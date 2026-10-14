<?php

declare(strict_types=1);

foreach (['reset', 'password', 'email'] as $operation) {
    test('account token revocation after ' . $operation, static function () use ($operation): void {
        [$config, $pdo] = boot_test_db();
        ai_scan_ensure_questions_schema($pdo);
        $now = time();
        $password = fake_pw('test-pw', 12);
        $uid = geo_user_with_password($pdo, 'tokens@example.nl', $password);
        $other = geo_user_with_password($pdo, 'other@example.nl', $password);
        // Two outstanding links on distinct scans of the same account.
        $pdo->exec("INSERT INTO scans(public_id, user_id, email, email_hash, website_url, domain, domain_hash, status, created_at)
            SELECT 'extra-token-scan', user_id, email, email_hash, website_url, domain, domain_hash, status, created_at FROM scans WHERE user_id = " . $uid);
        $scans = $pdo->query('SELECT id, user_id FROM scans ORDER BY id')->fetchAll();
        foreach ($scans as $scan) {
            $pdo->prepare('UPDATE scans SET password_token_hash = :h, password_token_expires_at = :x WHERE id = :id')
                ->execute(['h' => ai_scan_hash_token('reset-' . $scan['id']), 'x' => $now + 3600, 'id' => $scan['id']]);
        }
        foreach ([$uid, $other] as $id) {
            $pdo->prepare('UPDATE users SET pending_email = :e, pending_email_token_hash = :h, pending_email_expires_at = :x WHERE id = :id')
                ->execute(['e' => 'new-' . $id . '@example.nl', 'h' => ai_scan_hash_token('email-' . $id), 'x' => $now + 3600, 'id' => $id]);
        }
        $user = $pdo->query('SELECT * FROM users WHERE id = ' . $uid)->fetch();
        $first = (int) $scans[0]['id'];
        $result = match ($operation) {
            'reset' => ai_scan_set_password($pdo, 'reset-' . $first, fake_pw('new-pw', 123), $now),
            'password' => ai_scan_change_password($pdo, $config, $user, $password, fake_pw('new-pw', 123), $now),
            'email' => ai_scan_confirm_email_change($pdo, 'email-' . $uid, $now),
        };
        assert_true($result['ok'], 'account operation succeeds');
        assert_same(0, (int) $pdo->query('SELECT COUNT(*) FROM scans WHERE user_id = ' . $uid . ' AND (password_token_hash IS NOT NULL OR password_token_expires_at IS NOT NULL)')->fetchColumn(), 'all account reset tokens cleared');
        $fresh = $pdo->query('SELECT * FROM users WHERE id = ' . $uid)->fetch();
        foreach (['pending_email', 'pending_email_token_hash', 'pending_email_expires_at'] as $field) {
            assert_true($fresh[$field] === null, $field . ' cleared');
        }
        foreach ($scans as $scan) {
            if ((int) $scan['user_id'] === $uid) {
                assert_true(!ai_scan_set_password($pdo, 'reset-' . $scan['id'], fake_pw('replay-pw', 123), $now)['ok'], 'old reset link rejected');
            }
        }
        assert_true(!ai_scan_confirm_email_change($pdo, 'email-' . $uid, $now)['ok'], 'old email link rejected');
        assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM scans WHERE user_id = ' . $other . ' AND password_token_hash IS NOT NULL AND password_token_expires_at IS NOT NULL')->fetchColumn(), 'other account reset token preserved');
        assert_true(ai_scan_confirm_email_change($pdo, 'email-' . $other, $now)['ok'], 'other account email link preserved');
    });
}

foreach (['reset', 'password', 'email'] as $operation) {
    test('account token revocation rolls back on failure after ' . $operation, static function () use ($operation): void {
        [$config, $pdo] = boot_test_db();
        ai_scan_ensure_questions_schema($pdo);
        $now = time();
        $password = fake_pw('test-pw', 12);
        $uid = geo_user_with_password($pdo, 'rollback@example.nl', $password);
        $pdo->exec("INSERT INTO scans(public_id, user_id, email, email_hash, website_url, domain, domain_hash, status, created_at)
            SELECT 'rollback-extra', user_id, email, email_hash, website_url, domain, domain_hash, status, created_at FROM scans WHERE user_id = " . $uid);
        $pdo->prepare('UPDATE scans SET password_token_hash = :h, password_token_expires_at = :x WHERE user_id = :id')
            ->execute(['h' => ai_scan_hash_token('rollback-reset'), 'x' => $now + 3600, 'id' => $uid]);
        $pdo->prepare('UPDATE users SET pending_email = :e, pending_email_token_hash = :h, pending_email_expires_at = :x WHERE id = :id')
            ->execute(['e' => 'rollback-new@example.nl', 'h' => ai_scan_hash_token('rollback-email'), 'x' => $now + 3600, 'id' => $uid]);
        $beforeUser = $pdo->query('SELECT * FROM users WHERE id = ' . $uid)->fetch();
        $beforeScans = $pdo->query('SELECT * FROM scans ORDER BY id')->fetchAll();
        $pdo->exec("CREATE TRIGGER reject_token_revoke BEFORE UPDATE OF password_token_hash ON scans
            WHEN OLD.public_id = 'rollback-extra' AND NEW.password_token_hash IS NULL
            BEGIN SELECT RAISE(ABORT, 'synthetic revocation failure'); END");
        try {
            $result = match ($operation) {
                'reset' => ai_scan_set_password($pdo, 'rollback-reset', fake_pw('new-pw', 123), $now),
                'password' => ai_scan_change_password($pdo, $config, $beforeUser, $password, fake_pw('new-pw', 123), $now),
                'email' => ai_scan_confirm_email_change($pdo, 'rollback-email', $now),
            };
            assert_true(!$result['ok'], 'failed revocation cannot report success');
        } catch (PDOException $e) {
            assert_true(str_contains($e->getMessage(), 'synthetic revocation failure'), 'expected injected failure');
        }
        assert_true(!$pdo->inTransaction(), 'transaction closed after failure');
        assert_true($beforeUser === $pdo->query('SELECT * FROM users WHERE id = ' . $uid)->fetch(), 'account change rolled back');
        assert_true($beforeScans === $pdo->query('SELECT * FROM scans ORDER BY id')->fetchAll(), 'all token changes rolled back');
    });
}
