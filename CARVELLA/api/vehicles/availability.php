<?php
/* availability.php - Carvella Car Rental System */
require_once __DIR__ . '/../../config/auth.php';
// The conflict payload carries other customers' booking references and exact
// date windows, so it is scoped to signed-in users.
requireApiLogin();
requireMethod('GET');

$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$pickupDate = trim((string)($_GET['pickup_date'] ?? ''));
$returnDate = trim((string)($_GET['return_date'] ?? ''));

if ($vehicleId <= 0) {
    jsonResponse(['success' => false, 'error' => 'Missing vehicle id'], 422);
}

if (!rentalDateRangeIsValid($pickupDate, $returnDate)) {
    jsonResponse([
        'success' => false,
        'available' => false,
        'reason' => 'invalid_dates',
        'error' => 'Provide a valid pickup_date and a return_date on or after it',
    ], 422);
}

// A booking can never start in the past, whatever the calendar widget sent.
if ($pickupDate < date('Y-m-d')) {
    jsonResponse([
        'success' => false,
        'available' => false,
        'reason' => 'invalid_dates',
        'error' => 'Pickup date cannot be in the past',
    ], 422);
}

$check = vehicleIsAvailableForDates($conn, $vehicleId, $pickupDate, $returnDate);

// A customer only needs to know that the dates are taken. The reference codes
// and internal row ids belong to other people's bookings, so they stay behind
// the admin boundary.
$conflicts = $check['conflicts'];
$redacted = false;
if (!isAdmin() && $conflicts) {
    $redacted = true;
    $count = count($conflicts);
    $conflicts = array_fill(0, $count, ['reference' => 'Reserved', 'status' => 'unavailable']);
}

jsonResponse([
    'success' => true,
    'available' => $check['available'],
    'reason' => $check['reason'],
    'vehicle_id' => $vehicleId,
    'pickup_date' => $pickupDate,
    'return_date' => $returnDate,
    'redacted' => $redacted,
    'conflicts' => $conflicts,
]);
