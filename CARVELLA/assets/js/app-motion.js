/*
 * app-motion.js — single Motion-powered driver for site-wide JS animation.
 *
 * Replaces the legacy CSS/class reveals and page transitions with Motion
 * (https://motion.dev) while leaving the markup untouched. The CSS in the
 * design system stays as a quiet fallback: reduced-motion users and CDN
 * failures simply render static, fully-visible content.
 *
 * Responsibilities
 *   - Staged page opening: public hero cascade, admin chrome (top bar, rail,
 *     rail links) then the blocks of <main>
 *   - Scroll reveals below the fold (sections, cards, tables, metric cards)
 *   - Page leave transitions
 *   - Numeric stat counters
 *   - Micro-interactions: hover/press feedback on buttons and the link/tab
 *     shapes that act as buttons (cards are intentionally static)
 *   - Light/dark theme toggle: persistence, OS-preference sync, a11y state
 *     and an icon morph
 *   - Brand marquee (seamless infinite rAF loop, pause on hover)
 *   - Admin capsule sidebar collapse / expand (icon-only badge when collapsed)
 */
import { animate, scroll } from 'https://cdn.jsdelivr.net/npm/motion@12.23.12/+esm';

/* The pre-paint script in includes/header.php marks the document as
   "pending" before the first frame, which is what keeps the CSS reveal
   states hidden instead of flashing. Claim it here so the stylesheet knows
   a real animation is coming; if this module never evaluates (blocked CDN,
   offline) the header's timer clears the flag and the CSS fallback in
   style.css renders everything visible. */
document.documentElement.setAttribute('data-motion', 'ready');

const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const EASE = [0.22, 1, 0.36, 1];
const SPRING = [0.34, 1.4, 0.64, 1];
/* Page-to-page motion uses a symmetric ease-in-out instead of SPRING or EASE:
   the outgoing page accelerates away and settles, rather than SPRING's
   1.4 overshoot (a visible bounce) or EASE's fast-out-then-hold (a snap).
   Overshoot-free on purpose — navigation should glide, never wobble. */
const GLIDE = [0.45, 0, 0.55, 1];
const isAdmin = document.body.classList.contains('admin-layout');

/* Entrance cascade tuning. A step of ~55ms reads as one gesture; anything
   slower stops feeling like a sequence and starts feeling like latency.
   The cap keeps a long list (6 metric cards, a 12-row table) from pushing
   its last item past the point where the page looks unfinished. */
const ENTER_STEP = 0.055;
const ENTER_CAP = 0.5;
const ENTER_BASE_PUBLIC = 0.06;
const ENTER_BASE_ADMIN = 0.12;

const pageMain = document.querySelector('main');
const pageFooter = document.querySelector('body > footer');

const clearInline = (el) => {
    el.style.removeProperty('opacity');
    el.style.removeProperty('transform');
    el.style.removeProperty('scale');
};

/* ---------------------------------------------------------------
 * 1. ENTRANCE TARGETS
 *
 * Every opening animation in this file — the public hero, the admin
 * chrome, the blocks inside <main> — is assembled here, then split in
 * two by initEntrance():
 *
 *   in-viewport  -> a timed cascade (real delays, the authored ones
 *                   where the markup supplies them)
 *   below-fold   -> a scroll-linked tween via Motion's scroll()
 *
 * The split is the whole point. Handing an above-the-fold block to
 * scroll() resolves it on the first frame, so every authored stagger
 * collapsed into "visible immediately" and the opening read as a flat
 * fade. Timed entrances for what is already on screen, scroll-linked
 * reveals for what has not arrived yet.
 * --------------------------------------------------------------- */
const ENTRANCE_FROM = {
    block: { opacity: 0, y: 24 },
    left: { opacity: 0, x: -24 },
    right: { opacity: 0, x: 24 },
    scale: { opacity: 0, y: 12, scale: 0.95 },
    metric: { opacity: 0, y: 16, scale: 0.98 },
    row: { opacity: 0, y: 10 },
    hero: { opacity: 0, y: 20, scale: 0.98 },
    'hero-child': { opacity: 0, y: 16 }
};

/* The hidden frame pinned synchronously before an animation starts, so
   nothing paints in its final position first. */
const PINNED_TRANSFORM = {
    block: 'translate3d(0,24px,0)',
    left: 'translate3d(-24px,0,0)',
    right: 'translate3d(24px,0,0)',
    scale: 'translate3d(0,12px,0) scale(0.95)',
    metric: 'translate3d(0,16px,0) scale(0.98)',
    row: 'translate3d(0,10px,0)',
    hero: 'translate3d(0,20px,0) scale(0.98)',
    'hero-child': 'translate3d(0,16px,0)'
};

const REVEAL_CLASSES = [
    'reveal-on-scroll', 'reveal-scale', 'reveal-left', 'reveal-right',
    'hero-entrance', 'hero-entrance-child', 'table-row-enter', 'sidebar-sticky-enter'
];

/* Cards are held still now: no hover lift/tilt/zoom, and no load/scroll
   entrance. A card rides whatever entrance its containing block receives,
   so the only way to keep a card from sliding in is to refuse to animate
   the card AND any block that contains one - otherwise the section
   entrance would carry it after all. Buttons, nav, forms, tables and the
   marquee are deliberately NOT in this set. */
const STATIC_CARD_SELECTOR =
    '.vehicle-card, .veh-card, .glass-card, .step-flow-card, .spot-card, ' +
    '.metric-stagger > *';

/* Variants allowed to sit inside another animated target. Everything else
   is dropped when an ancestor is already animating: two nested fades on the
   same pixels compound into a slower, mushier entrance than either alone. */
const NESTABLE = new Set(['hero-child', 'metric', 'row']);

/* An element can only be animated if it is actually rendered and can own a
   transform. Off-screen drawers (the admin rail is translateX(-100%) on a
   phone), display:none panels, and collapsed menus all fail at least one of
   these, and animating them either fights a transform another rule owns or
   leaves a hidden frame behind that never clears. */
function isAnimatable(el) {
    if (!el || !(el instanceof Element) || !el.isConnected) return false;
    if (el.matches('[hidden], [data-no-motion]')) return false;
    if (el.closest('[hidden]')) return false;
    if (!el.getClientRects().length) return false;
    const style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    if (style.position === 'fixed') return false;
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
}

