<?php
/*
 * Include-once sentinel. See the note in includes/rental-service.php: a
 * function_exists() guard on this file's own functions is always true on a
 * cold include, so it is not a usable test.
 */
if (defined('CARVELLA_PASSWORD_RESET_LOADED')) {
    return;
}
define('CARVELLA_PASSWORD_RESET_LOADED', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

/* ------------------------------------------------------------------
 * SCHEMA
 *
 * Three columns on users: reset_token_hash, reset_expires_at and
 * reset_requested_at.
 *
 * These used to be added on first use by this file, which kept an older
 * database working at the cost of an ALTER TABLE on the first password-reset
 * request of a deployment. That has been removed for the same reason as in
 * rental-service.php: the schema belongs to database/carvella.sql, which builds
 * a fresh install and upgrades an existing one, and PHP improvising it on a hot
 * path is how a database drifts out of sync with its own dump without anything
 * reporting it.
 *
 * Note also that the old comment here claimed the dump lived outside the web
 * root, at C:\xampp\carvella-schema\carvella.sql. It does not: the dump is at
 * database/carvella.sql, inside the web root, which is why database/.htaccess
 * now denies HTTP access to that directory.
 * ------------------------------------------------------------------ */

function passwordResetColumnExists($conn, $table, $column) {
    /* Bound into locals, not inline casts: bind_param() takes its arguments
       by reference, so `(string)$table` raises a fatal. */
    $name = (string)$table;
    $col = (string)$column;
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param("ss", $name, $col);
    $stmt->execute();
    $found = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();
    return $found;
}

/**
 * The reset columns exist. Read-only: reports what is missing and points at the
 * migration instead of altering the table.
 */
function passwordResetSchemaReady($conn = null) {
    static $gaps = null;
    if ($gaps !== null) {
        return $gaps === [];
    }
    if ($conn === null) {
        global $conn;
    }
    if (!($conn instanceof mysqli)) {
        return false;
    }
    $gaps = [];
    foreach (['reset_token_hash', 'reset_expires_at', 'reset_requested_at'] as $column) {
        if (!passwordResetColumnExists($conn, 'users', $column)) {
            $gaps[] = "users.$column";
        }
    }
    if ($gaps !== []) {
        error_log(
            'carvella: password reset schema is out of date, missing '
            . implode(', ', $gaps)
            . ' - run database/carvella.sql against this database'
        );
    }
    return $gaps === [];
}

/* ------------------------------------------------------------------
 * TOKENS
 * ------------------------------------------------------------------ */

/**
 * A 256-bit token, URL-safe.
 *
 * random_bytes() is the only acceptable source here: the token is a
 * single-use bearer credential that arrives by email, so anything derived
 * from a predictable input (uniqid(), mt_rand(), a counter) would let an
 * attacker who can guess the issuing time mint a valid link.
 */
function passwordResetGenerateToken() {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * The form the token is stored in.
 *
 * A hash, not the token. The token itself travels in a URL, so it lands in
 * mail server logs, browser history and any proxy's access log. Storing the
 * hash means reading the users table - a SQL injection or a stolen database
 * backup - does not hand out working reset links. SHA-256 is the right
 * primitive here rather than bcrypt: the input is 256 bits of CSPRNG output,
 * so there is no dictionary to slow down, and the reset path has to stay
 * fast enough to run inline on a request.
 */
function passwordResetHashToken($token) {
    return hash('sha256', (string)$token);
}

/**
 * Where the emailed (or on-screen) link points.
 *
 * One page handles the whole flow, so the link returns to
 * auth/forgot-password.php with the token in the query string: the visitor
 * arrives there and is shown the "choose a new password" form instead of the
 * email box.
 */
function passwordResetUrl($token) {
    return BASE_URL . '/auth/forgot-password.php?token=' . rawurlencode((string)$token);
}

/* ------------------------------------------------------------------
 * REQUEST THROTTLE
 *
 * Session-scoped, mirroring the login throttle's shape but with its own
 * counters: a locked-out login must not lock a password reset, and a
 * reset burst must not consume the login budget.
 *
 * Unlike the login throttle this is never cleared on success. A reset can
 * be requested for an address that does not exist, so a "count failures
 * only" rule would let a caller send real mail without ever tripping it -
 * the throttle has to count the requests that actually produce a message.
 *
 * It is a floor and not a ceiling. There is no shared store in this app
 * (no rate-limit table, and PHP sessions are per visitor), so a caller who
 * discards the cookie gets a fresh budget. What it does buy is the common
 * case - a person hammering the button, or a script that reuses a session
 * - on top of the per-address cooldown in passwordResetIssue(), which is
 * the half an attacker cannot side-step and which is what actually
 * protects a third party's inbox.
 * ------------------------------------------------------------------ */

function passwordResetIsThrottled() {
    if (!empty($_SESSION['password_reset_lock'])
        && $_SESSION['password_reset_lock'] > time()) {
        return $_SESSION['password_reset_lock'] - time();
    }
    return 0;
}

function passwordResetThrottleHit() {
    $bucket = $_SESSION['password_reset_attempts'] ?? ['n' => 0, 'first' => time()];
    if (time() - $bucket['first'] > PASSWORD_RESET_COOLDOWN_SECONDS * 5) {
        $bucket = ['n' => 0, 'first' => time()];
    }
    $bucket['n']++;
    $_SESSION['password_reset_attempts'] = $bucket;
    if ($bucket['n'] >= 3) {
        $_SESSION['password_reset_lock'] = time() + PASSWORD_RESET_COOLDOWN_SECONDS * 5;
        unset($_SESSION['password_reset_attempts']);
    }
}

/* ------------------------------------------------------------------
 * ISSUE / LOOKUP / CONSUME
 * ------------------------------------------------------------------ */

/**
 * Start a reset for an address.
 *
 * Every outcome is reported distinctly, because the caller is expected to
 * stop the flow and tell the visitor what went wrong. That makes this page an
 * account-enumeration oracle - an unauthenticated visitor can use it to test
 * which addresses have an account - which is the price of the clear error the
 * caller wants. The per-address cooldown below is what bounds the abuse; the
 * session throttle in the caller is the other half.
 *
 * One token is live per account: issuing overwrites the previous hash, so a
 * link mailed earlier stops working the moment a newer one is requested.
 * That also bounds the blast radius of a leaked old email.
 */
function passwordResetIssue($conn, $email, $restartable = false) {
    $email = trim((string)$email);

    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, status, reset_requested_at FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return ['status' => 'unknown', 'token' => '', 'user' => null];
    }

    /* A deactivated account gets the same page as everyone else. Sending a
       working link here would be harmless (login still refuses the
       account) but refusing here without saying so is the point. */
    if (($user['status'] ?? 'active') !== 'active') {
        return ['status' => 'inactive', 'token' => '', 'user' => null];
    }

    /* The cooldown exists to stop one mailbox being flooded by repeated
       "send me a link" clicks, so it only means anything when a link is
       actually delivered. A caller that hands the token straight to the
       visitor (see auth/forgot-password.php) sends nothing and passes
       $restartable: there is no mailbox to protect, and refusing there would
       only lock a real user out of a page they are already looking at. */
    if (!$restartable && !empty($user['reset_requested_at'])) {
        $last = strtotime((string)$user['reset_requested_at']);
        if ($last !== false && (time() - $last) < PASSWORD_RESET_COOLDOWN_SECONDS) {
            return [
                'status' => 'cooldown',
                'token'  => '',
                'user'   => $user,
                'wait'   => PASSWORD_RESET_COOLDOWN_SECONDS - (time() - $last),
            ];
        }
    }

    $token = passwordResetGenerateToken();
    $tokenHash = passwordResetHashToken($token);
    $expires = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_MINUTES * 60);

    /* Written from PHP's clock, not NOW(). MySQL's NOW() is the server's own
       zone and PHP's is date_default_timezone_get()'s, and on this machine
       they are six hours apart. The cooldown above reads this column back
       and compares it to time(), so a value stamped by the other clock
       lands in the future - and (time() - future) is negative, which is
       less than the cooldown, which latched the lock on permanently and
       meant a second reset could never be requested for that account.
       Both this and reset_expires_at are therefore formatted in PHP. */
    $requestedAt = date('Y-m-d H:i:s');

    $update = $conn->prepare("UPDATE users SET reset_token_hash = ?, reset_expires_at = ?, reset_requested_at = ? WHERE id = ?");
    $userId = (int)$user['id'];
    $update->bind_param("sssi", $tokenHash, $expires, $requestedAt, $userId);
    $ok = $update->execute();
    $update->close();

    if (!$ok) {
        error_log('Carvella password reset failed: could not store the token for user ' . $userId);
        return ['status' => 'error', 'token' => '', 'user' => $user];
    }

    return ['status' => 'sent', 'token' => $token, 'user' => $user];
}

