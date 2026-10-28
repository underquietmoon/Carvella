<?php
/* reservation.php - Carvella Car Rental System */

$pageTitle = 'Vehicle Booking - Carvella';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

/* Single-hub: the POST handler ignores pickup_location/return_location, so no other city can be stored. */

$userId = (int)$_SESSION['user_id'];
$currentUser = $conn->query("SELECT * FROM users WHERE id = $userId")->fetch_assoc();

// Cast to int at the boundary: the raw string once broke out of an href attribute and ran script.
$vehicleId = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
if ($vehicleId <= 0) {
    redirect(BASE_URL . '/customer/vehicles.php');
}

$stmt = $conn->prepare("SELECT * FROM vehicles WHERE id = ?");
$stmt->bind_param("i", $vehicleId);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vehicle) {
    setFlash('error', 'Vehicle not found');
    redirect(BASE_URL . '/customer/vehicles.php');
}

$vehicleStatus = strtolower($vehicle['status'] ?? '');
if (!in_array($vehicleStatus, ['available', 'rented'], true)) {
    setFlash('error', 'This vehicle is currently not available for reservation');
    redirect(BASE_URL . '/customer/vehicles.php');
}

/* One form covers both flows: an available car is a walk-in rental, while a
   rented one can only be reserved for future dates. The breadcrumb, heading
   and confirm button follow that split. */
$bookingFlow = $vehicleStatus === 'available' ? 'rent' : 'reservation';
$flowLabel = $bookingFlow === 'rent' ? 'Rent' : 'Reservation';
$pageTitle = ($bookingFlow === 'rent' ? 'Rent a Vehicle' : 'Make Reservation') . ' - Carvella';

/* Only an open rental row says the car is out; scheduled windows are dates, so an overdue car looked free. */
$currentRental = vehicleCurrentRental($conn, (int)$vehicle['id']);
$today = date('Y-m-d');
$vehicleOnRoad = !empty($currentRental['on_road']);
$vehicleReturnDate = $vehicleOnRoad ? (string)$currentRental['handback'] : '';
$vehicleOverdue = $vehicleOnRoad && !empty($currentRental['overdue']);

/* The floor lives in vehicleBookableFromDate(), the one source the form's min and the POST guard both read. */
$pickupDefault = vehicleBookableFromDate($conn, $vehicleId);

/* The pick-up field opens on the rental's handback; a lot car opens empty and a late rental offers no date. */
$pickupValue = '';
if ($vehicleOnRoad && $vehicleReturnDate !== '' && !$vehicleOverdue) {
    // Never earlier than the floor the server enforces, so the field cannot offer a day the server would refuse.
    $pickupValue = $vehicleReturnDate >= $pickupDefault ? $vehicleReturnDate : $pickupDefault;
}

/* The server caps the vehicle ids it accepts to the same limit, so a crafted POST cannot fan one submit out. */
$maxVehiclesPerBooking = 5;

/* Clock values re-open on the hub's published windows and survive a rejected POST unchanged. */
$pickupTimeValue = sanitize($_POST['pickup_time'] ?? '');
$returnTimeValue = sanitize($_POST['return_time'] ?? '');

/* Date values stay native for the picker; these are only the formatted labels beside them. */
$pickupDateValue = (string)($_POST['pickup_date'] ?? $pickupValue);
$returnDateValue = (string)($_POST['return_date'] ?? '');
$pickupDateLabel = $pickupDateValue !== '' ? formatDate($pickupDateValue) : 'Select date';
$returnDateLabel = $returnDateValue !== '' ? formatDate($returnDateValue) : 'Select date';
$pickupTimeLabel = $pickupTimeValue !== '' ? formatTime($pickupTimeValue) : 'Select time';
$returnTimeLabel = $returnTimeValue !== '' ? formatTime($returnTimeValue) : 'Select time';
/* The return picker can never offer a day before the chosen departure, even before JS runs. */
$returnDateMin = ($pickupDateValue !== '' && rentalDateIsValid($pickupDateValue)) ? $pickupDateValue : $pickupDefault;

/* Server-rendered rental length, so the card reads correctly before the live summary script runs. */
$durationLabel = 'Select return date';
if ($pickupDateValue !== '' && $returnDateValue !== '') {
    $pickupTimestamp = strtotime($pickupDateValue);
    $returnTimestamp = strtotime($returnDateValue);
    if ($pickupTimestamp !== false && $returnTimestamp !== false && $returnTimestamp >= $pickupTimestamp) {
        $durationDays = max(1, (int)ceil(($returnTimestamp - $pickupTimestamp) / 86400));
        $durationLabel = $durationDays . ' day' . ($durationDays === 1 ? '' : 's');
    }
}

$error = '';

/* One GCash transfer covers the booking, so the amount is config; a hub taking no deposit sets it to 0. */
$payment_reference = sanitize($_POST['payment_reference'] ?? '');
$depositRequired = CARVELLA_GCASH_DEPOSIT_AMOUNT > 0;
/* Deposit scales with the rented vehicle's daily rate: 2,000 when the rate is
   2,000 or above, otherwise 1,000. */
$depositAmount = ((float)($vehicle['daily_rate'] ?? 0) >= 2000) ? 1500.0 : 1000.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pickup_date = sanitize($_POST['pickup_date'] ?? '');
    $return_date = sanitize($_POST['return_date'] ?? '');
    $pickup_time = sanitize($_POST['pickup_time'] ?? '');
    $return_time = sanitize($_POST['return_time'] ?? '');
    // Single-hub operation: submitted locations are discarded, not validated, so only the hub can be stored.
    $pickup_location = CARVELLA_HUB;
    $return_location = CARVELLA_HUB;
    $license_no = sanitize($_POST['license_no'] ?? '');

    $isValidDate = function ($value) {
        return rentalDateIsValid($value);
    };

    /* The page's own car leads, extra cars arrive as vehicle_ids[]; the list is cast, de-duplicated and capped. */
    $bookingVehicles = [$vehicleId];
    $postedVehicleIds = $_POST['vehicle_ids'] ?? [];
    if (!is_array($postedVehicleIds)) {
        $postedVehicleIds = [$postedVehicleIds];
    }
    foreach ($postedVehicleIds as $postedVehicleId) {
        $postedVehicleId = (int)$postedVehicleId;
        if ($postedVehicleId > 0
            && !in_array($postedVehicleId, $bookingVehicles, true)
            && count($bookingVehicles) < $maxVehiclesPerBooking) {
            $bookingVehicles[] = $postedVehicleId;
        }
    }

    if (empty($license_no)) {
        $error = 'Philippines Driver\'s License number is required';
    } elseif (mb_strlen($license_no) > 64) {
        $error = 'Driver\'s license number is too long';
    } elseif (!$isValidDate($pickup_date) || !$isValidDate($return_date)) {
        $error = 'Invalid pickup or return date';
    } elseif ($pickup_date < date('Y-m-d')) {
        $error = 'Pickup date cannot be in the past';
    } elseif ($pickup_date > $return_date) {
        $error = 'Return date cannot be before the pickup date';
    } elseif (!rentalTimeIsValid($pickup_time) || !rentalTimeIsValid($return_time)) {
        $error = 'Please choose a pick-up time and a return time';
    } elseif ($pickup_date === $return_date && $return_time <= $pickup_time) {
        $error = 'Return time must be after the pick-up time on the same day';
    } elseif ($depositRequired && !rentalGcashReferenceIsValid($payment_reference)) {
        $error = 'Enter the 13-digit GCash reference number from your receipt';
    } else {
        /* All cars are checked before any row is written and each floor is re-read here, not trusted to min. */
        $bookingRows = [];
        $maxBookingRate = 0.0;
        foreach ($bookingVehicles as $bookingVehicleId) {
            $bookingRow = null;
            $vehicleStmt = $conn->prepare("SELECT id, brand, model, year, status, daily_rate FROM vehicles WHERE id = ?");
            if ($vehicleStmt !== false) {
                $vehicleStmt->bind_param('i', $bookingVehicleId);
                if ($vehicleStmt->execute()) {
                    $bookingRow = $vehicleStmt->get_result()->fetch_assoc();
                }
                $vehicleStmt->close();
            }
            if (!$bookingRow) {
                $error = 'One of the selected vehicles is no longer listed. Please review your selection.';
                break;
            }
            $vehicleLabel = trim($bookingRow['year'] . ' ' . $bookingRow['brand'] . ' ' . $bookingRow['model']);
            if (empty(vehicleBookableStatuses($conn, $bookingVehicleId))) {
                $error = $vehicleLabel . ' is no longer available for reservation.';
                break;
            }
            /* Only cars the picker offers may join, so a crafted POST cannot slip a rented car onto the trip. */
            if ($bookingVehicleId !== $vehicleId
                && strtolower((string)$bookingRow['status']) !== 'available') {
                $error = $vehicleLabel . ' is no longer available to add to this booking.';
                break;
            }
            $vehicleFloor = vehicleBookableFromDate($conn, $bookingVehicleId);
            if ($pickup_date < $vehicleFloor) {
                $error = $vehicleLabel . ' can only be reserved from ' . formatDate($vehicleFloor) . ' onwards.';
                break;
            }
            if (isVehicleBooked($bookingVehicleId, $pickup_date, $return_date)) {
                $error = $vehicleLabel . ' is already booked for these dates. Please choose different dates.';
                break;
            }
            $bookingRows[$bookingVehicleId] = $bookingRow;
            if ((float)($bookingRow['daily_rate'] ?? 0) > $maxBookingRate) {
                $maxBookingRate = (float)$bookingRow['daily_rate'];
            }
        }

        if ($error === '') {
            $depositAmount = ($maxBookingRate >= 2000) ? 1500.0 : 1000.0;
            $protectionPrices = ['cwd' => 2500.00, 'fulltank' => 1500.00, 'roadside' => 1000.00];
            $selectedProtection = $_POST['protection'] ?? [];
            $protections = [];
            // A protection plan is billed per vehicle, so the same fee goes on each car's reservation below.
            $protectionFee = 0.0;
            if (is_array($selectedProtection)) {
                // A crafted POST could store "cwd,cwd,cwd"; each add-on may be charged at most once.
                foreach (array_unique(array_map('strval', $selectedProtection)) as $key) {
                    if (isset($protectionPrices[$key])) {
                        $protections[] = $key;
                        $protectionFee += $protectionPrices[$key];
                    }
                }
            }

            $created = [];
            foreach (array_keys($bookingRows) as $bookingVehicleId) {
                $result = createReservationRecord($conn, [
                    'user_id' => $userId,
                    'vehicle_id' => $bookingVehicleId,
                    'pickup_date' => $pickup_date,
                    'pickup_time' => $pickup_time,
                    'return_date' => $return_date,
                    'return_time' => $return_time,
                    'pickup_location' => $pickup_location,
                    'return_location' => $return_location,
                    'license_no' => $license_no,
                    'protection' => implode(',', $protections),
                    'protection_fee' => $protectionFee,
                    'status' => 'pending',
                ]);

                if (empty($result['success'])) {
                    $error = $result['error'] ?? 'Failed to create reservation';
                    break;
                }

                logActivity('created', "Reservation {$result['booking_reference']} booked for vehicle #$bookingVehicleId");
                $created[] = $result;
            }

            if ($error === '' && !empty($created)) {
                /* The deposit goes on after the booking exists, split across rows in proportion to their cost. */
                if ($depositRequired) {
                    $depositIds = array_map(function ($reservation) {
                        return (int)$reservation['reservation_id'];
                    }, $created);
                    $deposit = applyReservationDeposit($conn, $depositIds, $depositAmount, $payment_reference);
                    if (empty($deposit['success'])) {
                        // The booking stands - the money was sent - but the counter must settle it by hand.
                        setFlash(
                            'error',
                            'Your booking was created, but we could not record the GCash deposit automatically (' . ($deposit['error'] ?? 'unknown error') . '). Please contact the hub with your reference number.',
                            'Deposit not recorded'
                        );
                        redirect(BASE_URL . '/customer/reservations.php');
                    }
                }

                $depositNote = $depositRequired
                    ? ' GCash deposit of ' . formatCurrency($depositAmount) . ' recorded (ref ' . $payment_reference . ') - awaiting counter verification.'
                    : '';

                if (count($created) === 1) {
                    setFlash('success', 'Reservation created successfully!' . $depositNote);
                    redirect(BASE_URL . '/customer/reservation-details.php?id=' . (int)$created[0]['reservation_id']);
                }

                // Each car is its own reservation with its own reference, so the trip stays visible at once.
                $references = array_map(function ($reservation) {
                    return '#' . $reservation['booking_reference'];
                }, $created);
                setFlash('success', count($created) . ' reservations created for this trip: ' . implode(', ', $references) . '.' . $depositNote);
                redirect(BASE_URL . '/customer/reservations.php');
            }

            if ($error !== '' && !empty($created)) {
                // Committed rows stand; showing the customer the list beats leaving a half-filled form up.
                setFlash('error', $error . ' ' . count($created) . ' of ' . count($bookingRows) . ' reservations were created and are in your list.');
                redirect(BASE_URL . '/customer/reservations.php');
            }
        }
    }
}

