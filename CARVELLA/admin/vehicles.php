<?php
/* vehicles.php - Carvella Car Rental System */

$pageTitle = 'Vehicle Matrix - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
/* 'list' is the live matrix; anything else falls back to it so a typo cannot render a blank page. */
$view = (($_GET['view'] ?? 'list') === 'archived') ? 'archived' : 'list';
$error = '';

/* List state (?type=, ?q=, ?bay=) is server-side so it never collides with the modal/detail params. */
$typeFilter = (string)($_GET['type'] ?? '');
if (!in_array($typeFilter, ['all', 'sedan', 'suv', 'mpv', 'van', 'other'], true)) {
    $typeFilter = 'all';
}
$q = trim((string)($_GET['q'] ?? ''));
$bayFilter = (string)($_GET['bay'] ?? 'all');
if (!in_array($bayFilter, ['all', '1', '2', '3', '4', '5', '6', '7', '8'], true)) {
    $bayFilter = 'all';
}

/* One fleet WHERE feeds the counters, the COUNT and the page window, so numbers and rows always match. */
$fleetWhere = "status <> 'archived'";
$fleetParams = [];
$fleetTypes = '';
if ($q !== '') {
    // Same haystack as the old client-side filter - brand, model, plate, bay label - with wildcards escaped.
    $fleetWhere .= " AND LOWER(CONCAT(COALESCE(brand, ''), ' ', COALESCE(model, ''), ' ', COALESCE(license_plate, ''), ' Bay ', (MOD(id, 8) + 1))) LIKE ?";
    $fleetParams[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)) . '%';
    $fleetTypes = 's';
}
if ($bayFilter !== 'all') {
    // The staging bay is arithmetic on the unit id, not a stored column, so the filter repeats the formula.
    $fleetWhere .= " AND (MOD(id, 8) + 1) = ?";
    $fleetParams[] = (int)$bayFilter;
    $fleetTypes .= 'i';
}
/* One switch maps pills to SQL types (pickup = SUV, unmapped = other) so counts and rows always agree. */
$typeSql = '';
switch ($typeFilter) {
    case 'sedan': $typeSql = " AND type = 'sedan'"; break;
    case 'suv':   $typeSql = " AND type IN ('suv', 'pickup')"; break;
    case 'mpv':   $typeSql = " AND type = 'mpv'"; break;
    case 'van':   $typeSql = " AND type = 'van'"; break;
    case 'other': $typeSql = " AND type NOT IN ('sedan', 'suv', 'pickup', 'mpv', 'van')"; break;
}

/* Vehicle types live in one array so validation, the select, counts and the filter cannot drift apart. */
function carvellaVehicleTypes() {
    return ['sedan', 'suv', 'mpv', 'pickup', 'van', 'hatchback'];
}

function validateVehicleData(array $data, &$error) {
    if ($data['brand'] === '' || $data['model'] === '') {
        $error = 'Brand and model are required';
        return false;
    }
    if ($data['year'] < 1980 || $data['year'] > (int)date('Y') + 2) {
        $error = 'Year must be between 1980 and ' . ((int)date('Y') + 2);
        return false;
    }
    if ($data['seats'] < 1 || $data['seats'] > 99) {
        $error = 'Seats must be between 1 and 99';
        return false;
    }
    if ($data['daily_rate'] <= 0) {
        $error = 'Daily rate must be greater than 0';
        return false;
    }
    return true;
}

function resizeImageToWidth($src, $maxWidth) {
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= $maxWidth) {
        return $src;
    }
    $newHeight = (int)round($h * $maxWidth / $w);
    $dst = imagecreatetruecolor($maxWidth, $newHeight);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newHeight, $w, $h);
    return $dst;
}

/* Writes a master plus a 900px card under assets/images/vehicles/ and returns null with no file chosen. */
function processVehicleImageUpload(array $data, &$error) {
    if (empty($_FILES['vehicle_image']['name'])) {
        return null;
    }
    $file = $_FILES['vehicle_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Image upload failed, please try again';
        return false;
    }
    if ($file['size'] > 20 * 1024 * 1024) {
        $error = 'Image must be 20MB or smaller';
        return false;
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        $error = 'Image must be a JPG, PNG, or WebP file';
        return false;
    }
    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case IMAGETYPE_PNG:
            $src = @imagecreatefrompng($file['tmp_name']);
            break;
        case IMAGETYPE_WEBP:
            $src = @imagecreatefromwebp($file['tmp_name']);
            break;
        default:
            $src = false;
    }
    if (!$src) {
        $error = 'Could not read the uploaded image';
        return false;
    }
    $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim($data['brand'] . ' ' . $data['model'] . ' ' . $data['license_plate'])));
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'vehicle_' . time();
    }
    $projectRoot = dirname(__DIR__);
    $fullPath = $projectRoot . '/assets/images/vehicles/' . $slug . '.jpg';
    $thumbPath = $projectRoot . '/assets/images/vehicles/cards/' . $slug . '.jpg';
    $image = 'assets/images/vehicles/' . $slug . '.jpg?v=' . time();

    $full = resizeImageToWidth($src, 2560);
    imagejpeg($full, $fullPath, 92);
    imagedestroy($full);

    $thumbSrc = imagecreatefromjpeg($fullPath);
    $thumb = resizeImageToWidth($thumbSrc, 900);
    imagejpeg($thumb, $thumbPath, 88);
    imagedestroy($thumb);

    imagedestroy($src);
    return $image;
}

/* Removes the master + card derivatives, only inside the managed folder, so hand-placed files survive. */
function cleanVehicleImageFiles($imagePath) {
    $clean = preg_replace('/\?v=\d+$/', '', $imagePath);
    if ($clean === '' || strpos($clean, 'assets/images/vehicles/') !== 0) {
        return;
    }
    $projectRoot = dirname(__DIR__);
    $fullPath = $projectRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $clean);
    $thumbPath = $projectRoot . '/assets/images/vehicles/cards/' . basename(preg_replace('/\.(png|jpe?g|webp)$/i', '.jpg', $clean));
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
    if (is_file($thumbPath)) {
        @unlink($thumbPath);
    }
}