/* Mark an element only if no ancestor is already animating, unless this
   variant is one of the intentionally-nested ones.

   Anything we decline to animate is pushed onto `skipped` instead of being
   silently ignored. This matters more than it looks: an element that keeps a
   reveal class nobody is going to animate stays at opacity 0 forever. That is
   how a section nested inside a marked block used to vanish, and how content
   inside a display:none panel stayed invisible after the panel opened. */
function mark(targets, skipped, el, variant) {
    if (!el || !(el instanceof Element) || targets.has(el)) return;
    // Cards (and any block that contains one) never enter the entrance set.
    if (el.matches(STATIC_CARD_SELECTOR) || el.querySelector(STATIC_CARD_SELECTOR)) {
        skipped.add(el);
        return;
    }
    if (!isAnimatable(el)) { skipped.add(el); return; }
    if (!NESTABLE.has(variant)) {
        for (let node = el.parentElement; node; node = node.parentElement) {
            if (targets.has(node)) { skipped.add(el); return; }
        }
    }
    targets.set(el, variant);
}

function collectEntranceTargets() {
    const targets = new Map();
    const skipped = new Set();
    const markAll = (selector, variant) => {
        document.querySelectorAll(selector).forEach((el) => mark(targets, skipped, el, variant));
    };

    markAll('main > *', 'block');
    if (!isAdmin) markAll('main section, main article, main form', 'block');
    markAll('.reveal-on-scroll', 'block');
    markAll('.reveal-scale', 'scale');
    markAll('.reveal-left', 'left');
    markAll('.reveal-right, .sidebar-sticky-enter', 'right');
    // Cards are intentionally NOT entrance targets any more - they are held
    // static, see STATIC_CARD_SELECTOR. FAQ rows stay so the accordion still
    // assembles.
    markAll('.faq-item-reveal', 'block');
    markAll('main tbody tr', 'row');

    // The hero is a composition, not a block: the section itself scales in
    // while its authored children cascade on their own --hero-delay timings.
    // It has to be re-marked after the main > * sweep above claimed it as a
    // plain block, otherwise the section loses its scale.
    const firstSection = document.querySelector('main > section:first-child');
    if (firstSection && firstSection.querySelector('.hero-entrance-child')) {
        if (targets.has(firstSection)) targets.set(firstSection, 'hero');
        markAll('.hero-entrance', 'block');
        firstSection.querySelectorAll('.hero-entrance-child')
            .forEach((el) => mark(targets, skipped, el, 'hero-child'));
    } else {
        markAll('.hero-entrance', 'block');
        markAll('.hero-entrance-child', 'block');
    }

    // Declined elements still carry a CSS hidden state. Drop it, so "we chose
    // not to animate this" can never be read as "this is invisible".
    skipped.forEach((el) => el.classList.remove(...REVEAL_CLASSES));

    return targets;
}

/* Authored stagger, in seconds. --hero-delay is written in ms by the markup
   (100ms, 190ms, 280ms ...); --i is a unitless index used by the admin
   metric cards. Anything unparseable falls back to the cascade. */
function authoredDelay(el, variant) {
    const raw = el.style.getPropertyValue('--hero-delay') || '';
    if (raw) {
        const ms = parseFloat(raw);
        if (isFinite(ms) && ms >= 0) return ms / 1000;
    }
    if (variant === 'metric') {
        const index = parseFloat(el.style.getPropertyValue('--i'));
        if (isFinite(index) && index >= 0) return index * 0.05;
    }
    return null;
}

/* ---------------------------------------------------------------
 * 2. PAGE ENTER — the staged opening
 * --------------------------------------------------------------- */
function initEntrance() {
    if (reduceMotion) return;

    const targets = collectEntranceTargets();
    if (!targets.size && !pageFooter) return;

    // The footer is outside <main> and outside the cascade; it gets a soft
    // tail on its own so the page does not look cut off at the end.
    if (pageFooter) {
        pageFooter.style.opacity = '0';
        animate(pageFooter, { opacity: [0, 1], y: [10, 0] }, {
            duration: 0.55,
            delay: 0.16,
            easing: EASE,
            onComplete: () => clearInline(pageFooter)
        });
    }

    const base = isAdmin ? ENTER_BASE_ADMIN : ENTER_BASE_PUBLIC;
    const viewportLimit = window.innerHeight * 0.9;

    // Document order is the reading order, so the cascade follows the eye.
    const ordered = Array.from(targets.entries()).sort((a, b) => {
        if (a[0] === b[0]) return 0;
        return a[0].compareDocumentPosition(b[0]) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
    });

    const entrance = [];
    const belowFold = [];
    ordered.forEach(([el, variant]) => {
        const rect = el.getBoundingClientRect();
        if (rect.top < viewportLimit && rect.bottom > 0) entrance.push([el, variant]);
        else belowFold.push([el, variant]);
    });

    // Pin the hidden frame and strip the CSS reveal classes in the same
    // synchronous pass, so there is no flash of un-revealed content.
    ordered.forEach(([el]) => {
        el.classList.remove(...REVEAL_CLASSES);
    });

    // --- Timed cascade for everything already on screen ---
    const delays = new Map();
    let index = 0;
    entrance.forEach(([el, variant]) => {
        const authored = authoredDelay(el, variant);
        let delay = authored !== null ? authored : base + index * ENTER_STEP;
        // A nested target (metric cards inside an animated block) waits for
        // its container plus a beat, so the two never overlap.
        if (authored === null) {
            for (let node = el.parentElement; node; node = node.parentElement) {
                if (delays.has(node)) { delay = Math.max(delay, delays.get(node) + 0.06); break; }
            }
        }
        delay = Math.min(delay, ENTER_CAP);
        delays.set(el, delay);
        index++;

        const from = ENTRANCE_FROM[variant] || ENTRANCE_FROM.block;
        el.style.opacity = '0';
        el.style.transform = PINNED_TRANSFORM[variant] || PINNED_TRANSFORM.block;
        animate(el, {
            opacity: [0, 1],
            x: [from.x || 0, 0],
            y: [from.y || 0, 0],
            scale: [from.scale || 1, 1]
        }, {
            duration: 0.6,
            delay,
            easing: EASE,
            onComplete: () => clearInline(el)
        });
    });

    // --- Scroll-linked reveals for everything below the fold ---
    let rows = 0;
    belowFold.forEach(([el, variant]) => {
        const from = ENTRANCE_FROM[variant] || ENTRANCE_FROM.block;
        el.style.opacity = '0';
        el.style.transform = PINNED_TRANSFORM[variant] || PINNED_TRANSFORM.block;
        const endPercent = variant === 'row'
            ? Math.max(50, 100 - Math.min(rows++, 15) * 3)
            : 60;
        const animation = animate(el, {
            opacity: [0, 1],
            x: [from.x || 0, 0],
            y: [from.y || 0, 0],
            scale: [from.scale || 1, 1]
        }, { ease: EASE });
        scroll(animation, {
            target: el,
            offset: ['start end', `start ${endPercent}%`]
        });
    });
}

