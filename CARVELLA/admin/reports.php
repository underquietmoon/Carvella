<?php
/* admin/reports.php - Admin analytics and revenue reports. */

$pageTitle = 'Analytics & Revenue Intelligence - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/export.php';
requireAdmin();

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');

// The report bar is an arbitrary GET form, so a hand-edited query string must
// not feed unvalidated dates into the SQL below. Fall back to the current
// month for malformed values and swap the pair when they arrive reversed.
$isReportDate = function ($value) {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
};
if (!$isReportDate((string)$startDate)) {
    $startDate = date('Y-m-01');
}
if (!$isReportDate((string)$endDate)) {
    $endDate = date('Y-m-t');
}
if ($startDate > $endDate) {
    $swap = $startDate;
    $startDate = $endDate;
    $endDate = $swap;
}

if (isset($_GET['export'])) {
    $exportDataset = (string)($_GET['dataset'] ?? 'revenue');
    $exportRegistry = carvella_export_datasets();
    if (!isset($exportRegistry[$exportDataset])) {
        $exportDataset = 'revenue';
    }
    $exportFilters = carvella_export_filters([
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'vehicle_id' => (int)($_GET['vehicle_id'] ?? 0),
        'user_id'    => (int)($_GET['user_id'] ?? 0),
        'event'      => (string)($_GET['event'] ?? ''),
    ], $exportRegistry[$exportDataset]);
    $export = carvella_export_build($exportDataset, $exportFilters);
    csv_send($export['filename'], $export['headers'], $export['rows']);
}

rentalTrackingSchemaReady($conn);
flagOverdueRentals($conn);

$revenueStatuses = "'completed','active','in_progress','overdue'";

$totalRevenue = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM rentals WHERE status IN ($revenueStatuses) AND pickup_date BETWEEN ? AND ?");
$totalRevenue->bind_param("ss", $startDate, $endDate);
$totalRevenue->execute();
$revenue = $totalRevenue->get_result()->fetch_assoc()['total'];
$totalRevenue->close();

$totalRentals = $conn->prepare("SELECT COUNT(*) as count FROM rentals WHERE status IN ($revenueStatuses) AND pickup_date BETWEEN ? AND ?");
$totalRentals->bind_param("ss", $startDate, $endDate);
$totalRentals->execute();
$rentalCount = $totalRentals->get_result()->fetch_assoc()['count'];
$totalRentals->close();

$overdueCount = (int)$conn->query("SELECT COUNT(*) as c FROM rentals WHERE status = 'overdue'")->fetch_assoc()['c'];
$ledgerEventCount = (int)$conn->query("SELECT COUNT(*) as c FROM rental_transactions")->fetch_assoc()['c'];

$topVehicles = $conn->prepare("SELECT v.brand, v.model, v.year, v.type, v.daily_rate, COUNT(*) as rental_count, SUM(ren.total_amount) as total_rev FROM rentals ren JOIN vehicles v ON ren.vehicle_id = v.id WHERE ren.status IN ($revenueStatuses) AND ren.pickup_date BETWEEN ? AND ? GROUP BY ren.vehicle_id ORDER BY rental_count DESC LIMIT 5");
$topVehicles->bind_param("ss", $startDate, $endDate);
$topVehicles->execute();
$topVehiclesResult = $topVehicles->get_result();
$topVehicles->close();

$revenueByMonth = $conn->prepare("SELECT DATE_FORMAT(pickup_date, '%Y-%m') as month, SUM(total_amount) as revenue FROM rentals WHERE status IN ($revenueStatuses) AND pickup_date BETWEEN ? AND ? GROUP BY month ORDER BY month");
$revenueByMonth->bind_param("ss", $startDate, $endDate);
$revenueByMonth->execute();
$monthlyData = $revenueByMonth->get_result();
$revenueByMonth->close();

$monthlyRows = [];
while ($mRow = $monthlyData->fetch_assoc()) {
    $monthlyRows[] = $mRow;
}
$maxMonthlyRevenue = 0;
foreach ($monthlyRows as $mRow) {
    if ((float)$mRow['revenue'] > $maxMonthlyRevenue) {
        $maxMonthlyRevenue = (float)$mRow['revenue'];
    }
}
$avgPerRental = $rentalCount > 0 ? $revenue / $rentalCount : 0;

