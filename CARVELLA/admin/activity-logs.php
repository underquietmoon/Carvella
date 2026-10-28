<?php
/* activity-logs.php - Carvella Car Rental System */
$pageTitle = 'Activity Logs &amp; Audit Trail - Carvella';
$adminLayout = true;
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

// One fixed page size keeps the KPI card and the query in agreement; the
// shared helper clamps the requested page (a list that has shrunk must not
// render an empty table) and derives the offset from the clamped page.
$total = (int)$conn->query("SELECT COUNT(*) AS count FROM activity_logs")->fetch_assoc()['count'];
$pg = paginationBounds(paginationPage(), $total, 50);
$perPage = $pg['per_page'];
$offset = $pg['offset'];

// id tie-break: timestamps only carry second precision, so equal-time rows
// would otherwise repeat or vanish as the reader pages through the trail.
$logs = $conn->prepare("SELECT al.*, u.first_name, u.last_name FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC, al.id DESC LIMIT ? OFFSET ?");
$logs->bind_param("ii", $perPage, $offset);
$logs->execute();
$logsResult = $logs->get_result();
$logs->close();

if (isset($_POST['clear_logs'])) {
    verify_csrf();
    $conn->query("TRUNCATE TABLE activity_logs");
    logActivity('cleared', 'Activity log history purged');
    setFlash('success', 'All activity logs cleared');
    redirect(BASE_URL . '/admin/activity-logs.php');
}

