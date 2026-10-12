<?php

declare(strict_types=1);

/**
 * Brief 02: app UI structure, Dutch copy, a11y hooks, tier gating.
 */

require_once dirname(__DIR__) . '/lib/questions.php';
require_once dirname(__DIR__) . '/app/_init.php';

/**
 * @return array{0: array<string, mixed>, 1: PDO, 2: int, 3: int}
 */
function app_ui_seed_accounts(): array
{
    [$config, $pdo] = boot_test_db();
    ai_scan_ensure_questions_schema($pdo);
    $now = time();

    $result = [
        'mentioned' => 12,
        'answers' => 50,
        'level' => 'Zichtbaar',
        'company' => 'Voorbeeld Plinten',
        'brand_aliases' => ['Voorbeeld'],
        'services' => ['plinten plaatsen', 'plinten schilderen'],
        'locations' => ['Haarlem', 'Amsterdam'],
        'audience' => 'Huiseigenaren in Noord-Holland',
        'core_version' => 1,
        'competitors_version' => 1,
        'trend' => [
            ['week' => 'W40', 'mentioned' => 8, 'answers' => 50],
            ['week' => 'W41', 'mentioned' => 12, 'answers' => 50],
        ],
        'questions' => [
            'core' => [
                ['text' => 'Wie plaatst plinten in Haarlem?', 'named' => true, 'mentioned' => 3, 'answers' => 4],
                ['text' => 'Beste plinten schilder Amsterdam?', 'named' => false, 'mentioned' => 0, 'answers' => 4],
            ],
            'rotating' => [
                ['text' => 'Welk bedrijf doet spoedreparaties plinten?'],
            ],
        ],
        'by_place' => [
            ['place' => 'Haarlem', 'mentioned' => 6, 'answers' => 12],
            ['place' => 'Amsterdam', 'mentioned' => 2, 'answers' => 10],
        ],
        'competitors' => [
            'mentioned_this_week' => [
                ['name' => 'Voorbeeld Plinten', 'count' => 12, 'is_self' => true],
                ['name' => 'STYQX', 'count' => 9, 'is_self' => false],
                ['name' => 'PlintPro', 'count' => 5, 'is_self' => false],
                ['name' => 'Randafwerking NL', 'count' => 3, 'is_self' => false],
            ],
            'list' => [
                ['name' => 'STYQX', 'domains' => ['styqx.nl'], 'source' => 'AI-antwoord'],
                ['name' => 'PlintPro', 'domains' => ['plintpro.nl'], 'source' => 'webzoeken'],
                ['name' => 'Randafwerking NL', 'domains' => ['randafwerking.nl'], 'source' => 'handmatig'],
                ['name' => 'Extra Vier', 'domains' => ['extra4.nl'], 'source' => 'handmatig'],
            ],
            'excluded' => [
                ['name' => 'Oude Concurrent'],
            ],
            'proposals' => [
                ['name' => 'NieuwBedrijf', 'count' => 4],
            ],
        ],
        'actions' => [
            [
                'id' => 'a1',
                'type' => 'verbeteren',
                'status' => 'voorgesteld',
                'title' => 'Maak op de homepage duidelijk welke plaatsen je bedient',
                'page_path' => '/',
                'why' => 'AI noemt concurrenten vaker bij plaatsgebonden vragen.',
                'evidence' => 'homepage hero',
                'now' => 'Algemeen over plinten.',
                'becomes' => 'Plinten plaatsen in Haarlem en Amsterdam.',
                'placeholders' => [['label' => '[jouw prijs]']],
                'log' => 'Voorgesteld deze week',
                'effect' => 'Meer noemingen bij plaatsvragen',
            ],
            [
                'id' => 'a2',
                'type' => 'toevoegen',
                'status' => 'voorgesteld',
                'title' => 'Voeg een dienstenpagina toe voor plinten schilderen',
                'page_path' => '/diensten/schilderen',
                'why' => 'Die dienst scoort nu 0 noemingen.',
            ],
            [
                'id' => 'a3',
                'type' => 'technisch',
                'status' => 'voorgesteld',
                'title' => 'Zet merknamen in de titel van de contactpagina',
                'page_path' => '/contact',
            ],
        ],
    ];
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $insUser = $pdo->prepare(
        'INSERT INTO users (email, email_hash, password_hash, created_at, free_scan_used, tier_id, subscription_status, period_end)
         VALUES (:e, :eh, :ph, :c, 1, :tier, :status, :pe)'
    );
    $insUser->execute([
        'e' => 'free@voorbeeld.nl',
        'eh' => ai_scan_email_hash('free@voorbeeld.nl'),
        'ph' => ai_scan_hash_password('test-password-12'),
        'c' => $now,
        'tier' => 'free',
        'status' => 'none',
        'pe' => null,
    ]);
    $freeId = (int) $pdo->lastInsertId();

    $insUser->execute([
        'e' => 'paid@voorbeeld.nl',
        'eh' => ai_scan_email_hash('paid@voorbeeld.nl'),
        'ph' => ai_scan_hash_password('test-password-12'),
        'c' => $now,
        'tier' => 'q100',
        'status' => 'active',
        'pe' => $now + 30 * 86400,
    ]);
    $paidId = (int) $pdo->lastInsertId();

    $insScan = $pdo->prepare(
        'INSERT INTO scans (public_id, user_id, email, email_hash, website_url, domain, domain_hash, status, created_at, confirmed_at, completed_at, result_json, result_html)
         VALUES (:p, :u, :e, :eh, :w, :d, :dh, :s, :c, :conf, :done, :json, :html)'
    );
    foreach ([[$freeId, 'free@voorbeeld.nl'], [$paidId, 'paid@voorbeeld.nl']] as [$uid, $email]) {
        $insScan->execute([
            'p' => ai_scan_public_id(),
            'u' => $uid,
            'e' => $email,
            'eh' => ai_scan_email_hash($email),
            'w' => 'https://voorbeeld.nl/',
            'd' => 'voorbeeld.nl',
            'dh' => ai_scan_domain_hash('voorbeeld.nl'),
            's' => 'klaar',
            'c' => $now,
            'conf' => $now,
            'done' => $now,
            'json' => $json,
            'html' => '<p>12 van 50</p>',
        ]);
    }

    $pdo->prepare(
        'INSERT INTO account_profile(user_id, company, website, aliases_json, services_json, places_json, audience, updated_at)
         VALUES(:u, :c, :w, :a, :s, :p, :aud, :ts)'
    )->execute([
        'u' => $freeId,
        'c' => 'Voorbeeld Plinten',
        'w' => 'voorbeeld.nl',
        'a' => json_encode(['Voorbeeld'], JSON_UNESCAPED_UNICODE),
        's' => json_encode(['plinten plaatsen'], JSON_UNESCAPED_UNICODE),
        'p' => json_encode(['Haarlem'], JSON_UNESCAPED_UNICODE),
        'aud' => 'Huiseigenaren',
        'ts' => $now,
    ]);
    $pdo->prepare(
        'INSERT INTO account_profile(user_id, company, website, aliases_json, services_json, places_json, audience, updated_at)
         VALUES(:u, :c, :w, :a, :s, :p, :aud, :ts)'
    )->execute([
        'u' => $paidId,
        'c' => 'Voorbeeld Plinten',
        'w' => 'voorbeeld.nl',
        'a' => json_encode(['Voorbeeld'], JSON_UNESCAPED_UNICODE),
        's' => json_encode(['plinten plaatsen', 'plinten schilderen'], JSON_UNESCAPED_UNICODE),
        'p' => json_encode(['Haarlem', 'Amsterdam'], JSON_UNESCAPED_UNICODE),
        'aud' => 'Huiseigenaren in Noord-Holland',
        'ts' => $now,
    ]);

    return [$config, $pdo, $freeId, $paidId];
}

