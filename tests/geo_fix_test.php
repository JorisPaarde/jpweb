<?php

declare(strict_types=1);

/**
 * Testronde 13 okt 2026: IP-limiet, formulierfouten, +alias, wachtwoord vergeten,
 * retry-belofte, wachtrij-indicator en copy. Included from tests/run.php.
 */

function geo_root(): string
{
    return dirname(__DIR__);
}

function geo_src(string $rel): string
{
    return (string) file_get_contents(geo_root() . '/' . $rel);
}

/** @return array<string, mixed> decoded JSON lines of the mail log */
function geo_mails(string $log): array
{
    $out = [];
    foreach (is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        $row = json_decode((string) $line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

function geo_submit(PDO $pdo, array $config, string $email, string $site, string $ip, int $now, string $consent = '1'): array
{
    return ai_scan_submit($pdo, $config, ['email' => $email, 'website' => $site, 'consent' => $consent], $ip, $now);
}

function geo_insert_scan(PDO $pdo, string $status, int $attempts = 0, string $domain = 'firma.nl'): int
{
    $pdo->prepare(
        "INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, queued_at, attempts)
         VALUES(:p, 'k@firma.nl', :e, 'https://firma.nl/', :d, :dh, :s, 1, :q, :a)"
    )->execute([
        'p' => bin2hex(random_bytes(8)),
        'e' => ai_scan_email_hash('k@firma.nl'),
        'd' => $domain,
        'dh' => ai_scan_domain_hash($domain),
        's' => $status,
        'q' => time(),
        'a' => $attempts,
    ]);

    return (int) $pdo->lastInsertId();
}

// 1. IP-limiet ---------------------------------------------------------------

test('ip limit: invalid attempts count towards the per-ip hourly limit, not the burst', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 60;
    $config['form_burst_max'] = 1;
    $now = time();
    // Drie ongeldige pogingen kort na elkaar: geen burst-blokkade (typfout herstellen kan) ...
    for ($i = 0; $i < 3; $i++) {
        $r = geo_submit($pdo, $config, 'geen-mail', 'https://firma.nl', '9.9.9.9', $now + $i);
        assert_same('invalid', $r['code'], 'poging ' . $i);
    }
    // ... maar het uurlimiet (3) is bereikt: ook een geldige aanvraag wordt nu geweigerd.
    $r = geo_submit($pdo, $config, 'ok@firma.nl', 'https://firma.nl', '9.9.9.9', $now + 5);
    assert_same('rate_limited', $r['code']);
    // Ander IP heeft er geen last van.
    assert_same('check_mail', geo_submit($pdo, $config, 'ok@firma.nl', 'https://firma.nl', '9.9.9.8', $now + 5)['code']);
    // Na een uur is het venster weer leeg.
    assert_same('check_mail', geo_submit($pdo, $config, 'ok2@ander.nl', 'https://ander.nl', '9.9.9.9', $now + 3700)['code']);
});

test('ip limit: valid request after a typo within the burst window still works', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 60;
    $config['form_burst_max'] = 1;
    $now = time();
    assert_same('invalid', geo_submit($pdo, $config, 'geen-mail', 'https://firma.nl', '9.9.9.7', $now)['code']);
    assert_same('check_mail', geo_submit($pdo, $config, 'ok@firma.nl', 'https://firma.nl', '9.9.9.7', $now + 2)['code']);
});

test('ip limit: one free scan per ip is permanent; ipv6 per /64, ipv4 per address', static function (): void {
    [$config, $pdo] = boot_test_db();
    $now = time();
    assert_same('check_mail', geo_submit($pdo, $config, 'a@een.nl', 'https://een.nl', '2001:db8:1:2::1', $now)['code']);
    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h')->execute(['h' => ai_scan_hash_token('t6')]);
    assert_same('confirmed', ai_scan_confirm($pdo, $config, 't6', $now)['code']);
    // Zelfde /64, ander adres, veel later: nog steeds geblokkeerd.
    assert_same('ip_already_scanned', geo_submit($pdo, $config, 'b@twee.nl', 'https://twee.nl', '2001:db8:1:2:abcd::9', $now + 90000)['code']);
    // Ander /64 en ander IPv4-adres mogen wel.
    assert_same('check_mail', geo_submit($pdo, $config, 'c@drie.nl', 'https://drie.nl', '2001:db8:1:3::1', $now + 90000)['code']);
    assert_same('check_mail', geo_submit($pdo, $config, 'd@vier.nl', 'https://vier.nl', '4.4.4.4', $now + 90000)['code']);
    $stored = (string) $pdo->query('SELECT ip_hash FROM free_scan_blocks WHERE ip_hash IS NOT NULL LIMIT 1')->fetchColumn();
    assert_true(strlen($stored) === 64 && !str_contains($stored, '2001'), 'alleen een gesleutelde hash, nooit het IP');
});

test('ip limit: honeypot stays a silent success and counts nothing', static function (): void {
    [$config, $pdo] = boot_test_db();
    $r = ai_scan_submit($pdo, $config, ['email' => 'x@y.nl', 'website' => 'y.nl', 'consent' => '1', 'hp_field' => 'bot'], '8.8.8.8');
    assert_same('check_mail', $r['code']);
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM rate_events')->fetchColumn());
});

test('admin blokkade lifts ip blocks together with email/domain', static function (): void {
    $src = geo_src('lib/scan_service.php');
    assert_true(str_contains($src, 'DELETE FROM free_scan_blocks WHERE ip_hash = :h'));
    assert_true(str_contains(geo_src('admin/blokkade.php'), 'IP-blokkade'));
});

// 2. Ik-vorm -----------------------------------------------------------------

test('customer-facing copy uses ik-vorm (no wij/we/ons)', static function (): void {
    $files = ['script.js', 'lib/mail.php', 'ai-scan/index.php', 'ai-scan/wachtwoord/index.php', 'login/index.php',
        'login/vergeten/index.php', 'ai-vindbaarheid/index.html', 'ai-vindbaarheid/ai-vindbaarheid.js', 'lib/validation.php'];
    foreach (glob(geo_root() . '/app/*.php') ?: [] as $f) {
        $files[] = 'app/' . basename($f);
    }
    foreach ($files as $rel) {
        $text = geo_src($rel);
        // Alleen tekst tussen aanhalingstekens/HTML; code-identifiers bevatten deze woorden niet als los woord.
        assert_true(!preg_match('/(?<![\w$\-_.\/])(wij|we|ons|onze)(?![\w\-_(\/])/iu', preg_replace('#//[^\n]*|/\*.*?\*/|<!--.*?-->#s', '', $text) ?? ''), $rel . ' bevat wij-vorm');
    }
    assert_true(str_contains(geo_src('script.js'), 'Ik heb een bevestigingslink gestuurd naar'));
});

// 3. Specifieke formulierfouten ------------------------------------------------

test('server gives a specific safe Dutch message per field', static function (): void {
    [$config, $pdo] = boot_test_db();
    $now = time();
    $cases = [
        ['', 'firma.nl', '1', 'email', 'Vul je e-mailadres in.'],
        ['geen-mail', 'firma.nl', '1', 'email', 'Vul een geldig e-mailadres in, bijvoorbeeld naam@bedrijf.nl.'],
        ['a@firma.nl', '', '1', 'website', 'Vul de website van je bedrijf in.'],
        ['a@firma.nl', 'dit is geen site', '1', 'website', 'Vul een geldige website in, bijvoorbeeld jouwbedrijf.nl.'],
        ['a@firma.nl', 'voorbeeld.org', '1', 'website', 'Ik scan websites die eindigen op .nl, .be, .eu of .com.'],
        ['a@firma.nl', 'firma.nl', '0', 'privacy', 'Vink aan dat je akkoord gaat, anders kan ik de scan niet starten.'],
    ];
    foreach ($cases as $i => [$email, $site, $consent, $field, $message]) {
        $r = geo_submit($pdo, $config, $email, $site, '7.7.7.' . ($i + 1), $now, $consent);
        assert_same('invalid', $r['code'], 'case ' . $i);
        assert_same($field, $r['field'], 'field case ' . $i);
        assert_same($message, $r['message'], 'message case ' . $i);
    }
    // De lijst komt uit de config.
    $config['website_allowed_tlds'] = ['nl', 'be'];
    $r = geo_submit($pdo, $config, 'a@firma.nl', 'voorbeeld.com', '7.7.7.50', $now);
    assert_same('Ik scan websites die eindigen op .nl of .be.', $r['message']);
});

test('already_scanned stays neutral and is never turned into a field error', static function (): void {
    [$config, $pdo] = boot_test_db();
    $now = time();
    $pdo->prepare('INSERT INTO free_scan_blocks(email_hash, created_at) VALUES(:e, 1)')->execute(['e' => ai_scan_email_hash('bekend@firma.nl')]);
    $r = geo_submit($pdo, $config, 'bekend@firma.nl', 'https://firma.nl', '6.6.6.6', $now);
    assert_same('already_scanned', $r['code']);
    assert_true(!isset($r['field']) && !isset($r['message']));
    $endpoint = geo_src('ai-scan.php');
    assert_true(str_contains($endpoint, "'errors' => [\$field => \$message]"));
    assert_true(str_contains($endpoint, "\$result['code'] === 'invalid'"));
});

test('scan form validates locally in the existing field-error spans and names allowed domains', static function (): void {
    $js = geo_src('script.js');
    foreach (['Vul je e-mailadres in.', 'Vul een geldig e-mailadres in', 'Vul de website van je bedrijf in.',
        'Vul een geldige website in', 'Ik scan websites die eindigen op .nl, .be, .eu of .com.', 'Vink aan dat je akkoord gaat'] as $needle) {
        assert_true(str_contains($js, $needle), $needle);
    }
    assert_true(str_contains($js, 'validateScanForm()'));
    $html = geo_src('ai-vindbaarheid/index.html');
    foreach (['scan-email-error', 'scan-website-error', 'scan-privacy-error'] as $id) {
        assert_true(str_contains($html, 'id="' . $id . '"'));
    }
    assert_true(str_contains($html, 'Ik scan websites die eindigen op .nl, .be, .eu of .com.'));
    $config = ai_scan_default_config('');
    assert_same(['nl', 'be', 'eu', 'com'], $config['website_allowed_tlds'], 'HTML-tekst volgt de standaardconfig');
    assert_same('.nl, .be, .eu of .com', ai_scan_allowed_tlds_text($config['website_allowed_tlds']));
});

// 4. Copy ------------------------------------------------------------------------

test('free-scan limit sentence is clear everywhere and "Tool" is gone', static function (): void {
    $html = geo_src('ai-vindbaarheid/index.html');
    assert_true(str_contains($html, 'De gratis scan meet alle vijf. Je kunt hem één keer doen per bedrijf, per e-mailadres en per internetverbinding.'));
    assert_true(!str_contains($html, 'één keer per bedrijf, e-mailadres en internetverbinding'));
    assert_true(!str_contains($html, 'Eén keer per bedrijf, e-mailadres en internetverbinding'));
    assert_true(str_contains($html, 'Je kunt de scan één keer doen per bedrijf, per e-mailadres en per internetverbinding'));
    assert_true(!str_contains($html, 'Tool ·'));
    assert_true(str_contains($html, 'Pakket · alleen meten · wekelijks'));
});

// 5. /app/ ---------------------------------------------------------------------------

test('/app/ redirects to the overview instead of a bare 403', static function (): void {
    assert_true(is_file(geo_root() . '/app/index.php'));
    $src = geo_src('app/index.php');
    assert_true(str_contains($src, '/app/overzicht.php'));
    assert_true(str_contains($src, 'Location'));
    assert_true(preg_match('/DirectoryIndex\s+index\.html\s+index\.php/', geo_src('.htaccess')) === 1);
});

// 6. Wachtwoord vergeten ---------------------------------------------------------------

function geo_user_with_password(PDO $pdo, string $email, string $password): int
{
    $pdo->prepare('INSERT INTO users(email, email_hash, password_hash, created_at, free_scan_used) VALUES(:e, :h, :p, 1, 1)')
        ->execute(['e' => $email, 'h' => ai_scan_email_hash(ai_scan_normalize_email($email)), 'p' => ai_scan_hash_password($password)]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO scans(public_id, user_id, email, email_hash, website_url, domain, domain_hash, status, created_at)
         VALUES(:p, :u, :e, :h, 'https://firma.nl/', 'firma.nl', :dh, 'klaar', 1)"
    )->execute(['p' => bin2hex(random_bytes(8)), 'u' => $uid, 'e' => $email, 'h' => ai_scan_email_hash(ai_scan_normalize_email($email)), 'dh' => ai_scan_domain_hash('firma.nl')]);

    return $uid;
}

test('password reset: mail with one-time link to the existing token page, neutral for unknown accounts', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    $now = time();
    $uid = geo_user_with_password($pdo, 'klant+x@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);

    assert_same('no_account', ai_scan_request_password_reset($pdo, $config, 'niemand@firma.nl', '3.3.3.3', $now));
    assert_same([], geo_mails($mailLog), 'geen mail voor onbekend adres');

    // Alias-variant van het adres vindt het account; mail gaat naar het opgeslagen adres.
    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'Klant@Firma.nl', '3.3.3.3', $now));
    $mails = geo_mails($mailLog);
    assert_same(1, count($mails));
    assert_same('klant+x@firma.nl', $mails[0]['to']);
    assert_true(preg_match('#https://jpwebcreation\.nl/ai-scan/wachtwoord/\?t=([A-Za-z0-9_-]{32,})#', (string) $mails[0]['body'], $m) === 1);
    assert_true(!preg_match('/\b(wij|we|ons)\b/iu', (string) $mails[0]['body']));
    assert_true(str_ends_with(rtrim((string) $mails[0]['body']), "Groet,\nJoris"));

    // Per e-mailadres beperkt.
    assert_same('rate_limited', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '3.3.3.4', $now + 5));
    assert_same(1, count(geo_mails($mailLog)));

    // Link werkt één keer, verandert het wachtwoord en maakt oude sessies ongeldig.
    $before = (int) $pdo->query('SELECT password_version FROM users WHERE id = ' . $uid)->fetchColumn();
    $set = ai_scan_set_password($pdo, $m[1], 'nieuw-wachtwoord-456', $now + 60);
    assert_true($set['ok']);
    assert_same('klant+x@firma.nl', $set['email']);
    assert_same($before + 1, (int) $pdo->query('SELECT password_version FROM users WHERE id = ' . $uid)->fetchColumn());
    $hash = (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $uid)->fetchColumn();
    assert_true(ai_scan_verify_password('nieuw-wachtwoord-456', $hash) && !ai_scan_verify_password('oud-wachtwoord-123', $hash));
    assert_true(!ai_scan_set_password($pdo, $m[1], 'weer-een-ander-1', $now + 70)['ok'], 'eenmalig');
});

