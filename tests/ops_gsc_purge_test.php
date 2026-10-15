<?php

declare(strict_types=1);

/** Runs ops/cleanup.php as a subprocess against the given sqlite file. @return array{0:int,1:string} */
function run_ops_purge_gsc(array $config, string $job, string $extraEmail = ''): array
{
    $cfgFile = sys_get_temp_dir() . '/jpweb-ops-cfg-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($cfgFile, '<?php return ' . var_export($config, true) . ';');
    $env = array_merge(getenv(), [
        'OPS_ROOT' => dirname(__DIR__), 'OPS_JOB' => $job, 'OPS_MODE' => 'purge_gsc_preview',
        'OPS_EMAIL' => $extraEmail, 'JPWEB_AI_SCAN_CONFIG' => $cfgFile,
    ]);
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/ops/cleanup.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    $code = proc_close($p);
    @unlink($cfgFile);

    return [$code, $out];
}

test('ops purge_gsc_preview removes only the fixed preview accounts and their connections', static function (): void {
    [$config, $pdo, $sqlite] = boot_test_db();
    $config['website_require_http'] = true;
    $ins = $pdo->prepare('INSERT INTO users (email, email_hash, password_hash, created_at, free_scan_used) VALUES (:e, :h, NULL, 1, 0)');
    $ids = [];
    foreach (['preview-gsc-connected@example.nl', 'preview-gsc-disconnected@example.nl', 'preview-gsc-free@example.nl', 'klant@bedrijf.nl', 'ops@jpwebcreation.nl'] as $e) {
        $ins->execute(['e' => $e, 'h' => ai_scan_email_hash(ai_scan_normalize_email($e))]);
        $ids[$e] = (int) $pdo->lastInsertId();
        ai_scan_gsc_connection_save($pdo, $config, $ids[$e], 'sc-domain:example.nl', 'rt', 'at', 3600, null, 1);
    }
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');

    $job = 'purge-gsc-preview-test-' . bin2hex(random_bytes(4));
    // OPS_EMAIL pointing at a real account must be ignored.
    [$code, $out] = run_ops_purge_gsc($config, $job, 'klant@bedrijf.nl');
    assert_same(0, $code, $out);
    assert_true(str_contains($out, 'users=3 gsc_connections=3 require_http=1'), $out);
    assert_same(['klant@bedrijf.nl', 'ops@jpwebcreation.nl'], $pdo->query('SELECT email FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM gsc_connections')->fetchColumn());
    // Config values other than the boolean are never printed.
    assert_true(!str_contains($out, 'test-app-key') && !str_contains($out, 'test-runner-token'), 'no secrets');

    [$code, $out] = run_ops_purge_gsc($config, $job);
    assert_true(str_contains($out, 'al uitgevoerd'), $out);
    assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
});

test('ops purge_gsc_preview prints require_http=0 when disabled', static function (): void {
    [$config] = boot_test_db();
    $config['website_require_http'] = false;
    [$code, $out] = run_ops_purge_gsc($config, 'purge-gsc-preview-zero-' . bin2hex(random_bytes(4)));
    assert_same(0, $code, $out);
    assert_true(str_contains($out, 'users=0 gsc_connections=0 require_http=0'), $out);
});