/**
 * Resolve a presented token to its account.
 *
 * Returns ['ok' => bool, 'reason' => 'invalid'|'expired'|'ok', 'user' => ?array].
 * The reason exists so the reset page can tell a user their link simply
 * expired (a normal outcome, offer a new one) rather than claiming it was
 * never valid.
 */
function passwordResetFindUser($conn, $token) {
    $token = (string)$token;
    if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
        return ['ok' => false, 'reason' => 'invalid', 'user' => null];
    }

    $tokenHash = passwordResetHashToken($token);
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, password, role, status, reset_token_hash, reset_expires_at FROM users WHERE reset_token_hash = ? LIMIT 1");
    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !hash_equals($tokenHash, (string)$user['reset_token_hash'])) {
        return ['ok' => false, 'reason' => 'invalid', 'user' => null];
    }
    if (empty($user['reset_expires_at']) || strtotime((string)$user['reset_expires_at']) < time()) {
        return ['ok' => false, 'reason' => 'expired', 'user' => null];
    }
    if (($user['status'] ?? 'active') !== 'active') {
        return ['ok' => false, 'reason' => 'invalid', 'user' => null];
    }

    return ['ok' => true, 'reason' => 'ok', 'user' => $user];
}

/**
 * Set a new password against a live token and burn the token.
 *
 * The token hash is in the WHERE clause as well as the password, so the
 * single-use guarantee is enforced by the UPDATE itself: if two requests
 * carrying the same token race, the first clears reset_token_hash and the
 * second matches zero rows. Clearing the token in a follow-up SELECT-then-
 * UPDATE would let both succeed.
 *
 * A reset does not sign the user in. Someone who lands on this page from a
 * forwarded link would otherwise be handed a session, and the whole point
 * of the token is to prove control of the mailbox; proving it is not the
 * same as being at the keyboard.
 */
