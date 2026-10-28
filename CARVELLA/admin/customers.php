<?php
/* customers.php - Carvella Car Rental System */

$pageTitle = 'Customers & Verification Bureau - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/export.php';
requireAdmin();

$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
if (!in_array($filter, ['', 'active', 'inactive', 'renting'], true)) {
    $filter = '';
}

/* The status tabs are applied by the query now, not in the browser. The old
 * script hid rows client-side so a tab switch was an instant cross-fade, but
 * with the list paginated that would only ever hide part of the answer: a
 * "Verified" tab would show whichever slice of the verified customers happened
 * to sit on page 1. So the tab is a WHERE clause, the search ANDs with it, and
 * the tab links are real anchors built with queryUrl() - they keep ?search=,
 * reset to page 1 and still work with JS off. The counters below deliberately
 * leave the tab out of their WHERE so every tab reports how many matches it
 * would show, not just the one that is open. */
$where = "u.role = 'customer'";
$tabSql = '';
$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term, $term, $term];
    $types = 'ssss';
}

if ($filter === 'active') {
    $tabSql = " AND u.status = 'active'";
} elseif ($filter === 'inactive') {
    $tabSql = " AND u.status <> 'active'";
} elseif ($filter === 'renting') {
    // "Renting" is read off the reservation ledger, never a stored flag:
    // a customer is renting while one of their reservations is still active.
    $tabSql = " AND EXISTS (SELECT 1 FROM reservations ar WHERE ar.user_id = u.id AND ar.status = 'active')";
}

// One select shared by the paged table and the CSV export so both always
// describe the same customers.
$customerSelect = "SELECT u.*,
  (SELECT COUNT(*) FROM reservations WHERE user_id = u.id) AS reservation_count,
  (SELECT license_no FROM reservations WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) AS latest_license,
  (SELECT COALESCE(SUM(total_amount), 0) FROM reservations WHERE user_id = u.id) AS total_spent,
  (SELECT COUNT(*) FROM reservations WHERE user_id = u.id AND status = 'active') AS active_rentals,
  (SELECT created_at FROM reservations WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) AS last_reservation_date
  FROM users u WHERE ";

if (isset($_POST['toggle_status'])) {
    verify_csrf();
    $userId = (int)($_POST['toggle_status'] ?? 0);

    // The form posts to the bare URL, so search, tab and page ride along as
    // hidden fields; the redirect puts the operator back on the exact list
    // they were working through instead of an unfiltered first page.
    $backQuery = [];
    if ((string)($_POST['search'] ?? '') !== '') {
        $backQuery['search'] = (string)$_POST['search'];
    }
    if (in_array($_POST['filter'] ?? '', ['active', 'inactive', 'renting'], true)) {
        $backQuery['filter'] = (string)$_POST['filter'];
    }
    if ((int)($_POST['page'] ?? 1) > 1) {
        $backQuery['page'] = max(1, (int)$_POST['page']);
    }
    $backUrl = BASE_URL . '/admin/customers.php' . ($backQuery ? '?' . http_build_query($backQuery) : '');

    if ($userId <= 0) {
        setFlash('error', 'Invalid customer selection.');
        redirect($backUrl);
    }

    // Scoped to customer accounts: this screen must not be able to
    // deactivate an administrator, whose row is not listed here anyway.
    $target = $conn->prepare("SELECT id, first_name, last_name, role FROM users WHERE id = ? AND role = 'customer' LIMIT 1");
    $target->bind_param("i", $userId);
    $target->execute();
    $targetUser = $target->get_result()->fetch_assoc();
    $target->close();

    if (!$targetUser) {
        setFlash('error', 'That customer could not be found.');
    } else {
        $toggle = $conn->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ? AND role = 'customer'");
        $toggle->bind_param("i", $userId);
        if ($toggle->execute() && $toggle->affected_rows > 0) {
            logActivity('updated', "Toggled customer #$userId status");
            setFlash('success', 'Customer status updated.');
        } else {
            setFlash('error', 'That customer could not be updated.');
        }
        $toggle->close();
    }
    redirect($backUrl);
}

