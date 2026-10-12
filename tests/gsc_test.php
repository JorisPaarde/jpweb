<?php

declare(strict_types=1);

/**
 * Google Search Console integration tests (mocked HTTP — no network).
 * OAuth + web Search Analytics only. No Generative AI CSV/metric.
 */

require_once dirname(__DIR__) . '/lib/gsc.php';

/**
 * @param array<string, array{status:int, body:string}> $map keyed by "METHOD pathOrUrlContains"
 */
function gsc_mock_http(array $map): void
{
    ai_scan_gsc_set_http(static function (string $method, string $url, array $opts) use ($map): array {
        $method = strtoupper($method);
        foreach ($map as $key => $response) {
            $parts = explode(' ', $key, 2);
            $m = strtoupper($parts[0] ?? '');
            $needle = $parts[1] ?? '';
            if ($m === $method && ($needle === '' || str_contains($url, $needle))) {
                return [
                    'status' => (int) $response['status'],
                    'body' => (string) $response['body'],
                    'headers' => [],
                ];
            }
        }
        throw new RuntimeException('Unexpected GSC HTTP call: ' . $method . ' ' . $url);
    });
}

function gsc_test_config(string $sqlite): array
{
    $config = test_config($sqlite);
    $config['gsc_client_id'] = 'test-gsc-client-id';
    $config['gsc_client_secret'] = 'test-gsc-client-secret';
    $config['gsc_redirect_uri'] = 'https://jpwebcreation.nl/app/gsc/callback.php';
    $config['app_key'] = 'test-app-key-for-gsc-encryption-32b';

    return $config;
}

/**
 * @return array{0: array<string, mixed>, 1: PDO, 2: int}
 */
function gsc_boot_user(string $tierId = 'q25', string $status = 'active'): array
{
    $dir = sys_get_temp_dir() . '/jpweb-ai-scan-tests';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $sqlite = $dir . '/gsc-' . bin2hex(random_bytes(4)) . '.sqlite';
    $config = gsc_test_config($sqlite);
    $pdo = ai_scan_db($config);
    ai_scan_migrate($pdo);
    ai_scan_gsc_migrate($pdo);

    $email = 'gsc-' . bin2hex(random_bytes(3)) . '@example.nl';
    $pdo->prepare(
        'INSERT INTO users (email, email_hash, password_hash, created_at, free_scan_used, tier_id, subscription_status)
         VALUES (:e, :h, :p, :c, 1, :tier, :status)'
    )->execute([
        'e' => $email,
        'h' => ai_scan_email_hash($email),
        'p' => ai_scan_hash_password('secret-password-12'),
        'c' => time(),
        'tier' => $tierId,
        'status' => $status,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO scans (
            public_id, user_id, email, email_hash, website_url, domain, domain_hash,
            status, created_at, confirmed_at
         ) VALUES (
            :p, :u, :e, :eh, :w, :d, :dh, :s, :c, :conf
         )'
    )->execute([
        'p' => ai_scan_public_id(),
        'u' => $userId,
        'e' => $email,
        'eh' => ai_scan_email_hash($email),
        'w' => 'https://voorbeeld.nl/',
        'd' => 'voorbeeld.nl',
        'dh' => ai_scan_domain_hash('voorbeeld.nl'),
        's' => 'klaar',
        'c' => time(),
        'conf' => time(),
    ]);

    return [$config, $pdo, $userId];
}

test('gsc encrypt/decrypt roundtrip', static function (): void {
    [$config] = gsc_boot_user();
    $enc = ai_scan_gsc_encrypt($config, 'refresh-token-secret');
    assert_true($enc !== 'refresh-token-secret');
    assert_true(!str_contains($enc, 'refresh-token-secret'));
    assert_same('refresh-token-secret', ai_scan_gsc_decrypt($config, $enc));
});

test('gsc configured requires real-looking keys', static function (): void {
    $config = gsc_test_config(':memory:');
    assert_true(ai_scan_gsc_configured($config));
    $config['gsc_client_id'] = 'CHANGE_ME_GSC_CLIENT_ID';
    assert_true(!ai_scan_gsc_configured($config));
});

test('gsc redirect uri never comes from request', static function (): void {
    $config = gsc_test_config(':memory:');
    assert_same(
        'https://jpwebcreation.nl/app/gsc/callback.php',
        ai_scan_gsc_redirect_uri($config)
    );
    $_GET['redirect_uri'] = 'https://evil.example/steal';
    assert_same(
        'https://jpwebcreation.nl/app/gsc/callback.php',
        ai_scan_gsc_redirect_uri($config)
    );
    unset($_GET['redirect_uri']);
});

test('gsc safe next rejects open redirects', static function (): void {
    assert_same('/dashboard/', ai_scan_gsc_safe_next('https://evil.example/', '/dashboard/'));
    assert_same('/dashboard/', ai_scan_gsc_safe_next('//evil.example/', '/dashboard/'));
    assert_same('/dashboard/', ai_scan_gsc_safe_next('/\\evil', '/dashboard/'));
    assert_same('/app/meer/bedrijf/', ai_scan_gsc_safe_next('/app/meer/bedrijf/', '/dashboard/'));
});

test('gsc oauth state create and consume', static function (): void {
    [$config, , $userId] = gsc_boot_user();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
    }
    $state = ai_scan_gsc_oauth_state_create($config, $userId);
    $ok = ai_scan_gsc_oauth_state_consume($config, $state);
    assert_true($ok['ok']);
    assert_same($userId, $ok['user_id']);
    $again = ai_scan_gsc_oauth_state_consume($config, $state);
    assert_true(!$again['ok']);
});

