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
