<?php

declare(strict_types=1);

/** GEO-testronde 19-10 13:10. Included from tests/run.php. */

test('N19-2: lange kop op de foutpagina past op mobiel (ondergrens kop omlaag, lange woorden breken af; vanaf 800 px gelijk aan de hero-kop)', static function (): void {
    $root = dirname(__DIR__);
    $html = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
        'require ' . var_export($root . '/lib/http.php', true) . ';'
        . "ai_scan_error_page(405, 'Dit adres werkt alleen vanuit het contactformulier', 'x', '/#contact', 'Naar het contactformulier');"
    ));
    assert_true((bool) preg_match('/<h1 style="([^"]*)">Dit adres werkt alleen vanuit het contactformulier<\/h1>/', $html, $m), 'h1 zonder stijl');
    assert_true(str_contains($m[1], 'overflow-wrap:break-word'), 'geen woordafbreking');
    assert_true((bool) preg_match('/font-size:clamp\([\d.]+rem,[\d.]+vw,[\d.]+rem\)/', $m[1]), 'kopgrootte schaalt niet mee');
});
