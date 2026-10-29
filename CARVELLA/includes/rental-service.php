<?php
/* includes/rental-service.php - Rental and reservation service functions. */
/* Include-once sentinel: PHP binds top-level functions at compile time, so only a constant can guard this file. */
if (defined('CARVELLA_RENTAL_SERVICE_LOADED')) {
    return;
}
define('CARVELLA_RENTAL_SERVICE_LOADED', true);

if (!defined('RENTAL_BLOCKING_RESERVATION_STATUSES')) {
    define('RENTAL_BLOCKING_RESERVATION_STATUSES', ['pending', 'confirmed', 'active']);
}
if (!defined('RENTAL_BLOCKING_RENTAL_STATUSES')) {
    define('RENTAL_BLOCKING_RENTAL_STATUSES', ['active', 'in_progress', 'overdue']);
}
/* Same value as config/config.php, guarded the same way so a bare include of this file cannot fatal. */
if (!defined('CARVELLA_HUB')) {
    define('CARVELLA_HUB', 'AB Fernandez Ave Central Hub, Dagupan City');
}

function rentalTrackingTableExists($conn, $table) {
    $table = (string)$table;
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $found = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();
    return $found;
}

function rentalTrackingColumnExists($conn, $table, $column) {
    $table = (string)$table;
    $column = (string)$column;
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $found = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();
    return $found;
}

/* Schema ownership: schema lives only in database/carvella.sql; runtime CREATE/ALTER was removed for table locks. */

/** Missing columns a pre-migration database lacks - reported, not repaired, so an un-migrated install fails with a list. */
function rentalTrackingSchemaGaps($conn) {
    $required = [
        'rentals'     => ['odometer_start', 'odometer_end', 'late_fee', 'notes', 'dispatched_at', 'returned_at'],
        'reservations'=> ['pickup_time', 'return_time',
                          'payment_method', 'payment_reference', 'payment_amount',
                          'payment_status', 'payment_verified_at', 'payment_verified_by'],
    ];
    $gaps = [];
    foreach ($required as $table => $columns) {
        foreach ($columns as $column) {
            if (!rentalTrackingColumnExists($conn, $table, $column)) {
                $gaps[] = "$table.$column";
            }
        }
    }
    if (!rentalTrackingTableExists($conn, 'rental_transactions')) {
        $gaps[] = 'rental_transactions (missing table)';
    }
    return $gaps;
}

/** Ready = the ledger exists and carries every column the app writes; read-only, it never alters the schema. */
function rentalTrackingSchemaReady($conn = null) {
    static $gaps = null;
    if ($gaps !== null) {
        return $gaps === [];
    }
    if ($conn === null) {
        global $conn;
    }
    if (!($conn instanceof mysqli)) {
        return false;
    }
    $gaps = rentalTrackingSchemaGaps($conn);
    if ($gaps !== []) {
        error_log(
            'carvella: rental ledger schema is out of date, missing '
            . implode(', ', $gaps)
            . ' - run database/carvella.sql against this database'
        );
    }
    return $gaps === [];
}

function rentalTrackingActorId() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function recordRentalEvent($conn, array $event) {
    if (!rentalTrackingSchemaReady($conn)) {
        return false;
    }

    $rentalId = !empty($event['rental_id']) ? (int)$event['rental_id'] : null;
    $reservationId = !empty($event['reservation_id']) ? (int)$event['reservation_id'] : null;
    $vehicleId = (int)($event['vehicle_id'] ?? 0);
    $userId = !empty($event['user_id']) ? (int)$event['user_id'] : null;
    $eventType = (string)($event['event_type'] ?? 'unknown');
    $fromStatus = isset($event['from_status']) && $event['from_status'] !== '' ? (string)$event['from_status'] : null;
    $toStatus = isset($event['to_status']) && $event['to_status'] !== '' ? (string)$event['to_status'] : null;
    $pickupDate = !empty($event['pickup_date']) ? (string)$event['pickup_date'] : null;
    $expectedReturn = !empty($event['expected_return_date']) ? (string)$event['expected_return_date'] : null;
    $actualReturn = !empty($event['actual_return_date']) ? (string)$event['actual_return_date'] : null;
    $amount = isset($event['amount']) ? (float)$event['amount'] : 0.0;
    $odometer = isset($event['odometer']) && $event['odometer'] !== '' && $event['odometer'] !== null ? (int)$event['odometer'] : null;
    $notes = isset($event['notes']) && $event['notes'] !== '' ? (string)$event['notes'] : null;
    $actorId = array_key_exists('actor_id', $event) ? ($event['actor_id'] !== null ? (int)$event['actor_id'] : null) : rentalTrackingActorId();

    $stmt = $conn->prepare("INSERT INTO rental_transactions (rental_id, reservation_id, vehicle_id, user_id, event_type, from_status, to_status, pickup_date, expected_return_date, actual_return_date, amount, odometer, notes, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    // The bind_param type string must be exactly as long as the placeholder list, or mysqli throws ArgumentCountError.
    $stmt->bind_param("iiiissssssdisi", $rentalId, $reservationId, $vehicleId, $userId, $eventType, $fromStatus, $toStatus, $pickupDate, $expectedReturn, $actualReturn, $amount, $odometer, $notes, $actorId);
    $ok = $stmt->execute();
    if (!$ok) {
        $stmt->close();
        return false;
    }
    $stmt->close();
    return true;
}

function rentalDateIsValid($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $parsed = DateTime::createFromFormat('Y-m-d', $value);
    return $parsed !== false && $parsed->format('Y-m-d') === $value;
}

/** Handover clock times are stored as HH:MM, matching what <input type="time"> submits. */
function rentalTimeIsValid($value) {
    if (!is_string($value) || !preg_match('/^\d{2}:\d{2}$/', $value)) {
        return false;
    }
    $parsed = DateTime::createFromFormat('H:i', $value);
    return $parsed !== false && $parsed->format('H:i') === $value;
}

function rentalDateRangeIsValid($pickupDate, $returnDate) {
    if (!rentalDateIsValid($pickupDate) || !rentalDateIsValid($returnDate)) {
        return false;
    }
    // Same-day rentals are allowed and billed as one day; only a return before the pickup is impossible.
    return $returnDate >= $pickupDate;
}

function rentalDateIsPast($value) {
    if (!rentalDateIsValid($value)) {
        return true;
    }
    return $value < date('Y-m-d');
}

function rentalBookingReference($prefix = 'RES') {
    return function_exists('generateId')
        ? generateId($prefix)
        : $prefix . strtoupper(bin2hex(random_bytes(4))) . mt_rand(100, 999);
}

function rentalRentalPeriodEnd($row) {
    if (!empty($row['actual_return_date'])) {
        return (string)$row['actual_return_date'];
    }
    return (string)$row['expected_return_date'];
}

function findConflictingBookings($conn, $vehicleId, $pickupDate, $returnDate, $excludeReservationId = null, $excludeRentalId = null) {
    $vehicleId = (int)$vehicleId;
    $conflicts = [];

    // Overlap is half-open, so a same-day booking is widened to one day and still occupies the day it is on.
    $rangeEnd = $returnDate;
    if (rentalDateIsValid($pickupDate) && rentalDateIsValid($returnDate) && $returnDate <= $pickupDate) {
        $rangeEnd = date('Y-m-d', strtotime($pickupDate . ' +1 day'));
    }

    // A dispatched reservation and its rental row are the SAME booking, so the rental window supersedes it.
    $sql = "SELECT id, booking_reference, status, pickup_date, return_date FROM reservations
            WHERE vehicle_id = ? AND status IN ('pending','confirmed','active')
              AND NOT (
                  status = 'active'
                  AND EXISTS (
                      SELECT 1 FROM rentals ren
                      WHERE ren.reservation_id = reservations.id
                        AND ren.status IN ('pending','active','in_progress','overdue')
                  )
              )
              AND pickup_date < ? AND GREATEST(return_date, DATE_ADD(pickup_date, INTERVAL 1 DAY)) > ?";
    $params = [$vehicleId, $rangeEnd, $pickupDate];
    $types = "iss";
    if ($excludeReservationId) {
        $sql .= " AND id <> ?";
        $params[] = (int)$excludeReservationId;
        $types .= "i";
    }
    $sql .= " ORDER BY pickup_date";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $conflicts[] = [
            'source' => 'reservation',
            'id' => (int)$row['id'],
            'reference' => $row['booking_reference'],
            'status' => $row['status'],
            'start' => $row['pickup_date'],
            'end' => $row['return_date'],
        ];
    }
    $stmt->close();

    $rentalSql = "SELECT id, reservation_id, status, pickup_date, expected_return_date, actual_return_date FROM rentals
                  WHERE vehicle_id = ? AND status IN ('pending','active','in_progress','overdue')
                    AND pickup_date < ? AND GREATEST(COALESCE(actual_return_date, expected_return_date), DATE_ADD(pickup_date, INTERVAL 1 DAY)) > ?";
    $rentalParams = [$vehicleId, $rangeEnd, $pickupDate];
    $rentalTypes = "iss";
    if ($excludeRentalId) {
        $rentalSql .= " AND id <> ?";
        $rentalParams[] = (int)$excludeRentalId;
        $rentalTypes .= "i";
    }
    $rentalSql .= " ORDER BY pickup_date";

    $stmt = $conn->prepare($rentalSql);
    $stmt->bind_param($rentalTypes, ...$rentalParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $conflicts[] = [
            'source' => 'rental',
            'id' => (int)$row['id'],
            'reference' => 'Rental #' . (int)$row['id'],
            'status' => $row['status'],
            'start' => $row['pickup_date'],
            'end' => rentalRentalPeriodEnd($row),
        ];
    }
    $stmt->close();

    return $conflicts;
}

