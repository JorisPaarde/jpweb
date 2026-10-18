<?php

declare(strict_types=1);

const INTERNAL_TEST_ADDRESS = 'jpweb-geotest-1013@agentmail.to';

function internal_insert_scan(PDO $pdo, string $email, string $status, string $domain, int $attempts = 0): int
{
    $pdo->prepare(
        "INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, queued_at, attempts)
         VALUES(:p, :em, :e, :u, :d, :dh, :s, 1, :q, :a)"
    )->execute([
        'p' => bin2hex(random_bytes(8)),
        'em' => $email,
        'e' => ai_scan_email_hash(ai_scan_normalize_email($email)),
        'u' => 'https://' . $domain . '/',
        'd' => $domain,
        'dh' => ai_scan_domain_hash($domain),
        's' => $status,
        'q' => time(),
        'a' => $attempts,
    ]);

    return (int) $pdo->lastInsertId();
}

test('internal test emails: default config lists the geotest address', static function (): void {
    [$config] = boot_test_db();
    assert_same([INTERNAL_TEST_ADDRESS], $config['internal_test_emails']);
});

test('internal test emails: internal scan does not use a daily slot, normal scan does', static function (): void {
    [$config, $pdo] = boot_test_db();
    $now = time();
    internal_insert_scan($pdo, strtoupper(INTERNAL_TEST_ADDRESS), 'klaar', 'intern.nl');
    assert_same(0, ai_scan_count_free_scans_today($pdo, $now, $config));
    internal_insert_scan($pdo, 'klant@firma.nl', 'klaar', 'firma.nl');
    assert_same(1, ai_scan_count_free_scans_today($pdo, $now, $config));
});

test('internal test emails: internal scans do not fill the daily limit for confirm', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['daily_free_scans'] = 1;
    $config['form_burst_seconds'] = 0;
    $now = time();
    internal_insert_scan($pdo, INTERNAL_TEST_ADDRESS, 'bevestigd', 'intern.nl');
    $r = ai_scan_submit($pdo, $config, ['email' => 'klant@firma.nl', 'website' => 'https://firma.nl', 'consent' => '1'], '7.7.7.1', $now);
    assert_same('check_mail', $r['code']);
    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h WHERE email = :e')
        ->execute(['h' => ai_scan_hash_token('rt1'), 'e' => 'klant@firma.nl']);
    assert_same('confirmed', ai_scan_confirm($pdo, $config, 'rt1', $now)['code']);
    assert_same('bevestigd', (string) $pdo->query("SELECT status FROM scans WHERE email = 'klant@firma.nl'")->fetchColumn());
});

test('internal test emails: empty list keeps the old behaviour', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['internal_test_emails'] = [];
    internal_insert_scan($pdo, INTERNAL_TEST_ADDRESS, 'klaar', 'intern.nl');
    assert_same(1, ai_scan_count_free_scans_today($pdo, time(), $config));
    assert_same(1, ai_scan_count_free_scans_today($pdo, time()));
});

test('internal test emails: the one-free-scan rule still applies to internal addresses', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $post = ['email' => INTERNAL_TEST_ADDRESS, 'website' => 'https://intern.nl', 'consent' => '1'];
    assert_same('check_mail', ai_scan_submit($pdo, $config, $post, '9.9.9.1', time())['code']);
    $pdo->exec("UPDATE scans SET status = 'klaar'");
    $post['website'] = 'https://ander-intern.nl';
    assert_same('already_scanned', ai_scan_submit($pdo, $config, $post, '9.9.9.2', time())['code']);
});

test('internal test emails: runner health excludes internal scans; admin page labels them', static function (): void {
    [$dir, $sqlite] = housekeeping_env();
    housekeeping_run($dir, 'dashboard/index.php');
    $pdo = housekeeping_db($sqlite);
    internal_insert_scan($pdo, INTERNAL_TEST_ADDRESS, 'mislukt', 'intern.nl', 1);
    internal_insert_scan($pdo, 'klant@firma.nl', 'mislukt', 'firma.nl', 1);
    $run = '$_SERVER["HTTP_HOST"]="jpwebcreation.nl"; $_SERVER["REQUEST_METHOD"]="GET"; $_GET["action"]="health";'
        . '$_SERVER["HTTP_AUTHORIZATION"]="Bearer ' . str_repeat('r', 40) . '";'
        . 'require ' . var_export(dirname(__DIR__) . '/api/runner.php', true) . ';';
    $out = (string) shell_exec('JPWEB_AI_SCAN_CONFIG=' . escapeshellarg($dir . '/config.php') . ' php -r ' . escapeshellarg($run) . ' 2>&1');
    $json = json_decode(substr($out, (int) strpos($out, '{')), true);
    assert_true(is_array($json), 'health json: ' . substr($out, 0, 200));
    assert_same(1, $json['counts']['mislukt']);
    assert_same(1, count($json['failed']));
    assert_same('firma.nl', $json['failed'][0]['domain']);
    assert_same(1, $json['internal']);
    $pdo = null;
    housekeeping_cleanup($dir);

    $admin = (string) file_get_contents(dirname(__DIR__) . '/admin/scans.php');
    assert_true(str_contains($admin, '(intern)') && str_contains($admin, 'ai_scan_internal_email_hashes'));
});
