<?php

declare(strict_types=1);

/**
 * Per-account questions + related app settings tables and write operations.
 * Idempotent migration; do not put these in lib/db.php (Brief 02).
 */

const AI_SCAN_QUESTION_TOO_SHORT = 'Maak de vraag iets langer (minstens 15 tekens), zoals een klant hem zou typen.';
const AI_SCAN_QUESTION_DUPLICATE = 'Deze vraag staat er al.';
const AI_SCAN_QUESTION_OWN_NAME = 'Noem je eigen bedrijfsnaam niet in de vraag; dan zegt het antwoord niets.';
const AI_SCAN_QUESTION_EDIT_LIMIT_MSG = 'Je hebt deze maand al ' . AI_SCAN_QUESTION_EDITS_PER_MONTH
    . ' vragen gewijzigd. Zo blijft je trend bruikbaar. Volgende maand kan het weer, of vraag het mij.';
const AI_SCAN_QUESTION_MIN_MSG = 'Houd minimaal ' . AI_SCAN_QUESTION_MIN_ACTIVE . ' vaste vragen.';

const AI_SCAN_COMPETITOR_DUPLICATE = 'Dit bedrijf staat al op je lijst.';
const AI_SCAN_COMPETITOR_OWN = 'Dit is je eigen bedrijf; dat tel ik apart.';
const AI_SCAN_COMPETITOR_PLACE = 'Dit lijkt een plaats, geen bedrijf.';
const AI_SCAN_COMPETITOR_DOMAIN = 'Vul alleen de domeinnaam in, bijvoorbeeld voorbeeld.nl.';
const AI_SCAN_COMPETITOR_LIMIT = 'Je vaste lijst mag maximaal ' . AI_SCAN_COMPETITOR_MAX . ' concurrenten hebben.';

const AI_SCAN_SETTINGS_SAVE_LIMIT_MSG = 'Je hebt vandaag vaak opgeslagen. Probeer het morgen weer.';

const AI_SCAN_PASSWORD_TOO_SHORT = 'Kies een wachtwoord van minimaal ' . AI_SCAN_PASSWORD_MIN_LENGTH . ' tekens.';
const AI_SCAN_PASSWORD_CURRENT_WRONG = 'Het huidige wachtwoord klopt niet.';
const AI_SCAN_PASSWORD_THROTTLED = 'Je hebt je wachtwoord net gewijzigd. Probeer het later opnieuw.';
const AI_SCAN_PASSWORD_FAIL_THROTTLED = 'Te veel pogingen met een verkeerd wachtwoord. Probeer het over een kwartier opnieuw.';
const AI_SCAN_EMAIL_THROTTLED = 'Je hebt net een e-mailwijziging aangevraagd. Probeer het later opnieuw.';
const AI_SCAN_EMAIL_INVALID = 'Controleer het nieuwe e-mailadres en probeer het opnieuw.';
const AI_SCAN_EMAIL_SAME = 'Dat is al je huidige e-mailadres.';
const AI_SCAN_EMAIL_PENDING_SENT = 'Ik heb een bevestigingsmail gestuurd naar het nieuwe adres. Tot die bevestigd is, blijft je oude e-mailadres actief.';

/**
 * Stable rate-limit key for per-user account throttles.
 */
function ai_scan_account_user_rate_key(int $userId): string
{
    return hash('sha256', 'uid:' . $userId);
}

function ai_scan_password_fail_throttled(PDO $pdo, int $userId, ?int $now = null): bool
{
    $now ??= time();

    return ai_scan_rate_count(
        $pdo,
        'account_password_fail',
        ai_scan_account_user_rate_key($userId),
        $now - AI_SCAN_PASSWORD_FAIL_WINDOW
    ) >= AI_SCAN_PASSWORD_FAIL_MAX;
}

function ai_scan_password_fail_record(PDO $pdo, int $userId, ?int $now = null): void
{
    ai_scan_rate_hit(
        $pdo,
        'account_password_fail',
        ai_scan_account_user_rate_key($userId),
        $now ?? time()
    );
}

