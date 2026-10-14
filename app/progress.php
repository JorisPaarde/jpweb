<?php

declare(strict_types=1);

/**
 * JSON: live voortgang van de laatste scan van de ingelogde gebruiker (polling vanuit het dashboard).
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$userId = ai_scan_current_user_id($config);
if ($userId === null || !ai_scan_session_password_version_ok($pdo, $userId)) {
    ai_scan_json_response(['ok' => false, 'error' => 'login'], 401);
}
header('Cache-Control: no-store');
$view = ai_scan_progress_view(ai_scan_latest_for_user($pdo, $userId));
ai_scan_json_response(['ok' => true] + $view);
