document.addEventListener('DOMContentLoaded', function() {
    /* ============================================
       1. NAVBAR SCROLL STATE
       ============================================ */
    var navbar = document.getElementById('siteNavbar');
    var updateNavbar = function() {
        if (navbar) navbar.classList.toggle('navbar-scrolled', window.scrollY > 16);
    };
    updateNavbar();
    window.addEventListener('scroll', updateNavbar, { passive: true });

/* ============================================
        1c. BACK TO TOP BUTTON — non-admin pages
        ============================================ */
    var backToTopBtn = document.getElementById('backToTop');
    var updateBackToTop = function() {
        if (!backToTopBtn) return;
        backToTopBtn.classList.toggle('is-visible', window.scrollY > 480);
    };
    updateBackToTop();
    if (backToTopBtn) {
        window.addEventListener('scroll', updateBackToTop, { passive: true });
        backToTopBtn.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* ============================================
        2. CURSOR SPOTLIGHT — feeds .card-spotlight::before
        Writes the pointer position as px offsets from the card's top
        left into --mouse-x/--mouse-y; the gradient in CSS reads them.
        Only the two custom properties are set, never a style string,
        so the browser can skip repaint work for the rest of the card.
        ============================================ */
    var spotlightCards = document.querySelectorAll('.card-spotlight');
    spotlightCards.forEach(function(card) {
        var raf = null;
        card.addEventListener('pointermove', function(e) {
            if (raf) return;
            raf = requestAnimationFrame(function() {
                var rect = card.getBoundingClientRect();
                card.style.setProperty('--mouse-x', (e.clientX - rect.left) + 'px');
                card.style.setProperty('--mouse-y', (e.clientY - rect.top) + 'px');
                raf = null;
            });
        }, { passive: true });
    });

    /* ============================================
        2b. PARALLAX — subtle scroll drift for [data-parallax]
        layers. Desktop / non-reduced-motion only so touch
        stays jitter-free and motion-averse users see no movement.
        ============================================ */
    var parallaxEls = Array.prototype.slice.call(document.querySelectorAll('[data-parallax]'));
    var reduceMotionPref = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var finePointer = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
    if (parallaxEls.length && !reduceMotionPref && finePointer) {
        var pTicking = false;
        var applyParallax = function() {
            var vpCenter = window.innerHeight / 2;
            parallaxEls.forEach(function(el) {
                var rect = el.getBoundingClientRect();
                if (rect.bottom < -120 || rect.top > window.innerHeight + 120) return;
                var speed = parseFloat(el.getAttribute('data-parallax')) || 0.15;
                var delta = (rect.top + rect.height / 2) - vpCenter;
                var translate = Math.round(-delta * speed * 100) / 100;
                if (Math.abs(translate) < 0.1) translate = 0;
                el.style.transform = 'translate3d(0, ' + translate + 'px, 0)';
            });
            pTicking = false;
        };
        var onParallaxScroll = function() {
            if (!pTicking) {
                pTicking = true;
                window.requestAnimationFrame(applyParallax);
            }
        };
        window.addEventListener('scroll', onParallaxScroll, { passive: true });
        window.addEventListener('resize', onParallaxScroll, { passive: true });
        window.addEventListener('load', applyParallax, { once: true });
        applyParallax();
    }

    /* ============================================
        3. UTILITIES — expose for inline page scripts
        ============================================ */
    /* Re-play the entrance of a card that just became visible again after filtering */
    window.reenterCard = function(el) {
        if (!el) return;
        el.classList.remove('card-reenter');
        void el.offsetWidth;
        el.classList.add('card-reenter');
    };

    /* Pulse a live value (calculator ledger) when it updates */
    window.pulseValue = function(el) {
        if (!el) return;
        el.classList.remove('value-pulse');
        void el.offsetWidth;
        el.classList.add('value-pulse');
    };

    /* ============================================
        4. ALERT AUTO-DISMISS — with exit animation
        ============================================ */
    var alerts = document.querySelectorAll('.alert, .alert-enter');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-8px) scale(0.97)';
            alert.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            setTimeout(function() { alert.remove(); }, 350);
        }, 5000);
    });

    /* ============================================
        6. FORM SUBMIT LOCK — confirm, then fire exactly once
       ============================================ */
    /* Delegated, so it also covers rows rendered after load.
       
       Two things are handled here:

       a) Confirmation. A POST form declares its prompt via data-confirm.

       b) The double-submit lock. This used to apply ONLY to forms carrying
          data-confirm, so every unguarded submit button in the app — book a
          reservation, register a client, upload a vehicle, log in, log out —
          could be fired twice by a double-click or an impatient Enter key.
          For POST that means a duplicate reservation, a duplicate user, a
          duplicated inspection row, or (on logout) a second request against
          an already-destroyed session landing the user on a raw 403 page.

          Now every POST form is locked on its first submit, so the action
          runs once and the button reports the in-flight state to assistive
          tech. GET forms are deliberately left alone: they are read-only
          filters/reports and blocking them would break the second click
          while a page is already navigating.

          On a validation error the server re-renders a fresh DOM, so the
          lock never strands a disabled button across a failed submit. */
    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM' || !form.dataset) return;

        // Already dispatched: this is the second submit, drop it.
        if (form.dataset.submitting === '1') {
            e.preventDefault();
            return;
        }

        var method = String(form.getAttribute('method') || 'GET').toUpperCase();
        var shouldLock = method === 'POST' || form.dataset.submitOnce === '1';
        if (!shouldLock) return;

        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            e.preventDefault();
            return;
        }

        form.dataset.submitting = '1';
        // Disable every submit control, not just the first. Forms here can
        // carry more than one (different actions, or a destructive secondary),
        // and leaving a sibling enabled would let a second click through the
        // guard on a different code path.
        var controls = form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"], input[type="image"]');
        Array.prototype.forEach.call(controls, function (btn) {
            if (!btn.disabled) {
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
            }
        });
    }, true);

    /* ============================================
        6b. PHONE INPUTS — digits only
       Philippine mobile numbers are all digits (e.g. 09171234567), so every
       phone field accepts 0-9 and nothing else: letters, +, spaces, dashes and
       brackets are rejected as they are typed or pasted. Delegated so it also
       covers fields rendered after load (admin modals, profile forms).
       ============================================ */
    var PHONE_FIELD = 'input[type="tel"], input[data-phone]';
    var PHONE_ALLOWED = /[^0-9]/;
    document.addEventListener('beforeinput', function(e) {
        var t = e.target;
        if (!t || !t.matches || !t.matches(PHONE_FIELD)) return;
        // Only block invalid characters as they are typed. Pasted text is left
        // to the input handler below so a formatted number can be pasted and
        // then cleaned, instead of the paste silently doing nothing.
        if (e.inputType === 'insertText' && e.data && PHONE_ALLOWED.test(e.data)) {
            e.preventDefault();
        }
    });
    document.addEventListener('input', function(e) {
        var t = e.target;
        if (!t || !t.matches || !t.matches(PHONE_FIELD)) return;
        var cleaned = t.value.replace(/[^0-9]/g, '');
        if (cleaned !== t.value) {
            t.value = cleaned;
        }
    });

    /* ============================================
        7. SMOOTH SCROLL FOR ANCHOR LINKS
        ============================================ */
    document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
        anchor.addEventListener('click', function(e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
});

function formatDate(dateString) {
    var options = { year: 'numeric', month: 'short', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2);
}