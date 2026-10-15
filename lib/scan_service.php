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

/**
 * Eén gratis scan per IP: blokkade of lopende/afgeronde scan vanaf hetzelfde IP.
 */
function ai_scan_ip_used_free_scan(PDO $pdo, string $ipBlockHash): bool
{
    if ($ipBlockHash === '') {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM free_scan_blocks WHERE ip_hash = :h LIMIT 1');
    $stmt->execute(['h' => $ipBlockHash]);
    if ($stmt->fetch() !== false) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM scans WHERE ip_block_hash = :h AND status = 'onbevestigd' LIMIT 1");
    $stmt->execute(['h' => $ipBlockHash]);

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

function ai_scan_purge_expired_pending(PDO $pdo, string $emailHash, string $domainHash, string $ipBlockHash, int $now): void
{
    $pdo->prepare(
        "DELETE FROM scans
         WHERE status = 'onbevestigd'
           AND confirm_expires_at < :now
           AND (email_hash = :email_hash OR domain_hash = :domain_hash OR (:ip <> '' AND ip_block_hash = :ip2))"
    )->execute([
        'now' => $now,
        'email_hash' => $emailHash,
        'domain_hash' => $domainHash,
        'ip' => $ipBlockHash,
        'ip2' => $ipBlockHash,
    ]);
}

/**
 * Submit a free-scan request. Returns a contract status code.
 * Rate-limit hits use code `rate_limited` (HTTP 429 at the edge).
 *
 * @return array{code: string, scan_id?: int, retry_after?: int, rule?: string, field?: string, message?: string}
 */
function ai_scan_submit(PDO $pdo, array $config, array $input, string $ip, int $now = 0): array
{
    $now = $now > 0 ? $now : time();
    $retryAfter = ai_scan_rate_limit_retry_after($config);

    // Honeypot: always look like success.
    $honeypot = trim((string) ($input['hp_field'] ?? $input['bedrijfsfax'] ?? ''));
    if ($honeypot !== '') {
        return ['code' => 'check_mail'];
    }

    if (ai_scan_form_ip_rate_limited($pdo, $config, $ip, $now)) {
        return ['code' => 'rate_limited', 'retry_after' => $retryAfter];
    }

    $emailRaw = trim((string) ($input['email'] ?? ''));
    $websiteRaw = trim((string) ($input['website'] ?? ''));
    $consent = (string) ($input['consent'] ?? '') === '1'
        || (string) ($input['consent'] ?? '') === 'accepted'
        || (string) ($input['privacy'] ?? '') === 'accepted';

    $validated = ai_scan_validate_signup($config, $emailRaw, $websiteRaw, $consent);
    if (!$validated['ok']) {
        // Ongeldige pogingen tellen mee voor de IP-limiet (los van de burst, zodat een typfout herstellen kan).
        ai_scan_form_invalid_record($pdo, $ip, $now);

        return [
            'code' => 'invalid',
            'rule' => (string) ($validated['rule'] ?? 'unknown'),
            'field' => (string) ($validated['field'] ?? 'form'),
            'message' => (string) ($validated['message'] ?? AI_SCAN_INVALID_PUBLIC_MESSAGE),
        ];
    }

    $normalizedEmail = (string) $validated['email'];
    $deliveryEmail = (string) ($validated['email_delivery'] ?? $normalizedEmail);
    $parsed = $validated['website'];
    $emailHash = ai_scan_email_hash($normalizedEmail);
    $domain = (string) $parsed['domain'];
    $domainHash = ai_scan_domain_hash($domain);

    if (ai_scan_form_target_rate_limited($pdo, $config, $emailHash, $domainHash, $now)) {
        return ['code' => 'rate_limited', 'retry_after' => $retryAfter];
    }

    // Prevent using the form to spam confirm mails.
    if (ai_scan_confirm_mail_rate_limited($pdo, $config, $emailHash, $now)) {
        return ['code' => 'rate_limited', 'retry_after' => (int) ($config['confirm_mail_per_email_seconds'] ?? 600)];
    }

    // Config free_scan_one_per_ip (default aan) — alleen tests zetten dit uit.
    $ipBlockHash = ($config['free_scan_one_per_ip'] ?? true) ? ai_scan_ip_block_hash($config, $ip) : '';

    // Verlopen onbevestigde aanvragen reserveren niets meer: ruim ze op zodat een nieuwe aanvraag kan.
    ai_scan_purge_expired_pending($pdo, $emailHash, $domainHash, $ipBlockHash, $now);

    // First scan free (één per e-mail, domein en IP); daarna alleen abonnement → already_scanned.
    if (
        ai_scan_ip_used_free_scan($pdo, $ipBlockHash)
        || ai_scan_has_block($pdo, $emailHash, $domainHash)
        || ai_scan_has_existing_scan($pdo, $emailHash, $domainHash)
        || ai_scan_user_used_free_scan($pdo, $emailHash)
    ) {
        return ['code' => 'already_scanned'];
    }

    $dailyLimit = (int) ($config['daily_free_scans'] ?? 20);
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
            || ai_scan_ip_used_free_scan($pdo, $ipBlockHash)
        ) {
            $pdo->rollBack();

            return ['code' => 'already_scanned'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO scans (
                public_id, user_id, email, email_hash, website_url, domain, domain_hash,
                status, confirm_token_hash, confirm_expires_at, created_at, ip_hash, ip_block_hash
            ) VALUES (
                :public_id, NULL, :email, :email_hash, :website_url, :domain, :domain_hash,
                :status, :confirm_token_hash, :confirm_expires_at, :created_at, :ip_hash, :ip_block_hash
            )'
        );
        $stmt->execute([
            'public_id' => $publicId,
            'email' => $deliveryEmail,
            'email_hash' => $emailHash,
            'website_url' => (string) $parsed['url'],
            'domain' => $domain,
            'domain_hash' => $domainHash,
            'status' => 'onbevestigd',
            'confirm_token_hash' => ai_scan_hash_token($confirmToken),
            'confirm_expires_at' => $now + $ttl,
            'created_at' => $now,
            'ip_hash' => ai_scan_ip_hash($ip),
            'ip_block_hash' => $ipBlockHash !== '' ? $ipBlockHash : null,
        ]);
        $scanId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['code' => 'error'];
    }

    ai_scan_form_rate_record($pdo, $ip, $emailHash, $domainHash, $now);

    $base = rtrim((string) ($config['base_url'] ?? 'https://jpwebcreation.nl'), '/');
    $confirmUrl = $base . '/ai-scan.php?confirm=' . rawurlencode($confirmToken);
    $mail = ai_scan_mail_confirm($config, $deliveryEmail, $domain, $confirmUrl);
    if (!$mail['ok']) {
        error_log('ai_scan confirm_mail_failed scan_id=' . (int) $scanId . ' cause=' . ai_scan_mail_error_cause((string) ($mail['error'] ?? 'unknown')));
        // Definitieve verzendfout: geef e-mail, domein en IP vrij zodat een nieuwe aanvraag kan.
        $pdo->prepare("DELETE FROM scans WHERE id = :id AND status = 'onbevestigd'")->execute(['id' => $scanId]);

        return ['code' => 'error', 'scan_id' => $scanId];
    }

    ai_scan_confirm_mail_rate_record($pdo, $emailHash, $now);

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

    $dailyLimit = (int) ($config['daily_free_scans'] ?? 20);
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

        // Block future free scans for this email/domain/IP (zelfde rij: opheffen per e-mail/domein haalt ook het IP weg).
        $pdo->prepare(
            'INSERT INTO free_scan_blocks(email_hash, domain_hash, ip_hash, created_at)
             VALUES(:email_hash, :domain_hash, :ip_hash, :created_at)'
        )->execute([
            'email_hash' => $scan['email_hash'],
            'domain_hash' => $scan['domain_hash'],
            'ip_hash' => ($scan['ip_block_hash'] ?? '') !== '' ? $scan['ip_block_hash'] : null,
            'created_at' => $now,
        ]);
        $pdo->prepare('UPDATE scans SET ip_block_hash = NULL WHERE id = :id')->execute(['id' => $scan['id']]);

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
    if ($status === 'bevestigd') {
        ai_scan_trigger_runner($config);
    }

    return [
        'code' => 'confirmed',
        'user_id' => $userId,
        'scan_id' => (int) $scan['id'],
        // Zelfde eenmalige, verlopende token als in de welkomstmail: direct door naar wachtwoord kiezen.
        'password_token' => $passwordToken,
    ];
}

/**
 * Promote waitlisted jobs when today still has free capacity.
 * A wacht_dag job only starts once promoted (queued_at is refreshed to now).
 */
function ai_scan_promote_waitlist(PDO $pdo, array $config, int $now = 0): int
{
    $now = $now > 0 ? $now : time();
    $dailyLimit = (int) ($config['daily_free_scans'] ?? 20);
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
        "SELECT id, public_id, email, website_url, domain, status, created_at, confirmed_at, queued_at, attempts, spent_eur
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
        'SELECT id, public_id, email, website_url, domain, status, created_at, confirmed_at, queued_at, claimed_at, attempts, spent_eur
         FROM scans WHERE id = :id'
    );
    $stmt->execute(['id' => $scanId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function ai_scan_complete_job(PDO $pdo, array $config, int $scanId, string $html, string $json, int $now = 0): bool
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

    if ($upd->rowCount() !== 1) {
        return false;
    }

    ai_scan_send_ready_mail_once($pdo, $config, $scanId, $now);

    return true;
}

/**
 * Send the Dutch "scan klaar" mail at most once per scan, with mail throttle.
 *
 * @return array{ok: bool, sent: bool, reason?: string}
 */
function ai_scan_send_ready_mail_once(PDO $pdo, array $config, int $scanId, int $now = 0): array
{
    $now = $now > 0 ? $now : time();

    $stmt = $pdo->prepare("SELECT * FROM scans WHERE id = :id AND status = 'klaar' LIMIT 1");
    $stmt->execute(['id' => $scanId]);
    $scan = $stmt->fetch();
    if ($scan === false) {
        return ['ok' => false, 'sent' => false, 'reason' => 'not_found'];
    }

    if ($scan['ready_mail_sent_at'] !== null) {
        return ['ok' => true, 'sent' => false, 'reason' => 'already_sent'];
    }

    $email = trim((string) $scan['email']);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'sent' => false, 'reason' => 'no_email'];
    }

    $emailHash = (string) $scan['email_hash'];
    if (ai_scan_ready_mail_rate_limited($pdo, $config, $emailHash, $now)) {
        return ['ok' => true, 'sent' => false, 'reason' => 'throttled'];
    }

    // Atomic claim: only one sender wins.
    $claim = $pdo->prepare(
        "UPDATE scans SET ready_mail_sent_at = :sent_at
         WHERE id = :id AND status = 'klaar' AND ready_mail_sent_at IS NULL"
    );
    $claim->execute(['sent_at' => $now, 'id' => $scanId]);
    if ($claim->rowCount() !== 1) {
        return ['ok' => true, 'sent' => false, 'reason' => 'already_sent'];
    }

    $base = rtrim((string) ($config['base_url'] ?? 'https://jpwebcreation.nl'), '/');
    $dashboardUrl = $base . '/login/?next=' . rawurlencode('/dashboard/');
    $domain = (string) ($scan['domain'] !== '' ? $scan['domain'] : $scan['website_url']);
    $mail = ai_scan_mail_scan_ready($config, $email, $domain, $dashboardUrl);

    if (!$mail['ok']) {
        $pdo->prepare(
            'UPDATE scans SET ready_mail_sent_at = NULL WHERE id = :id AND ready_mail_sent_at = :sent_at'
        )->execute(['id' => $scanId, 'sent_at' => $now]);

        return ['ok' => false, 'sent' => false, 'reason' => 'mail_failed'];
    }

    ai_scan_ready_mail_rate_record($pdo, $emailHash, $now);

    return ['ok' => true, 'sent' => true];
}

