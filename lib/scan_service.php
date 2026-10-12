<?php

declare(strict_types=1);

/**
 * Core AI-scan business logic: submit, confirm, queue, GDPR delete.
 */

function ai_scan_day_start(int $now): int
{
    return (int) strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
}

/**
 * Free-scan slots used today = jobs that are allowed to run (not waitlisted).
 */
function ai_scan_count_free_scans_today(PDO $pdo, int $now): int
{
    $start = ai_scan_day_start($now);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM scans
         WHERE status IN ('bevestigd', 'bezig', 'klaar', 'mislukt')
           AND queued_at IS NOT NULL
           AND queued_at >= :start"
    );
    $stmt->execute(['start' => $start]);
    $row = $stmt->fetch();

    return (int) ($row['c'] ?? 0);
}

function ai_scan_has_block(PDO $pdo, string $emailHash, string $domainHash): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM free_scan_blocks
         WHERE email_hash = :email_hash OR domain_hash = :domain_hash
         LIMIT 1'
    );
    $stmt->execute([
        'email_hash' => $emailHash,
        'domain_hash' => $domainHash,
    ]);

    return $stmt->fetch() !== false;
}

function ai_scan_has_existing_scan(PDO $pdo, string $emailHash, string $domainHash): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM scans
         WHERE (email_hash = :email_hash OR domain_hash = :domain_hash)
           AND status IN ('onbevestigd', 'bevestigd', 'wacht_dag', 'bezig', 'klaar')
         LIMIT 1"
    );
    $stmt->execute([
        'email_hash' => $emailHash,
        'domain_hash' => $domainHash,
    ]);

    return $stmt->fetch() !== false;
}

function ai_scan_user_used_free_scan(PDO $pdo, string $emailHash): bool
{
    $stmt = $pdo->prepare('SELECT free_scan_used FROM users WHERE email_hash = :h LIMIT 1');
    $stmt->execute(['h' => $emailHash]);
    $row = $stmt->fetch();
    if ($row !== false && (int) $row['free_scan_used'] === 1) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM scans
         WHERE email_hash = :h
           AND status IN ('bevestigd', 'wacht_dag', 'bezig', 'klaar', 'mislukt')
         LIMIT 1"
    );
    $stmt->execute(['h' => $emailHash]);

    return $stmt->fetch() !== false;
}

/**
 * Submit a free-scan request. Returns a contract status code.
 *
 * @return array{code: string, scan_id?: int}
 */
function ai_scan_submit(PDO $pdo, array $config, array $input, string $ip, int $now = 0): array
{
    $now = $now > 0 ? $now : time();

    // Honeypot: always look like success.
    $honeypot = trim((string) ($input['hp_field'] ?? $input['bedrijfsfax'] ?? ''));
    if ($honeypot !== '') {
        return ['code' => 'check_mail'];
    }

    if (ai_scan_form_rate_limited($pdo, $config, $ip, $now)) {
        return ['code' => 'rate_limited'];
    }

    $emailRaw = trim((string) ($input['email'] ?? ''));
    $websiteRaw = trim((string) ($input['website'] ?? ''));
    $consent = (string) ($input['consent'] ?? '') === '1'
        || (string) ($input['consent'] ?? '') === 'accepted'
        || (string) ($input['privacy'] ?? '') === 'accepted';

    if (!$consent || !filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
        return ['code' => 'invalid_email'];
    }

    $normalizedEmail = ai_scan_normalize_email($emailRaw);
    if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        return ['code' => 'invalid_email'];
    }

    if (ai_scan_is_disposable_email($normalizedEmail)) {
        return ['code' => 'disposable'];
    }

    $parsed = ai_scan_parse_website($websiteRaw);
    if (!$parsed['ok']) {
        return ['code' => 'site_unreachable'];
    }

    $skipReachability = getenv('JPWEB_AI_SCAN_SKIP_REACHABILITY') === '1';
    if (!$skipReachability && !ai_scan_site_reachable((string) $parsed['url'])) {
        return ['code' => 'site_unreachable'];
    }

    $emailHash = ai_scan_email_hash($normalizedEmail);
    $domain = (string) $parsed['domain'];
    $domainHash = ai_scan_domain_hash($domain);

    // First scan free; afterwards subscription only → already_scanned (no email leak).
    if (
        ai_scan_has_block($pdo, $emailHash, $domainHash)
        || ai_scan_has_existing_scan($pdo, $emailHash, $domainHash)
        || ai_scan_user_used_free_scan($pdo, $emailHash)
    ) {
        return ['code' => 'already_scanned'];
    }

    $dailyLimit = (int) ($config['daily_free_scans'] ?? 10);
    $dailyFull = ai_scan_count_free_scans_today($pdo, $now) >= $dailyLimit;

    $confirmToken = ai_scan_random_token(32);
    $ttl = (int) ($config['confirm_token_ttl'] ?? 86400);
    $publicId = ai_scan_public_id();

    try {
        $pdo->beginTransaction();

        // Re-check inside transaction for races.
        if (
            ai_scan_has_block($pdo, $emailHash, $domainHash)
            || ai_scan_has_existing_scan($pdo, $emailHash, $domainHash)
        ) {
            $pdo->rollBack();

            return ['code' => 'already_scanned'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO scans (
                public_id, user_id, email, email_hash, website_url, domain, domain_hash,
                status, confirm_token_hash, confirm_expires_at, created_at, ip_hash
            ) VALUES (
                :public_id, NULL, :email, :email_hash, :website_url, :domain, :domain_hash,
                :status, :confirm_token_hash, :confirm_expires_at, :created_at, :ip_hash
            )'
        );
        $stmt->execute([
            'public_id' => $publicId,
            'email' => $normalizedEmail,
            'email_hash' => $emailHash,
            'website_url' => (string) $parsed['url'],
            'domain' => $domain,
            'domain_hash' => $domainHash,
            'status' => 'onbevestigd',
            'confirm_token_hash' => ai_scan_hash_token($confirmToken),
            'confirm_expires_at' => $now + $ttl,
            'created_at' => $now,
            'ip_hash' => ai_scan_ip_hash($ip),
        ]);
        $scanId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['code' => 'error'];
    }

    ai_scan_form_rate_record($pdo, $ip, $now);

    $base = rtrim((string) ($config['base_url'] ?? 'https://jpwebcreation.nl'), '/');
    $confirmUrl = $base . '/ai-scan.php?confirm=' . rawurlencode($confirmToken);
    $mail = ai_scan_mail_confirm($config, $normalizedEmail, $domain, $confirmUrl);
    if (!$mail['ok']) {
        // Keep the row so a resend path can exist later; surface generic error.
        return ['code' => 'error', 'scan_id' => $scanId];
    }

    return [
        'code' => $dailyFull ? 'daily_full' : 'check_mail',
        'scan_id' => $scanId,
    ];
}

