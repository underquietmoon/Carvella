<?php
/** Configuration: every setting reads getenv() first and falls back to the XAMPP default. */

/* Time zone: "today" means Philippine time; php.ini's Europe/Berlin default ran the app 6 hours behind. */
$carvellaTimezone = getenv('CARVELLA_TIMEZONE') ?: 'Asia/Manila';
if (!in_array($carvellaTimezone, timezone_identifiers_list(), true)) {
    $carvellaTimezone = 'Asia/Manila';
}
date_default_timezone_set($carvellaTimezone);

/* Runtime hardening in code, not shared php.ini: display_errors off keeps SQL and paths out of the response. */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* Response headers: one call site covers the whole app because every page reaches this file first. */
if (!headers_sent()) {
    /* Clickjacking: DENY rather than SAMEORIGIN because nothing in this app is meant to be framed. */
    header('X-Frame-Options: DENY');

    /* frame-ancestors covers cross-origin ancestors; no script-src is declared because pages use inline scripts. */
    header("Content-Security-Policy: frame-ancestors 'none'");

    /* nosniff stops the browser running user-uploaded vehicle images as script from the web root. */
    header('X-Content-Type-Options: nosniff');

    /* Referrer-Policy keeps the project path and the reset token out of cross-site requests. */
    header('Referrer-Policy: strict-origin-when-cross-origin');

    /* Enables the browser's cross-origin isolation checks (XSS Filter / CORP); harmless elsewhere. */
    header('X-XSS-Protection: 0');
}

