<?php

declare(strict_types=1);

/**
 * Shared boot for app screens: auth, tier, scan, flash, profile.
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/questions.php';

/**
 * CLI/phpdbg-only test harness inject. Never honored on a web SAPI.
 */
function app_test_user_id_from_env(?string $sapi = null): ?int
{
    $sapi ??= PHP_SAPI;
    if ($sapi !== 'cli' && $sapi !== 'phpdbg') {
        return null;
    }
    $testUser = getenv('JPWEB_AI_SCAN_TEST_USER_ID');
    if (!is_string($testUser) || !ctype_digit($testUser)) {
        return null;
    }

    return (int) $testUser;
}

/**
 * @return array{
 *   config: array<string, mixed>,
 *   pdo: PDO,
 *   user: array<string, mixed>,
 *   tiers: array<string, mixed>,
 *   effective: array{id: string, questions: int, actions: int, monthly: int, read_only: bool, weekly_measurement: bool},
 *   can_write: bool,
 *   scan: array<string, mixed>|null,
 *   result: array<string, mixed>,
 *   csrf: string,
 *   flash: string,
 *   company: string,
 *   updated_label: string,
 *   actions_used: int,
 *   actions_limit: int,
 *   pending_request: array<string, mixed>|null,
 *   is_free: bool
 * }
 */
function app_boot(string $pageId): array
{
    [$config, $pdo] = ai_scan_boot();
    ai_scan_ensure_questions_schema($pdo);
    if (!headers_sent()) {
        ai_scan_security_headers();
    }
    ai_scan_assert_allowed_host($config);

    try {
        ai_scan_check_stale_jobs($pdo, $config);
        ai_scan_promote_waitlist($pdo, $config);
    } catch (Throwable $e) {
        // ignore
    }

    $injected = app_test_user_id_from_env();
    if ($injected !== null) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!headers_sent()) {
                ai_scan_start_session($config);
            } else {
                @session_start();
            }
        }
        $_SESSION['user_id'] = $injected;
    }
    $userId = ai_scan_require_user($config, $pdo);
    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();
    if ($user === false) {
        ai_scan_logout_user($config);
        ai_scan_redirect('/login/', 303);
    }

    $tiers = ai_scan_load_tiers();
    $effective = ai_scan_effective_tier($user, $tiers);
    $canWrite = ai_scan_can_write($user, $tiers);
    $isFree = (string) $effective['id'] === 'free' || !empty($effective['read_only']);

    $scan = ai_scan_latest_for_user($pdo, $userId);
    $result = app_decode_result($scan);

    ai_scan_start_session($config);
    $flash = '';
    if (!empty($_SESSION['ai_scan_flash']) && is_string($_SESSION['ai_scan_flash'])) {
        $flash = (string) $_SESSION['ai_scan_flash'];
        unset($_SESSION['ai_scan_flash']);
    }

    $profile = app_load_profile($pdo, $userId, $scan, $result);
    $company = (string) ($profile['company'] !== '' ? $profile['company'] : ($scan['domain'] ?? 'Je bedrijf'));
    $updatedLabel = app_updated_label($scan, $result);

    $actionsUsed = ai_scan_usage_actions_used($pdo, $userId);
    $actionsLimit = (int) $effective['actions'];
    $pending = app_pending_subscription_request($pdo, $userId);

    return [
        'config' => $config,
        'pdo' => $pdo,
        'user' => $user,
        'tiers' => $tiers,
        'effective' => $effective,
        'can_write' => $canWrite,
        'scan' => $scan,
        'result' => $result,
        'csrf' => ai_scan_csrf_token($config),
        'flash' => $flash,
        'company' => $company,
        'updated_label' => $updatedLabel,
        'actions_used' => $actionsUsed,
        'actions_limit' => $actionsLimit,
        'pending_request' => $pending,
        'is_free' => $isFree,
        'profile' => $profile,
        'page_id' => $pageId,
    ];
}

