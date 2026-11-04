<?php

declare(strict_types=1);

/**
 * Operator-box OAuth parking (read-only GSC code hand-off).
 *
 * Security: the parked authorization code is useless without BOTH the OAuth
 * client_secret AND the PKCE code_verifier. Those stay only on the operator
 * box. The `state` value (box- + 43–128 url-safe chars ≈ 256-bit) is a
 * one-time bearer secret known only to the box and the person who clicks
 * through Google. This server never exchanges the code and never sees a
 * refresh token for this flow. Do not log `code` or `state`.
 */

const AI_SCAN_GSC_BOX_STATE_REGEX = '/^box-[A-Za-z0-9_-]{43,128}$/';
const AI_SCAN_GSC_BOX_TTL = 600;
const AI_SCAN_GSC_BOX_CODE_MAX = 2048;
const AI_SCAN_GSC_BOX_ERROR_MAX = 256;
const AI_SCAN_GSC_BOX_POST_MAX_BYTES = 4096;
const AI_SCAN_GSC_BOX_RATE_MAX = 30;
const AI_SCAN_GSC_BOX_RATE_WINDOW = 60;

/**
 * Override storage dir (tests). Pass null to clear a previous override.
 */
function ai_scan_gsc_box_storage_dir(?string $override = null, bool $clear = false): string
{
    static $dir = null;
    if ($clear) {
        $dir = null;
    }
    if ($override !== null && $override !== '') {
        if (!ai_scan_gsc_box_ensure_dir($override)) {
            throw new RuntimeException('GSC box storage override unavailable.');
        }
        $dir = $override;

        return $dir;
    }
    if (is_string($dir) && $dir !== '') {
        return $dir;
    }

    $env = getenv('JPWEB_GSC_BOX_STORAGE');
    if (is_string($env) && $env !== '' && ai_scan_gsc_box_ensure_dir($env)) {
        $dir = $env;

        return $dir;
    }

    $candidates = [];
    try {
        require_once __DIR__ . '/config.php';
        $sqlite = (string) (ai_scan_load_config()['sqlite_path'] ?? '');
        if ($sqlite !== '') {
            $candidates[] = dirname($sqlite) . '/gsc-box-oauth';
        }
    } catch (Throwable $e) {
        // Prefer the fixed private / data fallbacks below.
    }

    $webroot = dirname(__DIR__);
    $candidates[] = $webroot . '/../private/gsc-box-oauth';
    $candidates[] = '/home/jpwebcreation/private/gsc-box-oauth';
    // Last resort inside the repo tree; parent data/.htaccess is deny-all.
    $candidates[] = $webroot . '/data/gsc-box-oauth';

    foreach ($candidates as $candidate) {
        if (ai_scan_gsc_box_ensure_dir($candidate)) {
            $dir = $candidate;

            return $dir;
        }
    }

    $fallback = sys_get_temp_dir() . '/jpweb-gsc-box-oauth';
    if (!ai_scan_gsc_box_ensure_dir($fallback)) {
        throw new RuntimeException('GSC box storage unavailable.');
    }
    $dir = $fallback;

    return $dir;
}

function ai_scan_gsc_box_ensure_dir(string $path): bool
{
    if ($path === '' || str_contains($path, "\0")) {
        return false;
    }
    if (!is_dir($path)) {
        if (!@mkdir($path, 0700, true) && !is_dir($path)) {
            return false;
        }
    }
    if (!is_writable($path)) {
        return false;
    }

    // Defense in depth when the dir sits under the webroot data/ tree.
    $htaccess = $path . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Deny all HTTP access to parked OAuth codes.\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n"
        );
    }

    return true;
}

function ai_scan_gsc_box_state_valid(string $state): bool
{
    return preg_match(AI_SCAN_GSC_BOX_STATE_REGEX, $state) === 1;
}

function ai_scan_gsc_box_file_path(string $state): string
{
    return ai_scan_gsc_box_storage_dir() . '/' . hash('sha256', $state);
}

/**
 * @param array{code?: string, error?: string} $payload
 */
