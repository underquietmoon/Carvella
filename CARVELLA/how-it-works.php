<?php
$pageTitle = 'How It Works — CARVELLA Luxury Fleet Dagupan City';
require_once __DIR__ . '/includes/header.php';
?>
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main id="main-content" tabindex="-1" class="flex-grow pt-20 md:pt-24">

<!-- HERO -->
<section class="how-hero-glow relative overflow-hidden pt-4 md:pt-6 pb-10 px-6 md:px-12 text-center" id="home">
  <div class="max-w-3xl mx-auto">
    <span class="inline-flex items-center px-3.5 py-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest text-label-sm font-label-sm font-semibold uppercase tracking-[0.08em] text-primary hero-entrance-child" style="--hero-delay: 60ms">
      How It Works
    </span>

    <h1 class="mt-4 text-[30px] leading-[38px] tracking-[-0.02em] font-bold md:text-[48px] md:leading-[56px] md:tracking-[-0.03em] md:font-[800] text-on-surface hero-entrance-child" style="--hero-delay: 100ms">
      Seamless Vehicle Mobility in <span class="text-primary">3 Effortless Steps</span>
    </h1>

    <p class="mt-4 text-body-lg font-body-lg text-on-surface-variant max-w-2xl mx-auto leading-relaxed hero-entrance-child" style="--hero-delay: 200ms">
      Designed for modern travelers in Pangasinan. From digital reservation to in-person counter key handover at our central dispatch desk in downtown Dagupan City.
    </p>
  </div>
</section>

