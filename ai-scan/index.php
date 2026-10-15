<?php

declare(strict_types=1);

/**
 * Statuspagina na formulier, bevestiging of verwijderen (/ai-scan/?s=<code>).
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

ai_scan_security_headers();

$code = (string) ($_GET['s'] ?? '');
$pages = [
    'confirmed' => ['Je e-mailadres is bevestigd', 'Je scan is gestart; die is meestal binnen een kwartier klaar. Zodra de uitslag klaar is, krijg je een mail van mij. Je krijgt ook een mail met een link om een wachtwoord te kiezen; daarmee log je straks in.', true],
    'check_mail' => ['Kijk in je mail', 'Ik heb je een mail gestuurd. Klik op de link om je e-mailadres te bevestigen; daarna start de scan.', false],
    'daily_full' => ['Je scan staat ingepland', 'Vandaag zijn alle gratis scans bezet. Je scan start automatisch op de eerstvolgende dag met ruimte. Je krijgt een mail van mij zodra de uitslag klaar is.', true],
    'already_scanned' => ['Al een gratis scan gedaan', 'Voor deze website of dit e-mailadres is al een gratis scan gedaan. Log in met het e-mailadres van die scan.', true],
    'ip_already_scanned' => ['Al een gratis scan gedaan', 'Vanaf deze internetverbinding is al een gratis scan gedaan. Komt dat niet van jou, bijvoorbeeld op een kantoor- of mobiel netwerk? Mail me op info@jpwebcreation.nl, dan regel ik het.', false],
    'expired' => ['Link verlopen', 'Deze bevestigingslink is verlopen. Vraag de scan opnieuw aan.', false],
    'invalid_token' => ['Link ongeldig', 'Deze link is ongeldig of al gebruikt. Heb je al bevestigd? Dan staat je scan al in de wachtrij.', true],
    'deleted' => ['Account verwijderd', 'Je account en gegevens zijn verwijderd.', false],
    'rate_limited' => ['Even wachten', 'Je hebt het formulier een paar keer achter elkaar verstuurd. Probeer het over tien minuten opnieuw.', false],
];
$wait = ai_scan_wait_phrase((int) ($_GET['w'] ?? 0));
if ($code === 'rate_limited') {
    $pages['rate_limited'][1] = 'Je hebt het formulier een paar keer achter elkaar verstuurd. Probeer het '
        . ($wait !== '' ? $wait : 'later') . ' opnieuw.';
}
[$title, $text, $showLogin] = $pages[$code] ?? ['Er ging iets mis', 'Dat is niet gelukt. Mail je vraag naar info@jpwebcreation.nl, dan help ik je verder.', false];
if (!isset($pages[$code]) && $code === '') {
    header('Location: /ai-vindbaarheid/', true, 302);
    exit;
}
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $h($title) ?> · JP Web Creation</title>
  <link rel="stylesheet" href="/styles.css?v=ai-scan-1">
  <style>
    .ai-auth { max-width: 32rem; margin: 4rem auto; padding: 0 1.25rem; }
    .ai-auth h1 { font-size: 1.75rem; margin-bottom: 0.75rem; }
    .ai-auth p { color: var(--muted, #5e6862); margin-bottom: 1.25rem; }
    .ai-auth .button { display: inline-flex; align-items: center; min-height: 48px; padding: 0 1.25rem; margin-right: 0.5rem; }
  </style>
</head>
<body>
  <main class="ai-auth">
    <h1><?= $h($title) ?></h1>
    <p><?= $h($text) ?></p>
    <p>
      <?php if ($showLogin): ?><a class="button primary" href="/login/">Inloggen</a><?php endif; ?>
      <a href="/ai-vindbaarheid/">Terug naar AI-vindbaarheid</a>
    </p>
  </main>
</body>
</html>
