<?php
/* Include-once sentinel - never guard on own functions: PHP binds them at compile time, so the guard is always true. */
if (defined('CARVELLA_FUNCTIONS_LOADED')) {
    return;
}
define('CARVELLA_FUNCTIONS_LOADED', true);

/* Throttle limits, declared before the includes below so they exist for any caller. */
if (!defined('LOGIN_MAX_ATTEMPTS')) { define('LOGIN_MAX_ATTEMPTS', 6); }
if (!defined('LOGIN_LOCK_SECONDS'))  { define('LOGIN_LOCK_SECONDS', 300); }

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/rental-service.php';

/** Clean for STORAGE: trims and strips markup only, no encoding - writes use bound params, use e(). */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    if ($data === null || is_bool($data)) {
        return $data;
    }
    $clean = strip_tags((string)$data);
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
    $clean = preg_replace('/[ \t]+/u', ' ', (string)$clean);
    return trim($clean);
}

/** Escape a value for HTML OUTPUT. */
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function generateId($prefix = '') {
    return $prefix . bin2hex(random_bytes(6));
}

function formatCurrency($amount) {
    $amount = (float)$amount;
    // Whole pesos render as "2,000" and only real cents keep the decimals, so every surface quotes one price.
    $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
    return '₱' . number_format($amount, $decimals);
}

/* Photo derivatives: 3840px masters are too heavy for a grid, so consumers take a thumb/card/large one. */
function vehicleImagePath($imagePath, $size = 'card') {
    if (empty($imagePath)) {
        return '';
    }
    $clean = preg_replace('/\?v=\d+$/', '', $imagePath);
    $root  = dirname(__DIR__) . '/' . str_replace('/', DIRECTORY_SEPARATOR, $clean);
    $base  = 'assets/images/vehicles/';

    $version = preg_match('/\?v=(\d+)$/', $imagePath, $m) ? '?v=' . $m[1] : '';

    if (strpos($clean, $base) !== 0) {
        // Uploaded outside the managed folder: serve it untouched, but only when it is on disk.
        return is_file($root) ? encodeImagePath($clean . $version) : '';
    }

    $stem = preg_replace('/\.(png|jpe?g|webp)$/i', '', substr($clean, strlen($base)));

    foreach (vehicleImageFallbackOrder($size) as $candidate) {
        $rel = $base . $candidate['folder'] . '/' . $stem . '.jpg';
        $fs  = dirname(__DIR__) . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        // A tier folder holding a full-size master stalls the grid, so oversized files are skipped.
        if (is_file($fs) && filesize($fs) <= vehicleImageSizeCeiling($candidate['folder'])) {
            return encodeImagePath($rel . $version);
        }
    }
    // No usable derivative. The master is better than nothing.
    return is_file($root) ? encodeImagePath($clean . $version) : vehiclePlaceholderImage();
}

/* Largest a tier file may be and still count as it - sized well above real output to reject a copied-in master. */
function vehicleImageSizeCeiling($folder) {
    $ceilings = [
        'thumbs' => 400 * 1024,
        'cards'  => 900 * 1024,
        'large'  => 1600 * 1024,
    ];
    return isset($ceilings[$folder]) ? $ceilings[$folder] : 900 * 1024;
}

/* The three derivative tiers, widest-last for an ascending srcset; the grid dir is "cards", so it cannot be the key. */
function vehicleImageTiers() {
    return [
        'thumb' => ['folder' => 'thumbs', 'width' => 480],
        'card'  => ['folder' => 'cards',  'width' => 900],
        'large' => ['folder' => 'large',  'width' => 1600],
    ];
}

/* Which tiers to try: the requested one first, then the rest, so a card-only vehicle renders on a hero. */
function vehicleImageFallbackOrder($size) {
    $tiers = vehicleImageTiers();
    if (!isset($tiers[$size])) {
        $size = 'card';
    }
    $order = [];
    $order[$size] = $tiers[$size];
    unset($tiers[$size]);
    foreach ($tiers as $tier) {
        $order[] = $tier;
    }
    return $order;
}

/* Percent-encode photo filename spaces: literal spaces break URLs, so only spaces are touched. */
function encodeImagePath($path) {
    return str_replace(' ', '%20', $path);
}

