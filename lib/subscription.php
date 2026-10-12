<?php

declare(strict_types=1);

/**
 * Subscription requests + admin tier changes. No payment flow.
 */

const AI_SCAN_TIER_REQUEST_MESSAGE = 'Aanvraag ontvangen, ik pas je abonnement aan.';
const AI_SCAN_FREE_WRITE_MESSAGE = 'Zit in het abonnement: elke week een nieuwe meting.';

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed>|null $tiers
 */
function ai_scan_can_write(array $user, ?array $tiers = null): bool
{
    $tiers ??= ai_scan_load_tiers();
    $effective = ai_scan_effective_tier($user, $tiers);

    return empty($effective['read_only']);
}

/**
 * Bedrijfsgegevens en concurrenten mag elk account aanvullen, ook gratis
 * (voorgevuld uit de scan). Meet-instellingen blijven voor het abonnement.
 *
 * @param array<string, mixed> $user
 */
function ai_scan_can_edit_setup(array $user): bool
{
    return (int) ($user['id'] ?? 0) > 0;
}

/**
 * Actions remaining this calendar month (Europe/Amsterdam).
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed>|null $tiers
 */
function ai_scan_actions_remaining(PDO $pdo, array $user, ?array $tiers = null): int
{
    $tiers ??= ai_scan_load_tiers();
    $effective = ai_scan_effective_tier($user, $tiers);
    $limit = (int) $effective['actions'];
    if ($limit < 1) {
        return 0;
    }

    $used = ai_scan_usage_actions_used($pdo, (int) $user['id']);

    return max(0, $limit - $used);
}

function ai_scan_usage_month(?int $now = null): string
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $dt = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);

    return $dt->format('Y-m');
}

function ai_scan_usage_actions_used(PDO $pdo, int $userId, ?int $now = null): int
{
    $month = ai_scan_usage_month($now);
    $stmt = $pdo->prepare(
        'SELECT actions_used FROM usage_counters WHERE user_id = :u AND month = :m LIMIT 1'
    );
    $stmt->execute(['u' => $userId, 'm' => $month]);
    $row = $stmt->fetch();

    return $row === false ? 0 : (int) $row['actions_used'];
}

function ai_scan_usage_bump_actions(PDO $pdo, int $userId, int $by = 1, ?int $now = null): void
{
    $month = ai_scan_usage_month($now);
    $stmt = $pdo->prepare(
        'INSERT INTO usage_counters(user_id, month, actions_used, question_edits)
         VALUES(:u, :m, :a, 0)
         ON CONFLICT(user_id, month) DO UPDATE SET
           actions_used = actions_used + excluded.actions_used'
    );
    $stmt->execute(['u' => $userId, 'm' => $month, 'a' => max(0, $by)]);
}

/**
 * Atomically consume one action from the monthly budget when under the limit.
 * Returns false when the budget is already exhausted (safe under concurrent posts).
 */
function ai_scan_usage_try_consume_action(PDO $pdo, int $userId, int $limit, ?int $now = null): bool
{
    if ($limit < 1) {
        return false;
    }
    $month = ai_scan_usage_month($now);
    $pdo->prepare(
        'INSERT INTO usage_counters(user_id, month, actions_used, question_edits)
         VALUES(:u, :m, 0, 0)
         ON CONFLICT(user_id, month) DO NOTHING'
    )->execute(['u' => $userId, 'm' => $month]);

    $stmt = $pdo->prepare(
        'UPDATE usage_counters
         SET actions_used = actions_used + 1
         WHERE user_id = :u AND month = :m AND actions_used < :limit'
    );
    $stmt->execute([
        'u' => $userId,
        'm' => $month,
        'limit' => $limit,
    ]);

    return $stmt->rowCount() === 1;
}

/**
 * @param array<string, mixed> $user
 * @return array{ok: bool, mailed: bool, message: string, throttled?: bool, error?: string}
 */
