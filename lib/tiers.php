<?php

declare(strict_types=1);

/**
 * Single source for tier limits: assets/data/tiers.json.
 * Never hardcode questions/actions/monthly elsewhere.
 */

/** @var array<string, mixed>|null */
$GLOBALS['ai_scan_tiers_cache'] = null;

/**
 * @return array<string, mixed>
 */
function ai_scan_load_tiers(?string $path = null): array
{
    if ($path === null && is_array($GLOBALS['ai_scan_tiers_cache'] ?? null)) {
        return $GLOBALS['ai_scan_tiers_cache'];
    }

    $file = $path ?? (__DIR__ . '/../assets/data/tiers.json');
    if (!is_readable($file)) {
        throw new RuntimeException('tiers.json is missing or unreadable.');
    }

    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('tiers.json could not be read.');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('tiers.json is not valid JSON.');
    }

    ai_scan_validate_tiers($data);

    if ($path === null) {
        $GLOBALS['ai_scan_tiers_cache'] = $data;
    }

    return $data;
}

/**
 * Clear the in-process tiers cache (tests).
 */
function ai_scan_clear_tiers_cache(): void
{
    $GLOBALS['ai_scan_tiers_cache'] = null;
}

/**
 * @param array<string, mixed> $data
 */
function ai_scan_validate_tiers(array $data): void
{
    if (!isset($data['tiers'], $data['free'], $data['above_max']) || !is_array($data['tiers'])) {
        throw new RuntimeException('tiers.json missing required keys.');
    }

    $tiers = $data['tiers'];
    if ($tiers === []) {
        throw new RuntimeException('tiers.json has no tiers.');
    }

    $prevQuestions = 0;
    $prevMonthly = 0;
    $prevActions = 0;
    $annualMonths = (int) ($data['annual_months_charged'] ?? 10);
    $hourly = (float) ($data['hourly_rate'] ?? 0);
    $discount = (float) ($data['bundle_discount'] ?? 0);
    $hours = $data['compleet_hours'] ?? [];
    if (!is_array($hours)) {
        throw new RuntimeException('compleet_hours invalid.');
    }

    foreach ($tiers as $i => $tier) {
        if (!is_array($tier)) {
            throw new RuntimeException('tier entry invalid.');
        }
        $questions = (int) ($tier['questions'] ?? 0);
        $monthly = (int) ($tier['monthly'] ?? 0);
        $actions = (int) ($tier['actions'] ?? 0);
        $id = (string) ($tier['id'] ?? '');

        if ($questions <= $prevQuestions) {
            throw new RuntimeException('tiers questions must be ascending.');
        }
        if ($monthly < $prevMonthly) {
            throw new RuntimeException('tiers monthly must be non-decreasing.');
        }
        if ($actions < $prevActions) {
            throw new RuntimeException('tiers actions must be non-decreasing.');
        }
        if ($id !== 'q' . $questions) {
            throw new RuntimeException('tier id must be q{questions}.');
        }

        $annual = (int) ($tier['annual'] ?? -1);
        if ($annual !== $monthly * $annualMonths) {
            throw new RuntimeException('annual must equal monthly × annual_months_charged for ' . $id);
        }

        $weeklyExpected = (int) round($monthly * 12 / 52, 0, PHP_ROUND_HALF_UP);
        if ((int) ($tier['weekly'] ?? -1) !== $weeklyExpected) {
            throw new RuntimeException('weekly mismatch for ' . $id);
        }

        $compleet = $tier['compleet'] ?? null;
        if (!is_array($compleet)) {
            throw new RuntimeException('compleet missing for ' . $id);
        }
        foreach ($hours as $h) {
            $hInt = (int) $h;
            $expected = (int) round(
                $monthly + $hInt * $hourly * (1 - $discount),
                0,
                PHP_ROUND_HALF_UP
            );
            $key = (string) $hInt;
            if (!isset($compleet[$key]) && !isset($compleet[$hInt])) {
                throw new RuntimeException('compleet hour missing for ' . $id);
            }
            $got = (int) ($compleet[$key] ?? $compleet[$hInt]);
            if ($got !== $expected) {
                throw new RuntimeException('compleet mismatch for ' . $id . ' h=' . $hInt);
            }
        }

        $prevQuestions = $questions;
        $prevMonthly = $monthly;
        $prevActions = $actions;
    }

    $free = $data['free'];
    if (!is_array($free) || (string) ($free['id'] ?? '') !== 'free') {
        throw new RuntimeException('free tier invalid.');
    }
    if (empty($free['read_only']) || !empty($free['weekly_measurement'])) {
        throw new RuntimeException('free must be read_only without weekly measurement.');
    }
}

/**
 * @param array<string, mixed> $tiers
 * @return array<string, mixed>|null
 */
function ai_scan_tier_by_id(array $tiers, string $id): ?array
{
    if ($id === 'free') {
        $free = $tiers['free'] ?? null;

        return is_array($free) ? $free : null;
    }

    foreach ($tiers['tiers'] ?? [] as $tier) {
        if (is_array($tier) && (string) ($tier['id'] ?? '') === $id) {
            return $tier;
        }
    }

    return null;
}

