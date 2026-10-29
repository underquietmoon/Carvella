<?php
/* includes/header.php - Shared document head and opening layout partial. */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/functions.php';

$currentUser = null;
if (isLoggedIn()) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $currentUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$adminLayout = !empty($adminLayout);
// Both portals put the page canvas on --background and draw panels on
// --surface-container-lowest, so a card separates from the page by tone
// rather than by a shadow. That is why the admin body is NOT bg-surface.
$bodyClass = $adminLayout
    ? 'admin-layout bg-background text-on-surface antialiased min-h-screen flex flex-col selection:bg-secondary-fixed selection:text-on-secondary-fixed'
    : 'bg-background text-on-surface font-body-md min-h-screen flex flex-col antialiased selection:bg-primary-fixed selection:text-primary';
?>
<!DOCTYPE html>
<html class="scroll-smooth" lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light dark">
<title><?= htmlspecialchars($pageTitle ?? SITE_NAME) ?></title>
<!-- Site favicon: resize of the CARVELLA logo. The .ico covers older tabs;
     the PNG sizes cover modern browsers; apple-touch-icon covers iOS home
     screen. Without this the browser falls back to XAMPP's default icon. -->
<link rel="icon" href="<?= BASE_URL ?>/favicon.ico?v=2" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="<?= BASE_URL ?>/assets/images/favicon/favicon-32x32.png?v=2">
<link rel="icon" type="image/png" sizes="16x16" href="<?= BASE_URL ?>/assets/images/favicon/favicon-16x16.png?v=2">
<link rel="apple-touch-icon" sizes="180x180" href="<?= BASE_URL ?>/assets/images/favicon/apple-touch-icon.png?v=2">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- Bootstrap Icons is self-hosted, not loaded from a CDN, so every icon is a
     guaranteed-available asset: if a CDN was blocked, slow or offline the
     glyphs would simply not render. Only the icon font is self-hosted; the
     text face below still comes from the Google CDN. -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css?v=1">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600&display=swap" rel="stylesheet">
<!-- Theme tokens must be defined before anything paints, otherwise the
     first frame renders in light mode and then snaps. -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css?v=20261016a">
<script>
/* Resolve the theme BEFORE first paint (no flash of the wrong theme).
   Order: saved choice > OS preference > light. Runs synchronously and
   stays inline so it executes ahead of the stylesheet work above. */
(function () {
    var KEY = 'carvella_theme';
    var stored = null;
    try { stored = localStorage.getItem(KEY); } catch (e) { /* private mode */ }
    var theme = stored === 'light' || stored === 'dark'
        ? stored
        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark' : 'light');
    var root = document.documentElement;
    root.classList.toggle('dark', theme === 'dark');
    root.setAttribute('data-theme', theme);
    root.style.colorScheme = theme;
})();

/* Entrance-animation handshake. The CSS reveal states (opacity: 0) must be
   in force before the first paint, otherwise the page flashes fully visible
   and then jumps back to hidden when app-motion.js takes over. So the
   document is marked "pending" here, at parse time, and app-motion.js flips
   it to "ready" the moment it imports successfully.
   The timer is the other half: if that module never evaluates -- blocked
   CDN, offline, script error -- nothing ever claims the flag, so it is
   cleared and the stylesheet's fallback renders every reveal target visible
   instead of stranding the page at opacity 0. */