<!-- 3-STEP JOURNEY -->
<section class="py-16 px-6 md:px-12 max-w-[92rem] mx-auto" id="how-it-works">
  <div class="flex flex-col md:flex-row md:items-end justify-between mb-12 gap-4">
    <div>
      <span class="text-label-sm leading-[14px] tracking-[0.12em] font-bold text-primary uppercase block mb-1">Frictionless Experience</span>
      <h2 class="text-[30px] leading-[38px] tracking-[-0.02em] font-bold md:text-[36px] md:leading-[44px] md:font-[800] text-on-surface">The Dagupan Mobility Journey</h2>
    </div>
    <p class="text-body-md font-body-md text-on-surface-variant max-w-md md:text-right leading-relaxed">
      A touchless, algorithm-driven car booking experience engineered to eradicate waiting queues, paperwork, and counter delays.
    </p>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">

    <!-- STEP 01 -->
    <div class="card-spotlight step-flow-card bg-surface-container-lowest rounded-2xl border border-outline-variant p-6 flex flex-col justify-between transition-colors">
      <div>
        <div class="text-[36px] leading-[40px] font-[800] text-primary/30 mb-4">01</div>
        <span class="inline-block px-2.5 py-1 rounded-md bg-primary-fixed text-on-primary-fixed text-[10px] leading-[14px] tracking-[0.04em] font-bold uppercase mb-4">Real-Time Engine</span>
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-semibold mb-2 leading-snug">Select &amp; Lock Your Vehicle</h3>
        <p class="text-body-sm font-body-sm text-on-surface-variant leading-relaxed mb-6">
          Browse our curated Dagupan fleet. Our automated overlap engine locks your chosen dates in real-time, preventing double-booking across all Pangasinan destinations.
        </p>
      </div>
      <div class="bg-surface-container-low border border-outline-variant rounded-2xl p-3 flex flex-col items-center justify-center">
        <div class="w-full bg-primary-fixed rounded-md p-2 flex flex-col items-center">
          <?php
          /* The locked vehicle in this mockup is a real fleet record (id 16), so
           * the image goes through the shared card-derivative ladder: it keeps
           * the 900px source out of the document and falls back to the
           * placeholder if the upload is ever removed. */
          $step1Image = 'assets/images/vehicles/Toyota Granvia.png?v=2';
          $step1Src = vehicleCardImage($step1Image);
          $step1Srcset = vehicleImageSrcset($step1Image, BASE_URL);
          // The mockup sits inside a 4-up grid at ~238px of usable width.
          $step1Sizes = '(min-width: 1024px) 238px, (min-width: 640px) 45vw, 88vw';
          ?>
          <?php if ($step1Src !== ''): ?>
          <img class="w-full h-24 object-cover object-center rounded-lg" src="<?= BASE_URL ?>/<?= htmlspecialchars($step1Src) ?>"<?php if ($step1Srcset !== ''): ?> srcset="<?= htmlspecialchars($step1Srcset) ?>" sizes="<?= htmlspecialchars($step1Sizes) ?>"<?php endif; ?> alt="Toyota Granvia 2.8 Premium" width="900" height="672" loading="lazy" decoding="async">
          <?php else: ?>
          <i class="bi bi-car-front-fill text-[56px] text-primary/60 leading-none" aria-hidden="true"></i>
          <?php endif; ?>
          <div class="mt-2 flex items-center justify-between gap-2 w-full px-2">
            <span class="text-[11px] leading-[14px] font-bold text-on-surface min-w-0 truncate">Toyota Granvia 2.8 Premium</span>
            <span class="text-[9px] leading-[12px] font-bold text-on-primary bg-primary px-2 py-0.5 rounded-full flex items-center gap-1 shrink-0">
              <i class="bi bi-lock" style="font-size:10px" aria-hidden="true"></i>LOCKED
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- STEP 02 -->
    <div class="card-spotlight step-flow-card bg-surface-container-lowest rounded-2xl border border-outline-variant p-6 flex flex-col justify-between transition-colors">
      <div>
        <div class="text-[36px] leading-[40px] font-[800] text-primary/30 mb-4">02</div>
        <span class="inline-block px-2.5 py-1 rounded-md bg-primary-fixed text-on-primary-fixed text-[10px] leading-[14px] tracking-[0.04em] font-bold uppercase mb-4">Instant Verify</span>
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-semibold mb-2 leading-snug">Quick 60-Second Digital KYC</h3>
        <p class="text-body-sm font-body-sm text-on-surface-variant leading-relaxed mb-6">
          Upload your Philippine Driver's License and verify identity online. Fast-track approvals with instant LTO compliance sync and automated facial biometric matching.
        </p>
      </div>
      <div class="bg-primary-fixed border border-outline-variant rounded-2xl p-3">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-1.5">
            <i class="bi bi-person-badge text-base text-primary" aria-hidden="true"></i>
            <span class="text-[10px] leading-[12px] font-bold text-on-surface leading-none">Philippine<br>DL No.</span>
          </div>
          <span class="text-[9px] leading-[12px] font-bold text-emerald-600 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-full">&check; Verified</span>
        </div>
        <div class="bg-surface-container-lowest border border-outline-variant rounded-md px-2.5 py-1.5 flex items-center justify-between">
          <span class="text-[11px] leading-[14px] font-bold text-on-surface tracking-wider">N02-14-008924</span>
          <i class="bi bi-lock text-xs text-outline" aria-hidden="true"></i>
        </div>
      </div>
    </div>

    <!-- STEP 03 -->
    <div class="card-spotlight step-flow-card bg-surface-container-lowest rounded-2xl border border-outline-variant p-6 flex flex-col justify-between transition-colors">
      <div>
        <div class="text-[36px] leading-[40px] font-[800] text-primary/30 mb-4">03</div>
        <span class="inline-block px-2.5 py-1 rounded-md bg-primary-fixed text-on-primary-fixed text-[10px] leading-[14px] tracking-[0.04em] font-bold uppercase mb-4">24/7 Downtown Hub</span>
        <h3 class="font-headline-sm text-headline-sm text-on-surface font-semibold mb-2 leading-snug">Physical Counter Key Handover</h3>
        <p class="text-body-sm font-body-sm text-on-surface-variant leading-relaxed mb-6">
          Arrive at our AB Fernandez Ave Central Hub. Meet our friendly dispatch staff at Counter 02 for a quick physical key turnover and vehicle walk-around inspection.
        </p>
      </div>
      <div class="bg-primary-fixed border border-outline-variant rounded-2xl p-4 text-center">
        <div class="w-8 h-8 rounded-full bg-primary-fixed-dim text-on-primary-fixed flex items-center justify-center mx-auto mb-2">
          <i class="bi bi-person text-base" aria-hidden="true"></i>
        </div>
        <p class="text-[11px] leading-[14px] font-bold text-on-surface">Counter 02 &bull; Reception</p>
        <p class="text-[10px] leading-[14px] text-on-surface-variant mt-0.5">Staff-Assisted Walk-Around &amp; Handover</p>
      </div>
    </div>

  </div>