function vehicleHasBookingConflict($conn, $vehicleId, $pickupDate, $returnDate, $excludeReservationId = null, $excludeRentalId = null) {
    $conflicts = findConflictingBookings($conn, $vehicleId, $pickupDate, $returnDate, $excludeReservationId, $excludeRentalId);
    return !empty($conflicts);
}

function vehicleBookableStatuses($conn, $vehicleId) {
    $vehicleId = (int)$vehicleId;
    $stmt = $conn->prepare("SELECT status FROM vehicles WHERE id = ?");
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return [];
    }
    $status = strtolower((string)$row['status']);
    return in_array($status, ['available', 'rented'], true) ? ['available', 'rented'] : [];
}

function vehicleIsAvailableForDates($conn, $vehicleId, $pickupDate, $returnDate, $excludeReservationId = null) {
    if (!rentalDateRangeIsValid($pickupDate, $returnDate)) {
        return ['available' => false, 'reason' => 'invalid_dates', 'conflicts' => []];
    }
    if (empty(vehicleBookableStatuses($conn, $vehicleId))) {
        return ['available' => false, 'reason' => 'vehicle_not_bookable', 'conflicts' => []];
    }
    $conflicts = findConflictingBookings($conn, $vehicleId, $pickupDate, $returnDate, $excludeReservationId);
    if (!empty($conflicts)) {
        return ['available' => false, 'reason' => 'date_conflict', 'conflicts' => $conflicts];
    }
    return ['available' => true, 'reason' => 'ok', 'conflicts' => []];
}

function vehicleUnavailabilityWindows($conn, $vehicleId) {
    $windows = [];
    $vehicleId = (int)$vehicleId;

    // Same rule as findConflictingBookings(): a dispatched reservation's rental row is the authoritative window.
    $stmt = $conn->prepare("SELECT id, booking_reference, status, pickup_date, return_date FROM reservations WHERE vehicle_id = ? AND status IN ('pending','confirmed','active') AND NOT (status = 'active' AND EXISTS (SELECT 1 FROM rentals ren WHERE ren.reservation_id = reservations.id AND ren.status IN ('pending','active','in_progress','overdue'))) ORDER BY pickup_date");
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $windows[] = [
            'source' => 'reservation',
            'id' => (int)$row['id'],
            'reference' => $row['booking_reference'],
            'status' => $row['status'],
            'start' => $row['pickup_date'],
            'end' => $row['return_date'],
        ];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, status, pickup_date, expected_return_date, actual_return_date FROM rentals WHERE vehicle_id = ? AND status IN ('pending','active','in_progress','overdue') ORDER BY pickup_date");
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $windows[] = [
            'source' => 'rental',
            'id' => (int)$row['id'],
            'reference' => 'Rental #' . (int)$row['id'],
            'status' => $row['status'],
            'start' => $row['pickup_date'],
            'end' => rentalRentalPeriodEnd($row),
        ];
    }
    $stmt->close();

    usort($windows, function ($a, $b) {
        return strcmp($a['start'], $b['start']);
    });

    return $windows;
}

/** First day at or after $from this vehicle is free for, using the server's own overlap test so it cannot drift. */
function vehicleFirstFreeDate($conn, $vehicleId, $from) {
    $from = is_string($from) && $from !== '' ? $from : date('Y-m-d');
    for ($walk = 0; $walk < 60; $walk++) {
        $free = vehicleIsAvailableForDates($conn, $vehicleId, $from, $from);
        if (!empty($free['available'])) {
            break;
        }
        if (($free['reason'] ?? '') !== 'date_conflict') {
            break; // not a date problem (unavailable/invalid) - nothing to walk past
        }
        $blockedUntil = '';
        foreach (($free['conflicts'] ?? []) as $conflict) {
            if (!empty($conflict['end']) && $conflict['end'] > $from) {
                $blockedUntil = max($blockedUntil, (string)$conflict['end']);
            }
        }
        $from = $blockedUntil !== ''
            ? $blockedUntil
            : date('Y-m-d', strtotime($from . ' +1 day'));
    }
    return $from;
}

