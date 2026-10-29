<?php
/* admin/reservations.php - Admin reservations and rental management. */

$pageTitle = 'Manage Rentals - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

// ?customer=<id> narrows the workspace to one client; an unknown id falls back to the full workspace.
$customerFilter = isset($_GET['customer']) && is_numeric($_GET['customer']) ? (int)$_GET['customer'] : 0;
$customerFilterName = '';
if ($customerFilter > 0) {
    $cs = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
    $cs->bind_param('i', $customerFilter);
    $cs->execute();
    $cr = $cs->get_result()->fetch_assoc();
    $cs->close();
    if ($cr) {
        $customerFilterName = trim($cr['first_name'] . ' ' . $cr['last_name']);
    } else {
        $customerFilter = 0;
    }
}
/* Server-side queue search: ?q= narrows the query so a match counts on any page; the term is capped to maxlength. */
$searchTerm = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($searchTerm) > 100) {
    $searchTerm = mb_substr($searchTerm, 0, 100);
}

/* Transaction log search has its own ?tx_q= parameter so it never filters the dispatch queue; the term is capped. */
$txSearch = isset($_GET['tx_q']) && is_string($_GET['tx_q']) ? trim($_GET['tx_q']) : '';
if (mb_strlen($txSearch) > 100) {
    $txSearch = mb_substr($txSearch, 0, 100);
}

/* Ledger status tab, read before the POST handlers so a redirect can land back on the tab it was fired from. */
$rentalAction = $_GET['action'] ?? 'list';
if (!in_array($rentalAction, ['list', 'active', 'in_progress', 'overdue', 'pending', 'completed', 'cancelled'], true)) {
    $rentalAction = 'list';
}
$rentalActionLabels = [
    'active'      => 'Active',
    'in_progress' => 'In Progress',
    'overdue'     => 'Overdue',
    'pending'     => 'Pending',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
];

/* PRG: redirects re-append the active filters, and the fragment goes last so it is not read as a query value. */
$filterParams = [];
if ($customerFilter > 0) {
    $filterParams['customer'] = $customerFilter;
}
if ($rentalAction !== 'list') {
    $filterParams['action'] = $rentalAction;
}
if ($searchTerm !== '') {
    $filterParams['q'] = $searchTerm;
}
if ($txSearch !== '') {
    $filterParams['tx_q'] = $txSearch;
}
$filterQuery = $filterParams !== [] ? '?' . http_build_query($filterParams) : '';

/* One WHERE feeds the COUNT, the step aggregate and the page query, so the pager total, the badges and the rows cannot disagree. */
$joins = 'FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id JOIN users u ON r.user_id = u.id ';
$where = 'WHERE 1=1';
$params = [];
$types = '';

if ($customerFilter > 0) {
    $where .= ' AND r.user_id = ?';
    $params[] = $customerFilter;
    $types .= 'i';
}

