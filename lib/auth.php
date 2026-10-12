<?php

declare(strict_types=1);

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

function ai_scan_login_user(array $config, int $userId): void
{
    ai_scan_start_session($config);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_at'] = time();
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

function ai_scan_require_user(array $config): int
{
    $id = ai_scan_current_user_id($config);
    if ($id === null) {
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
