<?php

declare(strict_types=1);

/**
 * Server-side config for the AI scan backend.
 * Place OUTSIDE the webroot, e.g. /home/jpwebcreation/private/ai-scan-config.php
 * Never commit a filled-in copy.
 *
 * Lookup order (first readable file wins):
 * 1. JPWEB_AI_SCAN_CONFIG environment variable
 * 2. ../private/ai-scan-config.php (relative to the site webroot)
 * 3. /home/jpwebcreation/private/ai-scan-config.php
 */

return [
    // SQLite database path (outside webroot).
    'sqlite_path' => '/home/jpwebcreation/private/ai-scan.sqlite',

    // Shared secret for the authenticated runner API (Authorization: Bearer …).
    'runner_token' => 'CHANGE_ME_LONG_RANDOM_RUNNER_TOKEN',

    // Session / CSRF signing material.
    'app_key' => 'CHANGE_ME_LONG_RANDOM_APP_KEY',

    // Public site origin used in mail links (no trailing slash).
    'base_url' => 'https://jpwebcreation.nl',

    // SMTP (info@ via s1155, port 465 SSL).
    'smtp' => [
        'host' => 's1155.nl',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => 'info@jpwebcreation.nl',
        'password' => 'CHANGE_ME_SMTP_PASSWORD',
        'from_email' => 'info@jpwebcreation.nl',
        'from_name' => 'JP Web Creation',
        'alert_to' => 'info@jpwebcreation.nl',
    ],

    // Limits (overridable).
    'daily_free_scans' => 10,
    'confirm_token_ttl' => 86400,
    'password_token_ttl' => 86400,
    'stale_job_seconds' => 7200,
    'stale_alert_cooldown' => 3600,
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,
    'form_ip_max_per_hour' => 5,
    'form_ip_max_per_day' => 20,
];
