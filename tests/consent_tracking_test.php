<?php

declare(strict_types=1);

/**
 * GTM + cookiekeuze op PHP-pagina's, honeypot-status en kaart na toestemming.
 * Included from tests/run.php.
 */

require_once dirname(__DIR__) . '/lib/consent.php';

function consent_test_src(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
}

function consent_test_squash(string $s): string
{
    return (string) preg_replace('/\s+/', ' ', trim($s));
}

test('contact: honeypot krijgt status received, geen success', static function (): void {
    $src = consent_test_src('contact.php');
    $honeypot = strpos($src, "clean_input('website', 200) !== ''");
    assert_true($honeypot !== false, 'honeypot gevonden');
    $next = substr($src, (int) $honeypot, 200);
    assert_true(str_contains($next, "redirect_with_status('received', \$returnPath)"), 'honeypot moet naar received');
    assert_same(1, substr_count($src, "redirect_with_status(\$sent ? 'success' : 'error'"), 'echte verzending blijft success');
    assert_same(1, substr_count($src, "'success'"), 'success alleen na mail()');
});

test('consent.js: conversie alleen bij ?contact=success en niet op ingelogde pagina\'s', static function (): void {
    $js = consent_test_src('consent.js');
    assert_true(str_contains($js, 'get("contact") === "success"'), 'event alleen op success');
    assert_true(!str_contains($js, '"received"'), 'received telt niet mee');
    assert_true(str_contains($js, 'data-no-conversion-events'), 'vlag voor ingelogde pagina\'s');
    assert_true(str_contains($js, 'event: "consent_update"'), 'consent_update blijft');
});

test('consent: PHP-fragment is gelijk aan het statische fragment op de homepage', static function (): void {
    ob_start();
    jpwc_consent_head();
    $php = (string) ob_get_clean();
    $html = consent_test_src('index.html');
    $start = strpos($html, '<script>' . "\n" . '      window.dataLayer');
    $end = strpos($html, '<!-- End Google Tag Manager -->');
    assert_true($start !== false && $end !== false, 'fragment in index.html gevonden');
    $static = substr($html, (int) $start, (int) $end - (int) $start + strlen('<!-- End Google Tag Manager -->'));
    assert_true(str_contains(consent_test_squash($php), consent_test_squash($static)), 'zelfde default, restore en GTM');
    $default = strpos($php, "gtag('consent', 'default'");
    $update = strpos($php, "gtag('consent', 'update'");
    $gtm = strpos($php, "event:'gtm.js'");
    assert_true($default !== false && $update !== false && $gtm !== false && $default < $update && $update < $gtm, 'volgorde default, update, gtm.js');
    ob_start();
    jpwc_consent_noscript();
    assert_true(str_contains((string) ob_get_clean(), 'ns.html?id=GTM-WQ8RDBNK'), 'noscript');
});

test('consent: GTM op publieke PHP-pagina\'s, niet op app, admin en token-pagina\'s', static function (): void {
    foreach (['login/index.php', 'login/vergeten/index.php', 'ai-scan/index.php'] as $rel) {
        $src = consent_test_src($rel);
        assert_true(substr_count($src, 'jpwc_consent_head()') === substr_count($src, '<head>'), $rel . ': head');
        assert_true(substr_count($src, 'jpwc_consent_noscript()') === substr_count($src, '<head>'), $rel . ': noscript');
        assert_true(str_contains($src, 'jpwc_consent_html_attr(true)'), $rel . ': geen conversie-events');
    }
    foreach ([
        'app/layout.php', 'app/confirm-email.php', 'dashboard/index.php', 'admin/scans.php', 'admin/tier.php',
        'admin/blokkade.php', 'ai-scan.php', 'ai-scan/wachtwoord/index.php', 'wie-ik-ben/index.php',
    ] as $rel) {
        $src = consent_test_src($rel);
        assert_true(!str_contains($src, 'GTM-') && !str_contains($src, 'jpwc_consent_head'), $rel . ': geen GTM');
    }
    foreach (['ai-scan.php', 'ai-scan/wachtwoord/index.php', 'app/confirm-email.php'] as $rel) {
        assert_true(str_contains(consent_test_src($rel), "header('Referrer-Policy: no-referrer');"), $rel . ': token niet als referrer');
    }
});

test('contact: Google Maps pas na klik of toestemming', static function (): void {
    $html = consent_test_src('contact/index.html');
    assert_true(preg_match('~<iframe[^>]+google\\.com/maps~', $html) !== 1, 'geen kaart-iframe in de HTML');
    assert_true(str_contains($html, 'data-map-src="https://www.google.com/maps?cid=10877726827564355710&amp;output=embed"'), 'kaartbron');
    assert_true(str_contains($html, 'data-map-load>Kaart tonen</button>'), 'knop');
});
