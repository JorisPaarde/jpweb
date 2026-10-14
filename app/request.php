<?php

declare(strict_types=1);

/**
 * POST endpoint: account / upgrade / downgrade / cancel request.
 * Does not change limits; mails info@ + customer confirmation (throttled).
 * Brief 02 builds the form UI.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

if (ai_scan_request_method() !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$userId = ai_scan_require_user($config, $pdo);
$csrf = (string) ($_POST['csrf'] ?? '');
if (!ai_scan_verify_csrf($config, $csrf)) {
    ai_scan_start_session($config);
    $_SESSION['ai_scan_flash'] = 'De sessie is verlopen. Probeer het opnieuw.';
    ai_scan_redirect('/dashboard/', 303);
}

$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch();
if ($user === false) {
    ai_scan_logout_user($config);
    ai_scan_redirect('/login/', 303);
}

$kind = ai_scan_clean_input('kind', 40);
$requestedTier = ai_scan_clean_input('tier_id', 40);
if ($requestedTier === '') {
    $requestedTier = ai_scan_clean_input('requested_tier', 40);
}

$result = ai_scan_request_tier_change($pdo, $config, $user, $requestedTier, $kind);

ai_scan_start_session($config);
$_SESSION['ai_scan_flash'] = (string) ($result['message'] ?? AI_SCAN_TIER_REQUEST_MESSAGE);

$next = (string) ($_POST['next'] ?? '/dashboard/');
if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\x00-\x1F\x7F]/', $next) === 1) {
    $next = '/dashboard/';
}

ai_scan_redirect($next, 303);
