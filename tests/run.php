<?php

declare(strict_types=1);

/**
 * Lightweight PHP test runner for the AI-scan backend (no Composer required).
 * Exit code 0 = all passed.
 */

putenv('JPWEB_AI_SCAN_ALLOW_INSECURE=1');
putenv('JPWEB_AI_SCAN_MAIL_DRY_RUN=1');
putenv('JPWEB_AI_SCAN_SKIP_REACHABILITY=1');

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap.php';

$failures = 0;
$passed = 0;

function test(string $name, callable $fn): void
{
    global $failures, $passed;
    try {
        $fn();
        echo 'OK  ' . $name . PHP_EOL;
        $passed++;
    } catch (Throwable $e) {
        echo 'FAIL ' . $name . ' — ' . $e->getMessage() . PHP_EOL;
        $failures++;
    }
}

function assert_true(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg !== '' ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true)
        );
    }
}

function test_config(string $sqlite): array
{
    return array_replace_recursive(ai_scan_default_config($sqlite), [
        'runner_token' => 'test-runner-token',
        'app_key' => 'test-app-key',
        'base_url' => 'https://jpwebcreation.nl',
        'daily_free_scans' => 20,
        'form_burst_seconds' => 0,
        'form_burst_max' => 10,
        'confirm_mail_per_email_seconds' => 0,
        'confirm_mail_global_max_per_hour' => 1000,
        'smtp' => [
            'host' => 's1155.nl',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'info@jpwebcreation.nl',
            'password' => '',
            'from_email' => 'info@jpwebcreation.nl',
            'from_name' => 'JP Web Creation',
            'alert_to' => 'info@jpwebcreation.nl',
        ],
    ]);
}

function boot_test_db(): array
{
    $dir = sys_get_temp_dir() . '/jpweb-ai-scan-tests';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $sqlite = $dir . '/test-' . bin2hex(random_bytes(4)) . '.sqlite';
    $mailLog = $dir . '/mail-' . bin2hex(random_bytes(4)) . '.log';
    putenv('JPWEB_AI_SCAN_MAIL_LOG=' . $mailLog);
    $config = test_config($sqlite);
    $pdo = ai_scan_db($config);
    ai_scan_migrate($pdo);

    return [$config, $pdo, $sqlite, $mailLog];
}

test('normalize gmail dots and plus', static function (): void {
    assert_same('johndoe@gmail.com', ai_scan_normalize_email('John.Doe+tag@gmail.com'));
    assert_same('jane@example.com', ai_scan_normalize_email('Jane+news@example.com'));
});

test('registrable domain', static function (): void {
    assert_same('example.nl', ai_scan_registrable_domain('shop.example.nl'));
    assert_same('example.co.uk', ai_scan_registrable_domain('www.example.co.uk'));
});

test('parse website', static function (): void {
    $p = ai_scan_parse_website('Example.NL/path');
    assert_true($p['ok']);
    assert_same('example.nl', $p['domain']);
    assert_same('https://example.nl/', $p['url']);
});

test('email hash stable', static function (): void {
    assert_same(ai_scan_email_hash('a@b.nl'), ai_scan_email_hash('a@b.nl'));
});

test('disposable detection', static function (): void {
    assert_true(ai_scan_is_disposable_email('x@mailinator.com'));
    assert_true(!ai_scan_is_disposable_email('joris@jpwebcreation.nl'));
});

test('submit honeypot returns check_mail', static function (): void {
    [$config, $pdo] = boot_test_db();
    $r = ai_scan_submit($pdo, $config, [
        'email' => 'a@example.com',
        'website' => 'https://example.com',
        'consent' => '1',
        'hp_field' => 'bot',
    ], '1.2.3.4');
    assert_same('check_mail', $r['code']);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();
    assert_same(0, $count);
});

test('submit invalid email', static function (): void {
    [$config, $pdo] = boot_test_db();
    $r = ai_scan_submit($pdo, $config, [
        'email' => 'not-an-email',
        'website' => 'https://example.com',
        'consent' => '1',
    ], '1.2.3.4');
    assert_same('invalid_email', $r['code']);
});

