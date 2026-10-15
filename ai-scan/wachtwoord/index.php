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
    } elseif (mb_strlen($password) < AI_SCAN_PASSWORD_MIN_LENGTH) {
        $error = 'Kies een wachtwoord van minimaal ' . AI_SCAN_PASSWORD_MIN_LENGTH . ' tekens.';
    } elseif ($password !== $password2) {
        $error = 'De wachtwoorden komen niet overeen.';
    } else {
        $result = ai_scan_set_password($pdo, $token, $password);
        if (!$result['ok']) {
            $error = match ($result['code']) {
                'expired' => 'Deze link is verlopen. Vraag via "Wachtwoord vergeten?" op de inlogpagina een nieuwe aan.',
                'no_account' => 'Bij deze link hoort geen account meer. Vraag de scan opnieuw aan of mail info@jpwebcreation.nl.',
                default => 'Deze link is ongeldig of al gebruikt.',
            };
        } else {
            ai_scan_start_session($config);
            $_SESSION['ai_scan_login_prefill'] = [
                'email' => (string) ($result['email'] ?? ''),
                'scan_status' => (string) ($result['scan_status'] ?? ''),
                'at' => time(),
            ];
            ai_scan_redirect('/login/', 303);
        }
    }
}

$csrf = ai_scan_csrf_token($config);

// Controleer de link al bij het openen, zodat een ongeldige of verlopen link meteen een herstelroute toont.
$tokenState = ai_scan_password_token_state($pdo, $token);
$linkProblem = match ($tokenState) {
    'ok' => '',
    'expired' => 'Deze link is verlopen.',
    'no_account' => 'Bij deze link hoort geen account meer.',
    default => $token === '' ? 'Deze link is ongeldig.' : 'Deze link is ongeldig of al gebruikt.',
};

// E-mailadres bij de link, zodat de wachtwoordmanager het nieuwe wachtwoord bij het juiste account opslaat.
$accountEmail = '';
if ($token !== '') {
    $lookup = $pdo->prepare(
        'SELECT u.email FROM scans s JOIN users u ON u.id = s.user_id
         WHERE s.password_token_hash = :h AND s.password_token_expires_at >= :now LIMIT 1'
    );
    $lookup->execute(['h' => ai_scan_hash_token($token), 'now' => time()]);
    $accountEmail = (string) ($lookup->fetchColumn() ?: '');
}
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
    .ai-auth .auth-back { display: inline-block; margin-bottom: 1.5rem; }
    .ai-auth h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
    .ai-auth p { color: var(--muted, #5e6862); margin-bottom: 1.5rem; }
    .ai-auth label { display: block; margin: 0.75rem 0 0.35rem; font-weight: 600; }
    .ai-auth input[type="password"] {
      width: 100%; padding: 0.75rem 0.9rem; border: 1px solid #cfd6d1;
      border-radius: 0.4rem; font: inherit; background: #fff;
    }
    .ai-auth .error { color: #a84832; margin: 0.75rem 0; }
    .ai-auth .auth-links { display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem; align-items: center; }
    .ai-auth .auth-primary {
      display: inline-flex; align-items: center; min-height: 48px; padding: 0 1.25rem;
      background: var(--green, #315f4f); color: #fff; border-radius: 0.4rem; font-weight: 600; text-decoration: none;
    }
    .ai-auth button {
      margin-top: 1.25rem; width: 100%; min-height: 48px;
      background: var(--green, #315f4f); color: #fff; border: 0;
      border-radius: 0.4rem; font: inherit; font-weight: 600; cursor: pointer;
    }
  </style>
</head>
<body>
  <main class="ai-auth">
    <a class="auth-back" href="/">← Terug naar jpwebcreation.nl</a>
    <h1>Wachtwoord kiezen</h1>
    <?php if ($done): ?>
      <p>Je wachtwoord is ingesteld. Je kunt nu <a href="/login/">inloggen</a> op je dashboard.</p>
    <?php elseif ($linkProblem !== ''): ?>
      <p class="error" role="alert"><?= htmlspecialchars($linkProblem, ENT_QUOTES, 'UTF-8') ?></p>
      <?php if ($tokenState === 'no_account'): ?>
        <p>Vraag de scan opnieuw aan of mail info@jpwebcreation.nl, dan help ik je verder.</p>
        <p class="auth-links"><a href="/ai-vindbaarheid/">Naar de gratis scan</a></p>
      <?php else: ?>
        <p>Heb je al een account? Vraag dan een nieuwe link aan om je wachtwoord te kiezen.</p>
        <p class="auth-links">
          <a class="auth-primary" href="/login/vergeten/">Nieuwe wachtwoordlink aanvragen</a>
          <a href="/login/">Inloggen</a>
        </p>
      <?php endif; ?>
    <?php else: ?>
      <p>Kies zelf een wachtwoord van minimaal <?= AI_SCAN_PASSWORD_MIN_LENGTH ?> tekens. Ik stuur nooit een wachtwoord per mail.</p>
      <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
      <form method="post" action="/ai-scan/wachtwoord/">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="t" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="email" name="username" autocomplete="username" value="<?= htmlspecialchars($accountEmail, ENT_QUOTES, 'UTF-8') ?>" readonly hidden aria-hidden="true" tabindex="-1">
        <label for="password">Nieuw wachtwoord</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="<?= AI_SCAN_PASSWORD_MIN_LENGTH ?>" required>
        <label for="password2">Herhaal wachtwoord</label>
        <input id="password2" name="password2" type="password" autocomplete="new-password" minlength="<?= AI_SCAN_PASSWORD_MIN_LENGTH ?>" required>
        <button type="submit">Wachtwoord opslaan</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