/* ---------------------------------------------------------------
 * 2b. ADMIN CHROME — top bar, rail, rail links
 *
 * The admin opening used to be a single fade on <main>, so the shell
 * was simply there while the content arrived underneath it. This is
 * the shell's own entrance: the bar drops, the rail settles, the links
 * cascade, and the page blocks follow (see initEntrance).
 *
 * The rail gets opacity only, never a transform: on a phone it is held
 * off-canvas by Tailwind's -translate-x-full, and writing a transform
 * here would strand the drawer in the middle of the screen. The rail
 * links animate because they are the same elements the collapse
 * animation resizes, and opacity/x do not collide with width/height.
 * --------------------------------------------------------------- */
function initAdminChrome() {
    if (reduceMotion || !isAdmin) return;

    const topBar = document.body.querySelector(':scope > header');
    if (topBar && isAnimatable(topBar)) {
        animate(topBar, { opacity: [0, 1], y: [-14, 0] }, {
            duration: 0.5,
            delay: 0.02,
            easing: EASE,
            onComplete: () => clearInline(topBar)
        });
    }

    const rail = document.getElementById('sideNavBar');
    if (!rail || !isAnimatable(rail)) return;

    animate(rail, { opacity: [0, 1] }, {
        duration: 0.45,
        delay: 0.07,
        easing: EASE,
        onComplete: () => clearInline(rail)
    });

    const links = Array.from(rail.querySelectorAll('.nav-link'));
    links.forEach((link, i) => {
        if (!isAnimatable(link)) return;
        animate(link, { opacity: [0, 1], x: [-8, 0] }, {
            duration: 0.4,
            delay: 0.14 + i * 0.035,
            easing: EASE,
            onComplete: () => clearInline(link)
        });
    });
}

/* ---------------------------------------------------------------
 * 3. PRICE / STAT COUNTER — Motion-driven number counting
 *
 * Two rules drive this section:
 *   1. A counter must never leave a wrong number on screen. The old
 *      version overwrote every target with a formatted 0 at init and
 *      relied on an IntersectionObserver to restore it, so any stat
 *      the observer never reached (below the fold, inside a collapsed
 *      panel, threshold never met) displayed 0 forever. Targets now
 *      keep their server-rendered text until they actually animate.
 *   2. The final frame is the original markup text, written back
 *      verbatim, so a rounded tween can never turn 66.7% into 67%.
 * --------------------------------------------------------------- */

/* Matches a label made of exactly one number, e.g. "2,000", "₱2,000.00",
   "66.7%". Anything carrying a second number — "12 / 12", "3 of 8" — is
   rejected: the old parser stripped every separator and read "1212". */
const SINGLE_NUMBER = /^(\D*?)(-?[\d,]*\.?\d+)(\D*)$/;

function parseCounterValue(text) {
    if (typeof text !== 'string') return null;
    const trimmed = text.trim();
    if (!trimmed) return null;
    const groups = trimmed.match(/\d[\d,.]*/g);
    if (!groups || groups.length !== 1) return null;
    const parts = trimmed.match(SINGLE_NUMBER);
    if (!parts) return null;
    const num = parseFloat(parts[2].replace(/,/g, ''));
    if (!isFinite(num) || num <= 0) return null;
    const decimals = (parts[2].split('.')[1] || '').length;
    return { num, prefix: parts[1], suffix: parts[3], decimals };
}

/* Locale-pinned thousands separator. An unpinned toLocaleString() renders
   "2,000" as "2000" or "2 000" depending on the visitor's OS settings. */
const groupDigits = (value) => {
    const fixed = value.toFixed(2);
    const [whole, cents] = fixed.split('.');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (cents === '00' ? '' : '.' + cents);
};

/**
 * Tween the number inside `el` between two values.
 * @param {HTMLElement} el
 * @param {number} from
 * @param {number} to
 * @param {{duration?:number, prefix?:string, suffix?:string, decimals?:number,
 *          final?:string, onDone?:Function}} opts
 */
function tweenNumber(el, from, to, opts) {
    if (!el) return;
    const o = opts || {};
    const duration = o.duration === undefined ? 0.9 : o.duration;

    // A new tween always supersedes the previous one, otherwise a fast
    // slider drag leaves several animations fighting over one text node.
    if (el.__carvellaTween) {
        el.__carvellaTween.stop();
        el.__carvellaTween = null;
    }

    const decimals = o.decimals === undefined ? 0 : o.decimals;
    const write = (v) => {
        const body = decimals > 0 ? v.toFixed(decimals) : groupDigits(v);
        el.textContent = (o.prefix || '') + body + (o.suffix || '');
    };

    if (reduceMotion || duration <= 0 || from === to) {
        el.textContent = o.final !== undefined ? o.final : el.textContent;
        if (o.onDone) o.onDone();
        return;
    }

    el.__carvellaTween = animate(from, to, {
        duration,
        easing: EASE,
        onUpdate: write,
        onComplete: () => {
            el.__carvellaTween = null;
            if (o.final !== undefined) el.textContent = o.final;
            if (o.onDone) o.onDone();
        },
    });
}

