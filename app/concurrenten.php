<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('concurrenten');
$view = app_load_competitors_view($ctx['pdo'], (int) $ctx['user']['id'], $ctx['result'], !empty($ctx['is_free']));
$canWrite = !empty($ctx['can_write']);
$isFree = !empty($ctx['is_free']);
$version = (int) ($ctx['profile']['competitors_version'] ?? 1);
$listCount = count($view['list']);

app_render_layout($ctx, 'Concurrenten', static function (array $ctx) use ($view, $canWrite, $isFree, $version, $listCount): void {
    ?>
    <h1>Concurrenten</h1>
    <p class="app-lead">Wie AI noemt, en de vaste lijst die we tellen.</p>

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
              <?php if ($canWrite): ?>
                <button type="button" class="text-btn" disabled>Wijzigen</button>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($isFree): ?>
      <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
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
              <button type="button" class="text-btn" <?= $canWrite ? '' : 'disabled' ?>>Toevoegen</button>
              <button type="button" class="text-btn" <?= $canWrite ? '' : 'disabled' ?>>Negeren</button>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php
});