(function () {
    var root = document.documentElement;
    root.setAttribute('data-motion', 'pending');
    window.setTimeout(function () {
        if (root.getAttribute('data-motion') === 'pending') {
            root.removeAttribute('data-motion');
        }
    }, 2500);
})();
</script>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          "colors": {
            "surface-dim": "var(--surface-dim)",
            "surface-tint": "var(--surface-tint)",
            "primary-fixed": "var(--primary-fixed)",
            "on-primary": "var(--on-primary)",
            "on-tertiary-fixed-variant": "var(--on-tertiary-fixed-variant)",
            "inverse-primary": "var(--inverse-primary)",
            "on-surface-variant": "var(--on-surface-variant)",
            "secondary-container": "var(--secondary-container)",
            "surface-container": "var(--surface-container)",
            "surface-container-high": "var(--surface-container-high)",
            "error-container": "var(--error-container)",
            "tertiary-fixed-dim": "var(--tertiary-fixed-dim)",
            "surface-variant": "var(--surface-variant)",
            "on-primary-fixed": "var(--on-primary-fixed)",
            "on-tertiary": "var(--on-tertiary)",
            "on-secondary": "var(--on-secondary)",
            "on-error-container": "var(--on-error-container)",
            "on-secondary-container": "var(--on-secondary-container)",
            "primary-fixed-dim": "var(--primary-fixed-dim)",
            "tertiary-container": "var(--tertiary-container)",
            "on-secondary-fixed": "var(--on-secondary-fixed)",
            "on-tertiary-container": "var(--on-tertiary-container)",
            "on-secondary-fixed-variant": "var(--on-secondary-fixed-variant)",
            "surface-container-low": "var(--surface-container-low)",
            "surface-container-lowest": "var(--surface-container-lowest)",
            "secondary-fixed-dim": "var(--secondary-fixed-dim)",
            "error": "var(--error)",
            "surface-bright": "var(--surface-bright)",
            "tertiary": "var(--tertiary)",
            "on-error": "var(--on-error)",
            "inverse-on-surface": "var(--inverse-on-surface)",
            "secondary-fixed": "var(--secondary-fixed)",
            "surface-container-highest": "var(--surface-container-highest)",
            "on-primary-container": "var(--on-primary-container)",
            "primary-container": "var(--primary-container)",
            "on-surface": "var(--on-surface)",
            "on-tertiary-fixed": "var(--on-tertiary-fixed)",
            "on-background": "var(--on-background)",
            "on-primary-fixed-variant": "var(--on-primary-fixed-variant)",
            "outline-variant": "var(--outline-variant)",
            "tertiary-fixed": "var(--tertiary-fixed)",
            "background": "var(--background)",
            "surface": "var(--surface)",
            "outline": "var(--outline)",
            "inverse-surface": "var(--inverse-surface)",
            "secondary": "var(--secondary)",
            "primary": "var(--primary)"
          },
          "borderRadius": {
            "DEFAULT": "1rem",
            "lg": "2rem",
            "xl": "3rem",
            "full": "9999px"
          },
          "spacing": {
            "gutter-desktop": "2rem",
            "space-3xl": "4rem",
            "space-md": "1rem",
            "space-4xl": "6rem",
            "space-xxs": "0.25rem",
            "space-xs": "0.5rem",
            "space-xl": "2rem",
            "space-2xl": "3rem",
            "space-lg": "1.5rem",
            "space-sm": "0.75rem",
            "gutter-mobile": "1rem",
            "container-max-width": "92rem"
          },
          "fontFamily": {
            "body-md": ["Plus Jakarta Sans", "sans-serif"],
            "headline-lg": ["Plus Jakarta Sans", "sans-serif"],
            "headline-xl": ["Plus Jakarta Sans", "sans-serif"],
            "headline-sm": ["Plus Jakarta Sans", "sans-serif"],
            "headline-md": ["Plus Jakarta Sans", "sans-serif"],
            "label-lg": ["Plus Jakarta Sans", "sans-serif"],
            "label-md": ["Plus Jakarta Sans", "sans-serif"],
            "label-sm": ["Plus Jakarta Sans", "sans-serif"],
            "body-sm": ["Plus Jakarta Sans", "sans-serif"],
            "numeric-stat": ["Plus Jakarta Sans", "sans-serif"],
            "display-hero": ["Plus Jakarta Sans", "sans-serif"],
            "body-lg": ["Plus Jakarta Sans", "sans-serif"]
          },
          "fontSize": {
            "label-md": ["12px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "600" }],
            "headline-lg": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.02em", "fontWeight": "700" }],
            "headline-xl": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.025em", "fontWeight": "700" }],
            "headline-sm": ["18px", { "lineHeight": "26px", "letterSpacing": "-0.01em", "fontWeight": "600" }],
            "headline-md": ["22px", { "lineHeight": "30px", "letterSpacing": "-0.015em", "fontWeight": "600" }],
            "body-md": ["14px", { "lineHeight": "22px", "letterSpacing": "0em", "fontWeight": "400" }],
            "label-lg": ["14px", { "lineHeight": "20px", "letterSpacing": "0.01em", "fontWeight": "600" }],
            "label-sm": ["11px", { "lineHeight": "14px", "letterSpacing": "0.04em", "fontWeight": "700" }],
            "body-sm": ["13px", { "lineHeight": "18px", "letterSpacing": "0.01em", "fontWeight": "400" }],
            "numeric-stat": ["32px", { "lineHeight": "38px", "letterSpacing": "-0.02em", "fontWeight": "800" }],
            "display-hero": ["52px", { "lineHeight": "60px", "letterSpacing": "-0.03em", "fontWeight": "800" }],
            "display-hero-mobile": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "800" }],
            "body-lg": ["16px", { "lineHeight": "26px", "letterSpacing": "0em", "fontWeight": "400" }],
            "headline-xl-mobile": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.02em", "fontWeight": "700" }]
          }
        }
      }
    }
  </script>
