<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('account');
$flashLocal = '';
$errorLocal = '';
$emailError = '';
$passwordError = '';

if (ai_scan_request_method() === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    $action = ai_scan_clean_input('action', 40);
    if (!ai_scan_verify_csrf($ctx['config'], $token)) {
        $errorLocal = 'De sessie is verlopen. Probeer het opnieuw.';
    } elseif ($action === 'change_email' && !empty($ctx['is_demo'])) {
        $emailError = APP_ACCOUNT_DEMO_MESSAGE;
    } elseif ($action === 'change_email') {
        $res = ai_scan_request_email_change(
            $ctx['pdo'],
            $ctx['config'],
            $ctx['user'],
            ai_scan_clean_input('new_email', 200),
            (string) ($_POST['current_password'] ?? '')
        );
        // Ook bij falen herladen: een mislukte mail wist de openstaande wijziging.
        $stmt = $ctx['pdo']->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $ctx['user']['id']]);
        $fresh = $stmt->fetch();
        if ($fresh !== false) {
            $ctx['user'] = $fresh;
        }
        if (!empty($res['ok'])) {
            $flashLocal = (string) ($res['message'] ?? AI_SCAN_EMAIL_PENDING_SENT);
        } else {
            $emailError = (string) ($res['error'] ?? 'Er ging iets mis.');
        }
    } elseif ($action === 'change_password' && !empty($ctx['is_demo'])) {
        $passwordError = APP_ACCOUNT_DEMO_MESSAGE;
    } elseif ($action === 'change_password') {
        $res = ai_scan_change_password(
            $ctx['pdo'],
            $ctx['config'],
            $ctx['user'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? '')
        );
        if (!empty($res['ok'])) {
            $flashLocal = (string) ($res['message'] ?? 'Je wachtwoord is gewijzigd.');
            // Een wachtwoordwijziging laat een openstaande e-mailbevestiging vervallen.
            $stmt = $ctx['pdo']->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $ctx['user']['id']]);
            $fresh = $stmt->fetch();
            if ($fresh !== false) {
                $ctx['user'] = $fresh;
            }
        } else {
            $passwordError = (string) ($res['error'] ?? 'Er ging iets mis.');
        }
    }
}

$email = (string) ($ctx['user']['email'] ?? '');
$pendingEmail = (string) ($ctx['user']['pending_email'] ?? '');
$website = (string) ($ctx['profile']['website'] ?? ($ctx['scan']['domain'] ?? ''));
$isFree = !empty($ctx['is_free']);
$canDelete = app_account_can_delete($ctx);
$isDemo = !empty($ctx['is_demo']);

app_render_layout($ctx, 'Account', static function (array $ctx) use (
    $email,
    $pendingEmail,
    $website,
    $canDelete,
    $isDemo,
    $flashLocal,
    $errorLocal,
    $emailError,
    $passwordError
): void {
    $csrf = (string) $ctx['csrf'];
    ?>
    <h1>Account</h1>

    <?php if ($flashLocal !== ''): ?>
      <div class="app-flash" role="status"><?= app_h($flashLocal) ?></div>
    <?php endif; ?>
    <?php if ($errorLocal !== ''): ?>
      <p class="field__error" role="alert"><?= app_h($errorLocal) ?></p>
    <?php endif; ?>

    <section class="plan-card">
      <h2>E-mailadres</h2>
      <p><?= app_h($email) ?></p>
      <?php if ($pendingEmail !== ''): ?>
        <p class="pending-line">Bevestiging openstaand voor <?= app_h($pendingEmail) ?>. Tot die bevestigd is, blijft je oude adres actief.</p>
      <?php endif; ?>
      <p class="app-list__muted">Wijzigen gaat met bevestiging naar het nieuwe adres; tot dat bevestigd is, blijft het oude actief.</p>
      <form method="post" action="/app/account.php">
        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
        <input type="hidden" name="action" value="change_email">
        <label class="field">
          <span class="field__label">Nieuw e-mailadres</span>
          <input type="email" name="new_email" required autocomplete="email" placeholder="nieuw@voorbeeld.nl" aria-describedby="<?= $emailError !== '' ? 'email-error' : '' ?>">
          <?php if ($emailError !== ''): ?>
            <span class="field__error" id="email-error"><?= app_h($emailError) ?></span>
          <?php endif; ?>
        </label>
        <label class="field">
          <span class="field__label">Huidig wachtwoord</span>
          <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <?php if ($isDemo): ?>
          <p class="app-locked"><?= app_h(APP_ACCOUNT_DEMO_MESSAGE) ?></p>
        <?php endif; ?>
        <button type="submit" class="btn"<?= $isDemo ? ' disabled' : '' ?>>E-mailadres wijzigen</button>
      </form>
    </section>

    <section class="plan-card">
      <h2>Wachtwoord</h2>
      <p class="app-list__muted">Minimaal 12 tekens. Geen andere regels.</p>
      <form method="post" action="/app/account.php">
        <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
        <input type="hidden" name="action" value="change_password">
        <input type="email" name="username" autocomplete="username" value="<?= app_h($email) ?>" readonly hidden aria-hidden="true" tabindex="-1">
        <label class="field">
          <span class="field__label">Huidig wachtwoord</span>
          <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label class="field">
          <span class="field__label">Nieuw wachtwoord</span>
          <input type="password" name="new_password" minlength="12" required autocomplete="new-password" aria-describedby="<?= $passwordError !== '' ? 'pw-error' : '' ?>">
          <?php if ($passwordError !== ''): ?>
            <span class="field__error" id="pw-error"><?= app_h($passwordError) ?></span>
          <?php endif; ?>
        </label>
        <?php if ($isDemo): ?>
          <p class="app-locked"><?= app_h(APP_ACCOUNT_DEMO_MESSAGE) ?></p>
        <?php endif; ?>
        <button type="submit" class="btn"<?= $isDemo ? ' disabled' : '' ?>>Wachtwoord wijzigen</button>
      </form>
    </section>

    <section class="plan-card">
      <h2>Verwijder mijn account</h2>
      <?php if ($isDemo): ?>
        <p class="app-locked"><?= app_h(APP_ACCOUNT_DEMO_MESSAGE) ?></p>
        <button type="button" class="btn btn--danger" disabled>Verwijder mijn account</button>
      <?php elseif (!$canDelete): ?>
        <p class="app-locked">Zeg eerst je abonnement op voordat je je account verwijdert.</p>
      <?php else: ?>
        <button type="button" class="btn btn--danger" data-open-dialog="dialog-delete">Verwijder mijn account</button>
      <?php endif; ?>
    </section>

    <div class="dialog-backdrop" id="dialog-delete" role="presentation" hidden>
      <div class="dialog" role="alertdialog" aria-labelledby="delete-title" aria-describedby="delete-desc">
        <h2 id="delete-title">Verwijder mijn account</h2>
        <p id="delete-desc">Hiermee verwijder je je account, je uitslagen en je instellingen. Dit kan niet ongedaan worden. Daarna kun je met dit e-mailadres of deze website geen nieuwe gratis scan meer doen. Typ je website om te bevestigen.</p>
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