if ($searchTerm !== '') {
    // One haystack over the same fields the old client-side filter scanned: reference, customer name, vehicle and plate.
    $where .= " AND CONCAT_WS(' ', r.booking_reference, u.first_name, u.last_name, v.brand, v.model, v.license_plate) LIKE ?";
    $params[] = '%' . $searchTerm . '%';
    $types .= 's';
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS count " . $joins . $where);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['count'];
$countStmt->close();

$pg = paginationBounds(paginationPage(), $total, 25);
$perPage = $pg['per_page'];
$offset  = $pg['offset'];

/* The step badges count the whole filtered queue, not this page: a page-scoped count would hide bookings still waiting one page over. */
$stepCounts = ['pending' => 0, 'confirmed' => 0, 'active' => 0, 'finished' => 0];
$stepStmt = $conn->prepare("SELECT r.status, COUNT(*) AS count " . $joins . $where . " GROUP BY r.status");
if (!empty($params)) {
    $stepStmt->bind_param($types, ...$params);
}
$stepStmt->execute();
$stepResult = $stepStmt->get_result();
while ($srow = $stepResult->fetch_assoc()) {
    if (isset($stepCounts[$srow['status']])) {
        $stepCounts[$srow['status']] = (int)$srow['count'];
    }
}
$stepStmt->close();
// "finished" is the remainder after the other three steps, so it counts every row in the filtered set.
$stepCounts['finished'] = max(0, $total - $stepCounts['pending'] - $stepCounts['confirmed'] - $stepCounts['active']);

// id breaks created_at ties so rows booked in the same second cannot swap pages and appear twice or not at all.
$query = "SELECT r.*, v.brand, v.model, v.year, v.type, v.transmission, v.fuel_type, v.license_plate, v.daily_rate, u.first_name, u.last_name, u.email, u.phone " . $joins . $where . " ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?";
$pageParams = $params;
$pageParams[] = $perPage;
$pageParams[] = $offset;
$pageTypes = $types . 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($pageTypes, ...$pageParams);
$stmt->execute();
$reservations = $stmt->get_result();

$reservationRows = [];
while ($rrow = $reservations->fetch_assoc()) {
    $reservationRows[] = $rrow;
}
$stmt->close();

rentalTrackingSchemaReady($conn);
$flaggedOverdue = flagOverdueRentals($conn);
if ($flaggedOverdue > 0) {
    logActivity('overdue', "$flaggedOverdue rental(s) automatically flagged as overdue");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['reservation_action'])) {
    verify_csrf();

    $action = (string)$_POST['reservation_action'];
    $adminId = (int)$_SESSION['user_id'];
    $backToRentals = ($_POST['return_tab'] ?? '') === 'rentals' ? '#rentals-ledger' : '';

    if ($action === 'approve') {
        $result = approveReservationRecord($conn, (int)($_POST['reservation_id'] ?? 0), $adminId);
        if (!empty($result['success'])) {
            logActivity('approved', "Reservation #{$result['booking_reference']} confirmed");
            // Report the reconciled fleet state so a stale status is visibly corrected; a held unit reads 'rented' = reserved, not on the road.
            $suffix = '';
            if (!empty($result['vehicle_status'])) {
                $statusPhrase = [
                    'rented'      => 'reserved for these dates',
                    'available'   => 'available again',
                    'maintenance' => 'in maintenance',
                ][$result['vehicle_status']] ?? $result['vehicle_status'];
                $suffix = ' Vehicle #' . (int)$result['vehicle_id'] . ' is now ' . $statusPhrase . '.';
            }
            setFlash('success', 'Reservation approved and dates locked.' . $suffix, 'Reservation approved');
        } else {
            setFlash('error', $result['error'], 'Approval failed');
        }
    } elseif ($action === 'batch_approve') {
        $approved = 0;
        $skipped = 0;
        $reasons = [];
        $pending = $conn->query("SELECT id FROM reservations WHERE status = 'pending'");
        $pendingIds = [];
        while ($prow = $pending->fetch_assoc()) {
            $pendingIds[] = (int)$prow['id'];
        }
        $pending->close();
        foreach ($pendingIds as $pendingId) {
            $result = approveReservationRecord($conn, $pendingId, $adminId);
            if (!empty($result['success'])) {
                $approved++;
            } else {
                $skipped++;
                $reasons[$result['error']] = ($reasons[$result['error']] ?? 0) + 1;
            }
        }
        if ($approved) {
            logActivity('approved', "Batch approved $approved pending reservation(s)");
        }
        $msg = "Batch approve complete: $approved approved";
        if ($skipped) {
            $detail = [];
            foreach ($reasons as $reason => $count) {
                $detail[] = "$count x $reason";
            }
            $msg .= ', ' . $skipped . ' skipped (' . implode('; ', $detail) . ')';
        }
        setFlash(
            $skipped && !$approved ? 'error' : 'success',
            $msg,
            $skipped && !$approved ? 'Batch approval failed' : 'Batch approval complete'
        );
    } elseif ($action === 'cancel') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        $result = cancelReservationRecord($conn, (int)($_POST['reservation_id'] ?? 0), $reason, $adminId);
        if (!empty($result['success'])) {
            logActivity('cancelled', "Reservation #{$result['booking_reference']} cancelled" . ($reason !== '' ? ": $reason" : ''));
            setFlash('success', 'Reservation cancelled and dates released', 'Reservation cancelled');
        } else {
            setFlash('error', $result['error'], 'Cancel failed');
        }
    } elseif ($action === 'verify_deposit' || $action === 'reject_deposit') {
        /* The counter settles the deposit by matching the GCash reference against the transfer on their screen, without touching the booking. */
        $depositStatus = $action === 'verify_deposit' ? 'verified' : 'rejected';
        $result = setReservationPaymentStatus($conn, (int)($_POST['reservation_id'] ?? 0), $depositStatus, $adminId);
        if (!empty($result['success'])) {
            logActivity(
                $depositStatus === 'verified' ? 'approved' : 'cancelled',
                'GCash deposit of ' . formatCurrency($result['amount']) . ' (ref ' . $result['reference'] . ') marked ' . $depositStatus
            );
            $depositMessage = $depositStatus === 'verified'
                ? 'GCash deposit of ' . formatCurrency($result['amount']) . ' verified. Ref ' . $result['reference'] . '.'
                : 'GCash deposit of ' . formatCurrency($result['amount']) . ' rejected. Ref ' . $result['reference'] . ' - settle this with the customer before the car is released.';
            setFlash('success', $depositMessage, 'Deposit ' . ucfirst($depositStatus));
        } else {
            setFlash('error', $result['error'], 'Deposit review failed');
        }
    } elseif ($action === 'dispatch') {
        $result = dispatchRentalRecord($conn, [
            'reservation_id' => (int)($_POST['reservation_id'] ?? 0),
            'pickup_date' => trim((string)($_POST['pickup_date'] ?? '')),
            'expected_return_date' => trim((string)($_POST['expected_return_date'] ?? '')),
            'odometer_start' => trim((string)($_POST['odometer_start'] ?? '')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'actor_id' => $adminId,
        ]);
        if (!empty($result['success'])) {
            logActivity('dispatched', "Vehicle dispatched for reservation #{$result['booking_reference']} (rental #{$result['rental_id']})");
            setFlash('success', 'Vehicle dispatched to ' . ($result['booking_reference'] ?? 'customer'), 'Vehicle dispatched');
        } else {
            setFlash('error', $result['error'], 'Dispatch failed');
        }
        $backToRentals = '#rentals-ledger';
    } elseif ($action === 'complete_rental') {
        // late_fee is no longer sent from the browser: completeRentalRecord() computes it from the return dates and daily rate, so it cannot be edited.
        $result = completeRentalRecord($conn, [
            'rental_id' => (int)($_POST['rental_id'] ?? 0),
            'actual_return_date' => trim((string)($_POST['actual_return_date'] ?? '')),
            'odometer_end' => trim((string)($_POST['odometer_end'] ?? '')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
            'actor_id' => $adminId,
        ]);
        if (!empty($result['success'])) {
            logActivity('completed', "Rental #{$result['rental_id']} returned after {$result['days']} day(s), vehicle released");
            $lateNote = !empty($result['late_fee'])
                ? ' Late fee of ' . formatCurrency($result['late_fee']) . ' applied (' . (int)$result['late_days'] . ' late day(s)).'
                : '';
            setFlash('success', 'Return processed. Vehicle status is now ' . ($result['vehicle_status'] ?: 'updated') . '.' . $lateNote, 'Return processed');
        } else {
            setFlash('error', $result['error'], 'Return failed');
        }
        $backToRentals = '#rentals-ledger';
    }

    // The fragment stays last: ?filters#rentals-ledger, never the reverse.
    redirect(BASE_URL . '/admin/reservations.php' . $filterQuery . $backToRentals);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_walkin'])) {
    verify_csrf();

    $user_id = (int)($_POST['user_id'] ?? 0);
    $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
    $pickup_date = sanitize($_POST['pickup_date'] ?? '');
    $return_date = sanitize($_POST['return_date'] ?? '');
    $pickup_location = sanitize($_POST['pickup_location'] ?? 'Dagupan City Central Hub');
    $return_location = sanitize($_POST['return_location'] ?? 'Dagupan City Central Hub');
    $license_no = sanitize($_POST['license_no'] ?? '');
    $status = $_POST['status'] ?? 'confirmed';
    if (!in_array($status, ['pending', 'confirmed'], true)) {
        $status = 'confirmed';
    }

    $veh = $conn->query("SELECT * FROM vehicles WHERE id = $vehicle_id")->fetch_assoc();
    if (!$user_id || !$veh || $pickup_date === '' || $return_date === '') {
        setFlash('error', 'Please select a client, a vehicle, and valid pickup/return dates.', 'Check the form');
    } elseif (mb_strlen($license_no) > 64) {
        setFlash('error', 'Driver\'s license number is too long.', 'Check the form');
    } elseif ($pickup_date < date('Y-m-d')) {
        setFlash('error', 'Pickup date cannot be in the past.', 'Check the form');
    } else {
        $result = createReservationRecord($conn, [
            'user_id' => $user_id,
            'vehicle_id' => $vehicle_id,
            'pickup_date' => $pickup_date,
            'return_date' => $return_date,
            'pickup_location' => $pickup_location,
            'return_location' => $return_location,
            'license_no' => $license_no,
            'status' => $status,
            'notes' => 'Manual walk-in booking created at the counter',
        ]);
        if (!empty($result['success'])) {
            logActivity('created', "Walk-in reservation {$result['booking_reference']} created for vehicle #$vehicle_id");
            setFlash('success', "Walk-in reservation #{$result['booking_reference']} created for " . formatCurrency($result['total_amount']) . '.', 'Walk-in booked');
        } else {
            setFlash('error', $result['error'], 'Could not book');
        }
    }
    redirect(BASE_URL . '/admin/reservations.php' . $filterQuery);
}

$walkinCustomers = $conn->query("SELECT id, first_name, last_name, email, phone FROM users WHERE role = 'customer' ORDER BY first_name, last_name");
// Only units in the park are offered: status decides whether a car is on the road, and $walkinBusy covers future windows for units still parked.
$walkinVehicles = $conn->query("SELECT id, brand, model, year, license_plate, daily_rate, status FROM vehicles WHERE status = 'available' ORDER BY brand, model");

// The busy predicate matches findConflictingBookings() exactly and overlap is half-open, so the list never offers a clash the server will reject.
$walkinBusy = [];
$walkinBusyResult = $conn->query(
    "SELECT vehicle_id, pickup_date, return_date
       FROM reservations
      WHERE status IN ('pending','confirmed','active')
        AND NOT (
              status = 'active'
              AND EXISTS (
                    SELECT 1 FROM rentals ren
                     WHERE ren.reservation_id = reservations.id
                       AND ren.status IN ('pending','active','in_progress','overdue')
              )
        )
        AND return_date >= CURDATE()"
);
if ($walkinBusyResult) {
    while ($b = $walkinBusyResult->fetch_assoc()) {
        $walkinBusy[(int)$b['vehicle_id']][] = [$b['pickup_date'], $b['return_date']];
    }
}

// Grouped by brand: a flat run of near-identical rows is slow to scan, and the brand is the first thing an operator looks for.
$walkinVehicleGroups = [];
$walkinVehicleTotal = 0;
if ($walkinVehicles) {
    while ($v = $walkinVehicles->fetch_assoc()) {
        $walkinVehicleGroups[$v['brand']][] = $v;
        $walkinVehicleTotal++;
    }
}

$rentalsResult = $conn->query("SELECT ren.*, v.brand, v.model, v.year, v.type, v.license_plate, v.daily_rate, u.first_name, u.last_name, res.booking_reference FROM rentals ren JOIN vehicles v ON ren.vehicle_id = v.id JOIN users u ON ren.user_id = u.id LEFT JOIN reservations res ON res.id = ren.reservation_id ORDER BY ren.created_at DESC");
$rentalsData = [];
$rentalIdForReservation = [];
$rentalReturnForReservation = [];
while ($r = $rentalsResult->fetch_assoc()) {
    $rentalsData[] = $r;
    if (!empty($r['reservation_id'])) {
        $rentalIdForReservation[(int)$r['reservation_id']] = (int)$r['id'];
        $rentalReturnForReservation[(int)$r['reservation_id']] = $r;
    }
}

// Customer scope narrows the ledger rows to one client; the reservation -> rental maps stay complete so bookings resolve their rental row.
if ($customerFilter > 0) {
    $rentalsData = array_values(array_filter($rentalsData, function ($r) use ($customerFilter) {
        return (int)($r['user_id'] ?? 0) === $customerFilter;
    }));
}

/* Tab badges and the All total come from one GROUP BY over the whole ledger, not from the rendered rows, which would zero every badge but one. */
$rentalStatusCounts = ['active' => 0, 'in_progress' => 0, 'pending' => 0, 'completed' => 0, 'cancelled' => 0, 'overdue' => 0];
$statusCountSql = 'SELECT status, COUNT(*) AS tally FROM rentals' . ($customerFilter > 0 ? ' WHERE user_id = ?' : '') . ' GROUP BY status';
$statusCountStmt = $conn->prepare($statusCountSql);
if ($customerFilter > 0) {
    $statusCountStmt->bind_param('i', $customerFilter);
}
$statusCountStmt->execute();
$statusCountResult = $statusCountStmt->get_result();
while ($sc = $statusCountResult->fetch_assoc()) {
    $rentalStatusCounts[$sc['status']] = (int)$sc['tally'];
}
$statusCountStmt->close();
$allRentalCount = array_sum($rentalStatusCounts);

/* Every rental is fetched (cards need their row, KPIs total the set); the status tab only picks which rows RENDER, here not in the browser. */
$displayedRentals = $rentalAction === 'list'
    ? $rentalsData
    : array_values(array_filter($rentalsData, function ($r) use ($rentalAction) {
        return $r['status'] === $rentalAction;
    }));
$filteredRentalCount = count($displayedRentals);

$completedRevenue = 0;
$completedRentalCount = 0;
foreach ($rentalsData as $rd) {
    if ($rd['status'] === 'completed') {
        $completedRevenue += (float)$rd['total_amount'];
        $completedRentalCount++;
    }
}
$avgPerRental = $completedRentalCount > 0 ? $completedRevenue / $completedRentalCount : 0;

/* The transaction log searches in SQL so it reaches the whole ledger; paginated by tx_page to avoid clashing with the dispatch queue pager. */
$txFiltersBase = [];
if ($customerFilter > 0) {
    $txFiltersBase['user_id'] = $customerFilter;
}
if ($txSearch !== '') {
    $txFiltersBase['search'] = $txSearch;
}
$transactionMatchCount = countRentalTransactionHistory($conn, $txFiltersBase);
$txPage = max(1, (int)($_GET['tx_page'] ?? 1));
$txPerPage = 25;
$txBounds = paginationBounds($txPage, $transactionMatchCount, $txPerPage);
$txFilters = $txFiltersBase;
$txFilters['limit'] = $txBounds['per_page'];
$txFilters['offset'] = $txBounds['offset'];
$transactionRows = fetchRentalTransactionHistory($conn, $txFilters);
$txShown = count($transactionRows);
$transactionCounts = [];
foreach ($transactionRows as $tx) {
    $transactionCounts[$tx['event_type']] = ($transactionCounts[$tx['event_type']] ?? 0) + 1;
}
$transactionEventMeta = [
    'reservation_created'   => ['Reservation Created', 'bg-surface-container-high text-on-surface-variant', 'calendar-plus'],
    'reservation_approved'  => ['Reservation Approved', 'bg-secondary-fixed text-on-secondary-fixed', 'patch-check'],
    'reservation_cancelled' => ['Reservation Cancelled', 'bg-error-container text-on-error-container', 'x-circle'],
    'rental_dispatched'     => ['Vehicle Dispatched', 'bg-amber-100 text-amber-800', 'key'],
    'rental_completed'      => ['Rental Returned', 'bg-emerald-100 text-emerald-800', 'clipboard-check'],
    'rental_cancelled'      => ['Rental Cancelled', 'bg-error-container text-on-error-container', 'x-circle'],
    'rental_overdue'        => ['Flagged Overdue', 'bg-error-container text-on-error-container', 'exclamation-triangle'],
    'deposit_captured'      => ['GCash Deposit', 'bg-sky-100 text-sky-800', 'cash-coin'],
];

$actorNames = [];
$actorRows = $conn->query("SELECT id, first_name, last_name FROM users");
while ($actorRow = $actorRows->fetch_assoc()) {
    $actorNames[(int)$actorRow['id']] = trim($actorRow['first_name'] . ' ' . $actorRow['last_name']);
}
$actorRows->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

    <?php /* Every counter action is reported through the same toast component as the Vehicles page, with the outcome in the heading. */
    renderFlashNotices(); ?>

    <?php if ($customerFilter > 0 && $customerFilterName !== ''): ?>
    <!-- Scope banner: this page shows one client's history only; Clear returns to the full workspace. -->
    <div class="bg-primary-fixed/40 border border-primary/30 rounded-lg px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-body-sm">
      <span class="flex items-center gap-2 text-on-surface">
        <i aria-hidden="true" class="bi bi-person-lines-fill text-primary text-[20px]"></i>
        Showing rental history for <strong><?= htmlspecialchars($customerFilterName) ?></strong> only.
      </span>
      <span class="flex flex-wrap items-center gap-2">
        <a href="<?= BASE_URL ?>/admin/customers.php?view=<?= (int)$customerFilter ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-outline-variant/60 hover:bg-surface-container text-on-surface font-label-md text-label-md transition-colors">
          <i aria-hidden="true" class="bi bi-person-vcard text-[16px]"></i>
          Back to client
        </a>
        <a href="<?= BASE_URL ?>/admin/reservations.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md border border-outline-variant/60 hover:bg-surface-container text-on-surface font-label-md text-label-md transition-colors">
          <i aria-hidden="true" class="bi bi-x-circle text-[16px]"></i>
          Show all clients
        </a>
      </span>
    </div>
    <?php endif; ?>

    <!-- Header Section: Title, Search, Date Picker -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Rental &amp; Dispatch Management</h1>
        <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Approve requests, hand over cars, and take them back &mdash; one step at a time.</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <!-- Month/Date label. Not a control: there is no date input behind it, so filtering is done by the status chips below. -->
        <div class="flex items-center bg-surface-container-lowest border border-outline-variant/50 rounded-md px-4 py-2 shadow-sm">
          <i aria-hidden="true" class="bi bi-calendar-date text-primary text-[20px] mr-2"></i>
          <span class="text-label-md font-label-md text-on-surface"><?= date('F Y') ?></span>
        </div>
        <!-- Quick search submits ?q= to the server so it pages; other filters ride along as hidden fields, the page number does not. -->
        <form method="GET" class="relative min-w-[260px] sm:min-w-[320px]">
          <input id="reservationSearchInput" name="q" value="<?= htmlspecialchars($searchTerm) ?>" class="w-full pl-10 pr-4 py-2 text-body-sm rounded-md bg-surface-container-lowest border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 transition-all text-on-surface placeholder:text-outline" placeholder="Search by Rental ID or Customer Name..." maxlength="100" type="text" aria-label="Search rentals by rental ID or customer name">
          <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-outline text-[20px]" aria-hidden="true"></i>
          <?php if ($customerFilter > 0): ?>
          <input type="hidden" name="customer" value="<?= (int)$customerFilter ?>">
          <?php endif; ?>
          <?php if ($rentalAction !== 'list'): ?>
          <input type="hidden" name="action" value="<?= htmlspecialchars($rentalAction) ?>">
          <?php endif; ?>
          <?php if ($txSearch !== ''): ?>
          <!-- The ledger's own search rides along, so searching the queue does not wipe the transaction history term. -->
          <input type="hidden" name="tx_q" value="<?= htmlspecialchars($txSearch) ?>">
          <?php endif; ?>
        </form>
        <!-- Dispatch Configuration CTA -->
        <button type="button" onclick="openWalkinModal()" class="px-4 py-2 rounded-md bg-primary text-on-primary font-label-md text-label-md flex items-center gap-2 hover:bg-primary-container transition-all shadow-sm active:scale-95">
          <i aria-hidden="true" class="bi bi-plus-circle text-[18px]"></i>
          <span>Manual Walk-In</span>
        </button>
      </div>
    </div>

    <!-- Three-step dispatch queue for counter staff: approve, hand over, take back; each step lists only bookings that need an action now. -->
    <?php
    $queue = ['pending' => [], 'confirmed' => [], 'active' => [], 'finished' => []];
    foreach ($reservationRows as $row) {
        $queueStatus = strtolower((string)$row['status']);
        if ($queueStatus === 'pending') {
            $queue['pending'][] = $row;
        } elseif ($queueStatus === 'confirmed') {
            $queue['confirmed'][] = $row;
        } elseif ($queueStatus === 'active') {
            $queue['active'][] = $row;
        } else {
            $queue['finished'][] = $row;
        }
    }

    ?>

    <!-- Combined dispatch queue: one table with every booking, and a Stage column showing where it sits in the approve / hand-over / take-back flow. -->
    <?php
    $dispatchRows = [];
    foreach (['pending', 'confirmed', 'active'] as $stageKey) {
        foreach ($queue[$stageKey] as $queueRow) {
            $dispatchRows[] = ['stage' => $stageKey, 'row' => $queueRow];
        }
    }
    ?>
    <section class="space-y-3">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-headline-sm font-headline-sm text-on-surface font-bold">Dispatch Queue</h2>
          <p class="text-body-sm text-on-surface-variant">Every booking in one place &mdash; approve, hand over, and take back without switching tables.</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <span class="px-3 py-1 rounded-md bg-amber-100 text-amber-800 text-label-md font-bold"><?= $stepCounts['pending'] ?> waiting</span>
          <span class="px-3 py-1 rounded-md bg-secondary-fixed text-on-secondary-fixed text-label-md font-bold"><?= $stepCounts['confirmed'] ?> ready</span>
          <span class="px-3 py-1 rounded-md bg-emerald-100 text-emerald-800 text-label-md font-bold"><?= $stepCounts['active'] ?> on the road</span>
          <span class="px-3 py-1 rounded-md bg-surface-container-high text-on-surface-variant text-label-md font-bold"><?= $stepCounts['finished'] ?> finished</span>
          <?php /* The batch action approves every waiting booking in the system, so its visibility follows the aggregate count. */ ?>
          <?php if ($stepCounts['pending'] > 0 && $customerFilter === 0): ?>
          <form method="POST" action="" data-confirm="Approve every waiting rental that has no date conflict? Rentals with a clash are skipped and reported.">
            <?= csrf_field() ?>
            <input type="hidden" name="reservation_action" value="batch_approve">
            <button type="submit" class="px-4 py-2 rounded-md border border-primary/40 text-primary hover:bg-secondary-fixed text-label-md font-label-md transition-all cursor-pointer inline-flex items-center gap-1.5">
              <i aria-hidden="true" class="bi bi-check2-all text-[18px]"></i>
              Approve all waiting
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="bg-surface-container-lowest border border-outline-variant/40 rounded-2xl shadow-sm w-full overflow-hidden">
        <div class="w-full">
          <table class="w-full table-auto text-left border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
              <th class="py-3.5 px-4">Stage</th>
              <th class="py-3.5 px-4">Customer</th>
              <th class="py-3.5 px-4">Contact</th>
              <th class="py-3.5 px-4">Vehicle</th>
              <th class="py-3.5 px-4">Schedule</th>
              <th class="py-3.5 px-4">Pickup Hub</th>
              <th class="py-3.5 px-4">Payment</th>
              <th class="py-3.5 px-4 text-right">Total</th>
              <th class="py-3.5 px-4 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/20 text-body-sm">
            <?php if (empty($dispatchRows)): ?>
            <tr><td colspan="9" class="py-10 text-center text-on-surface-variant">No active rentals in the dispatch queue right now.</td></tr>
            <?php else: ?>
            <?php foreach ($dispatchRows as $entry):
              $stageKey = $entry['stage'];
              $row = $entry['row'];
              $ref = (string)($row['booking_reference'] ?? '');
              $name = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
              $initial = strtoupper(substr((string)($row['first_name'] ?? ''), 0, 1));
              $contact = ($row['phone'] ?? '') !== '' ? (string)$row['phone'] : (string)($row['email'] ?? '');
              $vehicle = trim(((string)($row['brand'] ?? '')) . ' ' . ((string)($row['model'] ?? '')));
              $plate = (string)($row['license_plate'] ?? '');
              $total = (float)($row['total_amount'] ?? 0);
              $hub = trim((string)($row['pickup_location'] ?? '')) !== '' ? (string)$row['pickup_location'] : 'Dagupan Central Hub';
              $puDate = (string)($row['pickup_date'] ?? '');
              $reDate = (string)($row['return_date'] ?? '');
              $days = max(1, (int)round((strtotime($reDate) - strtotime($puDate)) / 86400));
              $rentalId = (int)($rentalIdForReservation[(int)$row['id']] ?? 0);
              $rental = $rentalReturnForReservation[(int)$row['id']] ?? [];
              $rPickup = (string)($rental['pickup_date'] ?? $puDate);
              $rExpected = (string)($rental['expected_return_date'] ?? $reDate);
              $paymentMethod = strtolower((string)($row['payment_method'] ?? ''));
              $paymentStatus = strtolower((string)($row['payment_status'] ?? ''));
              $paymentAmount = (float)($row['payment_amount'] ?? 0);
              $paymentRef = (string)($row['payment_reference'] ?? '');

              if ($stageKey === 'pending') {
                  $stageLabel = '1 &middot; Approve';
                  $stageClass = 'bg-amber-100 text-amber-800';
                  $stageIcon = 'hourglass-split';
              } elseif ($stageKey === 'confirmed') {
                  $stageLabel = '2 &middot; Hand over';
                  $stageClass = 'bg-secondary-fixed text-on-secondary-fixed';
                  $stageIcon = 'key';
              } elseif ($stageKey === 'active') {
                  $stageLabel = '3 &middot; On the road';
                  $stageClass = 'bg-emerald-100 text-emerald-800';
                  $stageIcon = 'car-front';
              } else {
                  $finSt = strtolower((string)($row['status'] ?? ''));
                  if ($finSt === 'completed') {
                      $stageLabel = 'Completed';
                      $stageClass = 'bg-emerald-100 text-emerald-800';
                      $stageIcon = 'check-circle';
                  } elseif ($finSt === 'cancelled') {
                      $stageLabel = 'Cancelled';
                      $stageClass = 'bg-surface-container-high text-on-surface-variant';
                      $stageIcon = 'x-circle';
                  } else {
                      $stageLabel = ucfirst($finSt);
                      $stageClass = 'bg-surface-container-high text-on-surface-variant';
                      $stageIcon = 'dash-circle';
                  }
              }
            ?>
            <tr class="hover:bg-surface-container-low transition-colors align-top">
              <td class="py-3.5 px-4">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md <?= $stageClass ?> text-[11px] font-bold">
                  <i aria-hidden="true" class="bi bi-<?= $stageIcon ?> text-[13px]"></i>
                  <?= $stageLabel ?>
                </span>
              </td>
              <td class="py-3.5 px-4">
                <div class="flex items-center gap-2.5 min-w-0">
                  <span class="w-8 h-8 rounded-full bg-secondary inline-flex items-center justify-center text-on-secondary font-bold text-[11px] shrink-0"><?= htmlspecialchars($initial) ?></span>
                  <div class="min-w-0">
                    <p class="font-bold text-on-surface truncate"><?= htmlspecialchars($name) ?></p>
                    <p class="text-[11px] text-tertiary font-mono truncate">#<?= htmlspecialchars($ref) ?></p>
                  </div>
                </div>
              </td>
              <td class="py-3.5 px-4 text-[12px] text-on-surface-variant truncate"><?= htmlspecialchars($contact) ?></td>
              <td class="py-3.5 px-4">
                <p class="font-semibold text-on-surface truncate"><?= htmlspecialchars($vehicle) ?></p>
                <span class="inline-block mt-0.5 px-2 py-0.5 rounded bg-surface-container text-on-surface-variant font-mono text-[10px] font-bold"><?= htmlspecialchars($plate) ?></span>
              </td>
              <td class="py-3.5 px-4">
                <p class="font-semibold text-on-surface text-[12px] truncate"><?= formatDate($puDate) ?> <?= formatTime(($row['pickup_time'] ?? '') ?: '10:00') ?></p>
                <p class="text-[11px] text-on-surface-variant truncate">&rarr; <?= formatDate($reDate) ?> <?= formatTime(($row['return_time'] ?? '') ?: '18:00') ?> &middot; <?= $days ?>d</p>
              </td>
              <td class="py-3.5 px-4 text-[11px] text-on-surface-variant">
                <span class="block truncate" title="<?= htmlspecialchars($hub) ?>"><?= htmlspecialchars($hub) ?></span>
              </td>
              <td class="py-3.5 px-4">
                <?php if ($paymentMethod !== '' && $paymentAmount > 0): ?>
                  <p class="text-[11px] font-bold text-primary">GCash <?= formatCurrency($paymentAmount) ?></p>
                  <p class="text-[9px] font-mono text-tertiary truncate">Ref <?= htmlspecialchars($paymentRef) ?></p>
                  <?php if ($paymentStatus === 'verified'): ?>
                    <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[9px] font-bold">
                      <i aria-hidden="true" class="bi bi-patch-check text-[10px]"></i> Verified
                    </span>
                  <?php elseif ($paymentStatus === 'rejected'): ?>
                    <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded bg-error-container text-on-error-container text-[9px] font-bold">
                      <i aria-hidden="true" class="bi bi-x-circle text-[10px]"></i> Rejected
                    </span>
                  <?php else: ?>
                    <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[9px] font-bold">
                      <i aria-hidden="true" class="bi bi-hourglass-split text-[10px]"></i> To verify
                    </span>
                    <div class="mt-1.5 flex gap-1">
                      <form method="POST" action="" class="flex-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="reservation_action" value="verify_deposit">
                        <input type="hidden" name="reservation_id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="w-full px-1.5 py-0.5 rounded bg-emerald-600 hover:bg-emerald-700 text-white text-[9px] font-bold transition-colors cursor-pointer" title="Confirm">Verify</button>
                      </form>
                      <form method="POST" action="" class="flex-1" data-confirm="Reject deposit?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="reservation_action" value="reject_deposit">
                        <input type="hidden" name="reservation_id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="w-full px-1.5 py-0.5 rounded border border-outline-variant/70 text-on-surface-variant hover:bg-error-container text-[9px] font-bold transition-colors cursor-pointer" title="Reject">Reject</button>
                      </form>
                    </div>
                  <?php endif; ?>
                <?php else: ?>
                  <p class="text-emerald-700 text-[11px] font-medium">Cash</p>
                <?php endif; ?>
              </td>
              <td class="py-3.5 px-4 text-right font-bold text-on-surface text-[12px]"><?= formatCurrency($total) ?></td>
              <td class="py-3.5 px-4">
                <div class="flex flex-col items-end gap-1">
                  <?php if ($stageKey === 'pending'): ?>
                    <form method="POST" action="" class="w-full">
                      <?= csrf_field() ?>
                      <input type="hidden" name="reservation_action" value="approve">
                      <input type="hidden" name="reservation_id" value="<?= (int)$row['id'] ?>">
                      <button type="submit" class="w-full px-2 py-1 rounded-md bg-primary text-on-primary text-[11px] font-bold hover:bg-primary-container transition-colors cursor-pointer">Approve</button>
                    </form>
                    <form method="POST" action="" class="w-full" data-confirm="Reject and cancel rental #<?= htmlspecialchars($ref) ?>?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="reservation_action" value="cancel">
                      <input type="hidden" name="reservation_id" value="<?= (int)$row['id'] ?>">
                      <input type="hidden" name="reason" value="Rejected at counter review">
                      <button type="submit" class="w-full px-2 py-1 rounded-md border border-outline-variant/70 text-on-surface-variant hover:bg-error-container hover:text-on-error-container text-[11px] font-bold transition-colors cursor-pointer">Reject</button>
                    </form>
                  <?php elseif ($stageKey === 'confirmed'): ?>
                    <button type="button" onclick="openDispatchModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($ref) ?>', '<?= htmlspecialchars($puDate) ?>', '<?= htmlspecialchars($reDate) ?>', '<?= htmlspecialchars($vehicle) ?>', '<?= htmlspecialchars($plate) ?>')" class="w-full px-2 py-1 rounded-md bg-emerald-600 text-white text-[11px] font-bold hover:bg-emerald-700 transition-colors cursor-pointer">Hand over</button>
                    <form method="POST" action="" class="w-full" data-confirm="Cancel this confirmed rental?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="reservation_action" value="cancel">
                      <input type="hidden" name="reservation_id" value="<?= (int)$row['id'] ?>">
                      <input type="hidden" name="reason" value="Cancelled by admin">
                      <button type="submit" class="w-full px-2 py-1 rounded-md border border-outline-variant/70 text-on-surface-variant hover:bg-error-container hover:text-on-error-container text-[11px] font-bold transition-colors cursor-pointer">Cancel</button>
                    </form>
                  <?php elseif ($stageKey === 'active'): ?>
                    <?php if ($rentalId > 0): ?>
                    <button type="button" onclick="openReturnModal(<?= $rentalId ?>, '<?= htmlspecialchars($ref) ?>', '<?= htmlspecialchars($vehicle) ?>', '<?= htmlspecialchars($plate) ?>', '<?= htmlspecialchars($rPickup) ?>', '<?= htmlspecialchars($rExpected) ?>', <?= (float)($row['daily_rate'] ?? 0) ?>)" class="w-full px-2 py-1 rounded-md bg-amber-500 text-white text-[11px] font-bold hover:bg-amber-600 transition-colors cursor-pointer">Take car back</button>
                    <?php else: ?>
                    <button type="button" disabled title="No rental record" class="w-full px-2 py-1 rounded-md bg-amber-500/60 text-white text-[11px] font-bold cursor-not-allowed">Take car back</button>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/admin/customers.php?view=<?= (int)$row['user_id'] ?>" class="w-full px-2 py-1 rounded-md border border-outline-variant/70 text-on-surface-variant hover:bg-s-surface-container text-[11px] font-bold transition-colors text-center">Customer</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- renderPagination() rebuilds every link from the query string so the client scope, status tab and search term survive paging. -->
    <?= renderPagination($pg, ['summary' => true, 'noun' => 'reservations']) ?>

    <!-- Rentals & Dispatch Ledger: it starts collapsed because the dispatch queue is primary, and opens on demand or when the URL targets it. -->
    <details id="rentals-ledger" class="pt-2 ledger-disclosure"<?= $customerFilter > 0 ? ' open' : '' ?>>
      <summary class="ledger-disclosure__summary list-none cursor-pointer">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
          <div>
            <div class="flex items-center gap-2.5">
              <h2 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Rentals &amp; Dispatch Ledger</h2>
              <span class="hidden sm:inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-emerald-100 text-emerald-800 text-label-sm font-semibold">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Fleet Dispatch
              </span>
            </div>
            <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Fleet rental records, dispatch completions, and return ledger for the Carvella central hub.</p>
          </div>
          <span class="ledger-disclosure__toggle inline-flex items-center gap-2 px-4 py-2 rounded-md border border-outline-variant/60 text-on-surface font-label-md text-label-md whitespace-nowrap transition-colors hover:bg-surface-container">
            <span class="ledger-disclosure__label">Show ledger</span>
            <i aria-hidden="true" class="bi bi-chevron-down text-[16px] ledger-disclosure__chevron transition-transform"></i>
          </span>
        </div>
      </summary>
      <div class="space-y-6 mt-4">

      <!-- 4 KPI Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
          <div class="space-y-1">
            <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Total Rentals</span>
            <div class="text-numeric-stat font-numeric-stat font-extrabold text-on-surface"><?= $allRentalCount ?></div>
            <span class="text-on-surface-variant text-label-sm text-[12px]">All-time dispatches</span>
          </div>
        </div>
        <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
          <div class="space-y-1">
            <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Completed Revenue</span>
            <div class="text-numeric-stat font-numeric-stat font-extrabold text-emerald-700"><?= formatCurrency($completedRevenue) ?></div>
            <span class="text-on-surface-variant text-label-sm text-[12px]"><?= $completedRentalCount ?> completed rentals</span>
          </div>
        </div>
        <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
          <div class="space-y-1">
            <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Avg per Rental</span>
            <div class="text-numeric-stat font-numeric-stat font-extrabold text-primary"><?= formatCurrency($avgPerRental) ?></div>
            <span class="text-on-surface-variant text-label-sm text-[12px]">Yield metric</span>
          </div>
        </div>
        <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
          <div class="space-y-1">
            <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Active Dispatches</span>
            <div class="text-numeric-stat font-numeric-stat font-extrabold text-amber-700"><?= $rentalStatusCounts['active'] + $rentalStatusCounts['in_progress'] ?></div>
            <span class="text-amber-800 text-label-sm text-[12px] font-medium">Currently on-road</span>
          </div>
        </div>
      </div>

      <!-- Filter Tabs. Rows are server-filtered, so each tab is a real link; queryUrl() keeps the scope and search term but drops the page number. -->
      <div id="ledger-filters" style="scroll-margin-top: 84px;" class="flex items-center gap-2 overflow-x-auto pb-1 border-b border-outline-variant/30">
        <a href="<?= e(queryUrl(['action' => null])) ?>#ledger-filters" class="px-4 py-2 rounded-md <?= $rentalAction === 'list' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> text-label-md font-label-md font-bold whitespace-nowrap transition-colors flex items-center gap-1.5">
          All <span class="px-2 py-0.5 rounded-md <?= $rentalAction === 'list' ? 'bg-on-primary/20' : 'bg-surface-container-high text-tertiary' ?> text-[11px] font-bold"><?= $allRentalCount ?></span>
        </a>
        <?php
        $rentalFilterMap = [
            'active'     => ['Active', 'bg-amber-100 text-amber-800'],
            'in_progress'=> ['In Progress', 'bg-secondary-fixed text-on-secondary-fixed'],
            'overdue'    => ['Overdue', 'bg-error-container text-on-error-container'],
            'pending'    => ['Pending', 'bg-amber-100 text-amber-800'],
            'completed'  => ['Completed', 'bg-emerald-100 text-emerald-800'],
            'cancelled'  => ['Cancelled', 'bg-error-container text-on-error-container'],
        ];
        foreach ($rentalFilterMap as $key => $info):
        ?>
        <a href="<?= e(queryUrl(['action' => $key])) ?>#ledger-filters" class="px-4 py-2 rounded-md <?= $rentalAction === $key ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> text-label-md font-label-md whitespace-nowrap transition-colors flex items-center gap-1.5">
          <?= $info[0] ?>
          <span class="px-2 py-0.5 rounded-md <?= $rentalAction === $key ? 'bg-on-primary/20' : $info[1] ?> text-[11px] font-bold"><?= $rentalStatusCounts[$key] ?? 0 ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Rental Records Table -->
      <div class="bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-outline-variant/30 flex flex-wrap items-center justify-between gap-3 bg-surface-container-low/40">
          <div class="flex items-center gap-3">
            <span class="text-label-lg font-label-lg text-on-surface font-bold">Rental Records</span>
            <span id="ledger-count-label" class="text-body-sm text-tertiary"><?= $filteredRentalCount ?> record<?= $filteredRentalCount !== 1 ? 's' : '' ?><?= $rentalAction !== 'list' ? ' (' . htmlspecialchars($rentalActionLabels[$rentalAction] ?? ucfirst($rentalAction)) . ')' : '' ?></span>
          </div>
          <span class="text-label-sm font-label-sm text-outline">Sorted by date (newest)</span>
        </div>

        <!-- Pre-filtered by the server: this region holds the open tab's rows or the empty state, with nothing to toggle client-side. -->
        <div id="ledger-list">
        <?php if (empty($displayedRentals)): ?>
        <div class="text-center py-16 px-6">
          <i aria-hidden="true" class="bi bi-car-front text-[48px] text-outline mb-3 block"></i>
          <p class="text-on-surface-variant text-label-lg font-label-lg">No rentals found.</p>
          <p class="text-tertiary text-body-sm mt-1">There are no rental records matching the current filter.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
                <th scope="col" class="py-3.5 px-5">ID</th>
                <th scope="col" class="py-3.5 px-5">Customer</th>
                <th scope="col" class="py-3.5 px-5">Vehicle</th>
                <th scope="col" class="py-3.5 px-5">Rental Period &amp; Actual Return</th>
                <th scope="col" class="py-3.5 px-5 text-right">Total</th>
                <th scope="col" class="py-3.5 px-5">Status</th>
                <th scope="col" class="py-3.5 px-5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
              <?php foreach ($displayedRentals as $row): ?>
              <?php
                $rst = $row['status'];
                $rentalStatusClasses = [
                    'completed'  => 'bg-emerald-100 text-emerald-800',
                    'pending'    => 'bg-amber-100 text-amber-800',
                    'active'     => 'bg-secondary-fixed text-on-secondary-fixed',
                    'in_progress'=> 'bg-secondary-fixed text-on-secondary-fixed',
                    'overdue'    => 'bg-error-container text-on-error-container',
                    'cancelled'  => 'bg-surface-container-high text-on-surface-variant',
                ];
                $rentalPillClass = $rentalStatusClasses[$rst] ?? 'bg-surface-container-high text-on-surface-variant';
                $customerInitial = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
              ?>
              <tr class="hover:bg-surface-container-low/40 transition-colors">
                <td class="py-4 px-5 font-mono text-tertiary">#<?= (int)$row['id'] ?></td>
                <td class="py-4 px-5">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-secondary-fixed text-on-secondary-fixed font-bold text-[11px] flex items-center justify-center"><?= e($customerInitial) ?></div>
                    <span class="font-semibold text-on-surface"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></span>
                  </div>
                </td>
                <td class="py-4 px-5">
                  <div>
                    <span class="font-semibold text-on-surface"><?= htmlspecialchars($row['year'] . ' ' . $row['brand'] . ' ' . $row['model']) ?></span>
                    <span class="inline-block ml-1.5 px-2 py-0.5 rounded bg-surface-container text-on-surface-variant font-mono text-[10px] font-bold"><?= htmlspecialchars($row['license_plate'] ?? '') ?></span>
                  </div>
                </td>
                <td class="py-4 px-5">
                  <div class="flex flex-col">
                    <span class="font-semibold text-on-surface"><?= formatDate($row['pickup_date']) ?> <span class="text-tertiary text-[11px]">→</span> <?= formatDate($row['expected_return_date']) ?></span>
                    <span class="text-tertiary text-[12px]">
                      <?= $row['actual_return_date'] ? 'Returned ' . formatDate($row['actual_return_date']) : 'Not yet returned' ?>
                      <?php if (!empty($row['duration'])): ?>
                        &middot; <?= (int)$row['duration'] ?> day<?= (int)$row['duration'] > 1 ? 's' : '' ?>
                      <?php endif; ?>
                    </span>
                  </div>
                </td>
                <td class="py-4 px-5 text-right font-bold text-on-surface">
                  <?= formatCurrency($row['total_amount']) ?>
                  <?php if (!empty($row['late_fee'])): ?>
                    <div class="text-[11px] text-error-container font-semibold">incl. <?= formatCurrency($row['late_fee']) ?> late fee</div>
                  <?php endif; ?>
                </td>
                <td class="py-4 px-5">
                  <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md <?= $rentalPillClass ?> text-label-sm font-bold">
                    <?= ucfirst(htmlspecialchars(str_replace('_', ' ', $rst))) ?>
                  </span>
                </td>
                <td class="py-4 px-5 text-right">
                  <?php if (in_array($rst, ['active', 'in_progress', 'overdue', 'pending'], true)): ?>
                    <button type="button" onclick="openReturnModal(<?= (int)$row['id'] ?>, '<?= htmlspecialchars($row['booking_reference'] ?? ('Rental #' . (int)$row['id'])) ?>', '<?= htmlspecialchars($row['year'] . ' ' . $row['brand'] . ' ' . $row['model']) ?>', '<?= htmlspecialchars($row['license_plate'] ?? '') ?>', '<?= htmlspecialchars($row['pickup_date']) ?>', '<?= htmlspecialchars($row['expected_return_date']) ?>', <?= (float)($row['daily_rate'] ?? 0) ?>)" class="w-8 h-8 rounded-full bg-amber-100 text-amber-800 hover:bg-amber-200 border border-amber-200 inline-flex items-center justify-center transition-all cursor-pointer" title="Process return" aria-label="Process return">
                      <i aria-hidden="true" class="bi bi-clipboard-check text-[16px]"></i>
                    </button>
                  <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-emerald-100 text-emerald-800 text-label-sm font-bold">
                      <i aria-hidden="true" class="bi bi-check-circle text-[14px]"></i>
                      Settled
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        </div>

        <!-- The rental records do not page; the dispatch queue does, so its pager stands here (an identical copy ends that queue). -->
        <div class="px-5 py-3.5 border-t border-outline-variant/30 bg-surface-container-low/20">
          <?= renderPagination($pg, ['summary' => true, 'noun' => 'reservations']) ?>
        </div>
      </div>
      </div>
    </details>

    <!-- Full Transaction History Ledger -->
    <section id="transaction-history" class="space-y-4 pt-2">
      <!-- One row: the title and search summary on the left, the search box and export on the right, so the ledger itself stays on screen. -->
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div class="min-w-0">
          <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface tracking-tight">Full Transaction History</h2>
          <p class="text-label-sm font-label-sm text-on-surface-variant mt-0.5" id="tx-search-summary">
            <?php
            if ($txSearch !== '') {
                echo number_format($transactionMatchCount) . ' match' . ($transactionMatchCount === 1 ? '' : 'es') . ' for "' . htmlspecialchars($txSearch) . '"';
            } else {
                echo number_format($transactionMatchCount) . ' recorded event' . ($transactionMatchCount === 1 ? '' : 's');
            }
            ?>
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2 lg:shrink-0">
          <!-- Ledger search. A GET form, so it reaches the whole ledger; the queue search, scope and ledger tab ride along as hidden fields. -->
          <form method="GET" id="tx-search-form" class="flex items-center gap-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest pl-3 pr-1 py-1 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10 transition-all">
            <i aria-hidden="true" class="bi bi-search text-outline text-[16px] shrink-0"></i>
            <input type="search" name="tx_q" value="<?= htmlspecialchars($txSearch) ?>" maxlength="100" class="w-[140px] sm:w-[220px] bg-transparent border-0 p-0 focus:outline-none focus:ring-0 text-body-sm font-body-sm text-on-surface placeholder:text-outline" placeholder="Search transactions..." aria-label="Search the transaction history by booking reference, customer, vehicle, plate, event or note">
            <?php if ($txSearch !== ''): ?>
            <a href="<?= e(queryUrl(['tx_q' => null])) ?>" title="Clear search" aria-label="Clear search" class="shrink-0 grid place-items-center w-6 h-6 rounded-full text-outline hover:text-on-surface hover:bg-surface-container transition-colors">
              <i aria-hidden="true" class="bi bi-x-circle text-[15px]"></i>
            </a>
            <?php endif; ?>
            <?php if ($searchTerm !== ''): ?>
            <input type="hidden" name="q" value="<?= htmlspecialchars($searchTerm) ?>">
            <?php endif; ?>
            <?php if ($customerFilter > 0): ?>
            <input type="hidden" name="customer" value="<?= (int)$customerFilter ?>">
            <?php endif; ?>
            <?php if ($rentalAction !== 'list'): ?>
            <input type="hidden" name="action" value="<?= htmlspecialchars($rentalAction) ?>">
            <?php endif; ?>
            <button type="submit" class="shrink-0 h-8 px-3 rounded-full bg-primary text-on-primary font-label-sm text-label-sm font-bold hover:bg-primary-container transition-colors">Search</button>
          </form>

          <a href="<?= BASE_URL ?>/admin/reports.php?export=1&amp;dataset=rental_transactions" class="px-3 py-2 rounded-full border border-outline-variant/60 hover:bg-surface-container-low text-on-surface font-label-md text-label-md flex items-center gap-1.5 transition-colors whitespace-nowrap">
            <i aria-hidden="true" class="bi bi-file-earmark-arrow-down text-[16px]"></i>
            <span class="hidden sm:inline">Export CSV</span>
          </a>
        </div>
      </div>

      <div class="flex items-center gap-2 overflow-x-auto pb-1" id="tx-filters">
        <button type="button" data-tx-filter="all" class="px-4 py-2 rounded-md bg-primary text-on-primary font-label-md text-label-md whitespace-nowrap flex items-center gap-1.5 cursor-pointer transition-colors">
          <i aria-hidden="true" class="bi bi-clock-history text-[16px]"></i>
          All Events
          <span data-tx-badge data-idle="bg-surface-container-high text-on-surface-variant" class="px-2 py-0.5 rounded-md bg-on-primary/20 text-[11px] font-bold"><?= $transactionMatchCount ?></span>
        </button>
        <?php foreach ($transactionEventMeta as $key => $meta): ?>
          <?php if (empty($transactionCounts[$key])) continue; ?>
          <button type="button" data-tx-filter="<?= htmlspecialchars($key) ?>" class="px-4 py-2 rounded-md bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md whitespace-nowrap flex items-center gap-1.5 cursor-pointer transition-colors">
            <?= $meta[0] ?>
            <span data-tx-badge data-idle="<?= htmlspecialchars($meta[1]) ?>" class="px-2 py-0.5 rounded-md <?= $meta[1] ?> text-[11px] font-bold"><?= (int)$transactionCounts[$key] ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <div class="bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm overflow-hidden">
        <?php if (empty($transactionRows)): ?>
        <div class="text-center py-16 px-6">
          <?php if ($txSearch !== ''): ?>
          <!-- A search that matched nothing is not an empty ledger, so it says so and offers the way back. -->
          <i aria-hidden="true" class="bi bi-search text-[48px] text-outline mb-3 block"></i>
          <p class="text-on-surface-variant text-label-lg font-label-lg">No events match &ldquo;<?= htmlspecialchars($txSearch) ?>&rdquo;.</p>
          <p class="text-tertiary text-body-sm mt-1">Try a booking reference, a customer name, a vehicle or plate, an event name, or a word from the note.</p>
          <a href="<?= e(queryUrl(['tx_q' => null])) ?>" class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-md border border-outline-variant/60 text-on-surface font-label-md text-label-md hover:bg-surface-container-low transition-colors">
            <i aria-hidden="true" class="bi bi-x-circle text-[18px]"></i>
            <span>Clear the search</span>
          </a>
          <?php else: ?>
          <i aria-hidden="true" class="bi bi-clock-history text-[48px] text-outline mb-3 block"></i>
          <p class="text-on-surface-variant text-label-lg font-label-lg">No transactions recorded yet.</p>
          <p class="text-tertiary text-body-sm mt-1">Events appear here as rentals are created, approved, dispatched, and returned.</p>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
          <table id="tx-table" class="w-full text-left border-collapse">
            <thead>
              <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
                <th scope="col" class="py-3.5 px-5">When</th>
                <th scope="col" class="py-3.5 px-5">Event</th>
                <th scope="col" class="py-3.5 px-5">Reference</th>
                <th scope="col" class="py-3.5 px-5">Vehicle</th>
                <th scope="col" class="py-3.5 px-5">Status Change</th>
                <th scope="col" class="py-3.5 px-5">Note</th>
                <th scope="col" class="py-3.5 px-5">Rental Period</th>
                <th scope="col" class="py-3.5 px-5 text-right">Amount</th>
                <th scope="col" class="py-3.5 px-5">Recorded By</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
              <?php foreach ($transactionRows as $tx): ?>
                <?php
                  $meta = $transactionEventMeta[$tx['event_type']] ?? [ucfirst(str_replace('_', ' ', $tx['event_type'])), 'bg-surface-container-high text-on-surface-variant', 'clock-history'];
                  $actorName = $tx['actor_id'] ? ($actorNames[$tx['actor_id']] ?? 'User #' . (int)$tx['actor_id']) : 'System';
                ?>
              <tr class="tx-row hover:bg-surface-container-low/40 transition-colors" data-event="<?= htmlspecialchars($tx['event_type']) ?>">
                <td class="py-3.5 px-5 whitespace-nowrap text-tertiary"><?= htmlspecialchars(formatDateTime($tx['created_at'])) ?></td>
                <td class="py-3.5 px-5">
                  <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md <?= $meta[1] ?> text-label-sm font-bold">
                    <i aria-hidden="true" class="bi bi-<?= $meta[2] ?> text-[14px]"></i>
                    <?= $meta[0] ?>
                  </span>
                </td>
                <td class="py-3.5 px-5 font-mono text-primary text-[12px]">
                  <?= htmlspecialchars($tx['booking_reference'] ?: ($tx['rental_id'] ? 'Rental #' . (int)$tx['rental_id'] : '—')) ?>
                </td>
                <td class="py-3.5 px-5">
                  <?php if ($tx['vehicle_id']): ?>
                    <span class="font-semibold text-on-surface"><?= htmlspecialchars(trim(($tx['year'] ?? '') . ' ' . ($tx['brand'] ?? '') . ' ' . ($tx['model'] ?? ''))) ?></span>
                    <span class="inline-block ml-1.5 px-2 py-0.5 rounded bg-surface-container text-on-surface-variant font-mono text-[10px] font-bold"><?= htmlspecialchars($tx['license_plate'] ?? '') ?></span>
                  <?php else: ?>
                    <span class="text-outline">—</span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-5 text-[12px]">
                  <?php if ($tx['from_status'] || $tx['to_status']): ?>
                    <span class="text-tertiary"><?= htmlspecialchars(str_replace('_', ' ', (string)$tx['from_status'])) ?></span>
                    <i aria-hidden="true" class="bi bi-arrow-right text-[14px] text-primary align-middle"></i>
                    <span class="font-semibold text-on-surface"><?= htmlspecialchars(str_replace('_', ' ', (string)$tx['to_status'])) ?></span>
                  <?php else: ?>
                    <span class="text-outline">—</span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-5 text-[12px] text-on-surface-variant max-w-[280px]">
                  <?php /* The note must be on screen, not only in the database: the search looks inside it for a GCash reference. */ ?>
                  <?php if (!empty($tx['notes'])): ?>
                    <?= htmlspecialchars($tx['notes']) ?>
                  <?php else: ?>
                    <span class="text-outline">—</span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-5 text-[12px] text-on-surface-variant whitespace-nowrap">
                  <?php if ($tx['pickup_date']): ?>
                    <?= htmlspecialchars(formatDate($tx['pickup_date'])) ?> &rarr; <?= htmlspecialchars(formatDate($tx['expected_return_date'] ?: $tx['pickup_date'])) ?>
                    <?php if ($tx['actual_return_date']): ?>
                      <span class="block text-emerald-700">returned <?= htmlspecialchars(formatDate($tx['actual_return_date'])) ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-outline">—</span>
                  <?php endif; ?>
                </td>
                <td class="py-3.5 px-5 text-right font-semibold text-on-surface"><?= (float)$tx['amount'] > 0 ? formatCurrency($tx['amount']) : '<span class="text-outline">—</span>' ?></td>
                <td class="py-3.5 px-5 text-[12px] text-on-surface-variant"><?= htmlspecialchars($actorName) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (!empty($transactionRows) && isset($txBounds) && $txBounds['total_pages'] > 1): ?>
        <div class="px-5 py-3.5 border-t border-outline-variant/30 bg-surface-container-low/20 flex items-center justify-between gap-3 flex-wrap">
          <p class="text-body-sm text-on-surface-variant">Showing <?= $txBounds['from'] ?>–<?= $txBounds['to'] ?> of <?= $transactionMatchCount ?> events</p>
          <nav class="flex items-center gap-2" aria-label="Transaction pagination">
            <?php if ($txBounds['page'] > 1): ?>
              <a href="<?= e(queryUrl(['tx_page' => $txBounds['page'] - 1])) ?>" class="w-9 h-9 rounded-full flex items-center justify-center border border-outline-variant/60 text-on-surface-variant hover:border-primary hover:text-primary transition-all"><i class="bi bi-chevron-left"></i></a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $txBounds['total_pages']; $i++): ?>
              <?php if ($i == $txBounds['page']): ?>
                <span class="w-9 h-9 rounded-full flex items-center justify-center bg-primary text-on-primary font-bold"><?= $i ?></span>
              <?php else: ?>
                <a href="<?= e(queryUrl(['tx_page' => $i])) ?>" class="w-9 h-9 rounded-full flex items-center justify-center border border-outline-variant/60 text-on-surface-variant hover:border-primary hover:text-primary transition-all"><?= $i ?></a>
              <?php endif; ?>
            <?php endfor; ?>
            <?php if ($txBounds['page'] < $txBounds['total_pages']): ?>
              <a href="<?= e(queryUrl(['tx_page' => $txBounds['page'] + 1])) ?>" class="w-9 h-9 rounded-full flex items-center justify-center border border-outline-variant/60 text-on-surface-variant hover:border-primary hover:text-primary transition-all"><i class="bi bi-chevron-right"></i></a>
            <?php endif; ?>
          </nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

  </main>