function ai_scan_ensure_questions_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            text TEXT NOT NULL,
            place TEXT,
            status TEXT NOT NULL DEFAULT \'active\',
            kind TEXT NOT NULL DEFAULT \'core\',
            sort_order INTEGER NOT NULL DEFAULT 0,
            mentioned INTEGER,
            answers INTEGER,
            draft INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_questions_user ON questions(user_id, status, sort_order)');

    ai_scan_ensure_column($pdo, 'questions', 'status', "TEXT NOT NULL DEFAULT 'active'");
    ai_scan_ensure_column($pdo, 'questions', 'kind', "TEXT NOT NULL DEFAULT 'core'");
    ai_scan_ensure_column($pdo, 'questions', 'place', 'TEXT');
    ai_scan_ensure_column($pdo, 'questions', 'mentioned', 'INTEGER');
    ai_scan_ensure_column($pdo, 'questions', 'answers', 'INTEGER');
    ai_scan_ensure_column($pdo, 'questions', 'draft', 'INTEGER NOT NULL DEFAULT 0');
    ai_scan_ensure_column($pdo, 'questions', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');
    ai_scan_ensure_column($pdo, 'questions', 'created_at', 'INTEGER NOT NULL DEFAULT 0');
    ai_scan_ensure_column($pdo, 'questions', 'updated_at', 'INTEGER NOT NULL DEFAULT 0');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS account_profile (
            user_id INTEGER PRIMARY KEY,
            company TEXT,
            website TEXT,
            aliases_json TEXT,
            services_json TEXT,
            places_json TEXT,
            audience TEXT,
            core_version INTEGER NOT NULL DEFAULT 1,
            competitors_version INTEGER NOT NULL DEFAULT 1,
            places_version INTEGER NOT NULL DEFAULT 1,
            alias_version INTEGER NOT NULL DEFAULT 1,
            updated_at INTEGER,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS competitors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            aliases_json TEXT,
            domains_json TEXT,
            source TEXT NOT NULL DEFAULT \'handmatig\',
            status TEXT NOT NULL DEFAULT \'active\',
            mentioned_count INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_competitors_user ON competitors(user_id, status)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS actions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            public_id TEXT NOT NULL,
            type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'voorgesteld\',
            title TEXT NOT NULL,
            page_path TEXT,
            why_text TEXT,
            page_text TEXT,
            evidence_text TEXT,
            now_text TEXT,
            becomes_text TEXT,
            placeholders_json TEXT,
            log_text TEXT,
            effect_text TEXT,
            week_key TEXT,
            archived INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_actions_public ON actions(user_id, public_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_actions_user_week ON actions(user_id, archived, sort_order)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            ts INTEGER NOT NULL,
            by_role TEXT NOT NULL,
            field TEXT NOT NULL,
            old_value TEXT,
            new_value TEXT,
            effective_week TEXT,
            versions_json TEXT,
            undone INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_settings_log_user ON settings_log(user_id, ts)');

    // Pending email change (old stays active until confirmed).
    ai_scan_ensure_column($pdo, 'users', 'pending_email', 'TEXT NULL');
    ai_scan_ensure_column($pdo, 'users', 'pending_email_token_hash', 'TEXT NULL');
    ai_scan_ensure_column($pdo, 'users', 'pending_email_expires_at', 'INTEGER NULL');
    ai_scan_ensure_column($pdo, 'users', 'password_version', 'INTEGER NOT NULL DEFAULT 1');
}

function ai_scan_count_active_questions(PDO $pdo, int $userId): int
{
    ai_scan_ensure_questions_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM questions
         WHERE user_id = :u AND COALESCE(status, 'active') = 'active' AND kind = 'core'"
    );
    $stmt->execute(['u' => $userId]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return list<array<string, mixed>>
 */
function ai_scan_list_questions(PDO $pdo, int $userId, ?string $status = null): array
{
    ai_scan_ensure_questions_schema($pdo);
    if ($status === null) {
        $stmt = $pdo->prepare(
            'SELECT * FROM questions WHERE user_id = :u ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['u' => $userId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM questions WHERE user_id = :u AND status = :s ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['u' => $userId, 's' => $status]);
    }

    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function ai_scan_normalize_question_text(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[\s\p{P}]+/u', '', $text) ?? '';

    return $text;
}

function ai_scan_normalize_competitor_key(string $text): string
{
    return ai_scan_normalize_question_text($text);
}

function ai_scan_usage_question_edits(PDO $pdo, int $userId, ?int $now = null): int
{
    $month = ai_scan_usage_month($now);
    $stmt = $pdo->prepare(
        'SELECT question_edits FROM usage_counters WHERE user_id = :u AND month = :m LIMIT 1'
    );
    $stmt->execute(['u' => $userId, 'm' => $month]);
    $row = $stmt->fetch();

    return $row === false ? 0 : (int) $row['question_edits'];
}

function ai_scan_usage_bump_question_edits(PDO $pdo, int $userId, int $by = 1, ?int $now = null): void
{
    $month = ai_scan_usage_month($now);
    $stmt = $pdo->prepare(
        'INSERT INTO usage_counters(user_id, month, actions_used, question_edits)
         VALUES(:u, :m, 0, :q)
         ON CONFLICT(user_id, month) DO UPDATE SET
           question_edits = question_edits + excluded.question_edits'
    );
    $stmt->execute(['u' => $userId, 'm' => $month, 'q' => max(0, $by)]);
}

function ai_scan_settings_saves_today(PDO $pdo, int $userId, ?int $now = null): int
{
    $now ??= time();
    $tz = new DateTimeZone('Europe/Amsterdam');
    $start = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0, 0)->getTimestamp();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM settings_log WHERE user_id = :u AND ts >= :start'
    );
    $stmt->execute(['u' => $userId, 'start' => $start]);

    return (int) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $profile
 * @param array<string, mixed> $result
 * @return array{ok: bool, error?: string, id?: int, message?: string}
 */
function ai_scan_question_add(
    PDO $pdo,
    array $user,
    array $profile,
    array $result,
    string $text,
    string $place = '',
    ?array $tiers = null,
    ?int $now = null
): array {
    $now ??= time();
    $tiers ??= ai_scan_load_tiers();
    if (!ai_scan_can_write($user, $tiers)) {
        return ['ok' => false, 'error' => AI_SCAN_FREE_WRITE_MESSAGE];
    }

    $userId = (int) $user['id'];
    ai_scan_ensure_questions_from_result($pdo, $userId, $result, $profile);

    $check = ai_scan_validate_question_text($pdo, $userId, $text, $profile, null);
    if ($check !== null) {
        return ['ok' => false, 'error' => $check];
    }

    if (ai_scan_usage_question_edits($pdo, $userId, $now) >= AI_SCAN_QUESTION_EDITS_PER_MONTH) {
        return ['ok' => false, 'error' => AI_SCAN_QUESTION_EDIT_LIMIT_MSG];
    }

    $effective = ai_scan_effective_tier($user, $tiers);
    $limit = (int) $effective['questions'];
    if (ai_scan_count_active_questions($pdo, $userId) >= $limit) {
        return ['ok' => false, 'error' => ai_scan_questions_limit_message($limit)];
    }

    $place = trim($place);
    if ($place !== '' && mb_strlen($place) > 80) {
        $place = mb_substr($place, 0, 80);
    }

    $sortStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) FROM questions WHERE user_id = :u'
    );
    $sortStmt->execute(['u' => $userId]);
    $sort = (int) $sortStmt->fetchColumn() + 1;

    $stmt = $pdo->prepare(
        'INSERT INTO questions(user_id, text, place, status, kind, sort_order, draft, created_at, updated_at)
         VALUES(:u, :t, :p, \'active\', \'core\', :s, 1, :c, :c)'
    );
    $stmt->execute([
        'u' => $userId,
        't' => trim($text),
        'p' => $place !== '' ? $place : null,
        's' => $sort,
        'c' => $now,
    ]);
    $id = (int) $pdo->lastInsertId();

    ai_scan_bump_core_version($pdo, $userId, $now);
    ai_scan_usage_bump_question_edits($pdo, $userId, 1, $now);
    ai_scan_log_setting($pdo, $userId, 'Eigenaar', 'Vraag toegevoegd', null, trim($text), 'volgende meting', $now);

    return ['ok' => true, 'id' => $id, 'message' => 'Opgeslagen. Gaat in bij de meting van maandag ' . app_format_nl_date_helper($now) . '.'];
}

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $profile
 * @param array<string, mixed> $result
 * @return array{ok: bool, error?: string, message?: string}
 */
function ai_scan_question_edit(
    PDO $pdo,
    array $user,
    array $profile,
    array $result,
    int $questionId,
    string $text,
    string $place = '',
    ?array $tiers = null,
    ?int $now = null
): array {
    $now ??= time();
    $tiers ??= ai_scan_load_tiers();
    if (!ai_scan_can_write($user, $tiers)) {
        return ['ok' => false, 'error' => AI_SCAN_FREE_WRITE_MESSAGE];
    }

    $userId = (int) $user['id'];
    ai_scan_ensure_questions_from_result($pdo, $userId, $result, $profile);

    $row = ai_scan_question_owned($pdo, $userId, $questionId);
    if ($row === null || (string) ($row['kind'] ?? '') === 'rotating') {
        return ['ok' => false, 'error' => 'Vraag niet gevonden.'];
    }

    $check = ai_scan_validate_question_text($pdo, $userId, $text, $profile, $questionId);
    if ($check !== null) {
        return ['ok' => false, 'error' => $check];
    }

    if (ai_scan_usage_question_edits($pdo, $userId, $now) >= AI_SCAN_QUESTION_EDITS_PER_MONTH) {
        return ['ok' => false, 'error' => AI_SCAN_QUESTION_EDIT_LIMIT_MSG];
    }

    $old = (string) $row['text'];
    $place = trim($place);
    $upd = $pdo->prepare(
        'UPDATE questions SET text = :t, place = :p, draft = 1, updated_at = :u WHERE id = :id AND user_id = :uid'
    );
    $upd->execute([
        't' => trim($text),
        'p' => $place !== '' ? $place : null,
        'u' => $now,
        'id' => $questionId,
        'uid' => $userId,
    ]);

    ai_scan_bump_core_version($pdo, $userId, $now);
    ai_scan_usage_bump_question_edits($pdo, $userId, 1, $now);
    ai_scan_log_setting($pdo, $userId, 'Eigenaar', 'Vraag q' . $questionId . ' gewijzigd', $old, trim($text), 'volgende meting', $now);

    return ['ok' => true, 'message' => 'Opgeslagen. Gaat in bij de meting van maandag ' . app_format_nl_date_helper($now) . '.'];
}

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $profile
 * @param array<string, mixed> $result
 * @return array{ok: bool, error?: string, message?: string}
 */
function ai_scan_question_delete(
    PDO $pdo,
    array $user,
    array $profile,
    array $result,
    int $questionId,
    ?array $tiers = null,
    ?int $now = null
): array {
    $now ??= time();
    $tiers ??= ai_scan_load_tiers();
    if (!ai_scan_can_write($user, $tiers)) {
        return ['ok' => false, 'error' => AI_SCAN_FREE_WRITE_MESSAGE];
    }

    $userId = (int) $user['id'];
    ai_scan_ensure_questions_from_result($pdo, $userId, $result, $profile);

    $row = ai_scan_question_owned($pdo, $userId, $questionId);
    if ($row === null || (string) ($row['kind'] ?? '') === 'rotating') {
        return ['ok' => false, 'error' => 'Vraag niet gevonden.'];
    }

    if (ai_scan_count_active_questions($pdo, $userId) <= AI_SCAN_QUESTION_MIN_ACTIVE) {
        return ['ok' => false, 'error' => AI_SCAN_QUESTION_MIN_MSG];
    }

    if (ai_scan_usage_question_edits($pdo, $userId, $now) >= AI_SCAN_QUESTION_EDITS_PER_MONTH) {
        return ['ok' => false, 'error' => AI_SCAN_QUESTION_EDIT_LIMIT_MSG];
    }

    $old = (string) $row['text'];
    $pdo->prepare(
        "UPDATE questions SET status = 'deleted', updated_at = :u WHERE id = :id AND user_id = :uid"
    )->execute(['u' => $now, 'id' => $questionId, 'uid' => $userId]);

    ai_scan_bump_core_version($pdo, $userId, $now);
    ai_scan_usage_bump_question_edits($pdo, $userId, 1, $now);
    ai_scan_log_setting($pdo, $userId, 'Eigenaar', 'Vraag q' . $questionId . ' verwijderd', $old, null, 'volgende meting', $now);

    return ['ok' => true, 'message' => 'Opgeslagen. Gaat in bij de meting van maandag ' . app_format_nl_date_helper($now) . '.'];
}

/**
 * @param array<string, mixed> $profile
 */
function ai_scan_validate_question_text(
    PDO $pdo,
    int $userId,
    string $text,
    array $profile,
    ?int $exceptId
): ?string {
    $text = trim($text);
    $len = mb_strlen($text);
    if ($len < 15) {
        return AI_SCAN_QUESTION_TOO_SHORT;
    }
    if ($len > 200) {
        return 'Maak de vraag iets korter (maximaal 200 tekens).';
    }

    $norm = ai_scan_normalize_question_text($text);
    $rows = ai_scan_list_questions($pdo, $userId, 'active');
    foreach ($rows as $row) {
        if ($exceptId !== null && (int) $row['id'] === $exceptId) {
            continue;
        }
        if ((string) ($row['kind'] ?? 'core') !== 'core') {
            continue;
        }
        if (ai_scan_normalize_question_text((string) $row['text']) === $norm) {
            return AI_SCAN_QUESTION_DUPLICATE;
        }
    }

    $names = [];
    if (!empty($profile['company'])) {
        $names[] = (string) $profile['company'];
    }
    if (!empty($profile['aliases']) && is_array($profile['aliases'])) {
        foreach ($profile['aliases'] as $a) {
            $names[] = (string) $a;
        }
    }
    $hay = mb_strtolower($text, 'UTF-8');
    foreach ($names as $name) {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) < 2) {
            continue;
        }
        if (str_contains($hay, mb_strtolower($name, 'UTF-8'))) {
            return AI_SCAN_QUESTION_OWN_NAME;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function ai_scan_question_owned(PDO $pdo, int $userId, int $questionId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM questions WHERE id = :id AND user_id = :u AND COALESCE(status, 'active') = 'active' LIMIT 1"
    );
    $stmt->execute(['id' => $questionId, 'u' => $userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * @param array<string, mixed> $result
 * @param array<string, mixed> $profile
 */
function ai_scan_ensure_questions_from_result(PDO $pdo, int $userId, array $result, array $profile): void
{
    ai_scan_ensure_questions_schema($pdo);
    $existing = ai_scan_list_questions($pdo, $userId);
    if ($existing !== []) {
        return;
    }

    $fromCore = $result['questions']['core'] ?? ($result['core_questions'] ?? ($result['questions'] ?? []));
    if (!is_array($fromCore)) {
        return;
    }
    $now = time();
    $n = 0;
    $ins = $pdo->prepare(
        'INSERT INTO questions(user_id, text, place, status, kind, sort_order, mentioned, answers, draft, created_at, updated_at)
         VALUES(:u, :t, :p, \'active\', \'core\', :s, :m, :a, 0, :c, :c)'
    );
    foreach ($fromCore as $q) {
        if (is_string($q)) {
            $text = $q;
            $mentioned = null;
            $answers = null;
            $place = null;
        } elseif (is_array($q)) {
            $text = (string) ($q['text'] ?? $q['question'] ?? '');
            $mentioned = isset($q['mentioned']) ? (int) $q['mentioned'] : (isset($q['k']) ? (int) $q['k'] : null);
            $answers = isset($q['answers']) ? (int) $q['answers'] : (isset($q['m']) ? (int) $q['m'] : null);
            $place = isset($q['place']) ? (string) $q['place'] : null;
        } else {
            continue;
        }
        if (trim($text) === '') {
            continue;
        }
        $n++;
        $ins->execute([
            'u' => $userId,
            't' => trim($text),
            'p' => $place,
            's' => $n,
            'm' => $mentioned,
            'a' => $answers,
            'c' => $now,
        ]);
    }
}

function ai_scan_bump_core_version(PDO $pdo, int $userId, int $now): void
{
    $pdo->prepare(
        'INSERT INTO account_profile(user_id, core_version, updated_at)
         VALUES(:u, 2, :ts)
         ON CONFLICT(user_id) DO UPDATE SET
           core_version = COALESCE(account_profile.core_version, 1) + 1,
           updated_at = excluded.updated_at'
    )->execute(['u' => $userId, 'ts' => $now]);
}

function ai_scan_bump_competitors_version(PDO $pdo, int $userId, int $now): void
{
    $pdo->prepare(
        'INSERT INTO account_profile(user_id, competitors_version, updated_at)
         VALUES(:u, 2, :ts)
         ON CONFLICT(user_id) DO UPDATE SET
           competitors_version = COALESCE(account_profile.competitors_version, 1) + 1,
           updated_at = excluded.updated_at'
    )->execute(['u' => $userId, 'ts' => $now]);
}

function ai_scan_log_setting(
    PDO $pdo,
    int $userId,
    string $byRole,
    string $field,
    ?string $old,
    ?string $new,
    ?string $week,
    int $now
): void {
    $pdo->prepare(
        'INSERT INTO settings_log(user_id, ts, by_role, field, old_value, new_value, effective_week, versions_json)
         VALUES(:u, :ts, :role, :field, :old, :new, :week, NULL)'
    )->execute([
        'u' => $userId,
        'ts' => $now,
        'role' => $byRole,
        'field' => $field,
        'old' => $old,
        'new' => $new,
        'week' => $week,
    ]);
}

/** Helper without depending on app/_init.php date formatter name clash. */
function app_format_nl_date_helper(int $ts): string
{
    if (function_exists('app_next_monday_label')) {
        return app_next_monday_label($ts);
    }
    $tz = new DateTimeZone('Europe/Amsterdam');
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    if ((int) $dt->format('N') === 1 && (int) $dt->format('G') < 9) {
        // fall through
    } else {
        $dt = $dt->modify('next monday');
    }
    $months = [
        1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
        5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
        9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
    ];
    $m = (int) $dt->format('n');

    return $dt->format('j') . ' ' . ($months[$m] ?? '') . ' ' . $dt->format('Y');
}

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $profile
 * @param array<string, mixed> $result
 * @param list<string> $aliases
 * @param list<string> $domains
 * @return array{ok: bool, error?: string, id?: int, message?: string}
 */
function ai_scan_competitor_add(
    PDO $pdo,
    array $user,
    array $profile,
    array $result,
    string $name,
    array $aliases = [],
    array $domains = [],
    string $source = 'handmatig',
    ?array $tiers = null,
    ?int $now = null
): array {
    $now ??= time();
    $tiers ??= ai_scan_load_tiers();
    if (!ai_scan_can_edit_setup($user)) {
        return ['ok' => false, 'error' => AI_SCAN_FREE_WRITE_MESSAGE];
    }

    $userId = (int) $user['id'];
    if (ai_scan_settings_saves_today($pdo, $userId, $now) >= AI_SCAN_SETTINGS_SAVES_PER_DAY) {
        return ['ok' => false, 'error' => AI_SCAN_SETTINGS_SAVE_LIMIT_MSG];
    }

    ai_scan_ensure_competitors_from_result($pdo, $userId, $result);

    $name = trim($name);
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        return ['ok' => false, 'error' => 'De naam moet tussen 2 en 80 tekens zijn.'];
    }

    $aliases = array_values(array_filter(array_map('trim', array_slice($aliases, 0, 5))));
    $domains = array_values(array_filter(array_map('trim', array_slice($domains, 0, 3))));
    foreach ($aliases as $a) {
        if (mb_strlen($a) < 2 || mb_strlen($a) > 80) {
            return ['ok' => false, 'error' => 'Elke alias moet tussen 2 en 80 tekens zijn.'];
        }
    }
    foreach ($domains as $d) {
        $err = ai_scan_validate_competitor_domain($d);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err];
        }
    }

    $ownCheck = ai_scan_competitor_is_own($name, $aliases, $domains, $profile);
    if ($ownCheck) {
        return ['ok' => false, 'error' => AI_SCAN_COMPETITOR_OWN];
    }

    $places = $profile['places'] ?? [];
    if (is_array($places)) {
        foreach ($places as $place) {
            if (ai_scan_normalize_competitor_key((string) $place) === ai_scan_normalize_competitor_key($name)) {
                return ['ok' => false, 'error' => AI_SCAN_COMPETITOR_PLACE];
            }
        }
    }

    if (ai_scan_competitor_duplicate($pdo, $userId, $name, $aliases, $domains, null)) {
        return ['ok' => false, 'error' => AI_SCAN_COMPETITOR_DUPLICATE];
    }

    $active = ai_scan_count_active_competitors($pdo, $userId);
    if ($active >= AI_SCAN_COMPETITOR_MAX) {
        return ['ok' => false, 'error' => AI_SCAN_COMPETITOR_LIMIT];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO competitors(user_id, name, aliases_json, domains_json, source, status, created_at, updated_at)
         VALUES(:u, :n, :a, :d, :s, \'active\', :c, :c)'
    );
    $stmt->execute([
        'u' => $userId,
        'n' => $name,
        'a' => json_encode($aliases, JSON_UNESCAPED_UNICODE),
        'd' => json_encode($domains, JSON_UNESCAPED_UNICODE),
        's' => $source !== '' ? $source : 'handmatig',
        'c' => $now,
    ]);
    $id = (int) $pdo->lastInsertId();

    ai_scan_bump_competitors_version($pdo, $userId, $now);
    ai_scan_log_setting($pdo, $userId, 'Eigenaar', 'Concurrent toegevoegd', null, $name, 'volgende meting', $now);

    return ['ok' => true, 'id' => $id, 'message' => 'Opgeslagen. Gaat in bij de meting van maandag ' . app_format_nl_date_helper($now) . '.'];
}

/**
 * Soft-delete → excluded.
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed> $result
 * @return array{ok: bool, error?: string, message?: string}
 */
function ai_scan_competitor_delete(
    PDO $pdo,
    array $user,
    array $result,
    int $competitorId,
    ?array $tiers = null,
    ?int $now = null
): array {
    $now ??= time();
    $tiers ??= ai_scan_load_tiers();
    if (!ai_scan_can_edit_setup($user)) {
        return ['ok' => false, 'error' => AI_SCAN_FREE_WRITE_MESSAGE];
    }

    $userId = (int) $user['id'];
    if (ai_scan_settings_saves_today($pdo, $userId, $now) >= AI_SCAN_SETTINGS_SAVES_PER_DAY) {
        return ['ok' => false, 'error' => AI_SCAN_SETTINGS_SAVE_LIMIT_MSG];
    }

    ai_scan_ensure_competitors_from_result($pdo, $userId, $result);

    $stmt = $pdo->prepare(
        "SELECT * FROM competitors WHERE id = :id AND user_id = :u AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['id' => $competitorId, 'u' => $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return ['ok' => false, 'error' => 'Concurrent niet gevonden.'];
    }

    $pdo->prepare(
        "UPDATE competitors SET status = 'excluded', updated_at = :u WHERE id = :id AND user_id = :uid"
    )->execute(['u' => $now, 'id' => $competitorId, 'uid' => $userId]);

    ai_scan_bump_competitors_version($pdo, $userId, $now);
    ai_scan_log_setting(
        $pdo,
        $userId,
        'Eigenaar',
        'Concurrent ' . (string) $row['name'] . ' verwijderd',
        (string) $row['name'],
        'uitgesloten',
        'volgende meting',
        $now
    );

    return ['ok' => true, 'message' => 'Opgeslagen. Gaat in bij de meting van maandag ' . app_format_nl_date_helper($now) . '.'];
}

function ai_scan_count_active_competitors(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM competitors WHERE user_id = :u AND status = 'active'"
    );
    $stmt->execute(['u' => $userId]);

    return (int) $stmt->fetchColumn();
}

function ai_scan_validate_competitor_domain(string $domain): ?string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
    $domain = preg_replace('#^www\.#', '', $domain) ?? $domain;
    $domain = explode('/', $domain, 2)[0];
    $domain = explode('?', $domain, 2)[0];
    // Form check only (syntax), not semantic WHOIS.
    if ($domain === '' || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $domain)) {
        return AI_SCAN_COMPETITOR_DOMAIN;
    }

    return null;
}

/**
 * @param list<string> $aliases
 * @param list<string> $domains
 * @param array<string, mixed> $profile
 */
function ai_scan_competitor_is_own(string $name, array $aliases, array $domains, array $profile): bool
{
    $own = [];
    if (!empty($profile['company'])) {
        $own[] = (string) $profile['company'];
    }
    if (!empty($profile['website'])) {
        $own[] = (string) $profile['website'];
    }
    if (!empty($profile['aliases']) && is_array($profile['aliases'])) {
        foreach ($profile['aliases'] as $a) {
            $own[] = (string) $a;
        }
    }
    $candidates = array_merge([$name], $aliases, $domains);
    foreach ($candidates as $c) {
        $cn = ai_scan_normalize_competitor_key((string) $c);
        if ($cn === '') {
            continue;
        }
        foreach ($own as $o) {
            if ($cn === ai_scan_normalize_competitor_key((string) $o)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param list<string> $aliases
 * @param list<string> $domains
 */
function ai_scan_competitor_duplicate(
    PDO $pdo,
    int $userId,
    string $name,
    array $aliases,
    array $domains,
    ?int $exceptId
): bool {
    $stmt = $pdo->prepare(
        "SELECT * FROM competitors WHERE user_id = :u AND status IN ('active', 'excluded')"
    );
    $stmt->execute(['u' => $userId]);
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return false;
    }

    $keys = [ai_scan_normalize_competitor_key($name)];
    foreach ($aliases as $a) {
        $keys[] = ai_scan_normalize_competitor_key((string) $a);
    }
    foreach ($domains as $d) {
        $keys[] = ai_scan_normalize_competitor_key((string) $d);
    }
    $keys = array_values(array_filter($keys));

    foreach ($rows as $row) {
        if ($exceptId !== null && (int) $row['id'] === $exceptId) {
            continue;
        }
        $existing = [ai_scan_normalize_competitor_key((string) $row['name'])];
        $ea = json_decode((string) ($row['aliases_json'] ?? '[]'), true);
        $ed = json_decode((string) ($row['domains_json'] ?? '[]'), true);
        if (is_array($ea)) {
            foreach ($ea as $a) {
                $existing[] = ai_scan_normalize_competitor_key((string) $a);
            }
        }
        if (is_array($ed)) {
            foreach ($ed as $d) {
                $existing[] = ai_scan_normalize_competitor_key((string) $d);
            }
        }
        foreach ($keys as $k) {
            if (in_array($k, $existing, true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $result
 */
function ai_scan_ensure_competitors_from_result(PDO $pdo, int $userId, array $result): void
{
    ai_scan_ensure_questions_schema($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM competitors WHERE user_id = :u');
    $stmt->execute(['u' => $userId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $fromList = $result['competitors']['list'] ?? ($result['competitors'] ?? []);
    if (!is_array($fromList)) {
        return;
    }
    $now = time();
    $ins = $pdo->prepare(
        'INSERT INTO competitors(user_id, name, aliases_json, domains_json, source, status, mentioned_count, created_at, updated_at)
         VALUES(:u, :n, :a, :d, :s, \'active\', :m, :c, :c)'
    );
    foreach ($fromList as $c) {
        if (!is_array($c)) {
            continue;
        }
        $name = trim((string) ($c['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $aliases = isset($c['aliases']) && is_array($c['aliases']) ? array_values(array_map('strval', $c['aliases'])) : [];
        $domains = isset($c['domains']) && is_array($c['domains']) ? array_values(array_map('strval', $c['domains'])) : [];
        $ins->execute([
            'u' => $userId,
            'n' => $name,
            'a' => json_encode($aliases, JSON_UNESCAPED_UNICODE),
            'd' => json_encode($domains, JSON_UNESCAPED_UNICODE),
            's' => (string) ($c['source'] ?? 'AI-antwoord'),
            'm' => (int) ($c['mentioned_count'] ?? ($c['count'] ?? 0)),
            'c' => $now,
        ]);
    }
}

/**
 * Persist result actions then update status.
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed> $result
 * @return array{ok: bool, error?: string, message?: string}
 */
function ai_scan_action_set_status(
    PDO $pdo,
    array $user,
    array $result,
    string $publicId,
    string $status,
    ?array $tiers = null,
    ?int $now = null
): array {
    $now ??= time();
    $tiers ??= ai_scan_load_tiers();
    if (!ai_scan_can_write($user, $tiers)) {
        return ['ok' => false, 'error' => AI_SCAN_FREE_WRITE_MESSAGE];
    }

    $allowed = ['live_gemeld', 'afgewezen'];
    if (!in_array($status, $allowed, true)) {
        return ['ok' => false, 'error' => 'Ongeldige status.'];
    }

    $userId = (int) $user['id'];
    ai_scan_ensure_actions_persisted($pdo, $userId, $result);

    $stmt = $pdo->prepare(
        'SELECT * FROM actions WHERE user_id = :u AND public_id = :p LIMIT 1'
    );
    $stmt->execute(['u' => $userId, 'p' => $publicId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return ['ok' => false, 'error' => 'Actie niet gevonden.'];
    }

    $log = (string) ($row['log_text'] ?? '');
    $tz = new DateTimeZone('Europe/Amsterdam');
    $label = (new DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d');
    if ($status === 'live_gemeld') {
        $line = 'Live gemeld op ' . $label;
        $newLog = $log !== '' ? ($log . ' · ' . $line) : $line;
        $limit = (int) ai_scan_effective_tier($user, $tiers)['actions'];

        // BEGIN IMMEDIATE via exec so SQLite locks before the conditional UPDATE;
        // pair with exec COMMIT/ROLLBACK (PDO::commit is unaware of exec BEGIN).
        $pdo->exec('BEGIN IMMEDIATE');
        $liveTxOpen = true;
        try {
            $upd = $pdo->prepare(
                'UPDATE actions SET status = :s, log_text = :l, updated_at = :u
                 WHERE id = :id AND user_id = :uid AND status != \'live_gemeld\''
            );
            $upd->execute([
                's' => $status,
                'l' => $newLog,
                'u' => $now,
                'id' => (int) $row['id'],
                'uid' => $userId,
            ]);
            if ($upd->rowCount() !== 1) {
                $pdo->exec('COMMIT');
                $liveTxOpen = false;

                return [
                    'ok' => true,
                    'message' => 'Actie gemarkeerd als live gezet.',
                ];
            }
            if (!ai_scan_usage_try_consume_action($pdo, $userId, $limit, $now)) {
                $pdo->exec('ROLLBACK');
                $liveTxOpen = false;

                return ['ok' => false, 'error' => 'Je hebt deze maand geen acties meer over.'];
            }
            $pdo->exec('COMMIT');
            $liveTxOpen = false;
        } catch (Throwable $e) {
            if ($liveTxOpen) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable $ignored) {
                    // ignore
                }
            }
            throw $e;
        }

        return [
            'ok' => true,
            'message' => 'Actie gemarkeerd als live gezet.',
        ];
    }

    $line = 'Afgewezen op ' . $label . ' (past niet bij mijn bedrijf)';
    $log = $log !== '' ? ($log . ' · ' . $line) : $line;
    $pdo->prepare(
        'UPDATE actions SET status = :s, log_text = :l, updated_at = :u WHERE id = :id AND user_id = :uid'
    )->execute([
        's' => $status,
        'l' => $log,
        'u' => $now,
        'id' => (int) $row['id'],
        'uid' => $userId,
    ]);

    return [
        'ok' => true,
        'message' => 'Actie afgewezen.',
    ];
}

/**
 * @param array<string, mixed> $result
 */
function ai_scan_ensure_actions_persisted(PDO $pdo, int $userId, array $result): void
{
    ai_scan_ensure_questions_schema($pdo);
    $fromResult = $result['actions'] ?? null;
    if (!is_array($fromResult) || $fromResult === []) {
        return;
    }

    $now = time();
    $existing = $pdo->prepare('SELECT public_id FROM actions WHERE user_id = :u');
    $existing->execute(['u' => $userId]);
    $known = array_flip(array_map('strval', $existing->fetchAll(PDO::FETCH_COLUMN)));
    $current = [];
    $ins = $pdo->prepare(
        'INSERT INTO actions(
            user_id, public_id, type, status, title, page_path, why_text, page_text,
            evidence_text, now_text, becomes_text, placeholders_json, log_text, effect_text,
            week_key, archived, sort_order, created_at, updated_at
         ) VALUES (
            :u, :p, :type, :status, :title, :page, :why, :pt,
            :ev, :nowt, :bec, :ph, :log, :eff,
            :week, :arch, :sort, :c, :c
         )'
    );
    $i = 0;
    foreach ($fromResult as $item) {
        if (!is_array($item)) {
            continue;
        }
        $i++;
        $publicId = (string) ($item['id'] ?? ('a' . $i));
        $current[$publicId] = true;
        if (isset($known[$publicId])) {
            continue;
        }
        $known[$publicId] = true;
        $ins->execute([
            'u' => $userId,
            'p' => $publicId,
            'type' => (string) ($item['type'] ?? 'verbeteren'),
            'status' => (string) ($item['status'] ?? 'voorgesteld'),
            'title' => (string) ($item['title'] ?? 'Actie'),
            'page' => (string) ($item['page_path'] ?? ($item['page'] ?? '/')),
            'why' => (string) ($item['why'] ?? ($item['why_text'] ?? '')),
            'pt' => (string) ($item['page_text'] ?? ''),
            'ev' => (string) ($item['evidence'] ?? ($item['evidence_text'] ?? '')),
            'nowt' => (string) ($item['now'] ?? ($item['now_text'] ?? '')),
            'bec' => (string) ($item['becomes'] ?? ($item['becomes_text'] ?? '')),
            'ph' => json_encode($item['placeholders'] ?? [], JSON_UNESCAPED_UNICODE),
            'log' => (string) ($item['log'] ?? ($item['log_text'] ?? '')),
            'eff' => (string) ($item['effect'] ?? ($item['effect_text'] ?? '')),
            'week' => (string) ($item['week'] ?? 'deze'),
            'arch' => !empty($item['archived']) ? 1 : 0,
            'sort' => $i,
            'c' => $now,
        ]);
    }

    // Actions from earlier results that are not in this one are archived;
    // status and log stay untouched.
    $archive = $pdo->prepare(
        'UPDATE actions SET archived = 1, updated_at = :t WHERE user_id = :u AND public_id = :p AND archived = 0'
    );
    foreach ($known as $publicId => $_) {
        if (!isset($current[(string) $publicId])) {
            $archive->execute(['t' => $now, 'u' => $userId, 'p' => (string) $publicId]);
        }
    }
}

/**
 * Request email change: old stays active until token confirm.
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed> $config
 * @return array{ok: bool, error?: string, message?: string}
 */
function ai_scan_request_email_change(
    PDO $pdo,
    array $config,
    array $user,
    string $newEmail,
    string $currentPassword,
    ?int $now = null
): array {
    $now ??= time();
    $userId = (int) $user['id'];

    if (ai_scan_password_fail_throttled($pdo, $userId, $now)) {
        return ['ok' => false, 'error' => AI_SCAN_PASSWORD_FAIL_THROTTLED];
    }

    $hash = (string) ($user['password_hash'] ?? '');
    if ($hash === '' || !ai_scan_verify_password($currentPassword, $hash)) {
        ai_scan_password_fail_record($pdo, $userId, $now);

        return ['ok' => false, 'error' => AI_SCAN_PASSWORD_CURRENT_WRONG];
    }

    // Contactadres behoudt +alias; de genormaliseerde vorm dient alleen voor hash/uniciteit.
    $contact = strtolower(trim($newEmail));
    $normalized = ai_scan_normalize_email($contact);
    if ($normalized === '' || !ai_scan_email_syntax_ok($contact)) {
        return ['ok' => false, 'error' => AI_SCAN_EMAIL_INVALID];
    }
    $oldEmail = (string) ($user['email'] ?? '');
    if ($contact === strtolower($oldEmail)) {
        return ['ok' => false, 'error' => AI_SCAN_EMAIL_SAME];
    }

    $userKey = ai_scan_account_user_rate_key($userId);
    $cooldown = (int) ($config['account_email_change_seconds'] ?? 600);
    if ($cooldown > 0
        && ai_scan_rate_count($pdo, 'account_email_change', $userKey, $now - $cooldown) >= 1
    ) {
        return ['ok' => false, 'error' => AI_SCAN_EMAIL_THROTTLED];
    }

    // Unique check on new email.
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE email_hash = :h AND id != :id LIMIT 1');
    $exists->execute(['h' => ai_scan_email_hash($normalized), 'id' => $userId]);
    if ($exists->fetch() !== false) {
        return ['ok' => false, 'error' => AI_SCAN_EMAIL_INVALID];
    }

    $token = ai_scan_random_token(32);
    $ttl = (int) ($config['password_token_ttl'] ?? 86400);
    $pdo->prepare(
        'UPDATE users SET pending_email = :e, pending_email_token_hash = :h, pending_email_expires_at = :x
         WHERE id = :id'
    )->execute([
        'e' => $contact,
        'h' => ai_scan_hash_token($token),
        'x' => $now + $ttl,
        'id' => $userId,
    ]);

    $base = rtrim((string) ($config['base_url'] ?? 'https://jpwebcreation.nl'), '/');
    $url = $base . '/app/confirm-email.php?t=' . rawurlencode($token);
    ai_scan_send_mail(
        $config,
        $contact,
        'Bevestig je nieuwe e-mailadres',
        implode("\n", [
            'Hoi,',
            '',
            'Je vroeg om je e-mailadres te wijzigen voor je AI-vindbaarheidsaccount.',
            'Bevestig het nieuwe adres via deze link (24 uur geldig):',
            $url,
            '',
            'Tot die tijd blijft je oude e-mailadres actief. Heb jij dit niet aangevraagd? Negeer deze mail.',
            '',
            'Groet,',
            'Joris',
        ])
    );

    if ($oldEmail !== '') {
        ai_scan_send_mail(
            $config,
            $oldEmail,
            'Je e-mailadreswijziging',
            implode("\n", [
                'Hoi,',
                '',
                'Ik heb een verzoek ontvangen om het e-mailadres van je AI-vindbaarheidsaccount te wijzigen.',
                'Als jij dit was: bevestig via de mail die naar het nieuwe adres is gestuurd.',
                'Was jij dit niet? Negeer de bevestigingsmail of neem contact op.',
                '',
                'Groet,',
                'Joris',
            ])
        );
    }

    ai_scan_rate_hit($pdo, 'account_email_change', $userKey, $now);

    return ['ok' => true, 'message' => AI_SCAN_EMAIL_PENDING_SENT];
}

/**
 * @return array{ok: bool, error?: string, message?: string}
 */
function ai_scan_confirm_email_change(PDO $pdo, string $token, ?int $now = null): array
{
    $now ??= time();
    if ($token === '') {
        return ['ok' => false, 'error' => 'Ongeldige link.'];
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM users WHERE pending_email_token_hash = :h LIMIT 1'
    );
    $stmt->execute(['h' => ai_scan_hash_token($token)]);
    $user = $stmt->fetch();
    if ($user === false) {
        return ['ok' => false, 'error' => 'Ongeldige of verlopen link.'];
    }
    if ((int) ($user['pending_email_expires_at'] ?? 0) < $now) {
        return ['ok' => false, 'error' => 'Deze link is verlopen.'];
    }
    $newEmail = (string) ($user['pending_email'] ?? '');
    if ($newEmail === '') {
        return ['ok' => false, 'error' => 'Geen openstaande wijziging.'];
    }

    $userId = (int) $user['id'];
    $newHash = ai_scan_email_hash(ai_scan_normalize_email($newEmail));
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE email_hash = :h AND id != :id LIMIT 1');
    $exists->execute(['h' => $newHash, 'id' => $userId]);
    if ($exists->fetch() !== false) {
        return ['ok' => false, 'error' => 'Ongeldige of verlopen link.'];
    }

    $consume = $pdo->prepare(
        'UPDATE users SET
            email = :e,
            email_hash = :eh,
            pending_email = NULL,
            pending_email_token_hash = NULL,
            pending_email_expires_at = NULL
         WHERE id = :id AND pending_email_token_hash = :h'
    );
    $pdo->beginTransaction();
    try {
        $consume->execute([
            'e' => $newEmail,
            'eh' => $newHash,
            'id' => $userId,
            'h' => ai_scan_hash_token($token),
        ]);
        if ($consume->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Ongeldige of verlopen link.'];
        }
        $pdo->prepare(
            'UPDATE scans SET password_token_hash = NULL, password_token_expires_at = NULL WHERE user_id = :id'
        )->execute(['id' => $userId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Ongeldige of verlopen link.'];
    }

    return ['ok' => true, 'message' => 'Je e-mailadres is bijgewerkt.'];
}

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $config
 * @return array{ok: bool, error?: string, message?: string}
 */
function ai_scan_change_password(
    PDO $pdo,
    array $config,
    array $user,
    string $currentPassword,
    string $newPassword,
    ?int $now = null
): array {
    $now ??= time();
    $userId = (int) $user['id'];

    if (ai_scan_password_fail_throttled($pdo, $userId, $now)) {
        return ['ok' => false, 'error' => AI_SCAN_PASSWORD_FAIL_THROTTLED];
    }

    $hash = (string) ($user['password_hash'] ?? '');
    if ($hash === '' || !ai_scan_verify_password($currentPassword, $hash)) {
        ai_scan_password_fail_record($pdo, $userId, $now);

        return ['ok' => false, 'error' => AI_SCAN_PASSWORD_CURRENT_WRONG];
    }
    if (mb_strlen($newPassword) < AI_SCAN_PASSWORD_MIN_LENGTH) {
        return ['ok' => false, 'error' => AI_SCAN_PASSWORD_TOO_SHORT];
    }

    $userKey = ai_scan_account_user_rate_key($userId);
    $cooldown = (int) ($config['account_password_change_seconds'] ?? 300);
    if ($cooldown > 0
        && ai_scan_rate_count($pdo, 'account_password_change', $userKey, $now - $cooldown) >= 1
    ) {
        return ['ok' => false, 'error' => AI_SCAN_PASSWORD_THROTTLED];
    }

    $newVersion = (int) ($user['password_version'] ?? 1) + 1;
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE users SET password_hash = :h, password_version = :v, pending_email = NULL, pending_email_token_hash = NULL, pending_email_expires_at = NULL WHERE id = :id'
        )->execute([
            'h' => ai_scan_hash_password($newPassword),
            'v' => $newVersion,
            'id' => $userId,
        ]);
        $pdo->prepare(
            'UPDATE scans SET password_token_hash = NULL, password_token_expires_at = NULL WHERE user_id = :id'
        )->execute(['id' => $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    ai_scan_rate_hit($pdo, 'account_password_change', $userKey, $now);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['password_version'] = $newVersion;
        $_SESSION['login_at'] = $now;
    }

    return ['ok' => true, 'message' => 'Je wachtwoord is gewijzigd.'];
}