/* Shown when a vehicle's photo is missing, at the card's 900x672 size so it drops in with no reflow. */
function vehiclePlaceholderImage() {
    $rel = 'assets/images/vehicles/placeholder.jpg';
    $fs  = dirname(__DIR__) . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    return is_file($fs) ? encodeImagePath($rel) : '';
}

function vehicleCardImage($imagePath) {
    return vehicleImagePath($imagePath, 'card');
}

function vehicleLargeImage($imagePath) {
    return vehicleImagePath($imagePath, 'large');
}

function vehicleThumbImage($imagePath) {
    return vehicleImagePath($imagePath, 'thumb');
}

/* srcset for the tiers that actually exist on disk.
 *
 * A grid card renders at roughly 430px on desktop and 330px on a phone, so the
 * 900px card derivative is 2-3x more pixels than a phone can use. Declaring the
 * ladder lets the browser fetch the 480px file instead, which takes a 24-card
 * fleet grid from ~2.6MB to ~700KB on a handset.
 *
 * Returns '' rather than a one-entry srcset when fewer than two tiers resolve,
 * which is also the case for the placeholder and for a raw master - a single
 * candidate is legal but misleading, and browsers ignore it anyway. Callers
 * must pair a non-empty result with a sizes attribute that matches their
 * layout, otherwise the browser assumes 100vw and keeps picking the largest.
 *
 * Pass the same base URL used for the src attribute. A relative candidate
 * resolves against the page's own directory, not the document root, so a page
 * at /customer/vehicles.php would ask for /customer/assets/images/... and 404.
 * The browser prefers srcset over src, so that failure is silent and total.
 */
function vehicleImageSrcset($imagePath, $baseUrl = '') {
    if (empty($imagePath)) {
        return '';
    }
    $candidates = [];
    foreach (vehicleImageTiers() as $key => $tier) {
        $path = vehicleImagePath($imagePath, $key);
        if ($path === '' || strpos($path, 'placeholder') !== false) {
            continue;
        }
        // A path resolving to a tier other than the one asked for means the
        // ladder is incomplete for this vehicle; stop rather than advertise a
        // width the file does not have.
        if (strpos($path, '/' . $tier['folder'] . '/') === false) {
            continue;
        }
        $candidates[$path] = $tier['width'];
    }
    if (count($candidates) < 2) {
        return '';
    }
    $list = [];
    foreach ($candidates as $path => $width) {
        $list[] = ($baseUrl !== '' ? rtrim($baseUrl, '/') . '/' : '') . $path . ' ' . $width . 'w';
    }
    return implode(', ', $list);
}

function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts === false ? '—' : date('M d, Y', $ts);
}

function formatDateTime($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts === false ? '—' : date('M d, Y h:i A', $ts);
}

/** TIME column (or H:i value) as a readable clock time, e.g. 10:00 -> 10:00 AM. */
function formatTime($time) {
    if (empty($time)) {
        return '—';
    }
    $ts = strtotime($time);
    return $ts === false ? '—' : date('g:i A', $ts);
}

function getStatusBadgeClass($status) {
    $classes = [
        'pending' => 'badge-warning',
        'confirmed' => 'badge-info',
        'active' => 'badge-info',
        'in_progress' => 'badge-warning',
        'overdue' => 'badge-danger',
        'available' => 'badge-success',
        'completed' => 'badge-success',
        'cancelled' => 'badge-danger',
        'maintenance' => 'badge-danger',
        'unavailable' => 'badge-secondary',
        'archived' => 'badge-secondary',
        'inactive' => 'badge-secondary'
    ];
    return $classes[$status] ?? 'badge-secondary';
}

function setFlash($type, $message, $title = '') {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'title' => $title];
    unset($_SESSION['flash_shown']);
}

/**
 * The whole queued notice - type, message and the optional title - consumed on
 * the first matching read. getFlash() is the message-only view of this, so
 * every existing call site keeps working unchanged while a page that wants a
 * richer toast (admin/vehicles.php) can read the title too.
 */
function getFlashNotice($type = null) {
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    // Only the matching reader consumes the message. A page that checks
    // getFlash('success') first must not swallow a queued error before the
    // getFlash('error') call gets a chance to render it.
    if ($type !== null && ($flash['type'] ?? null) !== $type) {
        return null;
    }
    unset($_SESSION['flash']);
    return $flash;
}

function getFlash($type = null) {
    $flash = getFlashNotice($type);
    return $flash === null ? null : ($flash['message'] ?? null);
}

