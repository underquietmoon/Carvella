<?php
$currentFile = basename($_SERVER['PHP_SELF']);
$isAdminArea = strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
$fullName = $currentUser['first_name'] ?? ($_SESSION['name'] ?? 'Guest');
$initial = strtoupper(substr(trim($fullName), 0, 1));

$navActive = function ($file, $adminArea = false) use ($currentFile, $isAdminArea) {
    if (!empty($adminArea)) {
        return $isAdminArea ? ' nav-active-underline' : ' text-tertiary hover:text-on-surface';
    }
    return ($currentFile === $file) ? ' nav-active-underline' : ' text-tertiary hover:text-on-surface';
};

$navActiveMobile = function ($file) use ($currentFile) {
    return ($currentFile === $file) ? ' nav-active-underline-mobile' : ' text-on-surface-variant hover:bg-surface-container hover:text-on-surface';
};
?>
<!-- Top Navigation Bar (Shared Component, stitch design system) -->
<header class="navbar-glass fixed top-0 z-50 bg-surface border-b border-outline-variant w-full" id="siteNavbar">
<div class="w-full py-3 px-6 md:px-12 max-w-container-max-width mx-auto flex items-center justify-between gap-4 <?= isMaintenance() ? 'md:justify-between' : 'md:justify-center md:gap-10' ?>">
<!-- Brand Logo -->
<a class="flex items-center gap-2 group" href="<?= BASE_URL ?>/index.php">
<img class="h-14 w-auto object-contain transition-transform duration-300 group-hover:scale-105 theme-logo-light" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO.png?v=2" alt="CARVELLA" width="718" height="548" loading="eager">
<img class="h-14 w-auto object-contain transition-transform duration-300 group-hover:scale-105 theme-logo-dark" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO white.png?v=2" alt="CARVELLA" width="718" height="548" loading="eager">
</a>
<!-- Desktop Navigation Links -->
<nav class="hidden md:flex items-center gap-8" aria-label="Primary navigation">
<?php $navI = 0; $navStyle = function () use (&$navI) { return ' style="--nav-i:' . $navI++ . '"'; }; ?>
<?php if (isLoggedIn() && isAdmin()): ?>
<a class="<?= $navActive('index.php') ?> text-label-lg font-label-lg px-3 py-1.5 transition-colors"<?= $navStyle() ?> href="<?= BASE_URL ?>/index.php"><i aria-hidden="true" class="bi bi-house text-lg align-middle mr-1"></i>Website</a>
<a class="<?= $navActive('', true) ?> text-label-lg font-label-lg px-3 py-1.5 transition-colors"<?= $navStyle() ?> href="<?= BASE_URL ?>/admin/dashboard.php"><i aria-hidden="true" class="bi bi-shield-lock text-lg align-middle mr-1"></i>Admin Console</a>
<a class="<?= $navActive('vehicles.php') ?> text-label-lg font-label-lg px-3 py-1.5 transition-colors"<?= $navStyle() ?> href="<?= BASE_URL ?>/admin/vehicles.php">Fleet Catalog</a>
<?php elseif (isLoggedIn() && isMaintenance()): ?>
<?php // Maintenance staff: logo on the left, profile dropdown on the right only. ?>
<?php else: ?>
<a class="<?= $navActive('index.php') ?> text-label-lg font-label-lg px-3 py-1.5 transition-colors nav-link"<?= $navStyle() ?> href="<?= BASE_URL ?>/index.php">Home</a>
<a class="<?= $navActive('vehicles.php') ?> text-label-lg font-label-lg px-3 py-1.5 transition-colors nav-link"<?= $navStyle() ?> href="<?= BASE_URL ?>/customer/vehicles.php">Vehicle</a>
<a class="<?= $navActive('how-it-works.php') ?> text-label-lg font-label-lg px-3 py-1.5 transition-colors nav-link"<?= $navStyle() ?> href="<?= BASE_URL ?>/how-it-works.php">How It Works</a>
<?php endif; ?>
</nav>
<!-- Trailing Action: Quick Icons + Auth Capsule / CTAs -->
<div class="flex items-center gap-3">
<?php if (isLoggedIn()): ?>
<!-- User VIP Capsule w/ Dropdown -->
<div class="relative user-menu-wrap flex items-center gap-3 pl-2 sm:pl-3 border-l border-outline-variant/60">
<button type="button" class="flex items-center gap-3 cursor-pointer select-none appearance-none bg-transparent border-0 p-0 text-left" id="userMenuToggle" aria-controls="userMenu" aria-expanded="false">
<div class="relative">
<span class="w-10 h-10 rounded-full bg-secondary inline-flex items-center justify-center text-on-secondary font-bold text-label-md ring-2 ring-primary/30"><?= e($initial) ?></span>
<span class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 rounded-full ring-2 ring-surface"></span>
</div>
<div class="hidden lg:flex flex-col text-left">
<span class="text-label-md font-label-md text-on-surface leading-tight"><?= htmlspecialchars($fullName) ?></span>
</div>
<i aria-hidden="true" class="bi bi-chevron-down hidden lg:inline text-base text-outline"></i>
</button>
<!-- Dropdown Panel -->
<div class="user-menu hidden absolute right-0 top-[calc(100%+10px)] w-60 rounded-2xl bg-surface-container-lowest border border-outline-variant/40 shadow-xl p-2 z-50" id="userMenu">
<?php if ($currentUser): ?>
<div class="px-3 py-2.5 mb-1 border-b border-outline-variant/40">
<span class="block text-label-md font-label-md text-on-surface font-bold"><?= htmlspecialchars($currentUser['first_name'] . ' ' . ($currentUser['last_name'] ?? '')) ?></span>
<span class="block text-label-sm font-label-sm text-outline truncate"><?= isMaintenance() ? 'Maintenance Staff' : htmlspecialchars($currentUser['email'] ?? '') ?></span>
</div>
<?php endif; ?>
<?php if (!isMaintenance()): ?>
<a class="flex items-center gap-3 px-3 py-2 rounded-xl text-body-sm font-body-sm text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors" href="<?= BASE_URL ?>/customer/profile.php">
<i aria-hidden="true" class="bi bi-person text-[20px] text-primary"></i>Profile
</a>
<?php if (isAdmin()): ?>
<a class="flex items-center gap-3 px-3 py-2 rounded-xl text-body-sm font-body-sm text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors" href="<?= BASE_URL ?>/admin/reports.php">
<i aria-hidden="true" class="bi bi-graph-up text-[20px] text-primary"></i>Reports &amp; Analytics
</a>
<?php else: ?>
<a class="flex items-center gap-3 px-3 py-2 rounded-xl text-body-sm font-body-sm text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors" href="<?= BASE_URL ?>/customer/dashboard.php">
<i aria-hidden="true" class="bi bi-grid text-[20px] text-primary"></i>My Dashboard
</a>
<?php endif; ?>
<?php endif; ?>
<form method="POST" action="<?= BASE_URL ?>/auth/logout.php" class="contents">
  <?= csrf_field() ?>
  <button type="submit" class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-body-sm font-body-sm text-error hover:bg-error-container transition-colors cursor-pointer">
    <i class="bi bi-box-arrow-right text-[20px]" aria-hidden="true"></i>Log Out
  </button>