/**
 * Confirm double opt-in: create account, queue job, send set-password link.
 *
 * @return array{code: string, user_id?: int, scan_id?: int}
 */
function ai_scan_confirm(PDO $pdo, array $config, string $token, int $now = 0): array
{
    $now = $now > 0 ? $now : time();
    if ($token === '') {
        return ['code' => 'error'];
    }

    $tokenHash = ai_scan_hash_token($token);
    $stmt = $pdo->prepare('SELECT * FROM scans WHERE confirm_token_hash = :h LIMIT 1');
    $stmt->execute(['h' => $tokenHash]);
    $scan = $stmt->fetch();

    if ($scan === false) {
        // Already used or unknown — do not leak; friendly confirmed if account exists path is frontend.
        return ['code' => 'error'];
    }

    if ((string) $scan['status'] !== 'onbevestigd') {
        return ['code' => 'confirmed'];
    }

    if ((int) ($scan['confirm_expires_at'] ?? 0) < $now) {
        return ['code' => 'error'];
    }

    $dailyLimit = (int) ($config['daily_free_scans'] ?? 10);
    $dailyFull = ai_scan_count_free_scans_today($pdo, $now) >= $dailyLimit;
    $status = $dailyFull ? 'wacht_dag' : 'bevestigd';

    $passwordToken = ai_scan_random_token(32);
    $passwordTtl = (int) ($config['password_token_ttl'] ?? 86400);

    try {
        $pdo->beginTransaction();

        $userStmt = $pdo->prepare('SELECT * FROM users WHERE email_hash = :h LIMIT 1');
        $userStmt->execute(['h' => $scan['email_hash']]);
        $user = $userStmt->fetch();

        if ($user === false) {
            $ins = $pdo->prepare(
                'INSERT INTO users(email, email_hash, password_hash, created_at, free_scan_used)
                 VALUES(:email, :email_hash, NULL, :created_at, 1)'
            );
            $ins->execute([
                'email' => $scan['email'],
                'email_hash' => $scan['email_hash'],
                'created_at' => $now,
            ]);
            $userId = (int) $pdo->lastInsertId();
        } else {
            $userId = (int) $user['id'];
            $pdo->prepare('UPDATE users SET free_scan_used = 1 WHERE id = :id')->execute(['id' => $userId]);
        }

        // Block future free scans for this email/domain.
        $pdo->prepare(
            'INSERT INTO free_scan_blocks(email_hash, domain_hash, created_at)
             VALUES(:email_hash, :domain_hash, :created_at)'
        )->execute([
            'email_hash' => $scan['email_hash'],
            'domain_hash' => $scan['domain_hash'],
            'created_at' => $now,
        ]);

        $upd = $pdo->prepare(
            'UPDATE scans SET
                user_id = :user_id,
                status = :status,
                confirm_token_hash = NULL,
                confirm_expires_at = NULL,
                confirmed_at = :confirmed_at,
                queued_at = :queued_at,
                password_token_hash = :password_token_hash,
                password_token_expires_at = :password_token_expires_at
             WHERE id = :id AND status = :expected'
        );
        $upd->execute([
            'user_id' => $userId,
            'status' => $status,
            'confirmed_at' => $now,
            'queued_at' => $now,
            'password_token_hash' => ai_scan_hash_token($passwordToken),
            'password_token_expires_at' => $now + $passwordTtl,
            'id' => $scan['id'],
            'expected' => 'onbevestigd',
        ]);

        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();

            return ['code' => 'confirmed'];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['code' => 'error'];
    }

    $base = rtrim((string) ($config['base_url'] ?? 'https://jpwebcreation.nl'), '/');
    $passwordUrl = $base . '/ai-scan/wachtwoord/?t=' . rawurlencode($passwordToken);
    $dashboardUrl = $base . '/dashboard/';
    ai_scan_mail_welcome($config, (string) $scan['email'], (string) $scan['domain'], $passwordUrl, $dashboardUrl);

    return [
        'code' => 'confirmed',
        'user_id' => $userId,
        'scan_id' => (int) $scan['id'],
    ];
}

