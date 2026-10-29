<?php
/* customer/dashboard.php - Customer dashboard with active bookings and rental history. */
$pageTitle = 'Customer Dashboard - Carvella';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$userId = $_SESSION['user_id'];

rentalTrackingSchemaReady($conn);
flagOverdueRentals($conn);

$onRoadCount = (int)$conn->query("SELECT COUNT(*) as c FROM rentals WHERE user_id = " . (int)$userId . " AND status IN ('active','in_progress','overdue')")->fetch_assoc()['c'];
$overdueCount = (int)$conn->query("SELECT COUNT(*) as c FROM rentals WHERE user_id = " . (int)$userId . " AND status = 'overdue'")->fetch_assoc()['c'];
$nextReturn = $conn->query("SELECT pickup_date, expected_return_date FROM rentals WHERE user_id = " . (int)$userId . " AND status IN ('active','in_progress','overdue') AND actual_return_date IS NULL ORDER BY expected_return_date ASC LIMIT 1")->fetch_assoc();

$activeReservations = $conn->prepare("SELECT COUNT(*) as count FROM reservations WHERE user_id = ? AND status IN ('pending', 'confirmed')");
$activeReservations->bind_param("i", $userId);
$activeReservations->execute();
$activeCount = (int)$activeReservations->get_result()->fetch_assoc()['count'];
$activeReservations->close();

$totalRentals = $conn->prepare("SELECT COUNT(*) as count FROM rentals WHERE user_id = ?");
$totalRentals->bind_param("i", $userId);
$totalRentals->execute();
$rentalCount = (int)$totalRentals->get_result()->fetch_assoc()['count'];
$totalRentals->close();

$completedRentals = $conn->prepare("SELECT COUNT(*) as count FROM rentals WHERE user_id = ? AND status = 'completed'");
$completedRentals->bind_param("i", $userId);
$completedRentals->execute();
$completedCount = (int)$completedRentals->get_result()->fetch_assoc()['count'];
$completedRentals->close();

$totalSpent = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM rentals WHERE user_id = ?");
$totalSpent->bind_param("i", $userId);
$totalSpent->execute();
$spent = $totalSpent->get_result()->fetch_assoc()['total'];
$totalSpent->close();

/* "My Reservations" table. Live bookings float to the top, then the nearest
   pickup date first, so the rows a customer can still act on are never buried
   under a year of archived bookings. */
