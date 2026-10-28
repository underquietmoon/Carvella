<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';

/**
 * Re-read the account behind the current session, once per request.
 *
 * Authorization used to trust $_SESSION alone, so deactivating or
 * demoting a user left their live session fully working. The role and
 * status now come from the database, and a session whose account is
 * gone, inactive, or no longer an admin is torn down.
 */
function currentAccount() {
    static $account = null;
    static $resolved = false;
    if ($resolved) {
        return $account;
    }
    $resolved = true;

    if (empty($_SESSION['user_id'])) {
        return $account = null;
    }

    global $conn;
    $userId = (int)$_SESSION['user_id'];

    /* "We could not find out" is not the same as "this session is invalid", and
     * conflating the two is how a momentary database blip signed out every
     * logged-in user on the site at once: a null $account made isLoggedIn()
     * call destroySession(). A server restart, an exhausted max_connections or
     * a lock wait timeout was enough to do it.
     *
     * A lookup failure can arrive three different ways and all three have to be
     * handled here:
     *   1. prepare() returns false  - mysqli runs with MYSQLI_REPORT_OFF, so a
     *      plain query error is a false return, not an exception.
     *   2. execute() returns false - same reporting mode, same outcome.
     *   3. prepare() THROWS - once the connection has gone away (MySQL restart,
     *      wait_timeout, network drop) mysqli raises an uncaught
     *      Error("mysqli object is already closed"). This is the one that
     *      matters most: it is a fatal error on every single page, because
     *      currentAccount() runs on every request, so the whole site goes blank
     *      rather than merely signing people out. A prepare() that returns false
     *      is never even reached in that state.
     *
     * The catch(Throwable) covers all three, including any future mysqli error
     * mode. The session is left untouched and the next request simply retries. */
    try {
        $stmt = $conn->prepare("SELECT id, first_name, last_name, email, role, status FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('prepare failed: ' . $conn->error);
        }
        $stmt->bind_param("i", $userId);
        if (!$stmt->execute()) {
            $stmtError = $stmt->error;
            $stmt->close();
            throw new RuntimeException('execute failed: ' . $stmtError);
        }
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $account = $row ?: null;
    } catch (Throwable $e) {
        error_log('Carvella currentAccount lookup failed: ' . $e->getMessage());
        return $account = ['lookup_failed' => true];
    }

    // Only a real row updates the cached session details; the lookup_failed tag
    // must not reach here or it would overwrite role/status/name with blanks.
    if ($account) {
        $_SESSION['role'] = $account['role'];
        $_SESSION['status'] = $account['status'];
        $_SESSION['name'] = $account['first_name'] . ' ' . $account['last_name'];
    }

    return $account;
}

function isLoggedIn() {
    $account = currentAccount();
    /* A failed lookup is not a rejected session. Tearing the session down here
     * is what turned a momentary database blip into a site-wide sign-out, so a
     * lookup that never completed leaves the session intact and the page
     * renders as signed out for this request only - the next one re-reads the
     * account and the user is back. */
    if (is_array($account) && !empty($account['lookup_failed'])) {
        return false;
    }
    if ($account === null) {
        if (!empty($_SESSION['user_id'])) {
            destroySession();
        }
        return false;
    }
    return ($account['status'] ?? 'active') === 'active';
}

function maintenanceRoles() {
    return ['maintenance', 'maintenance_staff', 'staff', 'technician'];
}

function isMaintenance() {
    $account = currentAccount();
    if (!is_array($account) || !empty($account['lookup_failed'])) {
        return false;
    }
    return $account !== null
        && in_array($account['role'] ?? '', maintenanceRoles(), true)
        && ($account['status'] ?? 'active') === 'active';
}

function isAdmin() {
    $account = currentAccount();
    if (!is_array($account) || !empty($account['lookup_failed'])) {
        return false;
    }
    return $account !== null
        && ($account['role'] ?? '') === 'admin'
        && ($account['status'] ?? 'active') === 'active';
}

/** Clear the session and its cookie. */
function destroySession() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function requireLogin() {
    if (!isLoggedIn()) {
        setFlash('error', 'Please sign in to continue.');
        header("Location: " . BASE_URL . "/auth/login.php");
        exit();
    }
}

function requireAdmin() {
    if (!isLoggedIn()) {
        setFlash('error', 'Please sign in to continue.');
        header("Location: " . BASE_URL . "/auth/login.php");
        exit();
    }
    if (!isAdmin()) {
        setFlash('error', 'Administrator access is required for that area.');
        if (isMaintenance()) {
            header("Location: " . BASE_URL . "/maintenance/dashboard.php");
        } else {
            header("Location: " . BASE_URL . "/customer/dashboard.php");
        }
        exit();
    }
}

function requireMaintenance() {
    if (!isLoggedIn()) {
        setFlash('error', 'Please sign in to continue.');
        header("Location: " . BASE_URL . "/auth/login.php");
        exit();
    }
    if (!isMaintenance() && !isAdmin()) {
        setFlash('error', 'Maintenance staff access is required for that area.');
        header("Location: " . BASE_URL . "/customer/dashboard.php");
        exit();
    }
}

function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        if (isAdmin()) {
            header("Location: " . BASE_URL . "/admin/dashboard.php");
        } elseif (isMaintenance()) {
            header("Location: " . BASE_URL . "/maintenance/dashboard.php");
        } else {
            header("Location: " . BASE_URL . "/customer/dashboard.php");
        }
        exit();
    }
}

/**
 * API equivalents of requireLogin() / requireAdmin().
 *
 * The page guards answer a failure with a 302 to the login page, which is
 * right for a browser but wrong for a fetch() caller: it receives a 200 HTML
 * document as the body of what it expected to be JSON, and
 * response.json() then throws on the login form's markup. A client therefore
 * could not tell "not signed in" from "the server returned a page".
 *
 * These send 401 when there is no usable session and 403 when there is one
 * but the role is insufficient, both as JSON, and distinguish the two so a
 * client knows whether to prompt for sign-in or to report a permission error.
 */
function requireApiLogin() {
    if (!isLoggedIn()) {
        jsonResponse([
            'success' => false,
            'error'   => 'Authentication required',
        ], 401);
    }
}

function requireApiAdmin() {
    if (!isLoggedIn()) {
        jsonResponse([
            'success' => false,
            'error'   => 'Authentication required',
        ], 401);
    }
    if (!isAdmin()) {
        jsonResponse([
            'success' => false,
            'error'   => 'Administrator access is required',
        ], 403);
    }
}

/* Keep maintenance staff on their single dashboard: they never browse the
   customer area or unrelated admin screens. */
if (isMaintenance() && !empty($_SERVER['PHP_SELF'])) {
    $self = str_replace('\\\\', '/', (string)$_SERVER['PHP_SELF']);
    if (strpos($self, '/customer/') !== false) {
        header('Location: ' . BASE_URL . '/maintenance/dashboard.php');
        exit();
    }
    if (strpos($self, '/admin/') !== false) {
        $base = basename($self);
        if (!in_array($base, ['maintenance.php', 'maintenance-records.php'], true)) {
            header('Location: ' . BASE_URL . '/maintenance/dashboard.php');
            exit();
        }
    }
}