</div>

<style>
/* Ledger disclosure: a native <details> still collapses without JS; these rules only hide the marker and rotate the chevron. */
#rentals-ledger > summary { list-style: none; }
#rentals-ledger > summary::-webkit-details-marker { display: none; }
#rentals-ledger > summary::marker { content: ""; }
#rentals-ledger[open] .ledger-disclosure__chevron { transform: rotate(180deg); }
</style>

<script>
/* Transaction log filter chips: each chip shows only the rows whose data-event matches, so the chip count and the visible rows agree. */
(function () {
    var wrap = document.getElementById('tx-filters');
    var table = document.getElementById('tx-table');
    if (!wrap || !table) return;
    var tabs = Array.prototype.slice.call(wrap.querySelectorAll('[data-tx-filter]'));
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-event]'));
    if (!tabs.length) return;

    var TAB_ACTIVE = ['bg-primary', 'text-on-primary', 'shadow-sm'];
    var TAB_IDLE = ['bg-surface-container-lowest', 'border', 'border-outline-variant/60', 'text-on-surface-variant'];

    function paint(activeKey) {
        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-tx-filter') === activeKey;
            TAB_IDLE.forEach(function (c) { tab.classList.toggle(c, !on); });
            TAB_ACTIVE.forEach(function (c) { tab.classList.toggle(c, on); });
            var badge = tab.querySelector('[data-tx-badge]');
            if (!badge) return;
            var idle = (badge.getAttribute('data-idle') || '').split(/\s+/).filter(Boolean);
            idle.forEach(function (c) { badge.classList.toggle(c, !on); });
            badge.classList.toggle('bg-on-primary/20', on);
        });
    }

    function apply(key) {
        rows.forEach(function (row) {
            var show = key === 'all' || row.getAttribute('data-event') === key;
            row.style.display = show ? '' : 'none';
        });
        paint(key);
    }

    var current = 'all';
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var key = tab.getAttribute('data-tx-filter') || 'all';
            if (key === current) return;
            current = key;
            apply(key);
        });
    });

    apply('all');
})();