test('password reset: link expires after an hour and ip is rate limited', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '3.3.3.3', $now);
    preg_match('#\?t=([A-Za-z0-9_-]+)#', (string) geo_mails($mailLog)[0]['body'], $m);
    $r = ai_scan_set_password($pdo, $m[1], 'nieuw-wachtwoord-456', $now + 3601);
    assert_same('expired', $r['code']);

    $config['reset_email_seconds'] = 0;
    $config['reset_ip_max_per_hour'] = 3;
    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = ai_scan_request_password_reset($pdo, $config, 'x' . $i . '@firma.nl', '3.3.3.9', $now + 10 + $i);
    }
    assert_same(['no_account', 'no_account', 'no_account', 'rate_limited', 'rate_limited'], $results);
});

test('login page links to password reset and back to the site; reset page is neutral', static function (): void {
    $login = geo_src('login/index.php');
    assert_true(str_contains($login, 'href="/login/vergeten/">Wachtwoord vergeten?'));
    assert_true(str_contains($login, '← Terug naar jpwebcreation.nl'));
    assert_true(str_contains($login, 'email_hash = :h'), 'login zoekt op genormaliseerde hash');
    $reset = geo_src('login/vergeten/index.php');
    assert_true(str_contains($reset, 'Als er een account bestaat voor dit e-mailadres'));
    assert_true(str_contains($reset, 'ai_scan_verify_csrf'));
    assert_true(str_contains($reset, '← Terug naar jpwebcreation.nl'));
    assert_true(!str_contains($reset, "\$outcome ===") || str_contains($reset, "'mail_failed'"));
    $pw = geo_src('ai-scan/wachtwoord/index.php');
    assert_true(str_contains($pw, 'ai_scan_login_prefill') && str_contains($pw, "'email' => (string) (\$result['email'] ?? '')"));
});

