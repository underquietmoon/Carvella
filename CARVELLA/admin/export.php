<?php
/* admin/export.php - Admin CSV export endpoint. */

$pageTitle = 'Data Export - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/export.php';
requireAdmin();

$datasets = carvella_export_datasets();
$datasetKey = $_GET['dataset'] ?? 'reservations';
if (!isset($datasets[$datasetKey])) {
    $datasetKey = 'reservations';
}
$dataset = $datasets[$datasetKey];

$filters = carvella_export_filters($_GET, $dataset);

if (isset($_GET['download'])) {
    $export = carvella_export_build($datasetKey, $filters);
    // The "Download CSV" link is styled pointer-events-none when the preview
    // is empty, but that only blocks the mouse: the link stayed focusable and
    // Enter still fired it, downloading a header-only file. Refuse an empty
    // export here too so the disabled state is real, not cosmetic.
    if (($export['count'] ?? 0) === 0) {
        setFlash('error', 'There are no records matching these filters, so there is nothing to download.');
        redirect(BASE_URL . '/admin/export.php?' . http_build_query(['dataset' => $datasetKey] + $filters));
    }
    logActivity('exported', "Exported {$dataset['label']} CSV (" . $export['count'] . ' records)');
    csv_send($export['filename'], $export['headers'], $export['rows']);
}

$preview = carvella_export_build($datasetKey, $filters, 25);

$vehicleOptions = [];
$vehicleResult = $conn->query("SELECT id, brand, model, license_plate FROM vehicles ORDER BY brand, model");
if ($vehicleResult) {
    while ($v = $vehicleResult->fetch_assoc()) {
        $vehicleOptions[] = $v;
    }
}

$customerOptions = [];
$customerResult = $conn->query("SELECT id, first_name, last_name FROM users WHERE role = 'customer' ORDER BY first_name, last_name");
if ($customerResult) {
    while ($c = $customerResult->fetch_assoc()) {
        $customerOptions[] = $c;
    }
}

$actionOptions = [];
$actionResult = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
if ($actionResult) {
    while ($a = $actionResult->fetch_assoc()) {
        if ($a['action'] !== null && $a['action'] !== '') {
            $actionOptions[] = $a['action'];
        }
    }
}

$showFilter = function ($name) use ($dataset) {
    return in_array($name, $dataset['filters'], true);
};

$filterQuery = function ($extra = []) use ($datasetKey, $filters) {
    $params = array_merge(['dataset' => $datasetKey], array_filter([
        'start_date' => $filters['start_date'],
        'end_date'   => $filters['end_date'],
        'status'     => $filters['status'],
        'role'       => $filters['role'],
        'type'       => $filters['type'],
        'vehicle_id' => $filters['vehicle_id'] ?: '',
        'user_id'    => $filters['user_id'] ?: '',
        'action'     => $filters['action'],
        'event'      => $filters['event'],
    ], function ($v) { return $v !== '' && $v !== null; }), $extra);
    return http_build_query($params);
};

