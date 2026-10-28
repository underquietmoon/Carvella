<?php
/* dashboard.php - Carvella Car Rental System */

$pageTitle = 'Admin Dashboard - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

// Safety sync: ensure vehicle status matches active rentals.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && function_exists('refreshVehicleAvailabilityStatus')) {
    $syncVehs = $conn->query("SELECT id FROM vehicles WHERE status <> 'archived'");
    if ($syncVehs) {
        while ($sv = $syncVehs->fetch_assoc()) {
            refreshVehicleAvailabilityStatus($conn, (int)$sv['id']);
        }
        $syncVehs->close();
    }
}
$totalVehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status <> 'archived'")->fetch_assoc()['count'];
$availableVehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'available'")->fetch_assoc()['count'];
$rentedVehicles = $conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'rented'")->fetch_assoc()['count'];
$pendingReservations = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'")->fetch_assoc()['count'];
$approvedReservations = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'confirmed'")->fetch_assoc()['count'];
$completedRentals = $conn->query("SELECT COUNT(*) as count FROM rentals WHERE status = 'completed'")->fetch_assoc()['count'];
$pendingMaintenance = $conn->query("SELECT COUNT(*) as count FROM maintenance WHERE status = 'pending'")->fetch_assoc()['count'];
$timelineCount = (int)$conn->query("SELECT COUNT(*) as count FROM vehicles WHERE status IN ('available', 'rented')")->fetch_assoc()['count'];

$pendingQueue = $conn->query("
    SELECT r.*, v.brand, v.model, v.license_plate, v.type,
           u.first_name, u.last_name
    FROM reservations r
    JOIN vehicles v ON r.vehicle_id = v.id
    JOIN users u ON r.user_id = u.id
    WHERE r.status = 'pending'
    ORDER BY r.created_at DESC
    LIMIT 4
");

$availablePct = $totalVehicles > 0 ? round(($availableVehicles / $totalVehicles) * 100, 1) : 0;
$rentedPct = $totalVehicles > 0 ? round(($rentedVehicles / $totalVehicles) * 100, 1) : 0;
$maintenancePct = $totalVehicles > 0 ? round(($pendingMaintenance / $totalVehicles) * 100, 1) : 0;

$recentActivity = $conn->query("SELECT al.*, u.first_name, u.last_name FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 6");

/* Reports & Analytics snapshot for the dashboard widget. The revenue
   definition mirrors admin/reports.php (completed/active/in-progress/overdue
   all count as billed revenue) but is scoped to the current calendar month so
   the card reads as "this month" at a glance, with last month for the trend. */
$revenueStatuses = "'completed','active','in_progress','overdue'";
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

$analyticsStmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) AS rev, COUNT(*) AS cnt FROM rentals WHERE status IN ($revenueStatuses) AND pickup_date BETWEEN ? AND ?");
$analyticsStmt->bind_param('ss', $monthStart, $monthEnd);
$analyticsStmt->execute();
$analyticsRow = $analyticsStmt->get_result()->fetch_assoc();
$analyticsStmt->close();
$analyticsRevenue = (float)($analyticsRow['rev'] ?? 0);
$analyticsRentals = (int)($analyticsRow['cnt'] ?? 0);
$analyticsAvg = $analyticsRentals > 0 ? $analyticsRevenue / $analyticsRentals : 0.0;

$prevStart = date('Y-m-01', strtotime('first day of last month'));
$prevEnd   = date('Y-m-t', strtotime('last day of last month'));
$prevStmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) AS rev FROM rentals WHERE status IN ($revenueStatuses) AND pickup_date BETWEEN ? AND ?");
$prevStmt->bind_param('ss', $prevStart, $prevEnd);
$prevStmt->execute();
$prevRevenue = (float)($prevStmt->get_result()->fetch_assoc()['rev'] ?? 0);
$prevStmt->close();
$analyticsTrend = $prevRevenue > 0
    ? (($analyticsRevenue - $prevRevenue) / $prevRevenue) * 100
    : ($analyticsRevenue > 0 ? 100.0 : 0.0);

