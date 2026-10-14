<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('concurrenten');
$flashLocal = '';
$errorLocal = '';

if (ai_scan_request_method() === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    $action = ai_scan_clean_input('action', 40);
    if (!ai_scan_verify_csrf($ctx['config'], $token)) {
        $errorLocal = 'De sessie is verlopen. Probeer het opnieuw.';
    } elseif (!empty($ctx['result']['demo'])) {
        $errorLocal = 'Dit is een voorbeeldaccount; hier kun je niets aanpassen.';
    } elseif ($action === 'add') {
        $name = ai_scan_clean_input('name', 80);
        $aliasesRaw = ai_scan_clean_input('aliases', 400);
        $domainsRaw = ai_scan_clean_input('domains', 200);
        $aliases = array_values(array_filter(array_map('trim', explode(',', $aliasesRaw))));
        $domains = array_values(array_filter(array_map('trim', explode(',', $domainsRaw))));
        $res = ai_scan_competitor_add(
            $ctx['pdo'],
            $ctx['user'],
            $ctx['profile'],
            $ctx['result'],
            $name,
            $aliases,
            $domains,
            'handmatig',
            $ctx['tiers']
        );
        if (!empty($res['ok'])) {
            $flashLocal = (string) ($res['message'] ?? 'Opgeslagen.');
            $ctx['profile'] = app_load_profile($ctx['pdo'], (int) $ctx['user']['id'], $ctx['scan'], $ctx['result']);
        } else {
            $errorLocal = (string) ($res['error'] ?? 'Er ging iets mis.');
        }
    } elseif ($action === 'delete') {
        $cid = (int) ($_POST['competitor_id'] ?? 0);
        $res = ai_scan_competitor_delete(
            $ctx['pdo'],
            $ctx['user'],
            $ctx['result'],
            $cid,
            $ctx['tiers']
        );
        if (!empty($res['ok'])) {
            $flashLocal = (string) ($res['message'] ?? 'Opgeslagen.');
            $ctx['profile'] = app_load_profile($ctx['pdo'], (int) $ctx['user']['id'], $ctx['scan'], $ctx['result']);
        } else {
            $errorLocal = (string) ($res['error'] ?? 'Er ging iets mis.');
        }
    } elseif ($action === 'add_proposal') {
        $name = ai_scan_clean_input('name', 80);
        $res = ai_scan_competitor_add(
            $ctx['pdo'],
            $ctx['user'],
            $ctx['profile'],
            $ctx['result'],
            $name,
            [],
            [],
            'AI-antwoord',
            $ctx['tiers']
        );
        if (!empty($res['ok'])) {
            $flashLocal = (string) ($res['message'] ?? 'Opgeslagen.');
        } else {
            $errorLocal = (string) ($res['error'] ?? 'Er ging iets mis.');
        }
    }
}

$view = app_load_competitors_view($ctx['pdo'], (int) $ctx['user']['id'], $ctx['result'], !empty($ctx['is_free']));
$isFree = !empty($ctx['is_free']);
// Concurrentenlijst mag iedereen aanpassen (ook gratis); voorstellen blijven abonnement.
$canWrite = !empty($ctx['can_write']) || $isFree;
$version = (int) ($ctx['profile']['competitors_version'] ?? 1);
$listCount = count($view['list']);

