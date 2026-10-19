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

test('N19-3: docs en code noemen dezelfde stale-drempel (45 min / 2700) en alle config-sleutels', static function (): void {
    $root = dirname(__DIR__);
    $default = (int) ai_scan_default_config('')['stale_job_seconds'];
    assert_same(2700, $default);
    $docs = (string) file_get_contents($root . '/docs/ai-scan-api.md');
    assert_true(str_contains($docs, '**45 minuten** (`stale_job_seconds`, default `2700`)'), 'stale-alinea');
    assert_true(!str_contains($docs, '1800') && !str_contains($docs, '>30 min'), 'oude 30 min/1800 staat er nog');
    assert_true(!str_contains((string) file_get_contents($root . '/lib/stale_jobs.php'), 'default 30 min'), 'commentaar stale_jobs.php');
    foreach (['scan_max_retries', 'internal_test_emails'] as $key) {
        assert_true((bool) preg_match('/^Keys: .*`' . $key . '`/m', $docs), 'sleutellijst mist ' . $key);
    }
});
