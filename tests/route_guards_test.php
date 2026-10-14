<?php

declare(strict_types=1);

/**
 * Route-level checks (subprocess per request): control characters in `next`,
 * the PDO argument on GSC connect/disconnect and the password_version gate on
 * progress/callback.
 */

/**
 * Runs a route in a child PHP process with a pre-seeded session.
 *
 * @param array<string, mixed> $config
 * @param array<string, mixed> $session
 * @param array<string, string> $post
 * @return array{status: int, location: string, body: string}
 */
function route_guard_run(array $config, string $route, array $session, string $method = 'GET', array $post = [], array $get = []): array
{
    $dir = sys_get_temp_dir() . '/jpweb-route-guards-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $saveDir = $dir . '/sessions';
    mkdir($saveDir, 0700, true);
    file_put_contents($dir . '/config.php', '<?php return ' . var_export($config, true) . ';');
    $code = 'ini_set("session.save_path", ' . var_export($saveDir, true) . ');'
        . 'session_name("jpweb_ai_scan"); session_start(); $_SESSION = ' . var_export($session, true) . '; session_write_close();'
        . '$_SERVER["HTTP_HOST"] = "jpwebcreation.nl"; $_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . ';'
        . '$_POST = ' . var_export($post, true) . '; $_GET = ' . var_export($get, true) . ';'
        . 'register_shutdown_function(static function () { echo "\n@@STATUS=" . http_response_code(); });'
        . 'require ' . var_export(dirname(__DIR__) . '/' . $route, true) . ';';
    $cmd = 'JPWEB_AI_SCAN_TEST_CATCH_REDIRECT=1 JPWEB_AI_SCAN_CONFIG=' . escapeshellarg($dir . '/config.php')
        . ' php -d display_errors=1 -d html_errors=0 -d error_reporting=-1 -r ' . escapeshellarg($code) . ' 2>&1';
    $out = (string) shell_exec($cmd);
    foreach (glob($saveDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @unlink($dir . '/config.php');
    @rmdir($saveDir);
    @rmdir($dir);
    preg_match('/@@STATUS=(\d+)/', $out, $m);
    $redirected = preg_match('/RuntimeException: REDIRECT (\S*)/', $out, $r) === 1;

    return [
        'status' => $redirected ? 303 : (int) ($m[1] ?? 0),
        'location' => $redirected ? $r[1] : '',
        'body' => (string) preg_replace('/\n@@STATUS=.*$/s', '', $out),
    ];
}

/**
 * @return array{0: array<string, mixed>, 1: PDO, 2: int}
 */
function route_guard_user(): array
{
    [$config, $pdo, $uid] = gsc_boot_user();
    // The session password_version (1) must match the DB for the valid case.
    $pdo->prepare('UPDATE users SET password_version = 1 WHERE id = :id')->execute(['id' => $uid]);

    return [$config, $pdo, $uid];
}

test('next validators reject control characters', static function (): void {
    foreach (["/\t/evil.example/", "/\n/evil.example/", "/\x00/x", "/\x7f/x"] as $next) {
        assert_same('/dashboard/', ai_scan_gsc_safe_next($next, '/dashboard/'), 'gsc next ' . bin2hex($next));
    }
    assert_same('/app/meer/bedrijf/', ai_scan_gsc_safe_next('/app/meer/bedrijf/', '/dashboard/'));
});

test('request.php falls back to dashboard for a tab in next', static function (): void {
    [$config, , $uid] = route_guard_user();
    $csrf = str_repeat('c', 32);
    $session = ['user_id' => $uid, 'password_version' => 1, 'ai_scan_csrf' => $csrf];
    $bad = route_guard_run($config, 'app/request.php', $session, 'POST', ['csrf' => $csrf, 'kind' => 'cancel', 'next' => "/\t/evil.example/"]);
    assert_same('/dashboard/', $bad['location'], 'tab in next: ' . $bad['body']);
    $ok = route_guard_run($config, 'app/request.php', $session, 'POST', ['csrf' => $csrf, 'kind' => 'cancel', 'next' => '/app/meer/']);
    assert_same('/app/meer/', $ok['location'], 'valid next kept');
});

foreach (['connect', 'disconnect'] as $name) {
    test('gsc ' . $name . ' runs for a valid session and rejects a revoked one', static function () use ($name): void {
        [$config, $pdo, $uid] = route_guard_user();
        $csrf = str_repeat('c', 32);
        $post = ['csrf' => $csrf, 'next' => '/dashboard/'];
        $session = ['user_id' => $uid, 'password_version' => 1, 'ai_scan_csrf' => $csrf];
        $ok = route_guard_run($config, 'app/gsc/' . $name . '.php', $session, 'POST', $post);
        assert_true(!str_contains($ok['body'], 'ArgumentCountError'), 'no fatal: ' . substr($ok['body'], 0, 200));
        assert_same(303, $ok['status']);
        assert_true($ok['location'] !== '' && !str_starts_with($ok['location'], '/login/'), 'valid session not sent to login: ' . $ok['location']);

        $pdo->prepare('UPDATE users SET password_version = 2 WHERE id = :id')->execute(['id' => $uid]);
        $stale = route_guard_run($config, 'app/gsc/' . $name . '.php', $session, 'POST', $post);
        assert_true(!str_contains($stale['body'], 'ArgumentCountError'), 'no fatal for stale session');
        assert_true(str_starts_with($stale['location'], '/login/'), 'revoked session goes to login: ' . $stale['location']);
    });
}

test('progress.php returns 401 for a revoked session', static function (): void {
    [$config, $pdo, $uid] = route_guard_user();
    $session = ['user_id' => $uid, 'password_version' => 1];
    $ok = route_guard_run($config, 'app/progress.php', $session);
    assert_same(200, $ok['status'], 'valid session: ' . $ok['body']);
    $pdo->prepare('UPDATE users SET password_version = 2 WHERE id = :id')->execute(['id' => $uid]);
    $stale = route_guard_run($config, 'app/progress.php', $session);
    assert_same(401, $stale['status']);
    assert_true(str_contains($stale['body'], '"error":"login"'), $stale['body']);
    $none = route_guard_run($config, 'app/progress.php', ['user_id' => $uid]);
    assert_same(401, $none['status'], 'session without version');
});

test('gsc callback rejects a revoked session before the token exchange', static function (): void {
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
    assert_true(str_starts_with($stale['location'], '/login/'), 'revoked session goes to login: ' . $stale['location']);
});
