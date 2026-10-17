<?php

declare(strict_types=1);

/**
 * Testronde 14 okt 2026, P2-6: na "Kies dit" bij Alleen advies staat de gekozen abonnementsprijs bovenaan,
 * niet de Compleet-prijs. Browsercheck (390 px): wk42/tr-conf-after.png.
 */
test('P2-6: pakketkeuze scrolt naar het prijsblok dat bij de keuze hoort', static function (): void {
    $root = dirname(__DIR__);
    $js = (string) file_get_contents($root . '/ai-vindbaarheid/ai-vindbaarheid.js');
    assert_true(str_contains($js, 'goToPrices(root.querySelector(".geo-pricing-tool"), range, event)'), 'advies-keuze scrolt naar de schuif met de abonnementsprijs');
    assert_true(str_contains($js, 'goToPrices(root.querySelector(".geo-pricing-compleet"), input, event)'), 'compleet-keuze scrolt naar het urenblok');
    assert_true(!str_contains($js, 'goToPrices();'), 'geen keuze scrolt meer naar de bovenkant van de prijssectie');
    $css = (string) file_get_contents($root . '/ai-vindbaarheid/ai-vindbaarheid.css');
    assert_true((bool) preg_match('/\.geo-pricing-compleet \{\s*scroll-margin-top: 84px;/', $css), 'blok valt niet onder de vaste kop');
    $html = (string) file_get_contents($root . '/ai-vindbaarheid/index.html');
    assert_true(str_contains($html, 'ai-vindbaarheid.js?v=11') && str_contains($html, 'ai-vindbaarheid.css?v=13'), 'cache-busting opgehoogd');
});

/**
 * C7: de schuif zet bij laden en bij elke wijziging aria-valuetext/-now/-min/-max op de zichtbare waarde.
 */
test('C7: promo-schuif zet aria-waarden ook bij laden', static function (): void {
    $root = dirname(__DIR__);
    $js = (string) file_get_contents($root . '/ai-vindbaarheid/ai-vindbaarheid.js');
    assert_true((bool) preg_match('/addEventListener\("input", \(\) => update\(config\)\);\s*(\/\/[^\n]*\s*)?update\(config\);/', $js), 'update draait bij laden');
    assert_true(str_contains($js, 'range.setAttribute("aria-valuemin"') && str_contains($js, 'range.setAttribute("aria-valuemax"'), 'min/max worden gezet');
    $html = (string) file_get_contents($root . '/ai-vindbaarheid/index.html');
    assert_true(str_contains($html, 'ai-vindbaarheid.js?v=11'), 'cache-busting opgehoogd');
});

/**
 * N17-2: na Enter op "Kies dit" gaat de focus mee naar het prijsblok (alleen bij toetsenbord, click-detail 0).
 */
test('N17-2: toetsenbordkeuze verplaatst de focus naar het prijsblok', static function (): void {
    $root = dirname(__DIR__);
    $js = (string) file_get_contents($root . '/ai-vindbaarheid/ai-vindbaarheid.js');
    assert_true(str_contains($js, 'event.detail === 0') && str_contains($js, 'focusTarget.focus({ preventScroll: true })'), 'focus alleen bij toetsenbord, zonder extra scroll');
    $html = (string) file_get_contents($root . '/ai-vindbaarheid/index.html');
    assert_true(str_contains($html, 'ai-vindbaarheid.js?v=11'), 'cache-busting opgehoogd');
});
