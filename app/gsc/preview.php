<?php

declare(strict_types=1);

/**
 * Standalone preview of the GSC settings card (connected / not connected).
 * For layout QA and screenshots — not linked from the main menu.
 *
 * ?state=connected|disconnected|free  & optional ?embed=1
 */

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/gsc.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_gsc_ensure_schema($pdo);
ai_scan_security_headers();

$state = (string) ($_GET['state'] ?? 'disconnected');
if (!in_array($state, ['connected', 'disconnected', 'free'], true)) {
    $state = 'disconnected';
}

$config['gsc_client_id'] = 'preview-client-id';
$config['gsc_client_secret'] = 'preview-client-secret';
$config['gsc_redirect_uri'] = 'https://jpwebcreation.nl/app/gsc/callback.php';
$config['app_key'] = (string) ($config['app_key'] ?? 'preview-app-key');

$email = 'preview-gsc-' . $state . '@example.nl';
$emailHash = ai_scan_email_hash($email);
$existing = $pdo->prepare('SELECT * FROM users WHERE email_hash = :h LIMIT 1');
$existing->execute(['h' => $emailHash]);
$user = $existing->fetch();
if ($user === false) {
    $pdo->prepare(
        'INSERT INTO users (email, email_hash, password_hash, created_at, free_scan_used, tier_id, subscription_status)
         VALUES (:e, :h, :p, :c, 1, :tier, :status)'
    )->execute([
        'e' => $email,
        'h' => $emailHash,
        'p' => ai_scan_hash_password('preview-only-not-used'),
        'c' => time(),
        'tier' => $state === 'free' ? 'free' : 'q25',
        'status' => $state === 'free' ? 'none' : 'active',
    ]);
    $userId = (int) $pdo->lastInsertId();
    $user = $pdo->query('SELECT * FROM users WHERE id = ' . $userId)->fetch();
} else {
    $userId = (int) $user['id'];
    $pdo->prepare(
        'UPDATE users SET tier_id = :tier, subscription_status = :status WHERE id = :id'
    )->execute([
        'tier' => $state === 'free' ? 'free' : 'q25',
        'status' => $state === 'free' ? 'none' : 'active',
        'id' => $userId,
    ]);
    $user = $pdo->query('SELECT * FROM users WHERE id = ' . $userId)->fetch();
}

$pdo->prepare('DELETE FROM gsc_connections WHERE user_id = :u')->execute(['u' => $userId]);
if ($state === 'connected') {
    ai_scan_gsc_connection_save(
        $pdo,
        $config,
        $userId,
        'sc-domain:voorbeeld.nl',
        'preview-refresh-token',
        'preview-access-token',
        3600,
        null,
        time() - 86400
    );
}

ai_scan_start_session($config);
$gsc_next = '/app/gsc/preview.php?state=' . rawurlencode($state);

$embed = (string) ($_GET['embed'] ?? '') === '1';
$title = 'Google Search Console · ' . $state;

?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <style>
    body {
      margin: 0;
      background: #eef1ef;
      color: #1a1f24;
      font: 400 1rem/1.5 system-ui, "Segoe UI", sans-serif;
    }
    .preview-shell {
      max-width: 40rem;
      margin: 0 auto;
      padding: <?= $embed ? '1rem' : '2rem' ?> 1.25rem 3rem;
      background: #fff;
      min-height: 100vh;
      box-sizing: border-box;
    }
    .preview-shell__kicker {
      margin: 0 0 0.25rem;
      font-size: 0.8rem;
      color: #5b6475;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .preview-shell__h {
      margin: 0 0 0.35rem;
      font-size: 1.5rem;
      font-weight: 650;
      letter-spacing: -0.02em;
    }
    .preview-shell__sub {
      margin: 0 0 0.5rem;
      color: #5b6475;
      font-size: 0.95rem;
    }
  </style>
</head>
<body>
  <main class="preview-shell">
    <?php if (!$embed): ?>
      <p class="preview-shell__kicker">Instellingen · Bedrijf</p>
      <h1 class="preview-shell__h">Bedrijf</h1>
      <p class="preview-shell__sub">Voorbeeld.nl · bijgewerkt voor preview</p>
    <?php endif; ?>
    <?php require __DIR__ . '/settings-card.php'; ?>
  </main>
</body>
</html>
