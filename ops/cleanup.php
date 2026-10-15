<?php

declare(strict_types=1);

/**
 * Eenmalige opschoning, via de deploy over ssh (stdin) uitgevoerd. Nooit in de webroot.
 * Env: OPS_ROOT (webroot), OPS_JOB (markernaam), OPS_MODE (cancel_pending|lift|purge_gsc_preview),
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
if ($root === '' || $job === '' || !in_array($mode, ['cancel_pending', 'lift', 'purge_test', 'set_tier', 'set_profile', 'count_user', 'replace_competitor', 'create_test_user', 'create_smooth_demo', 'demo_drop_question', 'purge_gsc_preview'], true)) {
    fwrite(STDERR, "ops: OPS_ROOT/OPS_JOB/OPS_MODE ontbreekt\n");
    exit(1);
}

require_once rtrim($root, '/') . '/lib/bootstrap.php';
require_once rtrim($root, '/') . '/lib/questions.php';
[$config, $pdo] = ai_scan_boot();

$sqlite = (string) ($config['sqlite_path'] ?? '');
$markerDir = dirname($sqlite) . '/ops-markers';
$marker = $markerDir . '/' . $job . '.done';
if ($mode === 'count_user') {
    // Alleen-lezen diagnose: bestaat er een account (met scan) voor dit adres? Print alleen aantallen.
    $eh = ai_scan_email_hash(ai_scan_normalize_email(trim((string) getenv('OPS_EMAIL'))));
    $u = $pdo->prepare('SELECT id FROM users WHERE email_hash = :e');
    $u->execute(['e' => $eh]);
    $ids = array_map('intval', $u->fetchAll(PDO::FETCH_COLUMN));
    $scans = 0;
    foreach ($ids as $id) { $scans += ai_scan_latest_for_user($pdo, $id) === null ? 0 : 1; }
    echo "ops {$job}: users=" . count($ids) . " users_met_scan={$scans}\n";
    exit(0);
}
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
    } elseif ($mode === 'set_profile') {
        // Vult alleen lege bedrijfsvelden en voegt ontbrekende concurrenten toe; verwijdert niets.
        $eh = ai_scan_email_hash(ai_scan_normalize_email(trim((string) getenv('OPS_EMAIL'))));
        $sel = $pdo->prepare('SELECT id FROM users WHERE email_hash = :e');
        $sel->execute(['e' => $eh]);
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
        $data = json_decode((string) base64_decode((string) getenv('OPS_PROFILE_B64'), true), true);
        $byEmail = count($ids);
        if ($byEmail === 0 && $website !== '') {
            // Account op e-mail niet gevonden: zoek via de scan van dit domein (precies één gebruiker).
            $pw = ai_scan_parse_website($website);
            $dh = !empty($pw['ok']) ? ai_scan_domain_hash((string) $pw['domain']) : '';
            $sd = $pdo->prepare('SELECT DISTINCT user_id FROM scans WHERE domain_hash = :d AND user_id IS NOT NULL');
            $sd->execute(['d' => $dh]);
            $ids = $sd->fetchAll(PDO::FETCH_COLUMN);
        }
        echo "ops {$job}: users_op_email={$byEmail} users_gevonden=" . count($ids) . ' json=' . (is_array($data) ? 'ok' : 'fout') . "\n";
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
            // Laatste afgeronde scan opnieuw in de wachtrij (vragen ontbraken in het resultaat); geen mail opnieuw.
            $requeued = 0;
            if ((string) getenv('OPS_REQUEUE') === '1') {
                $last = $pdo->prepare("SELECT id FROM scans WHERE user_id = :u AND status = 'klaar' ORDER BY id DESC LIMIT 1");
                $last->execute(['u' => $uid]);
                $sid = $last->fetchColumn();
                if ($sid !== false) {
                    $q = $pdo->prepare("UPDATE scans SET status = 'bevestigd', queued_at = :t WHERE id = :id AND status = 'klaar'");
                    $q->execute(['t' => $now, 'id' => (int) $sid]);
                    $requeued = $q->rowCount();
                }
            }
            $r = ['ok' => true];
            $summary = 'requeued=' . $requeued . ' users=1 velden_gevuld=' . $filled . ' concurrenten_toegevoegd=' . $added . ' concurrenten_totaal=' . count($names);
        }
    } elseif ($mode === 'replace_competitor') {
        // Vervangt de actieve concurrent die het minst genoemd is (gelijk: oudste) door OPS_NEW_NAME/OPS_NEW_DOMAIN.
        $eh = ai_scan_email_hash(ai_scan_normalize_email(trim((string) getenv('OPS_EMAIL'))));
        $sel = $pdo->prepare('SELECT id FROM users WHERE email_hash = :e');
        $sel->execute(['e' => $eh]);
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === [] && $website !== '') {
            $pw = ai_scan_parse_website($website);
            $sd = $pdo->prepare('SELECT DISTINCT user_id FROM scans WHERE domain_hash = :d AND user_id IS NOT NULL');
            $sd->execute(['d' => !empty($pw['ok']) ? ai_scan_domain_hash((string) $pw['domain']) : '']);
            $ids = $sd->fetchAll(PDO::FETCH_COLUMN);
        }
        $new = trim((string) getenv('OPS_NEW_NAME'));
        $newDomain = trim((string) getenv('OPS_NEW_DOMAIN'));
        if (count($ids) !== 1 || $new === '') {
            $r = ['ok' => false];
            $summary = 'users_gevonden=' . count($ids);
        } else {
            $uid = (int) $ids[0];
            $dup = $pdo->prepare("SELECT COUNT(*) FROM competitors WHERE user_id = :u AND LOWER(name) = :n AND status = 'active'");
            $dup->execute(['u' => $uid, 'n' => mb_strtolower($new)]);
            if ((int) $dup->fetchColumn() > 0) {
                $r = ['ok' => true];
                $summary = 'al_aanwezig=1';
            } else {
                $low = $pdo->prepare("SELECT id, name, mentioned_count FROM competitors WHERE user_id = :u AND status = 'active' ORDER BY mentioned_count ASC, created_at ASC, id ASC LIMIT 1");
                $low->execute(['u' => $uid]);
                $out = $low->fetch();
                $now = time();
                if ($out !== false) {
                    $pdo->prepare('DELETE FROM competitors WHERE id = :id')->execute(['id' => (int) $out['id']]);
                    echo "ops {$job}: eruit=" . $out['name'] . ' genoemd=' . (int) $out['mentioned_count'] . "\n";
                }
                $pdo->prepare(
                    'INSERT INTO competitors(user_id, name, aliases_json, domains_json, source, status, mentioned_count, created_at, updated_at)
                     VALUES(:u, :n, :a, :d, \'scan\', \'active\', 0, :c, :c)'
                )->execute(['u' => $uid, 'n' => $new, 'a' => json_encode([$new]), 'd' => json_encode($newDomain !== '' ? [$newDomain] : []), 'c' => $now]);
                $r = ['ok' => true];
                $summary = 'vervangen=' . ($out !== false ? 1 : 0) . ' toegevoegd=1';
            }
        }
    } elseif ($mode === 'create_smooth_demo') {
        require_once (string) getenv('OPS_DEMO_LIB');
        $data = json_decode((string) file_get_contents((string) getenv('OPS_DEMO_FILE')), true, 512, JSON_THROW_ON_ERROR);
        smooth_demo_create($pdo, strtolower(trim($email)), $data);
        $r = ['ok' => true];
        $summary = 'users=1 scans=1 vragen=25 mail=0';
    } elseif ($mode === 'create_test_user') {
        // Testaccount zonder scanrun: users-rij + niet-uitvoerbare scanrij (status test_geen_scan), geen mail.
        $em = strtolower(trim((string) getenv('OPS_EMAIL')));
        $eh = ai_scan_email_hash(ai_scan_normalize_email($em));
        $pw = ai_scan_parse_website($website);
        $ex = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email_hash = :e');
        $ex->execute(['e' => $eh]);
        if ($em === '' || empty($pw['ok']) || (int) $ex->fetchColumn() > 0) {
            $r = ['ok' => false];
            $summary = 'input_of_bestaat';
        } else {
            $now = time();
            $pdo->prepare('INSERT INTO users(email, email_hash, password_hash, created_at, free_scan_used) VALUES(:e, :h, NULL, :c, 1)')
                ->execute(['e' => $em, 'h' => $eh, 'c' => $now]);
            $uid = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO scans(public_id, user_id, email, email_hash, website_url, domain, domain_hash, status, created_at)
                 VALUES(:p, :u, :e, :h, :w, :d, :dh, 'test_geen_scan', :c)"
            )->execute(['p' => ai_scan_random_token(16), 'u' => $uid, 'e' => $em, 'h' => $eh, 'w' => (string) $pw['url'],
                'd' => (string) $pw['domain'], 'dh' => ai_scan_domain_hash((string) $pw['domain']), 'c' => $now]);
            $r = ['ok' => true];
            $summary = 'users=1 scans=1 created_at=' . $now;
        }
    } elseif ($mode === 'demo_drop_question') {
        // Alleen demo-accounts: verberg één vaste vraag (tekst bevat OPS_MATCH). Meetdata blijft ongewijzigd.
        $eh = ai_scan_email_hash(ai_scan_normalize_email(trim((string) getenv('OPS_EMAIL'))));
        $match = trim((string) getenv('OPS_MATCH'));
        $sel = $pdo->prepare('SELECT id FROM users WHERE email_hash = :e');
        $sel->execute(['e' => $eh]);
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN);
        $scan = count($ids) === 1 ? ai_scan_latest_for_user($pdo, (int) $ids[0]) : null;
        $res = $scan !== null ? json_decode((string) ($scan['result_json'] ?? ''), true) : null;
        if (mb_strlen($match) < 5 || !is_array($res) || empty($res['demo'])) {
            $r = ['ok' => false];
            $summary = 'geen_demo_of_match';
        } else {
            $uid = (int) $ids[0];
            $q = $pdo->prepare("UPDATE questions SET status = 'deleted', updated_at = :t WHERE user_id = :u AND status != 'deleted' AND text LIKE :m");
            $q->execute(['t' => time(), 'u' => $uid, 'm' => '%' . $match . '%']);
            $hidden = $q->rowCount();
            $before = count($res['questions']['core'] ?? []);
            $res['questions']['core'] = array_values(array_filter($res['questions']['core'] ?? [],
                static fn ($x): bool => !str_contains((string) (is_array($x) ? ($x['text'] ?? '') : $x), $match)));
            $pdo->prepare('UPDATE scans SET result_json = :j WHERE id = :id')
                ->execute(['j' => json_encode($res, JSON_UNESCAPED_UNICODE), 'id' => (int) $scan['id']]);
            $r = ['ok' => $hidden <= 1];
            $summary = 'verborgen=' . $hidden . ' result_core=' . $before . '->' . count($res['questions']['core']);
        }
    } elseif ($mode === 'purge_gsc_preview') {
        // Vaste allowlist (nooit OPS_EMAIL): alleen de GSC-previewaccounts en hun gsc_connections.
        $users = 0;
        $conns = 0;
        foreach (['preview-gsc-connected@example.nl', 'preview-gsc-disconnected@example.nl', 'preview-gsc-free@example.nl'] as $fixed) {
            $sel = $pdo->prepare('SELECT id FROM users WHERE email_hash = :e OR lower(email) = :raw');
            $sel->execute(['e' => ai_scan_email_hash(ai_scan_normalize_email($fixed)), 'raw' => $fixed]);
            foreach (array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN)) as $uid) {
                $c = $pdo->prepare('DELETE FROM gsc_connections WHERE user_id = :u');
                $c->execute(['u' => $uid]);
                $conns += $c->rowCount();
                $d = $pdo->prepare('DELETE FROM users WHERE id = :u');
                $d->execute(['u' => $uid]);
                $users += $d->rowCount();
            }
        }
        $r = ['ok' => true];
        $summary = 'users=' . $users . ' gsc_connections=' . $conns . ' require_http=' . (!empty($config['website_require_http']) ? 1 : 0);
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
