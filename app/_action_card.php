<?php

declare(strict_types=1);

/**
 * Render one collapsible action card (§4).
 *
 * @param array<string, mixed> $action
 */
function app_render_action_card(
    array $action,
    bool $readOnly = false,
    bool $defaultOpen = false,
    string $csrf = ''
): void {
    $id = (string) ($action['public_id'] ?? $action['id'] ?? 'x');
    $domId = 'actie-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    $type = strtolower((string) ($action['type'] ?? 'verbeteren'));
    $typeLabel = match ($type) {
        'toevoegen', 'add' => '＋ Toevoegen',
        'technisch', 'tech' => '⚙ Technisch',
        default => '✎ Verbeteren',
    };
    $status = (string) ($action['status'] ?? 'voorgesteld');
    $statusLabel = match (strtolower($status)) {
        'live', 'live_gemeld' => 'Live gemeld',
        'afgewezen', 'past_niet' => 'Past niet',
        'bezig' => 'Bezig',
        default => 'Voorgesteld',
    };
    $statusClass = match (strtolower($status)) {
        'live', 'live_gemeld' => 'live',
        'afgewezen', 'past_niet' => 'afgewezen',
        default => 'voorgesteld',
    };
    $title = (string) ($action['title'] ?? '');
    $pagePath = (string) ($action['page_path'] ?? '');
    $placeholders = [];
    if (!empty($action['placeholders_json'])) {
        $decoded = json_decode((string) $action['placeholders_json'], true);
        if (is_array($decoded)) {
            $placeholders = $decoded;
        }
    } elseif (isset($action['placeholders']) && is_array($action['placeholders'])) {
        $placeholders = $action['placeholders'];
    }
    $hasFill = $placeholders !== [];
    $why = (string) ($action['why_text'] ?? '');
    $pageText = (string) ($action['page_text'] ?? '');
    $evidence = (string) ($action['evidence_text'] ?? '');
    $now = (string) ($action['now_text'] ?? '');
    $becomes = (string) ($action['becomes_text'] ?? '');
    $log = (string) ($action['log_text'] ?? '');
    $effect = (string) ($action['effect_text'] ?? '');
    $bodyId = $domId . '-body';
    $canAct = !$readOnly && !in_array(strtolower($status), ['live', 'live_gemeld', 'afgewezen'], true);
    ?>
    <article class="action-card" id="<?= app_h($domId) ?>" data-action-id="<?= app_h($id) ?>">
      <button
        type="button"
        class="action-card__toggle"
        aria-expanded="<?= $defaultOpen ? 'true' : 'false' ?>"
        aria-controls="<?= app_h($bodyId) ?>"
        data-action-toggle
      >
        <span class="action-card__row1">
          <span class="action-card__type"><?= app_h($typeLabel) ?></span>
          <span class="action-card__status action-card__status--<?= app_h($statusClass) ?>"><?= app_h($statusLabel) ?></span>
        </span>
        <span class="action-card__title"><?= app_h($title) ?></span>
        <span class="action-card__meta">
          <?php if ($pagePath !== ''): ?>
            <span><?= app_h($pagePath) ?></span>
          <?php endif; ?>
          <?php if ($hasFill): ?>
            <span>· Controleer of dit klopt</span>
          <?php endif; ?>
          <span class="action-card__chevron" aria-hidden="true">▾</span>
        </span>
      </button>
      <div class="action-card__body" id="<?= app_h($bodyId) ?>"<?= $defaultOpen ? '' : ' hidden' ?>>
        <h3 class="action-card__full-title"><?= app_h($title) ?></h3>
        <?php if ($why !== ''): ?>
          <section class="action-card__block">
            <h4>Waarom</h4>
            <p class="action-card__clamp" data-clamp><?= nl2br(app_h($why)) ?></p>
            <button type="button" class="text-btn" data-clamp-toggle hidden>Toon hele tekst</button>
          </section>
        <?php endif; ?>
        <?php if ($pageText !== '' || $pagePath !== ''): ?>
          <section class="action-card__block">
            <h4>Pagina</h4>
            <p><?= app_h($pagePath !== '' ? $pagePath : $pageText) ?></p>
          </section>
        <?php endif; ?>
        <?php if ($evidence !== ''): ?>
          <section class="action-card__block">
            <h4>Bewijs</h4>
            <p>Staat nu op <?= app_h($evidence) ?></p>
          </section>
        <?php endif; ?>
        <?php if ($now !== '' || $becomes !== ''): ?>
          <section class="action-card__block">
            <h4>Nu / Wordt</h4>
            <?php if ($now !== ''): ?><p><del><?= nl2br(app_h($now)) ?></del></p><?php endif; ?>
            <?php if ($becomes !== ''): ?><p><ins><?= nl2br(app_h($becomes)) ?></ins></p><?php endif; ?>
          </section>
        <?php endif; ?>
        <?php if ($hasFill && !$readOnly): ?>
          <section class="action-card__block">
            <h4>Invulplekken</h4>
            <?php foreach ($placeholders as $ph):
                $label = is_array($ph) ? (string) ($ph['label'] ?? $ph['key'] ?? 'veld') : (string) $ph;
                ?>
              <label class="field">
                <span class="field__label"><?= app_h($label) ?></span>
                <input type="text" name="placeholder[<?= app_h($id) ?>][<?= app_h($label) ?>]" maxlength="120">
              </label>
            <?php endforeach; ?>
          </section>
        <?php elseif ($hasFill && $readOnly): ?>
          <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
        <?php endif; ?>
        <?php if ($log !== ''): ?>
          <section class="action-card__block">
            <h4>Logboek</h4>
            <p><?= app_h($log) ?></p>
          </section>
        <?php endif; ?>
        <?php if ($effect !== ''): ?>
          <section class="action-card__block">
            <h4>Effect</h4>
            <p><?= app_h($effect) ?></p>
          </section>
        <?php elseif ($readOnly): ?>
          <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
        <?php endif; ?>
        <?php if ($canAct && $csrf !== ''): ?>
          <div class="action-card__actions">
            <form method="post" action="/app/acties.php" style="display:inline">
              <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
              <input type="hidden" name="action" value="live">
              <input type="hidden" name="public_id" value="<?= app_h($id) ?>">
              <button type="submit" class="btn btn--ghost">Staat live</button>
            </form>
            <form method="post" action="/app/acties.php" style="display:inline" onsubmit="return confirm('Past deze actie niet bij je bedrijf?');">
              <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="public_id" value="<?= app_h($id) ?>">
              <button type="submit" class="btn btn--ghost">Past niet bij mijn bedrijf</button>
            </form>
          </div>
        <?php elseif ($readOnly): ?>
          <p class="app-locked"><?= app_h(app_locked_line()) ?></p>
        <?php endif; ?>
      </div>
    </article>
    <?php
}