if (isset($_GET['export'])) {
    // The CSV walks the whole filtered set, never the current page window: an
    // export that silently stopped after 15 customers would be worse than no
    // export at all. The Export link is built with queryUrl(), so whatever
    // search and tab are on screen are the rows that get exported.
    $headers = carvella_export_headers('customers');
    $rows = [];
    $expStmt = $conn->prepare($customerSelect . $where . $tabSql . " ORDER BY u.created_at DESC");
    if (!empty($params)) {
        $expStmt->bind_param($types, ...$params);
    }
    $expStmt->execute();
    $expResult = $expStmt->get_result();
    while ($c = $expResult->fetch_assoc()) {
        $rows[] = carvella_export_map('customers', [
            'id'                => $c['id'],
            'first_name'        => $c['first_name'],
            'last_name'         => $c['last_name'],
            'email'             => $c['email'],
            'phone'             => $c['phone'],
            'address'           => $c['address'],
            'status'            => $c['status'],
            'reservation_count' => $c['reservation_count'],
            'total_spent'       => $c['total_spent'],
            'last_reservation'  => $c['last_reservation_date'],
            'created_at'        => $c['created_at'],
        ]);
    }
    $expStmt->close();
    csv_send('customers_' . date('Y-m-d') . '.csv', $headers, $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['walkin_register'])) {
    verify_csrf();

    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name  = sanitize($_POST['last_name'] ?? '');
    $email      = sanitize($_POST['email'] ?? '');
    $phone      = sanitize($_POST['phone'] ?? '');
    $address    = sanitize($_POST['address'] ?? '');
    $password   = $_POST['password'] ?? '';
    $tempPassword = '';

    if ($first_name === '' || $last_name === '' || $email === '') {
        setFlash('error', 'First name, last name, and email are required.');
    } elseif (!preg_match('/^[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*@[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*\.[A-Za-z]{2,}$/', $email)) {
        setFlash('error', 'Please enter a valid email address.');
    } elseif (mb_strlen($first_name) > 100 || mb_strlen($last_name) > 100) {
        setFlash('error', 'Names must be 100 characters or fewer.');
    } elseif ($phone !== '' && !preg_match('/^[0-9]{11}$/', $phone)) {
        setFlash('error', 'The mobile number must be 11 digits (e.g. 09171234567).');
    } elseif ($address !== '' && !preg_match('/^[A-Za-z0-9 ,.\-#]+$/', $address)) {
        setFlash('error', 'Please enter a valid home address (letters, numbers, spaces, commas, periods, hyphens, and # only).');
    } elseif (($password !== '') && strlen($password) < 8) {
        setFlash('error', 'The password must be at least 8 characters.');
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            setFlash('error', 'A customer with that email is already registered.');
        } else {
            if ($password === '') {
                // A 4-digit suffix is guessable online; a 10-char random
                // string is not.
                $tempPassword = 'Carvella-' . bin2hex(random_bytes(5));
                $password = $tempPassword;
            }
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, phone, address, password, role, status) VALUES (?, ?, ?, ?, ?, ?, 'customer', 'active')");
            $stmt->bind_param("ssssss", $first_name, $last_name, $email, $phone, $address, $hashed);
            if ($stmt->execute()) {
                $newId = $conn->insert_id;
                logActivity('created', "Walk-in customer #$newId registered at counter");
                $msg = 'Walk-in customer ' . $first_name . ' ' . $last_name . ' registered successfully.';
                if ($tempPassword !== '') {
                    $msg .= ' Temporary password: ' . $tempPassword;
                }
                setFlash('success', $msg);
            } else {
                setFlash('error', 'Failed to register customer. Please try again.');
            }
            $stmt->close();
        }
        $check->close();
    }
    redirect(BASE_URL . '/admin/customers.php');
}

/* Tab counters and the metric cards come from one grouped pass over the whole
 * search result, grouped by account status and by "has a live rental" so both
 * flags the tabs slice on fall out of the same rows. The open tab is left out
 * of this WHERE on purpose: a counter that only counted its own tab would zero
 * every other tab the moment one was picked. */
$countsStmt = $conn->prepare(
    "SELECT u.status,
            ((SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id AND r.status = 'active') > 0) AS is_renting,
            COUNT(*) AS cnt
       FROM users u
      WHERE $where
      GROUP BY u.status, is_renting"
);
if (!empty($params)) {
    $countsStmt->bind_param($types, ...$params);
}
$countsStmt->execute();
$countsResult = $countsStmt->get_result();
$totalCount = 0;
$activeCount = 0;
$inactiveCount = 0;
$rentingCount = 0;
while ($crow = $countsResult->fetch_assoc()) {
    $cnt = (int)$crow['cnt'];
    $totalCount += $cnt;
    if (($crow['status'] ?? 'active') === 'active') {
        $activeCount += $cnt;
    } else {
        $inactiveCount += $cnt;
    }
    if ((int)$crow['is_renting'] > 0) {
        $rentingCount += $cnt;
    }
}
$countsStmt->close();

