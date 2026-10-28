<?php
/*
 * Include-once sentinel. See the note in includes/rental-service.php: a
 * function_exists() guard on this file's own functions is always true on a
 * cold include, so it is not a usable test.
 */
if (defined('CARVELLA_MAILER_LOADED')) {
    return;
}
define('CARVELLA_MAILER_LOADED', true);

require_once __DIR__ . '/../config/config.php';

/**
 * Strip CR, LF and other control characters out of a header value.
 *
 * A bare CR or LF in a header is header injection: it ends the header and
 * lets the caller append a Bcc: or an extra Content-Type of its own, which
 * turns a password-reset mail into mail delivered to an attacker. Every
 * value that reaches a header goes through this, including the ones the
 * application generated itself.
 */
function mailHeaderSafe($value) {
    $clean = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$value);
    return trim((string)$clean);
}

/**
 * Encode a header for transport (RFC 2047 base64), skipping plain ASCII.
 *
 * Subject lines and display names here carry an em dash and a peso sign, so
 * they cannot go out as raw 8-bit bytes to a server that has not negotiated
 * 8BITMIME.
 */
function mailHeaderEncode($value) {
    $value = (string)$value;
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/** "Name <addr>", with the name encoded only when it needs to be. */
function mailAddressHeader($name, $address) {
    $name = mailHeaderEncode(mailHeaderSafe($name));
    return $name === '' ? ('<' . mailHeaderSafe($address) . '>') : ($name . ' <' . mailHeaderSafe($address) . '>');
}

/** A usable plain-text alternative when the caller has not supplied one. */
function mailHtmlToText($html) {
    $text = preg_replace('#<(head|script|style)\b[^>]*>.*?</\1>#is', '', (string)$html);
    $text = preg_replace('#<br\s*/?>#i', "\n", $text);
    $text = preg_replace('#</(p|div|tr|h[1-6]|table|ul|ol)>#i', "\n", $text);
    $text = preg_replace('#<li\b[^>]*>#i', '  - ', $text);
    $text = preg_replace('#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>#i', '$2', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/ *\n */', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim((string)$text);
}

/**
 * True when there is enough configuration to attempt a send at all.
 *
 * A username and password are only required when the client is actually going
 * to authenticate. Requiring them unconditionally made the documented
 * passwordless local relay (CARVELLA_MAIL_ENCRYPTION=none, XAMPP's Mercury)
 * impossible to use, because an empty password read as "not configured" and
 * the send was refused before it reached the encryption check.
 */
function mailIsConfigured() {
    if (trim((string)MAIL_HOST) === '') {
        return false;
    }
    if (strtolower(trim((string)MAIL_ENCRYPTION)) === 'none') {
        // No credential will be sent, so none is required. sendMailMessage()
        // still refuses to authenticate in this mode if one is set.
        return true;
    }
    return trim((string)MAIL_USERNAME) !== ''
        && trim((string)MAIL_PASSWORD) !== '';
}

/**
 * Read one SMTP reply.
 *
 * A reply is a reply code, then either a space (last line) or a hyphen
 * (more lines follow), then text. Reading only one fgets() got this wrong
 * and then matched the 250 of a 3-line EHLO capability list against the 220
 * of the greeting, so the dialogue ran on without knowing what the server
 * had actually answered. Returns code null when the stream ended or the
 * bytes were not a reply at all.
 */
function smtpReadReply($conn) {
    $lines = [];
    $code = null;

    for ($i = 0; $i < 100; $i++) {
        $line = fgets($conn, 1024);
        if ($line === false) {
            break;
        }
        $line = rtrim($line, "\r\n");
        $lines[] = $line;

        if (strlen($line) < 3 || !ctype_digit(substr($line, 0, 3))) {
            $code = null;
            break;
        }
        $code = (int)substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    return ['code' => $code, 'text' => implode(' ', $lines)];
}

/**
 * Send one command and check the reply code.
 *
 * $command is null for a bare read. The stage string ends up in the returned
 * error and in error_log, which is the only place a delivery failure is
 * reported: the caller shows the user a generic message.
 */
function smtpCommand($conn, $command, array $expected, $stage) {
    if ($command !== null) {
        $written = @fwrite($conn, $command . "\r\n");
        if ($written === false || $written === 0) {
            return ['success' => false, 'error' => 'Lost the connection to the mail server while ' . $stage . '.'];
        }
    }

    $reply = smtpReadReply($conn);
    if ($reply['code'] === null) {
        return ['success' => false, 'error' => 'The mail server sent an unreadable reply while ' . $stage . '.'];
    }
    if (!in_array($reply['code'], $expected, true)) {
        return ['success' => false, 'error' => 'The mail server refused ' . $stage . ' (' . $reply['code'] . '): ' . trim($reply['text'])];
    }
    return ['success' => true, 'code' => $reply['code'], 'text' => $reply['text']];
}

/** Upgrade a live socket to TLS. Fails closed on anything but a handshake. */
function smtpEnableTls($conn, $stage) {
    $ok = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($ok !== true) {
        $last = error_get_last();
        return ['success' => false, 'error' => 'TLS negotiation with the mail server failed while ' . $stage
            . ($last && !empty($last['message']) ? ': ' . $last['message'] : '.')];
    }
    return ['success' => true];
}

/** Assemble the wire message. Base64 parts keep 8-bit HTML intact. */
function mailBuildMessage($to, $subject, $html, $text, $headers) {
    $boundary = 'carvella-' . bin2hex(random_bytes(16));
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)MAIL_HOST);

    // $headers is caller-supplied (subject prefix, urgency markers, ...) and
    // therefore untrusted: a CR/LF in any value would let it forge extra
    // headers on the wire. Strip line breaks from every value up front.
    $headers = array_map('mailHeaderSafe', $headers);

    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'From: ' . mailAddressHeader(MAIL_FROM_NAME, MAIL_FROM_ADDRESS);
    $headers[] = 'To: ' . mailAddressHeader('', $to);
    $headers[] = 'Subject: ' . mailHeaderEncode(mailHeaderSafe($subject));
    $headers[] = 'Reply-To: ' . mailAddressHeader(MAIL_FROM_NAME, MAIL_FROM_ADDRESS);
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($host !== '' ? $host : 'localhost') . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $body = "--" . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text), 76, "\r\n");

    $body .= "--" . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html), 76, "\r\n");

    $body .= "--" . $boundary . "--\r\n";

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/**
 * Send one mail over SMTP.
 *
 * Returns ['success' => bool, 'error' => string]. The error is for the log
 * and for a developer; it is never shown to a visitor, because its text
 * distinguishes "your address is not registered" from "the SMTP password is
 * wrong", which is exactly the enumeration this flow exists to avoid.
 *
 * $headers is an optional list of extra header lines. Anything in it is
 * treated as untrusted and passed through mailHeaderSafe().
 */
