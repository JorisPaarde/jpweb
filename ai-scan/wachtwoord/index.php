<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$error = '';
$done = false;

if (ai_scan_request_method() === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');

    if (!ai_scan_verify_csrf($config, $csrf)) {
        $error = 'De sessie is verlopen. Probeer het opnieuw.';
    } elseif (strlen($password) < 10) {
        $error = 'Kies een wachtwoord van minimaal 10 tekens.';
    } elseif ($password !== $password2) {
        $error = 'De wachtwoorden komen niet overeen.';
    } else {
        $result = ai_scan_set_password($pdo, $token, $password);
        if (!$result['ok']) {
            $error = match ($result['code']) {
                'expired' => 'Deze link is verlopen (24 uur geldig).',
                'no_account' => 'Bij deze link hoort geen account meer. Vraag de scan opnieuw aan of mail info@jpwebcreation.nl.',
                default => 'Deze link is ongeldig of al gebruikt.',
            };
        } else {
            ai_scan_start_session($config);
            $_SESSION['ai_scan_login_prefill'] = [
                'email' => (string) ($result['email'] ?? ''),
                'at' => time(),
            ];
            ai_scan_redirect('/login/', 303);
        }
    }
}

$csrf = ai_scan_csrf_token($config);
?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Wachtwoord kiezen · JP Web Creation</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .ai-auth { max-width: 28rem; margin: 4rem auto; padding: 0 1.25rem; }
    .ai-auth h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
    .ai-auth p { color: var(--muted, #5e6862); margin-bottom: 1.5rem; }
    .ai-auth label { display: block; margin: 0.75rem 0 0.35rem; font-weight: 600; }
    .ai-auth input[type="password"] {
      width: 100%; padding: 0.75rem 0.9rem; border: 1px solid #cfd6d1;
      border-radius: 0.4rem; font: inherit; background: #fff;
    }
    .ai-auth .error { color: #a84832; margin: 0.75rem 0; }
    .ai-auth button {
      margin-top: 1.25rem; width: 100%; min-height: 48px;
      background: var(--green, #315f4f); color: #fff; border: 0;
      border-radius: 0.4rem; font: inherit; font-weight: 600; cursor: pointer;
    }
  </style>
</head>
<body>
  <main class="ai-auth">
    <h1>Wachtwoord kiezen</h1>
    <?php if ($done): ?>
      <p>Je wachtwoord is ingesteld. Je kunt nu <a href="/login/">inloggen</a> op je dashboard.</p>
    <?php elseif ($token === ''): ?>
      <p class="error">Deze link is ongeldig.</p>
    <?php else: ?>
      <p>Kies zelf een wachtwoord. Ik stuur nooit een wachtwoord per mail.</p>
      <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
      <form method="post" action="/ai-scan/wachtwoord/" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <label for="password">Nieuw wachtwoord</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required>
        <label for="password2">Herhaal wachtwoord</label>
        <input id="password2" name="password2" type="password" autocomplete="new-password" minlength="10" required>
        <button type="submit">Wachtwoord opslaan</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
