<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$method = ai_scan_request_method();
$error = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '/dashboard/');
if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')
    || str_contains($next, '\\') || preg_match('/[\x00-\x1F\x7F]/', $next) === 1) {
    $next = '/dashboard/';
}

if (ai_scan_current_user_id($config) !== null && $method === 'GET') {
    ai_scan_redirect($next, 303);
}

if ($method === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!ai_scan_verify_csrf($config, $csrf)) {
        $error = 'De sessie is verlopen. Probeer het opnieuw.';
    } elseif (ai_scan_login_rate_limited($pdo, $config, ai_scan_client_ip(), time())) {
        $error = 'Te veel pogingen. Probeer het later opnieuw.';
    } else {
        $email = ai_scan_normalize_email(ai_scan_clean_input('email', 254));
        $password = (string) ($_POST['password'] ?? '');
        ai_scan_login_rate_record($pdo, ai_scan_client_ip(), time());

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        $hash = is_array($user) ? (string) ($user['password_hash'] ?? '') : '';
        if ($user === false || $hash === '' || !ai_scan_verify_password($password, $hash)) {
            $error = 'E-mailadres of wachtwoord klopt niet.';
        } else {
            ai_scan_login_user(
                $config,
                (int) $user['id'],
                (int) ($user['password_version'] ?? 1)
            );
            $pdo->prepare('UPDATE users SET last_login_at = :t WHERE id = :id')->execute([
                't' => time(),
                'id' => $user['id'],
            ]);
            ai_scan_redirect($next, 303);
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
  <title>Inloggen · JP Web Creation</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .ai-auth { max-width: 28rem; margin: 4rem auto; padding: 0 1.25rem; }
    .ai-auth h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
    .ai-auth p { color: var(--muted, #5e6862); margin-bottom: 1.5rem; }
    .ai-auth label { display: block; margin: 0.75rem 0 0.35rem; font-weight: 600; }
    .ai-auth input[type="email"],
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
    <h1>Inloggen</h1>
    <p>Bekijk je AI-scan in je dashboard.</p>
    <?php if ($error !== ''): ?>
      <p class="error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <form method="post" action="/login/" novalidate>
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
      <label for="email">E-mailadres</label>
      <input id="email" name="email" type="email" autocomplete="email" required>
      <label for="password">Wachtwoord</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
      <button type="submit">Inloggen</button>
    </form>
  </main>
</body>
</html>
