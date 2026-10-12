<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$method = ai_scan_request_method();
$asJson = ai_scan_wants_json();

// Lightweight housekeeping on traffic.
try {
    ai_scan_check_stale_jobs($pdo, $config);
    ai_scan_purge_expired_unconfirmed($pdo, time());
    ai_scan_purge_old_ip_hashes($pdo, time());
} catch (Throwable $e) {
    // Never break the public form for housekeeping failures.
}

if ($method === 'GET' && (string) ($_GET['action'] ?? '') === 'csrf') {
    $token = ai_scan_csrf_token($config);
    ai_scan_json_response(['csrf' => $token]);
}

if ($method === 'GET' && (string) ($_GET['action'] ?? '') === 'state') {
    $state = ai_scan_consume_form_state($config);
    $state['csrf'] = ai_scan_csrf_token($config);
    ai_scan_json_response($state);
}

if ($method === 'GET' && isset($_GET['confirm'])) {
    $result = ai_scan_confirm($pdo, $config, (string) $_GET['confirm']);
    ai_scan_respond_code($result['code'], $asJson);
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

$input = [
    'email' => ai_scan_clean_input('email', 254),
    'website' => ai_scan_clean_input('website', 300),
    'consent' => (string) ($_POST['consent'] ?? ''),
    'privacy' => (string) ($_POST['privacy'] ?? ''),
    'hp_field' => ai_scan_clean_input('hp_field', 200),
    'bedrijfsfax' => ai_scan_clean_input('bedrijfsfax', 200),
];

// Honeypot first: never reveal validation or CSRF state to bots.
if ($input['hp_field'] !== '' || $input['bedrijfsfax'] !== '') {
    ai_scan_respond_code('check_mail', $asJson);
}

$csrf = (string) ($_POST['csrf'] ?? '');
if (!ai_scan_verify_csrf($config, $csrf)) {
    ai_scan_store_form_state($config, [
        'email' => $input['email'],
        'website' => $input['website'],
    ], ['form' => 'De sessie is verlopen. Probeer het opnieuw.']);
    ai_scan_respond_code('error', $asJson);
}

$result = ai_scan_submit($pdo, $config, $input, ai_scan_client_ip());

if (in_array($result['code'], ['invalid_email', 'site_unreachable', 'disposable'], true)) {
    $errors = [];
    if ($result['code'] === 'invalid_email') {
        $errors['email'] = 'Vul een geldig e-mailadres in en geef toestemming.';
    } elseif ($result['code'] === 'disposable') {
        $errors['email'] = 'Gebruik een vast e-mailadres, geen wegwerpadres.';
    } else {
        $errors['website'] = 'Ik kon deze website niet bereiken. Controleer het adres.';
    }
    ai_scan_store_form_state($config, [
        'email' => $input['email'],
        'website' => $input['website'],
    ], $errors);
}

$extra = [];
if ($result['code'] === 'rate_limited') {
    $extra['retry_after'] = (int) ($result['retry_after'] ?? ai_scan_rate_limit_retry_after($config));
}

ai_scan_respond_code($result['code'], $asJson, $extra);
