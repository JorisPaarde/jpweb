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
    <?php if (!empty($ctx['result']['demo'])): ?>
      <p class="app-lead"><?= app_h((string) $ctx['result']['demo_note']) ?></p>
      <p><?= app_h((string) $ctx['result']['question_set_label']) ?> · <?= (int) count($ctx['result']['questions']['core'] ?? []) ?> vragen</p>
    <?php endif; ?>

    <?php if ($score['status'] === 'none'): ?>
      <p class="app-empty">Er is nog geen scan gekoppeld aan dit account.</p>
    <?php elseif (!empty($score['retrying'])): ?>
      <div class="app-score" aria-live="polite" data-live-progress>
        <p><strong>De scan lukte niet in één keer.</strong> Ik probeer het nog één keer automatisch; je krijgt een mail als hij klaar is.</p>
      </div>
    <?php elseif (in_array($score['status'], ['bezig', 'bevestigd', 'onbevestigd'], true)): ?>
      <div class="app-score app-live" aria-live="polite" data-live-progress>
        <p><strong>Je scan loopt.</strong> <span data-live-step>Ik maak je scan klaar…</span></p>
        <ul class="app-live__sources" data-live-sources hidden></ul>
        <ol class="app-live__answers" data-live-answers hidden></ol>
        <p class="app-live__note">Je krijgt een mail als het klaar is; je hoeft deze pagina niet open te houden.</p>
      </div>
    <?php elseif ($score['status'] === 'wacht_dag'): ?>
      <div class="app-score" aria-live="polite" data-live-progress data-live-wait>
        <p>Je eerste scan staat in de rij; vandaag zijn alle gratis scans bezet. Zodra er ruimte is, start hij automatisch en krijg je een mail.</p>
      </div>
    <?php elseif ($score['status'] === 'mislukt'): ?>
      <div class="app-score" aria-live="polite">
        <p>De scan is niet gelukt. Ik kijk er zelf naar en neem contact met je op.</p>
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

      <?php
      $res = is_array($ctx['result'] ?? null) ? $ctx['result'] : [];
      $named = array_slice(array_values(array_filter((array) ($res['top_named_entities'] ?? []), 'is_array')), 0, 5);
      $cited = array_slice(array_values(array_filter((array) ($res['top_cited_domains'] ?? []), 'is_array')), 0, 5);
      $aio = is_array($res['aio'] ?? null) ? $res['aio'] : null;
      ?>
      <?php if ($score['mentioned'] === 0 && (int) $score['answers'] > 0): ?>
        <section class="app-zero" aria-label="Uitleg">
          <h2>Wat betekent dit?</h2>
          <p>AI noemde je bedrijf in geen van de <?= (int) $score['answers'] ?> antwoorden. Dat is bij een eerste scan heel gewoon: AI kent je nog niet goed genoeg om je aan te raden. Hieronder zie je wie AI wél noemde en welke sites het las.</p>
        </section>
      <?php endif; ?>
      <?php if ($named !== []): ?>
        <section aria-label="Wie AI wel noemde">
          <h2>Wie AI wél noemde</h2>
          <ul class="app-list">
            <?php foreach ($named as $n): ?>
              <li><span><?= app_h((string) ($n['name'] ?? '')) ?></span><span class="app-list__muted"><?= (int) ($n['count'] ?? 0) ?>×</span></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
      <?php if ($cited !== []): ?>
        <section aria-label="Sites die AI gebruikte">
          <h2>Sites die AI als bron gebruikte</h2>
          <ul class="app-list">
            <?php foreach ($cited as $d): ?>
              <li><span><?= app_h((string) ($d['domain'] ?? '')) ?></span><span class="app-list__muted"><?= (int) ($d['count'] ?? 0) ?>×</span></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
      <?php if ($aio !== null && (int) ($aio['calls'] ?? 0) > 0): ?>
        <p>Google liet bij <?= (int) ($aio['present'] ?? 0) ?> van de <?= (int) $aio['calls'] ?> vragen een AI-overzicht zien.</p>
      <?php endif; ?>
      <?php if ($score['mentioned'] === 0 && (int) $score['answers'] > 0): ?>
        <section aria-label="Volgende stappen">
          <h2>Wat je nu kunt doen</h2>
          <ol>
            <li>Zet op je site in gewone zinnen wat je doet, voor wie en in welke plaats.</li>
            <li>Kijk welke sites hierboven als bron staan en zorg dat je daar ook vermeld bent.</li>
            <li>Controleer je <a href="/app/bedrijf.php">bedrijfsgegevens</a> en <a href="/app/concurrenten.php">concurrenten</a>, dan meet ik daarmee.</li>
          </ol>
          <?php if ($isFree): ?>
            <p><a class="btn" href="/app/abonnement.php">Elke week meten en acties krijgen</a></p>
          <?php endif; ?>
        </section>
      <?php endif; ?>

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
              app_render_action_card($action, $isFree || !empty($ctx['result']['demo']), false, (string) $ctx['csrf']);
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