function ai_scan_request_tier_change(
    PDO $pdo,
    array $config,
    array $user,
    string $requestedTier,
    string $kind,
    ?int $now = null
): array {
    $now ??= time();
    $kind = ai_scan_normalize_request_kind($kind);
    $requestedTier = trim($requestedTier);
    $tiers = ai_scan_load_tiers();

    if ($kind === 'cancel') {
        $requestedTier = $requestedTier !== '' ? $requestedTier : 'free';
    } elseif ($requestedTier === '') {
        return ['ok' => false, 'mailed' => false, 'message' => AI_SCAN_TIER_REQUEST_MESSAGE, 'error' => 'missing_tier'];
    }

    if ($kind !== 'cancel' && $requestedTier !== 'free' && $requestedTier !== 'custom') {
        if (ai_scan_tier_by_id($tiers, $requestedTier) === null) {
            return ['ok' => false, 'mailed' => false, 'message' => AI_SCAN_TIER_REQUEST_MESSAGE, 'error' => 'unknown_tier'];
        }
    }

    $email = (string) ($user['email'] ?? '');
    $emailHash = (string) ($user['email_hash'] ?? ($email !== '' ? ai_scan_email_hash($email) : ''));
    $fromTier = (string) ($user['tier_id'] ?? 'free');

    if ($emailHash !== '' && ai_scan_tier_request_mail_rate_limited($pdo, $config, $emailHash, $now)) {
        return [
            'ok' => true,
            'mailed' => false,
            'throttled' => true,
            'message' => AI_SCAN_TIER_REQUEST_MESSAGE,
        ];
    }

    $accountName = ai_scan_account_display_name($pdo, $user);
    $toInfo = (string) (($config['smtp']['alert_to'] ?? '') ?: ($config['smtp']['from_email'] ?? 'info@jpwebcreation.nl'));
    if ($toInfo === '') {
        $toInfo = 'info@jpwebcreation.nl';
    }

    $infoMail = ai_scan_mail_tier_request_admin(
        $config,
        $toInfo,
        $accountName,
        $email,
        $fromTier,
        $requestedTier,
        $kind
    );

    $customerMailed = false;
    if ($email !== '' && !empty($infoMail['ok'])) {
        $confirm = ai_scan_mail_tier_request_customer(
            $config,
            $email,
            $accountName,
            $requestedTier,
            $kind,
            $tiers
        );
        $customerMailed = !empty($confirm['ok']);
    }

    $mailed = !empty($infoMail['ok']);
    if ($mailed && $emailHash !== '') {
        ai_scan_tier_request_mail_rate_record($pdo, $emailHash, $now);
    }

    ai_scan_subscription_event(
        $pdo,
        (int) $user['id'],
        'request',
        $fromTier,
        $requestedTier,
        'owner',
        $kind,
        $now
    );

    return [
        'ok' => true,
        'mailed' => $mailed,
        'customer_mailed' => $customerMailed,
        'message' => AI_SCAN_TIER_REQUEST_MESSAGE,
    ];
}

/**
 * Admin sets tier immediately. Excess questions become paused (never deleted).
 *
 * @param array<string, mixed> $fields tier_id, custom_questions?, subscription_status, period_end?, billing_period?
 * @return array{ok: bool, paused: int, mailed: bool, error?: string}
 */
