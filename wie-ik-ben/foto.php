<?php

declare(strict_types=1);

/**
 * Levert de afgeschermde foto's van /wie-ik-ben/ uit $HOME/private/flappen/.
 * Alleen met geldige sessie en alleen bestanden uit de allowlist (manifest.json);
 * in alle andere gevallen dezelfde 404, zodat niets verraadt wat er bestaat.
 */

require __DIR__ . '/_gate.php';

wib_send_common_headers();
wib_start_session(true);

$photo = null;
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && wib_session_is_authed($_SESSION ?? [])) {
    $photo = wib_resolve_photo($_GET['set'] ?? null, $_GET['f'] ?? null);
}

if ($photo === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Niet gevonden';
    exit;
}

header('Content-Type: ' . $photo['type']);
header('Content-Length: ' . (string) filesize($photo['path']));
header('Content-Disposition: inline');
readfile($photo['path']);