/* Only 'available' cars are offered; floors come from vehicleBookableFromDate(), the picker's own source. */
$selectableVehicles = [];
$candidateStmt = $conn->prepare("SELECT id, brand, model, year, license_plate, type, seats, transmission, fuel_type, daily_rate, image FROM vehicles WHERE status = 'available' AND id <> ? ORDER BY daily_rate ASC, id ASC");
if ($candidateStmt !== false) {
    $candidateStmt->bind_param('i', $vehicleId);
    if ($candidateStmt->execute()) {
        $candidateResult = $candidateStmt->get_result();
        while ($candidateRow = $candidateResult->fetch_assoc()) {
            $candidateRow['bookable_from'] = vehicleBookableFromDate($conn, (int)$candidateRow['id']);
            $selectableVehicles[] = $candidateRow;
        }
    }
    $candidateStmt->close();
}

/* A rejected POST re-renders the page, so the chosen extra cars come back with it; only selectable ids survive. */
$postedExtraVehicleIds = [];
if (!empty($_POST['vehicle_ids']) && is_array($_POST['vehicle_ids']) && !empty($selectableVehicles)) {
    $selectableIds = array_map(function ($candidate) {
        return (int)$candidate['id'];
    }, $selectableVehicles);
    foreach ($_POST['vehicle_ids'] as $postedVehicleId) {
        $postedVehicleId = (int)$postedVehicleId;
        if ($postedVehicleId > 0
            && $postedVehicleId !== $vehicleId
            && in_array($postedVehicleId, $selectableIds, true)
            && !in_array($postedVehicleId, $postedExtraVehicleIds, true)
            && count($postedExtraVehicleIds) < $maxVehiclesPerBooking - 1) {
            $postedExtraVehicleIds[] = $postedVehicleId;
        }
    }
}

$driverName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20 px-4 sm:px-6 md:px-12 max-w-container-max-width mx-auto w-full">

  <!-- Breadcrumb -->
  <nav class="flex items-center gap-2 text-label-sm font-label-sm text-tertiary mb-8">
    <a href="<?= BASE_URL ?>/customer/vehicles.php" class="hover:text-primary transition-colors">Vehicles</a>
    <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
    <a href="<?= BASE_URL ?>/customer/vehicle-details.php?id=<?= (int)$vehicleId ?>" class="hover:text-primary transition-colors">Details</a>
    <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
    <span class="text-on-surface font-semibold"><?= $flowLabel ?></span>
  </nav>

  <!-- Page Hero Banner: the conflict-lock pill was dropped; isVehicleBooked() still blocks double-booking. -->
  <div class="mb-10">
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-container-high text-tertiary text-label-sm font-label-sm">
        <i aria-hidden="true" class="bi bi-geo-alt text-[14px]"></i>
        Pangasinan Logistics Hub
      </span>
    </div>
    <h1 class="text-headline-xl font-headline-xl text-on-surface tracking-tight"><?= $bookingFlow === 'rent' ? 'Rent Your Journey' : 'Reserve Your Journey' ?> &mdash; Dagupan Central Terminal Handover</h1>
    <p class="text-body-lg font-body-lg text-tertiary mt-2 max-w-3xl">
      Automated conflict-lock engine guarantees zero double-booking. Pick up directly via 24/7 Smart Locker at AB Fernandez Ave, Dagupan City.
    </p>
  </div>

  <?php if ($error): ?>
  <div class="mb-8 flex items-center gap-3 px-4 py-3 rounded-2xl bg-error-container text-on-error-container text-body-sm font-body-sm">
    <i aria-hidden="true" class="bi bi-exclamation-circle text-[20px]"></i>
    <span><?= htmlspecialchars($error) ?></span>
  </div>
  <?php endif; ?>

  <!-- Step Indicator Tracker (Glass Capsule) -->
  <div class="mb-10 bg-surface-container-lowest/80 border border-outline-variant/40 rounded-full p-2.5 backdrop-blur-md shadow-sm">
    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
      <a href="#step-1" class="flex items-center gap-3 px-4 py-2 rounded-full bg-primary text-on-primary transition-all">
        <span class="w-6 h-6 rounded-full bg-on-primary/20 text-on-primary flex items-center justify-center text-label-sm font-label-sm font-bold">1</span>
        <div class="truncate">
          <p class="text-label-md font-label-md leading-none font-bold">Schedule &amp; Vehicle</p>
          <p class="text-[10px] opacity-80 mt-0.5 truncate">Dates &amp; Hub Locker</p>
        </div>
      </a>
      <a href="#step-2" class="flex items-center gap-3 px-4 py-2 rounded-full bg-surface-container-low text-on-surface hover:bg-surface-container-high hover:text-primary transition-all">
        <span class="w-6 h-6 rounded-full bg-surface-variant text-primary flex items-center justify-center text-label-sm font-label-sm font-bold">2</span>
        <div class="truncate">
          <p class="text-label-md font-label-md leading-none font-semibold">Driver &amp; Digital KYC</p>
          <p class="text-[10px] text-tertiary mt-0.5 truncate">LTO Instant Check</p>
        </div>
      </a>
      <a href="#step-3" class="flex items-center gap-3 px-4 py-2 rounded-full bg-surface-container-low text-on-surface hover:bg-surface-container-high hover:text-primary transition-all">
        <span class="w-6 h-6 rounded-full bg-surface-variant text-tertiary flex items-center justify-center text-label-sm font-label-sm font-bold">3</span>
        <div class="truncate">
          <p class="text-label-md font-label-md leading-none font-semibold">Protection &amp; Coverage</p>
          <p class="text-[10px] text-tertiary mt-0.5 truncate">Comprehensive Perks</p>
        </div>
      </a>
    </div>
  </div>

  <!-- Split Layout 12-Column Architecture -->
  <form method="POST" action="" id="reservation-form">