function ai_scan_admin_set_tier(
    PDO $pdo,
    array $config,
    int $userId,
    array $fields,
    string $byRole = 'admin',
    ?int $now = null
): array {
    $now ??= time();
    $tiers = ai_scan_load_tiers();

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if ($user === false) {
        return ['ok' => false, 'paused' => 0, 'mailed' => false, 'error' => 'user_not_found'];
    }

    $tierId = trim((string) ($fields['tier_id'] ?? 'free'));
    $status = trim((string) ($fields['subscription_status'] ?? 'none'));
    $allowedStatus = ['none', 'active', 'canceling', 'past_due', 'ended'];
    if (!in_array($status, $allowedStatus, true)) {
        return ['ok' => false, 'paused' => 0, 'mailed' => false, 'error' => 'bad_status'];
    }

    $customQuestions = null;
    if (array_key_exists('custom_questions', $fields) && $fields['custom_questions'] !== null && $fields['custom_questions'] !== '') {
        $customQuestions = (int) $fields['custom_questions'];
    }

    if ($tierId === 'custom') {
        $above = (int) (($tiers['above_max']['from_questions'] ?? 1000));
        if ($customQuestions === null || $customQuestions <= $above || ($customQuestions % 100) !== 0) {
            return ['ok' => false, 'paused' => 0, 'mailed' => false, 'error' => 'bad_custom_questions'];
        }
    } elseif ($tierId !== 'free') {
        if (ai_scan_tier_by_id($tiers, $tierId) === null) {
            return ['ok' => false, 'paused' => 0, 'mailed' => false, 'error' => 'unknown_tier'];
        }
        $customQuestions = null;
    } else {
        $customQuestions = null;
    }

    $fromTier = (string) ($user['tier_id'] ?? 'free');
    $billingPeriod = $fields['billing_period'] ?? $user['billing_period'] ?? null;
    if ($billingPeriod !== null && $billingPeriod !== '' && !in_array($billingPeriod, ['month', 'year'], true)) {
        return ['ok' => false, 'paused' => 0, 'mailed' => false, 'error' => 'bad_period'];
    }
    if ($billingPeriod === '') {
        $billingPeriod = null;
    }

    $periodEnd = array_key_exists('period_end', $fields) ? $fields['period_end'] : ($user['period_end'] ?? null);
    if ($periodEnd === '' || $periodEnd === false) {
        $periodEnd = null;
    }
    if ($periodEnd !== null) {
        $periodEnd = (int) $periodEnd;
    }

    $upd = $pdo->prepare(
        'UPDATE users SET
            tier_id = :tier_id,
            custom_questions = :custom_questions,
            subscription_status = :subscription_status,
            billing_period = :billing_period,
            period_end = :period_end,
            tier_changed_at = :tier_changed_at
         WHERE id = :id'
    );
    $upd->execute([
        'tier_id' => $tierId,
        'custom_questions' => $customQuestions,
        'subscription_status' => $status,
        'billing_period' => $billingPeriod,
        'period_end' => $periodEnd,
        'tier_changed_at' => $now,
        'id' => $userId,
    ]);

    $fresh = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $fresh->execute(['id' => $userId]);
    $updated = $fresh->fetch();
    if ($updated === false) {
        return ['ok' => false, 'paused' => 0, 'mailed' => false, 'error' => 'user_missing'];
    }

    $effective = ai_scan_effective_tier($updated, $tiers);
    $paused = ai_scan_pause_excess_questions($pdo, $userId, (int) $effective['questions']);

    ai_scan_subscription_event(
        $pdo,
        $userId,
        'admin_set',
        $fromTier,
        $tierId,
        $byRole,
        'admin_set',
        $now
    );

    $mailed = false;
    $email = (string) ($updated['email'] ?? '');
    $emailHash = (string) ($updated['email_hash'] ?? ($email !== '' ? ai_scan_email_hash($email) : ''));
    if ($email !== '' && $emailHash !== '' && !ai_scan_tier_change_mail_rate_limited($pdo, $config, $emailHash, $now)) {
        $name = ai_scan_account_display_name($pdo, $updated);
        $send = ai_scan_mail_tier_changed_customer($config, $email, $name, $effective, $tiers);
        if (!empty($send['ok'])) {
            ai_scan_tier_change_mail_rate_record($pdo, $emailHash, $now);
            $mailed = true;
        }
    }

    return ['ok' => true, 'paused' => $paused, 'mailed' => $mailed];
}

/**
 * Pause questions above max (newest first). Never deletes.
 * No-op when the questions table is not present yet.
 */
