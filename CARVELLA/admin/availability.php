<?php
/* availability.php - Carvella Car Rental System */

$pageTitle = 'Rental Tracker & Availability - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

$availFilter = $_GET['view'] ?? 'all';
$focusVehicleId = (int)($_GET['vehicle_id'] ?? 0);

rentalTrackingSchemaReady($conn);
flagOverdueRentals($conn);

$vehicles = $conn->query("SELECT * FROM vehicles WHERE status <> 'archived' ORDER BY brand, model");

$today = date('Y-m-d');
// A dispatched reservation and its rental row are the same booking; the rental
// window is merged below, so skip the reservation copy to avoid showing the
// same period twice on the vehicle card.
$reservations = $conn->query("SELECT r.*, v.brand, v.model, v.year, v.license_plate, v.status as v_status, u.first_name, u.last_name, u.email, u.phone FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id JOIN users u ON r.user_id = u.id WHERE r.status IN ('pending','confirmed','active','completed') AND NOT (r.status = 'active' AND EXISTS (SELECT 1 FROM rentals ren WHERE ren.reservation_id = r.id AND ren.status IN ('pending','active','in_progress','overdue'))) ORDER BY r.pickup_date DESC");

$vehicleBlocks = [];
$allTransactions = [];
$todayBlockedSet = [];
while ($rv = $reservations->fetch_assoc()) {
    $vid = (int)$rv['vehicle_id'];
    if (!isset($vehicleBlocks[$vid])) $vehicleBlocks[$vid] = [];
    $rv['source'] = 'reservation';
    $vehicleBlocks[$vid][] = $rv;
    $allTransactions[] = $rv;
    if (in_array($rv['status'], ['confirmed', 'active']) && $rv['pickup_date'] <= $today && $rv['return_date'] >= $today) {
        $todayBlockedSet[$vid] = true;
    }
}

$activeRentals = $conn->query("SELECT ren.*, v.brand, v.model, v.year, v.license_plate, v.status as v_status, v.type, u.first_name, u.last_name, u.email, u.phone, res.booking_reference FROM rentals ren JOIN vehicles v ON ren.vehicle_id = v.id JOIN users u ON ren.user_id = u.id LEFT JOIN reservations res ON res.id = ren.reservation_id WHERE ren.status IN ('pending','active','in_progress','overdue') ORDER BY ren.pickup_date DESC");
$openRentalCount = 0;
$overdueRentalCount = 0;
while ($rr = $activeRentals->fetch_assoc()) {
    $vid = (int)$rr['vehicle_id'];
    $rr['source'] = 'rental';
    $rr['pickup_date'] = $rr['pickup_date'];
    $rr['return_date'] = rentalRentalPeriodEnd($rr);
    $rr['booking_reference'] = $rr['booking_reference'] ?: ('Rental #' . (int)$rr['id']);
    if (!isset($vehicleBlocks[$vid])) $vehicleBlocks[$vid] = [];
    $vehicleBlocks[$vid][] = $rr;
    $allTransactions[] = $rr;
    $openRentalCount++;
    if ($rr['status'] === 'overdue') {
        $overdueRentalCount++;
    }
    if ($rr['pickup_date'] <= $today && $rr['return_date'] >= $today) {
        $todayBlockedSet[$vid] = true;
    }
}

foreach ($vehicleBlocks as $vid => $list) {
    usort($list, function ($a, $b) {
        return strcmp($a['pickup_date'], $b['pickup_date']);
    });
    $vehicleBlocks[$vid] = $list;
}

usort($allTransactions, function ($a, $b) {
    return strtotime($b['pickup_date']) <=> strtotime($a['pickup_date']);
});
$transactionCount = count($allTransactions);

$focusVehicle = null;
if ($focusVehicleId > 0) {
    $fv = $conn->query("SELECT * FROM vehicles WHERE id = $focusVehicleId")->fetch_assoc();
    $focusVehicle = $fv ?: null;
}

// fetchRentalTransactionHistory() caps by limit with no offset, so the ledger
// pages through its own copy of the same SELECT: COUNT and the page query
// share the vehicle filter, and renderPagination() rebuilds every link from
// the query string so ?view and ?vehicle_id survive paging.
$ledgerWhere = $focusVehicleId > 0 ? ' WHERE tx.vehicle_id = ?' : '';

