<?php

declare(strict_types=1);

/**
 * Promo/site drift checks for tiers.json (docs/tiers.md §Drift-test: 3, 4)
 * plus fallback table in ai-vindbaarheid/index.html == tiers.json.
 * Included from tests/run.php.
 */

/** @var callable $test */
/** @var callable $assert_true */
/** @var callable $assert_same */

test('pricing.json is gone (drift 4)', static function (): void {
    $root = dirname(__DIR__);
    assert_true(
        !is_file($root . '/assets/data/pricing.json'),
        'assets/data/pricing.json must not exist; use tiers.json'
    );
});

test('promo JS fetches tiers.json and has no hardcoded tier amounts (drift 3)', static function (): void {
    $root = dirname(__DIR__);
    $jsPath = $root . '/ai-vindbaarheid/ai-vindbaarheid.js';
    assert_true(is_file($jsPath), 'ai-vindbaarheid.js missing');
    $js = (string) file_get_contents($jsPath);

    assert_true(
        str_contains($js, 'fetch("/assets/data/tiers.json"'),
        'ai-vindbaarheid.js must fetch /assets/data/tiers.json'
    );
    assert_true(
        !str_contains($js, 'pricing.json'),
        'ai-vindbaarheid.js must not reference pricing.json'
    );

    $forbidden = ['€19', '€35', '€49', '€65', '€89', '€109', '€129', '€179', '€239', '€339', '€429'];
    $hits = [];
    foreach ($forbidden as $needle) {
        if (str_contains($js, $needle)) {
            $hits[] = $needle;
        }
    }
    assert_same([], $hits, 'ai-vindbaarheid.js must not hardcode tier euro amounts');
});

test('fallback table in index.html equals tiers.json', static function (): void {
    $root = dirname(__DIR__);
    $htmlPath = $root . '/ai-vindbaarheid/index.html';
    $tiersPath = $root . '/assets/data/tiers.json';
    assert_true(is_file($htmlPath), 'index.html missing');
    assert_true(is_file($tiersPath), 'tiers.json missing');

    $tiersData = json_decode((string) file_get_contents($tiersPath), true, 512, JSON_THROW_ON_ERROR);
    assert_true(is_array($tiersData['tiers'] ?? null) && $tiersData['tiers'] !== []);

    $html = (string) file_get_contents($htmlPath);
    $start = strpos($html, 'data-pricing-table-body');
    assert_true($start !== false, 'fallback tbody data-pricing-table-body missing');
    $tbodyOpen = strpos($html, '>', $start);
    assert_true($tbodyOpen !== false);
    $tbodyClose = strpos($html, '</tbody>', $tbodyOpen);
    assert_true($tbodyClose !== false);
    $tbody = substr($html, $tbodyOpen + 1, $tbodyClose - $tbodyOpen - 1);

    $dom = new DOMDocument();
    $wrapped = '<?xml encoding="UTF-8"><table><tbody>' . $tbody . '</tbody></table>';
    assert_true(@$dom->loadHTML($wrapped), 'could not parse fallback table body');
    $rows = $dom->getElementsByTagName('tr');
    assert_true($rows->length >= 2, 'fallback table has too few rows');

    $formatEuro = static function (int $amount): string {
        return '€' . number_format($amount, 0, ',', '.');
    };

    $tierRows = [];
    $aboveLabel = null;
    foreach ($rows as $row) {
        /** @var DOMElement $row */
        $th = $row->getElementsByTagName('th')->item(0);
        $cells = $row->getElementsByTagName('td');
        if (!($th instanceof DOMElement)) {
            continue;
        }
        $key = trim($th->textContent);
        if ($key === '1000+') {
            $aboveLabel = trim($cells->item(0)?->textContent ?? '');
            continue;
        }
        if (!ctype_digit($key)) {
            continue;
        }
        assert_same(6, $cells->length, 'tier row ' . $key . ' must have 6 data cells');
        $tierRows[(int) $key] = [
            'monthly' => trim($cells->item(0)->textContent),
            'actions' => trim($cells->item(1)->textContent),
            'annual' => trim($cells->item(2)->textContent),
            'c3' => trim($cells->item(3)->textContent),
            'c6' => trim($cells->item(4)->textContent),
            'c10' => trim($cells->item(5)->textContent),
        ];
    }

    $expectedKeys = [];
    foreach ($tiersData['tiers'] as $tier) {
        $q = (int) $tier['questions'];
        $expectedKeys[] = $q;
        assert_true(isset($tierRows[$q]), 'fallback table missing questions=' . $q);
        $got = $tierRows[$q];
        assert_same($formatEuro((int) $tier['monthly']), $got['monthly'], 'monthly q' . $q);
        assert_same((string) (int) $tier['actions'], $got['actions'], 'actions q' . $q);
        assert_same($formatEuro((int) $tier['annual']), $got['annual'], 'annual q' . $q);
        assert_same($formatEuro((int) $tier['compleet']['3']), $got['c3'], 'compleet 3u q' . $q);
        assert_same($formatEuro((int) $tier['compleet']['6']), $got['c6'], 'compleet 6u q' . $q);
        assert_same($formatEuro((int) $tier['compleet']['10']), $got['c10'], 'compleet 10u q' . $q);
    }

    assert_same($expectedKeys, array_keys($tierRows), 'fallback table questions must match tiers.json order');
    $requestLabel = (string) ($tiersData['above_max']['request_label'] ?? 'op aanvraag');
    assert_same($requestLabel, $aboveLabel, '1000+ row must use above_max.request_label');
});