/**
 * Retry klaar-mails that were skipped due to throttle (best-effort).
 */
function ai_scan_flush_ready_mails(PDO $pdo, array $config, int $limit = 10, int $now = 0): int
{
    $now = $now > 0 ? $now : time();
    $stmt = $pdo->prepare(
        "SELECT id FROM scans
         WHERE status = 'klaar' AND ready_mail_sent_at IS NULL AND email != ''
         ORDER BY completed_at ASC, id ASC
         LIMIT :lim"
    );
    $stmt->bindValue('lim', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    $sent = 0;
    foreach ($stmt->fetchAll() as $row) {
        $result = ai_scan_send_ready_mail_once($pdo, $config, (int) $row['id'], $now);
        if (!empty($result['sent'])) {
            $sent++;
        }
    }

    return $sent;
}

/**
 * Definitief mislukt: status mislukt, melding bewaard, poging geteld.
 */
function ai_scan_fail_job(PDO $pdo, int $scanId, string $message, int $now = 0): bool
{
    $now = $now > 0 ? $now : time();
    $upd = $pdo->prepare(
        "UPDATE scans SET
            status = 'mislukt',
            error_message = :msg,
            attempts = attempts + 1,
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

/**
 * Runner meldt een mislukking: de eerste keer gaat de job terug de wachtrij in (automatisch opnieuw),
 * de tweede keer is hij definitief mislukt en krijgt Joris een melding. Maximaal `scan_max_retries` (1) keer opnieuw.
 *
 * @return array{ok: bool, retry?: bool, final?: bool, attempts?: int}
 */
function ai_scan_fail_or_retry_job(PDO $pdo, array $config, int $scanId, string $message, int $now = 0, float $spentEur = 0.0): array
{
    $now = $now > 0 ? $now : time();
    // Totaal besteed over alle pogingen (runner stuurt cumulatief); nooit omlaag, zodat een herpoging alleen het restant krijgt.
    if ($spentEur > 0 && is_finite($spentEur)) {
        $sp = $pdo->prepare('UPDATE scans SET spent_eur = MAX(spent_eur, CAST(:s AS REAL)) WHERE id = :id');
        $sp->execute(['s' => min($spentEur, 1000.0), 'id' => $scanId]);
    }
    $maxRetries = max(0, (int) ($config['scan_max_retries'] ?? 1));
    $message = mb_substr($message, 0, 2000);

    $requeue = $pdo->prepare(
        "UPDATE scans SET
            status = 'bevestigd',
            attempts = attempts + 1,
            last_failure = :msg,
            error_message = NULL,
            claimed_at = NULL,
            progress_json = NULL,
            progress_at = NULL,
            queued_at = :now
         WHERE id = :id AND status IN ('bevestigd', 'bezig') AND attempts < :max"
    );
    $requeue->execute(['msg' => $message, 'now' => $now, 'id' => $scanId, 'max' => $maxRetries]);
    if ($requeue->rowCount() === 1) {
        error_log('ai_scan job_retry scan_id=' . $scanId);

        return ['ok' => true, 'retry' => true, 'final' => false, 'attempts' => 1];
    }

    if (!ai_scan_fail_job($pdo, $scanId, $message, $now)) {
        return ['ok' => false];
    }
    error_log('ai_scan job_failed_final scan_id=' . $scanId);
    try {
        $row = $pdo->prepare('SELECT public_id, domain, attempts FROM scans WHERE id = :id');
        $row->execute(['id' => $scanId]);
        $scan = $row->fetch();
        if ($scan !== false) {
            ai_scan_mail_failed_alert($config, (string) $scan['public_id'], (string) $scan['domain'], $message);
        }
    } catch (Throwable $e) {
        // De melding is bijzaak; de status staat al op mislukt.
    }

    return ['ok' => true, 'retry' => false, 'final' => true, 'attempts' => $maxRetries + 1];
}

/**
 * Staat er een job klaar voor de runner? Zelfde definitie als ai_scan_next_job, maar alleen lezen:
 * een bevestigde scan die wacht (ook opnieuw te proberen), of een wachtdag-job waarvoor vandaag ruimte is.
 */
function ai_scan_has_runnable_job(PDO $pdo, array $config, int $now = 0): bool
{
    $now = $now > 0 ? $now : time();
    if ($pdo->query("SELECT 1 FROM scans WHERE status = 'bevestigd' LIMIT 1")->fetch() !== false) {
        return true;
    }
    if ($pdo->query("SELECT 1 FROM scans WHERE status = 'wacht_dag' LIMIT 1")->fetch() === false) {
        return false;
    }

    return ai_scan_count_free_scans_today($pdo, $now) < (int) ($config['daily_free_scans'] ?? 20);
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
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
    $exists->execute(['id' => $userId]);
    if ($userId < 1 || $exists->fetch() === false) {
        // Account bestaat niet (meer): nooit "wachtwoord ingesteld" tonen.
        return ['ok' => false, 'code' => 'no_account'];
    }

    $pdo->beginTransaction();
    try {
        // Consume the one-time token atomically first, so a replayed/raced request cannot reuse it.
        $consume = $pdo->prepare(
            'UPDATE scans SET password_token_hash = NULL, password_token_expires_at = NULL
             WHERE id = :id AND password_token_hash = :h'
        );
        $consume->execute(['id' => $scan['id'], 'h' => ai_scan_hash_token($token)]);
        if ($consume->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'invalid_token'];
        }

        $hash = ai_scan_hash_password($password);
        $upd = $pdo->prepare('UPDATE users SET password_hash = :h, password_version = password_version + 1, pending_email = NULL, pending_email_token_hash = NULL, pending_email_expires_at = NULL WHERE id = :id');
        $upd->execute([
            'h' => $hash,
            'id' => $userId,
        ]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'code' => 'no_account'];
        }
        $pdo->prepare(
            'UPDATE scans SET password_token_hash = NULL, password_token_expires_at = NULL WHERE user_id = :id'
        )->execute(['id' => $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $mailStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
    $mailStmt->execute(['id' => $userId]);

    return [
        'ok' => true,
        'code' => 'ok',
        'email' => (string) ($mailStmt->fetchColumn() ?: ''),
        'scan_status' => (string) ($scan['status'] ?? ''),
    ];
}

/**
 * Wachtwoord vergeten: stuurt, als er een account bestaat, een eenmalige link naar de bestaande
 * wachtwoordpagina (/ai-scan/wachtwoord/?t=…). De aanroeper toont altijd dezelfde neutrale melding.
 * Beperkt per IP en per e-mailadres; telt ook pogingen voor onbekende adressen.
 *
 * @return string 'sent' | 'no_account' | 'rate_limited' | 'mail_failed' (alleen voor logs/tests, nooit tonen)
 */
function ai_scan_request_password_reset(PDO $pdo, array $config, string $emailRaw, string $ip, int $now = 0): string
{
    $now = $now > 0 ? $now : time();
    $ipKey = ai_scan_ip_hash($ip);
    $emailHash = ai_scan_email_hash(ai_scan_normalize_email($emailRaw));

    $ipMax = (int) ($config['reset_ip_max_per_hour'] ?? 5);
    $emailSeconds = (int) ($config['reset_email_seconds'] ?? 600);
    if (
        ($ipMax > 0 && ai_scan_rate_count($pdo, 'reset_ip', $ipKey, $now - 3600) >= $ipMax)
        || ($emailSeconds > 0 && ai_scan_rate_count($pdo, 'reset_email', $emailHash, $now - $emailSeconds) >= 1)
    ) {
        return 'rate_limited';
    }
    ai_scan_rate_hit($pdo, 'reset_ip', $ipKey, $now);
    ai_scan_rate_hit($pdo, 'reset_email', $emailHash, $now);

    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email_hash = :h LIMIT 1');
    $stmt->execute(['h' => $emailHash]);
    $user = $stmt->fetch();
    if ($user === false) {
        return 'no_account';
    }
    $scan = ai_scan_latest_for_user($pdo, (int) $user['id']);
    if ($scan === null) {
        return 'no_account';
    }

    // De token hangt aan de laatste scan van het account; dezelfde pagina en functie als bij het eerste wachtwoord.
    $token = ai_scan_random_token(32);
    $ttl = (int) ($config['reset_token_ttl'] ?? 3600);
    $pdo->prepare(
        'UPDATE scans SET password_token_hash = :h, password_token_expires_at = :x WHERE id = :id'
    )->execute(['h' => ai_scan_hash_token($token), 'x' => $now + $ttl, 'id' => $scan['id']]);

    $base = rtrim((string) ($config['base_url'] ?? 'https://jpwebcreation.nl'), '/');
    $mail = ai_scan_mail_password_reset($config, (string) $user['email'], $base . '/ai-scan/wachtwoord/?t=' . rawurlencode($token));

    return $mail['ok'] ? 'sent' : 'mail_failed';
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
                domain = '',
                progress_json = NULL,
                progress_at = NULL,
                last_failure = NULL,
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

/**
 * Beheer: hef een gratis-scanblokkade op voor een e-mailadres en/of domein.
 * Verwijdert de free_scan_blocks-rijen met de bijbehorende hash; logt alleen hashes.
 *
 * @return array{ok: bool, deleted?: int, error?: string}
 */
function ai_scan_admin_lift_free_scan_block(PDO $pdo, string $emailRaw, string $websiteRaw, string $adminEmail = ''): array
{
    $emailRaw = trim($emailRaw);
    $websiteRaw = trim($websiteRaw);
    $emailHash = null;
    $domainHash = null;

    if ($emailRaw !== '') {
        if (filter_var($emailRaw, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'email'];
        }
        $emailHash = ai_scan_email_hash(ai_scan_normalize_email($emailRaw));
    }
    if ($websiteRaw !== '') {
        $parsed = ai_scan_parse_website($websiteRaw);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'error' => 'domain'];
        }
        $domainHash = ai_scan_domain_hash((string) $parsed['domain']);
    }
    if ($emailHash === null && $domainHash === null) {
        return ['ok' => false, 'error' => 'empty'];
    }

    $deleted = 0;
    foreach (ai_scan_ip_block_hashes_for($pdo, $emailHash, $domainHash, false) as $ipHash) {
        $stmt = $pdo->prepare('DELETE FROM free_scan_blocks WHERE ip_hash = :h');
        $stmt->execute(['h' => $ipHash]);
        $deleted += $stmt->rowCount();
    }
    if ($emailHash !== null) {
        $stmt = $pdo->prepare('DELETE FROM free_scan_blocks WHERE email_hash = :h');
        $stmt->execute(['h' => $emailHash]);
        $deleted += $stmt->rowCount();
    }
    if ($domainHash !== null) {
        $stmt = $pdo->prepare('DELETE FROM free_scan_blocks WHERE domain_hash = :h');
        $stmt->execute(['h' => $domainHash]);
        $deleted += $stmt->rowCount();
    }

    error_log(sprintf(
        'ai_scan admin_lift_block by=%s email_hash=%s domain_hash=%s deleted=%d',
        $adminEmail !== '' ? hash('sha256', 'email:' . strtolower($adminEmail)) : 'unknown',
        $emailHash !== null ? substr($emailHash, 0, 12) : '-',
        $domainHash !== null ? substr($domainHash, 0, 12) : '-',
        $deleted
    ));

    return ['ok' => true, 'deleted' => $deleted];
}

/**
 * Telt blokkades voor e-mail en/of domein (voor controle in beheer).
 */
function ai_scan_count_free_scan_blocks(PDO $pdo, string $emailRaw, string $websiteRaw): int
{
    $emailHash = trim($emailRaw) !== '' ? ai_scan_email_hash(ai_scan_normalize_email(trim($emailRaw))) : '';
    $domainHash = '';
    if (trim($websiteRaw) !== '') {
        $parsed = ai_scan_parse_website($websiteRaw);
        $domainHash = !empty($parsed['ok']) ? ai_scan_domain_hash((string) $parsed['domain']) : '';
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM free_scan_blocks WHERE (:e <> \'\' AND email_hash = :e2) OR (:d <> \'\' AND domain_hash = :d2)');
    $stmt->execute(['e' => $emailHash, 'e2' => $emailHash, 'd' => $domainHash, 'd2' => $domainHash]);

    return (int) $stmt->fetchColumn();
}

/**
 * Mail-foutoorzaak voor error_log: één regel, ingekort, zonder adressen of base64.
 */
function ai_scan_mail_error_cause(string $error): string
{
    $error = str_replace(["\r", "\n"], ' ', $error);
    $error = preg_replace('/[^\s@<>]+@[^\s@<>]+/', '<addr>', $error) ?? '';
    $error = preg_replace('/[A-Za-z0-9+\/=]{24,}/', '<redacted>', $error) ?? '';

    return substr(trim($error), 0, 200);
}

/**
 * ip_block_hash-waarden van scans met deze e-mail- en/of domeinhash.
 *
 * @return list<string>
 */
function ai_scan_ip_block_hashes_for(PDO $pdo, ?string $emailHash, ?string $domainHash, bool $pendingOnly): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT ip_block_hash FROM scans
         WHERE ((:e <> \'\' AND email_hash = :e2) OR (:d <> \'\' AND domain_hash = :d2))'
        . ($pendingOnly ? " AND status = 'onbevestigd'" : '')
    );
    $e = $emailHash ?? '';
    $d = $domainHash ?? '';
    $stmt->execute(['e' => $e, 'e2' => $e, 'd' => $d, 'd2' => $d]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $h) {
        if (is_string($h) && $h !== '') {
            $out[] = $h;
        }
    }

    return $out;
}

/**
 * Beheer: annuleer onbevestigde aanvragen voor een e-mailadres en/of domein
 * en hef de IP-blokkade van die aanvragen op. Logt alleen aantallen.
 *
 * @return array{ok: bool, scans?: int, ip_blocks?: int, error?: string}
 */
function ai_scan_admin_cancel_pending(PDO $pdo, string $emailRaw, string $websiteRaw): array
{
    $emailHash = null;
    $domainHash = null;
    if (trim($emailRaw) !== '') {
        if (filter_var(trim($emailRaw), FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'email'];
        }
        $emailHash = ai_scan_email_hash(ai_scan_normalize_email(trim($emailRaw)));
    }
    if (trim($websiteRaw) !== '') {
        $parsed = ai_scan_parse_website($websiteRaw);
        if (empty($parsed['ok'])) {
            return ['ok' => false, 'error' => 'domain'];
        }
        $domainHash = ai_scan_domain_hash((string) $parsed['domain']);
    }
    if ($emailHash === null && $domainHash === null) {
        return ['ok' => false, 'error' => 'empty'];
    }

    $ipBlocks = 0;
    foreach (ai_scan_ip_block_hashes_for($pdo, $emailHash, $domainHash, true) as $ipHash) {
        $stmt = $pdo->prepare('DELETE FROM free_scan_blocks WHERE ip_hash = :h');
        $stmt->execute(['h' => $ipHash]);
        $ipBlocks += $stmt->rowCount();
    }
    $e = $emailHash ?? '';
    $d = $domainHash ?? '';
    $stmt = $pdo->prepare(
        "DELETE FROM scans WHERE status = 'onbevestigd'
           AND ((:e <> '' AND email_hash = :e2) OR (:d <> '' AND domain_hash = :d2))"
    );
    $stmt->execute(['e' => $e, 'e2' => $e, 'd' => $d, 'd2' => $d]);
    $scans = $stmt->rowCount();

    error_log(sprintf('ai_scan admin_cancel_pending scans=%d ip_blocks=%d', $scans, $ipBlocks));

    return ['ok' => true, 'scans' => $scans, 'ip_blocks' => $ipBlocks];
}

/**
 * Eenmalige opschoning van testdata (deploy-ops): alleen scans, blokkades (e-mail/IP) en gebruiker
 * van dit e-mailadres die binnen het tijdvenster zijn aangemaakt. Andere accounts op hetzelfde domein blijven staan.
 *
 * @return array{ok: bool, blocks?: int, scans?: int, users?: int, user_skipped?: int, error?: string}
 */
function ai_scan_ops_purge_test_data(PDO $pdo, string $emailRaw, string $websiteRaw, int $createdFrom, int $createdTo): array
{
    if (trim($emailRaw) === '' || trim($websiteRaw) === '' || $createdFrom <= 0 || $createdTo <= $createdFrom) {
        return ['ok' => false, 'error' => 'input'];
    }
    $email = trim($emailRaw);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'email'];
    }
    $parsed = ai_scan_parse_website($websiteRaw);
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'error' => 'domain'];
    }
    $emailHash = ai_scan_email_hash(ai_scan_normalize_email($email));
    $window = ['e' => $emailHash, 'f' => $createdFrom, 't' => $createdTo];

    $sel = $pdo->prepare('SELECT id, ip_block_hash FROM scans WHERE email_hash = :e AND created_at BETWEEN :f AND :t');
    $sel->execute($window);
    $scanRows = $sel->fetchAll();

    $blocks = 0;
    $delBlock = $pdo->prepare('DELETE FROM free_scan_blocks WHERE email_hash = :e AND created_at BETWEEN :f AND :t');
    $delBlock->execute($window);
    $blocks += $delBlock->rowCount();
    $delIp = $pdo->prepare('DELETE FROM free_scan_blocks WHERE ip_hash = :h AND created_at BETWEEN :f AND :t');
    $delScan = $pdo->prepare('DELETE FROM scans WHERE id = :id');
    $scans = 0;
    foreach ($scanRows as $row) {
        if ((string) ($row['ip_block_hash'] ?? '') !== '') {
            $delIp->execute(['h' => (string) $row['ip_block_hash'], 'f' => $createdFrom, 't' => $createdTo]);
            $blocks += $delIp->rowCount();
        }
        $delScan->execute(['id' => (int) $row['id']]);
        $scans += $delScan->rowCount();
    }

    $users = 0;
    $skipped = 0;
    $sel = $pdo->prepare('SELECT id, created_at FROM users WHERE email_hash = :e');
    $sel->execute(['e' => $emailHash]);
    foreach ($sel->fetchAll() as $u) {
        $created = (int) $u['created_at'];
        if ($created >= $createdFrom && $created <= $createdTo) {
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int) $u['id']]);
            $users++;
        } else {
            $skipped++;
        }
    }

    return ['ok' => true, 'blocks' => $blocks, 'scans' => $scans, 'users' => $users, 'user_skipped' => $skipped];
}

