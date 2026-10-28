<?php
/* maintenance/dashboard.php - Carvella Car Rental System */
$pageTitle = 'Maintenance Dashboard - Carvella';
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();

$flash = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $description = sanitize($_POST['description'] ?? '');
        $cost = (float)($_POST['cost'] ?? 0);
        $status = $_POST['status'] ?? 'pending';
        $scheduledDate = $_POST['scheduled_date'] ?? null;
        if ($scheduledDate === '') $scheduledDate = null;

        $allowedStatuses = ['pending', 'in_progress', 'completed'];
        if (!in_array($status, $allowedStatuses, true)) $status = 'pending';

        if ($vehicleId <= 0 || $description === '') {
            $flash = 'Vehicle and description are required.';
            $flashType = 'error';
        } else {
            $completedDate = ($status === 'completed') ? date('Y-m-d') : null;
            $stmt = $conn->prepare("INSERT INTO maintenance (vehicle_id, description, cost, status, scheduled_date, completed_date) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isdsss", $vehicleId, $description, $cost, $status, $scheduledDate, $completedDate);
            if ($stmt->execute()) {
                $newId = $conn->insert_id;
                logActivity('created', "Maintenance record #$newId created for vehicle #$vehicleId");
                $flash = 'Maintenance record created.';
                $flashType = 'success';
                if (function_exists('vehicleIsOnRoad')) {
                    $onRoad = vehicleIsOnRoad($conn, $vehicleId);
                    if (!$onRoad) {
                        $curStmt = $conn->prepare("SELECT status FROM vehicles WHERE id = ?");
                        $curStmt->bind_param("i", $vehicleId);
                        $curStmt->execute();
                        $curRow = $curStmt->get_result()->fetch_assoc();
                        $curStmt->close();
                        $current = strtolower((string)($curRow['status'] ?? ''));
                        if (!in_array($current, ['archived', 'unavailable', 'rented'], true)) {
                            $upd = $conn->prepare("UPDATE vehicles SET status = 'maintenance' WHERE id = ?");
                            $upd->bind_param("i", $vehicleId);
                            $upd->execute();
                            $upd->close();
                        }
                    }
                }
            } else {
                $flash = 'Could not create maintenance record.';
                $flashType = 'error';
            }
            $stmt->close();
        }
    } elseif ($action === 'update_status') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        $newStatus = $_POST['status'] ?? 'pending';
        if (!in_array($newStatus, ['pending', 'in_progress', 'completed'], true)) $newStatus = 'pending';
        if ($recordId > 0) {
            // Look up the vehicle behind this record so the fleet status can follow.
            $vehStmt = $conn->prepare("SELECT vehicle_id FROM maintenance WHERE id = ?");
            $vehStmt->bind_param("i", $recordId);
            $vehStmt->execute();
            $vehRow = $vehStmt->get_result()->fetch_assoc();
            $vehStmt->close();
            $vehicleId = $vehRow ? (int)$vehRow['vehicle_id'] : 0;

            $completedDate = ($newStatus === 'completed') ? date('Y-m-d') : null;
            $stmt = $conn->prepare("UPDATE maintenance SET status = ?, completed_date = ? WHERE id = ?");
            $stmt->bind_param("ssi", $newStatus, $completedDate, $recordId);
            if ($stmt->execute()) {
                logActivity('updated', "Maintenance record #$recordId status set to $newStatus");
                $flash = 'Status updated.';
                $flashType = 'success';

                // When a maintenance job is completed (and no other open jobs remain),
                // return the vehicle to the available fleet. Keep rented/on-road,
                // archived and deliberately-unavailable units untouched.
                if ($vehicleId > 0 && function_exists('vehicleHasOpenMaintenance') && function_exists('vehicleIsOnRoad')) {
                    $onRoad = vehicleIsOnRoad($conn, $vehicleId);
                    $openMaintenance = vehicleHasOpenMaintenance($conn, $vehicleId);
                    if (!$onRoad) {
                        $curStmt = $conn->prepare("SELECT status FROM vehicles WHERE id = ?");
                        $curStmt->bind_param("i", $vehicleId);
                        $curStmt->execute();
                        $curRow = $curStmt->get_result()->fetch_assoc();
                        $curStmt->close();
                        $current = strtolower((string)($curRow['status'] ?? ''));
                        if (!in_array($current, ['archived', 'unavailable'], true)) {
                            $next = $openMaintenance ? 'maintenance' : 'available';
                            if ($next !== $current) {
                                $upd = $conn->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
                                $upd->bind_param("si", $next, $vehicleId);
                                $upd->execute();
                                $upd->close();
                            }
                        }
                    }
                }
            } else {
                $flash = 'Could not update status.';
                $flashType = 'error';
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        if ($recordId > 0) {
            $vehStmt = $conn->prepare("SELECT vehicle_id FROM maintenance WHERE id = ?");
            $vehStmt->bind_param("i", $recordId);
            $vehStmt->execute();
            $vehRow = $vehStmt->get_result()->fetch_assoc();
            $vehStmt->close();
            $vehicleId = $vehRow ? (int)$vehRow['vehicle_id'] : 0;

            $del = $conn->prepare("DELETE FROM maintenance WHERE id = ?");
            $del->bind_param("i", $recordId);
            if ($del->execute()) {
                logActivity('deleted', "Maintenance record #$recordId removed");
                $flash = 'Maintenance record deleted.';
                $flashType = 'success';
                if ($vehicleId > 0 && function_exists('vehicleHasOpenMaintenance') && function_exists('vehicleIsOnRoad')) {
                    $onRoad = vehicleIsOnRoad($conn, $vehicleId);
                    $openMaintenance = vehicleHasOpenMaintenance($conn, $vehicleId);
                    if (!$onRoad) {
                        $curStmt = $conn->prepare("SELECT status FROM vehicles WHERE id = ?");
                        $curStmt->bind_param("i", $vehicleId);
                        $curStmt->execute();
                        $curRow = $curStmt->get_result()->fetch_assoc();
                        $curStmt->close();
                        $current = strtolower((string)($curRow['status'] ?? ''));
                        if (!in_array($current, ['archived', 'unavailable'], true)) {
                            $next = $openMaintenance ? 'maintenance' : 'available';
                            if ($next !== $current) {
                                $upd = $conn->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
                                $upd->bind_param("si", $next, $vehicleId);
                                $upd->execute();
                                $upd->close();
                            }
                        }
                    }
                }
            } else {
                $flash = 'Could not delete record.';
                $flashType = 'error';
            }
            $del->close();
        }
    }
}

