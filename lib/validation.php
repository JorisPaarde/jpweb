<?php

declare(strict_types=1);

/**
 * Strict signup validation for email + website.
 * Public failures always map to code `invalid` with one neutral message;
 * the concrete rule is logged server-side only.
 */

const AI_SCAN_INVALID_PUBLIC_MESSAGE = 'Controleer je e-mailadres en je website en probeer het opnieuw.';

/**
 * @var null|callable(string $host, string $type): bool
 */
$GLOBALS['ai_scan_dns_checker'] = $GLOBALS['ai_scan_dns_checker'] ?? null;

/**
 * @param null|callable(string, string): bool $checker
 */
function ai_scan_set_dns_checker(?callable $checker): void
{
    $GLOBALS['ai_scan_dns_checker'] = $checker;
}

function ai_scan_dns_records(string $host, string $type): bool
{
    $host = strtolower(rtrim($host, '.'));
    $type = strtoupper($type);
    $checker = $GLOBALS['ai_scan_dns_checker'] ?? null;
    if (is_callable($checker)) {
        return (bool) $checker($host, $type);
    }

    return checkdnsrr($host, $type);
}

/**
 * @param array<string, mixed> $context
 */
function ai_scan_log_validation_rule(string $rule, array $context = []): void
{
    $payload = array_merge([
        'ts' => time(),
        'rule' => $rule,
    ], $context);
    $line = '[ai-scan-validation] ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $logFile = getenv('JPWEB_AI_SCAN_VALIDATION_LOG');
    if (is_string($logFile) && $logFile !== '') {
        @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    error_log($line);
}

/**
 * Strict syntax: filter_var + regex (no odd chars, no consecutive dots, TLD ≥ 2 letters).
 */
function ai_scan_email_syntax_ok(string $email): bool
{
    // No leading/trailing whitespace or embedded control/space characters.
    if ($email === '' || $email !== trim($email) || preg_match('/\s/', $email)) {
        return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (str_contains($email, '..')) {
        return false;
    }

    // Local: alnum, then alnum/._+- , ending alnum; or single alnum.
    // Domain labels: alnum / hyphen; TLD letters only, length ≥ 2.
    $pattern = '/^'
        . '[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?'
        . '@'
        . '(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+'
        . '[a-z]{2,}'
        . '$/i';

    if (!preg_match($pattern, $email)) {
        return false;
    }

    // Reject odd Unicode / control leftovers after filter_var.
    if (preg_match('/[^\x21-\x7e]/', $email)) {
        return false;
    }

    return true;
}

/**
 * @param list<string> $blocked
 */
function ai_scan_email_tld_blocked(string $email, array $blocked): bool
{
    $email = strtolower(trim($email));
    if (!str_contains($email, '@')) {
        return true;
    }

    $domain = substr($email, strrpos($email, '@') + 1);
    $tld = ai_scan_extract_tld($domain);
    if ($tld === '') {
        return true;
    }

    $normalized = [];
    foreach ($blocked as $item) {
        $item = strtolower(ltrim(trim((string) $item), '.'));
        if ($item !== '') {
            $normalized[$item] = true;
        }
    }

    return isset($normalized[$tld]);
}

function ai_scan_extract_tld(string $host): string
{
    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || !str_contains($host, '.')) {
        return '';
    }

    $parts = explode('.', $host);

    return (string) end($parts);
}

/**
 * MX check, fall back to A (then AAAA).
 */
function ai_scan_email_domain_has_dns(string $email): bool
{
    $email = strtolower(trim($email));
    if (!str_contains($email, '@')) {
        return false;
    }

    $domain = substr($email, strrpos($email, '@') + 1);
    if ($domain === '') {
        return false;
    }

    if (ai_scan_dns_records($domain, 'MX')) {
        return true;
    }

    if (ai_scan_dns_records($domain, 'A')) {
        return true;
    }

    return ai_scan_dns_records($domain, 'AAAA');
}

/**
 * Disposable domains: config list merged with the on-disk denylist.
 *
 * @param array<string, mixed> $config
 */
function ai_scan_is_disposable_email_configured(string $email, array $config): bool
{
    $email = strtolower(trim($email));
    if (!str_contains($email, '@')) {
        return false;
    }

    $domain = substr($email, strrpos($email, '@') + 1);
    $set = ai_scan_load_disposable_domains();

    $extra = $config['disposable_email_domains'] ?? [];
    if (is_array($extra)) {
        foreach ($extra as $item) {
            $item = strtolower(trim((string) $item));
            if ($item !== '') {
                $set[$item] = true;
            }
        }
    }

    return isset($set[$domain]);
}

/**
 * @param list<string> $allowed
 */
function ai_scan_website_tld_allowed(string $hostOrDomain, array $allowed): bool
{
    $tld = ai_scan_extract_tld($hostOrDomain);
    if ($tld === '') {
        return false;
    }

    $normalized = [];
    foreach ($allowed as $item) {
        $item = strtolower(ltrim(trim((string) $item), '.'));
        if ($item !== '') {
            $normalized[$item] = true;
        }
    }

    return isset($normalized[$tld]);
}

function ai_scan_host_resolves(string $host): bool
{
    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || $host === 'localhost') {
        return $host === 'localhost';
    }

    if (ai_scan_dns_records($host, 'A') || ai_scan_dns_records($host, 'AAAA')) {
        return true;
    }

    // Some hosts only have records on the registrable apex.
    return false;
}