</form>
</div>
</div>
<?php else: ?>
<a class="hidden sm:inline-flex px-5 py-2.5 rounded-full border border-outline-variant/70 text-tertiary hover:bg-surface-container hover:text-on-surface font-label-lg font-label-lg transition-all" href="<?= BASE_URL ?>/auth/login.php">Sign in</a>
<a class="px-5 py-2.5 rounded-full bg-primary text-on-primary font-label-lg font-label-lg font-bold hover:bg-secondary transition-all active:scale-95" href="<?= BASE_URL ?>/auth/register.php">Book now</a>
<?php endif; ?>
<!-- Mobile Hamburger -->
<button type="button" class="md:hidden w-10 h-10 rounded-full flex items-center justify-center text-tertiary hover:bg-surface-container-low transition-all" id="mobileMenuBtn" aria-label="Open menu" aria-expanded="false" aria-controls="mobileMenuPanel" aria-haspopup="true">
<i aria-hidden="true" class="bi bi-menu-button text-[20px]"></i>
</button>
</div>
</div>
<!-- Mobile Dropdown Menu Panel -->
<div class="md:hidden mobile-menu-panel bg-surface border-t border-outline-variant/50 px-6 py-4" id="mobileMenuPanel">
<nav class="flex flex-col gap-1">
<?php $navI = 0; $navStyle = function () use (&$navI) { return ' style="--nav-i:' . $navI++ . '"'; }; ?>
<?php if (isLoggedIn() && isMaintenance()): ?>
<form method="POST" action="<?= BASE_URL ?>/auth/logout.php" class="contents">
  <?= csrf_field() ?>
  <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors cursor-pointer"<?= $navStyle() ?>><i class="bi bi-box-arrow-right text-[20px] text-error" aria-hidden="true"></i>Log Out</button>
