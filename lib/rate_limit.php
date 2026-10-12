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
 * Form IP limits: 5/hour, 20/day.
 */
function ai_scan_form_rate_limited(PDO $pdo, array $config, string $ip, int $now): bool
{
    $key = ai_scan_ip_hash($ip);
    ai_scan_rate_prune($pdo, $now - 86400);

    $perHour = (int) ($config['form_ip_max_per_hour'] ?? 5);
    $perDay = (int) ($config['form_ip_max_per_day'] ?? 20);

    if (ai_scan_rate_count($pdo, 'form_ip', $key, $now - 3600) >= $perHour) {
        return true;
    }

    if (ai_scan_rate_count($pdo, 'form_ip', $key, $now - 86400) >= $perDay) {
        return true;
    }

    return false;
}

function ai_scan_form_rate_record(PDO $pdo, string $ip, int $now): void
{
    ai_scan_rate_hit($pdo, 'form_ip', ai_scan_ip_hash($ip), $now);
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
