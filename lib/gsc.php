<?php

declare(strict_types=1);

/**
 * Google Search Console integration (second measurement source).
 * OAuth read-only scope; refresh tokens encrypted with app_key.
 */

require_once __DIR__ . '/gsc_migrate.php';

const AI_SCAN_GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
const AI_SCAN_GSC_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const AI_SCAN_GSC_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const AI_SCAN_GSC_REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
const AI_SCAN_GSC_SITES_URL = 'https://www.googleapis.com/webmasters/v3/sites';
const AI_SCAN_GSC_ANALYTICS_URL = 'https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';

const AI_SCAN_GSC_FREE_MESSAGE = 'Zit in het abonnement: elke week een nieuwe meting.';
const AI_SCAN_GSC_STATE_TTL = 600;

/** @var callable|null */
$GLOBALS['ai_scan_gsc_http'] = null;

/**
 * @param callable|null $fn function(string $method, string $url, array $opts): array{status:int, body:string, headers?:array}
 */
function ai_scan_gsc_set_http(?callable $fn): void
{
    $GLOBALS['ai_scan_gsc_http'] = $fn;
}

function ai_scan_gsc_ensure_schema(PDO $pdo): void
{
    ai_scan_gsc_migrate($pdo);
}

/**
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_configured(array $config): bool
{
    $id = trim((string) ($config['gsc_client_id'] ?? ''));
    $secret = trim((string) ($config['gsc_client_secret'] ?? ''));
    $redirect = trim((string) ($config['gsc_redirect_uri'] ?? ''));

    return $id !== ''
        && $secret !== ''
        && $redirect !== ''
        && $id !== 'CHANGE_ME_GSC_CLIENT_ID'
        && $secret !== 'CHANGE_ME_GSC_CLIENT_SECRET';
}

/**
 * Absolute redirect URI from config only — never from request params (no open redirect).
 *
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_redirect_uri(array $config): string
{
    $uri = trim((string) ($config['gsc_redirect_uri'] ?? ''));
    if ($uri === '') {
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        $uri = $base . '/app/gsc/callback.php';
    }

    return $uri;
}

/**
 * Encrypt a secret with AES-256-GCM; key = SHA-256(app_key).
 *
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_encrypt(array $config, string $plaintext): string
{
    $key = ai_scan_gsc_app_key_bytes($config);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false || $tag === '') {
        throw new RuntimeException('GSC encrypt failed.');
    }

    return base64_encode($iv . $tag . $cipher);
}

/**
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_decrypt(array $config, string $payload): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('GSC decrypt payload invalid.');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = ai_scan_gsc_app_key_bytes($config);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('GSC decrypt failed.');
    }

    return $plain;
}

/**
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_app_key_bytes(array $config): string
{
    $appKey = (string) ($config['app_key'] ?? '');
    if ($appKey === '') {
        throw new RuntimeException('app_key missing for GSC encryption.');
    }

    return hash('sha256', $appKey, true);
}

/**
 * Normalize text for exact match (no regex for meaning).
 */
function ai_scan_gsc_normalize_query(string $text): string
{
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $text = str_replace(["\r\n", "\r", "\t"], ' ', $text);
    while (str_contains($text, '  ')) {
        $text = str_replace('  ', ' ', $text);
    }

    return $text;
}

/**
 * @param array<string, mixed> $config
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function ai_scan_gsc_http(string $method, string $url, array $opts = []): array
{
    $fn = $GLOBALS['ai_scan_gsc_http'] ?? null;
    if (is_callable($fn)) {
        $result = $fn($method, $url, $opts);
        if (!is_array($result) || !isset($result['status'], $result['body'])) {
            throw new RuntimeException('GSC HTTP mock returned invalid shape.');
        }

        return [
            'status' => (int) $result['status'],
            'body' => (string) $result['body'],
            'headers' => is_array($result['headers'] ?? null) ? $result['headers'] : [],
        ];
    }

    $headers = [];
    foreach (($opts['headers'] ?? []) as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $headers[] = $name . ': ' . $value;
        }
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed.');
    }

    $method = strtoupper($method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if (isset($opts['body']) && is_string($opts['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    }

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('GSC HTTP error: ' . $err);
    }

    return ['status' => $status, 'body' => (string) $body, 'headers' => []];
}

/**
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_oauth_state_create(array $config, int $userId): string
{
    ai_scan_start_session($config);
    $state = ai_scan_random_token(32);
    $_SESSION['gsc_oauth_state'] = [
        'state' => $state,
        'user_id' => $userId,
        'created_at' => time(),
    ];

    return $state;
}

/**
 * @param array<string, mixed> $config
 * @return array{ok: bool, user_id?: int, error?: string}
 */