/* True when two paths are the same file, so re-uploading a photo never deletes the file it just wrote. */
function sameVehicleImageFile($a, $b) {
    $strip = function ($p) { return preg_replace('/\?v=\d+$/', '', (string)$p); };
    return $strip($a) === $strip($b);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // The select posts status_choice; 'status' is only the hidden 'rented' marker, so read the select first.
    $statusChoice = (string)($_POST['status_choice'] ?? '');
    $statusDerived = (string)($_POST['status'] ?? '');
    if (in_array($statusChoice, ['available', 'rented', 'maintenance', 'unavailable'], true)) {
        $submittedStatus = $statusChoice;
    } elseif ($statusDerived === 'rented') {
        // A rented vehicle's only option is disabled, so the browser submits nothing for the select.
        $submittedStatus = 'rented';
    } else {
        $submittedStatus = 'available';
    }

    $data = [
        'brand' => trim((string)($_POST['brand'] ?? '')),
        'model' => trim((string)($_POST['model'] ?? '')),
        'year' => (int)($_POST['year'] ?? 0),
        'type' => in_array($_POST['type'] ?? '', carvellaVehicleTypes(), true) ? $_POST['type'] : 'sedan',
        'license_plate' => strtoupper(trim((string)($_POST['license_plate'] ?? ''))),
        'seats' => (int)($_POST['seats'] ?? 0),
        'transmission' => in_array($_POST['transmission'] ?? '', ['automatic', 'manual'], true) ? $_POST['transmission'] : 'automatic',
        'fuel_type' => in_array($_POST['fuel_type'] ?? '', ['gasoline', 'diesel', 'electric', 'hybrid'], true) ? $_POST['fuel_type'] : 'gasoline',
        'daily_rate' => (float)($_POST['daily_rate'] ?? 0),
        'description' => sanitize($_POST['description'] ?? ''),
        'status' => $submittedStatus,
        'color' => sanitize($_POST['color'] ?? '')
    ];

    // 'rented' comes from the ledger, never the form, so an on-the-road unit cannot be marked idle.
    if ($data['status'] === 'rented') {
        $existingStatus = '';
        if (isset($_POST['update_vehicle'])) {
            $targetId = (int)$editId;
            $probe = $conn->prepare('SELECT status FROM vehicles WHERE id = ?');
            $probe->bind_param('i', $targetId);
            $existingStatus = $probe->execute() ? (string)($probe->get_result()->fetch_assoc()['status'] ?? '') : '';
            $probe->close();
        }
        if (strtolower($existingStatus) !== 'rented') {
            $data['status'] = 'available';
            $error = 'A vehicle returns to the fleet automatically when its rental is closed. Pick available, maintenance, or unavailable instead.';
        }
    }

    if ($error === '' && validateVehicleData($data, $error)) {
        $newImage = processVehicleImageUpload($data, $error);
        if ($newImage === false) {
            $error = $error !== '' ? $error : 'Failed to process the uploaded image';
        } elseif (isset($_POST['update_vehicle'])) {
            $targetId = (int)$editId;
            $current = $conn->query("SELECT image FROM vehicles WHERE id = " . $targetId)->fetch_assoc();
            if (!$current) {
                $error = 'That vehicle no longer exists.';
            } else {
                $image = $newImage ?: ($current['image'] ?? null);
                $stmt = $conn->prepare("UPDATE vehicles SET brand=?, model=?, year=?, type=?, license_plate=?, seats=?, transmission=?, fuel_type=?, daily_rate=?, description=?, status=?, color=?, image=? WHERE id=?");
                $params = array_values($data);
                $params[] = $image;
                $params[] = $targetId;
                $stmt->bind_param("ssississdssssi", ...$params);
                // execute() returns false instead of throwing, so a duplicate plate must not report success.
                $ok = $stmt->execute();
                $errno = $ok ? 0 : $stmt->errno;
                $stmt->close();
                if ($ok) {
                    if ($newImage && !empty($current['image']) && !sameVehicleImageFile($newImage, $current['image'])) {
                        cleanVehicleImageFiles($current['image']);
                    }
                    // The posted status passes through so a hand-parked unit is not undone by re-derivation.
                    refreshVehicleAvailabilityStatus($conn, $targetId, $data['status'] ?? null);
                    logActivity('updated', "Vehicle #$targetId updated");
                    setFlash('success', 'Vehicle updated successfully');
                    redirect(BASE_URL . '/admin/vehicles.php');
                }
                $error = $errno === 1062 ? 'License plate already in use' : 'Failed to update vehicle';
                if ($newImage && !sameVehicleImageFile($newImage, $current['image'] ?? '')) {
                    cleanVehicleImageFiles($image);
                }
            }
        } elseif (isset($_POST['add_vehicle'])) {
            $image = $newImage;
            $stmt = $conn->prepare("INSERT INTO vehicles (brand, model, year, type, license_plate, seats, transmission, fuel_type, daily_rate, description, status, color, image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $params = array_values($data);
            $params[] = $image;
            $stmt->bind_param("ssississdssss", ...$params);
            $ok = $stmt->execute();
            $errno = $ok ? 0 : $stmt->errno;
            $stmt->close();
            if ($ok) {
                logActivity('added', "Vehicle {$data['year']} {$data['brand']} {$data['model']} added");
                setFlash('success', 'Vehicle added to fleet successfully');
                redirect(BASE_URL . '/admin/vehicles.php');
            }
            $error = $errno === 1062 ? 'License plate already in use' : 'Failed to add vehicle';
            if ($newImage) cleanVehicleImageFiles($image);
        }
    }
}

if (isset($_POST['delete_vehicle'])) {
    verify_csrf();
    $deleteId = (int)($_POST['delete_vehicle'] ?? 0);
    // The archive posts its delete from ?view=archived, so it returns to the archive, not the matrix.
    $keepQuery = (($_POST['return_view'] ?? '') === 'archived') ? '?view=archived' : '';

    if ($deleteId <= 0) {
        setFlash('error', 'Invalid vehicle selection.', 'Nothing was deleted');
        redirect(BASE_URL . '/admin/vehicles.php' . $keepQuery);
    }

    $del = $conn->query("SELECT brand, model, year, image FROM vehicles WHERE id = " . $deleteId)->fetch_assoc();
    if (!$del) {
        setFlash('error', 'That vehicle no longer exists.', 'Nothing was deleted');
        redirect(BASE_URL . '/admin/vehicles.php' . $keepQuery);
    }
    $delName = $del['year'] . ' ' . $del['brand'] . ' ' . $del['model'];

    $deleted = $conn->query("DELETE FROM vehicles WHERE id = " . $deleteId);
    if ($deleted === false || $conn->affected_rows < 1) {
        // Reservations, rentals, maintenance and conditions hold a RESTRICT key, so the row is kept.
        $blockers = [];
        foreach ([
            'reservations'     => 'reservations',
            'rentals'          => 'rentals',
            'rental_transactions' => 'rental activity records',
            'maintenance'      => 'maintenance records',
            'vehicle_conditions' => 'inspection records',
        ] as $table => $label) {
            $probe = $conn->query("SELECT COUNT(*) AS c FROM `$table` WHERE vehicle_id = " . $deleteId);
            if ($probe && (int)($probe->fetch_assoc()['c'] ?? 0) > 0) {
                $blockers[] = $label;
            }
        }
        setFlash('error', $blockers
            ? $delName . ' still has ' . implode(', ', $blockers) . '. Those records keep the unit alive on purpose - close or remove them first.'
            : 'Unable to delete ' . $delName . '. Please try again.', 'Delete blocked');
    } else {
        if (!empty($del['image'])) {
            cleanVehicleImageFiles($del['image']);
        }
        logActivity('deleted', "Vehicle {$del['brand']} {$del['model']} removed");
        setFlash('success', $delName . ' was deleted permanently.', 'Vehicle deleted');
    }
    redirect(BASE_URL . '/admin/vehicles.php' . $keepQuery);
}

/* Archive only changes status so it is never blocked by rental history, but refuses a unit out on rental. */
if (isset($_POST['archive_vehicle'])) {
    verify_csrf();
    $archiveId = (int)($_POST['archive_vehicle'] ?? 0);

    if ($archiveId <= 0) {
        setFlash('error', 'Invalid vehicle selection.', 'Nothing was archived');
        redirect(BASE_URL . '/admin/vehicles.php');
    }

    $row = $conn->query("SELECT brand, model, year, status FROM vehicles WHERE id = " . $archiveId)->fetch_assoc();
    if (!$row) {
        setFlash('error', 'That vehicle no longer exists.', 'Nothing was archived');
        redirect(BASE_URL . '/admin/vehicles.php');
    }
    $archiveName = $row['year'] . ' ' . $row['brand'] . ' ' . $row['model'];

    if ($row['status'] === 'archived') {
        setFlash('success', $archiveName . ' is already in the archive.', 'Already archived');
        redirect(BASE_URL . '/admin/vehicles.php?view=archived');
    }

    $openRental = $conn->query("SELECT COUNT(*) AS c FROM rentals WHERE vehicle_id = " . $archiveId . " AND status IN ('pending','active','in_progress','overdue')")->fetch_assoc();
    if ((int)($openRental['c'] ?? 0) > 0) {
        setFlash('error', $archiveName . ' is still out on a rental. Record its return, then archive it.', 'Cannot archive yet');
        redirect(BASE_URL . '/admin/vehicles.php');
    }

    $stmt = $conn->prepare("UPDATE vehicles SET status = 'archived' WHERE id = ?");
    $stmt->bind_param("i", $archiveId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        setFlash('error', 'Could not archive ' . $archiveName . '. Please try again.', 'Archive failed');
        redirect(BASE_URL . '/admin/vehicles.php');
    }

    // A car archived with future bookings cannot be handed over, so name the bookings to clear.
    $upcoming = $conn->query("SELECT COUNT(*) AS c FROM reservations WHERE vehicle_id = " . $archiveId . " AND status IN ('pending','confirmed','active')")->fetch_assoc();
    $upcomingCount = (int)($upcoming['c'] ?? 0);

    logActivity('archived', "Vehicle {$row['brand']} {$row['model']} archived");
    $message = $archiveName . ' was moved to the archive and is hidden from the client catalog.';
    if ($upcomingCount > 0) {
        $message .= ' ' . $upcomingCount . ' open rental' . ($upcomingCount === 1 ? '' : 's')
            . ' still reference' . ($upcomingCount === 1 ? 's' : '') . ' this unit - cancel or reject '
            . ($upcomingCount === 1 ? 'it' : 'them') . ' so the dates are freed.';
    }
    setFlash('success', $message, 'Vehicle archived');
    redirect(BASE_URL . '/admin/vehicles.php?view=archived');
}

/* Undo resets the unit to 'available' and re-derives it, so a restored car never contradicts the ledger. */
if (isset($_POST['restore_vehicle'])) {
    verify_csrf();
    $restoreId = (int)($_POST['restore_vehicle'] ?? 0);

    if ($restoreId <= 0) {
        setFlash('error', 'Invalid vehicle selection.', 'Nothing was restored');
        redirect(BASE_URL . '/admin/vehicles.php?view=archived');
    }

    $row = $conn->query("SELECT brand, model, year, status FROM vehicles WHERE id = " . $restoreId)->fetch_assoc();
    if (!$row) {
        setFlash('error', 'That vehicle no longer exists.', 'Nothing was restored');
        redirect(BASE_URL . '/admin/vehicles.php?view=archived');
    }
    $restoreName = $row['year'] . ' ' . $row['brand'] . ' ' . $row['model'];

    if ($row['status'] !== 'archived') {
        setFlash('error', $restoreName . ' is not in the archive.', 'Nothing was restored');
        redirect(BASE_URL . '/admin/vehicles.php');
    }

    $stmt = $conn->prepare("UPDATE vehicles SET status = 'available' WHERE id = ?");
    $stmt->bind_param("i", $restoreId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        setFlash('error', 'Could not restore ' . $restoreName . '. Please try again.', 'Restore failed');
        redirect(BASE_URL . '/admin/vehicles.php?view=archived');
    }

    $newStatus = refreshVehicleAvailabilityStatus($conn, $restoreId);
    $statusLabel = $newStatus === 'rented' ? 'on the road' : ($newStatus === 'maintenance' ? 'in maintenance' : 'available for rental');
    logActivity('restored', "Vehicle {$row['brand']} {$row['model']} restored from archive");
    setFlash('success', $restoreName . ' is back in the fleet and is ' . $statusLabel . '.', 'Vehicle restored');
    redirect(BASE_URL . '/admin/vehicles.php');
}

$vehicle = null;
if ($action === 'edit') {
    if ($editId) {
        $vehicle = $conn->query("SELECT * FROM vehicles WHERE id = " . (int)$editId)->fetch_assoc();
    } else {
        $vehicle = [
            'brand' => '', 'model' => '', 'year' => date('Y'), 'type' => 'sedan',
            'license_plate' => '', 'seats' => 5, 'transmission' => 'automatic',
            'fuel_type' => 'gasoline', 'daily_rate' => '',
            'description' => '', 'status' => 'available', 'color' => '', 'image' => ''
        ];
    }
}

/* One grouped pass over the fleet feeds the counters and metric cards, with the type pill left out. */
$counterStmt = $conn->prepare(
    "SELECT CASE type WHEN 'sedan' THEN 'sedan' WHEN 'suv' THEN 'suv' WHEN 'pickup' THEN 'suv'
                      WHEN 'mpv' THEN 'mpv' WHEN 'van' THEN 'van' ELSE 'other' END AS type_group,
            status, COUNT(*) AS cnt
       FROM vehicles
      WHERE $fleetWhere
      GROUP BY type_group, status"
);
if (!empty($fleetParams)) {
    $counterStmt->bind_param($fleetTypes, ...$fleetParams);
}
$counterStmt->execute();
$counterResult = $counterStmt->get_result();
$tabCounts = [];
$statusCounts = [];
while ($crow = $counterResult->fetch_assoc()) {
    $group = (string)$crow['type_group'];
    $tabCounts[$group] = ($tabCounts[$group] ?? 0) + (int)$crow['cnt'];
    $st = (string)$crow['status'];
    $statusCounts[$st] = ($statusCounts[$st] ?? 0) + (int)$crow['cnt'];
}
$counterStmt->close();

$totalCount = array_sum($tabCounts);
$sedanCount = $tabCounts['sedan'] ?? 0;
$suvCount = $tabCounts['suv'] ?? 0;
$mpvCount = $tabCounts['mpv'] ?? 0;
$vanCount = $tabCounts['van'] ?? 0;
$otherCount = $tabCounts['other'] ?? 0;
$availableCount = $statusCounts['available'] ?? 0;
$rentedCount = ($statusCounts['rented'] ?? 0) + ($statusCounts['in_progress'] ?? 0);
$maintenanceCount = ($statusCounts['maintenance'] ?? 0) + ($statusCounts['unavailable'] ?? 0);

// Safety sync: ensure vehicle status matches active rentals (fixes stale 'available' when a car is actually on the road).
// Only run on GET requests to avoid overriding a just-submitted status change.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && function_exists('refreshVehicleAvailabilityStatus')) {
    $syncVehs = $conn->query("SELECT id FROM vehicles WHERE status <> 'archived'");
    if ($syncVehs) {
        while ($sv = $syncVehs->fetch_assoc()) {
            refreshVehicleAvailabilityStatus($conn, (int)$sv['id']);
        }
        $syncVehs->close();
    }
}

