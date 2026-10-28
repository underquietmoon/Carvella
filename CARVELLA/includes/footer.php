<?php
$isAdminFooter = !empty($adminLayout);
?>
<?php if (!$isAdminFooter): ?>
<!-- Footer (Shared Component, stitch design system) -->
<footer class="mt-auto bg-inverse-surface text-surface-bright">
  <div class="w-full max-w-container-max-width mx-auto px-6 py-12 md:px-12 md:py-14">
    <div class="grid grid-cols-1 gap-10 lg:grid-cols-12 lg:gap-12">
      <!-- Brand + Contact -->
      <div class="lg:col-span-5">
        <a href="<?= BASE_URL ?>/index.php" class="inline-flex items-center gap-2 text-2xl font-black tracking-tight text-surface-bright transition-colors hover:text-primary-fixed">
            <img class="theme-logo-inverse-light h-12 w-auto object-contain transition-transform duration-300 hover:scale-105" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO white.png?v=1" alt="CARVELLA" width="718" height="548" loading="lazy">
            <img class="theme-logo-inverse-dark h-12 w-auto object-contain transition-transform duration-300 hover:scale-105" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO.png?v=1" alt="CARVELLA" width="718" height="548" loading="lazy">
        </a>
        <p class="mt-4 text-body-sm font-body-sm leading-relaxed text-tertiary-fixed-dim max-w-sm">
          Reliable vehicles, thoughtful service, and a smoother way to get where you need to go across Pangasinan.
        </p>
        <address id="contact" class="mt-6 not-italic space-y-3 text-body-sm font-body-sm text-tertiary-fixed-dim">
          <span class="flex items-start gap-3">
            <i aria-hidden="true" class="bi bi-shop text-primary-fixed text-[18px] shrink-0 mt-0.5"></i>
            AB Fernandez Ave Central Hub<br class="sm:hidden"> Dagupan City, Pangasinan
          </span>
          <span class="flex items-center gap-3">
            <i aria-hidden="true" class="bi bi-telephone text-primary-fixed text-[18px] shrink-0"></i>
            +63 (075) 522-8800
          </span>
          <span class="flex items-center gap-3">
            <i aria-hidden="true" class="bi bi-envelope text-primary-fixed text-[18px] shrink-0"></i>
            <a class="footer-link" href="mailto:support@carvella.ph">support@carvella.ph</a>
          </span>
        </address>
      </div>

      <!-- Footer Navigation -->
      <div class="lg:col-span-7 grid grid-cols-2 gap-x-10 gap-y-10 sm:grid-cols-3">
        <div>
          <h2 class="text-label-md font-label-md uppercase tracking-[0.14em] text-primary-fixed">NAVIGATE</h2>
          <ul class="mt-4 space-y-2.5">
            <li><a class="footer-link" href="<?= BASE_URL ?>/index.php">Home</a></li>
            <li><a class="footer-link" href="<?= BASE_URL ?>/customer/vehicles.php">Vehicles</a></li>
            <li><a class="footer-link" href="<?= BASE_URL ?>/how-it-works.php">How It Works</a></li>
          </ul>
        </div>
        <div>
          <h2 class="text-label-md font-label-md uppercase tracking-[0.14em] text-primary-fixed">ACCESS</h2>
          <ul class="mt-4 space-y-2.5">
            <li><a class="footer-link" href="<?= BASE_URL ?>/auth/login.php">Sign in</a></li>
            <li><a class="footer-link" href="<?= BASE_URL ?>/auth/register.php">Sign up</a></li>
          </ul>
        </div>
        <div>
          <h2 class="text-label-md font-label-md uppercase tracking-[0.14em] text-primary-fixed">LEGAL</h2>
          <ul class="mt-4 space-y-2.5">
            <li><a class="footer-link" href="<?= BASE_URL ?>/terms.php">Terms of Service</a></li>
            <li><a class="footer-link" href="<?= BASE_URL ?>/privacy-policy.php">Privacy Policy</a></li>
            <li><a class="footer-link" href="<?= BASE_URL ?>/cookie-policy.php">Cookie Policy</a></li>
            <!-- Reopens the consent banner so an Accept/Reject decision is
                 never final. Self-contained rather than delegated from
                 includes/cookie-consent.php, because that include only
                 renders on index.php while this link renders everywhere. -->
            <li><a class="footer-link" href="<?= BASE_URL ?>/index.php" id="revokeCookieConsent">Cookie Settings</a></li>
          </ul>
        </div>
      </div>
    </div>

      <!-- Copyright -->
      <div class="pt-8 mt-10 border-t border-surface-bright/10 text-body-sm font-body-sm text-tertiary-fixed-dim flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <p>&copy; <?= date('Y') ?> CARVELLA. All rights reserved.</p>
        <p>Dagupan City, Pangasinan &middot; Open 24/7</p>
      </div>
    </div>

    <!-- Public theme control.
         Rendered here rather than in the navbar: as a switch pill it
         crowded the header and read as a second primary action. The FAB
         variant pairs with the back-to-top button just below, which is
         the only other floating control on public pages. Admin pages get
         their own switch in the rail, so this is suppressed there to
         avoid offering the same control twice on one page. -->
    <?php $themeToggleVariant = 'fab'; include __DIR__ . '/theme-toggle.php'; ?>
  </footer>
  <?php endif; ?>
