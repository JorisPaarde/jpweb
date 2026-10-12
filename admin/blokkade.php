<?php

declare(strict_types=1);

/**
 * Beheerder-only: gratis-scanblokkade opheffen voor een e-mailadres en/of domein.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$admin = ai_scan_require_admin_user($pdo, $config);
$csrf = ai_scan_csrf_token($config);
$flash = '';
$error = '';
$email = '';
$website = '';

if (ai_scan_request_method() === 'POST') {
    $email = ai_scan_clean_input('email', 254);
    $website = ai_scan_clean_input('website', 300);
    $action = ai_scan_clean_input('do', 20);
    if (!ai_scan_verify_csrf($config, (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Sessie verlopen. Probeer opnieuw.';
    } elseif ($action === 'check') {
        $flash = 'Gevonden blokkades: ' . ai_scan_count_free_scan_blocks($pdo, $email, $website) . '.';
    } else {
        $result = ai_scan_admin_lift_free_scan_block($pdo, $email, $website, (string) $admin['email']);
        if (!empty($result['ok'])) {
            $flash = 'Blokkade opgeheven (' . (int) $result['deleted'] . ' verwijderd).';
        } else {
            $error = 'Niet gelukt: vul een geldig e-mailadres en/of domein in.';
        }
    }
}

$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Blokkade opheffen · beheer</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .blk { max-width: 36rem; margin: 2rem auto; padding: 0 1.25rem 3rem; }
    .blk form { display: grid; gap: 0.75rem; }
    .blk input { min-height: 44px; font: inherit; }
    .blk button { min-height: 44px; padding: 0 0.9rem; cursor: pointer; font: inherit; }
    .blk-flash { padding: 0.75rem 1rem; background: #eef6f1; }
    .blk-error { padding: 0.75rem 1rem; background: #f8e8e4; }
  </style>
</head>
<body>
  <main class="blk">
    <h1>Gratis-scanblokkade opheffen</h1>
    <p><?= $h((string) $admin['email']) ?> · <a href="/admin/tier.php">Abonnementen</a> · <a href="/dashboard/">Dashboard</a></p>
    <p>Hiermee kan iemand met dit e-mailadres of domein opnieuw een gratis scan aanvragen.</p>
    <?php if ($flash !== ''): ?><p class="blk-flash" role="status"><?= $h($flash) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="blk-error" role="alert"><?= $h($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/blokkade.php">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <label>E-mailadres <input type="email" name="email" value="<?= $h($email) ?>"></label>
      <label>Website of domein <input type="text" name="website" value="<?= $h($website) ?>"></label>
      <button type="submit" name="do" value="check">Controleren</button>
      <button type="submit" name="do" value="lift">Blokkade opheffen</button>
    </form>
  </main>
</body>
</html>