</section>

<!-- FAQ -->
<section class="how-faq py-12 md:py-16 px-6 md:px-12 max-w-[92rem] mx-auto" id="faq">
  <div class="text-center max-w-2xl mx-auto mb-9">
    <span class="inline-flex px-3.5 py-1.5 rounded-full border border-outline-variant/60 bg-surface-container-lowest text-label-sm font-label-sm font-semibold uppercase tracking-[0.08em] text-primary">Clarity &amp; Confidence</span>
    <h2 class="text-[32px] leading-[40px] md:text-[40px] md:leading-[48px] tracking-[-0.025em] font-bold text-on-surface mt-4">Frequently Asked Questions</h2>
    <p class="text-body-md font-body-md text-on-surface-variant mt-3 max-w-xl mx-auto">
      Everything you need to know about renting a vehicle with CARVELLA in Dagupan City.
    </p>
  </div>
  <div class="max-w-3xl mx-auto space-y-3" id="faq-accordion">

    <div class="faq-item-reveal faq-item bg-surface-container-lowest border border-outline-variant rounded-full transition-colors duration-300">
      <button type="button" class="faq-toggle w-full flex items-center gap-3.5 text-left px-7 py-4 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" aria-expanded="false" data-target="faq-1">
        <span class="faq-num shrink-0 grid place-items-center h-7 min-w-[1.9rem] px-1.5 rounded-md bg-primary/10 text-primary text-[11px] font-bold tabular-nums">01</span>
        <span class="faq-q flex-1 text-body-lg font-body-lg font-semibold text-on-surface">What do I need to bring during pickup?</span>
        <span class="faq-chevron shrink-0 grid place-items-center w-8 h-8 rounded-full border border-outline-variant bg-surface-container transition-colors duration-300">
          <svg class="faq-icon w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
        </span>
      </button>
      <div class="faq-content" id="faq-1">
        <p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">
          You will only need your physical, unexpired Philippine Driver's License (or an International Driving Permit paired with your home country license if you are a foreign traveler) and your booking voucher QR code sent via email/SMS. No physical photocopies or manual paperwork are requested at the lockers.
        </p>
      </div>
    </div>

    <div class="faq-item-reveal faq-item bg-surface-container-lowest border border-outline-variant rounded-full transition-colors duration-300">
      <button type="button" class="faq-toggle w-full flex items-center gap-3.5 text-left px-7 py-4 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" aria-expanded="false" data-target="faq-2">
        <span class="faq-num shrink-0 grid place-items-center h-7 min-w-[1.9rem] px-1.5 rounded-md bg-primary/10 text-primary text-[11px] font-bold tabular-nums">02</span>
        <span class="faq-q flex-1 text-body-lg font-body-lg font-semibold text-on-surface">Can I pick up or return vehicles past midnight?</span>
        <span class="faq-chevron shrink-0 grid place-items-center w-8 h-8 rounded-full border border-outline-variant bg-surface-container transition-colors duration-300">
          <svg class="faq-icon w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
        </span>
      </button>
      <div class="faq-content" id="faq-2">
        <p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">
          Yes! Our automated smart lockers and designated terminal parking bays along AB Fernandez Avenue operate 24 hours a day, 7 days a week, 365 days a year. Your dynamic PIN activates 15 minutes before your scheduled reservation time regardless of the hour.
        </p>
      </div>
    </div>

    <div class="faq-item-reveal faq-item bg-surface-container-lowest border border-outline-variant rounded-full transition-colors duration-300">
      <button type="button" class="faq-toggle w-full flex items-center gap-3.5 text-left px-7 py-4 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" aria-expanded="false" data-target="faq-3">
        <span class="faq-num shrink-0 grid place-items-center h-7 min-w-[1.9rem] px-1.5 rounded-md bg-primary/10 text-primary text-[11px] font-bold tabular-nums">03</span>
        <span class="faq-q flex-1 text-body-lg font-body-lg font-semibold text-on-surface">What is the fuel policy?</span>
        <span class="faq-chevron shrink-0 grid place-items-center w-8 h-8 rounded-full border border-outline-variant bg-surface-container transition-colors duration-300">
          <svg class="faq-icon w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
        </span>
      </button>
      <div class="faq-content" id="faq-3">
        <p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">
          We enforce a strict Full-to-Full fuel policy. Every CARVELLA fleet car departs 100% full directly from accredited Shell or Petron stations on Perez Boulevard and must be returned with a full tank. Telematics verify tank readings instantly upon parking intake.
        </p>
      </div>
    </div>

    <div class="faq-item-reveal faq-item bg-surface-container-lowest border border-outline-variant rounded-full transition-colors duration-300">
      <button type="button" class="faq-toggle w-full flex items-center gap-3.5 text-left px-7 py-4 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40" aria-expanded="false" data-target="faq-4">
        <span class="faq-num shrink-0 grid place-items-center h-7 min-w-[1.9rem] px-1.5 rounded-md bg-primary/10 text-primary text-[11px] font-bold tabular-nums">04</span>
        <span class="faq-q flex-1 text-body-lg font-body-lg font-semibold text-on-surface">Can I drive to Baguio, La Union, or Manila?</span>
        <span class="faq-chevron shrink-0 grid place-items-center w-8 h-8 rounded-full border border-outline-variant bg-surface-container transition-colors duration-300">
          <svg class="faq-icon w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
        </span>
      </button>
      <div class="faq-content" id="faq-4">
        <p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">
          Absolutely. All standard rentals include unlimited mileage within the northern and central Luzon corridor—including Baguio City (via Kennon/Marcos Hwy), San Juan La Union, Alaminos Hundred Islands, and Metro Manila via TPLEX/NLEX. RFID Easytrip and Autosweep stickers are installed in all vehicles.
        </p>
      </div>
    </div>

  </div>