test('P2-N3: wachttijdmelding bij vergeten is exact gelijk voor bestaand en onbekend adres', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    // Eerste verzoek telt voor beide adressen; het tweede valt onder de limiet vóór de accountopzoeking.
    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '5.5.5.1', $now));
    assert_same('no_account', ai_scan_request_password_reset($pdo, $config, 'niemand@firma.nl', '5.5.5.2', $now));
    $existing = ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '5.5.5.3', $now + 5);
    $unknown = ai_scan_request_password_reset($pdo, $config, 'niemand@firma.nl', '5.5.5.4', $now + 5);
    assert_same('rate_limited', $existing);
    assert_same($existing, $unknown, 'zelfde uitkomst voor bestaand en onbekend adres');

    $reset = geo_src('login/vergeten/index.php');
    $msg = 'Je hebt net al een link aangevraagd. Probeer het later opnieuw.';
    assert_true(str_contains($reset, "\$outcome === 'rate_limited'") && str_contains($reset, "\$error = '$msg';"), 'exacte wachtmelding bij rate_limited');
    assert_same(1, substr_count($reset, $msg), 'melding staat op één plek, dus identiek voor beide adressen');
    assert_true(!str_contains($msg, 'minuten'), 'geen vast aantal minuten: e-mail- en IP-limiet hebben verschillende wachttijden');
    assert_true(str_contains($reset, 'Als er een account bestaat voor dit e-mailadres'), 'neutrale melding blijft voor andere uitkomsten');
});

