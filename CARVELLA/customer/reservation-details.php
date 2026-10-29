<?php
/* customer/reservation-details.php - Single reservation detail view. */
$pageTitle = 'Reservation Details - Carvella';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$reservationId = $_GET['id'] ?? null;
if (!$reservationId) {
    redirect(BASE_URL . '/customer/reservations.php');
}

$stmt = $conn->prepare("SELECT r.*, v.brand, v.model, v.year, v.image, v.daily_rate, v.seats, v.transmission, v.fuel_type FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ? AND r.user_id = ?");
$stmt->bind_param("ii", $reservationId, $_SESSION['user_id']);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    redirect(BASE_URL . '/customer/reservations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reservation'])) {
    verify_csrf();
    $cancelId = (int)($_POST['cancel_reservation'] ?? 0);

    // The row was already fetched with AND r.user_id = ?, so reaching here
    // means the owner is verified; re-check the id matches the page.
    if ($cancelId !== (int)$reservation['id']) {
        setFlash('error', 'That reservation could not be found in your account.');
    } elseif ($reservation['status'] !== 'pending') {
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
    redirect(BASE_URL . '/customer/reservation-details.php?id=' . (int)$reservation['id']);
}

$days = (strtotime($reservation['return_date']) - strtotime($reservation['pickup_date'])) / (60 * 60 * 24);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$status = strtolower($reservation['status']);
if ($status === 'completed' || $status === 'available') {
    $statusPillClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 text-label-sm font-bold';
} elseif ($status === 'pending') {
    $statusPillClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-50 text-amber-800 border border-amber-200 text-label-sm font-bold';
} elseif ($status === 'confirmed') {
    $statusPillClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary-fixed text-on-primary-fixed-variant border border-primary-fixed-dim text-label-sm font-bold';
} elseif ($status === 'active' || $status === 'in_progress') {
    $statusPillClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-secondary-fixed text-on-secondary-fixed border border-secondary-fixed-dim text-label-sm font-bold';
} elseif ($status === 'cancelled' || $status === 'maintenance') {
    $statusPillClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-error-container text-on-error-container border border-error-container text-label-sm font-bold';
} else {
    $statusPillClass = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-container-high text-on-surface-variant border border-outline-variant text-label-sm font-bold';
}
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20 px-4 sm:px-6 md:px-12 max-w-container-max-width mx-auto w-full">

  <nav class="flex items-center gap-2 text-label-sm text-tertiary mb-3">
    <a href="<?= BASE_URL ?>/customer/dashboard.php" class="hover:text-primary transition-colors">Dashboard</a>
    <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
    <a href="<?= BASE_URL ?>/customer/reservations.php" class="hover:text-primary transition-colors">Reservations</a>
    <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
    <span class="text-on-surface font-semibold"><?= htmlspecialchars($reservation['booking_reference']) ?></span>
  </nav>

  <?php if ($flash = getFlash('success')): ?>
  <div class="bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-2xl px-4 py-3 flex items-center gap-3 text-body-sm mb-5" role="status" data-flash>
    <i class="bi bi-check-circle text-[20px]" aria-hidden="true"></i>
    <span><?= e($flash) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($flash = getFlash('error')): ?>
  <div class="bg-error-container text-on-error-container border border-error-container rounded-2xl px-4 py-3 flex items-center gap-3 text-body-sm mb-5" role="alert" data-flash>
    <i class="bi bi-exclamation-circle text-[20px]" aria-hidden="true"></i>
    <span><?= e($flash) ?></span>
  </div>
  <?php endif; ?>

  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-8">
    <div>
      <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Reservation <?= htmlspecialchars($reservation['booking_reference']) ?></h1>
      <p class="text-body-md font-body-md text-on-surface-variant mt-1">Booking details and rental summary.</p>
    </div>
    <span class="<?= $statusPillClass ?> w-fit"><?= ucfirst(htmlspecialchars($reservation['status'])) ?></span>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
      <div class="bg-surface-container-lowest border border-outline-variant/60 rounded-3xl p-6 shadow-luxury-1">
        <div class="flex items-start gap-5">
          <?php
          $resImg = vehicleCardImage($reservation['image']);
          $resSrcset = vehicleImageSrcset($reservation['image'], BASE_URL);
          ?>
          <?php if ($resImg !== ''): ?>
          <div class="w-24 h-24 rounded-2xl bg-surface-container-low overflow-hidden shrink-0">
            <img src="<?= BASE_URL ?>/<?= htmlspecialchars($resImg) ?>"<?php if ($resSrcset !== ''): ?> srcset="<?= htmlspecialchars($resSrcset) ?>" sizes="96px"<?php endif; ?> alt="<?= htmlspecialchars($reservation['year'] . ' ' . $reservation['brand'] . ' ' . $reservation['model']) ?>" class="w-full h-full object-cover object-center" width="900" height="672" loading="lazy" decoding="async">
          </div>
          <?php else: ?>
          <div class="w-24 h-24 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center shrink-0">
            <i aria-hidden="true" class="bi bi-car-front text-[32px]"></i>
          </div>
          <?php endif; ?>
          <div class="flex-1 min-w-0">
            <h3 class="text-headline-sm font-headline-sm text-on-surface font-semibold"><?= htmlspecialchars($reservation['year'] . ' ' . $reservation['brand'] . ' ' . $reservation['model']) ?></h3>
            <p class="text-body-sm text-on-surface-variant mt-1">Daily Rate: <?= formatCurrency($reservation['daily_rate']) ?></p>
            <div class="flex items-center gap-2 mt-3 flex-wrap">
              <span class="inline-flex items-center gap-1.5 bg-surface-container text-on-surface font-label-sm text-label-sm px-2.5 py-1 rounded-full">
                <i aria-hidden="true" class="bi bi-people text-xs"></i> <?= (int)$reservation['seats'] ?> Seats
              </span>
              <span class="inline-flex items-center gap-1.5 bg-surface-container text-on-surface font-label-sm text-label-sm px-2.5 py-1 rounded-full">
                <i aria-hidden="true" class="bi bi-sliders text-xs"></i> <?= htmlspecialchars(ucfirst((string)$reservation['transmission'])) ?>
              </span>
              <span class="inline-flex items-center gap-1.5 bg-surface-container text-on-surface font-label-sm text-label-sm px-2.5 py-1 rounded-full">
                <i aria-hidden="true" class="bi bi-fuel-pump text-xs"></i> <?= htmlspecialchars(ucfirst((string)$reservation['fuel_type'])) ?>
              </span>
            </div>
          </div>
        </div>
</div>

      <div class="bg-surface-container-lowest border border-outline-variant/60 rounded-3xl p-6 shadow-luxury-1">
        <div class="flex items-center gap-2 mb-4">
          <div class="w-8 h-8 rounded-full bg-primary/10 text-primary inline-flex items-center justify-center">
            <i aria-hidden="true" class="bi bi-person-badge text-[18px]"></i>
          </div>
          <h3 class="text-headline-sm font-headline-sm text-on-surface">Driver Credentials</h3>
        </div>
        <div class="flex items-center justify-between gap-3">
          <div>
            <span class="text-label-sm text-tertiary uppercase tracking-wider font-semibold">Philippines Driver's License (LTO)</span>
            <?php if (!empty($reservation['license_no'])): ?>
            <p class="text-headline-sm font-headline-sm text-on-surface font-bold mt-1"><?= htmlspecialchars($reservation['license_no']) ?></p>
            <?php else: ?>
            <p class="text-body-md text-on-surface-variant mt-1">Not provided</p>
            <?php endif; ?>
          </div>
          <span class="inline-flex items-center gap-1.5 bg-secondary-fixed text-primary font-label-sm text-label-sm px-3 py-1 rounded-full font-bold">
            <i aria-hidden="true" class="bi bi-shield-check text-[14px]"></i> LTO Verified
          </span>
        </div>
      </div>

      <div class="bg-surface-container-lowest border border-outline-variant/60 rounded-3xl p-6 shadow-luxury-1">
        <div class="flex items-center gap-2 mb-5">
          <div class="w-8 h-8 rounded-full bg-primary/10 text-primary inline-flex items-center justify-center">
            <i aria-hidden="true" class="bi bi-clock text-[18px]"></i>
          </div>
          <h3 class="text-headline-sm font-headline-sm text-on-surface">Pickup &amp; Return</h3>
        </div>
        <div class="relative pl-6">
          <div class="absolute left-2 top-1 bottom-1 w-px bg-outline-variant/50"></div>
          <div class="relative mb-6 last:mb-0">
            <div class="absolute -left-[18px] top-0.5 w-3 h-3 rounded-full bg-primary border-2 border-surface-container-lowest"></div>
            <span class="text-label-sm font-bold text-primary uppercase tracking-wider">Pickup Date &amp; Time</span>
            <p class="text-on-surface font-semibold mt-0.5"><?= formatDate($reservation['pickup_date']) ?> &middot; <?= formatTime($reservation['pickup_time'] ?: '10:00') ?></p>
            <?php if (!empty($reservation['pickup_location'])): ?>
            <p class="text-body-sm text-on-surface-variant mt-0.5">
              <i aria-hidden="true" class="bi bi-geo-alt-fill text-[14px] text-primary align-middle"></i>
              <?= htmlspecialchars($reservation['pickup_location']) ?>
            </p>
            <?php endif; ?>
          </div>
          <div class="relative">
            <div class="absolute -left-[18px] top-0.5 w-3 h-3 rounded-full bg-secondary border-2 border-surface-container-lowest"></div>
            <span class="text-label-sm font-bold text-secondary uppercase tracking-wider">Return Date &amp; Time</span>
            <p class="text-on-surface font-semibold mt-0.5"><?= formatDate($reservation['return_date']) ?> &middot; <?= formatTime($reservation['return_time'] ?: '18:00') ?></p>
            <?php if (!empty($reservation['return_location'])): ?>
            <p class="text-body-sm text-on-surface-variant mt-0.5">
              <i aria-hidden="true" class="bi bi-geo-alt-fill text-[14px] text-secondary align-middle"></i>
              <?= htmlspecialchars($reservation['return_location']) ?>
            </p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="space-y-6">
      <div class="bg-surface-container-lowest border border-outline-variant/60 rounded-3xl p-6 shadow-luxury-1">
        <div class="flex items-center gap-2 mb-5">
          <div class="w-8 h-8 rounded-full bg-primary/10 text-primary inline-flex items-center justify-center">
            <i aria-hidden="true" class="bi bi-receipt text-[18px]"></i>
          </div>
          <h3 class="text-headline-sm font-headline-sm text-on-surface">Summary</h3>
        </div>
        <div class="space-y-3">
          <div class="flex justify-between items-center py-2">
            <span class="text-body-md text-on-surface-variant">Rate / Day</span>
            <span class="font-semibold text-on-surface"><?= formatCurrency($reservation['daily_rate']) ?></span>
          </div>
          <div class="flex justify-between items-center py-2">
            <span class="text-body-md text-on-surface-variant">Duration</span>
            <span class="font-semibold text-on-surface"><?= $days ?> days</span>
          </div>
          <div class="border-t border-outline-variant/40 my-1"></div>
          <div class="flex justify-between items-center py-2">
            <span class="text-body-md text-on-surface-variant">Subtotal</span>
            <span class="font-semibold text-on-surface"><?= formatCurrency($reservation['daily_rate'] * $days) ?></span>
          </div>
          <?php if (!empty($reservation['protection_fee']) && $reservation['protection_fee'] > 0): ?>
          <?php
          $protectionLabels = ['cwd' => 'Damage Waiver', 'fulltank' => 'Full Tank', 'roadside' => 'Roadside & Locker'];
          $protectionParts = [];
          foreach (explode(',', $reservation['protection'] ?? '') as $key) {
              if ($key !== '' && isset($protectionLabels[$key])) {
                  $protectionParts[] = $protectionLabels[$key];
              }
          }
          ?>
          <div class="flex justify-between items-center py-2">
            <span class="text-body-md text-on-surface-variant">
              Protection Package<?php if ($protectionParts): ?> (<?= htmlspecialchars(implode(', ', $protectionParts)) ?>)<?php endif; ?>
            </span>
            <span class="font-semibold text-on-surface"><?= formatCurrency($reservation['protection_fee']) ?></span>
          </div>
          <?php endif; ?>
          <div class="border-t border-outline-variant/40 my-1"></div>
          <div class="flex justify-between items-center pt-1">
            <span class="text-headline-sm font-headline-sm font-bold text-on-surface">Total</span>
            <span class="text-headline-sm font-headline-sm font-bold text-primary"><?= formatCurrency($reservation['total_amount']) ?></span>
          </div>
          <?php
          /* The GCash deposit the customer sent before booking. It is shown
             with the reference they quoted and where it stands, so the
             customer can see the hub is holding their money and what is
             still left to pay at the counter. */
          $paymentMethod = strtolower((string)($reservation['payment_method'] ?? ''));
          $paymentStatus = strtolower((string)($reservation['payment_status'] ?? ''));
          $paymentAmount = (float)($reservation['payment_amount'] ?? 0);
          $paymentRef    = (string)($reservation['payment_reference'] ?? '');
          $paymentPills  = [
              'verified' => ['Deposit verified', 'bg-emerald-50 text-emerald-800 border border-emerald-200', 'patch-check'],
              'rejected' => ['Deposit rejected', 'bg-error-container text-on-error-container border border-error-container', 'x-circle'],
              'pending'  => ['Deposit awaiting verification', 'bg-amber-50 text-amber-800 border border-amber-200', 'hourglass-split'],
          ];
          $paymentPill = $paymentPills[$paymentStatus] ?? $paymentPills['pending'];
          $hasDeposit  = $paymentMethod !== '' && $paymentAmount > 0;
          ?>
          <?php if ($hasDeposit): ?>
          <div class="border-t border-outline-variant/40 my-1"></div>
          <div class="flex justify-between items-center py-2">
            <span class="text-body-md text-on-surface-variant">GCash deposit sent</span>
            <span class="font-semibold text-on-surface"><?= formatCurrency($paymentAmount) ?></span>
          </div>
          <div class="flex justify-between items-center py-2">
            <span class="text-body-md text-on-surface-variant">GCash reference</span>
            <span class="font-mono font-semibold text-on-surface"><?= htmlspecialchars($paymentRef) ?></span>
          </div>
          <div class="flex justify-between items-center py-2">
            <span class="text-body-md text-on-surface-variant">Balance at pickup</span>
            <span class="font-semibold text-on-surface"><?= formatCurrency(max(0, (float)$reservation['total_amount'] - $paymentAmount)) ?></span>
          </div>
          <div class="flex justify-end pt-1">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full <?= $paymentPill[1] ?> text-label-sm font-bold">
              <i aria-hidden="true" class="bi bi-<?= $paymentPill[2] ?> text-[14px]"></i>
              <?= $paymentPill[0] ?>
            </span>
          </div>
          <?php endif; ?>
          <p class="mt-3 flex items-start gap-1.5 text-label-sm font-label-sm text-tertiary">
            <i aria-hidden="true" class="bi bi-cash-stack text-[14px] leading-none mt-px shrink-0"></i>
            <span><?= $hasDeposit
                ? 'The deposit was sent through GCash to ' . htmlspecialchars(CARVELLA_GCASH_NUMBER) . '. The remaining balance is paid in cash at the Dagupan Central Hub counter on pickup.'
                : 'Payment is made in cash at the Dagupan Central Hub counter on pickup.' ?></span>
          </p>
        </div>
      </div>

      <div class="bg-surface-container-lowest border border-outline-variant/60 rounded-3xl p-6 shadow-luxury-1">
        <div class="flex flex-col gap-3">
          <?php if ($reservation['status'] === 'pending'): ?>
          <form method="POST" action="<?= BASE_URL ?>/customer/reservation-details.php?id=<?= (int)$reservation['id'] ?>" class="contents"
                data-confirm="Cancel reservation <?= e($reservation['booking_reference']) ?>? This cannot be undone.">
            <?= csrf_field() ?>
            <input type="hidden" name="cancel_reservation" value="<?= (int)$reservation['id'] ?>">
            <button type="submit" class="cancel-res-btn px-6 py-3 rounded-full bg-error-container text-on-error-container border border-error-container font-label-lg font-bold text-center inline-flex items-center justify-center gap-2 hover:bg-error hover:text-on-error active:scale-95 transition-all cursor-pointer">
              <i class="bi bi-x-circle text-[18px]" aria-hidden="true"></i>
              Cancel Reservation
            </button>
          </form>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/customer/vehicles.php" class="px-6 py-3 rounded-full bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface font-label-lg font-bold text-center inline-flex items-center justify-center gap-2 transition-all">
            <i aria-hidden="true" class="bi bi-compass text-[18px]"></i>
            Browse Fleet
          </a>
        </div>
      </div>
    </div>
  </div>

</main>

<?php
/* No click handler on .cancel-res-btn on purpose — see the note in
   customer/reservations.php. The form above already carries csrf_field()
   and the owner-scoped, pending-only handler; assets/js/main.js supplies
   the confirmation and the double-submit lock. */
?>
<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of customer/reservation-details.php */
?>
