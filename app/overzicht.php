<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/_action_card.php';

$ctx = app_boot('overzicht');
$score = app_score_view($ctx['result'], $ctx['scan']);
$actions = app_actions_for_tier(
    app_load_actions($ctx['pdo'], (int) $ctx['user']['id'], $ctx['result']),
    $ctx['is_free']
);
$competitors = app_load_competitors_view($ctx['pdo'], (int) $ctx['user']['id'], $ctx['result'], $ctx['is_free']);
$trend = $ctx['result']['trend'] ?? null;
$hasTrend = is_array($trend) && $trend !== [];

app_render_layout($ctx, 'Overzicht', static function (array $ctx) use ($score, $actions, $competitors, $hasTrend, $trend): void {
    $isFree = !empty($ctx['is_free']);
    $csrf = (string) $ctx['csrf'];
    ?>
    <h1>Overzicht</h1>

    <?php if ($score['status'] === 'none'): ?>
      <p class="app-empty">Er is nog geen scan gekoppeld aan dit account.</p>
    <?php elseif (in_array($score['status'], ['bezig', 'bevestigd', 'onbevestigd'], true)): ?>
      <div class="app-score" aria-live="polite">
        <p><strong>Je scan loopt.</strong> Ik lees je website, stel vragen aan AI-assistenten en tel hoe vaak je genoemd wordt. Je krijgt een mail als het klaar is; je hoeft deze pagina niet open te houden.</p>
      </div>
    <?php elseif ($score['status'] === 'wacht_dag'): ?>
      <div class="app-score" aria-live="polite">
        <p>Geen meting deze week — je scan staat in de rij. Zodra er weer ruimte is, start hij automatisch.</p>
      </div>
    <?php elseif ($score['status'] === 'mislukt'): ?>
      <div class="app-score" aria-live="polite">
        <p>De scan is niet gelukt. Ik kijk ernaar en start hem opnieuw. Je krijgt een mail als hij klaar is.</p>
      </div>
    <?php else: ?>
      <section class="app-score" aria-label="Hoofdgetal">
        <?php if ($score['mentioned'] !== null && $score['answers'] !== null): ?>
          <p class="app-score__number"><?= (int) $score['mentioned'] ?> van <?= (int) $score['answers'] ?></p>
        <?php else: ?>
          <p class="app-score__number">Uitslag klaar</p>
        <?php endif; ?>
        <?php if ($score['level'] !== ''): ?>
          <p class="app-score__level"><?= app_h($score['level']) ?></p>
        <?php endif; ?>
      </section>

      <section aria-label="Trend">
        <h2>Trend</h2>
        <?php if ($isFree || !$hasTrend): ?>
          <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
        <?php else: ?>
          <ul class="app-list">
            <?php foreach ($trend as $point):
                if (!is_array($point)) {
                    continue;
                }
                ?>
              <li>
                <span><?= app_h((string) ($point['week'] ?? '')) ?></span>
                <span><?= (int) ($point['mentioned'] ?? 0) ?> / <?= (int) ($point['answers'] ?? 0) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section aria-label="Concurrenten">
        <h2>Concurrenten</h2>
        <?php if ($competitors['mentioned'] === []): ?>
          <p class="app-empty">Nog geen concurrenten in deze meting.</p>
        <?php else: ?>
          <ul class="app-list">
            <?php
            $top = array_slice($competitors['mentioned'], 0, 5);
            foreach ($top as $c):
                ?>
              <li class="<?= !empty($c['is_self']) ? 'is-self' : '' ?>">
                <span><?= app_h((string) $c['name']) ?><?= !empty($c['is_self']) ? ' (jij)' : '' ?></span>
                <span class="app-list__muted"><?= (int) $c['count'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <p><a href="/app/concurrenten.php">Alle concurrenten</a></p>
        <?php if ($isFree): ?>
          <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
        <?php endif; ?>
      </section>

      <section aria-label="Acties">
        <h2>Acties</h2>
        <?php if ($actions === []): ?>
          <p class="app-empty">Er staan deze week nog geen acties klaar.</p>
        <?php else: ?>
          <?php foreach ($actions as $action):
              app_render_action_card($action, $isFree, false, (string) $ctx['csrf']);
          endforeach; ?>
        <?php endif; ?>
        <p><a href="/app/acties.php">Alle acties</a></p>
        <?php if ($isFree): ?>
          <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
        <?php endif; ?>
      </section>

      <?php if ($isFree): ?>
        <section class="app-upgrade" aria-label="Abonnement">
          <p>Wil je elke week een nieuwe meting, meer vragen en verbeteracties? Kies een abonnement dat bij je past.</p>
          <form method="post" action="/app/request.php">
            <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
            <input type="hidden" name="kind" value="upgrade">
            <input type="hidden" name="tier_id" value="q100">
            <input type="hidden" name="next" value="/app/abonnement.php">
            <button class="btn" type="submit">Abonnement aanvragen</button>
          </form>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <details class="app-details">
      <summary>Hoe ik meet</summary>
      <p>Ik stel vaste en wisselende vragen aan AI-assistenten en tel hoe vaak jouw bedrijf genoemd wordt. Bij een abonnement herhaal ik dat elke week, zodat je trend en acties actueel blijven.</p>
    </details>
    <?php
});