function initCounters() {
    const targets = [];
    document.querySelectorAll('.text-numeric-stat').forEach((el) => {
        // A target another script owns (a live booking total) opts out and
        // drives its own tween, so the observer cannot overwrite it.
        if (el.dataset.count === 'manual') return;
        const original = el.textContent;
        const parsed = parseCounterValue(original);
        if (!parsed) return;
        targets.push({ el, original, parsed });
    });
    if (!targets.length) return;

    if (reduceMotion || !('IntersectionObserver' in window)) {
        return; // Markup text is already the final value.
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const target = targets.find((t) => t.el === entry.target);
            observer.unobserve(entry.target);
            if (!target || target.el.dataset.counted === '1') return;
            target.el.dataset.counted = '1';
            tweenNumber(target.el, 0, target.parsed.num, {
                duration: 1.1,
                prefix: target.parsed.prefix,
                suffix: target.parsed.suffix,
                decimals: target.parsed.decimals,
                final: target.original,
            });
        });
    }, { threshold: 0.35 });

    targets.forEach((t) => observer.observe(t.el));
}

/* Shared with the inline page scripts, which animate live values (the rate
   slider, the booking summary) that no observer owns. */
window.CarvellaNumber = {
    tween: tweenNumber,
    groupDigits,
    reduceMotion,
};

/* ---------------------------------------------------------------
 * 4. INTERACTION MOTION — lightweight hover/tap feedback for buttons
 *    and the link/tab shapes that act as buttons.
 *
 *    Cards are deliberately NOT animated any more: the surfaces that
 *    used to lift and scale on hover (.vehicle-card, .veh-card,
 *    .glass-card, .step-flow-card, .spot-card, metric cards, admin
 *    panels) are held still. A card's hover affordance is carried by
 *    CSS alone (the border firms up), so there is no per-surface
 *    listener here to move them.
 * --------------------------------------------------------------- */
function initInteractionMotion() {
    if (reduceMotion) return;

    const finePointer = window.matchMedia
        && window.matchMedia('(pointer: fine)').matches;
    if (!finePointer) return;

    // Every button, plus the link/tab shapes that act as buttons. Anchored on
    // the tag rather than on individual Tailwind classes so new markup keeps
    // the feel without another selector list to maintain.
    // Filter pills sit inside a horizontally scrollable strip that has no
    // vertical padding, so the hover nudge clipped their top edge and, via the
    // strip's coerced `overflow-y: auto`, could tip it into showing a scrollbar.
    // They already give feedback through their `hover:bg-*` colour change, so
    // they are left still — matching the customer-side `.veh-filter-pill`.
    const MOTION_EXCLUDED = '.btn-primary-sweep, .veh-filter-pill, .fleet-filter-tab, .bay-filter, .back-to-top-btn, .theme-toggle, [data-no-motion]';
    const controls = Array.from(document.querySelectorAll(
        'button, [role="button"], .navbar-glass a, #sideNavBar .nav-link, ' +
        '.fleet-filter-tab, .bay-filter, .btn-ghost-pill, .cookie-btn'
    )).filter((el) => !el.matches(MOTION_EXCLUDED));

    // A scale that reads as a "press" on a 32px icon button distorts on a
    // full-width submit, so wide controls only get the vertical nudge.
    const SCALE_LIMIT = 200;
    const hoverFor = (el) => (el.offsetWidth <= SCALE_LIMIT
        ? { y: [0, -2], scale: [1, 1.025] }
        : { y: [0, -1.5] });
    const releaseFor = (el) => (el.offsetWidth <= SCALE_LIMIT
        ? { y: 0, scale: 1 }
        : { y: 0 });

    controls.forEach((el) => {
        let hoverControl;
        let pressControl;
        el.addEventListener('pointerenter', () => {
            if (hoverControl) hoverControl.cancel();
            hoverControl = animate(el, hoverFor(el), {
                duration: 0.24,
                easing: EASE
            });
        });
        el.addEventListener('pointerleave', () => {
            if (hoverControl) hoverControl.cancel();
            hoverControl = animate(el, releaseFor(el), {
                duration: 0.2,
                easing: EASE
            });
        });
        el.addEventListener('pointerdown', () => {
            if (pressControl) pressControl.cancel();
            pressControl = el.offsetWidth <= SCALE_LIMIT
                ? animate(el, { scale: 0.97 }, { duration: 0.12, easing: EASE })
                : animate(el, { y: 0, opacity: 0.85 }, { duration: 0.12, easing: EASE });
        });
        el.addEventListener('pointerup', () => {
            if (pressControl) pressControl.cancel();
            pressControl = el.offsetWidth <= SCALE_LIMIT
                ? animate(el, { scale: 1.025, opacity: 1 }, { duration: 0.22, easing: EASE })
                : animate(el, { y: -1.5, opacity: 1 }, { duration: 0.22, easing: EASE });
        });
    });
}

/* ---------------------------------------------------------------
 * 4b. ROW MOTION — table rows are scroll-reveal targets: initEntrance
 *     binds opacity/x/y/scale to each row's scroll progress, so a
 *     transform-based hover would fight that binding every frame.
 *     Feedback here therefore uses a filter (no transform, no
 *     reflow, no conflict) layered over the Tailwind row hover.
 * --------------------------------------------------------------- */
function initRowMotion() {
    if (reduceMotion) return;
    if (!(window.matchMedia && window.matchMedia('(pointer: fine)').matches)) return;

    document.querySelectorAll('main tbody tr').forEach((row) => {
        if (row.matches('[data-no-motion]')) return;
        let control;
        const stop = () => { if (control) { control.cancel(); control = null; } };
        const settle = (to) => {
            stop();
            control = animate(row, to, { duration: 0.28, easing: EASE });
        };

        row.addEventListener('pointerenter', () => settle({ filter: 'brightness(1.025) saturate(1.03)' }));
        row.addEventListener('pointerleave', () => settle({ filter: 'brightness(1) saturate(1)' }));
        row.addEventListener('pointerdown', () => settle({ filter: 'brightness(0.97) saturate(0.98)' }));
    });
}

/* ---------------------------------------------------------------
 * THEME TOGGLE
 *
 * Deliberately does NOT need Motion to *work*: swapping the .dark
 * class is plain JS, and the knob/icon states are pure CSS (theme.css
 * §8). Motion only adds the icon flourish, so a blocked or failed CDN
 * degrades to a fully functional toggle instead of a dead control.
 * Mirrors the pre-paint script in includes/header.php.
 * --------------------------------------------------------------- */
const THEME_KEY = 'carvella_theme';

