<?php

declare(strict_types=1);

/**
 * .htaccess: interne includes, mapindexen en docs/ geven de Nederlandse 404.
 * Included from tests/run.php.
 */

/** @return list<string> patterns of all `RedirectMatch 404` rules */
function htaccess_404_patterns(): array
{
    $src = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
    preg_match_all('/^RedirectMatch\s+404\s+(\S+)\s*$/m', $src, $m);

    return $m[1];
}

function htaccess_404_blocks(string $path): bool
{
    foreach (htaccess_404_patterns() as $pattern) {
        $re = '~' . str_replace('~', '\~', $pattern) . '~';
        if (preg_match($re, $path) === 1) {
            return true;
        }
    }

    return false;
}

test('htaccess: interne app-includes en settings-card geven 404', static function (): void {
    foreach ([
        '/app/nav.php', '/app/layout.php', '/app/_init.php', '/app/_action_card.php',
        '/app/gsc/settings-card.php',
    ] as $path) {
        assert_true(htaccess_404_blocks($path), $path . ' must 404');
    }
});

test('htaccess: mapindex admin en app/gsc en docs geven 404', static function (): void {
    foreach (['/admin/', '/admin', '/app/gsc/', '/app/gsc', '/docs/', '/docs', '/docs/tiers.md'] as $path) {
        assert_true(htaccess_404_blocks($path), $path . ' must 404');
    }
});

test('htaccess: echte pagina en endpoints blijven bereikbaar', static function (): void {
    foreach ([
        '/admin/scans.php', '/admin/tier.php', '/admin/blokkade.php',
        '/app/', '/app/overzicht.php', '/app/gsc/callback.php', '/app/gsc/connect.php',
        '/app/gsc/disconnect.php', '/app/gsc/preview.php', '/app/confirm-email.php',
    ] as $path) {
        assert_true(!htaccess_404_blocks($path), $path . ' must stay reachable');
    }
});
