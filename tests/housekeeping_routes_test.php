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

function housekeeping_run_ai_scan(string $dir, string $method, string $action): void
{
    $code = '$_SERVER["HTTP_HOST"]="jpwebcreation.nl"; $_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
        . ($action !== '' ? '$_GET["action"]=' . var_export($action, true) . ';' : '')
        . 'require ' . var_export(dirname(__DIR__) . '/ai-scan.php', true) . ';';
    shell_exec('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT=1 JPWEB_AI_SCAN_CONFIG=' . escapeshellarg($dir . '/config.php')
        . ' php -r ' . escapeshellarg($code) . ' 2>&1');
}

test('anonymous ai-scan.php requests (GET, POST, state, csrf) write nothing; runner does the purges', static function (): void {
    [$dir, $sqlite] = housekeeping_env();
    housekeeping_run($dir, 'dashboard/index.php'); // creates the schema
    $pdo = housekeeping_db($sqlite);
    $stale = geo_insert_scan($pdo, 'onbevestigd', 0, 'oud.nl'); // created_at=1: ruim verlopen
    $hashed = geo_insert_scan($pdo, 'klaar', 0, 'hash.nl');
    $pdo->exec("UPDATE scans SET ip_hash = 'abc' WHERE id = " . $hashed);
    $claimed = geo_insert_scan($pdo, 'bezig', 0, 'claim.nl');
    $pdo->exec('UPDATE scans SET claimed_at = 1 WHERE id = ' . $claimed);

    foreach ([['GET', ''], ['POST', ''], ['GET', 'state'], ['GET', 'csrf']] as [$method, $action]) {
        housekeeping_run_ai_scan($dir, $method, $action);
    }
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM scans WHERE id = ' . $stale)->fetchColumn(), 'anoniem verzoek verwijdert niets');
    assert_same('abc', (string) $pdo->query('SELECT ip_hash FROM scans WHERE id = ' . $hashed)->fetchColumn(), 'anoniem verzoek wist geen ip-hash');
    assert_same('bezig', (string) $pdo->query('SELECT status FROM scans WHERE id = ' . $claimed)->fetchColumn(), 'anoniem verzoek raakt verlopen claim niet');

    $run = '$_SERVER["HTTP_HOST"]="jpwebcreation.nl"; $_SERVER["REQUEST_METHOD"]="GET"; $_GET["action"]="health";'
        . '$_SERVER["HTTP_AUTHORIZATION"]="Bearer ' . str_repeat('r', 40) . '";'
        . 'require ' . var_export(dirname(__DIR__) . '/api/runner.php', true) . ';';
    shell_exec('JPWEB_AI_SCAN_CONFIG=' . escapeshellarg($dir . '/config.php') . ' php -r ' . escapeshellarg($run) . ' 2>&1');
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM scans WHERE id = ' . $stale)->fetchColumn(), 'runner ruimt onbevestigde scan op');
    assert_same('', (string) $pdo->query('SELECT COALESCE(ip_hash, \'\') FROM scans WHERE id = ' . $hashed)->fetchColumn(), 'runner wist oude ip-hash');
    $pdo = null;
    housekeeping_cleanup($dir);
});
