<?php

declare(strict_types=1);

/**
 * Eenmalige opschoning, via de deploy over ssh (stdin) uitgevoerd. Nooit in de webroot.
 * Env: OPS_ROOT (webroot), OPS_JOB (markernaam), OPS_MODE (cancel_pending|lift),
 *      OPS_EMAIL, OPS_WEBSITE. Print alleen aantallen.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (is_array($e) && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo 'ops fatal: ' . substr((string) $e['message'], 0, 300) . ' @' . basename((string) $e['file']) . ':' . (int) $e['line'] . "\n";
    }
});
set_exception_handler(static function (Throwable $e): void {
    echo 'ops exception: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 300) . ' @' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    exit(1);
});

$root = (string) getenv('OPS_ROOT');
$job = preg_replace('/[^a-z0-9-]/', '', (string) getenv('OPS_JOB')) ?? '';
$mode = (string) getenv('OPS_MODE');
if ($root === '' || $job === '' || !in_array($mode, ['cancel_pending', 'lift', 'purge_test', 'set_tier'], true)) {
    fwrite(STDERR, "ops: OPS_ROOT/OPS_JOB/OPS_MODE ontbreekt\n");
    exit(1);
}

require_once rtrim($root, '/') . '/lib/bootstrap.php';
[$config, $pdo] = ai_scan_boot();

$sqlite = (string) ($config['sqlite_path'] ?? '');
$markerDir = dirname($sqlite) . '/ops-markers';
$marker = $markerDir . '/' . $job . '.done';
if (is_file($marker)) {
    echo "ops {$job}: al uitgevoerd, overgeslagen\n";
    exit(0);
}

$backup = $sqlite . '.bak-' . gmdate('Ymd-His');
if (!copy($sqlite, $backup)) {
    fwrite(STDERR, "ops {$job}: backup mislukt, gestopt\n");
    exit(1);
}
@chmod($backup, 0600);
echo "ops {$job}: backup ok\n";

$email = (string) getenv('OPS_EMAIL');
$website = (string) getenv('OPS_WEBSITE');

$pdo->beginTransaction();
try {
    if ($mode === 'cancel_pending') {
        $r = ai_scan_admin_cancel_pending($pdo, $email, $website);
        $summary = 'scans=' . (int) ($r['scans'] ?? 0) . ' ip_blocks=' . (int) ($r['ip_blocks'] ?? 0);
    } elseif ($mode === 'set_tier') {
        $rawEmail = strtolower(trim((string) getenv('OPS_EMAIL')));
        $eh = ai_scan_email_hash(ai_scan_normalize_email($rawEmail));
        $sel = $pdo->prepare('SELECT id FROM users WHERE email_hash = :e OR lower(email) = :raw');
        $sel->execute(['e' => $eh, 'raw' => $rawEmail]);
        $byEmail = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
        $byDomain = [];
        $site = (string) getenv('OPS_WEBSITE');
        if ($site !== '') {
            $parsed = ai_scan_parse_website($site);
            $d = $pdo->prepare('SELECT DISTINCT user_id FROM scans WHERE domain_hash = :d AND user_id IS NOT NULL');
            $d->execute(['d' => ai_scan_domain_hash((string) $parsed['domain'])]);
            $byDomain = array_map('intval', $d->fetchAll(PDO::FETCH_COLUMN));
        }
        echo "ops {$job}: users_via_email=" . count($byEmail) . ' users_via_domein=' . count($byDomain)
            . ' overlap=' . count(array_intersect($byEmail, $byDomain)) . "\n";
        $ids = $byEmail !== [] ? $byEmail : $byDomain;
        if (count($ids) !== 1 || ($byEmail !== [] && $byDomain !== [] && array_intersect($byEmail, $byDomain) === [])) {
            $r = ['ok' => false];
            $summary = 'users_gevonden=' . count($ids);
        } else {
            echo "ops {$job}: set_tier start\n";
            // Geen klantmail vanuit de deploy (de SMTP-verbinding vanuit CLI brak het proces af).
            $r = ai_scan_admin_set_tier($pdo, array_replace($config, ['smtp' => []]), (int) $ids[0], [
                'tier_id' => (string) getenv('OPS_TIER'),
                'subscription_status' => 'active',
                'custom_questions' => null,
                'billing_period' => null,
                'period_end' => null,
            ], 'admin');
            $summary = 'users=1 tier_set=' . (!empty($r['ok']) ? 1 : 0) . ' paused=' . (int) ($r['paused'] ?? 0) . ' mailed=' . (!empty($r['mailed']) ? 1 : 0);
        }
    } elseif ($mode === 'purge_test') {
        $r = ai_scan_ops_purge_test_data($pdo, (string) getenv('OPS_EMAIL'), (string) getenv('OPS_WEBSITE'), (int) getenv('OPS_USER_CREATED_FROM'), (int) getenv('OPS_USER_CREATED_TO'));
        $summary = 'blocks=' . (int) ($r['blocks'] ?? 0) . ' scans=' . (int) ($r['scans'] ?? 0) . ' users=' . (int) ($r['users'] ?? 0) . ' users_overgeslagen=' . (int) ($r['user_skipped'] ?? 0);
    } else {
        $r = ai_scan_admin_lift_free_scan_block($pdo, $email, $website, 'deploy');
        $summary = 'blocks=' . (int) ($r['deleted'] ?? 0);
    }
    if (empty($r['ok'])) {
        throw new RuntimeException('invoer ongeldig');
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "ops {$job}: mislukt, teruggedraaid (" . get_class($e) . ': ' . substr($e->getMessage(), 0, 200) . ")\n";
    exit(1);
}

$left = ai_scan_count_free_scan_blocks($pdo, $email, $website);
$pending = 0;
foreach (['email' => $email] as $_) {
    $e = trim($email) !== '' ? ai_scan_email_hash(ai_scan_normalize_email(trim($email))) : '';
    $p = ai_scan_parse_website($website);
    $d = !empty($p['ok']) ? ai_scan_domain_hash((string) $p['domain']) : '';
    $s = $pdo->prepare("SELECT COUNT(*) FROM scans WHERE (:e <> '' AND email_hash = :e2) OR (:d <> '' AND domain_hash = :d2)");
    $s->execute(['e' => $e, 'e2' => $e, 'd' => $d, 'd2' => $d]);
    $pending = (int) $s->fetchColumn();
}

if (!is_dir($markerDir)) {
    mkdir($markerDir, 0700, true);
}
file_put_contents($marker, gmdate('c') . "\n");
$u = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email_hash = :e');
$u->execute(['e' => trim($email) !== '' ? ai_scan_email_hash(ai_scan_normalize_email(trim($email))) : '']);
echo "ops {$job}: {$summary}; resterend blocks={$left} scans={$pending} users=" . (int) $u->fetchColumn() . "\n";
