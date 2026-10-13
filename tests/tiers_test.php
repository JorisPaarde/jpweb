<?php

declare(strict_types=1);

/**
 * Drift-test for tiers.json + effective tier (docs/tiers.md §Drift-test: 1, 2, 5, 6).
 * Included from tests/run.php.
 */

/** @var callable $test */
/** @var callable $assert_true */
/** @var callable $assert_same */
/** @var callable $boot_test_db */

test('tiers schema invariants (drift 1)', static function (): void {
    ai_scan_clear_tiers_cache();
    $data = ai_scan_load_tiers();
    ai_scan_validate_tiers($data);
    assert_true(is_array($data['tiers']) && $data['tiers'] !== []);
    assert_same('free', (string) $data['free']['id']);
    assert_true(!empty($data['free']['read_only']));
});

test('tiers definitive staffel values (drift 2)', static function (): void {
    ai_scan_clear_tiers_cache();
    $data = ai_scan_load_tiers();
    $expected = [
        25 => [19, 3],
        50 => [35, 4],
        75 => [49, 5],
        100 => [65, 6],
        150 => [89, 7],
        200 => [109, 8],
        250 => [129, 9],
        350 => [179, 10],
        500 => [239, 12],
        750 => [339, 15],
        1000 => [429, 20],
    ];
    $got = [];
    foreach ($data['tiers'] as $tier) {
        $q = (int) $tier['questions'];
        $got[$q] = [(int) $tier['monthly'], (int) $tier['actions']];
        assert_same('q' . $q, (string) $tier['id']);
    }
    assert_same($expected, $got, 'staffel must match docs/tiers.md');
});

test('ai_scan_effective_tier matches file for every tier (drift 5)', static function (): void {
    ai_scan_clear_tiers_cache();
    $tiers = ai_scan_load_tiers();

    $freeUser = ['tier_id' => 'free', 'subscription_status' => 'none'];
    $free = ai_scan_effective_tier($freeUser, $tiers);
    assert_same('free', $free['id']);
    assert_same((int) $tiers['free']['questions'], $free['questions']);
    assert_same((int) $tiers['free']['actions'], $free['actions']);
    assert_true($free['read_only']);
    assert_true(!$free['weekly_measurement']);

    foreach ($tiers['tiers'] as $tier) {
        $user = [
            'tier_id' => (string) $tier['id'],
            'subscription_status' => 'active',
        ];
        $eff = ai_scan_effective_tier($user, $tiers);
        assert_same((string) $tier['id'], $eff['id']);
        assert_same((int) $tier['questions'], $eff['questions']);
        assert_same((int) $tier['actions'], $eff['actions']);
        assert_same((int) $tier['monthly'], $eff['monthly']);
        assert_true(!$eff['read_only']);
        assert_true($eff['weekly_measurement']);
    }

    $unknown = ai_scan_effective_tier([
        'tier_id' => 'q9999',
        'subscription_status' => 'active',
    ], $tiers);
    assert_same('free', $unknown['id'], 'unknown tier_id fail closed');

    $ended = ai_scan_effective_tier([
        'tier_id' => 'q50',
        'subscription_status' => 'ended',
    ], $tiers);
    assert_same('free', $ended['id']);

    $custom = ai_scan_effective_tier([
        'tier_id' => 'custom',
        'custom_questions' => 1200,
        'subscription_status' => 'active',
    ], $tiers);
    assert_same('custom', $custom['id']);
    assert_same(1200, $custom['questions']);
    assert_same((int) $tiers['above_max']['actions'], $custom['actions']);
});

