<?php

declare(strict_types=1);

function ai_scan_rate_prune(PDO $pdo, int $olderThan): void
{
    $stmt = $pdo->prepare('DELETE FROM rate_events WHERE created_at < :ts');
    $stmt->execute(['ts' => $olderThan]);
}

function ai_scan_rate_count(PDO $pdo, string $bucket, string $keyHash, int $since): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM rate_events
         WHERE bucket = :bucket AND key_hash = :key_hash AND created_at >= :since'
    );
    $stmt->execute([
        'bucket' => $bucket,
        'key_hash' => $keyHash,
        'since' => $since,
    ]);
    $row = $stmt->fetch();

    return (int) ($row['c'] ?? 0);
}

function ai_scan_rate_hit(PDO $pdo, string $bucket, string $keyHash, int $now): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO rate_events(bucket, key_hash, created_at) VALUES(:bucket, :key_hash, :created_at)'
    );
    $stmt->execute([
        'bucket' => $bucket,
        'key_hash' => $keyHash,
        'created_at' => $now,
    ]);
}

/**
 * Global (no key) counters use a fixed key hash.
 */
function ai_scan_rate_global_key(): string
{
    return hash('sha256', 'global');
}

/**
 * Form IP limits: burst (short window) + max/hour (+ optional day).
 */
function ai_scan_form_ip_rate_limited(PDO $pdo, array $config, string $ip, int $now): bool
{
    $key = ai_scan_ip_hash($ip);
    ai_scan_rate_prune($pdo, $now - 86400);

    $burstWindow = (int) ($config['form_burst_seconds'] ?? 60);
    $burstMax = (int) ($config['form_burst_max'] ?? 1);
    if ($burstWindow > 0 && ai_scan_rate_count($pdo, 'form_ip', $key, $now - $burstWindow) >= $burstMax) {
        return true;
    }

    $perHour = (int) ($config['form_ip_max_per_hour'] ?? 3);
    if ($perHour > 0 && ai_scan_rate_count($pdo, 'form_ip', $key, $now - 3600) >= $perHour) {
        return true;
    }

    $perDay = (int) ($config['form_ip_max_per_day'] ?? 20);
    if ($perDay > 0 && ai_scan_rate_count($pdo, 'form_ip', $key, $now - 86400) >= $perDay) {
        return true;
    }

    return false;
}

/**
 * Per-email and per-domain form limits (burst + hourly).
 */
function ai_scan_form_target_rate_limited(
    PDO $pdo,
    array $config,
    string $emailHash,
    string $domainHash,
    int $now
): bool {
    $burstWindow = (int) ($config['form_burst_seconds'] ?? 60);
    $burstMax = (int) ($config['form_burst_max'] ?? 1);

    if ($burstWindow > 0) {
        if (ai_scan_rate_count($pdo, 'form_email', $emailHash, $now - $burstWindow) >= $burstMax) {
            return true;
        }
        if (ai_scan_rate_count($pdo, 'form_domain', $domainHash, $now - $burstWindow) >= $burstMax) {
            return true;
        }
    }

    $emailPerHour = (int) ($config['form_email_max_per_hour'] ?? 3);
    if ($emailPerHour > 0 && ai_scan_rate_count($pdo, 'form_email', $emailHash, $now - 3600) >= $emailPerHour) {
        return true;
    }

    $domainPerHour = (int) ($config['form_domain_max_per_hour'] ?? 3);
    if ($domainPerHour > 0 && ai_scan_rate_count($pdo, 'form_domain', $domainHash, $now - 3600) >= $domainPerHour) {
        return true;
    }

    return false;
}

/**
 * Confirmation-mail throttle: max 1 per email per N seconds + global hourly cap.
 */
function ai_scan_confirm_mail_rate_limited(PDO $pdo, array $config, string $emailHash, int $now): bool
{
    $perEmailSeconds = (int) ($config['confirm_mail_per_email_seconds'] ?? 600);
    if ($perEmailSeconds > 0
        && ai_scan_rate_count($pdo, 'confirm_mail_email', $emailHash, $now - $perEmailSeconds) >= 1
    ) {
        return true;
    }

    $globalMax = (int) ($config['confirm_mail_global_max_per_hour'] ?? 40);
    if ($globalMax > 0
        && ai_scan_rate_count($pdo, 'confirm_mail_global', ai_scan_rate_global_key(), $now - 3600) >= $globalMax
    ) {
        return true;
    }

    return false;
}

function ai_scan_form_rate_record(
    PDO $pdo,
    string $ip,
    ?string $emailHash,
    ?string $domainHash,
    int $now
): void {
    ai_scan_rate_hit($pdo, 'form_ip', ai_scan_ip_hash($ip), $now);
    if ($emailHash !== null && $emailHash !== '') {
        ai_scan_rate_hit($pdo, 'form_email', $emailHash, $now);
    }
    if ($domainHash !== null && $domainHash !== '') {
        ai_scan_rate_hit($pdo, 'form_domain', $domainHash, $now);
    }
}