/** Earliest reservable date for this vehicle - one helper so the form, picker and POST guard cannot disagree. */
function vehicleBookableFromDate($conn, $vehicleId) {
    $vehicleId = (int)$vehicleId;
    $today = date('Y-m-d');

    // Not restricted to a window covering today: a car spoken for on the 31st still reports that booking's handback.
    $handback = '';
    foreach (vehicleUnavailabilityWindows($conn, $vehicleId) as $window) {
        if ($window['end'] >= $today
            && ($handback === '' || $window['end'] < $handback)) {
            $handback = $window['end'];
        }
    }

    // The handback is the floor only when it is later than the first physically free day.
    $earliest = vehicleFirstFreeDate($conn, $vehicleId, $today);
    $target = $handback !== '' && $handback > $earliest ? $handback : $earliest;

    // A handback another booking immediately claims hands over on that booking's end date, not on a taken day.
    return vehicleFirstFreeDate($conn, $vehicleId, $target);
}

function vehicleHasOpenMaintenance($conn, $vehicleId) {
    $vehicleId = (int)$vehicleId;
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM maintenance WHERE vehicle_id = ? AND status <> 'completed'");
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $open = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();
    return $open;
}

/** True when the unit is out on an open rental now, not merely reserved - the two both land on 'rented'. */
function vehicleIsOnRoad($conn, $vehicleId) {
    $vehicleId = (int)$vehicleId;
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM rentals WHERE vehicle_id = ? AND status IN ('active','in_progress','overdue')");
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $onRoad = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();
    return $onRoad;
}

/** The rental holding this unit now and its handback day, read from the rental row so an overdue car still counts as out. */
function vehicleCurrentRental($conn, $vehicleId) {
    $result = ['on_road' => false, 'handback' => '', 'rental_id' => 0, 'status' => '', 'overdue' => false];
    $vehicleId = (int)$vehicleId;
    $today = date('Y-m-d');

    // Only a rental that has already been collected holds the car: a pending rental is a booking, not a car out.
    $stmt = $conn->prepare("SELECT id, status, pickup_date, expected_return_date FROM rentals
        WHERE vehicle_id = ? AND status IN ('pending','active','in_progress','overdue')
          AND actual_return_date IS NULL AND pickup_date <= ?
        ORDER BY pickup_date DESC, id DESC");
    if ($stmt === false) {
        return $result;
    }
    $stmt->bind_param('is', $vehicleId, $today);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return $result;
    }

    $handback = (string)($row['expected_return_date'] ?? '');
    if (!rentalDateIsValid($handback)) {
        $pickedUp = (string)($row['pickup_date'] ?? '');
        $handback = rentalDateIsValid($pickedUp)
            ? date('Y-m-d', strtotime($pickedUp . ' +1 day'))
            : $today;
    }

    $result['on_road'] = true;
    $result['handback'] = $handback;
    $result['rental_id'] = (int)$row['id'];
    $result['status'] = (string)$row['status'];
    $result['overdue'] = $handback < $today;
    return $result;
}

function refreshVehicleAvailabilityStatus($conn, $vehicleId, $manualStatus = null) {
    $vehicleId = (int)$vehicleId;
    $stmt = $conn->prepare("SELECT status FROM vehicles WHERE id = ?");
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    $current = strtolower((string)$row['status']);

    // 'archived', 'unavailable' and 'maintenance' are deliberate parks and are never
    // auto-reversed by a status refresh, unless the car is actually on the road.
    if (in_array($current, ['archived', 'unavailable', 'maintenance'], true) && !$onRoad) {
        return $current;
    }
    if ($current === 'archived') {
        return $current;
    }

    $onRoad = vehicleIsOnRoad($conn, $vehicleId);

    // An approved but uncollected reservation pins the fleet status; a booking whose whole window is in the past does not.
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM reservations WHERE vehicle_id = ? AND status IN ('confirmed','active') AND return_date >= CURDATE()");
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $reserved = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $stmt->close();

    // A manual status pick from the admin form outranks the derived status.
    $manual = is_string($manualStatus) ? strtolower(trim($manualStatus)) : '';
    if (in_array($manual, ['available', 'maintenance', 'unavailable'], true)) {
        // Respect the admin's explicit choice, unless the car is actually on the road.
        if ($onRoad && $manual !== 'maintenance') {
            $next = 'rented';
        } else {
            $next = $manual;
        }
    } else {
        // No manual choice (or 'rented'): derive from ledger state.
        $openMaintenance = $onRoad ? false : vehicleHasOpenMaintenance($conn, $vehicleId);
        if ($onRoad) {
            $next = 'rented';
        } elseif ($openMaintenance) {
            $next = 'maintenance';
        } elseif ($reserved) {
            // Approved but not yet collected: the dates are spoken for, so the unit is held out of the available pool.
            $next = 'rented';
        } else {
            $next = 'available';
        }
    }

    // 'unavailable' is a deliberate park and is never auto-reversed by a status refresh, unless on the road.
    if ($current === 'unavailable' && !$onRoad && $manual === '') {
        return $current;
    }

    if ($next !== $current) {
        $update = $conn->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
        $update->bind_param("si", $next, $vehicleId);
        $update->execute();
        $update->close();
    }

    return $next;
}