test('submit disposable', static function (): void {
    [$config, $pdo] = boot_test_db();
    $r = ai_scan_submit($pdo, $config, [
        'email' => 'x@yopmail.com',
        'website' => 'https://example.com',
        'consent' => '1',
    ], '1.2.3.4');
    assert_same('disposable', $r['code']);
});

test('submit + confirm + set password + runner flow', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();

    $r = ai_scan_submit($pdo, $config, [
        'email' => 'Owner+test@Example.com',
        'website' => 'https://acme-test-site.nl',
        'consent' => '1',
    ], '10.0.0.1');
    assert_same('check_mail', $r['code']);
    assert_true(isset($r['scan_id']));

    $scan = $pdo->query('SELECT * FROM scans')->fetch();
    assert_same('onbevestigd', $scan['status']);
    assert_same('owner@example.com', $scan['email']);

    // Recover confirm token from mail log URL is hard; inject known token.
    $token = 'confirm-token-test-1';
    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h WHERE id = :id')->execute([
        'h' => ai_scan_hash_token($token),
        'id' => $scan['id'],
    ]);

    $c = ai_scan_confirm($pdo, $config, $token);
    assert_same('confirmed', $c['code']);

    $scan = $pdo->query('SELECT * FROM scans')->fetch();
    assert_same('bevestigd', $scan['status']);
    assert_true($scan['user_id'] !== null);

    $user = $pdo->query('SELECT * FROM users')->fetch();
    assert_same(1, (int) $user['free_scan_used']);
    assert_true($user['password_hash'] === null);

    $pwToken = 'password-token-test-1';
    $pdo->prepare('UPDATE scans SET password_token_hash = :h, password_token_expires_at = :e WHERE id = :id')->execute([
        'h' => ai_scan_hash_token($pwToken),
        'e' => time() + 3600,
        'id' => $scan['id'],
    ]);
    $set = ai_scan_set_password($pdo, $pwToken, 'super-secret-password');
    assert_true($set['ok']);
    $user = $pdo->query('SELECT * FROM users')->fetch();
    assert_true(ai_scan_verify_password('super-secret-password', (string) $user['password_hash']));

    $job = ai_scan_next_job($pdo, $config);
    assert_true($job !== null);
    assert_same((int) $scan['id'], (int) $job['id']);

    $claimed = ai_scan_claim_job($pdo, (int) $job['id']);
    assert_true($claimed !== null);
    assert_same('bezig', $claimed['status']);

    $html = '<section><h2>Uitslag</h2><p>3 van 50</p></section>';
    $json = json_encode(['mentioned' => 3, 'answers' => 50], JSON_THROW_ON_ERROR);
    assert_true(ai_scan_complete_job($pdo, (int) $job['id'], $html, $json));

    $done = $pdo->query('SELECT * FROM scans')->fetch();
    assert_same('klaar', $done['status']);
    assert_same($html, $done['result_html']);

    assert_true(is_file($mailLog));
    $mail = file_get_contents($mailLog);
    assert_true(str_contains((string) $mail, 'Bevestig je e-mailadres'));
    assert_true(str_contains((string) $mail, 'Je account voor de AI-scan'));
    assert_true(!str_contains((string) $mail, 'super-secret-password'));
});

test('already_scanned per email and domain', static function (): void {
    [$config, $pdo] = boot_test_db();
    $r1 = ai_scan_submit($pdo, $config, [
        'email' => 'one@firma.nl',
        'website' => 'https://firma.nl',
        'consent' => '1',
    ], '1.1.1.1');
    assert_same('check_mail', $r1['code']);

    $r2 = ai_scan_submit($pdo, $config, [
        'email' => 'one@firma.nl',
        'website' => 'https://other.nl',
        'consent' => '1',
    ], '1.1.1.2');
    assert_same('already_scanned', $r2['code']);

    $r3 = ai_scan_submit($pdo, $config, [
        'email' => 'two@firma.nl',
        'website' => 'https://www.firma.nl',
        'consent' => '1',
    ], '1.1.1.3');
    assert_same('already_scanned', $r3['code']);
});