test('gsc property match prefers sc-domain', static function (): void {
    $sites = [
        'https://other.nl/',
        'https://www.voorbeeld.nl/',
        'sc-domain:voorbeeld.nl',
    ];
    assert_same('sc-domain:voorbeeld.nl', ai_scan_gsc_match_property($sites, 'voorbeeld.nl'));
    assert_same(
        'https://voorbeeld.nl/',
        ai_scan_gsc_match_property(['https://voorbeeld.nl/', 'https://anders.nl/'], 'voorbeeld.nl')
    );
    assert_true(ai_scan_gsc_match_property(['https://anders.nl/'], 'voorbeeld.nl') === null);
});

test('gsc complete connect stores encrypted refresh token', static function (): void {
    [$config, $pdo, $userId] = gsc_boot_user();
    gsc_mock_http([
        'POST oauth2.googleapis.com/token' => [
            'status' => 200,
            'body' => json_encode([
                'access_token' => 'access-abc',
                'refresh_token' => 'refresh-xyz',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ],
        'GET webmasters/v3/sites' => [
            'status' => 200,
            'body' => json_encode([
                'siteEntry' => [
                    ['siteUrl' => 'sc-domain:voorbeeld.nl', 'permissionLevel' => 'siteOwner'],
                ],
            ]),
        ],
    ]);

    $result = ai_scan_gsc_complete_connect($pdo, $config, $userId, 'auth-code-1');
    assert_true($result['ok'], (string) ($result['error'] ?? ''));
    assert_same('sc-domain:voorbeeld.nl', $result['property']);

    $row = ai_scan_gsc_connection_get($pdo, $userId);
    assert_true($row !== null);
    assert_same('sc-domain:voorbeeld.nl', $row['property_uri']);
    assert_true(!str_contains((string) $row['refresh_token_enc'], 'refresh-xyz'));
    assert_same('refresh-xyz', ai_scan_gsc_decrypt($config, (string) $row['refresh_token_enc']));

    ai_scan_gsc_set_http(null);
});

test('gsc fetch analytics matches core questions via web performance', static function (): void {
    [$config, $pdo, $userId] = gsc_boot_user();

    ai_scan_gsc_connection_save(
        $pdo,
        $config,
        $userId,
        'sc-domain:voorbeeld.nl',
        'refresh-xyz',
        'access-abc',
        7200,
        null,
        time()
    );

    $seenType = null;
    ai_scan_gsc_set_http(static function (string $method, string $url, array $opts) use (&$seenType): array {
        $body = (string) ($opts['body'] ?? '');
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $seenType = $decoded['type'] ?? null;
        }
        if (str_contains($body, 'GENERATIVE_AI') || str_contains($body, 'searchAppearance')) {
            throw new RuntimeException('must not call Generative AI endpoints');
        }
        if (!str_contains($url, 'searchAnalytics/query')) {
            throw new RuntimeException('unexpected ' . $url);
        }

        return [
            'status' => 200,
            'body' => json_encode([
                'rows' => [
                    [
                        'keys' => ['beste plinten laten plaatsen haarlem'],
                        'clicks' => 12,
                        'impressions' => 200,
                        'ctr' => 0.06,
                        'position' => 4.2,
                    ],
                    [
                        'keys' => ['goedkope sneakers'],
                        'clicks' => 99,
                        'impressions' => 999,
                        'ctr' => 0.1,
                        'position' => 1.1,
                    ],
                ],
            ]),
            'headers' => [],
        ];
    });

    $questions = ['Beste plinten laten plaatsen Haarlem'];
    $fetch = ai_scan_gsc_fetch_analytics($pdo, $config, $userId, $questions, time());
    assert_true($fetch['ok']);
    assert_same('web', $seenType);
    assert_same(1, count($fetch['queries']));
    assert_same('beste plinten laten plaatsen haarlem', ai_scan_gsc_normalize_query($fetch['queries'][0]['query']));
    assert_same(12, $fetch['queries'][0]['clicks']);
    assert_same(200, $fetch['queries'][0]['impressions']);
    assert_true(!array_key_exists('ai', $fetch));

    $stored = $pdo->query('SELECT COUNT(*) FROM gsc_query_stats')->fetchColumn();
    assert_same(1, (int) $stored);

    ai_scan_gsc_set_http(null);
});

test('gsc disconnect revokes and deletes token', static function (): void {
    [$config, $pdo, $userId] = gsc_boot_user();
    ai_scan_gsc_connection_save(
        $pdo,
        $config,
        $userId,
        'sc-domain:voorbeeld.nl',
        'refresh-to-revoke',
        'access-abc',
        3600
    );

    $revokedToken = null;
    ai_scan_gsc_set_http(static function (string $method, string $url, array $opts) use (&$revokedToken): array {
        if (str_contains($url, 'oauth2.googleapis.com/revoke')) {
            parse_str((string) ($opts['body'] ?? ''), $parsed);
            $revokedToken = (string) ($parsed['token'] ?? '');

            return ['status' => 200, 'body' => '', 'headers' => []];
        }
        throw new RuntimeException('unexpected ' . $url);
    });

    $result = ai_scan_gsc_disconnect($pdo, $config, $userId);
    assert_true($result['ok']);
    assert_true($result['revoked']);
    assert_same('refresh-to-revoke', $revokedToken);
    assert_true(ai_scan_gsc_connection_get($pdo, $userId) === null);

    ai_scan_gsc_set_http(null);
});

test('gsc free tier is read-only in settings view', static function (): void {
    [$config, $pdo, $userId] = gsc_boot_user('free', 'none');
    $user = $pdo->query('SELECT * FROM users WHERE id = ' . $userId)->fetch();
    assert_true(is_array($user));
    $view = ai_scan_gsc_settings_view($pdo, $config, $user);
    assert_true($view['read_only']);
    assert_same(AI_SCAN_GSC_FREE_MESSAGE, $view['free_message']);
    assert_true(!$view['connected']);
    assert_true(!array_key_exists('ai_upload_action', $view));
    assert_true(!array_key_exists('ai_metric_label', $view));
});

test('gsc settings view for paid disconnected', static function (): void {
    [$config, $pdo, $userId] = gsc_boot_user();
    $user = $pdo->query('SELECT * FROM users WHERE id = ' . $userId)->fetch();
    $view = ai_scan_gsc_settings_view($pdo, $config, $user);
    assert_true(!$view['read_only']);
    assert_true(!$view['connected']);
    assert_true($view['configured']);
    assert_same('/app/gsc/connect.php', $view['connect_action']);
    assert_same('/app/gsc/disconnect.php', $view['disconnect_action']);
});

test('gsc auth url contains readonly scope and state', static function (): void {
    $config = gsc_test_config(':memory:');
    $url = ai_scan_gsc_auth_url($config, 'state-token-abc');
    assert_true(str_starts_with($url, 'https://accounts.google.com/o/oauth2/v2/auth?'));
    assert_true(str_contains($url, rawurlencode(AI_SCAN_GSC_SCOPE)) || str_contains($url, 'webmasters.readonly'));
    assert_true(str_contains($url, 'state=state-token-abc'));
    assert_true(str_contains($url, 'access_type=offline'));
    assert_true(!str_contains($url, 'evil'));
});

test('gsc example config documents gsc keys without secrets', static function (): void {
    $path = dirname(__DIR__) . '/config-examples/ai-scan-config.php';
    $raw = (string) file_get_contents($path);
    assert_true(str_contains($raw, "'gsc_client_id'"));
    assert_true(str_contains($raw, "'gsc_client_secret'"));
    assert_true(str_contains($raw, "'gsc_redirect_uri'"));
    assert_true(str_contains($raw, 'CHANGE_ME_GSC_CLIENT_ID'));
    assert_true(!str_contains($raw, 'ya29.'));
    assert_true(!str_contains($raw, 'GOCSPX-'));
});

test('gsc has no generative AI csv or metric surface', static function (): void {
    $root = dirname(__DIR__);
    assert_true(!is_file($root . '/lib/gsc_ai_csv.php'));
    assert_true(!is_file($root . '/lib/gsc_ai_source.php'));
    assert_true(!is_file($root . '/app/gsc/ai-upload.php'));
    assert_true(!is_file($root . '/admin/gsc-ai-import.php'));
    assert_true(!is_dir($root . '/tests/fixtures/gsc'));

    $lib = (string) file_get_contents($root . '/lib/gsc.php');
    assert_true(!str_contains($lib, 'Google AI-vertoningen'));
    assert_true(!str_contains($lib, 'gsc_ai_'));
    assert_true(!str_contains($lib, 'GENERATIVE_AI'));

    $card = (string) file_get_contents($root . '/app/gsc/settings-card.php');
    assert_true(!str_contains($card, 'Generatieve AI'));
    assert_true(!str_contains($card, 'ai-upload'));
    assert_true(!str_contains($card, 'Upload'));
});