/**
 * Drop a flash that was never rendered on the request that displayed it.
 *
 * Without this, changing getFlash() to peek-on-type-mismatch would let a
 * message leak onto a later, unrelated page whenever a template renders only
 * one of the two types. The first request after setFlash() is allowed to show
 * it; anything still queued at the end of that request is discarded.
 */
function flash_prune_stale() {
    if (!isset($_SESSION['flash'])) {
        return;
    }
    if (!empty($_SESSION['flash_shown'])) {
        unset($_SESSION['flash']);
    }
    $_SESSION['flash_shown'] = true;
}

register_shutdown_function('flash_prune_stale');

/**
 * Render the queued notice - and any extra notices - as dismissible toasts.
 *
 * getFlashNotice() consumes the queued flash together with the operator-facing
 * title setFlash() stored, so the operator reads what actually happened
 * ("Reservation approved", "Vehicle archived") instead of a bare line of text.
 * A caller can append its own notices through $extra - a form validation error,
 * say - so every outcome on the page is reported through the same component.
 * Markup and script are emitted together because neither is any use without the
 * other, and an empty queue renders nothing at all.
 *
 * @param array $extra Notices shaped like ['type','variant','title','message'].
 */
function renderFlashNotices(array $extra = []) {
    $notices = [];
    if ($notice = getFlashNotice('success')) {
        $notices[] = $notice + ['variant' => 'success'];
    }
    if ($notice = getFlashNotice('error')) {
        $notices[] = $notice + ['variant' => 'error'];
    }
    foreach ($extra as $notice) {
        if (is_array($notice)) {
            $notices[] = $notice;
        }
    }
    if (!$notices) {
        return;
    }
    ?>
    <div id="toast-stack" class="fixed top-4 right-4 sm:top-6 sm:right-6 z-[70] flex flex-col gap-3 w-[calc(100vw-2rem)] sm:w-[24rem] pointer-events-none" aria-live="polite">
        <?php foreach ($notices as $notice): ?>
        <?php
        $isError = ($notice['variant'] ?? 'success') === 'error';
        $toastTitle = trim((string)($notice['title'] ?? ''));
        if ($toastTitle === '') {
            $toastTitle = $isError ? 'Something went wrong' : 'Done';
        }
        $toastMessage = (string)($notice['message'] ?? '');
        $toastIcon  = $isError ? 'bi-exclamation-octagon-fill' : 'bi-check-circle-fill';
        $toastChip  = $isError ? 'bg-error-container text-on-error-container' : 'bg-emerald-100 text-emerald-700';
        $toastEdge  = $isError ? 'border-error-container' : 'border-emerald-200';
        $toastBar   = $isError ? 'bg-error' : 'bg-emerald-500';
        ?>
        <div class="toast pointer-events-auto relative overflow-hidden rounded-2xl border <?= $toastEdge ?> bg-surface-container-lowest shadow-[0_18px_50px_-16px_rgba(29,27,32,0.4)]" data-toast role="<?= $isError ? 'alert' : 'status' ?>">
            <div class="flex items-start gap-3 p-4">
                <span class="shrink-0 w-9 h-9 rounded-full <?= $toastChip ?> flex items-center justify-center">
                    <i aria-hidden="true" class="bi <?= $toastIcon ?> text-[18px]"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-label-lg text-label-lg font-bold text-on-surface"><?= htmlspecialchars($toastTitle) ?></p>
                    <?php if ($toastMessage !== ''): ?>
                    <p class="font-body-sm text-body-sm text-on-surface-variant mt-0.5"><?= htmlspecialchars($toastMessage) ?></p>
                    <?php endif; ?>
                </div>
                <button type="button" class="shrink-0 w-7 h-7 rounded-full hover:bg-surface-container-high text-tertiary hover:text-on-surface flex items-center justify-center transition-colors" data-toast-close aria-label="Dismiss notification">
                    <i aria-hidden="true" class="bi bi-x-lg text-[14px]"></i>
                </button>
            </div>
            <span class="absolute bottom-0 left-0 h-0.5 w-full <?= $toastBar ?>" data-toast-bar aria-hidden="true"></span>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    /* Each toast drains its own progress bar so it is clear when it will go, it
       pauses while the pointer is over it, and it can be dismissed outright. */
    (function () {
        var stack = document.getElementById('toast-stack');
        if (!stack) return;

        var LIFETIME = 7000;

        Array.prototype.forEach.call(stack.querySelectorAll('[data-toast]'), function (toast) {
            var bar = toast.querySelector('[data-toast-bar]');
            var timer = null;

            function dismiss() {
                if (timer) { clearTimeout(timer); timer = null; }
                toast.style.transition = 'opacity .25s ease, transform .25s ease';
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-0.5rem)';
                setTimeout(function () {
                    toast.remove();
                    if (!stack.children.length) stack.remove();
                }, 260);
            }

            function arm(delay) {
                if (timer) clearTimeout(timer);
                timer = setTimeout(dismiss, delay);
            }

            if (bar) {
                bar.style.transition = 'width ' + LIFETIME + 'ms linear';
                bar.style.width = '100%';
                requestAnimationFrame(function () {
                    requestAnimationFrame(function () { bar.style.width = '0%'; });
                });
            }

            var closeBtn = toast.querySelector('[data-toast-close]');
            if (closeBtn) closeBtn.addEventListener('click', dismiss);

            toast.addEventListener('mouseenter', function () {
                if (timer) { clearTimeout(timer); timer = null; }
            });
            toast.addEventListener('mouseleave', function () {
                // Re-arm with a short grace so a paused toast does not sit forever.
                arm(2500);
            });

            arm(LIFETIME);
        });
    })();
    </script>
    <?php
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function isVehicleBooked($vehicleId, $pickupDate, $returnDate, $excludeId = null) {
    global $conn;
    if (!rentalDateRangeIsValid($pickupDate, $returnDate)) {
        return true;
    }
    $exclude = !empty($excludeId) ? (int)$excludeId : null;
    return vehicleHasBookingConflict($conn, $vehicleId, $pickupDate, $returnDate, $exclude);
}