$countStmt = $conn->prepare("SELECT COUNT(*) AS count FROM rental_transactions tx" . $ledgerWhere);
if ($focusVehicleId > 0) {
    $countStmt->bind_param("i", $focusVehicleId);
}
$countStmt->execute();
$ledgerTotal = (int)$countStmt->get_result()->fetch_assoc()['count'];
$countStmt->close();

$ledgerPg = paginationBounds(paginationPage(), $ledgerTotal, 25);
$ledgerPerPage = $ledgerPg['per_page'];
$ledgerOffset = $ledgerPg['offset'];

$ledgerStmt = $conn->prepare("SELECT tx.*, v.brand, v.model, v.year, v.license_plate, u.first_name, u.last_name, r.booking_reference
        FROM rental_transactions tx
        LEFT JOIN vehicles v ON v.id = tx.vehicle_id
        LEFT JOIN users u ON u.id = tx.user_id
        LEFT JOIN reservations r ON r.id = tx.reservation_id
        $ledgerWhere
        ORDER BY tx.created_at DESC, tx.id DESC
        LIMIT ? OFFSET ?");
if ($focusVehicleId > 0) {
    $ledgerStmt->bind_param("iii", $focusVehicleId, $ledgerPerPage, $ledgerOffset);
} else {
    $ledgerStmt->bind_param("ii", $ledgerPerPage, $ledgerOffset);
}
$ledgerStmt->execute();
$ledgerResult = $ledgerStmt->get_result();
$ledgerRows = [];
while ($ledgerRow = $ledgerResult->fetch_assoc()) {
    $ledgerRows[] = $ledgerRow;
}
$ledgerStmt->close();

$vehiclesList = [];
$totalUnits = 0;
$availableUnits = 0;
$blockedUnits = 0;
$rentedUnits = 0;
while ($v = $vehicles->fetch_assoc()) {
    $totalUnits++;
    $vid = (int)$v['id'];
    $currentlyBlocked = isset($todayBlockedSet[$vid]);
    $hardBlocked = in_array($v['status'], ['rented', 'maintenance', 'unavailable', 'in_progress']);
    if ($currentlyBlocked || $hardBlocked) {
        $blockedUnits++;
        if ($v['status'] === 'rented' || $v['status'] === 'in_progress') $rentedUnits++;
    } else {
        $availableUnits++;
    }
    $vehiclesList[] = $v;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

    <?php if ($flash = getFlash('success')): ?>
    <div class="bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-lg px-4 py-3 flex items-center gap-3 text-body-sm">
      <i aria-hidden="true" class="bi bi-check-circle text-[20px]"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <div class="flex items-center gap-2.5">
          <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Rental Tracker &amp; Availability</h1>
          <span class="hidden sm:inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-emerald-100 text-emerald-800 text-label-sm font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
            Overlap Lock Active
          </span>
        </div>
        <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Track rental dates and status to automatically block unavailable periods and keep a full history of every transaction.</p>
      </div>
    </div>

    <!-- Legend -->
    <div class="flex items-center gap-4 text-label-sm font-label-sm flex-wrap">
      <span class="inline-flex items-center gap-1.5">
        <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
        <span class="text-on-surface-variant">Available</span>
      </span>
      <span class="inline-flex items-center gap-1.5">
        <span class="w-3 h-3 rounded-full bg-primary"></span>
        <span class="text-on-surface-variant">Confirmed Rental</span>
      </span>
      <span class="inline-flex items-center gap-1.5">
        <span class="w-3 h-3 rounded-full bg-secondary"></span>
        <span class="text-on-surface-variant">Active On-Road</span>
      </span>
      <span class="inline-flex items-center gap-1.5">
        <span class="w-3 h-3 rounded-full bg-error"></span>
        <span class="text-on-surface-variant">Blocked / Unavailable</span>
      </span>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Total Fleet Units</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-on-surface"><?= $totalUnits ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Dagupan stationed</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Available Now</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-emerald-700"><?= $availableUnits ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Ready for dispatch</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Blocked / On-Road</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-secondary"><?= $blockedUnits ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]"><?= $rentedUnits ?> actively rented</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Blocked Periods</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-primary"><?= $transactionCount ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]"><?= $openRentalCount ?> open rental<?= $openRentalCount === 1 ? '' : 's' ?></span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Overdue</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold <?= $overdueRentalCount > 0 ? 'text-error-container' : 'text-on-surface' ?>"><?= $overdueRentalCount ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Past expected return</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Transaction Ledger</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-primary"><?= (int)$ledgerTotal ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Recorded events</span>
        </div>
      </div>
    </div>

    <!-- Availability Matrix -->
    <div class="bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3.5 border-b border-outline-variant/30 flex flex-wrap items-center justify-between gap-3 bg-surface-container-low/40">
        <div class="flex items-center gap-3">
          <span class="text-label-lg font-label-lg text-on-surface font-bold">Fleet Availability Matrix</span>
          <span class="text-body-sm text-tertiary">Live utilization per unit — blocked periods auto-computed from rentals</span>
        </div>
      </div>
      <?php if (empty($vehiclesList)): ?>
      <div class="text-center py-16 px-6">

        <p class="text-on-surface-variant text-label-lg font-label-lg">No vehicles in the fleet.</p>
      </div>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
              <th scope="col" class="py-3.5 px-5">Vehicle Unit</th>
              <th scope="col" class="py-3.5 px-5">Plate</th>
              <th scope="col" class="py-3.5 px-5">Daily Rate</th>
              <th scope="col" class="py-3.5 px-5">Current Status</th>
              <th scope="col" class="py-3.5 px-5">Upcoming / Blocked Periods</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
            <?php foreach ($vehiclesList as $v):
              $vStatus = $v['status'];
              $vid = (int)$v['id'];
              if (isset($todayBlockedSet[$vid]) && in_array($vStatus, ['rented', 'in_progress'])) {
                  $statusPill = 'bg-secondary-fixed text-on-secondary-fixed';
                  $statusText = 'Active On-Road';
                  $statusIcon = 'truck';
              } elseif (isset($todayBlockedSet[$vid])) {
                  $statusPill = 'bg-primary/10 text-primary';
                  $statusText = 'Booked Today';
                  $statusIcon = 'calendar-range';
              } elseif (in_array($vStatus, ['rented', 'in_progress'])) {
                  $statusPill = 'bg-secondary-fixed text-on-secondary-fixed';
                  $statusText = 'Active On-Road';
                  $statusIcon = 'truck';
              } elseif (in_array($vStatus, ['maintenance', 'unavailable'])) {
                  $statusPill = 'bg-error-container text-on-error-container';
                  $statusText = 'Blocked / Unavailable';
                  $statusIcon = 'slash-circle';
              } else {
                  $statusPill = 'bg-emerald-100 text-emerald-800';
                  $statusText = 'Available';
                  $statusIcon = 'check-circle';
              }
              $vPeriods = [];
              if (isset($vehicleBlocks[$vid])) {
                  $vPeriods = $vehicleBlocks[$vid];
              }
            ?>
            <tr class="hover:bg-surface-container-low/40 transition-colors">
              <td class="py-4 px-5">
                <a href="availability.php?vehicle_id=<?= $vid ?>" class="font-bold text-on-surface hover:text-primary transition-colors"><?= htmlspecialchars($v['year'] . ' ' . $v['brand'] . ' ' . $v['model']) ?></a>
                <p class="text-tertiary text-[12px]"><?= htmlspecialchars(ucfirst($v['type'])) ?> &middot; <?= htmlspecialchars(ucfirst($v['transmission'])) ?> &middot; <?= htmlspecialchars(ucfirst($v['fuel_type'])) ?></p>
              </td>
              <td class="py-4 px-5">
                <span class="inline-block px-2.5 py-1 rounded-lg bg-surface-container text-on-surface-variant font-mono text-[12px] font-bold"><?= htmlspecialchars($v['license_plate']) ?></span>
              </td>
              <td class="py-4 px-5">
                <span class="font-bold text-on-surface"><?= formatCurrency($v['daily_rate']) ?></span>
                <p class="text-tertiary text-[11px]">per day</p>
              </td>
              <td class="py-4 px-5">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md <?= $statusPill ?> text-label-sm font-bold">
                  <i aria-hidden="true" class="bi bi-<?= $statusIcon ?> text-[14px]"></i>
                  <?= $statusText ?>
                </span>
              </td>
              <td class="py-4 px-5">
                <div class="flex flex-col gap-1.5 min-w-[220px]">
                  <?php if (!empty($vPeriods)): ?>
                    <?php foreach (array_slice($vPeriods, 0, 4) as $p): ?>
                      <?php
                        $pSt = strtolower($p['status']);
                        $isRental = ($p['source'] ?? '') === 'rental';
                        if ($pSt === 'confirmed') {
                            $periodPill = 'bg-primary/10 text-primary border border-primary/30';
                            $periodLabel = 'Confirmed';
                        } elseif (in_array($pSt, ['active', 'in_progress'])) {
                            $periodPill = 'bg-secondary-fixed/60 text-on-secondary-fixed border border-secondary-fixed-dim';
                            $periodLabel = 'Active';
                        } elseif ($pSt === 'overdue') {
                            $periodPill = 'bg-error-container text-on-error-container border border-error-container';
                            $periodLabel = 'Overdue';
                        } elseif ($pSt === 'completed') {
                            $periodPill = 'bg-surface-container-high text-on-surface-variant border border-outline-variant';
                            $periodLabel = 'Completed';
                        } else {
                            $periodPill = 'bg-amber-100 text-amber-800 border border-amber-200';
                            $periodLabel = ucfirst($pSt);
                        }
                      ?>
                      <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg <?= $periodPill ?> text-label-sm font-semibold">
                        <i aria-hidden="true" class="bi bi-<?= $isRental ? 'truck' : 'calendar-range' ?> text-[13px]"></i>
                        <?= formatDate($p['pickup_date']) ?> &rarr; <?= formatDate($p['return_date']) ?>
                        <span class="text-[10px] opacity-70">(<?= $periodLabel ?><?= $isRental ? ', rental' : '' ?>)</span>
                      </span>
                    <?php endforeach; ?>
                    <?php if (count($vPeriods) > 4): ?>
                      <span class="text-[11px] text-tertiary">+<?= count($vPeriods) - 4 ?> more period<?= count($vPeriods) - 4 === 1 ? '' : 's' ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-[12px] text-emerald-700 font-medium">No rentals &mdash; open for dispatch</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Booking & Rental Schedule Ledger -->
    <div class="bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3.5 border-b border-outline-variant/30 flex flex-wrap items-center justify-between gap-3 bg-surface-container-low/40">
        <div class="flex items-center gap-3">
          <span class="text-label-lg font-label-lg text-on-surface font-bold">Rental Schedule</span>
          <span class="text-body-sm text-tertiary"><?= $transactionCount ?> blocked period<?= $transactionCount !== 1 ? 's' : '' ?> across the fleet</span>
        </div>
      </div>
      <?php if ($transactionCount === 0): ?>
      <div class="text-center py-16 px-6">
        <i aria-hidden="true" class="bi bi-receipt text-[48px] text-outline mb-3 block"></i>
        <p class="text-on-surface-variant text-label-lg font-label-lg">No transactions yet.</p>
        <p class="text-tertiary text-body-sm mt-1">Rentals will be logged here automatically.</p>
      </div>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
              <th scope="col" class="py-3.5 px-5">Rental Ref</th>
              <th scope="col" class="py-3.5 px-5">Customer</th>
              <th scope="col" class="py-3.5 px-5">Vehicle</th>
              <th scope="col" class="py-3.5 px-5">Dates Blocked</th>
              <th scope="col" class="py-3.5 px-5 text-right">Amount</th>
              <th scope="col" class="py-3.5 px-5">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
            <?php foreach ($allTransactions as $t):
              $tSt = strtolower($t['status']);
              if ($tSt === 'confirmed') {
                  $tPill = 'bg-primary/10 text-primary border border-primary/30';
                  $tText = 'Confirmed - Blocks Dates';
              } elseif (in_array($tSt, ['active', 'in_progress'])) {
                  $tPill = 'bg-secondary-fixed text-on-secondary-fixed border border-secondary-fixed-dim';
                  $tText = 'Active On-Road';
              } elseif ($tSt === 'overdue') {
                  $tPill = 'bg-error-container text-on-error-container';
                  $tText = 'Overdue - Not Returned';
              } elseif ($tSt === 'completed') {
                  $tPill = 'bg-emerald-100 text-emerald-800';
                  $tText = 'Completed';
              } elseif ($tSt === 'pending') {
                  $tPill = 'bg-amber-100 text-amber-800';
                  $tText = 'Pending';
              } elseif ($tSt === 'cancelled') {
                  $tPill = 'bg-surface-container-high text-on-surface-variant';
                  $tText = 'Cancelled - Dates Released';
              } else {
                  $tPill = 'bg-surface-container-high text-on-surface-variant';
                  $tText = ucfirst($tSt);
              }
              $initial = strtoupper(substr(trim($t['first_name']), 0, 1) . substr(trim($t['last_name']), 0, 1));
            ?>
            <tr class="hover:bg-surface-container-low/40 transition-colors">
              <td class="py-4 px-5">
                <span class="font-bold text-primary font-mono text-label-md">#<?= htmlspecialchars($t['booking_reference']) ?></span>
                <p class="text-tertiary text-[11px]"><?= date('M d, Y', strtotime($t['created_at'])) ?></p>
              </td>
              <td class="py-4 px-5">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded-full bg-secondary-fixed text-on-secondary-fixed font-bold text-[11px] flex items-center justify-center"><?= e($initial) ?></div>
                  <div>
                    <span class="font-bold text-on-surface"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></span>
                    <p class="text-tertiary text-[11px]"><?= htmlspecialchars($t['phone'] ?: $t['email']) ?></p>
                  </div>
                </div>
              </td>
              <td class="py-4 px-5">
                <span class="font-semibold text-on-surface"><?= htmlspecialchars($t['brand'] . ' ' . $t['model']) ?></span>
                <span class="inline-block ml-1.5 px-2 py-0.5 rounded bg-surface-container text-on-surface-variant font-mono text-[10px] font-bold"><?= htmlspecialchars($t['license_plate']) ?></span>
              </td>
              <td class="py-4 px-5">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-surface-container/60 text-on-surface-variant text-label-sm font-semibold">
                  <i aria-hidden="true" class="bi bi-calendar-range text-[13px]"></i>
                  <?= formatDate($t['pickup_date']) ?> &rarr; <?= formatDate($t['return_date']) ?>
                </span>
              </td>
              <td class="py-4 px-5 text-right font-bold text-on-surface"><?= formatCurrency($t['total_amount']) ?></td>
              <td class="py-4 px-5">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md <?= $tPill ?> text-label-sm font-bold">
                  <?= $tText ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Rental Transaction Ledger -->
    <div class="bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3.5 border-b border-outline-variant/30 flex flex-wrap items-center justify-between gap-3 bg-surface-container-low/40">
        <div class="flex items-center gap-3">
          <i aria-hidden="true" class="bi bi-receipt text-primary text-[22px]"></i>
          <div>
            <span class="text-label-lg font-label-lg text-on-surface font-bold block">Rental Transaction History</span>
            <span class="text-body-sm text-tertiary">
              <?php if ($focusVehicle): ?>
                Filtered to <?= htmlspecialchars($focusVehicle['year'] . ' ' . $focusVehicle['brand'] . ' ' . $focusVehicle['model'] . ' (' . $focusVehicle['license_plate'] . ')') ?> &middot;
              <?php endif; ?>
              Every logged event, newest first
            </span>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <?php if ($focusVehicle): ?>
          <a href="availability.php" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-surface-container text-on-surface-variant text-label-sm font-bold hover:bg-surface-container-high transition-colors">
            <i aria-hidden="true" class="bi bi-funnel text-[16px]"></i>
            Clear vehicle filter
          </a>
          <?php else: ?>
          <a href="reports.php?export=1&amp;dataset=rental_transactions&amp;start_date=<?= date('Y-01-01') ?>&amp;end_date=<?= date('Y-12-31', strtotime('+1 year')) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-surface-container text-on-surface-variant text-label-sm font-bold hover:bg-surface-container-high transition-colors">
            <i aria-hidden="true" class="bi bi-download text-[16px]"></i>
            Export ledger
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php if (empty($ledgerRows)): ?>
      <div class="text-center py-16 px-6">
        <i aria-hidden="true" class="bi bi-clock-history text-[48px] text-outline mb-3 block"></i>
        <p class="text-on-surface-variant text-label-lg font-label-lg">No rental events recorded yet.</p>
        <p class="text-tertiary text-body-sm mt-1">Events appear as soon as a rental is created, dispatched, or returned.</p>
      </div>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
              <th scope="col" class="py-3.5 px-5">When</th>
              <th scope="col" class="py-3.5 px-5">Event</th>
              <th scope="col" class="py-3.5 px-5">Vehicle</th>
              <th scope="col" class="py-3.5 px-5">Customer</th>
              <th scope="col" class="py-3.5 px-5">Rental Period</th>
              <th scope="col" class="py-3.5 px-5 text-right">Amount</th>
              <th scope="col" class="py-3.5 px-5">Recorded By</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
            <?php
            $eventMeta = [
                'reservation_created'   => ['Reservation Created', 'bg-surface-container-high text-on-surface-variant border border-outline-variant', 'calendar-plus'],
                'reservation_approved'  => ['Reservation Approved', 'bg-primary/10 text-primary border border-primary/30', 'patch-check'],
                'reservation_cancelled' => ['Reservation Cancelled', 'bg-surface-container-high text-on-surface-variant border border-outline-variant', 'x-circle'],
                'rental_dispatched'     => ['Vehicle Dispatched', 'bg-secondary-fixed text-on-secondary-fixed border border-secondary-fixed-dim', 'truck'],
                'rental_completed'      => ['Vehicle Returned', 'bg-emerald-100 text-emerald-800 border border-emerald-200', 'clipboard-check'],
                'rental_cancelled'      => ['Rental Cancelled', 'bg-surface-container-high text-on-surface-variant border border-outline-variant', 'x-circle'],
                'rental_overdue'        => ['Late Return Flagged', 'bg-error-container text-on-error-container', 'exclamation-triangle'],
            ];
            ?>
            <?php foreach ($ledgerRows as $tx):
              $meta = $eventMeta[$tx['event_type']] ?? [ucwords(str_replace('_', ' ', $tx['event_type'])), 'bg-surface-container text-on-surface-variant', 'info-circle'];
            ?>
            <tr class="hover:bg-surface-container-low/40 transition-colors">
              <td class="py-3.5 px-5 whitespace-nowrap">
                <span class="font-semibold text-on-surface"><?= formatDateTime($tx['created_at']) ?></span>
              </td>
              <td class="py-3.5 px-5">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md <?= $meta[1] ?> text-label-sm font-bold">
                  <i aria-hidden="true" class="bi bi-<?= $meta[2] ?> text-[14px]"></i>
                  <?= $meta[0] ?>
                </span>
                <?php if (!empty($tx['from_status']) && !empty($tx['to_status'])): ?>
                <p class="text-tertiary text-[11px] mt-1"><?= htmlspecialchars(ucfirst($tx['from_status'])) ?> &rarr; <?= htmlspecialchars(ucfirst($tx['to_status'])) ?></p>
                <?php endif; ?>
              </td>
              <td class="py-3.5 px-5">
                <?php if ($tx['brand']): ?>
                <span class="font-semibold text-on-surface"><?= htmlspecialchars($tx['year'] . ' ' . $tx['brand'] . ' ' . $tx['model']) ?></span>
                <span class="inline-block ml-1.5 px-2 py-0.5 rounded bg-surface-container text-on-surface-variant font-mono text-[10px] font-bold"><?= htmlspecialchars($tx['license_plate']) ?></span>
                <?php else: ?>
                <span class="text-outline">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="py-3.5 px-5">
                <?php if ($tx['first_name']): ?>
                <span class="font-semibold text-on-surface"><?= htmlspecialchars($tx['first_name'] . ' ' . $tx['last_name']) ?></span>
                <?php if (!empty($tx['booking_reference'])): ?>
                <p class="text-tertiary text-[11px]">#<?= htmlspecialchars($tx['booking_reference']) ?></p>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-outline">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="py-3.5 px-5 whitespace-nowrap text-on-surface-variant">
                <?php if (!empty($tx['pickup_date'])): ?>
                <?= formatDate($tx['pickup_date']) ?> &rarr;
                <?= $tx['actual_return_date'] ? formatDate($tx['actual_return_date']) : formatDate($tx['expected_return_date']) ?>
                <?php if (empty($tx['actual_return_date']) && $tx['to_status'] === 'overdue'): ?>
                <span class="ml-1 px-1.5 py-0.5 rounded bg-error-container text-on-error-container text-[10px] font-bold">overdue</span>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-outline">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="py-3.5 px-5 text-right font-bold text-on-surface"><?= (float)$tx['amount'] > 0 ? formatCurrency($tx['amount']) : '<span class="text-outline">&mdash;</span>' ?></td>
              <td class="py-3.5 px-5 text-tertiary text-[12px]">
                <?= !empty($tx['actor_id']) ? 'Staff #' . (int)$tx['actor_id'] : 'System' ?>
                <?php if (!empty($tx['notes'])): ?>
                <p class="text-tertiary text-[11px] max-w-[220px]"><?= htmlspecialchars($tx['notes']) ?></p>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="px-5 pb-4">
        <?= renderPagination($ledgerPg, ['summary' => true, 'noun' => 'transactions']) ?>
      </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
