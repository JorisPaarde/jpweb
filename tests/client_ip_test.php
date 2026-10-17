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
    $file = sys_get_temp_dir() . '/jpweb-contact-' . hash('sha256', $expectedIp);
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