function logActivity($action, $details = '') {
    global $conn;
    if (!($conn instanceof mysqli)) {
        return false;
    }
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("isss", $userId, $action, $details, $ip);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function loginUser(array $user) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['status'] = $user['status'] ?? 'active';
    $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
}

/* ---------------------------------------------------------------
 * CSRF — every state-changing form carries a per-session token and
 * every state-changing handler validates it. SameSite=Lax alone is a
 * single browser-level control, so it is treated as defence in depth.
 * --------------------------------------------------------------- */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside a <form method="post">. */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid($token) {
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validate the token for a state-changing request. Aborts with a 403
 * when it is missing or does not match. Reads both the form field and
 * the X-CSRF-Token header so fetch() calls are covered too.
 */
function verify_csrf() {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_is_valid($token)) {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            http_response_code(405);
            exit('This action requires a POST request.');
        }
        http_response_code(403);
        exit('Your session expired or the request could not be verified. Please reload the page and try again.');
    }
}

/* ---------------------------------------------------------------
 * LOGIN THROTTLING — per-session back-off plus a cross-session lock
 * on the submitted email, so a shared browser still cannot be used
 * to grind credentials.
 *
 * LOGIN_MAX_ATTEMPTS and LOGIN_LOCK_SECONDS are defined at the top of
 * this file, ahead of the include guard, so they survive a re-entrant
 * include. See the note there.
 * --------------------------------------------------------------- */

function login_is_locked($email) {
    $key = login_throttle_key($email);
    if (!empty($_SESSION['login_lock'][$key])
        && $_SESSION['login_lock'][$key] > time()) {
        return $_SESSION['login_lock'][$key] - time();
    }
    return 0;
}

function login_throttle_key($email) {
    return 'login:' . strtolower(trim((string)$email));
}

function login_throttle_hit($email) {
    $key = login_throttle_key($email);
    $bucket = $_SESSION['login_attempts'][$key] ?? ['n' => 0, 'first' => time()];
    if (time() - $bucket['first'] > LOGIN_LOCK_SECONDS) {
        $bucket = ['n' => 0, 'first' => time()];
    }
    $bucket['n']++;
    $_SESSION['login_attempts'][$key] = $bucket;
    if ($bucket['n'] >= LOGIN_MAX_ATTEMPTS) {
        $_SESSION['login_lock'][$key] = time() + LOGIN_LOCK_SECONDS;
        unset($_SESSION['login_attempts'][$key]);
    }
}

function login_throttle_clear($email) {
    $key = login_throttle_key($email);
    unset($_SESSION['login_attempts'][$key], $_SESSION['login_lock'][$key]);
}