<!-- Back to Top Button (global) -->
<button type="button" class="back-to-top-btn" id="backToTop" aria-label="Back to top">
<i aria-hidden="true" class="bi bi-arrow-up"></i>
</button>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=20261012a"></script>
<script>
// Cookie Settings — clears the recorded consent choice and returns to the
// landing page so the banner asks again. Inline here (rather than in
// includes/cookie-consent.php) because that include only renders on
// index.php, while this link appears in the footer of every public page.
document.addEventListener('DOMContentLoaded', function () {
    var revoke = document.getElementById('revokeCookieConsent');
    if (!revoke) return;
    revoke.addEventListener('click', function (e) {
        e.preventDefault();
        try { localStorage.removeItem('carvella_cookie_consent'); } catch (err) { /* private mode */ }
        window.location.href = revoke.getAttribute('href');
    });
});
</script>
<script>
// Theme toggle fallback.
//
// app-motion.js is a type="module" that statically imports motion from
// cdn.jsdelivr.net. If the CDN is blocked, offline, or slow, the whole module
// never evaluates, and initThemeToggle() inside it never runs - which left
// every [data-theme-toggle] button rendering as a dead control. This classic
// script registers the same handler independently, so the control always
// works. app-motion.js guards its own binding with the data flag below, so the
// two never double-fire and the spring animation simply layers on top when the
// module does load.
(function () {
    if (document.documentElement.dataset.themeToggleBound === '1') return;
    document.documentElement.dataset.themeToggleBound = '1';

    var root = document.documentElement;
    var KEY = 'carvella_theme';
    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-theme-toggle]'));
    if (!buttons.length) return;

    // Read the stored preference up front so the first paint and the button
    // label agree with each other instead of flipping after load.
    try {
        var stored = localStorage.getItem(KEY);
        if (stored === 'dark') {
            root.classList.add('dark');
            root.setAttribute('data-theme', 'dark');
            root.style.colorScheme = 'dark';
        } else if (stored === 'light') {
            root.classList.remove('dark');
            root.setAttribute('data-theme', 'light');
            root.style.colorScheme = 'light';
        }
    } catch (err) { /* private mode: fall back to whatever the server rendered */ }

    var sync = function () {
        var dark = root.classList.contains('dark');
        buttons.forEach(function (btn) {
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            var label = dark ? 'Switch to light theme' : 'Switch to dark theme';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        });
    };

    // The theme-reveal iris lives HERE, not only in app-motion.js, because
    // this handler is the one that always wins. app-motion.js is a
    // type="module" (deferred by definition) and guards its own binding on
    // the data flag this script sets at parse time, so initThemeToggle()
    // always early-returns and its commit() is never wired to a click.
    // Implementing the reveal here means the animation works with no CDN and
    // no module evaluation at all.
    var reduced = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var revealing = false;

    // Iris geometry: the control's centre, plus a radius that reaches the
    // furthest viewport corner so the spring's tail lands fully covered.
    var setRevealOrigin = function (btn) {
        var r = btn.getBoundingClientRect();
        var x = r.left + r.width / 2;
        var y = r.top + r.height / 2;
        var farX = Math.max(x, window.innerWidth - x);
        var farY = Math.max(y, window.innerHeight - y);
        root.style.setProperty('--theme-reveal-x', x + 'px');
        root.style.setProperty('--theme-reveal-y', y + 'px');
        root.style.setProperty('--theme-reveal-r', Math.sqrt(farX * farX + farY * farY) + 'px');
    };

    var swap = function (dark) {
        root.classList.toggle('dark', dark);
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        root.style.colorScheme = dark ? 'dark' : 'light';
    };

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dark = !root.classList.contains('dark');
            var canReveal = typeof document.startViewTransition === 'function'
                && !reduced && !revealing;

            if (canReveal) {
                setRevealOrigin(btn);
                revealing = true;
                var t = document.startViewTransition(function () { swap(dark); });
                t.finished.catch(function () { /* skipped or interrupted */ }).then(function () {
                    revealing = false;
                    root.style.removeProperty('--theme-reveal-x');
                    root.style.removeProperty('--theme-reveal-y');
                    root.style.removeProperty('--theme-reveal-r');
                });
            } else {
                // No View Transitions API, reduced motion, or a reveal already
                // in flight: plain cross-fade (theme.css .theme-transition).
                if (!reduced) {
                    document.body.classList.add('theme-transition');
                    window.setTimeout(function () {
                        document.body.classList.remove('theme-transition');
                    }, 340);
                }
                swap(dark);
            }

            try { localStorage.setItem(KEY, dark ? 'dark' : 'light'); } catch (err) { /* private mode */ }
            sync();
        });
    });

    sync();
})();
</script>
<script type="module" src="<?= BASE_URL ?>/assets/js/app-motion.js?v=20261013g"></script>
<?php if ($adminLayout): ?>
<script src="<?= BASE_URL ?>/assets/js/admin.js?v=20260929n"></script>
<?php endif; ?>
<?php if (isset($extraScripts)): ?>
<?php foreach ($extraScripts as $script): ?>
<script src="<?= BASE_URL ?>/assets/js/<?= htmlspecialchars($script) ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
