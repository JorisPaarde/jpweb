<?php

declare(strict_types=1);

/**
 * Revoke Google token (best effort) and delete the stored connection.
 */

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/gsc.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_gsc_ensure_schema($pdo);
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

if (ai_scan_request_method() !== 'POST') {
    ai_scan_post_only_page();
}

$userId = ai_scan_require_user($config, $pdo);
$csrf = (string) ($_POST['csrf'] ?? '');
$next = ai_scan_gsc_safe_next((string) ($_POST['next'] ?? ''), '/dashboard/');

if (!ai_scan_verify_csrf($config, $csrf)) {
    ai_scan_start_session($config);
    $_SESSION['ai_scan_flash'] = 'De sessie is verlopen. Probeer het opnieuw.';
    ai_scan_redirect($next, 303);
}

$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $userId]);
$user = $userStmt->fetch();
if ($user === false) {
    ai_scan_logout_user($config);
    ai_scan_redirect('/login/', 303);
}

if (!ai_scan_can_write($user)) {
    ai_scan_start_session($config);
    $_SESSION['ai_scan_flash'] = AI_SCAN_GSC_FREE_MESSAGE;
    ai_scan_redirect($next, 303);
}

ai_scan_gsc_disconnect($pdo, $config, $userId);

ai_scan_start_session($config);
$_SESSION['ai_scan_flash'] = 'Google Search Console is ontkoppeld.';
ai_scan_redirect($next, 303);