/* Rentals ledger disclosure: opens when the URL points inside the ledger and keeps the summary label in sync on every toggle. */
(function () {
    var details = document.getElementById('rentals-ledger');
    if (!details) return;
    var label = details.querySelector('.ledger-disclosure__label');

    function syncLabel() {
        if (label) label.textContent = details.open ? 'Hide ledger' : 'Show ledger';
    }
    details.addEventListener('toggle', syncLabel);
    syncLabel();

    function openIfTargeted() {
        var hash = window.location.hash;
        if (!hash || hash.length < 2) return;
        var target = null;
        try { target = document.querySelector(hash); } catch (e) { target = null; }
        if (target && (target === details || details.contains(target))) details.open = true;
    }
    openIfTargeted();
    window.addEventListener('hashchange', openIfTargeted);
})();

/* Vehicle pick: the browser's overlap test mirrors the server's half-open rule, and createReservationRecord() re-checks on submit. */
function walkinNextDay(dateStr) {
    // UTC-anchored on purpose: a local-midnight Date read back with toISOString() rolls back a day east of UTC.
    var d = new Date(dateStr + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + 1);
    return d.toISOString().slice(0, 10);
}

/* A same-day range covers one day, so both sides are widened first, as findConflictingBookings() does on the server. */
function walkinRangesOverlap(aStart, aEnd, bStart, bEnd) {
    if (!aStart || !bStart) return false;
    if (aEnd <= aStart) aEnd = walkinNextDay(aStart);
    if (bEnd <= bStart) bEnd = walkinNextDay(bStart);
    return aStart < bEnd && aEnd > bStart;
}