function readStoredTheme() {
    try {
        const value = localStorage.getItem(THEME_KEY);
        return value === 'light' || value === 'dark' ? value : null;
    } catch (err) {
        return null; // private mode / storage disabled
    }
}

function initThemeToggle() {
    // The inline fallback in includes/footer.php binds its own click handler
    // and stores the same carvella_theme key. It sets this flag first, so if it
    // ran we must NOT re-toggle here or the theme would flip twice and appear
    // to do nothing.
    //
    // It also owns the reveal iris now (it has to: this file is type="module",
    // so it is always deferred and loses the parse-time race for the flag).
    // So in this branch we do not return early - we still layer the icon
    // spring on top, which is the part that genuinely needs Motion.
    if (document.documentElement.dataset.themeToggleBound === '1') {
        const rootSync = document.documentElement;
        const btns = Array.from(document.querySelectorAll('[data-theme-toggle]'));

        const syncLabels = () => {
            const dark = rootSync.classList.contains('dark');
            btns.forEach((btn) => {
                btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
                const lbl = dark ? 'Switch to light theme' : 'Switch to dark theme';
                btn.setAttribute('aria-label', lbl);
                btn.setAttribute('title', lbl);
            });
        };
        syncLabels();

        if (typeof animate === 'function' && !reduceMotion && typeof MutationObserver === 'function') {
            // Observe the class rather than listening for click: the fallback
            // applies the swap inside startViewTransition's callback, which is
            // async, so a click listener would still read the pre-flip value
            // and would spin the wrong icon.
            new MutationObserver(syncLabels).observe(rootSync, {
                attributes: true,
                attributeFilter: ['class'],
            });

            new MutationObserver(() => {
                // Only the icon coming INTO view should spin; spinning both
                // directions at once reads as a glitch.
                const toDark = rootSync.classList.contains('dark');
                btns.forEach((btn) => {
                    const icon = btn.querySelector(toDark ? '.theme-toggle__icon--moon' : '.theme-toggle__icon--sun');
                    if (!icon) return;
                    try {
                        animate(icon, { rotate: [-120, 0], scale: [0.55, 1] }, { duration: 0.42, easing: SPRING });
                    } catch (err) {
                        /* no-op: the state swap has already landed */
                    }
                });
            }).observe(rootSync, { attributes: true, attributeFilter: ['class'] });
        }
        return;
    }

    const root = document.documentElement;
    const buttons = Array.from(document.querySelectorAll('[data-theme-toggle]'));
    if (!buttons.length) return;

    const isDark = () => root.classList.contains('dark');

    // Only the icon that is coming INTO view should spin; spinning both
    // directions at once reads as a glitch.
    const spinIncoming = (toDark) => {
        if (reduceMotion) return;
        buttons.forEach((btn) => {
            const icon = btn.querySelector(toDark ? '.theme-toggle__icon--moon' : '.theme-toggle__icon--sun');
            if (!icon) return;
            try {
                animate(icon, { rotate: [-120, 0], scale: [0.55, 1] }, { duration: 0.42, easing: SPRING });
            } catch (err) {
                /* no-op: the state swap above already landed */
            }
        });
    };

    const sync = () => {
        const dark = isDark();
        buttons.forEach((btn) => {
            btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            btn.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
            btn.setAttribute('title', dark ? 'Switch to light theme' : 'Switch to dark theme');
        });
    };

    const apply = (theme) => {
        const dark = theme === 'dark';
        root.classList.toggle('dark', dark);
        root.setAttribute('data-theme', theme);
        root.style.colorScheme = theme;
    };

    // Geometry for the iris: the control's centre, and a radius large
    // enough to reach the furthest viewport corner from it.
    const setRevealOrigin = (btn) => {
        const rect = btn.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;
        const farX = Math.max(x, window.innerWidth - x);
        const farY = Math.max(y, window.innerHeight - y);
        root.style.setProperty('--theme-reveal-x', x + 'px');
        root.style.setProperty('--theme-reveal-y', y + 'px');
        // small overshoot so the spring's tail lands on fully covered
        root.style.setProperty('--theme-reveal-r', Math.hypot(farX, farY) + 'px');
    };

    // A second click mid-reveal would restart a transition the browser is
    // still running; fall back to an instant swap instead.
    let revealing = false;

    const commit = (next, btn) => {
        const persist = () => { try { localStorage.setItem(THEME_KEY, next); } catch (err) { /* private mode */ } };

        const canReveal = typeof document.startViewTransition === 'function' && !reduceMotion && !revealing;
        if (!canReveal) {
            // No API, reduced motion, or a reveal already in flight.
            if (!reduceMotion) {
                document.body.classList.add('theme-transition');
                window.setTimeout(() => document.body.classList.remove('theme-transition'), 340);
            }
            apply(next);
            persist();
            sync();
            spinIncoming(next === 'dark');
            return;
        }

        setRevealOrigin(btn);
        revealing = true;

        // Lift this control out of the root snapshot so the iris does not
        // freeze the knob slide and icon morph (see theme.css). The name has
        // to be on the button before startViewTransition is called, because
        // the old snapshot is taken at that moment.
        //
        // Only the clicked one is named: view-transition-names must be
        // unique per document, and public pages carry two controls (the
        // navbar toggle and the footer FAB). Naming both would throw and
        // drop the transition entirely.
        btn.style.viewTransitionName = 'carvella-theme-toggle';

        const transition = document.startViewTransition(() => apply(next));
        transition.finished
            .catch(() => { /* skipped or interrupted; the swap still applied */ })
            .then(() => {
                revealing = false;
                btn.style.viewTransitionName = '';
                root.style.removeProperty('--theme-reveal-x');
                root.style.removeProperty('--theme-reveal-y');
                root.style.removeProperty('--theme-reveal-r');
            });
        persist();
        sync();

        // No icon spring queued behind the wipe any more. The control now
        // cross-fades inside its own transition group, so the flip happens
        // under the iris rather than after it; the old choreography
        // (updateCallbackDone -> 420ms spring) put a third beat at the end
        // and left the knob snapping into place first.
    };

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => commit(isDark() ? 'light' : 'dark', btn));
    });

    // Follow the OS only while the user hasn't made an explicit choice.
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const onSystemChange = (event) => {
            if (readStoredTheme()) return; // explicit choice wins
            apply(event.matches ? 'dark' : 'light');
            sync();
        };
        if (mq.addEventListener) mq.addEventListener('change', onSystemChange);
        else if (mq.addListener) mq.addListener(onSystemChange);
    }

    sync();
}

