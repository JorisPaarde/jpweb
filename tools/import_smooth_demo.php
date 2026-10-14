<?php

declare(strict_types=1);

/** Read-only translation; never runs a scan or sends mail. */
function smooth_demo_import(string $dir): array
{
    $read = static function (string $file) use ($dir): array {
        $data = json_decode((string) file_get_contents($dir . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException('Ongeldige bron'); }
        return $data;
    };
    $sets = $read('question_sets.json');
    if (($sets['question_set_version'] ?? '') !== 'core-v2-koop' || count($sets['core'] ?? []) !== 25
        || count(array_unique(array_column($sets['core'], 'id'))) !== 25) {
        throw new RuntimeException('Onverwachte vraagset');
    }
    $weeks = array_map(static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        file($dir . '/weekly.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $week = end($weeks);
    if (!is_array($week) || empty($week['complete'])) { throw new RuntimeException('Geen volledige weekmeting'); }
    $scores = array_column($week['per_question'], null, 'id');
    $questions = [];
    foreach ($sets['core'] as $q) {
        // Changed IDs/texts must never inherit measurements from replaced questions.
        $score = $scores[$q['id']] ?? [];
        $same = ($score['text'] ?? '') === $q['vraag'];
        $questions[] = ['source_id' => $q['id'], 'text' => $q['vraag'],
            'mentioned' => $same ? (int) $score['mentioned'] : null,
            'answers' => $same ? (int) $score['answers'] : null];
    }
    $actions = [];
    foreach ($read('actions.json')['actions'] ?? [] as $a) {
        if (($a['status'] ?? '') !== 'voorgesteld') { continue; }
        $actions[] = ['id' => $a['id'], 'week' => $a['week'], 'type' => $a['type'], 'status' => $a['status'],
            'title' => $a['title'], 'why' => $a['why'], 'page_path' => $a['page_url'],
            'now' => (string) ($a['now']['quote'] ?? ''), 'becomes' => $a['becomes'],
            'evidence' => 'Ik baseer dit voorstel op de eerdere meting en de opgeslagen pagina.',
            'questions' => $a['questions'], 'control_questions' => $a['control_questions']];
    }
    $competitors = $read('competitors.json');
    return ['demo' => true, 'company' => 'Smooth Operator', 'brand_aliases' => ['SmoothOperator', 'Smooth Operator Haarlem'],
        'question_set_version' => $sets['question_set_version'], 'question_set_label' => 'Koopvragen, versie 2',
        'core_version' => 2, 'competitors_version' => (int) $competitors['version'],
        'demo_note' => 'Ik laat hier bestaande meetgegevens zien. De cijfers en voorstellen komen uit de eerdere meting van 12 oktober 2026. Ik heb daarna acht vragen vervangen. Bij die nieuwe vragen staat nog geen uitslag. Ik start vanuit deze demo geen nieuwe meting.',
        'updated_at' => strtotime($week['measured_at']), 'mentioned' => (int) $week['mentioned'], 'answers' => (int) $week['answers'],
        'questions' => ['core' => $questions, 'rotating' => []], 'actions' => $actions,
        'trend' => [['week' => $week['week'] . ' · eerdere vragen', 'mentioned' => (int) $week['mentioned'], 'answers' => (int) $week['answers']]],
        'competitors' => ['list' => $competitors['competitors'], 'mentioned_this_week' => array_map(
            static fn ($c) => ['name' => $c['name'], 'count' => (int) $c['answers_mentioned']], $week['competitors'])],
        'aio' => $week['aio_rate']];
}

/** Caller owns the transaction. Account creation fails closed on duplicates. */
function smooth_demo_create(PDO $pdo, string $email, array $result): int
{
    if (!ai_scan_email_syntax_ok($email) || !str_ends_with(strtolower($email), '@agentmail.to')
        || empty($result['demo']) || ($result['question_set_version'] ?? '') !== 'core-v2-koop'
        || count($result['questions']['core'] ?? []) !== 25) {
        throw new RuntimeException('Ongeldige demo-invoer');
    }
    ai_scan_ensure_questions_schema($pdo);
    $now = time();
    $hash = ai_scan_email_hash(ai_scan_normalize_email($email));
    $pdo->prepare('INSERT INTO users(email, email_hash, password_hash, created_at, free_scan_used, tier_id, subscription_status)
        VALUES(:e, :h, NULL, :t, 1, \'q100\', \'paused\')')->execute(['e' => $email, 'h' => $hash, 't' => $now]);
    $uid = (int) $pdo->lastInsertId();
    // Paused account: the app shows imported demo results, no recurring scan scheduled.
    $pdo->prepare("INSERT INTO scans(public_id, user_id, email, email_hash, website_url, domain, domain_hash,
        status, created_at, completed_at, ready_mail_sent_at, result_json)
        VALUES(:p, :u, :e, :h, 'https://www.smooth-operator.nl/', 'smooth-operator.nl', :d, 'klaar', :t, :c, :t, :r)")
        ->execute(['p' => ai_scan_random_token(16), 'u' => $uid, 'e' => $email, 'h' => $hash,
            'd' => ai_scan_domain_hash('smooth-operator.nl'), 't' => $now, 'c' => $result['updated_at'],
            'r' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $pdo->prepare('INSERT INTO account_profile(user_id, company, core_version, updated_at) VALUES(:u, \'Smooth Operator\', 2, :t)')
        ->execute(['u' => $uid, 't' => $now]);
    ai_scan_ensure_questions_from_result($pdo, $uid, $result, []);
    ai_scan_ensure_competitors_from_result($pdo, $uid, $result);
    ai_scan_ensure_actions_persisted($pdo, $uid, $result);
    return $uid;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $result = smooth_demo_import($argv[1] ?? '/workspace/reports/geo/smooth-operator');
        $out = $argv[2] ?? '';
        if ($out === '' || file_exists($out)) { throw new RuntimeException('Kies een nieuw uitvoerbestand'); }
        $handle = fopen($out, 'x');
        if ($handle === false) { throw new RuntimeException('Uitvoer mislukt'); }
        chmod($out, 0600);
        fwrite($handle, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        fclose($handle);
        echo "Import klaar: vragen=25 acties=" . count($result['actions']) . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Import mislukt\n");
        exit(1);
    }
}