/* Moving the pickup past the return would invalidate the range, so the return floor follows the pickup. */
function syncWalkinReturnFloor() {
    var pickup = document.getElementById('walkin_pickup');
    var ret = document.getElementById('walkin_return');
    if (!pickup || !ret || !pickup.value) return;
    ret.min = pickup.value;
    // Same-day is allowed, so the value only moves when it would sit strictly before the pickup.
    if (ret.value && ret.value < pickup.value) {
        ret.value = pickup.value;
    }
}

function syncWalkinVehicles() {
    var sel = document.getElementById('walkin_vehicle');
    if (!sel) return;
    var pickup = document.getElementById('walkin_pickup');
    var ret = document.getElementById('walkin_return');
    var note = document.getElementById('walkin_vehicle_note');
    var start = pickup ? pickup.value : '';
    var end = ret ? ret.value : '';
    var usable = Boolean(start && end && end >= start);
    var blocked = 0;

    Array.prototype.forEach.call(sel.options, function (opt) {
        if (!opt.value) return;
        // data-label holds the pristine text: the clash suffix is re-applied each pass, so appending to textContent would compound it.
        var base = opt.getAttribute('data-label') || opt.textContent;
        opt.disabled = false;
        opt.textContent = base;
        if (!usable) return;
        var busy = [];
        try { busy = JSON.parse(opt.getAttribute('data-busy') || '[]'); } catch (e) { busy = []; }
        var clash = busy.some(function (range) {
            return Array.isArray(range) && walkinRangesOverlap(start, end, range[0], range[1]);
        });
        if (clash) {
            opt.disabled = true;
            opt.textContent = base + ' \u2014 already booked';
            blocked++;
        }
    });

    // A selection that has just become a clash must not survive the change.
    var chosen = sel.options[sel.selectedIndex];
    if (chosen && chosen.disabled) sel.value = '';

    if (note) {
        if (!usable) {
            note.textContent = 'Pick both dates to see which units are already out.';
        } else if (blocked) {
            note.textContent = blocked + ' of ' + (sel.options.length - 1) + ' units already booked for these dates.';
        } else {
            note.textContent = 'All ' + (sel.options.length - 1) + ' units are free for these dates.';
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var pickup = document.getElementById('walkin_pickup');
    var ret = document.getElementById('walkin_return');
    if (pickup) {
        pickup.addEventListener('change', function () {
            syncWalkinReturnFloor();
            syncWalkinVehicles();
        });
    }
    if (ret) ret.addEventListener('change', syncWalkinVehicles);
});
function openWalkinModal() {
    document.getElementById('walkinModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    syncWalkinReturnFloor();
    syncWalkinVehicles();
}
function closeWalkinModal() {
    document.getElementById('walkinModal').classList.add('hidden');
    document.body.style.overflow = '';
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
    document.body.style.overflow = '';
}
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function openDispatchModal(reservationId, bookingReference, pickupDate, returnDate, vehicleLabel, plate) {
    document.getElementById('dispatch_reservation_id').value = reservationId;
    document.getElementById('dispatch_pickup_date').value = pickupDate || new Date().toISOString().slice(0, 10);
    document.getElementById('dispatch_return_date').value = returnDate || '';
    document.getElementById('dispatch_headline').textContent = bookingReference + ' · ' + vehicleLabel + ' · ' + plate;
    syncDispatchReturnMin();
    openModal('dispatchModal');
}
function syncDispatchReturnMin() {
    var pickup = document.getElementById('dispatch_pickup_date');
    var ret = document.getElementById('dispatch_return_date');
    var info = document.getElementById('dispatch_period_info');
    if (!pickup || !ret) {
        return;
    }
    if (pickup.value) {
        // Same-day handback is a valid one-day rental, so the floor is the pickup date itself.
        ret.min = pickup.value;
    }
    if (pickup.value && ret.value) {
        var days = Math.round((new Date(ret.value) - new Date(pickup.value)) / 86400000);
        info.textContent = days >= 0 ? ('Rental period ' + Math.max(1, days) + ' day(s) · handback due ' + ret.value)
            : 'Return date cannot be before the pickup date';
    } else {
        info.textContent = '—';
    }
}
// Daily rate and expected return date for the rental open in the return modal, at module scope so the date listener can recompute the preview.
var returnDailyRate = 0;
var returnExpectedDate = '';

/* Live preview of the automatic late fee. This is cosmetic: completeRentalRecord() computes the real charge on submit. */
function renderReturnLateFee() {
    var field = document.getElementById('return_late_fee');
    var note = document.getElementById('return_late_fee_note');
    if (!field) return;
    var actual = document.getElementById('return_date').value;
    var expectedMs = Date.parse(returnExpectedDate + 'T00:00:00');
    var actualMs = Date.parse(actual + 'T00:00:00');
    var lateDays = 0;
    if (actual && !isNaN(expectedMs) && !isNaN(actualMs) && actualMs > expectedMs) {
        lateDays = Math.ceil((actualMs - expectedMs) / 86400000);
    }
    var fee = lateDays * returnDailyRate;
    field.value = '<?= CURRENCY ?>' + fee.toFixed(2);
    if (note) {
        note.textContent = lateDays > 0
            ? lateDays + ' late day(s) x <?= CURRENCY ?>' + returnDailyRate.toFixed(2) + '/day, added automatically on return.'
            : 'On time - no late fee will be charged.';
    }
}

function openReturnModal(rentalId, bookingReference, vehicleLabel, plate, pickupDate, expectedReturn, dailyRate) {
    // The modal's own handler rejects rental_id <= 0, so refuse to open rather than let the admin fill in a doomed form.
    if (!(rentalId > 0)) {
        alert('No rental record is linked to this rental yet, so its return cannot be processed.');
        return;
    }
    returnDailyRate = parseFloat(dailyRate) || 0;
    returnExpectedDate = expectedReturn || '';
    // Bind with onchange (not addEventListener) so re-opening replaces the handler; the input lives in markup this script precedes.
    document.getElementById('return_date').onchange = renderReturnLateFee;
    document.getElementById('return_rental_id').value = rentalId;
    document.getElementById('return_date').value = new Date().toISOString().slice(0, 10);
    document.getElementById('return_headline').textContent = bookingReference + ' · ' + vehicleLabel + ' · ' + plate;
    var info = document.getElementById('return_period_info');
    info.textContent = 'Scheduled ' + pickupDate + ' to ' + expectedReturn + (expectedReturn < new Date().toISOString().slice(0, 10) ? ' · OVERDUE' : '');
    renderReturnLateFee();
    openModal('returnModal');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeWalkinModal();
        closeModal('dispatchModal');
        closeModal('returnModal');
    }
});
</script>

