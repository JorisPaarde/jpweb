<?php

declare(strict_types=1);

/**
 * Beheerder-only: scans die opnieuw geprobeerd worden of definitief mislukt zijn.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$admin = ai_scan_require_admin_user($pdo, $config);

$rows = $pdo->query(
    "SELECT public_id, domain, status, attempts, queued_at, completed_at, last_failure, error_message
     FROM scans
     WHERE (status = 'mislukt' AND attempts > 0) OR (status IN ('bevestigd', 'bezig') AND attempts > 0)
     ORDER BY COALESCE(completed_at, queued_at) DESC, id DESC
     LIMIT 50"
)->fetchAll();

$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$when = static fn (mixed $ts): string => $ts === null || $ts === '' ? '-' : gmdate('Y-m-d H:i', (int) $ts) . ' UTC';
?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Mislukte scans · beheer</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .scn { max-width: 60rem; margin: 2rem auto; padding: 0 1.25rem 3rem; }
    .scn table { width: 100%; border-collapse: collapse; }
    .scn th, .scn td { text-align: left; padding: 0.5rem; border-bottom: 1px solid #dde3df; vertical-align: top; }
  </style>
</head>
<body>
  <main class="scn">
    <h1>Mislukte scans</h1>
    <p><?= $h((string) $admin['email']) ?> · <a href="/admin/tier.php">Abonnementen</a> · <a href="/admin/blokkade.php">Blokkades</a></p>
    <p>Scans die één keer automatisch opnieuw zijn gezet (status bevestigd of bezig) of definitief mislukt zijn (status mislukt).</p>
    <?php if ($rows === []): ?>
      <p>Geen mislukte scans.</p>
    <?php else: ?>
      <table>
        <thead><tr><th scope="col">Domein</th><th scope="col">Status</th><th scope="col">Pogingen</th><th scope="col">Laatst</th><th scope="col">Melding</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= $h((string) $r['domain']) ?></td>
            <td><?= $h((string) $r['status']) ?></td>
            <td><?= (int) $r['attempts'] ?></td>
            <td><?= $h($when($r['completed_at'] ?? $r['queued_at'])) ?></td>
            <td><?= $h(mb_substr((string) ($r['error_message'] ?: $r['last_failure']), 0, 300)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
</body>
</html>