</form>
<?php elseif (isLoggedIn() && isAdmin()): ?>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm <?= $navActiveMobile('index.php') ?>"<?= $navStyle() ?> href="<?= BASE_URL ?>/index.php"><i aria-hidden="true" class="bi bi-house text-[20px] text-primary"></i>Website</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm <?= $navActiveMobile('dashboard.php') ?>"<?= $navStyle() ?> href="<?= BASE_URL ?>/admin/dashboard.php"><i aria-hidden="true" class="bi bi-grid text-[20px] text-primary"></i>Dashboard</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm <?= $navActiveMobile('vehicles.php') ?>"<?= $navStyle() ?> href="<?= BASE_URL ?>/admin/vehicles.php"><i aria-hidden="true" class="bi bi-car-front text-[20px] text-primary"></i>Fleet Catalog</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm <?= $navActiveMobile('reservations.php') ?>"<?= $navStyle() ?> href="<?= BASE_URL ?>/admin/reservations.php"><i aria-hidden="true" class="bi bi-calendar3 text-[20px] text-primary"></i>Rentals</a>
<form method="POST" action="<?= BASE_URL ?>/auth/logout.php" class="contents">
  <?= csrf_field() ?>
  <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm text-on-surface-variant hover:bg-surface-container hover:text-on-surface transition-colors cursor-pointer"<?= $navStyle() ?>><i class="bi bi-box-arrow-right text-[20px] text-error" aria-hidden="true"></i>Log Out</button>
</form>
<?php else: ?>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm <?= $navActiveMobile('index.php') ?>"<?= $navStyle() ?> href="<?= BASE_URL ?>/index.php"><i aria-hidden="true" class="bi bi-house text-[20px] text-primary"></i>Home</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm <?= $navActiveMobile('vehicles.php') ?>"<?= $navStyle() ?> href="<?= BASE_URL ?>/customer/vehicles.php"><i aria-hidden="true" class="bi bi-car-front text-[20px] text-primary"></i>Vehicle</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm <?= $navActiveMobile('how-it-works.php') ?>"<?= $navStyle() ?> href="<?= BASE_URL ?>/how-it-works.php"><i aria-hidden="true" class="bi bi-signpost-split text-[20px] text-primary"></i>How It Works</a>
<?php if (!isLoggedIn()): ?>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-body-sm font-body-sm text-on-surface-variant hover:bg-surface-container hover:text-on-surface"<?= $navStyle() ?> href="<?= BASE_URL ?>/auth/login.php"><i aria-hidden="true" class="bi bi-box-arrow-in-right text-[20px] text-primary"></i>Sign in</a>
<a class="mt-1 px-4 py-3 rounded-full bg-primary text-on-primary text-center font-label-lg font-bold"<?= $navStyle() ?> href="<?= BASE_URL ?>/auth/register.php">Book now</a>
<?php endif; ?>
<?php endif; ?>
</nav>
        </div>
        </header>
<script>
(function () {
    var menuBtn = document.getElementById('mobileMenuBtn');
    var menuPanel = document.getElementById('mobileMenuPanel');
    var userToggle = document.getElementById('userMenuToggle');
    var userMenu = document.getElementById('userMenu');
    if (menuBtn && menuPanel) {
        var setMenuState = function (open) {
            menuPanel.classList.toggle('is-open', open);
            menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            // The label has to track the state, otherwise a screen reader is
            // told "Open menu" while the panel is already open.
            menuBtn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        };
        menuBtn.addEventListener('click', function () {
            setMenuState(!menuPanel.classList.contains('is-open'));
        });
        // Follow the viewport: the panel is display:none from md up, so leaving
        // it marked open in the DOM would strand a keyboard user on a
        // permanently "expanded" toggle.
        var wide = window.matchMedia('(min-width: 768px)');
        var onBreakpoint = function (e) { if (e.matches) { setMenuState(false); } };
        if (wide.addEventListener) { wide.addEventListener('change', onBreakpoint); }
        else if (wide.addListener) { wide.addListener(onBreakpoint); }
        document.addEventListener('click', function (e) {
            if (menuPanel.classList.contains('is-open') && !e.target.closest('#mobileMenuPanel') && !menuBtn.contains(e.target)) {
                setMenuState(false);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && menuPanel.classList.contains('is-open')) {
                setMenuState(false);
                menuBtn.focus();
            }
        });
    }
    if (userToggle && userMenu) {
        userToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = userMenu.classList.toggle('hidden') === false;
            userToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (!userMenu.classList.contains('hidden') && !e.target.closest('.user-menu-wrap')) {
                userMenu.classList.add('hidden');
                userToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !userMenu.classList.contains('hidden')) {
                userMenu.classList.add('hidden');
                userToggle.setAttribute('aria-expanded', 'false');
                userToggle.focus();
            }
        });
    }
})();
</script>
