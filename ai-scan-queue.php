<?php

declare(strict_types=1);

/**
 * Publieke wachtrij-indicator voor de scan-wachter: GET /ai-scan-queue.php → {"work":true|false}.
 *
 * work = er staat een job klaar voor de runner (zelfde definitie als api/runner.php?action=next:
 * een bevestigde scan die wacht of opnieuw geprobeerd wordt). Geen aantallen, id's of persoonsgegevens.
 * Bij een fout: HTTP 503 met {"work":false}, zodat de wachter een storing van "geen werk" kan onderscheiden.
 */

require_once __DIR__ . '/lib/bootstrap.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex');

$method = ai_scan_request_method();
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo '{"work":false}';
    exit;
}

try {
    [$config, $pdo] = ai_scan_boot();
    $work = ai_scan_has_runnable_job($pdo, $config);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{"work":false}';
    exit;
}

echo $work ? '{"work":true}' : '{"work":false}';