$typeRevenue = $conn->prepare("SELECT v.type, COUNT(*) as cnt, COALESCE(SUM(ren.total_amount), 0) as rev FROM rentals ren JOIN vehicles v ON ren.vehicle_id = v.id WHERE ren.status IN ($revenueStatuses) AND ren.pickup_date BETWEEN ? AND ? GROUP BY v.type ORDER BY rev DESC");
$typeRevenue->bind_param("ss", $startDate, $endDate);
$typeRevenue->execute();
$typeResult = $typeRevenue->get_result();
$typeRevenue->close();
$typeRows = [];
while ($tRow = $typeResult->fetch_assoc()) {
    $typeRows[] = $tRow;
}

$totalVehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles WHERE status <> 'archived'")->fetch_assoc()['c'];
$rentedVehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles WHERE status = 'rented'")->fetch_assoc()['c'];
$maintenanceVehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles WHERE status = 'maintenance'")->fetch_assoc()['c'];
$utilizationRate = $totalVehicles > 0 ? round(($rentedVehicles / $totalVehicles) * 100, 1) : 0;

$totalDeposits = $conn->prepare("SELECT COALESCE(SUM(protection_fee), 0) as total FROM reservations WHERE protection_fee > 0 AND status IN ('active','confirmed') AND pickup_date BETWEEN ? AND ?");
$totalDeposits->bind_param("ss", $startDate, $endDate);
$totalDeposits->execute();
$depositsHeld = $totalDeposits->get_result()->fetch_assoc()['total'];
$totalDeposits->close();
$activeDeposits = $conn->query("SELECT COUNT(*) as c FROM reservations WHERE protection_fee > 0 AND status IN ('active','confirmed')")->fetch_assoc()['c'];

