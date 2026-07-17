<?php

declare(strict_types=1);

const CONTACT_STATE_TTL = 600;

function redirect_with_status(string $status, string $returnPath = '/'): void
{
    header('Location: ' . $returnPath . '?contact=' . rawurlencode($status), true, 303);
    exit;
}

function clean_input(string $key, int $maxLength): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function input_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}

function contact_return_path(): string
{
    $returnPath = (string) ($_POST['return_to'] ?? '/');
    $allowedPaths = ['/', '/digitale-opname/'];

    return in_array($returnPath, $allowedPaths, true) ? $returnPath : '/';
}

function start_form_session(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    ini_set('session.use_strict_mode', '1');
    session_name('jpweb_contact');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return @session_start();
}

function store_contact_state(array $values, array $errors): void
{
    if (!start_form_session()) {
        return;
    }

    $_SESSION['contact_state'] = [
        'created_at' => time(),
        'values' => $values,
        'errors' => $errors,
    ];
    session_write_close();
}

function send_contact_state(): void
{
    $state = null;

    if (start_form_session()) {
        $candidate = $_SESSION['contact_state'] ?? null;
        unset($_SESSION['contact_state']);
        session_write_close();

        if (
            is_array($candidate)
            && isset($candidate['created_at'], $candidate['values'], $candidate['errors'])
            && is_int($candidate['created_at'])
            && (time() - $candidate['created_at']) <= CONTACT_STATE_TTL
            && is_array($candidate['values'])
            && is_array($candidate['errors'])
        ) {
            $state = [
                'values' => $candidate['values'],
                'errors' => $candidate['errors'],
            ];
        }
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($state ?? ['values' => [], 'errors' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$allowedHosts = ['jpwebcreation.nl', 'www.jpwebcreation.nl', 'test.jpwebcreation.nl'];
$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$requestHost = preg_replace('/:\d+$/', '', $requestHost) ?? '';

if (!in_array($requestHost, $allowedHosts, true)) {
    http_response_code(400);
    exit('Bad Request');
}

$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'state') {
    send_contact_state();
}

if ($requestMethod !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method Not Allowed');
}

$returnPath = contact_return_path();

if (clean_input('website', 200) !== '') {
    redirect_with_status('success', $returnPath);
}

$name = clean_input('name', 120);
$company = clean_input('company', 160);
$email = clean_input('email', 254);
$phone = clean_input('phone', 40);
$message = clean_input('message', 5000);
$privacyAccepted = (string) ($_POST['privacy'] ?? '') === 'accepted';

$errors = [];

if (input_length($name) < 2) {
    $errors['name'] = 'Vul je naam in.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Vul een geldig e-mailadres in.';
}

if (input_length($message) < 10) {
    $errors['message'] = 'Omschrijf je vraag in minimaal 10 tekens.';
}

if (!$privacyAccepted) {
    $errors['privacy'] = 'Geef toestemming om op je vraag te reageren.';
}

if ($errors !== []) {
    store_contact_state([
        'name' => $name,
        'company' => $company,
        'email' => $email,
        'phone' => $phone,
        'message' => $message,
    ], $errors);
    redirect_with_status('invalid', $returnPath);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateLimitFile = sys_get_temp_dir() . '/jpweb-contact-' . hash('sha256', $clientIp);

if (is_file($rateLimitFile) && (time() - (int) filemtime($rateLimitFile)) < 30) {
    redirect_with_status('wait', $returnPath);
}

@touch($rateLimitFile);

$safeEmail = str_replace(["\r", "\n"], '', $email);
$subject = $returnPath === '/digitale-opname/'
    ? 'Kennismaking Digitale Opname via jpwebcreation.nl'
    : 'Nieuwe aanvraag via jpwebcreation.nl';

if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8');
}

$body = implode("\n", [
    $returnPath === '/digitale-opname/'
        ? 'Kennismaking Digitale Opname via jpwebcreation.nl'
        : 'Nieuwe aanvraag via jpwebcreation.nl',
    '',
    'Naam: ' . $name,
    'Bedrijf: ' . ($company !== '' ? $company : '-'),
    'E-mail: ' . $email,
    'Telefoon: ' . ($phone !== '' ? $phone : '-'),
    '',
    'Vraag:',
    $message,
]);

$headers = implode("\r\n", [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: JPWebcreation website <info@jpwebcreation.nl>',
    'Reply-To: ' . $safeEmail,
]);

$sent = mail('info@jpwebcreation.nl', $subject, $body, $headers);

redirect_with_status($sent ? 'success' : 'error', $returnPath);