function ai_scan_gsc_oauth_state_consume(array $config, string $state): array
{
    ai_scan_start_session($config);
    $stored = $_SESSION['gsc_oauth_state'] ?? null;
    unset($_SESSION['gsc_oauth_state']);

    if (
        !is_array($stored)
        || !isset($stored['state'], $stored['user_id'], $stored['created_at'])
        || !is_string($stored['state'])
        || !is_int($stored['user_id'])
        || !is_int($stored['created_at'])
    ) {
        return ['ok' => false, 'error' => 'missing_state'];
    }

    if ((time() - $stored['created_at']) > AI_SCAN_GSC_STATE_TTL) {
        return ['ok' => false, 'error' => 'expired_state'];
    }

    if (!hash_equals($stored['state'], $state)) {
        return ['ok' => false, 'error' => 'bad_state'];
    }

    return ['ok' => true, 'user_id' => $stored['user_id']];
}

/**
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_auth_url(array $config, string $state): string
{
    $params = [
        'client_id' => (string) $config['gsc_client_id'],
        'redirect_uri' => ai_scan_gsc_redirect_uri($config),
        'response_type' => 'code',
        'scope' => AI_SCAN_GSC_SCOPE,
        'access_type' => 'offline',
        'include_granted_scopes' => 'true',
        'prompt' => 'consent',
        'state' => $state,
    ];

    return AI_SCAN_GSC_AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * @param array<string, mixed> $config
 * @return array{ok: bool, access_token?: string, refresh_token?: string, expires_in?: int, error?: string}
 */
