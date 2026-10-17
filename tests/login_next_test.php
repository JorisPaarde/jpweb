<?php

declare(strict_types=1);

/**
 * N17-3: de bestemming (?next=) blijft behouden via "Wachtwoord vergeten?" en het herstel, zonder open redirect.
 */

/** Rendert een pagina met eigen GET/POST; geeft ['html' => ..., 'redirect' => ?string]. */
function login_next_render(array $config, string $script, array $get, array $post = []): array
{
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['REMOTE_ADDR'] = '9.8.7.6';
    unset($_SESSION['user_id']);
    $_GET = $get;
    $_POST = $post;
    $cfgPath = sys_get_temp_dir() . '/jpweb-loginnext-config-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($cfgPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    putenv('JPWEB_AI_SCAN_CONFIG=' . $cfgPath);
    putenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT=1');
    $redirect = null;
    ob_start();
    try {
        include dirname(__DIR__) . '/' . $script;
    } catch (RuntimeException $e) {
        if (!str_starts_with($e->getMessage(), 'REDIRECT ')) {
            ob_end_clean();
            throw $e;
        }
        $redirect = substr($e->getMessage(), 9);
    } finally {
        putenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT');
        @unlink($cfgPath);
    }

    return ['html' => (string) ob_get_clean(), 'redirect' => $redirect];
}

test('safe_next: weigert alles buiten de eigen site', static function (): void {
    foreach ([
        '//evil.example', '/\\evil.example', '\\\\evil', 'https://evil.example', 'javascript:alert(1)',
        '/%2F%2Fevil.example', '/%2f/evil.example', '%2F%2Fevil.example', ' /x', "\t/x", '', 'app/vragen.php',
        "/x\r\nSet-Cookie: a=b", "/a\\b", '/a%5Cb', '/%5Cevil.example',
    ] as $bad) {
        assert_same('/dashboard/', ai_scan_safe_next($bad), 'geweigerd: ' . json_encode($bad));
    }
    assert_same('/x', ai_scan_safe_next('//evil', '/x'), 'eigen fallback');
});

test('safe_next: accepteert paden binnen de site ongewijzigd', static function (): void {
    foreach (['/app/vragen.php', '/app/vragen.php?x=1#y', '/dashboard/', '/a/b?c=%20d'] as $good) {
        assert_same($good, ai_scan_safe_next($good));
    }
});

test('login: Wachtwoord vergeten? neemt next over, standaardbestemming niet', static function (): void {
    [$config] = boot_test_db();
    $r = login_next_render($config, 'login/index.php', ['next' => '/app/vragen.php?x=1&y=2']);
    assert_true(str_contains($r['html'], 'href="/login/vergeten/?next=%2Fapp%2Fvragen.php%3Fx%3D1%26y%3D2"'), 'next in vergeten-link');
    $r = login_next_render($config, 'login/index.php', []);
    assert_true(str_contains($r['html'], 'href="/login/vergeten/"'), 'kale link bij standaard');
    $r = login_next_render($config, 'login/index.php', ['next' => '//evil.example']);
    assert_true(str_contains($r['html'], 'href="/login/vergeten/"') && !str_contains($r['html'], 'evil'), 'kwaadaardige next verdwijnt');
});

test('vergeten: behoudt next in formulier en terug-link, weigert kwaadaardige next', static function (): void {
    [$config] = boot_test_db();
    $r = login_next_render($config, 'login/vergeten/index.php', ['next' => '/app/vragen.php']);
    assert_true(str_contains($r['html'], '<input type="hidden" name="next" value="/app/vragen.php">'), 'hidden veld');
    assert_true(str_contains($r['html'], 'href="/login/?next=%2Fapp%2Fvragen.php"'), 'terug-link');
    foreach (['//evil.example', 'https://evil.example', '/\\evil.example'] as $bad) {
        $r = login_next_render($config, 'login/vergeten/index.php', ['next' => $bad]);
        assert_true(!str_contains($r['html'], 'evil'), 'geen evil in pagina: ' . $bad);
        assert_true(str_contains($r['html'], 'href="/login/"'), 'kale terug-link');
    }
});

