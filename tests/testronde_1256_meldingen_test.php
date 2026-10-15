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
    assert_same('already_scanned', geo_submit($pdo, $config, 'a@een.nl', 'https://twee.nl', '7.7.7.1', $now + 2)['code']);

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
    assert_same(3600, $r['retry_after']);

    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 60;
    $config['form_burst_max'] = 1;
    ai_scan_form_rate_record($pdo, '7.7.7.3', null, null, $now);
    $r = geo_submit($pdo, $config, 'c@drie.nl', 'https://drie.nl', '7.7.7.3', $now + 1);
    assert_same(60, $r['retry_after']);

    assert_same('over een minuut', ai_scan_wait_phrase(60));
    assert_same('over 10 minuten', ai_scan_wait_phrase(600));
    assert_same('over een uur', ai_scan_wait_phrase(3600));
    assert_same('morgen', ai_scan_wait_phrase(86400));
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
