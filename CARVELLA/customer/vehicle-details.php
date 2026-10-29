<?php
/* customer/vehicle-details.php - Vehicle detail page with booking action. */
$pageTitle = 'Vehicle Details - Carvella';
require_once __DIR__ . '/../config/auth.php';

$vehicleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($vehicleId <= 0) {
    redirect(BASE_URL . '/customer/vehicles.php');
}

// Columns are listed explicitly rather than SELECT *. A future column
// (internal_notes, deleted_at, an acquisition cost) would otherwise become
// public the moment it is added to the table, with no diff to notice.
//
// There is deliberately no deposit column here. The vehicles table has never
// had one: a deposit is a per-reservation figure, held in
// reservations.protection_fee and reported from there in admin/reports.php.
// Selecting a deposit_amount that does not exist made prepare() return false,
// and the unchecked ->bind_param() on that false was a fatal that blanked the
// page for every visitor.
$stmt = $conn->prepare("SELECT id, brand, model, year, type, seats, transmission,
                               fuel_type, daily_rate, description, color, image,
                               status, created_at, license_plate
                       FROM vehicles
                       WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $vehicleId);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vehicle) {
    redirect(BASE_URL . '/customer/vehicles.php');
}

// Does the viewer have a booking on this vehicle? Resolved once here so the
// decision is not repeated per tile. isAdmin() is deliberately OR'd in: the
// admin fleet view already shows plates, so denying them here would only hide
// the fleet from the people who manage it.
$viewerOwnsVehicle = false;
$viewer = currentAccount();
if ($viewer !== null && ($viewer['status'] ?? 'active') === 'active') {
    $viewerId = (int)$viewer['id'];
    if (($viewer['role'] ?? '') === 'admin') {
        $viewerOwnsVehicle = true;
    } else {
        $ownStmt = $conn->prepare(
            "SELECT 1 FROM vehicles v
              WHERE v.id = ?
                AND ( EXISTS (SELECT 1 FROM reservations r
                               WHERE r.vehicle_id = v.id AND r.user_id = ?)
                   OR EXISTS (SELECT 1 FROM rentals t
                               WHERE t.vehicle_id = v.id AND t.user_id = ?) )
              LIMIT 1"
        );
        if ($ownStmt) {
            $ownStmt->bind_param("iii", $vehicleId, $viewerId, $viewerId);
            $ownStmt->execute();
            $viewerOwnsVehicle = (bool)$ownStmt->get_result()->fetch_row();
            $ownStmt->close();
        }
    }
}

/* A unit the operator has parked ('unavailable') or retired ('archived') must
 * not be reachable from the public site. It is already filtered out of
 * customer/vehicles.php, index.php, the reservation picker and the booking
 * helper, so this closes the last door: a stale bookmark or a shared link to
 * ?id=<parked unit>. Anyone who already has a booking on it (or an admin
 * previewing the fleet) keeps access to the page they legitimately came for. */
$visibilityStatus = strtolower((string)($vehicle['status'] ?? ''));
if (in_array($visibilityStatus, ['unavailable', 'archived'], true) && !$viewerOwnsVehicle) {
    setFlash('error', 'That vehicle is not currently listed in the catalog.');
    redirect(BASE_URL . '/customer/vehicles.php');
}

$rateList = [];
$ratesResult = $conn->query("SELECT daily_rate FROM vehicles");
if ($ratesResult) {
    while ($rr = $ratesResult->fetch_assoc()) $rateList[] = (float)$rr['daily_rate'];
    $ratesResult->close();
}
$rateMin = count($rateList) > 0 ? floor(min($rateList) / 500) * 500 : 3000;
$rateMax = count($rateList) > 0 ? ceil(max($rateList) / 500) * 500 : 9000;

$statusRaw = strtolower($vehicle['status'] ?? 'available');
/* 'rented' covers two situations - out on the road right now, or reserved for
   an approved booking not yet collected. The pill and the banner say which. */