$recentReservations = $conn->prepare("SELECT r.*, v.brand, v.model, v.year, v.type, v.transmission
                                      FROM reservations r
                                      JOIN vehicles v ON r.vehicle_id = v.id
                                      WHERE r.user_id = ?
                                      ORDER BY (r.status IN ('pending', 'confirmed')) DESC, r.pickup_date DESC
                                      LIMIT 5");
$recentReservations->bind_param("i", $userId);
$recentReservations->execute();
$recent = $recentReservations->get_result();
$recentRows = $recent->fetch_all(MYSQLI_ASSOC);
$recent->free();

/* Rental history table. The filter tabs are a plain GET round-trip rather than
   a JS toggle so a filtered view is linkable, survives a reload, and still
   renders every row with scripting disabled. Five rows, same as the
   reservations teaser: this table is a summary, and the full list lives at
   customer/rental-history.php behind the "View All" link. */
$range = $_GET['range'] ?? 'all';
if (!in_array($range, ['all', 'year', 'completed', 'archived'], true)) {
    $range = 'all';
}
$rangeWhere = '';
if ($range === 'year') {
    $rangeWhere = ' AND YEAR(ren.pickup_date) = YEAR(CURDATE())';
} elseif ($range === 'completed') {
    $rangeWhere = " AND ren.status = 'completed'";
} elseif ($range === 'archived') {
    $rangeWhere = " AND ren.status = 'cancelled'";
}

$historyStmt = $conn->prepare("SELECT ren.*, v.brand, v.model, v.year, v.type, v.transmission,
                                      res.booking_reference, res.pickup_location
                               FROM rentals ren
                               JOIN vehicles v ON ren.vehicle_id = v.id
                               LEFT JOIN reservations res ON res.id = ren.reservation_id
                               WHERE ren.user_id = ?" . $rangeWhere . "
                               ORDER BY ren.pickup_date DESC, ren.id DESC
                               LIMIT 5");
$historyStmt->bind_param("i", $userId);
$historyStmt->execute();
$historyRows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();

/* One status renderer for both tables: the badge tone, its dot, and the label
   the design shows. Booking and rental statuses overlap, so the shared lookup
   keeps a "confirmed" chip identical in the two sections. */
$statusChip = function ($status) {
    $s = strtolower(trim((string)$status));
    $ok    = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-label-sm font-bold whitespace-nowrap bg-emerald-50 text-emerald-700 border-emerald-200';
    $warn  = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-label-sm font-bold whitespace-nowrap bg-amber-50 text-amber-700 border-amber-200';
    $brand = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-label-sm font-bold whitespace-nowrap bg-primary-container text-on-primary-container border-primary-fixed-dim';
    $live  = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-label-sm font-bold whitespace-nowrap bg-secondary-fixed text-on-secondary-fixed border-secondary-fixed-dim';
    $bad   = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-label-sm font-bold whitespace-nowrap bg-error-container text-on-error-container border-error/40';
    $mute  = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-label-sm font-bold whitespace-nowrap bg-surface-container-high text-on-surface-variant border-outline-variant';

    $map = [
        'pending'     => [$warn,  'bg-amber-500',      'Pending'],
        'confirmed'   => [$brand, 'bg-primary',        'Confirmed'],
        'active'      => [$live,  'bg-secondary',      'Active'],
        'in_progress' => [$live,  'bg-secondary',      'In Progress'],
        'overdue'     => [$bad,   'bg-error',          'Overdue'],
        'completed'   => [$ok,    'bg-emerald-500',    'Completed'],
        'available'   => [$ok,    'bg-emerald-500',    'Completed'],
        'cancelled'   => [$mute,  'bg-outline',        'Cancelled'],
        'maintenance' => [$mute,  'bg-outline',        'Maintenance'],
    ];
    if (isset($map[$s])) {
        return $map[$s];
    }
    return [$mute, 'bg-outline', ucfirst(str_replace('_', ' ', $s)) ?: 'Unknown'];
};

/* Duration is stored on the rental, but a walk-in or an older row can have it
   null, so fall back to the difference between the two dates. */
$rentalDays = function ($row) {
    $days = (int)($row['duration'] ?? 0);
    if ($days > 0) {
        return $days;
    }
    $start = strtotime($row['pickup_date'] ?? '');
    $end   = strtotime($row['expected_return_date'] ?? '');
    if (!$start || !$end) {
        return 0;
    }
    return max(1, (int)round(($end - $start) / 86400));
};

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20 px-4 sm:px-6 md:px-12 max-w-container-max-width mx-auto w-full">

  <!-- Breadcrumb + page header -->
  <section class="mb-8">
    <nav class="flex items-center gap-2 text-label-sm font-label-sm text-outline mb-2" aria-label="Breadcrumb">
      <span class="hover:text-on-surface transition-colors">Customer Portal</span>
      <i aria-hidden="true" class="bi bi-chevron-right text-xs align-middle"></i>
      <span class="text-on-surface">Dashboard</span>
    </nav>

    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Welcome Back, <?= e($_SESSION['name']) ?></h1>
        <p class="text-body-md font-body-md text-outline mt-1">Rental management, vehicle bookings, and account overview for your Carvella fleet.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-label-sm font-label-sm">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
          System Online
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-container text-on-primary-container border border-primary-fixed-dim text-label-sm font-label-sm">
          <i aria-hidden="true" class="bi bi-geo-alt-fill text-sm align-middle"></i>
          Dagupan Hub Central
        </span>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary-container text-on-primary-container border border-primary-fixed-dim text-label-sm font-label-sm">
          <span class="w-1.5 h-1.5 rounded-full bg-primary"></span>
          100% On-Site OTC Cash Only
        </span>
      </div>
    </div>

    <!-- Account quick badges -->
    <div class="flex flex-wrap items-center gap-2 mt-4">
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container-lowest border border-outline-variant text-label-sm font-bold text-on-surface">
        <i aria-hidden="true" class="bi bi-calendar3 text-base align-middle text-primary"></i>
        Active Reservations: <strong class="font-extrabold"><?= $activeCount ?></strong>
      </span>
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container-lowest border border-outline-variant text-label-sm font-bold text-on-surface">
        <i aria-hidden="true" class="bi bi-key text-base align-middle text-primary"></i>
        <?= $completedCount ?> Completed Rental<?= $completedCount === 1 ? '' : 's' ?>
      </span>
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container-lowest border border-outline-variant text-label-sm font-bold text-on-surface">
        <i aria-hidden="true" class="bi bi-shield-check text-base align-middle text-secondary"></i>
        Verified Account
      </span>
      <?php if ($onRoadCount > 0): ?>
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-secondary-fixed text-on-secondary-fixed border border-secondary-fixed-dim text-label-sm font-bold">
        <i aria-hidden="true" class="bi bi-truck text-base align-middle"></i>
        <strong class="font-extrabold"><?= $onRoadCount ?></strong> On the Road
        <?php if (!empty($nextReturn)): ?>
        <span class="font-normal opacity-80">&middot; due <?= e(formatDate($nextReturn['expected_return_date'])) ?></span>
        <?php endif; ?>
      </span>
      <?php endif; ?>
      <?php if ($overdueCount > 0): ?>
      <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-error-container text-on-error-container border border-error/40 text-label-sm font-bold">
        <i aria-hidden="true" class="bi bi-exclamation-triangle text-base align-middle"></i>
        <strong class="font-extrabold"><?= $overdueCount ?></strong> Overdue
      </span>
      <?php endif; ?>
    </div>
  </section>

  <!-- Live voucher banner -->
  <section class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-4 sm:p-5 mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div class="flex items-center gap-3.5">
      <span class="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center flex-shrink-0">
        <i aria-hidden="true" class="bi bi-key text-[20px]"></i>
      </span>
      <div>
        <span class="block text-[10px] font-bold text-primary uppercase tracking-wider">Active Booking Live Voucher</span>
        <h2 class="text-headline-sm font-headline-sm text-on-surface">You have <?= $activeCount ?> active reservation<?= $activeCount === 1 ? '' : 's' ?></h2>
      </div>
    </div>
    <div class="self-start sm:self-center">
      <span class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-label-sm font-bold">
        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
        Ready for Action
      </span>
    </div>
  </section>

  <!-- Reservation overview -->
  <section class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-6 sm:p-7 mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-6 border-b border-outline-variant/50 gap-4">
      <div>
        <span class="text-[11px] font-bold text-primary uppercase tracking-wider">Reservation Overview</span>
        <h2 class="text-headline-lg font-headline-lg text-on-surface mt-1">Your Active Bookings</h2>
        <p class="text-body-sm font-body-sm text-outline mt-0.5">Manage your pending and confirmed reservations below.</p>
      </div>
      <a href="<?= BASE_URL ?>/customer/vehicles.php" class="inline-flex items-center justify-center px-4 py-2.5 bg-primary hover:bg-secondary text-on-primary text-body-sm font-bold rounded-xl active:scale-95 transition-all">
        <i aria-hidden="true" class="bi bi-plus-lg text-base align-middle mr-1.5"></i>
        New Reservation
      </a>
    </div>

    <div class="mt-6 p-4 rounded-xl bg-primary-container/70 border border-primary-fixed-dim flex items-start gap-3.5">
      <i aria-hidden="true" class="bi bi-clock text-base align-middle text-primary mt-0.5"></i>
      <div>
        <div class="text-[11px] font-bold text-on-primary-container uppercase tracking-wide">Quick Actions</div>
        <p class="text-body-sm font-body-sm text-on-surface mt-1 leading-relaxed">
          Check your reservation details, view vehicle specs, or proceed to pick-up at Dagupan City Central Hub. 100% On-Site OTC cash settlement on vehicle pickup with physical key handover.
        </p>
      </div>
    </div>

    <?php if ($activeCount === 0): ?>
    <div class="mt-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-xl border border-dashed border-outline-variant px-5 py-4">
      <div class="flex items-center gap-3">
        <i aria-hidden="true" class="bi bi-car-front text-[22px] align-middle text-outline"></i>
        <div>
          <h3 class="text-body-md font-bold text-on-surface">No active bookings</h3>
          <p class="text-body-sm font-body-sm text-outline mt-0.5">You have nothing pending or confirmed right now. Pick a car and reserve it in a couple of taps.</p>
        </div>
      </div>
      <a href="<?= BASE_URL ?>/customer/vehicles.php" class="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-primary text-on-primary text-body-sm font-bold rounded-xl hover:bg-secondary active:scale-95 transition-all">
        <i aria-hidden="true" class="bi bi-compass text-base align-middle"></i>
        Browse Vehicles
      </a>
    </div>
    <?php endif; ?>
  </section>

  <!-- Metric summary -->
  <section class="metric-stagger grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
    <div class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-6 hover:border-primary/40 transition-colors">
      <span class="w-10 h-10 rounded-xl bg-primary/10 text-primary inline-flex items-center justify-center mb-4">
        <i aria-hidden="true" class="bi bi-calendar3 text-[20px]"></i>
      </span>
      <div class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-2">Active Reservations</div>
      <div class="text-numeric-stat font-numeric-stat text-on-surface"><?= $activeCount ?></div>
    </div>
    <div class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-6 hover:border-primary/40 transition-colors">
      <span class="w-10 h-10 rounded-xl bg-primary/10 text-primary inline-flex items-center justify-center mb-4">
        <i aria-hidden="true" class="bi bi-key text-[20px]"></i>
      </span>
      <div class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-2">Total Rentals</div>
      <div class="text-numeric-stat font-numeric-stat text-on-surface"><?= $rentalCount ?></div>
    </div>
    <div class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-6 hover:border-primary/40 transition-colors">
      <span class="w-10 h-10 rounded-xl bg-primary/10 text-primary inline-flex items-center justify-center mb-4">
        <i aria-hidden="true" class="bi bi-cash-stack text-[20px]"></i>
      </span>
      <div class="text-[11px] font-bold text-on-surface-variant uppercase tracking-wider mb-2">Total Spent</div>
      <div class="text-numeric-stat font-numeric-stat text-on-surface"><?= e(formatCurrency($spent)) ?></div>
    </div>
  </section>

  <!-- My Reservations -->
  <section class="mb-10">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
      <div>
        <h2 class="text-headline-md font-headline-md text-on-surface">My Reservations</h2>
        <p class="text-body-sm font-body-sm text-outline mt-0.5">Manage and inspect your active reservations and upcoming bookings.</p>
      </div>
      <a href="<?= BASE_URL ?>/customer/reservations.php" class="inline-flex items-center text-label-sm font-bold px-3 py-1.5 rounded-full border border-outline-variant bg-surface-container-lowest hover:bg-surface-container-low text-on-surface transition">
        <i aria-hidden="true" class="bi bi-eye text-sm align-middle mr-1.5"></i>
        View All
      </a>
    </div>

    <div class="bg-surface-container-lowest border border-outline-variant rounded-2xl overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse min-w-[760px]">
          <thead>
            <tr class="bg-surface-container-low border-b border-outline-variant">
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Reservation ID</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Vehicle</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Pickup Date</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Return Date</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Status</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/50">
            <?php foreach ($recentRows as $row):
              [$chipClass, $dotClass, $chipLabel] = $statusChip($row['status']);
              $vehicleName = trim($row['year'] . ' ' . $row['brand'] . ' ' . $row['model']);
            ?>
            <tr class="hover:bg-primary/5 transition-colors">
              <td class="py-4 px-6 text-label-md font-label-md font-bold text-primary">#<?= e($row['booking_reference'] ?? $row['id']) ?></td>
              <td class="py-4 px-6">
                <div class="text-body-sm font-bold text-on-surface"><?= e($vehicleName) ?></div>
                <div class="text-label-sm font-label-sm text-outline mt-0.5">
                  <?= e(ucfirst($row['type'] ?? 'Vehicle')) ?>
                  <?php if (!empty($row['transmission'])): ?>
                    &bull; <?= e(ucfirst($row['transmission'])) ?>
                  <?php endif; ?>
                  &bull; Dagupan Central Hub
                </div>
              </td>
              <td class="py-4 px-6 text-body-sm font-medium text-on-surface-variant"><?= e(formatDate($row['pickup_date'])) ?></td>
              <td class="py-4 px-6 text-body-sm font-medium text-on-surface-variant"><?= e(formatDate($row['return_date'])) ?></td>
              <td class="py-4 px-6">
                <span class="<?= $chipClass ?>"><span class="w-1.5 h-1.5 rounded-full <?= $dotClass ?>"></span><?= e($chipLabel) ?></span>
              </td>
              <td class="py-4 px-6 text-right">
                <a href="<?= BASE_URL ?>/customer/reservation-details.php?id=<?= (int)$row['id'] ?>" class="inline-block px-3.5 py-1 bg-primary/10 text-primary hover:bg-primary/20 font-bold text-label-sm rounded-lg transition">
                  Details
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentRows)): ?>
            <tr>
              <td colspan="6" class="py-10 text-center">
                <i aria-hidden="true" class="bi bi-inbox text-[40px] text-outline/40 block mb-2"></i>
                <p class="text-body-sm font-body-sm text-outline">No reservations yet. <a href="<?= BASE_URL ?>/customer/vehicles.php" class="text-primary font-bold hover:underline">Browse vehicles</a> to get started.</p>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Rental History -->
  <section class="mb-12">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 gap-4">
      <div>
        <h2 class="text-headline-md font-headline-md text-on-surface">Rental History</h2>
        <p class="text-body-sm font-body-sm text-outline mt-0.5">Past completed trips, physical counter handovers, and OTC settlement receipts.</p>
      </div>
      <div class="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0">
        <?php
          $tabs = [
              'all'       => 'All Time',
              'year'      => 'This Year',
              'completed' => 'Completed',
              'archived'  => 'Archived',
          ];
        ?>
        <?php foreach ($tabs as $key => $label):
          $isActive = ($range === $key);
        ?>
        <a href="?range=<?= e($key) ?>#rental-history"
           class="shrink-0 px-3.5 py-1.5 rounded-full text-label-sm font-bold transition <?= $isActive
              ? 'bg-primary text-on-primary'
              : 'bg-surface-container-lowest border border-outline-variant text-on-surface-variant hover:border-outline' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php /* Same pill as the reservations "View All": the table is a five-row
                 teaser and the full history lives on its own page. */ ?>
        <a href="<?= BASE_URL ?>/customer/rental-history.php" class="ml-1.5 shrink-0 inline-flex items-center text-label-sm font-bold px-3 py-1.5 rounded-full border border-outline-variant bg-surface-container-lowest hover:bg-surface-container-low text-on-surface transition">
          <i aria-hidden="true" class="bi bi-eye text-sm align-middle mr-1.5"></i>
          View All
        </a>
      </div>
    </div>

    <div class="bg-surface-container-lowest border border-outline-variant rounded-2xl overflow-hidden" id="rental-history">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse min-w-[880px]">
          <thead>
            <tr class="bg-surface-container-low border-b border-outline-variant">
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Reservation ID</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Vehicle</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Journey Period</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Handover Desk</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">On-Site Cash OTC</th>
              <th scope="col" class="py-3.5 px-6 text-[10px] uppercase tracking-wider font-bold text-outline">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/50">
            <?php foreach ($historyRows as $row):
              [$chipClass, $dotClass, $chipLabel] = $statusChip($row['status']);
              $days = $rentalDays($row);
              $handoverDesk = trim((string)($row['pickup_location'] ?? '')) !== ''
                  ? $row['pickup_location']
                  : 'Dagupan Hub Central';
            ?>
            <tr class="hover:bg-primary/5 transition-colors">
              <td class="py-4 px-6 text-label-md font-label-md font-bold text-primary">
                #<?= e($row['booking_reference'] ?: ('Rental #' . (int)$row['id'])) ?>
              </td>
              <td class="py-4 px-6 text-body-sm font-bold text-on-surface"><?= e(trim($row['year'] . ' ' . $row['brand'] . ' ' . $row['model'])) ?></td>
              <td class="py-4 px-6">
                <div class="text-body-sm font-medium text-on-surface"><?= e(formatDate($row['pickup_date'])) ?> &ndash; <?= e(formatDate($row['expected_return_date'])) ?></div>
                <?php if ($days > 0): ?>
                <div class="text-label-sm font-label-sm font-bold text-primary mt-0.5">(<?= $days ?> Day<?= $days === 1 ? '' : 's' ?>)</div>
                <?php endif; ?>
              </td>
              <td class="py-4 px-6 text-body-sm font-medium text-on-surface-variant"><?= e($handoverDesk) ?></td>
              <td class="py-4 px-6">
                <div class="text-body-sm font-bold text-on-surface"><?= e(formatCurrency($row['total_amount'])) ?></div>
                <div class="text-label-sm font-label-sm text-outline mt-0.5">Settled on Pickup</div>
              </td>
              <td class="py-4 px-6">
                <span class="<?= $chipClass ?>"><span class="w-1.5 h-1.5 rounded-full <?= $dotClass ?>"></span><?= e($chipLabel) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($historyRows)): ?>
            <tr>
              <td colspan="6" class="py-10 text-center">
                <i aria-hidden="true" class="bi bi-clock-history text-[40px] text-outline/40 block mb-2"></i>
                <p class="text-body-sm font-body-sm text-outline">
                  <?php if ($range === 'all'): ?>
                    You haven't completed any rentals yet.
                  <?php else: ?>
                    No rentals match the "<?= e($tabs[$range]) ?>" filter.
                  <?php endif; ?>
                </p>
                <?php if ($range !== 'all'): ?>
                <a href="?range=all#rental-history" class="inline-block mt-2 text-body-sm font-bold text-primary hover:underline">Show all rentals</a>
                <?php else: ?>
                <a href="<?= BASE_URL ?>/customer/vehicles.php" class="inline-block mt-2 text-body-sm font-bold text-primary hover:underline">Browse the fleet</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of customer/dashboard.php */
?>
