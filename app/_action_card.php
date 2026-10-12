<?php

declare(strict_types=1);

/**
 * Render one collapsible action card (§4).
 *
 * @param array<string, mixed> $action
 */
function app_render_action_card(array $action, bool $readOnly = false, bool $defaultOpen = false): void
{
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
        'live' => 'Live',
        'afgewezen', 'past_niet' => 'Past niet',
        'bezig' => 'Bezig',
        default => 'Voorgesteld',
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
          <span class="action-card__status action-card__status--<?= app_h(strtolower($status)) ?>"><?= app_h($statusLabel) ?></span>
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
                <input type="text" name="placeholder[<?= app_h($id) ?>][<?= app_h($label) ?>]" maxlength="120" <?= $readOnly ? 'disabled' : '' ?>>
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
        <?php if (!$readOnly): ?>
          <div class="action-card__actions">
            <button type="button" class="btn btn--ghost" disabled>Staat live</button>
            <button type="button" class="btn btn--ghost" disabled>Past niet bij mijn bedrijf</button>
          </div>
        <?php endif; ?>
      </div>
    </article>
    <?php
}
