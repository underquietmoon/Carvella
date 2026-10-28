<?php
/* rental-history.php - Carvella Car Rental System */
$pageTitle = 'Rental History - Carvella';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$userId = $_SESSION['user_id'];

/* The widgets and status chips describe the whole history, not the page on
   screen, so they come from one aggregate that mirrors the card query's
   JOIN exactly: a rental whose vehicle row is gone never rendered a card
   and must not surface here either. The card query is LIMITed for
   pagination and can no longer feed them in a first pass. */
$statsStmt = $conn->prepare("SELECT COUNT(*) AS total_rentals,
                                    COALESCE(SUM(ren.total_amount), 0) AS total_spent,
                                    COALESCE(SUM(ren.status = 'completed'), 0) AS completed_count,
                                    COALESCE(SUM(ren.status IN ('active', 'in_progress')), 0) AS active_count,
                                    COALESCE(SUM(ren.status = 'cancelled'), 0) AS cancelled_count,
                                    COALESCE(SUM(ren.status = 'overdue'), 0) AS overdue_count
                             FROM rentals ren
                             JOIN vehicles v ON ren.vehicle_id = v.id
                             WHERE ren.user_id = ?");
$statsStmt->bind_param("i", $userId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$totalRentals = (int)($stats['total_rentals'] ?? 0);
$totalSpent = (float)($stats['total_spent'] ?? 0);
$completedCount = (int)($stats['completed_count'] ?? 0);
$activeCount = (int)($stats['active_count'] ?? 0);
$cancelledCount = (int)($stats['cancelled_count'] ?? 0);
$overdueCount = (int)($stats['overdue_count'] ?? 0);
$pendingCount = max(0, $totalRentals - $completedCount - $activeCount - $cancelledCount - $overdueCount);

/* $totalRentals already counts the joined set, so it is the exact
   pagination total — the LEFT JOIN to reservations only adds columns. */
$pg = paginationBounds(paginationPage(), $totalRentals, 8);
$perPage = $pg['per_page'];
$offset  = $pg['offset'];

$stmt = $conn->prepare("SELECT ren.*, v.brand, v.model, v.year, v.daily_rate, res.booking_reference, res.pickup_location, res.return_location
                        FROM rentals ren
                        JOIN vehicles v ON ren.vehicle_id = v.id
                        LEFT JOIN reservations res ON res.id = ren.reservation_id
                        WHERE ren.user_id = ?
                        ORDER BY ren.created_at DESC
                        LIMIT ? OFFSET ?");
$stmt->bind_param("iii", $userId, $perPage, $offset);
$stmt->execute();
/* Collected into a plain array in one pass: the template used to iterate
   the result set twice via data_seek(0), which a LIMITed page cannot do. */
$rentalRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$timelineByRental = [];
$timelineRows = fetchRentalTransactionHistory($conn, ['user_id' => (int)$userId, 'limit' => 500]);
foreach ($timelineRows as $tx) {
    if (!empty($tx['rental_id'])) {
        $timelineByRental[(int)$tx['rental_id']][] = $tx;
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20 px-4 sm:px-6 md:px-12 max-w-container-max-width mx-auto w-full">

  <div class="mb-8">
    <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Rental History</h1>
    <p class="text-body-md font-body-md text-on-surface-variant mt-1">Your past and present vehicle rentals with Carvella.</p>
  </div>

  <?php if ($totalRentals > 0): ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
    <div class="rounded-3xl bg-surface-container-lowest border border-outline-variant/70 shadow-luxury-1 p-6 flex items-center justify-between">
      <div>
        <span class="text-label-md font-bold text-on-surface-variant uppercase">Total Rentals</span>
        <span class="text-numeric-stat font-numeric-stat text-on-surface mt-1 block"><?= $totalRentals ?></span>
      </div>
      <div class="w-12 h-12 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center">
        <i aria-hidden="true" class="bi bi-clock-history text-[24px]"></i>
      </div>
    </div>
    <div class="rounded-3xl bg-surface-container-lowest border border-outline-variant/70 shadow-luxury-1 p-6 flex items-center justify-between">
      <div>
        <span class="text-label-md font-bold text-on-surface-variant uppercase">Total Spent</span>
        <span class="text-numeric-stat font-numeric-stat text-on-surface mt-1 block"><?= formatCurrency($totalSpent) ?></span>
      </div>
      <div class="w-12 h-12 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center">
        <i aria-hidden="true" class="bi bi-cash-stack text-[24px]"></i>
      </div>
    </div>
  </div>

  <div class="flex items-center gap-2 overflow-x-auto pb-2 mb-8">
    <span class="px-4 py-2 rounded-full bg-primary text-on-primary font-label-md text-label-md shrink-0 shadow-sm inline-flex items-center gap-1.5">
      All Rentals
      <span class="px-2 py-0.5 rounded-full bg-surface-container-lowest/20 text-[11px] font-bold"><?= $totalRentals ?></span>
    </span>
    <?php if ($completedCount > 0): ?>
    <span class="px-4 py-2 rounded-full bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md shrink-0 inline-flex items-center gap-1.5">
      Completed
      <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-bold"><?= $completedCount ?></span>
    </span>
    <?php endif; ?>
    <?php if ($activeCount > 0): ?>
    <span class="px-4 py-2 rounded-full bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md shrink-0 inline-flex items-center gap-1.5">
      Active
      <span class="px-2 py-0.5 rounded-full bg-secondary-fixed text-on-secondary-fixed text-[11px] font-bold"><?= $activeCount ?></span>
    </span>
    <?php endif; ?>
    <?php if ($pendingCount > 0): ?>
    <span class="px-4 py-2 rounded-full bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md shrink-0 inline-flex items-center gap-1.5">
      Pending
      <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 text-[11px] font-bold"><?= $pendingCount ?></span>
    </span>
    <?php endif; ?>
    <?php if ($overdueCount > 0): ?>
    <span class="px-4 py-2 rounded-full bg-error-container text-on-error-container font-label-md font-label-md shrink-0 inline-flex items-center gap-1.5">
      Overdue
      <span class="px-2 py-0.5 rounded-full bg-surface-container-lowest/30 text-[11px] font-bold"><?= $overdueCount ?></span>
    </span>
    <?php endif; ?>
    <?php if ($cancelledCount > 0): ?>
    <span class="px-4 py-2 rounded-full bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md shrink-0 inline-flex items-center gap-1.5">
      Cancelled
      <span class="px-2 py-0.5 rounded-full bg-error-container text-on-error-container text-[11px] font-bold"><?= $cancelledCount ?></span>
    </span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="space-y-4">
    <?php if ($totalRentals > 0): ?>
      <?php foreach ($rentalRows as $row):
        $st = strtolower($row['status']);
        $rentalTimeline = $timelineByRental[(int)$row['id']] ?? [];
        if ($st === 'completed' || $st === 'available') {
          $statusClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 text-label-sm font-bold';
        } elseif ($st === 'pending') {
          $statusClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-50 text-amber-800 border border-amber-200 text-label-sm font-bold';
        } elseif ($st === 'confirmed') {
          $statusClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary-fixed text-on-primary-fixed-variant border border-primary-fixed-dim text-label-sm font-bold';
        } elseif ($st === 'overdue') {
          $statusClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-error-container text-on-error-container border border-error text-label-sm font-bold';
        } elseif ($st === 'active' || $st === 'in_progress') {
          $statusClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-secondary-fixed text-on-secondary-fixed border border-secondary-fixed-dim text-label-sm font-bold';
        } elseif ($st === 'cancelled' || $st === 'maintenance') {
          $statusClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-container-high text-on-surface-variant border border-outline-variant text-label-sm font-bold';
        } else {
          $statusClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-container-high text-on-surface-variant border border-outline-variant text-label-sm font-bold';
        }
      ?>
      <div class="bg-surface-container-lowest border border-outline-variant/50 rounded-3xl p-5 md:p-6 shadow-luxury-1 hover:shadow-luxury-2 transition-shadow group">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div class="flex items-center gap-4 flex-1 min-w-0">
            <div class="w-12 h-12 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center shrink-0">
              <i aria-hidden="true" class="bi bi-car-front text-[22px]"></i>
            </div>
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-semibold text-on-surface text-headline-sm font-headline-sm truncate"><?= htmlspecialchars($row['year'] . ' ' . $row['brand'] . ' ' . $row['model']) ?></span>
                <span class="<?= $statusClass ?>"><?= ucfirst(htmlspecialchars(str_replace('_', ' ', $row['status']))) ?></span>
                <?php if (!empty($row['booking_reference'])): ?>
                <span class="px-2 py-0.5 rounded-full bg-surface-container font-mono text-[11px] font-bold text-primary">#<?= htmlspecialchars($row['booking_reference']) ?></span>
                <?php endif; ?>
              </div>
              <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1.5">
                <span class="inline-flex items-center gap-1.5 text-body-sm text-on-surface-variant">
                  <i aria-hidden="true" class="bi bi-calendar-date text-[16px] text-primary"></i>
                  <?= formatDate($row['pickup_date']) ?> — <?= formatDate($row['expected_return_date']) ?>
                </span>
                <?php if (!empty($row['pickup_location'])): ?>
                <span class="inline-flex items-center gap-1.5 text-body-sm text-on-surface-variant">
                  <i aria-hidden="true" class="bi bi-geo-alt-fill text-[16px] text-primary"></i>
                  <?= htmlspecialchars($row['pickup_location']) ?><?php if (!empty($row['return_location']) && $row['return_location'] !== $row['pickup_location']): ?> &rarr; <?= htmlspecialchars($row['return_location']) ?><?php endif; ?>
                </span>
                <?php endif; ?>
                <span class="inline-flex items-center gap-1.5 text-body-sm <?= $row['actual_return_date'] ? 'text-emerald-700' : ($st === 'overdue' ? 'text-error-container font-semibold' : 'text-on-surface-variant') ?>">
                  <i aria-hidden="true" class="bi bi-clipboard-check text-[16px] <?= $row['actual_return_date'] ? 'text-emerald-600' : ($st === 'overdue' ? 'text-error-container' : 'text-primary') ?>"></i>
                  <?php if ($row['actual_return_date']): ?>
                    Returned <?= formatDate($row['actual_return_date']) ?>
                  <?php elseif ($st === 'overdue'): ?>
                    Overdue since <?= formatDate($row['expected_return_date']) ?>
                  <?php else: ?>
                    Awaiting return
                  <?php endif; ?>
                </span>
                <?php if (!empty($row['daily_rate'])): ?>
                <span class="inline-flex items-center gap-1.5 text-body-sm text-on-surface-variant">
                  <i aria-hidden="true" class="bi bi-cash-stack text-[16px] text-primary"></i>
                  <?= formatCurrency($row['daily_rate']) ?>/day
                </span>
                <?php endif; ?>
                <?php if ($row['duration']): ?>
                <span class="inline-flex items-center gap-1.5 text-body-sm text-on-surface-variant">
                  <i aria-hidden="true" class="bi bi-clock text-[16px] text-primary"></i>
                  <?= (int)$row['duration'] ?> days
                </span>
                <?php endif; ?>
              </div>
              <?php if (!empty($rentalTimeline)): ?>
              <details class="mt-3 group">
                <summary class="cursor-pointer text-label-md font-label-md text-primary font-semibold flex items-center gap-1 select-none">
                  <i aria-hidden="true" class="bi bi-chevron-right text-[16px] group-open:rotate-90 transition-transform"></i>
                  Transaction history (<?= count($rentalTimeline) ?> event<?= count($rentalTimeline) > 1 ? 's' : '' ?>)
                </summary>
                <ol class="mt-2 ml-3 border-l border-outline-variant/60 pl-4 space-y-2">
                  <?php foreach (array_reverse($rentalTimeline) as $tx): ?>
                  <li class="text-body-sm text-on-surface-variant">
                    <span class="font-semibold text-on-surface"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $tx['event_type']))) ?></span>
                    <span class="text-tertiary">· <?= htmlspecialchars(formatDateTime($tx['created_at'])) ?></span>
                    <?php if (!empty($tx['notes'])): ?>
                      <span class="block text-tertiary text-[12px]"><?= htmlspecialchars($tx['notes']) ?></span>
                    <?php endif; ?>
                  </li>
                  <?php endforeach; ?>
                </ol>
              </details>
              <?php endif; ?>
            </div>
          </div>
          <div class="flex items-center gap-4 sm:gap-6 sm:pl-6 sm:border-l sm:border-outline-variant/30">
            <div class="text-right">
              <span class="text-label-sm text-outline block">Total Paid</span>
              <span class="text-headline-sm font-headline-sm font-bold text-on-surface"><?= formatCurrency($row['total_amount']) ?></span>
            </div>
            <?php if (!empty($row['reservation_id'])): ?>
            <a href="<?= BASE_URL ?>/customer/reservation-details.php?id=<?= $row['reservation_id'] ?>" class="px-4 py-2 rounded-full bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface inline-flex items-center gap-2 transition-all text-label-md font-label-md shrink-0">
              <i aria-hidden="true" class="bi bi-eye text-[16px]"></i>
              View
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($totalRentals === 0): ?>
    <div class="text-center py-12 bg-surface-container-lowest rounded-3xl border border-outline-variant/60">
      <div class="w-16 h-16 rounded-full bg-surface-container-low text-outline inline-flex items-center justify-center mx-auto mb-4">
        <i aria-hidden="true" class="bi bi-car-front text-[32px]"></i>
      </div>
      <h3 class="text-headline-sm font-headline-sm text-on-surface mb-1">No Rental History</h3>
      <p class="text-body-md font-body-md text-on-surface-variant mb-6">You haven't completed any rentals yet.</p>
      <a href="<?= BASE_URL ?>/customer/vehicles.php" class="px-6 py-3 rounded-full bg-primary text-on-primary font-label-lg font-bold shadow-luxury-glow hover:bg-secondary active:scale-95 transition-all inline-flex items-center gap-2">
        <i aria-hidden="true" class="bi bi-compass text-[18px]"></i>
        Browse Fleet
      </a>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($totalRentals > 0): ?>
  <div class="pt-2">
    <?= renderPagination($pg, ['summary' => true, 'noun' => 'rentals']) ?>
  </div>
  <?php endif; ?>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
