<?php

declare(strict_types=1);

/**
 * One-time pickup of a parked Google OAuth authorization code for the operator box.
 *
 * POST only, param `state` matching ^box-[A-Za-z0-9_-]{43,128}$.
 * Returns JSON {code} or {error}, then deletes the parked file.
 *
 * Security: the code is useless without the client_secret AND the PKCE
 * code_verifier, both only on the box; `state` is a 256-bit bearer secret
 * known only to the box and the person clicking. This endpoint never
 * exchanges the code. Do not log `code` or `state`.
 */

require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/gsc_box.php';

$config = ai_scan_load_config();
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
ai_scan_assert_allowed_host($config);

if (ai_scan_request_method() !== 'POST') {
    header('Allow: POST');
    ai_scan_json_response(['error' => 'method_not_allowed'], 405);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > AI_SCAN_GSC_BOX_POST_MAX_BYTES) {
    ai_scan_json_response(['error' => 'not_found'], 404);
}

ai_scan_gsc_box_gc();

$ip = ai_scan_client_ip();
if (!ai_scan_gsc_box_rate_allow($ip)) {
    http_response_code(429);
    header('Content-Type: application/json; charset=UTF-8');
    header('Retry-After: ' . (string) AI_SCAN_GSC_BOX_RATE_WINDOW);
    echo '{"error":"not_found"}';
    exit;
}

$state = trim((string) ($_POST['state'] ?? ''));
if (!ai_scan_gsc_box_state_valid($state)) {
    // Same shape/status as a miss — constant-ish for invalid vs missing.
    ai_scan_json_response(['error' => 'not_found'], 404);
}

$payload = ai_scan_gsc_box_pickup($state);
if ($payload === null) {
    ai_scan_json_response(['error' => 'not_found'], 404);
}

if (isset($payload['code'])) {
    ai_scan_json_response(['code' => $payload['code']], 200);
}

ai_scan_json_response(['error' => (string) ($payload['error'] ?? 'unknown')], 200);
