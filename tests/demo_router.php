<?php

declare(strict_types=1);

/**
 * PHP built-in server router for local app UI screenshots.
 * Forces demo config + auto-login via ?demo=free|paid|smooth (real session + password_version).
 */

putenv('JPWEB_AI_SCAN_ALLOW_INSECURE=1');
putenv('JPWEB_AI_SCAN_MAIL_DRY_RUN=1');
putenv('JPWEB_AI_SCAN_SKIP_REACHABILITY=1');
putenv('JPWEB_AI_SCAN_CONFIG=/tmp/jpweb-app-ui-demo/config.php');

$root = dirname(__DIR__);
require_once $root . '/lib/bootstrap.php';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$file = $root . $uri;

if ($uri !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false; // serve static
}

$idsFile = '/tmp/jpweb-app-ui-demo/ids.json';
$ids = is_readable($idsFile) ? json_decode((string) file_get_contents($idsFile), true) : null;
$demo = (string) ($_GET['demo'] ?? ($_COOKIE['jpweb_demo'] ?? ''));
if ($demo !== 'free' && $demo !== 'paid' && $demo !== 'smooth' && is_array($ids) && isset($_COOKIE['jpweb_demo'])) {
    $demo = (string) $_COOKIE['jpweb_demo'];
}

if (($demo === 'free' || $demo === 'paid' || $demo === 'smooth') && is_array($ids)) {
    setcookie('jpweb_demo', $demo, [
        'expires' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $uid = $demo === 'smooth' ? (int) ($ids['smooth_id'] ?? 0) : ($demo === 'paid' ? (int) ($ids['paid_id'] ?? 0) : (int) ($ids['free_id'] ?? 0));
    if ($uid > 0) {
        [$config, $pdo] = ai_scan_boot();
        $stmt = $pdo->prepare('SELECT id, password_version FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $uid]);
        $user = $stmt->fetch();
        if ($user !== false) {
            ai_scan_login_user(
                $config,
                (int) $user['id'],
                (int) ($user['password_version'] ?? 1)
            );
            // CLI inject still helps when the cookie jar is awkward in headless captures.
            putenv('JPWEB_AI_SCAN_TEST_USER_ID=' . (int) $user['id']);
        }
    }
}

if ($uri === '/' || $uri === '') {
    header('Location: /app/overzicht.php');
    exit;
}

if (is_file($file) && str_ends_with($file, '.php')) {
    require $file;
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