/**
 * @param array<string, mixed>|null $scan
 * @return array<string, mixed>
 */
function app_decode_result(?array $scan): array
{
    if ($scan === null || empty($scan['result_json'])) {
        return [];
    }
    $decoded = json_decode((string) $scan['result_json'], true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed>|null $scan
 * @param array<string, mixed> $result
 */
function app_updated_label(?array $scan, array $result): string
{
    $ts = null;
    if (isset($result['updated_at']) && is_numeric($result['updated_at'])) {
        $ts = (int) $result['updated_at'];
    } elseif ($scan !== null && isset($scan['completed_at']) && $scan['completed_at'] !== null) {
        $ts = (int) $scan['completed_at'];
    }
    if ($ts === null || $ts <= 0) {
        return 'Nog niet bijgewerkt';
    }

    return 'Bijgewerkt op ' . app_format_nl_date($ts);
}

function app_format_nl_date(int $ts): string
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    $months = [
        1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
        5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
        9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
    ];
    $m = (int) $dt->format('n');

    return $dt->format('j') . ' ' . ($months[$m] ?? $dt->format('F')) . ' ' . $dt->format('Y');
}

/**
 * @param array<string, mixed>|null $scan
 * @param array<string, mixed> $result
 * @return array{
 *   company: string,
 *   website: string,
 *   aliases: list<string>,
 *   services: list<string>,
 *   places: list<string>,
 *   audience: string,
 *   core_version: int,
 *   competitors_version: int
 * }
 */
function app_load_profile(PDO $pdo, int $userId, ?array $scan, array $result): array
{
    ai_scan_ensure_questions_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM account_profile WHERE user_id = :u LIMIT 1');
    $stmt->execute(['u' => $userId]);
    $row = $stmt->fetch();

    $aliases = [];
    $services = [];
    $places = [];
    $company = '';
    $website = '';
    $audience = '';
    $coreVersion = 1;
    $competitorsVersion = 1;

    if ($row !== false) {
        $company = (string) ($row['company'] ?? '');
        $website = (string) ($row['website'] ?? '');
        $audience = (string) ($row['audience'] ?? '');
        $coreVersion = (int) ($row['core_version'] ?? 1);
        $competitorsVersion = (int) ($row['competitors_version'] ?? 1);
        $aliases = app_json_string_list($row['aliases_json'] ?? null);
        $services = app_json_string_list($row['services_json'] ?? null);
        $places = app_json_string_list($row['places_json'] ?? null);
    }

    if ($company === '' && isset($result['company']) && is_string($result['company'])) {
        $company = $result['company'];
    }
    if ($website === '' && $scan !== null) {
        $website = (string) ($scan['domain'] ?? '');
    }
    if ($aliases === [] && isset($result['brand_aliases']) && is_array($result['brand_aliases'])) {
        $aliases = array_values(array_filter(array_map('strval', $result['brand_aliases'])));
    }
    if ($services === [] && isset($result['services']) && is_array($result['services'])) {
        $services = array_values(array_filter(array_map('strval', $result['services'])));
    }
    if ($places === [] && isset($result['locations']) && is_array($result['locations'])) {
        $places = array_values(array_filter(array_map('strval', $result['locations'])));
    }
    if ($audience === '' && isset($result['audience']) && is_string($result['audience'])) {
        $audience = $result['audience'];
    }
    if (isset($result['core_version']) && is_numeric($result['core_version'])) {
        $coreVersion = (int) $result['core_version'];
    }
    if (isset($result['competitors_version']) && is_numeric($result['competitors_version'])) {
        $competitorsVersion = (int) $result['competitors_version'];
    }

    return [
        'company' => $company,
        'website' => $website,
        'aliases' => $aliases,
        'services' => $services,
        'places' => $places,
        'audience' => $audience,
        'core_version' => $coreVersion,
        'competitors_version' => $competitorsVersion,
    ];
}

/**
 * @return list<string>
 */
function app_json_string_list(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_filter(array_map('strval', $raw), static fn ($s) => $s !== ''));
    }
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $decoded), static fn ($s) => $s !== ''));
}

