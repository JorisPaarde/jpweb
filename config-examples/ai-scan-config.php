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

    // Session / CSRF signing material (also used to encrypt GSC refresh tokens).
    'app_key' => 'CHANGE_ME_LONG_RANDOM_APP_KEY',

    // Google Search Console OAuth (read-only). Create in Google Cloud Console.
    // Redirect URI must match exactly; never take it from the request.
    'gsc_client_id' => 'CHANGE_ME_GSC_CLIENT_ID',
    'gsc_client_secret' => 'CHANGE_ME_GSC_CLIENT_SECRET',
    'gsc_redirect_uri' => 'https://jpwebcreation.nl/app/gsc/callback.php',

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

    // Limits (all overridable).
    'daily_free_scans' => 20,
    'confirm_token_ttl' => 86400,
    'password_token_ttl' => 86400,
    'stale_job_seconds' => 2700,
    'stale_alert_cooldown' => 3600,
    'login_max_attempts' => 5,
    'login_window_seconds' => 900,

    // Form: IP / email / domain + short burst → HTTP 429.
    'form_ip_max_per_hour' => 3,
    'form_ip_max_per_day' => 20,
    'form_email_max_per_hour' => 3,
    'form_domain_max_per_hour' => 3,
    'form_burst_seconds' => 60,
    'form_burst_max' => 1,

    // Confirmation mail: max 1 per email per 10 minutes + global hourly cap.
    'confirm_mail_per_email_seconds' => 600,
    'confirm_mail_global_max_per_hour' => 40,

    // Scan-klaar mail after runner complete: once per scan + throttle.
    'ready_mail_per_email_seconds' => 60,
    'ready_mail_global_max_per_hour' => 40,

    // Tier-request mails (info@ + customer confirmation).
    'tier_request_mail_per_email_seconds' => 600,
    'tier_request_mail_global_max_per_hour' => 40,

    // Admin tier-change customer mail.
    'tier_change_mail_per_email_seconds' => 60,
    'tier_change_mail_global_max_per_hour' => 40,

    // Beheerder e-mailadressen voor admin/tier.php.
    'admin_emails' => ['info@jpwebcreation.nl'],

    // Signup validation (failures always return public code `invalid`).
    'email_blocked_tlds' => ['ru', 'su', 'by', 'in', 'cn', 'tk', 'top', 'xyz', 'buzz'],
    'website_allowed_tlds' => ['nl', 'be', 'eu', 'com'],
    'disposable_email_domains' => [],
    'website_require_http' => false,
];
