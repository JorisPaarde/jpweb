<?php

declare(strict_types=1);

/**
 * GEO-testronde 14 okt 2026 (12:56): P3-7, P3-8, P3-10, P3-12.
 */

test('P3-7: de bevestigingslink toont alleen een knop, pas versturen bevestigt', static function (): void {
    [$config, $pdo] = boot_test_db();
    $token = 'bevestig-token-p37';
    $pdo->prepare(
        "INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, confirm_token_hash, confirm_expires_at)
         VALUES('p37scan', 'p37@firma.nl', :eh, 'https://firma.nl/', 'firma.nl', 'dh', 'onbevestigd', :now, :h, :exp)"
    )->execute(['eh' => ai_scan_email_hash('p37@firma.nl'), 'now' => time(), 'h' => ai_scan_hash_token($token), 'exp' => time() + 3600]);

    $get = route_guard_run($config, 'ai-scan.php', [], 'GET', [], ['confirm' => $token]);
    assert_same('', $get['location'], 'GET stuurt niet door');
    assert_true(str_contains($get['body'], '<form method="post"') && str_contains($get['body'], 'name="confirm" value="' . $token . '"'), 'knop met formulier: ' . substr($get['body'], 0, 200));
    assert_same('onbevestigd', (string) $pdo->query("SELECT status FROM scans WHERE public_id = 'p37scan'")->fetchColumn(), 'GET bevestigt niets');
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'GET maakt geen account');

    $post = route_guard_run($config, 'ai-scan.php', [], 'POST', ['confirm' => $token]);
    assert_true(str_starts_with($post['location'], '/ai-scan/wachtwoord/?t='), 'POST bevestigt: ' . $post['location'] . $post['body']);
    assert_same('bevestigd', (string) $pdo->query("SELECT status FROM scans WHERE public_id = 'p37scan'")->fetchColumn());
});

test('P3-8: uitloggen kan alleen via POST met CSRF-token', static function (): void {
    [$config, , $uid] = route_guard_user();
    $csrf = str_repeat('c', 32);
    $session = ['user_id' => $uid, 'password_version' => 1, 'ai_scan_csrf' => $csrf];

    $get = route_guard_run($config, 'logout.php', $session, 'GET');
    assert_same('/app/meer.php', $get['location'], 'GET logt niet uit: ' . $get['body']);
    $noToken = route_guard_run($config, 'logout.php', $session, 'POST');
    assert_same('/app/meer.php', $noToken['location'], 'POST zonder token logt niet uit');
    $wrong = route_guard_run($config, 'logout.php', $session, 'POST', ['csrf' => 'fout']);
    assert_same('/app/meer.php', $wrong['location'], 'POST met fout token logt niet uit');
    $ok = route_guard_run($config, 'logout.php', $session, 'POST', ['csrf' => $csrf]);
    assert_same('/login/', $ok['location'], 'POST met token logt uit: ' . $ok['body']);

    foreach (['app/nav.php', 'app/meer.php'] as $file) {
        $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
        assert_true(!str_contains($src, 'href="/logout.php"'), $file . ' linkt niet meer naar logout');
        assert_true(str_contains($src, 'method="post" action="/logout.php"') && str_contains($src, 'name="csrf"'), $file . ' heeft een formulier met token');
    }
});

test('P3-10: wachtwoord vergeten laat een nog geldige welkomstlink werken', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 0;
    $now = time();
    $uid = geo_user_with_password($pdo, 'welkom@firma.nl', 'oud-wachtwoord-123');
    $pdo->prepare('UPDATE scans SET password_token_hash = :h, password_token_expires_at = :x WHERE user_id = :u')
        ->execute(['h' => ai_scan_hash_token('welkomstlink'), 'x' => $now + 80000, 'u' => $uid]);
    @unlink($mailLog);

    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'welkom@firma.nl', '4.4.4.4', $now));
    preg_match('#\?t=([A-Za-z0-9_-]+)#', (string) geo_mails($mailLog)[0]['body'], $m);

    assert_same('ok', ai_scan_password_token_state($pdo, 'welkomstlink', $now + 5), 'welkomstlink blijft geldig');
    assert_same('ok', ai_scan_password_token_state($pdo, $m[1], $now + 5), 'resetlink werkt ook');
    assert_true(ai_scan_set_password($pdo, $m[1], 'nieuw-wachtwoord-456', $now + 10)['ok']);
    assert_same('invalid_token', ai_scan_password_token_state($pdo, 'welkomstlink', $now + 20), 'nieuw wachtwoord ruimt alle links op');
});

