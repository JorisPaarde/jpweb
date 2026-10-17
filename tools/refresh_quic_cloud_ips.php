<?php

declare(strict_types=1);

/**
 * Regenerates lib/quic_cloud_ips.php from the official QUIC.cloud IP lists.
 *
 * Usage (by hand, never during a web request):
 *   php tools/refresh_quic_cloud_ips.php                 # fetch the live lists
 *   php tools/refresh_quic_cloud_ips.php path/to/ips.txt # use a saved list (one IP per line)
 *
 * Sources: https://api.quic.cloud/ips and https://www.quic.cloud/ips-all?trusted=
 * Review the diff, then run `php tests/run.php` before committing.
 */

$args = array_slice($argv, 1);
if ($args !== []) {
    $raw = (string) file_get_contents($args[0]);
} else {
    $raw = '';
    foreach (['https://api.quic.cloud/ips', 'https://www.quic.cloud/ips-all?trusted='] as $url) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
        if ($body === false) {
            fwrite(STDERR, "Could not fetch {$url}\n");
            exit(1);
        }
        $raw .= "\n" . $body;
    }
}

$ips = [];
foreach (preg_split('/[\s,"\[\]]+/', $raw) ?: [] as $item) {
    if ($item !== '' && filter_var($item, FILTER_VALIDATE_IP) !== false) {
        $ips[(string) inet_ntop((string) inet_pton($item))] = true;
    }
}
$ips = array_keys($ips);
sort($ips, SORT_STRING);

if (count($ips) < 50) {
    fwrite(STDERR, 'Only ' . count($ips) . " addresses found; refusing to write.\n");
    exit(1);
}

$out = "<?php\n\ndeclare(strict_types=1);\n\n"
    . "/**\n * QUIC.cloud node IPs, fixed list. Never fetched during a request.\n"
    . " * Sources: https://api.quic.cloud/ips and https://www.quic.cloud/ips-all?trusted=\n"
    . ' * Snapshot: ' . date('Y-m-d') . ' (' . count($ips) . " addresses).\n"
    . " * Refresh: php tools/refresh_quic_cloud_ips.php\n */\n\nreturn [\n";
foreach ($ips as $ip) {
    $out .= "    '{$ip}',\n";
}
$out .= "];\n";

file_put_contents(dirname(__DIR__) . '/lib/quic_cloud_ips.php', $out);
echo 'Wrote ' . count($ips) . " addresses to lib/quic_cloud_ips.php\n";