/**
 * Run all signup email/website rules. On failure returns public code `invalid`
 * and logs the internal rule name.
 *
 * @param array<string, mixed> $config
 * @return array{ok: true, email: string, website: array{ok: bool, url: string, host: string, domain: string}}|array{ok: false, code: string, rule: string}
 */
function ai_scan_validate_signup(array $config, string $emailRaw, string $websiteRaw, bool $consent): array
{
    if (!$consent) {
        ai_scan_log_validation_rule('consent');

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'consent'];
    }

    if (!ai_scan_email_syntax_ok($emailRaw)) {
        ai_scan_log_validation_rule('email_syntax', ['email' => $emailRaw]);

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'email_syntax'];
    }

    $blockedTlds = $config['email_blocked_tlds'] ?? ['ru', 'su', 'by', 'in', 'cn', 'tk', 'top', 'xyz', 'buzz'];
    if (!is_array($blockedTlds)) {
        $blockedTlds = ['ru', 'su', 'by', 'in', 'cn', 'tk', 'top', 'xyz', 'buzz'];
    }

    if (ai_scan_email_tld_blocked($emailRaw, $blockedTlds)) {
        ai_scan_log_validation_rule('email_tld_blocked', ['email' => $emailRaw]);

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'email_tld_blocked'];
    }

    if (ai_scan_is_disposable_email_configured($emailRaw, $config)) {
        ai_scan_log_validation_rule('email_disposable', ['email' => $emailRaw]);

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'email_disposable'];
    }

    if (!ai_scan_email_domain_has_dns($emailRaw)) {
        ai_scan_log_validation_rule('email_mx', ['email' => $emailRaw]);

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'email_mx'];
    }

    $parsed = ai_scan_parse_website($websiteRaw);
    if (!$parsed['ok']) {
        ai_scan_log_validation_rule('website_parse', ['website' => $websiteRaw]);

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'website_parse'];
    }

    $allowedWebsiteTlds = $config['website_allowed_tlds'] ?? ['nl', 'be', 'eu', 'com'];
    if (!is_array($allowedWebsiteTlds)) {
        $allowedWebsiteTlds = ['nl', 'be', 'eu', 'com'];
    }

    $host = (string) $parsed['host'];
    $domain = (string) $parsed['domain'];
    if (!ai_scan_website_tld_allowed($host, $allowedWebsiteTlds)
        && !ai_scan_website_tld_allowed($domain, $allowedWebsiteTlds)
    ) {
        ai_scan_log_validation_rule('website_tld', ['host' => $host]);

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'website_tld'];
    }

    if (!ai_scan_host_resolves($host) && !ai_scan_host_resolves($domain)) {
        ai_scan_log_validation_rule('website_dns', ['host' => $host]);

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'website_dns'];
    }

    $requireHttp = (bool) ($config['website_require_http'] ?? false);
    if ($requireHttp) {
        $skip = getenv('JPWEB_AI_SCAN_SKIP_REACHABILITY') === '1';
        if (!$skip && !ai_scan_site_reachable((string) $parsed['url'])) {
            ai_scan_log_validation_rule('website_http', ['url' => $parsed['url']]);

            return ['ok' => false, 'code' => 'invalid', 'rule' => 'website_http'];
        }
    }

    $normalizedEmail = ai_scan_normalize_email($emailRaw);
    if (!ai_scan_email_syntax_ok($normalizedEmail) && !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        ai_scan_log_validation_rule('email_normalize', ['email' => $emailRaw]);

        return ['ok' => false, 'code' => 'invalid', 'rule' => 'email_normalize'];
    }

    return [
        'ok' => true,
        'email' => $normalizedEmail,
        'website' => [
            'ok' => true,
            'url' => (string) $parsed['url'],
            'host' => $host,
            'domain' => $domain,
        ],
    ];
}
