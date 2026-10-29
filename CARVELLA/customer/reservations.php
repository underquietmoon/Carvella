<?php
/* customer/reservations.php - Customer reservation list. */
$pageTitle = 'My Reservations - Carvella';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$userId = $_SESSION['user_id'];
$filter = $_GET['status'] ?? '';
$filter = in_array($filter, ['pending', 'confirmed', 'active', 'completed', 'cancelled'], true) ? $filter : '';

/* One WHERE shared by the COUNT and the page query (both keep the vehicle
   JOIN), so the pager's totals can never drift from the rows actually
   rendered. */
$where = "WHERE r.user_id = ?";
$params = [$userId];
$types = 'i';

if ($filter) {
    $where .= " AND r.status = ?";
    $params[] = $filter;
    $types .= 's';
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS count FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id " . $where);
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['count'];
$countStmt->close();

$pg = paginationBounds(paginationPage(), $total, 6);
$perPage = $pg['per_page'];
$offset  = $pg['offset'];

$query = "SELECT r.*, v.brand, v.model, v.year, v.image FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id " . $where . " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$reservations = $stmt->get_result();
$stmt->close();

$statusLabels = [
    'pending' => 'Pending Review',
    'confirmed' => 'Confirmed',
    'active' => 'Active Rental',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$statusColors = [
    'pending' => 'bg-amber-50 text-amber-800 border border-amber-200',
    'confirmed' => 'bg-primary-fixed text-on-primary-fixed-variant border border-primary-fixed-dim',
    'active' => 'bg-secondary-fixed text-on-secondary-fixed border border-secondary-fixed-dim',
    'completed' => 'bg-emerald-50 text-emerald-800 border border-emerald-200',
    'cancelled' => 'bg-error-container text-on-error-container border border-error-container',
];

$counts = [
    'all' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'active' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

// Tab totals must come from an unfiltered aggregate. Counting the rows of the
// filtered page would zero out every tab the visitor is not currently on.
$countRows = $conn->prepare("SELECT status, COUNT(*) AS c FROM reservations WHERE user_id = ? GROUP BY status");
$countRows->bind_param("i", $userId);
$countRows->execute();
$countResult = $countRows->get_result();
while ($row = $countResult->fetch_assoc()) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['c'];
        $counts['all'] += (int)$row['c'];
    }
}
$countRows->close();

$allRows = [];
while ($r = $reservations->fetch_assoc()) {
    $allRows[] = $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reservation'])) {
    verify_csrf();
    $cancelId = (int)($_POST['cancel_reservation'] ?? 0);

    // Scope the lookup to the signed-in owner: never trust the posted id alone.
    $owner = $conn->prepare("SELECT id, status FROM reservations WHERE id = ? AND user_id = ?");
    $owner->bind_param("ii", $cancelId, $userId);
    $owner->execute();
    $owned = $owner->get_result()->fetch_assoc();
    $owner->close();

    if (!$owned) {
        setFlash('error', 'That reservation could not be found in your account.');
    } elseif ($owned['status'] !== 'pending') {
        setFlash('error', 'Only a reservation that is still pending can be cancelled. Contact the branch if it has already been confirmed.');
    } else {
        $outcome = cancelReservationRecord($conn, $cancelId, 'Cancelled by customer');
        if (!empty($outcome['success'])) {
            logActivity('cancelled', "Reservation #$cancelId cancelled by customer");
            setFlash('success', 'Reservation cancelled. Any hold on the vehicle has been released.');
        } else {
            setFlash('error', $outcome['error'] ?? 'Unable to cancel this reservation right now.');
        }
    }

    /* PRG: land back on the exact tab and page the customer was viewing.
       The form action below carries the query string, so $_GET still holds
       the status filter and the page number on this POST. */
    $back = $_GET ? '?' . http_build_query($_GET) : '';
    redirect(BASE_URL . '/customer/reservations.php' . $back);
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20 px-4 sm:px-6 md:px-12 max-w-container-max-width mx-auto w-full">
<section class="space-y-6">
    <?php if ($flash = getFlash('success')): ?>
    <div class="bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-2xl px-4 py-3 flex items-center gap-3 text-body-sm" role="status" data-flash>
        <i class="bi bi-check-circle text-[20px]" aria-hidden="true"></i>
        <span><?= e($flash) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($flash = getFlash('error')): ?>
    <div class="bg-error-container text-on-error-container border border-error-container rounded-2xl px-4 py-3 flex items-center gap-3 text-body-sm" role="alert" data-flash>
        <i class="bi bi-exclamation-circle text-[20px]" aria-hidden="true"></i>
        <span><?= e($flash) ?></span>
    </div>
    <?php endif; ?>
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
            <nav class="flex items-center gap-2 text-label-sm text-tertiary mb-3">
                <a class="hover:text-primary" href="<?= BASE_URL ?>/customer/dashboard.php">Dashboard</a>
                <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
                <span class="text-on-surface font-semibold">My Reservations</span>
            </nav>
            <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">My Reservations</h1>
            <p class="text-body-md font-body-md text-on-surface-variant mt-0.5">View and manage your vehicle rental bookings.</p>
        </div>
    </div>

    <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
        <a href="<?= e(queryUrl(['status' => null])) ?>" class="px-4 py-2 rounded-full font-label-md text-label-md shrink-0 flex items-center gap-1.5 transition-all <?= !$filter ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant hover:border-primary hover:text-primary' ?>">
            <span>All</span>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?= !$filter ? 'bg-on-primary/20' : 'bg-surface-container-high text-tertiary' ?>"><?= $counts['all'] ?></span>
        </a>
        <?php foreach (['pending','confirmed','active','completed','cancelled'] as $s): ?>
        <a href="<?= e(queryUrl(['status' => $s])) ?>" class="px-4 py-2 rounded-full font-label-md text-label-md shrink-0 flex items-center gap-1.5 transition-all <?= $filter === $s ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant hover:border-primary hover:text-primary' ?>">
            <span><?= ucfirst($s) ?></span>
            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold <?= $filter === $s ? 'bg-on-primary/20' : 'bg-surface-container-high text-tertiary' ?>"><?= $counts[$s] ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if (count($allRows) > 0): ?>
    <div class="space-y-4">
        <?php foreach ($allRows as $row): ?>
        <div class="bg-surface-container-lowest border border-outline-variant/50 rounded-3xl shadow-luxury-1 overflow-hidden hover:border-primary/30 transition-all">
            <div class="p-5 md:p-6">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-3">
                            <span class="font-bold text-primary font-mono text-label-md">#<?= htmlspecialchars($row['booking_reference'] ?? $row['id']) ?></span>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-label-sm font-bold <?= $statusColors[$row['status']] ?? 'bg-surface-container-high text-on-surface-variant border border-outline-variant' ?>">
                                <?= $statusLabels[$row['status']] ?? ucfirst($row['status']) ?>
                            </span>
                        </div>
                        <h3 class="text-headline-sm font-headline-sm text-on-surface font-semibold mb-3">
                            <?= htmlspecialchars($row['year'] . ' ' . $row['brand'] . ' ' . $row['model']) ?>
                        </h3>
                        <div class="flex flex-wrap items-center gap-3 text-body-sm font-body-sm">
                            <div class="flex items-center gap-2 text-on-surface">
                                <i aria-hidden="true" class="bi bi-calendar-date text-[18px] text-primary"></i>
                                <span class="font-semibold"><?= formatDate($row['pickup_date']) ?></span>
                                <i aria-hidden="true" class="bi bi-arrow-right text-[16px] text-outline"></i>
                                <span class="font-semibold"><?= formatDate($row['return_date']) ?></span>
                            </div>
                            <div class="h-4 w-px bg-outline-variant/60 hidden md:block"></div>
                            <div class="flex items-center gap-2">
                                <i aria-hidden="true" class="bi bi-cash-stack text-[18px] text-primary"></i>
                                <span class="font-bold text-on-surface"><?= formatCurrency($row['total_amount']) ?></span>
                            </div>
                            <?php /* The GCash deposit the customer sent, and where it
                                     stands. The customer can see the hub is holding
                                     their money from the list, not only from the
                                     details page. */ ?>
                            <?php if (!empty($row['payment_method']) && (float)($row['payment_amount'] ?? 0) > 0): ?>
                            <div class="h-4 w-px bg-outline-variant/60 hidden md:block"></div>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold <?= ($row['payment_status'] ?? '') === 'verified' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200' ?>">
                                <i aria-hidden="true" class="bi bi-cash-coin text-[13px]"></i>
                                GCash <?= formatCurrency($row['payment_amount']) ?> &middot;
                                <?= ($row['payment_status'] ?? '') === 'verified' ? 'Verified' : 'To verify' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <a href="<?= BASE_URL ?>/customer/reservation-details.php?id=<?= $row['id'] ?>" class="px-4 py-2.5 rounded-full bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface font-label-md text-label-md flex items-center gap-2 transition-all">
                            <i aria-hidden="true" class="bi bi-eye text-[18px]"></i>
                            View Details
                        </a>
                        <?php if ($row['status'] === 'pending'): ?>
                        <?php /* The action keeps the full query string (status + page) so the
                                 PRG redirect above can rebuild the customer's exact view. */ ?>
                        <form method="POST" action="<?= e(queryUrl([], false)) ?>" class="contents"
                              data-confirm="Cancel reservation <?= e($row['booking_reference']) ?>? This cannot be undone.">
                            <?= csrf_field() ?>
                            <?php if ($filter !== ''): ?>
                            <input type="hidden" name="status" value="<?= e($filter) ?>">
                            <?php endif; ?>
                            <input type="hidden" name="cancel_reservation" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="cancel-res-btn px-4 py-2.5 rounded-full bg-error-container text-on-error-container border border-error-container font-label-md text-label-md flex items-center gap-2 hover:bg-error hover:text-on-error transition-all cursor-pointer">
                                <i class="bi bi-x-lg text-[18px]" aria-hidden="true"></i>
                                Cancel
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="pt-2">
        <?= renderPagination($pg, ['summary' => true, 'noun' => 'reservations']) ?>
    </div>
    <?php else: ?>
    <div class="text-center py-12 bg-surface-container-lowest rounded-3xl border border-outline-variant/60">
        <i aria-hidden="true" class="bi bi-calendar3 text-[56px] text-outline-variant/50 mb-4 block"></i>
        <p class="text-headline-sm font-headline-sm text-on-surface-variant mb-1">No reservations found</p>
        <p class="text-body-sm font-body-sm text-tertiary mb-6"><?= $filter ? 'No reservations match the selected status.' : 'You haven\'t made any reservations yet.' ?></p>
        <?php if (!$filter): ?>
        <a href="<?= BASE_URL ?>/customer/vehicles.php" class="px-6 py-3 rounded-full bg-primary text-on-primary font-label-lg font-bold shadow-luxury-glow hover:bg-secondary active:scale-95 transition-all inline-flex items-center gap-2">
            <i aria-hidden="true" class="bi bi-car-front text-[20px]"></i>
            Browse Vehicles
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
</main>

<?php
/* No click handler on .cancel-res-btn on purpose. The button is the
   submit control of the POST form above, which already carries csrf_field()
   and the owner-scoped, pending-only handler. A fetch() shim used to sit
   here and read btn.getAttribute('href') — always null on a <button type="button"> — so
   it POSTed to /customer/null, got a 404, and e.preventDefault() stopped
   the real request from ever firing. The delegated submit guard in
   assets/js/main.js owns the confirmation and the double-submit lock. */
?>
<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of customer/reservations.php */
?>
