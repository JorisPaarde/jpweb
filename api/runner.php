<?php

declare(strict_types=1);

/**
 * Authenticated runner API for the scan box.
 *
 * Auth: Authorization: Bearer <runner_token>  OR  X-Runner-Token: <token>
 *
 * Endpoints (JSON):
 *   GET  ?action=next          → next queued job (status bevestigd) or null
 *   POST action=claim          body: {id} → claim job (bezig)
 *   POST action=complete       body: {id, html, json} → store result (klaar) + scan-klaar mail (once)
 *   POST action=fail           body: {id, message} → mark mislukt
 *   GET|POST action=health     → stale-job check + queue counts + flush ready mails
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';

[$config, $pdo] = ai_scan_boot();
ai_scan_security_headers();

function ai_scan_runner_token_from_request(): string
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
        return $m[1];
    }

    return (string) ($_SERVER['HTTP_X_RUNNER_TOKEN'] ?? '');
}

function ai_scan_runner_authorize(array $config): void
{
    $expected = (string) ($config['runner_token'] ?? '');
    $got = ai_scan_runner_token_from_request();
    if (strlen($expected) < 32 || str_starts_with($expected, 'CHANGE_ME') || $got === '' || !hash_equals($expected, $got)) {
        ai_scan_json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

ai_scan_runner_authorize($config);

$method = ai_scan_request_method();
$action = (string) ($_GET['action'] ?? '');

$raw = file_get_contents('php://input') ?: '';
$body = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
if ($action === '' && isset($body['action'])) {
    $action = (string) $body['action'];
}
if ($action === '' && $method === 'GET') {
    $action = 'next';
}

try {
    ai_scan_check_stale_jobs($pdo, $config);
    ai_scan_flush_ready_mails($pdo, $config);
} catch (Throwable $e) {
    // continue
}

if ($action === 'health') {
    $counts = [];
    foreach (['onbevestigd', 'bevestigd', 'wacht_dag', 'bezig', 'klaar', 'mislukt'] as $status) {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM scans WHERE status = :s');
        $stmt->execute(['s' => $status]);
        $counts[$status] = (int) ($stmt->fetch()['c'] ?? 0);
    }
    $stale = ai_scan_check_stale_jobs($pdo, $config);
    $readyFlushed = ai_scan_flush_ready_mails($pdo, $config);
    ai_scan_json_response([
        'ok' => true,
        'counts' => $counts,
        'stale' => $stale,
        'ready_mail_flushed' => $readyFlushed,
    ]);
}

// GET ?action=limits&user_id=… | &scan_id=…  or legacy ?limits=<scan_or_user_id>
$limitsParam = (string) ($_GET['limits'] ?? '');
if ($action === 'limits' || $limitsParam !== '') {
    if ($method !== 'GET' && $method !== 'POST') {
        ai_scan_json_response(['ok' => false, 'error' => 'method'], 405);
    }

    $userId = (int) ($body['user_id'] ?? $_GET['user_id'] ?? 0);
    $scanId = (int) ($body['scan_id'] ?? $_GET['scan_id'] ?? 0);
    if ($limitsParam !== '' && ctype_digit($limitsParam)) {
        $asId = (int) $limitsParam;
        // Prefer scan lookup; fall back to user id.
        $scanProbe = $pdo->prepare('SELECT user_id FROM scans WHERE id = :id LIMIT 1');
        $scanProbe->execute(['id' => $asId]);
        $scanRow = $scanProbe->fetch();
        if ($scanRow !== false) {
            $scanId = $asId;
            if ($userId < 1 && $scanRow['user_id'] !== null) {
                $userId = (int) $scanRow['user_id'];
            }
        } elseif ($userId < 1) {
            $userId = $asId;
        }
    }

    if ($userId < 1 && $scanId > 0) {
        $scanProbe = $pdo->prepare('SELECT user_id FROM scans WHERE id = :id LIMIT 1');
        $scanProbe->execute(['id' => $scanId]);
        $scanRow = $scanProbe->fetch();
        if ($scanRow !== false && $scanRow['user_id'] !== null) {
            $userId = (int) $scanRow['user_id'];
        }
    }

    if ($userId < 1) {
        ai_scan_json_response(['ok' => false, 'error' => 'missing_user'], 400);
    }

    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();
    if ($user === false) {
        ai_scan_json_response(['ok' => false, 'error' => 'user_not_found'], 404);
    }

    $limits = ai_scan_limits_for_user($pdo, $user);
    ai_scan_json_response(['ok' => true] + $limits);
}

if ($action === 'next') {
    if ($method !== 'GET' && $method !== 'POST') {
        ai_scan_json_response(['ok' => false, 'error' => 'method'], 405);
    }
    $job = ai_scan_next_job($pdo, $config);
    ai_scan_json_response(['ok' => true, 'job' => $job]);
}

if ($action === 'claim') {
    if ($method !== 'POST') {
        ai_scan_json_response(['ok' => false, 'error' => 'method'], 405);
    }
    $id = (int) ($body['id'] ?? $_POST['id'] ?? 0);
    if ($id < 1) {
        ai_scan_json_response(['ok' => false, 'error' => 'missing_id'], 400);
    }
    $job = ai_scan_claim_job($pdo, $id);
    if ($job === null) {
        ai_scan_json_response(['ok' => false, 'error' => 'not_claimable'], 409);
    }
    ai_scan_json_response(['ok' => true, 'job' => $job]);
}

if ($action === 'complete') {
    if ($method !== 'POST') {
        ai_scan_json_response(['ok' => false, 'error' => 'method'], 405);
    }
    $id = (int) ($body['id'] ?? 0);
    $html = (string) ($body['html'] ?? '');
    $json = $body['json'] ?? null;
    if (is_array($json)) {
        $json = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $json = (string) $json;
    if ($id < 1 || $html === '' || $json === '') {
        ai_scan_json_response(['ok' => false, 'error' => 'missing_fields'], 400);
    }
    if (!ai_scan_complete_job($pdo, $config, $id, $html, $json)) {
        ai_scan_json_response(['ok' => false, 'error' => 'not_completable'], 409);
    }
    ai_scan_json_response(['ok' => true]);
}

if ($action === 'fail') {
    if ($method !== 'POST') {
        ai_scan_json_response(['ok' => false, 'error' => 'method'], 405);
    }
    $id = (int) ($body['id'] ?? 0);
    $message = (string) ($body['message'] ?? 'unknown');
    if ($id < 1) {
        ai_scan_json_response(['ok' => false, 'error' => 'missing_id'], 400);
    }
    if (!ai_scan_fail_job($pdo, $id, $message)) {
        ai_scan_json_response(['ok' => false, 'error' => 'not_failable'], 409);
    }
    ai_scan_json_response(['ok' => true]);
}

ai_scan_json_response(['ok' => false, 'error' => 'unknown_action'], 400);
