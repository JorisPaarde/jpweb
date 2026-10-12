<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('vragen');
$flashLocal = '';
$errorLocal = '';
$formText = '';
$formPlace = '';
$editId = 0;

if (ai_scan_request_method() === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    $action = ai_scan_clean_input('action', 40);
    if (!ai_scan_verify_csrf($ctx['config'], $token)) {
        $errorLocal = 'De sessie is verlopen. Probeer het opnieuw.';
    } else {
        $text = ai_scan_clean_input('question_text', 200);
        $place = ai_scan_clean_input('question_place', 80);
        $qid = (int) ($_POST['question_id'] ?? 0);
        $formText = $text;
        $formPlace = $place;
        $editId = $qid;

        if ($action === 'add') {
            $res = ai_scan_question_add(
                $ctx['pdo'],
                $ctx['user'],
                $ctx['profile'],
                $ctx['result'],
                $text,
                $place,
                $ctx['tiers']
            );
        } elseif ($action === 'edit') {
            $res = ai_scan_question_edit(
                $ctx['pdo'],
                $ctx['user'],
                $ctx['profile'],
                $ctx['result'],
                $qid,
                $text,
                $place,
                $ctx['tiers']
            );
        } elseif ($action === 'delete') {
            $res = ai_scan_question_delete(
                $ctx['pdo'],
                $ctx['user'],
                $ctx['profile'],
                $ctx['result'],
                $qid,
                $ctx['tiers']
            );
        } else {
            $res = ['ok' => false, 'error' => 'Onbekende actie.'];
        }

        if (!empty($res['ok'])) {
            $flashLocal = (string) ($res['message'] ?? 'Opgeslagen.');
            $formText = '';
            $formPlace = '';
            $editId = 0;
            // Refresh profile version after write.
            $ctx['profile'] = app_load_profile($ctx['pdo'], (int) $ctx['user']['id'], $ctx['scan'], $ctx['result']);
        } else {
            $errorLocal = (string) ($res['error'] ?? 'Er ging iets mis.');
        }
    }
}

$q = app_load_questions_view($ctx['pdo'], (int) $ctx['user']['id'], $ctx['result'], $ctx['profile']);
$nCore = count($q['core']);
$tierQ = (int) $ctx['effective']['questions'];
$canWrite = !empty($ctx['can_write']);
$isFree = !empty($ctx['is_free']);
$places = $ctx['profile']['places'] ?? [];

app_render_layout($ctx, 'Vragen', static function (array $ctx) use (
    $q,
    $nCore,
    $tierQ,
    $canWrite,
    $isFree,
    $flashLocal,
    $errorLocal,
    $formText,
    $formPlace,
    $editId,
    $places
): void {
    $csrf = (string) $ctx['csrf'];
    ?>
    <h1>Vragen</h1>
    <p class="app-lead">Dit zijn de vragen die ik AI-assistenten stel over jouw diensten en plaatsen.</p>
    <p><?= (int) $nCore ?> van <?= (int) $tierQ ?></p>

    <?php if ($flashLocal !== ''): ?>
      <div class="app-flash" role="status"><?= app_h($flashLocal) ?></div>
    <?php endif; ?>
    <?php if ($errorLocal !== ''): ?>
      <p class="field__error" role="alert"><?= app_h($errorLocal) ?></p>
    <?php endif; ?>

    <h2>Vaste vragen (<?= (int) $nCore ?>, versie <?= (int) $q['core_version'] ?>)</h2>
    <?php if ($q['core'] === []): ?>
      <p class="app-empty">Er staan nog geen vragen. Na je scan zie je hier welke vragen ik AI-assistenten stelde.</p>
    <?php else: ?>
      <ul class="app-list">
        <?php foreach ($q['core'] as $item):
            $text = (string) ($item['text'] ?? '');
            $qid = (int) ($item['id'] ?? 0);
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
                  <button
                    type="button"
                    class="text-btn"
                    data-open-dialog="dialog-versiebreuk"
                    data-q-action="edit"
                    data-q-id="<?= (int) $qid ?>"
                    data-q-text="<?= app_h($text) ?>"
                    data-q-place="<?= app_h((string) ($item['place'] ?? '')) ?>"
                  >Wijzigen</button>
                  <button
                    type="button"
                    class="text-btn"
                    data-open-dialog="dialog-versiebreuk"
                    data-q-action="delete"
                    data-q-id="<?= (int) $qid ?>"
                    data-q-text="<?= app_h($text) ?>"
                  >Verwijderen</button>
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
        <button type="button" class="btn" data-open-dialog="dialog-versiebreuk" data-q-action="add">Vraag toevoegen</button>
      </p>
    <?php else: ?>
      <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
    <?php endif; ?>

    <div class="dialog-backdrop" id="dialog-versiebreuk" role="presentation" hidden>
      <div class="dialog" role="alertdialog" aria-labelledby="versiebreuk-title" aria-describedby="versiebreuk-desc">
        <h2 id="versiebreuk-title">Je trend begint hierna opnieuw</h2>
        <p id="versiebreuk-desc">
          Als je de vragen wijzigt, kan ik de nieuwe metingen niet meer eerlijk vergelijken met de oude.
          Je oude cijfers blijven zichtbaar, maar de lijn begint vanaf <?= app_h(app_next_monday_label()) ?> opnieuw.
          De wijziging gaat in bij de meting van maandag <?= app_h(app_next_monday_label()) ?>.
        </p>
        <form method="post" action="/app/vragen.php" id="question-write-form">
          <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
          <input type="hidden" name="action" id="q-action" value="<?= $editId > 0 ? 'edit' : 'add' ?>">
          <input type="hidden" name="question_id" id="q-id" value="<?= (int) $editId ?>">
          <div id="q-fields">
            <label class="field">
              <span class="field__label">Vraag</span>
              <textarea name="question_text" id="q-text" maxlength="200" minlength="15" required><?= app_h($formText) ?></textarea>
            </label>
            <label class="field">
              <span class="field__label">Plaats (optioneel)</span>
              <select name="question_place" id="q-place">
                <option value="">—</option>
                <?php if (is_array($places)): foreach ($places as $pl): ?>
                  <option value="<?= app_h((string) $pl) ?>" <?= $formPlace === (string) $pl ? 'selected' : '' ?>><?= app_h((string) $pl) ?></option>
                <?php endforeach; endif; ?>
              </select>
            </label>
          </div>
          <div class="dialog__actions">
            <button type="button" class="btn btn--ghost" data-dialog-cancel>Annuleren</button>
            <button type="submit" class="btn" id="q-confirm">Ja, wijzig de vragen</button>
          </div>
        </form>
      </div>
    </div>
    <?php
});
