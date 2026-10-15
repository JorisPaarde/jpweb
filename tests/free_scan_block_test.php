<?php

declare(strict_types=1);

test('admin lift free-scan block by email and domain', static function (): void {
    [$config, $pdo] = boot_test_db();
    $ins = $pdo->prepare('INSERT INTO free_scan_blocks(email_hash, domain_hash, created_at) VALUES(:e, :d, 1)');
    $ins->execute(['e' => ai_scan_email_hash('a@example.nl'), 'd' => ai_scan_domain_hash('example.nl')]);
    $ins->execute(['e' => ai_scan_email_hash('keep@other.nl'), 'd' => ai_scan_domain_hash('other.nl')]);

    assert_same(1, ai_scan_count_free_scan_blocks($pdo, 'A@Example.nl', ''));
    assert_same(1, ai_scan_count_free_scan_blocks($pdo, '', 'https://www.example.nl/x'));

    $r = ai_scan_admin_lift_free_scan_block($pdo, '', 'shop.example.nl', 'info@jpwebcreation.nl');
    assert_true($r['ok']);
    assert_same(1, $r['deleted']);
    assert_same(0, ai_scan_count_free_scan_blocks($pdo, 'a@example.nl', 'example.nl'));
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM free_scan_blocks')->fetchColumn());

    $r = ai_scan_admin_lift_free_scan_block($pdo, 'keep@other.nl', '');
    assert_same(1, $r['deleted']);
});

test('admin lift free-scan block rejects empty or invalid input', static function (): void {
    [, $pdo] = boot_test_db();
    assert_same('empty', ai_scan_admin_lift_free_scan_block($pdo, '', '')['error']);
    assert_same('email', ai_scan_admin_lift_free_scan_block($pdo, 'geen-mail', '')['error']);
    assert_same('domain', ai_scan_admin_lift_free_scan_block($pdo, '', 'niet geldig')['error']);
});

test('admin block page requires admin, csrf and logs', static function (): void {
    $src = (string) file_get_contents(dirname(__DIR__) . '/admin/blokkade.php');
    assert_true(str_contains($src, 'ai_scan_require_admin_user'));
    assert_true(str_contains($src, 'ai_scan_verify_csrf'));
    assert_true(str_contains($src, 'noindex'));
});

test('one free scan per IP (keyed hash, survives confirm, lifted with email/domain)', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $now = time();
    $r1 = ai_scan_submit($pdo, $config, ['email' => 'ip1@firma.nl', 'website' => 'https://firma.nl', 'consent' => '1'], '5.6.7.8', $now);
    assert_same('check_mail', $r1['code']);
    // pending scan from same IP blocks a different email/domain
    $r2 = ai_scan_submit($pdo, $config, ['email' => 'ip2@ander.nl', 'website' => 'https://ander.nl', 'consent' => '1'], '5.6.7.8', $now);
    assert_same('ip_already_scanned', $r2['code']);

    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h WHERE email = :e')
        ->execute(['h' => ai_scan_hash_token('tip'), 'e' => 'ip1@firma.nl']);
    assert_same('confirmed', ai_scan_confirm($pdo, $config, 'tip', $now)['code']);
    $hash = ai_scan_ip_block_hash($config, '5.6.7.8');
    assert_true(strlen($hash) === 64 && $hash !== hash('sha256', '5.6.7.8') && $hash !== ai_scan_ip_hash('5.6.7.8'));
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM free_scan_blocks WHERE ip_hash = '" . $hash . "'")->fetchColumn());
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM scans WHERE ip_block_hash IS NOT NULL')->fetchColumn());

    $r3 = ai_scan_submit($pdo, $config, ['email' => 'ip3@derde.nl', 'website' => 'https://derde.nl', 'consent' => '1'], '5.6.7.8', $now + 7200);
    assert_same('ip_already_scanned', $r3['code']);
    // other IP still fine
    $r4 = ai_scan_submit($pdo, $config, ['email' => 'ip4@vierde.nl', 'website' => 'https://vierde.nl', 'consent' => '1'], '5.6.7.9', $now + 7200);
    assert_same('check_mail', $r4['code']);

    // IPv6: same /64 counts as one
    assert_same(ai_scan_ip_block_hash($config, '2001:db8:1:2::1'), ai_scan_ip_block_hash($config, '2001:db8:1:2:ffff::9'));
    assert_same('', ai_scan_ip_block_hash($config, ''));

    // admin lift by email removes the IP block too (same row)
    ai_scan_admin_lift_free_scan_block($pdo, 'ip1@firma.nl', 'firma.nl');
    $pdo->exec("DELETE FROM scans WHERE email = 'ip1@firma.nl'");
    $r5 = ai_scan_submit($pdo, $config, ['email' => 'ip5@vijfde.nl', 'website' => 'https://vijfde.nl', 'consent' => '1'], '5.6.7.8', $now + 7300);
    assert_same('check_mail', $r5['code']);
});

