<?php

declare(strict_types=1);

require_once __DIR__ . '/app/_init.php';

if (ai_scan_request_method() !== 'POST') {
    if (ai_scan_request_method() === 'GET') {
        ai_scan_redirect('/app/account.php', 303);
    }
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}

$ctx = app_boot('account');
$config = $ctx['config'];
$pdo = $ctx['pdo'];
$userId = (int) $ctx['user']['id'];
$csrf = (string) ($_POST['csrf'] ?? '');
if (!ai_scan_verify_csrf($config, $csrf)) {
    ai_scan_redirect('/dashboard/?deleted=csrf', 303);
}

$error = '';
if (!empty($ctx['is_demo'])) {
    $error = APP_ACCOUNT_DEMO_MESSAGE;
} elseif (!app_account_can_delete($ctx)) {
    $error = 'Zeg eerst je abonnement op voordat je je account verwijdert.';
} elseif (!app_account_website_confirmed($ctx, (string) ($_POST['confirm_website'] ?? ''))) {
    $error = 'De ingevulde website komt niet overeen. Je account is niet verwijderd.';
} elseif (!ai_scan_delete_account($pdo, $userId)) {
    $error = 'Verwijderen is niet gelukt. Probeer het later opnieuw.';
}
if ($error !== '') {
    ai_scan_start_session($config);
    $_SESSION['ai_scan_flash'] = $error;
    ai_scan_redirect('/app/account.php', 303);
}

ai_scan_logout_user($config);
ai_scan_redirect('/ai-scan/?s=deleted', 303);