test('no PHP outside lib/tiers.php hardcodes tier limits (drift 6)', static function (): void {
    $root = dirname(__DIR__);
    $forbiddenAmounts = ['€19', '€35', '€49', '€65', '€89', '€109', '€129', '€179', '€239', '€339', '€429'];
    // Literal monthly integers that appear as tier prices in tiers.json — only allowed in tiers.php.
    $priceLiterals = ['monthly' => true]; // marker only
    unset($priceLiterals);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    $hits = [];
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $rel = substr($path, strlen($root) + 1);
        if ($rel === 'lib/tiers.php' || str_starts_with($rel, 'tests/')) {
            continue;
        }
        // Skip vendor-like / non-app paths if any.
        if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, '.git/')) {
            continue;
        }
        $src = (string) file_get_contents($path);
        foreach ($forbiddenAmounts as $needle) {
            if (str_contains($src, $needle)) {
                $hits[] = $rel . ' contains ' . $needle;
            }
        }
        // Staffel monthly amounts as bare assignment patterns like `= 19` next to questions are
        // checked via the definitive list appearing as a PHP array of prices.
        if (preg_match('/\b(19|35|49|65|89|109|129|179|239|339|429)\b.*\b(3|4|5|6|7|8|9|10|12|15|20)\b/', $src)
            && str_contains($src, 'questions')
            && str_contains($src, 'actions')
            && str_contains($src, 'monthly')
        ) {
            // Likely a duplicated staffel table.
            if (substr_count($src, 'monthly') >= 3 && substr_count($src, 'questions') >= 3) {
                $hits[] = $rel . ' looks like a duplicated tier staffel';
            }
        }
    }
    assert_same([], $hits, 'tier limits must only live in lib/tiers.php / tiers.json');
});

test('new user defaults free/none; unknown tier fail closed; free write blocked', static function (): void {
    [$config, $pdo] = boot_test_db();
    $pdo->prepare(
        'INSERT INTO users(email, email_hash, password_hash, created_at, free_scan_used)
         VALUES(:e, :h, NULL, :c, 0)'
    )->execute([
        'e' => 'new@tier.nl',
        'h' => ai_scan_email_hash('new@tier.nl'),
        'c' => time(),
    ]);
    $user = $pdo->query('SELECT * FROM users WHERE email = \'new@tier.nl\'')->fetch();
    assert_same('free', (string) $user['tier_id']);
    assert_same('none', (string) $user['subscription_status']);

    $tiers = ai_scan_load_tiers();
    assert_true(!ai_scan_can_write($user, $tiers));
    assert_same(AI_SCAN_FREE_WRITE_MESSAGE, 'Zit in het abonnement: elke week een nieuwe meting.');

    $paid = $user;
    $paid['tier_id'] = 'q50';
    $paid['subscription_status'] = 'active';
    assert_true(ai_scan_can_write($paid, $tiers));

    $limits = ai_scan_limits_for_user($pdo, $user, $tiers);
    assert_same('free', $limits['tier_id']);
    assert_true(!$limits['weekly_measurement']);
});

test('snap questions and custom above max', static function (): void {
    $tiers = ai_scan_load_tiers();
    $s = ai_scan_snap_questions(40, $tiers);
    assert_same('q50', $s['id']);
    assert_same(50, $s['questions']);
    $c = ai_scan_snap_questions(1101, $tiers);
    assert_same('custom', $c['id']);
    assert_same(1200, $c['questions']);
    assert_same((int) $tiers['above_max']['actions'], $c['actions']);
});