<!-- Dispatch Vehicle Modal -->
<div id="dispatchModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('dispatchModal')"></div>
  <div class="relative w-full max-w-xl bg-surface-container-lowest rounded-lg shadow-2xl border border-outline-variant/50 max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-5 py-3.5 border-b border-outline-variant/30 sticky top-0 bg-surface-container-lowest z-10">
      <div class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-full bg-amber-100 text-amber-800 flex items-center justify-center">
          <i aria-hidden="true" class="bi bi-key"></i>
        </span>
        <div>
          <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Dispatch Vehicle</h2>
          <p id="dispatch_headline" class="text-body-sm text-on-surface-variant text-[12px]">—</p>
        </div>
      </div>
      <button type="button" onclick="closeModal('dispatchModal')" class="p-2 rounded-md hover:bg-surface-container text-on-surface-variant transition-colors" aria-label="Close Dispatch Vehicle dialog">
        <i aria-hidden="true" class="bi bi-x-lg"></i>
      </button>
    </div>
    <form method="POST" action="" class="p-5 space-y-5">
<?= csrf_field() ?>
      <input type="hidden" name="reservation_action" value="dispatch">
      <input type="hidden" name="return_tab" value="rentals">
      <input type="hidden" name="reservation_id" id="dispatch_reservation_id" value="0">

      <p class="text-body-sm text-on-surface-variant">This opens an active rental record, marks the rental as on the road, and locks the vehicle as <strong>rented</strong> for its scheduled period.</p>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="dispatch_pickup_date">Actual Pickup Date</label>
        <input type="date" id="dispatch_pickup_date" name="pickup_date" required onchange="syncDispatchReturnMin()" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="dispatch_return_date">Return Handback Date</label>
        <input type="date" id="dispatch_return_date" name="expected_return_date" required onchange="syncDispatchReturnMin()" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
        <p id="dispatch_period_info" class="mt-2 text-body-sm text-on-surface-variant text-[12px]">—</p>
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="dispatch_notes">Handover Notes</label>
        <textarea id="dispatch_notes" name="notes" rows="2" maxlength="500" placeholder="Condition of vehicle, accessories handed over..." class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all"></textarea>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2 border-t border-outline-variant/30">
        <button type="button" onclick="closeModal('dispatchModal')" class="px-6 py-2.5 rounded-md border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md inline-flex items-center justify-center hover:bg-surface-container transition-colors">Cancel</button>
        <button type="submit" class="px-6 py-2.5 rounded-md bg-primary text-on-primary font-label-md text-label-md font-semibold hover:bg-primary-container transition-all shadow-sm active:scale-95 flex items-center gap-2">
          <i aria-hidden="true" class="bi bi-truck text-[18px]"></i>
          Confirm Dispatch
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Process Return Modal -->
<div id="returnModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal('returnModal')"></div>
  <div class="relative w-full max-w-xl bg-surface-container-lowest rounded-lg shadow-2xl border border-outline-variant/50 max-h-[90vh] overflow-y-auto">
    <div class="flex items-center justify-between px-5 py-3.5 border-b border-outline-variant/30 sticky top-0 bg-surface-container-lowest z-10">
      <div class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-800 flex items-center justify-center">
          <i aria-hidden="true" class="bi bi-clipboard-check"></i>
        </span>
        <div>
          <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Process Return</h2>
          <p id="return_headline" class="text-body-sm text-on-surface-variant text-[12px]">—</p>
        </div>
      </div>
      <button type="button" onclick="closeModal('returnModal')" class="p-2 rounded-md hover:bg-surface-container text-on-surface-variant transition-colors" aria-label="Close Process Return dialog">
        <i aria-hidden="true" class="bi bi-x-lg"></i>
      </button>
    </div>
    <form method="POST" action="" class="p-5 space-y-5" data-confirm="Record this vehicle's return? Any late fee is calculated automatically. This closes the rental record and cannot be undone.">