/**
 * @return array<string, mixed>|null
 */
function app_pending_subscription_request(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM subscription_events
         WHERE user_id = :u AND event = 'request'
         ORDER BY ts DESC, id DESC LIMIT 1"
    );
    $stmt->execute(['u' => $userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }

    // A later admin_set after this request means it was handled.
    $check = $pdo->prepare(
        "SELECT 1 FROM subscription_events
         WHERE user_id = :u AND event = 'admin_set' AND ts >= :ts
         LIMIT 1"
    );
    $check->execute(['u' => $userId, 'ts' => (int) $row['ts']]);
    if ($check->fetch() !== false) {
        return null;
    }

    return $row;
}

function app_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_locked_line(): string
{
    return AI_SCAN_FREE_WRITE_MESSAGE;
}

/**
 * Next Monday label in Europe/Amsterdam.
 */
function app_next_monday_label(?int $now = null): string
{
    $tz = new DateTimeZone('Europe/Amsterdam');
    $dt = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
    if ((int) $dt->format('N') === 1 && (int) $dt->format('G') < 9) {
        return app_format_nl_date($dt->getTimestamp());
    }
    $next = $dt->modify('next monday');

    return app_format_nl_date($next->getTimestamp());
}

/**
 * Format euro amount from tiers (never hardcode the staffel).
 */
function app_format_euro(int $amount): string
{
    return '€' . $amount;
}

/**
 * @param list<array<string, mixed>> $actions
 * @return list<array<string, mixed>>
 */
function app_actions_for_tier(array $actions, bool $isFree): array
{
    if (!$isFree) {
        return $actions;
    }

    return array_slice($actions, 0, 1);
}

/**
 * Load actions for user; seed from result_json when table empty.
 *
 * @param array<string, mixed> $result
 * @return list<array<string, mixed>>
 */
