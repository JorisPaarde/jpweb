<?php

declare(strict_types=1);

/**
 * Afscherming van /wie-ik-ben/: pincode-sessie, rate limit en foto-allowlist.
 *
 * Geen pincode of hash in de repo: de bcrypt-hash staat alleen op de server in
 * $HOME/private/flappen-config.php (return ['pin_hash' => '...']).
 * Foto's en manifesten staan in $HOME/private/flappen/ (buiten de webroot).
 * Dit bestand is een include; direct opvragen geeft 404 (.htaccess + check hieronder).
 */

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

const WIB_SESSION_NAME = 'jpwc_wib';
const WIB_COOKIE_PATH = '/wie-ik-ben/';
const WIB_SESSION_TTL = 43200; // 12 uur
const WIB_IP_MAX_FAILS = 5;
const WIB_IP_WINDOW = 900; // 15 minuten
const WIB_IP_LOCK = 900; // 15 minuten
const WIB_GLOBAL_MAX_FAILS = 40;
const WIB_GLOBAL_WINDOW = 3600; // 1 uur
const WIB_ROBOTS = 'noindex, nofollow, noarchive, nosnippet, noimageindex';
const WIB_PHOTO_SETS = ['dag-1-3', 'dag-7'];
const WIB_BLOEM_FILES = ['bloem-1200.webp' => 'image/webp', 'bloem-1200.jpg' => 'image/jpeg'];

/** $HOME/private van dit cPanel-account (test en productie delen dezelfde $HOME). */
function wib_private_dir(): string
{
    $override = $GLOBALS['wib_private_dir_override'] ?? null;
    if (is_string($override) && $override !== '') {
        return rtrim($override, '/');
    }

    // wie-ik-ben/ -> webroot -> $HOME
    return dirname(__DIR__, 2) . '/private';
}

function wib_flappen_dir(): string
{
    return wib_private_dir() . '/flappen';
}

/** @return array{pin_hash: string}|null */
function wib_config(): ?array
{
    $path = wib_private_dir() . '/flappen-config.php';
    if (!is_file($path)) {
        return null;
    }
    $config = require $path;
    if (!is_array($config) || !is_string($config['pin_hash'] ?? null) || $config['pin_hash'] === '') {
        return null;
    }

    return ['pin_hash' => $config['pin_hash']];
}

/** Headers op elke response van index.php en foto.php. */
function wib_send_common_headers(): void
{
    header('X-Robots-Tag: ' . WIB_ROBOTS);
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
}

function wib_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    // Achter QUIC.cloud is alleen het laatste X-Forwarded-For-item betrouwbaar.
    $list = dirname(__DIR__) . '/lib/quic_cloud_ips.php';
    if (is_file($list)) {
        $nodes = require $list;
        if (is_array($nodes) && in_array($remote, $nodes, true)) {
            $items = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
            $last = trim((string) end($items));
            if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
                return $last;
            }
        }
    }

    return $remote;
}

function wib_start_session(bool $readOnly = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = wib_flappen_dir() . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) WIB_SESSION_TTL);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_cache_limiter('');
    session_name(WIB_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => WIB_SESSION_TTL,
        'path' => WIB_COOKIE_PATH,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if ($readOnly) {
        // Alleen lezen: geen nieuwe sessie of cookie en geen sessielock bij 35 parallelle foto's.
        if (!isset($_COOKIE[WIB_SESSION_NAME]) || !is_string($_COOKIE[WIB_SESSION_NAME])) {
            $_SESSION = [];

            return;
        }
        session_start(['read_and_close' => true]);

        return;
    }
    session_start();
}

/** @param array<string, mixed> $session */
function wib_session_is_authed(array $session, ?int $now = null): bool
{
    $now ??= time();
    $at = $session['wib_auth_at'] ?? null;

    return ($session['wib_auth'] ?? false) === true && is_int($at) && $at <= $now && $now - $at < WIB_SESSION_TTL;
}

