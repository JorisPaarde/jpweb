<?php

declare(strict_types=1);

/**
 * /wie-ik-ben/ achter pincode: geen inhoud of foto zonder sessie, lock na foute pogingen,
 * allowlist zonder path traversal en de juiste headers.
 * De pincode wordt per run willekeurig gemaakt; er staat geen pincode of hash in de repo.
 * Included from tests/run.php.
 */

require_once dirname(__DIR__) . '/wie-ik-ben/_gate.php';

/** @return array{dir: string, pin: string} */
function wib_test_fixture(): array
{
    $dir = sys_get_temp_dir() . '/wib-test-' . bin2hex(random_bytes(6));
    mkdir($dir . '/flappen/dag-1-3', 0700, true);
    mkdir($dir . '/flappen/dag-7', 0700, true);
    mkdir($dir . '/flappen/bloem', 0700, true);
    $pin = (string) random_int(100000, 999999);
    file_put_contents($dir . '/flappen-config.php', '<?php return ' . var_export(['pin_hash' => password_hash($pin, PASSWORD_BCRYPT, ['cost' => 4])], true) . ';');
    $webp = 'RIFF' . pack('V', 4) . 'WEBP';
    file_put_contents($dir . '/flappen/dag-1-3/01-een.webp', $webp);
    file_put_contents($dir . '/flappen/dag-1-3/02-twee.webp', $webp);
    file_put_contents($dir . '/flappen/dag-7/28-drie.webp', $webp);
    file_put_contents($dir . '/flappen/dag-7/niet-in-manifest.webp', $webp);
    file_put_contents($dir . '/flappen/bloem/bloem-1200.jpg', "\xff\xd8\xff");
    file_put_contents($dir . '/flappen/dag-1-3/manifest.json', json_encode([
        ['file' => '01-een.webp', 'caption' => 'Een', 'width' => 10, 'height' => 20],
        ['file' => '02-twee.webp', 'caption' => 'Twee <b>', 'width' => 10, 'height' => 20],
        ['file' => '../../flappen-config.php', 'caption' => 'x', 'width' => 1, 'height' => 1],
    ]));
    file_put_contents($dir . '/flappen/dag-7/manifest.json', json_encode([
        ['file' => '28-drie.webp', 'caption' => 'Drie', 'width' => 10, 'height' => 20],
    ]));

    return ['dir' => $dir, 'pin' => $pin];
}

function wib_test_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function wib_wrong_pin(string $pin): string
{
    // Altijd anders dan de goede (willekeurige) pincode, nooit een vaste waarde.
    return str_pad((string) (((int) $pin + 1) % 1000000), 6, '0', STR_PAD_LEFT);
}

test('wie-ik-ben: geen index.html, geen bestanden behalve PHP, geen foto in assets', static function (): void {
    $root = dirname(__DIR__);
    assert_true(!is_file($root . '/wie-ik-ben/index.html'), 'index.html zou de pincode omzeilen');
    assert_true(!is_dir($root . '/assets/wie-ik-ben'), 'assets/wie-ik-ben moet weg');
    foreach (scandir($root . '/wie-ik-ben') as $f) {
        if ($f !== '.' && $f !== '..') {
            assert_same('php', pathinfo($f, PATHINFO_EXTENSION), 'wie-ik-ben/' . $f);
        }
    }
});

test('wie-ik-ben: pincode alleen met password_verify, lock na 5 fouten, ook goede pin tijdens lock', static function (): void {
    $fx = wib_test_fixture();
    $GLOBALS['wib_private_dir_override'] = $fx['dir'];
    try {
        $ip = '203.0.113.7';
        $now = 1_800_000_000;
        $wrong = wib_wrong_pin($fx['pin']);
        for ($i = 1; $i <= 4; $i++) {
            assert_same('wrong', wib_check_pin($ip, $wrong, $now + $i)['status'], 'poging ' . $i);
        }
        $fifth = wib_check_pin($ip, $wrong, $now + 5);
        assert_same('locked', $fifth['status'], 'vijfde fout');
        assert_true($fifth['retry_after'] > 0 && $fifth['retry_after'] <= WIB_IP_LOCK, 'retry_after');
        assert_same('locked', wib_check_pin($ip, $fx['pin'], $now + 6)['status'], 'goede pin tijdens lock');
        assert_same('ok', wib_check_pin('198.51.100.9', $fx['pin'], $now + 6)['status'], 'ander IP mag wel');
        assert_same('ok', wib_check_pin($ip, $fx['pin'], $now + 5 + WIB_IP_LOCK + 1)['status'], 'na de lock weer toegang');
        foreach (['', '12a4', ['x'], null, str_repeat('1', 40)] as $bad) {
            assert_same('wrong', wib_check_pin('192.0.2.' . random_int(1, 200), $bad, $now)['status'], 'ongeldige invoer');
        }
        $files = glob($fx['dir'] . '/flappen/ratelimit/*.json') ?: [];
        foreach ($files as $f) {
            assert_true(!str_contains((string) file_get_contents($f), '203.0.113.7') && !str_contains(basename($f), '203'), 'geen ruw IP');
            assert_same('0600', substr(sprintf('%o', fileperms($f)), -4), 'ratelimit-bestand 600');
        }
    } finally {
        unset($GLOBALS['wib_private_dir_override']);
        wib_test_rmdir($fx['dir']);
    }
});

