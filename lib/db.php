<?php

declare(strict_types=1);

function ai_scan_db(array $config): PDO
{
    $path = (string) ($config['sqlite_path'] ?? '');
    if ($path === '') {
        throw new RuntimeException('sqlite_path is not configured.');
    }

    if ($path !== ':memory:') {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create SQLite directory: ' . $dir);
        }
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    return $pdo;
}

function ai_scan_migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            email_hash TEXT NOT NULL UNIQUE,
            password_hash TEXT,
            created_at INTEGER NOT NULL,
            last_login_at INTEGER,
            free_scan_used INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            user_id INTEGER,
            email TEXT NOT NULL,
            email_hash TEXT NOT NULL,
            website_url TEXT NOT NULL,
            domain TEXT NOT NULL,
            domain_hash TEXT NOT NULL,
            status TEXT NOT NULL,
            confirm_token_hash TEXT,
            confirm_expires_at INTEGER,
            confirmed_at INTEGER,
            password_token_hash TEXT,
            password_token_expires_at INTEGER,
            created_at INTEGER NOT NULL,
            queued_at INTEGER,
            claimed_at INTEGER,
            completed_at INTEGER,
            result_html TEXT,
            result_json TEXT,
            error_message TEXT,
            ip_hash TEXT,
            marketing_opt_in INTEGER NOT NULL DEFAULT 0,
            ready_mail_sent_at INTEGER,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    ai_scan_ensure_column($pdo, 'scans', 'ready_mail_sent_at', 'INTEGER');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_status ON scans(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_email_hash ON scans(email_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_domain_hash ON scans(domain_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_confirm_token ON scans(confirm_token_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_password_token ON scans(password_token_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scans_ready_mail ON scans(status, ready_mail_sent_at)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS free_scan_blocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_hash TEXT,
            domain_hash TEXT,
            created_at INTEGER NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blocks_email ON free_scan_blocks(email_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blocks_domain ON free_scan_blocks(domain_hash)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rate_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            key_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_bucket_key ON rate_events(bucket, key_hash, created_at)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS form_state (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL UNIQUE,
            payload TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );

    // Per-account subscription columns (tier is never global).
    ai_scan_ensure_column($pdo, 'users', 'tier_id', "TEXT NOT NULL DEFAULT 'free'");
    ai_scan_ensure_column($pdo, 'users', 'custom_questions', 'INTEGER NULL');
    ai_scan_ensure_column($pdo, 'users', 'subscription_status', "TEXT NOT NULL DEFAULT 'none'");
    ai_scan_ensure_column($pdo, 'users', 'billing_period', 'TEXT NULL');
    ai_scan_ensure_column($pdo, 'users', 'period_start', 'INTEGER NULL');
    ai_scan_ensure_column($pdo, 'users', 'period_end', 'INTEGER NULL');
    ai_scan_ensure_column($pdo, 'users', 'pending_tier_id', 'TEXT NULL');
    ai_scan_ensure_column($pdo, 'users', 'tier_changed_at', 'INTEGER NULL');
    ai_scan_ensure_column($pdo, 'users', 'password_version', 'INTEGER NOT NULL DEFAULT 1');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS subscription_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            ts INTEGER NOT NULL,
            event TEXT NOT NULL,
            from_tier TEXT,
            to_tier TEXT,
            by_role TEXT NOT NULL,
            note TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subscription_events_user ON subscription_events(user_id, ts)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS usage_counters (
            user_id INTEGER NOT NULL,
            month TEXT NOT NULL,
            actions_used INTEGER NOT NULL DEFAULT 0,
            question_edits INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id, month),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
}

function ai_scan_ensure_column(PDO $pdo, string $table, string $column, string $sqlType): void
{
    if (!preg_match('/^[a-z_]+$/', $table) || !preg_match('/^[a-z_]+$/', $column)) {
        throw new InvalidArgumentException('Invalid table/column name.');
    }

    $cols = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $sqlType);
}

function ai_scan_meta_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM meta WHERE key = :key LIMIT 1');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();

    return $row === false ? $default : (string) $row['value'];
}

function ai_scan_meta_set(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO meta(key, value) VALUES(:key, :value)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $stmt->execute(['key' => $key, 'value' => $value]);
}
