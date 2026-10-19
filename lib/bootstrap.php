<?php

declare(strict_types=1);

/**
 * Shared bootstrap for AI-scan PHP endpoints.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tiers.php';
require_once __DIR__ . '/subscription.php';
require_once __DIR__ . '/scan_service.php';
require_once __DIR__ . '/stale_jobs.php';

/**
 * Load config, open DB, ensure schema. Returns [config, PDO].
 *
 * @return array{0: array<string, mixed>, 1: PDO}
 */
function ai_scan_boot(?string $configOverride = null): array
{
    $config = ai_scan_load_config($configOverride);
    ai_scan_quic_cloud_dir(dirname((string) $config['sqlite_path'])); // same folder as the runner refresh
    $pdo = ai_scan_db($config);
    ai_scan_migrate($pdo);

    return [$config, $pdo];
}
