<?php

declare(strict_types=1);

/** Eén minimum voor wachtwoord kiezen, herstellen en wijzigen. */
const AI_SCAN_PASSWORD_MIN_LENGTH = 12;

function ai_scan_start_session(array $config): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ai_scan_request_host() === 'jpwebcreation.nl'
        || ai_scan_request_host() === 'www.jpwebcreation.nl'
        || ai_scan_request_host() === 'test.jpwebcreation.nl';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('jpweb_ai_scan');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return @session_start();
}

function ai_scan_current_user_id(array $config): ?int
{
    if (!ai_scan_start_session($config)) {
        return null;
    }

    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        return null;
    }

    return (int) $id;
}

function ai_scan_login_user(array $config, int $userId, ?int $passwordVersion = null): void
{
    ai_scan_start_session($config);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_at'] = time();
    if ($passwordVersion !== null) {
        $_SESSION['password_version'] = $passwordVersion;
    }
}

/**
 * True when the session carries a password_version that matches the DB.
 * Missing session version is stale (force re-login); never adopt the DB value.
 */
function ai_scan_session_password_version_ok(PDO $pdo, int $userId): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $sessionVersion = $_SESSION['password_version'] ?? null;
    if ($sessionVersion === null || $sessionVersion === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT password_version FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return false;
    }

    return (int) $sessionVersion === (int) ($row['password_version'] ?? 1);
}

function ai_scan_logout_user(array $config): void
{
    if (!ai_scan_start_session($config)) {
        return;
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Require a logged-in user whose session password_version still matches the DB.
 * Rejects stale sessions (missing or outdated version) everywhere this is called.
 */
function ai_scan_require_user(array $config, PDO $pdo): int
{
    $id = ai_scan_current_user_id($config);
    if ($id === null || !ai_scan_session_password_version_ok($pdo, $id)) {
        if ($id !== null) {
            ai_scan_logout_user($config);
        }
        ai_scan_redirect('/login/?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard/')), 303);
    }

    return $id;
}

/**
 * Store short-lived form values/errors in session (max 10 minutes).
 * Never stores honeypot or consent acceptance.
 *
 * @param array<string, string> $values
 * @param array<string, string> $errors
 */
function ai_scan_store_form_state(array $config, array $values, array $errors): void
{
    if (!ai_scan_start_session($config)) {
        return;
    }

    $_SESSION['ai_scan_form_state'] = [
        'created_at' => time(),
        'values' => [
            'email' => (string) ($values['email'] ?? ''),
            'website' => (string) ($values['website'] ?? ''),
        ],
        'errors' => $errors,
    ];
}

/**
 * @return array{values: array<string, string>, errors: array<string, string>}
 */
function ai_scan_consume_form_state(array $config): array
{
    $empty = ['values' => [], 'errors' => []];
    if (!ai_scan_start_session($config)) {
        return $empty;
    }

    $candidate = $_SESSION['ai_scan_form_state'] ?? null;
    unset($_SESSION['ai_scan_form_state']);

    if (
        !is_array($candidate)
        || !isset($candidate['created_at'], $candidate['values'], $candidate['errors'])
        || !is_int($candidate['created_at'])
        || (time() - $candidate['created_at']) > 600
        || !is_array($candidate['values'])
        || !is_array($candidate['errors'])
    ) {
        return $empty;
    }

    return [
        'values' => [
            'email' => (string) ($candidate['values']['email'] ?? ''),
            'website' => (string) ($candidate['values']['website'] ?? ''),
        ],
        'errors' => array_map('strval', $candidate['errors']),
    ];
}

function ai_scan_hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function ai_scan_verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}