function ai_scan_gsc_exchange_code(array $config, string $code): array
{
    $body = http_build_query([
        'code' => $code,
        'client_id' => (string) $config['gsc_client_id'],
        'client_secret' => (string) $config['gsc_client_secret'],
        'redirect_uri' => ai_scan_gsc_redirect_uri($config),
        'grant_type' => 'authorization_code',
    ], '', '&');

    $res = ai_scan_gsc_http('POST', AI_SCAN_GSC_TOKEN_URL, [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
        'body' => $body,
    ]);

    $data = json_decode($res['body'], true);
    if ($res['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
        return ['ok' => false, 'error' => 'token_exchange_failed'];
    }

    return [
        'ok' => true,
        'access_token' => (string) $data['access_token'],
        'refresh_token' => isset($data['refresh_token']) ? (string) $data['refresh_token'] : '',
        'expires_in' => (int) ($data['expires_in'] ?? 3600),
    ];
}

/**
 * @param array<string, mixed> $config
 * @return array{ok: bool, access_token?: string, expires_in?: int, error?: string}
 */
function ai_scan_gsc_refresh_access_token(array $config, string $refreshToken): array
{
    $body = http_build_query([
        'client_id' => (string) $config['gsc_client_id'],
        'client_secret' => (string) $config['gsc_client_secret'],
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ], '', '&');

    $res = ai_scan_gsc_http('POST', AI_SCAN_GSC_TOKEN_URL, [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
        'body' => $body,
    ]);

    $data = json_decode($res['body'], true);
    if ($res['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
        return ['ok' => false, 'error' => 'refresh_failed'];
    }

    return [
        'ok' => true,
        'access_token' => (string) $data['access_token'],
        'expires_in' => (int) ($data['expires_in'] ?? 3600),
    ];
}

/**
 * Best-effort revoke; always treat local delete as authoritative.
 *
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_revoke_token(array $config, string $token): bool
{
    if ($token === '') {
        return true;
    }

    try {
        $res = ai_scan_gsc_http('POST', AI_SCAN_GSC_REVOKE_URL, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query(['token' => $token], '', '&'),
        ]);

        return $res['status'] === 200 || $res['status'] === 400;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<string>
 */
function ai_scan_gsc_list_sites(string $accessToken): array
{
    $res = ai_scan_gsc_http('GET', AI_SCAN_GSC_SITES_URL, [
        'headers' => [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ],
    ]);
    $data = json_decode($res['body'], true);
    if ($res['status'] !== 200 || !is_array($data)) {
        return [];
    }

    $sites = [];
    foreach (($data['siteEntry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $url = (string) ($entry['siteUrl'] ?? '');
        if ($url !== '') {
            $sites[] = $url;
        }
    }

    return $sites;
}

/**
 * Pick the GSC property that matches the account domain.
 *
 * @param list<string> $siteUrls
 */
function ai_scan_gsc_match_property(array $siteUrls, string $domain): ?string
{
    $domain = strtolower(trim($domain));
    if ($domain === '' || str_starts_with($domain, '.')) {
        return null;
    }

    $candidates = [];
    foreach ($siteUrls as $url) {
        $url = trim($url);
        if ($url === '') {
            continue;
        }
        $score = 0;
        $lower = strtolower($url);
        if ($lower === 'sc-domain:' . $domain) {
            $score = 100;
        } elseif (str_starts_with($lower, 'sc-domain:') && str_ends_with($lower, '.' . $domain)) {
            $score = 40;
        } elseif (
            $lower === 'https://' . $domain . '/'
            || $lower === 'http://' . $domain . '/'
            || $lower === 'https://www.' . $domain . '/'
            || $lower === 'http://www.' . $domain . '/'
        ) {
            $score = 80;
        } elseif (str_contains($lower, '://' . $domain . '/') || str_contains($lower, '://www.' . $domain . '/')) {
            $score = 60;
        }

        if ($score > 0) {
            $candidates[] = ['url' => $url, 'score' => $score];
        }
    }

    if ($candidates === []) {
        return null;
    }

    usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

    return $candidates[0]['url'];
}

/**
 * @return array<string, mixed>|null
 */
function ai_scan_gsc_connection_get(PDO $pdo, int $userId): ?array
{
    ai_scan_gsc_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM gsc_connections WHERE user_id = :u LIMIT 1');
    $stmt->execute(['u' => $userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * @param array<string, mixed> $config
 */
function ai_scan_gsc_connection_save(
    PDO $pdo,
    array $config,
    int $userId,
    string $propertyUri,
    string $refreshToken,
    string $accessToken,
    int $expiresIn,
    ?string $googleEmail = null,
    ?int $now = null
): void {
    ai_scan_gsc_ensure_schema($pdo);
    $now ??= time();
    $stmt = $pdo->prepare(
        'INSERT INTO gsc_connections(
            user_id, property_uri, refresh_token_enc, access_token_enc, access_token_expires_at,
            connected_at, last_sync_at, last_error, google_account_email
         ) VALUES (
            :user_id, :property_uri, :refresh_token_enc, :access_token_enc, :access_token_expires_at,
            :connected_at, NULL, NULL, :google_account_email
         )
         ON CONFLICT(user_id) DO UPDATE SET
            property_uri = excluded.property_uri,
            refresh_token_enc = excluded.refresh_token_enc,
            access_token_enc = excluded.access_token_enc,
            access_token_expires_at = excluded.access_token_expires_at,
            connected_at = excluded.connected_at,
            last_error = NULL,
            google_account_email = excluded.google_account_email'
    );
    $stmt->execute([
        'user_id' => $userId,
        'property_uri' => $propertyUri,
        'refresh_token_enc' => ai_scan_gsc_encrypt($config, $refreshToken),
        'access_token_enc' => $accessToken !== '' ? ai_scan_gsc_encrypt($config, $accessToken) : null,
        'access_token_expires_at' => $now + max(60, $expiresIn) - 30,
        'connected_at' => $now,
        'google_account_email' => $googleEmail,
    ]);
}

/**
 * @param array<string, mixed> $config
 * @return array{ok: bool, revoked: bool, error?: string}
 */
function ai_scan_gsc_disconnect(PDO $pdo, array $config, int $userId): array
{
    ai_scan_gsc_ensure_schema($pdo);
    $row = ai_scan_gsc_connection_get($pdo, $userId);
    $revoked = true;
    if ($row !== null) {
        try {
            $refresh = ai_scan_gsc_decrypt($config, (string) $row['refresh_token_enc']);
            $revoked = ai_scan_gsc_revoke_token($config, $refresh);
        } catch (Throwable $e) {
            $revoked = false;
        }
    }

    $pdo->prepare('DELETE FROM gsc_query_stats WHERE user_id = :u')->execute(['u' => $userId]);
    $pdo->prepare('DELETE FROM gsc_connections WHERE user_id = :u')->execute(['u' => $userId]);

    return ['ok' => true, 'revoked' => $revoked];
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $connection
 * @return array{ok: bool, access_token?: string, error?: string}
 */
function ai_scan_gsc_access_token_for(PDO $pdo, array $config, array $connection, ?int $now = null): array
{
    $now ??= time();
    $expires = (int) ($connection['access_token_expires_at'] ?? 0);
    $encAccess = (string) ($connection['access_token_enc'] ?? '');
    if ($encAccess !== '' && $expires > ($now + 60)) {
        try {
            return ['ok' => true, 'access_token' => ai_scan_gsc_decrypt($config, $encAccess)];
        } catch (Throwable $e) {
            // fall through to refresh
        }
    }

    try {
        $refresh = ai_scan_gsc_decrypt($config, (string) $connection['refresh_token_enc']);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'decrypt_failed'];
    }

    $refreshed = ai_scan_gsc_refresh_access_token($config, $refresh);
    if (empty($refreshed['ok'])) {
        return ['ok' => false, 'error' => (string) ($refreshed['error'] ?? 'refresh_failed')];
    }

    $access = (string) $refreshed['access_token'];
    $expiresIn = (int) ($refreshed['expires_in'] ?? 3600);
    $stmt = $pdo->prepare(
        'UPDATE gsc_connections
         SET access_token_enc = :a, access_token_expires_at = :e, last_error = NULL
         WHERE user_id = :u'
    );
    $stmt->execute([
        'a' => ai_scan_gsc_encrypt($config, $access),
        'e' => $now + max(60, $expiresIn) - 30,
        'u' => (int) $connection['user_id'],
    ]);

    return ['ok' => true, 'access_token' => $access];
}

/**
 * Core questions for the account (from questions table when present).
 *
 * @return list<string>
 */
function ai_scan_gsc_core_questions(PDO $pdo, int $userId): array
{
    $exists = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'questions' LIMIT 1"
    )->fetchColumn();
    if ($exists === false) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT question FROM questions
         WHERE user_id = :u
           AND (status IS NULL OR status NOT IN ('paused', 'deleted', 'excluded'))
         ORDER BY id ASC"
    );
    try {
        $stmt->execute(['u' => $userId]);
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    while ($row = $stmt->fetch()) {
        $q = trim((string) ($row['question'] ?? ''));
        if ($q !== '') {
            $out[] = $q;
        }
    }

    return $out;
}

/**
 * Domain for the account (latest scan).
 */
function ai_scan_gsc_account_domain(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare(
        "SELECT domain FROM scans
         WHERE user_id = :u AND domain IS NOT NULL AND domain != ''
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['u' => $userId]);
    $domain = $stmt->fetchColumn();

    return is_string($domain) ? strtolower($domain) : '';
}

/**
 * Fetch web Performance (Search Analytics) for queries matching core questions.
 *
 * @param array<string, mixed> $config
 * @param list<string>|null $questions
 * @return array{
 *   ok: bool,
 *   queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>,
 *   period_start: string,
 *   period_end: string,
 *   error?: string
 * }
 */
function ai_scan_gsc_fetch_analytics(
    PDO $pdo,
    array $config,
    int $userId,
    ?array $questions = null,
    ?int $now = null
): array {
    ai_scan_gsc_ensure_schema($pdo);
    $now ??= time();
    $connection = ai_scan_gsc_connection_get($pdo, $userId);
    if ($connection === null) {
        return [
            'ok' => false,
            'queries' => [],
            'period_start' => '',
            'period_end' => '',
            'error' => 'not_connected',
        ];
    }

    $tokenRes = ai_scan_gsc_access_token_for($pdo, $config, $connection, $now);
    if (empty($tokenRes['ok'])) {
        return [
            'ok' => false,
            'queries' => [],
            'period_start' => '',
            'period_end' => '',
            'error' => (string) ($tokenRes['error'] ?? 'token_error'),
        ];
    }

    $access = (string) $tokenRes['access_token'];
    $property = (string) $connection['property_uri'];
    $tz = new DateTimeZone('Europe/Amsterdam');
    $end = (new DateTimeImmutable('yesterday', $tz));
    $start = $end->modify('-27 days');
    $periodStart = $start->format('Y-m-d');
    $periodEnd = $end->format('Y-m-d');

    $questions ??= ai_scan_gsc_core_questions($pdo, $userId);
    $wanted = [];
    foreach ($questions as $q) {
        $n = ai_scan_gsc_normalize_query($q);
        if ($n !== '') {
            $wanted[$n] = $q;
        }
    }

    $analyticsUrl = sprintf(
        AI_SCAN_GSC_ANALYTICS_URL,
        rawurlencode($property)
    );

    $payload = [
        'startDate' => $periodStart,
        'endDate' => $periodEnd,
        'dimensions' => ['query'],
        'type' => 'web',
        'rowLimit' => 25000,
        'dataState' => 'final',
    ];

    $res = ai_scan_gsc_http('POST', $analyticsUrl, [
        'headers' => [
            'Authorization' => 'Bearer ' . $access,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $matched = [];
    if ($res['status'] === 200) {
        $data = json_decode($res['body'], true);
        $rows = is_array($data) ? ($data['rows'] ?? []) : [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $keys = $row['keys'] ?? [];
                $query = is_array($keys) ? (string) ($keys[0] ?? '') : '';
                $norm = ai_scan_gsc_normalize_query($query);
                if ($wanted !== [] && !isset($wanted[$norm])) {
                    continue;
                }
                $matched[] = [
                    'query' => $query,
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => (float) ($row['ctr'] ?? 0),
                    'position' => (float) ($row['position'] ?? 0),
                ];
            }
        }
        ai_scan_gsc_store_query_stats($pdo, $userId, $matched, $periodStart, $periodEnd, 'search_analytics', $now);
        $pdo->prepare(
            'UPDATE gsc_connections SET last_sync_at = :t, last_error = NULL WHERE user_id = :u'
        )->execute(['t' => $now, 'u' => $userId]);
    } else {
        $pdo->prepare(
            'UPDATE gsc_connections SET last_error = :e WHERE user_id = :u'
        )->execute(['e' => 'analytics_http_' . $res['status'], 'u' => $userId]);

        return [
            'ok' => false,
            'queries' => [],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'error' => 'analytics_failed',
        ];
    }

    return [
        'ok' => true,
        'queries' => $matched,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ];
}

/**
 * @param list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}> $rows
 */
function ai_scan_gsc_store_query_stats(
    PDO $pdo,
    int $userId,
    array $rows,
    string $periodStart,
    string $periodEnd,
    string $source,
    int $now
): void {
    $pdo->prepare(
        'DELETE FROM gsc_query_stats
         WHERE user_id = :u AND period_start = :ps AND period_end = :pe AND source = :s'
    )->execute([
        'u' => $userId,
        'ps' => $periodStart,
        'pe' => $periodEnd,
        's' => $source,
    ]);

    $ins = $pdo->prepare(
        'INSERT INTO gsc_query_stats(
            user_id, query, clicks, impressions, ctr, position, period_start, period_end, source, fetched_at
         ) VALUES (
            :user_id, :query, :clicks, :impressions, :ctr, :position, :period_start, :period_end, :source, :fetched_at
         )'
    );

    foreach ($rows as $row) {
        $ins->execute([
            'user_id' => $userId,
            'query' => (string) $row['query'],
            'clicks' => (int) $row['clicks'],
            'impressions' => (int) $row['impressions'],
            'ctr' => (float) $row['ctr'],
            'position' => (float) $row['position'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'source' => $source,
            'fetched_at' => $now,
        ]);
    }
}

/**
 * Complete OAuth connect after callback.
 *
 * @param array<string, mixed> $config
 * @return array{ok: bool, property?: string, error?: string}
 */
function ai_scan_gsc_complete_connect(
    PDO $pdo,
    array $config,
    int $userId,
    string $code
): array {
    if (!ai_scan_gsc_configured($config)) {
        return ['ok' => false, 'error' => 'not_configured'];
    }

    $tokens = ai_scan_gsc_exchange_code($config, $code);
    if (empty($tokens['ok'])) {
        return ['ok' => false, 'error' => (string) ($tokens['error'] ?? 'token_exchange_failed')];
    }

    $access = (string) $tokens['access_token'];
    $refresh = (string) ($tokens['refresh_token'] ?? '');
    if ($refresh === '') {
        // Re-auth without new refresh: keep existing if present.
        $existing = ai_scan_gsc_connection_get($pdo, $userId);
        if ($existing !== null) {
            try {
                $refresh = ai_scan_gsc_decrypt($config, (string) $existing['refresh_token_enc']);
            } catch (Throwable $e) {
                $refresh = '';
            }
        }
    }
    if ($refresh === '') {
        return ['ok' => false, 'error' => 'missing_refresh_token'];
    }

    $domain = ai_scan_gsc_account_domain($pdo, $userId);
    $sites = ai_scan_gsc_list_sites($access);
    $property = ai_scan_gsc_match_property($sites, $domain);
    if ($property === null) {
        return ['ok' => false, 'error' => 'no_matching_property'];
    }

    ai_scan_gsc_connection_save(
        $pdo,
        $config,
        $userId,
        $property,
        $refresh,
        $access,
        (int) ($tokens['expires_in'] ?? 3600)
    );

    return ['ok' => true, 'property' => $property];
}

/**
 * Safe internal redirect path (no open redirect).
 */
function ai_scan_gsc_safe_next(string $next, string $fallback = '/app/gsc/preview.php'): string
{
    if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\x00-\x1F\x7F]/', $next) === 1) {
        return $fallback;
    }
    if (str_contains($next, '://')) {
        return $fallback;
    }

    return $next;
}

/**
 * View-model for the Instellingen > Bedrijf settings card.
 *
 * @param array<string, mixed> $config
 * @param array<string, mixed> $user
 * @return array{
 *   connected: bool,
 *   read_only: bool,
 *   configured: bool,
 *   property_uri: string,
 *   connected_at: ?int,
 *   last_sync_at: ?int,
 *   free_message: string,
 *   csrf: string,
 *   connect_action: string,
 *   disconnect_action: string
 * }
 */
function ai_scan_gsc_settings_view(PDO $pdo, array $config, array $user): array
{
    ai_scan_gsc_ensure_schema($pdo);
    $userId = (int) ($user['id'] ?? 0);
    $conn = $userId > 0 ? ai_scan_gsc_connection_get($pdo, $userId) : null;
    $readOnly = !ai_scan_can_write($user);

    return [
        'connected' => $conn !== null,
        'read_only' => $readOnly,
        'configured' => ai_scan_gsc_configured($config),
        'property_uri' => $conn !== null ? (string) $conn['property_uri'] : '',
        'connected_at' => $conn !== null && $conn['connected_at'] !== null ? (int) $conn['connected_at'] : null,
        'last_sync_at' => $conn !== null && $conn['last_sync_at'] !== null ? (int) $conn['last_sync_at'] : null,
        'free_message' => AI_SCAN_GSC_FREE_MESSAGE,
        'csrf' => ai_scan_csrf_token($config),
        'connect_action' => '/app/gsc/connect.php',
        'disconnect_action' => '/app/gsc/disconnect.php',
    ];
}