test('daily limit sets wacht_dag on confirm', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['daily_free_scans'] = 1;
    $now = time();

    // Consume today's slot with an already-confirmed scan.
    $pdo->prepare(
        'INSERT INTO scans (public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, confirmed_at, queued_at)
         VALUES (:p, :e, :eh, :w, :d, :dh, :s, :c, :conf, :q)'
    )->execute([
        'p' => ai_scan_public_id(),
        'e' => 'first@a.nl',
        'eh' => ai_scan_email_hash('first@a.nl'),
        'w' => 'https://a.nl/',
        'd' => 'a.nl',
        'dh' => ai_scan_domain_hash('a.nl'),
        's' => 'bevestigd',
        'c' => $now,
        'conf' => $now,
        'q' => $now,
    ]);

    $r = ai_scan_submit($pdo, $config, [
        'email' => 'second@b.nl',
        'website' => 'https://b.nl',
        'consent' => '1',
    ], '2.2.2.2', $now);
    assert_same('daily_full', $r['code']);

    $token = 'c2';
    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h WHERE email = :e')->execute([
        'h' => ai_scan_hash_token($token),
        'e' => 'second@b.nl',
    ]);
    $c = ai_scan_confirm($pdo, $config, $token, $now);
    assert_same('confirmed', $c['code']);
    $status = $pdo->query("SELECT status FROM scans WHERE email = 'second@b.nl'")->fetchColumn();
    assert_same('wacht_dag', $status);
});

test('form IP rate limit (max 3/hour default path)', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_ip_max_per_hour'] = 3;
    $config['form_ip_max_per_day'] = 20;
    $config['form_burst_seconds'] = 0;
    $ip = '9.9.9.9';
    $now = time();
    for ($i = 0; $i < 3; $i++) {
        $r = ai_scan_submit($pdo, $config, [
            'email' => "u{$i}@zzz{$i}.nl",
            'website' => "https://zzz{$i}.nl",
            'consent' => '1',
        ], $ip, $now + $i);
        assert_same('check_mail', $r['code']);
    }
    $r = ai_scan_submit($pdo, $config, [
        'email' => 'u3@zzz3.nl',
        'website' => 'https://zzz3.nl',
        'consent' => '1',
    ], $ip, $now + 3);
    assert_same('rate_limited', $r['code']);
    assert_true(isset($r['retry_after']));
});

test('form burst blocks repeated requests quickly', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 60;
    $config['form_burst_max'] = 1;
    $config['form_ip_max_per_hour'] = 10;
    $now = time();
    $r1 = ai_scan_submit($pdo, $config, [
        'email' => 'burst1@a.nl',
        'website' => 'https://a-burst.nl',
        'consent' => '1',
    ], '5.5.5.5', $now);
    assert_same('check_mail', $r1['code']);
    $r2 = ai_scan_submit($pdo, $config, [
        'email' => 'burst2@b.nl',
        'website' => 'https://b-burst.nl',
        'consent' => '1',
    ], '5.5.5.5', $now + 5);
    assert_same('rate_limited', $r2['code']);
});

test('form email/domain rate limit', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $config['form_email_max_per_hour'] = 1;
    $config['form_domain_max_per_hour'] = 1;
    $config['confirm_mail_per_email_seconds'] = 0;
    $now = time();

    // First request for email creates onbevestigd; second same email hits already_scanned
    // before email rate if we don't clear. Use rate record path: force by recording then submit new domain.
    $r1 = ai_scan_submit($pdo, $config, [
        'email' => 'lim@firma.nl',
        'website' => 'https://firma-lim.nl',
        'consent' => '1',
    ], '4.4.4.1', $now);
    assert_same('check_mail', $r1['code']);

    // Different email, same domain → domain hourly limit after first success recorded.
    $r2 = ai_scan_submit($pdo, $config, [
        'email' => 'other@elders.nl',
        'website' => 'https://www.firma-lim.nl',
        'consent' => '1',
    ], '4.4.4.2', $now + 1);
    assert_same('rate_limited', $r2['code']);
});

