<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/_action_card.php';

$ctx = app_boot('acties');
$flashLocal = '';
$errorLocal = '';

if (ai_scan_request_method() === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    $action = ai_scan_clean_input('action', 40);
    $publicId = ai_scan_clean_input('public_id', 80);
    if (!ai_scan_verify_csrf($ctx['config'], $token)) {
        $errorLocal = 'De sessie is verlopen. Probeer het opnieuw.';
    } else {
        $status = $action === 'live' ? 'live_gemeld' : ($action === 'reject' ? 'afgewezen' : '');
        if ($status === '') {
            $errorLocal = 'Onbekende actie.';
        } else {
            $res = ai_scan_action_set_status(
                $ctx['pdo'],
                $ctx['user'],
                $ctx['result'],
                $publicId,
                $status,
                $ctx['tiers']
            );
            if (!empty($res['ok'])) {
                $flashLocal = (string) ($res['message'] ?? 'Opgeslagen.');
                $ctx['actions_used'] = ai_scan_usage_actions_used($ctx['pdo'], (int) $ctx['user']['id']);
            } else {
                $errorLocal = (string) ($res['error'] ?? 'Er ging iets mis.');
            }
        }
    }
}

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
$hiddenCount = $isFree ? max(0, count($all) - count($visible)) : 0;
$used = (int) $ctx['actions_used'];
$limit = (int) $ctx['actions_limit'];
$csrf = (string) $ctx['csrf'];

app_render_layout($ctx, 'Acties', static function (array $ctx) use (
    $current,
    $archive,
    $isFree,
    $hiddenCount,
    $used,
    $limit,
    $csrf,
    $flashLocal,
    $errorLocal
): void {
    ?>
    <h1>Acties</h1>
    <p class="app-lead"><?= (int) $used ?> van <?= (int) $limit ?> deze maand</p>

    <?php if ($flashLocal !== ''): ?>
      <div class="app-flash" role="status"><?= app_h($flashLocal) ?></div>
    <?php endif; ?>
    <?php if ($errorLocal !== ''): ?>
      <p class="field__error" role="alert"><?= app_h($errorLocal) ?></p>
    <?php endif; ?>

    <div class="action-toolbar">
      <button type="button" class="text-btn" data-actions-toolbar data-mode="expand">Alles openklappen</button>
    </div>

    <h2>Deze week</h2>
    <?php if ($current === []): ?>
      <p class="app-empty">Er staan deze week nog geen acties klaar.</p>
    <?php else: ?>
      <?php foreach ($current as $action):
          app_render_action_card($action, $isFree, false, $csrf);
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
          app_render_action_card($action, $isFree, false, $csrf);
      endforeach;
      if (count($archive) > 10):
          ?>
        <button type="button" class="text-btn" disabled>Meer laden</button>
      <?php endif; ?>
    <?php endif; ?>
    <?php
});