$recentTransactions = $conn->prepare("SELECT COALESCE(res.booking_reference, CONCAT('Rental #', ren.id)) AS booking_reference, ren.total_amount, COALESCE(res.protection_fee, 0) AS protection_fee, ren.status, ren.created_at, ren.pickup_date, ren.actual_return_date, ren.expected_return_date, CONCAT(u.first_name, ' ', u.last_name) as customer_name, CONCAT(v.brand, ' ', v.model) as vehicle_name, v.license_plate FROM rentals ren JOIN users u ON ren.user_id = u.id JOIN vehicles v ON ren.vehicle_id = v.id LEFT JOIN reservations res ON res.id = ren.reservation_id WHERE ren.pickup_date BETWEEN ? AND ? ORDER BY ren.created_at DESC LIMIT 8");
$recentTransactions->bind_param("ss", $startDate, $endDate);
$recentTransactions->execute();
$txnResult = $recentTransactions->get_result();
$recentTransactions->close();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

    <!-- Executive Header -->
    <section class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4">
      <div>
        <div class="flex items-center gap-2 text-label-sm font-label-sm text-primary uppercase tracking-wider mb-1">
          <span>Pangasinan Operations</span>
          <span>&bull;</span>
          <span>Live Telemetry</span>
        </div>
        <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Analytics &amp; Revenue Intelligence - Dagupan City Hub</h1>
        <p class="text-body-sm font-body-sm text-on-surface-variant mt-1">Real-time yield optimization, fleet asset returns, and rental cash flows across Dagupan coastal routes.</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-3">
          <div class="inline-flex items-center gap-2 px-4 py-2.5 bg-surface-container-lowest border border-outline-variant/60 rounded-md shadow-sm">
            <i aria-hidden="true" class="bi bi-calendar-date text-primary text-[18px]"></i>
            <span class="text-label-lg font-label-lg text-on-surface"><?= date('M d', strtotime($startDate)) ?> - <?= date('M d, Y', strtotime($endDate)) ?></span>
          </div>
          <div class="flex items-center gap-2">
            <label class="sr-only" for="start_date">Start Date</label>
            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="px-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/70 text-body-sm text-on-surface focus:ring-4 focus:ring-primary/15 focus:border-primary transition-all">
            <label class="sr-only" for="end_date">End Date</label>
            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="px-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/70 text-body-sm text-on-surface focus:ring-4 focus:ring-primary/15 focus:border-primary transition-all">
          </div>
          <!-- Two controls, not one. The submit used to carry a hidden
               name="export" value="1", which made every submission take the
               csv_send() branch at the top of this file and exit — so the
               date pickers could never re-render the on-page report and the
               only way to change the period was to download a CSV. On a GET
               form a submit button's own name/value joins the query string,
               so the export is now opt-in per button. -->
          <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md bg-surface-container-lowest border border-outline-variant/70 text-on-surface hover:bg-surface-container hover:text-primary font-label-lg transition-all duration-200 active:scale-95 cursor-pointer">
            <i aria-hidden="true" class="bi bi-funnel text-[18px]"></i>
            <span>Apply Range</span>
          </button>
          <button type="submit" name="export" value="1" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md bg-primary hover:bg-secondary text-on-primary font-label-lg shadow-sm transition-all duration-200 active:scale-95 cursor-pointer" title="Tip: In Excel, select all columns and double-click the border between any two column headers to auto-fit.">
            <i aria-hidden="true" class="bi bi-download text-[18px]"></i>
            <span>Download Financial Audit CSV</span>
          </button>
        <button type="button" id="privacyEye" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md bg-surface-container-lowest border border-outline-variant/70 text-on-surface hover:bg-surface-container hover:text-primary font-label-lg shadow-sm transition-all duration-200 active:scale-95 cursor-pointer">
          <i aria-hidden="true" class="bi bi-eye text-[18px]"></i>
          <span>Hide Numbers</span>
        </button>

        </form>
      </div>
    </section>

    <!-- 5 Key Financial Metrics -->
    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
      <div class="bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/60 shadow-sm flex flex-col justify-between hover:border-primary-fixed-dim transition-colors">
        <div>
          <span class="text-label-md font-label-md text-on-surface-variant">Gross Rental Revenue</span>
          <div class="text-numeric-stat font-numeric-stat text-on-surface mt-2 tracking-tight" data-privacy="<?= htmlspecialchars(formatCurrency($revenue)) ?>"><?= formatCurrency($revenue) ?></div>
        </div>
        <div class="mt-4 flex items-center justify-between">
          <span class="inline-flex items-center px-2 py-0.5 rounded-md text-label-sm font-label-sm bg-emerald-50 text-emerald-700 border border-emerald-200">
            Completed Rentals
          </span>
        </div>
      </div>
      <div class="bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/60 shadow-sm flex flex-col justify-between hover:border-primary-fixed-dim transition-colors">
        <div>
          <span class="text-label-md font-label-md text-on-surface-variant">Average Revenue / Day</span>
          <div class="text-numeric-stat font-numeric-stat text-on-surface mt-2 tracking-tight" data-privacy="<?= htmlspecialchars(formatCurrency($avgPerRental)) ?>"><?= formatCurrency($avgPerRental) ?></div>
        </div>
        <div class="mt-4 flex items-center justify-between">
          <span class="inline-flex items-center px-2 py-0.5 rounded-md text-label-sm font-label-sm bg-surface-container-low text-primary border border-outline-variant/40">
            Optimal Yield
          </span>
        </div>
      </div>
      <div class="bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/60 shadow-sm flex flex-col justify-between hover:border-primary-fixed-dim transition-colors">
        <div>
          <span class="text-label-md font-label-md text-on-surface-variant">Vehicle Utilization Rate</span>
          <div class="text-numeric-stat font-numeric-stat text-on-surface mt-2 tracking-tight" data-privacy="<?= $utilizationRate ?>%"><?= $utilizationRate ?>%</div>
        </div>
        <div class="mt-4 flex items-center justify-between">
          <span class="text-body-sm font-body-sm text-on-surface-variant">
            <?= $rentedVehicles ?> of <?= $totalVehicles ?> units active
          </span>
        </div>
      </div>
      <div class="bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/60 shadow-sm flex flex-col justify-between hover:border-primary-fixed-dim transition-colors">
        <div>
          <span class="text-label-md font-label-md text-on-surface-variant">Security Deposits Held</span>
          <div class="text-numeric-stat font-numeric-stat text-on-surface mt-2 tracking-tight" data-privacy="<?= htmlspecialchars(formatCurrency($depositsHeld)) ?>"><?= formatCurrency($depositsHeld) ?></div>
        </div>
        <div class="mt-4 flex items-center justify-between">
          <span class="inline-flex items-center px-2 py-0.5 rounded-md text-label-sm font-label-sm bg-violet-50 text-primary border border-violet-200">
            Protected Deposit
          </span>
          <span class="text-body-sm font-body-sm text-outline"><?= $activeDeposits ?> units active</span>
        </div>
      </div>
      <div class="bg-surface-container-lowest rounded-lg p-4 border border-outline-variant/60 shadow-sm flex flex-col justify-between hover:border-primary-fixed-dim transition-colors">
        <div>
          <span class="text-label-md font-label-md text-on-surface-variant">Fleet Maintenance Units</span>
          <div class="text-numeric-stat font-numeric-stat text-secondary mt-2 tracking-tight" data-privacy="<?= $maintenanceVehicles ?>"><?= $maintenanceVehicles ?></div>
        </div>
        <div class="mt-4 flex items-center justify-between">
          <span class="inline-flex items-center px-2 py-0.5 rounded-md text-label-sm font-label-sm bg-emerald-50 text-emerald-700 border border-emerald-200">
            <?= $totalVehicles > 0 ? round((($totalVehicles - $maintenanceVehicles) / $totalVehicles) * 100, 1) : 0 ?>% Operational
          </span>
        </div>
      </div>
    </section>

    <!-- Revenue Chart + Category Donut -->
    <section class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- Revenue Trend Chart -->
      <div class="lg:col-span-8 bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/60 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
          <div>
            <h2 class="text-headline-sm font-headline-sm text-on-surface">Revenue &amp; Rental Velocity Curve</h2>
            <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Monthly rental turnover showing revenue performance across Dagupan routes</p>
          </div>
          <div class="flex items-center gap-4 text-label-sm font-label-sm">
            <div class="flex items-center gap-1.5">
              <span class="w-3 h-3 rounded-full bg-primary"></span>
              <span class="text-on-surface-variant">Gross Revenue</span>
            </div>
          </div>
        </div>
        <?php if (count($monthlyRows) > 0): ?>
        <div class="relative pt-6 pb-2">
          <div class="h-64 flex items-end justify-between gap-1 sm:gap-2 px-2 border-b border-outline-variant/40 relative">
            <div class="absolute inset-0 flex flex-col justify-between pointer-events-none opacity-25">
              <div class="border-b border-dashed border-outline w-full"></div>
              <div class="border-b border-dashed border-outline w-full"></div>
              <div class="border-b border-dashed border-outline w-full"></div>
              <div class="border-b border-dashed border-outline w-full"></div>
            </div>
            <?php foreach ($monthlyRows as $mRow):
              $pct = $maxMonthlyRevenue > 0 ? round(((float)$mRow['revenue'] / $maxMonthlyRevenue) * 100) : 0;
              $pct = max($pct, 3);
              $monthLabel = date('M Y', strtotime($mRow['month'] . '-01'));
            ?>
            <div class="flex-1 flex flex-col items-center gap-1.5 h-full justify-end group">
              <div class="w-full max-w-[36px] bg-primary-fixed hover:bg-primary transition-all rounded-t-lg origin-bottom relative group-hover:scale-y-105" style="height: <?= $pct ?>%">
                <div class="opacity-0 group-hover:opacity-100 absolute -top-8 left-1/2 -translate-x-1/2 bg-inverse-surface text-inverse-on-surface text-label-sm px-2 py-0.5 rounded pointer-events-none whitespace-nowrap z-10"><?= formatCurrency($mRow['revenue']) ?></div>
              </div>
              <span class="text-label-sm font-label-sm text-outline whitespace-nowrap"><?= htmlspecialchars($monthLabel) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mt-6 pt-4 border-t border-outline-variant/30 grid grid-cols-3 gap-3 text-center">
          <div class="bg-surface-container-low/60 p-3 rounded-lg">
            <span class="text-body-sm font-body-sm text-on-surface-variant">Completed</span>
            <p class="text-label-lg font-label-lg text-on-surface mt-0.5 font-bold"><?= $rentalCount ?> rentals</p>
          </div>
          <div class="bg-surface-container-low/60 p-3 rounded-lg">
            <span class="text-body-sm font-body-sm text-on-surface-variant">Avg per Rental</span>
            <p class="text-label-lg font-label-lg text-primary mt-0.5 font-bold"><?= formatCurrency($avgPerRental) ?></p>
          </div>
          <div class="bg-surface-container-low/60 p-3 rounded-lg">
            <span class="text-body-sm font-body-sm text-on-surface-variant">Fleet Idle Ratio</span>
            <p class="text-label-lg font-label-lg text-emerald-700 mt-0.5 font-bold"><?= $totalVehicles > 0 ? round(($maintenanceVehicles / $totalVehicles) * 100, 1) : 0 ?>% (Minimal)</p>
          </div>
        </div>
        <?php else: ?>
        <div class="text-center py-12">
          <i aria-hidden="true" class="bi bi-bar-chart text-[48px] text-outline mb-2"></i>
          <p class="text-body-md font-body-md text-on-surface-variant">No revenue data for this period.</p>
        </div>
        <?php endif; ?>
      </div>

      <!-- Category Revenue Donut -->
      <div class="lg:col-span-4 bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/60 shadow-sm flex flex-col justify-between">
        <div>
          <h2 class="text-headline-sm font-headline-sm text-on-surface">Category Revenue Contribution</h2>
          <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Breakdown by fleet category stationed at the central hub</p>
          <?php
            $typeColors = ['primary', 'primary-container', 'primary-fixed-dim', 'secondary', 'tertiary'];
            $donutOffset = 0;
            $totalTypeRev = 0;
            foreach ($typeRows as $tr) $totalTypeRev += (float)$tr['rev'];
          ?>
          <div class="relative w-48 h-48 mx-auto my-6 flex items-center justify-center">
            <svg class="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
              <circle cx="18" cy="18" fill="none" r="15.9155" stroke="#FAF0FF" stroke-width="3.5"></circle>
              <?php foreach ($typeRows as $ti => $tr):
                $pct = $totalTypeRev > 0 ? round(((float)$tr['rev'] / $totalTypeRev) * 100) : 0;
                $colorClass = $typeColors[$ti % count($typeColors)];
              ?>
              <circle cx="18" cy="18" fill="none" r="15.9155" stroke="<?= $colorClass === 'primary' ? '#6B38D4' : ($colorClass === 'primary-container' ? '#8455EF' : ($colorClass === 'primary-fixed-dim' ? '#D0BCFF' : ($colorClass === 'secondary' ? '#712Edd' : '#5c5a6b'))) ?>" stroke-dasharray="<?= $pct ?> <?= 100 - $pct ?>" stroke-dashoffset="-<?= $donutOffset ?>" stroke-width="3.5"></circle>
              <?php $donutOffset += $pct; endforeach; ?>
            </svg>
            <div class="absolute flex flex-col items-center justify-center text-center">
              <span class="text-label-sm font-label-sm text-outline uppercase tracking-wider">Total</span>
              <span class="text-headline-md font-headline-md font-bold text-on-surface" data-privacy="<?= htmlspecialchars(formatCurrency($totalTypeRev)) ?>"><?= formatCurrency($totalTypeRev) ?></span>
              <span class="text-label-sm font-label-sm text-primary font-semibold">100% Stationed</span>
            </div>
          </div>
        </div>
        <div class="space-y-2.5">
          <?php foreach ($typeRows as $ti => $tr):
            $pct = $totalTypeRev > 0 ? round(((float)$tr['rev'] / $totalTypeRev) * 100) : 0;
            $colorClass = $typeColors[$ti % count($typeColors)];
          ?>
          <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-low/70 border border-outline-variant/30">
            <div class="flex items-center gap-2.5">
              <span class="w-3.5 h-3.5 rounded-full bg-<?= $colorClass === 'primary' ? 'primary' : ($colorClass === 'primary-container' ? 'primary-container' : ($colorClass === 'primary-fixed-dim' ? 'primary-fixed-dim' : ($colorClass === 'secondary' ? 'secondary' : 'tertiary'))) ?>"></span>
              <span class="text-label-lg font-label-lg text-on-surface"><?= htmlspecialchars(ucfirst($tr['type'])) ?></span>
            </div>
            <div class="text-right">
              <span class="text-label-lg font-label-lg font-bold text-on-surface"><?= $pct ?>%</span>
              <span class="text-body-sm font-body-sm text-outline block"><?= formatCurrency($tr['rev']) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($typeRows)): ?>
          <div class="text-center py-8">
            <i aria-hidden="true" class="bi bi-pie-chart text-[48px] text-outline mb-2"></i>
            <p class="text-body-md font-body-md text-on-surface-variant">No category data for this period.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- Top Performing Vehicles + Maintenance ROI -->
    <section class="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <!-- Top Vehicles -->
      <div class="lg:col-span-7 bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/60 shadow-sm">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h2 class="text-headline-sm font-headline-sm text-on-surface">Top Performing Vehicles in Dagupan</h2>
            <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Ranked by revenue generation and rental volume</p>
          </div>
          <span class="px-3 py-1 rounded-md text-label-sm font-label-sm bg-surface-container text-primary font-bold">Hub Leaders</span>
        </div>
        <div class="space-y-3.5">
          <?php
            $rank = 0;
            $maxTopRev = 0;
            $topRows = [];
            while ($tvRow = $topVehiclesResult->fetch_assoc()) {
              $topRows[] = $tvRow;
              if ((float)$tvRow['total_rev'] > $maxTopRev) $maxTopRev = (float)$tvRow['total_rev'];
            }
            foreach ($topRows as $tvRow):
              $rank++;
              $barPct = $maxTopRev > 0 ? round(((float)$tvRow['total_rev'] / $maxTopRev) * 100) : 0;
              $barPct = max($barPct, 5);
              $avgDaily = (int)$tvRow['rental_count'] > 0 ? (float)$tvRow['total_rev'] / (int)$tvRow['rental_count'] : 0;
          ?>
          <div class="p-4 rounded-lg bg-surface-container-low/50 border border-outline-variant/40 flex flex-col sm:flex-row sm:items-center justify-between gap-4 hover:bg-surface-container-low transition-colors">
            <div class="flex items-center gap-3.5">
              <div class="w-12 h-12 rounded-lg bg-surface-container flex items-center justify-center text-primary font-bold text-headline-sm"><?= $rank ?></div>
              <div>
                <h3 class="text-label-lg font-label-lg font-bold text-on-surface"><?= htmlspecialchars($tvRow['year'] . ' ' . $tvRow['brand'] . ' ' . $tvRow['model']) ?></h3>
                <div class="flex items-center gap-2 mt-1">
                  <span class="px-2 py-0.5 rounded-md text-label-sm font-label-sm bg-violet-100 text-primary"><?= htmlspecialchars(ucfirst($tvRow['type'])) ?></span>
                  <span class="text-body-sm font-body-sm text-on-surface-variant"><?= (int)$tvRow['rental_count'] ?> rental<?= (int)$tvRow['rental_count'] > 1 ? 's' : '' ?></span>
                </div>
              </div>
            </div>
            <div class="sm:text-right">
              <span class="text-headline-sm font-headline-sm font-bold text-primary" data-privacy="<?= htmlspecialchars(formatCurrency($tvRow['total_rev'])) ?>"><?= formatCurrency($tvRow['total_rev']) ?></span>
              <span class="text-label-sm font-label-sm text-outline block"><?= formatCurrency($avgDaily) ?> / rental</span>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($topRows)): ?>
          <div class="text-center py-12">
            <i aria-hidden="true" class="bi bi-car-front text-[48px] text-outline mb-2"></i>
            <p class="text-body-md font-body-md text-on-surface-variant">No completed rentals in this period.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Rental Status Breakdown -->
      <div class="lg:col-span-5 bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/60 shadow-sm flex flex-col justify-between">
        <div>
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-headline-sm font-headline-sm text-on-surface">Rental Status Breakdown</h2>
            <span class="px-3 py-1 rounded-md text-label-sm font-label-sm bg-emerald-50 text-emerald-700 border border-emerald-200">
              <?= number_format($utilizationRate, 1) ?>% Utilization
            </span>
          </div>
          <p class="text-body-sm font-body-sm text-on-surface-variant">Completion status breakdown for the selected period</p>
        </div>
        <?php
          $statusStmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM reservations WHERE pickup_date BETWEEN ? AND ? GROUP BY status ORDER BY cnt DESC");
          $statusStmt->bind_param("ss", $startDate, $endDate);
          $statusStmt->execute();
          $statusResult = $statusStmt->get_result();
          $statusStmt->close();
          $statusTotal = 0;
          $statusRows = [];
          while ($sRow = $statusResult->fetch_assoc()) {
            $statusRows[] = $sRow;
            $statusTotal += (int)$sRow['cnt'];
          }
          $statusColors = [
            'completed' => ['bar' => 'bg-emerald-500', 'label' => 'bg-emerald-50 text-emerald-800 border-emerald-200'],
            'active'    => ['bar' => 'bg-secondary', 'label' => 'bg-secondary-fixed text-on-secondary-fixed border-secondary-fixed-dim'],
            'confirmed' => ['bar' => 'bg-primary', 'label' => 'bg-primary-fixed text-on-primary-fixed-variant border-primary-fixed-dim'],
            'pending'   => ['bar' => 'bg-amber-500', 'label' => 'bg-amber-50 text-amber-800 border-amber-200'],
            'cancelled' => ['bar' => 'bg-error', 'label' => 'bg-error-container text-on-error-container border-error-container'],
          ];
        ?>
        <div class="mt-6 space-y-2.5">
          <?php if (count($statusRows) > 0): ?>
            <?php foreach ($statusRows as $sRow):
              $sKey = strtolower($sRow['status']);
              $colors = $statusColors[$sKey] ?? ['bar' => 'bg-outline', 'label' => 'bg-surface-container-high text-on-surface-variant border-outline-variant'];
              $pct = $statusTotal > 0 ? round(((int)$sRow['cnt'] / $statusTotal) * 100) : 0;
            ?>
            <div class="p-3 rounded-lg bg-surface-container-low/70 border border-outline-variant/30">
              <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2.5">
                  <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md <?= $colors['label'] ?> text-label-sm font-bold border">
                    <?= htmlspecialchars(ucfirst($sRow['status'])) ?>
                  </span>
                </div>
                <div class="text-right">
                  <span class="text-label-lg font-label-lg font-bold text-on-surface"><?= $pct ?>%</span>
                  <span class="text-body-sm font-body-sm text-outline block"><?= (int)$sRow['cnt'] ?> rentals</span>
                </div>
              </div>
              <div class="w-full bg-surface-container rounded-md h-2 overflow-hidden">
                <div class="<?= $colors['bar'] ?> h-2 rounded-md" style="width: <?= $pct ?>%"></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-center py-8">
              <i aria-hidden="true" class="bi bi-pie-chart text-[48px] text-outline mb-2"></i>
              <p class="text-body-md font-body-md text-on-surface-variant">No rental data for this period.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- Recent Transactions Ledger -->
    <section class="bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/60 shadow-sm">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
          <h2 class="text-headline-sm font-headline-sm text-on-surface">Recent Payouts &amp; Cashiering Ledger</h2>
          <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Reconciled payment disbursements, physical POS counter settlements, and direct bank remittances</p>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-label-sm font-label-sm text-outline">Terminal: <strong>DGP-CENTRAL-01</strong></span>
          <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
              <th scope="col" class="py-3.5 px-5">Transaction ID</th>
              <th scope="col" class="py-3.5 px-5">Vehicle Unit</th>
              <th scope="col" class="py-3.5 px-5">Customer</th>
              <th scope="col" class="py-3.5 px-5">Ledger Type</th>
              <th scope="col" class="py-3.5 px-5">Amount</th>
              <th scope="col" class="py-3.5 px-5">Terminal Status</th>
              <th scope="col" class="py-3.5 px-5 text-right">Timestamp</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
            <?php while ($txn = $txnResult->fetch_assoc()):
              $txnStatus = $txn['status'];
              // Chips reuse the status palette every other admin table badges
              // with, instead of one-off hex values that drift from the theme.
              if ($txnStatus === 'completed') {
                $txnLabel = 'Settled';
                $txnBadge = 'bg-emerald-100 text-emerald-800';
              } elseif ($txnStatus === 'active') {
                $txnLabel = 'In Progress';
                $txnBadge = 'bg-secondary-fixed text-on-secondary-fixed';
              } elseif ($txnStatus === 'cancelled') {
                $txnLabel = 'Cancelled';
                $txnBadge = 'bg-error-container text-on-error-container';
              } else {
                $txnLabel = ucfirst($txnStatus);
                $txnBadge = 'bg-surface-container-high text-on-surface-variant';
              }
              $ledgerType = (float)$txn['protection_fee'] > 0 ? 'Security Deposit' : 'Hub POS Cashier';
              $ledgerBadge = (float)$txn['protection_fee'] > 0 ? 'bg-surface-container-high text-on-surface' : 'bg-surface-container text-on-surface-variant';
            ?>
            <tr class="hover:bg-surface-container-low/40 transition-colors">
              <td class="py-4 px-5 font-mono text-label-md font-bold text-primary">#<?= htmlspecialchars($txn['booking_reference']) ?></td>
              <td class="py-4 px-5 text-on-surface font-medium"><?= htmlspecialchars($txn['vehicle_name'] . ' (' . $txn['license_plate'] . ')') ?></td>
              <td class="py-4 px-5 text-on-surface"><?= htmlspecialchars($txn['customer_name']) ?></td>
              <td class="py-4 px-5">
                <span class="px-2.5 py-1 rounded-md text-label-sm font-label-sm <?= $ledgerBadge ?>">
                  <?= $ledgerType ?>
                </span>
              </td>
              <td class="py-4 px-5 font-bold text-on-surface" data-privacy="<?= htmlspecialchars(formatCurrency($txn['total_amount'])) ?>"><?= formatCurrency($txn['total_amount']) ?></td>
              <td class="py-4 px-5">
                <span class="px-2.5 py-0.5 rounded-md text-label-sm font-label-sm <?= $txnBadge ?> font-bold">
                  <?= $txnLabel ?>
                </span>
              </td>
              <td class="py-4 px-5 text-right text-outline"><?= date('M d, H:i', strtotime($txn['created_at'])) ?></td>
            </tr>
            <?php endwhile; ?>
            <?php if ($txnResult->num_rows === 0): ?>
            <tr>
              <td colspan="7" class="text-center py-8">
                <i aria-hidden="true" class="bi bi-receipt text-[48px] text-outline mb-2"></i>
                <p class="text-body-md font-body-md text-on-surface-variant">No transactions for this period.</p>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-4 pt-4 border-t border-outline-variant/30 flex flex-col sm:flex-row items-center justify-between text-body-sm font-body-sm text-outline">
        <span>Showing latest reconciled entries for the central terminal</span>
        <div class="flex items-center gap-4 mt-2 sm:mt-0 font-label-sm">
          <a href="<?= BASE_URL ?>/admin/export.php?dataset=rental_transactions" class="text-primary hover:underline font-bold" title="Tip: In Excel, select all columns and double-click the border between any two column headers to auto-fit.">View Complete Monthly General Ledger</a>
          <span>&bull;</span>
          <a href="<?= BASE_URL ?>/admin/export.php?dataset=revenue" class="text-primary hover:underline font-bold">Export Revenue Report</a>
        </div>
      </div>
    </section>

  </main>
