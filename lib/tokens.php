<?php

declare(strict_types=1);

function ai_scan_random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function ai_scan_public_id(): string
{
    return bin2hex(random_bytes(16));
}

function ai_scan_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function ai_scan_csrf_token(array $config): string
{
    ai_scan_start_session($config);
    if (empty($_SESSION['ai_scan_csrf']) || !is_string($_SESSION['ai_scan_csrf'])) {
        $_SESSION['ai_scan_csrf'] = ai_scan_random_token(32);
    }

    return $_SESSION['ai_scan_csrf'];
}

function ai_scan_verify_csrf(array $config, ?string $token): bool
{
    ai_scan_start_session($config);
    $expected = $_SESSION['ai_scan_csrf'] ?? null;
    if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($expected, $token);
}
