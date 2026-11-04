<?php

declare(strict_types=1);

/**
 * Operator-box GSC OAuth parking + pickup (no token exchange on the server).
 */

require_once dirname(__DIR__) . '/lib/gsc_box.php';

/**
 * @return array{0: string, 1: string} [dir, state]
 */
function gsc_box_test_env(): array
{
    $dir = sys_get_temp_dir() . '/jpweb-gsc-box-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    ai_scan_gsc_box_storage_dir($dir);
    $state = 'box-' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(random_bytes(48)));
    assert_true(ai_scan_gsc_box_state_valid($state), 'generated state valid');

    return [$dir, $state];
}

function gsc_box_test_cleanup(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    ai_scan_gsc_box_storage_dir(null, true);
}

/**
 * @param array<string, string> $get
 * @param array<string, string> $post
 * @return array{status: int, location: string, body: string, headers: string}
 */
function gsc_box_route(string $route, string $method, array $get = [], array $post = [], ?string $storage = null): array
{
    $dir = sys_get_temp_dir() . '/jpweb-gsc-box-route-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $saveDir = $dir . '/sessions';
    mkdir($saveDir, 0700, true);
    $config = gsc_test_config($dir . '/test.sqlite');
    file_put_contents($dir . '/config.php', '<?php return ' . var_export($config, true) . ';');

    $headerFile = $dir . '/headers.txt';
    $code = 'ini_set("session.save_path", ' . var_export($saveDir, true) . ');'
        . '$_SERVER["HTTP_HOST"] = "jpwebcreation.nl";'
        . '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';'
        . '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
        . '$_SERVER["CONTENT_LENGTH"] = ' . var_export((string) strlen(http_build_query($post)), true) . ';'
        . '$_POST = ' . var_export($post, true) . ';'
        . '$_GET = ' . var_export($get, true) . ';'
        . 'ob_start();'
        . 'register_shutdown_function(static function () { '
        . '  $body = ob_get_contents(); '
        . '  echo "\n@@STATUS=" . http_response_code(); '
        . '  echo "\n@@BODY_BEGIN\n" . $body . "\n@@BODY_END"; '
        . '});'
        . 'require ' . var_export(dirname(__DIR__) . '/' . $route, true) . ';';

    $env = 'JPWEB_AI_SCAN_TEST_CATCH_REDIRECT=1 JPWEB_AI_SCAN_CONFIG=' . escapeshellarg($dir . '/config.php');
    if ($storage !== null) {
        $env .= ' JPWEB_GSC_BOX_STORAGE=' . escapeshellarg($storage);
    }
    $cmd = $env . ' php -d display_errors=1 -d html_errors=0 -d error_reporting=-1 -r ' . escapeshellarg($code) . ' 2>&1';
    $out = (string) shell_exec($cmd);

    foreach (glob($saveDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @unlink($dir . '/config.php');
    @unlink($dir . '/test.sqlite');
    @rmdir($saveDir);
    @rmdir($dir);

    $redirected = preg_match('/RuntimeException: REDIRECT (\S*)/', $out, $r) === 1;
    preg_match('/@@STATUS=(\d+)/', $out, $m);
    $body = '';
    if (preg_match('/@@BODY_BEGIN\n(.*)\n@@BODY_END/s', $out, $b) === 1) {
        $body = $b[1];
    }

    return [
        'status' => $redirected ? 303 : (int) ($m[1] ?? 0),
        'location' => $redirected ? $r[1] : '',
        'body' => $body,
        'headers' => $out,
    ];
}

test('gsc box state regex accepts long box- tokens and rejects short/other', static function (): void {
    assert_true(ai_scan_gsc_box_state_valid('box-' . str_repeat('a', 43)));
    assert_true(ai_scan_gsc_box_state_valid('box-' . str_repeat('A', 128)));
    assert_true(ai_scan_gsc_box_state_valid('box-' . str_repeat('x_-', 20) . 'abcd')); // 43+ with url-safe
    assert_true(!ai_scan_gsc_box_state_valid('box-' . str_repeat('a', 42)));
    assert_true(!ai_scan_gsc_box_state_valid('box-' . str_repeat('a', 129)));
    assert_true(!ai_scan_gsc_box_state_valid('box-' . str_repeat('a', 40) . '!'));
    assert_true(!ai_scan_gsc_box_state_valid(str_repeat('s', 32)));
    assert_true(!ai_scan_gsc_box_state_valid('dashboard-state'));
});

test('gsc box store and one-time pickup of code', static function (): void {
    [$dir, $state] = gsc_box_test_env();
    assert_true(ai_scan_gsc_box_store($state, ['code' => 'auth-code-from-google']));
    $path = $dir . '/' . hash('sha256', $state);
    assert_true(is_file($path));
    $perms = fileperms($path) & 0777;
    assert_same(0600, $perms);

    $raw = (string) file_get_contents($path);
    assert_true(!str_contains($raw, $state), 'state not stored in file body');
    $first = ai_scan_gsc_box_pickup($state);
    assert_true(is_array($first));
    assert_same('auth-code-from-google', $first['code'] ?? null);
    assert_true(!is_file($path), 'file deleted after pickup');
    assert_true(ai_scan_gsc_box_pickup($state) === null, 'second pickup misses');

    gsc_box_test_cleanup($dir);
});

test('gsc box stores error payload for denied consent', static function (): void {
    [$dir, $state] = gsc_box_test_env();
    assert_true(ai_scan_gsc_box_store($state, ['error' => 'access_denied']));
    $got = ai_scan_gsc_box_pickup($state);
    assert_same('access_denied', $got['error'] ?? null);
    assert_true(!isset($got['code']));
    gsc_box_test_cleanup($dir);
});

test('gsc box expired files are garbage-collected and not returned', static function (): void {
    [$dir, $state] = gsc_box_test_env();
    assert_true(ai_scan_gsc_box_store($state, ['code' => 'stale-code']));
    $path = $dir . '/' . hash('sha256', $state);
    $stale = json_encode(['code' => 'stale-code', 'created_at' => time() - AI_SCAN_GSC_BOX_TTL - 5]);
    file_put_contents($path, $stale);
    touch($path, time() - AI_SCAN_GSC_BOX_TTL - 5);

    assert_true(ai_scan_gsc_box_pickup($state) === null);
    assert_true(!is_file($path));

    $other = 'box-' . str_repeat('b', 43);
    assert_true(ai_scan_gsc_box_store($other, ['code' => 'also-stale']));
    $otherPath = $dir . '/' . hash('sha256', $other);
    file_put_contents($otherPath, json_encode(['code' => 'also-stale', 'created_at' => time() - 9999]));
    touch($otherPath, time() - 9999);
    ai_scan_gsc_box_gc();
    assert_true(!is_file($otherPath));

    gsc_box_test_cleanup($dir);
});

test('gsc box callback parks code and returns 200 HTML without redirect or code leak', static function (): void {
    $storage = sys_get_temp_dir() . '/jpweb-gsc-box-cb-' . bin2hex(random_bytes(4));
    mkdir($storage, 0700, true);
    $state = 'box-' . str_repeat('c', 48);
    $res = gsc_box_route(
        'app/gsc/callback.php',
        'GET',
        ['state' => $state, 'code' => 'parked-auth-code'],
        [],
        $storage
    );
    assert_same(200, $res['status'], 'box callback status: ' . substr($res['headers'], 0, 400));
    assert_same('', $res['location'], 'no redirect');
    assert_true(str_contains($res['body'], 'Gelukt, je kunt dit venster sluiten.'));
    assert_true(!str_contains($res['body'], 'parked-auth-code'));
    assert_true(!str_contains($res['body'], $state));

    ai_scan_gsc_box_storage_dir($storage);
    $got = ai_scan_gsc_box_pickup($state);
    assert_same('parked-auth-code', $got['code'] ?? null);
    gsc_box_test_cleanup($storage);
});

test('gsc box callback with error parks error and still shows success HTML', static function (): void {
    $storage = sys_get_temp_dir() . '/jpweb-gsc-box-err-' . bin2hex(random_bytes(4));
    mkdir($storage, 0700, true);
    $state = 'box-' . str_repeat('e', 48);
    $res = gsc_box_route(
        'app/gsc/callback.php',
        'GET',
        ['state' => $state, 'error' => 'access_denied'],
        [],
        $storage
    );
    assert_same(200, $res['status']);
    assert_same('', $res['location']);
    assert_true(str_contains($res['body'], 'Gelukt, je kunt dit venster sluiten.'));
    assert_true(!str_contains($res['body'], 'access_denied'));

    ai_scan_gsc_box_storage_dir($storage);
    $got = ai_scan_gsc_box_pickup($state);
    assert_same('access_denied', $got['error'] ?? null);
    gsc_box_test_cleanup($storage);
});

test('gsc dashboard callback path still 303s for non-box state (unchanged)', static function (): void {
    [$config, $pdo, $uid] = route_guard_user();
    $state = str_repeat('s', 32);
    $session = [
        'user_id' => $uid,
        'password_version' => 1,
        'gsc_oauth_next' => '/dashboard/',
        'gsc_oauth_state' => ['state' => $state, 'user_id' => $uid, 'expires_at' => time() + 600, 'created_at' => time()],
    ];
    $pdo->prepare('UPDATE users SET password_version = 2 WHERE id = :id')->execute(['id' => $uid]);
    $stale = route_guard_run($config, 'app/gsc/callback.php', $session, 'GET', [], ['state' => $state, 'code' => 'x']);
    assert_true(str_starts_with($stale['location'], '/login/'), 'dashboard flow still redirects: ' . $stale['location']);
    assert_same(303, $stale['status']);
});

test('gsc box-pickup returns code once then 404; rejects GET and bad state', static function (): void {
    $storage = sys_get_temp_dir() . '/jpweb-gsc-box-pu-' . bin2hex(random_bytes(4));
    mkdir($storage, 0700, true);
    $state = 'box-' . str_repeat('p', 48);
    ai_scan_gsc_box_storage_dir($storage);
    assert_true(ai_scan_gsc_box_store($state, ['code' => 'pickup-me']));

    $get = gsc_box_route('app/gsc/box-pickup.php', 'GET', [], [], $storage);
    assert_same(405, $get['status']);

    $bad = gsc_box_route('app/gsc/box-pickup.php', 'POST', [], ['state' => 'not-a-box-state'], $storage);
    assert_same(404, $bad['status']);
    assert_true(str_contains($bad['body'], '"error":"not_found"'));

    $ok = gsc_box_route('app/gsc/box-pickup.php', 'POST', [], ['state' => $state], $storage);
    assert_same(200, $ok['status'], substr($ok['headers'], 0, 500));
    $json = json_decode($ok['body'], true);
    assert_true(is_array($json));
    assert_same('pickup-me', $json['code'] ?? null);

    $again = gsc_box_route('app/gsc/box-pickup.php', 'POST', [], ['state' => $state], $storage);
    assert_same(404, $again['status']);

    gsc_box_test_cleanup($storage);
});

test('gsc box storage: function override and deny-all .htaccess; source prefers private', static function (): void {
    $base = sys_get_temp_dir() . '/jpweb-gsc-box-priv-' . bin2hex(random_bytes(4));
    mkdir($base, 0700, true);
    $override = $base . '/override-box';
    ai_scan_gsc_box_storage_dir($override);
    assert_same($override, ai_scan_gsc_box_storage_dir());
    assert_true(is_dir($override));
    assert_true(is_file($override . '/.htaccess'));
    $ht = (string) file_get_contents($override . '/.htaccess');
    assert_true(str_contains($ht, 'Require all denied') || str_contains($ht, 'Deny from all'));

    $src = (string) file_get_contents(dirname(__DIR__) . '/lib/gsc_box.php');
    assert_true(str_contains($src, '/private/gsc-box-oauth'));
    assert_true(str_contains($src, '/data/gsc-box-oauth'));
    assert_true(str_contains($src, "dirname(\$sqlite)"));
    assert_true(is_file(dirname(__DIR__) . '/data/.htaccess'));

    gsc_box_test_cleanup($override);
    @rmdir($base);
});

test('gsc box-pickup stays reachable in htaccess rules', static function (): void {
    assert_true(!htaccess_404_blocks('/app/gsc/box-pickup.php'));
});
