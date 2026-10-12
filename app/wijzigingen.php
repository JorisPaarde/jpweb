<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('wijzigingen');
$pdo = $ctx['pdo'];
$userId = (int) $ctx['user']['id'];

$stmt = $pdo->prepare(
    'SELECT * FROM settings_log WHERE user_id = :u ORDER BY ts DESC, id DESC LIMIT 100'
);
$stmt->execute(['u' => $userId]);
$logs = $stmt->fetchAll();
if (!is_array($logs)) {
    $logs = [];
}

app_render_layout($ctx, 'Wijzigingen', static function (array $ctx) use ($logs): void {
    ?>
    <h1>Wijzigingen</h1>
    <p class="app-lead">Geschiedenis van aanpassingen aan vragen, concurrenten en bedrijfsgegevens.</p>

    <?php if ($logs === []): ?>
      <p class="app-empty">Nog geen wijzigingen.</p>
    <?php else: ?>
      <ul class="app-list">
        <?php foreach ($logs as $row):
            $ts = (int) ($row['ts'] ?? 0);
            $week = (string) ($row['effective_week'] ?? '');
            $undone = !empty($row['undone']);
            ?>
          <li class="change-item">
            <div>
              <strong><?= app_h(app_format_nl_date(max(1, $ts))) ?></strong>
              · <?= app_h((string) ($row['by_role'] ?? 'Eigenaar')) ?>
            </div>
            <div><?= app_h((string) ($row['field'] ?? '')) ?></div>
            <div class="app-list__muted">
              <?= app_h((string) ($row['old_value'] ?? '')) ?>
              →
              <?= app_h((string) ($row['new_value'] ?? '')) ?>
            </div>
            <?php if ($week !== ''): ?>
              <div class="app-list__muted">
                <?= str_starts_with($week, 'Ingegaan') || str_starts_with($week, 'Gaat')
                    ? app_h($week)
                    : app_h('Gaat in bij meting ' . $week) ?>
              </div>
            <?php endif; ?>
            <?php if (!$undone && $week !== '' && !str_starts_with($week, 'Ingegaan')): ?>
              <button type="button" class="text-btn" disabled>Ongedaan maken</button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php
});