$vehicles = $conn->query("SELECT id, brand, model, year, license_plate, status FROM vehicles WHERE status <> 'archived' ORDER BY brand, model");

$allowedSorts = [
    'id'             => 'm.id',
    'vehicle'        => 'v.brand, v.model',
    'description'    => 'm.description',
    'cost'           => 'm.cost',
    'scheduled_date' => 'm.scheduled_date',
    'status'         => 'm.status',
    'created_at'     => 'm.created_at',
];
$sort = isset($_GET['sort']) && isset($allowedSorts[$_GET['sort']]) ? $_GET['sort'] : 'created_at';
$dir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';
$orderBy = $allowedSorts[$sort] . ' ' . $dir;

$statusFilter = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'in_progress', 'completed'], true) ? $_GET['status'] : '';
$search = trim((string)($_GET['search'] ?? ''));

$whereParts = [];
$params = [];
$types = '';
if ($statusFilter !== '') {
    $whereParts[] = "m.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($search !== '') {
    $whereParts[] = "(v.brand LIKE ? OR v.model LIKE ? OR v.license_plate LIKE ? OR m.description LIKE ? OR m.status LIKE ?)";
    $term = '%' . $search . '%';
    for ($i = 0; $i < 5; $i++) {
        $params[] = $term;
        $types .= 's';
    }
}
$where = $whereParts ? " WHERE " . implode(" AND ", $whereParts) : '';

$countSql = "SELECT COUNT(*) AS c FROM maintenance m JOIN vehicles v ON m.vehicle_id = v.id" . $where;
if ($types !== '') {
    $cStmt = $conn->prepare($countSql);
    $cStmt->bind_param($types, ...$params);
    $cStmt->execute();
    $totalRecords = (int)$cStmt->get_result()->fetch_assoc()['c'];
    $cStmt->close();
} else {
    $totalRecords = (int)$conn->query($countSql)->fetch_assoc()['c'];
}

// Excel export of the filtered set.
if (isset($_GET['export'])) {
    $expSql = "SELECT m.id, v.brand, v.model, v.license_plate, m.description, m.cost, m.status, m.scheduled_date, m.completed_date, m.created_at
               FROM maintenance m JOIN vehicles v ON m.vehicle_id = v.id" . $where . " ORDER BY $orderBy";
    $expStmt = $conn->prepare($expSql);
    if ($types !== '') {
        $expStmt->bind_param($types, ...$params);
    }
    $expStmt->execute();
    $expResult = $expStmt->get_result();
    $rows = [];
    while ($row = $expResult->fetch_assoc()) {
        $rows[] = $row;
    }
    $expStmt->close();
    carvella_export_xlsx('maintenance_records_' . date('Y-m-d') . '.xlsx', ['ID', 'Brand', 'Model', 'License Plate', 'Description', 'Cost', 'Status', 'Scheduled Date', 'Completed Date', 'Created At'], $rows);
}