test('P3-12: e-mailwijziging weigert wegwerpadressen en domeinen zonder mail', static function (): void {
    [$config, $pdo, $free, $paid] = app_writes_seed();
    $config['account_email_change_seconds'] = 0;
    $config['base_url'] = 'https://jpwebcreation.nl';

    $disposable = ai_scan_request_email_change($pdo, $config, $paid, 'iemand@mailinator.com', fake_pw('test-pw', 12));
    assert_true(empty($disposable['ok']));
    assert_same(AI_SCAN_EMAIL_DISPOSABLE, $disposable['error'] ?? '');

    ai_scan_set_dns_checker(static fn (string $host, string $type): bool => false);
    $noMail = ai_scan_request_email_change($pdo, $config, $paid, 'iemand@bestaat-niet-voorbeeld.nl', fake_pw('test-pw', 12));
    ai_scan_set_dns_checker(static fn (string $host, string $type): bool => true);
    assert_true(empty($noMail['ok']));
    assert_same(AI_SCAN_EMAIL_NO_MAILBOX, $noMail['error'] ?? '');
    assert_same(null, $pdo->query('SELECT pending_email FROM users WHERE id = ' . (int) $paid['id'])->fetchColumn() ?: null, 'niets opgeslagen');
});

test('P3-N1: bevestigknop alleen bij een geldige, niet-verlopen link', static function (): void {
    [$config, $pdo] = boot_test_db();
    $add = static function (string $id, string $token, string $status, int $exp) use ($pdo): void {
        $pdo->prepare(
            "INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, confirm_token_hash, confirm_expires_at)
             VALUES(:id, :e, :eh, 'https://firma.nl/', 'firma.nl', 'dh', :s, :now, :h, :exp)"
        )->execute(['id' => $id, 'e' => $id . '@firma.nl', 'eh' => ai_scan_email_hash($id . '@firma.nl'), 's' => $status, 'now' => time(), 'h' => ai_scan_hash_token($token), 'exp' => $exp]);
    };
    $add('n1ok', 'tok-ok', 'onbevestigd', time() + 3600);
    $add('n1old', 'tok-old', 'onbevestigd', time() - 10);
    $add('n1used', 'tok-used', 'bevestigd', time() + 3600);

    $ok = route_guard_run($config, 'ai-scan.php', [], 'GET', [], ['confirm' => 'tok-ok']);
    assert_true(str_contains($ok['body'], 'Bevestig mijn scan'), 'geldige link toont knop');

    foreach (['tok-old' => 'verlopen', 'tok-used' => 'al bevestigd', 'onbekend' => 'ongeldig'] as $t => $msg) {
        $r = route_guard_run($config, 'ai-scan.php', [], 'GET', [], ['confirm' => $t]);
        assert_true(!str_contains($r['body'], 'Bevestig mijn scan') && !str_contains($r['body'], 'name="confirm"'), "geen knop bij '$t'");
        assert_true(str_contains($r['body'], $msg), "melding bij '$t'");
        assert_true(str_contains($r['body'], 'href="/login/') || str_contains($r['body'], 'href="/ai-vindbaarheid/"'), "vervolgstap bij '$t'");
    }
});

test('P3-N2: wachtwoordpagina zonder link vraagt niet of je al een account hebt', static function (): void {
    [$config] = boot_test_db();
    $html = tr1256_render($config, 'ai-scan/wachtwoord/index.php', []);
    assert_true(!str_contains($html, 'Heb je al een account?'), 'geen account-vraag zonder link');
    assert_true(str_contains($html, 'href="/login/vergeten/"') && str_contains($html, 'href="/login/"'), 'verwijst naar vergeten en inloggen');
});

test('P3-R1: bevestigingspagina met foutmelding heeft opmaak voor fout en links', static function (): void {
    [$config, $pdo] = boot_test_db();
    $r = route_guard_run($config, 'ai-scan.php', [], 'GET', [], ['confirm' => 'onbekend-r1']);
    assert_true(str_contains($r['body'], 'class="error"') && str_contains($r['body'], '.ai-auth .error {'), 'foutmelding heeft eigen stijl');
    assert_true(str_contains($r['body'], '.ai-auth .auth-primary {') && str_contains($r['body'], 'class="auth-links"') && str_contains($r['body'], 'class="auth-primary" href="/ai-vindbaarheid/"'), 'actie is een herkenbare knop');
    assert_true(str_contains($r['body'], '.ai-auth p a {') && str_contains($r['body'], 'text-decoration: underline'), 'links in tekst zijn herkenbaar');
    assert_true(str_contains($r['body'], 'class="auth-back" href="/"'), 'terug naar de site');
});