test('tier request mails info@ and customer; throttle skips mail', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['tier_request_mail_per_email_seconds'] = 600;
    $config['tier_request_mail_global_max_per_hour'] = 40;
    $now = time();

    $pdo->prepare(
        'INSERT INTO users(email, email_hash, password_hash, created_at, free_scan_used, tier_id, subscription_status)
         VALUES(:e, :h, NULL, :c, 1, \'free\', \'none\')'
    )->execute([
        'e' => 'klant@firma.nl',
        'h' => ai_scan_email_hash('klant@firma.nl'),
        'c' => $now,
    ]);
    $user = $pdo->query('SELECT * FROM users WHERE email = \'klant@firma.nl\'')->fetch();
    $pdo->prepare(
        'INSERT INTO scans (public_id, user_id, email, email_hash, website_url, domain, domain_hash, status, created_at)
         VALUES (:p, :u, :e, :eh, :w, :d, :dh, :s, :c)'
    )->execute([
        'p' => ai_scan_public_id(),
        'u' => (int) $user['id'],
        'e' => 'klant@firma.nl',
        'eh' => ai_scan_email_hash('klant@firma.nl'),
        'w' => 'https://firma.nl/',
        'd' => 'firma.nl',
        'dh' => ai_scan_domain_hash('firma.nl'),
        's' => 'klaar',
        'c' => $now,
    ]);
    $user = $pdo->query('SELECT * FROM users WHERE email = \'klant@firma.nl\'')->fetch();

    @unlink($mailLog);
    putenv('JPWEB_AI_SCAN_MAIL_LOG=' . $mailLog);

    $r1 = ai_scan_request_tier_change($pdo, $config, $user, 'q50', 'upgrade', $now);
    assert_true($r1['ok']);
    assert_true($r1['mailed']);
    assert_same(AI_SCAN_TIER_REQUEST_MESSAGE, $r1['message']);
    assert_same('free', (string) $pdo->query('SELECT tier_id FROM users WHERE id = ' . (int) $user['id'])->fetchColumn());

    $mail = (string) file_get_contents($mailLog);
    assert_true(str_contains($mail, 'info@jpwebcreation.nl'));
    assert_true(str_contains($mail, 'klant@firma.nl'));
    assert_true(str_contains($mail, 'q50'));
    $sawCustomer = false;
    $sawAdmin = false;
    foreach (file($mailLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $jsonLine) {
        $entry = json_decode((string) $jsonLine, true);
        if (!is_array($entry)) {
            continue;
        }
        if (($entry['to'] ?? '') === 'klant@firma.nl') {
            $sawCustomer = true;
            $body = (string) ($entry['body'] ?? '');
            assert_true(str_starts_with($body, 'Hoi firma.nl,'));
            assert_true(str_ends_with(rtrim($body), "Groet,\nJoris"));
            assert_true(!preg_match('/\b(wij|we|ons)\b/iu', $body), 'customer mail must use ik, not wij/we/ons');
            assert_true(str_contains($body, 'Ik heb je aanvraag ontvangen'));
            assert_true(str_contains($body, 'Ik pas het aan'));
        }
        if (($entry['to'] ?? '') === 'info@jpwebcreation.nl') {
            $sawAdmin = true;
            assert_true(str_contains((string) ($entry['body'] ?? ''), 'Huidige tier: free'));
            assert_true(str_contains((string) ($entry['body'] ?? ''), 'Gevraagde tier: q50'));
        }
    }
    assert_true($sawCustomer && $sawAdmin);

    $events = (int) $pdo->query('SELECT COUNT(*) FROM subscription_events WHERE event = \'request\'')->fetchColumn();
    assert_same(1, $events);

    @unlink($mailLog);
    putenv('JPWEB_AI_SCAN_MAIL_LOG=' . $mailLog);
    $r2 = ai_scan_request_tier_change($pdo, $config, $user, 'q100', 'upgrade', $now + 10);
    assert_true($r2['ok']);
    assert_true(!empty($r2['throttled']));
    assert_true(!$r2['mailed']);
    assert_same(AI_SCAN_TIER_REQUEST_MESSAGE, $r2['message']);
    assert_true(!is_file($mailLog) || trim((string) file_get_contents($mailLog)) === '');
});

test('admin set tier applies immediately, pauses excess, mails customer', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['admin_emails'] = ['info@jpwebcreation.nl'];
    $config['tier_change_mail_per_email_seconds'] = 0;
    $config['tier_change_mail_global_max_per_hour'] = 1000;
    $now = time();

    $pdo->prepare(
        'INSERT INTO users(email, email_hash, password_hash, created_at, free_scan_used, tier_id, subscription_status)
         VALUES(:e, :h, NULL, :c, 1, \'free\', \'none\')'
    )->execute([
        'e' => 'owner@site.nl',
        'h' => ai_scan_email_hash('owner@site.nl'),
        'c' => $now,
    ]);
    $userId = (int) $pdo->lastInsertId();

    // Temporary questions table for pause behaviour (schema lands with later briefs).
    $pdo->exec(
        'CREATE TABLE questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT \'active\',
            created_at INTEGER NOT NULL
        )'
    );
    $insQ = $pdo->prepare('INSERT INTO questions(user_id, status, created_at) VALUES(:u, \'active\', :c)');
    for ($i = 0; $i < 30; $i++) {
        $insQ->execute(['u' => $userId, 'c' => $now + $i]);
    }

    @unlink($mailLog);
    putenv('JPWEB_AI_SCAN_MAIL_LOG=' . $mailLog);

    $result = ai_scan_admin_set_tier($pdo, $config, $userId, [
        'tier_id' => 'q25',
        'subscription_status' => 'active',
        'billing_period' => 'month',
        'period_end' => $now + 86400 * 30,
    ], 'admin', $now);

    assert_true($result['ok']);
    assert_same(5, (int) $result['paused']);
    $active = (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE user_id = {$userId} AND status = 'active'")->fetchColumn();
    $paused = (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE user_id = {$userId} AND status = 'paused'")->fetchColumn();
    assert_same(25, $active);
    assert_same(5, $paused);
    $pausedIds = $pdo->query("SELECT id FROM questions WHERE status = 'paused' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $activeIds = $pdo->query("SELECT id FROM questions WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(max(array_map('intval', $pausedIds)) > max(array_map('intval', $activeIds)));

    $row = $pdo->query('SELECT * FROM users WHERE id = ' . $userId)->fetch();
    assert_same('q25', (string) $row['tier_id']);
    assert_same('active', (string) $row['subscription_status']);

    $mail = (string) file_get_contents($mailLog);
    assert_true(str_contains($mail, 'owner@site.nl'));
    assert_true(str_contains($mail, 'Je abonnement is aangepast'));
    foreach (file($mailLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $jsonLine) {
        $entry = json_decode((string) $jsonLine, true);
        if (!is_array($entry) || ($entry['to'] ?? '') !== 'owner@site.nl') {
            continue;
        }
        $body = (string) ($entry['body'] ?? '');
        assert_true(str_starts_with($body, 'Hoi '));
        assert_true(str_ends_with(rtrim($body), "Groet,\nJoris"));
        assert_true(str_contains($body, 'Ik heb je abonnement aangepast'));
        assert_true(str_contains($body, '25 vragen'));
        assert_true(str_contains($body, '3 verbeteracties'));
        assert_true(!preg_match('/\b(wij|we|ons)\b/iu', $body));
    }

    $events = (int) $pdo->query('SELECT COUNT(*) FROM subscription_events WHERE event = \'admin_set\'')->fetchColumn();
    assert_same(1, $events);

    // Throttle: second admin change within window skips mail but still updates.
    $config['tier_change_mail_per_email_seconds'] = 600;
    @unlink($mailLog);
    putenv('JPWEB_AI_SCAN_MAIL_LOG=' . $mailLog);
    $r2 = ai_scan_admin_set_tier($pdo, $config, $userId, [
        'tier_id' => 'q50',
        'subscription_status' => 'active',
    ], 'admin', $now + 5);
    assert_true($r2['ok']);
    assert_true(!$r2['mailed']);
    assert_same('q50', (string) $pdo->query('SELECT tier_id FROM users WHERE id = ' . $userId)->fetchColumn());
});

test('actions remaining uses Europe/Amsterdam month', static function (): void {
    [$config, $pdo] = boot_test_db();
    $pdo->prepare(
        'INSERT INTO users(email, email_hash, password_hash, created_at, free_scan_used, tier_id, subscription_status)
         VALUES(:e, :h, NULL, :c, 1, \'q50\', \'active\')'
    )->execute([
        'e' => 'act@x.nl',
        'h' => ai_scan_email_hash('act@x.nl'),
        'c' => time(),
    ]);
    $user = $pdo->query('SELECT * FROM users WHERE email = \'act@x.nl\'')->fetch();
    $tiers = ai_scan_load_tiers();
    $limit = (int) ai_scan_effective_tier($user, $tiers)['actions'];
    assert_same($limit, ai_scan_actions_remaining($pdo, $user, $tiers));
    ai_scan_usage_bump_actions($pdo, (int) $user['id'], 2);
    assert_same($limit - 2, ai_scan_actions_remaining($pdo, $user, $tiers));
});
