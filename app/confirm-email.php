<?php

declare(strict_types=1);

/**
 * Confirm pending email change via one-time token.
 *
 * The link in the mail only shows a button. Only submitting that button changes the address, so a
 * mail scanner that opens the link beforehand does not use up the token. The outcome is shown right
 * here, without a login session.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/questions.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_ensure_questions_schema($pdo);
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$isPost = ai_scan_request_method() === 'POST';
$token = (string) ($isPost ? ($_POST['t'] ?? '') : ($_GET['t'] ?? ''));

// view: form (button), done (changed) or error
$view = 'error';
$message = 'Deze link is ongeldig.';

if ($isPost) {
    if (!ai_scan_verify_csrf($config, (string) ($_POST['csrf'] ?? ''))) {
        if (ai_scan_email_change_token_state($pdo, $token) === 'ok') {
            $view = 'form';
            $message = 'Je sessie was verlopen. Klik nog een keer op de knop.';
        }
    } else {
        $result = ai_scan_confirm_email_change($pdo, $token);
        if (!empty($result['ok'])) {
            $view = 'done';
            $message = (string) ($result['message'] ?? 'Je e-mailadres is bijgewerkt.');
        } else {
            $message = (string) ($result['error'] ?? 'Deze link is ongeldig.');
        }
    }
} else {
    $state = ai_scan_email_change_token_state($pdo, $token);
    if ($state === 'ok') {
        $view = 'form';
    } elseif ($state === 'expired') {
        $message = 'Deze link is verlopen.';
    }
}

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>E-mailadres bevestigen · JP Web Creation</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .ai-auth { max-width: 28rem; margin: 4rem auto; padding: 0 1.25rem; }
    .ai-auth .auth-back { display: inline-block; margin-bottom: 1.5rem; }
    .ai-auth h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
    .ai-auth p { color: var(--muted, #5e6862); margin-bottom: 1.5rem; }
    .ai-auth p a { color: var(--green, #315f4f); text-decoration: underline; text-underline-offset: 0.15em; }
    .ai-auth .error { color: #a84832; margin: 0.75rem 0; font-weight: 600; }
    .ai-auth .auth-links { display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem; align-items: center; }
    .ai-auth .auth-primary {
      display: inline-flex; align-items: center; min-height: 48px; padding: 0 1.25rem;
      background: var(--green, #315f4f); color: #fff; border-radius: 0.4rem; font-weight: 600; text-decoration: none;
    }
    .ai-auth button {
      width: 100%; min-height: 48px; background: var(--green, #315f4f); color: #fff; border: 0;
      border-radius: 0.4rem; font: inherit; font-weight: 600; cursor: pointer;
    }
  </style>
</head>
<body>
  <main class="ai-auth">
    <a class="auth-back" href="/">← Terug naar jpwebcreation.nl</a>
    <h1>E-mailadres bevestigen</h1>
    <?php if ($view === 'form'): ?>
      <?php if ($isPost): ?><p class="error" role="alert"><?= $e($message) ?></p><?php endif; ?>
      <p>Klik op de knop om je nieuwe e-mailadres te bevestigen. Daarna log je voortaan met dit adres in.</p>
      <form method="post" action="/app/confirm-email.php">
        <input type="hidden" name="t" value="<?= $e($token) ?>">
        <input type="hidden" name="csrf" value="<?= $e(ai_scan_csrf_token($config)) ?>">
        <button type="submit">Bevestig mijn nieuwe e-mailadres</button>
      </form>
    <?php elseif ($view === 'done'): ?>
      <p><?= $e($message) ?></p>
      <p class="auth-links"><a class="auth-primary" href="/login/">Inloggen</a></p>
    <?php else: ?>
      <p class="error" role="alert"><?= $e($message) ?></p>
      <p>Log in en vraag de wijziging van je e-mailadres opnieuw aan, dan stuur ik je een nieuwe link. Lukt dat niet? Mail me op info@jpwebcreation.nl.</p>
      <p class="auth-links"><a class="auth-primary" href="/login/">Inloggen</a></p>
    <?php endif; ?>
  </main>
</body>
</html>
