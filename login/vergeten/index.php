<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$error = '';
$sent = false;

if (ai_scan_request_method() === 'POST') {
    if (!ai_scan_verify_csrf($config, (string) ($_POST['csrf'] ?? ''))) {
        $error = 'De sessie is verlopen. Probeer het opnieuw.';
    } else {
        $email = ai_scan_clean_input('email', 254);
        if (!ai_scan_email_syntax_ok($email)) {
            $error = 'Vul een geldig e-mailadres in.';
        } else {
            // Account wel/niet en mail mislukt worden nooit getoond. De limiet geldt vóór de accountopzoeking,
            // dus de wachtmelding is voor bestaande en onbekende adressen identiek.
            $outcome = ai_scan_request_password_reset($pdo, $config, $email, ai_scan_client_ip());
            if ($outcome === 'mail_failed') {
                error_log('ai_scan password_reset_mail_failed');
            }
            if ($outcome === 'rate_limited') {
                $error = 'Je hebt net al een link aangevraagd. Probeer het later opnieuw.';
            } else {
                $sent = true;
            }
        }
    }
}

$csrf = ai_scan_csrf_token($config);
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Wachtwoord vergeten · JP Web Creation</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .ai-auth { max-width: 28rem; margin: 4rem auto; padding: 0 1.25rem; }
    .ai-auth h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
    .ai-auth p { color: var(--muted, #5e6862); margin-bottom: 1.5rem; }
    .ai-auth .auth-back { display: inline-block; margin-bottom: 1.5rem; }
    .ai-auth label { display: block; margin: 0.75rem 0 0.35rem; font-weight: 600; }
    .ai-auth input[type="email"] {
      width: 100%; padding: 0.75rem 0.9rem; border: 1px solid #cfd6d1;
      border-radius: 0.4rem; font: inherit; background: #fff;
    }
    .ai-auth .error { color: #a84832; margin: 0.75rem 0; }
    .ai-auth .notice { background: #eef6f1; color: #22473a; padding: 0.75rem 1rem; border-radius: 0.4rem; margin: 0.75rem 0; }
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
    <h1>Wachtwoord vergeten?</h1>
    <?php if ($sent): ?>
      <p class="notice" role="status">Als er een account bestaat voor dit e-mailadres, heb ik je een mail gestuurd met een link om een nieuw wachtwoord te kiezen. De link werkt 1 uur. Zie je niets? Kijk ook in je spam.</p>
      <p><a href="/login/">Terug naar inloggen</a></p>
    <?php else: ?>
      <p>Vul je e-mailadres in. Ik stuur je een link waarmee je zelf een nieuw wachtwoord kiest.</p>
      <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= $h($error) ?></p>
      <?php endif; ?>
      <form method="post" action="/login/vergeten/" novalidate>
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <label for="email">E-mailadres</label>
        <input id="email" name="email" type="email" autocomplete="username" required autofocus>
        <button type="submit">Stuur me een link</button>
      </form>
      <p style="margin-top:1.25rem"><a href="/login/">Terug naar inloggen</a></p>
    <?php endif; ?>
  </main>
</body>
</html>
