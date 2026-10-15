<?php

declare(strict_types=1);

/**
 * Normalize email for uniqueness: lowercase; Gmail/googlemail strip dots and +tags.
 */
function ai_scan_normalize_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return $email;
    }

    [$local, $domain] = explode('@', $email, 2);
    $domain = rtrim($domain, '.');

    if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
        $local = explode('+', $local, 2)[0];
        $local = str_replace('.', '', $local);
        $domain = 'gmail.com';
    } else {
        $local = explode('+', $local, 2)[0];
    }

    return $local . '@' . $domain;
}

function ai_scan_email_hash(string $normalizedEmail): string
{
    return hash('sha256', 'email:' . $normalizedEmail);
}

function ai_scan_domain_hash(string $domain): string
{
    return hash('sha256', 'domain:' . $domain);
}

/**
 * IPv4-mapped IPv6 (::ffff:a.b.c.d) is gewoon een IPv4-adres: geef dan de 4 bytes terug.
 */
function ai_scan_ip_unmap(string $bin): string
{
    if (strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
        return substr($bin, 12);
    }

    return $bin;
}

/**
 * Sleutel voor de tijdelijke IP-vensters. IPv6 op /64-prefix, zoals de blijvende blokkade;
 * IPv4 en onbekende waarden blijven ongewijzigd.
 */
function ai_scan_ip_hash(string $ip): string
{
    $bin = @inet_pton(trim($ip));
    if ($bin !== false) {
        $bin = ai_scan_ip_unmap($bin);
    }
    if ($bin !== false && strlen($bin) === 4) {
        return hash('sha256', 'ip:' . inet_ntop($bin));
    }
    if ($bin !== false && strlen($bin) === 16) {
        return hash('sha256', 'ip:' . bin2hex(substr($bin, 0, 8)) . '/64');
    }

    return hash('sha256', 'ip:' . $ip);
}

/**
 * Privacyvriendelijke hash voor de gratis-scanblokkade per IP.
 * HMAC met app_key (niet terug te rekenen zonder de sleutel); IPv6 op /64-prefix.
 * Lege string bij onbekend IP (dan geen IP-blokkade).
 */
function ai_scan_ip_block_hash(array $config, string $ip): string
{
    $ip = trim($ip);
    $bin = $ip !== '' ? @inet_pton($ip) : false;
    if ($bin === false) {
        return '';
    }
    $bin = ai_scan_ip_unmap($bin);
    if (strlen($bin) === 16) {
        $bin = substr($bin, 0, 8) . str_repeat("\0", 8);
    }
    $key = (string) ($config['app_key'] ?? '');

    return hash_hmac('sha256', 'ipblock:' . bin2hex($bin), $key !== '' ? $key : 'ai-scan');
}

/**
 * Extract host from a user-supplied website value and normalize.
 *
 * @return array{ok: bool, url?: string, host?: string, domain?: string, error?: string}
 */
function ai_scan_parse_website(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'empty'];
    }

    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . $raw;
    }

    $parts = parse_url($raw);
    if ($parts === false || empty($parts['host'])) {
        return ['ok' => false, 'error' => 'invalid'];
    }

    $host = strtolower((string) $parts['host']);
    $host = preg_replace('/\.$/', '', $host) ?? $host;
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    // Basic IDN → ASCII when intl is available.
    if (function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($ascii) && $ascii !== '') {
            $host = strtolower($ascii);
        }
    }

    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) && $host !== 'localhost') {
        return ['ok' => false, 'error' => 'invalid'];
    }

    $domain = ai_scan_registrable_domain($host);
    $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
    if ($scheme !== 'http' && $scheme !== 'https') {
        $scheme = 'https';
    }

    $url = $scheme . '://' . $host . '/';

    return [
        'ok' => true,
        'url' => $url,
        'host' => $host,
        'domain' => $domain,
    ];
}

/**
 * Rough public-suffix style reduction for common cases (nl/be/com/…).
 * shop.example.nl → example.nl; example.co.uk → example.co.uk.
 */
function ai_scan_registrable_domain(string $host): string
{
    $host = strtolower($host);
    if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return $host;
    }

    $labels = explode('.', $host);
    $count = count($labels);
    if ($count < 2) {
        return $host;
    }

    $multi = [
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk',
        'co.nz', 'com.au', 'net.au', 'org.au',
        'co.jp', 'com.br',
    ];

    $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
    if (in_array($lastTwo, $multi, true)) {
        if ($count >= 3) {
            return $labels[$count - 3] . '.' . $lastTwo;
        }

        return $lastTwo;
    }

    return $lastTwo;
}

/**
 * Soft reachability check: HEAD/GET with short timeout.
 */
function ai_scan_site_reachable(string $url, int $timeoutSeconds = 5): bool
{
    if (!function_exists('curl_init')) {
        $headers = @get_headers($url, false, stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'follow_location' => 1,
                'user_agent' => 'JPWebcreationAIScan/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]));

        if ($headers === false || !isset($headers[0])) {
            return false;
        }

        return (bool) preg_match('/\s(2|3)\d\d\s/', (string) $headers[0]);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_USERAGENT => 'JPWebcreationAIScan/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);

    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);

    if ($err !== 0 || $status === 0) {
        // Some hosts reject HEAD; retry GET.
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'JPWebcreationAIScan/1.0',
            CURLOPT_RANGE => '0-0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err !== 0 || $status === 0) {
            return false;
        }
    }

    return $status >= 200 && $status < 400;
}

function ai_scan_is_disposable_email(string $email): bool
{
    $email = strtolower(trim($email));
    if (!str_contains($email, '@')) {
        return false;
    }

    $domain = substr($email, strrpos($email, '@') + 1);
    static $set = null;
    if ($set === null) {
        $set = ai_scan_load_disposable_domains();
    }

    return isset($set[$domain]);
}

/**
 * @return array<string, true>
 */
function ai_scan_load_disposable_domains(): array
{
    $path = dirname(__DIR__) . '/data/disposable-email-domains/domains.txt';
    $set = [];
    if (!is_readable($path)) {
        // Minimal built-in denylist when the file is absent.
        foreach (['mailinator.com', 'guerrillamail.com', 'tempmail.com', '10minutemail.com', 'yopmail.com', 'trashmail.com'] as $d) {
            $set[$d] = true;
        }

        return $set;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = strtolower(trim($line));
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $set[$line] = true;
    }

    return $set;
}