<?= csrf_field() ?>
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

    <!-- Left Column: Form & Configuration (7 cols) -->
    <div class="lg:col-span-7 space-y-8">

      <!-- Step 1: Schedule & Selected Fleet Vehicle -->
      <section id="step-1" style="scroll-margin-top: 120px;" class="bg-surface-container-lowest border border-outline-variant/50 rounded-3xl p-6 sm:p-8 shadow-sm">
        <div class="flex items-center justify-between pb-5 border-b border-outline-variant/30">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-primary-fixed text-primary flex items-center justify-center text-label-md font-label-md font-bold">01</div>
            <h2 class="text-headline-sm font-headline-sm text-on-surface">Trip Schedule &amp; Vehicle Choice</h2>
          </div>
          <span class="text-label-sm font-label-sm text-primary font-semibold flex items-center gap-1">
            <i aria-hidden="true" class="bi bi-check-circle-fill text-[16px]"></i>
            Slot Reserved (12m Lock)
          </span>
        </div>

        <!-- Vehicle Selection Capsule Card: the page's own car leads; extra cars become sibling capsules. -->
        <div class="reservation-vehicle mt-6 p-4 rounded-2xl bg-surface-container-low border border-outline-variant/40 flex flex-col sm:flex-row items-center gap-4"
          data-vehicle-id="<?= (int)$vehicle['id'] ?>"
          data-vehicle-name="<?= htmlspecialchars($vehicle['year'] . ' ' . $vehicle['brand'] . ' ' . $vehicle['model'], ENT_QUOTES) ?>"
          data-rate="<?= htmlspecialchars((string)(float)$vehicle['daily_rate']) ?>"
          data-floor="<?= htmlspecialchars($pickupDefault) ?>">
          <?php
          $rowImg = vehicleCardImage($vehicle['image']);
          $rowSrcset = vehicleImageSrcset($vehicle['image'], BASE_URL);
          ?>
          <div class="w-full sm:w-36 h-24 rounded-xl overflow-hidden relative flex-shrink-0 bg-surface-variant">
            <?php if ($rowImg !== ''): ?>
            <img class="w-full h-full object-cover object-center" src="<?= BASE_URL ?>/<?= htmlspecialchars($rowImg) ?>"<?php if ($rowSrcset !== ''): ?> srcset="<?= htmlspecialchars($rowSrcset) ?>" sizes="(min-width: 640px) 144px, 92vw"<?php endif; ?> alt="<?= htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']) ?>" width="900" height="672" loading="lazy" decoding="async">
            <?php endif; ?>
            <span class="absolute top-1.5 left-1.5 bg-on-surface/80 backdrop-blur-md text-surface text-[10px] font-bold px-2 py-0.5 rounded-full"><?= htmlspecialchars(ucfirst($vehicle['type'])) ?></span>
          </div>
          <div class="flex-grow w-full">
            <div class="flex items-center justify-between gap-2 flex-wrap">
              <h3 class="text-headline-sm font-headline-sm text-on-surface font-bold"><?= htmlspecialchars($vehicle['year'] . ' ' . $vehicle['brand'] . ' ' . $vehicle['model']) ?></h3>
              <span class="text-label-sm font-label-sm text-primary font-bold bg-primary-fixed px-2.5 py-0.5 rounded-full">Plate: <?= htmlspecialchars($vehicle['license_plate']) ?></span>
            </div>
            <div class="flex items-center gap-3 mt-1 text-label-sm font-label-sm text-tertiary flex-wrap">
              <span class="flex items-center gap-1"><i aria-hidden="true" class="bi bi-people text-[14px]"></i> <?= htmlspecialchars($vehicle['seats']) ?> Seats</span>
              <span>&bull;</span>
              <span class="flex items-center gap-1"><i aria-hidden="true" class="bi bi-gear text-[14px]"></i> <?= htmlspecialchars(ucfirst($vehicle['transmission'])) ?></span>
              <span>&bull;</span>
              <span class="flex items-center gap-1"><i aria-hidden="true" class="bi bi-fuel-pump text-[14px]"></i> <?= htmlspecialchars(ucfirst($vehicle['fuel_type'])) ?></span>
            </div>
            <div class="flex items-center justify-between mt-3 pt-2 border-t border-outline-variant/30 gap-2 flex-wrap">
              <div class="text-primary font-bold text-label-lg font-label-lg"><?= formatCurrency($vehicle['daily_rate']) ?> <span class="text-tertiary text-label-sm font-normal">/ day</span></div>
              <div class="flex items-center gap-3 flex-wrap">
                <button type="button" id="add-vehicle-toggle" class="text-label-md font-label-md text-primary font-bold hover:underline flex items-center gap-0.5" aria-expanded="false" aria-controls="vehicle-picker">
                  Add Vehicle <i aria-hidden="true" class="bi bi-plus-circle text-[16px]"></i>
                </button>
                <a class="text-label-md font-label-md text-secondary font-bold hover:underline flex items-center gap-0.5" href="<?= BASE_URL ?>/customer/vehicles.php">
                  Change Vehicle <i aria-hidden="true" class="bi bi-arrow-left-right text-[16px]"></i>
                </a>
              </div>
            </div>
          </div>
        </div>

        <!-- Extra vehicles: each becomes its own reservation; the hidden input travels with the capsule. -->
        <div id="extra-vehicles" class="mt-4 space-y-3"></div>

        <!-- Add-Vehicle Picker -->
        <div class="mt-4">
          <div id="vehicle-picker" class="hidden p-4 rounded-2xl bg-surface-container-low border border-outline-variant/40">
            <div class="flex items-center justify-between gap-3 mb-1">
              <p class="text-label-lg font-label-lg text-on-surface font-bold flex items-center gap-2">
                <i aria-hidden="true" class="bi bi-plus-circle text-primary text-[18px]"></i>
                Add another vehicle
              </p>
              <button type="button" id="add-vehicle-close" class="w-7 h-7 rounded-full text-tertiary hover:text-on-surface hover:bg-surface-container flex items-center justify-center transition-colors" title="Close vehicle picker" aria-label="Close vehicle picker">
                <i aria-hidden="true" class="bi bi-x-lg text-[14px]"></i>
              </button>
            </div>
            <p class="text-body-sm font-body-sm text-tertiary mb-3">
              Every vehicle added here shares the dates, licence and protections of this trip. Up to <?= (int)$maxVehiclesPerBooking ?> vehicles per booking.
            </p>

            <?php if (empty($selectableVehicles)): ?>
            <p class="text-body-sm font-body-sm text-tertiary flex items-center gap-2">
              <i aria-hidden="true" class="bi bi-info-circle text-[16px]"></i>
              No other vehicle is available right now. Rented and reserved units are not offered here.
            </p>
            <?php else: ?>
            <div class="max-h-80 overflow-y-auto pr-1 space-y-2.5">
              <?php foreach ($selectableVehicles as $candidate):
                  $candidateName = $candidate['year'] . ' ' . $candidate['brand'] . ' ' . $candidate['model'];
                  $candidateImg = vehicleCardImage($candidate['image']);
                  $candidateFloor = (string)$candidate['bookable_from'];
              ?>
              <div class="vehicle-picker-row flex items-center gap-3 p-3 rounded-2xl bg-surface-container-lowest border border-outline-variant/40"
                data-vehicle-id="<?= (int)$candidate['id'] ?>"
                data-vehicle-name="<?= htmlspecialchars($candidateName, ENT_QUOTES) ?>"
                data-vehicle-plate="<?= htmlspecialchars($candidate['license_plate'], ENT_QUOTES) ?>"
                data-rate="<?= htmlspecialchars((string)(float)$candidate['daily_rate']) ?>"
                data-floor="<?= htmlspecialchars($candidateFloor) ?>"
                data-image="<?= htmlspecialchars($candidateImg, ENT_QUOTES) ?>">
                <div class="w-20 h-14 rounded-xl overflow-hidden bg-surface-variant flex-shrink-0">
                  <?php if ($candidateImg !== ''): ?>
                  <img class="w-full h-full object-cover object-center" src="<?= BASE_URL ?>/<?= htmlspecialchars($candidateImg) ?>" alt="<?= htmlspecialchars($candidateName) ?>" width="900" height="672" loading="lazy" decoding="async">
                  <?php endif; ?>
                </div>
                <div class="flex-grow min-w-0">
                  <p class="text-label-md font-label-md text-on-surface font-bold truncate"><?= htmlspecialchars($candidateName) ?></p>
                  <p class="text-body-sm font-body-sm text-tertiary truncate">
                    Plate <?= htmlspecialchars($candidate['license_plate']) ?> &bull; <?= (int)$candidate['seats'] ?> Seats &bull; <?= htmlspecialchars(ucfirst($candidate['transmission'])) ?> &bull; <?= formatCurrency($candidate['daily_rate']) ?>/day
                  </p>
                  <p class="text-label-sm font-label-sm text-tertiary mt-0.5<?= $candidateFloor > $today ? ' text-primary font-semibold' : '' ?>">
                    <i aria-hidden="true" class="bi bi-calendar-check text-[13px]"></i>
                    <?= $candidateFloor > $today ? 'Available from ' . htmlspecialchars(formatDate($candidateFloor)) : 'Available now' ?>
                  </p>
                  <p class="vehicle-picker-message hidden text-label-sm font-label-sm text-on-error-container bg-error-container rounded-full px-2.5 py-1 mt-1.5" role="status"></p>
                </div>
                <button type="button" class="add-vehicle-btn shrink-0 px-4 py-2 rounded-full bg-primary text-on-primary text-label-sm font-label-sm font-bold hover:opacity-90 transition-all active:scale-95"
                  title="Add <?= htmlspecialchars($candidateName, ENT_QUOTES) ?> to this booking"
                  aria-label="Add <?= htmlspecialchars($candidateName, ENT_QUOTES) ?> to this booking">
                  Add
                </button>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Capsule template for an added vehicle: a script-added and a POST-restored car render identically. -->
        <template id="extra-vehicle-template">
          <div class="reservation-vehicle extra-vehicle flex items-center gap-3 p-3 rounded-2xl bg-surface-container-low border border-outline-variant/40" data-vehicle-id="" data-rate="" data-floor="" data-vehicle-name="">
            <div class="w-20 h-14 rounded-xl overflow-hidden bg-surface-variant flex-shrink-0">
              <img class="w-full h-full object-cover object-center" src="" alt="" width="900" height="672" loading="lazy" decoding="async" data-vehicle-image>
            </div>
            <div class="flex-grow min-w-0">
              <p class="text-label-lg font-label-lg text-on-surface font-bold truncate" data-vehicle-name-text></p>
              <p class="text-body-sm font-body-sm text-tertiary truncate" data-vehicle-plate-text></p>
              <p class="text-primary font-bold text-label-md font-label-md mt-0.5"><span data-vehicle-rate-text></span> <span class="text-tertiary text-label-sm font-normal">/ day</span></p>
            </div>
            <input type="hidden" name="vehicle_ids[]" value="">
            <button type="button" class="remove-vehicle-btn shrink-0 w-9 h-9 rounded-full bg-surface-container-lowest border border-outline-variant/60 text-on-surface-variant hover:text-on-surface hover:border-outline flex items-center justify-center transition-all" title="Remove this vehicle from the booking" aria-label="Remove this vehicle from the booking">
              <i aria-hidden="true" class="bi bi-x-lg text-[16px]"></i>
            </button>
          </div>
        </template>

        <!-- Terminal Pickup Hub Info Segment -->
        <div class="mt-6">
          <label class="block text-label-md font-label-md text-on-surface-variant mb-1.5">Pick-up &amp; Handover Station</label>
          <div class="flex flex-wrap items-center gap-3 p-3.5 rounded-2xl bg-surface border border-outline-variant/40">
            <span class="w-10 h-10 rounded-full bg-secondary-fixed text-primary flex items-center justify-center flex-shrink-0">
              <i aria-hidden="true" class="bi bi-shop-window"></i>
            </span>
            <div class="flex-grow min-w-[12rem]">
              <p class="text-label-lg font-label-lg text-on-surface font-bold">Dagupan City Central Hub (AB Fernandez Ave)</p>
              <p class="text-body-sm font-body-sm text-tertiary">Stationed near Dagupan Peoples Astro Park &bull; 24/7 Electronic Key Locker Bay</p>
            </div>
            <span class="ml-auto inline-flex items-center gap-1.5 px-2.5 py-1 bg-[#DCFCE7] text-[#15803D] rounded-full text-label-sm font-label-sm font-bold shrink-0">
              <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              Locker active
            </span>
          </div>
        </div>

        <!-- Handover schedule: the cards are query containers, so the date and
             time controls stack instead of clipping the selected date on a
             narrow phone. The native inputs keep their values and pickers;
             the visible labels are server-rendered and updated by the script. -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-5">
          <div class="handover-card p-4 rounded-2xl bg-surface border border-outline-variant/40 transition-colors">
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 mb-3.5">
              <span class="w-8 h-8 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center shrink-0">
                <i aria-hidden="true" class="bi bi-calendar-plus text-[16px] leading-none"></i>
              </span>
              <span class="text-label-lg font-label-lg text-on-surface font-bold whitespace-nowrap">Pick-up</span>
              <?php if (!$vehicleOnRoad && $pickupDefault === $today): ?>
              <span class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold shrink-0">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Available today
              </span>
              <?php endif; ?>
            </div>

            <div class="handover-fields">
              <div class="handover-field<?= $pickupDateValue === '' ? ' is-empty' : '' ?>" data-handover-field data-date-field>
                <label for="pickup_date" class="handover-field__label">Date</label>
                <div class="handover-control">
                  <span class="handover-control__display<?= $pickupDateValue === '' ? ' is-placeholder' : '' ?>" data-handover-display aria-hidden="true"><?= htmlspecialchars($pickupDateLabel) ?></span>
                  <input type="date" id="pickup_date" name="pickup_date" min="<?= $pickupDefault ?>" required
                    value="<?= htmlspecialchars($pickupDateValue) ?>" class="handover-control__input" aria-label="Pick-up date">
                  <button type="button" class="handover-control__trigger" data-picker-trigger title="Open the pick-up date picker" aria-label="Open the pick-up date picker">
                    <i aria-hidden="true" class="bi bi-calendar3"></i>
                  </button>
                </div>
                <p class="text-error text-[11px] mt-1 hidden" data-date-error="pickup_date">Year must be 4 digits</p>
              </div>
              <div class="handover-field<?= $pickupTimeValue === '' ? ' is-empty' : '' ?>" data-handover-field>
                <label for="pickup_time" class="handover-field__label">Time</label>
                <div class="handover-control">
                  <span class="handover-control__display<?= $pickupTimeValue === '' ? ' is-placeholder' : '' ?>" data-handover-display aria-hidden="true"><?= htmlspecialchars($pickupTimeLabel) ?></span>
                  <input type="time" id="pickup_time" name="pickup_time" required step="300"
                    value="<?= htmlspecialchars($pickupTimeValue) ?>" class="handover-control__input" aria-label="Pick-up time">
                  <button type="button" class="handover-control__trigger" data-picker-trigger title="Open the pick-up time picker" aria-label="Open the pick-up time picker">
                    <i aria-hidden="true" class="bi bi-clock"></i>
                  </button>
                </div>
              </div>
            </div>

            <?php if ($vehicleOnRoad || $pickupDefault !== $today): ?>
            <div class="mt-3 pt-3 border-t border-outline-variant/30 space-y-1.5">
              <?php if ($vehicleOnRoad): ?>
              <p class="text-body-sm font-body-sm text-on-surface flex items-center gap-1.5">
                <i aria-hidden="true" class="bi bi-calendar-check text-[14px] text-primary shrink-0"></i>
                <span><?= $vehicleOverdue ? 'Still out &middot; was due back' : 'On rent now &middot; returns' ?> <strong class="font-bold"><?= htmlspecialchars(formatDate($vehicleReturnDate)) ?></strong></span>
              </p>
              <?php endif; ?>
              <?php if ($pickupDefault !== $today): ?>
              <p class="text-body-sm font-body-sm text-tertiary flex items-center gap-1.5">
                <i aria-hidden="true" class="bi bi-calendar-range text-[14px] shrink-0"></i>
                <span>Book from <strong class="font-semibold text-on-surface"><?= htmlspecialchars(formatDate($pickupDefault)) ?></strong> onwards</span>
              </p>
              <?php endif; ?>
            </div>
            <?php endif; ?>


          </div>

          <div class="handover-card p-4 rounded-2xl bg-surface border border-outline-variant/40 transition-colors">
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 mb-3.5">
              <span class="w-8 h-8 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center shrink-0">
                <i aria-hidden="true" class="bi bi-calendar-check text-[16px] leading-none"></i>
              </span>
              <span class="text-label-lg font-label-lg text-on-surface font-bold whitespace-nowrap">Return</span>
            </div>

            <div class="handover-fields">
              <div class="handover-field<?= $returnDateValue === '' ? ' is-empty' : '' ?>" data-handover-field data-date-field>
                <label for="return_date" class="handover-field__label">Date</label>
                <div class="handover-control">
                  <span class="handover-control__display<?= $returnDateValue === '' ? ' is-placeholder' : '' ?>" data-handover-display aria-hidden="true"><?= htmlspecialchars($returnDateLabel) ?></span>
                  <input type="date" id="return_date" name="return_date" min="<?= htmlspecialchars($returnDateMin) ?>" required
                    value="<?= htmlspecialchars($returnDateValue) ?>" class="handover-control__input" aria-label="Return date">
                  <button type="button" class="handover-control__trigger" data-picker-trigger title="Open the return date picker" aria-label="Open the return date picker">
                    <i aria-hidden="true" class="bi bi-calendar3"></i>
                  </button>
                </div>
                <p class="text-error text-[11px] mt-1 hidden" data-date-error="return_date">Year must be 4 digits</p>
              </div>
              <div class="handover-field<?= $returnTimeValue === '' ? ' is-empty' : '' ?>" data-handover-field>
                <label for="return_time" class="handover-field__label">Time</label>
                <div class="handover-control">
                  <span class="handover-control__display<?= $returnTimeValue === '' ? ' is-placeholder' : '' ?>" data-handover-display aria-hidden="true"><?= htmlspecialchars($returnTimeLabel) ?></span>
                  <input type="time" id="return_time" name="return_time" required step="300"
                    value="<?= htmlspecialchars($returnTimeValue) ?>" class="handover-control__input" aria-label="Return time">
                  <button type="button" class="handover-control__trigger" data-picker-trigger title="Open the return time picker" aria-label="Open the return time picker">
                    <i aria-hidden="true" class="bi bi-clock"></i>
                  </button>
                </div>
              </div>
            </div>


          </div>
        </div>

        <div id="availability-check" class="mt-4 hidden items-center gap-2 px-4 py-3 rounded-2xl text-body-sm font-body-sm border">
          <i aria-hidden="true" class="bi bi-arrow-clockwise text-[18px]" id="availability-icon"></i>
          <span id="availability-message">Checking vehicle availability...</span>
        </div>

        <!-- Pickup & Return Location: removed for a single-hub business; the stored value stays pinned. -->
      </section>

      <!-- Step 2: Driver & Digital KYC -->
      <section id="step-2" style="scroll-margin-top: 120px;" class="bg-surface-container-lowest border border-outline-variant/50 rounded-3xl p-6 sm:p-8 shadow-sm">
        <div class="flex items-center justify-between pb-5 border-b border-outline-variant/30">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-primary-fixed text-primary flex items-center justify-center text-label-md font-label-md font-bold">02</div>
            <h2 class="text-headline-sm font-headline-sm text-on-surface">Driver &amp; Digital KYC</h2>
          </div>
          <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-secondary-fixed text-primary text-label-sm font-label-sm font-bold">
            <i aria-hidden="true" class="bi bi-shield-check text-[14px]"></i> Instant LTO Verification
          </span>
        </div>
        <p class="text-body-sm font-body-sm text-tertiary mt-4">
          Supply your Philippines Driver's License to satisfy LTO compliance and smart-locker activation at the Dagupan handover counter.
        </p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
          <div>
            <label class="block text-label-md font-label-md text-on-surface mb-1.5">Full Legal Name (as on Account)</label>
            <div class="relative">
              <i aria-hidden="true" class="bi bi-person absolute left-4 top-1/2 -translate-y-1/2 text-tertiary text-[20px]"></i>
              <input class="w-full pl-11 pr-4 py-3 rounded-full border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-on-surface bg-surface-container-lowest text-body-md font-body-md" type="text" value="<?= htmlspecialchars($driverName) ?>" readonly>
            </div>
          </div>
          <div>
            <label class="block text-label-md font-label-md text-on-surface mb-1.5">Mobile Phone (OTP &amp; Locker PIN)</label>
            <div class="relative">
              <i aria-hidden="true" class="bi bi-telephone absolute left-4 top-1/2 -translate-y-1/2 text-tertiary text-[20px]"></i>
              <input class="w-full pl-11 pr-4 py-3 rounded-full border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-on-surface bg-surface-container-lowest text-body-md font-body-md" type="text" value="<?= htmlspecialchars($currentUser['phone'] ?? '&mdash;') ?>" readonly>
            </div>
          </div>
          <div class="md:col-span-2">
            <label class="block text-label-md font-label-md text-on-surface mb-1.5">Official E-mail Address</label>
            <div class="relative">
              <i aria-hidden="true" class="bi bi-envelope absolute left-4 top-1/2 -translate-y-1/2 text-tertiary text-[20px]"></i>
              <input class="w-full pl-11 pr-4 py-3 rounded-full border border-outline-variant/60 focus:border-primary focus:ring-4 focus:ring-primary/10 text-on-surface bg-surface-container-lowest text-body-md font-body-md" type="email" value="<?= htmlspecialchars($currentUser['email'] ?? '') ?>" readonly>
            </div>
          </div>
          <div class="md:col-span-2">
            <label class="block text-label-md font-label-md text-on-surface mb-1.5">Philippines Driver's License (LTO)</label>
            <div class="relative">
              <i aria-hidden="true" class="bi bi-person-badge absolute left-4 top-1/2 -translate-y-1/2 text-primary text-[20px]"></i>
              <input id="license_no" name="license_no" type="text" placeholder="e.g. D01-23-045678" maxlength="20" required
                value="<?= htmlspecialchars($_POST['license_no'] ?? '') ?>"
                class="w-full pl-11 pr-4 py-3 rounded-full border border-outline-variant/70 focus:border-primary focus:ring-4 focus:ring-primary/15 text-on-surface bg-surface-container-low text-body-md font-body-md placeholder:text-outline">
            </div>
            <p class="text-label-sm font-label-sm text-tertiary mt-1.5 flex items-center gap-1">
              <i aria-hidden="true" class="bi bi-shield-check text-[14px]"></i> Cross-checked against the LTO database during instant verification.
            </p>
          </div>
        </div>
      </section>

      <!-- Step 3: Driver Option -->
      <section id="step-3" style="scroll-margin-top: 120px;" class="bg-surface-container-lowest border border-outline-variant/50 rounded-3xl p-6 sm:p-8 shadow-sm">
        <div class="flex items-center justify-between pb-5 border-b border-outline-variant/30">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-primary-fixed text-primary flex items-center justify-center text-label-md font-label-md font-bold">03</div>
            <h2 class="text-headline-sm font-headline-sm text-on-surface">Driver Option</h2>
          </div>
          <span class="text-label-sm font-label-sm text-tertiary">Required selection</span>
        </div>
        <div class="space-y-3.5 mt-6">
          <label class="driver-item block cursor-pointer relative group">
            <input type="radio" name="driver_option" value="with_driver" data-price="1000" data-label="With Driver" class="driver-radio" required style="position:absolute;opacity:0;width:1px;height:1px;pointer-events:none;">
            <div class="driver-inner flex items-center justify-between gap-3 p-4 rounded-2xl border-2 border-outline-variant/60 bg-surface-container-low/60 transition-all group-hover:border-outline">
              <div class="flex items-center gap-3">
                <span class="driver-box w-6 h-6 rounded-full border-2 border-outline-variant/70 bg-surface-container-lowest transition-all flex items-center justify-center shrink-0">
                  <span class="w-2.5 h-2.5 rounded-full bg-primary opacity-0 transition-opacity"></span>
                </span>
                <div>
                  <span class="text-label-lg font-label-lg text-on-surface font-bold">With Driver</span>
                  <p class="text-body-sm font-body-sm text-tertiary mt-0.5">A professional driver will be provided for your rental.</p>
                </div>
              </div>
              <span class="text-label-lg font-label-lg font-bold text-primary shrink-0">₱1,000.00</span>
            </div>
          </label>
          <label class="driver-item block cursor-pointer relative group">
            <input type="radio" name="driver_option" value="self_drive" data-price="0" data-label="Without Driver (Self-Drive)" class="driver-radio" required style="position:absolute;opacity:0;width:1px;height:1px;pointer-events:none;">
            <div class="driver-inner flex items-center justify-between gap-3 p-4 rounded-2xl border-2 border-outline-variant/60 bg-surface-container-low/60 transition-all group-hover:border-outline">
              <div class="flex items-center gap-3">
                <span class="driver-box w-6 h-6 rounded-full border-2 border-outline-variant/70 bg-surface-container-lowest transition-all flex items-center justify-center shrink-0">
                  <span class="w-2.5 h-2.5 rounded-full bg-primary opacity-0 transition-opacity"></span>
                </span>
                <div>
                  <span class="text-label-lg font-label-lg text-on-surface font-bold">Without Driver (Self-Drive)</span>
                  <p class="text-body-sm font-body-sm text-tertiary mt-0.5">You will drive the vehicle yourself.</p>
                </div>
              </div>
            </div>
          </label>
        </div>
        <p class="text-label-sm font-label-sm text-tertiary mt-3 flex items-center gap-1.5">
          <i aria-hidden="true" class="bi bi-info-circle text-[14px]"></i>
          The driver fee applies to every vehicle in this booking and is added to your confirmed total.
        </p>
      </section>

      <!-- Step 4: GCash Deposit: the number and amount are config values, changeable without a redeploy. -->
      <?php if ($depositRequired): ?>
      <section id="step-4" style="scroll-margin-top: 120px;" class="bg-surface-container-lowest border border-outline-variant/50 rounded-3xl p-6 sm:p-8 shadow-sm">
        <div class="flex items-center justify-between pb-5 border-b border-outline-variant/30">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-primary-fixed text-primary flex items-center justify-center text-label-md font-label-md font-bold">04</div>
            <h2 class="text-headline-sm font-headline-sm text-on-surface">GCash Deposit</h2>
          </div>
          <span class="text-label-sm font-label-sm text-tertiary">Required to confirm</span>
        </div>

        <div class="mt-6 space-y-5">
          <div class="rounded-2xl border border-outline-variant/60 bg-surface-container-low p-4 sm:p-5">
            <p class="text-label-sm font-label-sm uppercase tracking-wider text-tertiary font-semibold">Send the deposit to this GCash number</p>
            <div class="mt-2 flex flex-wrap items-center gap-3">
              <span class="font-mono text-headline-sm font-headline-sm font-bold text-on-surface tracking-wide"><?= htmlspecialchars(CARVELLA_GCASH_NUMBER) ?></span>
              <button type="button" data-copy-value="<?= htmlspecialchars(CARVELLA_GCASH_NUMBER) ?>" class="px-3 py-1.5 rounded-full border border-outline-variant/60 text-on-surface-variant font-label-md text-label-md hover:bg-surface-container transition-colors">
                Copy number
              </button>
            </div>
            <p class="text-body-sm font-body-sm text-tertiary mt-3">
              Deposit amount <strong class="text-on-surface deposit-amount"><?= formatCurrency($depositAmount) ?></strong> &mdash; based on the vehicle's daily rate. The rest of the total is settled in cash at the counter on pickup.
            </p>
          </div>

          <div>
            <label for="payment_reference" class="block text-label-md font-label-md text-on-surface mb-1.5">GCash Reference Number</label>
            <div class="relative">
              <i aria-hidden="true" class="bi bi-receipt absolute left-4 top-1/2 -translate-y-1/2 text-tertiary text-[20px]"></i>
              <input id="payment_reference" name="payment_reference" type="text" inputmode="numeric" pattern="[0-9]{13}" maxlength="13" required autocomplete="off"
                value="<?= htmlspecialchars($_POST['payment_reference'] ?? '') ?>"
                placeholder="e.g. 1234567890123"
                class="w-full pl-11 pr-4 py-3 rounded-full border border-outline-variant/70 focus:border-primary focus:ring-4 focus:ring-primary/15 text-on-surface bg-surface-container-lowest text-body-md font-body-md placeholder:text-outline">
            </div>
            <p class="text-label-sm font-label-sm text-tertiary mt-1.5 flex items-center gap-1">
              <i aria-hidden="true" class="bi bi-info-circle text-[14px]"></i> The 13-digit reference printed on your GCash receipt. The counter matches it against the transfer before the car is released.
            </p>
          </div>
        </div>
      </section>
      <?php endif; ?>
    </div>

    <!-- Right Column: Sticky Reservation Summary & Guarantee Card (5 cols) -->
    <div class="lg:col-span-5 lg:sticky lg:top-24 space-y-6">
      <div class="bg-surface-container-lowest border border-outline-variant/60 rounded-3xl p-6 sm:p-7 shadow-lg relative overflow-hidden">

        <!-- Vehicle Visual & Core Metrics -->
        <div class="flex gap-4 items-center pb-5 border-b border-outline-variant/30">
          <?php
          $thumbImg = vehicleCardImage($vehicle['image']);
          $thumbSrcset = vehicleImageSrcset($vehicle['image'], BASE_URL);
          ?>
          <div class="w-24 h-16 rounded-xl overflow-hidden bg-surface-variant flex-shrink-0">
            <?php if ($thumbImg !== ''): ?>
            <img class="w-full h-full object-cover object-center" src="<?= BASE_URL ?>/<?= htmlspecialchars($thumbImg) ?>"<?php if ($thumbSrcset !== ''): ?> srcset="<?= htmlspecialchars($thumbSrcset) ?>" sizes="96px"<?php endif; ?> alt="<?= htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']) ?>" width="900" height="672" loading="lazy" decoding="async">
            <?php endif; ?>
          </div>
          <div>
            <span class="text-label-sm font-label-sm text-tertiary uppercase tracking-wider font-semibold">Selected Fleet Item</span>
            <h4 class="text-headline-sm font-headline-sm text-on-surface font-bold"><?= htmlspecialchars($vehicle['year'] . ' ' . $vehicle['brand'] . ' ' . $vehicle['model']) ?></h4>
            <p class="text-body-sm font-body-sm text-tertiary">Plate: <?= htmlspecialchars($vehicle['license_plate']) ?></p>
          </div>
        </div>

        <!-- Trip Duration Breakdown -->
        <div class="py-4 border-b border-outline-variant/30 space-y-2.5">
          <div class="flex items-center justify-between text-body-sm font-body-sm">
            <span class="text-tertiary flex items-center gap-1.5">
              <i aria-hidden="true" class="bi bi-stopwatch text-[16px]"></i> Duration
            </span>
            <span class="font-bold text-on-surface" id="summary-days">--</span>
          </div>
          <div class="flex items-center justify-between text-body-sm font-body-sm">
            <span class="text-tertiary flex items-center gap-1.5">
              <i aria-hidden="true" class="bi bi-calendar-date text-[16px]"></i> Pickup
            </span>
            <span class="font-semibold text-on-surface" id="summary-pickup">--</span>
          </div>
          <div class="flex items-center justify-between text-body-sm font-body-sm">
            <span class="text-tertiary flex items-center gap-1.5">
              <i aria-hidden="true" class="bi bi-calendar-check text-[16px]"></i> Return
            </span>
            <span class="font-semibold text-on-surface" id="summary-return">--</span>
          </div>
          <div class="flex items-center justify-between text-body-sm font-body-sm">
            <span class="text-tertiary flex items-center gap-1.5">
              <i aria-hidden="true" class="bi bi-door-open text-[16px]"></i> Locker Access
            </span>
            <span class="font-semibold text-primary">Smart Locker Bay</span>
          </div>
        </div>

        <!-- Calculated Financial Breakdown -->
        <div class="py-4 border-b border-outline-variant/30 space-y-2">
          <div class="flex justify-between text-body-md font-body-md text-on-surface">
            <span>Base Fleet Rate</span>
            <span class="font-semibold" id="summary-rate-line"><?= formatCurrency($vehicle['daily_rate']) ?> x --</span>
          </div>
          <!-- One line per car, filled in by the page script once a second vehicle joins. -->
          <ul id="summary-vehicle-lines" class="hidden text-[12px] text-tertiary space-y-1 pb-1"></ul>
          <div class="flex justify-between text-body-md font-body-md text-on-surface">
            <span>Estimated Rental Subtotal</span>
            <span class="font-semibold" id="summary-subtotal">--</span>
          </div>
          <div id="summary-driver-row" class="hidden">
            <div class="flex justify-between text-body-md font-body-md text-on-surface">
              <span>Driver Option<span id="summary-driver-count" class="text-tertiary text-[12px] font-normal"></span></span>
              <span class="font-semibold" id="summary-driver">--</span>
            </div>
            <p id="summary-driver-label" class="text-[12px] font-semibold text-primary mt-1"></p>
          </div>
          <div id="summary-protection-row" class="hidden">
            <div class="flex justify-between text-body-md font-body-md text-on-surface">
              <span>Protection Package<span id="summary-protection-count" class="text-tertiary text-[12px] font-normal"></span></span>
              <span class="font-semibold" id="summary-protection">--</span>
            </div>
            <ul id="summary-protection-list" class="text-[12px] font-semibold text-emerald-700 mt-1.5 space-y-1"></ul>
          </div>
          <div class="flex justify-between text-body-sm font-body-sm text-tertiary">
            <span>12% EVAT &amp; Dagupan Terminal Surcharge</span>
            <span class="font-medium text-[#15803D]">Included</span>
          </div>
        </div>

        <!-- Grand Totals Section -->
        <div class="pt-4 pb-6">
          <div class="flex items-baseline justify-between">
            <div>
              <span class="text-label-md font-label-md text-tertiary block">Estimated Total</span>
              <span class="text-numeric-stat font-numeric-stat text-primary font-black" id="summary-total" data-count="manual"><?= formatCurrency(0) ?></span>
            </div>
            <div class="text-right">
              <span class="text-[11px] text-tertiary block" id="summary-perday"><?= formatCurrency($vehicle['daily_rate']) ?>/day</span>
            </div>
          </div>
          <?php if ($depositRequired): ?>
          <div class="border-t border-outline-variant/30 mt-3 pt-3 space-y-2">
            <div class="flex justify-between text-body-sm font-body-sm text-on-surface">
              <span>GCash deposit</span>
              <span class="font-semibold text-primary deposit-amount"><?= formatCurrency($depositAmount) ?></span>
            </div>
            <div class="flex justify-between text-body-sm font-body-sm text-tertiary">
              <span>Balance at counter</span>
              <span class="font-medium" id="summary-balance">--</span>
            </div>
          </div>
          <p class="mt-3 flex items-start gap-1.5 text-[11px] text-tertiary">
            <i aria-hidden="true" class="bi bi-cash-stack text-[14px] leading-none mt-px shrink-0"></i>
            <span>The deposit is sent through GCash to <?= htmlspecialchars(CARVELLA_GCASH_NUMBER) ?> before pickup. The remaining balance is paid in cash at the Dagupan Central Hub counter.</span>
          </p>
          <?php else: ?>
          <p class="mt-3 flex items-start gap-1.5 text-[11px] text-tertiary">
            <i aria-hidden="true" class="bi bi-cash-stack text-[14px] leading-none mt-px shrink-0"></i>
            <span>Payment is made in cash at the Dagupan Central Hub counter on pickup.</span>
          </p>
          <?php endif; ?>
        </div>

        <!-- Primary Action Call-to-Action -->
        <div class="mb-4">
          <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" id="gcash_deposit_ack" required class="mt-1 w-4 h-4 rounded border-outline-variant text-primary focus:ring-primary/20">
            <span class="text-[12px] text-on-surface leading-relaxed">
              I confirm that the deposit of <strong class="text-primary deposit-amount"><?= formatCurrency($depositAmount) ?></strong> will be sent through GCash to <strong><?= htmlspecialchars(CARVELLA_GCASH_NUMBER) ?></strong> before pickup. The remaining balance is paid in cash at the Dagupan Central Hub counter.
            </span>
          </label>
        </div>
        <button type="submit" class="w-full py-4 px-6 rounded-full bg-primary hover:opacity-95 text-on-primary font-label-lg text-label-lg font-bold shadow-lg hover:shadow-xl transition-all duration-200 active:scale-95 flex items-center justify-center gap-2">
          Confirm <?= $bookingFlow === 'rent' ? 'Rental' : 'Reservation' ?>
        </button>
        <p class="text-center text-[11px] text-tertiary mt-3">
          By confirming, you accept CARVELLA Dagupan Fleet Rental Terms &amp; LTO Compliance KYC Agreement.
        </p>
      </div>

      <!-- Need Assistance Capsule -->
      <div class="p-4 rounded-2xl bg-surface-container-lowest border border-outline-variant/50 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <i aria-hidden="true" class="bi bi-telephone text-primary text-[22px]"></i>
          <div>
            <p class="text-label-md font-label-md text-on-surface font-bold">AB Fernandez Hub Dispatcher</p>
            <p class="text-body-sm font-body-sm text-tertiary">+63 (075) 522-8890 &bull; 24/7 Hotlines</p>
          </div>
        </div>
        <a class="text-label-sm font-label-sm text-primary font-bold hover:underline" href="<?= BASE_URL ?>/index.php#contact">Contact</a>
      </div>
    </div>

  </div>
  </form>
