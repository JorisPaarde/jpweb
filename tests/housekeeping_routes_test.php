<?php

declare(strict_types=1);

/**
 * Housekeeping (stale check, waitlist promotion) runs in the runner API, not on
 * anonymous page requests (those would take a SQLite write lock on every GET).
 */

/**
 * @return array{0: string, 1: string} [dir, sqlite path]
 */
function housekeeping_env(): array
{
    $dir = sys_get_temp_dir() . '/jpweb-housekeeping-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $sqlite = $dir . '/h.sqlite';
    file_put_contents($dir . '/config.php', '<?php return ' . var_export([
        'sqlite_path' => $sqlite,
        'runner_token' => str_repeat('r', 40),
        'app_key' => 'k',
        'daily_free_scans' => 20,
    ], true) . ';');

    return [$dir, $sqlite];
}

function housekeeping_run(string $dir, string $route, string $token = ''): string
{
    $code = '$_SERVER["HTTP_HOST"]="jpwebcreation.nl"; $_SERVER["REQUEST_METHOD"]="GET";'
        . ($token !== '' ? '$_SERVER["HTTP_AUTHORIZATION"]="Bearer ' . $token . '";' : '')
        . 'require ' . var_export(dirname(__DIR__) . '/' . $route, true) . ';';
    $cmd = 'JPWEB_AI_SCAN_TEST_CATCH_REDIRECT=1 JPWEB_AI_SCAN_CONFIG=' . escapeshellarg($dir . '/config.php')
        . ' php -r ' . escapeshellarg($code) . ' 2>&1';

    return (string) shell_exec($cmd);
}

function housekeeping_cleanup(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

function housekeeping_db(string $sqlite): PDO
{
    $pdo = new PDO('sqlite:' . $sqlite);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

test('anonymous page GETs do not run housekeeping (no promotion, no write)', static function (): void {
    [$dir, $sqlite] = housekeeping_env();
    housekeeping_run($dir, 'dashboard/index.php'); // creates the schema
    $pdo = housekeeping_db($sqlite);
    $id = geo_insert_scan($pdo, 'wacht_dag', 0, 'wacht.nl');
    foreach (['dashboard/index.php', 'app/index.php'] as $route) {
        if (is_file(dirname(__DIR__) . '/' . $route)) {
            housekeeping_run($dir, $route);
        }
    }
    $status = $pdo->query('SELECT status FROM scans WHERE id = ' . $id)->fetchColumn();
    assert_same('wacht_dag', (string) $status, 'anonieme GET promoot de wachtlijst niet');
    $pdo = null;
    housekeeping_cleanup($dir);
});

test('runner poll promotes the waitlist (next and health)', static function (): void {
    foreach (['next', 'health'] as $action) {
        [$dir, $sqlite] = housekeeping_env();
        housekeeping_run($dir, 'dashboard/index.php');
        $pdo = housekeeping_db($sqlite);
        $id = geo_insert_scan($pdo, 'wacht_dag', 0, 'wacht.nl');
        $_GET = [];
        $code = '$_GET["action"]=' . var_export($action, true) . ';';
        $run = '$_SERVER["HTTP_HOST"]="jpwebcreation.nl"; $_SERVER["REQUEST_METHOD"]="GET";'
            . '$_SERVER["HTTP_AUTHORIZATION"]="Bearer ' . str_repeat('r', 40) . '";' . $code
            . 'require ' . var_export(dirname(__DIR__) . '/api/runner.php', true) . ';';
        shell_exec('JPWEB_AI_SCAN_CONFIG=' . escapeshellarg($dir . '/config.php') . ' php -r ' . escapeshellarg($run) . ' 2>&1');
        $status = (string) $pdo->query('SELECT status FROM scans WHERE id = ' . $id)->fetchColumn();
        assert_true(in_array($status, ['bevestigd', 'bezig'], true), "$action promoot wachtlijst, status=$status");
        $pdo = null;
        housekeeping_cleanup($dir);
    }
});