// The COUNT shares $where/$tabSql verbatim with the table query below, so the
// pager can never size itself against a different set than it pages over.
$countStmt = $conn->prepare("SELECT COUNT(*) AS count FROM users u WHERE $where$tabSql");
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['count'];
$countStmt->close();

$pg = paginationBounds(paginationPage(), $total, 15);
$perPage = $pg['per_page'];
$offset  = $pg['offset'];

$stmt = $conn->prepare($customerSelect . $where . $tabSql . " ORDER BY u.created_at DESC LIMIT ? OFFSET ?");
$queryArgs = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($types . 'ii', ...$queryArgs);
$stmt->execute();
$customersResult = $stmt->get_result();
$customerRows = [];
while ($r = $customersResult->fetch_assoc()) {
    $customerRows[] = $r;
}
$stmt->close();

/* The KYC panel opens on the first customer the table shows - the tab has
 * already been applied by the query, so the first row is the first visible
 * one. */
$detailRow = $customerRows[0] ?? null;
// Customers exist for this search but the active tab excludes every one of
// them: that is the "No customers in this tab" state, not an empty registry.
$customerNoMatch = $totalCount > 0 && $total === 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

    <?php /* Status toggles, walk-in registration and any validation failure all
             queue a flash, and the shared toast stack renders each of them
             exactly once - reading the queue consumes it, so success and error
             can never leak onto a later request. */ ?>
    <?php renderFlashNotices(); ?>

    <!-- Page Header -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <div class="flex items-center gap-2.5">
          <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Customers &amp; Verification Bureau</h1>
          <span class="hidden sm:inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-surface-container text-primary text-label-sm font-semibold border border-outline-variant/30">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
            Live Registry
          </span>
        </div>
        <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Dagupan City Central Hub &bull; Registered Customer Directory &amp; Identity Verification</p>
      </div>
    </div>

    <!-- 1. Metrics Overview (4 Cards) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Total Registered Customers</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-on-surface"><?= number_format($totalCount) ?></div>
          <span class="text-emerald-700 text-label-sm text-[12px] font-medium">Registered customers</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Verified Customers</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-primary"><?= $activeCount ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Active &amp; Enabled</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Pending Verification</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-amber-700"><?= $inactiveCount ?></div>
          <span class="text-amber-800 text-label-sm text-[12px] font-medium">Counter Walk-in Review</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Active On-Road Customers</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-secondary"><?= $rentingCount ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Dagupan &amp; Pangasinan Rentals</span>
        </div>
      </div>
    </div>

    <!-- 2. Search & Filter Controls -->
    <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
      <form method="GET" id="customerSearchForm" class="relative flex-1 max-w-xl">
        <?php if ($filter !== ''): ?>
        <!-- A GET form submits only its own fields, so the open tab has to be
             carried explicitly or a search would silently drop it. The page
             number is dropped on purpose: a new search resets to page 1. -->
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <?php endif; ?>
        <i aria-hidden="true" class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-on-surface-variant text-[20px]"></i>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" maxlength="100" class="w-full pl-10 pr-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface placeholder:text-on-surface-variant/70 font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-primary/40 focus:border-primary transition-all" placeholder="Search by customer name, mobile (+63), email, or rental..." aria-label="Search customers by name, mobile number, email, or rental reference">
      </form>
      <div class="flex items-center gap-2.5 flex-wrap">
        <a href="<?= e(queryUrl(['export' => 1])) ?>" class="flex items-center gap-1.5 px-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface text-label-md font-label-md hover:bg-surface-container transition-colors" title="Tip: In Excel, select all columns and double-click the border between any two column headers to auto-fit.">
          <i aria-hidden="true" class="bi bi-download text-[18px]"></i>
          <span>Export CSV</span>
        </a>
        <button type="button" onclick="openWalkinRegister()" class="flex items-center gap-1.5 px-5 py-2.5 rounded-md bg-primary text-on-primary text-label-md font-label-md font-semibold hover:bg-secondary transition-colors shadow-sm active:scale-[0.98]">
          <i aria-hidden="true" class="bi bi-person-plus text-[18px]"></i>
          <span>Register Walk-in Customer</span>
        </button>
      </div>
    </div>

    <!-- Filter Tabs. Each tab is a real link now: the switch is a server-side
         query since the list is paginated (hiding rows in the browser could
         only ever show one page's worth of a tab), so ?filter= travels in the
         URL, survives a refresh and works with JS off. queryUrl() keeps the
         search and resets to page 1, and the #customer-filters hash keeps the
         app's page-transition handler from intercepting the click so the
         strip stays in view after the switch. -->
    <div id="customer-filters" style="scroll-margin-top: 84px;" class="flex items-center gap-2 overflow-x-auto pb-1 border-b border-outline-variant/30">
      <a href="<?= e(queryUrl(['filter' => null])) ?>#customer-filters" class="px-4 py-2 rounded-md <?= $filter === '' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> text-label-md font-label-md font-bold whitespace-nowrap transition-colors">
        All Customers (<?= $totalCount ?>)
      </a>
      <a href="<?= e(queryUrl(['filter' => 'active'])) ?>#customer-filters" class="px-4 py-2 rounded-md <?= $filter === 'active' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> text-label-md font-label-md whitespace-nowrap transition-colors">
        Verified (<?= $activeCount ?>)
      </a>
      <a href="<?= e(queryUrl(['filter' => 'renting'])) ?>#customer-filters" class="px-4 py-2 rounded-md <?= $filter === 'renting' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> text-label-md font-label-md whitespace-nowrap transition-colors flex items-center gap-1.5">
        Active Rentals (<?= $rentingCount ?>)
        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
      </a>
    </div>

    <!-- 3. Two-Column Layout: Table + Detail Panel -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">

      <!-- Left: Customer Registry Table -->
      <div class="xl:col-span-8 bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-outline-variant/30 flex items-center justify-between">
          <div>
            <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Registered Customer Directory</h2>
            <p class="text-body-sm text-[12px] text-on-surface-variant">Showing active registry verified through the central hub counter</p>
          </div>
          <span class="text-label-sm font-mono text-on-surface-variant text-[12px]">Page <?= (int)$pg['page'] ?> of <?= (int)$pg['total_pages'] ?></span>
        </div>
        <div class="overflow-x-auto" id="customer-list">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
                <th scope="col" class="py-3.5 px-5 font-bold">Customer / Contact</th>
                <th scope="col" class="py-3.5 px-5 font-bold">LTO License No.</th>
                <th scope="col" class="py-3.5 px-5 font-bold">KYC Status</th>
                <th scope="col" class="py-3.5 px-5 font-bold">Rentals</th>
                <th scope="col" class="py-3.5 px-5 font-bold">Payment Compliance</th>
                <th scope="col" class="py-3.5 px-5 font-bold text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
              <?php foreach ($customerRows as $row): ?>
              <?php
                $fullName = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
                $initials = strtoupper(substr(trim($row['first_name']), 0, 1) . substr(trim($row['last_name']), 0, 1));
                $isActive = ($row['status'] ?? 'active') === 'active';
                $hasActive = (int)$row['active_rentals'] > 0;
                $tripCount = (int)$row['reservation_count'];
                $license = $row['latest_license'] ?? null;
                $phone = $row['phone'] ?? '';
                $address = $row['address'] ?? '';
                $totalSpent = (float)$row['total_spent'];
                $isSelected = $detailRow !== null && (int)$row['id'] === (int)$detailRow['id'];
              ?>
              <tr class="customer-row <?= $isSelected ? 'bg-primary-fixed/20' : 'hover:bg-surface-container-low/60' ?> transition-colors cursor-pointer"
                  <?= $isSelected ? 'data-selected="1" ' : '' ?>data-id="<?= (int)$row['id'] ?>"
                  data-name="<?= e($fullName) ?>"
                  data-initials="<?= htmlspecialchars($initials) ?>"
                  data-phone="<?= htmlspecialchars($phone) ?>"
                  data-email="<?= htmlspecialchars($row['email']) ?>"
                  data-license="<?= htmlspecialchars($license ?: 'N/A') ?>"
                  data-active="<?= $isActive ? 'true' : 'false' ?>"
                  data-has-active="<?= $hasActive ? 'true' : 'false' ?>"
                  data-trips="<?= $tripCount ?>"
                  data-spent="<?= $totalSpent ?>"
                  data-joined="<?= htmlspecialchars(formatDate($row['created_at'])) ?>"
                  data-address="<?= htmlspecialchars($address ?: 'Dagupan City, Pangasinan') ?>"
                  data-reserved="<?= htmlspecialchars($row['reservation_count']) ?>"
                  onclick="selectClient(this)">
                <td class="py-3.5 px-5">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full <?= $isActive ? 'bg-primary text-on-primary' : 'bg-amber-100 text-amber-800' ?> font-bold text-xs flex items-center justify-center <?= $hasActive ? 'ring-2 ring-emerald-500' : '' ?>">
                      <?= e($initials) ?>
                    </div>
                    <div class="flex flex-col">
                      <span class="font-semibold text-on-surface flex items-center gap-1.5">
                        <?= e($fullName) ?>
                        <?php if ($hasActive): ?>
                        <span class="w-2 h-2 rounded-full bg-emerald-500" title="Active on road"></span>
                        <?php endif; ?>
                      </span>
                      <span class="text-[12px] text-on-surface-variant"><?= htmlspecialchars($phone ?: 'No phone') ?></span>
                      <span class="text-[11px] text-on-surface-variant/80"><?= htmlspecialchars($row['email']) ?></span>
                    </div>
                  </div>
                </td>
                <td class="py-3.5 px-5 font-mono text-[12px] text-on-surface">
                  <?= $license ? htmlspecialchars($license) : '<span class="text-on-surface-variant font-sans">Not yet filed</span>' ?>
                </td>
                <td class="py-3.5 px-5">
                  <?php if ($isActive): ?>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-800 border border-emerald-200 text-[11px] font-bold">
                    <i aria-hidden="true" class="bi bi-patch-check text-[13px]"></i>
                    Verified
                  </span>
                  <?php else: ?>
                  <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-amber-50 text-amber-800 border border-amber-200 text-[11px] font-bold">
                    <i aria-hidden="true" class="bi bi-hourglass text-[13px]"></i>
                    Pending Review
                  </span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-5 font-semibold text-on-surface">
                  <?= $tripCount ?> rental<?= $tripCount !== 1 ? 's' : '' ?>
                  <?php if ($hasActive): ?>
                  <span class="block text-[11px] text-primary font-medium">1 Active Now</span>
                  <?php elseif ($tripCount > 0): ?>
                  <span class="block text-[11px] text-on-surface-variant">Completed</span>
                  <?php else: ?>
                  <span class="block text-[11px] text-on-surface-variant/70">New Customer</span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-5">
                  <?php if ($totalSpent > 0): ?>
                  <span class="inline-flex items-center gap-1 text-[12px] text-on-surface font-medium">
                    <i aria-hidden="true" class="bi bi-cash-stack text-[16px] text-emerald-600"></i>
                    <?= formatCurrency($totalSpent) ?> spent
                  </span>
                  <?php else: ?>
                  <span class="inline-flex items-center gap-1 text-[12px] text-on-surface-variant">
                    <i aria-hidden="true" class="bi bi-hourglass-split text-[16px] text-amber-700"></i>
                    No transactions yet
                  </span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-5 text-right">
                  <div class="flex items-center justify-end gap-1.5">
                    <!-- "Inspect KYC" used to live here, but it just called
                         selectClient(), which the whole row already does on
                         click. Removed as a duplicate of the row affordance. -->
                    <a href="<?= BASE_URL ?>/admin/reservations.php?customer=<?= (int)$row['id'] ?>#rentals-ledger" class="w-8 h-8 rounded-full flex items-center justify-center text-on-surface-variant hover:text-on-surface hover:bg-surface-container transition-colors" title="View rental history" aria-label="View rental history for <?= e($fullName) ?>" onclick="event.stopPropagation()">
                      <i aria-hidden="true" class="bi bi-clock-history text-[18px]"></i>
                    </a>
                    <form method="POST" action="<?= BASE_URL ?>/admin/customers.php" class="contents"
                          data-confirm="<?= $isActive ? 'Deactivate this customer? They will no longer be able to sign in or book.' : 'Reactivate this customer?' ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="toggle_status" value="<?= (int)$row['id'] ?>">
                      <?php if ($search !== ''): ?>
                      <input type="hidden" name="search" value="<?= e($search) ?>">
                      <?php endif; ?>
                      <?php if ($filter !== ''): ?>
                      <input type="hidden" name="filter" value="<?= e($filter) ?>">
                      <?php endif; ?>
                      <?php if ($pg['page'] > 1): ?>
                      <input type="hidden" name="page" value="<?= (int)$pg['page'] ?>">
                      <?php endif; ?>
                      <button type="submit" class="w-8 h-8 rounded-full flex items-center justify-center transition-colors cursor-pointer <?= $isActive ? 'text-on-surface-variant hover:text-error hover:bg-error-container/60' : 'text-emerald-600 hover:bg-emerald-50' ?>" title="<?= $isActive ? 'Deactivate customer' : 'Reactivate customer' ?>" aria-label="<?= $isActive ? 'Deactivate' : 'Reactivate' ?> <?= e($fullName) ?>">
                        <i class="bi bi-<?= $isActive ? 'person-x' : 'person-check' ?> text-[18px]" aria-hidden="true"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if ($customerNoMatch): ?>
              <tr id="customer-no-match">
                <td colspan="6" class="text-center py-12">
                  <i aria-hidden="true" class="bi bi-person-x text-[48px] text-outline mb-3 block"></i>
                  <p class="text-on-surface-variant text-body-md font-body-md">No customers in this tab.</p>
                </td>
              </tr>
              <?php elseif (empty($customerRows)): ?>
              <tr>
                <td colspan="6" class="text-center py-12">
                  <i aria-hidden="true" class="bi bi-person-x text-[48px] text-outline mb-3 block"></i>
                  <p class="text-on-surface-variant text-body-md font-body-md">No customers found.</p>
                  <?php if ($search || $filter): ?>
                  <a href="<?= BASE_URL ?>/admin/customers.php" class="mt-3 inline-flex items-center gap-1.5 px-4 py-2 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface-variant text-label-md font-label-md hover:bg-surface-container transition-colors">
                    <i aria-hidden="true" class="bi bi-list text-[16px]"></i>
                    Clear Search
                  </a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <!-- Pager. The Previous / 1 / Next buttons that used to sit here were
             dead chrome - the query had no LIMIT, so there was nothing to page
             to. The list really pages now, so the helper renders the real
             pager with its "Showing X-Y of Z" summary. -->
        <?php if ($pg['total'] > 0): ?>
        <div class="px-5 py-3.5 border-t border-outline-variant/30 bg-surface-container-low/30">
          <?= renderPagination($pg, ['summary' => true, 'noun' => 'customers']) ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Right: Customer KYC Detail Side Panel -->
      <div class="xl:col-span-4 bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm p-3 space-y-3 sticky top-24" id="clientDetailPanel">
        <!-- Card Header -->
        <div class="flex items-start justify-between border-b border-outline-variant/30 pb-3">
          <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-full bg-primary text-on-primary font-extrabold text-base flex items-center justify-center ring-4 ring-primary/10 shadow-sm" id="detailAvatar">
              <?= $detailRow ? strtoupper(substr(trim($detailRow['first_name']), 0, 1) . substr(trim($detailRow['last_name']), 0, 1)) : '--' ?>
            </div>
            <div>
              <div class="flex items-center gap-1.5">
                <h3 class="text-[17px] font-headline-sm font-bold text-on-surface" id="detailName"><?= $detailRow ? e(trim($detailRow['first_name'] . ' ' . $detailRow['last_name'])) : 'Select a customer' ?></h3>
                <?php if ($detailRow && $detailRow['status'] === 'active'): ?>
                <i class="bi bi-patch-check text-emerald-600 text-[18px]" title="Verified Customer"></i>
                <?php endif; ?>
              </div>
              <span class="inline-block px-2 py-0.5 rounded-md <?= ($detailRow && $detailRow['status'] === 'active') ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200' ?> text-[11px] font-bold" id="detailBadge">
                <?= ($detailRow && $detailRow['status'] === 'active') ? 'Verified Customer' : 'Pending Verification' ?>
              </span>
            </div>
          </div>
        </div>

        <!-- Contact Details -->
        <div class="space-y-2">
          <span class="text-label-sm font-label-sm text-primary uppercase font-bold tracking-wider flex items-center gap-1.5">
            <i aria-hidden="true" class="bi bi-telephone-outbound text-[16px]"></i>
            Contact Details
          </span>
          <div class="p-2 rounded-md bg-surface-container-low border border-outline-variant/40 space-y-1 text-[13px]">
            <div class="flex justify-between">
              <span class="text-on-surface-variant">Phone:</span>
              <span class="font-mono font-bold text-on-surface" id="detailPhone"><?= $detailRow ? htmlspecialchars($detailRow['phone'] ?: 'N/A') : 'N/A' ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-on-surface-variant">Email:</span>
              <span class="font-mono text-on-surface font-medium text-[12px]" id="detailEmail"><?= $detailRow ? htmlspecialchars($detailRow['email']) : 'N/A' ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-on-surface-variant">Customer ID:</span>
              <span class="font-mono text-on-surface" id="detailId"><?= $detailRow ? '#' . $detailRow['id'] : '#' ?></span>
            </div>
          </div>
        </div>

        <!-- Registered Address -->
        <div class="space-y-2">
          <span class="text-label-sm font-label-sm text-primary uppercase font-bold tracking-wider flex items-center gap-1.5">
            <i aria-hidden="true" class="bi bi-geo-alt text-[16px]"></i>
            Address
          </span>
          <div class="p-2 rounded-md bg-surface-container-low border border-outline-variant/40 space-y-1 text-[13px]">
            <div>
              <span class="text-[11px] uppercase font-bold text-on-surface-variant block">Registered Address:</span>
              <span class="font-medium text-on-surface block" id="detailAddress"><?= $detailRow ? htmlspecialchars($detailRow['address'] ?: 'Dagupan City, Pangasinan') : 'Dagupan City, Pangasinan' ?></span>
            </div>
          </div>
        </div>

        <!-- Rental & Spending -->
        <div class="space-y-2">
          <span class="text-label-sm font-label-sm text-primary uppercase font-bold tracking-wider flex items-center gap-1.5">
            <i aria-hidden="true" class="bi bi-piggy-bank text-[16px]"></i>
            Rental &amp; Spending
          </span>
          <div class="p-2 rounded-md bg-surface-container-low border border-outline-variant/40 space-y-1 text-[13px]">
            <div class="flex justify-between items-center">
              <span class="text-on-surface-variant">Total Rentals:</span>
              <span class="font-bold text-on-surface text-sm" id="detailTrips"><?= $detailRow ? (int)$detailRow['reservation_count'] : 0 ?></span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-on-surface-variant">Total Spent:</span>
              <span class="font-bold text-emerald-800 text-sm" id="detailSpent"><?= $detailRow ? formatCurrency($detailRow['total_spent']) : formatCurrency(0) ?></span>
            </div>
            <div class="flex justify-between">
              <span class="text-on-surface-variant">Member Since:</span>
              <span class="font-mono text-on-surface" id="detailJoined"><?= $detailRow ? formatDate($detailRow['created_at']) : 'N/A' ?></span>
            </div>
          </div>
        </div>

        <!-- LTO License -->
        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-label-sm font-label-sm text-primary uppercase font-bold tracking-wider flex items-center gap-1.5">
              <i aria-hidden="true" class="bi bi-credit-card text-[16px]"></i>
              LTO Driver's License
            </span>
            <span class="text-emerald-700 text-label-sm text-[11px] font-bold bg-emerald-50 px-2 py-0.5 rounded-md" id="detailLicenseBadge">On File</span>
          </div>
          <div class="p-2 rounded-md bg-surface-container-low border border-outline-variant/40 space-y-1 text-[13px]">
            <div class="flex justify-between">
              <span class="text-on-surface-variant">License Number:</span>
              <span class="font-mono font-bold text-on-surface" id="detailLicense"><?= $detailRow ? htmlspecialchars($detailRow['latest_license'] ?: 'Not yet filed') : 'Not yet filed' ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
function selectClient(el) {
  // Update detail panel
  document.getElementById('detailAvatar').innerText = el.dataset.initials;
  document.getElementById('detailName').innerText = el.dataset.name;
  document.getElementById('detailPhone').innerText = el.dataset.phone || 'N/A';
  document.getElementById('detailEmail').innerText = el.dataset.email;
  document.getElementById('detailId').innerText = '#' + el.dataset.id;
  document.getElementById('detailAddress').innerText = el.dataset.address || 'Dagupan City, Pangasinan';
  document.getElementById('detailTrips').innerText = el.dataset.trips;
  var spent = parseFloat(el.dataset.spent || 0);
  document.getElementById('detailSpent').innerText = window.CarvellaNumber ? '\u20B1' + window.CarvellaNumber.groupDigits(spent) : '\u20B1' + spent.toFixed(2);
  document.getElementById('detailJoined').innerText = el.dataset.joined || 'N/A';
  document.getElementById('detailLicense').innerText = el.dataset.license || 'Not yet filed';

  var isActive = el.dataset.active === 'true';
  document.getElementById('detailBadge').innerText = isActive ? 'Verified Customer' : 'Pending Verification';
  document.getElementById('detailBadge').className = 'inline-block px-2 py-0.5 rounded-md text-[11px] font-bold ' + (isActive ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200');
  document.getElementById('detailLicenseBadge').innerText = el.dataset.license ? 'On File' : 'Not yet filed';

  // Highlight selected row
  document.querySelectorAll('.customer-row').forEach(function(r) {
    r.classList.remove('bg-primary-fixed/20');
    r.classList.add('hover:bg-surface-container-low/60');
    r.removeAttribute('data-selected');
  });
  el.classList.add('bg-primary-fixed/20');
  el.classList.remove('hover:bg-surface-container-low/60');
  el.setAttribute('data-selected', '1');
}

(function () {
  var params = new URLSearchParams(window.location.search);
  var vid = params.get('view');
  if (vid) {
    var row = document.querySelector('.customer-row[data-id="' + parseInt(vid, 10) + '"]');
    if (row) selectClient(row);
  }
})();

function openWalkinRegister() {
  document.getElementById('walkinRegisterModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}
function closeWalkinRegister() {
  document.getElementById('walkinRegisterModal').classList.add('hidden');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeWalkinRegister();
});

/* Email: only allow alphanumeric, dots and @ as special characters. */
var wEmail = document.getElementById('w_email');
if (wEmail) {
  wEmail.addEventListener('input', function () {
    var raw = wEmail.value;
    var stripped = raw.replace(/[^A-Za-z0-9.@]/g, '');
    if (stripped !== raw) wEmail.value = stripped;
  });
}

/* Address: only allow letters, numbers, spaces, commas, periods, hyphens and #. */
var wAddress = document.getElementById('w_address');
if (wAddress) {
  wAddress.addEventListener('input', function () {
    var raw = wAddress.value;
    var stripped = raw.replace(/[^A-Za-z0-9 ,.\-#]/g, '');
    if (stripped !== raw) wAddress.value = stripped;
  });
}
</script>

<!-- Register Walk-in Customer Modal -->
<div id="walkinRegisterModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeWalkinRegister()"></div>
  <div class="relative w-full max-w-xl bg-surface-container-lowest rounded-lg shadow-2xl border border-outline-variant/50 max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-5 py-3.5 border-b border-outline-variant/30 sticky top-0 bg-surface-container-lowest z-10">
      <div class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-full bg-primary/10 text-primary flex items-center justify-center">
          <i aria-hidden="true" class="bi bi-person-plus"></i>
        </span>
        <div>
          <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Register Walk-in Customer</h2>
          <p class="text-body-sm text-on-surface-variant text-[12px]">Enroll a new customer at the central hub counter.</p>
        </div>
      </div>
      <button type="button" onclick="closeWalkinRegister()" class="p-2 rounded-md hover:bg-surface-container text-on-surface-variant transition-colors" aria-label="Close Register Walk-in Customer dialog">
        <i aria-hidden="true" class="bi bi-x-lg"></i>
      </button>
    </div>
    <form method="POST" action="" class="p-5 space-y-5">
<?= csrf_field() ?>
      <input type="hidden" name="walkin_register" value="1">

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div>
          <label class="block text-label-md font-label-md text-on-surface mb-2" for="w_first_name">First Name</label>
          <input type="text" id="w_first_name" name="first_name" maxlength="100" required placeholder="Juan" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
        </div>
        <div>
          <label class="block text-label-md font-label-md text-on-surface mb-2" for="w_last_name">Last Name</label>
          <input type="text" id="w_last_name" name="last_name" maxlength="100" required placeholder="Dela Cruz" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
        </div>
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="w_email">Email Address</label>
        <input type="email" id="w_email" name="email" maxlength="255" required placeholder="you@example.com" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="w_phone">Philippine Mobile Number</label>
        <input type="tel" id="w_phone" name="phone" inputmode="numeric" pattern="[0-9]*" maxlength="11" placeholder="09171234567" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="w_address">Address</label>
        <input type="text" id="w_address" name="address" maxlength="255" placeholder="Dagupan City, Pangasinan" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="w_password">Temporary Password <span class="text-on-surface-variant font-normal">(optional)</span></label>
        <input type="text" id="w_password" name="password" maxlength="200" placeholder="Leave blank to auto-generate" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
        <p class="text-[11px] text-on-surface-variant mt-1.5">If left blank, a temporary password is generated and shown after registration.</p>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2 border-t border-outline-variant/30">
        <button type="button" onclick="closeWalkinRegister()" class="px-6 py-2.5 rounded-md border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md inline-flex items-center justify-center hover:bg-surface-container transition-colors">Cancel</button>
        <button type="submit" class="px-6 py-2.5 rounded-md bg-primary text-on-primary font-label-md text-label-md font-semibold hover:bg-secondary transition-all shadow-sm active:scale-95 flex items-center gap-2">
          <i aria-hidden="true" class="bi bi-person-plus text-[18px]"></i>
          Register Customer
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
