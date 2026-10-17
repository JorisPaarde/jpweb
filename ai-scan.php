<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();
ai_scan_assert_allowed_host($config);

$method = ai_scan_request_method();
$asJson = ai_scan_wants_json();

if ($method === 'GET' && (string) ($_GET['action'] ?? '') === 'csrf') {
    $token = ai_scan_csrf_token($config);
    ai_scan_json_response(['csrf' => $token]);
}

if ($method === 'GET' && (string) ($_GET['action'] ?? '') === 'state') {
    $state = ai_scan_consume_form_state($config);
    $state['csrf'] = ai_scan_csrf_token($config);
    ai_scan_json_response($state);
}

// De link in de mail toont alleen een knop. Pas het versturen van die knop bevestigt, zodat een
// mailscanner die de link vooraf opent niets start.
if ($method === 'GET' && isset($_GET['confirm'])) {
    $confirmToken = htmlspecialchars((string) $_GET['confirm'], ENT_QUOTES, 'UTF-8');
    $confirmState = ai_scan_confirm_token_state($pdo, (string) $_GET['confirm']);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    ?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Scan bevestigen · JP Web Creation</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .ai-auth { max-width: 28rem; margin: 4rem auto; padding: 0 1.25rem; }
    .ai-auth .auth-back { display: inline-block; margin-bottom: 1.5rem; }
    .ai-auth h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
    .ai-auth p { color: var(--muted, #5e6862); margin-bottom: 1.5rem; }
    .ai-auth p a { color: var(--green, #315f4f); text-decoration: underline; text-underline-offset: 0.15em; }
    .ai-auth .error { color: #a84832; margin: 0.75rem 0; font-weight: 600; }
    .ai-auth .auth-links { display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem; align-items: center; }
    .ai-auth .auth-primary {
      display: inline-flex; align-items: center; min-height: 48px; padding: 0 1.25rem;
      background: var(--green, #315f4f); color: #fff; border-radius: 0.4rem; font-weight: 600; text-decoration: none;
    }
    .ai-auth button {
      width: 100%; min-height: 48px; background: var(--green, #315f4f); color: #fff; border: 0;
      border-radius: 0.4rem; font: inherit; font-weight: 600; cursor: pointer;
    }
  </style>
</head>
<body>
  <main class="ai-auth">
    <a class="auth-back" href="/">← Terug naar jpwebcreation.nl</a>
    <h1>Scan bevestigen</h1>
    <?php if ($confirmState === 'ok'): ?>
      <p>Klik op de knop om je e-mailadres te bevestigen. Daarna start ik je gratis scan.</p>
      <form method="post" action="/ai-scan.php">
        <input type="hidden" name="confirm" value="<?= $confirmToken ?>">
        <button type="submit">Bevestig mijn scan</button>
      </form>
    <?php elseif ($confirmState === 'used'): ?>
      <p class="error" role="alert">Deze scan is al bevestigd.</p>
      <p>Je kunt inloggen om je resultaat te bekijken. Heb je nog geen wachtwoord gekozen? Vraag dan een nieuwe link aan.</p>
      <p class="auth-links"><a class="auth-primary" href="/login/">Inloggen</a> <a href="/login/vergeten/">Wachtwoord vergeten</a></p>
    <?php else: ?>
      <p class="error" role="alert"><?= $confirmState === 'expired' ? 'Deze link is verlopen.' : 'Deze link is ongeldig.' ?></p>
      <p>Vraag de gratis scan opnieuw aan, dan stuur ik je een nieuwe link. Lukt dat niet? Mail me op info@jpwebcreation.nl.</p>
      <p class="auth-links"><a class="auth-primary" href="/ai-vindbaarheid/">Naar de gratis scan</a></p>
    <?php endif; ?>
  </main>
</body>
</html>
<?php
    exit;
}

if ($method === 'POST' && isset($_POST['confirm'])) {
    $result = ai_scan_confirm($pdo, $config, (string) $_POST['confirm']);
    if (!$asJson && $result['code'] === 'confirmed' && (string) ($result['password_token'] ?? '') !== '') {
        ai_scan_redirect('/ai-scan/wachtwoord/?t=' . rawurlencode((string) $result['password_token']), 303);
    }
    ai_scan_respond_code($result['code'], $asJson);
}

if ($method === 'GET' && !$asJson) {
    // Een bezoeker die het formulieradres opent: terug naar de pagina met het formulier.
    ai_scan_redirect('/ai-vindbaarheid/', 302);
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo 'Method Not Allowed';
    exit;
}

$input = [
    'email' => ai_scan_clean_input('email', 254),
    'website' => ai_scan_clean_input('website', 300),
    'consent' => (string) ($_POST['consent'] ?? ''),
    'privacy' => (string) ($_POST['privacy'] ?? ''),
    'hp_field' => ai_scan_clean_input('hp_field', 200),
    'bedrijfsfax' => ai_scan_clean_input('bedrijfsfax', 200),
];

// Honeypot first: never reveal validation or CSRF state to bots.
if ($input['hp_field'] !== '' || $input['bedrijfsfax'] !== '') {
    ai_scan_respond_code('check_mail', $asJson);
}

$csrf = (string) ($_POST['csrf'] ?? '');
if (!ai_scan_verify_csrf($config, $csrf)) {
    ai_scan_store_form_state($config, [
        'email' => $input['email'],
        'website' => $input['website'],
    ], ['form' => 'De sessie is verlopen. Probeer het opnieuw.']);
    ai_scan_respond_code('error', $asJson);
}

$result = ai_scan_submit($pdo, $config, $input, ai_scan_client_ip());

if ($result['code'] === 'invalid') {
    // Per veld een veilige melding; nooit of een adres of website al gescand is (dat blijft already_scanned).
    $field = (string) ($result['field'] ?? 'form');
    $message = (string) ($result['message'] ?? AI_SCAN_INVALID_PUBLIC_MESSAGE);
    $values = ['email' => $input['email'], 'website' => $input['website']];
    ai_scan_store_form_state($config, $values, [$field => $message]);
    if ($asJson) {
        ai_scan_json_response([
            'ok' => false,
            'code' => 'invalid',
            'message' => 'Controleer het gemarkeerde veld en probeer het opnieuw.',
            'errors' => [$field => $message],
            'values' => $values,
        ], 422);
    }
}

$extra = [];
if ($result['code'] === 'rate_limited') {
    $extra['retry_after'] = (int) ($result['retry_after'] ?? ai_scan_rate_limit_retry_after($config));
    $extra['busy'] = !empty($result['busy']);
}

ai_scan_respond_code($result['code'], $asJson, $extra);