<?= csrf_field() ?>
      <input type="hidden" name="reservation_action" value="complete_rental">
      <input type="hidden" name="return_tab" value="rentals">
      <input type="hidden" name="rental_id" id="return_rental_id" value="0">

      <p id="return_period_info" class="text-body-sm text-on-surface-variant px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/40">—</p>
      <p class="text-body-sm text-on-surface-variant">Returning the vehicle records the actual return date, recomputes the rental duration, closes the rental, and releases the vehicle back to the fleet.</p>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="return_date">Actual Return Date</label>
        <input type="date" id="return_date" name="actual_return_date" required class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="return_late_fee">Late Fee (<?= CURRENCY ?>)</label>
        <input type="text" id="return_late_fee" value="<?= CURRENCY ?>0.00" readonly aria-readonly="true" class="w-full px-4 py-3 rounded-lg bg-surface-container-high border border-outline-variant/60 text-body-md text-on-surface font-semibold cursor-not-allowed">
        <p id="return_late_fee_note" class="text-label-sm font-label-sm text-tertiary mt-1.5">On time - no late fee will be charged.</p>
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="return_notes">Return Notes</label>
        <textarea id="return_notes" name="notes" rows="2" maxlength="500" placeholder="Damage notes, fuel level, cleaning..." class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all"></textarea>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2 border-t border-outline-variant/30">
        <button type="button" onclick="closeModal('returnModal')" class="px-6 py-2.5 rounded-md border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md inline-flex items-center justify-center hover:bg-surface-container transition-colors">Cancel</button>
        <button type="submit" class="px-6 py-2.5 rounded-md bg-primary text-on-primary font-label-md text-label-md font-semibold hover:bg-primary-container transition-all shadow-sm active:scale-95 flex items-center gap-2">
          <i aria-hidden="true" class="bi bi-check2-circle text-[18px]"></i>
          Complete Return
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Manual Walk-In Rental Modal -->
<div id="walkinModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeWalkinModal()"></div>
  <div class="relative w-full max-w-2xl bg-surface-container-lowest rounded-lg shadow-2xl border border-outline-variant/50 max-h-[90vh] overflow-y-auto scrollbar-none">
    <div class="flex items-center justify-between px-5 py-3.5 border-b border-outline-variant/30 sticky top-0 bg-surface-container-lowest z-10">
      <div class="flex items-center gap-3">
        <span class="w-10 h-10 rounded-full bg-primary/10 text-primary flex items-center justify-center">
          <i aria-hidden="true" class="bi bi-signpost-split"></i>
        </span>
        <div>
          <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Manual Walk-In Rental</h2>
          <p class="text-body-sm text-on-surface-variant text-[12px]">Create an on-site rental for a walk-in customer.</p>
        </div>
      </div>
      <button type="button" onclick="closeWalkinModal()" class="p-2 rounded-md hover:bg-surface-container text-on-surface-variant transition-colors" aria-label="Close Walk-in Rental dialog">
        <i aria-hidden="true" class="bi bi-x-lg"></i>
      </button>
    </div>
    <form method="POST" action="" class="p-5 space-y-5">
