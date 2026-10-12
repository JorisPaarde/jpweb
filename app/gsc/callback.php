<?php

declare(strict_types=1);

/**
 * Google OAuth callback. Validates state; redirect_uri comes only from config.
 */

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/gsc.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_gsc_ensure_schema($pdo);
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

ai_scan_start_session($config);
$next = ai_scan_gsc_safe_next((string) ($_SESSION['gsc_oauth_next'] ?? ''), '/dashboard/');
unset($_SESSION['gsc_oauth_next']);

$error = (string) ($_GET['error'] ?? '');
if ($error !== '') {
    $_SESSION['ai_scan_flash'] = 'Koppelen met Google is geannuleerd of mislukt.';
    ai_scan_redirect($next, 303);
}

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
if ($state === '' || $code === '') {
    $_SESSION['ai_scan_flash'] = 'Ongeldig antwoord van Google. Probeer het opnieuw.';
    ai_scan_redirect($next, 303);
}

$check = ai_scan_gsc_oauth_state_consume($config, $state);
if (empty($check['ok'])) {
    $_SESSION['ai_scan_flash'] = 'De beveiligingscontrole is mislukt. Probeer het opnieuw.';
    ai_scan_redirect($next, 303);
}

$sessionUserId = ai_scan_current_user_id($config);
$oauthUserId = (int) ($check['user_id'] ?? 0);
if ($sessionUserId === null || $sessionUserId !== $oauthUserId) {
    $_SESSION['ai_scan_flash'] = 'Je sessie kwam niet overeen. Log opnieuw in en probeer het nog eens.';
    ai_scan_redirect('/login/?next=' . rawurlencode($next), 303);
}

$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
$userStmt->execute(['id' => $oauthUserId]);
$user = $userStmt->fetch();
if ($user === false || !ai_scan_can_write($user)) {
    $_SESSION['ai_scan_flash'] = AI_SCAN_GSC_FREE_MESSAGE;
    ai_scan_redirect($next, 303);
}

$result = ai_scan_gsc_complete_connect($pdo, $config, $oauthUserId, $code);
if (empty($result['ok'])) {
    $map = [
        'no_matching_property' => 'We vonden geen Search Console-property die bij je website past. Controleer of je website in Search Console staat en probeer opnieuw.',
        'missing_refresh_token' => 'Google gaf geen langetermijn-toegang. Kies bij het koppelen opnieuw voor toestemming.',
        'not_configured' => 'Google Search Console is nog niet geconfigureerd.',
        'token_exchange_failed' => 'Het ophalen van toegang bij Google is mislukt. Probeer het later opnieuw.',
    ];
    $err = (string) ($result['error'] ?? '');
    $_SESSION['ai_scan_flash'] = $map[$err] ?? 'Koppelen met Google Search Console is mislukt.';
    ai_scan_redirect($next, 303);
}

$_SESSION['ai_scan_flash'] = 'Google Search Console is gekoppeld (' . (string) ($result['property'] ?? '') . ').';
ai_scan_redirect($next, 303);
