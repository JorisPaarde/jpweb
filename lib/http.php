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
    header('Location: ' . $location, true, $status);
    exit;
}

function ai_scan_redirect_code(string $code, string $path = '/ai-scan/'): void
{
    $sep = str_contains($path, '?') ? '&' : '?';
    ai_scan_redirect($path . $sep . 's=' . rawurlencode($code), 303);
}

function ai_scan_respond_code(string $code, bool $asJson = false): void
{
    if ($asJson) {
        ai_scan_json_response(['ok' => in_array($code, ['check_mail', 'confirmed'], true), 'code' => $code]);
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
