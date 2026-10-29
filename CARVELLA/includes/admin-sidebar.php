<?php
/* includes/admin-sidebar.php - Admin sidebar navigation partial. */
$currentFile = basename($_SERVER['PHP_SELF']);
$adminLinks = [
    'dashboard.php' => ['label' => 'Dashboard', 'icon' => 'grid'],
    'vehicles.php' => ['label' => 'Vehicle Matrix', 'icon' => 'car-front'],
    'reservations.php' => ['label' => 'Rentals', 'icon' => 'calendar2-check'],
    'customers.php' => ['label' => 'Customers', 'icon' => 'people'],
    'availability.php' => ['label' => 'Rental Tracker', 'icon' => 'calendar-range'],
    'vehicle-conditions.php' => ['label' => 'Vehicle Conditions', 'icon' => 'clipboard2-check'],
    'maintenance.php' => ['label' => 'Maintenance Records', 'icon' => 'gear'],
    'reports.php' => ['label' => 'Reports &amp; Analytics', 'icon' => 'bar-chart'],
    'activity-logs.php' => ['label' => 'Activity Logs', 'icon' => 'clock'],
];
?>
<!-- Mobile Sidebar Backdrop -->
<div class="fixed inset-0 bg-on-surface/40 backdrop-blur-sm z-30 hidden md:hidden" id="sideNavBackdrop"></div>
<!-- FLOATING CAPSULE SIDEBAR (Collapsible Rail) -->
<aside class="fixed md:sticky top-0 md:top-[130px] left-0 z-40 md:z-30 w-[280px] md:w-[240px] shrink-0 bg-surface-container-lowest rounded-none md:rounded-lg p-5 md:p-2.5 md:border md:border-outline-variant flex flex-col h-dvh md:h-fit md:self-start md:mx-auto -translate-x-full md:translate-x-0" id="sideNavBar">
  <!-- Mobile Close Row -->
  <div class="flex md:hidden justify-between items-center pb-2 border-b border-outline-variant/25">
    <span class="text-headline-sm font-bold text-primary">Carvella Portal</span>
    <button type="button" class="p-1.5 rounded-md hover:bg-surface-container-low text-on-surface" id="closeMobileMenuBtn" aria-label="Close menu">
      <i aria-hidden="true" class="bi bi-x-lg"></i>
    </button>
  </div>

  <!-- Core Navigation List -->
  <nav class="overflow-y-auto scrollbar-none space-y-1 py-1">
    <?php foreach ($adminLinks as $file => $item): ?>
    <a class="nav-link <?= $currentFile === $file ? 'bg-primary text-on-primary font-semibold' : 'text-on-surface-variant font-medium hover:bg-surface-container-low hover:text-on-surface' ?> rounded-md px-4 py-2.5 flex items-center gap-2.5 font-label-md text-[15px] transition-[background-color,color,border-color,transform] duration-150 active:scale-95 group" href="<?= BASE_URL ?>/admin/<?= $file ?>" title="<?= $item['label'] ?>">
      <span class="icon-wrapper flex items-center justify-center shrink-0">
        <i aria-hidden="true" class="bi <?= $currentFile === $file ? 'bi-' . $item['icon'] . '-fill' : 'bi-' . $item['icon'] ?> text-[20px]"></i>
      </span>
      <span class="nav-text truncate"><?= $item['label'] ?></span>
    </a>
      <?php endforeach; ?>
    </nav>
  </aside>
<script>
(function () {
    var sideNav = document.getElementById('sideNavBar');
    var toggleBtn = document.getElementById('sidebar-toggle');
    var toggleIcon = document.getElementById('toggle-icon');
    var backdrop = document.getElementById('sideNavBackdrop');
    var closeBtn = document.getElementById('closeMobileMenuBtn');
    var storedKey = 'carvella_sidebar_collapsed';
    function isDesktop() { return window.matchMedia('(min-width: 768px)').matches; }
    function setCollapsed(collapsed) {
        if (collapsed) {
            toggleIcon.classList.add('bi-layout-sidebar');
            toggleIcon.classList.remove('bi-layout-sidebar-reverse');
            toggleBtn.setAttribute('title', 'Expand sidebar');
            sideNav.classList.add('collapsed');
        } else {
            toggleIcon.classList.remove('bi-layout-sidebar');
            toggleIcon.classList.add('bi-layout-sidebar-reverse');
            toggleBtn.setAttribute('title', 'Collapse sidebar');
            sideNav.classList.remove('collapsed');
        }
    }
    function openDrawer() {
        sideNav.classList.add('translate-x-0');
        sideNav.classList.remove('-translate-x-full');
        if (backdrop) backdrop.classList.remove('hidden');
    }
    function closeDrawer() {
        sideNav.classList.remove('translate-x-0');
        sideNav.classList.add('-translate-x-full');
        if (backdrop) backdrop.classList.add('hidden');
    }
    if (toggleBtn && sideNav && toggleIcon) {
        if (isDesktop()) {
            var stored;
            try { stored = localStorage.getItem(storedKey); } catch (e) { stored = null; }
            if (stored === '1') {
                setCollapsed(true);
            } else {
                setCollapsed(false);
            }
        }
        toggleBtn.addEventListener('click', function () {
            if (isDesktop()) {
                var isCollapsed = !sideNav.classList.contains('collapsed');
                // Motion must receive the event before the class changes so
                // the collapse animation has a real start frame.
                sideNav.dispatchEvent(new CustomEvent('carvella:sidebar-toggle', {
                    detail: { collapsed: isCollapsed }
                }));
                // Update UI after dispatch for consistency
                if (isCollapsed) {
                    toggleIcon.classList.add('bi-layout-sidebar');
                    toggleIcon.classList.remove('bi-layout-sidebar-reverse');
                    toggleBtn.setAttribute('title', 'Expand sidebar');
                    sideNav.classList.add('collapsed');
                } else {
                    toggleIcon.classList.remove('bi-layout-sidebar');
                    toggleIcon.classList.add('bi-layout-sidebar-reverse');
                    toggleBtn.setAttribute('title', 'Collapse sidebar');
                    sideNav.classList.remove('collapsed');
                }
                try { localStorage.setItem(storedKey, isCollapsed ? '1' : '0'); } catch (e) {}
            } else {
                if (sideNav.classList.contains('-translate-x-full')) openDrawer(); else closeDrawer();
            }
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeDrawer);
    if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
})();
</script>
<?php /* End of includes/admin-sidebar.php */ ?>