/**
 * @param array<string, mixed> $config
 */
function app_ui_login(array $config, int $userId, ?int $passwordVersion = 1): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Avoid session_name/cookie warnings after the test runner has printed output.
        if (!headers_sent()) {
            ai_scan_start_session($config);
        } else {
            @session_start();
        }
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_at'] = time();
    if ($passwordVersion !== null) {
        $_SESSION['password_version'] = $passwordVersion;
    }
}

/**
 * Render an app page script into a string (no HTTP).
 *
 * @param array<string, mixed> $config
 */
function app_ui_render(
    array $config,
    int $userId,
    string $script,
    array $get = [],
    array $post = [],
    string $method = 'GET',
    ?string $flash = null
): string {
    app_ui_login($config, $userId);
    if ($flash !== null) {
        $_SESSION['ai_scan_flash'] = $flash;
    }
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['HTTPS'] = 'off';
    $_GET = $get;
    $_POST = $post;
    $_SERVER['REQUEST_URI'] = '/app/' . basename($script);

    // Point boot at this test config via a temp file.
    $cfgPath = sys_get_temp_dir() . '/jpweb-app-ui-config-' . $userId . '-' . basename($script) . '.php';
    $export = var_export($config, true);
    file_put_contents($cfgPath, "<?php\nreturn {$export};\n");
    putenv('JPWEB_AI_SCAN_CONFIG=' . $cfgPath);
    putenv('JPWEB_AI_SCAN_ALLOW_INSECURE=1');
    putenv('JPWEB_AI_SCAN_TEST_USER_ID=' . (string) $userId);

    ob_start();
    try {
        include $script;
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return (string) ob_get_clean();
}

test('questions schema is idempotent', static function (): void {
    [$config, $pdo] = boot_test_db();
    ai_scan_ensure_questions_schema($pdo);
    ai_scan_ensure_questions_schema($pdo);
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name IN ('questions','account_profile','competitors','actions','settings_log')"
    )->fetchAll(PDO::FETCH_COLUMN);
    assert_true(count($tables) === 5, 'expected five app tables');
    assert_same(0, ai_scan_count_active_questions($pdo, 1));
});