/* Credentials: never rely on XAMPP's passwordless root; this file is only safe because PHP executes it. */
define('DB_HOST', getenv('CARVELLA_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('CARVELLA_DB_NAME') ?: 'carvella');
define('DB_USER', getenv('CARVELLA_DB_USER') ?: 'root');
define('DB_PASS', getenv('CARVELLA_DB_PASS') !== false ? getenv('CARVELLA_DB_PASS') : '');
define('DB_PORT', (int)(getenv('CARVELLA_DB_PORT') ?: 3307));
$docRoot = str_replace('\\', '/', rtrim((string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'));
$appRoot = str_replace('\\', '/', dirname(__DIR__));
$relative = '';
if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $relative = substr($appRoot, strlen($docRoot));
}
$segments = array_values(array_filter(explode('/', trim($relative, '/')), 'strlen'));
$urlPath = '/' . implode('/', array_map('rawurlencode', $segments));
if ($urlPath === '/') {
    $urlPath = '';
}
define('BASE_URL', (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http' : 'https') . '://' . appRequestHost() . $urlPath);
define('SITE_NAME', 'Carvella');
define('CURRENCY', '₱');

/* Single-hub operation: booking location is not a customer choice - createReservationRecord() overwrites it. */
define('CARVELLA_HUB', 'AB Fernandez Ave Central Hub, Dagupan City');

/* GCash deposit: one figure per booking, not per vehicle, split across the reservation rows by cost. */
define('CARVELLA_GCASH_NUMBER', getenv('CARVELLA_GCASH_NUMBER') ?: '09956327377');

/* No falsy check on the deposit: "0" is legitimate and would be silently turned back into the default. */
$carvellaDeposit = getenv('CARVELLA_GCASH_DEPOSIT_AMOUNT');
if ($carvellaDeposit === false || trim((string)$carvellaDeposit) === '') {
    $carvellaDeposit = 2000;
}
define('CARVELLA_GCASH_DEPOSIT_AMOUNT', max(0.0, (float)$carvellaDeposit));

/* SMTP: MAIL_PASSWORD is a Google App Password, and MAIL_FROM_ADDRESS must match MAIL_USERNAME. */
define('MAIL_HOST', getenv('CARVELLA_MAIL_HOST') ?: 'smtp.gmail.com');
define('MAIL_PORT', (int)(getenv('CARVELLA_MAIL_PORT') ?: 587));
define('MAIL_USERNAME', getenv('CARVELLA_MAIL_USERNAME') ?: '');
define('MAIL_PASSWORD', getenv('CARVELLA_MAIL_PASSWORD') !== false ? getenv('CARVELLA_MAIL_PASSWORD') : '');
/* 'tls' for STARTTLS on 587, 'ssl' for implicit TLS on 465; STARTTLS is the safe default. */
define('MAIL_ENCRYPTION', getenv('CARVELLA_MAIL_ENCRYPTION') ?: 'tls');
define('MAIL_FROM_ADDRESS', getenv('CARVELLA_MAIL_FROM_ADDRESS') ?: MAIL_USERNAME);
define('MAIL_FROM_NAME', getenv('CARVELLA_MAIL_FROM_NAME') ?: SITE_NAME);
/* EHLO name: some providers reject a bare localhost and log it, so it is configurable. */
define('MAIL_HELO_NAME', getenv('CARVELLA_MAIL_HELO_NAME') ?: 'localhost');
/* Whole-request budget so a hung SMTP server cannot hold a PHP worker open indefinitely. */
define('MAIL_TIMEOUT', max(5, (int)(getenv('CARVELLA_MAIL_TIMEOUT') ?: 15)));

/* Short TTL (bearer credential) and a per-account cooldown keep this endpoint from being a spam relay. */
define('PASSWORD_RESET_TTL_MINUTES', max(5, (int)(getenv('CARVELLA_PASSWORD_RESET_TTL_MINUTES') ?: 60)));
define('PASSWORD_RESET_COOLDOWN_SECONDS', max(0, (int)(getenv('CARVELLA_PASSWORD_RESET_COOLDOWN_SECONDS') ?: 60)));

/* HTTP_HOST is attacker-controlled: appSanitizeHost() only proves a host is well-formed, not that it is ours. */
function appRequestHost() {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    // Allowlist via CARVELLA_ALLOWED_HOSTS: a forged Host header turns every redirect() into an open redirect.
    $configured = [];
    $raw = getenv('CARVELLA_ALLOWED_HOSTS');
    if (is_string($raw) && $raw !== '') {
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $sanitised = appSanitizeHost($entry);
            if ($sanitised !== null) {
                $configured[] = strtolower($sanitised);
            }
        }
    }

    // Split "host:port" so a host and an allowlist entry compare by name alone, not as raw strings.
    $splitHost = static function ($value) {
        $value = appSanitizeHost($value);
        if ($value === null) {
            return null;
        }
        $name = $value;
        $port = null;
        if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[^:]+)(:(\d{1,5}))?$/', $value, $m)) {
            $name = $m[1];
            if (($m[3] ?? '') !== '') {
                $port = (int)$m[3];
            }
        }
        return [strtolower($name), $port];
    };

    $allowedNames = [];
    foreach ($configured as $entry) {
        $parts = $splitHost($entry);
        if ($parts !== null) {
            $allowedNames[] = $parts[0];
        }
    }

    if ($allowedNames) {
        $headerHost = $_SERVER['HTTP_HOST'] ?? '';
        if (is_string($headerHost) && $headerHost !== '') {
            $parts = $splitHost($headerHost);
            // Exact name match only: a suffix test would accept "evil.com.carvella.ph" for "carvella.ph".
            if ($parts !== null && in_array($parts[0], $allowedNames, true)) {
                return $resolved = $headerHost;
            }
        }
        return $resolved = $configured[0];
    }

    // No allowlist: trust SERVER_NAME over HTTP_HOST because it comes from the vhost config, not the request.
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    if (is_string($serverName) && $serverName !== '') {
        $host = appSanitizeHost($serverName);
        if ($host !== null) {
            $headerHost = $_SERVER['HTTP_HOST'] ?? '';
            if (is_string($headerHost) && $headerHost !== '') {
                $headerSanitised = appSanitizeHost($headerHost);
                if ($headerSanitised !== null && strtolower($headerSanitised) === strtolower($host)) {
                    // Port may legitimately differ between the two.
                    $resolved = $host;
                    return $resolved;
                }
            }
            return $resolved = $host;
        }
    }

    return $resolved = 'localhost';
}

/** Return a validated host[:port], or null when it is not trustworthy. */
function appSanitizeHost($host) {
    if (!is_string($host) || $host === '') {
        return null;
    }
    // IPv6 literal, optionally with a port.
    if (preg_match('/^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/', $host, $m)) {
        return isset($m[1]) && (int)substr($m[1], 1) > 65535 ? null : $host;
    }
    // hostname or IPv4, optionally with a port.
    //
    // Named groups, because the numbered ones are easy to misread: group 3 is
    // the repeated dot-label and group 5 is the port. This read $m[3] as the
    // port, so for "carvella.ph" it did (int)substr('.ph', 1) === 0, failed
    // the $port < 1 range check and rejected the host. Every dotted hostname
    // whose last label is not a number was therefore refused, and
    // appRequestHost() silently fell back to 'localhost' - meaning on any real
    // domain every link and redirect pointed at localhost. It only appeared
    // to work because XAMPP is served from localhost.
    if (preg_match(
        '/^(?<label>[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*(?::(?<port>\d{1,5}))?$/',
        $host,
        $m
    )) {
        $port = ($m['port'] ?? '') === '' ? null : (int)$m['port'];
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        return $host;
    }
    return null;
}