// The COUNT shares $fleetWhere/$typeSql with the table query, so the pager matches the set it pages over.
$countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM vehicles WHERE $fleetWhere$typeSql");
if (!empty($fleetParams)) {
    $countStmt->bind_param($fleetTypes, ...$fleetParams);
}
$countStmt->execute();
$totalFleet = (int)$countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();

$pgFleet = paginationBounds(paginationPage(), $totalFleet, 12);
$fleetPerPage = $pgFleet['per_page'];
$fleetOffset = $pgFleet['offset'];

$vehicles = $conn->prepare("SELECT * FROM vehicles WHERE $fleetWhere$typeSql ORDER BY id ASC LIMIT ? OFFSET ?");
$fleetArgs = array_merge($fleetParams, [$fleetPerPage, $fleetOffset]);
$vehicles->bind_param($fleetTypes . 'ii', ...$fleetArgs);
$vehicles->execute();
$vehiclesResult = $vehicles->get_result();
$vehicleRows = [];
while ($vrow = $vehiclesResult->fetch_assoc()) {
    $vehicleRows[] = $vrow;
}
$vehicles->close();

/* Archived units are held apart from the matrix counts and pills, but the header badge always counts them. */
$archivedCountResult = $conn->query("SELECT COUNT(*) AS c FROM vehicles WHERE status = 'archived'");
$archivedCount = $archivedCountResult ? (int)($archivedCountResult->fetch_assoc()['c'] ?? 0) : 0;
$archivedRows = [];
$pgArchived = null;
if ($view === 'archived') {
    $pgArchived = paginationBounds(paginationPage(), $archivedCount, 10);
    $archivedPerPage = $pgArchived['per_page'];
    $archivedOffset = $pgArchived['offset'];
    $archivedStmt = $conn->prepare("SELECT * FROM vehicles WHERE status = 'archived' ORDER BY id ASC LIMIT ? OFFSET ?");
    $archivedStmt->bind_param('ii', $archivedPerPage, $archivedOffset);
    $archivedStmt->execute();
    $archivedResult = $archivedStmt->get_result();
    while ($arow = $archivedResult->fetch_assoc()) {
        $archivedRows[] = $arow;
    }
    $archivedStmt->close();
}

