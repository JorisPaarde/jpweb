<?php

declare(strict_types=1);

/**
 * Google Search Console connection schema (own migration file).
 * Called from lib/gsc.php; does not edit lib/db.php.
 */

function ai_scan_gsc_migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gsc_connections (
            user_id INTEGER PRIMARY KEY,
            property_uri TEXT NOT NULL,
            refresh_token_enc TEXT NOT NULL,
            access_token_enc TEXT,
            access_token_expires_at INTEGER,
            connected_at INTEGER NOT NULL,
            last_sync_at INTEGER,
            last_error TEXT,
            google_account_email TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gsc_query_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            query TEXT NOT NULL,
            clicks INTEGER NOT NULL DEFAULT 0,
            impressions INTEGER NOT NULL DEFAULT 0,
            ctr REAL NOT NULL DEFAULT 0,
            position REAL NOT NULL DEFAULT 0,
            period_start TEXT NOT NULL,
            period_end TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT \'search_analytics\',
            fetched_at INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gsc_query_stats_user ON gsc_query_stats(user_id, fetched_at)');
    $pdo->exec(
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_gsc_query_stats_unique
         ON gsc_query_stats(user_id, query, period_start, period_end, source)'
    );
}