test('login by alias address: account found via normalized hash', static function (): void {
    [$config, $pdo] = boot_test_db();
    $now = time();
    assert_same('check_mail', geo_submit($pdo, $config, 'Naam+Winkel@Firma.nl', 'https://firma.nl', '2.2.2.2', $now)['code']);
    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h')->execute(['h' => ai_scan_hash_token('tal')]);
    ai_scan_confirm($pdo, $config, 'tal', $now);
    $stmt = $pdo->prepare('SELECT email FROM users WHERE email_hash = :h');
    $stmt->execute(['h' => ai_scan_email_hash(ai_scan_normalize_email('naam@firma.nl'))]);
    assert_same('naam+winkel@firma.nl', (string) $stmt->fetchColumn());
});

// 7. Prijzen ---------------------------------------------------------------------------------

test('pricing "Kies dit" sets hours and the questions shown on the card', static function (): void {
    $js = geo_src('ai-vindbaarheid/ai-vindbaarheid.js');
    assert_true(preg_match('/input\.checked = true;\s*\}\s*\/\/[^\n]*\n\s*range\.value = "0";/', $js) === 1);
    assert_true(str_contains($js, '`met ${first.questions} vragen'));
    // 1000+: geen lege prijsdetails of Engels label meer.
    assert_true(str_contains($js, 'Prijs op maat'));
    assert_true(!str_contains($js, '? "—"'));
});

