<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/tools/import_smooth_demo.php';

test('Smooth import preserves current questions and distinguishes historical measurements', static function (): void {
    $result = smooth_demo_import(__DIR__ . '/fixtures/smooth');
    assert_same('core-v2-koop', $result['question_set_version']);
    assert_same(25, count($result['questions']['core']));
    assert_same(3, count($result['actions']));
    assert_same(null, $result['questions']['core'][2]['answers']);
    assert_same(4, $result['questions']['core'][0]['answers']);
    assert_same('https://www.smooth-operator.nl/behandeling-jukbeenderen', $result['actions'][0]['page_path']);
    assert_true(is_string($result['actions'][0]['now']));
    assert_true(!str_contains(json_encode($result), 'core-v1'));
});

test('Smooth account is isolated, resettable, not queued and sends no mail', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    @unlink($mailLog);
    $result = smooth_demo_import(__DIR__ . '/fixtures/smooth');
    $pdo->beginTransaction();
    $id = smooth_demo_create($pdo, 'smooth-demo@agentmail.to', $result);
    $pdo->commit();
    $user = $pdo->query('SELECT * FROM users')->fetch();
    assert_same(null, $user['password_hash']);
    assert_same('paused', $user['subscription_status']);
    assert_same(null, ai_scan_next_job($pdo, $config));
    assert_same(0, ai_scan_flush_ready_mails($pdo, $config));
    assert_true(!is_file($mailLog));
    assert_same(25, count(ai_scan_list_questions($pdo, $id)));
    assert_same([], ai_scan_list_questions($pdo, $id + 1));
    assert_same(3, count(app_load_actions($pdo, $id, $result)));
    $html = app_ui_render($config, $id, dirname(__DIR__) . '/app/overzicht.php');
    assert_true(str_contains($html, 'Koopvragen, versie 2'));
    assert_true(str_contains($html, '17 van 113'));
    assert_true(str_contains($html, $result['actions'][2]['title']));
    $html = app_ui_render($config, $id, dirname(__DIR__) . '/app/vragen.php');
    assert_same(8, substr_count($html, 'nog niet gemeten'));
    assert_true(!str_contains($html, 'core-v1'));
    try {
        smooth_demo_create($pdo, 'smooth-demo@agentmail.to', $result);
        assert_true(false, 'duplicate must fail');
    } catch (PDOException $e) { }
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
});

test('smooth demo: concurrenten.php blocks writes for demo accounts', static function (): void {
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/concurrenten.php');
    assert_true(str_contains($src, "elseif (!empty(\$ctx['result']['demo']))"), 'demo write guard in concurrenten.php');
});

test('smooth demo: overzicht shows the real number of demo questions, not a fixed 25', static function (): void {
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/overzicht.php');
    assert_true(!str_contains($src, '· 25 vragen'), 'no hardcoded 25');
    $ops = (string) file_get_contents(dirname(__DIR__) . '/ops/cleanup.php');
    assert_true(str_contains($ops, "'demo_drop_question'") && str_contains($ops, "empty(\$res['demo'])"), 'drop only on demo accounts');
});