</section>

</main>

<style>
.how-hero-glow {
    background: radial-gradient(circle at 50% 18%, var(--primary-fixed) 0%, transparent 70%);
}

/* FAQ accordion — scoped to this page and declared as the collapsed state.
   The shared .faq-content rules in assets/css/style.css sit outside any
   cascade layer, so nothing there can be relied on to keep an answer shut
   once Tailwind's utilities layer (or the motion script's display writes)
   are in play. Every property here is explicit and the collapsed state is
   the one that wins, so an answer is hidden until its question is clicked. */
.how-faq .faq-content {
    display: grid !important;
    grid-template-rows: 0fr !important;
    opacity: 0 !important;
    visibility: hidden;
    transition:
        grid-template-rows 0.4s cubic-bezier(0.22, 1, 0.36, 1),
        opacity 0.25s ease,
        visibility 0s linear 0.4s;
}
.how-faq .faq-content > * {
    min-height: 0 !important;
    overflow: hidden !important;
    border: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
}
.how-faq .faq-item.faq-open > .faq-content {
    grid-template-rows: 1fr !important;
    opacity: 1 !important;
    visibility: visible;
    transition:
        grid-template-rows 0.4s cubic-bezier(0.22, 1, 0.36, 1),
        opacity 0.25s ease,
        visibility 0s linear 0s;
}
/* Open: the pill relaxes into a card and takes a tint, so the active
   question is obvious without reading the answer. The radius morph is what
   stops a tall block of text sitting inside a stadium-shaped pill. The
   divider is inset to the text column instead of running edge to edge. */
