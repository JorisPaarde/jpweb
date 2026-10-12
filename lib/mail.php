<?php

declare(strict_types=1);

/**
 * Minimal SMTP client (SSL on port 465) for transactional AI-scan mail.
 * Never sends passwords. Bodies are Dutch, "Hoi," / "ik" / "Groet,".
 */

/**
 * @param array<string, mixed> $config
 * @return array{ok: bool, error?: string}
 */
function ai_scan_send_mail(array $config, string $to, string $subject, string $body): array
{
    $smtp = $config['smtp'] ?? [];
    if (!is_array($smtp)) {
        return ['ok' => false, 'error' => 'smtp_missing'];
    }

    $host = (string) ($smtp['host'] ?? '');
    $port = (int) ($smtp['port'] ?? 465);
    $username = (string) ($smtp['username'] ?? '');
    $password = (string) ($smtp['password'] ?? '');
    $fromEmail = (string) ($smtp['from_email'] ?? $username);
    $fromName = (string) ($smtp['from_name'] ?? 'JP Web Creation');

    if ($host === '' || $username === '' || $password === '') {
        if (getenv('JPWEB_AI_SCAN_MAIL_DRY_RUN') === '1') {
            $log = getenv('JPWEB_AI_SCAN_MAIL_LOG');
            if (is_string($log) && $log !== '') {
                file_put_contents(
                    $log,
                    json_encode([
                        'to' => $to,
                        'subject' => $subject,
                        'body' => $body,
                        'at' => time(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                    FILE_APPEND
                );
            }

            return ['ok' => true];
        }

        return ['ok' => false, 'error' => 'smtp_not_configured'];
    }

    if (preg_match('/[\r\n<>,;\s]/', $to . $fromEmail) === 1 || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_recipient'];
    }

    $remote = 'ssl://' . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if ($fp === false) {
        return ['ok' => false, 'error' => 'connect: ' . $errstr];
    }

    stream_set_timeout($fp, 20);

    try {
        ai_scan_smtp_expect($fp, [220]);
        ai_scan_smtp_cmd($fp, 'EHLO jpwebcreation.nl', [250]);
        ai_scan_smtp_cmd($fp, 'AUTH LOGIN', [334]);
        ai_scan_smtp_cmd($fp, base64_encode($username), [334]);
        ai_scan_smtp_cmd($fp, base64_encode($password), [235]);
        ai_scan_smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        ai_scan_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        ai_scan_smtp_cmd($fp, 'DATA', [354]);

        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : $subject;

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . ai_scan_smtp_encode_address($fromName, $fromEmail),
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@jpwebcreation.nl>',
        ];

        $safeBody = str_replace(["\r\n", "\r"], "\n", $body);
        $safeBody = preg_replace('/^\./m', '..', $safeBody) ?? $safeBody;
        $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $safeBody) . "\r\n.";
        ai_scan_smtp_cmd($fp, $data, [250]);
        ai_scan_smtp_cmd($fp, 'QUIT', [221]);
    } catch (Throwable $e) {
        fclose($fp);

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    fclose($fp);

    return ['ok' => true];
}

/**
 * @param resource $fp
 * @param list<int> $okCodes
 */
function ai_scan_smtp_expect($fp, array $okCodes): string
{
    $response = '';
    while (($line = fgets($fp, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $okCodes, true)) {
        throw new RuntimeException('SMTP unexpected reply: ' . trim($response));
    }

    return $response;
}

/**
 * @param resource $fp
 * @param list<int> $okCodes
 */
function ai_scan_smtp_cmd($fp, string $command, array $okCodes): string
{
    fwrite($fp, $command . "\r\n");

    return ai_scan_smtp_expect($fp, $okCodes);
}

function ai_scan_smtp_encode_address(string $name, string $email): string
{
    $safeName = trim(str_replace(["\r", "\n"], '', $name));
    if ($safeName === '') {
        return '<' . $email . '>';
    }

    if (function_exists('mb_encode_mimeheader')) {
        $safeName = mb_encode_mimeheader($safeName, 'UTF-8', 'B', "\r\n");
    }

    return $safeName . ' <' . $email . '>';
}

function ai_scan_mail_confirm(array $config, string $to, string $website, string $confirmUrl): array
{
    $subject = 'Bevestig je e-mailadres voor de AI-scan';
    $body = implode("\n", [
        'Hoi,',
        '',
        'Je vroeg een gratis AI-vindbaarheidsscan aan voor ' . $website . '.',
        '',
        'Bevestig eerst je e-mailadres, dan maak ik je account aan en start de scan:',
        $confirmUrl,
        '',
        'De link werkt 24 uur. Heb jij dit niet aangevraagd? Dan hoef je niets te doen; de aanvraag wordt na 7 dagen vanzelf verwijderd.',
        '',
        'Groet,',
    ]);

    return ai_scan_send_mail($config, $to, $subject, $body);
}

function ai_scan_mail_welcome(array $config, string $to, string $website, string $passwordUrl, string $dashboardUrl): array
{
    $subject = 'Je account voor de AI-scan staat klaar';
    $body = implode("\n", [
        'Hoi,',
        '',
        'Je e-mailadres is bevestigd. Je account staat klaar.',
        '',
        'Inloggen doe je met dit e-mailadres. Kies eerst een wachtwoord via deze link (24 uur geldig):',
        $passwordUrl,
        '',
        'Je dashboard: ' . $dashboardUrl,
        '',
        'De scan voor ' . $website . ' is gestart. Ik mail je als de uitslag klaar is.',
        '',
        'Groet,',
    ]);

    return ai_scan_send_mail($config, $to, $subject, $body);
}

function ai_scan_mail_stale_alert(array $config, int $staleCount, array $sampleIds): array
{
    $to = (string) (($config['smtp']['alert_to'] ?? '') ?: ($config['smtp']['from_email'] ?? 'info@jpwebcreation.nl'));
    $minutes = max(1, (int) round(((int) ($config['stale_job_seconds'] ?? 1800)) / 60));
    $subject = 'AI-scan: job wacht langer dan ' . $minutes . ' minuten';
    $ids = $sampleIds === [] ? '-' : implode(', ', $sampleIds);
    $body = implode("\n", [
        'Hoi,',
        '',
        'Er staan ' . $staleCount . ' AI-scan job(s) langer dan ' . $minutes . ' minuten te wachten.',
        'Voorbeelden: ' . $ids,
        '',
        'Groet,',
    ]);

    return ai_scan_send_mail($config, $to, $subject, $body);
}