test('app pages exist', static function (): void {
    $root = dirname(__DIR__);
    foreach ([
        'app/layout.php',
        'app/nav.php',
        'app/overzicht.php',
        'app/acties.php',
        'app/vragen.php',
        'app/concurrenten.php',
        'app/meer.php',
        'app/bedrijf.php',
        'app/abonnement.php',
        'app/wijzigingen.php',
        'app/account.php',
        'app/assets/app.css',
        'app/assets/app.js',
    ] as $rel) {
        assert_true(is_file($root . '/' . $rel), 'missing ' . $rel);
    }
});

test('exact Dutch UI strings present in sources', static function (): void {
    $root = dirname(__DIR__);
    $blob = '';
    foreach (glob($root . '/app/*.php') ?: [] as $file) {
        $blob .= (string) file_get_contents($file);
    }
    $blob .= (string) file_get_contents($root . '/app/assets/app.js');
    $needles = [
        'Naar inhoud',
        'Abonnement aanvragen',
        'Alles openklappen',
        'Alles inklappen',
        'Controleer of dit klopt',
        'Er staan nog geen vragen. Na je scan zie je hier welke vragen ik AI-assistenten stelde.',
        'Je trend begint hierna opnieuw',
        'Ja, wijzig de vragen',
        'Annuleren',
        'Hiermee verwijder je je account, je uitslagen en je instellingen. Dit kan niet ongedaan worden. Typ je website om te bevestigen.',
        'AI noemde bij je vragen geen andere bedrijven. Ken je zelf een concurrent? Voeg hem toe.',
        'Hoe ik meet',
        'Opzeggen',
        'Wijzig',
        'app_locked_line()',
        'AI_SCAN_TIER_REQUEST_MESSAGE',
    ];
    foreach ($needles as $n) {
        assert_true(str_contains($blob, $n), 'missing string: ' . $n);
    }
    // Locked + flash copy come from Brief 01 constants (exact Dutch).
    assert_same(
        'Aanvraag ontvangen, ik pas je abonnement aan.',
        AI_SCAN_TIER_REQUEST_MESSAGE
    );
    assert_same(
        'Zit in het abonnement: elke week een nieuwe meting.',
        AI_SCAN_FREE_WRITE_MESSAGE
    );
    assert_same(AI_SCAN_FREE_WRITE_MESSAGE, app_locked_line());
});