// 8. +alias ------------------------------------------------------------------------------------

test('+alias: delivery address kept for storage and mail, normalized form only for hashes and limits', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $now = time();
    @unlink($mailLog);
    assert_same('check_mail', geo_submit($pdo, $config, '  Jan.Jansen+Scan@Gmail.com ', 'https://firma.nl', '5.5.5.5', $now)['code']);
    $scan = $pdo->query('SELECT * FROM scans')->fetch();
    assert_same('jan.jansen+scan@gmail.com', $scan['email']);
    assert_same(ai_scan_email_hash('janjansen@gmail.com'), $scan['email_hash']);
    $mails = geo_mails($mailLog);
    assert_same('jan.jansen+scan@gmail.com', $mails[0]['to']);
    // Dezelfde persoon zonder alias/punten: limiet werkt op de genormaliseerde vorm.
    assert_same('already_scanned', geo_submit($pdo, $config, 'jan.jansen@gmail.com', 'https://ander.nl', '5.5.5.6', $now + 1)['code']);
    // Ongeldige aliassen blijven ongeldig.
    assert_same('invalid', geo_submit($pdo, $config, 'jan+@@gmail.com', 'https://firma.nl', '5.5.5.7', $now + 2)['code']);
});

// 9. Kleinigheden -----------------------------------------------------------------------------------

test('mails sign with Groet, Joris', static function (): void {
    [$config, , , $mailLog] = boot_test_db();
    @unlink($mailLog);
    ai_scan_mail_confirm($config, 'a@firma.nl', 'firma.nl', 'https://jpwebcreation.nl/ai-scan.php?confirm=x');
    ai_scan_mail_welcome($config, 'a@firma.nl', 'firma.nl', 'https://x/p', 'https://x/d');
    ai_scan_mail_scan_ready($config, 'a@firma.nl', 'firma.nl', 'https://x/d');
    foreach (geo_mails($mailLog) as $mail) {
        assert_true(str_ends_with(rtrim((string) $mail['body']), "Groet,\nJoris"), (string) $mail['subject']);
    }
    assert_same(3, count(geo_mails($mailLog)));
});

test('GET /ai-scan.php without action redirects to the form page instead of 405', static function (): void {
    $src = geo_src('ai-scan.php');
    assert_true(str_contains($src, "\$method === 'GET' && !\$asJson"));
    assert_true(str_contains($src, "ai_scan_redirect('/ai-vindbaarheid/', 302)"));
});

test('mobile tables explain that they scroll', static function (): void {
    $html = geo_src('ai-vindbaarheid/index.html');
    assert_same(1, substr_count($html, 'class="geo-swipe-hint"'));
    assert_true(str_contains(geo_src('ai-vindbaarheid/ai-vindbaarheid.css'), '.geo-swipe-hint'));
});

test('script.js cache-bust is shared and bumped in every html file', static function (): void {
    $versions = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(geo_root(), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'html' && !str_contains($f->getPathname(), '/.git/')) {
            if (preg_match('/script\.js\?v=(\d+)/', (string) file_get_contents($f->getPathname()), $m)) {
                $versions[$m[1]] = true;
            }
        }
    }
    assert_same(1, count($versions), 'één gedeelde versie: ' . implode(',', array_keys($versions)));
});

// 10. Wachtrij-indicator ----------------------------------------------------------------------------

