<?php
/* privacy-policy.php - Privacy policy page describing data collection and handling. */
$pageTitle = 'Privacy Policy | CARVELLA';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
$updated = 'September 7, 2026';
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20">

<!-- Hero Band -->
<section class="relative overflow-hidden bg-gradient-to-br from-secondary-fixed/30 via-surface to-surface-variant border-b border-outline-variant/40">
<div class="max-w-container-max-width mx-auto px-6 md:px-12 py-12">
<span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-surface-container-lowest border border-outline-variant/50 text-label-sm font-label-sm text-primary font-bold">
<i aria-hidden="true" class="bi bi-lock text-sm"></i> Legal &amp; Compliance
</span>
<h1 class="text-headline-xl md:text-display-hero font-display-hero text-on-surface mt-4 tracking-tight">Privacy Policy</h1>
<p class="text-body-lg font-body-lg text-tertiary mt-2 max-w-2xl">How <strong class="text-on-surface">CARVELLA Luxury Fleet</strong> collects, uses, protects, and discloses your personal information in compliance with Republic Act No. 10173 (Data Privacy Act of 2012).</p>
<p class="text-label-sm font-label-sm text-tertiary mt-4">Last Updated: <?= htmlspecialchars($updated) ?></p>
</div>
</section>

<!-- Content -->
<section class="max-w-container-max-width mx-auto px-6 md:px-12 py-12">
<div class="max-w-4xl space-y-6">

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-person-badge-fill text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">1. Information We Collect</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<p><strong class="text-on-surface">Personal information you provide:</strong> full name, email address, mobile number, home address, valid driver's license number and copy, government-issued ID, reservation dates, pickup/return locations, and optional notes.</p>
<p><strong class="text-on-surface">Reservation details:</strong> vehicle selected, rental period, protection package chosen, and computed total amounts in Philippine Peso (₱).</p>
<p><strong class="text-on-surface">Device and usage information:</strong> browser type, device type, and pages visited on our website to improve performance and security.</p>
<div class="rounded-2xl bg-surface-container px-4 py-3 flex gap-2 items-start">
<i aria-hidden="true" class="bi bi-info-circle text-sm text-primary"></i>
<span class="text-body-sm font-body-sm">We do <strong>not</strong> store full credit/debit card numbers. Payments are settled Cash OTC at Counter on Pickup; no payment card data is transmitted or retained on our servers.</span>
</div>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-search text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">2. How We Use Your Information</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<p>We use collected information to:</p>
<ul class="list-disc pl-6 space-y-2">
<li>Create and manage your reservations and rental schedule;</li>
<li>Verify your identity and driver's license (KYC) in line with LTO rental requirements;</li>
<li>Communicate reservation confirmations, updates, and support messages;</li>
<li>Compute pricing, protection fees, and billing totals;</li>
<li>Maintain secure access to your account and reservations;</li>
<li>Improve website functionality, security, and customer experience.</li>
</ul>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-share-fill text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">3. Disclosure and Sharing</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<p>We do <strong class="text-on-surface">not sell</strong> your personal information. We may share it only with:</p>
<ul class="list-disc pl-6 space-y-2">
<li><strong class="text-on-surface">Government bodies</strong> (e.g., LTO, law enforcement) when required by law or to protect legal rights;</li>
<li><strong class="text-on-surface">Service providers</strong> supporting our platform, hosting, and communications, bound by confidentiality;</li>
<li><strong class="text-on-surface">Insurance partners</strong> solely for claims processing related to your rental;</li>
<li><strong class="text-on-surface">Account changes:</strong> in the event of a merger or transfer of business, your data may transfer under the same protections.</li>
</ul>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-calendar3-fill text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">4. Data Retention</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">We retain personal data only as long as necessary to provide our services, comply with legal and tax obligations, and resolve disputes. Reservation and identity records are retained for a reasonable period after your last booking and then securely deleted or anonymized.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-shield-check text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">5. Your Rights Under the Data Privacy Act</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<p>Under RA 10173, you have the right to:</p>
<ul class="list-disc pl-6 space-y-2">
<li><strong class="text-on-surface">Be informed</strong> of how your data is processed;</li>
<li><strong class="text-on-surface">Access</strong> the data we hold about you;</li>
<li><strong class="text-on-surface">Correct</strong> inaccurate or incomplete information;</li>
<li><strong class="text-on-surface">Object</strong> to processing on valid grounds;</li>
<li><strong class="text-on-surface">Erase or block</strong> data when authorized;</li>
<li><strong class="text-on-surface">Withdraw consent</strong> at any time, subject to legal obligations;</li>
<li><strong class="text-on-surface">Lodge a complaint</strong> with the National Privacy Commission (NPC) if you believe your rights were violated.</li>
</ul>
<p class="mt-2">To exercise any of these rights, contact our Data Privacy Officer using the details in Section 9.</p>
</div>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-shield-fill text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">6. Data Security</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">We apply reasonable technical and organizational safeguards — including encrypted connections, role-based access controls, and secure session management — to protect your information against unauthorized access, alteration, or loss. No method of transmission over the internet is 100% secure; however, we strive to protect your data at all times.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-person-hearts text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">7. Children's Privacy</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">Our rental services are intended for licensed drivers aged 21 and above. We do not knowingly collect personal information from minors. If you believe a minor has provided us data, contact us and we will delete it promptly.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-arrow-clockwise text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">8. Changes to This Policy</h2>
</div>
<p class="text-body-md font-body-md text-on-surface-variant leading-relaxed">We may update this Privacy Policy from time to time. The latest version will always be published on this page with an updated "Last Updated" date. Continued use of our website after changes constitutes acceptance of the revised policy.</p>
</div>

<div class="bg-surface-container-lowest rounded-3xl p-6 md:p-8 border border-outline-variant/40 shadow-xs">
<div class="flex items-center gap-3 mb-4">
<span class="w-10 h-10 rounded-2xl bg-primary-fixed flex items-center justify-center text-primary"><i aria-hidden="true" class="bi bi-envelope-at-fill text-lg"></i></span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">9. Contact Us</h2>
</div>
<div class="space-y-3 text-body-md font-body-md text-on-surface-variant leading-relaxed">
<p>For privacy requests or concerns, contact our Data Privacy Officer:</p>
<div class="rounded-2xl bg-surface-container px-4 py-3 space-y-2">
<p><strong class="text-on-surface">CARVELLA Luxury Fleet</strong> — AB Fernandez Ave Central Hub, Dagupan City, Pangasinan</p>
<p><i aria-hidden="true" class="bi bi-telephone-fill text-sm text-primary align-middle"></i> +63 (075) 522-8800</p>
<p><i aria-hidden="true" class="bi bi-envelope-fill text-sm text-primary align-middle"></i> privacy@carvella.ph</p>
</div>
<p class="text-body-sm font-body-sm">You may also file a complaint with the National Privacy Commission at <strong class="text-on-surface">complaints@privacy.gov.ph</strong>.</p>
</div>
</div>

</div>
</section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; 
/* End of privacy-policy.php */
?>