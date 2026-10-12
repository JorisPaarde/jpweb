<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('abonnement');
$effective = $ctx['effective'];
$tiers = $ctx['tiers'];
$isFree = !empty($ctx['is_free']);
$user = $ctx['user'];
$pending = $ctx['pending_request'];
$csrf = (string) $ctx['csrf'];

$tierList = [];
foreach ($tiers['tiers'] as $t) {
    if (!is_array($t)) {
        continue;
    }
    $tierList[] = $t;
}

$currentLabel = ai_scan_tier_label(
    (string) $effective['id'],
    $tiers,
    isset($user['custom_questions']) ? (int) $user['custom_questions'] : null
);
$monthly = (int) $effective['monthly'];
$questions = (int) $effective['questions'];
$actions = (int) $effective['actions'];

$periodEnd = isset($user['period_end']) && $user['period_end'] !== null ? (int) $user['period_end'] : null;
$status = (string) ($user['subscription_status'] ?? 'none');
$nextMeasure = app_next_monday_label();

$defaultSelect = 'q100';
foreach ($tierList as $t) {
    if ((string) $t['id'] === (string) $effective['id']) {
        $defaultSelect = (string) $t['id'];
        break;
    }
}

app_render_layout($ctx, 'Abonnement', static function (array $ctx) use (
    $isFree,
    $currentLabel,
    $monthly,
    $questions,
    $actions,
    $tierList,
    $pending,
    $csrf,
    $periodEnd,
    $status,
    $nextMeasure,
    $defaultSelect,
    $effective
): void {
    ?>
    <h1>Abonnement</h1>

    <section class="plan-card" aria-label="Huidig plan">
      <p class="app-list__muted">Huidig plan</p>
      <p class="plan-card__price">
        <?php if ($isFree): ?>
          Gratis
        <?php else: ?>
          <?= app_h($currentLabel) ?> · <?= app_h(app_format_euro($monthly)) ?> / maand
        <?php endif; ?>
      </p>
      <p><?= (int) $questions ?> vragen · <?= (int) $actions ?> <?= ((int) $actions === 1) ? 'actie' : 'acties' ?> per maand</p>
      <p>Volgende meetdatum: <?= app_h($nextMeasure) ?></p>
      <?php if ($status === 'canceling' && $periodEnd): ?>
        <?php
        $endLabel = app_format_nl_date($periodEnd);
        ?>
        <p class="pending-line">Je abonnement loopt tot <?= app_h($endLabel) ?>. Daarna blijft je laatste uitslag zichtbaar, maar meten we niet meer.</p>
      <?php endif; ?>
      <?php if (is_array($pending)): ?>
        <p class="pending-line">
          Openstaande aanvraag:
          <?= app_h((string) ($pending['note'] ?? 'request')) ?>
          → <?= app_h((string) ($pending['to_tier'] ?? '')) ?>
        </p>
      <?php endif; ?>
    </section>

    <section class="tier-compare" aria-label="Vergelijking">
      <div>
        <strong>Gratis</strong>
        <p>1 scan, alles inzien, 1 voorbeeldactie, geen wekelijkse meting.</p>
      </div>
      <div>
        <strong>Betaald</strong>
        <p>Wekelijkse meting, vragen en concurrenten aanpassen, verbeteracties per maand.</p>
      </div>
    </section>

    <?php if ($isFree): ?>
      <form method="post" action="/app/request.php" class="plan-card">
        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
        <input type="hidden" name="kind" value="upgrade">
        <input type="hidden" name="next" value="/app/abonnement.php">
        <label class="field">
          <span class="field__label">Kies een abonnement</span>
          <select name="tier_id" class="tier-slider" id="tier-select">
            <?php foreach ($tierList as $t): ?>
              <option
                value="<?= app_h((string) $t['id']) ?>"
                <?= (string) $t['id'] === $defaultSelect ? 'selected' : '' ?>
                data-monthly="<?= (int) $t['monthly'] ?>"
                data-annual="<?= (int) $t['annual'] ?>"
                data-questions="<?= (int) $t['questions'] ?>"
                data-actions="<?= (int) $t['actions'] ?>"
              >
                <?= (int) $t['questions'] ?> vragen · <?= app_h(app_format_euro((int) $t['monthly'])) ?>/mnd
                (jaarlijks <?= app_h(app_format_euro((int) $t['annual'])) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <p id="tier-preview" class="app-list__muted"></p>
        <button class="btn btn--block" type="submit">Abonnement aanvragen</button>
      </form>
    <?php else: ?>
      <div class="plan-actions">
        <form method="post" action="/app/request.php">
          <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
          <input type="hidden" name="kind" value="downgrade">
          <input type="hidden" name="next" value="/app/abonnement.php">
          <label class="field">
            <span class="field__label">Wijzig naar</span>
            <select name="tier_id">
              <?php foreach ($tierList as $t): ?>
                <option
                  value="<?= app_h((string) $t['id']) ?>"
                  <?= (string) $t['id'] === (string) $effective['id'] ? 'selected' : '' ?>
                >
                  <?= (int) $t['questions'] ?> vragen · <?= app_h(app_format_euro((int) $t['monthly'])) ?>/mnd
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn btn--ghost btn--block" type="submit">Wijzig</button>
        </form>
        <form method="post" action="/app/request.php">
          <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
          <input type="hidden" name="kind" value="cancel">
          <input type="hidden" name="tier_id" value="free">
          <input type="hidden" name="next" value="/app/abonnement.php">
          <label class="field">
            <span class="field__label">&nbsp;</span>
            <button class="btn btn--ghost btn--block" type="submit">Opzeggen</button>
          </label>
        </form>
      </div>
      <?php if ($periodEnd): ?>
        <?php
        $endLabel = app_format_nl_date($periodEnd);
        ?>
        <p class="app-lead">Je abonnement loopt tot <?= app_h($endLabel) ?>. Daarna blijft je laatste uitslag zichtbaar, maar meten we niet meer.</p>
      <?php else: ?>
        <p class="app-lead">Opzeggen loopt door tot het einde van de periode. Daarna blijft je laatste uitslag zichtbaar, maar meten we niet meer.</p>
      <?php endif; ?>
    <?php endif; ?>

    <script>
      (function () {
        var select = document.getElementById('tier-select');
        var preview = document.getElementById('tier-preview');
        if (!select || !preview) return;
        function sync() {
          var opt = select.options[select.selectedIndex];
          if (!opt) return;
          preview.textContent = opt.getAttribute('data-questions') + ' vragen, '
            + opt.getAttribute('data-actions') + ' acties · €'
            + opt.getAttribute('data-monthly') + '/mnd of €'
            + opt.getAttribute('data-annual') + '/jaar';
        }
        select.addEventListener('change', sync);
        sync();
      })();
    </script>
    <?php
});