test('queue indicator: true only when a job is ready for the runner (same definition as next job)', static function (): void {
    [$config, $pdo] = boot_test_db();
    $now = time();
    assert_same(false, ai_scan_has_runnable_job($pdo, $config, $now));
    geo_insert_scan($pdo, 'onbevestigd');
    $id = geo_insert_scan($pdo, 'bezig', 0, 'bezig.nl');
    geo_insert_scan($pdo, 'klaar', 0, 'klaar.nl');
    geo_insert_scan($pdo, 'mislukt', 2, 'mislukt.nl');
    assert_same(false, ai_scan_has_runnable_job($pdo, $config, $now));
    assert_same(null, ai_scan_next_job($pdo, $config, $now));

    $wait = geo_insert_scan($pdo, 'bevestigd', 0, 'wacht.nl');
    assert_same(true, ai_scan_has_runnable_job($pdo, $config, $now));
    assert_true(ai_scan_next_job($pdo, $config, $now) !== null);
    ai_scan_claim_job($pdo, $wait);
    assert_same(false, ai_scan_has_runnable_job($pdo, $config, $now));

    // Wachtdag: pas werk als er vandaag ruimte is.
    $pdo->exec("UPDATE scans SET status = 'klaar'");
    geo_insert_scan($pdo, 'wacht_dag', 0, 'dag.nl');
    $config['daily_free_scans'] = 20;
    assert_same(true, ai_scan_has_runnable_job($pdo, $config, $now));
    $config['daily_free_scans'] = 1;
    assert_same(false, ai_scan_has_runnable_job($pdo, $config, $now));
    unset($id);
});

test('queue endpoint prints only {"work":bool}, no-store, GET only', static function (): void {
    $dir = sys_get_temp_dir() . '/jpweb-queue-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $sqlite = $dir . '/q.sqlite';
    file_put_contents($dir . '/config.php', '<?php return ' . var_export(['sqlite_path' => $sqlite, 'runner_token' => str_repeat('r', 40), 'app_key' => 'k'], true) . ';');
    $run = static function (string $method) use ($dir): string {
        $cmd = 'JPWEB_AI_SCAN_CONFIG=' . escapeshellarg($dir . '/config.php') . ' REQUEST_METHOD=' . $method
            . ' php -d variables_order=EGPCS -r ' . escapeshellarg('$_SERVER["REQUEST_METHOD"]=getenv("REQUEST_METHOD"); require ' . var_export(geo_root() . '/ai-scan-queue.php', true) . ';') . ' 2>&1';

        return trim((string) shell_exec($cmd));
    };
    assert_same('{"work":false}', $run('GET'));
    $pdo = new PDO('sqlite:' . $sqlite);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->prepare("INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, queued_at) VALUES('p','e','eh','u','d','dh','bevestigd',1,1)")->execute();
    assert_same('{"work":true}', $run('GET'));
    assert_same('{"work":false}', $run('POST'));
    $src = geo_src('ai-scan-queue.php');
    assert_true(str_contains($src, 'Cache-Control: no-store'));
    foreach ([$dir . '/q.sqlite', $dir . '/q.sqlite-wal', $dir . '/q.sqlite-shm', $dir . '/config.php'] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});

// 11. Retry-belofte -------------------------------------------------------------------------------------

test('retry promise: first failure re-queues once, second is final with an internal alert', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $now = time();
    @unlink($mailLog);
    $id = geo_insert_scan($pdo, 'bevestigd', 0, 'retry.nl');
    assert_true(ai_scan_claim_job($pdo, $id, $now) !== null);

    $r1 = ai_scan_fail_or_retry_job($pdo, $config, $id, 'te weinig concurrenten afgeleid (0)', $now + 10, 0.42);
    assert_same(['ok' => true, 'retry' => true, 'final' => false, 'attempts' => 1], $r1);
    $row = $pdo->query('SELECT * FROM scans WHERE id = ' . $id)->fetch();
    assert_same('bevestigd', $row['status']);
    assert_same(1, (int) $row['attempts']);
    assert_same(null, $row['claimed_at']);
    assert_same(null, $row['error_message'], 'klant ziet geen interne foutmelding bij herpoging');
    assert_same('te weinig concurrenten afgeleid (0)', $row['last_failure']);
    $job = ai_scan_next_job($pdo, $config, $now + 11);
    assert_same($id, (int) $job['id'], 'job staat weer klaar voor de runner');
    assert_true(abs((float) $job['spent_eur'] - 0.42) < 1e-9, 'herpoging krijgt besteed bedrag mee (restant-budget)');
    ai_scan_fail_or_retry_job($pdo, $config, 999999, 'x', $now + 11, 0.10);
    assert_same([], geo_mails($mailLog), 'geen mail bij herpoging');

    assert_true(ai_scan_claim_job($pdo, $id, $now + 12) !== null);
    $r2 = ai_scan_fail_or_retry_job($pdo, $config, $id, 'opnieuw mislukt', $now + 20, 0.30);
    assert_true(abs((float) $pdo->query('SELECT spent_eur FROM scans WHERE id = ' . $id)->fetchColumn() - 0.42) < 1e-9, 'besteed gaat nooit omlaag');
    assert_same(['ok' => true, 'retry' => false, 'final' => true, 'attempts' => 2], $r2);
    $row = $pdo->query('SELECT * FROM scans WHERE id = ' . $id)->fetch();
    assert_same('mislukt', $row['status']);
    assert_same(2, (int) $row['attempts']);
    assert_same('opnieuw mislukt', $row['error_message']);
    assert_same(null, ai_scan_next_job($pdo, $config, $now + 21));
    assert_same(false, ai_scan_has_runnable_job($pdo, $config, $now + 21));

    $mails = geo_mails($mailLog);
    assert_same(1, count($mails), 'alleen een interne melding, niets naar de klant');
    assert_same('info@jpwebcreation.nl', $mails[0]['to']);
    assert_true(str_contains((string) $mails[0]['subject'], 'retry.nl'));

    // Derde melding op een al mislukte scan wordt geweigerd.
    assert_same(['ok' => false], ai_scan_fail_or_retry_job($pdo, $config, $id, 'nog eens', $now + 30));
});

