<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/_action_card.php';

$ctx = app_boot('acties');
$all = app_load_actions($ctx['pdo'], (int) $ctx['user']['id'], $ctx['result']);
$isFree = !empty($ctx['is_free']);
$visible = app_actions_for_tier($all, $isFree);
$current = [];
$archive = [];
foreach ($visible as $a) {
    if (!empty($a['archived'])) {
        $archive[] = $a;
    } else {
        $current[] = $a;
    }
}
// Free: only example in "deze week"; locked note for the rest.
$hiddenCount = $isFree ? max(0, count($all) - count($visible)) : 0;
$used = (int) $ctx['actions_used'];
$limit = (int) $ctx['actions_limit'];

app_render_layout($ctx, 'Acties', static function (array $ctx) use ($current, $archive, $isFree, $hiddenCount, $used, $limit): void {
    ?>
    <h1>Acties</h1>
    <p class="app-lead"><?= (int) $used ?> van <?= (int) $limit ?> deze maand</p>

    <div class="action-toolbar">
      <button type="button" class="text-btn" data-actions-toolbar data-mode="expand">Alles openklappen</button>
    </div>

    <h2>Deze week</h2>
    <?php if ($current === []): ?>
      <p class="app-empty">Er staan deze week nog geen acties klaar.</p>
    <?php else: ?>
      <?php foreach ($current as $i => $action):
          app_render_action_card($action, $isFree, false);
      endforeach; ?>
    <?php endif; ?>
    <?php if ($hiddenCount > 0): ?>
      <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
    <?php endif; ?>

    <h2>Eerder</h2>
    <?php if ($archive === []): ?>
      <p class="app-empty">Nog geen eerdere acties.</p>
    <?php else: ?>
      <?php
      $show = array_slice($archive, 0, 10);
      foreach ($show as $action):
          app_render_action_card($action, $isFree, false);
      endforeach;
      if (count($archive) > 10):
          ?>
        <button type="button" class="text-btn" disabled>Meer laden</button>
      <?php endif; ?>
    <?php endif; ?>
    <?php
});