function passwordResetApply($conn, $token, $newPassword) {
    $found = passwordResetFindUser($conn, $token);
    if (!$found['ok']) {
        return ['success' => false, 'reason' => $found['reason'], 'user' => null];
    }

    $user = $found['user'];
    if (password_verify($newPassword, (string)$user['password'])) {
        return ['success' => false, 'reason' => 'unchanged', 'user' => $user];
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $tokenHash = passwordResetHashToken($token);
    $userId = (int)$user['id'];

    $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token_hash = NULL, reset_expires_at = NULL, reset_requested_at = NULL WHERE id = ? AND reset_token_hash = ?");
    $stmt->bind_param("sis", $passwordHash, $userId, $tokenHash);
    $ok = $stmt->execute();
    $changed = (int)$stmt->affected_rows;
    $stmt->close();

    if (!$ok || $changed !== 1) {
        error_log('Carvella password reset failed: token consume for user ' . $userId . ' matched ' . $changed . ' rows');
        return ['success' => false, 'reason' => 'consumed', 'user' => $user];
    }

    return ['success' => true, 'reason' => 'ok', 'user' => $user];
}

/* ------------------------------------------------------------------
 * MAIL
 * ------------------------------------------------------------------ */

/**
 * The reset mail, as HTML and text.
 *
 * Inline styles in a table layout: this has to render in Outlook and in the
 * Gmail web client, which ignore <style> blocks, external stylesheets and
 * CSS custom properties. Everything the template needs is therefore inlined,
 * which is why it does not reuse the app's Tailwind classes.
 */
function passwordResetBuildEmail($user, $token) {
    $url = passwordResetUrl($token);
    $name = trim(((string)($user['first_name'] ?? '')) . ' ' . ((string)($user['last_name'] ?? '')));
    $name = $name !== '' ? $name : 'there';
    $minutes = PASSWORD_RESET_TTL_MINUTES;

    $subject = 'Reset your Carvella password';

    $button = 'display:inline-block;padding:14px 32px;background:#6d28d9;color:#ffffff;'
        . 'border-radius:9999px;font-family:Arial,Helvetica,sans-serif;font-size:15px;'
        . 'font-weight:bold;text-decoration:none;';

    $cell = 'padding:0 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;';
    $muted = $cell . 'font-size:13px;color:#6b7280;';

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f5f3ff;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ff;padding:32px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb;">'

        . '<tr><td style="background:#6d28d9;padding:28px 32px;">'
        . '<span style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;letter-spacing:2px;">CARVELLA</span>'
        . '</td></tr>'

        . '<tr><td style="padding:32px 32px 8px;">'
        . '<h1 style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:1.3;color:#111827;">Reset your password</h1>'
        . '<p style="' . $cell . 'margin:0 0 20px;">Hi ' . e($name) . ',</p>'
        . '<p style="' . $cell . 'margin:0 0 20px;">We received a request to reset the password for your Carvella account. Use the button below to choose a new one. The link works once and expires in ' . (int)$minutes . ' minutes.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;"><tr><td style="border-radius:9999px;">'
        . '<a href="' . e($url) . '" style="' . $button . '">Choose a new password</a>'
        . '</td></tr></table>'
        . '<p style="' . $muted . 'margin:0 0 8px;">If the button does not work, paste this link into your browser:</p>'
        . '<p style="' . $muted . 'margin:0 0 24px;word-break:break-all;">' . e($url) . '</p>'
        . '</td></tr>'

        . '<tr><td style="padding:8px 32px 28px;">'
        . '<p style="' . $muted . 'margin:0;padding-top:20px;border-top:1px solid #e5e7eb;">'
        . 'If you did not ask to reset your password, you can ignore this email - your current password will keep working and nothing has changed.'
        . '</p>'
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';

    $text = "Reset your Carvella password\n\n"
        . "Hi $name,\n\n"
        . "We received a request to reset the password for your Carvella account. "
        . "Open the link below to choose a new one. It works once and expires in $minutes minutes.\n\n"
        . "$url\n\n"
        . "If you did not ask to reset your password, you can ignore this email - "
        . "your current password will keep working and nothing has changed.\n\n"
        . "Carvella\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}