$pg = paginationBounds(paginationPage(), $totalRecords, 15);
$perPage = $pg['per_page'];
$offset = $pg['offset'];

$sql = "SELECT m.*, v.brand, v.model, v.year, v.license_plate FROM maintenance m JOIN vehicles v ON m.vehicle_id = v.id" . $where . " ORDER BY $orderBy LIMIT ? OFFSET ?";
$params2 = array_merge($params, [$perPage, $offset]);
$types2 = $types . 'ii';
$stmt = $conn->prepare($sql);
$stmt->bind_param($types2, ...$params2);
$stmt->execute();
$records = $stmt->get_result();

$pendingCount = (int)$conn->query("SELECT COUNT(*) c FROM maintenance WHERE status='pending'")->fetch_assoc()['c'];
$inProgressCount = (int)$conn->query("SELECT COUNT(*) c FROM maintenance WHERE status='in_progress'")->fetch_assoc()['c'];
$completedCount = (int)$conn->query("SELECT COUNT(*) c FROM maintenance WHERE status='completed'")->fetch_assoc()['c'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20 px-4 sm:px-6 md:px-12 max-w-container-max-width mx-auto w-full space-y-6">
    <?php if ($flash): ?>
    <div class="px-4 py-3 rounded-lg <?= $flashType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-error-container text-on-error-container border border-error-container' ?> text-body-sm font-body-sm">
        <?= htmlspecialchars($flash) ?>
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Maintenance Dashboard</h1>
            <p class="text-body-sm text-on-surface-variant">Vehicle service and repair records</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm">
            <span class="text-label-sm text-amber-800">Pending</span>
            <div class="text-numeric-stat font-extrabold text-amber-600 mt-1"><?= $pendingCount ?></div>
        </div>
        <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm">
            <span class="text-label-sm text-primary">In Progress</span>
            <div class="text-numeric-stat font-extrabold text-primary mt-1"><?= $inProgressCount ?></div>
        </div>
        <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm">
            <span class="text-label-sm text-emerald-800">Completed</span>
            <div class="text-numeric-stat font-extrabold text-emerald-700 mt-1"><?= $completedCount ?></div>
        </div>
    </div>

    <!-- Add Maintenance Record -->
    <div class="bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/40 shadow-sm">
        <h2 class="text-headline-sm font-bold text-on-surface mb-4">Add Maintenance Record</h2>
        <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div>
                <label class="block text-label-md font-label-md text-on-surface mb-2">Vehicle</label>
                <select name="vehicle_id" required class="w-full px-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface">
                    <option value="">Select vehicle</option>
                    <?php if ($vehicles): while ($v = $vehicles->fetch_assoc()): ?>
                    <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['brand'] . ' ' . $v['model'] . ' (' . $v['license_plate'] . ')') ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div>
                <label class="block text-label-md font-label-md text-on-surface mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface">
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div>
                <label class="block text-label-md font-label-md text-on-surface mb-2">Scheduled Date</label>
                <input type="date" name="scheduled_date" class="w-full px-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface">
            </div>
            <div>
                <label class="block text-label-md font-label-md text-on-surface mb-2">Cost</label>
                <input type="number" step="0.01" min="0" name="cost" value="0" class="w-full px-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface">
            </div>
            <div class="md:col-span-2">
                <label class="block text-label-md font-label-md text-on-surface mb-2">Description</label>
                <textarea name="description" required rows="3" class="w-full px-4 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface"></textarea>
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="px-6 py-2.5 rounded-md bg-primary text-on-primary font-label-md font-semibold hover:bg-secondary transition-all shadow-sm">Save Record</button>
            </div>
        </form>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <form method="GET" action="<?= BASE_URL ?>/maintenance/dashboard.php" class="flex items-center gap-2 flex-1 max-w-xl">
        <?php if ($sort !== 'created_at'): ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <?php endif; ?>
        <?php if ($dir !== 'DESC'): ?>
        <input type="hidden" name="dir" value="<?= htmlspecialchars(strtolower($dir)) ?>">
        <?php endif; ?>
        <?php if ($statusFilter !== ''): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
        <?php endif; ?>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search vehicle, description, status..." class="w-full px-4 py-2 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface">
        <button type="submit" class="px-4 py-2 rounded-md bg-primary text-on-primary text-label-md font-label-md">Search</button>
      </form>
    </div>
    <?php
    $filterUrl = function ($s) use ($sort, $dir) {
        $params = ['sort' => $sort, 'dir' => strtolower($dir)];
        if ($s !== '') $params['status'] = $s;
        return BASE_URL . '/maintenance/dashboard.php?' . http_build_query($params);
    };
    ?>
    <div class="flex items-center gap-2 border-b border-outline-variant/30 pb-1">
      <a href="<?= $filterUrl('') ?>" class="px-4 py-2 rounded-md text-label-md font-label-md <?= $statusFilter === '' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> transition-colors">All</a>
      <a href="<?= $filterUrl('pending') ?>" class="px-4 py-2 rounded-md text-label-md font-label-md <?= $statusFilter === 'pending' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> transition-colors">Pending</a>
      <a href="<?= $filterUrl('in_progress') ?>" class="px-4 py-2 rounded-md text-label-md font-label-md <?= $statusFilter === 'in_progress' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> transition-colors">In Progress</a>
      <a href="<?= $filterUrl('completed') ?>" class="px-4 py-2 rounded-md text-label-md font-label-md <?= $statusFilter === 'completed' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> transition-colors">Completed</a>
    </div>

    <!-- Records Table -->
    <div class="bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/40 shadow-sm overflow-hidden">
        <h2 class="text-headline-sm font-bold text-on-surface mb-4">Maintenance Records</h2>
        <div class="overflow-x-auto">
            <?php
        $mSortLink = function ($key, $label) use ($sort, $dir) {
            $nextDir = ($sort === $key && $dir === 'ASC') ? 'desc' : 'asc';
            $arrow = '';
            if ($sort === $key) {
                $arrow = $dir === 'ASC' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
            }
            $url = BASE_URL . '/maintenance/dashboard.php?' . http_build_query(['sort' => $key, 'dir' => $nextDir]);
            return '<a href="' . $url . '" class="hover:text-primary transition-colors">' . $label . $arrow . '</a>';
        };
        ?>
        <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
                        <th class="py-3 px-4"><?= $mSortLink('id', 'ID') ?></th>
                        <th class="py-3 px-4"><?= $mSortLink('vehicle', 'Vehicle') ?></th>
                        <th class="py-3 px-4"><?= $mSortLink('description', 'Description') ?></th>
                        <th class="py-3 px-4"><?= $mSortLink('cost', 'Cost') ?></th>
                        <th class="py-3 px-4"><?= $mSortLink('scheduled_date', 'Scheduled') ?></th>
                        <th class="py-3 px-4"><?= $mSortLink('status', 'Status') ?></th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-outline-variant/20 text-body-sm">
                    <?php if ($records): while ($r = $records->fetch_assoc()): ?>
                    <tr class="hover:bg-surface-container-low transition-colors">
                        <td class="py-3 px-4">#<?= (int)$r['id'] ?></td>
                        <td class="py-3 px-4"><?= htmlspecialchars($r['brand'] . ' ' . $r['model'] . ' (' . $r['license_plate'] . ')') ?></td>
                        <td class="py-3 px-4"><?= htmlspecialchars($r['description']) ?></td>
                        <td class="py-3 px-4"><?= formatCurrency($r['cost']) ?></td>
                        <td class="py-3 px-4"><?= $r['scheduled_date'] ? htmlspecialchars($r['scheduled_date']) : '—' ?></td>
                        <td class="py-3 px-4">
                            <form method="POST" action="" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                                <select name="status" onchange="this.form.submit()" class="px-2 py-1 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface text-[12px]">
                                    <?php foreach (['pending','in_progress','completed'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $r['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="py-3 px-4 text-right">
                            <form method="POST" action="" class="inline" onsubmit="return confirm('Delete this maintenance record?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="record_id" value="<?= (int)$r['id'] ?>">
                                <button type="submit" class="px-3 py-1 rounded-md bg-error-container text-on-error-container text-[12px] font-bold hover:opacity-90">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pg['total'] > 0): ?>
        <div class="px-5 py-3.5 border-t border-outline-variant/30 bg-surface-container-low/30">
          <?= renderPagination($pg, ['summary' => true, 'noun' => 'records']) ?>
        </div>
        <?php endif; ?>
    </div>
</main>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=20261012a"></script>
<script type="module" src="<?= BASE_URL ?>/assets/js/app-motion.js?v=20261013g"></script>