function ai_scan_gsc_box_store(string $state, array $payload): bool
{
    if (!ai_scan_gsc_box_state_valid($state)) {
        return false;
    }

    $record = ['created_at' => time()];
    if (isset($payload['code']) && is_string($payload['code']) && $payload['code'] !== '') {
        $code = $payload['code'];
        if (strlen($code) > AI_SCAN_GSC_BOX_CODE_MAX) {
            return false;
        }
        $record['code'] = $code;
    } elseif (isset($payload['error']) && is_string($payload['error']) && $payload['error'] !== '') {
        $err = $payload['error'];
        if (strlen($err) > AI_SCAN_GSC_BOX_ERROR_MAX) {
            $err = substr($err, 0, AI_SCAN_GSC_BOX_ERROR_MAX);
        }
        $record['error'] = $err;
    } else {
        return false;
    }

    $path = ai_scan_gsc_box_file_path($state);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }
    @chmod($path, 0600);

    return true;
}

/**
 * One-time read: returns payload and deletes the file. Null = missing/expired/invalid.
 *
 * @return array{code?: string, error?: string}|null
 */
function ai_scan_gsc_box_pickup(string $state): ?array
{
    if (!ai_scan_gsc_box_state_valid($state)) {
        return null;
    }

    $path = ai_scan_gsc_box_file_path($state);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    @unlink($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['created_at']) || !is_int($decoded['created_at'])) {
        return null;
    }
    if ((time() - $decoded['created_at']) > AI_SCAN_GSC_BOX_TTL) {
        return null;
    }

    if (isset($decoded['code']) && is_string($decoded['code']) && $decoded['code'] !== '') {
        return ['code' => $decoded['code']];
    }
    if (isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '') {
        return ['error' => $decoded['error']];
    }

    return null;
}

/** Delete expired parked files (by mtime and by created_at when readable). */
function ai_scan_gsc_box_gc(?int $now = null): void
{
    $now ??= time();
    $dir = ai_scan_gsc_box_storage_dir();
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.htaccess' || str_starts_with($name, 'rl-')) {
            continue;
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $name) && !str_starts_with($name, 'tmp.')) {
            // Allow our write temps: <hash>.tmp.<hex>
            if (!preg_match('/^[a-f0-9]{64}\.tmp\.[a-f0-9]+$/', $name)) {
                continue;
            }
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            continue;
        }
        $mtime = @filemtime($path);
        if ($mtime !== false && ($now - $mtime) > AI_SCAN_GSC_BOX_TTL) {
            @unlink($path);
            continue;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $name) !== 1) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (
            is_array($decoded)
            && isset($decoded['created_at'])
            && is_int($decoded['created_at'])
            && ($now - $decoded['created_at']) > AI_SCAN_GSC_BOX_TTL
        ) {
            @unlink($path);
        }
    }
}

/**
 * Simple per-IP file counter. Returns true when the request may proceed.
 */
function ai_scan_gsc_box_rate_allow(string $ip, ?int $now = null): bool
{
    $now ??= time();
    $dir = ai_scan_gsc_box_storage_dir();
    $key = hash('sha256', 'gsc-box-rl|' . $ip);
    $path = $dir . '/rl-' . $key;

    $windowStart = $now - AI_SCAN_GSC_BOX_RATE_WINDOW;
    $times = [];
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $t) {
                    if (is_int($t) && $t >= $windowStart) {
                        $times[] = $t;
                    }
                }
            }
        }
    }

    if (count($times) >= AI_SCAN_GSC_BOX_RATE_MAX) {
        return false;
    }

    $times[] = $now;
    @file_put_contents($path, json_encode($times), LOCK_EX);
    @chmod($path, 0600);

    return true;
}

function ai_scan_gsc_box_success_page(): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>Gelukt</title></head><body>'
        . '<p>Gelukt, je kunt dit venster sluiten.</p>'
        . '</body></html>';
    exit;
}

/**
 * Early callback branch for operator-box states. Never redirects; never exchanges the code.
 */
function ai_scan_gsc_box_handle_callback(string $state, string $code, string $error): void
{
    if ($code !== '') {
        if (strlen($code) <= AI_SCAN_GSC_BOX_CODE_MAX) {
            ai_scan_gsc_box_store($state, ['code' => $code]);
        }
    } elseif ($error !== '') {
        ai_scan_gsc_box_store($state, ['error' => $error]);
    }

    ai_scan_gsc_box_success_page();
}
