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
    assert_same('already_scanned', $r2['code']);

    $pdo->prepare('UPDATE scans SET confirm_token_hash = :h WHERE email = :e')
        ->execute(['h' => ai_scan_hash_token('tip'), 'e' => 'ip1@firma.nl']);
    assert_same('confirmed', ai_scan_confirm($pdo, $config, 'tip', $now)['code']);
    $hash = ai_scan_ip_block_hash($config, '5.6.7.8');
    assert_true(strlen($hash) === 64 && $hash !== hash('sha256', '5.6.7.8') && $hash !== ai_scan_ip_hash('5.6.7.8'));
    assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM free_scan_blocks WHERE ip_hash = '" . $hash . "'")->fetchColumn());
    assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM scans WHERE ip_block_hash IS NOT NULL')->fetchColumn());

    $r3 = ai_scan_submit($pdo, $config, ['email' => 'ip3@derde.nl', 'website' => 'https://derde.nl', 'consent' => '1'], '5.6.7.8', $now + 7200);
    assert_same('already_scanned', $r3['code']);
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