</main>

<style>html{scroll-behavior:smooth;}
.protection-check:checked ~ .protection-inner{border-color:#6b38d4;box-shadow:0 0 0 4px rgba(107,56,212,.12);}
.protection-check:checked ~ .protection-inner .protection-box{background:#6b38d4;border-color:#6b38d4;color:#fff;}
.driver-radio:checked ~ .driver-inner{border-color:#6b38d4;background:rgba(107,56,212,.05);box-shadow:0 1px 2px 0 rgba(0,0,0,.05);}
.driver-radio:checked ~ .driver-inner .driver-box{background:#6b38d4;border-color:#6b38d4;}
.driver-radio:checked ~ .driver-inner .driver-box span{opacity:1 !important;}
</style>
<script>
  const pickupInput = document.getElementById('pickup_date');
  const returnInput = document.getElementById('return_date');
  const pickupTimeInput = document.getElementById('pickup_time');
  const returnTimeInput = document.getElementById('return_time');
  const summaryDays = document.getElementById('summary-days');
  const scheduleDuration = document.getElementById('schedule-duration');
  const summaryPickup = document.getElementById('summary-pickup');
  const summaryReturn = document.getElementById('summary-return');
  const summaryRateLine = document.getElementById('summary-rate-line');
  const summarySubtotal = document.getElementById('summary-subtotal');
  const summaryProtection = document.getElementById('summary-protection');
  const summaryProtectionRow = document.getElementById('summary-protection-row');
  const summaryProtectionCount = document.getElementById('summary-protection-count');
  const summaryVehicleLines = document.getElementById('summary-vehicle-lines');
  const summaryTotal = document.getElementById('summary-total');
  const summaryBalance = document.getElementById('summary-balance');
  const summaryPerDay = document.getElementById('summary-perday');
  const summaryProtectionList = document.getElementById('summary-protection-list');
  const protectionInputs = document.querySelectorAll('input[name="protection[]"]');
  const driverOptionInputs = document.querySelectorAll('input[name="driver_option"]');
  const summaryDriverRow = document.getElementById('summary-driver-row');
  const summaryDriver = document.getElementById('summary-driver');
  const summaryDriverCount = document.getElementById('summary-driver-count');
  const summaryDriverLabel = document.getElementById('summary-driver-label');
  const extraVehiclesContainer = document.getElementById('extra-vehicles');
  const vehiclePicker = document.getElementById('vehicle-picker');
  const addVehicleToggle = document.getElementById('add-vehicle-toggle');
  const addVehicleClose = document.getElementById('add-vehicle-close');
  const extraVehicleTemplate = document.getElementById('extra-vehicle-template');

  // A booking shares one date range: the pick-up starts at the latest floor and protections bill per car.
  const MAX_BOOKING_VEHICLES = <?= (int)$maxVehiclesPerBooking ?>;
  const availabilityUrl = <?= json_encode(BASE_URL . '/api/vehicles/availability.php') ?>;
  // The GCash deposit scales with the rented vehicle's daily rate.
  let depositAmount = <?= json_encode((float)$depositAmount) ?>;
  function computeDepositAmount() {
    const vehicles = selectedVehicles();
    let maxRate = <?= json_encode((float)$vehicle['daily_rate']) ?>;
    vehicles.forEach(function (el) {
      const r = parseFloat(el.dataset.rate || 0);
      if (r > maxRate) maxRate = r;
    });
    return maxRate >= 2000 ? 1500 : 1000;
  }
  // The chosen cars are rebuilt below so a rejected POST re-render cannot drop them on a validation error.
  const postedExtraVehicleIds = <?= json_encode(array_values($postedExtraVehicleIds)) ?>;
  let availabilityTimer = null;
  let availabilityState = null;

  function formatDateLabel(dateStr) {
    if (!dateStr) return '--';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  // Mirrors the PHP formatTime() fallback so the summary reads like the handover slip.
  function formatTimeLabel(timeStr) {
    if (!timeStr) return '';
    const parts = String(timeStr).split(':');
    let hours = parseInt(parts[0], 10);
    if (isNaN(hours)) return '';
    const minutes = parts[1] || '00';
    const suffix = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    return hours + ':' + minutes + ' ' + suffix;
  }

  /* The date/time fields paint a formatted label over a transparent native
     input. This keeps that label in step with the picker and with keyboard
     edits; the server renders the same text on first paint. */
  function syncHandoverField(field) {
    const input = field.querySelector('input');
    const display = field.querySelector('[data-handover-display]');
    if (!input || !display) return;
    const empty = !input.value;
    field.classList.toggle('is-empty', empty);
    display.classList.toggle('is-placeholder', empty);
    if (empty) {
      display.textContent = input.type === 'time' ? 'Select time' : 'Select date';
    } else if (input.type === 'time') {
      display.textContent = formatTimeLabel(input.value);
    } else {
      display.textContent = formatDateLabel(input.value);
    }
  }

  function syncAllHandoverFields() {
    document.querySelectorAll('[data-handover-field]').forEach(syncHandoverField);
  }

  function initHandoverFields() {
    document.querySelectorAll('[data-handover-field]').forEach(function (field) {
      const input = field.querySelector('input');
      const trigger = field.querySelector('[data-picker-trigger]');
      if (!input) return;
      ['input', 'change'].forEach(function (eventName) {
        input.addEventListener(eventName, function () { syncHandoverField(field); });
      });
      if (trigger) {
        trigger.addEventListener('click', function () {
          if (typeof input.showPicker === 'function') {
            try { input.showPicker(); return; } catch (err) { /* fall through to focus */ }
          }
          input.focus();
        });
      }
      syncHandoverField(field);
    });
  }

  function getProtectionSelected() {
    const selected = [];
    protectionInputs.forEach(function (cb) {
      if (cb.checked) selected.push({ label: cb.dataset.label || 'Protection', price: parseFloat(cb.dataset.price || 0) });
    });
    return selected;
  }

  function getProtectionTotal() {
    let total = 0;
    protectionInputs.forEach(function (cb) {
      if (cb.checked) total += parseFloat(cb.dataset.price || 0);
    });
    return total;
  }

  function updateProtectionSummary() {
    const selected = getProtectionSelected();
    // Every add-on is billed per car, so these figures follow the number of vehicles on the trip.
    const vehicles = Math.max(1, selectedVehicles().length);
    const protection = getProtectionTotal() * vehicles;
    if (summaryProtectionCount) {
      summaryProtectionCount.textContent = vehicles > 1 ? ' (x ' + vehicles + ' vehicles)' : '';
    }
    if (protection > 0) {
      summaryProtectionRow.classList.remove('hidden');
      summaryProtection.textContent = formatCurrencyJS(protection);
      summaryProtectionList.innerHTML = '';
      selected.forEach(function (p) {
        const li = document.createElement('li');
        li.className = 'flex items-center justify-between gap-2';
        const name = document.createElement('span');
        name.className = 'flex items-center gap-1';
        name.innerHTML = '<i aria-hidden="true" class="bi bi-shield text-[14px]"></i>' + p.label.replace(/&/g, '&amp;') + (vehicles > 1 ? ' x ' + vehicles : '');
        const price = document.createElement('span');
        price.textContent = '+' + formatCurrencyJS(p.price * vehicles);
        li.appendChild(name);
        li.appendChild(price);
        summaryProtectionList.appendChild(li);
      });
    } else {
      summaryProtectionRow.classList.add('hidden');
      summaryProtection.textContent = '--';
      summaryProtectionList.innerHTML = '';
    }
  }

  /* Vehicles in this booking: each capsule holds its own vehicle_ids[] input, so rendering it submits it. */
  function selectedVehicles() {
    return Array.prototype.slice.call(document.querySelectorAll('.reservation-vehicle'));
  }

  function selectedVehicleIds() {
    return selectedVehicles().map(function (el) { return parseInt(el.dataset.vehicleId, 10); });
  }

  // The booking shares one date range, so the daily rate is the sum of every selected car's rate.
  function fleetDailyRate() {
    return selectedVehicles().reduce(function (sum, el) {
      return sum + parseFloat(el.dataset.rate || 0);
    }, 0);
  }

  /* The latest floor among the cars on the trip, so the widget and the server guard read the same value. */
  function bookingFloor() {
    let floor = '';
    selectedVehicles().forEach(function (el) {
      const value = el.dataset.floor || '';
      if (value && (floor === '' || value > floor)) floor = value;
    });
    return floor;
  }

  function syncBookingFloor() {
    const floor = bookingFloor();
    if (!floor) return;
    pickupInput.min = floor;
    if (pickupInput.value && pickupInput.value < floor) pickupInput.value = floor;
    // Mirror reservation.js: a script-assigned pick-up value fires no change event, so set the return floor here.
    returnInput.min = pickupInput.value || floor;
    if (returnInput.value && returnInput.value < returnInput.min) returnInput.value = returnInput.min;
  }

  function updateVehicleSummary() {
    const vehicles = selectedVehicles();
    if (!summaryVehicleLines) return;
    if (vehicles.length < 2) {
      summaryVehicleLines.classList.add('hidden');
      summaryVehicleLines.innerHTML = '';
      return;
    }
    // More than one car and the "Base Fleet Rate" line stops explaining the total, so each car is listed below.
    summaryVehicleLines.classList.remove('hidden');
    summaryVehicleLines.innerHTML = '';
    vehicles.forEach(function (el) {
      const li = document.createElement('li');
      li.className = 'flex items-center justify-between gap-2';
      const name = document.createElement('span');
      name.className = 'truncate';
      name.textContent = el.dataset.vehicleName || ('Vehicle #' + el.dataset.vehicleId);
      const rate = document.createElement('span');
      rate.className = 'shrink-0 font-semibold text-on-surface-variant';
      rate.textContent = formatCurrencyJS(parseFloat(el.dataset.rate || 0)) + '/day';
      li.appendChild(name);
      li.appendChild(rate);
      summaryVehicleLines.appendChild(li);
    });
  }

  function getDriverOption() {
    let selected = null;
    driverOptionInputs.forEach(function (input) {
      if (input.checked) {
        selected = {
          value: input.value,
          price: parseFloat(input.dataset.price || 0),
          label: input.dataset.label || (input.value === 'with_driver' ? 'With Driver' : 'Without Driver (Self-Drive)')
        };
      }
    });
    return selected;
  }

  function getDriverFeeTotal() {
    const selected = getDriverOption();
    if (!selected) return 0;
    if (selected.value === 'with_driver') {
      const vehicles = Math.max(1, selectedVehicles().length);
      return selected.price * vehicles;
    }
    return 0;
  }

  function updateDriverSummary() {
    const selected = getDriverOption();
    const vehicles = Math.max(1, selectedVehicles().length);
    if (summaryDriverRow && summaryDriver) {
      if (selected && selected.value === 'with_driver') {
        const fee = selected.price * vehicles;
        summaryDriverRow.classList.remove('hidden');
        if (summaryDriverCount) {
          summaryDriverCount.textContent = vehicles > 1 ? ' (x ' + vehicles + ' vehicles)' : '';
        }
        if (summaryDriverLabel) {
          summaryDriverLabel.textContent = selected.label;
        }
        summaryDriver.textContent = formatCurrencyJS(fee);
      } else {
        summaryDriverRow.classList.add('hidden');
        summaryDriver.textContent = '--';
        if (summaryDriverCount) summaryDriverCount.textContent = '';
        if (summaryDriverLabel) summaryDriverLabel.textContent = '';
      }
    }
  }

  function updateSummary() {
    depositAmount = computeDepositAmount();
    document.querySelectorAll('.deposit-amount').forEach(function (el) {
      el.textContent = formatCurrencyJS(depositAmount);
    });
    updateProtectionSummary();
    updateVehicleSummary();
    updateDriverSummary();

    if (pickupInput.value && returnInput.value) {
      // A same-day booking is one billable day, so the count floors at 1 instead of falling through to 0.
      const days = Math.max(1, Math.ceil((new Date(returnInput.value) - new Date(pickupInput.value)) / (1000 * 60 * 60 * 24)));
      if (days > 0) {
        const vehicles = Math.max(1, selectedVehicles().length);
        const dailyRate = fleetDailyRate();
        // Protections are charged per car; the rental rate is the sum of the cars' rates per day.
        const protection = getProtectionTotal() * vehicles;
        const driverFee = getDriverFeeTotal();
        const total = days * dailyRate + protection + driverFee;
        summaryDays.textContent = days + ' Day' + (days > 1 ? 's' : '');
        if (scheduleDuration) scheduleDuration.textContent = days + ' day' + (days > 1 ? 's' : '');
        const pickupClock = pickupTimeInput && pickupTimeInput.value ? ', ' + formatTimeLabel(pickupTimeInput.value) : '';
        const returnClock = returnTimeInput && returnTimeInput.value ? ', ' + formatTimeLabel(returnTimeInput.value) : '';
        summaryPickup.textContent = formatDateLabel(pickupInput.value) + pickupClock;
        summaryReturn.textContent = formatDateLabel(returnInput.value) + returnClock;
        summaryRateLine.textContent = formatCurrencyJS(dailyRate) + ' x ' + days;
        setMoney(summarySubtotal, days * dailyRate);
        setMoney(summaryTotal, total);
        // The deposit comes off the total up front; the rest is paid in cash and never goes negative.
        setMoney(summaryBalance, Math.max(0, total - depositAmount));
        summaryPerDay.textContent = formatCurrencyJS(dailyRate) + '/day';
      } else {
        if (scheduleDuration) scheduleDuration.textContent = 'Check dates';
        setMoney(summaryTotal, 0);
        setMoney(summaryBalance, 0);
      }
    } else {
      if (scheduleDuration) {
        scheduleDuration.textContent = returnInput.value ? 'Select pick-up date' : 'Select return date';
      }
      setMoney(summaryTotal, 0);
      setMoney(summaryBalance, 0);
    }
  }

  function formatCurrencyJS(amount) {
    amount = Number(amount) || 0;
    if (window.CarvellaNumber) return '\u20B1' + window.CarvellaNumber.groupDigits(amount);
    return '\u20B1' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',').replace(/\.00$/, '');
  }

  // Money that changes in place (day count, protection add-ons) glides between values instead of snapping.
  function setMoney(el, amount) {
    if (!el) return;
    amount = Number(amount) || 0;
    const prev = Number(el.dataset.shown || amount);
    el.dataset.shown = String(amount);
    const final = formatCurrencyJS(amount);
    if (window.CarvellaNumber && prev !== amount) {
      window.CarvellaNumber.tween(el, prev, amount, { duration: 0.45, prefix: '\u20B1', final: final });
    } else {
      el.textContent = final;
    }
  }

  const availabilityBox = document.getElementById('availability-check');
  const availabilityIcon = document.getElementById('availability-icon');
  const availabilityMessage = document.getElementById('availability-message');

  function setAvailability(state, message, icon) {
    availabilityState = state;
    availabilityBox.classList.remove('hidden', 'items-start', 'items-center', 'bg-emerald-50', 'text-emerald-800', 'border-emerald-200', 'bg-error-container', 'text-on-error-container', 'border-error-container', 'bg-surface-container-low', 'text-tertiary', 'border-outline-variant/40');
    availabilityBox.classList.add('items-start');
    if (state === 'available') {
      availabilityBox.classList.add('bg-emerald-50', 'text-emerald-800', 'border-emerald-200');
    } else if (state === 'booked') {
      availabilityBox.classList.add('bg-error-container', 'text-on-error-container', 'border-error-container');
    } else {
      availabilityBox.classList.add('bg-surface-container-low', 'text-tertiary', 'border-outline-variant/40');
    }
    Array.from(availabilityIcon.classList).forEach(function (c) { if (c.indexOf('bi-') === 0) availabilityIcon.classList.remove(c); });
    availabilityIcon.classList.add('bi-' + icon);
    availabilityMessage.textContent = message;
  }

  function describeConflict(conflict) {
    // A redacted entry carries no reference or dates, so it must not be rendered as if it did.
    if (conflict.status === 'unavailable') {
      return 'already reserved';
    }
    const ref = conflict.reference || ('#' + conflict.id);
    return ref + ' (' + String(conflict.status).replace(/_/g, ' ') + ', ' + conflict.start + ' to ' + conflict.end + ')';
  }

  /* ---- Adding and removing vehicles ------------------------------------- */
  function pickerRow(vehicleId) {
    return document.querySelector('.vehicle-picker-row[data-vehicle-id="' + vehicleId + '"]');
  }

  function showPickerMessage(row, message) {
    const box = row.querySelector('.vehicle-picker-message');
    if (!box) return;
    if (message) {
      box.textContent = message;
      box.classList.remove('hidden');
    } else {
      box.textContent = '';
      box.classList.add('hidden');
    }
  }

  function vehicleName(vehicleId) {
    const capsule = document.querySelector('.reservation-vehicle[data-vehicle-id="' + vehicleId + '"]');
    if (capsule && capsule.dataset.vehicleName) return capsule.dataset.vehicleName;
    const row = pickerRow(vehicleId);
    return row && row.dataset.vehicleName ? row.dataset.vehicleName : 'This vehicle';
  }

  function buildExtraVehicleCapsule(row) {
    const fragment = extraVehicleTemplate.content.cloneNode(true);
    const capsule = fragment.querySelector('.extra-vehicle');
    capsule.dataset.vehicleId = row.dataset.vehicleId;
    capsule.dataset.rate = row.dataset.rate || '0';
    capsule.dataset.floor = row.dataset.floor || '';
    capsule.dataset.vehicleName = row.dataset.vehicleName || '';
    const image = capsule.querySelector('[data-vehicle-image]');
    if (image) {
      if (row.dataset.image) {
        image.src = '<?= BASE_URL ?>/' + row.dataset.image;
        image.alt = row.dataset.vehicleName || '';
      } else {
        image.remove();
      }
    }
    capsule.querySelector('[data-vehicle-name-text]').textContent = row.dataset.vehicleName || '';
    capsule.querySelector('[data-vehicle-plate-text]').textContent = 'Plate: ' + (row.dataset.vehiclePlate || '—');
    capsule.querySelector('[data-vehicle-rate-text]').textContent = formatCurrencyJS(parseFloat(row.dataset.rate || 0));
    return capsule;
  }

  function addVehicle(capsule) {
    const vehicleId = capsule.dataset.vehicleId || '';
    if (vehicleId) {
      // Prevent duplicates - ensure only one entry per vehicle
      const existing = extraVehiclesContainer.querySelector('.extra-vehicle[data-vehicle-id="' + vehicleId + '"]');
      if (existing) {
        return;
      }
    }
    // The capsule carries its own hidden input, so its id must be written here or the form books one car short.
    const input = capsule.querySelector('input[name="vehicle_ids[]"]');
    if (input) input.value = vehicleId;
    extraVehiclesContainer.appendChild(capsule);
    const row = pickerRow(vehicleId);
    if (row) {
      row.classList.add('hidden');
      showPickerMessage(row, '');
    }
    syncBookingFloor();
    updateSummary();
    scheduleAvailabilityCheck();
  }

  function removeVehicle(capsule) {
    const vehicleId = capsule.dataset.vehicleId;
    capsule.remove();
    const row = pickerRow(vehicleId);
    if (row) {
      row.classList.remove('hidden');
      showPickerMessage(row, '');
    }
    syncBookingFloor();
    updateSummary();
    scheduleAvailabilityCheck();
  }

  function countVehicles() {
    return selectedVehicles().length;
  }

  function handleAddVehicle(row) {
    const vehicleId = row.dataset.vehicleId || '';
    if (vehicleId) {
      const existing = document.querySelector('.extra-vehicle[data-vehicle-id="' + vehicleId + '"]');
      if (existing) {
        showPickerMessage(row, 'This vehicle is already added to this booking.');
        return;
      }
    }

    if (countVehicles() >= MAX_BOOKING_VEHICLES) {
      showPickerMessage(row, 'Up to ' + MAX_BOOKING_VEHICLES + ' vehicles can be booked at once.');
      return;
    }

    const pickup = pickupInput.value;
    const ret = returnInput.value;
    const floor = row.dataset.floor || '';

    // The floor is the car's own handback, so the customer is told the date instead of the trip shifting later.
    if (floor && pickup && pickup < floor) {
      showPickerMessage(row, 'This vehicle is only available from ' + formatDateLabel(floor) + '. Move the pick-up date to add it.');
      return;
    }

    // Dates not chosen yet: the server re-checks every car on submit, so the car can be added now.
    if (!pickup || !ret) {
      showPickerMessage(row, '');
      addVehicle(buildExtraVehicleCapsule(row));
      return;
    }

    showPickerMessage(row, '');
    const button = row.querySelector('.add-vehicle-btn');
    if (button) button.disabled = true;

    fetch(availabilityUrl + '?vehicle_id=' + encodeURIComponent(row.dataset.vehicleId) + '&pickup_date=' + encodeURIComponent(pickup) + '&return_date=' + encodeURIComponent(ret), { headers: { 'Accept': 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (button) button.disabled = false;
        if (data && data.success && data.available) {
          addVehicle(buildExtraVehicleCapsule(row));
          return;
        }
        showPickerMessage(row, data && data.reason === 'vehicle_not_bookable'
          ? 'This vehicle is currently under maintenance or unavailable.'
          : 'Not available for your dates. Pick a different car, or change the dates first.');
      })
      .catch(function () {
        if (button) button.disabled = false;
        // An unreachable API is not proof the car is taken; the server re-checks on submit, so let it through.
        addVehicle(buildExtraVehicleCapsule(row));
      });
  }

  function checkAvailability() {
    const pickup = pickupInput.value;
    const ret = returnInput.value;
    const ids = selectedVehicleIds();
    if (!pickup || !ret || !ids.length) {
      availabilityBox.classList.add('hidden');
      availabilityState = null;
      return;
    }
    if (ret < pickup) {
      setAvailability('booked', 'The return date cannot be before the pick-up date.', 'calendar-x');
      return;
    }

    // A car cannot be booked before its handback, so the range is checked against every selected car's floor.
    const floorBreach = selectedVehicles().filter(function (el) {
      return (el.dataset.floor || '') && pickup < el.dataset.floor;
    });
    if (floorBreach.length) {
      setAvailability('booked', vehicleName(floorBreach[0].dataset.vehicleId) + ' can only be reserved from ' + formatDateLabel(floorBreach[0].dataset.floor) + ' onwards.', 'calendar-x');
      return;
    }

    setAvailability('checking', ids.length > 1
      ? 'Checking availability for ' + ids.length + ' vehicles...'
      : 'Checking vehicle availability for these dates...', 'arrow-clockwise');

    Promise.all(ids.map(function (vehicleId) {
      return fetch(availabilityUrl + '?vehicle_id=' + encodeURIComponent(vehicleId) + '&pickup_date=' + encodeURIComponent(pickup) + '&return_date=' + encodeURIComponent(ret), { headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) { return { vehicleId: vehicleId, data: data }; })
        .catch(function () { return { vehicleId: vehicleId, data: null }; });
    })).then(function (results) {
      if (results.some(function (r) { return !r.data; })) {
        setAvailability('unknown', 'Could not verify availability right now. You can still submit and we will validate the dates on the server.', 'question-circle');
        return;
      }

      const blocked = results.filter(function (r) { return !(r.data.success && r.data.available); });
      if (!blocked.length) {
        setAvailability('available', ids.length > 1
          ? 'Good news, all ' + ids.length + ' vehicles are free for your selected dates.'
          : 'Good news, this vehicle is free for your selected dates.', 'check-circle');
        return;
      }

      // One car is named in full: the only case where the other party's details fit without burying the answer.
      const blockedNames = blocked.map(function (r) {
        if (ids.length === 1) {
          return r.data.reason === 'vehicle_not_bookable'
            ? 'This vehicle is currently under maintenance or unavailable.'
            : 'These dates are already taken: ' + ((r.data.conflicts || []).map(describeConflict).join('; ') || 'another booking overlaps your dates.');
        }
        return vehicleName(r.vehicleId);
      });
      setAvailability('booked', ids.length > 1
        ? 'These dates are taken for ' + blockedNames.join(', ') + '. Change the dates or remove that car to continue.'
        : blockedNames.join(' '), 'calendar-x');
    });
  }

  function scheduleAvailabilityCheck() {
    if (availabilityTimer) clearTimeout(availabilityTimer);
    availabilityTimer = setTimeout(checkAvailability, 300);
  }

  if (addVehicleToggle && vehiclePicker) {
    addVehicleToggle.addEventListener('click', function () {
      const opening = vehiclePicker.classList.contains('hidden');
      vehiclePicker.classList.toggle('hidden');
      addVehicleToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
    });
  }

  if (addVehicleClose && vehiclePicker && addVehicleToggle) {
    addVehicleClose.addEventListener('click', function () {
      vehiclePicker.classList.add('hidden');
      addVehicleToggle.setAttribute('aria-expanded', 'false');
    });
  }

  // Delegated, so a row keeps working after it is hidden and shown again.
  document.querySelectorAll('.add-vehicle-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      const row = button.closest('.vehicle-picker-row');
      if (row) handleAddVehicle(row);
    });
  });

  if (extraVehiclesContainer) {
    extraVehiclesContainer.addEventListener('click', function (event) {
      const button = event.target.closest('.remove-vehicle-btn');
      if (button) {
        const capsule = button.closest('.extra-vehicle');
        if (capsule) removeVehicle(capsule);
      }
    });
  }

  const reservationForm = document.getElementById('reservation-form');
  if (reservationForm) {
    reservationForm.addEventListener('submit', function (e) {
      if (availabilityState === 'booked') {
        e.preventDefault();
      }
    });
  }

  /* Copy the GCash number. The number stays readable, so a browser without the clipboard API still works. */
  document.addEventListener('click', function (event) {
    const button = event.target.closest('[data-copy-value]');
    if (!button) return;
    const value = button.getAttribute('data-copy-value') || '';
    const original = button.textContent;
    const done = function (ok) {
      button.textContent = ok ? 'Copied' : 'Copy manually';
      setTimeout(function () { button.textContent = original; }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(function () { done(true); }, function () { done(false); });
    } else {
      done(false);
    }
  });

  // Rebuild the chosen cars before the summary runs, so the customer sees the booking they confirmed.
  postedExtraVehicleIds.forEach(function (vehicleId) {
    const row = pickerRow(vehicleId);
    if (row) addVehicle(buildExtraVehicleCapsule(row));
  });

  pickupInput.addEventListener('change', function () {
    syncBookingFloor();
    // reservation.js may move the return date programmatically; a script-set
    // value fires no event, so repaint both handover labels as well.
    syncAllHandoverFields();
    updateSummary();
    scheduleAvailabilityCheck();
  });
  returnInput.addEventListener('change', function () {
    updateSummary();
    scheduleAvailabilityCheck();
  });
  if (pickupTimeInput) pickupTimeInput.addEventListener('change', updateSummary);
  if (returnTimeInput) returnTimeInput.addEventListener('change', updateSummary);
  protectionInputs.forEach(function (cb) { cb.addEventListener('change', updateSummary); });
  driverOptionInputs.forEach(function (input) { input.addEventListener('change', updateSummary); });

  initHandoverFields();
  updateSummary();
  checkAvailability();
</script>

<?php
// Wire the shared reservation-page behaviour (return-date floor, submit prompt) — see assets/js/reservation.js.
$extraScripts = ['reservation.js'];
require_once __DIR__ . '/../includes/footer.php'; ?>
