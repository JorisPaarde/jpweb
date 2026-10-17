<?php

declare(strict_types=1);

function ai_scan_request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function ai_scan_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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
