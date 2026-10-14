<?php

declare(strict_types=1);

// A copied route and sentinel bootstrap never open a real database, even before the fix.
foreach ([
    'default CLI' => ['', '', 'http://localhost', false],
    'opt-in without config' => ['1', '', 'http://localhost', false],
    'config without opt-in' => ['', 'config.php', 'http://localhost', false],
    'non-explicit opt-in' => ['true', 'config.php', 'http://localhost', false],
    'production config' => ['1', 'config.php', 'https://jpwebcreation.nl', false],
    'test deployment config' => ['1', 'config.php', 'https://test.jpwebcreation.nl', false],
    'production HTTP runtime' => ['1', 'config.php', 'http://localhost', false],
    'remote PHP test server' => ['1', 'config.php', 'http://localhost', false],
    'local PHP test server' => ['1', 'config.php', 'http://localhost', true],
    'explicit local CLI' => ['1', 'config.php', 'http://localhost:4173', true],
] as $name => [$optIn, $configFile, $baseUrl, $allowed]) {
    test('gsc preview guard: ' . $name, static function () use ($name, $optIn, $configFile, $baseUrl, $allowed): void {
        $dir = sys_get_temp_dir() . '/jpweb-preview-' . bin2hex(random_bytes(6));
        mkdir($dir . '/app/gsc', 0700, true);
        mkdir($dir . '/lib', 0700);
        // Simulate SAPI in the isolated copy; this sandbox forbids listening sockets.
        $sapi = match ($name) {
            'production HTTP runtime' => 'fpm-fcgi',
            'remote PHP test server', 'local PHP test server' => 'cli-server',
            default => 'cli',
        };
        $route = file_get_contents(dirname(__DIR__) . '/app/gsc/preview.php');
        file_put_contents($dir . '/app/gsc/preview.php', str_replace('PHP_SAPI', var_export($sapi, true), $route));
        file_put_contents($dir . '/config.php', '<?php return ' . var_export(['base_url' => $baseUrl], true) . ';');
        file_put_contents($dir . '/lib/gsc.php', '<?php');
        file_put_contents($dir . '/lib/bootstrap.php', '<?php
function ai_scan_load_config() { return require getenv("JPWEB_AI_SCAN_CONFIG"); }
function ai_scan_db($config) { echo "DATABASE_REACHED"; exit; }
function ai_scan_boot() { echo "DATABASE_REACHED"; exit; }
');
        $script = '$_SERVER["REMOTE_ADDR"] = ' . var_export($name === 'remote PHP test server' ? '192.0.2.1' : '127.0.0.1', true) . '; register_shutdown_function(function () { echo " STATUS=" . http_response_code(); }); require ' . var_export($dir . '/app/gsc/preview.php', true) . ';';
        try {
            $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, [
                'JPWEB_GSC_PREVIEW_LOCAL_TEST' => $optIn,
                'JPWEB_AI_SCAN_CONFIG' => $configFile === '' ? '' : $dir . '/' . $configFile,
            ]);
            assert_true(is_resource($process), 'subprocess started');
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            assert_same(0, proc_close($process), 'route exits cleanly');
            assert_same('', $errors, 'no PHP errors');
            assert_same($allowed, str_contains($output, 'DATABASE_REACHED'), 'database access policy');
            if (!$allowed) {
                assert_true(str_contains($output, 'STATUS=404'), 'denied route returns 404');
            }
        } finally {
            foreach (['app/gsc/preview.php', 'lib/bootstrap.php', 'lib/gsc.php', 'config.php'] as $file) {
                unlink($dir . '/' . $file);
            }
            rmdir($dir . '/app/gsc');
            rmdir($dir . '/app');
            rmdir($dir . '/lib');
            rmdir($dir);
        }
    });
}

test('gsc preview removed by both rsync deploy jobs', static function (): void {
    $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy.yml');
    foreach (['deploy_test', 'deploy_production'] as $job) {
        preg_match('/^  ' . $job . ':.*?(?=^  [a-z_]+:|\z)/ms', $workflow, $match);
        assert_true(isset($match[0]), 'deploy job exists');
        assert_true(str_contains($match[0], "--filter='H /app/gsc/preview.php'"), 'hide preview from transfer: ' . $job);
        assert_true(str_contains($match[0], "--filter='R /app/gsc/preview.php'"), 'allow deletion of previously deployed preview: ' . $job);
    }
});