test('wie-ik-ben: globale limiet blokkeert iedereen', static function (): void {
    $fx = wib_test_fixture();
    $GLOBALS['wib_private_dir_override'] = $fx['dir'];
    try {
        $now = 1_800_000_000;
        $wrong = wib_wrong_pin($fx['pin']);
        for ($i = 0; $i < WIB_GLOBAL_MAX_FAILS; $i++) {
            wib_check_pin('10.0.' . intdiv($i, 4) . '.' . ($i % 4), $wrong, $now + $i);
        }
        assert_same('locked', wib_check_pin('10.9.9.9', $fx['pin'], $now + 60)['status'], 'globale lock');
        assert_same('ok', wib_check_pin('10.9.9.9', $fx['pin'], $now + WIB_GLOBAL_WINDOW + 60)['status'], 'na een uur weer open');
    } finally {
        unset($GLOBALS['wib_private_dir_override']);
        wib_test_rmdir($fx['dir']);
    }
});

test('wie-ik-ben: zonder config of rate-limitmap geen toegang (fail closed)', static function (): void {
    $GLOBALS['wib_private_dir_override'] = sys_get_temp_dir() . '/wib-bestaat-niet-' . bin2hex(random_bytes(4));
    try {
        assert_same('unavailable', wib_check_pin('192.0.2.1', '123456')['status']);
    } finally {
        wib_test_rmdir($GLOBALS['wib_private_dir_override']);
        unset($GLOBALS['wib_private_dir_override']);
    }
});

test('wie-ik-ben: foto-allowlist uit manifest, geen path traversal', static function (): void {
    $fx = wib_test_fixture();
    $GLOBALS['wib_private_dir_override'] = $fx['dir'];
    try {
        assert_same(['01-een.webp', '02-twee.webp'], array_column(wib_manifest('dag-1-3'), 'file'), 'manifest filtert onveilige namen');
        assert_true(wib_resolve_photo('dag-1-3', '01-een.webp') !== null, 'geldige foto');
        assert_same('image/webp', wib_resolve_photo('dag-7', '28-drie.webp')['type'] ?? null);
        assert_same('image/jpeg', wib_resolve_photo('bloem', 'bloem-1200.jpg')['type'] ?? null);
        foreach ([
            ['dag-7', 'niet-in-manifest.webp'], ['dag-7', '01-een.webp'], ['dag-1-3', '../dag-7/28-drie.webp'],
            ['dag-1-3', '../../flappen-config.php'], ['dag-1-3', 'manifest.json'], ['../flappen', 'flappen-config.php'],
            ['bloem', '../flappen-config.php'], ['bloem', 'bloem-1200.webp'], ['', ''], [null, null], [['dag-7'], '28-drie.webp'],
            ['dag-7', "28-drie.webp\0.jpg"], ['DAG-7', '28-drie.webp'],
        ] as [$set, $file]) {
            assert_same(null, wib_resolve_photo($set, $file), 'moet 404: ' . json_encode([$set, $file]));
        }
    } finally {
        unset($GLOBALS['wib_private_dir_override']);
        wib_test_rmdir($fx['dir']);
    }
});

test('wie-ik-ben: sessie verloopt na 12 uur', static function (): void {
    $now = 1_800_000_000;
    assert_true(wib_session_is_authed(['wib_auth' => true, 'wib_auth_at' => $now - 60], $now));
    assert_true(!wib_session_is_authed(['wib_auth' => true, 'wib_auth_at' => $now - WIB_SESSION_TTL], $now));
    assert_true(!wib_session_is_authed(['wib_auth' => 1, 'wib_auth_at' => $now], $now));
    assert_true(!wib_session_is_authed([], $now));
});