/* Brand choices come from the fleet so they cannot drift; the edited unit is folded in to keep its brand. */
$brandOptions = [];
$brandResult = $conn->query("SELECT brand FROM vehicles WHERE status <> 'archived'");
if ($brandResult) {
    while ($brandRow = $brandResult->fetch_assoc()) {
        $brandName = trim((string)($brandRow['brand'] ?? ''));
        if ($brandName !== '') $brandOptions[$brandName] = true;
    }
}
$currentBrand = trim((string)($vehicle['brand'] ?? ''));
if ($currentBrand !== '') $brandOptions[$currentBrand] = true;
$brandOptions = array_keys($brandOptions);
natcasesort($brandOptions);
$brandOptions = array_values($brandOptions);

function getStatusPillClasses($status) {
    switch ($status) {
        case 'available': return 'bg-emerald-100 text-emerald-800';
        case 'rented':
        case 'in_progress': return 'bg-secondary-fixed text-on-secondary-fixed';
        case 'maintenance':
        case 'unavailable': return 'bg-rose-100 text-rose-800';
        case 'archived': return 'bg-surface-container-high text-on-surface-variant';
        case 'pending': return 'bg-amber-100 text-amber-800';
        default: return 'bg-surface-container-high text-on-surface-variant';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex w-full relative pl-4 md:pl-8">
    <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
    <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

        <?php
        /* Every outcome goes through the shared toast: the queued flash and any validation error. */
        renderFlashNotices($error !== ''
            ? [['type' => 'error', 'variant' => 'error', 'title' => 'Could not save', 'message' => $error]]
            : []);
        ?>

        <?php if ($action === 'edit' && $vehicle): ?>
        <!-- Edit/Add Vehicle Form (Inline) -->
        <div class="flex flex-col gap-4">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-primary"></span>
                <span class="font-label-sm text-label-sm uppercase tracking-wider text-tertiary"><?= $editId ? 'Edit Vehicle Record' : 'Add New Vehicle' ?></span>
            </div>
            <div class="bg-surface-container-lowest rounded-lg border border-outline-variant/70 shadow-sm overflow-hidden">
                <div class="px-5 py-3.5 border-b border-outline-variant/40 flex items-center justify-between">
                    <h2 class="font-headline-md text-headline-md text-on-surface"><?= $editId ? 'Edit Vehicle' : 'Add Vehicle to Fleet' ?></h2>
                    <!-- Cancel returns to the same filters and page, hence resetPage = false. -->
                    <a href="<?= e(queryUrl(['action' => null, 'id' => null], false)) ?>" class="px-6 py-3 rounded-md bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface font-label-lg font-bold inline-flex items-center gap-2 transition-all">
                        <i aria-hidden="true" class="bi bi-x-lg text-[20px]"></i>
                        Cancel
                    </a>
                </div>
                <form method="POST" enctype="multipart/form-data" class="p-5">
<?= csrf_field() ?>
                    <input type="hidden" name="<?= $editId ? 'update_vehicle' : 'add_vehicle' ?>" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Brand</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-building text-[20px] text-outline"></i>
                                <select name="brand" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface appearance-none cursor-pointer" required>
                                    <option value="" disabled <?= trim((string)($vehicle['brand'] ?? '')) === '' ? 'selected' : '' ?>>Select brand</option>
                                    <?php foreach ($brandOptions as $brandOpt): ?>
                                        <option value="<?= htmlspecialchars($brandOpt) ?>" <?= ($vehicle['brand'] ?? '') === $brandOpt ? 'selected' : '' ?>><?= htmlspecialchars($brandOpt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Model</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-car-front text-[20px] text-outline"></i>
                                <input type="text" name="model" maxlength="100" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" value="<?= htmlspecialchars($vehicle['model'] ?? '') ?>" placeholder="e.g. Vios" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Year</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-calendar-date text-[20px] text-outline"></i>
                                <input type="number" name="year" min="1900" max="2100" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" value="<?= htmlspecialchars($vehicle['year'] ?? date('Y')) ?>" placeholder="2024" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Type</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-grid-3x3-gap text-[20px] text-outline"></i>
                                <select name="type" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface appearance-none cursor-pointer" required>
                                    <?php foreach (carvellaVehicleTypes() as $t): ?>
                                        <option value="<?= $t ?>" <?= ($vehicle['type'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">License Plate</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-pin text-[20px] text-outline"></i>
                                <input type="text" name="license_plate" maxlength="20" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline uppercase tracking-wider" value="<?= htmlspecialchars($vehicle['license_plate'] ?? '') ?>" placeholder="ABC 1234" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Seats</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-people text-[20px] text-outline"></i>
                                <input type="number" name="seats" min="1" max="99" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" value="<?= htmlspecialchars($vehicle['seats'] ?? 5) ?>" placeholder="5" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Transmission</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-gear text-[20px] text-outline"></i>
                                <select name="transmission" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface appearance-none cursor-pointer" required>
                                    <option value="automatic" <?= ($vehicle['transmission'] ?? '') === 'automatic' ? 'selected' : '' ?>>Automatic</option>
                                    <option value="manual" <?= ($vehicle['transmission'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Fuel Type</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-fuel-pump text-[20px] text-outline"></i>
                                <select name="fuel_type" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface appearance-none cursor-pointer" required>
                                    <?php foreach (['gasoline', 'diesel', 'electric', 'hybrid'] as $f): ?>
                                        <option value="<?= $f ?>" <?= ($vehicle['fuel_type'] ?? '') === $f ? 'selected' : '' ?>><?= ucfirst($f) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Daily Rate (<?= CURRENCY ?>)</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-cash-stack text-[20px] text-outline"></i>
                                <input type="number" step="0.01" name="daily_rate" min="0.01" max="9999999.99" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" value="<?= htmlspecialchars($vehicle['daily_rate'] ?? '') ?>" placeholder="0.00" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Color</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-palette text-[20px] text-outline"></i>
                                <input type="text" name="color" maxlength="100" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" value="<?= htmlspecialchars($vehicle['color'] ?? '') ?>" placeholder="e.g. Platinum White">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Status</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-flag text-[20px] text-outline"></i>
                                <select name="status_choice" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface appearance-none cursor-pointer" required>
                                    <?php foreach (['available', 'maintenance', 'unavailable'] as $s): ?>
                                        <option value="<?= $s ?>" <?= ($vehicle['status'] ?? 'available') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                    <?php endforeach; ?>
                                    <?php if (($vehicle['status'] ?? '') === 'rented'): ?>
                                        <?php /* Keep an on-the-road unit visibly on the road. */ ?>
                                        <option value="" disabled selected>Currently on rental</option>
                                    <?php endif; ?>
                                </select>
                                <?php if (($vehicle['status'] ?? '') === 'rented'): ?>
                                    <?php /* A disabled option is never submitted, so the derived status is carried explicitly. Without this the select had no successful option and 'required' blocked every edit of an on-the-road unit. */ ?>
                                    <input type="hidden" name="status" value="rented">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Description</label>
                            <div class="flex items-start gap-3 px-4 py-3.5 rounded-lg bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-card-text text-[20px] text-outline mt-0.5"></i>
                                <textarea name="description" maxlength="1000" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline resize-none" rows="3" placeholder="Optional vehicle description..."><?= htmlspecialchars($vehicle['description'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Vehicle Image</label>
                            <div class="flex items-center gap-3 px-4 py-3.5 rounded-lg bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
                                <i aria-hidden="true" class="bi bi-image text-[20px] text-outline"></i>
                                <input type="file" name="vehicle_image" accept="image/jpeg,image/png,image/webp" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface file:mr-4 file:px-5 file:py-2.5 file:rounded-md file:border-0 file:bg-primary file:text-on-primary file:font-label-md file:font-bold file:cursor-pointer hover:file:bg-secondary">
                            </div>
                            <?php $currentImage = vehicleCardImage($vehicle['image'] ?? ''); ?>
                            <?php if ($currentImage !== ''): ?>
                            <div class="flex items-center gap-3 mt-2">
                                <img src="<?= BASE_URL ?>/<?= htmlspecialchars($currentImage) ?>" alt="Current vehicle image" class="w-16 h-12 rounded-lg object-cover object-center border border-outline-variant/40" width="900" height="672" loading="lazy" decoding="async">
                                <span class="font-body-sm text-body-sm text-tertiary">Current photo. Choose a file to replace it, or leave empty to keep it.</span>
                            </div>
                            <?php else: ?>
                            <p class="font-body-sm text-body-sm text-tertiary mt-1.5">JPG, PNG, or WebP up to 20MB.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 mt-6">
                        <button type="submit" class="px-6 py-3 rounded-md bg-primary text-on-primary font-label-lg font-bold shadow-sm hover:bg-primary-container active:scale-95 transition-all inline-flex items-center gap-2">
                            <i aria-hidden="true" class="bi bi-floppy text-[20px]"></i>
                            <?= $editId ? 'Update Vehicle' : 'Add Vehicle' ?>
                        </button>
                        <a href="<?= e(queryUrl(['action' => null, 'id' => null], false)) ?>" class="px-6 py-3 rounded-md bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface font-label-lg font-bold inline-flex items-center gap-2 transition-all">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <?php if ($view === 'archived'): ?>
        <!-- Archive view: units parked out of the fleet; delete is refused while history exists. -->
        <div class="flex flex-col gap-4">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-tertiary"></span>
                        <span class="font-label-sm text-label-sm uppercase tracking-wider text-tertiary">Retired from the active fleet</span>
                    </div>
                    <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Vehicle Archive</h1>
                    <p class="font-body-sm text-body-sm text-on-surface-variant">Archived units are hidden from the customer catalog and the operations matrix. Restore one to bring it back, or delete it permanently when it has no history.</p>
                </div>
                <a href="<?= e(queryUrl(['view' => null, 'action' => null, 'id' => null])) ?>" class="inline-flex shrink-0 items-center gap-2 px-5 py-2.5 rounded-md bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface font-label-md font-semibold transition-all active:scale-95 whitespace-nowrap">
                    <i aria-hidden="true" class="bi bi-arrow-left text-[18px]"></i>
                    Back to Fleet
                </a>
            </div>
        </div>

        <div class="bg-surface-container-lowest rounded-lg border border-outline-variant/50 shadow-sm overflow-hidden">
            <div class="w-full">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
                            <th scope="col" class="py-3.5 px-5">Vehicle &amp; Model</th>
                            <th scope="col" class="py-3.5 px-5">Plate &amp; Specs</th>
                            <th scope="col" class="py-3.5 px-5">Daily Rate</th>
                            <th scope="col" class="py-3.5 px-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
                        <?php if (empty($archivedRows)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-12">
                                <div class="flex flex-col items-center gap-3">
                                    <i aria-hidden="true" class="bi bi-archive text-[48px] text-outline-variant"></i>
                                    <p class="font-body-lg text-body-lg text-on-surface-variant">The archive is empty</p>
                                    <p class="font-body-sm text-body-sm text-tertiary">Vehicles you archive from the matrix will appear here.</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($archivedRows as $row): ?>
                        <?php
                        $rowImg = vehicleCardImage($row['image']);
                        $rowSrcset = vehicleImageSrcset($row['image'], BASE_URL);
                        $seatsLabel = ($row['type'] ?? 'sedan') === 'suv' || ($row['type'] ?? '') === 'mpv' ? '7' : '5';
                        $transLabel = ucfirst($row['transmission'] ?? 'AT');
                        ?>
                        <tr class="hover:bg-surface-container-low/50 transition-colors">
                            <td class="py-4 px-5">
                                <div class="flex items-center gap-3">
                                    <?php if ($rowImg !== ''): ?>
                                    <img class="w-14 h-10 object-cover object-center rounded-lg border border-outline-variant/40 shadow-xs" loading="lazy" decoding="async" width="900" height="672" src="<?= BASE_URL ?>/<?= htmlspecialchars($rowImg) ?>"<?php if ($rowSrcset !== ''): ?> srcset="<?= htmlspecialchars($rowSrcset) ?>" sizes="56px"<?php endif; ?> alt="<?= htmlspecialchars($row['brand'] . ' ' . $row['model']) ?>">
                                    <?php else: ?>
                                    <div class="w-14 h-10 rounded-lg bg-surface-container border border-outline-variant/40 flex items-center justify-center">
                                        <i aria-hidden="true" class="bi bi-car-front text-[16px] text-tertiary"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="font-headline-sm text-headline-sm text-on-surface"><?= htmlspecialchars($row['brand'] . ' ' . $row['model']) ?></div>
                                        <div class="font-body-sm text-body-sm text-tertiary"><?= htmlspecialchars($row['year']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 px-5">
                                <span class="px-2.5 py-1 rounded-md bg-surface-container-high text-on-surface font-label-sm text-label-sm font-mono tracking-wider font-bold"><?= htmlspecialchars($row['license_plate']) ?></span>
                                <div class="font-body-sm text-body-sm text-on-surface-variant mt-1"><?= $seatsLabel ?>-Seater <?= htmlspecialchars(ucfirst($row['type'])) ?> &middot; <?= $transLabel ?></div>
                            </td>
                            <td class="py-4 px-5">
                                <div class="font-numeric-stat text-[20px] leading-tight text-on-surface"><?= formatCurrency($row['daily_rate']) ?></div>
                                <span class="font-body-sm text-body-sm text-tertiary">/ day</span>
                            </td>
                            <td class="py-4 px-5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <form method="POST" action="<?= BASE_URL ?>/admin/vehicles.php" class="contents">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="restore_vehicle" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="w-8 h-8 rounded-full hover:bg-secondary-fixed flex items-center justify-center text-primary transition-colors cursor-pointer" title="Restore to fleet" aria-label="Restore to fleet">
                                            <i aria-hidden="true" class="bi bi-arrow-counterclockwise text-[18px]"></i>
                                        </button>
                                    </form>
                                    <form method="POST" action="<?= BASE_URL ?>/admin/vehicles.php" class="contents"
                                          data-confirm="Delete this vehicle permanently? If it has any rental history it will be kept and you will be told why. This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_vehicle" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="return_view" value="archived">
                                        <button type="submit" class="w-8 h-8 rounded-full hover:bg-error-container/60 flex items-center justify-center text-tertiary hover:text-error transition-colors cursor-pointer" title="Delete permanently" aria-label="Delete vehicle permanently">
                                            <i aria-hidden="true" class="bi bi-trash text-[18px]"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($archivedCount > 0): ?>
            <div class="px-5 py-3.5 bg-surface-container-lowest border-t border-outline-variant/30">
                <?= renderPagination($pgArchived, ['summary' => true, 'noun' => 'archived vehicles']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <!-- Header Section -->
        <div class="flex flex-col gap-4">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                        <span class="font-label-sm text-label-sm uppercase tracking-wider text-tertiary">Live Telematics Sync Active</span>
                    </div>
                    <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Vehicle Operations Matrix</h1>
                    <p class="font-body-sm text-body-sm text-on-surface-variant">Real-time inventory status, diagnostics, and staging bay telemetry for Dagupan operations.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
                    <!-- Search is a GET form, so the term reaches the query, not a row-hiding script. -->
                    <form method="GET" class="relative w-full md:w-80">
                        <?php if ($typeFilter !== 'all'): ?>
                        <input type="hidden" name="type" value="<?= e($typeFilter) ?>">
                        <?php endif; ?>
                        <?php if ($bayFilter !== 'all'): ?>
                        <input type="hidden" name="bay" value="<?= e($bayFilter) ?>">
                        <?php endif; ?>
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-tertiary">
                            <i aria-hidden="true" class="bi bi-search text-[18px]"></i>
                        </span>
                        <input id="fleetSearchInput" name="q" value="<?= htmlspecialchars($q) ?>" maxlength="100" class="w-full pl-10 pr-4 py-2.5 bg-surface-container-lowest border border-outline-variant rounded-md font-body-sm text-body-sm text-on-surface placeholder:text-tertiary focus:outline-none focus:border-primary focus:ring-4 focus:ring-primary/10 transition-all" placeholder="Search plate, model, or bay..." type="text">
                    </form>
                    <a href="<?= e(queryUrl(['view' => 'archived', 'action' => null, 'id' => null])) ?>" class="inline-flex shrink-0 items-center gap-1.5 px-5 py-2.5 rounded-md bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface font-label-md font-semibold transition-all active:scale-95 whitespace-nowrap" title="Vehicles parked out of the fleet">
                        <i aria-hidden="true" class="bi bi-archive text-[18px]"></i>
                        Archive<?php if ($archivedCount > 0): ?><span class="ml-0.5 min-w-[20px] h-5 px-1.5 rounded-md bg-primary text-on-primary text-label-sm font-bold inline-flex items-center justify-center"><?= $archivedCount ?></span><?php endif; ?>
                    </a>
                    <a href="<?= e(queryUrl(['action' => 'edit', 'id' => null], false)) ?>" class="inline-flex shrink-0 items-center gap-1.5 px-5 py-2.5 rounded-md bg-primary text-on-primary font-label-md font-label-md font-semibold hover:bg-secondary transition-all active:scale-95 whitespace-nowrap">
                        <i aria-hidden="true" class="bi bi-plus-circle text-[18px]"></i>
                        Add Vehicle
                    </a>
                </div>
            </div>

            <!-- Top Summary Metrics (Minimal) -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-surface-container-lowest p-4 rounded-lg border border-outline-variant/50 shadow-sm hover:shadow-md transition-shadow">
                    <div class="font-label-md text-label-md text-tertiary">Total Units</div>
                    <div class="font-numeric-stat text-numeric-stat text-on-surface mt-1"><?= $totalCount ?></div>
                    <div class="font-body-sm text-body-sm text-tertiary mt-0.5">Central Hub allocation</div>
                </div>
                <div class="bg-surface-container-lowest p-4 rounded-lg border border-outline-variant/50 shadow-sm hover:shadow-md transition-shadow">
                    <div class="font-label-md text-label-md text-primary font-bold">Active On-Road</div>
                    <div class="font-numeric-stat text-numeric-stat text-primary mt-1"><?= $rentedCount ?></div>
                    <div class="font-body-sm text-body-sm text-tertiary mt-0.5">In customer custody</div>
                </div>
                <div class="bg-surface-container-lowest p-4 rounded-lg border border-outline-variant/50 shadow-sm hover:shadow-md transition-shadow">
                    <div class="font-label-md text-label-md text-emerald-700 font-bold">Ready for Dispatch</div>
                    <div class="font-numeric-stat text-numeric-stat text-emerald-600 mt-1"><?= $availableCount ?></div>
                    <div class="font-body-sm text-body-sm text-tertiary mt-0.5">Staged, fueled &amp; verified</div>
                </div>
                <div class="bg-surface-container-lowest p-4 rounded-lg border border-outline-variant/50 shadow-sm hover:shadow-md transition-shadow">
                    <div class="font-label-md text-label-md text-amber-700 font-bold">Staging / Inspection</div>
                    <div class="font-numeric-stat text-numeric-stat text-amber-600 mt-1"><?= $maintenanceCount ?></div>
                    <div class="font-body-sm text-body-sm text-tertiary mt-0.5">Turnaround bay detailing</div>
                </div>
            </div>

            <!-- Filter Pills & Bay Selector: real links that keep the list params and reset to page 1. -->
            <?php
            $pillOn  = 'bg-primary text-on-primary font-label-md text-label-md whitespace-nowrap shadow-sm';
            $pillOff = 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high font-label-md text-label-md whitespace-nowrap transition-colors';
            $bayOn  = 'bay-filter w-8 h-8 rounded-full bg-primary text-on-primary font-label-sm text-label-sm flex items-center justify-center font-bold shadow-xs';
            $bayOff = 'bay-filter w-8 h-8 rounded-full bg-surface-container-low text-on-surface-variant hover:bg-secondary-fixed font-label-sm text-label-sm flex items-center justify-center transition-colors';
            ?>
            <div class="bg-surface-container-lowest p-3 rounded-lg border border-outline-variant/40 shadow-sm flex flex-col lg:flex-row lg:items-center justify-between gap-3">
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0">
                    <a href="<?= e(queryUrl(['type' => null])) ?>" class="fleet-filter-tab px-4 py-1.5 rounded-md <?= $typeFilter === 'all' ? $pillOn : $pillOff ?>">All <?= $totalCount ?></a>
                    <a href="<?= e(queryUrl(['type' => 'sedan'])) ?>" class="fleet-filter-tab px-4 py-1.5 rounded-md <?= $typeFilter === 'sedan' ? $pillOn : $pillOff ?>">Sedans <?= $sedanCount ?></a>
                    <a href="<?= e(queryUrl(['type' => 'suv'])) ?>" class="fleet-filter-tab px-4 py-1.5 rounded-md <?= $typeFilter === 'suv' ? $pillOn : $pillOff ?>">SUVs <?= $suvCount ?></a>
                    <a href="<?= e(queryUrl(['type' => 'mpv'])) ?>" class="fleet-filter-tab px-4 py-1.5 rounded-md <?= $typeFilter === 'mpv' ? $pillOn : $pillOff ?>">MPVs <?= $mpvCount ?></a>
                    <a href="<?= e(queryUrl(['type' => 'van'])) ?>" class="fleet-filter-tab px-4 py-1.5 rounded-md <?= $typeFilter === 'van' ? $pillOn : $pillOff ?>">Vans <?= $vanCount ?></a>
                    <?php /* Shown even at zero, so a deep link to an empty set keeps the active pill. */ ?>
                    <?php if ($otherCount > 0 || $typeFilter === 'other'): ?>
                    <a href="<?= e(queryUrl(['type' => 'other'])) ?>" class="fleet-filter-tab px-4 py-1.5 rounded-md <?= $typeFilter === 'other' ? $pillOn : $pillOff ?>">Other <?= $otherCount ?></a>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 overflow-x-auto scrollbar-none pt-1 lg:pt-0">
                    <span class="font-label-sm text-label-sm text-tertiary whitespace-nowrap">Dagupan Staging Bay:</span>
                    <div class="flex items-center gap-1">
                        <a href="<?= e(queryUrl(['bay' => null])) ?>" class="<?= $bayFilter === 'all' ? $bayOn : $bayOff ?>">All</a>
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                        <a href="<?= e(queryUrl(['bay' => $i])) ?>" class="<?= $bayFilter === (string)$i ? $bayOn : $bayOff ?>">B<?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vehicle Inventory Table: pinned to the container width, so the grid never scrolls sideways. -->
        <div class="bg-surface-container-lowest rounded-lg border border-outline-variant/50 shadow-sm overflow-hidden">
            <div class="w-full">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
                            <th scope="col" class="py-3.5 px-5">Vehicle &amp; Model</th>
                            <th scope="col" class="py-3.5 px-5">Plate &amp; Specs</th>
                            <th scope="col" class="py-3.5 px-5">Fuel</th>
                            <th scope="col" class="py-3.5 px-5">GPS Location / Bay</th>
                            <th scope="col" class="py-3.5 px-5">Status</th>
                            <th scope="col" class="py-3.5 px-5">Daily Rate</th>
                            <th scope="col" class="py-3.5 px-5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
                        <?php if (empty($vehicleRows)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-12">
                                <div class="flex flex-col items-center gap-3">
                                    <i aria-hidden="true" class="bi bi-droplet text-[48px] text-outline-variant"></i>
                                    <p class="font-body-lg text-body-lg text-on-surface-variant">No vehicles found</p>
                                    <?php if ($q !== '' || $bayFilter !== 'all' || $typeFilter !== 'all'): ?>
                                    <p class="font-body-sm text-body-sm text-tertiary">Nothing matches the current search and filters.</p>
                                    <a href="<?= e(queryUrl(['q' => null, 'bay' => null, 'type' => null])) ?>" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface-variant text-label-md font-label-md hover:bg-surface-container transition-colors">
                                        <i aria-hidden="true" class="bi bi-arrow-counterclockwise text-[16px]"></i>
                                        Clear search &amp; filters
                                    </a>
                                    <?php else: ?>
                                    <p class="font-body-sm text-body-sm text-tertiary">No vehicles in the fleet yet. Add your first vehicle above.</p>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php
                        $fuelStatusMap = ['available' => [90, 'Full-to-Full verified'], 'rented' => [62, 'Active telemetry feed'], 'in_progress' => [62, 'Active telemetry feed'], 'maintenance' => [45, 'Inspection in progress'], 'unavailable' => [30, 'Under service']];
                        $fuelColorMap = [90 => 'bg-emerald-500', 62 => 'bg-primary', 45 => 'bg-amber-500', 30 => 'bg-rose-500'];
                        $locMap = ['available' => ['Docked at the Hub', 'geo-alt-fill', 'text-primary'], 'rented' => ['Live in transit', 'cursor', 'text-amber-500'], 'in_progress' => ['Live in transit', 'cursor', 'text-amber-500'], 'maintenance' => ['Detailing Bay', 'wrench', 'text-amber-500'], 'unavailable' => ['Maintenance Bay', 'wrench', 'text-rose-500']];
                        foreach ($vehicleRows as $row):
                            $st = $row['status'] ?? 'available';
                            $bayNum = ((int)$row['id'] % 8) + 1;
                            $fuelPct = $fuelStatusMap[$st][0] ?? 70;
                            $fuelColor = 'bg-emerald-500';
                            if ($fuelPct < 50) $fuelColor = 'bg-amber-500';
                            elseif ($fuelPct < 75) $fuelColor = 'bg-primary';
                            $locInfo = $locMap[$st] ?? $locMap['available'];
                            $seatsLabel = ($row['type'] ?? 'sedan') === 'suv' || ($row['type'] ?? '') === 'mpv' ? '7' : '5';
                            $transLabel = ucfirst($row['transmission'] ?? 'AT');
                        ?>
                        <tr class="fleet-row hover:bg-surface-container-low/50 transition-colors group cursor-pointer" data-status="<?= htmlspecialchars($st) ?>" data-type="<?= htmlspecialchars(strtolower($row['type'] ?? '')) ?>" data-bay="<?= $bayNum ?>" data-search="<?= htmlspecialchars(strtolower($row['brand'] . ' ' . $row['model'] . ' ' . $row['license_plate'] . ' Bay ' . $bayNum)) ?>" onclick="openVehicleDrawer(this)">
                            <td class="py-4 px-5">
                                <div class="flex items-center gap-3">
                                    <?php
                                    $rowImg = vehicleCardImage($row['image']);
                                    $rowSrcset = vehicleImageSrcset($row['image'], BASE_URL);
                                    ?>
                                    <?php if ($rowImg !== ''): ?>
                                    <img class="w-14 h-10 object-cover object-center rounded-lg border border-outline-variant/40 shadow-xs" loading="lazy" decoding="async" width="900" height="672" src="<?= BASE_URL ?>/<?= htmlspecialchars($rowImg) ?>"<?php if ($rowSrcset !== ''): ?> srcset="<?= htmlspecialchars($rowSrcset) ?>" sizes="56px"<?php endif; ?> alt="<?= htmlspecialchars($row['brand'] . ' ' . $row['model']) ?>">
                                    <?php else: ?>
                                    <div class="w-14 h-10 rounded-lg bg-gradient-to-br from-surface-container via-primary/5 to-secondary-fixed/20 border border-outline-variant/40 flex items-center justify-center">
                                        <i aria-hidden="true" class="bi bi-car-front text-[16px] text-primary/40"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="font-headline-sm text-headline-sm text-on-surface group-hover:text-primary transition-colors">
                                            <?= htmlspecialchars($row['brand'] . ' ' . $row['model']) ?>
                                        </div>
                                        <div class="font-body-sm text-body-sm text-tertiary"><?= htmlspecialchars($row['year']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 px-5">
                                <span class="px-2.5 py-1 rounded-md bg-surface-container-high text-on-surface font-label-sm text-label-sm font-mono tracking-wider font-bold"><?= htmlspecialchars($row['license_plate']) ?></span>
                                <div class="font-body-sm text-body-sm text-on-surface-variant mt-1"><?= $seatsLabel ?>-Seater <?= htmlspecialchars(ucfirst($row['type'])) ?> &middot; <?= $transLabel ?></div>
                            </td>
                            <td class="py-4 px-5">
                                <div class="flex items-center gap-2">
                                    <div class="w-24 h-2 bg-surface-container-high rounded-md overflow-hidden">
                                        <div class="<?= $fuelColor ?> h-full rounded-md" style="width: <?= $fuelPct ?>%;"></div>
                                    </div>
                                    <span class="font-label-sm text-label-sm <?= $fuelPct >= 75 ? 'text-emerald-700' : ($fuelPct >= 50 ? 'text-primary' : 'text-amber-700') ?> font-bold"><?= $fuelPct ?>%</span>
                                </div>
                                <div class="font-label-sm text-label-sm text-tertiary text-[10px]"><?= $fuelStatusMap[$st][1] ?? 'Full-to-Full verified' ?></div>
                            </td>
                            <td class="py-4 px-5">
                                <div class="flex items-center gap-1.5 text-on-surface font-body-sm text-body-sm">
                                    <i aria-hidden="true" class="bi bi-<?= $locInfo[1] ?> <?= $locInfo[2] ?> text-[18px]"></i>
                                    <?= $locInfo[0] ?>
                                </div>
                                <span class="font-label-sm text-label-sm <?= $st === 'maintenance' || $st === 'unavailable' ? 'text-amber-700 bg-amber-50' : 'text-primary bg-primary/10' ?> px-2 py-0.5 rounded-md inline-block mt-1">Staging Bay <?= $bayNum ?></span>
                            </td>
                            <td class="py-4 px-5">
                                <span class="px-3 py-1 rounded-md <?= getStatusPillClasses($st) ?> font-label-sm text-label-sm inline-flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $st === 'available' ? 'bg-emerald-600' : ($st === 'rented' || $st === 'in_progress' ? 'bg-primary animate-ping' : 'bg-rose-600') ?>"></span>
                                    <?= htmlspecialchars(ucfirst($st === 'in_progress' ? 'rented' : $st)) ?>
                                </span>
                            </td>
                            <td class="py-4 px-5">
                                <div class="font-numeric-stat text-[20px] leading-tight text-on-surface"><?= formatCurrency($row['daily_rate']) ?></div>
                                <span class="font-body-sm text-body-sm text-tertiary">/ day</span>
                            </td>
                            <td class="py-4 px-5 text-right" onclick="event.stopPropagation()">
                                <div class="flex items-center justify-end gap-1.5">
                                    <a href="<?= e(queryUrl(['action' => 'edit', 'id' => (int)$row['id']], false)) ?>" class="w-8 h-8 rounded-full hover:bg-secondary-fixed flex items-center justify-center text-primary transition-colors" title="Edit specs" aria-label="Edit specs">
                                        <i aria-hidden="true" class="bi bi-pencil-square text-[18px]"></i>
                                    </a>
                                    <form method="POST" action="<?= BASE_URL ?>/admin/vehicles.php" class="contents"
                                          data-confirm="Move this vehicle to the archive? It leaves the operations matrix and the customer catalog, but nothing is deleted and you can restore it from the Archive at any time.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="archive_vehicle" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="w-8 h-8 rounded-full hover:bg-surface-container-high flex items-center justify-center text-tertiary hover:text-primary transition-colors cursor-pointer" title="Archive vehicle" aria-label="Archive vehicle">
                                            <i class="bi bi-archive text-[18px]" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Table Footer: the shared helper renders the real pager and summary now the matrix pages. -->
            <?php if ($totalFleet > 0): ?>
            <div class="px-5 py-3.5 bg-surface-container-lowest border-t border-outline-variant/30">
                <?= renderPagination($pgFleet, ['summary' => true, 'noun' => 'vehicles']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

<!-- Quick-View Slide-Over Panel -->
<div class="fixed inset-0 bg-on-surface/25 backdrop-blur-sm z-50 transition-opacity duration-300 opacity-0 pointer-events-none" id="vehicle-drawer-backdrop" onclick="closeVehicleDrawer()">
    <div class="absolute top-0 right-0 h-full w-full max-w-md md:max-w-lg bg-surface-container-lowest shadow-2xl border-l border-outline-variant/40 flex flex-col overflow-y-auto transform transition-transform duration-300 ease-in-out translate-x-full" id="vehicle-drawer" onclick="event.stopPropagation()">
        <div class="px-5 py-3.5 border-b border-outline-variant/30 flex items-center justify-between sticky top-0 bg-surface-container-lowest/90 backdrop-blur-md z-10">
            <div>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-md bg-surface-container-high text-on-surface-variant font-label-sm text-label-sm font-bold uppercase">Quick View</span>
                </div>
                <h2 class="font-headline-md text-headline-md text-on-surface mt-1" id="drawer-title">Vehicle Name</h2>
            </div>
            <button type="button" class="w-9 h-9 rounded-full hover:bg-surface-container-high flex items-center justify-center text-tertiary hover:text-on-surface transition-colors" onclick="closeVehicleDrawer()" aria-label="Close">
                <i aria-hidden="true" class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="p-5 space-y-5">
            <div class="relative rounded-lg overflow-hidden border border-outline-variant/40 bg-surface-container-low">
                <img class="w-full h-48 object-cover object-center hidden" id="drawer-photo" alt="Vehicle photo" width="900" height="672" loading="lazy" decoding="async">
                <div class="w-full h-48 bg-gradient-to-br from-surface-container via-primary/5 to-secondary-fixed/20 flex items-center justify-center" id="drawer-photo-placeholder">
                    <i aria-hidden="true" class="bi bi-car-front text-[64px] text-primary/20"></i>
                </div>
                <div class="absolute bottom-3 left-3 right-3 flex items-center justify-between">
                    <span class="px-3 py-1 rounded-lg bg-surface-container-lowest/90 backdrop-blur-sm text-on-surface font-label-md text-label-md font-mono font-bold shadow-sm" id="drawer-plate">—</span>
                    <span class="px-3 py-1 rounded-md bg-primary text-on-primary font-label-md text-label-md font-bold shadow-sm" id="drawer-rate">— / day</span>
                </div>
            </div>
            <div class="p-4 rounded-lg bg-surface-container-low border border-outline-variant/30 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="font-label-md text-label-md text-tertiary">Hub Staging Assignment</span>
                    <span class="font-label-lg text-label-lg text-primary font-bold" id="drawer-bay">Bay — (Central Hub)</span>
                </div>
                <div class="pt-2 border-t border-outline-variant/30">
                    <div class="p-2 rounded-lg bg-surface-container-lowest text-center">
                        <div class="font-label-sm text-label-sm text-tertiary">Status</div>
                        <div class="font-label-lg text-label-lg text-on-surface font-bold mt-0.5" id="drawer-status">—</div>
                    </div>
                </div>
            </div>
            <div class="space-y-3">
                <h3 class="font-headline-sm text-headline-sm text-on-surface">Hub Dispatch Checklist</h3>
                <div class="space-y-2">
                    <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-lowest border border-outline-variant/30">
                        <div class="flex items-center gap-2.5">
                            <i aria-hidden="true" class="bi bi-check-circle text-emerald-600 text-[20px]"></i>
                            <span class="font-body-sm text-body-sm text-on-surface">Interior Sanitization &amp; Cleaning</span>
                        </div>
                        <span class="font-label-sm text-label-sm text-tertiary">Completed</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-lowest border border-outline-variant/30">
                        <div class="flex items-center gap-2.5">
                            <i aria-hidden="true" class="bi bi-check-circle text-emerald-600 text-[20px]"></i>
                            <span class="font-body-sm text-body-sm text-on-surface">Full-to-Full Tank Verification</span>
                        </div>
                        <span class="font-label-sm text-label-sm text-tertiary">Verified</span>
                    </div>
                    <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-lowest border border-outline-variant/30">
                        <div class="flex items-center gap-2.5">
                            <i aria-hidden="true" class="bi bi-check-circle text-emerald-600 text-[20px]"></i>
                            <span class="font-body-sm text-body-sm text-on-surface">Tire Tread &amp; Fluid Inspection</span>
                        </div>
                        <span class="font-label-sm text-label-sm text-tertiary">Passed</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="px-5 py-3.5 border-t border-outline-variant/30 bg-surface-container-lowest mt-auto flex items-center justify-center gap-3">
            <a href="<?= BASE_URL ?>/admin/vehicles.php" id="drawer-edit-btn" class="w-11 h-11 rounded-full bg-primary text-on-primary hover:bg-primary-container transition-colors shadow-md inline-flex items-center justify-center" title="Edit specs" aria-label="Edit specs">
                <i aria-hidden="true" class="bi bi-pencil-square text-[20px]"></i>
            </a>
            <button type="button" class="w-11 h-11 rounded-full bg-surface-container-low text-primary hover:bg-surface-container-high inline-flex items-center justify-center transition-colors" onclick="closeVehicleDrawer()" title="Close" aria-label="Close panel">
                <i aria-hidden="true" class="bi bi-x-lg text-[20px]"></i>
            </button>
        </div>
    </div>
</div>

<script>
// WHY: filtering and paging are resolved in SQL now, so this block only opens the quick-view drawer.
function openVehicleDrawer(row) {
    var backdrop = document.getElementById('vehicle-drawer-backdrop');
    var drawer = document.getElementById('vehicle-drawer');
    var name = row.querySelector('td:first-child .font-headline-sm');
    var plate = row.querySelector('td:nth-child(2) .font-mono');
    var specs = row.querySelector('td:nth-child(2) .font-body-sm');
    var rate = row.querySelector('td:nth-child(6) .font-numeric-stat');
    var bay = row.querySelector('td:nth-child(4) .font-label-sm');
    var status = row.querySelector('td:nth-child(5) .font-label-sm');

    document.getElementById('drawer-title').textContent = name ? name.textContent.trim() : 'Vehicle';
    document.getElementById('drawer-plate').textContent = plate ? plate.textContent.trim() : '—';
    document.getElementById('drawer-rate').textContent = rate ? rate.textContent.trim() + ' / day' : '— / day';
    document.getElementById('drawer-bay').textContent = bay ? bay.textContent.trim() + ' (Central Hub)' : 'Bay — (Central Hub)';
    document.getElementById('drawer-status').textContent = status ? status.textContent.trim() : '—';

    // Show the row's real photo when it has one; otherwise keep the placeholder, not the blank gradient.
    var rowThumb = row.querySelector('td:first-child img');
    var photo = document.getElementById('drawer-photo');
    var placeholder = document.getElementById('drawer-photo-placeholder');
    if (rowThumb && photo && (rowThumb.currentSrc || rowThumb.src)) {
        photo.src = rowThumb.currentSrc || rowThumb.src;
        photo.alt = name ? name.textContent.trim() : 'Vehicle photo';
        photo.classList.remove('hidden');
        if (placeholder) placeholder.classList.add('hidden');
    } else {
        if (photo) photo.classList.add('hidden');
        if (placeholder) placeholder.classList.remove('hidden');
    }
    // Point the drawer CTA at the row's edit form; without one, drop the href so it stops looking operable.
    var editLink = row.querySelector('a[href*="action=edit"]');
    var editBtn = document.getElementById('drawer-edit-btn');
    if (editLink) {
        editBtn.href = editLink.href;
        editBtn.classList.remove('opacity-50', 'pointer-events-none', 'cursor-not-allowed');
    } else {
        editBtn.removeAttribute('href');
        editBtn.classList.add('opacity-50', 'pointer-events-none', 'cursor-not-allowed');
    }

    backdrop.classList.remove('opacity-0', 'pointer-events-none');
    backdrop.classList.add('opacity-100', 'pointer-events-auto');
    drawer.classList.remove('translate-x-full');
    drawer.classList.add('translate-x-0');
}

function closeVehicleDrawer() {
    var backdrop = document.getElementById('vehicle-drawer-backdrop');
    var drawer = document.getElementById('vehicle-drawer');
    backdrop.classList.add('opacity-0', 'pointer-events-none');
    backdrop.classList.remove('opacity-100', 'pointer-events-auto');
    drawer.classList.add('translate-x-full');
    drawer.classList.remove('translate-x-0');
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