/* ---------------------------------------------------------------
 * VEHICLE CARD TILT — fully removed.
 *
 * The pointer-tracked 3D rotation was part of the card "movement" the
 * product no longer wants, so the driver, the theme.css rules and the
 * markup hooks are all gone. Cards sit perfectly still.
 * --------------------------------------------------------------- */

/* ---------------------------------------------------------------
 * 5. UX MOTION — animated feedback for shared controls. This keeps
 * the PHP pages feeling like one cohesive app without introducing a
 * frontend framework or changing the existing DOM layout.
 * --------------------------------------------------------------- */
function initUXMotion() {
    if (reduceMotion) return;

    // Motion only nudges the scale. The focus ring itself lives in CSS
    // (`input:focus { box-shadow: … !important }`) — animating boxShadow
    // here wrote an inline style that the !important CSS ring overrode
    // anyway, so the tween was doing nothing except cost a style write
    // on every focus and blur.
    const animateFocus = (el, focused) => {
        animate(el, { scale: focused ? 1.006 : 1 }, { duration: focused ? 0.2 : 0.24, easing: EASE });
    };

    document.querySelectorAll(
        'input:not([type="checkbox"]):not([type="radio"]), select, textarea'
    ).forEach((field) => {
        field.addEventListener('focus', () => animateFocus(field, true));
        field.addEventListener('blur', () => animateFocus(field, false));
    });

    const playAlert = (alert) => {
        if (!alert || alert.dataset.motionReady === '1') return;
        alert.dataset.motionReady = '1';
        animate(alert, {
            opacity: [0, 1],
            y: [-12, 0],
            scale: [0.98, 1]
        }, { duration: 0.42, easing: SPRING });
    };

    document.querySelectorAll('.alert, .alert-enter, [role="alert"]').forEach(playAlert);
    const alertObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                if (node.matches('.alert, .alert-enter, [role="alert"]')) playAlert(node);
                node.querySelectorAll?.('.alert, .alert-enter, [role="alert"]').forEach(playAlert);
            });
        });
    });
    alertObserver.observe(document.body, { childList: true, subtree: true });

    /* Dropdown open/close motion.

       The open half is a staggered reveal of the panel and its rows. The
       close half matters as much and was missing: every one of these panels
       is toggled with a `hidden` class, and `display:none` is applied by the
       page's own click handler, so the panel used to disappear on the same
       frame it was dismissed. Closing now plays a short exit and the `hidden`
       class is withheld until it finishes (see closeMenu below). */

    const openMenu = (menu) => {
        if (!menu || reduceMotion) return;
        const items = Array.from(menu.querySelectorAll('a, button'));
        // scale-from-top-right so the panel appears to come off the control
        // that opened it, rather than growing from its own middle.
        menu.style.transformOrigin = 'top right';
        animate(menu, {
            opacity: [0, 1],
            y: [-10, 0],
            scale: [0.96, 1]
        }, { duration: 0.26, easing: SPRING });
        if (items.length) {
            const step = 0.024;
            animate(items, {
                opacity: [0, 1],
                y: [-6, 0]
            }, { duration: 0.26, delay: (i) => 0.03 + i * step, easing: EASE });
        }
    };

    const closeMenu = (menu) => {
        if (!menu || reduceMotion) return;
        animate(menu, {
            opacity: 0,
            y: -6,
            scale: 0.98
        }, { duration: 0.15, easing: EASE });
    };

    // Panel visibility is owned by the page's own inline click handlers, which
    // add or remove `hidden` on the panel directly. To animate the exit, this
    // capture-phase listener gets in front of them: on a close-click it stops
    // the event, plays the exit, and applies `hidden` itself once the exit has
    // finished.
    //
    // It only intercepts a click on a toggle whose panel is OPEN (a close
    // click). An open click passes straight through to the page handler and
    // the MutationObserver below picks the panel up once it is visible.
    //
    // Escape and outside-click closes are deliberately not intercepted. They
    // are document-level and firing an exit from inside a pointer event would
    // read as lag, not polish. Those still close instantly.
    // Only these panels are `hidden`-toggled dropdowns. Matching on
    // aria-controls alone would also catch #sidebar-toggle, which is
    // aria-controlled too and has its own collapse animation in
    // initSidebarToggle() - intercepting it would leave the rail stuck open.
    const DROPDOWN_PANELS = new Set(['adminUserMenu', 'notifMenu', 'userMenu']);
    const pendingClose = new WeakSet();

    document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element
            ? event.target.closest('[aria-controls][aria-expanded]')
            : null;
        if (!trigger) return;
        const panelId = trigger.getAttribute('aria-controls');
        if (!panelId || !DROPDOWN_PANELS.has(panelId)) return;
        const panel = document.getElementById(panelId);
        // `hidden` is the CLOSED state, so bail unless the panel is currently
        // showing. Inverted, this returns on close-clicks and intercepts
        // open-clicks instead: stopPropagation() in the capture phase then
        // prevents the page's own handler from ever opening the panel, so the
        // menu silently refused to open and aria-expanded was forced false.
        if (!panel || panel.classList.contains('hidden')) return;
        if (!document.body.contains(panel)) return;

        if (pendingClose.has(panel)) return;
        event.preventDefault();
        event.stopPropagation();
        pendingClose.add(panel);
        trigger.setAttribute('aria-expanded', 'false');
        closeMenu(panel);
        setTimeout(() => {
            pendingClose.delete(panel);
            panel.classList.add('hidden');
            // Motion writes inline transform/opacity; clearing them stops a
            // stale closed frame from being captured if the panel is reopened
            // before the next rAF.
            panel.style.opacity = '';
            panel.style.transform = '';
            panel.style.transformOrigin = '';
        }, reduceMotion ? 0 : 150);
    }, true);

    // Opening, and the class changes the inline handlers make, are observed
    // rather than intercepted - the panel is already visible at that point, so
    // there is nothing to race.
    const menuObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type !== 'attributes' || mutation.attributeName !== 'class') return;
            const menu = mutation.target;
            if (!(menu instanceof Element)) return;
            if (pendingClose.has(menu)) return;
            // is-open is a class the page toggles; `hidden` is a Tailwind
            // utility whose presence is the closed state. Either way the test
            // is the same question - is this panel showing right now?
            const showing = menu.id === 'mobileMenuPanel'
                ? menu.classList.contains('is-open')
                : !menu.classList.contains('hidden');
            if (showing) openMenu(menu);
        });
    });
    document.querySelectorAll('#adminUserMenu, #notifMenu, #userMenu, #mobileMenuPanel')
        .forEach((menu) => menuObserver.observe(menu, { attributes: true, attributeFilter: ['class'] }));

    // Scroll-linked hairline only. This used to be a shadow ramp, which is
    // the exact "depth" cue the flat system drops; the border going from
    // faint to firm is enough to show the bar has detached from the page.
    const navbar = document.getElementById('siteNavbar');
    if (navbar && pageMain) {
        const animation = animate(navbar, {
            borderBottomColor: [
                'rgba(107, 56, 212, 0.10)',
                'rgba(107, 56, 212, 0.22)'
            ]
        }, { ease: EASE });
        scroll(animation, {
            target: pageMain,
            offset: ['start start', 'start 35%']
        });
    }
}

