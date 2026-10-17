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