</div>
<script>
function sortTable(key) {
  var table = document.querySelector('table');
  if (!table) return;
  var tbody = table.querySelector('tbody');
  var rows = Array.from(tbody.querySelectorAll('tr'));
  rows.sort(function(a, b) {
    var aText = a.textContent.toLowerCase();
    var bText = b.textContent.toLowerCase();
    if (key === 'amount') {
      var aAmt = parseFloat(a.querySelectorAll('td')[4].textContent.replace(/[^\d.]/g, '')) || 0;
      var bAmt = parseFloat(b.querySelectorAll('td')[4].textContent.replace(/[^\d.]/g, '')) || 0;
      return bAmt - aAmt;
    } else if (key === 'date') {
      var aDate = a.querySelectorAll('td')[6].textContent;
      var bDate = b.querySelectorAll('td')[6].textContent;
      return bDate.localeCompare(aDate);
    } else if (key === 'vehicle') {
      var aVeh = a.querySelectorAll('td')[1].textContent.toLowerCase();
      var bVeh = b.querySelectorAll('td')[1].textContent.toLowerCase();
      return aVeh.localeCompare(bVeh);
    }
    return 0;
  });
  rows.forEach(function(row) { tbody.appendChild(row); });
}
document.addEventListener('DOMContentLoaded', function() {
  var eye = document.getElementById('privacyEye');
  var hidden = false;
  if (eye) {
    eye.addEventListener('click', function() {
      hidden = !hidden;
      document.querySelectorAll('[data-privacy]').forEach(function(el) {
        if (hidden) {
          el.textContent = '••••••';
        } else {
          el.textContent = el.getAttribute('data-privacy');
        }
      });
      eye.querySelector('i').classList.toggle('bi-eye', !hidden);
      eye.querySelector('i').classList.toggle('bi-eye-slash', hidden);
    });
  }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of admin/reports.php */
?>