$todayCount = $conn->query("SELECT COUNT(*) AS c FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-1 flex w-full relative pl-4 md:pl-8">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
  <main id="main-content" tabindex="-1" class="flex-1 min-w-0 px-4 sm:px-6 lg:px-10 py-6 md:py-10 space-y-6">

    <?php renderFlashNotices(); ?>

    <!-- Page Header -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <div class="flex items-center gap-2.5">
          <h1 class="text-headline-lg font-headline-lg text-on-surface tracking-tight">Activity Logs &amp; Audit Trail</h1>
          <span class="hidden sm:inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-emerald-100 text-emerald-800 text-label-sm font-semibold">
            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
            Audit Feed Live
          </span>
        </div>
        <p class="text-body-sm font-body-sm text-on-surface-variant mt-0.5">Real-time audit trail of admin actions, rentals, and fleet operations.</p>
      </div>
      <form method="POST" action="<?= BASE_URL ?>/admin/activity-logs.php" class="contents"
            data-confirm="Clear all logs? This cannot be undone.">
        <?= csrf_field() ?>
        <input type="hidden" name="clear_logs" value="1">
        <button type="submit" class="px-4 py-2 rounded-md bg-error-container text-on-error-container font-label-md text-label-md flex items-center gap-2 hover:bg-red-200 transition-all active:scale-95 cursor-pointer">
          <i class="bi bi-trash3 text-[18px]" aria-hidden="true"></i>
          Clear Logs
        </button>
      </form>
    </div>

    <!-- 3 KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Total Events</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-on-surface"><?= number_format((int)$total) ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Logged actions</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Today's Activity</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-primary"><?= (int)$todayCount ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Events this session</span>
        </div>
      </div>
      <div class="p-4 rounded-lg bg-surface-container-lowest border border-outline-variant/40 shadow-sm flex items-center justify-between">
        <div class="space-y-1">
          <span class="text-label-sm font-label-sm uppercase tracking-wider text-on-surface-variant font-semibold">Current Page</span>
          <div class="text-numeric-stat font-numeric-stat font-extrabold text-on-surface"><?= (int)$pg['page'] ?> / <?= (int)$pg['total_pages'] ?></div>
          <span class="text-on-surface-variant text-label-sm text-[12px]">Of log pages (<?= $perPage ?>/page)</span>
        </div>
      </div>
    </div>

    <?php if ($logsResult->num_rows === 0): ?>
    <div class="text-center py-16 bg-surface-container-lowest rounded-lg border border-outline-variant/40">
      <i aria-hidden="true" class="bi bi-clock-history text-[48px] text-outline mb-3 block"></i>
      <p class="text-on-surface-variant text-label-lg font-label-lg">No activity logs found.</p>
      <p class="text-tertiary text-body-sm mt-1">Logs will appear here as actions are performed.</p>
    </div>
    <?php else: ?>

    <!-- Audit Timeline -->
    <div class="bg-surface-container-lowest border border-outline-variant/40 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3.5 border-b border-outline-variant/30 flex flex-wrap items-center justify-between gap-3 bg-surface-container-low/40">
        <div class="flex items-center gap-3">
          <span class="text-label-lg font-label-lg text-on-surface font-bold">Audit Timeline</span>
          <span class="text-body-sm text-tertiary"><?= (int)$logsResult->num_rows ?> event<?= $logsResult->num_rows !== 1 ? 's' : '' ?> on this page</span>
        </div>
      </div>
      <div class="p-5">
        <ol class="relative border-l-2 border-outline-variant/40 ml-3 space-y-6">
          <?php while ($row = $logsResult->fetch_assoc()): ?>
          <?php
            $action = $row['action'] ?? '';
            $actionColor = 'bg-primary/10 text-primary';
            if (stripos($action, 'login') !== false || stripos($action, 'sign') !== false) {
                $actionColor = 'bg-emerald-100 text-emerald-800';
            } elseif (stripos($action, 'create') !== false || stripos($action, 'add') !== false || stripos($action, 'insert') !== false) {
                $actionColor = 'bg-secondary-fixed text-on-secondary-fixed';
            } elseif (stripos($action, 'delete') !== false || stripos($action, 'remove') !== false || stripos($action, 'clear') !== false) {
                $actionColor = 'bg-error-container text-on-error-container';
            } elseif (stripos($action, 'update') !== false || stripos($action, 'edit') !== false || stripos($action, 'change') !== false) {
                $actionColor = 'bg-amber-100 text-amber-800';
            }
          ?>
          <li class="ml-6 relative">
            <span class="absolute -left-[31px] top-0.5 w-7 h-7 rounded-full <?= $actionColor ?> flex items-center justify-center ring-4 ring-surface-container-lowest shadow-sm">
              <i aria-hidden="true" class="bi bi-lightning-charge text-[15px]"></i>
            </span>
            <div class="rounded-lg border border-outline-variant/40 bg-surface-container-low/30 p-5 shadow-sm hover:shadow-md transition-shadow">
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-3 flex-wrap">
                  <span class="font-bold text-on-surface"><?= htmlspecialchars(trim(($row['first_name'] ?? 'System') . ' ' . ($row['last_name'] ?? ''))) ?></span>
                  <span class="px-3 py-1 rounded-md <?= $actionColor ?> font-label-sm text-label-sm font-bold"><?= htmlspecialchars($action) ?></span>
                </div>
                <div class="flex items-center gap-3 text-tertiary text-label-sm font-label-sm">
                  <?php if (!empty($row['ip_address']) && !in_array($row['ip_address'], ['::1', '127.0.0.1', '::ffff:127.0.0.1'], true)): ?>
                  <span class="inline-flex items-center gap-1.5">
                    <code class="bg-surface-container-high px-2 py-0.5 rounded-md text-on-surface text-[11px]"><?= htmlspecialchars($row['ip_address']) ?></code>
                  </span>
                  <?php endif; ?>
                  <span class="inline-flex items-center gap-1.5">
                    <i aria-hidden="true" class="bi bi-clock text-[15px]"></i>
                    <?= formatDateTime($row['created_at']) ?>
                  </span>
                </div>
              </div>
              <?php if (!empty($row['details'])): ?>
              <p class="mt-3 text-body-sm font-body-sm text-on-surface-variant border-t border-outline-variant/30 pt-3"><?= htmlspecialchars($row['details']) ?></p>
              <?php endif; ?>
            </div>
          </li>
          <?php endwhile; ?>
        </ol>
      </div>
    </div>

    <!-- Pagination -->
    <?= renderPagination($pg, ['summary' => true, 'noun' => 'events']) ?>
    <?php endif; ?>

  </main>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
