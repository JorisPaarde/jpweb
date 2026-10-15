<?php

declare(strict_types=1);

/**
 * Dashboard entry: JSON status API stays here; HTML redirects into the app shell.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$userId = ai_scan_require_user($config, $pdo);
$scan = ai_scan_latest_for_user($pdo, $userId);
$wantsJson = ai_scan_wants_json() || (string) ($_GET['format'] ?? '') === 'json';

if ($wantsJson) {
    if ($scan === null) {
        ai_scan_json_response(['ok' => true, 'status' => 'none', 'result' => null]);
    }

    $resultJson = null;
    if (!empty($scan['result_json'])) {
        $decoded = json_decode((string) $scan['result_json'], true);
        $resultJson = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    ai_scan_json_response([
        'ok' => true,
        'status' => (string) $scan['status'],
        'domain' => (string) $scan['domain'],
        'website_url' => (string) $scan['website_url'],
        'public_id' => (string) $scan['public_id'],
        'result' => $resultJson,
        'error_message' => $scan['error_message'] !== null ? (string) $scan['error_message'] : null,
        'completed_at' => $scan['completed_at'] !== null ? (int) $scan['completed_at'] : null,
    ]);
}

ai_scan_redirect('/app/overzicht.php', 303);
