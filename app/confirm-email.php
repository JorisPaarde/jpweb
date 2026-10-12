<?php

declare(strict_types=1);

/**
 * Confirm pending email change via one-time token.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/questions.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_ensure_questions_schema($pdo);
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$token = (string) ($_GET['t'] ?? '');
$result = ai_scan_confirm_email_change($pdo, $token);

ai_scan_start_session($config);
$_SESSION['ai_scan_flash'] = !empty($result['ok'])
    ? (string) ($result['message'] ?? 'Je e-mailadres is bijgewerkt.')
    : (string) ($result['error'] ?? 'Ongeldige of verlopen link.');

ai_scan_redirect('/app/account.php', 303);