function ai_scan_pause_excess_questions(PDO $pdo, int $userId, int $maxQuestions): int
{
    if ($maxQuestions < 0) {
        $maxQuestions = 0;
    }

    $exists = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'questions' LIMIT 1"
    )->fetchColumn();
    if ($exists === false) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM questions
         WHERE user_id = :u AND COALESCE(status, 'active') = 'active'
         ORDER BY id DESC"
    );
    $stmt->execute(['u' => $userId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($ids) || $ids === []) {
        return 0;
    }

    $activeCount = count($ids);
    if ($activeCount <= $maxQuestions) {
        return 0;
    }

    $toPause = array_slice($ids, 0, $activeCount - $maxQuestions);
    $upd = $pdo->prepare("UPDATE questions SET status = 'paused' WHERE id = :id AND user_id = :u");
    $paused = 0;
    foreach ($toPause as $id) {
        $upd->execute(['id' => (int) $id, 'u' => $userId]);
        $paused += $upd->rowCount() > 0 ? 1 : 0;
    }

    return $paused;
}

function ai_scan_subscription_event(
    PDO $pdo,
    int $userId,
    string $event,
    ?string $fromTier,
    ?string $toTier,
    string $byRole,
    ?string $note,
    ?int $now = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO subscription_events(user_id, ts, event, from_tier, to_tier, by_role, note)
         VALUES(:user_id, :ts, :event, :from_tier, :to_tier, :by_role, :note)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'ts' => $now ?? time(),
        'event' => $event,
        'from_tier' => $fromTier,
        'to_tier' => $toTier,
        'by_role' => $byRole,
        'note' => $note,
    ]);
}

/**
 * @param array<string, mixed> $user
 */
function ai_scan_account_display_name(PDO $pdo, array $user): string
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId > 0) {
        $stmt = $pdo->prepare(
            'SELECT domain FROM scans WHERE user_id = :id AND domain IS NOT NULL AND domain != \'\'
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $domain = $stmt->fetchColumn();
        if (is_string($domain) && $domain !== '') {
            return $domain;
        }
    }

    $email = (string) ($user['email'] ?? '');
    if ($email !== '' && str_contains($email, '@')) {
        $local = explode('@', $email, 2)[0];
        if ($local !== '') {
            return $local;
        }
    }

    return 'daar';
}

function ai_scan_normalize_request_kind(string $kind): string
{
    $kind = strtolower(trim($kind));
    $map = [
        'account' => 'account',
        'upgrade' => 'upgrade',
        'downgrade' => 'downgrade',
        'cancel' => 'cancel',
        'opzeggen' => 'cancel',
        'opzegging' => 'cancel',
    ];

    return $map[$kind] ?? 'account';
}

/**
 * Limits payload for the runner.
 *
 * @param array<string, mixed> $user
 * @return array{tier_id: string, questions: int, actions_remaining: int, weekly_measurement: bool}
 */
function ai_scan_limits_for_user(PDO $pdo, array $user, ?array $tiers = null): array
{
    $tiers ??= ai_scan_load_tiers();
    $effective = ai_scan_effective_tier($user, $tiers);

    return [
        'tier_id' => (string) $effective['id'],
        'questions' => (int) $effective['questions'],
        'actions_remaining' => ai_scan_actions_remaining($pdo, $user, $tiers),
        'weekly_measurement' => (bool) $effective['weekly_measurement'],
    ];
}

/**
 * Question-limit error text (n from effective tier, never hardcoded).
 */
function ai_scan_questions_limit_message(int $n): string
{
    return 'Je abonnement heeft ruimte voor ' . $n . ' vragen. Kies een groter abonnement of haal een vraag weg.';
}

/**
 * @param array<string, mixed> $config
 */
function ai_scan_user_is_admin(array $config, array $user): bool
{
    $admins = $config['admin_emails'] ?? [];
    if (!is_array($admins) || $admins === []) {
        return false;
    }
    $email = strtolower(trim((string) ($user['email'] ?? '')));
    if ($email === '') {
        return false;
    }
    foreach ($admins as $admin) {
        if (is_string($admin) && strtolower(trim($admin)) === $email) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>
 */
function ai_scan_require_admin_user(PDO $pdo, array $config): array
{
    $userId = ai_scan_require_user($config, $pdo);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if ($user === false || !ai_scan_user_is_admin($config, $user)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden';
        exit;
    }

    return $user;
}