/** Flag rentals past their expected return date as overdue; only the caller whose UPDATE took effect logs the ledger. */
function flagOverdueRentals($conn) {
    $stmt = $conn->prepare("SELECT id, vehicle_id, reservation_id, status, expected_return_date
                              FROM rentals
                             WHERE status IN ('active','in_progress')
                               AND expected_return_date < CURDATE()");
    if (!$stmt) {
        return 0;
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $overdue = [];
    while ($row = $result->fetch_assoc()) {
        $overdue[] = $row;
    }
    $stmt->close();

    $flipped = 0;
    $update = $conn->prepare(
        "UPDATE rentals SET status = 'overdue'
          WHERE id = ?
            AND status IN ('active','in_progress')
            AND expected_return_date < CURDATE()"
    );
    if (!$update) {
        return 0;
    }

    foreach ($overdue as $row) {
        $overdueRentalId = (int)$row['id'];
        $update->bind_param("i", $overdueRentalId);
        $update->execute();
        if ($update->affected_rows !== 1) {
            // Someone else got there first, or the rental was closed, so this call did not transition it and logs nothing.
            continue;
        }
        $flipped++;

        recordRentalEvent($conn, [
            'rental_id' => $overdueRentalId,
            'reservation_id' => (int)$row['reservation_id'],
            'vehicle_id' => (int)$row['vehicle_id'],
            'event_type' => 'rental_overdue',
            'from_status' => (string)$row['status'],
            'to_status' => 'overdue',
            'expected_return_date' => $row['expected_return_date'],
            'notes' => 'Automatically flagged because the expected return date has passed',
        ]);
    }
    $update->close();

    return $flipped;
}

function lockVehicleForSchedule($conn, $vehicleId) {
    $vehicleId = (int)$vehicleId;
    $stmt = $conn->prepare("SELECT * FROM vehicles WHERE id = ? FOR UPDATE");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $vehicleId);
    $stmt->execute();
    $vehicle = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $vehicle;
}

function createReservationRecord($conn, array $data) {
    $vehicleId = (int)($data['vehicle_id'] ?? 0);
    $userId = (int)($data['user_id'] ?? 0);
    $pickupDate = trim((string)($data['pickup_date'] ?? ''));
    $returnDate = trim((string)($data['return_date'] ?? ''));
    /* Callers that have no clock picker (the counter's walk-in form) keep the published hub windows. */
    $pickupTime = trim((string)($data['pickup_time'] ?? ''));
    $returnTime = trim((string)($data['return_time'] ?? ''));
    $pickupTime = $pickupTime !== '' ? $pickupTime : '10:00';
    $returnTime = $returnTime !== '' ? $returnTime : '18:00';
    // Single-hub operation: the caller's location is ignored and replaced with the one terminal we operate.
    $pickupLocation = CARVELLA_HUB;
    $returnLocation = CARVELLA_HUB;
    $licenseNo = trim((string)($data['license_no'] ?? ''));
    $protection = trim((string)($data['protection'] ?? ''));
    // A protection plan can only add to the bill; a negative fee would otherwise discount the reservation.
    $protectionFee = max(0.0, (float)($data['protection_fee'] ?? 0));
    $notes = trim((string)($data['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;
    $requestedStatus = (string)($data['status'] ?? 'pending');
    $initialStatus = in_array($requestedStatus, ['pending', 'confirmed'], true) ? $requestedStatus : 'pending';
    $excludeReservationId = !empty($data['exclude_reservation_id']) ? (int)$data['exclude_reservation_id'] : null;

    if ($vehicleId <= 0 || $userId <= 0) {
        return ['success' => false, 'error' => 'A vehicle and a customer are required'];
    }
    if ($pickupDate < date('Y-m-d')) {
        return ['success' => false, 'error' => 'Pickup date cannot be in the past'];
    }
    if (!rentalDateRangeIsValid($pickupDate, $returnDate)) {
        return ['success' => false, 'error' => 'Return date must be after the pickup date'];
    }
    if (!rentalTimeIsValid($pickupTime) || !rentalTimeIsValid($returnTime)) {
        return ['success' => false, 'error' => 'Pickup and return times must use the HH:MM clock format'];
    }
    // A same-day rental still needs a positive window: you cannot hand the car back before taking it.
    if ($pickupDate === $returnDate && $returnTime <= $pickupTime) {
        return ['success' => false, 'error' => 'Return time must be after the pickup time'];
    }

    // Confirm the renter exists and may book, otherwise the INSERT fails on the foreign key with a generic error.
    $renterStmt = $conn->prepare("SELECT id, status FROM users WHERE id = ? LIMIT 1");
    $renterStmt->bind_param("i", $userId);
    $renterStmt->execute();
    $renter = $renterStmt->get_result()->fetch_assoc();
    $renterStmt->close();
    if (!$renter) {
        return ['success' => false, 'error' => 'Customer not found'];
    }
    if (($renter['status'] ?? 'active') !== 'active') {
        return ['success' => false, 'error' => 'This account is inactive and cannot book'];
    }

    rentalTrackingSchemaReady($conn);
    $conn->begin_transaction();
    try {
        $vehicle = lockVehicleForSchedule($conn, $vehicleId);
        if (!$vehicle) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Vehicle not found'];
        }
        if (!in_array(strtolower((string)$vehicle['status']), ['available', 'rented'], true)) {
            $conn->rollback();
            return ['success' => false, 'error' => 'This vehicle is not available for reservation'];
        }

        $conflicts = findConflictingBookings($conn, $vehicleId, $pickupDate, $returnDate, $excludeReservationId);
        if (!empty($conflicts)) {
            $conn->rollback();
            return ['success' => false, 'error' => 'These dates are already booked for this vehicle', 'conflicts' => $conflicts];
        }

        $days = rentalDayCount($pickupDate, $returnDate);
        $totalAmount = ($days * (float)$vehicle['daily_rate']) + $protectionFee;
        $bookingReference = rentalBookingReference('RES');

        // Payment is collected in cash at the counter on pickup, so the booking is written with no wallet capture.
        $stmt = $conn->prepare("INSERT INTO reservations (booking_reference, user_id, vehicle_id, pickup_date, pickup_time, return_date, return_time, pickup_location, return_location, license_no, protection, protection_fee, total_amount, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        // 15 placeholders: s ii ssssssss dd ss
        $stmt->bind_param("siissssssssddss", $bookingReference, $userId, $vehicleId, $pickupDate, $pickupTime, $returnDate, $returnTime, $pickupLocation, $returnLocation, $licenseNo, $protection, $protectionFee, $totalAmount, $initialStatus, $notes);
        if (!$stmt->execute()) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Failed to create reservation'];
        }
        $reservationId = (int)$stmt->insert_id;
        $stmt->close();

        recordRentalEvent($conn, [
            'reservation_id' => $reservationId,
            'vehicle_id' => $vehicleId,
            'user_id' => $userId,
            'event_type' => 'reservation_created',
            'from_status' => null,
            'to_status' => $initialStatus,
            'pickup_date' => $pickupDate,
            'expected_return_date' => $returnDate,
            'amount' => $totalAmount,
            'notes' => $notes,
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Failed to create reservation'];
    }

    // A 'confirmed' reservation locks its dates at once, so the fleet status must follow it or the catalog offers taken dates.
    refreshVehicleAvailabilityStatus($conn, $vehicleId);

    return [
        'success' => true,
        'reservation_id' => $reservationId,
        'booking_reference' => $bookingReference,
        'total_amount' => $totalAmount,
        'days' => $days,
    ];
}

/** Is this a GCash reference number? GCash prints 13 digits on the receipt, so the shape is checked here to catch typos early. */
function rentalGcashReferenceIsValid($reference) {
    return (bool)preg_match('/^\d{13}$/', trim((string)$reference));
}

/** Attach a GCash deposit to one booking, split across its rows by cost (remainder to the largest row), written as 'pending'. */
function applyReservationDeposit($conn, array $reservationIds, $amount, $reference) {
    $amount = round(max(0.0, (float)$amount), 2);
    $reference = trim((string)$reference);
    $ids = [];
    foreach ($reservationIds as $reservationId) {
        $reservationId = (int)$reservationId;
        if ($reservationId > 0 && !in_array($reservationId, $ids, true)) {
            $ids[] = $reservationId;
        }
    }

    if ($ids === []) {
        return ['success' => false, 'error' => 'There is no booking to attach the deposit to'];
    }
    if ($amount <= 0) {
        return ['success' => false, 'error' => 'The deposit amount must be greater than zero'];
    }
    if ($reference === '') {
        return ['success' => false, 'error' => 'A GCash reference number is required'];
    }
    if (!rentalGcashReferenceIsValid($reference)) {
        return ['success' => false, 'error' => 'The GCash reference number must be the 13 digits on the receipt'];
    }
    if (mb_strlen($reference) > 64) {
        return ['success' => false, 'error' => 'The GCash reference number is too long'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT id, vehicle_id, user_id, total_amount FROM reservations WHERE id IN ($placeholders)");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Could not read the booking'];
    }
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Could not read the booking'];
    }
    $result = $stmt->get_result();
    $rows = [];
    $tripTotal = 0.0;
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        $tripTotal += (float)$row['total_amount'];
    }
    $stmt->close();

    if ($rows === []) {
        return ['success' => false, 'error' => 'The booking could not be found'];
    }
    if ($tripTotal <= 0) {
        return ['success' => false, 'error' => 'The booking has no total to split the deposit across'];
    }

    // Share per row, then hand the rounding remainder to the largest row so the shares add up to the amount sent.
    $shares = [];
    $allocated = 0.0;
    $largestId = 0;
    $largestTotal = -1.0;
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $total = (float)$row['total_amount'];
        $share = round($amount * ($total / $tripTotal), 2);
        $shares[$id] = $share;
        $allocated += $share;
        if ($total > $largestTotal) {
            $largestTotal = $total;
            $largestId = $id;
        }
    }
    $remainder = round($amount - $allocated, 2);
    if ($remainder !== 0.0 && $largestId > 0) {
        $shares[$largestId] = round($shares[$largestId] + $remainder, 2);
    }

    $conn->begin_transaction();
    try {
        $update = $conn->prepare("UPDATE reservations SET payment_method = 'gcash', payment_reference = ?, payment_amount = ?, payment_status = 'pending' WHERE id = ?");
        if (!$update) {
            throw new RuntimeException('prepare failed: ' . $conn->error);
        }
        foreach ($shares as $id => $share) {
            // A share that rounds to zero fails the table's own check, so that row keeps no capture of its own.
            if ($share <= 0) {
                continue;
            }
            $update->bind_param('sds', $reference, $share, $id);
            if (!$update->execute()) {
                throw new RuntimeException('update failed: ' . $update->error);
            }
        }
        $update->close();

        // One ledger event for the whole transfer on the first row, carrying the full amount and the GCash reference.
        recordRentalEvent($conn, [
            'reservation_id' => (int)$rows[0]['id'],
            'vehicle_id' => (int)$rows[0]['vehicle_id'],
            'user_id' => (int)($rows[0]['user_id'] ?? 0) ?: null,
            'event_type' => 'deposit_captured',
            'from_status' => null,
            'to_status' => 'pending',
            'amount' => $amount,
            'notes' => 'GCash deposit received · ref ' . $reference,
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'The deposit could not be recorded'];
    }

    return ['success' => true, 'shares' => $shares];
}

/** Move a deposit capture to 'verified' or 'rejected'; only a carrying reservation can move, stamped with who and when. */
function setReservationPaymentStatus($conn, $reservationId, $status, $actorId = null) {
    $reservationId = (int)$reservationId;
    $status = strtolower(trim((string)$status));

    if ($reservationId <= 0) {
        return ['success' => false, 'error' => 'Unknown reservation'];
    }
    if (!in_array($status, ['verified', 'rejected'], true)) {
        return ['success' => false, 'error' => 'Unknown payment status'];
    }

    $stmt = $conn->prepare("SELECT payment_method, payment_amount, payment_reference FROM reservations WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['success' => false, 'error' => 'Could not read the reservation'];
    }
    $stmt->bind_param('i', $reservationId);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'error' => 'Could not read the reservation'];
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'error' => 'That reservation could not be found'];
    }
    if (empty($row['payment_method'])) {
        return ['success' => false, 'error' => 'This booking carries no deposit to review'];
    }

    $verifiedAt = date('Y-m-d H:i:s');
    $verifiedBy = $actorId > 0 ? (int)$actorId : null;
    $update = $conn->prepare("UPDATE reservations SET payment_status = ?, payment_verified_at = ?, payment_verified_by = ? WHERE id = ?");
    if (!$update) {
        return ['success' => false, 'error' => 'Could not update the deposit'];
    }
    $update->bind_param('ssii', $status, $verifiedAt, $verifiedBy, $reservationId);
    $ok = $update->execute();
    $update->close();

    if (!$ok) {
        return ['success' => false, 'error' => 'Could not update the deposit'];
    }

    return [
        'success'   => true,
        'status'    => $status,
        'amount'    => (float)$row['payment_amount'],
        'reference' => (string)$row['payment_reference'],
    ];
}

function rentalDayCount($pickupDate, $returnDate) {
    $start = strtotime($pickupDate);
    $end = strtotime($returnDate);
    if ($start === false || $end === false) {
        return 1;
    }
    return max(1, (int)round(($end - $start) / 86400));
}

/** Late-return charge: one daily rate per day past the due date, read from the vehicle - total_amount is not a per-day price. */
function rentalLateFeeBreakdown($expectedReturnDate, $actualReturnDate, $dailyRate) {
    $expected = strtotime((string)$expectedReturnDate);
    $actual = strtotime((string)$actualReturnDate);
    $rate = max(0.0, (float)$dailyRate);

    if ($expected === false || $actual === false || $actual <= $expected || $rate <= 0.0) {
        return ['days' => 0, 'fee' => 0.0];
    }

    // Dates are stored without a time, so ceil charges a stray same-day-but-later timestamp as a day rather than away.
    $days = (int)ceil(($actual - $expected) / 86400);

    return ['days' => $days, 'fee' => round($days * $rate, 2)];
}

function fetchReservationForUpdate($conn, $reservationId) {
    $reservationId = (int)$reservationId;
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE id = ? FOR UPDATE");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $reservationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

function approveReservationRecord($conn, $reservationId, $actorId = null) {
    $reservationId = (int)$reservationId;
    if ($reservationId <= 0) {
        return ['success' => false, 'error' => 'Missing reservation id'];
    }

    rentalTrackingSchemaReady($conn);

    $conn->begin_transaction();
    try {
        $reservation = fetchReservationForUpdate($conn, $reservationId);
        if (!$reservation) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Reservation not found'];
        }
        if ($reservation['status'] !== 'pending') {
            $conn->rollback();
            return ['success' => false, 'error' => 'Only pending reservations can be approved'];
        }

        $vehicleId = (int)$reservation['vehicle_id'];

        // A reservation can sit in 'pending' for weeks, so approving it must not lock dates nobody can collect.
        if (rentalDateIsPast($reservation['pickup_date'])) {
            $conn->rollback();
            return ['success' => false, 'error' => 'The pickup date for this reservation is already in the past'];
        }

        // The renter may have been deactivated while the request was waiting.
        $renterId = (int)$reservation['user_id'];
        $renter = $conn->prepare("SELECT status FROM users WHERE id = ?");
        $renter->bind_param('i', $renterId);
        $renterActive = $renter->execute() ? (string)($renter->get_result()->fetch_assoc()['status'] ?? '') : '';
        $renter->close();
        if (strtolower($renterActive) !== 'active') {
            $conn->rollback();
            return ['success' => false, 'error' => 'The renter account is no longer active'];
        }

        $vehicle = lockVehicleForSchedule($conn, $vehicleId);
        if (!$vehicle) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Vehicle not found'];
        }

        // Reconcile the fleet status first, or a unit stranded in 'maintenance' or 'rented' blocks approval forever.
        $vehicleStatus = refreshVehicleAvailabilityStatus($conn, $vehicleId);
        if ($vehicleStatus === null) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Vehicle not found'];
        }
        if (!in_array($vehicleStatus, ['available', 'rented'], true)) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Vehicle is not available for reservation'];
        }

        $conflicts = findConflictingBookings($conn, $vehicleId, $reservation['pickup_date'], $reservation['return_date'], $reservationId);
        if (!empty($conflicts)) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Vehicle already booked for those dates', 'conflicts' => $conflicts];
        }

        $stmt = $conn->prepare("UPDATE reservations SET status = 'confirmed' WHERE id = ?");
        $stmt->bind_param("i", $reservationId);
        // mysqli runs with MYSQLI_REPORT_OFF, so a rejected UPDATE returns false and must be checked before committing.
        if (!$stmt->execute()) {
            error_log('Carvella approve reservation failed: ' . $stmt->error);
            $stmt->close();
            $conn->rollback();
            return ['success' => false, 'error' => 'Failed to approve reservation'];
        }
        $stmt->close();

        // Re-derive now that the row is confirmed, or the car stays 'available' while its dates are already locked.
        $vehicleStatus = refreshVehicleAvailabilityStatus($conn, $vehicleId);

        recordRentalEvent($conn, [
            'reservation_id' => $reservationId,
            'vehicle_id' => (int)$reservation['vehicle_id'],
            'user_id' => (int)$reservation['user_id'],
            'event_type' => 'reservation_approved',
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'pickup_date' => $reservation['pickup_date'],
            'expected_return_date' => $reservation['return_date'],
            'amount' => (float)$reservation['total_amount'],
            'actor_id' => $actorId,
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Failed to approve reservation'];
    }

    return [
        'success' => true,
        'booking_reference' => $reservation['booking_reference'],
        'vehicle_id' => (int)$reservation['vehicle_id'],
        'vehicle_status' => $vehicleStatus,
    ];
}

