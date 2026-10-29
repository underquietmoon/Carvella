<?php
/* admin/maintenance-records.php - Admin view-only maintenance records (same view as maintenance.php). */
$pageTitle = 'Maintenance Records - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();

$allowedSorts = [
    'id'             => 'm.id',
    'vehicle'        => 'v.brand, v.model',
    'description'    => 'm.description',
    'cost'           => 'm.cost',
    'scheduled_date' => 'm.scheduled_date',
    'completed_date' => 'm.completed_date',
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

require_once __DIR__ . '/../includes/header.php';
?>
<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">
    <?php renderFlashNotices(); ?>
    <div>
      <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Maintenance Records</h1>
      <p class="text-body-sm text-on-surface-variant mt-0.5">View-only maintenance and service history.</p>
    </div>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <form method="GET" action="<?= BASE_URL ?>/admin/maintenance.php" class="flex items-center gap-2 flex-1 max-w-xl">
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
      <a href="<?= BASE_URL ?>/admin/maintenance.php?<?= http_build_query(array_filter(['search' => $search, 'status' => $statusFilter, 'sort' => $sort, 'dir' => strtolower($dir), 'export' => 1])) ?>" class="px-4 py-2 rounded-md border border-outline-variant/60 text-on-surface-variant hover:bg-surface-container transition-colors text-label-md font-label-md">Export CSV</a>
    </div>
    <?php
    $filterUrl = function ($s) use ($sort, $dir) {
        $params = ['sort' => $sort, 'dir' => strtolower($dir)];
        if ($s !== '') $params['status'] = $s;
        return BASE_URL . '/admin/maintenance.php?' . http_build_query($params);
    };
    ?>
    <div class="flex items-center gap-2 border-b border-outline-variant/30 pb-1">
      <a href="<?= $filterUrl('') ?>" class="px-4 py-2 rounded-md text-label-md font-label-md <?= $statusFilter === '' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> transition-colors">All</a>
      <a href="<?= $filterUrl('pending') ?>" class="px-4 py-2 rounded-md text-label-md font-label-md <?= $statusFilter === 'pending' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> transition-colors">Pending</a>
      <a href="<?= $filterUrl('in_progress') ?>" class="px-4 py-2 rounded-md text-label-md font-label-md <?= $statusFilter === 'in_progress' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> transition-colors">In Progress</a>
      <a href="<?= $filterUrl('completed') ?>" class="px-4 py-2 rounded-md text-label-md font-label-md <?= $statusFilter === 'completed' ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant hover:text-on-surface hover:bg-surface-container' ?> transition-colors">Completed</a>
    </div>
    <div class="bg-surface-container-lowest rounded-lg p-5 border border-outline-variant/40 shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <?php
          $sortLink = function ($key, $label) use ($sort, $dir) {
              $nextDir = ($sort === $key && $dir === 'ASC') ? 'desc' : 'asc';
              $arrow = '';
              if ($sort === $key) {
                  $arrow = $dir === 'ASC' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>';
              }
              $url = BASE_URL . '/admin/maintenance.php?' . http_build_query(['sort' => $key, 'dir' => $nextDir]);
              return '<a href="' . $url . '" class="hover:text-primary transition-colors">' . $label . $arrow . '</a>';
          };
          ?>
          <thead>
            <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
              <th class="py-3 px-4"><?= $sortLink('id', 'ID') ?></th>
              <th class="py-3 px-4"><?= $sortLink('vehicle', 'Vehicle') ?></th>
              <th class="py-3 px-4"><?= $sortLink('description', 'Description') ?></th>
              <th class="py-3 px-4"><?= $sortLink('cost', 'Cost') ?></th>
              <th class="py-3 px-4"><?= $sortLink('scheduled_date', 'Scheduled') ?></th>
              <th class="py-3 px-4"><?= $sortLink('completed_date', 'Completed') ?></th>
              <th class="py-3 px-4"><?= $sortLink('status', 'Status') ?></th>
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
              <td class="py-3 px-4"><?= $r['completed_date'] ? htmlspecialchars($r['completed_date']) : '—' ?></td>
              <td class="py-3 px-4"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></td>
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
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of admin/maintenance-records.php */
?>