function wib_csrf_token(): string
{
    if (!is_string($_SESSION['wib_csrf'] ?? null) || strlen($_SESSION['wib_csrf']) !== 64) {
        $_SESSION['wib_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['wib_csrf'];
}

function wib_csrf_valid(mixed $token): bool
{
    $expected = $_SESSION['wib_csrf'] ?? null;

    return is_string($expected) && is_string($token) && $expected !== '' && hash_equals($expected, $token);
}

/* ---------- Rate limit (bestanden, geen database) ---------- */

function wib_ratelimit_dir(): string
{
    return wib_flappen_dir() . '/ratelimit';
}

/** Bestandsnaam voor een IP: HMAC, nooit het ruwe adres. */
function wib_ip_key(string $ip): string
{
    $config = wib_config();
    $salt = $config['pin_hash'] ?? 'wib';

    return 'ip-' . substr(hash_hmac('sha256', $ip, $salt), 0, 40);
}

/**
 * Leest en schrijft één JSON-bestand onder een exclusieve lock.
 *
 * @param callable(array<string, mixed>): array<string, mixed> $fn
 * @return array<string, mixed>
 */
function wib_ratelimit_update(string $name, callable $fn): array
{
    $dir = wib_ratelimit_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $path = $dir . '/' . $name . '.json';
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        // Fail closed: zonder rate-limitbestand geen pogingen.
        return ['fails' => [], 'lock_until' => PHP_INT_MAX];
    }
    @chmod($path, 0600);
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    $data = $fn($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string) json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $data;
}

/** @return array<string, mixed> */
function wib_ratelimit_read(string $name): array
{
    $path = wib_ratelimit_dir() . '/' . $name . '.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents($path), true);

    return is_array($data) ? $data : [];
}

/** @param array<string, mixed> $data @return list<int> */
function wib_recent_fails(array $data, int $now, int $window): array
{
    $fails = [];
    foreach ((array) ($data['fails'] ?? []) as $t) {
        if (is_int($t) && $t > $now - $window && $t <= $now) {
            $fails[] = $t;
        }
    }

    return $fails;
}

/** Aantal seconden dat dit IP (of iedereen) nog geblokkeerd is; 0 = niet geblokkeerd. */
function wib_lock_seconds(string $ip, ?int $now = null): int
{
    $now ??= time();
    $ipData = wib_ratelimit_read(wib_ip_key($ip));
    $wait = 0;
    $until = (int) ($ipData['lock_until'] ?? 0);
    if ($until > $now) {
        $wait = $until - $now;
    }
    $global = wib_recent_fails(wib_ratelimit_read('global'), $now, WIB_GLOBAL_WINDOW);
    if (count($global) >= WIB_GLOBAL_MAX_FAILS) {
        sort($global);
        $wait = max($wait, $global[count($global) - WIB_GLOBAL_MAX_FAILS] + WIB_GLOBAL_WINDOW - $now);
    }

    return $wait;
}

/** Registreert een foute poging; geeft de lock-tijd terug (0 = nog niet geblokkeerd). */
function wib_register_fail(string $ip, ?int $now = null): int
{
    $now ??= time();
    wib_ratelimit_update(wib_ip_key($ip), static function (array $data) use ($now): array {
        $fails = wib_recent_fails($data, $now, WIB_IP_WINDOW);
        $fails[] = $now;
        $until = (int) ($data['lock_until'] ?? 0);
        if (count($fails) >= WIB_IP_MAX_FAILS) {
            $until = $now + WIB_IP_LOCK;
            $fails = [];
        }

        return ['fails' => $fails, 'lock_until' => $until];
    });
    wib_ratelimit_update('global', static function (array $data) use ($now): array {
        $fails = wib_recent_fails($data, $now, WIB_GLOBAL_WINDOW);
        $fails[] = $now;

        return ['fails' => array_slice($fails, -200)];
    });

    return wib_lock_seconds($ip, $now);
}

function wib_clear_ip(string $ip): void
{
    @unlink(wib_ratelimit_dir() . '/' . wib_ip_key($ip) . '.json');
}

/**
 * Controleert een pincodepoging.
 *
 * @return array{status: 'ok'|'wrong'|'locked'|'unavailable', retry_after: int}
 */
function wib_check_pin(string $ip, mixed $pin, ?int $now = null): array
{
    $now ??= time();
    $wait = wib_lock_seconds($ip, $now);
    if ($wait > 0) {
        return ['status' => 'locked', 'retry_after' => $wait];
    }
    $config = wib_config();
    $rl = wib_ratelimit_dir();
    if (!is_dir($rl)) {
        @mkdir($rl, 0700, true);
    }
    if ($config === null || !is_dir($rl) || !is_writable($rl)) {
        // Fail closed: zonder hash of zonder rate limit geen pogingen.
        return ['status' => 'unavailable', 'retry_after' => 0];
    }
    if (is_string($pin) && preg_match('/^\d{4,12}$/', $pin) === 1 && password_verify($pin, $config['pin_hash'])) {
        wib_clear_ip($ip);

        return ['status' => 'ok', 'retry_after' => 0];
    }
    $wait = wib_register_fail($ip, $now);

    return $wait > 0 ? ['status' => 'locked', 'retry_after' => $wait] : ['status' => 'wrong', 'retry_after' => 0];
}

/* ---------- Foto's ---------- */

/** @return list<array{file: string, caption: string, width: int, height: int}> */
function wib_manifest(string $set): array
{
    if (!in_array($set, WIB_PHOTO_SETS, true)) {
        return [];
    }
    $path = wib_flappen_dir() . '/' . $set . '/manifest.json';
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $row) {
        $file = $row['file'] ?? null;
        if (!is_string($file) || preg_match('/^[a-z0-9][a-z0-9-]*\.webp$/', $file) !== 1) {
            continue;
        }
        $out[] = [
            'file' => $file,
            'caption' => is_string($row['caption'] ?? null) ? $row['caption'] : '',
            'width' => max(1, (int) ($row['width'] ?? 0)),
            'height' => max(1, (int) ($row['height'] ?? 0)),
        ];
    }

    return $out;
}

/**
 * Lost een fotoverzoek op naar een pad + content-type, alleen binnen de allowlist.
 *
 * @return array{path: string, type: string}|null
 */
function wib_resolve_photo(mixed $set, mixed $file): ?array
{
    if (!is_string($set) || !is_string($file)) {
        return null;
    }
    if ($set === 'bloem') {
        if (!array_key_exists($file, WIB_BLOEM_FILES)) {
            return null;
        }
        $path = wib_flappen_dir() . '/bloem/' . $file;

        return is_file($path) ? ['path' => $path, 'type' => WIB_BLOEM_FILES[$file]] : null;
    }
    foreach (wib_manifest($set) as $row) {
        if (hash_equals($row['file'], $file)) {
            $path = wib_flappen_dir() . '/' . $set . '/' . $row['file'];

            return is_file($path) ? ['path' => $path, 'type' => 'image/webp'] : null;
        }
    }

    return null;
}

function wib_photo_url(string $set, string $file): string
{
    return 'foto.php?set=' . rawurlencode($set) . '&amp;f=' . rawurlencode($file);
}

function wib_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