function sendMailMessage($to, $subject, $html, $text = null, array $headers = []) {
    $fail = static function ($message) {
        error_log('Carvella mail failed: ' . $message);
        return ['success' => false, 'error' => $message];
    };

    $to = trim((string)$to);
    $from = trim((string)MAIL_FROM_ADDRESS);
    $host = trim((string)MAIL_HOST);
    $username = trim((string)MAIL_USERNAME);
    $password = (string)MAIL_PASSWORD;
    $port = MAIL_PORT;
    $encryption = strtolower(trim((string)MAIL_ENCRYPTION));

    if (!mailIsConfigured()) {
        return $fail('Outgoing mail is not configured. Set MAIL_HOST, MAIL_USERNAME and MAIL_PASSWORD in config/config.php.');
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return $fail('Refusing to send to an invalid recipient address.');
    }
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return $fail('CARVELLA_MAIL_FROM_ADDRESS is not a valid email address.');
    }
    if ($port < 1 || $port > 65535) {
        return $fail('CARVELLA_MAIL_PORT is out of range.');
    }
    if ($host !== '' && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && !filter_var($host, FILTER_VALIDATE_IP)) {
        return $fail('CARVELLA_MAIL_HOST is not a valid hostname.');
    }

    /* Refuse to hand the credential to a server in the clear. Without this,
       a typo of CARVELLA_MAIL_ENCRYPTION=none would post the mailbox app
       password over an unencrypted socket. A no-encryption relay is still
       fine when no password is set, which is the local Mercury case. */
    if ($encryption === 'none' && $password !== '') {
        return $fail('Refusing to authenticate over an unencrypted connection. Set CARVELLA_MAIL_ENCRYPTION to tls or ssl when CARVELLA_MAIL_PASSWORD is set.');
    }

    $implicitTls = ($encryption === 'ssl' || $port === 465) && $encryption !== 'none';
    $timeout = MAIL_TIMEOUT;

    $context = stream_context_create([
        'ssl' => [
            'peer_name'          => $host,
            'verify_peer'        => true,
            'verify_peer_name'   => true,
            'allow_self_signed'  => false,
            'SNI_enabled'        => true,
        ],
    ]);

    $scheme = ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $conn = @stream_socket_client($scheme, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$conn) {
        return $fail('Could not connect to ' . $host . ':' . $port . ' - ' . ($errstr !== '' ? $errstr : 'error ' . $errno) . '.');
    }
    stream_set_timeout($conn, $timeout);

    $cleanup = static function () use ($conn) {
        @fwrite($conn, "QUIT\r\n");
        @fclose($conn);
    };

    $greeting = smtpCommand($conn, null, [220], 'reading the server greeting');
    if (!$greeting['success']) {
        $cleanup();
        return $fail($greeting['error']);
    }

    $helo = mailHeaderSafe(MAIL_HELO_NAME) !== '' ? mailHeaderSafe(MAIL_HELO_NAME) : 'localhost';
    $ehlo = smtpCommand($conn, 'EHLO ' . $helo, [250], 'introducing the client (EHLO)');
    if (!$ehlo['success']) {
        $cleanup();
        return $fail($ehlo['error']);
    }

    /* STARTTLS before AUTH, always. Port 587 opens in the clear by design,
       so the upgrade has to happen before the username and password are
       written to the socket. EHLO is then repeated because the capability
       list may only be re-read after the channel is secured. */
    if (!$implicitTls && $encryption !== 'none') {
        $starttls = smtpCommand($conn, 'STARTTLS', [220], 'starting TLS (STARTTLS)');
        if (!$starttls['success']) {
            $cleanup();
            return $fail($starttls['error']);
        }
        $tls = smtpEnableTls($conn, 'STARTTLS');
        if (!$tls['success']) {
            $cleanup();
            return $fail($tls['error']);
        }
        $ehlo = smtpCommand($conn, 'EHLO ' . $helo, [250], 're-introducing the client after TLS');
        if (!$ehlo['success']) {
            $cleanup();
            return $fail($ehlo['error']);
        }
    }

    /* AUTH LOGIN. Chosen over PLAIN because it is a challenge/response, so
       the password is not in the clear even if the server somehow answers on
       a channel we believed was encrypted.

       Only attempted when there is a password to send. An open local relay
       (Mercury) advertises no AUTH and needs none, and demanding it there
       refused every mail. When a password IS set this is unchanged: the
       server must offer AUTH, and the encryption check above has already
       guaranteed the channel is encrypted before a single byte goes out. */
    if ($password !== '') {
        if (!preg_match('/\bAUTH\b/i', $ehlo['text'])) {
            $cleanup();
            return $fail('The mail server does not offer AUTH, so this account cannot sign in to send.');
        }

        $auth = smtpCommand($conn, 'AUTH LOGIN', [334], 'authenticating (AUTH LOGIN)');
        if ($auth['success']) {
            $auth = smtpCommand($conn, base64_encode($username), [334], 'sending the SMTP username');
        }
        if ($auth['success']) {
            $auth = smtpCommand($conn, base64_encode($password), [235], 'sending the SMTP password');
        }
        if (!$auth['success']) {
            $cleanup();
            return $fail($auth['error']);
        }
    }

    $fromStep = smtpCommand($conn, 'MAIL FROM:<' . mailHeaderSafe($from) . '>', [250], 'setting the sender');
    if (!$fromStep['success']) {
        $cleanup();
        return $fail($fromStep['error']);
    }

    $rcptStep = smtpCommand($conn, 'RCPT TO:<' . mailHeaderSafe($to) . '>', [250, 251], 'setting the recipient');
    if (!$rcptStep['success']) {
        $cleanup();
        return $fail($rcptStep['error']);
    }

    $dataStep = smtpCommand($conn, 'DATA', [354], 'starting the message body (DATA)');
    if (!$dataStep['success']) {
        $cleanup();
        return $fail($dataStep['error']);
    }

    $plain = ($text === null || trim((string)$text) === '') ? mailHtmlToText($html) : (string)$text;
    $message = mailBuildMessage($to, $subject, $html, $plain, $headers);

    /* Dot-stuffing. A body line starting with a single dot would otherwise
       be read as the end-of-data terminator and truncate the message, so
       the leading dot is doubled. Only a dot at the start of a line counts;
       the /m anchor is what makes that distinction. */
    $message = preg_replace('/^\./m', '..', $message);

    if (@fwrite($conn, $message . "\r\n.\r\n") === false) {
        $cleanup();
        return $fail('Lost the connection to the mail server while sending the message body.');
    }

    $sent = smtpCommand($conn, null, [250], 'confirming delivery');
    $cleanup();

    if (!$sent['success']) {
        return $fail($sent['error']);
    }
    return ['success' => true, 'error' => ''];
}
