<?php

declare(strict_types=1);

/**
 * Include partial for Instellingen > Bedrijf.
 *
 * One-line include (after boot + login + user row):
 *   require __DIR__ . '/../gsc/settings-card.php';
 * from e.g. app/meer/bedrijf.php, or:
 *   require dirname(__DIR__) . '/gsc/settings-card.php';
 *
 * Expected in scope: $pdo (PDO), $config (array), $user (users row).
 * Optional: $gsc_next (safe return path, default current path).
 */

if (!function_exists('ai_scan_gsc_settings_view')) {
    require_once dirname(__DIR__, 2) . '/lib/gsc.php';
}

/** @var PDO $pdo */
/** @var array<string, mixed> $config */
/** @var array<string, mixed> $user */

$gsc = ai_scan_gsc_settings_view($pdo, $config, $user);
$gscNext = isset($gsc_next) && is_string($gsc_next)
    ? ai_scan_gsc_safe_next($gsc_next, '/dashboard/')
    : ai_scan_gsc_safe_next((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard/'), '/dashboard/');

$propertyLabel = $gsc['property_uri'] !== ''
    ? $gsc['property_uri']
    : '';
$connectedAtLabel = '';
if ($gsc['connected_at'] !== null) {
    $connectedAtLabel = (new DateTimeImmutable('@' . $gsc['connected_at']))
        ->setTimezone(new DateTimeZone('Europe/Amsterdam'))
        ->format('d-m-Y');
}

?>
<section class="gsc-card" aria-labelledby="gsc-card-title">
  <style>
    .gsc-card {
      --gsc-ink: #1a1f24;
      --gsc-muted: #5b6475;
      --gsc-line: #d7ddd8;
      --gsc-accent: #0b6bcb;
      --gsc-bg: #f7f8f7;
      border-top: 1px solid var(--gsc-line);
      padding: 1.5rem 0 0.25rem;
      margin-top: 1.75rem;
      color: var(--gsc-ink);
      font: 400 1rem/1.5 system-ui, "Segoe UI", sans-serif;
    }
    .gsc-card__title {
      margin: 0 0 0.35rem;
      font-size: 1.125rem;
      font-weight: 600;
      letter-spacing: -0.01em;
    }
    .gsc-card__lead {
      margin: 0 0 1rem;
      color: var(--gsc-muted);
      font-size: 0.95rem;
      max-width: 36rem;
    }
    .gsc-card__status {
      display: grid;
      gap: 0.35rem;
      margin: 0 0 1.1rem;
      padding: 0.9rem 1rem;
      background: var(--gsc-bg);
      border-radius: 0.35rem;
    }
    .gsc-card__status dt {
      margin: 0;
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--gsc-muted);
    }
    .gsc-card__status dd {
      margin: 0 0 0.55rem;
      font-size: 0.98rem;
      word-break: break-word;
    }
    .gsc-card__status dd:last-child { margin-bottom: 0; }
    .gsc-card__note {
      margin: 0 0 1rem;
      font-size: 0.92rem;
      color: var(--gsc-muted);
      max-width: 36rem;
    }
    .gsc-card__locked {
      margin: 0 0 0.75rem;
      font-size: 0.95rem;
      color: var(--gsc-ink);
    }
    .gsc-card__actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
    }
    .gsc-card__actions button {
      min-height: 44px;
      padding: 0 1.1rem;
      border-radius: 0.35rem;
      border: 1px solid var(--gsc-accent);
      background: var(--gsc-accent);
      color: #fff;
      font: inherit;
      font-weight: 550;
      cursor: pointer;
    }
    .gsc-card__actions button.gsc-card__disconnect {
      background: transparent;
      color: #a84832;
      border-color: #a84832;
    }
    .gsc-card__actions button:disabled {
      opacity: 0.55;
      cursor: not-allowed;
    }
    .gsc-card__badge {
      display: inline-block;
      font-size: 0.8rem;
      color: var(--gsc-muted);
    }
  </style>

  <h2 id="gsc-card-title" class="gsc-card__title">Google Search Console</h2>
  <p class="gsc-card__lead">
    Tweede meetbron naast de AI-scan: zoekopdrachten, klikken en zichtbaarheid
    voor je kernvragen, rechtstreeks uit jouw eigen Search Console-property.
  </p>

  <?php if ($gsc['read_only']): ?>
    <p class="gsc-card__locked"><?= htmlspecialchars($gsc['free_message'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($gsc['connected']): ?>
      <dl class="gsc-card__status">
        <dt>Status</dt>
        <dd>Gekoppeld (alleen lezen)</dd>
        <dt>Property</dt>
        <dd><?= htmlspecialchars($propertyLabel, ENT_QUOTES, 'UTF-8') ?></dd>
      </dl>
    <?php else: ?>
      <p class="gsc-card__note">
        Koppelen en ontkoppelen kan met een abonnement. Je ziet hier dan je eigen property.
      </p>
    <?php endif; ?>
  <?php elseif (!$gsc['configured']): ?>
    <p class="gsc-card__note">
      Koppelen is nog niet beschikbaar. We richten Google OAuth eerst in; daarna kun je hier verbinden.
    </p>
  <?php elseif ($gsc['connected']): ?>
    <dl class="gsc-card__status">
      <dt>Status</dt>
      <dd>Gekoppeld<?= $connectedAtLabel !== '' ? ' op ' . htmlspecialchars($connectedAtLabel, ENT_QUOTES, 'UTF-8') : '' ?></dd>
      <dt>Property</dt>
      <dd><?= htmlspecialchars($propertyLabel, ENT_QUOTES, 'UTF-8') ?></dd>
    </dl>
    <p class="gsc-card__note">
      We halen klikken, impressies, CTR en positie op uit je web-prestatierapport
      (Search Analytics) voor vragen die bij je kernvragen passen.
    </p>
    <div class="gsc-card__actions">
      <form method="post" action="<?= htmlspecialchars($gsc['disconnect_action'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($gsc['csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="next" value="<?= htmlspecialchars($gscNext, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="gsc-card__disconnect">Ontkoppelen</button>
      </form>
      <span class="gsc-card__badge">Alleen-lezen toegang · webmasters.readonly</span>
    </div>
  <?php else: ?>
    <p class="gsc-card__note">
      Nog niet gekoppeld. Je verbindt je eigen property via Google (alleen lezen).
      Wij slaan geen wachtwoord op — alleen een versleutelde toegangstoken.
    </p>
    <div class="gsc-card__actions">
      <form method="post" action="<?= htmlspecialchars($gsc['connect_action'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($gsc['csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="next" value="<?= htmlspecialchars($gscNext, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Koppel Google Search Console</button>
      </form>
    </div>
  <?php endif; ?>
</section>
