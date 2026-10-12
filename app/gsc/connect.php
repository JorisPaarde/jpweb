<?php

declare(strict_types=1);

/**
 * Start Google Search Console OAuth (read-only). POST + CSRF.
 */

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/gsc.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_gsc_ensure_schema($pdo);
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

if (ai_scan_request_method() !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$userId = ai_scan_require_user($config);
$csrf = (string) ($_POST['csrf'] ?? '');
if (!ai_scan_verify_csrf($config, $csrf)) {
    ai_scan_start_session($config);
    $_SESSION['ai_scan_flash'] = 'De sessie is verlopen. Probeer het opnieuw.';
    ai_scan_redirect(ai_scan_gsc_safe_next((string) ($_POST['next'] ?? ''), '/dashboard/'), 303);
}

$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch();
if ($user === false) {
    ai_scan_logout_user($config);
    ai_scan_redirect('/login/', 303);
}

$next = ai_scan_gsc_safe_next((string) ($_POST['next'] ?? ''), '/dashboard/');

if (!ai_scan_can_write($user)) {
    ai_scan_start_session($config);
    $_SESSION['ai_scan_flash'] = AI_SCAN_GSC_FREE_MESSAGE;
    ai_scan_redirect($next, 303);
}

if (!ai_scan_gsc_configured($config)) {
    ai_scan_start_session($config);
    $_SESSION['ai_scan_flash'] = 'Google Search Console is nog niet geconfigureerd. Neem contact met ons op.';
    ai_scan_redirect($next, 303);
}

ai_scan_start_session($config);
$_SESSION['gsc_oauth_next'] = $next;
$state = ai_scan_gsc_oauth_state_create($config, $userId);
ai_scan_redirect(ai_scan_gsc_auth_url($config, $state), 303);