/**
 * Promote waitlisted jobs when today still has free capacity.
 * A wacht_dag job only starts once promoted (queued_at is refreshed to now).
 */
function ai_scan_promote_waitlist(PDO $pdo, array $config, int $now = 0): int
{
    $now = $now > 0 ? $now : time();
    $dailyLimit = (int) ($config['daily_free_scans'] ?? 10);
    $slots = $dailyLimit - ai_scan_count_free_scans_today($pdo, $now);
    if ($slots <= 0) {
        return 0;
    }

    $sel = $pdo->prepare(
        "SELECT id FROM scans WHERE status = 'wacht_dag' ORDER BY confirmed_at ASC, id ASC LIMIT :lim"
    );
    $sel->bindValue('lim', $slots, PDO::PARAM_INT);
    $sel->execute();
    $ids = array_map(static fn ($r) => (int) $r['id'], $sel->fetchAll());
    if ($ids === []) {
        return 0;
    }

    $upd = $pdo->prepare(
        "UPDATE scans SET status = 'bevestigd', queued_at = :queued_at WHERE id = :id AND status = 'wacht_dag'"
    );
    $n = 0;
    foreach ($ids as $id) {
        $upd->execute(['queued_at' => $now, 'id' => $id]);
        $n += $upd->rowCount();
    }

    return $n;
}

/**
 * @return array<string, mixed>|null
 */
