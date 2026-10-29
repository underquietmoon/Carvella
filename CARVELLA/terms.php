<?php
/* terms.php - Terms and conditions page for rentals and site use. */
$pageTitle = 'Terms & Conditions | CARVELLA';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
$updated = 'September 7, 2026';
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20">

<!-- Hero Band -->
<section class="relative overflow-hidden bg-gradient-to-br from-secondary-fixed/30 via-surface to-surface-variant border-b border-outline-variant/40">
<div class="max-w-container-max-width mx-auto px-6 md:px-12 py-12">
<span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container-lowest border border-outline-variant/50 text-label-sm font-label-sm text-primary font-bold">
<i aria-hidden="true" class="bi bi-file-text text-sm"></i> Rental Agreement
</span>
<h1 class="text-headline-xl md:text-display-hero font-display-hero text-on-surface mt-4 tracking-tight">Terms &amp; Conditions</h1>
<p class="text-body-lg font-body-lg text-tertiary mt-2 max-w-2xl">The terms governing the use of the <strong class="text-on-surface">CARVELLA Luxury Fleet</strong> website, vehicle reservations, and rental transactions in the Republic of the Philippines.</p>
<p class="text-label-sm font-label-sm text-tertiary mt-4">Last Updated: <?= htmlspecialchars($updated) ?></p>
</div>
</section>

<!-- Content -->
<section class="max-w-container-max-width mx-auto px-6 md:px-12 py-12">
<div class="max-w-4xl space-y-6">

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-person-check text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">1. Acceptance of Terms</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">By accessing, browsing, or using this website and/or by creating a reservation, you acknowledge that you have read, understood, and agreed to be bound by these Terms &amp; Conditions and our <a class="text-primary underline underline-offset-2 hover:text-secondary" href="<?= BASE_URL ?>/privacy-policy.php">Privacy Policy</a>. If you do not agree, please do not use our website or services.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-car-front text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">2. The Service</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">CARVELLA operates a premium vehicle rental and reservation platform within Dagupan City and surrounding areas of Pangasinan, North Luzon. Vehicles are dispatched at our AB Fernandez Ave Central Hub. Availability, rates, and vehicle specifications shown on the website are indicative and subject to final confirmation at booking.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-cash-stack text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">3. Reservations, Dates &amp; Payment</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<ul class="list-disc pl-6 space-y-2">
<li>All rental fees are quoted and settled in Philippine Peso (₱).</li>
<li>Daily rates and protection fees are displayed transparently at reservation; the total is computed as <em>days &times; daily rate + protection fee</em>.</li>
<li>Settlement is <strong class="text-on-surface">Cash OTC at Counter on Pickup</strong> unless otherwise arranged.</li>
<li>The reservation period runs from the pickup date to the return date indicated on your reservation.</li>
<li>Late returns are subject to additional charges calculated on the prevailing daily rate.</li>
</ul>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-person-badge text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">4. Driver Eligibility &amp; Requirements</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<ul class="list-disc pl-6 space-y-2">
<li>You must be at least <strong class="text-on-surface">21 years old</strong> and hold a <strong class="text-on-surface">valid Philippine driver's license</strong> (or an International Driving Permit with valid home license).</li>
<li>A valid <strong class="text-on-surface">license number (License No.)</strong> and government-issued ID are required at reservation and verified at pickup (KYC).</li>
<li>The reservation holder must be present at pickup to sign the rental agreement.</li>
<li>CARVELLA reserves the right to decline service to any individual failing verification.</li>
</ul>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-fuel-pump text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">5. Vehicle Use &amp; Mileage</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<ul class="list-disc pl-6 space-y-2">
<li>Vehicles are provided with <strong class="text-on-surface">unlimited daily mileage</strong> for lawful road use within the Philippines.</li>
<li>You must comply with all traffic laws and LTO regulations while driving.</li>
<li>Vehicles must not be used for subleasing, racing, illegal activities, or off-road use without authorization.</li>
<li>If the <strong class="text-on-surface">Full-to-Full</strong> policy applies, the vehicle is delivered with a full tank and must be returned with a full tank.</li>
</ul>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-shield text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">6. Protection Packages</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<p>Optional protection add-ons are available at reservation (flat fee per rental, as selected):</p>
<ul class="list-disc pl-6 space-y-2">
<li><strong class="text-on-surface">Comprehensive Coverage (CWD)</strong> — ₱2,500: reduces your liability for accidental damage.</li>
<li><strong class="text-on-surface">Full Tank (Fulltank)</strong> — ₱1,500: pre-paid full fuel delivered at pickup.</li>
<li><strong class="text-on-surface">Roadside Assistance</strong> — ₱1,000: 24/7 roadside help during the rental.</li>
</ul>
<p class="text-body-sm font-body-sm">Protection packages do not cover willful damage, unauthorized use, or gross negligence.</p>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-calendar-x text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">7. Cancellation &amp; No-Show</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<ul class="list-disc pl-6 space-y-2">
<li>Cancellations requested through your account before pickup may be processed free of charge where permitted.</li>
<li>Failure to appear at pickup (no-show) may be treated as a cancelled reservation and may prevent future bookings.</li>
<li>CARVELLA may cancel reservations due to force majeure, vehicle unavailability, or suspected fraud; affected customers will be notified.</li>
</ul>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-wrench text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">8. Vehicle Condition, Damage &amp; Liability</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<ul class="list-disc pl-6 space-y-2">
<li>You are responsible for the vehicle during the rental period, including traffic violations, tolls, and parking fines incurred.</li>
<li>Document any pre-existing damage at pickup; unexplained damage on return may be charged to you.</li>
<li>Report accidents immediately; do not admit liability or settle claims without our consent.</li>
<li>Renter liability is subject to applicable protection packages and insurance policies.</li>
</ul>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-key-fill text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">9. Pickup &amp; Return</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">Vehicles are dispatched and returned at the <strong class="text-on-surface">CARVELLA AB Fernandez Ave Central Hub, Dagupan City</strong>. Pickup follows a streamlined counter dispatch process, including vehicle inspection and physical key handover. Return requires prompt mileage and fuel checks at the counter.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-hammer text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">10. Limitation of Liability</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">To the fullest extent permitted by law, CARVELLA shall not be liable for indirect, incidental, or consequential damages arising from your use of the website or rental services. Our aggregate liability is limited to the amounts you paid for the affected reservation.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-bank text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">11. Governing Law</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">These Terms are governed by the laws of the Republic of the Philippines. Any disputes shall be subject to the exclusive jurisdiction of the courts of Dagupan City, Pangasinan.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-arrow-clockwise text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">12. Amendments &amp; Contact</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<p>We may revise these Terms from time to time; the latest version is published on this page. Questions? Contact <strong class="text-on-surface">CARVELLA Luxury Fleet</strong>, AB Fernandez Ave Central Hub, Dagupan City, Pangasinan &mdash; +63 (075) 522-8800 or <span>support@carvella.ph</span>.</p>
</div>
</div>

</div>
</section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; 
/* End of terms.php */
?>