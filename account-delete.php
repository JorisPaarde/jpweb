<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
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
    ai_scan_redirect('/dashboard/?deleted=csrf', 303);
}

ai_scan_delete_account($pdo, $userId);
ai_scan_logout_user($config);
ai_scan_redirect('/ai-scan/?s=deleted', 303);