test('confirm mail throttle per email and global', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $config['confirm_mail_per_email_seconds'] = 600;
    $config['confirm_mail_global_max_per_hour'] = 2;
    $now = time();

    $r1 = ai_scan_submit($pdo, $config, [
        'email' => 'mail1@t1.nl',
        'website' => 'https://t1.nl',
        'consent' => '1',
    ], '3.3.3.1', $now);
    assert_same('check_mail', $r1['code']);

    // Same email within 10 minutes → blocked (even if no scan row yet for a new attempt:
    // existing scan would be already_scanned; simulate by deleting the row but keeping mail rate).
    $pdo->exec('DELETE FROM scans');
    $r2 = ai_scan_submit($pdo, $config, [
        'email' => 'mail1@t1.nl',
        'website' => 'https://t1.nl',
        'consent' => '1',
    ], '3.3.3.1', $now + 30);
    assert_same('rate_limited', $r2['code']);

    $r3 = ai_scan_submit($pdo, $config, [
        'email' => 'mail2@t2.nl',
        'website' => 'https://t2.nl',
        'consent' => '1',
    ], '3.3.3.2', $now + 31);
    assert_same('check_mail', $r3['code']);

    // Global cap 2 already used (mail1 + mail2).
    $r4 = ai_scan_submit($pdo, $config, [
        'email' => 'mail3@t3.nl',
        'website' => 'https://t3.nl',
        'consent' => '1',
    ], '3.3.3.3', $now + 32);
    assert_same('rate_limited', $r4['code']);
});

test('defaults: 20 daily scans and 30 min stale alert', static function (): void {
    $defaults = ai_scan_default_config(':memory:');
    assert_same(20, $defaults['daily_free_scans']);
    assert_same(1800, $defaults['stale_job_seconds']);
    assert_same(3, $defaults['form_ip_max_per_hour']);
    assert_same(600, $defaults['confirm_mail_per_email_seconds']);
});

test('GDPR delete keeps hashes, clears PII', static function (): void {
    [$config, $pdo] = boot_test_db();
    $r = ai_scan_submit($pdo, $config, [
        'email' => 'delete-me@example.nl',
        'website' => 'https://deleteme.nl',
        'consent' => '1',
    ], '8.8.8.8');
    $token = 'del-confirm';
    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h WHERE id = :id')->execute([
        'h' => ai_scan_hash_token($token),
        'id' => $r['scan_id'],
    ]);
    $c = ai_scan_confirm($pdo, $config, $token);
    $userId = (int) $c['user_id'];
    $pdo->prepare("UPDATE scans SET status = 'klaar', result_html = '<p>secret</p>', result_json = '{}' WHERE user_id = :id")
        ->execute(['id' => $userId]);

    assert_true(ai_scan_delete_account($pdo, $userId));
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    $scan = $pdo->query('SELECT * FROM scans')->fetch();
    assert_same('', $scan['email']);
    assert_true($scan['result_html'] === null);
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM free_scan_blocks')->fetchColumn() >= 1);

    $again = ai_scan_submit($pdo, $config, [
        'email' => 'delete-me@example.nl',
        'website' => 'https://other-after-delete.nl',
        'consent' => '1',
    ], '8.8.8.9');
    assert_same('already_scanned', $again['code']);
});

test('stale job alert cooldown', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $now = time();
    $pdo->prepare(
        'INSERT INTO scans (public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, confirmed_at, queued_at)
         VALUES (:p, :e, :eh, :w, :d, :dh, :s, :c, :conf, :q)'
    )->execute([
        'p' => ai_scan_public_id(),
        'e' => 'stale@x.nl',
        'eh' => ai_scan_email_hash('stale@x.nl'),
        'w' => 'https://x.nl/',
        'd' => 'x.nl',
        'dh' => ai_scan_domain_hash('x.nl'),
        's' => 'bevestigd',
        'c' => $now - 10000,
        'conf' => $now - 10000,
        'q' => $now - 10000,
    ]);

    @unlink($mailLog);
    putenv('JPWEB_AI_SCAN_MAIL_LOG=' . $mailLog);

    $a1 = ai_scan_check_stale_jobs($pdo, $config, $now);
    assert_true($a1['alerted']);
    assert_true($a1['stale'] >= 1);

    $a2 = ai_scan_check_stale_jobs($pdo, $config, $now + 10);
    assert_true(!$a2['alerted']);
});

test('password_hash used, never plaintext column', static function (): void {
    [$config, $pdo] = boot_test_db();
    $hash = ai_scan_hash_password('notherpassword');
    assert_true(str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon'));
    $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    $names = array_map(static fn ($c) => $c['name'], $cols);
    assert_true(in_array('password_hash', $names, true));
    assert_true(!in_array('password', $names, true));
});

echo PHP_EOL . "Passed: {$passed}; Failed: {$failures}" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