<style>
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      background-color: var(--background);
      color: var(--on-surface);
    }
    .bi {
      /* Bootstrap Icons draws each glyph on ::before. Normalise the element to
         the same inline-block / vertical-align: middle rhythm the previous
         icon font used, so icons still sit centred on their baseline. */
      display: inline-block;
      font-style: normal;
      vertical-align: middle;
      line-height: 1;
    }
    .bi::before {
      vertical-align: middle;
    }
    .custom-scrollbar::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
      background-color: var(--ui-line-strong);
      border-radius: 9999px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
      background: transparent;
    }
    /* The UI is flat: these three helpers no longer cast a shadow.
       assets/css/style.css also lists them in its shadow reset, so a
       leftover shadow-luxury-* in the markup is neutralised either
       way; they are kept as no-ops so old call sites stay valid. */
    .shadow-luxury-1,
    .shadow-luxury-2,
    .shadow-luxury-glow {
      box-shadow: none;
    }
    /* Collapsible capsule sidebar styles — Motion animates width/padding;
       CSS defines the resting shapes. */
    #sideNavBar {
      overflow: hidden;
      will-change: width, padding;
      /* Keep the mobile drawer's transform transition, let Motion own
         width/padding for desktop collapse to avoid conflicts */
      transition-property: transform;
      transition-duration: 0.3s;
      transition-timing-function: cubic-bezier(0.22, 1, 0.36, 1);
      backface-visibility: hidden;
      transform: translateZ(0);
    }
    #sideNavBar.collapsed {
      width: 64px;
      padding-left: 0.5rem;
      padding-right: 0.5rem;
    }
    /* The active/hover highlight hugs only the icon + label — no wide oval. */
    #sideNavBar .nav-link {
      width: fit-content;
      transition: background-color 0.28s ease, color 0.28s ease, border-color 0.28s ease, transform 0.2s ease;
      backface-visibility: hidden;
      transform: translateZ(0);
      will-change: width, height, padding, gap;
    }
    /* Collapsed — a centred 44px badge showing the icon only. */
    #sideNavBar.collapsed .nav-link {
      width: 44px;
      height: 44px;
      gap: 0;
      justify-content: center;
      padding-left: 0;
      padding-right: 0;
    }
    #sideNavBar.collapsed .nav-text,
    #sideNavBar.collapsed .header-info,
    #sideNavBar.collapsed .nav-tag {
      opacity: 0;
      max-width: 0;
      transform: translateX(0);
      pointer-events: none;
    }
    #sideNavBar .nav-text,
    #sideNavBar .header-info,
    #sideNavBar .nav-tag {
      max-width: 180px;
      opacity: 1;
      transform: translateX(0);
      overflow: hidden;
      white-space: nowrap;
      transition: none;
    }
    #sideNavBar .nav-link .bi,
    #sideNavBar .nav-link .icon-wrapper {
      transition: color 0.28s ease, transform 0.25s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      will-change: transform;
    }
    #sideNavBar .nav-link:hover .bi,
    #sideNavBar .nav-link:hover .icon-wrapper {
      transform: scale(1.08) translateY(-0.5px);
    }
    #sideNavBar .nav-link:active .bi,
    #sideNavBar .nav-link:active .icon-wrapper {
      transform: scale(1.05);
    }
    #sideNavBar.collapsed .sidebar-head {
      justify-content: center;
      padding-left: 0;
      padding-right: 0;
    }
    #sidebar-toggle {
      transition: all 0.2s ease;
    }
</style>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=20261024a">
</head>
<body class="<?= $bodyClass ?>">
<?php /* Skip link. Every page opens with a full admin top bar (brand, search,
       notifications, user menu) or the public navbar, all of which precede
       <main> in the tab order. A keyboard user therefore has to traverse that
       chrome on every single page load before reaching any content, and on the
       reservations screen the header also holds a 6-item filter row.

       It is the first focusable thing in the document, sits off-screen until
       focused, and jumps focus (not just the scroll position) into <main>, so
       the next Tab continues from inside the content. Every <main> already
       carries tabindex="-1" for exactly this target. */ ?>
<a href="#main-content" class="skip-link">Skip to main content</a>
<?php if ($adminLayout && isLoggedIn()): ?>
<!-- TOP APP BAR -->
<?php
$adminName = $currentUser['first_name'] . ' ' . ($currentUser['last_name'] ?? '');
$adminAvatarInitial = strtoupper(substr(trim($adminName), 0, 1) ?: 'A');

