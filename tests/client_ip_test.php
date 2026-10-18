<?php

declare(strict_types=1);

/**
 * Client IP behind QUIC.cloud: X-Forwarded-For counts only when REMOTE_ADDR is a
 * known QUIC.cloud node. Included from tests/run.php.
 */

/** @param array<string, string> $server */
function client_ip_with(array $server): string
{
    $saved = $_SERVER;
    unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    $_SERVER = array_merge($_SERVER, $server);
    try {
        return ai_scan_client_ip();
    } finally {
        $_SERVER = $saved;
    }
}

test('client ip: spoofed X-Forwarded-For from a normal IP is ignored', static function (): void {
    assert_true(client_ip_with(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']) === '203.0.113.9');
});

test('client ip: QUIC.cloud node uses the last X-Forwarded-For item', static function (): void {
    $ip = client_ip_with(['REMOTE_ADDR' => '102.221.36.98', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7']);
    assert_true($ip === '198.51.100.7', $ip);
});

test('client ip: invalid or empty X-Forwarded-For falls back to REMOTE_ADDR', static function (): void {
    foreach (['', 'unknown', '198.51.100.7, nonsense', '198.51.100.7,'] as $xff) {
        $ip = client_ip_with(['REMOTE_ADDR' => '102.221.36.98', 'HTTP_X_FORWARDED_FOR' => $xff]);
        assert_true($ip === '102.221.36.98', "xff '{$xff}' gave {$ip}");
    }
    assert_true(client_ip_with(['REMOTE_ADDR' => '102.221.36.98']) === '102.221.36.98');
});

test('client ip: QUIC.cloud IPv6 node matches in another spelling', static function (): void {
    foreach (['2001:19F0:1000:2226:5400:4FF:FEC3:D996', '2001:19f0:1000:2226:5400:04ff:fec3:d996'] as $remote) {
        $ip = client_ip_with(['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => '2001:db8::1']);
        assert_true($ip === '2001:db8::1', "remote {$remote} gave {$ip}");
    }
});

/** Runs contact.php as a valid POST in a child process; returns whether the rate-limit file for $expectedIp exists afterwards. */
function contact_rate_key_for(array $server, string $expectedIp): bool
{
    $file = sys_get_temp_dir() . '/jpweb-contact-' . ai_scan_ip_hash($expectedIp);
    @unlink($file);
    $runner = sys_get_temp_dir() . '/jpweb-contact-runner-' . getmypid() . '.php';
    file_put_contents($runner, '<?php
        $_SERVER = array_merge($_SERVER, ' . var_export($server + [
            'HTTP_HOST' => 'jpwebcreation.nl',
            'REQUEST_METHOD' => 'POST',
        ], true) . ');
        $_POST = ["name" => "Test Naam", "email" => "t@example.com", "message" => "Een testbericht van genoeg lengte", "privacy" => "accepted"];
        ob_start();
        require ' . var_export(dirname(__DIR__) . '/contact.php', true) . ';');
    exec(escapeshellarg(PHP_BINARY) . ' -d sendmail_path=/bin/true ' . escapeshellarg($runner) . ' 2>&1');
    @unlink($runner);
    $exists = is_file($file);
    @unlink($file);

    return $exists;
}

test('contact rate limit: spoofed X-Forwarded-For from a normal IP keeps the REMOTE_ADDR key', static function (): void {
    $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
    assert_true(contact_rate_key_for($server, '203.0.113.9'), 'key for REMOTE_ADDR missing');
    assert_true(!contact_rate_key_for($server, '198.51.100.7'), 'spoofed XFF became the key');
});

test('contact rate limit: QUIC.cloud node keys on the last X-Forwarded-For item', static function (): void {
    $server = ['REMOTE_ADDR' => '102.221.36.98', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7'];
    assert_true(contact_rate_key_for($server, '198.51.100.7'), 'key for visitor IP missing');
    assert_true(!contact_rate_key_for($server, '102.221.36.98'), 'node IP became the key');
});

test('contact rate limit: IPv6 addresses in the same /64 share the key, other /64 and IPv4 do not', static function (): void {
    assert_true(contact_rate_key_for(['REMOTE_ADDR' => '2001:db8:1:2::1'], '2001:db8:1:2:ffff::9'), 'same /64 gave other key');
    assert_true(!contact_rate_key_for(['REMOTE_ADDR' => '2001:db8:1:2::1'], '2001:db8:1:3::1'), 'other /64 shares key');
    assert_true(contact_rate_key_for(['REMOTE_ADDR' => '203.0.113.9'], '203.0.113.9'), 'IPv4 key changed');
});

test('contact rate limit: old limit files are cleaned up, fresh ones stay', static function (): void {
    $dir = sys_get_temp_dir() . '/jpweb-contact-clean-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $old = $dir . '/jpweb-contact-old';
    $new = $dir . '/jpweb-contact-new';
    $other = $dir . '/other-file';
    foreach ([$old, $new, $other] as $f) {
        touch($f);
    }
    touch($old, time() - 2 * 86400);
    touch($other, time() - 2 * 86400);
    contact_rate_cleanup($dir, 86400);
    $result = [is_file($old), is_file($new), is_file($other)];
    foreach ([$new, $other, $old] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    assert_same([false, true, true], $result);
});

test('contact.php: GET shows a Dutch 405 page with a link to the form', static function (): void {
    $runner = sys_get_temp_dir() . '/jpweb-contact-get-' . getmypid() . '.php';
    file_put_contents($runner, '<?php
        $_SERVER = array_merge($_SERVER, ["HTTP_HOST" => "jpwebcreation.nl", "REQUEST_METHOD" => "GET"]);
        require ' . var_export(dirname(__DIR__) . '/contact.php', true) . ';
    ');
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($runner) . ' 2>&1');
    @unlink($runner);
    assert_true(!str_contains($out, 'Method Not Allowed'), 'English text remains');
    assert_true(str_contains($out, 'lang="nl"') && str_contains($out, 'name="viewport"'), 'not the Dutch page');
    assert_true(str_contains($out, 'href="/#contact"'), 'link to form missing');
    $status = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        '$_SERVER["HTTP_HOST"]="jpwebcreation.nl";$_SERVER["REQUEST_METHOD"]="GET";'
        . 'register_shutdown_function(function(){fwrite(STDOUT,"\nSTATUS=".http_response_code());});'
        . 'ob_start();require ' . var_export(dirname(__DIR__) . '/contact.php', true) . ';'
    ));
    assert_true(str_contains($status, 'STATUS=405'), 'status is not 405: ' . $status);
});

/** Fresh temp folder as QUIC.cloud cache dir; returns the path. */
function quic_env(): string
{
    $dir = sys_get_temp_dir() . '/jpweb-quic-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    ai_scan_quic_cloud_dir($dir, true);

    return $dir;
}

function quic_env_end(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    ai_scan_quic_cloud_dir(dirname(__DIR__) . '/../private', true);
}

/** @return list<string> 60 documentation-range IPs */
function quic_fake_list(int $n = 60): array
{
    $ips = [];
    for ($i = 1; $i <= $n; $i++) {
        $ips[] = '198.18.0.' . $i;
    }

    return $ips;
}

test('quic refresh: a valid list is cached and used next to the snapshot', static function (): void {
    $dir = quic_env();
    try {
        assert_true(!ai_scan_is_quic_cloud_ip('198.18.0.7'));
        $ok = ai_scan_quic_cloud_refresh($dir, static fn (string $u): string => json_encode(quic_fake_list()), 1000);
        assert_true($ok && is_file($dir . '/quic-cloud-ips.json'), 'cache not written');
        assert_true(glob($dir . '/*.tmp') === [], 'tmp file left behind');
        assert_true(ai_scan_is_quic_cloud_ip('198.18.0.7'), 'cached node unknown');
        assert_true(ai_scan_is_quic_cloud_ip('102.221.36.98'), 'snapshot node lost');
        assert_true(client_ip_with(['REMOTE_ADDR' => '198.18.0.7', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7']) === '198.51.100.7');
    } finally {
        quic_env_end($dir);
    }
});

test('quic refresh: invalid, too small or broken answers are ignored and the old cache stays', static function (): void {
    $dir = quic_env();
    try {
        ai_scan_quic_cloud_refresh($dir, static fn (string $u): string => json_encode(quic_fake_list()), 1000);
        $before = (string) file_get_contents($dir . '/quic-cloud-ips.json');
        $bad = [
            json_encode(quic_fake_list(49)),
            json_encode(array_merge(array_fill(0, 60, 'nope'), ['203.0.113.5'])),
            '<html>error</html>',
            '',
        ];
        $t = 1000;
        foreach ($bad as $body) {
            $t += 86400 + 1;
            $ok = ai_scan_quic_cloud_refresh($dir, static fn (string $u): string => $body, $t);
            assert_true($ok === false);
            assert_same($before, (string) file_get_contents($dir . '/quic-cloud-ips.json'));
        }
        $t += 86400 + 1;
        assert_true(ai_scan_quic_cloud_refresh($dir, static function (string $u): string { throw new RuntimeException('down'); }, $t) === false);
        assert_same($before, (string) file_get_contents($dir . '/quic-cloud-ips.json'));
        assert_true(!ai_scan_is_quic_cloud_ip('203.0.113.5'));
    } finally {
        quic_env_end($dir);
    }
});

test('quic refresh: at most once per 24 hours, also after a failed attempt', static function (): void {
    $dir = quic_env();
    try {
        $calls = 0;
        $fetch = static function (string $u) use (&$calls): string {
            $calls++;

            return '[]';
        };
        ai_scan_quic_cloud_refresh($dir, $fetch, 5000);
        $first = $calls;
        assert_true($first > 0);
        ai_scan_quic_cloud_refresh($dir, $fetch, 5000 + 86399);
        assert_same($first, $calls, 'fetched again within 24h');
        ai_scan_quic_cloud_refresh($dir, $fetch, 5000 + 86400);
        assert_true($calls > $first, 'no retry after 24h');
    } finally {
        quic_env_end($dir);
    }
});

test('quic signal: spoofed X-Forwarded-For from an unknown IP keeps REMOTE_ADDR and logs once an hour', static function (): void {
    $dir = quic_env();
    $log = $dir . '/php.log';
    $oldLog = ini_get('error_log');
    ini_set('error_log', $log);
    try {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        for ($i = 0; $i < 3; $i++) {
            assert_same('203.0.113.9', client_ip_with($server));
        }
        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        assert_same(1, count($lines), 'log lines: ' . count($lines));
        assert_true(str_contains($lines[0], '203.0.113.9') && !str_contains($lines[0], '198.51.100.7'), 'line leaks or misses IP');
        // After an hour it logs again.
        touch($dir . '/quic-cloud-unknown-xff.mark', time() - 3601);
        client_ip_with($server);
        assert_same(2, count(file($log) ?: []));
        // No header, no line.
        touch($dir . '/quic-cloud-unknown-xff.mark', time() - 7200);
        client_ip_with(['REMOTE_ADDR' => '203.0.113.9']);
        assert_same(2, count(file($log) ?: []));
        // A known node never signals.
        client_ip_with(['REMOTE_ADDR' => '102.221.36.98', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']);
        assert_same(2, count(file($log) ?: []));
    } finally {
        ini_set('error_log', (string) $oldLog);
        quic_env_end($dir);
    }
});
