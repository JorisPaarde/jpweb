<?php

declare(strict_types=1);

function redirect_with_status(string $status): void
{
    header('Location: /?contact=' . rawurlencode($status), true, 303);
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

$allowedHosts = ['jpwebcreation.nl', 'www.jpwebcreation.nl', 'test.jpwebcreation.nl'];
$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$requestHost = preg_replace('/:\d+$/', '', $requestHost) ?? '';

if (!in_array($requestHost, $allowedHosts, true)) {
    http_response_code(400);
    exit('Bad Request');
}

if (clean_input('website', 200) !== '') {
    redirect_with_status('success');
}

$name = clean_input('name', 120);
$company = clean_input('company', 160);
$email = clean_input('email', 254);
$phone = clean_input('phone', 40);
$message = clean_input('message', 5000);
$privacyAccepted = (string) ($_POST['privacy'] ?? '') === 'accepted';

if (
    $name === ''
    || strlen($name) < 2
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($message) < 10
    || !$privacyAccepted
) {
    redirect_with_status('invalid');
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateLimitFile = sys_get_temp_dir() . '/jpweb-contact-' . hash('sha256', $clientIp);

if (is_file($rateLimitFile) && (time() - (int) filemtime($rateLimitFile)) < 30) {
    redirect_with_status('wait');
}

@touch($rateLimitFile);

$safeEmail = str_replace(["\r", "\n"], '', $email);
$subject = 'Nieuwe aanvraag via jpwebcreation.nl';

if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8');
}

$body = implode("\n", [
    'Nieuwe aanvraag via jpwebcreation.nl',
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

redirect_with_status($sent ? 'success' : 'error');