.how-faq .faq-item {
    border-radius: 9999px;
    background-color: transparent;
    border-color: var(--outline-variant);
    transition:
        border-radius 0.4s cubic-bezier(0.22, 1, 0.36, 1),
        background-color 0.25s ease,
        border-color 0.25s ease,
        box-shadow 0.25s ease;
}
.how-faq .faq-item:hover {
    border-color: var(--primary-fixed-dim);
    background-color: var(--surface-container-lowest);
    background-color: color-mix(in srgb, var(--primary-fixed) 35%, var(--surface-container-lowest));
}
.how-faq .faq-item.faq-open,
.how-faq .faq-item.faq-open:hover {
    border-radius: 1.75rem;
    background-color: var(--surface-container-lowest);
    background-color: color-mix(in srgb, var(--primary-fixed) 55%, var(--surface-container-lowest));
    border-color: var(--primary-fixed-dim) !important;
    box-shadow: 0 2px 8px -4px rgba(109, 40, 217, 0.12);
}
.how-faq .faq-item.faq-open > .faq-content > * {
    padding: 1.125rem 1.75rem 1.75rem !important;
    max-width: min(100%, calc(72ch + 3.5rem));
    background-image: linear-gradient(var(--outline-variant), var(--outline-variant));
    background-repeat: no-repeat;
    background-size: min(calc(100% - 3.5rem), 72ch) 1px;
    background-position: 1.75rem 0;
}
.how-faq .faq-item.faq-open .faq-toggle:hover { background-color: transparent; }

.faq-toggle { cursor: pointer; }
.faq-toggle:hover { background-color: transparent; }

/* Chevron chip — the disclosure affordance lives in a small circle instead of
   as a bare caret, and fills with the primary colour when its answer is open,
   so the active question reads without having to inspect the text. */
.how-faq .faq-chevron {
    background-color: var(--surface-container);
    border-color: var(--outline-variant);
    color: var(--outline);
}
.how-faq .faq-icon {
    color: inherit;
    /* Inherit replaces the dropped transition-transform utility; the script
       writes the rotation inline, so the transition has to live here. */
    transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}
.how-faq .faq-item:hover .faq-chevron {
    color: var(--primary);
    border-color: var(--primary-fixed-dim);
}
.how-faq .faq-item.faq-open .faq-chevron {
    background-color: var(--primary);
    border-color: var(--primary);
    /* Not #fff: dark mode swaps --primary for a light tint and expects its
       own on-primary, so the caret stays legible in both schemes. */
    color: var(--on-primary);
}
/* The question carries the open-state weight, not the number chip. */
.how-faq .faq-item.faq-open .faq-q { font-weight: 700; }
</style>
<noscript><style>
.how-faq .faq-content {
    grid-template-rows: 1fr !important;
    opacity: 1 !important;
    visibility: visible !important;
}
.how-faq .faq-content > * {
    padding: 1.125rem 1.75rem 1.75rem !important;
    max-width: min(100%, calc(72ch + 3.5rem));
    background-image: linear-gradient(var(--outline-variant), var(--outline-variant));
    background-repeat: no-repeat;
    background-size: min(calc(100% - 3.5rem), 72ch) 1px;
    background-position: 1.75rem 0;
}
.how-faq .faq-item { border-radius: 1.75rem; }
</style></noscript>

<script>
(function() {
  "use strict";

  function forEachNode(list, fn) {
    Array.prototype.forEach.call(list, fn);
  }

  function setFaqState(btn, isOpen) {
    var item = btn.closest('.faq-item');
    var icon = btn.querySelector('.faq-icon');

    if (item) {
      item.classList.toggle('faq-open', isOpen);
    }
    if (icon) {
      icon.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
    }
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }

  function toggleFaq(btn) {
    var wasOpen = btn.getAttribute('aria-expanded') === 'true';
    forEachNode(document.querySelectorAll('.faq-toggle'), function (other) {
      if (other !== btn) setFaqState(other, false);
    });
    setFaqState(btn, !wasOpen);
  }

  document.addEventListener('click', function(e) {
    var target = e.target;
    var btn = target && target.closest ? target.closest('.faq-toggle') : null;
    if (btn) toggleFaq(btn);
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.key === ' ') {
      var target = e.target;
      var btn = target && target.closest ? target.closest('.faq-toggle') : null;
      if (btn) {
        e.preventDefault();
        toggleFaq(btn);
      }
    }
  });

  forEachNode(document.querySelectorAll('.faq-toggle'), function (btn) {
    if (!btn.hasAttribute('aria-expanded')) btn.setAttribute('aria-expanded', 'false');
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
