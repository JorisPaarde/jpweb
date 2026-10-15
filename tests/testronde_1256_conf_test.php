<?php

declare(strict_types=1);

/**
 * Testronde 14 okt 2026, P2-6: na "Kies dit" bij Alleen advies staat de gekozen abonnementsprijs bovenaan,
 * niet de Compleet-prijs. Browsercheck (390 px): wk42/tr-conf-after.png.
 */
test('P2-6: pakketkeuze scrolt naar het prijsblok dat bij de keuze hoort', static function (): void {
    $root = dirname(__DIR__);
    $js = (string) file_get_contents($root . '/ai-vindbaarheid/ai-vindbaarheid.js');
    assert_true(str_contains($js, 'goToPrices(root.querySelector(".geo-pricing-tool"))'), 'advies-keuze scrolt naar de schuif met de abonnementsprijs');
    assert_true(str_contains($js, 'goToPrices(root.querySelector(".geo-pricing-compleet"))'), 'compleet-keuze scrolt naar het urenblok');
    assert_true(!str_contains($js, 'goToPrices();'), 'geen keuze scrolt meer naar de bovenkant van de prijssectie');
    $css = (string) file_get_contents($root . '/ai-vindbaarheid/ai-vindbaarheid.css');
    assert_true((bool) preg_match('/\.geo-pricing-compleet \{\s*scroll-margin-top: 84px;/', $css), 'blok valt niet onder de vaste kop');
    $html = (string) file_get_contents($root . '/ai-vindbaarheid/index.html');
    assert_true(str_contains($html, 'ai-vindbaarheid.js?v=9') && str_contains($html, 'ai-vindbaarheid.css?v=11'), 'cache-busting opgehoogd');
});