test('expired claim: process death after claim is retried once, then fails finally', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $now = time();
    @unlink($mailLog);
    $id = geo_insert_scan($pdo, 'bevestigd', 0, 'claimdood.nl');
    assert_true(ai_scan_claim_job($pdo, $id, $now) !== null);
    $stale = (int) $config['stale_job_seconds'];

    // Nog niet verlopen: blijft bezig.
    assert_same(0, ai_scan_recover_expired_claims($pdo, $config, $now + $stale - 1));
    assert_same('bezig', (string) $pdo->query("SELECT status FROM scans WHERE id = $id")->fetchColumn());

    // Verlopen: terug de wachtrij in, uitvoerbaar voor de runner.
    ai_scan_check_stale_jobs($pdo, $config, $now + $stale + 1);
    $row = $pdo->query("SELECT status, attempts, claimed_at FROM scans WHERE id = $id")->fetch();
    assert_same('bevestigd', (string) $row['status']);
    assert_same(1, (int) $row['attempts']);
    assert_true($row['claimed_at'] === null);
    $next = ai_scan_next_job($pdo, $config, $now + $stale + 2);
    assert_true($next !== null && (int) $next['id'] === $id);

    // Tweede poging valt ook uit: definitief mislukt, geen derde ronde.
    assert_true(ai_scan_claim_job($pdo, $id, $now + $stale + 3) !== null);
    assert_same(1, ai_scan_recover_expired_claims($pdo, $config, $now + 2 * $stale + 10));
    $row = $pdo->query("SELECT status, attempts FROM scans WHERE id = $id")->fetch();
    assert_same('mislukt', (string) $row['status']);
    assert_same(2, (int) $row['attempts']);
    assert_same(0, ai_scan_recover_expired_claims($pdo, $config, $now + 5 * $stale));
});

test('expired claim: late result of an old attempt is ignored', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $now = time();
    @unlink($mailLog);
    $id = geo_insert_scan($pdo, 'bevestigd', 0, 'laat.nl');
    $old = ai_scan_claim_job($pdo, $id, $now);
    $oldToken = (int) $old['claimed_at'];
    $stale = (int) $config['stale_job_seconds'];
    $later = $now + $stale + 5;
    ai_scan_recover_expired_claims($pdo, $config, $later);
    $new = ai_scan_claim_job($pdo, $id, $later + 1);
    assert_true($new !== null);

    // De oude poging meldt zich laat: resultaat en fout worden genegeerd.
    assert_true(!ai_scan_complete_job($pdo, $config, $id, '<p>oud</p>', '{"a":1}', $later + 2, $oldToken));
    assert_same(['ok' => false], ai_scan_fail_or_retry_job($pdo, $config, $id, 'oud', $later + 2, 0.0, $oldToken));
    $row = $pdo->query("SELECT status, result_html, attempts FROM scans WHERE id = $id")->fetch();
    assert_same('bezig', (string) $row['status']);
    assert_true($row['result_html'] === null);
    assert_same(1, (int) $row['attempts']);

    // De huidige poging mag wel afronden.
    assert_true(ai_scan_complete_job($pdo, $config, $id, '<p>nieuw</p>', '{"a":2}', $later + 3, (int) $new['claimed_at']));
    assert_same('klaar', (string) $pdo->query("SELECT status FROM scans WHERE id = $id")->fetchColumn());
});