/* ---------------------------------------------------------------
 * 4. BRAND MARQUEE — seamless infinite loop.
 *
 *    Driven by a tiny rAF engine instead of Motion's animate():
 *    Motion's standalone animate() fast-forwards to the final frame
 *    when prefers-reduced-motion is reduce, which would freeze the
 *    marquee. A 4-line algebra loop is deterministic everywhere,
 *    still pauses on hover / hidden tab, and stays seamless because
 *    the content repeats every `distance` px.
 * --------------------------------------------------------------- */
function initMarquee() {
    const marquee = document.querySelector('.marquee');
    const track = document.querySelector('.marquee-track');
    const set = track ? track.querySelector('.marquee-track-set') : null;
    if (!marquee || !track || !set) return;

    const SPEED = reduceMotion ? 42 : 62; // px per second
    let x = 0;
    let distance = 0;
    let raf = 0;
    let last = 0;
    let paused = false;
    let hidden = document.hidden;
    let debounce = 0;

    // Clone whole sets until there is always a full loop period visible
    // to the right of the viewport, so wrapping is never visible.
    const measure = () => {
        const viewport = marquee.getBoundingClientRect().width;
        const base = set.getBoundingClientRect().width;
        if (!base) return false;
        while (track.getBoundingClientRect().width < viewport + base) {
            track.appendChild(set.cloneNode(true));
        }
        distance = base;
        return true;
    };

    const loop = (now) => {
        if (!paused && !hidden) {
            if (!last) last = now;
            const dt = Math.min(0.05, (now - last) / 1000);
            last = now;
            x += SPEED * dt;
            if (x >= distance) x -= distance;
            track.style.transform = 'translate3d(' + (-x).toFixed(2) + 'px, 0, 0)';
        } else {
            last = 0;
        }
        raf = requestAnimationFrame(loop);
    };

    const start = () => {
        cancelAnimationFrame(raf);
        last = 0;
        if (measure()) {
            x = x % distance;
            raf = requestAnimationFrame(loop);
        }
    };

    const pause = () => { paused = true; };
    const resume = () => { paused = false; };

    start();
    window.addEventListener('load', start);
    window.addEventListener('resize', () => {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(start, 150);
    }, { passive: true });
    marquee.addEventListener('mouseenter', pause);
    marquee.addEventListener('mouseleave', resume);
    document.addEventListener('visibilitychange', () => {
        hidden = document.hidden;
    });
}

/* ---------------------------------------------------------------
 * 5. ADMIN SIDEBAR — capsule rail collapse / expand (Motion)
 * --------------------------------------------------------------- */
