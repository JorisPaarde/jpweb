<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('account');
$email = (string) ($ctx['user']['email'] ?? '');
$website = (string) ($ctx['profile']['website'] ?? ($ctx['scan']['domain'] ?? ''));
$isFree = !empty($ctx['is_free']);
$status = (string) ($ctx['user']['subscription_status'] ?? 'none');
$canDelete = $isFree || in_array($status, ['ended', 'none', 'canceling'], true);

app_render_layout($ctx, 'Account', static function (array $ctx) use ($email, $website, $canDelete): void {
    $csrf = (string) $ctx['csrf'];
    ?>
    <h1>Account</h1>

    <section class="plan-card">
      <h2>E-mailadres</h2>
      <p><?= app_h($email) ?></p>
      <p class="app-list__muted">Wijzigen gaat met bevestiging naar het nieuwe adres; tot dat bevestigd is, blijft het oude actief.</p>
      <label class="field">
        <span class="field__label">Nieuw e-mailadres</span>
        <input type="email" name="new_email" disabled placeholder="nieuw@voorbeeld.nl">
      </label>
    </section>

    <section class="plan-card">
      <h2>Wachtwoord</h2>
      <p class="app-list__muted">Minimaal 12 tekens. Geen andere regels.</p>
      <label class="field">
        <span class="field__label">Nieuw wachtwoord</span>
        <input type="password" name="password" minlength="12" disabled autocomplete="new-password">
      </label>
    </section>

    <section class="plan-card">
      <h2>Verwijder mijn account</h2>
      <?php if (!$canDelete): ?>
        <p class="app-locked">Zeg eerst je abonnement op voordat je je account verwijdert.</p>
      <?php else: ?>
        <button type="button" class="btn btn--danger" data-open-dialog="dialog-delete">Verwijder mijn account</button>
      <?php endif; ?>
    </section>

    <div class="dialog-backdrop" id="dialog-delete" role="presentation" hidden>
      <div class="dialog" role="alertdialog" aria-labelledby="delete-title" aria-describedby="delete-desc">
        <h2 id="delete-title">Verwijder mijn account</h2>
        <p id="delete-desc">Hiermee verwijder je je account, je uitslagen en je instellingen. Dit kan niet ongedaan worden. Typ je website om te bevestigen.</p>
        <form method="post" action="/account-delete.php">
          <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
          <label class="field">
            <span class="field__label">Website</span>
            <input name="confirm_website" required placeholder="<?= app_h($website) ?>">
          </label>
          <div class="dialog__actions">
            <button type="button" class="btn btn--ghost" data-dialog-cancel>Annuleren</button>
            <button type="submit" class="btn btn--danger">Definitief verwijderen</button>
          </div>
        </form>
      </div>
    </div>
    <?php
});