test('wie-ik-ben: HTTP — formulier zonder inhoud, 404 op foto, headers, lock 429, goede pin toont galerij', static function (): void {
    $fx = wib_test_fixture();
    $root = dirname(__DIR__);
    $router = $fx['dir'] . '/router.php';
    file_put_contents($router, '<?php
$GLOBALS["wib_private_dir_override"] = ' . var_export($fx['dir'], true) . ';
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$map = ["/wie-ik-ben/" => "index.php", "/wie-ik-ben/foto.php" => "foto.php"];
if (!isset($map[$path])) { http_response_code(404); return true; }
chdir(' . var_export($root . '/wie-ik-ben', true) . ');
require ' . var_export($root . '/wie-ik-ben/', true) . ' . $map[$path];
return true;
');
    $port = random_int(20000, 40000);
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
    $base = 'http://127.0.0.1:' . $port;
    /** @return array{0: int, 1: array<string, string>, 2: string} */
    $req = static function (string $path, string $method = 'GET', array $post = [], string $cookie = '') use ($base): array {
        $headers = $cookie !== '' ? "Cookie: $cookie\r\n" : '';
        $content = '';
        if ($post !== []) {
            $content = http_build_query($post);
            $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => $content, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
        $body = @file_get_contents($base . $path, false, $ctx);
        $status = 0;
        $h = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
                $status = (int) $m[1];
                $h = [];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $h[strtolower(trim($k))] = ($h[strtolower(trim($k))] ?? '') === '' ? trim($v) : $h[strtolower(trim($k))] . "\n" . trim($v);
            }
        }

        return [$status, $h, (string) $body];
    };
    $cookieFrom = static function (array $h): string {
        preg_match('/(' . WIB_SESSION_NAME . '=[^;]+)/', $h['set-cookie'] ?? '', $m);

        return $m[1] ?? '';
    };
    try {
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port) !== false) {
                break;
            }
            usleep(100000);
        }
        [$st, $h, $body] = $req('/wie-ik-ben/');
        assert_same(200, $st, 'poort');
        assert_same(WIB_ROBOTS, $h['x-robots-tag'] ?? null, 'X-Robots-Tag');
        assert_true(str_contains($h['cache-control'] ?? '', 'private') && str_contains($h['cache-control'] ?? '', 'no-store'), 'Cache-Control');
        assert_true(str_contains($body, 'type="password"') && str_contains($body, 'inputmode="numeric"') && str_contains($body, 'name="csrf"'), 'formulier');
        foreach (['Wie ik ben en hoe ik leef', 'Bovenaan', 'Flappen dag', 'foto.php', 'og:', 'twitter:', 'canonical'] as $leak) {
            assert_true(!str_contains($body, $leak), 'lek in poortpagina: ' . $leak);
        }
        $cookieHeader = $h['set-cookie'] ?? '';
        foreach (['path=/wie-ik-ben/', 'secure', 'HttpOnly', 'SameSite=Strict'] as $flag) {
            assert_true(stripos($cookieHeader, $flag) !== false, 'cookie ' . $flag);
        }
        $cookie = $cookieFrom($h);
        preg_match('/name="csrf" value="([0-9a-f]{64})"/', $body, $m);
        $csrf = $m[1] ?? '';

        [$st, $h] = $req('/wie-ik-ben/foto.php?set=dag-7&f=28-drie.webp');
        assert_same(404, $st, 'foto zonder sessie');
        assert_same(WIB_ROBOTS, $h['x-robots-tag'] ?? null, 'X-Robots-Tag op foto-404');
        [$st] = $req('/wie-ik-ben/foto.php?set=dag-7&f=28-drie.webp', 'GET', [], $cookie);
        assert_same(404, $st, 'foto met niet-ingelogde sessie');

        // CSRF ontbreekt: geen pincodecontrole
        [$st] = $req('/wie-ik-ben/', 'POST', ['pin' => $fx['pin']], $cookie);
        assert_same(400, $st, 'zonder csrf');

        // Goede pin: redirect + nieuwe sessie-id + inhoud
        [$st, $h] = $req('/wie-ik-ben/', 'POST', ['pin' => $fx['pin'], 'csrf' => $csrf], $cookie);
        assert_same(303, $st, 'goede pin');
        $authCookie = $cookieFrom($h);
        assert_true($authCookie !== '' && $authCookie !== $cookie, 'session_regenerate_id');
        [$st, $h, $body] = $req('/wie-ik-ben/', 'GET', [], $authCookie);
        assert_same(200, $st);
        assert_true(str_contains($body, 'Flappen dag 1-3') && str_contains($body, 'Flappen dag 7'), 'galerijen');
        assert_same(3, substr_count($body, 'class="wib-flap"'), 'drie foto\'s');
        assert_true(str_contains($body, 'Twee &lt;b&gt;') && !str_contains($body, 'Twee <b>'), 'bijschrift ge-escaped');
        assert_true(str_contains($body, 'loading="lazy"') && str_contains($body, 'width="10" height="20"'), 'lazy + afmetingen');
        assert_true(!preg_match('~og:|twitter:|rel="canonical"|name="description"~', $body), 'geen social/canonical tags');
        [$st, $h, $img] = $req('/wie-ik-ben/foto.php?set=dag-7&f=28-drie.webp', 'GET', [], $authCookie);
        assert_same(200, $st, 'foto met sessie');
        assert_same('image/webp', $h['content-type'] ?? null);
        assert_same(WIB_ROBOTS, $h['x-robots-tag'] ?? null);
        assert_true(str_contains($h['cache-control'] ?? '', 'no-store') && !str_contains($h['cache-control'] ?? '', 'public'), 'no-store');
        assert_same('nosniff', $h['x-content-type-options'] ?? null);
        assert_same('RIFF', substr($img, 0, 4));
        foreach (['set=dag-7&f=niet-in-manifest.webp', 'set=dag-1-3&f=..%2F..%2Fflappen-config.php', 'set=..&f=flappen-config.php', 'set=dag-1-3&f=manifest.json'] as $q) {
            [$st] = $req('/wie-ik-ben/foto.php?' . $q, 'GET', [], $authCookie);
            assert_same(404, $st, $q);
        }

        // Uitloggen: daarna geen foto meer met dezelfde cookie
        preg_match('/name="csrf" value="([0-9a-f]{64})"/', $body, $m);
        [$st, $h] = $req('/wie-ik-ben/', 'POST', ['action' => 'logout', 'csrf' => $m[1] ?? ''], $authCookie);
        assert_same(303, $st, 'uitloggen');
        [$st] = $req('/wie-ik-ben/foto.php?set=dag-7&f=28-drie.webp', 'GET', [], $authCookie);
        assert_same(404, $st, 'oude cookie na uitloggen');

        // 5x fout: 429 met Retry-After
        [$st, $h, $body] = $req('/wie-ik-ben/');
        $c2 = $cookieFrom($h);
        preg_match('/name="csrf" value="([0-9a-f]{64})"/', $body, $m);
        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            [$st, $h] = $req('/wie-ik-ben/', 'POST', ['pin' => wib_wrong_pin($fx['pin']), 'csrf' => $m[1] ?? ''], $c2);
            $statuses[] = $st;
        }
        assert_same([200, 200, 200, 200, 429], $statuses, 'lock na 5 fouten');
        assert_true((int) ($h['retry-after'] ?? 0) > 0, 'Retry-After');
        [$st] = $req('/wie-ik-ben/', 'POST', ['pin' => $fx['pin'], 'csrf' => $m[1] ?? ''], $c2);
        assert_same(429, $st, 'goede pin tijdens lock');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        wib_test_rmdir($fx['dir']);
    }
});

test('wie-ik-ben: .htaccess geeft 404 op includes en oude asset-paden, pagina en foto.php blijven bereikbaar', static function (): void {
    if (!function_exists('htaccess_404_blocks')) {
        require_once __DIR__ . '/htaccess_404_test.php';
    }
    foreach (['/wie-ik-ben/_gate.php', '/wie-ik-ben/index.html', '/wie-ik-ben/dag-7/28-vat-bron.webp', '/wie-ik-ben/manifest.json', '/assets/wie-ik-ben/', '/assets/wie-ik-ben/bloem-1200.jpg'] as $path) {
        assert_true(htaccess_404_blocks($path), $path . ' must 404');
    }
    foreach (['/wie-ik-ben/', '/wie-ik-ben/foto.php', '/wie-ik-ben/index.php'] as $path) {
        assert_true(!htaccess_404_blocks($path), $path . ' must stay reachable');
    }
});
