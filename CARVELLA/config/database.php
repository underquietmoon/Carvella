<?php
/* config/database.php - MySQL connection bootstrap shared by every page. */
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    // Never surface the driver message: it discloses the host, socket and
    // database name to whoever triggered the failure.
    error_log('Carvella DB connection failed: ' . $conn->connect_error);
    if (PHP_SAPI === 'cli') {
        die("Connection failed: " . $conn->connect_error . "\n");
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 30');
    die('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Service Unavailable</title></head>'
        . '<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#fdfbff;color:#1d1b20">'
        . '<div style="text-align:center;padding:2rem;max-width:34rem">'
        . '<h1 style="font-size:1.5rem;margin:0 0 .5rem">We are having trouble loading Carvella</h1>'
        . '<p style="margin:0;opacity:.7">The rental service is temporarily unavailable. '
        . 'Please try again in a moment.</p></div></body></html>');
}

$conn->set_charset("utf8mb4");

/* Pin the session time zone to whatever PHP is already using.
 *
 * PHP defaults to Europe/Berlin, but MySQL's @@time_zone was SYSTEM, which on
 * this host resolves to a different offset - the two clocks were 6 hours apart,
 * so while PHP believed it was 2026-10-01 19:24, CURDATE() returned
 * 2026-10-02. Anything comparing a PHP-computed date against a MySQL-computed
 * one then disagreed by a day near midnight: bookings created "today" looked
 * like past dates, rentals due back tonight were marked overdue, and
 * availability windows opened or closed a day early.
 *
 * Taking the offset from PHP rather than hard-coding a zone keeps the two in
 * step automatically if date_default_timezone_set() ever changes, and using a
 * numeric offset avoids depending on MySQL's named-zone tables being loaded.
 * The value is regex-checked before interpolation. */
$phpTimezoneOffset = (new DateTime('now'))->format('P');
if (preg_match('/^[+-]\d{2}:\d{2}$/', $phpTimezoneOffset)) {
    if (!$conn->query("SET time_zone = '$phpTimezoneOffset'")) {
        error_log('Carvella time zone sync failed: ' . $conn->error);
    }
}

/* End of config/database.php */