/**
 * Start de scanrunner direct via GitHub workflow_dispatch (kort, niet-blokkerend genoeg: 3 s timeout).
 * Mislukt dit, dan logt het alleen; het schema elke 30 minuten blijft de terugval.
 */
function ai_scan_trigger_runner(array $config): bool
{
    $token = (string) ($config['runner_dispatch_token'] ?? '');
    if ($token === '' || !function_exists('curl_init')) {
        return false;
    }
    $url = 'https://api.github.com/repos/JorisPaarde/jpwebcreation-scripts/actions/workflows/freescan.yml/dispatches';
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{"ref":"main"}',
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: jpweb-ai-scan',
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code !== 204) {
        error_log('ai_scan runner_dispatch_failed http=' . $code . ($err !== '' ? ' err=' . substr($err, 0, 120) : ''));

        return false;
    }

    return true;
}

/**
 * Live voortgang van een lopende scan (runner action=progress). Alleen voor status 'bezig'.
 *
 * @param array<string, mixed> $progress
 */
function ai_scan_store_progress(PDO $pdo, int $id, array $progress, int $now = 0): string
{
    $now = $now > 0 ? $now : time();
    $json = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return 'bad_json';
    }
    if (strlen($json) > 65536) {
        return 'too_large';
    }
    $stmt = $pdo->prepare("UPDATE scans SET progress_json = :p, progress_at = :t WHERE id = :id AND status = 'bezig'");
    $stmt->execute(['p' => $json, 't' => $now, 'id' => $id]);

    return $stmt->rowCount() === 1 ? 'ok' : 'not_running';
}

