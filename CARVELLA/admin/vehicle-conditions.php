<?php
/* admin/vehicle-conditions.php - Admin vehicle condition reports. */

$pageTitle = 'Vehicle Conditions - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_condition'])) {
    verify_csrf();

    $conditionVehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $conditionType = strtolower(sanitize($_POST['condition_type'] ?? ''));
    $conditionNotes = sanitize($_POST['notes'] ?? '');
    $conditionTypes = ['excellent', 'good', 'fair', 'poor'];

    if ($conditionVehicleId <= 0) {
        setFlash('error', 'Please choose a vehicle for this inspection.');
    } elseif (!in_array($conditionType, $conditionTypes, true)) {
        setFlash('error', 'Please choose a valid condition rating.');
    } elseif ($conditionNotes === '') {
        setFlash('error', 'Please describe what was inspected.');
    } elseif (mb_strlen($conditionNotes) > 1000) {
        setFlash('error', 'Notes must be 1000 characters or fewer.');
    } else {
        $exists = $conn->prepare("SELECT id FROM vehicles WHERE id = ?");
        $exists->bind_param("i", $conditionVehicleId);
        $exists->execute();
        $found = $exists->get_result()->num_rows > 0;
        $exists->close();

        if (!$found) {
            setFlash('error', 'That vehicle no longer exists.');
        } else {
            $stmt = $conn->prepare("INSERT INTO vehicle_conditions (vehicle_id, condition_type, notes, recorded_by) VALUES (?, ?, ?, ?)");
            $recorderId = (int)$_SESSION['user_id'];
            $stmt->bind_param("issi", $conditionVehicleId, $conditionType, $conditionNotes, $recorderId);
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                logActivity('added', "Condition recorded for vehicle #$conditionVehicleId");

                // A poor condition automatically opens a maintenance job so it
                // shows up on the maintenance dashboard for follow-up.
                if ($conditionType === 'poor') {
                    $maintDesc = 'Poor condition recorded: ' . $conditionNotes;
                    $maintStmt = $conn->prepare("INSERT INTO maintenance (vehicle_id, description, cost, status, scheduled_date) VALUES (?, ?, 0.00, 'pending', CURDATE())");
                    $maintStmt->bind_param("is", $conditionVehicleId, $maintDesc);
                    if ($maintStmt->execute()) {
                        $newMaintId = $conn->insert_id;
                        logActivity('created', "Maintenance record #$newMaintId auto-created from poor condition for vehicle #$conditionVehicleId");
                    }
                    $maintStmt->close();
                }

                if (function_exists('vehicleIsOnRoad')) {
                    $onRoad = vehicleIsOnRoad($conn, $conditionVehicleId);
                    if (!$onRoad) {
                        $curStmt = $conn->prepare("SELECT status FROM vehicles WHERE id = ?");
                        $curStmt->bind_param("i", $conditionVehicleId);
                        $curStmt->execute();
                        $curRow = $curStmt->get_result()->fetch_assoc();
                        $curStmt->close();
                        $current = strtolower((string)($curRow['status'] ?? ''));
                        if (!in_array($current, ['archived', 'unavailable'], true)) {
                            if ($conditionType === 'poor') {
                                $next = 'maintenance';
                            } elseif (function_exists('vehicleHasOpenMaintenance') && vehicleHasOpenMaintenance($conn, $conditionVehicleId)) {
                                $next = 'maintenance';
                            } else {
                                $next = 'available';
                            }
                            if ($next !== $current) {
                                $upd = $conn->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
                                $upd->bind_param("si", $next, $conditionVehicleId);
                                $upd->execute();
                                $upd->close();
                            }
                        }
                    }
                }
                setFlash('success', 'Condition recorded');
            } else {
                setFlash('error', 'Could not save this inspection. Please try again.');
            }
        }
    }
    redirect(BASE_URL . '/admin/vehicle-conditions.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_condition'])) {
    verify_csrf();
    $deleteId = (int)($_POST['delete_condition'] ?? 0);

    if ($deleteId <= 0) {
        setFlash('error', 'Invalid inspection record selection.');
    } else {
        // Fetch the vehicle before deleting, since the row is gone afterwards.
        $targetVeh = $conn->prepare("SELECT vehicle_id FROM vehicle_conditions WHERE id = ? LIMIT 1");
        $targetVeh->bind_param("i", $deleteId);
        $targetVeh->execute();
        $vehRow = $targetVeh->get_result()->fetch_assoc();
        $targetVeh->close();
        $targetVehicleId = $vehRow ? (int)$vehRow['vehicle_id'] : 0;

        $target = $conn->prepare("SELECT id FROM vehicle_conditions WHERE id = ?");
        $target->bind_param("i", $deleteId);
        $target->execute();
        $found = $target->get_result()->num_rows > 0;
        $target->close();

        if (!$found) {
            setFlash('error', 'That inspection record has already been removed.');
        } else {
            $del = $conn->prepare("DELETE FROM vehicle_conditions WHERE id = ?");
            $del->bind_param("i", $deleteId);
            $ok = $del->execute();
            $affected = $ok ? $del->affected_rows : 0;
            $del->close();
            if ($ok && $affected > 0) {
                logActivity('deleted', "Condition record #$deleteId removed");
                if ($targetVehicleId > 0 && function_exists('vehicleIsOnRoad')) {
                    $onRoad = vehicleIsOnRoad($conn, $targetVehicleId);
                    if (!$onRoad) {
                        $curStmt = $conn->prepare("SELECT status FROM vehicles WHERE id = ?");
                        $curStmt->bind_param("i", $targetVehicleId);
                        $curStmt->execute();
                        $curRow = $curStmt->get_result()->fetch_assoc();
                        $curStmt->close();
                        $current = strtolower((string)($curRow['status'] ?? ''));
                        if (!in_array($current, ['archived', 'unavailable'], true)) {
                            $openMaintenance = function_exists('vehicleHasOpenMaintenance') ? vehicleHasOpenMaintenance($conn, $targetVehicleId) : false;
                            $next = $openMaintenance ? 'maintenance' : 'available';
                            if ($next !== $current) {
                                $upd = $conn->prepare("UPDATE vehicles SET status = ? WHERE id = ?");
                                $upd->bind_param("si", $next, $targetVehicleId);
                                $upd->execute();
                                $upd->close();
                            }
                        }
                    }
                }
                setFlash('success', 'Condition record deleted');
            } else {
                setFlash('error', 'Could not delete this inspection record. Please try again.');
            }
        }
    }
    redirect(BASE_URL . '/admin/vehicle-conditions.php');
}