function cancelReservationRecord($conn, $reservationId, $reason = '', $actorId = null) {
    $reservationId = (int)$reservationId;
    if ($reservationId <= 0) {
        return ['success' => false, 'error' => 'Missing reservation id'];
    }

    rentalTrackingSchemaReady($conn);

    $conn->begin_transaction();
    try {
        $reservation = fetchReservationForUpdate($conn, $reservationId);
        if (!$reservation) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Reservation not found'];
        }
        if (!in_array(strtolower((string)$reservation['status']), ['pending', 'confirmed'], true)) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Only pending or confirmed reservations can be cancelled'];
        }

        $stmt = $conn->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param("i", $reservationId);
        if (!$stmt->execute()) {
            error_log('Carvella cancel reservation failed: ' . $stmt->error);
            $stmt->close();
            $conn->rollback();
            return ['success' => false, 'error' => 'Failed to cancel reservation'];
        }
        $stmt->close();

        recordRentalEvent($conn, [
            'reservation_id' => $reservationId,
            'vehicle_id' => (int)$reservation['vehicle_id'],
            'user_id' => (int)$reservation['user_id'],
            'event_type' => 'reservation_cancelled',
            'from_status' => $reservation['status'],
            'to_status' => 'cancelled',
            'pickup_date' => $reservation['pickup_date'],
            'expected_return_date' => $reservation['return_date'],
            'amount' => (float)$reservation['total_amount'],
            'notes' => $reason !== '' ? $reason : null,
            'actor_id' => $actorId,
        ]);

        refreshVehicleAvailabilityStatus($conn, (int)$reservation['vehicle_id']);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Failed to cancel reservation'];
    }

    return ['success' => true, 'booking_reference' => $reservation['booking_reference']];
}