function jsonResponse($data, $status = 200) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}

/** Abort unless the current request is a POST. Used by mutating actions. */
function requirePost() {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('This action requires a POST request.');
    }
}

function requireMethod($method) {
    $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($current !== strtoupper($method)) {
        jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
}

function readJsonInput() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/* ---------------------------------------------------------------
 * PAGINATION — one shared implementation for every list page.
 *
 * admin/activity-logs.php used to be the only paginated surface and
 * it hand-rolled both the LIMIT/OFFSET maths and the pager markup,
 * with links of the bare form ?page=N that silently dropped every
 * other query param on the page. These helpers replace that: the
 * bounds maths is computed once, and links are built by merging the
 * page number into the current query string so filters (status tabs,
 * search, date ranges, ...) survive paging.
 * --------------------------------------------------------------- */

/** The requested page number, clamped to a positive integer. */
function paginationPage(): int {
    return max(1, (int)($_GET['page'] ?? 1));
}

/**
 * All the numbers a paginated page needs, from one request.
 *
 * The requested page is clamped into range here rather than trusted: a
 * bookmarked ?page=99 on a list that has since shrunk used to render an
 * empty table that looked like data loss. Everything else (offset, the
 * "showing X-Y of Z" window) is derived from the clamped page so the
 * query, the summary and the pager can never disagree.
 *
 * @param int $requestedPage raw page from paginationPage().
 * @param int $total         COUNT(*) of the filtered set.
 * @param int $perPage       rows per page.
 * @return array{page:int,per_page:int,total:int,total_pages:int,offset:int,from:int,to:int}
 */
function paginationBounds($requestedPage, $total, $perPage): array {
    $perPage    = max(1, (int)$perPage);
    $total      = max(0, (int)$total);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = min(max(1, (int)$requestedPage), $totalPages);
    return [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
        'offset'      => ($page - 1) * $perPage,
        'from'        => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
        'to'          => min($total, $page * $perPage),
    ];
}

/**
 * Build a URL for the current script with query params overridden.
 *
 * Passing null (or '') for a key removes it, which is how a filter link
 * drops a stale filter. Unless $resetPage is false the page number is
 * stripped as well, so changing a filter always lands on page 1 instead
 * of a page that may no longer exist for the new result set.
 */
function queryUrl(array $overrides = [], $resetPage = true): string {
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    if ($resetPage) {
        unset($params['page']);
    }
    $query = http_build_query($params);
    // Resolve against the script's own path: a bare ?page=2 on a page
    // that was reached through a rewritten URL would rebase against the
    // wrong directory.
    $path = (string)strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    if ($path === '') {
        $path = '?';
    }
    return $query === '' ? $path : $path . '?' . $query;
}

/** URL for a given page number of the current filters. */
function paginationUrl($page): string {
    $page = (int)$page;
    return queryUrl(['page' => $page > 1 ? $page : null], false);
}

/**
 * Render the pager (and optionally a "Showing X-Y of Z" summary).
 *
 * Matches the house style - rounded-full 36px buttons - that the audit
 * trail pager established. Page numbers are windowed around the current
 * page with ellipses, so a 200-page log does not render 200 links.
 * Returns '' when the set fits on one page and no summary was asked for,
 * so callers can wrap it unconditionally.
 *
 * @param array $pg      result of paginationBounds().
 * @param array $options 'summary' => bool, render "Showing X-Y of Z";
 *                       'noun'    => string, plural label for the summary.
 */
function renderPagination(array $pg, array $options = []): string {
    $page       = (int)$pg['page'];
    $totalPages = (int)$pg['total_pages'];
    $showSummary = !empty($options['summary']);
    $noun = (string)($options['noun'] ?? 'results');

    $html = '';
    if ($showSummary) {
        $total = (int)$pg['total'];
        $text = $total === 0
            ? 'No ' . $noun . ' found'
            : 'Showing ' . number_format($pg['from']) . '–' . number_format($pg['to'])
                . ' of ' . number_format($total) . ' ' . $noun;
        $html .= '<p class="text-body-sm font-body-sm text-on-surface-variant" data-pagination-summary>'
               . e($text) . '</p>';
    }

    if ($totalPages <= 1) {
        // A summary on its own is still useful; a pager is not.
        return $showSummary
            ? '<div class="flex items-center justify-between gap-3 pt-1">' . $html . '</div>'
            : '';
    }

    $btnBase  = 'w-9 h-9 rounded-full flex items-center justify-center text-label-md font-label-md transition-all';
    $idleCls  = 'bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant hover:border-primary hover:text-primary';
    $arrowCls = 'bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant hover:bg-surface-container';

    $nav  = '<nav class="flex items-center justify-center gap-2" aria-label="Pagination">';

    if ($page > 1) {
        $nav .= '<a href="' . e(paginationUrl($page - 1)) . '" rel="prev" aria-label="Previous page" '
             .  'class="' . $btnBase . ' ' . $arrowCls . '">'
             .  '<i aria-hidden="true" class="bi bi-chevron-left text-[18px]"></i></a>';
    }

    // Window: current page ±2, always the first and last page, ellipses
    // wherever the gap is wider than one page.
    $window = [];
    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
        $window[] = $i;
    }
    $printed = [];
    foreach (array_merge([1], $window, [$totalPages]) as $i) {
        if (isset($printed[$i])) {
            continue;
        }
        // Gap check runs against the last page actually printed, before
        // this one joins the set; a jump wider than one page gets an
        // ellipsis.
        $prev = $printed ? max($printed) : 0;
        if ($i > 1 && $i - 1 > $prev) {
            $nav .= '<span class="w-6 text-center text-on-surface-variant" aria-hidden="true">&hellip;</span>';
        }
        $printed[$i] = true;
        if ($i === $page) {
            $nav .= '<a href="' . e(paginationUrl($i)) . '" aria-current="page" '
                 .  'class="' . $btnBase . ' bg-primary text-on-primary font-bold shadow-sm">' . $i . '</a>';
        } else {
            $nav .= '<a href="' . e(paginationUrl($i)) . '" '
                 .  'class="' . $btnBase . ' ' . $idleCls . '">' . $i . '</a>';
        }
    }

    if ($page < $totalPages) {
        $nav .= '<a href="' . e(paginationUrl($page + 1)) . '" rel="next" aria-label="Next page" '
             .  'class="' . $btnBase . ' ' . $arrowCls . '">'
             .  '<i aria-hidden="true" class="bi bi-chevron-right text-[18px]"></i></a>';
    }
    $nav .= '</nav>';

    if ($showSummary) {
        return '<div class="flex flex-col-reverse sm:flex-row sm:items-center justify-between gap-3 pt-1">'
             . $html . $nav . '</div>';
    }
    return $nav;
}