app_render_layout($ctx, 'Concurrenten', static function (array $ctx) use (
    $view,
    $canWrite,
    $isFree,
    $version,
    $listCount,
    $flashLocal,
    $errorLocal
): void {
    $csrf = (string) $ctx['csrf'];
    ?>
    <h1>Concurrenten</h1>
    <p class="app-lead">Wie AI noemt, en de vaste lijst die ik tel.</p>

    <?php if ($flashLocal !== ''): ?>
      <div class="app-flash" role="status"><?= app_h($flashLocal) ?></div>
    <?php endif; ?>
    <?php if ($errorLocal !== ''): ?>
      <p class="field__error" role="alert"><?= app_h($errorLocal) ?></p>
    <?php endif; ?>

    <h2>Deze week genoemd</h2>
    <?php if ($view['mentioned'] === []): ?>
      <p class="app-empty">AI noemde bij je vragen geen andere bedrijven. Ken je zelf een concurrent? Voeg hem toe.</p>
    <?php else: ?>
      <ul class="app-list">
        <?php foreach ($view['mentioned'] as $c): ?>
          <li class="<?= !empty($c['is_self']) ? 'is-self' : '' ?>">
            <span><?= app_h((string) $c['name']) ?><?= !empty($c['is_self']) ? ' (jij)' : '' ?></span>
            <span class="app-list__muted"><?= (int) $c['count'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>Je vaste lijst (<?= (int) $listCount ?> van max 10, versie <?= (int) $version ?>)</h2>
    <?php if ($view['list'] === []): ?>
      <?php if ($canWrite): ?>
        <p class="app-empty">AI noemde bij je vragen geen andere bedrijven. Ken je zelf een concurrent? Voeg hem toe.</p>
      <?php else: ?>
        <p class="app-empty">Nog geen vaste concurrentenlijst.</p>
      <?php endif; ?>
    <?php else: ?>
      <ul class="app-list">
        <?php foreach ($view['list'] as $c):
            $domains = isset($c['domains']) && is_array($c['domains']) ? $c['domains'] : [];
            $source = (string) ($c['source'] ?? 'handmatig');
            $sourceLabel = match ($source) {
                'AI-antwoord', 'ai' => 'AI-antwoord',
                'webzoeken', 'web' => 'webzoeken',
                default => 'handmatig',
            };
            $cid = (int) ($c['id'] ?? 0);
            ?>
          <li class="c-item">
            <div class="c-item__top">
              <div>
                <strong><?= app_h((string) $c['name']) ?></strong>
                <?php if ($domains !== []): ?>
                  <div class="app-list__muted"><?= app_h(implode(', ', array_map('strval', $domains))) ?></div>
                <?php endif; ?>
                <div class="app-list__muted"><?= app_h($sourceLabel) ?></div>
              </div>
              <?php if ($canWrite && $cid > 0): ?>
                <form method="post" action="/app/concurrenten.php" onsubmit="return confirm('Dit bedrijf uitsluiten van je lijst?');">
                  <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="competitor_id" value="<?= $cid ?>">
                  <button type="submit" class="text-btn">Verwijderen</button>
                </form>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($isFree): ?>
      <p class="app-locked">Deze concurrenten heb ik bij je gratis scan zelf gevonden. Klopt er een niet, haal hem weg. Mis je er een, voeg hem toe.</p>
    <?php endif; ?>

    <?php if ($canWrite && $listCount < 10): ?>
      <form method="post" action="/app/concurrenten.php" class="plan-card" style="margin-top:1rem">
        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
        <input type="hidden" name="action" value="add">
        <h2>Concurrent toevoegen</h2>
        <label class="field">
          <span class="field__label">Naam</span>
          <input name="name" maxlength="80" minlength="2" required>
        </label>
        <label class="field">
          <span class="field__label">Aliassen (komma-gescheiden, max 5)</span>
          <input name="aliases" maxlength="400">
        </label>
        <label class="field">
          <span class="field__label">Domeinen (komma-gescheiden, max 3)</span>
          <input name="domains" maxlength="200" placeholder="voorbeeld.nl">
        </label>
        <button type="submit" class="btn">Toevoegen</button>
      </form>
    <?php endif; ?>

    <details class="app-details">
      <summary>Uitgesloten</summary>
      <?php if ($view['excluded'] === []): ?>
        <p class="app-empty">Geen uitgesloten bedrijven.</p>
      <?php else: ?>
        <ul class="app-list">
          <?php foreach ($view['excluded'] as $c): ?>
            <li><?= app_h((string) ($c['name'] ?? '')) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </details>

    <h2>Voorstellen</h2>
    <?php if ($isFree): ?>
      <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
    <?php elseif ($view['proposals'] === []): ?>
      <p class="app-empty">Geen nieuwe voorstellen.</p>
    <?php else: ?>
      <ul class="app-list">
        <?php foreach ($view['proposals'] as $c): ?>
          <li>
            <span><?= app_h((string) $c['name']) ?> (<?= (int) $c['count'] ?>)</span>
            <span>
              <?php if ($canWrite): ?>
                <form method="post" action="/app/concurrenten.php" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                  <input type="hidden" name="action" value="add_proposal">
                  <input type="hidden" name="name" value="<?= app_h((string) $c['name']) ?>">
                  <button type="submit" class="text-btn">Toevoegen</button>
                </form>
              <?php else: ?>
                <button type="button" class="text-btn" disabled>Toevoegen</button>
              <?php endif; ?>
              <button type="button" class="text-btn" disabled>Negeren</button>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php
});
