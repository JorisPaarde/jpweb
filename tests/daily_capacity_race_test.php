<?php

declare(strict_types=1);

/**
 * Statement class that runs a hook right after the daily-capacity count has been read,
 * so a second connection can act in exactly the window between "count" and "reserve".
 */
class CapacityRaceStatement extends PDOStatement
{
    public static ?Closure $afterCount = null;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $ok = parent::execute($params);
        if (self::$afterCount !== null && str_contains($this->queryString, 'COUNT(*) AS c FROM scans')) {
            $hook = self::$afterCount;
            self::$afterCount = null;
            $hook();
        }

        return $ok;
    }
}

/**
 * @return array{0: array<string, mixed>, 1: PDO, 2: PDO, 3: int}
 */
function capacity_race_setup(int $unconfirmed): array
{
    [$config, $a, $sqlite] = boot_test_db();
    $config['daily_free_scans'] = 1;
    $config['form_burst_seconds'] = 0;
    $now = time();
    for ($i = 1; $i <= $unconfirmed; $i++) {
        $r = ai_scan_submit($a, $config, ['email' => "race$i@firma$i.nl", 'website' => "https://firma$i.nl", 'consent' => '1'], "7.7.7.$i", $now);
        assert_same('check_mail', $r['code']);
        $a->prepare('UPDATE scans SET confirm_token_hash = :h WHERE email = :e')
            ->execute(['h' => ai_scan_hash_token("rt$i"), 'e' => "race$i@firma$i.nl"]);
    }

    // Second, independent connection that does not wait for locks.
    $b = ai_scan_db($config);
    $b->setAttribute(PDO::ATTR_TIMEOUT, 0);

    return [$config, $a, $b, $now];
}

function capacity_count(PDO $pdo, string $status): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE status = '" . $status . "'")->fetchColumn();
}

test('confirm: two connections cannot both take the last daily slot', static function (): void {
    [$config, $a, $b, $now] = capacity_race_setup(2);
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CapacityRaceStatement::class]);

    // While A has counted free capacity, B confirms the other scan.
    CapacityRaceStatement::$afterCount = static function () use ($config, $b, $now): void {
        try {
            ai_scan_confirm($b, $config, 'rt2', $now);
        } catch (Throwable $e) {
            // Blocked by A's write lock: acceptable, B retries below.
        }
    };
    assert_same('confirmed', ai_scan_confirm($a, $config, 'rt1', $now)['code']);
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['PDOStatement']);

    // B (re)tries after A has finished; an unfinished confirm leaves the scan retryable.
    ai_scan_confirm($b, $config, 'rt2', $now);

    assert_same(1, capacity_count($a, 'bevestigd'));
    assert_same(1, capacity_count($a, 'wacht_dag'));
    assert_same(1, ai_scan_count_free_scans_today($a, $now));
});

test('waitlist promotion: two connections cannot both promote into one free slot', static function (): void {
    [$config, $a, $b, $now] = capacity_race_setup(2);
    $a->exec("UPDATE scans SET status = 'wacht_dag', confirmed_at = $now, queued_at = $now");
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CapacityRaceStatement::class]);

    CapacityRaceStatement::$afterCount = static function () use ($config, $b, $now): void {
        try {
            ai_scan_promote_waitlist($b, $config, $now);
        } catch (Throwable $e) {
            // Blocked by A's write lock: acceptable.
        }
    };
    ai_scan_promote_waitlist($a, $config, $now);
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['PDOStatement']);
    ai_scan_promote_waitlist($b, $config, $now);

    assert_same(1, capacity_count($a, 'bevestigd'));
    assert_same(1, capacity_count($a, 'wacht_dag'));
});

test('confirm and promotion share one capacity: no over-booking across connections', static function (): void {
    [$config, $a, $b, $now] = capacity_race_setup(2);
    $a->exec("UPDATE scans SET status = 'wacht_dag', confirmed_at = $now, queued_at = $now WHERE email LIKE 'race1@%'");
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CapacityRaceStatement::class]);

    CapacityRaceStatement::$afterCount = static function () use ($config, $b, $now): void {
        try {
            ai_scan_confirm($b, $config, 'rt2', $now);
        } catch (Throwable $e) {
        }
    };
    ai_scan_promote_waitlist($a, $config, $now);
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['PDOStatement']);
    ai_scan_confirm($b, $config, 'rt2', $now);

    assert_same(1, ai_scan_count_free_scans_today($a, $now));
    assert_same(1, capacity_count($a, 'bevestigd'));
    assert_same(1, capacity_count($a, 'wacht_dag'));
});

/**
 * Statement class that runs a hook right after the first free_scan_used lookup,
 * i.e. after the check outside the transaction and before the transaction starts.
 */
class FreeScanRaceStatement extends PDOStatement
{
    public static ?Closure $afterLookup = null;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $ok = parent::execute($params);
        if (self::$afterLookup !== null && str_contains($this->queryString, 'SELECT free_scan_used FROM users')) {
            $hook = self::$afterLookup;
            self::$afterLookup = null;
            $hook();
        }

        return $ok;
    }
}

test('submit: free scan used by a parallel request is re-checked inside the transaction', static function (): void {
    [$config, $a, $sqlite] = boot_test_db();
    $config['form_burst_seconds'] = 0;
    $now = time();
    $email = 'parallel@firma.nl';
    $b = ai_scan_db($config);
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FreeScanRaceStatement::class]);

    // After A's first check passed, B marks the free scan of this e-mail address as used.
    FreeScanRaceStatement::$afterLookup = static function () use ($b, $email, $now): void {
        $b->prepare('INSERT INTO users (email, email_hash, password_hash, created_at, free_scan_used) VALUES (:e, :h, NULL, :t, 1)')
            ->execute(['e' => $email, 'h' => ai_scan_email_hash($email), 't' => $now]);
    };
    $r = ai_scan_submit($a, $config, ['email' => $email, 'website' => 'https://parallel-firma.nl', 'consent' => '1'], '7.8.9.1', $now);
    $a->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['PDOStatement']);

    assert_same('already_scanned', $r['code']);
    assert_same(0, (int) $a->query('SELECT COUNT(*) FROM scans')->fetchColumn());
});