/**
 * Build a minimal XLSX (Excel) file with auto-sized columns and send it.
 */
function carvella_export_xlsx(string $filename, array $headers, array $rows): void {
    if (substr($filename, -5) !== '.xlsx') {
        $filename .= '.xlsx';
    }

    // Estimate column widths from header + content (capped).
    $widths = [];
    foreach (array_values($headers) as $i => $h) {
        $widths[$i] = max(10, min(60, strlen((string)$h) + 4));
    }
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $val) {
            $len = strlen((string)$val);
            if ($len + 2 > ($widths[$i] ?? 0)) {
                $widths[$i] = min(60, $len + 2);
            }
        }
    }

    $colLetter = function ($n) {
        $n++;
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    };

    $escapeXml = function ($v) {
        return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };

    // Build worksheet XML.
    $colsXml = '<cols>';
    foreach ($widths as $i => $w) {
        $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $colsXml .= '</cols>';

    $sheetRows = '';
    $r = 1;
    // header
    $sheetRows .= '<row r="' . $r . '">';
    foreach (array_values($headers) as $i => $h) {
        $ref = $colLetter($i) . $r;
        $sheetRows .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $escapeXml($h) . '</t></is></c>';
    }
    $sheetRows .= '</row>';
    $r++;
    foreach ($rows as $row) {
        $sheetRows .= '<row r="' . $r . '">';
        foreach (array_values($row) as $i => $val) {
            $ref = $colLetter($i) . $r;
            if (is_numeric($val) && !preg_match('/^0[0-9]/', (string)$val)) {
                $sheetRows .= '<c r="' . $ref . '"><v>' . $escapeXml($val) . '</v></c>';
            } else {
                $sheetRows .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $escapeXml($val) . '</t></is></c>';
            }
        }
        $sheetRows .= '</row>';
        $r++;
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $colsXml
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit();
}