function app_load_actions(PDO $pdo, int $userId, array $result): array
{
    ai_scan_ensure_questions_schema($pdo);
    ai_scan_ensure_actions_persisted($pdo, $userId, $result);
    $stmt = $pdo->prepare(
        'SELECT * FROM actions WHERE user_id = :u ORDER BY archived ASC, sort_order ASC, id ASC'
    );
    $stmt->execute(['u' => $userId]);
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

/**
 * @param array<string, mixed> $result
 * @param array{core_version: int} $profile
 * @return array{core: list<array<string, mixed>>, rotating: list<array<string, mixed>>, by_place: list<array<string, mixed>>, core_version: int}
 */
function app_load_questions_view(PDO $pdo, int $userId, array $result, array $profile): array
{
    ai_scan_ensure_questions_from_result($pdo, $userId, $result, $profile);
    $rows = ai_scan_list_questions($pdo, $userId);
    $core = [];
    $rotating = [];
    if ($rows !== []) {
        foreach ($rows as $row) {
            if ((string) ($row['status'] ?? 'active') === 'deleted') {
                continue;
            }
            $kind = (string) ($row['kind'] ?? 'core');
            if ($kind === 'rotating') {
                $rotating[] = $row;
            } else {
                $core[] = $row;
            }
        }
    } else {
        $fromCore = $result['questions']['core'] ?? ($result['core_questions'] ?? ($result['questions'] ?? []));
        if (is_array($fromCore)) {
            $n = 0;
            foreach ($fromCore as $q) {
                if (!is_array($q) && !is_string($q)) {
                    continue;
                }
                $n++;
                if (is_string($q)) {
                    $core[] = [
                        'id' => $n,
                        'text' => $q,
                        'mentioned' => null,
                        'answers' => null,
                        'status' => 'active',
                        'draft' => 0,
                        'place' => null,
                    ];
                    continue;
                }
                $core[] = [
                    'id' => $n,
                    'text' => (string) ($q['text'] ?? $q['question'] ?? ''),
                    'mentioned' => isset($q['mentioned']) ? (int) $q['mentioned'] : (isset($q['k']) ? (int) $q['k'] : null),
                    'answers' => isset($q['answers']) ? (int) $q['answers'] : (isset($q['m']) ? (int) $q['m'] : null),
                    'status' => (string) ($q['status'] ?? 'active'),
                    'draft' => !empty($q['draft']) ? 1 : 0,
                    'place' => $q['place'] ?? null,
                    'named' => $q['named'] ?? ($q['hit'] ?? null),
                ];
            }
        }
        $fromRot = $result['questions']['rotating'] ?? ($result['rotating'] ?? []);
        if (is_array($fromRot)) {
            foreach ($fromRot as $q) {
                if (!is_array($q)) {
                    continue;
                }
                $rotating[] = [
                    'id' => (string) ($q['id'] ?? ''),
                    'text' => (string) ($q['text'] ?? $q['question'] ?? ''),
                    'mentioned' => isset($q['mentioned']) ? (int) $q['mentioned'] : null,
                    'answers' => isset($q['answers']) ? (int) $q['answers'] : null,
                    'named' => $q['named'] ?? ($q['hit'] ?? null),
                ];
            }
        }
    }

    $byPlace = [];
    $fromPlaces = $result['by_place'] ?? ($result['places'] ?? []);
    if (is_array($fromPlaces)) {
        foreach ($fromPlaces as $p) {
            if (!is_array($p)) {
                continue;
            }
            $byPlace[] = [
                'place' => (string) ($p['place'] ?? $p['name'] ?? ''),
                'mentioned' => (int) ($p['mentioned'] ?? 0),
                'answers' => (int) ($p['answers'] ?? 0),
            ];
        }
    }

    return [
        'core' => $core,
        'rotating' => $rotating,
        'by_place' => $byPlace,
        'core_version' => (int) ($profile['core_version'] ?? 1),
    ];
}

/**
 * @param array<string, mixed> $result
 * @return array{mentioned: list<array<string, mixed>>, list: list<array<string, mixed>>, excluded: list<array<string, mixed>>, proposals: list<array<string, mixed>>}
 */
function app_load_competitors_view(PDO $pdo, int $userId, array $result, bool $isFree): array
{
    ai_scan_ensure_questions_schema($pdo);
    ai_scan_ensure_competitors_from_result($pdo, $userId, $result);
    $stmt = $pdo->prepare('SELECT * FROM competitors WHERE user_id = :u ORDER BY id ASC');
    $stmt->execute(['u' => $userId]);
    $rows = $stmt->fetchAll();
    $list = [];
    $excluded = [];
    if (is_array($rows) && $rows !== []) {
        foreach ($rows as $row) {
            $item = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'aliases' => app_json_string_list($row['aliases_json'] ?? null),
                'domains' => app_json_string_list($row['domains_json'] ?? null),
                'source' => (string) ($row['source'] ?? 'handmatig'),
                'mentioned_count' => (int) ($row['mentioned_count'] ?? 0),
                'is_self' => false,
            ];
            if ((string) ($row['status'] ?? '') === 'excluded') {
                $excluded[] = $item;
            } else {
                $list[] = $item;
            }
        }
    } else {
        $fromList = $result['competitors']['list'] ?? ($result['competitors'] ?? []);
        if (is_array($fromList)) {
            foreach ($fromList as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $list[] = [
                    'id' => (string) ($c['id'] ?? ''),
                    'name' => (string) ($c['name'] ?? ''),
                    'aliases' => isset($c['aliases']) && is_array($c['aliases'])
                        ? array_values(array_map('strval', $c['aliases']))
                        : [],
                    'domains' => isset($c['domains']) && is_array($c['domains'])
                        ? array_values(array_map('strval', $c['domains']))
                        : [],
                    'source' => (string) ($c['source'] ?? 'AI-antwoord'),
                    'mentioned_count' => (int) ($c['mentioned_count'] ?? ($c['count'] ?? 0)),
                    'is_self' => !empty($c['is_self']),
                ];
            }
        }
        $fromEx = $result['competitors']['excluded'] ?? [];
        if (is_array($fromEx)) {
            foreach ($fromEx as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $excluded[] = [
                    'name' => (string) ($c['name'] ?? ''),
                    'source' => (string) ($c['source'] ?? 'handmatig'),
                ];
            }
        }
    }

    $mentioned = $result['competitors']['mentioned_this_week'] ?? ($result['mentioned_competitors'] ?? $list);
    if (!is_array($mentioned)) {
        $mentioned = [];
    }
    $mentionedOut = [];
    foreach ($mentioned as $c) {
        if (!is_array($c)) {
            continue;
        }
        $mentionedOut[] = [
            'name' => (string) ($c['name'] ?? ''),
            'count' => (int) ($c['count'] ?? ($c['mentioned_count'] ?? 0)),
            'is_self' => !empty($c['is_self']),
        ];
    }

    $proposals = [];
    $fromProp = $result['competitors']['proposals'] ?? [];
    if (is_array($fromProp)) {
        foreach ($fromProp as $c) {
            if (!is_array($c)) {
                continue;
            }
            $proposals[] = [
                'name' => (string) ($c['name'] ?? ''),
                'count' => (int) ($c['count'] ?? 0),
            ];
        }
    }

    if ($isFree) {
        // Lijst blijft volledig: gratis accounts mogen concurrenten aanvullen en weghalen.
        $mentionedOut = array_slice($mentionedOut, 0, 3);
    }

    return [
        'mentioned' => $mentionedOut,
        'list' => $list,
        'excluded' => $excluded,
        'proposals' => $proposals,
    ];
}