test('vergeten: verzonden aanvraag houdt next uit mail en melding, en bewaart hem in de sessie', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 0;
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);
    unset($_SESSION['ai_scan_reset_next']);
    $csrf = ai_scan_csrf_token($config);
    $r = login_next_render($config, 'login/vergeten/index.php', [], ['csrf' => $csrf, 'email' => 'klant@firma.nl', 'next' => '/app/vragen.php']);
    assert_true(str_contains($r['html'], 'Als er een account bestaat voor dit e-mailadres'));
    assert_true(str_contains($r['html'], 'href="/login/?next=%2Fapp%2Fvragen.php"'), 'terug-link na verzenden');
    $mails = geo_mails($mailLog);
    assert_same(1, count($mails));
    assert_true(!str_contains(json_encode($mails), 'vragen'), 'next staat niet in de mail');
    assert_same('/app/vragen.php', $_SESSION['ai_scan_reset_next']['next'] ?? null);
});

test('vergeten: kwaadaardige next komt niet in de sessie; identieke melding voor onbekend adres', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['reset_email_seconds'] = 0;
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    unset($_SESSION['ai_scan_reset_next']);
    $csrf = ai_scan_csrf_token($config);
    login_next_render($config, 'login/vergeten/index.php', [], ['csrf' => $csrf, 'email' => 'klant@firma.nl', 'next' => '//evil.example']);
    assert_true(!isset($_SESSION['ai_scan_reset_next']), 'niets bewaard');
    $a = login_next_render($config, 'login/vergeten/index.php', [], ['csrf' => $csrf, 'email' => 'klant@firma.nl', 'next' => '/app/vragen.php']);
    $b = login_next_render($config, 'login/vergeten/index.php', [], ['csrf' => $csrf, 'email' => 'niemand@firma.nl', 'next' => '/app/vragen.php']);
    assert_same($a['html'], $b['html'], 'melding identiek');
});

test('wachtwoord: na herstel gaat login naar de oorspronkelijke bestemming, eenmalig en alleen als geldig', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 0;
    $now = time();
    $uid = geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    $other = static function (string $ip) use ($pdo, $config, $mailLog, $now): string {
        @unlink($mailLog);
        ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', $ip, $now);
        return reset_link_token($mailLog, 0);
    };
    $post = static fn (string $t): array => ['csrf' => ai_scan_csrf_token($config), 't' => $t, 'password' => 'nieuw-wachtwoord-456', 'password2' => 'nieuw-wachtwoord-456'];

    // Geldig: doorgeven aan login en uit de sessie halen.
    $_SESSION['ai_scan_reset_next'] = ['next' => '/app/vragen.php?x=1#y', 'at' => time()];
    $r = login_next_render($config, 'ai-scan/wachtwoord/index.php', [], $post($other('9.9.9.1')));
    assert_same('/login/?next=' . rawurlencode('/app/vragen.php?x=1#y'), $r['redirect']);
    assert_true(!isset($_SESSION['ai_scan_reset_next']), 'uit sessie gehaald');
    $login = login_next_render($config, 'login/index.php', ['next' => '/app/vragen.php?x=1']);
    assert_true(str_contains($login['html'], 'name="next" value="/app/vragen.php?x=1"'));

    // Zonder sessie-next, verlopen of kwaadaardig: gewoon /login/.
    foreach ([null, ['next' => '/app/vragen.php', 'at' => time() - 3601], ['next' => '//evil.example', 'at' => time()]] as $state) {
        unset($_SESSION['ai_scan_reset_next']);
        if ($state !== null) {
            $_SESSION['ai_scan_reset_next'] = $state;
        }
        $r = login_next_render($config, 'ai-scan/wachtwoord/index.php', [], $post($other('9.9.9.2')));
        assert_same('/login/', $r['redirect'], 'fallback ' . json_encode($state));
        assert_true(!isset($_SESSION['ai_scan_reset_next']), 'sessie opgeruimd');
    }
});

test('open redirect via next op login is onmogelijk', static function (): void {
    [$config, $pdo] = boot_test_db();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    foreach (['//evil.example', '/\\evil.example', 'https://evil.example', '/%2F%2Fevil.example'] as $bad) {
        $r = login_next_render($config, 'login/index.php', [], [
            'csrf' => ai_scan_csrf_token($config), 'email' => 'klant@firma.nl', 'password' => 'oud-wachtwoord-123', 'next' => $bad,
        ]);
        assert_same('/dashboard/', $r['redirect'], 'login-redirect voor ' . $bad);
    }
    unset($_SESSION['user_id']);
});
