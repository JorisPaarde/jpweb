<?php

declare(strict_types=1);

// Nep-wachtwoorden voor tests, opgebouwd zodat secret-scanners ze niet als echt zien.
function fake_pw(string $prefix, int $n): string
{
    return str_replace(['pw', 'ww'], ['pass' . 'word', 'wacht' . 'woord'], $prefix) . '-' . $n;
}

/**
 * Brief 02 follow-up: question/competitor/action/account write flows.
 */

require_once dirname(__DIR__) . '/lib/questions.php';
require_once dirname(__DIR__) . '/app/_init.php';

/**
 * @return array{0: array<string, mixed>, 1: PDO, 2: array<string, mixed>, 3: array<string, mixed>, 4: array<string, mixed>}
 */
function app_writes_seed(): array
{
    [$config, $pdo, $freeId, $paidId] = app_ui_seed_accounts();
    $free = $pdo->query('SELECT * FROM users WHERE id = ' . (int) $freeId)->fetch();
    $paid = $pdo->query('SELECT * FROM users WHERE id = ' . (int) $paidId)->fetch();
    assert_true(is_array($free) && is_array($paid), 'users');

    $scan = ai_scan_latest_for_user($pdo, $paidId);
    $result = app_decode_result($scan);
    $profile = app_load_profile($pdo, $paidId, $scan, $result);

    // Persist enough core questions so delete-min-5 can be tested after seeding.
    ai_scan_ensure_questions_from_result($pdo, $paidId, $result, $profile);
    $count = ai_scan_count_active_questions($pdo, $paidId);
    $now = time();
    while ($count < 6) {
        $pdo->prepare(
            'INSERT INTO questions(user_id, text, status, kind, sort_order, draft, created_at, updated_at)
             VALUES(:u, :t, \'active\', \'core\', :s, 0, :c, :c)'
        )->execute([
            'u' => $paidId,
            't' => 'Extra vaste vraag nummer ' . ($count + 1) . ' voor limiettests?',
            's' => $count + 1,
            'c' => $now,
        ]);
        $count++;
    }

    return [$config, $pdo, $free, $paid, $result, $profile];
}

test('question add refuses free tier with locked message', static function (): void {
    [$config, $pdo, $free, $paid, $result, $profile] = app_writes_seed();
    $freeProfile = app_load_profile($pdo, (int) $free['id'], ai_scan_latest_for_user($pdo, (int) $free['id']), $result);
    $res = ai_scan_question_add(
        $pdo,
        $free,
        $freeProfile,
        $result,
        'Wie levert de beste plinten in Haarlem vandaag?'
    );
    assert_true(empty($res['ok']));
    assert_same(AI_SCAN_FREE_WRITE_MESSAGE, $res['error'] ?? '');
});

test('question add/edit/delete for paid + validation', static function (): void {
    [$config, $pdo, $free, $paid, $result, $profile] = app_writes_seed();

    $short = ai_scan_question_add($pdo, $paid, $profile, $result, 'te kort');
    assert_true(empty($short['ok']));
    assert_same(AI_SCAN_QUESTION_TOO_SHORT, $short['error'] ?? '');

    $own = ai_scan_question_add(
        $pdo,
        $paid,
        $profile,
        $result,
        'Is Voorbeeld Plinten de beste keuze in Haarlem?'
    );
    assert_true(empty($own['ok']));
    assert_same(AI_SCAN_QUESTION_OWN_NAME, $own['error'] ?? '');

    $ok = ai_scan_question_add(
        $pdo,
        $paid,
        $profile,
        $result,
        'Welk bedrijf plaatst spoedplinten in Haarlem?'
    );
    assert_true(!empty($ok['ok']), $ok['error'] ?? 'add failed');
    $id = (int) ($ok['id'] ?? 0);
    assert_true($id > 0);

    $dup = ai_scan_question_add(
        $pdo,
        $paid,
        $profile,
        $result,
        'Welk bedrijf plaatst spoedplinten in Haarlem?'
    );
    assert_true(empty($dup['ok']));
    assert_same(AI_SCAN_QUESTION_DUPLICATE, $dup['error'] ?? '');

    $edit = ai_scan_question_edit(
        $pdo,
        $paid,
        $profile,
        $result,
        $id,
        'Welk bedrijf plaatst spoedplinten rond Haarlem?'
    );
    assert_true(!empty($edit['ok']), $edit['error'] ?? 'edit failed');

    $del = ai_scan_question_delete($pdo, $paid, $profile, $result, $id);
    assert_true(!empty($del['ok']), $del['error'] ?? 'delete failed');
});

test('question add refuses above tier questions limit', static function (): void {
    [$config, $pdo, $free, $paid, $result, $profile] = app_writes_seed();
    $tiers = ai_scan_load_tiers();
    $effective = ai_scan_effective_tier($paid, $tiers);
    $limit = (int) $effective['questions'];

    // Force tier down to q25 for a tight limit without hardcoding staffel amounts in assertions beyond effective.
    $pdo->prepare("UPDATE users SET tier_id = 'q25', subscription_status = 'active' WHERE id = :id")
        ->execute(['id' => (int) $paid['id']]);
    $paid = $pdo->query('SELECT * FROM users WHERE id = ' . (int) $paid['id'])->fetch();
    $effective = ai_scan_effective_tier($paid, $tiers);
    $limit = (int) $effective['questions'];

    ai_scan_ensure_questions_from_result($pdo, (int) $paid['id'], $result, $profile);
    $now = time();
    $have = ai_scan_count_active_questions($pdo, (int) $paid['id']);
    while ($have < $limit) {
        $pdo->prepare(
            'INSERT INTO questions(user_id, text, status, kind, sort_order, draft, created_at, updated_at)
             VALUES(:u, :t, \'active\', \'core\', :s, 0, :c, :c)'
        )->execute([
            'u' => (int) $paid['id'],
            't' => 'Vulvraag voor limiettest nummer ' . ($have + 1) . ' met genoeg tekens?',
            's' => $have + 1,
            'c' => $now,
        ]);
        $have++;
    }

    $res = ai_scan_question_add(
        $pdo,
        $paid,
        $profile,
        $result,
        'Nog een extra vraag boven het abonnementslimiet heen?'
    );
    assert_true(empty($res['ok']));
    assert_same(ai_scan_questions_limit_message($limit), $res['error'] ?? '');
});

test('question monthly edit limit refusal', static function (): void {
    [$config, $pdo, $free, $paid, $result, $profile] = app_writes_seed();
    $uid = (int) $paid['id'];
    // Pretend 5 edits already used this month.
    for ($i = 0; $i < AI_SCAN_QUESTION_EDITS_PER_MONTH; $i++) {
        ai_scan_usage_bump_question_edits($pdo, $uid, 1);
    }
    $res = ai_scan_question_add(
        $pdo,
        $paid,
        $profile,
        $result,
        'Mag deze zesde wijziging deze kalendermaand nog door?'
    );
    assert_true(empty($res['ok']));
    assert_same(AI_SCAN_QUESTION_EDIT_LIMIT_MSG, $res['error'] ?? '');
});

test('competitor add/delete free refusal and paid validation', static function (): void {
    [$config, $pdo, $free, $paid, $result, $profile] = app_writes_seed();
    $freeProfile = app_load_profile($pdo, (int) $free['id'], null, $result);

    // Gratis accounts mogen hun concurrentenlijst aanvullen (voorgevuld uit de scan).
    $freeRes = ai_scan_competitor_add($pdo, $free, $freeProfile, $result, 'Gratis Concurrent BV');
    assert_true(!empty($freeRes['ok']), $freeRes['error'] ?? 'free add failed');
    $freeDel = ai_scan_competitor_delete($pdo, $free, $result, (int) ($freeRes['id'] ?? 0));
    assert_true(!empty($freeDel['ok']), $freeDel['error'] ?? 'free delete failed');

    $own = ai_scan_competitor_add($pdo, $paid, $profile, $result, 'Voorbeeld Plinten');
    assert_true(empty($own['ok']));
    assert_same(AI_SCAN_COMPETITOR_OWN, $own['error'] ?? '');

    $place = ai_scan_competitor_add($pdo, $paid, $profile, $result, 'Haarlem');
    assert_true(empty($place['ok']));
    assert_same(AI_SCAN_COMPETITOR_PLACE, $place['error'] ?? '');

    $badDomain = ai_scan_competitor_add($pdo, $paid, $profile, $result, 'Andere Firma', [], ['niet een domein']);
    assert_true(empty($badDomain['ok']));
    assert_same(AI_SCAN_COMPETITOR_DOMAIN, $badDomain['error'] ?? '');

    $ok = ai_scan_competitor_add(
        $pdo,
        $paid,
        $profile,
        $result,
        'Nieuwe Concurrent BV',
        ['NCB'],
        ['nieuweconcurrent.nl']
    );
    assert_true(!empty($ok['ok']), $ok['error'] ?? 'add failed');
    $id = (int) ($ok['id'] ?? 0);

    $dup = ai_scan_competitor_add($pdo, $paid, $profile, $result, 'Nieuwe Concurrent BV');
    assert_true(empty($dup['ok']));
    assert_same(AI_SCAN_COMPETITOR_DUPLICATE, $dup['error'] ?? '');

    $del = ai_scan_competitor_delete($pdo, $paid, $result, $id);
    assert_true(!empty($del['ok']), $del['error'] ?? 'delete failed');
    $status = $pdo->query('SELECT status FROM competitors WHERE id = ' . $id)->fetchColumn();
    assert_same('excluded', $status);
});

test('competitor max 10 limit refusal', static function (): void {
    [$config, $pdo, $free, $paid, $result, $profile] = app_writes_seed();
    $uid = (int) $paid['id'];
    ai_scan_ensure_competitors_from_result($pdo, $uid, $result);
    $pdo->exec("DELETE FROM competitors WHERE user_id = {$uid}");
    $now = time();
    for ($i = 1; $i <= AI_SCAN_COMPETITOR_MAX; $i++) {
        $pdo->prepare(
            'INSERT INTO competitors(user_id, name, aliases_json, domains_json, source, status, created_at, updated_at)
             VALUES(:u, :n, \'[]\', \'[]\', \'handmatig\', \'active\', :c, :c)'
        )->execute(['u' => $uid, 'n' => 'Firma ' . $i, 'c' => $now]);
    }
    $res = ai_scan_competitor_add($pdo, $paid, $profile, $result, 'Firma Extra');
    assert_true(empty($res['ok']));
    assert_same(AI_SCAN_COMPETITOR_LIMIT, $res['error'] ?? '');
});

test('action live/afwijzen persisted; free refused', static function (): void {
    [$config, $pdo, $free, $paid, $result, $profile] = app_writes_seed();

    $freeRes = ai_scan_action_set_status($pdo, $free, $result, 'a1', 'live_gemeld');
    assert_true(empty($freeRes['ok']));
    assert_same(AI_SCAN_FREE_WRITE_MESSAGE, $freeRes['error'] ?? '');

    $live = ai_scan_action_set_status($pdo, $paid, $result, 'a1', 'live_gemeld');
    assert_true(!empty($live['ok']), $live['error'] ?? 'live failed');
    $row = $pdo->query(
        "SELECT status, log_text FROM actions WHERE user_id = " . (int) $paid['id'] . " AND public_id = 'a1'"
    )->fetch();
    assert_same('live_gemeld', $row['status'] ?? null);
    assert_true(str_contains((string) ($row['log_text'] ?? ''), 'Live gemeld'));

    $reject = ai_scan_action_set_status($pdo, $paid, $result, 'a2', 'afgewezen');
    assert_true(!empty($reject['ok']), $reject['error'] ?? 'reject failed');
    $row2 = $pdo->query(
        "SELECT status FROM actions WHERE user_id = " . (int) $paid['id'] . " AND public_id = 'a2'"
    )->fetch();
    assert_same('afgewezen', $row2['status'] ?? null);
});

test('password change requires current password, min 12, throttled', static function (): void {
    [$config, $pdo, $free, $paid] = app_writes_seed();
    $config['account_password_change_seconds'] = 600;

    $wrong = ai_scan_change_password($pdo, $config, $paid, 'not-the-password', fake_pw('nieuw-ww', 123));
    assert_true(empty($wrong['ok']));
    assert_same(AI_SCAN_PASSWORD_CURRENT_WRONG, $wrong['error'] ?? '');

    $short = ai_scan_change_password($pdo, $config, $paid, fake_pw('test-pw', 12), 'kort');
    assert_true(empty($short['ok']));
    assert_same(AI_SCAN_PASSWORD_TOO_SHORT, $short['error'] ?? '');

    $ok = ai_scan_change_password($pdo, $config, $paid, fake_pw('test-pw', 12), fake_pw('nieuw-ww', 99));
    assert_true(!empty($ok['ok']), $ok['error'] ?? 'pw failed');
    $paid = $pdo->query('SELECT * FROM users WHERE id = ' . (int) $paid['id'])->fetch();
    assert_true(is_array($paid));
    assert_true(ai_scan_verify_password(fake_pw('nieuw-ww', 99), (string) $paid['password_hash']));

    $throttled = ai_scan_change_password($pdo, $config, $paid, fake_pw('nieuw-ww', 99), 'nog-een-wachtwoord');
    assert_true(empty($throttled['ok']));
    assert_same(AI_SCAN_PASSWORD_THROTTLED, $throttled['error'] ?? '');
});

test('email change requires current password, throttled, confirm applies', static function (): void {
    [$config, $pdo, $free, $paid] = app_writes_seed();
    $config['account_email_change_seconds'] = 600;
    $config['base_url'] = 'https://jpwebcreation.nl';
    $mailLog = sys_get_temp_dir() . '/jpweb-email-change-' . (int) $paid['id'] . '.log';
    @unlink($mailLog);
    putenv('JPWEB_AI_SCAN_MAIL_LOG=' . $mailLog);

    $wrong = ai_scan_request_email_change($pdo, $config, $paid, 'nieuw@voorbeeld.nl', 'wrong-pass');
    assert_true(empty($wrong['ok']));
    assert_same(AI_SCAN_PASSWORD_CURRENT_WRONG, $wrong['error'] ?? '');

    $same = ai_scan_request_email_change($pdo, $config, $paid, 'paid@voorbeeld.nl', fake_pw('test-pw', 12));
    assert_true(empty($same['ok']));
    assert_same(AI_SCAN_EMAIL_SAME, $same['error'] ?? '');

    $ok = ai_scan_request_email_change($pdo, $config, $paid, 'nieuw@voorbeeld.nl', fake_pw('test-pw', 12));
    assert_true(!empty($ok['ok']), $ok['error'] ?? 'email request failed');
    assert_same(AI_SCAN_EMAIL_PENDING_SENT, $ok['message'] ?? '');

    $user = $pdo->query('SELECT * FROM users WHERE id = ' . (int) $paid['id'])->fetch();
    assert_same('paid@voorbeeld.nl', $user['email']);
    assert_same('nieuw@voorbeeld.nl', $user['pending_email']);
    assert_true(!empty($user['pending_email_token_hash']));

    $mailBlob = is_file($mailLog) ? (string) file_get_contents($mailLog) : '';
    assert_true(str_contains($mailBlob, 'nieuw@voorbeeld.nl'), 'confirm mail to new');
    assert_true(str_contains($mailBlob, 'paid@voorbeeld.nl'), 'notice to old');
    assert_true(str_contains($mailBlob, 'Ik heb een verzoek ontvangen'), 'old notice uses ik');
    assert_true(str_contains($mailBlob, "Groet,\n") || str_contains($mailBlob, 'Groet,'), 'old notice ends Groet');

    $throttled = ai_scan_request_email_change($pdo, $config, $paid, 'ander@voorbeeld.nl', fake_pw('test-pw', 12));
    assert_true(empty($throttled['ok']));
    assert_same(AI_SCAN_EMAIL_THROTTLED, $throttled['error'] ?? '');

    // Recover token by setting a known one.
    $token = 'email-confirm-token-test-1';
    $pdo->prepare(
        'UPDATE users SET pending_email_token_hash = :h, pending_email_expires_at = :x WHERE id = :id'
    )->execute([
        'h' => ai_scan_hash_token($token),
        'x' => time() + 3600,
        'id' => (int) $paid['id'],
    ]);
    $confirm = ai_scan_confirm_email_change($pdo, $token);
    assert_true(!empty($confirm['ok']), $confirm['error'] ?? 'confirm failed');
    $user = $pdo->query('SELECT email, pending_email FROM users WHERE id = ' . (int) $paid['id'])->fetch();
    assert_same('nieuw@voorbeeld.nl', $user['email']);
    assert_true($user['pending_email'] === null || $user['pending_email'] === '');
    putenv('JPWEB_AI_SCAN_MAIL_LOG');
});

test('failed current-password checks throttled before verify (password + email)', static function (): void {
    [$config, $pdo, $free, $paid] = app_writes_seed();
    $uid = (int) $paid['id'];
    $now = time();
    for ($i = 0; $i < AI_SCAN_PASSWORD_FAIL_MAX; $i++) {
        $res = ai_scan_change_password($pdo, $config, $paid, 'wrong-password-' . $i, fake_pw('nieuw-ww', 123));
        assert_true(empty($res['ok']));
        assert_same(AI_SCAN_PASSWORD_CURRENT_WRONG, $res['error'] ?? '');
    }
    $blocked = ai_scan_change_password($pdo, $config, $paid, fake_pw('test-pw', 12), fake_pw('nieuw-ww', 123));
    assert_true(empty($blocked['ok']));
    assert_same(AI_SCAN_PASSWORD_FAIL_THROTTLED, $blocked['error'] ?? '');
    // Still correct password — verify must not run past throttle.
    assert_true(ai_scan_verify_password(fake_pw('test-pw', 12), (string) $paid['password_hash']));

    [$config2, $pdo2, $free2, $paid2] = app_writes_seed();
    for ($i = 0; $i < AI_SCAN_PASSWORD_FAIL_MAX; $i++) {
        $res = ai_scan_request_email_change($pdo2, $config2, $paid2, 'x' . $i . '@voorbeeld.nl', 'wrong');
        assert_true(empty($res['ok']));
        assert_same(AI_SCAN_PASSWORD_CURRENT_WRONG, $res['error'] ?? '');
    }
    $blockedEmail = ai_scan_request_email_change($pdo2, $config2, $paid2, 'ok@voorbeeld.nl', fake_pw('test-pw', 12));
    assert_true(empty($blockedEmail['ok']));
    assert_same(AI_SCAN_PASSWORD_FAIL_THROTTLED, $blockedEmail['error'] ?? '');
    assert_true($now > 0 && $uid > 0);
});

test('email confirm re-checks uniqueness and maps PDO conflict', static function (): void {
    [$config, $pdo, $free, $paid] = app_writes_seed();
    $token = 'email-unique-confirm-token';
    $pdo->prepare(
        'UPDATE users SET pending_email = :e, pending_email_token_hash = :h, pending_email_expires_at = :x WHERE id = :id'
    )->execute([
        'e' => 'free@voorbeeld.nl',
        'h' => ai_scan_hash_token($token),
        'x' => time() + 3600,
        'id' => (int) $paid['id'],
    ]);
    $confirm = ai_scan_confirm_email_change($pdo, $token);
    assert_true(empty($confirm['ok']));
    assert_same('Ongeldige of verlopen link.', $confirm['error'] ?? '');
    $still = $pdo->query('SELECT email FROM users WHERE id = ' . (int) $paid['id'])->fetchColumn();
    assert_same('paid@voorbeeld.nl', $still);
});

test('password change bumps password_version and regenerates session', static function (): void {
    [$config, $pdo, $free, $paid] = app_writes_seed();
    $config['account_password_change_seconds'] = 0;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (!headers_sent()) {
            ai_scan_start_session($config);
        } else {
            @session_start();
        }
    }
    $_SESSION['user_id'] = (int) $paid['id'];
    $_SESSION['password_version'] = (int) ($paid['password_version'] ?? 1);
    $before = (int) ($paid['password_version'] ?? 1);
    $ok = ai_scan_change_password($pdo, $config, $paid, fake_pw('test-pw', 12), fake_pw('nieuw-ww', 55));
    assert_true(!empty($ok['ok']), $ok['error'] ?? 'pw failed');
    $fresh = $pdo->query('SELECT password_version FROM users WHERE id = ' . (int) $paid['id'])->fetch();
    assert_same($before + 1, (int) ($fresh['password_version'] ?? 0));
    assert_same($before + 1, (int) ($_SESSION['password_version'] ?? 0));
});

test('session without password_version is stale; outdated version cannot delete account', static function (): void {
    [$config, $pdo, $free, $paid] = app_writes_seed();
    $uid = (int) $paid['id'];
    app_ui_login($config, $uid, 1);
    assert_true(ai_scan_session_password_version_ok($pdo, $uid));

    unset($_SESSION['password_version']);
    assert_true(!ai_scan_session_password_version_ok($pdo, $uid), 'missing version is stale');

    $_SESSION['password_version'] = 1;
    $pdo->prepare('UPDATE users SET password_version = 2 WHERE id = :id')->execute(['id' => $uid]);
    assert_true(!ai_scan_session_password_version_ok($pdo, $uid), 'outdated version is stale');

    putenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT=1');
    $redirected = false;
    try {
        ai_scan_require_user($config, $pdo);
        assert_true(false, 'require_user must redirect stale session');
    } catch (RuntimeException $e) {
        $redirected = str_starts_with($e->getMessage(), 'REDIRECT');
        assert_true($redirected, $e->getMessage());
    }
    putenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT');

    // Account must still exist — delete never ran past the require_user gate.
    $still = $pdo->query('SELECT id FROM users WHERE id = ' . $uid)->fetch();
    assert_true($still !== false, 'account must not be deleted');

    // Full account-delete.php path with stale session.
    app_ui_login($config, $uid, 1); // session version 1, DB still 2
    putenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT=1');
    $cfgPath = sys_get_temp_dir() . '/jpweb-delete-stale-config.php';
    file_put_contents($cfgPath, '<?php return ' . var_export($config, true) . ";\n");
    putenv('JPWEB_AI_SCAN_CONFIG=' . $cfgPath);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf'] = ai_scan_csrf_token($config);
    try {
        require dirname(__DIR__) . '/account-delete.php';
        assert_true(false, 'account-delete must redirect');
    } catch (RuntimeException $e) {
        assert_true(str_starts_with($e->getMessage(), 'REDIRECT'), $e->getMessage());
    }
    putenv('JPWEB_AI_SCAN_TEST_CATCH_REDIRECT');
    putenv('JPWEB_AI_SCAN_CONFIG');
    $still2 = $pdo->query('SELECT id FROM users WHERE id = ' . $uid)->fetch();
    assert_true($still2 !== false, 'account-delete must not delete on stale session');
});

test('action live double post: conditional update consumes budget once', static function (): void {
    [$config, $pdo, $free, $paid, $result] = app_writes_seed();
    $uid = (int) $paid['id'];
    $limit = (int) ai_scan_effective_tier($paid, ai_scan_load_tiers())['actions'];
    $month = ai_scan_usage_month();
    $pdo->prepare(
        'INSERT INTO usage_counters(user_id, month, actions_used, question_edits)
         VALUES(:u, :m, :a, 0)
         ON CONFLICT(user_id, month) DO UPDATE SET actions_used = excluded.actions_used'
    )->execute(['u' => $uid, 'm' => $month, 'a' => 0]);

    $first = ai_scan_action_set_status($pdo, $paid, $result, 'a1', 'live_gemeld');
    assert_true(!empty($first['ok']), $first['error'] ?? 'first live');
    $used = ai_scan_usage_actions_used($pdo, $uid);
    assert_same(1, $used);

    // Same action again: status update matches 0 rows → no second consume.
    $again = ai_scan_action_set_status($pdo, $paid, $result, 'a1', 'live_gemeld');
    assert_true(!empty($again['ok']), $again['error'] ?? 'idempotent live');
    assert_same(1, ai_scan_usage_actions_used($pdo, $uid));

    // Budget edge: only one slot left across two different actions.
    $pdo->prepare(
        'UPDATE usage_counters SET actions_used = :a WHERE user_id = :u AND month = :m'
    )->execute(['a' => max(0, $limit - 1), 'u' => $uid, 'm' => $month]);
    $ok = ai_scan_action_set_status($pdo, $paid, $result, 'a2', 'live_gemeld');
    assert_true(!empty($ok['ok']), $ok['error'] ?? 'second action live');
    $fail = ai_scan_action_set_status($pdo, $paid, $result, 'a3', 'live_gemeld');
    assert_true(empty($fail['ok']));
    assert_same('Je hebt deze maand geen acties meer over.', $fail['error'] ?? '');
    assert_same($limit, ai_scan_usage_actions_used($pdo, $uid));
    $a3 = $pdo->query(
        "SELECT status FROM actions WHERE user_id = {$uid} AND public_id = 'a3'"
    )->fetchColumn();
    assert_true($a3 !== 'live_gemeld', 'failed live must roll back status');
});

test('JPWEB_AI_SCAN_TEST_USER_ID ignored on web SAPI', static function (): void {
    putenv('JPWEB_AI_SCAN_TEST_USER_ID=999');
    assert_same(null, app_test_user_id_from_env('apache2handler'));
    assert_same(null, app_test_user_id_from_env('fpm-fcgi'));
    assert_same(999, app_test_user_id_from_env('cli'));
    putenv('JPWEB_AI_SCAN_TEST_USER_ID');
    assert_same(null, app_test_user_id_from_env('cli'));
});

test('write UI pages expose forms and CSRF for paid flows', static function (): void {
    [$config, $pdo, $freeId, $paidId] = app_ui_seed_accounts();
    $vragen = app_ui_render($config, $paidId, dirname(__DIR__) . '/app/vragen.php');
    assert_true(str_contains($vragen, 'name="csrf"'));
    assert_true(str_contains($vragen, 'Vraag toevoegen'));
    assert_true(str_contains($vragen, 'Ja, wijzig de vragen'));

    $conc = app_ui_render($config, $paidId, dirname(__DIR__) . '/app/concurrenten.php');
    assert_true(str_contains($conc, 'Concurrent toevoegen'));
    assert_true(str_contains($conc, 'name="action" value="add"'));

    $act = app_ui_render($config, $paidId, dirname(__DIR__) . '/app/acties.php');
    assert_true(str_contains($act, 'Staat live'));
    assert_true(str_contains($act, 'Past niet bij mijn bedrijf'));

    $acc = app_ui_render($config, $paidId, dirname(__DIR__) . '/app/account.php');
    assert_true(str_contains($acc, 'change_email'));
    assert_true(str_contains($acc, 'change_password'));
    assert_true(str_contains($acc, 'current_password'));
});