/**
 * Snap a requested question count to the first tier with questions ≥ n.
 * Above above_max.from_questions → synthetic custom tier.
 *
 * @param array<string, mixed>|null $tiers
 * @return array{id: string, questions: int, actions: int, monthly: int, custom: bool}
 */
function ai_scan_snap_questions(int $n, ?array $tiers = null): array
{
    $tiers ??= ai_scan_load_tiers();
    $list = $tiers['tiers'] ?? [];
    if (!is_array($list) || $list === []) {
        throw new RuntimeException('no tiers to snap to');
    }

    $above = $tiers['above_max'] ?? [];
    $from = (int) ($above['from_questions'] ?? 1000);
    $per100 = (int) ($above['per_started_100'] ?? 39);
    $aboveActions = (int) ($above['actions'] ?? 20);

    /** @var array<string, mixed> $last */
    $last = $list[array_key_last($list)];

    if ($n > $from) {
        $extraBlocks = (int) ceil(($n - $from) / 100);
        $questions = $from + ($extraBlocks * 100);

        return [
            'id' => 'custom',
            'questions' => $questions,
            'actions' => $aboveActions,
            'monthly' => (int) ($last['monthly'] ?? 0) + ($extraBlocks * $per100),
            'custom' => true,
        ];
    }

    foreach ($list as $tier) {
        if (!is_array($tier)) {
            continue;
        }
        if ((int) ($tier['questions'] ?? 0) >= $n) {
            return [
                'id' => (string) $tier['id'],
                'questions' => (int) $tier['questions'],
                'actions' => (int) $tier['actions'],
                'monthly' => (int) $tier['monthly'],
                'custom' => false,
            ];
        }
    }

    return [
        'id' => (string) $last['id'],
        'questions' => (int) $last['questions'],
        'actions' => (int) $last['actions'],
        'monthly' => (int) $last['monthly'],
        'custom' => false,
    ];
}

/**
 * Effective tier for an account. Unknown tier_id → free (fail closed).
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed> $tiers
 * @return array{
 *   id: string,
 *   questions: int,
 *   actions: int,
 *   monthly: int,
 *   read_only: bool,
 *   weekly_measurement: bool
 * }
 */
function ai_scan_effective_tier(array $user, array $tiers): array
{
    $status = (string) ($user['subscription_status'] ?? 'none');
    $tierId = (string) ($user['tier_id'] ?? 'free');
    $activeStatuses = ['active', 'canceling', 'past_due'];

    $free = $tiers['free'];
    $freeResult = [
        'id' => 'free',
        'questions' => (int) ($free['questions'] ?? 25),
        'actions' => (int) ($free['actions'] ?? 1),
        'monthly' => 0,
        'read_only' => true,
        'weekly_measurement' => false,
    ];

    if (!in_array($status, $activeStatuses, true) || $tierId === 'free') {
        return $freeResult;
    }

    if ($tierId === 'custom') {
        $customQ = (int) ($user['custom_questions'] ?? 0);
        $above = $tiers['above_max'] ?? [];
        $from = (int) ($above['from_questions'] ?? 1000);
        $per100 = (int) ($above['per_started_100'] ?? 39);
        $aboveActions = (int) ($above['actions'] ?? 20);
        $list = $tiers['tiers'] ?? [];
        $last = is_array($list) && $list !== [] ? $list[array_key_last($list)] : null;
        $lastMonthly = is_array($last) ? (int) ($last['monthly'] ?? 0) : 0;

        if ($customQ <= $from || ($customQ % 100) !== 0) {
            error_log('ai_scan: invalid custom_questions, fail closed to free');

            return $freeResult;
        }

        $extraBlocks = (int) (($customQ - $from) / 100);

        return [
            'id' => 'custom',
            'questions' => $customQ,
            'actions' => $aboveActions,
            'monthly' => $lastMonthly + ($extraBlocks * $per100),
            'read_only' => false,
            'weekly_measurement' => true,
        ];
    }

    $tier = ai_scan_tier_by_id($tiers, $tierId);
    if ($tier === null || $tierId === 'free') {
        error_log('ai_scan: unknown tier_id=' . $tierId . ', fail closed to free');

        return $freeResult;
    }

    return [
        'id' => (string) $tier['id'],
        'questions' => (int) $tier['questions'],
        'actions' => (int) $tier['actions'],
        'monthly' => (int) ($tier['monthly'] ?? 0),
        'read_only' => false,
        'weekly_measurement' => true,
    ];
}

/**
 * Human label for mails/UI (not a hardcoded limit).
 *
 * @param array<string, mixed> $tiers
 */
function ai_scan_tier_label(string $tierId, array $tiers, ?int $customQuestions = null): string
{
    if ($tierId === 'free' || $tierId === '' || $tierId === 'none') {
        return 'gratis';
    }
    if ($tierId === 'custom') {
        $n = $customQuestions ?? 0;

        return $n > 0 ? ($n . ' vragen (op aanvraag)') : 'op aanvraag';
    }
    $tier = ai_scan_tier_by_id($tiers, $tierId);
    if ($tier === null) {
        return $tierId;
    }

    return (int) $tier['questions'] . ' vragen';
}
