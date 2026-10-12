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

$root = (string) getenv('OPS_ROOT');
$job = preg_replace('/[^a-z0-9-]/', '', (string) getenv('OPS_JOB')) ?? '';
$mode = (string) getenv('OPS_MODE');
if ($root === '' || $job === '' || !in_array($mode, ['cancel_pending', 'lift', 'purge_test', 'set_tier', 'set_profile'], true)) {
    fwrite(STDERR, "ops: OPS_ROOT/OPS_JOB/OPS_MODE ontbreekt\n");
    exit(1);
}

require_once rtrim($root, '/') . '/lib/bootstrap.php';
require_once rtrim($root, '/') . '/lib/questions.php';
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
        $eh = ai_scan_email_hash(ai_scan_normalize_email(trim((string) getenv('OPS_EMAIL'))));
        $sel = $pdo->prepare('SELECT id FROM users WHERE email_hash = :e');
        $sel->execute(['e' => $eh]);
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) {
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
    } elseif ($mode === 'set_profile') {
        // Vult alleen lege bedrijfsvelden en voegt ontbrekende concurrenten toe; verwijdert niets.
        $eh = ai_scan_email_hash(ai_scan_normalize_email(trim((string) getenv('OPS_EMAIL'))));
        $sel = $pdo->prepare('SELECT id FROM users WHERE email_hash = :e');
        $sel->execute(['e' => $eh]);
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
        $data = json_decode((string) base64_decode((string) getenv('OPS_PROFILE_B64'), true), true);
        if (count($ids) !== 1 || !is_array($data)) {
            $r = ['ok' => false];
            $summary = 'users_gevonden=' . count($ids);
        } else {
            $uid = (int) $ids[0];
            ai_scan_ensure_questions_schema($pdo);
            $now = time();
            $row = $pdo->prepare('SELECT * FROM account_profile WHERE user_id = :u');
            $row->execute(['u' => $uid]);
            $cur = $row->fetch() ?: [];
            $want = [
                'company' => (string) ($data['company'] ?? ''),
                'aliases_json' => json_encode(array_values($data['brand_aliases'] ?? []), JSON_UNESCAPED_UNICODE),
                'services_json' => json_encode(array_values($data['services'] ?? []), JSON_UNESCAPED_UNICODE),
                'places_json' => json_encode(array_values($data['locations'] ?? []), JSON_UNESCAPED_UNICODE),
                'audience' => (string) ($data['audience'] ?? ''),
            ];
            $filled = 0;
            if ($cur === []) {
                $pdo->prepare('INSERT INTO account_profile(user_id, updated_at) VALUES(:u, :t)')->execute(['u' => $uid, 't' => $now]);
            }
            foreach ($want as $col => $val) {
                $old = (string) ($cur[$col] ?? '');
                if (($old === '' || $old === '[]' || $old === 'null') && $val !== '' && $val !== '[]') {
                    $pdo->prepare("UPDATE account_profile SET {$col} = :v, updated_at = :t WHERE user_id = :u")
                        ->execute(['v' => $val, 't' => $now, 'u' => $uid]);
                    $filled++;
                }
            }
            $added = 0;
            $have = $pdo->prepare('SELECT LOWER(name) FROM competitors WHERE user_id = :u');
            $have->execute(['u' => $uid]);
            $names = $have->fetchAll(PDO::FETCH_COLUMN);
            $ins = $pdo->prepare(
                'INSERT INTO competitors(user_id, name, aliases_json, domains_json, source, status, mentioned_count, created_at, updated_at)
                 VALUES(:u, :n, :a, :d, :s, \'active\', 0, :c, :c)'
            );
            foreach (($data['competitors']['list'] ?? []) as $c) {
                $n = trim((string) ($c['name'] ?? ''));
                if ($n === '' || in_array(mb_strtolower($n), $names, true) || count($names) >= 10) {
                    continue;
                }
                $ins->execute(['u' => $uid, 'n' => $n, 'a' => json_encode($c['aliases'] ?? []), 'd' => json_encode($c['domains'] ?? []), 's' => 'scan', 'c' => $now]);
                $names[] = mb_strtolower($n);
                $added++;
            }
            $r = ['ok' => true];
            $summary = 'users=1 velden_gevuld=' . $filled . ' concurrenten_toegevoegd=' . $added . ' concurrenten_totaal=' . count($names);
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
    fwrite(STDERR, "ops {$job}: mislukt, teruggedraaid (" . get_class($e) . ": " . $e->getMessage() . ")\n");
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
