<?php

declare(strict_types=1);

/**
 * Alert info@ when jobs wait >2h. At most one alert per hour.
 * Called from public requests and the runner API.
 *
 * @return array{checked: bool, alerted: bool, stale: int}
 */
function ai_scan_check_stale_jobs(PDO $pdo, array $config, int $now = 0, bool $forceMail = false): array
{
    $now = $now > 0 ? $now : time();
    $staleAfter = (int) ($config['stale_job_seconds'] ?? 7200);
    $cooldown = (int) ($config['stale_alert_cooldown'] ?? 3600);

    $cutoff = $now - $staleAfter;
    $stmt = $pdo->prepare(
        "SELECT id, public_id, queued_at, status FROM scans
         WHERE status IN ('bevestigd', 'wacht_dag')
           AND queued_at IS NOT NULL
           AND queued_at <= :cutoff
         ORDER BY queued_at ASC
         LIMIT 20"
    );
    $stmt->execute(['cutoff' => $cutoff]);
    $rows = $stmt->fetchAll();
    $stale = count($rows);

    if ($stale === 0) {
        return ['checked' => true, 'alerted' => false, 'stale' => 0];
    }

    $last = (int) (ai_scan_meta_get($pdo, 'stale_alert_at', '0') ?? '0');
    if (!$forceMail && ($now - $last) < $cooldown) {
        return ['checked' => true, 'alerted' => false, 'stale' => $stale];
    }

    $sample = array_map(static fn ($r) => (string) $r['public_id'], array_slice($rows, 0, 5));
    $mail = ai_scan_mail_stale_alert($config, $stale, $sample);
    if ($mail['ok']) {
        ai_scan_meta_set($pdo, 'stale_alert_at', (string) $now);

        return ['checked' => true, 'alerted' => true, 'stale' => $stale];
    }

    return ['checked' => true, 'alerted' => false, 'stale' => $stale];
}