test('retry promise: runner.php wires fail to retry and exposes it in health; copy and admin page', static function (): void {
    $runner = geo_src('api/runner.php');
    assert_true(str_contains($runner, 'ai_scan_fail_or_retry_job($pdo, $config, $id, $message, 0, $spent, '));
    foreach (["'retry' =>", "'final' =>", "'attempts' =>", "'retrying' => \$retrying", "'failed' => \$failed"] as $needle) {
        assert_true(str_contains($runner, $needle), $needle);
    }
    $ov = geo_src('app/overzicht.php');
    assert_true(str_contains($ov, 'De scan lukte niet in één keer.'));
    assert_true(str_contains($ov, 'Ik probeer het nog één keer automatisch; je krijgt een mail als hij klaar is.'));
    assert_true(str_contains($ov, 'De scan is niet gelukt. Ik kijk er zelf naar en neem contact met je op.'));
    assert_true(!str_contains($ov, 'start hem opnieuw'));
    $admin = geo_src('admin/scans.php');
    assert_true(str_contains($admin, 'ai_scan_require_admin_user') && str_contains($admin, 'noindex'));
});

test('retry promise: customer view flags a retrying scan; migration is idempotent', static function (): void {
    [, $pdo] = boot_test_db();
    ai_scan_migrate($pdo);
    ai_scan_migrate($pdo);
    $cols = array_column($pdo->query('PRAGMA table_info(scans)')->fetchAll(), 'name');
    assert_true(in_array('attempts', $cols, true) && in_array('last_failure', $cols, true));
    $v = app_score_view([], ['status' => 'bevestigd', 'attempts' => 1]);
    assert_same(true, $v['retrying']);
    assert_same(false, app_score_view([], ['status' => 'bevestigd', 'attempts' => 0])['retrying']);
    assert_same(false, app_score_view([], ['status' => 'mislukt', 'attempts' => 2])['retrying']);
});

test('retry view keeps live progress polling; logout in sidebar beats .logout-form button', static function (): void {
    $ov = geo_src('app/overzicht.php');
    $retry = substr($ov, (int) strpos($ov, "!empty(\$score['retrying'])"), 400);
    assert_true(str_contains($retry, 'data-live-progress'), 'retry branch must poll for the final status');
    $css = geo_src('app/assets/app.css');
    assert_true(preg_match('/^\s*\.logout-form \.app-nav__logout\s*\{[^}]*color:\s*var\(--app-muted\)/m', $css) === 1, 'sidebar logout muted colour must outrank .logout-form button');
    assert_true(preg_match('/\.logout-form \.app-nav__logout\s*\{[^}]*padding-left:\s*0\.85rem/', $css) === 1, 'aligned with .app-nav__link--text');
    $js = geo_src('app/assets/app.js');
    assert_true(str_contains($js, 'if (!stepEl || !srcEl || !ansEl) return;'), 'render tolerates the retry box');
});

test('testronde 0255: wacht_dag-blok pollt mee; boven 1000 vragen staan mail- en bellink als DOM-elementen', static function (): void {
    $ov = geo_src('app/overzicht.php');
    $wait = substr($ov, (int) strpos($ov, "=== 'wacht_dag'"), 250);
    assert_true(str_contains($wait, 'data-live-progress'), 'wacht_dag-blok moet pollen');
    $js = geo_src('app/assets/app.js');
    assert_true(str_contains($js, "d.status !== 'wacht_dag'"), 'wachtblok herlaadt zodra de status wijzigt');
    assert_true(str_contains($js, 'if (!stepEl || !srcEl || !ansEl) return;'), 'render tolerant voor blok zonder stapelementen');
    $geo = geo_src('ai-vindbaarheid/ai-vindbaarheid.js');
    assert_true(str_contains($geo, 'mailto:info@jpwebcreation.nl') && str_contains($geo, 'tel:+31621228884'), 'mail- en bellink aanwezig');
    $contact = substr($geo, (int) strpos($geo, 'if (contactEl instanceof HTMLElement)'), 700);
    assert_true(!str_contains($contact, 'innerHTML') && str_contains($contact, 'createElement("a")'), 'contactblok via DOM-elementen');
    assert_true(!str_contains($geo, 'Mail of bel me.";'), 'geen platte tekst meer');
    assert_true(str_contains(geo_src('app/layout.php'), 'app.js?v=6'), 'app.js cache-bust');
});

test('M3/P3-A: promo belooft geen "één klik" meer en de AI-tabel past op 390 px zonder scrollen', static function (): void {
    $html = geo_src('ai-vindbaarheid/index.html');
    assert_true(!str_contains($html, 'met één klik'), 'bevestigen kost nu mail plus knop');
    assert_true(str_contains($html, 'Je krijgt een mail met een knop. Na het bevestigen start ik je scan.'));
    $css = geo_src('ai-vindbaarheid/ai-vindbaarheid.css');
    assert_true(!(bool) preg_match('/\.geo-ai-table,\s*\.geo-pricing-table \{[^}]*min-width/', $css), 'AI-tabel heeft geen vaste minimumbreedte');
    assert_true(!str_contains($css, 'min-width: 520px'));
    assert_true(str_contains($html, 'ai-vindbaarheid.css?v=11'));
});