/**
 * Publieke (eigen gebruiker) weergave van de voortgang: alleen veilige velden.
 *
 * @return array{status: string, progress: array<string, mixed>|null}
 */
function ai_scan_progress_view(?array $scan): array
{
    if ($scan === null) {
        return ['status' => 'none', 'progress' => null];
    }
    $status = (string) ($scan['status'] ?? '');
    $raw = (string) ($scan['progress_json'] ?? '');
    $p = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($p) || $status !== 'bezig') {
        return ['status' => $status, 'progress' => null];
    }
    $out = [
        'step' => (string) ($p['step'] ?? ''),
        'active_source' => (string) ($p['active_source'] ?? ''),
        'sources' => [],
        'answers' => [],
    ];
    foreach ((array) ($p['sources'] ?? []) as $label => $src) {
        if (is_array($src)) {
            $out['sources'][] = [
                'label' => substr((string) $label, 0, 40),
                'done' => (int) ($src['done'] ?? 0),
                'total' => (int) ($src['total'] ?? 0),
                'mentioned' => (int) ($src['mentioned'] ?? 0),
            ];
        }
    }
    foreach (array_slice((array) ($p['answers'] ?? []), -60) as $a) {
        if (is_array($a)) {
            $out['answers'][] = [
                'source' => substr((string) ($a['source'] ?? ''), 0, 40),
                'vraag' => substr((string) ($a['vraag'] ?? ''), 0, 200),
                'mentioned' => !empty($a['mentioned']),
            ];
        }
    }

    return ['status' => $status, 'progress' => $out];
}