$analyticsOverdue = (int)$conn->query("SELECT COUNT(*) AS c FROM rentals WHERE status = 'overdue'")->fetch_assoc()['c'];

// Top performer by number of rentals (all-time, so a quiet month still shows a
// meaningful name). Ties break alphabetically for a stable result.
$analyticsTopRow = $conn->query("SELECT v.year, v.brand, v.model, COUNT(*) AS rental_count FROM rentals ren JOIN vehicles v ON ren.vehicle_id = v.id GROUP BY ren.vehicle_id ORDER BY rental_count DESC, v.brand ASC LIMIT 1")->fetch_assoc();
$analyticsTopVehicle = $analyticsTopRow ? trim((string)$analyticsTopRow['year'] . ' ' . $analyticsTopRow['brand'] . ' ' . $analyticsTopRow['model']) : '';
$analyticsTopCount = $analyticsTopRow ? (int)$analyticsTopRow['rental_count'] : 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

    <!-- Page Title & Date Filter Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <nav class="flex items-center gap-2 text-outline text-body-sm mb-1">
          <span>Admin Console</span>
          <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
          <span class="text-primary font-medium">Executive Overview</span>
        </nav>
        <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Vehicle Operations Matrix</h1>
      </div>
      <div class="flex items-center gap-6 self-end sm:self-auto">
        <!-- The "Today / This Week / M Y" segmented control that used to sit
             here was three dead buttons: dashboard.php has no period
             filtering behind it, so every pill reloaded the identical
             all-time view. The cards below report live operational state
             (fleet on the lot, open bookings), not a dated window, so a
             period switcher would be misleading even if wired up. Removed
             rather than shipped as dead chrome. -->
        </div>
    </div>

    <!-- 6 Executive Overview Metric Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 metric-stagger">
      <!-- Total Fleet -->
      <div style="--i:0" class="card-spotlight bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/40 transition-all">
        <div class="flex items-center justify-between">
          <span class="text-label-md text-outline">Total Vehicles</span>
        </div>
        <div class="text-numeric-stat text-on-surface mt-2 font-extrabold"><?= $totalVehicles ?></div>
        <div class="flex items-center gap-1 text-[12px] text-primary font-semibold mt-1">
          <span>Stationed in Dagupan</span>
        </div>
      </div>
      <!-- Available for Dispatch -->
      <div style="--i:1" class="card-spotlight bg-surface-container-lowest rounded-lg p-4 border border-emerald-200/70 transition-all">
        <div class="flex items-center justify-between">
          <span class="text-label-md text-emerald-800">Available</span>
        </div>
        <div class="text-numeric-stat text-emerald-700 mt-2 font-extrabold"><?= $availableVehicles ?></div>
        <div class="flex items-center gap-1 text-[12px] text-emerald-700 font-medium mt-1">
          <span>Ready for dispatch</span>
        </div>
      </div>
      <!-- Rented Out -->
      <div style="--i:2" class="card-spotlight bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/40 transition-all">
        <div class="flex items-center justify-between">
          <span class="text-label-md text-outline">Rented Out</span>
        </div>
        <div class="text-numeric-stat text-secondary mt-2 font-extrabold"><?= $rentedVehicles ?></div>
        <div class="flex items-center gap-1 text-[12px] text-secondary font-medium mt-1">
          <span><?= $rentedPct ?>% active dispatch</span>
        </div>
      </div>
      <!-- Pending Approval -->
      <div style="--i:3" class="card-spotlight bg-surface-container-lowest rounded-lg p-4 border border-amber-200 transition-all">
        <div class="flex items-center justify-between">
          <span class="text-label-md text-amber-800">Pending</span>
        </div>
        <div class="text-numeric-stat text-amber-600 mt-2 font-extrabold"><?= $pendingReservations ?></div>
        <div class="flex items-center gap-1 text-[12px] text-amber-800 font-semibold mt-1">
          <span>Amber triage alert</span>
        </div>
      </div>
      <!-- Approved / Ready Pickup -->
      <div style="--i:4" class="card-spotlight bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/40 transition-all">
        <div class="flex items-center justify-between">
          <span class="text-label-md text-outline">Approved</span>
        </div>
        <div class="text-numeric-stat text-primary mt-2 font-extrabold"><?= $approvedReservations ?></div>
        <div class="flex items-center gap-1 text-[12px] text-primary font-medium mt-1">
          <span>Ready for pickup</span>
        </div>
      </div>
      <!-- Completed Rentals -->
      <div style="--i:5" class="card-spotlight bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/40 transition-all">
        <div class="flex items-center justify-between">
          <span class="text-label-md text-outline">Completed</span>
        </div>
        <div class="text-numeric-stat text-on-surface mt-2 font-extrabold"><?= $completedRentals ?></div>
        <div class="flex items-center gap-1 text-[12px] text-emerald-700 font-semibold mt-1">
          <span>All-time completions</span>
        </div>
      </div>
    </div>

    <!-- Bento Row: Vehicle Availability Calendar (left) & Pending Approvals Queue (right) -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
      <!-- Vehicle Availability Calendar -->
      <div class="xl:col-span-1 bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/40 shadow-sm flex flex-col">
        <?php
        $calYear = (int)date('Y');
        $calMonth = (int)date('n');
        $firstDay = mktime(0,0,0,$calMonth,1,$calYear);
        $daysInMonth = (int)date('t', $firstDay);
        $startWeekday = (int)date('w', $firstDay);
        $activeByDay = array_fill(1, $daysInMonth, 0);
        $rentedCountByDay = array_fill(1, $daysInMonth, 0);
        $reservedCountByDay = array_fill(1, $daysInMonth, 0);
        $rentedByDay = array_fill(1, $daysInMonth, []);
        // Get reservations (pending/confirmed/approved)
        $rentalRows = $conn->query("SELECT r.pickup_date, r.return_date, v.brand, v.model FROM reservations r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.status IN ('pending','confirmed','approved') AND r.return_date >= '" . date('Y-m-01', $firstDay) . "' AND r.pickup_date <= '" . date('Y-m-t', $firstDay) . "'");
        if ($rentalRows) {
            while ($rr = $rentalRows->fetch_assoc()) {
                $s = strtotime($rr['pickup_date']); $e = strtotime($rr['return_date']);
                if ($s === false || $e === false) continue;
                $label = $rr['brand'] . ' ' . $rr['model'] . ' (Reserved)';
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $ts = mktime(0,0,0,$calMonth,$d,$calYear);
                    if ($ts >= $s && $ts <= $e) { 
                        $activeByDay[$d]++; 
                        $reservedCountByDay[$d]++; 
                        $rentedByDay[$d][] = $label; 
                    }
                }
            }
        }
        // Get active rentals
        $rentalRows2 = $conn->query("SELECT r.pickup_date, r.expected_return_date AS return_date, v.brand, v.model FROM rentals r JOIN vehicles v ON v.id = r.vehicle_id WHERE r.status IN ('active','ongoing','rented') AND r.expected_return_date >= '" . date('Y-m-01', $firstDay) . "' AND r.pickup_date <= '" . date('Y-m-t', $firstDay) . "'");
        if ($rentalRows2) {
            while ($rr = $rentalRows2->fetch_assoc()) {
                $s = strtotime($rr['pickup_date']); $e = strtotime($rr['return_date']);
                if ($s === false || $e === false) continue;
                $label = $rr['brand'] . ' ' . $rr['model'] . ' (Rented)';
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $ts = mktime(0,0,0,$calMonth,$d,$calYear);
                    if ($ts >= $s && $ts <= $e) { 
                        $activeByDay[$d]++; 
                        $rentedCountByDay[$d]++; 
                        $rentedByDay[$d][] = $label; 
                    }
                }
            }
        }
        $totalV = max(1, (int)$totalVehicles);
        ?>
        <div class="flex items-center justify-between pb-3 border-b border-outline-variant/30">
          <div class="flex items-center gap-2">
            <div class="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center text-primary">
              <i aria-hidden="true" class="bi bi-calendar3"></i>
            </div>
            <div>
              <h2 class="text-headline-sm font-bold text-on-surface">Vehicle Availability Calendar</h2>
              <p class="text-body-sm text-outline"><?= date('F Y') ?></p>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-[10px] font-medium">
            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-emerald-200 dark:bg-emerald-800 border border-emerald-500"></span> Free</span>
            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span> Rented</span>
            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-amber-600"></span> Reserved</span>
            <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-red-600"></span> Full</span>
          </div>
        </div>
        <div class="mt-3">
          <div class="grid grid-cols-7 gap-1 text-center text-[11px] text-outline font-semibold pb-1">
            <div>S</div><div>M</div><div>T</div><div>W</div><div>T</div><div>F</div><div>S</div>
          </div>
          <div class="grid grid-cols-7 gap-1">
            <?php for ($i = 0; $i < $startWeekday; $i++): ?>
              <div class="aspect-square bg-transparent"></div>
            <?php endfor; ?>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
              $booked = $activeByDay[$d];
              $rented = $rentedCountByDay[$d] ?? 0;
              $reserved = $reservedCountByDay[$d] ?? 0;
              $ratio = $booked / $totalV;
              if ($booked === 0) { 
                $cellClass = 'bg-emerald-100 dark:bg-emerald-900/40 border border-emerald-500 dark:border-emerald-400 text-emerald-900 dark:text-emerald-200'; 
                $statusLabel = 'Free'; 
              } elseif ($ratio >= 1) { 
                $cellClass = 'bg-red-100 dark:bg-red-900/40 border border-red-500 dark:border-red-400 text-red-900 dark:text-red-200'; 
                $statusLabel = 'Full'; 
              } elseif ($rented > 0 && $reserved === 0) { 
                $cellClass = 'bg-blue-100 dark:bg-blue-900/40 border border-blue-500 dark:border-blue-400 text-blue-900 dark:text-blue-200'; 
                $statusLabel = 'Rented'; 
              } elseif ($reserved > 0 && $rented === 0) { 
                $cellClass = 'bg-amber-100 dark:bg-amber-900/40 border border-amber-500 dark:border-amber-400 text-amber-900 dark:text-amber-200'; 
                $statusLabel = 'Reserved'; 
              } elseif ($rented > 0 && $reserved > 0) { 
                $cellClass = 'bg-violet-100 dark:bg-violet-900/40 border border-violet-500 dark:border-violet-400 text-violet-900 dark:text-violet-200'; 
                $statusLabel = 'Rented'; 
              } else { 
                $cellClass = 'bg-amber-100 dark:bg-amber-900/40 border border-amber-500 dark:border-amber-400 text-amber-900 dark:text-amber-200'; 
                $statusLabel = 'Reserved'; 
              }
              $isToday = ($d === (int)date('j') && $calMonth === (int)date('n') && $calYear === (int)date('Y'));
              $tooltip = empty($rentedByDay[$d]) ? 'No bookings - Free' : implode(' • ', $rentedByDay[$d]);
            ?>
              <div class="group relative aspect-square rounded-md <?= $cellClass ?> flex flex-col items-center justify-center <?= $isToday ? 'ring-2 ring-primary' : '' ?>" title="<?= htmlspecialchars($statusLabel . ': ' . $tooltip) ?>">
                <span class="text-[11px] font-semibold leading-none"><?= $d ?></span>
                <?php if ($booked > 0): ?>
                  <div class="flex items-center justify-center gap-0.5 mt-0.5">
                    <?php $count = min($booked, 3); ?>
                    <?php if ($ratio >= 1): ?>
                      <?php for ($c = 0; $c < $count; $c++): ?><span class="w-1 h-1 rounded-full bg-red-600"></span><?php endfor; ?>
                    <?php elseif ($rented > 0 && $reserved == 0): ?>
                      <?php for ($c = 0; $c < $count; $c++): ?><span class="w-1 h-1 rounded-full bg-blue-600"></span><?php endfor; ?>
                    <?php elseif ($reserved > 0 && $rented == 0): ?>
                      <?php for ($c = 0; $c < $count; $c++): ?><span class="w-1 h-1 rounded-full bg-amber-600"></span><?php endfor; ?>
                    <?php elseif ($rented > 0 && $reserved > 0): ?>
                      <?php for ($c = 0; $c < $count; $c++): ?><?php if ($c < $rented): ?><span class="w-1 h-1 rounded-full bg-blue-600"></span><?php else: ?><span class="w-1 h-1 rounded-full bg-amber-600"></span><?php endif; ?><?php endfor; ?>
                    <?php else: ?>
                      <?php for ($c = 0; $c < $count; $c++): ?><span class="w-1 h-1 rounded-full bg-amber-600"></span><?php endfor; ?>
                    <?php endif; ?>
                    <?php if ($booked > 3): ?><span class="text-[7px] text-on-surface-variant">+</span><?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if (!empty($rentedByDay[$d])): ?>
                <div class="pointer-events-none absolute z-50 bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block w-56 rounded-lg bg-on-surface text-surface text-[10px] p-2.5 shadow-xl">
                  <div class="font-bold mb-1.5 border-b border-surface/20 pb-1">
                    <?php if ($booked === 0): ?>Free
                    <?php elseif ($ratio >= 1): ?>Full (<?= $booked ?>/<?= $totalV ?>)
                    <?php elseif ($rented > 0 && $reserved > 0): ?>Rented (<?= $rented ?>) & Reserved (<?= $reserved ?>)
                    <?php elseif ($rented > 0): ?>Rented (<?= $rented ?>)
                    <?php elseif ($reserved > 0): ?>Reserved (<?= $reserved ?>)
                    <?php else: ?><?= $statusLabel ?><?php endif; ?>
                  </div>
                  <?= nl2br(htmlspecialchars(implode("\n", $rentedByDay[$d]))) ?>
                </div>
                <?php endif; ?>
              </div>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <!-- Pending Approvals Pipeline Table -->
      <div class="xl:col-span-2 bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/40 shadow-sm flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between pb-4 border-b border-outline-variant/30">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center text-primary">
                <i aria-hidden="true" class="bi bi-lightning-charge"></i>
              </div>
              <div>
                <h2 class="text-headline-sm font-bold text-on-surface">Pending Approvals Queue</h2>
                <p class="text-body-sm text-outline">Action pending rentals to enforce schedule lock and confirm Dagupan dispatch</p>
              </div>
            </div>
            <a href="<?= BASE_URL ?>/admin/reservations.php" class="w-9 h-9 rounded-full bg-primary/10 hover:bg-primary/20 text-primary flex items-center justify-center transition-colors" title="View all rentals" aria-label="View all rentals">
              <i aria-hidden="true" class="bi bi-arrow-right text-[18px]"></i>
            </a>
          </div>
          <div class="overflow-x-auto mt-3">
            <table class="w-full text-left border-collapse">
              <thead>
                <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
                  <th scope="col" class="py-3.5 px-5">Rental ID</th>
                  <th scope="col" class="py-3.5 px-5">Customer</th>
                  <th scope="col" class="py-3.5 px-5">Vehicle</th>
                  <th scope="col" class="py-3.5 px-5">Schedule</th>
                  <th scope="col" class="py-3.5 px-5">Total</th>
                  <th scope="col" class="py-3.5 px-5">Status</th>
                  <th scope="col" class="py-3.5 px-5 text-right">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
                <?php if ($pendingQueue->num_rows > 0): ?>
                <?php while ($pq = $pendingQueue->fetch_assoc()):
                  $start = date('M d', strtotime($pq['pickup_date']));
                  $end = date('M d', strtotime($pq['return_date']));
                  $days = max(1, (strtotime($pq['return_date']) - strtotime($pq['pickup_date'])) / 86400);
                ?>
                <tr class="hover:bg-surface-container-low transition-colors">
                  <td class="py-4 px-5 font-semibold text-primary">#<?= htmlspecialchars($pq['booking_reference']) ?></td>
                  <td class="py-4 px-5 font-bold text-on-surface"><?= htmlspecialchars($pq['first_name'] . ' ' . $pq['last_name']) ?></td>
                  <td class="py-4 px-5 text-on-surface-variant font-medium"><?= htmlspecialchars($pq['brand'] . ' ' . $pq['model']) ?> <span class="font-mono text-xs text-outline">(<?= htmlspecialchars($pq['license_plate']) ?>)</span></td>
                  <td class="py-4 px-5 text-on-surface"><?= $start ?> - <?= $end ?> <span class="text-outline text-xs">(<?= (int)$days ?>d)</span></td>
                  <td class="py-4 px-5 font-bold text-on-surface"><?= formatCurrency($pq['total_amount']) ?></td>
                  <td class="py-4 px-5"><span class="px-2.5 py-1 rounded-md bg-amber-100 text-amber-800 text-[11px] font-bold">Pending</span></td>
                  <td class="py-4 px-5 text-right">
                    <div class="flex items-center justify-end gap-1">
                      <form method="post" action="<?= BASE_URL ?>/admin/reservations.php" class="inline">
                        <input type="hidden" name="reservation_action" value="approve">
                        <input type="hidden" name="reservation_id" value="<?= (int)$pq['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="w-8 h-8 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white inline-flex items-center justify-center transition-all shadow-xs" title="Approve" aria-label="Approve">
                          <i aria-hidden="true" class="bi bi-check-lg text-[16px]"></i>
                        </button>
                      </form>
                      <form method="post" action="<?= BASE_URL ?>/admin/reservations.php" class="inline">
                        <input type="hidden" name="reservation_action" value="cancel">
                        <input type="hidden" name="reservation_id" value="<?= (int)$pq['id'] ?>">
                        <input type="hidden" name="reason" value="Rejected from dashboard">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="w-8 h-8 rounded-full bg-red-600 hover:bg-red-700 text-white inline-flex items-center justify-center transition-all shadow-xs" title="Reject" aria-label="Reject" onclick="return confirm('Reject this reservation?');">
                          <i aria-hidden="true" class="bi bi-x-lg text-[16px]"></i>
                        </button>
                      </form>
                      <a href="<?= BASE_URL ?>/admin/reservations.php" class="w-8 h-8 rounded-full bg-primary/10 hover:bg-primary/20 text-primary inline-flex items-center justify-center transition-all" title="View details" aria-label="View details">
                        <i aria-hidden="true" class="bi bi-eye text-[16px]"></i>
                      </a>
                    </div>
                  </td>
                </tr>
                <?php endwhile; ?>
                <?php else: ?>
                <tr>
                  <td colspan="7" class="text-center py-12">
                    <div class="flex flex-col items-center gap-3 text-outline">
                      <i aria-hidden="true" class="bi bi-check-circle text-[40px]"></i>
                      <span class="text-body-md">No pending approvals. All clear!</span>
                    </div>
                  </td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="mt-4 pt-3 border-t border-outline-variant/30 flex items-center justify-between text-body-sm text-outline">
          <span class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            Dispatch Queue: <?= $pendingReservations ?> pending approval
          </span>
          <span class="text-label-sm text-primary font-semibold">Zero Double-Rental Conflict</span>
        </div>
      </div>

      <!-- Reports & Analytics Snapshot -->
      <div class="xl:col-span-1 bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/40 shadow-sm flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between mb-2">
            <h2 class="text-headline-sm font-bold text-on-surface">Reports &amp; Analytics</h2>
            <span class="p-1.5 rounded-md bg-secondary-fixed text-primary text-xs font-mono"><?= date('M Y') ?></span>
          </div>
          <p class="text-body-sm text-outline mb-5">Revenue and performance snapshot for the current month</p>

          <!-- 2x2 headline metrics -->
          <div class="grid grid-cols-2 gap-3">
            <div class="p-3.5 rounded-lg bg-surface-container-low border border-outline-variant/30">
              <span class="text-label-sm text-outline uppercase tracking-wider font-semibold">Revenue</span>
              <div class="text-numeric-stat font-extrabold text-on-surface mt-1"><?= formatCurrency($analyticsRevenue) ?></div>
              <span class="text-[12px] font-semibold inline-flex items-center gap-0.5 mt-0.5 <?= $analyticsTrend >= 0 ? 'text-emerald-700' : 'text-error' ?>">
                <i aria-hidden="true" class="bi bi-<?= $analyticsTrend >= 0 ? 'arrow-up-right' : 'arrow-down-right' ?>"></i>
                <?= ($analyticsTrend >= 0 ? '+' : '') . number_format($analyticsTrend, 1) ?>% vs last mo.
              </span>
            </div>
            <div class="p-3.5 rounded-lg bg-surface-container-low border border-outline-variant/30">
              <span class="text-label-sm text-outline uppercase tracking-wider font-semibold">Avg / Rental</span>
              <div class="text-numeric-stat font-extrabold text-primary mt-1"><?= formatCurrency($analyticsAvg) ?></div>
              <span class="text-[12px] text-outline mt-0.5 block"><?= $analyticsRentals ?> rental<?= $analyticsRentals !== 1 ? 's' : '' ?> this month</span>
            </div>
            <div class="p-3.5 rounded-lg bg-surface-container-low border border-outline-variant/30">
              <span class="text-label-sm text-outline uppercase tracking-wider font-semibold">Utilization</span>
              <div class="text-numeric-stat font-extrabold text-secondary mt-1"><?= $rentedPct ?>%</div>
              <span class="text-[12px] text-outline mt-0.5 block"><?= $rentedVehicles ?> of <?= $totalVehicles ?> on the road</span>
            </div>
            <div class="p-3.5 rounded-lg bg-surface-container-low border border-outline-variant/30">
              <span class="text-label-sm text-outline uppercase tracking-wider font-semibold">Overdue</span>
              <div class="text-numeric-stat font-extrabold mt-1 <?= $analyticsOverdue > 0 ? 'text-error' : 'text-emerald-700' ?>"><?= $analyticsOverdue ?></div>
              <span class="text-[12px] font-medium mt-0.5 block <?= $analyticsOverdue > 0 ? 'text-error' : 'text-emerald-700' ?>"><?= $analyticsOverdue > 0 ? 'Needs follow-up' : 'All on schedule' ?></span>
            </div>
          </div>

          <?php if ($analyticsTopVehicle !== ''): ?>
          <div class="mt-4 p-4 rounded-lg bg-surface-container-low border border-outline-variant/30 flex items-center gap-3">
            <span class="w-10 h-10 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0">
              <i aria-hidden="true" class="bi bi-trophy text-[18px]"></i>
            </span>
            <div class="min-w-0">
              <span class="text-label-sm text-primary font-bold uppercase tracking-wider block">Top Performer</span>
              <span class="text-body-sm font-semibold text-on-surface truncate block"><?= htmlspecialchars($analyticsTopVehicle) ?></span>
              <span class="text-[12px] text-outline"><?= $analyticsTopCount ?> rental<?= $analyticsTopCount !== 1 ? 's' : '' ?> booked</span>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <a href="<?= BASE_URL ?>/admin/reports.php" class="mt-6 w-full px-4 py-3 rounded-md bg-primary text-on-primary font-label-lg text-label-lg font-bold hover:bg-primary-container transition-all shadow-sm inline-flex items-center justify-center gap-2 active:scale-95">
          <i aria-hidden="true" class="bi bi-bar-chart-line text-[20px]"></i>
          Open Reports &amp; Analytics
        </a>
      </div>


     <!-- Recent Activity Table -->
     <div class="xl:col-span-2 bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/40 shadow-sm overflow-hidden">
       <div class="flex items-center justify-between pb-4 border-b border-outline-variant/30">
         <div class="flex items-center gap-3">
           <div class="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center text-primary">
             <i aria-hidden="true" class="bi bi-receipt"></i>
           </div>
           <div>
             <h2 class="text-headline-sm font-bold text-on-surface">Recent Activity</h2>
             <p class="text-body-sm text-outline">Latest system and user actions across the fleet</p>
           </div>
         </div>
         <a href="<?= BASE_URL ?>/admin/activity-logs.php" class="w-9 h-9 rounded-full bg-primary/10 hover:bg-primary/20 text-primary flex items-center justify-center transition-colors" title="View all activity" aria-label="View all activity">
           <i aria-hidden="true" class="bi bi-arrow-right text-[18px]"></i>
         </a>
       </div>
       <div class="mt-3">
         <table class="w-full text-left border-collapse">
           <thead>
             <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
               <th scope="col" class="py-3.5 px-5">User</th>
               <th scope="col" class="py-3.5 px-5">Action</th>
               <th scope="col" class="py-3.5 px-5">Details</th>
               <th scope="col" class="py-3.5 px-5">Date</th>
             </tr>
           </thead>
           <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
             <?php while ($log = $recentActivity->fetch_assoc()): ?>
             <tr class="hover:bg-surface-container-low transition-colors">
               <td class="py-4 px-5 font-bold text-on-surface"><?= htmlspecialchars(($log["first_name"] ?? "System") . " " . ($log["last_name"] ?? "")) ?></td>
               <td class="py-4 px-5">
                 <?php
                 $action = strtolower($log["action"]);
                 if (in_array($action, ["created", "added", "completed", "approved", "available"])) {
                   $pillClass = "inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-emerald-50 text-emerald-800 border border-emerald-200 text-label-sm font-bold";
                 } elseif (in_array($action, ["updated", "edited", "modified", "changed", "confirmed"])) {
                   $pillClass = "inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-secondary-fixed text-on-secondary-fixed border border-secondary-fixed-dim text-label-sm font-bold";
                 } elseif (in_array($action, ["deleted", "removed", "cancelled", "failed"])) {
                   $pillClass = "inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-error-container text-on-error-container border border-error-container text-label-sm font-bold";
                 } elseif (in_array($action, ["pending", "awaiting"])) {
                   $pillClass = "inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-amber-50 text-amber-800 border border-amber-200 text-label-sm font-bold";
                 } else {
                   $pillClass = "inline-flex items-center gap-1.5 px-3 py-1 rounded-md bg-primary-fixed text-on-primary-fixed-variant border border-primary-fixed-dim text-label-sm font-bold";
                 }
                 ?>
                 <span class="<?= $pillClass ?>"><?= htmlspecialchars($log["action"]) ?></span>
               </td>
               <td class="py-4 px-5 text-on-surface-variant font-medium"><?= htmlspecialchars($log["details"]) ?></td>
               <td class="py-4 px-5 text-on-surface-variant"><?= formatDateTime($log["created_at"]) ?></td>
             </tr>
             <?php endwhile; ?>
             <?php if ($recentActivity->num_rows === 0): ?>
             <tr>
               <td colspan="4" class="text-center py-12">
                 <div class="flex flex-col items-center gap-3 text-outline">
                   <i aria-hidden="true" class="bi bi-inbox text-[40px]"></i>
                   <span class="text-body-md">No activity recorded yet.</span>
                 </div>
               </td>
             </tr>
             <?php endif; ?>
           </tbody>
         </table>
       </div>
     </div>
    </div>



  </main>

<script>
(function () {
    var toggle = document.getElementById('timelineToggle');
    var rows = document.getElementById('timelineRows');
    if (!toggle || !rows) return;
    toggle.addEventListener('click', function () {
        var expanded = rows.classList.toggle('show-all');
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        var icon = toggle.querySelector('.timeline-toggle-icon');
        if (icon) {
          icon.classList.toggle('bi-chevron-up', expanded);
          icon.classList.toggle('bi-chevron-down', !expanded);
        }
        var hint = expanded ? 'Show fewer vehicles' : 'Show all vehicles';
        toggle.setAttribute('title', hint);
        toggle.setAttribute('aria-label', hint);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
