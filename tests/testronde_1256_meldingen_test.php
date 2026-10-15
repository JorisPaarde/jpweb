<?php

declare(strict_types=1);

/**
 * Testronde 14 okt 2026 (12:56): meldingteksten rond de gratis scan
 * (P2-2/N2, P2-3, N3/P3-6, P3-13/N8, N7, P3-5).
 */
test('P2-2/N2: already_scanned belooft geen mail die niet komt', static function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/script.js');
    assert_true(!str_contains($js, 'Ik stuur een inloglink'), 'geen belofte van een inloglink');
    assert_true(str_contains($js, 'Log in met het e-mailadres van die scan.'));
});

test('P2-3: alleen-IP-blokkade krijgt eigen melding, e-mail of domein houdt already_scanned', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $now = time();
    assert_same('check_mail', geo_submit($pdo, $config, 'a@een.nl', 'https://een.nl', '7.7.7.1', $now)['code']);
    assert_same('ip_already_scanned', geo_submit($pdo, $config, 'b@twee.nl', 'https://twee.nl', '7.7.7.1', $now + 1)['code']);
    // P3-N3: zelfde IP, maar e-mail of domein al gebruikt: dezelfde melding, dus geen verschil af te lezen.
    assert_same('ip_already_scanned', geo_submit($pdo, $config, 'a@een.nl', 'https://twee.nl', '7.7.7.1', $now + 2)['code']);
    assert_same('ip_already_scanned', geo_submit($pdo, $config, 'b@twee.nl', 'https://een.nl', '7.7.7.1', $now + 3)['code']);
    // Ander IP: e-mail of domein gebruikt blijft already_scanned.
    assert_same('already_scanned', geo_submit($pdo, $config, 'a@een.nl', 'https://twee.nl', '7.7.7.9', $now + 4)['code']);

    $js = (string) file_get_contents(dirname(__DIR__) . '/script.js');
    assert_true(str_contains($js, 'ip_already_scanned:') && str_contains($js, 'info@jpwebcreation.nl'));
    $page = (string) file_get_contents(dirname(__DIR__) . '/ai-scan/index.php');
    assert_true(str_contains($page, "'ip_already_scanned' =>"));
});

test('N3/P3-6: rate_limited geeft de werkelijke wachttijd van de aangesproken limiet', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $config['form_ip_max_per_hour'] = 1;
    $now = time();
    assert_same('check_mail', geo_submit($pdo, $config, 'a@een.nl', 'https://een.nl', '7.7.7.2', $now)['code']);
    $r = geo_submit($pdo, $config, 'b@twee.nl', 'https://twee.nl', '7.7.7.2', $now + 1);
    assert_same('rate_limited', $r['code']);
    assert_same(3599, $r['retry_after']);

    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 60;
    $config['form_burst_max'] = 1;
    ai_scan_form_rate_record($pdo, '7.7.7.3', null, null, $now);
    $r = geo_submit($pdo, $config, 'c@drie.nl', 'https://drie.nl', '7.7.7.3', $now + 1);
    assert_same(59, $r['retry_after']);

    assert_same('over een minuut', ai_scan_wait_phrase(60));
    assert_same('over 10 minuten', ai_scan_wait_phrase(600));
    assert_same('over een uur', ai_scan_wait_phrase(3600));
    assert_same('over 24 uur', ai_scan_wait_phrase(86400));
    assert_same('over 5 uur', ai_scan_wait_phrase(4 * 3600 + 1));
    assert_same('', ai_scan_wait_phrase(0));

    $js = (string) file_get_contents(dirname(__DIR__) . '/script.js');
    assert_true(!str_contains($js, 'over tien minuten') && !str_contains($js, 'over een uur opnieuw'), 'geen vaste wachttijd in de formuliertekst');
    assert_true(str_contains($js, 'payload?.retry_after'));
});

test('N3/P3-5: statuspagina rate_limited toont de wachttijd; HTML-variant stuurt 303 met w', static function (): void {
    [$config] = boot_test_db();
    $html = tr1256_render($config, 'ai-scan/index.php', ['s' => 'rate_limited', 'w' => '3600']);
    assert_true(str_contains($html, 'Probeer het over een uur opnieuw.'));
    assert_true(!str_contains($html, 'tien minuten'));
    $html = tr1256_render($config, 'ai-scan/index.php', ['s' => 'rate_limited']);
    assert_true(str_contains($html, 'Probeer het later opnieuw.'));

    putenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT=1');
    try {
        ob_start();
        ai_scan_respond_code('rate_limited', false, ['retry_after' => 3600]);
        ob_end_clean();
        $caught = null;
    } catch (RuntimeException $e) {
        ob_end_clean();
        $caught = $e;
    } finally {
        putenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT');
    }
    assert_true($caught instanceof RuntimeException, 'redirect');
    assert_same(303, $caught->getCode());
    assert_true(str_contains($caught->getMessage(), '/ai-scan/?s=rate_limited&w=3600'));
});