test('overzicht free: score, one action, locked line, upgrade CTA, nav a11y', static function (): void {
    [$config, $pdo, $freeId] = app_ui_seed_accounts();
    $html = app_ui_render($config, $freeId, dirname(__DIR__) . '/app/overzicht.php');
    assert_true(str_contains($html, 'aria-label="Hoofdmenu"'), 'nav label');
    assert_true(str_contains($html, 'aria-current="page"'), 'aria-current');
    assert_true(str_contains($html, 'Naar inhoud'), 'skip link');
    assert_true(str_contains($html, '12 van 50'), 'score');
    assert_true(str_contains($html, 'Zit in het abonnement: elke week een nieuwe meting.'), 'locked');
    assert_true(str_contains($html, 'Abonnement aanvragen'), 'upgrade CTA');
    assert_true(str_contains($html, 'action-card'), 'action card');
    assert_true(substr_count($html, 'class="action-card"') === 1, 'free sees 1 example action');
    assert_true(str_contains($html, 'href="/app/abonnement.php"'), 'abonnement text link');
    assert_true(str_contains($html, 'Hoe ik meet'), 'details');
    assert_true(str_contains($html, 'app.css'), 'stylesheet');
});

test('overzicht paid: trend visible, multiple actions, no free upgrade CTA on overzicht', static function (): void {
    [$config, $pdo, $freeId, $paidId] = app_ui_seed_accounts();
    $html = app_ui_render($config, $paidId, dirname(__DIR__) . '/app/overzicht.php');
    assert_true(str_contains($html, 'W40'), 'trend week');
    assert_true(substr_count($html, 'class="action-card"') >= 2, 'paid sees more than one action on overzicht list source');
    // Paid header should not show the free-only Abonnement text link in top (sidebar still has it).
    assert_true(!str_contains($html, 'class="app-top__sub-link"'), 'no free top link');
});

test('acties: aria-expanded, toolbar, usage line from tier', static function (): void {
    [$config, $pdo, $freeId, $paidId] = app_ui_seed_accounts();
    $tiers = ai_scan_load_tiers();
    $user = $pdo->query('SELECT * FROM users WHERE id = ' . (int) $paidId)->fetch();
    $eff = ai_scan_effective_tier($user, $tiers);
    $html = app_ui_render($config, $paidId, dirname(__DIR__) . '/app/acties.php');
    assert_true(str_contains($html, 'aria-expanded='), 'aria-expanded');
    assert_true(str_contains($html, 'Alles openklappen'), 'toolbar');
    assert_true(str_contains($html, 'van ' . (int) $eff['actions'] . ' deze maand'), 'actions limit from tier');
    assert_true(str_contains($html, 'data-action-toggle'), 'toggle hook');
});

test('vragen: count line and version-break dialog copy', static function (): void {
    [$config, $pdo, $freeId, $paidId] = app_ui_seed_accounts();
    $html = app_ui_render($config, $paidId, dirname(__DIR__) . '/app/vragen.php');
    assert_true(str_contains($html, 'van 100'), 'tier questions');
    assert_true(str_contains($html, 'Je trend begint hierna opnieuw'), 'dialog title');
    assert_true(str_contains($html, 'role="alertdialog"'), 'alertdialog');
    assert_true(str_contains($html, 'Ja, wijzig de vragen'), 'confirm');
    $freeHtml = app_ui_render($config, $freeId, dirname(__DIR__) . '/app/vragen.php');
    assert_true(str_contains($freeHtml, 'Zit in het abonnement: elke week een nieuwe meting.'), 'free locked');
});

test('abonnement free request form posts to request.php; paid has Wijzig and Opzeggen equal', static function (): void {
    [$config, $pdo, $freeId, $paidId] = app_ui_seed_accounts();
    $freeHtml = app_ui_render($config, $freeId, dirname(__DIR__) . '/app/abonnement.php');
    assert_true(str_contains($freeHtml, 'action="/app/request.php"'), 'request endpoint');
    assert_true(str_contains($freeHtml, 'Abonnement aanvragen'), 'request button');
    assert_true(str_contains($freeHtml, 'name="tier_id"'), 'tier select');

    $paidHtml = app_ui_render($config, $paidId, dirname(__DIR__) . '/app/abonnement.php');
    assert_true(str_contains($paidHtml, '>Wijzig<'), 'wijzig');
    assert_true(str_contains($paidHtml, '>Opzeggen<'), 'opzeggen');
    assert_true(str_contains($paidHtml, 'kind" value="cancel"') || str_contains($paidHtml, 'name="kind" value="cancel"'), 'cancel kind');
});

test('flash message from session shown on abonnement', static function (): void {
    [$config, $pdo, $freeId] = app_ui_seed_accounts();
    $html = app_ui_render(
        $config,
        $freeId,
        dirname(__DIR__) . '/app/abonnement.php',
        [],
        [],
        'GET',
        AI_SCAN_TIER_REQUEST_MESSAGE
    );
    assert_true(str_contains($html, 'Aanvraag ontvangen, ik pas je abonnement aan.'), 'flash visible');
});

