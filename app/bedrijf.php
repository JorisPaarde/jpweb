<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('bedrijf');
$profile = $ctx['profile'];
$isFree = !empty($ctx['is_free']);
// Bedrijfsgegevens mag iedereen aanvullen, ook met een gratis account (voorgevuld uit de scan).
$canWrite = !empty($ctx['can_write']) || $isFree;
$toast = '';

if (ai_scan_request_method() === 'POST' && $canWrite) {
    $token = (string) ($_POST['csrf'] ?? '');
    if (!ai_scan_verify_csrf($ctx['config'], $token)) {
        $toast = 'De sessie is verlopen. Probeer het opnieuw.';
    } else {
        $company = ai_scan_clean_input('company', 80);
        $audience = ai_scan_clean_input('audience', 200);
        $aliasesRaw = ai_scan_clean_input('aliases', 800);
        $servicesRaw = ai_scan_clean_input('services', 1200);
        $placesRaw = ai_scan_clean_input('places', 800);
        $aliases = array_values(array_filter(array_map('trim', explode(',', $aliasesRaw))));
        $services = array_values(array_filter(array_map('trim', explode(',', $servicesRaw))));
        $places = array_values(array_filter(array_map('trim', explode(',', $placesRaw))));
        $aliases = array_slice($aliases, 0, 10);
        $services = array_slice($services, 0, 15);
        $places = array_slice($places, 0, 10);

        $pdo = $ctx['pdo'];
        $userId = (int) $ctx['user']['id'];
        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO account_profile(user_id, company, website, aliases_json, services_json, places_json, audience, updated_at)
             VALUES(:u, :c, :w, :a, :s, :p, :aud, :ts)
             ON CONFLICT(user_id) DO UPDATE SET
               company = excluded.company,
               aliases_json = excluded.aliases_json,
               services_json = excluded.services_json,
               places_json = excluded.places_json,
               audience = excluded.audience,
               updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'u' => $userId,
            'c' => $company,
            'w' => (string) ($profile['website'] ?? ''),
            'a' => json_encode($aliases, JSON_UNESCAPED_UNICODE),
            's' => json_encode($services, JSON_UNESCAPED_UNICODE),
            'p' => json_encode($places, JSON_UNESCAPED_UNICODE),
            'aud' => $audience,
            'ts' => $now,
        ]);
        $pdo->prepare(
            'INSERT INTO settings_log(user_id, ts, by_role, field, old_value, new_value, effective_week, versions_json)
             VALUES(:u, :ts, :role, :field, :old, :new, :week, :versions)'
        )->execute([
            'u' => $userId,
            'ts' => $now,
            'role' => 'Eigenaar',
            'field' => 'bedrijf',
            'old' => (string) ($profile['company'] ?? ''),
            'new' => $company,
            'week' => 'volgende meting',
            'versions' => null,
        ]);
        $toast = $isFree
            ? 'Opgeslagen. Ik gebruik dit bij je volgende meting.'
            : 'Opgeslagen. Gaat in bij de meting van maandag ' . app_next_monday_label() . '.';
        $ctx['profile'] = app_load_profile($pdo, $userId, $ctx['scan'], $ctx['result']);
        $profile = $ctx['profile'];
        if ($company !== '') {
            $ctx['company'] = $company;
        }
    }
}

app_render_layout($ctx, 'Bedrijf', static function (array $ctx) use ($profile, $canWrite, $isFree, $toast): void {
    $csrf = (string) $ctx['csrf'];
    $readOnlyAttr = $canWrite ? '' : 'readonly disabled';
    ?>
    <h1>Bedrijf</h1>
    <?php if ($toast !== ''): ?>
      <div class="app-flash" role="status"><?= app_h($toast) ?></div>
    <?php endif; ?>
    <?php if ($isFree): ?>
      <p class="app-locked">Deze gegevens heb ik uit je website gehaald bij de gratis scan. Klopt iets niet of mist er iets? Pas het aan en sla op.</p>
    <?php endif; ?>

    <form method="post" action="/app/bedrijf.php" novalidate>
      <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">

      <label class="field">
        <span class="field__label">Bedrijfsnaam</span>
        <input name="company" maxlength="80" value="<?= app_h((string) ($profile['company'] ?? '')) ?>" <?= $readOnlyAttr ?>>
      </label>

      <div class="field">
        <span class="field__label">Website</span>
        <input value="<?= app_h((string) ($profile['website'] ?? '')) ?>" readonly disabled>
        <?php if (!$isFree): ?>
          <p class="app-locked">Je website wijzigen doe je via mij, omdat je meting dan opnieuw begint. <a href="/app/abonnement.php">Vraag het aan</a></p>
        <?php endif; ?>
      </div>

      <label class="field">
        <span class="field__label">Merknamen (aliassen)</span>
        <input name="aliases" value="<?= app_h(implode(', ', $profile['aliases'] ?? [])) ?>" <?= $readOnlyAttr ?> placeholder="bijv. Merk A, Merk B">
      </label>

      <label class="field">
        <span class="field__label">Diensten</span>
        <input name="services" value="<?= app_h(implode(', ', $profile['services'] ?? [])) ?>" <?= $readOnlyAttr ?>>
      </label>

      <label class="field">
        <span class="field__label">Plaatsen / werkgebied</span>
        <input name="places" value="<?= app_h(implode(', ', $profile['places'] ?? [])) ?>" <?= $readOnlyAttr ?>>
      </label>

      <label class="field">
        <span class="field__label">Doelgroep</span>
        <textarea name="audience" maxlength="200" <?= $readOnlyAttr ?>><?= app_h((string) ($profile['audience'] ?? '')) ?></textarea>
      </label>

      <?php if ($canWrite): ?>
        <div class="sticky-save">
          <button class="btn btn--block" type="submit">Opslaan</button>
        </div>
      <?php endif; ?>
    </form>
    <?php
});