$vehicles = $conn->query("SELECT id, brand, model, year FROM vehicles WHERE status <> 'archived' ORDER BY brand");

// The rating cards describe the whole history, not the rows on this page, so
// they aggregate in SQL instead of counting fetched rows. COUNT mirrors the
// data query's JOIN, so a record whose vehicle no longer exists counts in neither.
$totalCond = (int)$conn->query("SELECT COUNT(*) AS count FROM vehicle_conditions vc JOIN vehicles v ON vc.vehicle_id = v.id")->fetch_assoc()['count'];
$pg = paginationBounds(paginationPage(), $totalCond, 15);
$perPage = $pg['per_page'];
$offset = $pg['offset'];

$typeCounts = ['excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0];
$ratingTotals = $conn->query("SELECT vc.condition_type AS cond_key, COUNT(*) AS c FROM vehicle_conditions vc JOIN vehicles v ON vc.vehicle_id = v.id GROUP BY vc.condition_type");
while ($rt = $ratingTotals->fetch_assoc()) {
    $key = strtolower($rt['cond_key']);
    if (isset($typeCounts[$key])) {
        $typeCounts[$key] = (int)$rt['c'];
    }
}

// id tie-break: a shared second-precision timestamp would otherwise let rows
// repeat or vanish as the reader pages through the history.
$conditions = $conn->prepare("SELECT vc.*, v.brand, v.model, v.year, u.first_name, u.last_name FROM vehicle_conditions vc JOIN vehicles v ON vc.vehicle_id = v.id LEFT JOIN users u ON vc.recorded_by = u.id ORDER BY vc.created_at DESC, vc.id DESC LIMIT ? OFFSET ?");
$conditions->bind_param("ii", $perPage, $offset);
$conditions->execute();
$conditionsResult = $conditions->get_result();
$conditions->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

    <?php renderFlashNotices(); ?>

    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Vehicle Conditions</h1>
        <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Checklist scores and inspection history for the fleet.</p>
      </div>
      <button type="button" onclick="document.getElementById('conditionFormCard').classList.toggle('hidden')" class="px-4 py-2 rounded-md bg-primary text-on-primary font-label-md text-label-md flex items-center gap-2 hover:bg-primary-container transition-all shadow-sm active:scale-95">
        <i aria-hidden="true" class="bi bi-plus-circle text-[18px]"></i>
        <span>Record Condition</span>
      </button>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-surface-container-lowest p-4 rounded-lg border border-emerald-200/60 shadow-sm">
        <div class="font-label-md text-label-md text-emerald-700 font-bold uppercase">Excellent</div>
        <div class="font-numeric-stat text-numeric-stat text-emerald-600 mt-1"><?= (int)$typeCounts['excellent'] ?></div>
        <div class="font-body-sm text-body-sm text-tertiary mt-0.5">Showroom condition</div>
      </div>
      <div class="bg-surface-container-lowest p-4 rounded-lg border border-primary-fixed-dim/60 shadow-sm">
        <div class="font-label-md text-label-md text-primary font-bold uppercase">Good</div>
        <div class="font-numeric-stat text-numeric-stat text-primary mt-1"><?= (int)$typeCounts['good'] ?></div>
        <div class="font-body-sm text-body-sm text-tertiary mt-0.5">Rental-ready</div>
      </div>
      <div class="bg-surface-container-lowest p-4 rounded-lg border border-amber-200 shadow-sm">
        <div class="font-label-md text-label-md text-amber-700 font-bold uppercase">Fair</div>
        <div class="font-numeric-stat text-numeric-stat text-amber-600 mt-1"><?= (int)$typeCounts['fair'] ?></div>
        <div class="font-body-sm text-body-sm text-tertiary mt-0.5">Needs attention</div>
      </div>
      <div class="bg-surface-container-lowest p-4 rounded-lg border border-error-container shadow-sm">
        <div class="font-label-md text-label-md text-error font-bold uppercase">Poor</div>
        <div class="font-numeric-stat text-numeric-stat text-error mt-1"><?= (int)$typeCounts['poor'] ?></div>
        <div class="font-body-sm text-body-sm text-tertiary mt-0.5">Out of service</div>
      </div>
    </div>

    <div id="conditionFormCard" class="hidden rounded-lg bg-surface-container-lowest border border-outline-variant/60 shadow-luxury-1 overflow-hidden">
      <div class="px-5 py-3.5 border-b border-outline-variant/30 flex items-center justify-between bg-surface-container-low/40">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center">
            <i aria-hidden="true" class="bi bi-shield-plus text-[20px]"></i>
          </div>
          <div>
            <h3 class="text-label-lg font-label-lg text-on-surface font-bold">Record Condition</h3>
            <p class="text-body-sm text-body-sm text-tertiary">Log an inspection result for a vehicle.</p>
          </div>
        </div>
        <button type="button" onclick="document.getElementById('conditionFormCard').classList.add('hidden')" class="w-8 h-8 rounded-full hover:bg-surface-container-high flex items-center justify-center text-tertiary hover:text-on-surface transition-colors" aria-label="Close Record Condition dialog">
          <i aria-hidden="true" class="bi bi-x-lg text-[18px]"></i>
        </button>
      </div>
      <form method="POST" class="p-5">
<?= csrf_field() ?>
        <input type="hidden" name="add_condition" value="1">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="md:col-span-3">
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Vehicle</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-car-front text-[20px] text-outline"></i>
              <select name="vehicle_id" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface" required>
                <option value="">Select Vehicle</option>
                <?php while ($v = $vehicles->fetch_assoc()): ?>
                  <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['year'] . ' ' . $v['brand'] . ' ' . $v['model']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Condition</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-heart-pulse text-[20px] text-outline"></i>
              <select name="condition_type" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface" required>
                <option value="excellent">Excellent</option>
                <option value="good">Good</option>
                <option value="fair">Fair</option>
                <option value="poor">Poor</option>
              </select>
            </div>
          </div>
          <div class="md:col-span-2">
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Notes</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-md bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-text-paragraph text-[20px] text-outline"></i>
              <input type="text" name="notes" maxlength="500" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" placeholder="Notes...">
            </div>
          </div>
        </div>
        <div class="mt-6 flex flex-wrap gap-3">
          <button type="submit" class="px-6 py-3 rounded-md bg-primary text-on-primary font-label-lg font-bold shadow-luxury-glow hover:bg-secondary active:scale-95 transition-all inline-flex items-center gap-2">
            <i aria-hidden="true" class="bi bi-check-lg text-[20px]"></i>
            Record
          </button>
          <button type="button" onclick="document.getElementById('conditionFormCard').classList.add('hidden')" class="px-6 py-3 rounded-md bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface font-label-lg transition-all inline-flex items-center gap-2">
            Cancel
          </button>
        </div>
      </form>
    </div>

    <div class="bg-surface-container-lowest border border-outline-variant/50 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3.5 border-b border-outline-variant/30 flex flex-wrap items-center justify-between gap-3 bg-surface-container-low/40">
        <div class="flex items-center gap-3">
          <span class="text-label-lg font-label-lg text-on-surface font-bold">Inspection History</span>
          <span class="text-body-sm text-tertiary"><?= (int)$totalCond ?> record<?= $totalCond !== 1 ? 's' : '' ?></span>
        </div>
      </div>
      <div class="overflow-x-auto custom-scrollbar">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
              <th scope="col" class="py-3.5 px-5">Vehicle</th>
              <th scope="col" class="py-3.5 px-5">Condition</th>
              <th scope="col" class="py-3.5 px-5">Remarks</th>
              <th scope="col" class="py-3.5 px-5">Recorded By</th>
              <th scope="col" class="py-3.5 px-5">Inspection Date</th>
              <th scope="col" class="py-3.5 px-5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
            <?php while ($row = $conditionsResult->fetch_assoc()): ?>
            <?php
              $ct = strtolower($row['condition_type']);
              $conditionClasses = [
                  'excellent' => 'bg-emerald-100 text-emerald-800 border border-emerald-200',
                  'good'      => 'bg-primary-fixed text-on-primary-fixed-variant border border-primary-fixed-dim',
                  'fair'      => 'bg-amber-50 text-amber-800 border border-amber-200',
                  'poor'      => 'bg-error-container text-on-error-container border border-error-container',
              ];
              $condPill = $conditionClasses[$ct] ?? 'bg-surface-container-high text-on-surface-variant border border-outline-variant';
              $score = ['excellent' => 100, 'good' => 80, 'fair' => 55, 'poor' => 30];
              $bar = $score[$ct] ?? 50;
              $barColor = $ct === 'poor' ? 'bg-error' : ($ct === 'fair' ? 'bg-amber-500' : ($ct === 'good' ? 'bg-primary' : 'bg-emerald-500'));
            ?>
            <tr class="hover:bg-surface-container-low/40 transition-colors group">
              <td class="py-4 px-5 align-top">
                <span class="font-bold text-on-surface"><?= htmlspecialchars($row['year'] . ' ' . $row['brand'] . ' ' . $row['model']) ?></span>
              </td>
              <td class="py-4 px-5 align-top">
                <div class="flex flex-col gap-1.5">
                  <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md <?= $condPill ?> text-label-sm font-bold w-max"><?= htmlspecialchars(ucfirst($row['condition_type'])) ?></span>
                  <div class="flex items-center gap-2">
                    <div class="w-20 h-1.5 bg-surface-container-high rounded-md overflow-hidden">
                      <div class="h-full rounded-md <?= $barColor ?>" style="width: <?= (int)$bar ?>%;"></div>
                    </div>
                    <span class="text-[11px] font-bold text-tertiary"><?= (int)$bar ?>/100</span>
                  </div>
                </div>
              </td>
              <td class="py-4 px-5 align-top">
                <span class="text-on-surface"><?= htmlspecialchars($row['notes']) ?></span>
              </td>
              <td class="py-4 px-5 align-top">
                <span class="text-on-surface-variant"><?= htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?></span>
              </td>
              <td class="py-4 px-5 align-top">
                <span class="text-on-surface"><?= formatDateTime($row['created_at']) ?></span>
              </td>
              <td class="py-4 px-5 align-top text-right">
                <div class="flex items-center justify-end gap-1.5">
                  <form method="POST" action="<?= BASE_URL ?>/admin/vehicle-conditions.php" class="contents"
                        data-confirm="Delete this condition record?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="delete_condition" value="<?= (int)$row['id'] ?>">
                    <button type="submit" class="w-8 h-8 rounded-full bg-error-container text-on-error-container hover:bg-error-container/60 hover:text-error flex items-center justify-center transition-all cursor-pointer" title="Delete record" aria-label="Delete condition record">
                      <i class="bi bi-trash text-[16px]" aria-hidden="true"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if ($totalCond === 0): ?>
            <tr>
              <td colspan="6" class="text-center py-12">
                <div class="text-center py-12 bg-surface-container-lowest rounded-lg border border-outline-variant/60">
                  <i aria-hidden="true" class="bi bi-shield-plus text-[48px] text-outline mb-3 block"></i>
                  <p class="text-on-surface-variant font-label-lg font-label-lg">No condition records found.</p>
                  <p class="text-tertiary text-body-sm mt-1">Record the first inspection for your fleet.</p>
                </div>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($totalCond > 0): ?>
    <?= renderPagination($pg, ['summary' => true, 'noun' => 'inspection records']) ?>
    <?php endif; ?>

  </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of admin/vehicle-conditions.php */
?>
