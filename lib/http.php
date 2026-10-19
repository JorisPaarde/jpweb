<?php

declare(strict_types=1);

function ai_scan_request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

/**
 * Folder for the refreshed QUIC.cloud list (cache) and the log markers.
 * One folder for writing (runner refresh), reading (client IP) and signalling: the folder of the
 * configured SQLite file. ai_scan_boot() sets it from the loaded config; pages without the app
 * bootstrap (contact.php) resolve it from the same config. Pass $dir to point it elsewhere (tests)
 * or $reset to rebuild the lookup.
 */
function ai_scan_quic_cloud_dir(?string $set = null, bool $reset = false): string
{
    static $dir = null;
    if ($set !== null && $set !== $dir) {
        $reset = $reset || $dir !== null; // another folder: rebuild the lookup from its cache
        $dir = $set;
    }
    $dir ??= ai_scan_quic_cloud_default_dir();
    if ($reset) {
        ai_scan_quic_cloud_nodes(true);
    }

    return $dir;
}

/** Folder of the configured SQLite file; falls back to SITE/../private when the config cannot be read. */
function ai_scan_quic_cloud_default_dir(): string
{
    try {
        require_once __DIR__ . '/config.php';
        $path = (string) (ai_scan_load_config()['sqlite_path'] ?? '');
        if ($path !== '') {
            return dirname($path);
        }
    } catch (Throwable $e) {
        // fall back below
    }

    return dirname(__DIR__) . '/../private';
}

/**
 * Valid, unique IPs from a QUIC.cloud answer (JSON list or plain text); junk is skipped.
 *
 * @return list<string>
 */
function ai_scan_quic_cloud_parse(string $raw): array
{
    $decoded = json_decode($raw, true);
    $items = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : (preg_split('/[\s,"\[\]]+/', $raw) ?: []);
    $ips = [];
    foreach ($items as $item) {
        $item = trim((string) $item);
        if ($item !== '' && filter_var($item, FILTER_VALIDATE_IP) !== false) {
            $ips[(string) inet_ntop((string) inet_pton($item))] = true;
        }
    }

    return array_keys($ips);
}

/** @return array<string, true> packed address => true */
function ai_scan_quic_cloud_nodes(bool $reset = false): array
{
    static $nodes = null;
    if ($reset) {
        $nodes = null;
    }
    if ($nodes === null) {
        $list = require __DIR__ . '/quic_cloud_ips.php';
        $cache = json_decode((string) @file_get_contents(ai_scan_quic_cloud_dir() . '/quic-cloud-ips.json'), true);
        if (is_array($cache) && is_array($cache['ips'] ?? null)) {
            $list = array_merge($list, array_values(array_filter($cache['ips'], 'is_string')));
        }
        $nodes = [];
        foreach ($list as $node) {
            $packed = @inet_pton($node);
            if ($packed !== false) {
                $nodes[$packed] = true;
            }
        }
    }

    return $nodes;
}

/** True when $ip is a known QUIC.cloud node (compared in binary form, so IPv6 spelling does not matter). */
function ai_scan_is_quic_cloud_ip(string $ip): bool
{
    $packed = @inet_pton($ip);

    return $packed !== false && isset(ai_scan_quic_cloud_nodes()[$packed]);
}

function ai_scan_quic_cloud_fetch(string $url): ?string
{
    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 5, 'user_agent' => 'JPWebcreationAIScan/1.0'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]), 0, 262144);

    return $body === false ? null : $body;
}

/**
 * Refreshes the cache at most once per 24 hours (attempts count, so a failing source is not hammered).
 * Only a list of at least 50 valid IPs is written (tmp + rename); otherwise the old cache stays.
 * Never throws. Runs from the authenticated runner, never from a public page.
 *
 * @param null|callable(string): ?string $fetcher
 */
