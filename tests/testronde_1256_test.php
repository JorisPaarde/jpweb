<?php

declare(strict_types=1);

/**
 * GEO-testronde 14 okt 2026 (12:56): P2/P3-punten.
 */

require_once dirname(__DIR__) . '/lib/questions.php';

/**
 * Rendert een publieke pagina met de testconfig.
 *
 * @param array<string, mixed> $config
 * @param array<string, string> $get
 */
function tr1256_render(array $config, string $script, array $get = [], string $method = 'GET'): string
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = $get;
    $_POST = [];
    $cfgPath = sys_get_temp_dir() . '/jpweb-tr1256-config-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($cfgPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    putenv('JPWEB_AI_SCAN_CONFIG=' . $cfgPath);
    putenv('JPWEB_AI_SCAN_ALLOW_INSECURE=1');
    ob_start();
    try {
        include dirname(__DIR__) . '/' . $script;
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    } finally {
        @unlink($cfgPath);
    }

    return (string) ob_get_clean();
}

function tr1256_scan_with_password_token(PDO $pdo, string $token, int $expiresAt, bool $withUser = true): void
{
    $suffix = bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO users(email, email_hash, created_at, free_scan_used) VALUES(:e, :h, 1, 1)")
        ->execute(['e' => 'tr-' . $suffix . '@firma.nl', 'h' => 'tr-' . $suffix]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO scans(public_id, user_id, email, email_hash, website_url, domain, domain_hash, status, created_at, password_token_hash, password_token_expires_at)
         VALUES(:p, :u, 'tr@firma.nl', 'eh', 'https://firma.nl/', 'firma.nl', 'dh', 'klaar', 1, :h, :e)"
    )->execute(['p' => bin2hex(random_bytes(8)), 'u' => $uid, 'h' => ai_scan_hash_token($token), 'e' => $expiresAt]);
    if (!$withUser) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $uid]);
    }
}

test('N4/P3-9: wachtwoordpagina controleert de link bij openen en biedt een herstelroute', static function (): void {
    [$config, $pdo] = boot_test_db();
    tr1256_scan_with_password_token($pdo, 'geldig-token', time() + 3600);
    tr1256_scan_with_password_token($pdo, 'oud-token', time() - 10);

    $ok = tr1256_render($config, 'ai-scan/wachtwoord/index.php', ['t' => 'geldig-token']);
    assert_true(str_contains($ok, 'name="password"'), 'geldige link toont formulier');
    assert_true(!str_contains($ok, '/login/vergeten/'), 'geen herstelroute bij geldige link');

    foreach (['abc' => 'ongeldig of al gebruikt', 'oud-token' => 'verlopen', '' => 'Deze link is ongeldig.'] as $t => $msg) {
        $html = tr1256_render($config, 'ai-scan/wachtwoord/index.php', $t === '' ? [] : ['t' => $t]);
        assert_true(!str_contains($html, 'name="password"'), "geen formulier bij link '$t'");
        assert_true(str_contains($html, $msg), "melding bij link '$t'");
        assert_true(str_contains($html, 'href="/login/vergeten/"'), "link naar wachtwoord vergeten bij '$t'");
        assert_true(str_contains($html, 'href="/login/"'), "inloglink bij '$t'");
    }
});

test('N4: link zonder account verwijst naar de scan, niet naar wachtwoord vergeten', static function (): void {
    [$config, $pdo] = boot_test_db();
    tr1256_scan_with_password_token($pdo, 'weeskind', time() + 3600, false);
    assert_same('no_account', ai_scan_password_token_state($pdo, 'weeskind'));
    $html = tr1256_render($config, 'ai-scan/wachtwoord/index.php', ['t' => 'weeskind']);
    assert_true(str_contains($html, 'Bij deze link hoort geen account meer.'));
    assert_true(!str_contains($html, 'name="password"'));
});

test('N5: wachtwoord kiezen, herstellen en wijzigen gebruiken hetzelfde minimum (12)', static function (): void {
    assert_same(12, AI_SCAN_PASSWORD_MIN_LENGTH);
    [$config, $pdo] = boot_test_db();
    tr1256_scan_with_password_token($pdo, 'kort-token', time() + 3600);
    $short = ai_scan_set_password($pdo, 'kort-token', str_repeat('a', 11));
    assert_true(!$short['ok'], '11 tekens geweigerd bij link');
    assert_same('ok', ai_scan_password_token_state($pdo, 'kort-token'), 'token niet verbruikt door te kort wachtwoord');
    assert_true(ai_scan_set_password($pdo, 'kort-token', str_repeat('a', 12))['ok'], '12 tekens toegestaan');

    $html = tr1256_render($config, 'ai-scan/wachtwoord/index.php', ['t' => 'x']);
    $src = (string) file_get_contents(dirname(__DIR__) . '/ai-scan/wachtwoord/index.php');
    assert_true(!str_contains($src, 'minlength="10"') && !str_contains($src, '< 10'), 'geen 10-tekensregel meer');
    assert_true(str_contains(AI_SCAN_PASSWORD_TOO_SHORT, '12 tekens'));
    $account = (string) file_get_contents(dirname(__DIR__) . '/app/account.php');
    assert_true(str_contains($account, 'Minimaal 12 tekens'));
});

test('P3-11: geen OAuth in klanttekst van de Search Console-kaart', static function (): void {
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/gsc/settings-card.php');
    $visible = preg_replace('/<\?php.*?\?>/s', '', $src);
    assert_true(!str_contains((string) $visible, 'OAuth'), 'OAuth zichtbaar in kaarttekst');
});