test('pending request line after subscription_events request', static function (): void {
    [$config, $pdo, $freeId] = app_ui_seed_accounts();
    ai_scan_subscription_event($pdo, $freeId, 'request', 'free', 'q100', 'owner', 'upgrade', time());
    $html = app_ui_render($config, $freeId, dirname(__DIR__) . '/app/abonnement.php');
    assert_true(str_contains($html, 'Openstaande aanvraag'), 'pending line');
    assert_true(str_contains($html, 'q100'), 'requested tier');
});

test('concurrenten free top 3 + locked; CSS has focus ring and reduced motion', static function (): void {
    [$config, $pdo, $freeId] = app_ui_seed_accounts();
    $html = app_ui_render($config, $freeId, dirname(__DIR__) . '/app/concurrenten.php');
    assert_true(str_contains($html, 'STYQX'), 'competitor');
    assert_true(str_contains($html, 'Zit in het abonnement: elke week een nieuwe meting.'), 'locked');
    $css = (string) file_get_contents(dirname(__DIR__) . '/app/assets/app.css');
    assert_true(str_contains($css, 'outline: 2px solid'), 'focus ring');
    assert_true(str_contains($css, 'prefers-reduced-motion'), 'reduced motion');
    assert_true(str_contains($css, '#0b6bcb'), 'accent');
    assert_true(str_contains($css, '232px'), 'sidebar width');
    assert_true(str_contains($css, '64px'), 'bottom nav height');
});

test('meer page links and account delete copy', static function (): void {
    [$config, $pdo, $freeId] = app_ui_seed_accounts();
    $meer = app_ui_render($config, $freeId, dirname(__DIR__) . '/app/meer.php');
    assert_true(str_contains($meer, '/app/bedrijf.php'));
    assert_true(str_contains($meer, '/app/abonnement.php'));
    assert_true(str_contains($meer, '/app/wijzigingen.php'));
    assert_true(str_contains($meer, '/app/account.php'));
    assert_true(str_contains($meer, '/logout.php'));
    $acc = app_ui_render($config, $freeId, dirname(__DIR__) . '/app/account.php');
    assert_true(str_contains($acc, 'Hiermee verwijder je je account, je uitslagen en je instellingen. Dit kan niet ongedaan worden. Typ je website om te bevestigen.'));
});

test('dashboard entry redirects HTML to app overzicht and keeps JSON branch', static function (): void {
    $src = (string) file_get_contents(dirname(__DIR__) . '/dashboard/index.php');
    assert_true(str_contains($src, "ai_scan_redirect('/app/overzicht.php'"), 'html redirect');
    assert_true(str_contains($src, "\$_GET['format']") && str_contains($src, "'json'"), 'json branch');
});

test('no hardcoded tier amounts in app PHP', static function (): void {
    $root = dirname(__DIR__) . '/app';
    foreach (glob($root . '/*.php') ?: [] as $file) {
        $src = (string) file_get_contents($file);
        // Allow comments / strings that are not limits; forbid classic staffel literals near questions/actions.
        assert_true(!preg_match('/\b(questions|actions|monthly)\s*[=:]\s*(25|50|75|100|3|4|5|6)\b/', $src), basename($file));
    }
});

test('bedrijf: free accounts can edit, prefilled from scan profile', static function (): void {
    [, $pdo] = boot_test_db();
    $result = ['company' => 'Voorbeeld BV', 'services' => ['plinten', 'vloeren'], 'locations' => ['Haarlem'],
        'brand_aliases' => ['Voorbeeld'], 'audience' => 'huiseigenaren'];
    $p = app_load_profile($pdo, 999, ['domain' => 'voorbeeld.nl'], $result);
    assert_same('Voorbeeld BV', $p['company']);
    assert_same(['plinten', 'vloeren'], $p['services']);
    assert_same(['Haarlem'], $p['places']);
    assert_same('huiseigenaren', $p['audience']);
    assert_same('voorbeeld.nl', $p['website']);
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/bedrijf.php');
    assert_true(str_contains($src, "|| \$isFree;"), 'free accounts must be able to save bedrijf');
    assert_true(!str_contains($src, 'Wijzigen kan niet'));
});