function ai_scan_quic_cloud_refresh(?string $dir = null, ?callable $fetcher = null, ?int $now = null): bool
{
    try {
        $dir ??= ai_scan_quic_cloud_dir();
        $now ??= time();
        $fetcher ??= 'ai_scan_quic_cloud_fetch';
        $marker = $dir . '/quic-cloud-ips.checked';
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        if (is_file($marker) && $now - (int) filemtime($marker) < 86400) {
            return false;
        }
        @touch($marker, $now);

        foreach (['https://api.quic.cloud/ips?json', 'https://www.quic.cloud/ips-all?trusted='] as $url) {
            $ips = ai_scan_quic_cloud_parse((string) $fetcher($url));
            if (count($ips) < 50) {
                continue;
            }
            sort($ips, SORT_STRING);
            $tmp = $dir . '/quic-cloud-ips.json.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode(['fetched_at' => $now, 'ips' => $ips])) === false) {
                @unlink($tmp);

                return false;
            }
            if (!@rename($tmp, $dir . '/quic-cloud-ips.json')) {
                @unlink($tmp);

                return false;
            }
            ai_scan_quic_cloud_nodes(true);

            return true;
        }
    } catch (Throwable $e) {
        // keep the old list
    }

    return false;
}

/** Logs (at most once an hour) that a forwarded header came from an address that is not a known node. Header is never trusted. */
function ai_scan_quic_cloud_signal(string $remote, ?int $now = null): void
{
    $marker = ai_scan_quic_cloud_dir() . '/quic-cloud-unknown-xff.mark';
    $now ??= time();
    if (is_file($marker) && $now - (int) @filemtime($marker) < 3600) {
        return;
    }
    if (!@touch($marker, $now)) {
        return;
    }
    error_log('jpweb: X-Forwarded-For van onbekend adres ' . (filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'ongeldig') . ' genegeerd');
}

function ai_scan_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (!ai_scan_is_quic_cloud_ip($remote)) {
        if (trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) !== '') {
            ai_scan_quic_cloud_signal($remote);
        }

        return $remote;
    }

    // The CDN node appends the visitor's address itself, so only the last item is trustworthy.
    $items = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $last = trim((string) end($items));

    return filter_var($last, FILTER_VALIDATE_IP) !== false ? $last : $remote;
}

function ai_scan_request_host(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';

    return $host;
}

function ai_scan_assert_allowed_host(array $config): void
{
    $allowed = $config['allowed_hosts'] ?? [];
    if (!is_array($allowed) || $allowed === []) {
        return;
    }

    $host = ai_scan_request_host();
    if ($host === '' || !in_array($host, $allowed, true)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Bad Request';
        exit;
    }
}

function ai_scan_wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json')) {
        return true;
    }

    return (string) ($_POST['format'] ?? $_GET['format'] ?? '') === 'json';
}

/**
 * @param array<string, mixed> $payload
 */
function ai_scan_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Alleen een relatief pad binnen de eigen site is een geldige bestemming na inloggen.
 * Alles anders (schema, //host, backslash, controletekens, ook na één keer urldecode) valt terug.
 */
function ai_scan_safe_next(string $next, string $fallback = '/dashboard/'): string
{
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
        return $fallback;
    }
    $decoded = urldecode($next);
    foreach ([$next, $decoded] as $candidate) {
        if (str_contains($candidate, '\\') || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return $fallback;
        }
    }
    if (str_starts_with($decoded, '//')) {
        return $fallback;
    }

    return $next;
}