$activeFilterCount = 0;
foreach (['start_date', 'end_date', 'status', 'role', 'type', 'vehicle_id', 'user_id', 'action', 'event'] as $fk) {
    if ($filters[$fk] !== '' && $filters[$fk] !== 0) {
        $activeFilterCount++;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

    <?php if ($flash = getFlash('success')): ?>
    <div class="bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-lg px-4 py-3 flex items-center gap-3 text-body-sm">
      <i aria-hidden="true" class="bi bi-check-circle text-[20px]"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
    <?php endif; ?>
    <?php if ($flash = getFlash('error')): ?>
    <div class="bg-error-container text-on-error-container border border-error-container rounded-lg px-4 py-3 flex items-center gap-3 text-body-sm">
      <i aria-hidden="true" class="bi bi-exclamation-circle text-[20px]"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <div class="flex items-center gap-2.5">
          <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Data Export Center</h1>
          <span class="hidden sm:inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-surface-container text-primary text-label-sm font-semibold border border-outline-variant/30">
            <i aria-hidden="true" class="bi bi-table text-[14px]"></i>
            CSV Reports
          </span>
        </div>
        <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Select a dataset, refine it with filters, preview the results, then download a spreadsheet-ready CSV.</p>
      </div>
    </div>

    <!-- Dataset Selector -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
      <?php foreach ($datasets as $key => $item): ?>
      <?php $isActive = $key === $datasetKey; ?>
      <a href="<?= BASE_URL ?>/admin/export.php?dataset=<?= urlencode($key) ?>" class="p-3 rounded-md border shadow-sm flex items-start gap-2 transition-all <?= $isActive ? 'bg-primary text-on-primary border-primary' : 'bg-surface-container-lowest border-outline-variant/40 hover:border-primary/50 hover:bg-surface-container-low' ?>">
        <span class="w-8 h-8 rounded-md flex items-center justify-center shrink-0 <?= $isActive ? 'bg-on-primary/15 text-on-primary' : 'bg-primary/10 text-primary' ?>">
          <i aria-hidden="true" class="bi bi-<?= htmlspecialchars($item['icon']) ?> text-[16px]"></i>
        </span>
        <span class="min-w-0">
          <span class="block text-label-sm font-label-sm font-bold truncate"><?= htmlspecialchars($item['label']) ?></span>
          <span class="block text-[10px] mt-0.5 <?= $isActive ? 'text-on-primary/80' : 'text-on-surface-variant' ?>"><?= htmlspecialchars($item['description']) ?></span>
        </span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm space-y-4">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-2">
          <i aria-hidden="true" class="bi bi-funnel text-primary text-[20px]"></i>
          <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Refine <?= htmlspecialchars($dataset['label']) ?></h2>
          <?php if ($activeFilterCount > 0): ?>
          <span class="px-2 py-0.5 rounded-md bg-primary/10 text-primary text-label-sm font-bold"><?= $activeFilterCount ?> active</span>
          <?php endif; ?>
        </div>
        <?php if ($activeFilterCount > 0): ?>
        <a href="<?= BASE_URL ?>/admin/export.php?dataset=<?= urlencode($datasetKey) ?>" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface-variant text-label-sm font-label-sm hover:bg-surface-container transition-colors">
          <i aria-hidden="true" class="bi bi-list text-[16px]"></i>
          Reset filters
        </a>
        <?php endif; ?>
      </div>

      <form method="GET" action="<?= BASE_URL ?>/admin/export.php" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
        <input type="hidden" name="dataset" value="<?= htmlspecialchars($datasetKey) ?>">

        <?php if ($showFilter('date')): ?>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="start_date">From date</label>
          <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($filters['start_date']) ?>" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
        </div>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="end_date">To date</label>
          <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($filters['end_date']) ?>" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
        </div>
        <?php endif; ?>

        <?php if ($showFilter('status')): ?>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="status">Status</label>
          <select id="status" name="status" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
            <option value="">All statuses</option>
            <?php foreach ($dataset['statuses'] as $status): ?>
            <option value="<?= htmlspecialchars($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($showFilter('role')): ?>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="role">Role</label>
          <select id="role" name="role" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
            <option value="">All roles</option>
            <?php foreach ($dataset['roles'] as $role): ?>
            <option value="<?= htmlspecialchars($role) ?>" <?= $filters['role'] === $role ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($role)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($showFilter('type')): ?>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="type">Vehicle type</label>
          <select id="type" name="type" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
            <option value="">All types</option>
            <?php foreach ($dataset['types'] as $type): ?>
            <option value="<?= htmlspecialchars($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($type)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($showFilter('vehicle')): ?>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="vehicle_id">Vehicle</label>
          <select id="vehicle_id" name="vehicle_id" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
            <option value="">All vehicles</option>
            <?php foreach ($vehicleOptions as $v): ?>
            <option value="<?= (int) $v['id'] ?>" <?= (int) $filters['vehicle_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['brand'] . ' ' . $v['model'] . ' (' . $v['license_plate'] . ')') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($showFilter('customer')): ?>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="user_id">Customer</label>
          <select id="user_id" name="user_id" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
            <option value="">All customers</option>
            <?php foreach ($customerOptions as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= (int) $filters['user_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($c['first_name'] . ' ' . $c['last_name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($showFilter('action')): ?>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="action">Action</label>
          <select id="action" name="action" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
            <option value="">All actions</option>
            <?php foreach ($actionOptions as $action): ?>
            <option value="<?= htmlspecialchars($action) ?>" <?= $filters['action'] === $action ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $action))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <?php if ($showFilter('event')): ?>
        <div>
          <label class="block text-label-sm font-label-sm text-on-surface-variant mb-1.5" for="event">Event</label>
          <select id="event" name="event" class="w-full px-3.5 py-2.5 rounded-lg bg-surface-container-low border border-outline-variant/60 text-body-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary transition-all">
            <option value="">All events</option>
            <?php foreach (($dataset['events'] ?? []) as $event): ?>
            <option value="<?= htmlspecialchars($event) ?>" <?= $filters['event'] === $event ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $event))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="flex items-center gap-2.5 <?= $showFilter('date') ? 'sm:col-span-2 lg:col-span-4' : '' ?>">
          <button type="submit" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-md bg-primary text-on-primary text-label-md font-label-md font-semibold hover:bg-secondary transition-colors shadow-sm active:scale-[0.98]">
            <i aria-hidden="true" class="bi bi-funnel text-[18px]"></i>
            Apply filters
          </button>
          <?php if ($preview['count'] === 0): ?>
          <span class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface text-label-md font-label-md font-semibold opacity-50 cursor-not-allowed" aria-disabled="true">
            <i aria-hidden="true" class="bi bi-download text-[18px]"></i>
            Download CSV
          </span>
          <?php else: ?>
          <a href="<?= BASE_URL ?>/admin/export.php?<?= htmlspecialchars($filterQuery(['download' => 1])) ?>" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface text-label-md font-label-md font-semibold hover:bg-surface-container transition-colors">
            <i aria-hidden="true" class="bi bi-download text-[18px]"></i>
            Download CSV
          </a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Preview -->
    <div class="bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3.5 border-b border-outline-variant/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface"><?= htmlspecialchars($dataset['label']) ?> Preview</h2>
          <p class="text-body-sm text-[12px] text-on-surface-variant">
            <?= number_format($preview['count']) ?> record<?= $preview['count'] !== 1 ? 's' : '' ?> match your filters<?= $preview['count'] > 25 ? ' • showing the first 25' : '' ?>.
            <br><span class="text-[11px] text-outline">Tip: In Excel, select all columns and double-click the border between any two column headers to auto-fit.</span>
          </p>
        </div>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-surface-container-low border border-outline-variant/40 text-on-surface-variant text-label-sm font-mono">
          <i aria-hidden="true" class="bi bi-file-text text-[15px]"></i>
          <?= htmlspecialchars($preview['filename']) ?>
        </span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse whitespace-nowrap table-auto">
          <thead>
            <tr class="border-b border-outline-variant/30 bg-surface-container-low/60 text-tertiary text-label-sm font-label-sm uppercase tracking-wider">
              <?php foreach ($preview['headers'] as $header): ?>
              <th scope="col" class="py-3.5 px-5 font-bold whitespace-nowrap"><?= htmlspecialchars($header) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody class="divide-y divide-outline-variant/20 text-body-sm font-body-sm">
            <?php foreach ($preview['rows'] as $row): ?>
            <tr class="hover:bg-surface-container-low/50 transition-colors">
              <?php foreach ($row as $i => $cell): ?>
              <td class="py-3.5 px-5 text-on-surface <?= $i === 0 ? 'font-mono font-semibold text-primary' : '' ?>"><?= htmlspecialchars((string) $cell) ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($preview['rows'])): ?>
            <tr>
              <td colspan="<?= count($preview['headers']) ?>" class="text-center py-12">
                <i aria-hidden="true" class="bi bi-search text-[48px] text-outline mb-3 block"></i>
                <p class="text-on-surface-variant text-body-md font-body-md">No records match the selected filters.</p>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of admin/export.php */
?>
