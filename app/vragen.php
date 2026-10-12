<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('vragen');
$q = app_load_questions_view($ctx['pdo'], (int) $ctx['user']['id'], $ctx['result'], $ctx['profile']);
$nCore = count($q['core']);
$tierQ = (int) $ctx['effective']['questions'];
$canWrite = !empty($ctx['can_write']);
$isFree = !empty($ctx['is_free']);

app_render_layout($ctx, 'Vragen', static function (array $ctx) use ($q, $nCore, $tierQ, $canWrite, $isFree): void {
    $csrf = (string) $ctx['csrf'];
    ?>
    <h1>Vragen</h1>
    <p class="app-lead">Dit zijn de vragen die we AI-assistenten stellen over jouw diensten en plaatsen.</p>
    <p><?= (int) $nCore ?> van <?= (int) $tierQ ?></p>

    <h2>Vaste vragen (<?= (int) $nCore ?>, versie <?= (int) $q['core_version'] ?>)</h2>
    <?php if ($q['core'] === []): ?>
      <p class="app-empty">Er staan nog geen vragen. Na je scan zie je hier welke vragen we AI-assistenten stelden.</p>
    <?php else: ?>
      <ul class="app-list">
        <?php foreach ($q['core'] as $item):
            $text = (string) ($item['text'] ?? '');
            $named = $item['named'] ?? null;
            if ($named === null && isset($item['mentioned'], $item['answers'])) {
                $named = ((int) $item['mentioned']) > 0;
            }
            $k = isset($item['mentioned']) ? (int) $item['mentioned'] : null;
            $m = isset($item['answers']) ? (int) $item['answers'] : null;
            ?>
          <li class="q-item">
            <div class="q-item__top">
              <div>
                <?php if ($named): ?>
                  <span class="q-hit">✓</span>
                  <?php if ($k !== null && $m !== null): ?>
                    <span class="app-list__muted">genoemd in <?= $k ?> van <?= $m ?></span>
                  <?php else: ?>
                    <span class="app-list__muted">genoemd</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="q-miss">○</span>
                  <span class="app-list__muted">niet genoemd</span>
                <?php endif; ?>
                <?php if (!empty($item['draft'])): ?>
                  <span class="pill pill--draft">◷ Gaat in bij de volgende meting</span>
                <?php endif; ?>
                <p><?= app_h($text) ?></p>
              </div>
              <?php if ($canWrite): ?>
                <details>
                  <summary aria-label="Meer acties">⋯</summary>
                  <button type="button" class="text-btn" data-open-dialog="dialog-versiebreuk">Wijzigen</button>
                  <button type="button" class="text-btn" data-open-dialog="dialog-versiebreuk">Verwijderen</button>
                </details>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($isFree): ?>
      <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
    <?php elseif ($nCore >= $tierQ): ?>
      <p class="field__error" role="alert"><?= app_h(ai_scan_questions_limit_message($tierQ)) ?></p>
    <?php endif; ?>

    <h2>Wisselende vragen deze week</h2>
    <?php if ($q['rotating'] === []): ?>
      <p class="app-empty">Deze week staan er geen wisselende vragen.</p>
    <?php else: ?>
      <ul class="app-list">
        <?php foreach ($q['rotating'] as $item): ?>
          <li><?= app_h((string) ($item['text'] ?? '')) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <h2>Per plaats</h2>
    <?php if ($q['by_place'] === []): ?>
      <p class="app-empty">Nog geen uitsplitsing per plaats.</p>
    <?php else: ?>
      <div class="badge-row">
        <?php foreach ($q['by_place'] as $p):
            $answers = (int) ($p['answers'] ?? 0);
            $early = $answers < 20;
            ?>
          <span class="badge">
            <?= app_h((string) $p['place']) ?>
            · <?= (int) ($p['mentioned'] ?? 0) ?>/<?= $answers ?>
            <?php if ($early): ?><span title="Te vroeg">◷</span><?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($canWrite): ?>
      <p style="margin-top:1.25rem">
        <button type="button" class="btn" data-open-dialog="dialog-versiebreuk">Vraag toevoegen</button>
      </p>
    <?php else: ?>
      <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
    <?php endif; ?>

    <div class="dialog-backdrop" id="dialog-versiebreuk" role="presentation" hidden>
      <div class="dialog" role="alertdialog" aria-labelledby="versiebreuk-title" aria-describedby="versiebreuk-desc">
        <h2 id="versiebreuk-title">Je trend begint hierna opnieuw</h2>
        <p id="versiebreuk-desc">
          Als je de vragen wijzigt, kunnen we de nieuwe metingen niet meer eerlijk vergelijken met de oude.
          Je oude cijfers blijven zichtbaar, maar de lijn begint vanaf <?= app_h(app_next_monday_label()) ?> opnieuw.
          De wijziging gaat in bij de meting van maandag <?= app_h(app_next_monday_label()) ?>.
        </p>
        <div class="dialog__actions">
          <button type="button" class="btn btn--ghost" data-dialog-cancel>Annuleren</button>
          <button type="button" class="btn" disabled>Ja, wijzig de vragen</button>
        </div>
      </div>
    </div>
    <?php
});
