<?php

declare(strict_types=1);

/**
 * Wachtwoord vergeten: limiet per IP+adres, meerdere herstellinks tegelijk, identieke melding.
 */

/** Rendert login/vergeten/ als POST vanaf een IP; geeft de HTML met het csrf-token eruit gehaald. */
function reset_page_post(array $config, string $email, string $ip): string
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_GET = [];
    $_POST = ['csrf' => ai_scan_csrf_token($config), 'email' => $email];
    $cfgPath = sys_get_temp_dir() . '/jpweb-reset-config-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($cfgPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    putenv('JPWEB_AI_SCAN_CONFIG=' . $cfgPath);
    ob_start();
    try {
        include dirname(__DIR__) . '/login/vergeten/index.php';
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    } finally {
        @unlink($cfgPath);
    }
    $html = (string) ob_get_clean();

    return (string) preg_replace('/name="csrf" value="[^"]*"/', 'name="csrf" value="X"', $html);
}

function reset_link_token(string $mailLog, int $index): string
{
    $mails = geo_mails($mailLog);
    assert_true(preg_match('#\?t=([A-Za-z0-9_-]+)#', (string) $mails[$index]['body'], $m) === 1, 'mail bevat link');

    return $m[1];
}

test('reset: een ander IP kan het herstel van een adres niet blokkeren', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);

    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now));
    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.2', $now + 5));
    assert_same(2, count(geo_mails($mailLog)));
});

test('reset: zelfde IP en adres binnen 600 seconden stuurt geen tweede mail', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);

    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now));
    assert_same('rate_limited', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now + 599));
    assert_same(1, count(geo_mails($mailLog)));
    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now + 601));
    assert_same(2, count(geo_mails($mailLog)));
});

test('reset: de IP-limiet per uur blijft werken', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    $config['reset_ip_max_per_hour'] = 3;
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = ai_scan_request_password_reset($pdo, $config, 'a' . $i . '@firma.nl', '9.9.9.3', $now + $i);
    }
    assert_same(['no_account', 'no_account', 'no_account', 'rate_limited', 'rate_limited'], $results);
});

test('reset: pagina is gelijk voor bestaand, onbekend en gelimiteerd adres', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);

    $existing = reset_page_post($config, 'klant@firma.nl', '9.9.9.4');
    $unknown = reset_page_post($config, 'niemand@firma.nl', '9.9.9.5');
    $limited = reset_page_post($config, 'klant@firma.nl', '9.9.9.4');
    assert_same(1, count(geo_mails($mailLog)), 'gelimiteerde aanvraag stuurt geen mail');
    assert_true(str_contains($existing, 'Als er een account bestaat voor dit e-mailadres'));
    assert_same($existing, $unknown, 'onbekend gelijk aan bestaand');
    assert_same($existing, $limited, 'gelimiteerd gelijk aan bestaand');
});

test('reset: tweede aanvraag laat de eerste link geldig', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);

    ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now);
    ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.2', $now + 5);
    $first = reset_link_token($mailLog, 0);
    $second = reset_link_token($mailLog, 1);
    assert_same('ok', ai_scan_password_token_state($pdo, $first, $now + 10), 'eerste link geldig');
    assert_same('ok', ai_scan_password_token_state($pdo, $second, $now + 10), 'tweede link geldig');
    assert_same('expired', ai_scan_password_token_state($pdo, $first, $now + 3601), 'verloopt na een uur');
});

test('reset: bij 3 geldige links geen nieuwe mail en blijft geen enkele link verwijderd', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 0;
    $config['reset_ip_max_per_hour'] = 0;
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);
    $results = [];
    for ($i = 0; $i < 4; $i++) {
        $results[] = ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now + $i);
    }
    assert_same(['sent', 'sent', 'sent', 'rate_limited'], $results);
    assert_same(3, count(geo_mails($mailLog)), 'vierde aanvraag stuurt geen mail');
    foreach ([0, 1, 2] as $i) {
        assert_same('ok', ai_scan_password_token_state($pdo, reset_link_token($mailLog, $i), $now + 10), 'link ' . $i . ' blijft geldig');
    }
    // Na verloop zijn weer nieuwe aanvragen mogelijk.
    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now + 3700));
});

test('reset: gelimiteerde uitkomst is gelijk voor bestaand en onbekend adres', static function (): void {
    [$config, $pdo] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '5.5.5.1', $now));
    assert_same('no_account', ai_scan_request_password_reset($pdo, $config, 'niemand@firma.nl', '5.5.5.1', $now));
    $existing = ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '5.5.5.1', $now + 5);
    $unknown = ai_scan_request_password_reset($pdo, $config, 'niemand@firma.nl', '5.5.5.1', $now + 5);
    assert_same('rate_limited', $existing);
    assert_same($existing, $unknown, 'zelfde uitkomst voor bestaand en onbekend adres');
});

test('reset: na wachtwoord zetten zijn alle herstellinks ongeldig', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 600;
    $now = time();
    geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    $other = geo_user_with_password($pdo, 'ander@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);
    ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now);
    ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.2', $now + 1);
    ai_scan_request_password_reset($pdo, $config, 'ander@firma.nl', '9.9.9.2', $now + 1);
    $first = reset_link_token($mailLog, 0);
    $second = reset_link_token($mailLog, 1);
    $otherLink = reset_link_token($mailLog, 2);

    assert_true(ai_scan_set_password($pdo, $first, 'nieuw-wachtwoord-456', $now + 10)['ok']);
    assert_same('invalid_token', ai_scan_password_token_state($pdo, $second, $now + 11), 'tweede link vervallen');
    assert_true(!ai_scan_set_password($pdo, $first, 'weer-een-ander-1', $now + 12)['ok'], 'eenmalig');
    assert_true(!ai_scan_set_password($pdo, $second, 'weer-een-ander-1', $now + 12)['ok']);
    assert_same('ok', ai_scan_password_token_state($pdo, $otherLink, $now + 11), 'ander account blijft geldig');
});

test('reset: na wachtwoord- of adreswijziging tellen onbruikbare links niet mee voor de limiet', static function (): void {
    [$config, $pdo, , $mailLog] = boot_test_db();
    $config['reset_email_seconds'] = 0;
    $config['reset_ip_max_per_hour'] = 0;
    $now = time();
    $uid = geo_user_with_password($pdo, 'klant@firma.nl', 'oud-wachtwoord-123');
    @unlink($mailLog);
    for ($i = 0; $i < 3; $i++) {
        assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now + $i));
    }
    // Wachtwoord gewijzigd in het account: de drie links werken niet meer.
    $pdo->prepare('UPDATE users SET password_version = password_version + 1 WHERE id = :id')->execute(['id' => $uid]);
    assert_same('sent', ai_scan_request_password_reset($pdo, $config, 'klant@firma.nl', '9.9.9.1', $now + 10));
    assert_same(4, count(geo_mails($mailLog)), 'eigenaar krijgt weer een werkende link');
    assert_same('ok', ai_scan_password_token_state($pdo, reset_link_token($mailLog, 3), $now + 11));
});
