<?php

declare(strict_types=1);

/**
 * Per-account questions + related app settings tables.
 * Idempotent migration; do not put these in lib/db.php (Brief 02).
 */

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
}

/**
 * Count active (non-paused) questions for a user.
 */
function ai_scan_count_active_questions(PDO $pdo, int $userId): int
{
    ai_scan_ensure_questions_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM questions
         WHERE user_id = :u AND COALESCE(status, 'active') = 'active'"
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

/**
 * Normalize question text for duplicate detection (lowercase, strip spaces/punct).
 */
function ai_scan_normalize_question_text(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[\s\p{P}]+/u', '', $text) ?? '';

    return $text;
}