test('P3-13/N8/N7: geen vaste ~15 minuten op de scanstatuspagina; wachtrijcopy noemt de eerste scan', static function (): void {
    $root = dirname(__DIR__);
    $status = (string) file_get_contents($root . '/ai-scan/index.php');
    assert_true(!str_contains($status, '~15'));
    $overzicht = (string) file_get_contents($root . '/app/overzicht.php');
    assert_true(!str_contains($overzicht, 'Geen meting deze week'));
    assert_true(str_contains($overzicht, 'Je eerste scan staat in de rij'));
});

test('M2: wachttijd is de langste resterende blokkade, geen volledig venster', static function (): void {
    [$config, $pdo] = boot_test_db();
    $now = time();
    $config['form_burst_seconds'] = 60;
    $config['form_burst_max'] = 1;
    $config['form_ip_max_per_hour'] = 2;
    $config['form_ip_max_per_day'] = 3;

    // Uurgrens: oudste poging was 50 minuten geleden -> nog 10 minuten, niet 3600.
    ai_scan_form_rate_record($pdo, '7.7.8.1', null, null, $now - 3000);
    ai_scan_form_rate_record($pdo, '7.7.8.1', null, null, $now - 2000);
    assert_same(600, ai_scan_form_ip_wait($pdo, $config, '7.7.8.1', $now));

    // Daggrens (rollend 24 uur): drie pogingen, de oudste 20 uur geleden -> nog 4 uur, en langer dan de uurgrens.
    ai_scan_form_rate_record($pdo, '7.7.8.2', null, null, $now - 20 * 3600);
    ai_scan_form_rate_record($pdo, '7.7.8.2', null, null, $now - 10 * 3600);
    ai_scan_form_rate_record($pdo, '7.7.8.2', null, null, $now - 5000);
    assert_same(4 * 3600, ai_scan_form_ip_wait($pdo, $config, '7.7.8.2', $now));
    assert_same('over 4 uur', ai_scan_wait_phrase(4 * 3600));

    // Burst: nog 45 seconden.
    ai_scan_form_rate_record($pdo, '7.7.8.3', null, null, $now - 15);
    assert_same(45, ai_scan_form_ip_wait($pdo, $config, '7.7.8.3', $now));

    // E-mail: uurgrens van 3, oudste 40 minuten geleden -> nog 20 minuten.
    $eh = ai_scan_email_hash('m2@voorbeeld.nl');
    $dh = ai_scan_domain_hash('voorbeeld.nl');
    foreach ([2400, 1800, 1200] as $ago) {
        ai_scan_rate_hit($pdo, 'form_email', $eh, $now - $ago);
    }
    assert_same(1200, ai_scan_form_target_wait($pdo, $config, $eh, $dh, $now));

    // Bevestigingsmail: cooldown van 600 s, laatste mail 100 s geleden -> nog 500 s.
    $config['confirm_mail_per_email_seconds'] = 600;
    $ch = ai_scan_email_hash('m2b@voorbeeld.nl');
    ai_scan_rate_hit($pdo, 'confirm_mail_email', $ch, $now - 100);
    assert_same(500, ai_scan_confirm_mail_wait($pdo, $config, $ch, $now));

    // Globale mailgrens wint als die langer duurt dan de cooldown.
    $config['confirm_mail_global_max_per_hour'] = 1;
    ai_scan_rate_hit($pdo, 'confirm_mail_global', ai_scan_rate_global_key(), $now - 600);
    assert_same(3000, ai_scan_confirm_mail_wait($pdo, $config, $ch, $now));

    $js = (string) file_get_contents(dirname(__DIR__) . '/script.js');
    assert_true(!str_contains($js, '"morgen"'), 'geen "morgen" meer in de wachttekst');
});

test('P3-N4: w op de statuspagina toont alleen bekende wachttijden', static function (): void {
    [$config] = boot_test_db();
    $html = tr1256_render($config, 'ai-scan/index.php', ['s' => 'rate_limited', 'w' => '86399']);
    assert_true(!str_contains($html, '1440 minuten') && str_contains($html, 'Probeer het later opnieuw.'));
    $html = tr1256_render($config, 'ai-scan/index.php', ['s' => 'rate_limited', 'w' => '7']);
    assert_true(str_contains($html, 'Probeer het later opnieuw.'));
    $html = tr1256_render($config, 'ai-scan/index.php', ['s' => 'rate_limited', 'w' => '99999999']);
    assert_true(str_contains($html, 'Probeer het later opnieuw.'));
    assert_same(600, ai_scan_wait_bucket(541));
    assert_same(4 * 3600, ai_scan_wait_bucket(3 * 3600 + 5));
    assert_same(86400, ai_scan_wait_bucket(999999));
    assert_same(0, ai_scan_wait_known(541));
    assert_same(600, ai_scan_wait_known(600));
});

test('P3-N5: CSRF-fout bij account verwijderen gaat terug naar het account met melding', static function (): void {
    $src = (string) file_get_contents(dirname(__DIR__) . '/account-delete.php');
    assert_true(!str_contains($src, 'deleted=csrf'));
    assert_true(preg_match("~verify_csrf.*?ai_scan_flash'\] = 'De sessie is verlopen[^;]*;\s*ai_scan_redirect\('/app/account\.php', 303\)~s", $src) === 1);
});