function dispatchRentalRecord($conn, array $data) {
    $reservationId = (int)($data['reservation_id'] ?? 0);
    $actualPickupDate = trim((string)($data['pickup_date'] ?? ''));
    $expectedReturnOverride = trim((string)($data['expected_return_date'] ?? ''));
    $odometerStart = isset($data['odometer_start']) && $data['odometer_start'] !== '' ? (int)$data['odometer_start'] : null;
    $notes = trim((string)($data['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;
    $actorId = $data['actor_id'] ?? rentalTrackingActorId();

    if ($reservationId <= 0) {
        return ['success' => false, 'error' => 'Missing reservation id'];
    }
    if ($actualPickupDate !== '' && !rentalDateIsValid($actualPickupDate)) {
        return ['success' => false, 'error' => 'Invalid pickup date'];
    }
    if ($expectedReturnOverride !== '' && !rentalDateIsValid($expectedReturnOverride)) {
        return ['success' => false, 'error' => 'Invalid return date'];
    }

    rentalTrackingSchemaReady($conn);

    $conn->begin_transaction();
    try {
        $reservation = fetchReservationForUpdate($conn, $reservationId);
        if (!$reservation) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Reservation not found'];
        }
        if ($reservation['status'] !== 'confirmed') {
            $conn->rollback();
            return ['success' => false, 'error' => 'Only confirmed reservations can be dispatched'];
        }

        $vehicleId = (int)$reservation['vehicle_id'];
        $vehicle = lockVehicleForSchedule($conn, $vehicleId);
        if (!$vehicle) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Vehicle not found'];
        }
        if (!in_array(strtolower((string)$vehicle['status']), ['available', 'rented'], true)) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Vehicle is not available for dispatch'];
        }

        $conflicts = findConflictingBookings($conn, $vehicleId, $reservation['pickup_date'], $reservation['return_date'], $reservationId);
        if (!empty($conflicts)) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Vehicle already booked for those dates', 'conflicts' => $conflicts];
        }

        $existing = $conn->prepare("SELECT id, status FROM rentals WHERE reservation_id = ?");
        $existing->bind_param("i", $reservationId);
        $existing->execute();
        $existingRental = $existing->get_result()->fetch_assoc();
        $existing->close();
        if ($existingRental) {
            $conn->rollback();
            return ['success' => false, 'error' => 'This reservation already has rental record #' . (int)$existingRental['id'] . ' (' . $existingRental['status'] . ')'];
        }

        $pickupDate = $actualPickupDate !== '' ? $actualPickupDate : $reservation['pickup_date'];
        $expectedReturn = $reservation['return_date'];
        // Staff may confirm or revise the handback date at handover.
        if ($expectedReturnOverride !== '') {
            $expectedReturn = $expectedReturnOverride;
        }
        // A staff revision that puts the handback before the pickup is impossible, so it is nudged forward.
        if ($pickupDate > $expectedReturn) {
            $expectedReturn = date('Y-m-d', strtotime($pickupDate . ' +1 day'));
        }
        $days = rentalDayCount($pickupDate, $expectedReturn);
        $protectionFee = max(0.0, (float)($reservation['protection_fee'] ?? 0));
        // The bill is derived from the window actually handed over, not copied from the reservation.
        $totalAmount = ($days * (float)$vehicle['daily_rate']) + $protectionFee;
        $rentalUserId = (int)$reservation['user_id'];

        $stmt = $conn->prepare("INSERT INTO rentals (reservation_id, user_id, vehicle_id, pickup_date, expected_return_date, actual_return_date, duration, total_amount, status, odometer_start, late_fee, notes, dispatched_at, returned_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, 'active', ?, 0.00, ?, NOW(), NULL)");
        $stmt->bind_param("iiissidis", $reservationId, $rentalUserId, $vehicleId, $pickupDate, $expectedReturn, $days, $totalAmount, $odometerStart, $notes);
        if (!$stmt->execute()) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Failed to dispatch the vehicle'];
        }
        $rentalId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("UPDATE reservations SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $reservationId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE vehicles SET status = 'rented' WHERE id = ?");
        $stmt->bind_param("i", $vehicleId);
        $stmt->execute();
        $stmt->close();

        recordRentalEvent($conn, [
            'rental_id' => $rentalId,
            'reservation_id' => $reservationId,
            'vehicle_id' => $vehicleId,
            'user_id' => (int)$reservation['user_id'],
            'event_type' => 'rental_dispatched',
            'from_status' => 'confirmed',
            'to_status' => 'active',
            'pickup_date' => $pickupDate,
            'expected_return_date' => $expectedReturn,
            'amount' => $totalAmount,
            'odometer' => $odometerStart,
            'notes' => $notes,
            'actor_id' => $actorId,
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Failed to dispatch the vehicle'];
    }

    return ['success' => true, 'rental_id' => $rentalId, 'booking_reference' => $reservation['booking_reference'], 'total_amount' => $totalAmount];
}