function initSidebar() {
    const sidebar = document.getElementById('sideNavBar');
    if (!sidebar) return;
    if (reduceMotion) return;

    const navLinks = Array.from(sidebar.querySelectorAll('.nav-link'));
    const navLabels = Array.from(sidebar.querySelectorAll('.nav-text'));
    const navIcons = Array.from(sidebar.querySelectorAll('.nav-link .bi, .nav-link .icon-wrapper'));
    if (!navLinks.length) return;

    const BADGE = 44; // collapsed icon-only circular badge

    // Measure the expanded pill sizes once (before first paint) so we can
    // morph width/height in both directions.
    const wasCollapsed = sidebar.classList.contains('collapsed');
    // Force expanded state temporarily for measurement
    const hadCollapsedClass = wasCollapsed;
    // Get expanded sizes without flickering
    const expandedSizes = navLinks.map((el) => {
        // Store current state
        const currentWidth = el.style.width;
        const currentHeight = el.style.height;
        const currentGap = el.style.gap;
        const currentPaddingL = el.style.paddingLeft;
        const currentPaddingR = el.style.paddingRight;
        // Clear inline styles to get natural expanded size
        el.style.width = '';
        el.style.height = '';
        el.style.gap = '';
        el.style.paddingLeft = '';
        el.style.paddingRight = '';
        const r = el.getBoundingClientRect();
        const size = { w: r.width, h: r.height };
        // Restore
        el.style.width = currentWidth;
        el.style.height = currentHeight;
        el.style.gap = currentGap;
        el.style.paddingLeft = currentPaddingL;
        el.style.paddingRight = currentPaddingR;
        return size;
    });

    let active = [];
    const stopAll = () => { active.forEach((c) => { if (c && c.cancel) c.cancel(); }); active = []; };
    const settleLinks = () => navLinks.forEach((el) => {
        el.style.removeProperty('width');
        el.style.removeProperty('height');
        el.style.removeProperty('gap');
        el.style.removeProperty('padding-left');
        el.style.removeProperty('padding-right');
        el.style.removeProperty('justify-content');
    });

    const animateSidebar = (collapsed) => {
        stopAll();

        const rectNow = sidebar.getBoundingClientRect();
        const styleNow = getComputedStyle(sidebar);
        const widthNow = `${rectNow.width}px`;
        const padNow = styleNow.paddingLeft;
        const targetWidth = collapsed ? '64px' : '240px';
        const targetPad = collapsed ? '8px' : '10px';

        const widths = collapsed
            ? navLinks.map((el) => [el.getBoundingClientRect().width, BADGE])
            : expandedSizes.map((s) => [BADGE, s.w]);
        const heights = collapsed
            ? navLinks.map((el) => [el.getBoundingClientRect().height, BADGE])
            : expandedSizes.map((s) => [BADGE, s.h]);

        active = [
            animate(sidebar, {
                width: [widthNow, targetWidth],
                paddingLeft: [padNow, targetPad],
                paddingRight: [padNow, targetPad]
            }, { duration: 0.38, easing: [0.25, 0.1, 0.25, 1] }),

            animate(navLabels, {
                opacity: collapsed ? [1, 0] : [0, 1],
                maxWidth: collapsed ? ['180px', '0px'] : ['0px', '180px']
            }, { duration: collapsed ? 0.12 : 0.16, delay: collapsed ? 0 : (i) => i * 0.008, easing: [0.25, 0.1, 0.25, 1] }),

            animate(navLinks, {
                width: widths,
                height: heights,
                gap: collapsed ? ['10px', '0px'] : ['0px', '10px'],
                paddingLeft: collapsed ? ['16px', '0px'] : ['0px', '16px'],
                paddingRight: collapsed ? ['16px', '0px'] : ['0px', '16px']
            }, { duration: 0.38, easing: [0.25, 0.1, 0.25, 1], onComplete: settleLinks }),

            animate(navIcons, {
                scale: collapsed ? [1, 1.08] : [1.08, 1]
            }, { duration: 0.38, easing: [0.34, 1.4, 0.64, 1] })
        ];
    };

    let isInitialLoad = true;
    sidebar.addEventListener('carvella:sidebar-toggle', (event) => {
        if (isInitialLoad) {
            isInitialLoad = false;
            // Set state without animation on initial load
            const collapsed = !!event.detail.collapsed;
            if (collapsed) {
                sidebar.style.width = '64px';
                sidebar.style.paddingLeft = '8px';
                sidebar.style.paddingRight = '8px';
                navLinks.forEach((el) => {
                    el.style.width = `${BADGE}px`;
                    el.style.height = `${BADGE}px`;
                    el.style.gap = '0px';
                    el.style.paddingLeft = '0px';
                    el.style.paddingRight = '0px';
                });
                navLabels.forEach((el) => {
                    el.style.opacity = '0';
                    el.style.maxWidth = '0px';
                    el.style.transform = 'translateX(0px)';
                    el.style.pointerEvents = 'none';
                });
            } else {
                sidebar.style.width = '';
                sidebar.style.paddingLeft = '';
                sidebar.style.paddingRight = '';
                navLinks.forEach((el) => { el.style.width = ''; el.style.height = ''; el.style.gap = ''; el.style.paddingLeft = ''; el.style.paddingRight = ''; });
                navLabels.forEach((el) => { el.style.opacity = ''; el.style.maxWidth = ''; el.style.transform = ''; el.style.pointerEvents = ''; });
                navIcons.forEach((el) => { el.style.scale = ''; el.style.rotateZ = ''; });
            }
            return;
        }
        animateSidebar(!!event.detail.collapsed);
    });

    // Set initial visual state based on class (no animation on load)
    isInitialLoad = false;
    if (hadCollapsedClass) {
        sidebar.style.width = '64px';
        sidebar.style.paddingLeft = '8px';
        sidebar.style.paddingRight = '8px';
        navLinks.forEach((el) => {
            el.style.width = `${BADGE}px`;
            el.style.height = `${BADGE}px`;
            el.style.gap = '0px';
            el.style.paddingLeft = '0px';
            el.style.paddingRight = '0px';
            el.style.justifyContent = 'center';
        });
        navLabels.forEach((el) => {
            el.style.opacity = '0';
            el.style.maxWidth = '0px';
            el.style.transform = 'translateX(0px)';
            el.style.pointerEvents = 'none';
        });
        navIcons.forEach((el) => {
            el.style.scale = '1.08';
            el.style.rotateZ = '0deg';
        });
    } else {
        // Expanded state - ensure clean inline styles
        sidebar.style.width = '';
        sidebar.style.paddingLeft = '';
        sidebar.style.paddingRight = '';
        navLinks.forEach((el) => {
            el.style.width = '';
            el.style.height = '';
            el.style.gap = '';
            el.style.paddingLeft = '';
            el.style.paddingRight = '';
            el.style.justifyContent = '';
        });
        navLabels.forEach((el) => {
            el.style.opacity = '';
            el.style.maxWidth = '';
            el.style.transform = '';
            el.style.pointerEvents = '';
        });
        navIcons.forEach((el) => {
            el.style.scale = '';
        });
    }
}

/* ---------------------------------------------------------------
 * 6. PAGE LEAVE — glide out then navigate (replaces CSS leaving state)
 * --------------------------------------------------------------- */
function initPageTransitions() {
    const state = { active: false };
    const nodes = [];
    if (pageMain) nodes.push(pageMain);
    if (pageFooter && !isAdmin) nodes.push(pageFooter);

    document.querySelectorAll('a[href]').forEach((link) => {
        // Protected links (inline confirm()/onclick or download) navigate
        // natively so their cancellation logic is never bypassed.
        if (link.hasAttribute('onclick') || link.hasAttribute('download')) return;
        link.addEventListener('click', function (event) {
            if (state.active) { event.preventDefault(); return; }
            let url;
            try { url = new URL(this.href, window.location.href); } catch (err) { return; }
            if (
                url.origin !== window.location.origin ||
                url.hash ||
                (url.protocol !== 'http:' && url.protocol !== 'https:') ||
                this.target === '_blank' ||
                event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            ) return;

            event.preventDefault();
            state.active = true;
            if (!reduceMotion) {
                nodes.forEach((el) => animate(el, {
                    opacity: [1, 0],
                    y: [0, -12],
                    scale: [1, 0.995]
                }, { duration: 0.3, easing: GLIDE }));
            }
            window.setTimeout(() => { window.location.assign(url.href); }, reduceMotion ? 40 : 330);
        });
    });
}

  initAdminChrome();
  initEntrance();
  initCounters();
  initInteractionMotion();
  initRowMotion();
  initThemeToggle();
  initUXMotion();
initMarquee();
initSidebar();
initPageTransitions();
