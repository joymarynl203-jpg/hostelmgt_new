<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function hms_mail_configured(): bool
{
    if (HMS_MAIL_TRANSPORT === 'php_mail') {
        return true;
    }
    return HMS_MAIL_TRANSPORT === 'smtp' && HMS_SMTP_HOST !== '';
}

function hms_send_mail(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): bool
{
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (HMS_MAIL_TRANSPORT === 'php_mail') {
        return hms_send_mail_php($toEmail, $subject, $textBody, $htmlBody);
    }

    if (HMS_SMTP_HOST === '') {
        return false;
    }

    return hms_send_mail_smtp($toEmail, $subject, $textBody, $htmlBody);
}

function hms_send_mail_php(string $toEmail, string $subject, string $textBody, ?string $htmlBody): bool
{
    $from = HMS_MAIL_FROM;
    $fromName = HMS_MAIL_FROM_NAME;
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $boundary = 'bnd_' . bin2hex(random_bytes(12));

    if ($htmlBody !== null && $htmlBody !== '') {
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $textBody . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . "--{$boundary}--\r\n";
        $headers = "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    } else {
        $body = $textBody;
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    }

    $headers .= 'From: ' . hms_mail_encode_from($fromName, $from) . "\r\n";
    $headers .= 'X-Mailer: HMS';

    return @mail($toEmail, $encodedSubject, $body, $headers);
}

function hms_mail_encode_from(string $name, string $email): string
{
    $name = trim($name);
    if ($name === '' || preg_match('/[\r\n]/', $name)) {
        return $email;
    }
    return '=?UTF-8?B?' . base64_encode($name) . "?= <{$email}>";
}

/** @return resource|false */
function hms_smtp_stream_connect(string $remote, ?int &$errno = null, ?string &$errstr = null)
{
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'      => HMS_SMTP_VERIFY_PEER,
            'verify_peer_name' => HMS_SMTP_VERIFY_PEER,
            'allow_self_signed'=> !HMS_SMTP_VERIFY_PEER,
        ],
    ]);

    $errno = 0;
    $errstr = '';

    return @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
}

/**
 * @param resource $fp
 * @return list<string>
 */
function hms_smtp_recv_lines($fp): array
{
    $lines = [];
    while (true) {
        $line = fgets($fp, 8192);
        if ($line === false) {
            break;
        }
        $lines[] = $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $lines;
}

/**
 * @param resource $fp
 */
function hms_smtp_expect($fp, int $code): bool
{
    $lines = hms_smtp_recv_lines($fp);
    if ($lines === []) {
        return false;
    }
    $last = $lines[count($lines) - 1];

    return strlen($last) >= 3 && (int) substr($last, 0, 3) === $code;
}

/**
 * @param resource $fp
 */
function hms_smtp_cmd($fp, string $command, int $expectCode): bool
{
    fwrite($fp, $command . "\r\n");

    return hms_smtp_expect($fp, $expectCode);
}

function hms_smtp_dot_stuff(string $payload): string
{
    $norm = str_replace(["\r\n", "\r"], "\n", $payload);
    $lines = explode("\n", $norm);
    $out = [];
    foreach ($lines as $line) {
        if ($line !== '' && $line[0] === '.') {
            $out[] = '.' . $line;
        } else {
            $out[] = $line;
        }
    }

    return implode("\r\n", $out);
}

function hms_send_mail_smtp(string $toEmail, string $subject, string $textBody, ?string $htmlBody): bool
{
    $host = HMS_SMTP_HOST;
    $port = (int) HMS_SMTP_PORT;
    $enc = strtolower(HMS_SMTP_ENCRYPTION);
    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';

    $remote = $enc === 'ssl'
        ? 'ssl://' . $host . ':' . $port
        : 'tcp://' . $host . ':' . $port;

    $errno = 0;
    $errstr = '';
    $fp = hms_smtp_stream_connect($remote, $errno, $errstr);
    if ($fp === false) {
        error_log('HMS SMTP connect failed: ' . $errstr . " ({$errno})");

        return false;
    }
    stream_set_timeout($fp, 30);

    if (!hms_smtp_expect($fp, 220)) {
        fclose($fp);

        return false;
    }

    if (!hms_smtp_cmd($fp, 'EHLO ' . $ehloHost, 250)) {
        fclose($fp);

        return false;
    }

    if ($enc === 'tls') {
        if (!hms_smtp_cmd($fp, 'STARTTLS', 220)) {
            fclose($fp);

            return false;
        }
        $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoOk !== true) {
            error_log('HMS SMTP STARTTLS handshake failed');
            fclose($fp);

            return false;
        }
        if (!hms_smtp_cmd($fp, 'EHLO ' . $ehloHost, 250)) {
            fclose($fp);

            return false;
        }
    }

    $user = HMS_SMTP_USER;
    $pass = HMS_SMTP_PASS;
    if ($user !== '') {
        if (!hms_smtp_cmd($fp, 'AUTH LOGIN', 334)) {
            fclose($fp);

            return false;
        }
        if (!hms_smtp_cmd($fp, base64_encode($user), 334)) {
            fclose($fp);

            return false;
        }
        if (!hms_smtp_cmd($fp, base64_encode($pass), 235)) {
            error_log('HMS SMTP authentication failed');
            fclose($fp);

            return false;
        }
    }

    $from = HMS_MAIL_FROM;
    if (!hms_smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', 250)) {
        fclose($fp);

        return false;
    }
    if (!hms_smtp_cmd($fp, 'RCPT TO:<' . $toEmail . '>', 250)) {
        fclose($fp);

        return false;
    }
    if (!hms_smtp_cmd($fp, 'DATA', 354)) {
        fclose($fp);

        return false;
    }

    $boundary = 'bnd_' . bin2hex(random_bytes(12));
    $headers = [];
    $headers[] = 'From: ' . hms_mail_encode_from(HMS_MAIL_FROM_NAME, $from);
    $headers[] = 'To: <' . $toEmail . '>';
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@hms>';

    if ($htmlBody !== null && $htmlBody !== '') {
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $mime = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $textBody . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . '--' . $boundary . "--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $mime = implode("\r\n", $headers) . "\r\n\r\n" . $textBody . "\r\n";
    }

    $payload = hms_smtp_dot_stuff($mime) . "\r\n.\r\n";
    fwrite($fp, $payload);
    if (!hms_smtp_expect($fp, 250)) {
        fclose($fp);

        return false;
    }

    hms_smtp_cmd($fp, 'QUIT', 221);
    fclose($fp);

    return true;
}