/**
 * Score / niveau from result.
 *
 * @param array<string, mixed> $result
 * @param array<string, mixed>|null $scan
 * @return array{mentioned: int|null, answers: int|null, level: string, ready: bool, status: string, retrying: bool}
 */
function app_score_view(array $result, ?array $scan): array
{
    $status = $scan ? (string) $scan['status'] : 'none';
    $mentioned = null;
    $answers = null;
    if (isset($result['mentioned']) && is_numeric($result['mentioned'])) {
        $mentioned = (int) $result['mentioned'];
    }
    if (isset($result['answers']) && is_numeric($result['answers'])) {
        $answers = (int) $result['answers'];
    }
    if ($mentioned === null && isset($result['score']['mentioned'])) {
        $mentioned = (int) $result['score']['mentioned'];
    }
    if ($answers === null && isset($result['score']['answers'])) {
        $answers = (int) $result['score']['answers'];
    }
    $level = '';
    if (isset($result['level']) && is_string($result['level'])) {
        $level = $result['level'];
    } elseif (isset($result['score']['level']) && is_string($result['score']['level'])) {
        $level = $result['score']['level'];
    } elseif ($mentioned !== null && $answers !== null && $answers > 0) {
        $pct = $mentioned / $answers;
        if ($pct >= 0.4) {
            $level = 'Sterk zichtbaar';
        } elseif ($pct >= 0.2) {
            $level = 'Zichtbaar';
        } elseif ($pct > 0) {
            $level = 'Beperkt zichtbaar';
        } else {
            $level = 'Nog niet zichtbaar';
        }
    }

    return [
        'mentioned' => $mentioned,
        'answers' => $answers,
        'level' => $level,
        'ready' => $status === 'klaar' && ($mentioned !== null || !empty($scan['result_html'])),
        'status' => $status,
        // Eerste poging mislukt, tweede loopt of staat klaar.
        'retrying' => in_array($status, ['bevestigd', 'bezig'], true) && (int) ($scan['attempts'] ?? 0) > 0,
    ];
}