function completeRentalRecord($conn, array $data) {
    $rentalId = (int)($data['rental_id'] ?? 0);
    $actualReturnDate = trim((string)($data['actual_return_date'] ?? ''));
    $odometerEnd = isset($data['odometer_end']) && $data['odometer_end'] !== '' ? (int)$data['odometer_end'] : null;
    $notes = trim((string)($data['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;
    $actorId = $data['actor_id'] ?? rentalTrackingActorId();

    if ($rentalId <= 0) {
        return ['success' => false, 'error' => 'Missing rental id'];
    }
    if ($actualReturnDate === '') {
        $actualReturnDate = date('Y-m-d');
    }
    if (!rentalDateIsValid($actualReturnDate)) {
        return ['success' => false, 'error' => 'Invalid return date'];
    }

    rentalTrackingSchemaReady($conn);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM rentals WHERE id = ? FOR UPDATE");
        if (!$stmt) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Rental not found'];
        }
        $stmt->bind_param("i", $rentalId);
        $stmt->execute();
        $rental = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rental) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Rental not found'];
        }
        // A cancelled rental is already closed out, so completing it here would rewrite its dates, total and status.
        if (in_array(strtolower((string)$rental['status']), ['completed', 'cancelled'], true)) {
            $conn->rollback();
            return ['success' => false, 'error' => $rental['status'] === 'cancelled'
                ? 'This rental was cancelled and can no longer be completed'
                : 'This rental is already completed'];
        }
        if ($actualReturnDate < $rental['pickup_date']) {
            $conn->rollback();
            return ['success' => false, 'error' => 'The return date cannot be before the pickup date'];
        }

        $vehicleId = (int)$rental['vehicle_id'];
        // The vehicle row carries the daily rate the automatic late fee needs.
        $vehicle = lockVehicleForSchedule($conn, $vehicleId);

        $days = rentalDayCount($rental['pickup_date'], $actualReturnDate);
        // Late fees are computed here, never taken from the browser: one daily rate per late day from the agreed due date.
        $lateBreakdown = rentalLateFeeBreakdown($rental['expected_return_date'], $actualReturnDate, $vehicle['daily_rate'] ?? 0);
        $lateDays = $lateBreakdown['days'];
        $lateFee = $lateBreakdown['fee'];
        $totalAmount = (float)$rental['total_amount'] + $lateFee;

        $stmt = $conn->prepare("UPDATE rentals SET status = 'completed', actual_return_date = ?, duration = ?, total_amount = ?, late_fee = ?, odometer_end = ?, notes = COALESCE(?, notes), returned_at = NOW() WHERE id = ?");
        $stmt->bind_param("siddisi", $actualReturnDate, $days, $totalAmount, $lateFee, $odometerEnd, $notes, $rentalId);
        if (!$stmt->execute()) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Failed to complete the rental'];
        }
        $stmt->close();

        if (!empty($rental['reservation_id'])) {
            $rentalReservationId = (int)$rental['reservation_id'];
            $stmt = $conn->prepare("UPDATE reservations SET status = 'completed' WHERE id = ? AND status <> 'cancelled'");
            $stmt->bind_param("i", $rentalReservationId);
            $stmt->execute();
            $stmt->close();
        }

        $vehicleStatus = refreshVehicleAvailabilityStatus($conn, $vehicleId);

        recordRentalEvent($conn, [
            'rental_id' => $rentalId,
            'reservation_id' => $rental['reservation_id'],
            'vehicle_id' => $vehicleId,
            'user_id' => (int)$rental['user_id'],
            'event_type' => 'rental_completed',
            'from_status' => $rental['status'],
            'to_status' => 'completed',
            'pickup_date' => $rental['pickup_date'],
            'expected_return_date' => $rental['expected_return_date'],
            'actual_return_date' => $actualReturnDate,
            'amount' => $totalAmount,
            'odometer' => $odometerEnd,
            'notes' => $notes,
            'actor_id' => $actorId,
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Failed to complete the rental'];
    }

    return ['success' => true, 'rental_id' => $rentalId, 'total_amount' => $totalAmount, 'days' => $days, 'late_days' => $lateDays, 'late_fee' => $lateFee, 'vehicle_status' => $vehicleStatus];
}