$notifLogs = [];
$notifResult = $conn->query("SELECT al.*, u.first_name, u.last_name FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 8");
if ($notifResult) {
    while ($nr = $notifResult->fetch_assoc()) {
        $notifLogs[] = $nr;
    }
}
?>
<header class="top-0 sticky z-50 bg-surface border-b border-outline-variant">
  <div class="flex justify-between items-center h-[68px] px-4 md:px-8 w-full gap-4">
    <!-- Brand -->
    <div class="flex items-center gap-3 shrink-0">
      <a class="flex items-center" href="<?= BASE_URL ?>/admin/dashboard.php">
        <span class="hidden sm:inline">
          <!-- Same dark-mode logo swap as the public navbar: the dark-ink art
               disappears on a dark app bar. The two are a display swap, so
               there is no layout shift and no flash before the theme resolves
               (includes/header.php sets .dark pre-paint). -->
          <img class="h-12 w-auto object-contain transition-transform duration-300 hover:scale-105 theme-logo-light" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO.png?v=2" alt="CARVELLA" width="718" height="548" loading="eager">
          <img class="h-12 w-auto object-contain transition-transform duration-300 hover:scale-105 theme-logo-dark" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO white.png?v=2" alt="CARVELLA" width="718" height="548" loading="eager">
        </span>
      </a>
      <button type="button" class="w-9 h-9 flex items-center justify-center text-on-surface-variant hover:text-primary transition-all active:scale-90 shrink-0" id="sidebar-toggle" title="Collapse or Expand sidebar" aria-label="Toggle sidebar" aria-controls="sideNavBar" aria-expanded="true">
        <i aria-hidden="true" class="bi bi-layout-sidebar-reverse text-[20px]" id="toggle-icon"></i>
      </button>
    </div>
    <!-- Actions & Profile Cluster -->
    <div class="flex items-center gap-3 shrink-0">
      <!-- Theme toggle: the plain sun/moon switch pill for everyone. The
           labelled Light/Dark state is pure CSS off .dark, so it reads
           correctly before hydration. Sits immediately left of notifications
           so the two controls read as one cluster. -->
      <?php $themeToggleVariant = 'switch'; $themeToggleClass = 'relative'; include __DIR__ . '/theme-toggle.php'; ?>
      <div class="relative notif-menu-wrap">
        <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center text-on-surface-variant hover:bg-surface-container hover:text-primary transition-all active:scale-90 shrink-0 border border-outline-variant bg-surface relative" id="notifToggle" aria-controls="notifMenu" aria-expanded="false" title="Notifications" aria-label="Notifications">
          <i aria-hidden="true" class="bi bi-bell text-[20px]"></i>
          <span id="notifBadge" class="hidden absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-error text-on-error text-[10px] font-bold items-center justify-center ring-2 ring-surface">0</span>
        </button>
        <div class="user-menu hidden absolute right-0 top-[calc(100%+10px)] w-80 max-w-[calc(100vw-2rem)] rounded-2xl bg-surface-container-lowest border border-outline-variant/40 shadow-xl p-2 z-50" id="notifMenu">
          <div class="px-3 py-2.5 mb-1 border-b border-outline-variant/40 flex items-center justify-between">
            <span class="text-label-sm font-label-sm text-tertiary uppercase tracking-wider font-semibold">Notifications</span>
            <a href="<?= BASE_URL ?>/admin/activity-logs.php" class="text-label-sm text-primary font-semibold hover:underline">View all</a>
          </div>
          <div class="max-h-80 overflow-y-auto custom-scrollbar">
            <?php if (empty($notifLogs)): ?>
            <div class="px-3 py-8 text-center text-tertiary text-body-sm">No notifications yet.</div>
            <?php else: ?>
              <?php foreach ($notifLogs as $nr): ?>
              <?php
                $notifActor = trim(($nr['first_name'] ?? '') . ' ' . ($nr['last_name'] ?? ''));
                if ($notifActor === '') $notifActor = 'System';
              ?>
              <a href="<?= BASE_URL ?>/admin/activity-logs.php" class="notif-item flex items-start gap-3 px-3 py-2.5 rounded-xl hover:bg-surface-container transition-colors" data-ts="<?= (int)strtotime($nr['created_at']) * 1000 ?>">
                <span class="w-8 h-8 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0">
                  <i aria-hidden="true" class="bi bi-bell text-[17px]"></i>
                </span>
                <span class="min-w-0">
                  <span class="block text-body-sm text-on-surface"><span class="font-semibold"><?= htmlspecialchars($notifActor) ?></span> <?= htmlspecialchars($nr['action'] ?? '') ?></span>
                  <?php if (!empty($nr['details'])): ?>
                  <span class="block text-[12px] text-tertiary truncate"><?= htmlspecialchars($nr['details']) ?></span>
                  <?php endif; ?>
                  <span class="block text-[11px] text-outline mt-0.5"><?= formatDateTime($nr['created_at']) ?></span>
                </span>
              </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="relative user-menu-wrap">
        <button type="button" class="flex items-center gap-2 cursor-pointer select-none appearance-none bg-transparent border-0 p-0 text-left" id="adminUserMenuToggle" aria-controls="adminUserMenu" aria-expanded="false">
          <span class="w-9 h-9 rounded-full bg-secondary-fixed text-on-secondary-fixed flex items-center justify-center font-bold text-label-sm shrink-0 ring-1 ring-outline-variant"><?= e($adminAvatarInitial) ?></span>
          <i aria-hidden="true" class="bi bi-chevron-down hidden xl:inline text-[18px] text-on-surface-variant"></i>
        </button>
        <div class="user-menu hidden absolute right-0 top-[calc(100%+10px)] w-56 rounded-2xl bg-surface-container-lowest border border-outline-variant/40 shadow-xl p-2 z-50" id="adminUserMenu">
          <div class="px-3 py-2.5 mb-1 border-b border-outline-variant/40">
            <span class="block text-label-sm font-label-sm text-on-surface font-bold truncate"><?= htmlspecialchars($adminName) ?></span>
            <span class="block text-[11px] text-tertiary truncate">Administrator</span>
          </div>
          <form method="POST" action="<?= BASE_URL ?>/auth/logout.php" class="contents">
            <?= csrf_field() ?>
            <button type="submit" class="w-full flex items-center gap-3 px-3 py-2 rounded-xl text-body-sm font-body-sm text-error hover:bg-error-container transition-colors cursor-pointer">
              <i class="bi bi-box-arrow-right text-[19px]" aria-hidden="true"></i>Log Out
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</header>
<?php endif; ?>
<script>
(function () {
    var toggle = document.getElementById('adminUserMenuToggle');
    var menu = document.getElementById('adminUserMenu');
    var notifToggle = document.getElementById('notifToggle');
    var notifMenu = document.getElementById('notifMenu');
    var notifBadge = document.getElementById('notifBadge');
    var notifItems = Array.prototype.slice.call(document.querySelectorAll('.notif-item'));
    var notifKey = 'carvella_admin_notif_seen';

    function getSeen() {
        try { return parseInt(localStorage.getItem(notifKey) || '0', 10) || 0; } catch (e) { return 0; }
    }
    function setSeen(ts) {
        try { localStorage.setItem(notifKey, String(ts)); } catch (e) {}
    }
    function updateBadge() {
        if (!notifBadge) return;
        var seen = getSeen();
        var count = notifItems.filter(function (el) {
            return (parseInt(el.getAttribute('data-ts'), 10) || 0) > seen;
        }).length;
        if (count > 0) {
            notifBadge.textContent = count > 9 ? '9+' : String(count);
            notifBadge.classList.remove('hidden');
            notifBadge.classList.add('flex');
        } else {
            notifBadge.classList.add('hidden');
            notifBadge.classList.remove('flex');
        }
    }

    if (toggle && menu) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = menu.classList.toggle('hidden') === false;
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (isOpen && notifMenu) {
                notifMenu.classList.add('hidden');
                if (notifToggle) notifToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('click', function (e) {
            if (!menu.classList.contains('hidden') && !e.target.closest('.user-menu-wrap')) {
                menu.classList.add('hidden');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !menu.classList.contains('hidden')) {
                menu.classList.add('hidden');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
    }

    if (notifToggle && notifMenu) {
        notifToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = notifMenu.classList.toggle('hidden') === false;
            notifToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (isOpen) {
                if (menu) {
                    menu.classList.add('hidden');
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');
                }
                setSeen(Date.now());
                updateBadge();
            }
        });
        document.addEventListener('click', function (e) {
            if (!notifMenu.classList.contains('hidden') && !e.target.closest('.notif-menu-wrap')) {
                notifMenu.classList.add('hidden');
                notifToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !notifMenu.classList.contains('hidden')) {
                notifMenu.classList.add('hidden');
                notifToggle.setAttribute('aria-expanded', 'false');
                notifToggle.focus();
            }
        });
    }

    updateBadge();
})();
</script>
<?php /* End of includes/header.php */ ?>