<?= csrf_field() ?>
      <input type="hidden" name="manual_walkin" value="1">

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="walkin_client">Customer</label>
        <div class="flex items-center gap-3 px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10 transition-all">
          <i aria-hidden="true" class="bi bi-person text-[20px] text-outline"></i>
          <select id="walkin_client" name="user_id" required class="w-full bg-transparent border-0 p-0 focus:ring-0 text-body-md text-on-surface">
            <option value="">Select a registered customer...</option>
            <?php if ($walkinCustomers): while ($c = $walkinCustomers->fetch_assoc()): ?>
            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?><?= $c['phone'] ? " \u{00B7} " . htmlspecialchars($c['phone']) : '' ?></option>
            <?php endwhile; endif; ?>
          </select>
        </div>
        <p class="text-[11px] text-on-surface-variant mt-1.5">Customer not registered yet? <a href="<?= BASE_URL ?>/admin/customers.php" class="text-primary font-semibold hover:underline">Register walk-in customer</a> first.</p>
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="walkin_vehicle">Vehicle</label>
        <div class="flex items-center gap-3 px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10 transition-all">
          <i aria-hidden="true" class="bi bi-car-front text-[20px] text-outline"></i>
          <select id="walkin_vehicle" name="vehicle_id" required
                  <?= $walkinVehicleTotal ? '' : 'disabled' ?>
                  class="w-full bg-transparent border-0 p-0 focus:ring-0 text-body-md text-on-surface">
            <option value=""><?= $walkinVehicleTotal ? 'Select a vehicle...' : 'No vehicles available' ?></option>
            <?php if ($walkinVehicleGroups): foreach ($walkinVehicleGroups as $groupBrand => $groupRows): ?>
            <optgroup label="<?= htmlspecialchars($groupBrand) ?>">
              <?php foreach ($groupRows as $v):
                  $vid   = (int)$v['id'];
                  $busy  = $walkinBusy[$vid] ?? [];
                  $label = trim($v['model'] . ' (' . $v['year'] . ')')
                         . ' ' . "\u{00B7}" . ' ' . $v['license_plate']
                         . ' ' . "\u{2014}" . ' ' . formatCurrency($v['daily_rate']) . '/day';
              ?>
              <option value="<?= $vid ?>"
                      data-label="<?= htmlspecialchars($label, ENT_QUOTES) ?>"
                      data-busy="<?= htmlspecialchars(json_encode($busy), ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; endif; ?>
          </select>
        </div>
        <p class="text-[11px] text-on-surface-variant mt-1.5" id="walkin_vehicle_note">Pick both dates to see which units are already out.</p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div>
          <label class="block text-label-md font-label-md text-on-surface mb-2" for="walkin_pickup">Pickup Date</label>
          <input type="date" id="walkin_pickup" name="pickup_date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
        </div>
        <div>
          <label class="block text-label-md font-label-md text-on-surface mb-2" for="walkin_return">Return Date</label>
          <input type="date" id="walkin_return" name="return_date" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div>
          <label class="block text-label-md font-label-md text-on-surface mb-2" for="walkin_pickup_loc">Pickup Location</label>
          <input type="text" id="walkin_pickup_loc" name="pickup_location" maxlength="255" value="Dagupan City Central Hub" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
        </div>
        <div>
          <label class="block text-label-md font-label-md text-on-surface mb-2" for="walkin_return_loc">Return Location</label>
          <input type="text" id="walkin_return_loc" name="return_location" maxlength="255" value="Dagupan City Central Hub" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
        </div>
      </div>

      <div>
        <label class="block text-label-md font-label-md text-on-surface mb-2" for="walkin_license">LTO Driver's License No.</label>
        <input type="text" id="walkin_license" name="license_no" maxlength="20" placeholder="e.g. N01-23-456789" class="w-full px-4 py-3 rounded-lg bg-surface-container-low border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-body-md text-on-surface transition-all">
      </div>

      <div class="flex items-center justify-end gap-3 pt-2 border-t border-outline-variant/30">
        <button type="button" onclick="closeWalkinModal()" class="px-6 py-2.5 rounded-md border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md inline-flex items-center justify-center hover:bg-surface-container transition-colors">Cancel</button>
        <button type="submit" class="px-6 py-2.5 rounded-md bg-primary text-on-primary font-label-md text-label-md font-semibold hover:bg-primary-container transition-all shadow-sm active:scale-95 flex items-center gap-2">
          <i aria-hidden="true" class="bi bi-check-lg text-[18px]"></i>
          Create Reservation
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of admin/reservations.php */
?>
