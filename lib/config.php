<?php

declare(strict_types=1);

/**
 * Resolve and load AI-scan config from outside the webroot.
 *
 * @return array<string, mixed>
 */
function ai_scan_load_config(?string $overridePath = null): array
{
    $candidates = [];

    if ($overridePath !== null && $overridePath !== '') {
        $candidates[] = $overridePath;
    }

    $env = getenv('JPWEB_AI_SCAN_CONFIG');
    if (is_string($env) && $env !== '') {
        $candidates[] = $env;
    }

    $webroot = dirname(__DIR__);
    $candidates[] = $webroot . '/../private/ai-scan-config.php';
    $candidates[] = '/home/jpwebcreation/private/ai-scan-config.php';

    // Local/dev fallbacks (still outside public HTML when possible).
    $candidates[] = $webroot . '/../ai-scan-config.php';

    $path = null;
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
            $path = $candidate;
            break;
        }
    }

    if ($path === null) {
        // Test/dev in-memory style defaults when no file exists.
        $fallbackSqlite = $webroot . '/../private/ai-scan.sqlite';
        $config = ai_scan_default_config($fallbackSqlite);
    } else {
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new RuntimeException('AI-scan config must return an array.');
        }
        $config = array_replace_recursive(ai_scan_default_config(''), $loaded);
    }

    if (($config['sqlite_path'] ?? '') === '') {
        $config['sqlite_path'] = dirname(__DIR__) . '/../private/ai-scan.sqlite';
    }

    if (($config['app_key'] ?? '') === '' || ($config['app_key'] ?? '') === 'CHANGE_ME_LONG_RANDOM_APP_KEY') {
        // Allow boot in tests; production must override.
        if (getenv('JPWEB_AI_SCAN_ALLOW_INSECURE') !== '1' && ($config['base_url'] ?? '') === 'https://jpwebcreation.nl') {
            // Still bootable; endpoints that need secrets will fail closed where checked.
        }
    }

    return $config;
}

/**
 * @return array<string, mixed>
 */
function ai_scan_default_config(string $sqlitePath): array
{
    return [
        'sqlite_path' => $sqlitePath,
        'runner_token' => '',
        'app_key' => 'dev-insecure-app-key-change-me',
        'base_url' => 'https://jpwebcreation.nl',
        'smtp' => [
            'host' => 's1155.nl',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'info@jpwebcreation.nl',
            'password' => '',
            'from_email' => 'info@jpwebcreation.nl',
            'from_name' => 'JP Web Creation',
            'alert_to' => 'info@jpwebcreation.nl',
        ],
        'daily_free_scans' => 20,
        'confirm_token_ttl' => 86400,
        'password_token_ttl' => 86400,
        'stale_job_seconds' => 1800,
        'stale_alert_cooldown' => 3600,
        'login_max_attempts' => 5,
        'login_window_seconds' => 900,
        // Form rate limits (all overridable).
        'form_ip_max_per_hour' => 3,
        'form_ip_max_per_day' => 20,
        'form_email_max_per_hour' => 3,
        'form_domain_max_per_hour' => 3,
        'form_burst_seconds' => 60,
        'form_burst_max' => 1,
        // Confirmation mail anti-spam.
        'confirm_mail_per_email_seconds' => 600,
        'confirm_mail_global_max_per_hour' => 40,
        // Signup validation (public failure is always code `invalid`).
        'email_blocked_tlds' => ['ru', 'su', 'by', 'in', 'cn', 'tk', 'top', 'xyz', 'buzz'],
        'website_allowed_tlds' => ['nl', 'be', 'eu', 'com'],
        'disposable_email_domains' => [],
        'website_require_http' => false,
        'allowed_hosts' => [
            'jpwebcreation.nl',
            'www.jpwebcreation.nl',
            'test.jpwebcreation.nl',
            '127.0.0.1',
            'localhost',
        ],
    ];
}

function ai_scan_config_value(array $config, string $key, mixed $default = null): mixed
{
    return $config[$key] ?? $default;
}