function cancelRentalRecord($conn, $rentalId, $reason = '', $actorId = null) {
    // Fall back to the signed-in staff member so the ledger never files a real cancellation against "System".
    $actorId = $actorId ?? rentalTrackingActorId();
    $rentalId = (int)$rentalId;
    if ($rentalId <= 0) {
        return ['success' => false, 'error' => 'Missing rental id'];
    }

    rentalTrackingSchemaReady($conn);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM rentals WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $rentalId);
        if (!$stmt->execute()) {
            error_log('Carvella cancel rental lock failed: ' . $stmt->error);
            $stmt->close();
            $conn->rollback();
            return ['success' => false, 'error' => 'Failed to cancel the rental'];
        }
        $rental = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rental) {
            $conn->rollback();
            return ['success' => false, 'error' => 'Rental not found'];
        }
        if (in_array(strtolower((string)$rental['status']), ['completed', 'cancelled'], true)) {
            $conn->rollback();
            return ['success' => false, 'error' => 'This rental can no longer be cancelled'];
        }

        // GREATEST keeps the stored handback legal: CURDATE() can fall before pickup_date and trip chk_rentals_return.
        $stmt = $conn->prepare("UPDATE rentals SET status = 'cancelled', actual_return_date = GREATEST(COALESCE(actual_return_date, CURDATE()), pickup_date), returned_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $rentalId);
        if (!$stmt->execute()) {
            error_log('Carvella cancel rental failed: ' . $stmt->error);
            $stmt->close();
            $conn->rollback();
            return ['success' => false, 'error' => 'Failed to cancel the rental'];
        }
        $stmt->close();

        if (!empty($rental['reservation_id'])) {
            $cancelledReservationId = (int)$rental['reservation_id'];
            $stmt = $conn->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ? AND status IN ('confirmed','active')");
            $stmt->bind_param("i", $cancelledReservationId);
            if (!$stmt->execute()) {
                // Leaving the reservation confirmed while the rental is cancelled would double-book the vehicle's dates.
                error_log('Carvella cancel rental reservation sync failed: ' . $stmt->error);
                $stmt->close();
                $conn->rollback();
                return ['success' => false, 'error' => 'Failed to cancel the rental'];
            }
            $stmt->close();
        }

        refreshVehicleAvailabilityStatus($conn, (int)$rental['vehicle_id']);

        recordRentalEvent($conn, [
            'rental_id' => $rentalId,
            'reservation_id' => $rental['reservation_id'],
            'vehicle_id' => (int)$rental['vehicle_id'],
            'user_id' => (int)$rental['user_id'],
            'event_type' => 'rental_cancelled',
            'from_status' => $rental['status'],
            'to_status' => 'cancelled',
            'pickup_date' => $rental['pickup_date'],
            'expected_return_date' => $rental['expected_return_date'],
            'notes' => $reason !== '' ? $reason : null,
            'actor_id' => $actorId,
        ]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => 'Failed to cancel the rental'];
    }

    return ['success' => true];
}

/** Shared WHERE fragment: keeps the reported hit count and the rendered rows in agreement. @return array{0:string,1:array,2:string} */
function rentalTransactionHistoryFilter(array $filters): array {
    $sql = '';
    $params = [];
    $types = '';

    if (!empty($filters['vehicle_id'])) {
        $sql .= " AND tx.vehicle_id = ?";
        $params[] = (int)$filters['vehicle_id'];
        $types .= 'i';
    }
    if (!empty($filters['user_id'])) {
        $sql .= " AND tx.user_id = ?";
        $params[] = (int)$filters['user_id'];
        $types .= 'i';
    }
    if (!empty($filters['reservation_id'])) {
        $sql .= " AND tx.reservation_id = ?";
        $params[] = (int)$filters['reservation_id'];
        $types .= 'i';
    }
    if (!empty($filters['rental_id'])) {
        $sql .= " AND tx.rental_id = ?";
        $params[] = (int)$filters['rental_id'];
        $types .= 'i';
    }
    if (!empty($filters['event_type'])) {
        $sql .= " AND tx.event_type = ?";
        $params[] = (string)$filters['event_type'];
        $types .= 's';
    }
    if (!empty($filters['from_date'])) {
        $sql .= " AND DATE(tx.created_at) >= ?";
        $params[] = (string)$filters['from_date'];
        $types .= 's';
    }
    if (!empty($filters['to_date'])) {
        $sql .= " AND DATE(tx.created_at) <= ?";
        $params[] = (string)$filters['to_date'];
        $types .= 's';
    }
    if (!empty($filters['search'])) {
        // One CONCAT_WS haystack over every readable column, with wildcards escaped so a literal '%' does not match everything.
        $sql .= " AND (CONCAT_WS(' ', r.booking_reference, u.first_name, u.last_name,"
              . " v.brand, v.model, v.year, v.license_plate, tx.event_type,"
              . " tx.from_status, tx.to_status, tx.notes,"
              . " a.first_name, a.last_name) LIKE ? ESCAPE '\\\\')";
        $params[] = '%' . addcslashes((string)$filters['search'], '%_\\') . '%';
        $types .= 's';
    }

    return [$sql, $params, $types];
}

/** The FROM/JOIN every ledger query shares; all four links are LEFT joins because an event outlives the record it points at. */
function rentalTransactionHistoryFrom(): string {
    return "FROM rental_transactions tx
            LEFT JOIN vehicles v ON v.id = tx.vehicle_id
            LEFT JOIN users u ON u.id = tx.user_id
            LEFT JOIN reservations r ON r.id = tx.reservation_id
            LEFT JOIN users a ON a.id = tx.actor_id";
}

function fetchRentalTransactionHistory($conn, $filters = []) {
    rentalTrackingSchemaReady($conn);

    [$where, $params, $types] = rentalTransactionHistoryFilter($filters);

    $sql = "SELECT tx.*, v.brand, v.model, v.year, v.license_plate,
                   u.first_name, u.last_name, r.booking_reference,
                   a.first_name AS actor_first_name, a.last_name AS actor_last_name
            " . rentalTransactionHistoryFrom() . "
            WHERE 1 = 1" . $where . " ORDER BY tx.created_at DESC, tx.id DESC";
    if (!empty($filters['limit'])) {
        $limit = max(1, (int)$filters['limit']);
        $sql .= " LIMIT $limit";
        if (isset($filters['offset']) && (int)$filters['offset'] > 0) {
            $sql .= " OFFSET " . (int)$filters['offset'];
        }
    }

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/** How many ledger rows the same filters match, with no row cap, so the operator sees matches beyond the fetched window. */
function countRentalTransactionHistory($conn, $filters = []) {
    rentalTrackingSchemaReady($conn);

    [$where, $params, $types] = rentalTransactionHistoryFilter($filters);

    $stmt = $conn->prepare("SELECT COUNT(*) AS count " . rentalTransactionHistoryFrom() . " WHERE 1 = 1" . $where);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['count'] ?? 0);
}

function rentalTransactionSummary($conn, $fromDate = null, $toDate = null) {
    rentalTrackingSchemaReady($conn);

    $where = " WHERE 1 = 1";
    $params = [];
    $types = '';
    if ($fromDate) {
        $where .= " AND DATE(created_at) >= ?";
        $params[] = (string)$fromDate;
        $types .= 's';
    }
    if ($toDate) {
        $where .= " AND DATE(created_at) <= ?";
        $params[] = (string)$toDate;
        $types .= 's';
    }

    $stmt = $conn->prepare("SELECT event_type, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total FROM rental_transactions $where GROUP BY event_type");
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $summary = [];
    while ($row = $result->fetch_assoc()) {
        $summary[$row['event_type']] = [
            'count' => (int)$row['cnt'],
            'total' => (float)$row['total'],
        ];
    }
    $stmt->close();

    return $summary;
}

/* End of includes/rental-service.php */