test('admin cancel pending removes only pending scans and their ip block', static function (): void {
    [, $pdo] = boot_test_db();
    $eh = ai_scan_email_hash('t@agentmail.to');
    $dh = ai_scan_domain_hash('intake-engine.nl');
    $ins = $pdo->prepare("INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, ip_block_hash)
        VALUES(:p, 'x', :e, 'u', 'd', :d, :s, 1, :ip)");
    $ins->execute(['p' => 'a', 'e' => $eh, 'd' => $dh, 's' => 'onbevestigd', 'ip' => 'ipA']);
    $ins->execute(['p' => 'b', 'e' => ai_scan_email_hash('o@x.nl'), 'd' => ai_scan_domain_hash('x.nl'), 's' => 'onbevestigd', 'ip' => 'ipB']);
    $pdo->exec("INSERT INTO free_scan_blocks(ip_hash, created_at) VALUES('ipA', 1), ('ipB', 1)");

    $r = ai_scan_admin_cancel_pending($pdo, 't@agentmail.to', 'intake-engine.nl');
    assert_same(1, $r['scans']);
    assert_same(1, $r['ip_blocks']);
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
    assert_same('ipB', (string) $pdo->query('SELECT ip_hash FROM free_scan_blocks')->fetchColumn());
    assert_same('empty', ai_scan_admin_cancel_pending($pdo, '', '')['error']);
});

test('admin lift also removes ip blocks of scans for that email/domain', static function (): void {
    [, $pdo] = boot_test_db();
    $eh = ai_scan_email_hash('t@agentmail.to');
    $pdo->prepare("INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, ip_block_hash)
        VALUES('a', 'x', :e, 'u', 'd', 'dh', 'klaar', 1, 'ipA')")->execute(['e' => $eh]);
    $pdo->exec("INSERT INTO free_scan_blocks(ip_hash, created_at) VALUES('ipA', 1)");
    $r = ai_scan_admin_lift_free_scan_block($pdo, 't@agentmail.to', '');
    assert_same(1, $r['deleted']);
});

test('ops cleanup script is cli-only and kept out of the webroot', static function (): void {
    $root = dirname(__DIR__);
    $src = (string) file_get_contents($root . '/ops/cleanup.php');
    assert_true(str_contains($src, "PHP_SAPI !== 'cli'"));
    assert_true(str_contains($src, 'beginTransaction'));
    assert_true(str_contains($src, '.bak-'));
    $wf = (string) file_get_contents($root . '/.github/workflows/deploy.yml');
    assert_true(str_contains($wf, "--exclude='ops/'"));
});

test('ops purge test data removes blocks, scans and only the user created in window', static function (): void {
    [, $pdo] = boot_test_db();
    $eh = ai_scan_email_hash('jorispaarde@gmail.com');
    $dh = ai_scan_domain_hash('intake-engine.nl');
    $pdo->prepare("INSERT INTO users(email, email_hash, created_at, free_scan_used) VALUES('j', :e, 150, 1)")->execute(['e' => $eh]);
    $pdo->prepare("INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, ip_block_hash)
        VALUES('a', 'j', :e, 'u', 'd', :d, 'mislukt', 150, 'ipA')")->execute(['e' => $eh, 'd' => $dh]);
    $pdo->prepare("INSERT INTO free_scan_blocks(email_hash, domain_hash, ip_hash, created_at) VALUES(:e, :d, 'ipA', 150)")->execute(['e' => $eh, 'd' => $dh]);
    $pdo->exec("INSERT INTO free_scan_blocks(ip_hash, created_at) VALUES('ipZ', 1)");

    $r = ai_scan_ops_purge_test_data($pdo, 'jorispaarde@gmail.com', 'intake-engine.nl', 100, 200);
    assert_true($r['ok']);
    assert_same(1, $r['scans']);
    assert_same(1, $r['users']);
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM free_scan_blocks')->fetchColumn());

    $pdo->prepare("INSERT INTO users(email, email_hash, created_at, free_scan_used) VALUES('j', :e, 50, 1)")->execute(['e' => $eh]);
    $r = ai_scan_ops_purge_test_data($pdo, 'jorispaarde@gmail.com', 'intake-engine.nl', 100, 200);
    assert_same(0, $r['users']);
    assert_same(1, $r['user_skipped']);
});

test('ops purge test data leaves other accounts, older scans and their blocks on the same domain', static function (): void {
    [, $pdo] = boot_test_db();
    $eh = ai_scan_email_hash('jorispaarde@gmail.com');
    $oh = ai_scan_email_hash('klant@intake-engine.nl');
    $dh = ai_scan_domain_hash('intake-engine.nl');
    $ins = $pdo->prepare("INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at, ip_block_hash)
        VALUES(:p, 'x', :e, 'u', 'd', :d, 'klaar', :c, :ip)");
    $ins->execute(['p' => 'test', 'e' => $eh, 'd' => $dh, 'c' => 150, 'ip' => 'ipT']);
    $ins->execute(['p' => 'old', 'e' => $eh, 'd' => $dh, 'c' => 50, 'ip' => 'ipOld']);
    $ins->execute(['p' => 'other', 'e' => $oh, 'd' => $dh, 'c' => 150, 'ip' => 'ipO']);
    $ins->execute(['p' => 'other-old', 'e' => $oh, 'd' => $dh, 'c' => 20, 'ip' => 'ipO2']);
    $blk = $pdo->prepare('INSERT INTO free_scan_blocks(email_hash, domain_hash, ip_hash, created_at) VALUES(:e, :d, :ip, :c)');
    $blk->execute(['e' => $eh, 'd' => $dh, 'ip' => 'ipT', 'c' => 150]);
    $blk->execute(['e' => $eh, 'd' => $dh, 'ip' => 'ipOld', 'c' => 50]);
    $blk->execute(['e' => $oh, 'd' => $dh, 'ip' => 'ipO', 'c' => 150]);
    $blk->execute(['e' => $oh, 'd' => $dh, 'ip' => 'ipO2', 'c' => 20]);

    $r = ai_scan_ops_purge_test_data($pdo, 'jorispaarde@gmail.com', 'intake-engine.nl', 100, 200);
    assert_true($r['ok']);
    assert_same(1, $r['scans']);
    assert_same(1, $r['blocks']);
    $left = $pdo->query('SELECT public_id FROM scans ORDER BY public_id')->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['old', 'other', 'other-old'], $left);
    $ips = $pdo->query('SELECT ip_hash FROM free_scan_blocks ORDER BY ip_hash')->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['ipO', 'ipO2', 'ipOld'], $ips);
});

test('ai-scan status page exists for redirect codes', static function (): void {
    $src = (string) file_get_contents(dirname(__DIR__) . '/ai-scan/index.php');
    assert_true(str_contains($src, "'confirmed'"));
    assert_true(str_contains($src, 'wachtrij'));
    assert_true(!str_contains(strtolower($src), ' we '));
});

test('set password on deleted account reports no_account, never success', static function (): void {
    [, $pdo] = boot_test_db();
    $pdo->exec("INSERT INTO users(email, email_hash, created_at, free_scan_used) VALUES('a@b.nl', 'eh', 1, 1)");
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO scans(public_id, user_id, email, email_hash, website_url, domain, domain_hash, status, created_at, password_token_hash, password_token_expires_at)
        VALUES('p', :u, 'a@b.nl', 'eh', 'u', 'd', 'dh', 'bevestigd', 1, :h, :e)")->execute(['u' => $uid, 'h' => ai_scan_hash_token('tok-1'), 'e' => time() + 3600]);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $uid]);
    $r = ai_scan_set_password($pdo, 'tok-1', 'een-lang-wachtwoord');
    assert_true(!$r['ok']);
    assert_same('no_account', $r['code']);
});

test('flow: confirm redirects to password page, password page redirects to login with prefill', static function (): void {
    $root = dirname(__DIR__);
    $scan = (string) file_get_contents($root . '/ai-scan.php');
    assert_true(str_contains($scan, "/ai-scan/wachtwoord/?t="));
    $pw = (string) file_get_contents($root . '/ai-scan/wachtwoord/index.php');
    assert_true(str_contains($pw, "ai_scan_redirect('/login/', 303)"));
    assert_true(str_contains($pw, 'ai_scan_login_prefill'));
    $login = (string) file_get_contents($root . '/login/index.php');
    assert_true(str_contains($login, 'Je wachtwoord is ingesteld, log nu in.'));
    assert_true(str_contains($login, 'Je scan is gestart, binnen ~15 minuten klaar.'));
    assert_true(str_contains($login, "unset(\$_SESSION['ai_scan_login_prefill'])"));
});

test('runner dispatch is a no-op without token and wired into confirm', static function (): void {
    assert_same(false, ai_scan_trigger_runner([]));
    $src = (string) file_get_contents(dirname(__DIR__) . '/lib/scan_service.php');
    assert_true(str_contains($src, 'ai_scan_trigger_runner($config);'));
    assert_true(str_contains($src, 'CURLOPT_TIMEOUT => 3'));
});

test('live progress: stored only while running, view exposes safe fields', static function (): void {
    [, $pdo] = boot_test_db();
    $pdo->exec("INSERT INTO scans(public_id, email, email_hash, website_url, domain, domain_hash, status, created_at) VALUES('p', 'e', 'eh', 'u', 'd', 'dh', 'bevestigd', 1)");
    $id = (int) $pdo->lastInsertId();
    $state = ['step' => 'meten', 'active_source' => 'ChatGPT', 'sources' => ['ChatGPT' => ['done' => 2, 'total' => 10, 'mentioned' => 1]],
        'answers' => [['source' => 'ChatGPT', 'vraag' => 'Wie?', 'mentioned' => true, 'snippet' => 'x']]];
    assert_same('not_running', ai_scan_store_progress($pdo, $id, $state));
    $pdo->exec("UPDATE scans SET status = 'bezig' WHERE id = " . $id);
    assert_same('ok', ai_scan_store_progress($pdo, $id, $state));
    assert_same('too_large', ai_scan_store_progress($pdo, $id, ['x' => str_repeat('a', 70000)]));
    $scan = $pdo->query('SELECT * FROM scans WHERE id = ' . $id)->fetch();
    $v = ai_scan_progress_view($scan);
    assert_same('bezig', $v['status']);
    assert_same('meten', $v['progress']['step']);
    assert_same(2, $v['progress']['sources'][0]['done']);
    assert_true(!isset($v['progress']['answers'][0]['snippet']));
    $runner = (string) file_get_contents(dirname(__DIR__) . '/api/runner.php');
    assert_true(str_contains($runner, "\$action === 'progress'"));
});

test('failed confirm mail releases email, domain and IP for a new request', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $now = time();
    $in = ['email' => 'mailfail@firma.nl', 'website' => 'https://mailfail.nl', 'consent' => '1'];

    putenv('JPWEB_AI_SCAN_MAIL_DRY_RUN=0');
    $r1 = ai_scan_submit($pdo, $config, $in, '9.9.9.1', $now);
    putenv('JPWEB_AI_SCAN_MAIL_DRY_RUN=1');
    assert_same('error', $r1['code']);
    assert_same(0, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE status = 'onbevestigd'")->fetchColumn());

    $r2 = ai_scan_submit($pdo, $config, $in, '9.9.9.1', $now + 1);
    assert_same('check_mail', $r2['code']);
});

test('expired unconfirmed request no longer blocks email, domain or IP', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $now = time();
    $in = ['email' => 'verlopen@firma.nl', 'website' => 'https://verlopen.nl', 'consent' => '1'];

    assert_same('check_mail', ai_scan_submit($pdo, $config, $in, '9.9.9.2', $now)['code']);
    // binnen de geldigheid blijft de aanvraag staan
    assert_same('already_scanned', ai_scan_submit($pdo, $config, $in, '9.9.9.2', $now + 60)['code']);
    // na verloop van de bevestigingslink (en het uurvenster) kan een nieuwe aanvraag gewoon
    $later = $now + 90000;
    $r = ai_scan_submit($pdo, $config, $in, '9.9.9.2', $later);
    assert_same('check_mail', $r['code']);
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE email = 'verlopen@firma.nl'")->fetchColumn());
    $row = $pdo->query("SELECT confirm_expires_at FROM scans WHERE email = 'verlopen@firma.nl'")->fetch();
    assert_true((int) $row['confirm_expires_at'] > $later);
});

test('confirmed scans keep blocking after the confirm window', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $now = time();
    $in = ['email' => 'klaar@firma.nl', 'website' => 'https://klaar.nl', 'consent' => '1'];
    assert_same('check_mail', ai_scan_submit($pdo, $config, $in, '9.9.9.3', $now)['code']);
    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h WHERE email = :e')
        ->execute(['h' => ai_scan_hash_token('tk'), 'e' => 'klaar@firma.nl']);
    assert_same('confirmed', ai_scan_confirm($pdo, $config, 'tk', $now)['code']);
    assert_same('already_scanned', ai_scan_submit($pdo, $config, $in, '9.9.9.3', $now + 90000)['code']);
});