$vehicleOnRoad = ($statusRaw === 'rented') ? vehicleIsOnRoad($conn, $vehicleId) : false;
$statusLabel = $statusRaw === 'rented' ? ($vehicleOnRoad ? 'Rented' : 'Reserved') : ucfirst($statusRaw);
$statusPill = match($statusRaw) {
    'available', 'completed' => 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 text-label-sm font-bold',
    'pending' => 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-50 text-amber-800 border border-amber-200 text-label-sm font-bold',
    'confirmed' => 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary-fixed text-on-primary-fixed-variant border border-primary-fixed-dim text-label-sm font-bold',
    'active', 'in_progress' => 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-secondary-fixed text-on-secondary-fixed border border-secondary-fixed-dim text-label-sm font-bold',
    'cancelled', 'maintenance' => 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-error-container text-on-error-container border border-error-container text-label-sm font-bold',
    'unavailable', 'inactive', 'archived' => 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-container-high text-on-surface-variant border border-outline-variant text-label-sm font-bold',
    default => 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 text-label-sm font-bold',
};

$isBookable = ($statusRaw === 'available');
$statusDotClass = match($statusRaw) {
    'available' => 'bg-emerald-500',
    'rented' => 'bg-primary',
    'maintenance' => 'bg-amber-500',
    'unavailable' => 'bg-rose-500',
    'archived' => 'bg-tertiary',
    default => 'bg-emerald-500',
};
$statusMessage = match($statusRaw) {
    'available' => 'Ready for immediate booking',
    'rented' => 'Currently rented by another guest — reserve now for upcoming dates',
    'maintenance' => 'Under maintenance — check back soon',
    'unavailable' => 'Currently unavailable — check back soon',
    'archived' => 'Retired from the fleet — no longer offered for booking',
    default => 'Ready for immediate booking',
};
// A unit held by an approved booking reads differently from one on the road.
if ($statusRaw === 'rented') {
    $statusMessage = $vehicleOnRoad
        ? 'Currently out on a rental — reserve it for upcoming dates'
        : 'Reserved for an approved booking — reserve it for other dates';
}
$statusIcon = $isBookable ? 'check-circle-fill' : 'clock-fill';
$statusIconClass = $isBookable ? 'text-emerald-600' : 'text-amber-600';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20 px-4 sm:px-6 md:px-12 max-w-container-max-width mx-auto w-full">

    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-body-sm font-body-sm text-tertiary mb-5">
        <a href="<?= BASE_URL ?>/index.php" class="hover:text-primary transition-colors flex items-center gap-1">
            <i aria-hidden="true" class="bi bi-house text-[16px]"></i> Home
        </a>
        <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
        <a href="<?= BASE_URL ?>/customer/vehicles.php" class="hover:text-primary transition-colors">Fleet Catalog</a>
        <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
        <span class="text-on-surface font-bold truncate"><?= htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']) ?></span>
    </nav>

    <div class="flex flex-col lg:flex-row gap-8">

        <!-- LEFT COLUMN -->
        <div class="flex-1 space-y-8 min-w-0">

            <!-- Vehicle Showcase -->
            <div class="relative rounded-3xl border border-outline-variant/60 bg-surface-container-lowest overflow-hidden">
                <div class="relative bg-surface-container-lowest h-[380px] md:h-[460px] flex items-center justify-center overflow-hidden">
                    <?php
                    $heroImg = vehicleLargeImage($vehicle['image']);
                    $heroSrcset = vehicleImageSrcset($vehicle['image'], BASE_URL);
                    // A 7-column hero in a 92rem container: ~800px on desktop,
                    // full-bleed on a phone. Declared so a 1x phone takes the
                    // 900px candidate rather than the 1600px one.
                    $heroSizes = '(min-width: 1280px) 800px, (min-width: 1024px) 58vw, 92vw';
                    ?>
                    <?php if ($heroImg !== ''): ?>
                    <img class="relative z-10 w-full h-full object-cover object-center" src="<?= BASE_URL ?>/<?= htmlspecialchars($heroImg) ?>"<?php if ($heroSrcset !== ''): ?> srcset="<?= htmlspecialchars($heroSrcset) ?>" sizes="<?= htmlspecialchars($heroSizes) ?>"<?php endif; ?> alt="<?= htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']) ?>" width="1600" height="1195" fetchpriority="high" decoding="async">
                    <?php endif; ?>

                    <!-- Floating Badges -->
                    <div class="absolute top-5 left-5 flex flex-col gap-2 items-start">
                        <span class="<?= $statusPill ?> shadow-sm backdrop-blur">
                            <span class="w-1.5 h-1.5 rounded-full <?= $statusDotClass ?> <?= $isBookable ? 'animate-pulse' : '' ?>"></span>
                            <?= htmlspecialchars($statusLabel) ?>
                        </span>
                        <span class="px-3 py-1 rounded-full bg-on-surface/70 backdrop-blur-md text-surface-bright text-label-sm font-label-sm shadow-sm">
                            <?= htmlspecialchars(ucfirst($vehicle['type'])) ?> &bull; <?= (int)$vehicle['year'] ?> Release
                        </span>
                    </div>
                    <span class="absolute bottom-5 right-5 px-3.5 py-1.5 rounded-full bg-surface-container-lowest/90 backdrop-blur-md border border-outline-variant/50 text-label-sm font-label-sm text-primary font-bold shadow-sm">
                        <?= htmlspecialchars(ucfirst($vehicle['type'])) ?>
                    </span>
                </div>

                <!-- Bottom Info Bar -->
                <div class="px-6 md:px-8 py-5 flex flex-wrap items-center justify-between gap-4 border-t border-outline-variant/30">
                    <div>
                        <p class="text-label-sm text-label-sm text-tertiary uppercase tracking-wider font-semibold mb-0.5"><?= htmlspecialchars(ucfirst($vehicle['type'])) ?> Executive Class</p>
                        <h1 class="text-headline-xl font-headline-xl text-on-surface tracking-tight">
                            <?= htmlspecialchars($vehicle['year'] . ' ' . $vehicle['brand'] . ' ' . $vehicle['model']) ?>
                        </h1>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-label-sm font-label-sm text-tertiary flex items-center gap-1.5">
                            <i aria-hidden="true" class="bi bi-geo-alt text-[16px] text-primary"></i>
                            Dagupan Hub
                        </span>
                        <span class="text-label-sm font-label-sm text-tertiary flex items-center gap-1.5">
                            <i aria-hidden="true" class="bi bi-patch-check text-[16px] text-primary"></i>
                            LTO Compliant
                        </span>
                    </div>
                </div>
            </div>

            <!-- About This Vehicle -->
            <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/60 p-6 md:p-8">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-11 h-11 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center">
                        <i aria-hidden="true" class="bi bi-car-front text-[22px]"></i>
                    </div>
                    <div>
                        <h2 class="text-headline-md font-headline-md text-on-surface">About This Vehicle</h2>
                        <p class="text-label-sm font-label-sm text-tertiary">Specifications &amp; vehicle overview</p>
                    </div>
                </div>
                <p class="text-body-lg font-body-lg text-on-surface-variant leading-relaxed"><?= nl2br(htmlspecialchars($vehicle['description'] ?? 'A premium executive vehicle in the CARVELLA Dagupan fleet, ready for your next journey.')) ?></p>
            </div>

        </div>

        <!-- RIGHT COLUMN: Booking Card -->
        <div class="w-full lg:w-[400px] flex-shrink-0 space-y-5 lg:sticky lg:top-24 self-start">

            <div class="bg-surface-container-lowest rounded-3xl border border-outline-variant/60 shadow-luxury-2 overflow-hidden">
                <!-- Card Header Strip -->
                <div class="bg-primary px-6 py-4 flex items-center justify-between">
                    <span class="text-label-md font-label-md text-on-primary font-bold flex items-center gap-2">
                        <i aria-hidden="true" class="bi bi-stars text-[18px]"></i>
                        Booking Summary
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white text-on-primary-fixed-variant text-label-sm font-bold shadow-sm">
                            <span class="w-1.5 h-1.5 rounded-full <?= $statusDotClass ?> <?= $isBookable ? 'animate-pulse' : '' ?>"></span>
                            <?= htmlspecialchars($statusLabel) ?>
                        </span>
                </div>

                <div class="p-6">
                    <!-- Vehicle Identity -->
                    <?php
                    /* Real photo rather than a generic car glyph: the 64px avatar
                     * uses the 480px thumb, the smallest derivative that exists,
                     * and falls back to the icon when a vehicle has no upload. */
                    $detailThumb = vehicleThumbImage($vehicle['image']);
                    $detailSizes = '(min-width: 1024px) 64px, 18vw';
                    $detailName = $vehicle['year'] . ' ' . $vehicle['brand'] . ' ' . $vehicle['model'];
                    ?>
                    <div class="flex items-center gap-3.5 mb-5">
                        <?php if ($detailThumb !== ''): ?>
                        <img class="w-16 h-16 rounded-2xl object-cover object-center ring-1 ring-outline-variant shrink-0" src="<?= BASE_URL ?>/<?= htmlspecialchars($detailThumb) ?>" sizes="<?= htmlspecialchars($detailSizes) ?>" alt="<?= htmlspecialchars($detailName) ?>" width="900" height="672" loading="lazy" decoding="async">
                        <?php else: ?>
                        <div class="w-16 h-16 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center shrink-0">
                            <i class="bi bi-car-front-fill text-[30px] leading-none" aria-hidden="true"></i>
                        </div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <p class="text-label-sm font-label-sm text-tertiary uppercase tracking-wider font-semibold">Selected Fleet Item</p>
                            <h3 class="text-headline-sm font-headline-sm text-on-surface font-bold leading-snug truncate"><?= htmlspecialchars($detailName) ?></h3>
                            <span class="inline-flex items-center mt-1.5 px-2.5 py-0.5 rounded-full bg-primary-fixed text-primary text-label-sm font-label-sm font-semibold"><?= htmlspecialchars(ucfirst($vehicle['type'])) ?></span>
                        </div>
                    </div>

                    <!-- Daily Rate Meter -->
                    <?php /* Reduced to just the figure at the customer's request:
                           the fleet-range bar and its min/max captions were
                           removed as noise on a page whose job is to sell this
                           one car. */ ?>
                    <div class="flex items-center justify-between gap-3 rounded-2xl bg-surface-container-low border border-outline-variant/40 px-4 py-3.5 mb-4">
                        <span class="text-label-sm font-label-sm text-on-surface-variant flex items-center gap-1.5">
                            <i class="bi bi-cash-stack text-sm text-primary" aria-hidden="true"></i>
                            Daily Rate
                        </span>
                        <span class="text-headline-md font-headline-md text-primary font-bold"><?= formatCurrency($vehicle['daily_rate']) ?>/d</span>
                    </div>

                    <!-- Spec Grid -->
                    <?php
                    // Plate and odometer are withheld from anyone who has not
                    // booked the vehicle. This page is the public fleet page -
                    // index.php links "Details" here - so it cannot simply be
                    // gated behind requireLogin() without breaking the browse
                    // path. It used to SELECT * and render both to anyone, and
                    // the fix that followed gated them on isLoggedIn(), which
                    // is still a disclosure: any account could read any plate in
                    // the fleet by walking ids, and a full plate plus odometer
                    // is enough to target a specific car. Scope it to a
                    // reservation or rental on this vehicle instead.
                    $showOwnershipDetail = $viewerOwnsVehicle;
                    $specTiles = [
                        ['icon' => 'people',            'label' => 'Seats',        'value' => (string)(int)$vehicle['seats'],                          'mono' => false],
                        ['icon' => 'gear',          'label' => 'Transmission', 'value' => htmlspecialchars(ucfirst($vehicle['transmission'])),      'mono' => false],
                        ['icon' => 'fuel-pump', 'label' => 'Fuel / Engine', 'value' => htmlspecialchars(ucfirst($vehicle['fuel_type'])),        'mono' => false],
                        ['icon' => 'palette',           'label' => 'Color',        'value' => htmlspecialchars((string)($vehicle['color'] ?? '')) ?: '—', 'mono' => false],
                    ];
                    if ($showOwnershipDetail) {
                        $specTiles[] = ['icon' => 'credit-card', 'label' => 'Plate / License', 'value' => htmlspecialchars((string)$vehicle['license_plate']), 'mono' => true];
                    }
                    ?>
                    <div class="grid grid-cols-2 gap-2 mb-5">
                    <?php foreach ($specTiles as $tile): ?>
                        <div class="flex items-center gap-2.5 min-w-0 px-3 py-2.5 rounded-xl bg-surface-container-low border border-outline-variant/40">
                            <span class="w-8 h-8 rounded-lg bg-primary/10 text-primary inline-flex items-center justify-center shrink-0">
                                <i class="bi bi-<?= htmlspecialchars($tile['icon']) ?> text-[17px] leading-none" aria-hidden="true"></i>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-[10px] leading-[12px] uppercase tracking-wider font-semibold text-tertiary truncate"><?= htmlspecialchars($tile['label']) ?></span>
                                <span class="block text-body-sm font-body-sm font-bold text-on-surface truncate<?= $tile['mono'] ? ' font-mono tracking-wider' : '' ?>"><?= $tile['value'] ?></span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <?php if (!$showOwnershipDetail): ?>
                    <div class="flex items-center gap-2.5 px-3.5 py-3 rounded-xl border border-dashed border-outline-variant mb-5">
                        <span class="w-8 h-8 rounded-lg bg-surface-container-high text-on-surface-variant inline-flex items-center justify-center shrink-0">
                            <i class="bi bi-lock text-[17px] leading-none" aria-hidden="true"></i>
                        </span>
                        <span class="text-body-sm font-body-sm text-tertiary">The plate is shown for a vehicle you have booked.</span>
                    </div>
                    <?php endif; ?>

                    <!-- Availability Banner -->
                    <div class="rounded-2xl bg-surface-container-low border border-outline-variant/40 p-4 flex items-center gap-3 mb-5">
                        <i aria-hidden="true" class="bi bi-<?= $statusIcon ?> text-[22px] shrink-0 <?= $statusIconClass ?>"></i>
                        <span class="font-body-sm font-body-sm text-on-surface-variant"><?= $statusMessage ?></span>
                    </div>

                    <!-- CTA -->
                    <?php if ($isBookable): ?>
                    <a href="<?= BASE_URL ?>/customer/reservation.php?vehicle_id=<?= $vehicle['id'] ?>" class="w-full py-3.5 px-6 rounded-2xl bg-primary text-on-primary font-label-lg font-label-lg font-bold shadow-sm hover:bg-secondary active:scale-[0.98] transition-all duration-200 inline-flex items-center justify-center focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                        Rent This Vehicle
                    </a>
                    <?php elseif ($statusRaw === 'rented'): ?>
                    <a href="<?= BASE_URL ?>/customer/reservation.php?vehicle_id=<?= $vehicle['id'] ?>" class="w-full py-3.5 px-6 rounded-2xl bg-primary text-on-primary font-label-lg font-label-lg font-bold shadow-sm hover:bg-secondary active:scale-[0.98] transition-all duration-200 inline-flex items-center justify-center focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                        Reserve This Vehicle
                    </a>
                    <?php else: ?>
                    <button type="button" disabled class="w-full py-3.5 px-6 rounded-2xl bg-surface-container-high text-on-surface-variant font-label-lg font-label-lg font-bold cursor-not-allowed inline-flex items-center justify-center select-none">
                        Not Available
                    </button>
                    <?php endif; ?>
                    <p class="text-center text-[11px] text-tertiary mt-3 flex items-center justify-center gap-1">
                        <i aria-hidden="true" class="bi bi-lock text-[13px]"></i>
                        Zero hidden fees &bull; Pay OTC at counter on pickup
                    </p>
                </div>
            </div>

        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of customer/vehicle-details.php */
?>