function ai_scan_confirm_mail_rate_record(PDO $pdo, string $emailHash, int $now): void
{
    ai_scan_rate_hit($pdo, 'confirm_mail_email', $emailHash, $now);
    ai_scan_rate_hit($pdo, 'confirm_mail_global', ai_scan_rate_global_key(), $now);
}

/**
 * Scan-klaar mail throttle: per-email cooldown + global hourly cap.
 */
function ai_scan_ready_mail_rate_limited(PDO $pdo, array $config, string $emailHash, int $now): bool
{
    $perEmailSeconds = (int) ($config['ready_mail_per_email_seconds'] ?? 60);
    if ($perEmailSeconds > 0
        && ai_scan_rate_count($pdo, 'ready_mail_email', $emailHash, $now - $perEmailSeconds) >= 1
    ) {
        return true;
    }

    $globalMax = (int) ($config['ready_mail_global_max_per_hour'] ?? 40);
    if ($globalMax > 0
        && ai_scan_rate_count($pdo, 'ready_mail_global', ai_scan_rate_global_key(), $now - 3600) >= $globalMax
    ) {
        return true;
    }

    return false;
}

function ai_scan_ready_mail_rate_record(PDO $pdo, string $emailHash, int $now): void
{
    ai_scan_rate_hit($pdo, 'ready_mail_email', $emailHash, $now);
    ai_scan_rate_hit($pdo, 'ready_mail_global', ai_scan_rate_global_key(), $now);
}

/**
 * Tier-request mail throttle (info@ + customer confirmation): per-email + global.
 */
function ai_scan_tier_request_mail_rate_limited(PDO $pdo, array $config, string $emailHash, int $now): bool
{
    $perEmailSeconds = (int) ($config['tier_request_mail_per_email_seconds'] ?? 600);
    if ($perEmailSeconds > 0
        && ai_scan_rate_count($pdo, 'tier_request_mail_email', $emailHash, $now - $perEmailSeconds) >= 1
    ) {
        return true;
    }

    $globalMax = (int) ($config['tier_request_mail_global_max_per_hour'] ?? 40);
    if ($globalMax > 0
        && ai_scan_rate_count($pdo, 'tier_request_mail_global', ai_scan_rate_global_key(), $now - 3600) >= $globalMax
    ) {
        return true;
    }

    return false;
}

function ai_scan_tier_request_mail_rate_record(PDO $pdo, string $emailHash, int $now): void
{
    ai_scan_rate_hit($pdo, 'tier_request_mail_email', $emailHash, $now);
    ai_scan_rate_hit($pdo, 'tier_request_mail_global', ai_scan_rate_global_key(), $now);
}

/**
 * Admin tier-change customer mail throttle: per-email + global.
 */
function ai_scan_tier_change_mail_rate_limited(PDO $pdo, array $config, string $emailHash, int $now): bool
{
    $perEmailSeconds = (int) ($config['tier_change_mail_per_email_seconds'] ?? 60);
    if ($perEmailSeconds > 0
        && ai_scan_rate_count($pdo, 'tier_change_mail_email', $emailHash, $now - $perEmailSeconds) >= 1
    ) {
        return true;
    }

    $globalMax = (int) ($config['tier_change_mail_global_max_per_hour'] ?? 40);
    if ($globalMax > 0
        && ai_scan_rate_count($pdo, 'tier_change_mail_global', ai_scan_rate_global_key(), $now - 3600) >= $globalMax
    ) {
        return true;
    }

    return false;
}

function ai_scan_tier_change_mail_rate_record(PDO $pdo, string $emailHash, int $now): void
{
    ai_scan_rate_hit($pdo, 'tier_change_mail_email', $emailHash, $now);
    ai_scan_rate_hit($pdo, 'tier_change_mail_global', ai_scan_rate_global_key(), $now);
}

/** @deprecated Use ai_scan_form_ip_rate_limited */
function ai_scan_form_rate_limited(PDO $pdo, array $config, string $ip, int $now): bool
{
    return ai_scan_form_ip_rate_limited($pdo, $config, $ip, $now);
}

function ai_scan_login_rate_limited(PDO $pdo, array $config, string $ip, int $now): bool
{
    $key = ai_scan_ip_hash($ip);
    $window = (int) ($config['login_window_seconds'] ?? 900);
    $max = (int) ($config['login_max_attempts'] ?? 5);
    ai_scan_rate_prune($pdo, $now - max(86400, $window));

    return ai_scan_rate_count($pdo, 'login_ip', $key, $now - $window) >= $max;
}

function ai_scan_login_rate_record(PDO $pdo, string $ip, int $now): void
{
    ai_scan_rate_hit($pdo, 'login_ip', ai_scan_ip_hash($ip), $now);
}

function ai_scan_rate_limit_retry_after(array $config): int
{
    $burst = (int) ($config['form_burst_seconds'] ?? 60);
    $emailCooldown = (int) ($config['confirm_mail_per_email_seconds'] ?? 600);

    return max(60, $burst, min(3600, $emailCooldown));
}