test('IPv6 rate windows share one /64, IPv4 stays per address', static function (): void {
    assert_same(ai_scan_ip_hash('2001:db8:1:2::1'), ai_scan_ip_hash('2001:db8:1:2:ffff::9'));
    assert_true(ai_scan_ip_hash('2001:db8:1:2::1') !== ai_scan_ip_hash('2001:db8:1:3::1'));
    assert_true(ai_scan_ip_hash('5.6.7.8') !== ai_scan_ip_hash('5.6.7.9'));
    assert_true(ai_scan_ip_hash('::ffff:1.2.3.4') !== ai_scan_ip_hash('::ffff:1.2.3.5'));
    assert_same(ai_scan_ip_hash('1.2.3.4'), ai_scan_ip_hash('::ffff:1.2.3.4'));
    assert_true(ai_scan_ip_hash('::ffff:1.2.3.4') !== ai_scan_ip_hash('::1'));

    [$config, $pdo] = boot_test_db();
    $config['form_ip_max_per_hour'] = 2;
    $config['form_burst_seconds'] = 0;
    $config['free_scan_one_per_ip'] = false;
    $now = time();
    for ($i = 0; $i < 2; $i++) {
        ai_scan_form_invalid_record($pdo, '2001:db8:1:2::' . dechex($i + 1), $now + $i);
    }
    assert_true(ai_scan_form_ip_rate_limited($pdo, $config, '2001:db8:1:2::abcd', $now + 3));
    assert_true(!ai_scan_form_ip_rate_limited($pdo, $config, '2001:db8:1:3::1', $now + 3));
});