function ai_scan_redirect(string $location, int $status = 303): void
{
    // Test harness only: catch redirects under CLI/phpdbg (never on a web SAPI).
    if (
        getenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT') === '1'
        && (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg')
    ) {
        throw new RuntimeException('REDIRECT ' . $location, $status);
    }
    header('Location: ' . $location, true, $status);
    exit;
}

function ai_scan_redirect_code(string $code, string $path = '/ai-scan/'): void
{
    $sep = str_contains($path, '?') ? '&' : '?';
    ai_scan_redirect($path . $sep . 's=' . rawurlencode($code), 303);
}

/**
 * @param array<string, mixed> $extra
 */
function ai_scan_respond_code(string $code, bool $asJson = false, array $extra = []): void
{
    if ($code === 'rate_limited') {
        $retryAfter = (int) ($extra['retry_after'] ?? 60);
        header('Retry-After: ' . max(1, $retryAfter));
        if ($asJson) {
            ai_scan_json_response([
                'ok' => false,
                'code' => 'rate_limited',
                'retry_after' => $retryAfter,
                'busy' => !empty($extra['busy']),
            ], 429);
        }

        // 303 zodat ook een bezoeker zonder JS de statuspagina ziet (een Location bij 429 wordt niet gevolgd).
        ai_scan_redirect(
            '/ai-scan/?s=rate_limited&w=' . ai_scan_wait_bucket($retryAfter) . (!empty($extra['busy']) ? '&b=1' : ''),
            303
        );
        exit;
    }

    if ($asJson) {
        ai_scan_json_response([
            'ok' => in_array($code, ['check_mail', 'confirmed'], true),
            'code' => $code,
        ]);
    }

    ai_scan_redirect_code($code);
}

function ai_scan_clean_input(string $key, int $maxLength, ?array $source = null): string
{
    $source ??= $_POST;
    $value = trim((string) ($source[$key] ?? ''));
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function ai_scan_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, max-age=0');
}

/**
 * Wachttijd in klanttaal ("over 10 minuten"); lege string als de wachttijd onbekend is.
 */
/**
 * Rondt een wachttijd naar boven af op een vaste waarde, zodat de statuspagina alleen bekende waarden toont.
 */
function ai_scan_wait_bucket(int $seconds): int
{
    foreach ([60, 300, 600, 900, 1800, 3600] as $bucket) {
        if ($seconds <= $bucket) {
            return $bucket;
        }
    }

    return min(24, (int) ceil($seconds / 3600)) * 3600;
}

/**
 * Alleen een waarde die ai_scan_wait_bucket zelf kan geven; alles anders telt als onbekend (0).
 */
function ai_scan_wait_known(int $seconds): int
{
    return $seconds > 0 && ai_scan_wait_bucket($seconds) === $seconds ? $seconds : 0;
}

function ai_scan_wait_phrase(int $seconds): string
{
    if ($seconds <= 0) {
        return '';
    }
    if ($seconds <= 90) {
        return 'over een minuut';
    }
    if ($seconds < 3600) {
        return 'over ' . (int) ceil($seconds / 60) . ' minuten';
    }

    $hours = (int) ceil($seconds / 3600);

    return $hours <= 1 ? 'over een uur' : "over {$hours} uur";
}

/**
 * Korte Nederlandse foutpagina met een weg terug; stopt het script.
 */
function ai_scan_error_page(int $status, string $title, string $text, string $href, string $label): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . $h($title) . ' · JP Web Creation</title>'
        . '<link rel="stylesheet" href="/styles.css?v=ai-scan-1"></head>'
        . '<body><main style="max-width:32rem;margin:4rem auto;padding:0 1.25rem">'
        . '<h1>' . $h($title) . '</h1><p>' . $h($text) . '</p>'
        . '<p><a href="' . $h($href) . '">' . $h($label) . '</a></p></main></body></html>';
    exit;
}

/**
 * 405 voor een adres dat alleen via een formulier werkt (POST).
 */
function ai_scan_post_only_page(): void
{
    header('Allow: POST');
    ai_scan_error_page(
        405,
        'Dit adres werkt alleen vanuit een knop',
        'Ik kan deze pagina niet openen door het adres in te typen. Ga terug naar je account en gebruik daar de knop. Lukt het dan niet, mail me op info@jpwebcreation.nl.',
        '/app/',
        'Terug naar mijn account'
    );
}

/** Ruimt contact-limietbestanden op die ouder zijn dan $maxAge seconden. */
function contact_rate_cleanup(string $dir, int $maxAge): void
{
    foreach (glob($dir . '/jpweb-contact-*') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && (time() - $mtime) > $maxAge && is_file($file)) {
            @unlink($file);
        }
    }
}