function ai_scan_next_job(PDO $pdo, array $config, int $now = 0): ?array
{
    $now = $now > 0 ? $now : time();
    ai_scan_promote_waitlist($pdo, $config, $now);
    ai_scan_purge_expired_unconfirmed($pdo, $now);
    ai_scan_purge_old_ip_hashes($pdo, $now);

    $stmt = $pdo->query(
        "SELECT id, public_id, email, website_url, domain, status, created_at, confirmed_at, queued_at
         FROM scans
         WHERE status = 'bevestigd'
         ORDER BY queued_at ASC, id ASC
         LIMIT 1"
    );
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * @return array<string, mixed>|null
 */
function ai_scan_claim_job(PDO $pdo, int $scanId, int $now = 0): ?array
{
    $now = $now > 0 ? $now : time();
    $upd = $pdo->prepare(
        "UPDATE scans SET status = 'bezig', claimed_at = :claimed_at
         WHERE id = :id AND status = 'bevestigd'"
    );
    $upd->execute(['claimed_at' => $now, 'id' => $scanId]);
    if ($upd->rowCount() !== 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, public_id, email, website_url, domain, status, created_at, confirmed_at, queued_at, claimed_at
         FROM scans WHERE id = :id'
    );
    $stmt->execute(['id' => $scanId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function ai_scan_complete_job(PDO $pdo, int $scanId, string $html, string $json, int $now = 0): bool
{
    $now = $now > 0 ? $now : time();
    // Basic JSON sanity.
    json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    $upd = $pdo->prepare(
        "UPDATE scans SET
            status = 'klaar',
            result_html = :html,
            result_json = :json,
            completed_at = :completed_at,
            error_message = NULL
         WHERE id = :id AND status = 'bezig'"
    );
    $upd->execute([
        'html' => $html,
        'json' => $json,
        'completed_at' => $now,
        'id' => $scanId,
    ]);

    return $upd->rowCount() === 1;
}

function ai_scan_fail_job(PDO $pdo, int $scanId, string $message, int $now = 0): bool
{
    $now = $now > 0 ? $now : time();
    $upd = $pdo->prepare(
        "UPDATE scans SET
            status = 'mislukt',
            error_message = :msg,
            completed_at = :completed_at
         WHERE id = :id AND status IN ('bevestigd', 'bezig')"
    );
    $upd->execute([
        'msg' => mb_substr($message, 0, 2000),
        'completed_at' => $now,
        'id' => $scanId,
    ]);

    return $upd->rowCount() === 1;
}

function ai_scan_purge_expired_unconfirmed(PDO $pdo, int $now): int
{
    $cutoff = $now - 7 * 86400;
    $stmt = $pdo->prepare(
        "DELETE FROM scans WHERE status = 'onbevestigd' AND created_at < :cutoff"
    );
    $stmt->execute(['cutoff' => $cutoff]);

    return $stmt->rowCount();
}

function ai_scan_purge_old_ip_hashes(PDO $pdo, int $now): int
{
    $cutoff = $now - 86400;
    $stmt = $pdo->prepare(
        'UPDATE scans SET ip_hash = NULL WHERE ip_hash IS NOT NULL AND created_at < :cutoff'
    );
    $stmt->execute(['cutoff' => $cutoff]);

    return $stmt->rowCount();
}

/**
 * Set password from one-time token. Never emails the password.
 *
 * @return array{ok: bool, code: string}
 */
function ai_scan_set_password(PDO $pdo, string $token, string $password, int $now = 0): array
{
    $now = $now > 0 ? $now : time();
    if ($token === '' || strlen($password) < 10) {
        return ['ok' => false, 'code' => 'invalid'];
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM scans WHERE password_token_hash = :h LIMIT 1'
    );
    $stmt->execute(['h' => ai_scan_hash_token($token)]);
    $scan = $stmt->fetch();
    if ($scan === false) {
        return ['ok' => false, 'code' => 'invalid_token'];
    }

    if ((int) ($scan['password_token_expires_at'] ?? 0) < $now) {
        return ['ok' => false, 'code' => 'expired'];
    }

    $userId = (int) ($scan['user_id'] ?? 0);
    if ($userId < 1) {
        return ['ok' => false, 'code' => 'error'];
    }

    $hash = ai_scan_hash_password($password);
    $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([
        'h' => $hash,
        'id' => $userId,
    ]);
    $pdo->prepare(
        'UPDATE scans SET password_token_hash = NULL, password_token_expires_at = NULL WHERE id = :id'
    )->execute(['id' => $scan['id']]);

    return ['ok' => true, 'code' => 'ok'];
}

/**
 * AVG/GDPR delete: wipe personal data, keep email/domain hashes for free-scan blocks.
 */
function ai_scan_delete_account(PDO $pdo, int $userId, int $now = 0): bool
{
    $now = $now > 0 ? $now : time();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if ($user === false) {
        return false;
    }

    try {
        $pdo->beginTransaction();

        $scans = $pdo->prepare('SELECT email_hash, domain_hash FROM scans WHERE user_id = :id');
        $scans->execute(['id' => $userId]);
        foreach ($scans->fetchAll() as $row) {
            $pdo->prepare(
                'INSERT INTO free_scan_blocks(email_hash, domain_hash, created_at)
                 VALUES(:email_hash, :domain_hash, :created_at)'
            )->execute([
                'email_hash' => $row['email_hash'],
                'domain_hash' => $row['domain_hash'],
                'created_at' => $now,
            ]);
        }

        // Also block the user email hash.
        $pdo->prepare(
            'INSERT INTO free_scan_blocks(email_hash, domain_hash, created_at)
             VALUES(:email_hash, NULL, :created_at)'
        )->execute([
            'email_hash' => $user['email_hash'],
            'created_at' => $now,
        ]);

        $pdo->prepare(
            "UPDATE scans SET
                email = '',
                website_url = '',
                result_html = NULL,
                result_json = NULL,
                error_message = NULL,
                confirm_token_hash = NULL,
                password_token_hash = NULL,
                ip_hash = NULL,
                status = CASE WHEN status IN ('klaar', 'mislukt') THEN status ELSE 'mislukt' END
             WHERE user_id = :id"
        )->execute(['id' => $userId]);

        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }

    return true;
}

/**
 * @return array<string, mixed>|null
 */
function ai_scan_latest_for_user(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM scans WHERE user_id = :id ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}
