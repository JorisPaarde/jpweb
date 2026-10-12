<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

try {
    ai_scan_check_stale_jobs($pdo, $config);
    ai_scan_promote_waitlist($pdo, $config);
} catch (Throwable $e) {
    // ignore
}

$userId = ai_scan_require_user($config);
$scan = ai_scan_latest_for_user($pdo, $userId);
$wantsJson = ai_scan_wants_json() || (string) ($_GET['format'] ?? '') === 'json';

if ($wantsJson) {
    if ($scan === null) {
        ai_scan_json_response(['ok' => true, 'status' => 'none', 'result' => null]);
    }

    $resultJson = null;
    if (!empty($scan['result_json'])) {
        $decoded = json_decode((string) $scan['result_json'], true);
        $resultJson = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    ai_scan_json_response([
        'ok' => true,
        'status' => (string) $scan['status'],
        'domain' => (string) $scan['domain'],
        'website_url' => (string) $scan['website_url'],
        'public_id' => (string) $scan['public_id'],
        'result' => $resultJson,
        'error_message' => $scan['error_message'] !== null ? (string) $scan['error_message'] : null,
        'completed_at' => $scan['completed_at'] !== null ? (int) $scan['completed_at'] : null,
    ]);
}

$status = $scan ? (string) $scan['status'] : 'none';
$domain = $scan ? htmlspecialchars((string) $scan['domain'], ENT_QUOTES, 'UTF-8') : '';
$csrf = ai_scan_csrf_token($config);

?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Dashboard · AI-scan</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .ai-dash { max-width: 52rem; margin: 2.5rem auto; padding: 0 1.25rem 4rem; }
    .ai-dash__bar { display: flex; justify-content: space-between; gap: 1rem; align-items: center; margin-bottom: 1.5rem; }
    .ai-dash__bar a { color: var(--green, #315f4f); }
    .ai-status {
      border: 1px solid #d7ddd8; background: #fff; border-radius: 0.5rem;
      padding: 1.25rem 1.35rem; margin-bottom: 1.5rem;
    }
    .ai-status[data-status="bezig"],
    .ai-status[data-status="bevestigd"] { border-color: #c9d0cc; }
    .ai-status[data-status="wacht_dag"] { border-color: #e7ad52; }
    .ai-status[data-status="mislukt"] { border-color: #a84832; }
    .ai-result { margin-top: 1rem; }
    .ai-danger { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid #e2e6e2; }
    .ai-danger button {
      background: transparent; color: #a84832; border: 1px solid #a84832;
      border-radius: 0.4rem; min-height: 44px; padding: 0 1rem; cursor: pointer; font: inherit;
    }
  </style>
</head>
<body>
  <main class="ai-dash">
    <div class="ai-dash__bar">
      <h1>AI-scan dashboard<?= $domain !== '' ? ' · ' . $domain : '' ?></h1>
      <a href="/logout.php">Uitloggen</a>
    </div>

    <?php if ($scan === null): ?>
      <div class="ai-status" data-status="none">
        <p>Er is nog geen scan gekoppeld aan dit account.</p>
      </div>
    <?php elseif ($status === 'klaar' && !empty($scan['result_html'])): ?>
      <div class="ai-result">
        <?= $scan['result_html'] /* runner-supplied HTML for this user only */ ?>
      </div>
    <?php elseif ($status === 'mislukt'): ?>
      <div class="ai-status" data-status="mislukt" aria-live="polite">
        <p>De scan is niet gelukt. Ik kijk ernaar en start hem opnieuw. Je krijgt een mail als hij klaar is.</p>
      </div>
    <?php elseif ($status === 'wacht_dag'): ?>
      <div class="ai-status" data-status="wacht_dag" aria-live="polite">
        <p>Je scan staat in de rij. Zodra er weer ruimte is, start hij automatisch. Je krijgt een mail.</p>
      </div>
    <?php else: ?>
      <div class="ai-status" data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" aria-live="polite">
        <p><strong>Je scan loopt.</strong> We lezen je website, stellen vragen aan AI-assistenten en tellen hoe vaak je genoemd wordt. Je krijgt een mail als het klaar is; je hoeft deze pagina niet open te houden.</p>
      </div>
      <script>
        (function () {
          var started = Date.now();
          var timer = setInterval(function () {
            if (Date.now() - started > 30 * 60 * 1000) {
              clearInterval(timer);
              return;
            }
            fetch('/dashboard/?format=json', { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data && (data.status === 'klaar' || data.status === 'mislukt')) {
                  location.reload();
                }
              })
              .catch(function () {});
          }, 30000);
        })();
      </script>
    <?php endif; ?>

    <section class="ai-danger">
      <h2>Gegevens verwijderen</h2>
      <p>Verwijder je account, uitslag en e-mailadres. Alleen een onomkeerbare code blijft om een tweede gratis scan te voorkomen.</p>
      <form method="post" action="/account-delete.php" onsubmit="return confirm('Account en scanuitslag definitief verwijderen?');">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Verwijder mijn account</button>
      </form>
    </section>
  </main>
</body>
</html>
