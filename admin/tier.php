<?php

declare(strict_types=1);

/**
 * Beheerder-only: set tier_id / custom_questions / status / period_end.
 * Facturatie blijft buiten de app (Rompslomp).
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$admin = ai_scan_require_admin_user($pdo, $config);
$tiers = ai_scan_load_tiers();
$csrf = ai_scan_csrf_token($config);
$flash = '';
$error = '';

if (ai_scan_request_method() === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    if (!ai_scan_verify_csrf($config, $token)) {
        $error = 'Sessie verlopen. Probeer opnieuw.';
    } else {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $tierId = ai_scan_clean_input('tier_id', 40);
        $status = ai_scan_clean_input('subscription_status', 40);
        $customRaw = trim((string) ($_POST['custom_questions'] ?? ''));
        $periodEndRaw = trim((string) ($_POST['period_end'] ?? ''));
        $billingPeriod = ai_scan_clean_input('billing_period', 10);
        if ($billingPeriod === '') {
            $billingPeriod = null;
        }

        $fields = [
            'tier_id' => $tierId !== '' ? $tierId : 'free',
            'subscription_status' => $status !== '' ? $status : 'none',
            'custom_questions' => $customRaw === '' ? null : (int) $customRaw,
            'billing_period' => $billingPeriod,
            'period_end' => null,
        ];
        if ($periodEndRaw !== '') {
            if (ctype_digit($periodEndRaw)) {
                $fields['period_end'] = (int) $periodEndRaw;
            } else {
                $ts = strtotime($periodEndRaw . ' Europe/Amsterdam');
                $fields['period_end'] = $ts === false ? null : $ts;
            }
        }

        $result = ai_scan_admin_set_tier($pdo, $config, $targetId, $fields, 'admin');
        if (!empty($result['ok'])) {
            $flash = 'Tier opgeslagen'
                . ((int) ($result['paused'] ?? 0) > 0 ? ' (' . (int) $result['paused'] . ' vragen gepauzeerd)' : '')
                . (!empty($result['mailed']) ? '; klant gemaild' : '')
                . '.';
        } else {
            $error = 'Opslaan mislukt (' . (string) ($result['error'] ?? 'error') . ').';
        }
    }
}

$users = $pdo->query(
    'SELECT id, email, tier_id, custom_questions, subscription_status, billing_period, period_end, tier_changed_at
     FROM users ORDER BY id DESC LIMIT 200'
)->fetchAll();

$tierOptions = [['id' => 'free', 'label' => 'free']];
foreach ($tiers['tiers'] as $t) {
    if (!is_array($t)) {
        continue;
    }
    $tierOptions[] = [
        'id' => (string) $t['id'],
        'label' => (string) $t['id'] . ' · ' . (int) $t['questions'] . ' vragen',
    ];
}
$tierOptions[] = ['id' => 'custom', 'label' => 'custom (>1000)'];

?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Tiers · beheer</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .tier-admin { max-width: 56rem; margin: 2rem auto; padding: 0 1.25rem 3rem; }
    .tier-admin table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
    .tier-admin th, .tier-admin td { text-align: left; padding: 0.55rem 0.4rem; border-bottom: 1px solid #e2e6e2; vertical-align: top; }
    .tier-admin form.inline { display: grid; gap: 0.35rem; }
    .tier-admin select, .tier-admin input { min-height: 40px; font: inherit; }
    .tier-admin button { min-height: 44px; padding: 0 0.9rem; cursor: pointer; font: inherit; }
    .tier-flash { padding: 0.75rem 1rem; background: #eef6f1; margin-bottom: 1rem; }
    .tier-error { padding: 0.75rem 1rem; background: #f8e8e4; margin-bottom: 1rem; }
    .tier-admin__bar { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; }
  </style>
</head>
<body>
  <main class="tier-admin">
    <div class="tier-admin__bar">
      <h1>Abonnementen</h1>
      <p><?= htmlspecialchars((string) $admin['email'], ENT_QUOTES, 'UTF-8') ?> · <a href="/dashboard/">Dashboard</a></p>
    </div>

    <?php if ($flash !== ''): ?>
      <p class="tier-flash" role="status"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <p class="tier-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <table>
      <thead>
        <tr>
          <th>Id</th>
          <th>E-mail</th>
          <th>Huidig</th>
          <th>Aanpassen</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <?php
          $uid = (int) $u['id'];
          $curTier = (string) ($u['tier_id'] ?? 'free');
          $curStatus = (string) ($u['subscription_status'] ?? 'none');
          $curCustom = $u['custom_questions'] !== null ? (string) $u['custom_questions'] : '';
          $curBilling = (string) ($u['billing_period'] ?? '');
          $curEnd = $u['period_end'] !== null ? (string) $u['period_end'] : '';
        ?>
        <tr>
          <td><?= $uid ?></td>
          <td><?= htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8') ?></td>
          <td>
            <?= htmlspecialchars($curTier, ENT_QUOTES, 'UTF-8') ?>
            · <?= htmlspecialchars($curStatus, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($curCustom !== ''): ?>
              · <?= htmlspecialchars($curCustom, ENT_QUOTES, 'UTF-8') ?> vrg
            <?php endif; ?>
          </td>
          <td>
            <form class="inline" method="post" action="/admin/tier.php">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="user_id" value="<?= $uid ?>">
              <label>Tier
                <select name="tier_id">
                  <?php foreach ($tierOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt['id'], ENT_QUOTES, 'UTF-8') ?>"<?= $curTier === $opt['id'] ? ' selected' : '' ?>>
                      <?= htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Status
                <select name="subscription_status">
                  <?php foreach (['none', 'active', 'canceling', 'past_due', 'ended'] as $st): ?>
                    <option value="<?= $st ?>"<?= $curStatus === $st ? ' selected' : '' ?>><?= $st ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Custom vragen
                <input type="number" name="custom_questions" min="1100" step="100" value="<?= htmlspecialchars($curCustom, ENT_QUOTES, 'UTF-8') ?>" placeholder="alleen bij custom">
              </label>
              <label>Periode
                <select name="billing_period">
                  <option value=""<?= $curBilling === '' ? ' selected' : '' ?>>—</option>
                  <option value="month"<?= $curBilling === 'month' ? ' selected' : '' ?>>month</option>
                  <option value="year"<?= $curBilling === 'year' ? ' selected' : '' ?>>year</option>
                </select>
              </label>
              <label>Period end (unix of datum)
                <input type="text" name="period_end" value="<?= htmlspecialchars($curEnd, ENT_QUOTES, 'UTF-8') ?>" placeholder="optioneel">
              </label>
              <button type="submit">Opslaan</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($users === []): ?>
        <tr><td colspan="4">Nog geen accounts.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </main>
</body>
</html>
