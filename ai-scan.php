<?php

declare(strict_types=1);

const AI_SCAN_STATE_TTL = 600;

const AI_SCAN_MESSAGES = [
    'success' => 'Check je mail. We hebben een bevestigingslink gestuurd naar {email}. Zie je niets? Kijk in je spam of stuur opnieuw.',
    'invalid_email' => 'Dit lijkt geen geldig e-mailadres. Controleer het nog even.',
    'invalid_website' => 'We kunnen deze website niet bereiken. Klopt het adres? Probeer bijvoorbeeld "jouwbedrijf.nl".',
    'blocked_website' => 'We mogen deze website niet lezen. Daardoor kunnen we geen goede vragen maken. Neem contact op als je denkt dat dit niet klopt.',
    'already_scanned' => 'Voor deze website of dit e-mailadres is al een gratis scan gedaan. We sturen een inloglink naar het e-mailadres van die scan.',
    'disposable_email' => 'Gebruik een e-mailadres dat je blijft lezen. Je inloggegevens en uitslag komen daar terecht.',
    'daily_limit' => 'Vandaag zijn alle gratis scans bezet. Bevestig je adres; je scan start dan automatisch op de eerstvolgende dag met ruimte. Je krijgt een mail.',
    'rate_limit' => 'Je hebt het formulier een paar keer achter elkaar verstuurd. Probeer het over een uur opnieuw.',
    'privacy' => 'Geef toestemming om je e-mailadres en website voor deze scan te gebruiken.',
    'error' => 'Verzenden is niet gelukt. Mail je vraag naar info@jpwebcreation.nl.',
];

function ai_scan_clean_input(string $key, int $maxLength): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function ai_scan_input_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value);
    }

    return strlen($value);
}

function ai_scan_return_path(): string
{
    $returnPath = (string) ($_POST['return_to'] ?? '/ai-vindbaarheid/');
    $allowedPaths = ['/ai-vindbaarheid/'];

    return in_array($returnPath, $allowedPaths, true) ? $returnPath : '/ai-vindbaarheid/';
}

function ai_scan_wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
}

function ai_scan_start_session(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    ini_set('session.use_strict_mode', '1');
    session_name('jpweb_ai_scan');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !in_array((string) ($_SERVER['HTTP_HOST'] ?? ''), ['127.0.0.1', 'localhost'], true),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return @session_start();
}

function ai_scan_store_state(array $values, array $errors): void
{
    if (!ai_scan_start_session()) {
        return;
    }

    $_SESSION['ai_scan_state'] = [
        'created_at' => time(),
        'values' => $values,
        'errors' => $errors,
    ];
    session_write_close();
}

function ai_scan_send_state(): void
{
    $state = null;

    if (ai_scan_start_session()) {
        $candidate = $_SESSION['ai_scan_state'] ?? null;
        unset($_SESSION['ai_scan_state']);
        session_write_close();

        if (
            is_array($candidate)
            && isset($candidate['created_at'], $candidate['values'], $candidate['errors'])
            && is_int($candidate['created_at'])
            && (time() - $candidate['created_at']) <= AI_SCAN_STATE_TTL
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

function ai_scan_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_scan_redirect_with_status(string $status, string $returnPath): void
{
    header('Location: ' . $returnPath . '?scan=' . rawurlencode($status), true, 303);
    exit;
}

function ai_scan_success_message(string $email): string
{
    return str_replace('{email}', $email !== '' ? $email : 'je adres', AI_SCAN_MESSAGES['success']);
}

function ai_scan_normalize_website(string $website): string
{
    $website = trim($website);
    $website = preg_replace('#^https?://#i', '', $website) ?? $website;
    $website = preg_replace('#/.*$#', '', $website) ?? $website;
    $website = strtolower(rtrim($website, '.'));

    return $website;
}

function ai_scan_is_valid_website(string $website): bool
{
    if ($website === '' || ai_scan_input_length($website) > 200) {
        return false;
    }

    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $website)) {
        return false;
    }

    return filter_var('https://' . $website, FILTER_VALIDATE_URL) !== false;
}

$allowedHosts = [
    'jpwebcreation.nl',
    'www.jpwebcreation.nl',
    'test.jpwebcreation.nl',
    '127.0.0.1',
    'localhost',
];
$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$requestHost = preg_replace('/:\d+$/', '', $requestHost) ?? '';

if (!in_array($requestHost, $allowedHosts, true)) {
    http_response_code(400);
    exit('Bad Request');
}

$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'state') {
    ai_scan_send_state();
}

if ($requestMethod !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method Not Allowed');
}

$returnPath = ai_scan_return_path();
$wantsJson = ai_scan_wants_json();

// Honeypot: filled → same success response, nothing stored.
if (ai_scan_clean_input('bedrijfsfax', 200) !== '') {
    $emailForMessage = ai_scan_clean_input('email', 254);
    $message = ai_scan_success_message($emailForMessage);

    if ($wantsJson) {
        ai_scan_json_response([
            'ok' => true,
            'status' => 'success',
            'message' => $message,
        ]);
    }

    ai_scan_redirect_with_status('success', $returnPath);
}

$email = ai_scan_clean_input('email', 254);
$websiteRaw = ai_scan_clean_input('website', 200);
$website = ai_scan_normalize_website($websiteRaw);
$privacyAccepted = (string) ($_POST['privacy'] ?? '') === 'accepted';

$errors = [];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = AI_SCAN_MESSAGES['invalid_email'];
}

if (!ai_scan_is_valid_website($website)) {
    $errors['website'] = AI_SCAN_MESSAGES['invalid_website'];
}

if (!$privacyAccepted) {
    $errors['privacy'] = AI_SCAN_MESSAGES['privacy'];
}

if ($errors !== []) {
    // Never restore honeypot or privacy acceptance.
    ai_scan_store_state([
        'email' => $email,
        'website' => $websiteRaw,
    ], $errors);

    if ($wantsJson) {
        ai_scan_json_response([
            'ok' => false,
            'status' => 'invalid',
            'message' => 'Controleer de ingevulde gegevens en probeer het opnieuw.',
            'errors' => $errors,
            'values' => [
                'email' => $email,
                'website' => $websiteRaw,
            ],
        ], 422);
    }

    ai_scan_redirect_with_status('invalid', $returnPath);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateLimitFile = sys_get_temp_dir() . '/jpweb-ai-scan-' . hash('sha256', $clientIp);

if (is_file($rateLimitFile) && (time() - (int) filemtime($rateLimitFile)) < 60) {
    if ($wantsJson) {
        ai_scan_json_response([
            'ok' => false,
            'status' => 'rate_limit',
            'message' => AI_SCAN_MESSAGES['rate_limit'],
            'errors' => [],
        ], 429);
    }

    ai_scan_redirect_with_status('rate_limit', $returnPath);
}

@touch($rateLimitFile);

// Stub only: validation + honeypot + JSON/redirect. No mail, database, or real scans.
$message = ai_scan_success_message($email);

if ($wantsJson) {
    ai_scan_json_response([
        'ok' => true,
        'status' => 'success',
        'message' => $message,
    ]);
}

ai_scan_redirect_with_status('success', $returnPath);
