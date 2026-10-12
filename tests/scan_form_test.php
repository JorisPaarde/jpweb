<?php

declare(strict_types=1);

test('scan form fetches csrf and reads payload.code', static function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/script.js');
    assert_true(str_contains($js, '/ai-scan.php?action=csrf'));
    assert_true(str_contains($js, 'data.set("csrf"'));
    assert_true(str_contains($js, 'payload?.code'));
    assert_true(str_contains($js, 'rate_limited:'));
});

test('mail error cause is logged without addresses or long tokens', static function (): void {
    $c = ai_scan_mail_error_cause("SMTP unexpected reply: 550 user a@b.nl rejected\r\nQUJDREVGR0hJSktMTU5PUFFSU1RVVldY");
    assert_true(!str_contains($c, 'a@b.nl'));
    assert_true(!str_contains($c, 'QUJDREVG'));
    assert_true(str_contains($c, '550'));
    assert_true(!str_contains($c, "\n"));
});
