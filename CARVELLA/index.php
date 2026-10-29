<?php
/* index.php - Public landing page with fleet showcase and booking calls to action. */
$pageTitle = 'CARVELLA — Vehicle Reservation Systems';
require_once __DIR__ . '/includes/header.php';

$query = "SELECT * FROM vehicles WHERE status = 'available' ORDER BY daily_rate ASC";
$availableResult = $conn->query($query);
$availableVehicles = [];
if ($availableResult && $availableResult->num_rows > 0) {
    while ($row = $availableResult->fetch_assoc()) {
        $availableVehicles[] = $row;
    }
}
$vehicleCount = count($availableVehicles);
$topQuery = "SELECT v.*, COUNT(r.id) AS rental_count FROM vehicles v LEFT JOIN reservations r ON r.vehicle_id = v.id AND r.status <> 'cancelled' WHERE v.status = 'available' GROUP BY v.id ORDER BY rental_count DESC, v.daily_rate ASC LIMIT 6";
$topResult = $conn->query($topQuery);
$topRented = [];
if ($topResult && $topResult->num_rows > 0) {
    while ($row = $topResult->fetch_assoc()) {
        $topRented[] = $row;
    }
}
$topRentedCount = count($topRented);
$featuredVehicle = $vehicleCount > 0 ? end($availableVehicles) : null;
$topRate = $featuredVehicle ? (int)$featuredVehicle['daily_rate'] : 8000;
$rateMin = 3000;
$rateMax = max($topRate + 500, 8000);
$rateSliderStep = 250;
$today = date('Y-m-d');
$defaultPickup = date('Y-m-d', strtotime('+1 day'));
$defaultReturn = date('Y-m-d', strtotime('+4 day'));

$categoryMap = [
    'sedan'     => 'sedan',
    'suv'       => 'suv',
    'mpv'       => 'mpv',
    'pickup'    => 'pickup',
    'van'       => 'van',
];
$protoCategoryLabel = [
    'sedan'     => 'Sedan',
    'suv'       => 'SUV',
    'mpv'       => 'MPV',
    'pickup'    => 'Pickup',
    'van'       => 'Van',
];
?>
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main id="main-content" tabindex="-1" class="flex-grow pt-20 md:pt-24">

<!-- 01. HERO SECTION -->
<section class="relative overflow-hidden pt-space-xl pb-space-2xl px-gutter-mobile md:px-gutter-desktop max-w-container-max-width mx-auto" id="home">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-space-2xl items-center">
<!-- Hero Text Column (7 Cols) -->
<div class="lg:col-span-7 flex flex-col items-start gap-space-lg hero-entrance-child" style="--hero-delay: 100ms">
<h1 class="text-headline-xl md:text-display-hero font-display-hero tracking-tight text-on-surface">
Find the Right Car for Your <span class="text-primary">Journey</span>
</h1>
<p class="text-body-lg font-body-lg text-on-surface-variant max-w-xl">
Browse reliable vehicles, check live availability, and reserve your next ride with CARVELLA. Uncompromising comfort, transparent pricing, and concierge care.
</p>
<!-- CTAs -->
<div class="flex flex-wrap items-center gap-space-sm pt-space-xs">
<a href="<?= BASE_URL ?>/auth/register.php" class="btn-primary-sweep bg-primary text-on-primary font-label-md text-label-md px-6 py-3.5 rounded-full hover:bg-primary-container transition-all inline-flex items-center gap-2 active:scale-95 group">
<span>Book Now</span>
<i class="bi bi-arrow-right text-sm transition-transform duration-200 group-hover:translate-x-1" aria-hidden="true"></i>
</a>
<a href="<?= BASE_URL ?>/customer/vehicles.php" class="bg-secondary-fixed text-on-secondary-fixed font-label-md text-label-md px-6 py-3.5 rounded-full hover:bg-secondary-fixed-dim transition-all active:scale-95">
<span>Explore Vehicles</span>
</a>
</div>
</div>
<!-- Hero Vehicle Presentation Column (5 Cols) -->
<div class="lg:col-span-5 relative hero-entrance-child" style="--hero-delay: 280ms">
<div class="deco-blob absolute -inset-4 bg-gradient-to-tr from-secondary-fixed via-surface-container to-surface-tint/20 rounded-3xl blur-2xl -z-10 opacity-70" data-parallax="0.22"></div>
<div class="card-spotlight bg-surface-container-lowest border border-outline-variant/60 rounded-3xl p-space-lg relative overflow-hidden hero-car-float">
<div class="flex justify-between items-center mb-space-sm">
<span class="bg-[#DCFCE7] text-[#15803D] font-label-sm text-label-sm px-space-md py-1 rounded-full uppercase tracking-wider flex items-center gap-1">
<span class="w-2 h-2 rounded-full bg-[#15803D] animate-ping"></span> Live Available
</span>
<?php if ($featuredVehicle): ?>
<span class="font-numeric-stat text-numeric-stat text-primary font-bold"><?= formatCurrency($featuredVehicle['daily_rate']) ?><span class="text-body-sm font-body-sm text-on-surface-variant font-normal">/day</span></span>
<?php endif; ?>
</div>
<div class="relative w-full h-64 rounded-2xl overflow-hidden bg-surface-container-low flex items-center justify-center">
<?php
$heroImg = vehicleLargeImage($featuredVehicle['image'] ?? '');
$heroSrcset = vehicleImageSrcset($featuredVehicle['image'] ?? '', BASE_URL);
// A 7-column hero inside a 92rem container: ~800px on desktop, full-bleed on a
// phone. The 1600px candidate covers retina, the 900px one covers a 1x phone.
$heroSizes = '(min-width: 1280px) 800px, (min-width: 1024px) 58vw, 92vw';
?>
<?php if ($heroImg !== ''): ?>
<img class="w-full h-full object-cover object-center" src="<?= BASE_URL ?>/<?= htmlspecialchars($heroImg) ?>"<?php if ($heroSrcset !== ''): ?> srcset="<?= htmlspecialchars($heroSrcset) ?>" sizes="<?= htmlspecialchars($heroSizes) ?>"<?php endif; ?> alt="<?= htmlspecialchars($featuredVehicle['brand'] . ' ' . $featuredVehicle['model']) ?>" width="1600" height="1195" fetchpriority="high" decoding="async">
<?php endif; ?>
<div class="absolute bottom-3 left-3 right-3 bg-surface-container-lowest rounded-2xl p-2.5 flex justify-around text-center border border-outline-variant">
<div>
<div class="text-label-sm text-outline">Capacity</div>
<div class="font-label-md text-on-surface font-semibold"><?= $featuredVehicle ? htmlspecialchars($featuredVehicle['seats']) : '5' ?> Passengers</div>
</div>
<div class="border-r border-outline-variant/40"></div>
<div>
<div class="text-label-sm text-outline">Transmission</div>
<div class="font-label-md text-on-surface font-semibold"><?= $featuredVehicle ? htmlspecialchars(ucfirst($featuredVehicle['transmission'])) : 'Automatic' ?></div>
</div>
<div class="border-r border-outline-variant/40"></div>
<div>
<div class="text-label-sm text-outline">Fuel</div>
<div class="font-label-md text-on-surface font-semibold"><?= $featuredVehicle ? htmlspecialchars(ucfirst($featuredVehicle['fuel_type'])) : 'Gasoline' ?></div>
</div>
</div>
</div>
<div class="mt-space-md flex justify-between items-center">
<div>
<h3 class="font-headline-sm text-headline-sm text-on-surface font-semibold"><?= $featuredVehicle ? htmlspecialchars($featuredVehicle['year'] . ' ' . $featuredVehicle['brand'] . ' ' . $featuredVehicle['model']) : 'Featured Vehicle' ?></h3>
</div>
<a href="<?= BASE_URL ?>/customer/reservation.php?vehicle_id=<?= $featuredVehicle ? (int)$featuredVehicle['id'] : 1 ?>" class="bg-primary text-on-primary font-label-md text-label-md px-space-lg py-2.5 rounded-full hover:bg-primary-container transition-all">
Rent Now
</a>
</div>
</div>
</div>
</div>
</section>

<!-- 02. FLOATING SEARCH & LIVE AVAILABILITY STRIP -->
<section class="px-gutter-mobile md:px-gutter-desktop max-w-container-max-width mx-auto mt-space-2xl mb-space-3xl relative z-20" id="search">
<div class="bg-surface-container-lowest border border-outline-variant rounded-3xl p-5 md:p-7">
<form onsubmit="event.preventDefault(); applyFleetFilter();">
<!-- Terminal Notice -->
<div class="bg-primary-fixed rounded-2xl px-space-md py-3 mb-5 flex flex-wrap items-center gap-2 text-body-sm font-body-sm text-on-primary-fixed border border-outline-variant">
<i class="bi bi-geo-alt-fill text-base text-primary" aria-hidden="true"></i>
<span class="text-on-primary-fixed-variant">Exclusive Pick-up &amp; Return Location</span>
<span class="font-semibold text-on-primary-fixed">Dagupan City Hub, Pangasinan</span>
<span class="text-body-sm text-on-primary-fixed-variant hidden sm:inline">(Strictly All Pick-ups &amp; Handovers)</span>
</div>
<!-- Filter Input Controls -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-space-md items-end">
<!-- Pick-up Date -->
<div class="lg:col-span-3">
<label class="block text-label-md font-label-md text-on-surface-variant mb-1.5 flex items-center gap-1.5" for="search-pickup">
<i class="bi bi-calendar3 text-sm text-primary" aria-hidden="true"></i>
Pick-up Date
</label>
<input class="w-full px-space-md py-2.5 bg-primary-fixed rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors" id="search-pickup" onchange="calculateLiveQuote()" type="date" value="<?= $defaultPickup ?>">
</div>
<!-- Return Date -->
<div class="lg:col-span-3">
<label class="block text-label-md font-label-md text-on-surface-variant mb-1.5 flex items-center gap-1.5" for="search-return">
<i class="bi bi-arrow-repeat text-sm text-primary" aria-hidden="true"></i>
Return Date
</label>
<input class="w-full px-space-md py-2.5 bg-primary-fixed rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors" id="search-return" onchange="calculateLiveQuote()" type="date" value="<?= $defaultReturn ?>">
</div>
<!-- Category -->
<div class="lg:col-span-3">
<label class="block text-label-md font-label-md text-on-surface-variant mb-1.5 flex items-center gap-1.5" for="search-category">
<i class="bi bi-car-front text-sm text-primary" aria-hidden="true"></i>
Category
</label>
<select class="w-full px-space-md py-2.5 bg-primary-fixed rounded-xl border border-outline-variant font-label-md text-label-md text-on-surface focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors" id="search-category">
<option value="all">All Models</option>
<option value="sedan">Sedans</option>
<option value="suv">SUVs</option>
<option value="mpv">MPVs</option>
<option value="pickup">Pickups</option>
<option value="van">Vans</option>
</select>
</div>
<!-- Max Price Slider & Search Action -->
<div class="lg:col-span-3 flex flex-col gap-space-sm">
<div>
<div class="flex items-center justify-between mb-1.5">
<label class="block text-label-md font-label-md text-on-surface-variant" for="search-price">Max Price</label>
<span class="text-label-md font-label-md text-primary font-bold" id="price-slider-val"><?= formatCurrency($rateMax) ?>/d</span>
</div>
<input class="w-full accent-primary cursor-pointer" id="search-price" min="<?= $rateMin ?>" max="<?= $rateMax ?>" step="<?= $rateSliderStep ?>" value="<?= $rateMax ?>" type="range" oninput="updatePriceSlider(this)">
</div>
<button class="btn-primary-sweep w-full bg-primary text-on-primary font-label-md text-label-md py-2.5 px-space-md rounded-xl hover:bg-primary-container transition-all inline-flex items-center justify-center gap-2" type="submit">
<i class="bi bi-search text-sm" aria-hidden="true"></i>
<span>Search Cars</span>
</button>
</div>
</div>
</form>
</div>
</section>

<!-- 03. CONTINUOUS AUTOMOTIVE BRANDS MARQUEE -->
<section class="border-y border-dashed border-outline-variant bg-surface-container-lowest py-6 md:py-7 overflow-hidden" aria-labelledby="brands-heading">
<div class="max-w-container-max-width mx-auto px-gutter-mobile md:px-gutter-desktop">
<div class="marquee-heading">
<span class="marquee-heading-rule" aria-hidden="true"></span>
<h2 id="brands-heading" class="marquee-heading-label">Top Brands Available in Carvella</h2>
<span class="marquee-heading-rule" aria-hidden="true"></span>
</div>
<?php
$brandLogos = [
    'TOYOTA' => 'TOYOTA BRAND LOGO.png',
    'HONDA' => 'HONDA BRAND LOGO.png',
    'MITSUBISHI' => 'MITSUBISHI BRAND LOGO.png',
    'FORD' => 'FORD BRAND LOGO.png',
    'HYUNDAI' => 'HYUNDAI BRAND LOGO.png',
    'SUZUKI' => 'SUZUKI BRAND LOGO.png',
];
$brandMarkup = '';
foreach ($brandLogos as $brandName => $brandFile):
    $brandMarkup .= '<div class="marquee-item"><img src="' . BASE_URL . '/assets/images/brands/' . rawurlencode($brandFile) . '" alt="" loading="eager" decoding="async"><span class="marquee-item-label">' . htmlspecialchars($brandName) . '</span></div>';
endforeach;
?>
<!-- The scrolling strip repeats each logo set to make the loop seamless,
     so it is decorative: hidden from assistive tech, with the brand list
     given once in the visually-hidden line below instead. -->
<div class="marquee" aria-hidden="true">
<div class="marquee-track">
<?php echo '<div class="marquee-track-set">' . $brandMarkup . '</div><div class="marquee-track-set">' . $brandMarkup . '</div>'; ?>
</div>
</div>
<p class="sr-only">Brands available at Carvella: <?= htmlspecialchars(implode(', ', array_keys($brandLogos))) ?>.</p>
</div>
</section>

<!-- 04. FEATURED VEHICLES CATALOG GRID -->
<section class="py-space-3xl px-gutter-mobile md:px-gutter-desktop max-w-container-max-width mx-auto" id="fleet">
<div class="flex flex-col md:flex-row md:items-end justify-between mb-space-xl gap-space-md">
<div>
<h2 class="text-headline-lg font-headline-lg text-on-surface">Top 6 Most Rented Vehicles</h2>
</div>
<!-- The category chips (All / Sedan / SUV / MPV / Pickup / Van) used to sit
     here. They had no click handler — the JS filtered the grid by
     .fleet-filter-btn, a NodeList that was empty when these elements were
     added, so the control was decorative. Removed rather than left as a
     row of dead controls. Category filtering still happens through the
     search form above, which drives window.applyFleetFilter. -->
<span class="font-label-sm text-label-sm text-tertiary" id="available-count" aria-live="polite"></span>
</div>
<!-- Skeleton Loader -->
<div class="hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-space-lg mb-space-xl" id="skeleton-catalog">
<?php for($s=1;$s<=3;$s++): ?>
<div class="bg-surface-container-lowest rounded-3xl p-4 sm:p-5 border border-outline-variant">
<div class="w-full aspect-[16/10] rounded-2xl shimmer-bg mb-space-md"></div>
<div class="w-3/4 h-6 shimmer-bg rounded-full mb-space-sm"></div>
<div class="flex gap-2 mb-space-md">
<div class="w-24 h-6 shimmer-bg rounded-full"></div>
<div class="w-20 h-6 shimmer-bg rounded-full"></div>
<div class="w-20 h-6 shimmer-bg rounded-full"></div>
</div>
<div class="pt-space-xs border-t border-outline-variant flex justify-between items-center">
<div class="w-24 h-7 shimmer-bg rounded-full"></div>
<div class="w-28 h-9 shimmer-bg rounded-full"></div>
</div>
</div>
<?php endfor; ?>
</div>
<!-- Vehicle Cards Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-space-lg" id="fleet-card-grid">
<?php if ($topRentedCount > 0): ?>
<?php foreach ($topRented as $v): ?>
<?php
$protoCat = $categoryMap[$v['type']] ?? 'sedan';
$typeLabel = $protoCategoryLabel[$v['type']] ?? ucfirst($v['type']);
$vehicleName = htmlspecialchars($v['year'] . ' ' . $v['brand'] . ' ' . $v['model']);
$rate = (float)$v['daily_rate'];
$isAvail = ($v['status'] === 'available');
?>
<div class="vehicle-card veh-card glass-card card-spotlight rounded-3xl overflow-hidden flex flex-col transition-all duration-300 group" data-category="<?= $protoCat ?>" data-price="<?= $rate ?>" data-trans="<?= htmlspecialchars(strtolower((string)$v['transmission'])) ?>" data-seats="<?= (int)$v['seats'] ?>" data-available="<?= $isAvail ? 'yes' : 'no' ?>">
<div class="flex flex-col w-full flex-1">
<div class="relative bg-gradient-to-br from-surface-container-low via-primary/5 to-surface-variant aspect-[4/3] overflow-hidden">
<?php
$cardImg = vehicleCardImage($v['image']);
$cardSrcset = vehicleImageSrcset($v['image'], BASE_URL);
// One of three columns in a 92rem container: ~450px on desktop, ~92vw on a
// phone.
$cardSizes = '(min-width: 1280px) 450px, (min-width: 1024px) 31vw, (min-width: 768px) 45vw, 88vw';
?>
<?php if ($cardImg !== ''): ?>
<img class="w-full h-full object-cover object-center relative z-10" src="<?= BASE_URL ?>/<?= htmlspecialchars($cardImg) ?>"<?php if ($cardSrcset !== ''): ?> srcset="<?= htmlspecialchars($cardSrcset) ?>" sizes="<?= htmlspecialchars($cardSizes) ?>"<?php endif; ?> width="900" height="672" alt="<?= $vehicleName ?>" loading="lazy" decoding="async">
<?php endif; ?>
<div class="absolute inset-0 z-10 bg-gradient-to-t from-black/45 via-black/5 to-black/25 pointer-events-none"></div>
<div class="absolute top-3 left-3 flex flex-col gap-1.5 items-start z-20">
<?php if ($isAvail): ?>
<span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 text-label-sm font-label-sm uppercase font-bold flex items-center gap-1 shadow-xs">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span> Available Now
</span>
<?php else: ?>
<span class="px-2.5 py-1 rounded-full bg-amber-100 text-amber-800 text-label-sm font-label-sm uppercase font-bold flex items-center gap-1 shadow-xs">
<span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span> <?= htmlspecialchars(ucfirst((string)$v['status'])) ?>
</span>
<?php endif; ?>
</div>
<span class="absolute bottom-3 right-3 z-20 px-2.5 py-1 rounded-full bg-black/35 backdrop-blur-sm text-white text-label-sm font-label-sm font-semibold capitalize"><?= htmlspecialchars($typeLabel) ?></span>
</div>
<div class="p-5 flex-1 flex flex-col justify-between">
<div>
<div class="flex items-center justify-between mb-1">
<span class="text-label-sm font-label-sm text-primary uppercase font-bold"><?= htmlspecialchars($typeLabel) ?></span>
<span class="text-label-sm font-label-sm text-tertiary"><?= (int)$v['year'] ?> Release</span>
</div>
<h3 class="text-headline-sm font-headline-sm text-on-surface group-hover:text-primary transition-colors"><?= $vehicleName ?></h3>
<div class="grid grid-cols-2 gap-2 my-4">
<div class="flex items-center gap-1.5 text-body-sm font-body-sm text-tertiary bg-surface-container-low px-2.5 py-1.5 rounded-xl">
<i aria-hidden="true" class="bi bi-people text-[16px] text-primary"></i>
<span><?= (int)$v['seats'] ?> Passengers</span>
</div>
<div class="flex items-center gap-1.5 text-body-sm font-body-sm text-tertiary bg-surface-container-low px-2.5 py-1.5 rounded-xl">
<i aria-hidden="true" class="bi bi-gear text-[16px] text-primary"></i>
<span><?= htmlspecialchars(ucfirst((string)$v['transmission'])) ?></span>
</div>
<div class="flex items-center gap-1.5 text-body-sm font-body-sm text-tertiary bg-surface-container-low px-2.5 py-1.5 rounded-xl">
<i aria-hidden="true" class="bi bi-fuel-pump text-[16px] text-primary"></i>
<span><?= htmlspecialchars(ucfirst((string)$v['fuel_type'])) ?></span>
</div>
<div class="flex items-center gap-1.5 text-body-sm font-body-sm text-tertiary bg-surface-container-low px-2.5 py-1.5 rounded-xl">
<i aria-hidden="true" class="bi bi-signpost-split text-[16px] text-primary"></i>
<span>Unlimited Mileage</span>
</div>
</div>
</div>
<div class="pt-4 border-t border-outline-variant/30 flex items-center justify-between">
<div>
<p class="text-label-sm font-label-sm text-tertiary">Daily Rate</p>
<div class="flex items-baseline gap-1">
<span class="text-headline-md font-headline-md font-bold text-on-surface"><?= formatCurrency($rate) ?></span>
<span class="text-body-sm font-body-sm text-tertiary">/day</span>
</div>
<span class="text-label-sm font-label-sm text-primary block mt-0.5">Cash OTC at Counter on Pickup</span>
</div>
<a href="<?= BASE_URL ?>/customer/vehicle-details.php?id=<?= (int)$v['id'] ?>" class="px-4 py-2.5 rounded-full <?= $isAvail ? 'bg-primary hover:bg-secondary text-on-primary' : 'bg-surface-container-high hover:bg-surface-container text-on-surface-variant' ?> font-label-md text-label-md font-semibold <?= $isAvail ? 'pill-accent-glow' : '' ?> active:scale-95 transition-all inline-flex items-center">
<span><?= $isAvail ? 'Rent' : 'Reserve' ?></span>
</a>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="col-span-full text-center py-16 bg-surface-container-lowest rounded-3xl border border-outline-variant/50">
<i aria-hidden="true" class="bi bi-car-front text-[48px] text-outline"></i>
<p class="text-headline-sm font-headline-sm text-on-surface mt-3">No vehicles available right now.</p>
<p class="text-body-md font-body-md text-on-surface-variant mt-1">Please check back soon for fresh arrivals.</p>
</div>
<?php endif; ?>
</div>
</section>

<!-- 05. 4-STEP RESERVATION FLOW & DYNAMIC CALCULATOR -->
<span id="how-it-works" class="block scroll-mt-32" aria-hidden="true"></span>
<section class="px-gutter-mobile md:px-gutter-desktop max-w-container-max-width mx-auto py-space-2xl text-center" id="calculator">
<!-- Pill Badge -->
<span class="inline-block px-space-md py-1.5 rounded-full text-label-sm text-label-sm font-bold bg-primary-fixed text-on-primary-fixed border border-outline-variant mb-space-md">
Simple &amp; Transparent Protocol
</span>
<h2 class="text-headline-lg font-headline-lg text-on-surface">Effortless 4-Step Reservation Architecture</h2>
<p class="mt-3 text-body-md font-body-md text-on-surface-variant max-w-2xl mx-auto">Book with peace of mind. Transparent quote generation and instant digital reservation submission.</p>
<!-- 4-Step Progression Indicators -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-md mt-space-2xl text-left">
<div class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-space-md flex items-center gap-3.5">
<div class="w-10 h-10 rounded-full bg-primary text-on-primary flex items-center justify-center flex-shrink-0 font-bold text-label-md">01</div>
<div>
<span class="block text-label-sm text-label-sm uppercase text-outline font-bold tracking-wider">Step 01</span>
<span class="block text-label-lg font-label-lg text-on-surface font-bold">Select Model</span>
</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-space-md flex items-center gap-3.5">
<div class="w-10 h-10 rounded-full bg-primary text-on-primary flex items-center justify-center flex-shrink-0 font-bold text-label-md">02</div>
<div>
<span class="block text-label-sm text-label-sm uppercase text-outline font-bold tracking-wider">Step 02</span>
<span class="block text-label-lg font-label-lg text-on-surface font-bold">Rental Dates</span>
</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-space-md flex items-center gap-3.5">
<div class="w-10 h-10 rounded-full bg-primary text-on-primary flex items-center justify-center flex-shrink-0 font-bold text-label-md">03</div>
<div>
<span class="block text-label-sm text-label-sm uppercase text-outline font-bold tracking-wider">Step 03</span>
<span class="block text-label-lg font-label-lg text-on-surface font-bold">Client Details</span>
</div>
</div>
<div class="bg-surface-container-lowest border border-outline-variant rounded-2xl p-space-md flex items-center gap-3.5">
<div class="w-10 h-10 rounded-full bg-primary-fixed text-on-primary-fixed flex items-center justify-center flex-shrink-0 font-bold text-label-md">04</div>
<div>
<span class="block text-label-sm text-label-sm uppercase text-outline font-bold tracking-wider">Step 04</span>
<span class="block text-label-lg font-label-lg text-on-surface font-bold">Approval &amp; Key</span>
</div>
</div>
</div>

<!-- Dynamic Calculator Box -->
<div class="mt-space-2xl bg-surface-container-lowest border border-outline-variant rounded-3xl p-6 md:p-9 text-left">
<div class="grid grid-cols-1 lg:grid-cols-12 gap-space-2xl items-start">
<!-- Left Side: Interactive Inputs -->
<div class="lg:col-span-7 flex flex-col gap-space-lg">
<h3 class="text-headline-lg font-headline-lg text-on-surface">Interactive Cost Estimator</h3>
<!-- Terminal Notice -->
<div class="bg-primary-fixed rounded-xl px-space-md py-2.5 flex flex-wrap items-center gap-2 text-body-sm font-body-sm border border-outline-variant">
<i class="bi bi-geo-alt-fill text-base text-primary" aria-hidden="true"></i>
<span class="text-on-primary-fixed-variant">Pick-up &amp; Key Handover Terminal:</span>
<span class="font-semibold text-on-primary-fixed">Dagupan City Hub, Pangasinan</span>
<span class="text-on-primary-fixed-variant">(Exclusively)</span>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-space-md">
<div>
<label class="block text-label-md font-label-md text-on-surface-variant mb-1.5" for="calc-vehicle-select">Target Vehicle</label>
<select class="w-full px-space-md py-2.5 bg-primary-fixed rounded-xl border border-outline-variant text-on-surface font-label-md focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors" id="calc-vehicle-select" onchange="runCostCalculator()">
<?php foreach ($availableVehicles as $v): ?>
<option value="<?= (int)$v['id'] ?>" data-rate="<?= (float)$v['daily_rate'] ?>"<?= $featuredVehicle && (int)$v['id'] === (int)$featuredVehicle['id'] ? ' selected' : '' ?>><?= htmlspecialchars($v['brand'] . ' ' . $v['model']) ?> — <?= formatCurrency($v['daily_rate']) ?>/day</option>
<?php endforeach; ?>
</select>
</div>
<div>
<label class="block text-label-md font-label-md text-on-surface-variant mb-1.5" for="calc-insurance">Protection Tier</label>
<select class="w-full px-space-md py-2.5 bg-primary-fixed rounded-xl border border-outline-variant text-on-surface font-label-md focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors" id="calc-insurance" onchange="runCostCalculator()">
<option value="0" data-fee="0">Standard Comprehensive (Included)</option>
<option value="2500" data-fee="2500">Zero Liability Damage Waiver (+&#8369;2,500 flat)</option>
<option value="1500" data-fee="1500">Full Tank Guarantee (+&#8369;1,500 flat)</option>
<option value="1000" data-fee="1000">Roadside &amp; Locker (+&#8369;1,000 flat)</option>
</select>
</div>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-space-md">
<div>
<label class="block text-label-md font-label-md text-on-surface-variant mb-1.5" for="calc-start">Pickup Date</label>
<input class="w-full px-space-md py-2.5 bg-primary-fixed rounded-xl border border-outline-variant text-on-surface font-label-md focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors" id="calc-start" onchange="runCostCalculator()" type="date" value="<?= $defaultPickup ?>">
</div>
<div>
<label class="block text-label-md font-label-md text-on-surface-variant mb-1.5" for="calc-end">Return Date</label>
<input class="w-full px-space-md py-2.5 bg-primary-fixed rounded-xl border border-outline-variant text-on-surface font-label-md focus:border-primary focus:ring-2 focus:ring-primary/20 transition-colors" id="calc-end" onchange="runCostCalculator()" type="date" value="<?= $defaultReturn ?>">
</div>
</div>
<!-- Add-on Amenities Selection -->
<div>
<span class="block text-label-md font-label-md text-on-surface-variant mb-2">Add-on Amenities</span>
<div class="flex flex-wrap gap-3">
<label class="cursor-pointer">
<input class="protection-check sr-only" type="checkbox" data-addon-fee="150" onchange="runCostCalculator()">
<span class="protection-inner inline-flex items-center gap-2.5 px-4 py-2.5 rounded-full border border-outline-variant transition-all">
<span class="protection-box w-4 h-4 rounded border border-outline-variant flex items-center justify-center flex-shrink-0" aria-hidden="true">
<i aria-hidden="true" class="bi bi-check-lg" style="font-size:12px"></i>
</span>
<span class="text-label-sm font-label-sm text-on-surface">Unlimited 5G Wi-Fi Device (+&#8369;150/d)</span>
</span>
</label>
<label class="cursor-pointer">
<input class="protection-check sr-only" type="checkbox" data-addon-fee="1000" onchange="runCostCalculator()">
<span class="protection-inner inline-flex items-center gap-2.5 px-4 py-2.5 rounded-full border border-outline-variant transition-all">
<span class="protection-box w-4 h-4 rounded border border-outline-variant flex items-center justify-center flex-shrink-0" aria-hidden="true">
<i aria-hidden="true" class="bi bi-check-lg" style="font-size:12px"></i>
</span>
<span class="text-label-sm font-label-sm text-on-surface">Chauffeur Service (+&#8369;1,000/d)</span>
</span>
</label>
</div>
</div>
</div>
<!-- Right Side: Dynamic Cost Ledger -->
<div class="lg:col-span-5 bg-primary-fixed rounded-3xl p-space-lg border border-outline-variant flex flex-col justify-between">
<div>
<div class="flex items-center justify-between pb-space-sm border-b border-outline-variant">
<span class="font-label-lg text-label-lg text-on-surface font-bold">Estimated Ledger</span>
<span class="text-label-sm text-label-sm font-bold text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200">Guaranteed Rate</span>
</div>
<div class="py-space-md space-y-space-sm">
<div class="flex justify-between items-center text-label-md font-label-md text-on-primary-fixed-variant">
<span>Duration Calculated</span>
<span class="font-bold text-on-surface" id="summary-days">3 Days</span>
</div>
<span class="sr-only" id="calc-duration-text" aria-live="polite"></span>
<div class="flex justify-between items-center text-label-md font-label-md text-on-primary-fixed-variant">
<span>Base Vehicle Rental</span>
<span class="font-bold text-on-surface" id="summary-base"><?= formatCurrency(($featuredVehicle['daily_rate'] ?? 3500) * 3) ?></span>
</div>
<div class="flex justify-between items-center text-label-md font-label-md text-on-primary-fixed-variant">
<span>Protection Package</span>
<span class="font-bold text-on-surface" id="summary-insurance">&#8369;0</span>
</div>
<div class="flex justify-between items-center text-label-md font-label-md text-on-primary-fixed-variant">
<span>Selected Add-ons</span>
<span class="font-bold text-on-surface" id="summary-addons">&#8369;0</span>
</div>
<div class="flex justify-between items-center text-label-md font-label-md text-on-primary-fixed-variant">
<span>Taxes &amp; Fleet Service</span>
<span class="font-bold text-on-surface">Included</span>
</div>
</div>
<div class="pt-3 border-t border-outline-variant flex items-baseline justify-between">
<span class="text-label-lg font-label-lg text-on-surface font-bold">Estimated Total</span>
<span class="text-numeric-stat font-numeric-stat text-primary font-bold" id="summary-total" data-count="manual"><?= formatCurrency(($featuredVehicle['daily_rate'] ?? 3500) * 3) ?></span>
</div>
</div>
<div class="mt-space-lg pt-space-xs">
<a id="calc-reserve-btn" href="<?= BASE_URL ?>/customer/reservation.php?vehicle_id=<?= $featuredVehicle ? (int)$featuredVehicle['id'] : 1 ?>" class="btn-primary-sweep w-full bg-primary text-on-primary font-label-md text-label-md py-3.5 rounded-xl hover:bg-primary-container transition-all flex items-center justify-center gap-2">
<span>Rent This Vehicle &amp; Continue</span>
<i class="bi bi-arrow-right text-sm" aria-hidden="true"></i>
</a>
<span class="text-label-sm font-label-sm text-on-primary-fixed-variant text-center block mt-3 leading-relaxed px-2">Payment Method: On-site OTC cash settlement only at Dagupan Central Hub counter upon vehicle inspection &amp; key handover.</span>
</div>
</div>
</div>
</div>
</section>

</main>

<script>
(function(){
    var BASE = '<?= BASE_URL ?>';

    function formatPeso(n){
    n = Number(n) || 0;
    if (window.CarvellaNumber) return '\u20B1' + window.CarvellaNumber.groupDigits(n);
    return '\u20B1' + n.toLocaleString('en-US');
}

// Price-range readout: glides between drag positions instead of snapping.
window.updatePriceSlider = function(input){
    var el = document.getElementById('price-slider-val');
    if (!el) return;
    var target = Number(input.value);
    var prev = Number(el.dataset.shown || target);
    el.dataset.shown = String(target);
    var final = formatPeso(target) + '/d';
    if (window.CarvellaNumber) {
        window.CarvellaNumber.tween(el, prev, target, { duration: 0.3, prefix: '\u20B1', final: final });
    } else {
        el.textContent = final;
    }
};

    function scrollToEl(id){ var el = document.getElementById(id); if(el) el.scrollIntoView({behavior:'smooth'}); }
    window.scrollToFleet = function(){ scrollToEl('fleet'); };
    window.scrollToCalculator = function(){ scrollToEl('calculator'); };
    window.calculateLiveQuote = function(){
        var p = document.getElementById('search-pickup');
        var r = document.getElementById('search-return');
        if (!p || !r) return;
        var d1 = new Date(p.value);
        var d2 = new Date(r.value);
        var diff = Math.ceil((d2 - d1) / (1000*60*60*24));
        if(isNaN(diff) || diff < 1) diff = 1;
        var el = document.getElementById('calc-duration-text');
        if (el) el.innerText = diff + ' Day' + (diff > 1 ? 's' : '');
    };

    window.applyFleetFilter = function(){
        var cat = document.getElementById('search-category').value;
        var maxP = Number(document.getElementById('search-price').value);
        var cards = document.querySelectorAll('.vehicle-card');
        var count = 0;
        cards.forEach(function(c){
            var match = (cat === 'all' || c.dataset.category === cat) && Number(c.dataset.price) <= maxP;
            if(match){ c.classList.remove('hidden'); count++; if (window.reenterCard) window.reenterCard(c); }
            else { c.classList.add('hidden'); c.classList.remove('card-reenter'); }
        });
        var ac = document.getElementById('available-count');
        if (ac) ac.innerText = count + ' Vehicle' + (count !== 1 ? 's' : '') + ' Available';
        scrollToEl('fleet');
    };

    window.toggleSkeletonDemo = function(){
        var skel = document.getElementById('skeleton-catalog');
        var grid = document.getElementById('fleet-card-grid');
        skel.classList.remove('hidden'); grid.classList.add('hidden');
        setTimeout(function(){ skel.classList.add('hidden'); grid.classList.remove('hidden'); }, 1200);
    };

    window.runCostCalculator = function(){
        var sel = document.getElementById('calc-vehicle-select');
        var opt = sel.selectedOptions[0];
        var rate = Number(opt.dataset.rate);
        var vehicleId = sel.value;
        var insSel = document.getElementById('calc-insurance');
        var insOpt = insSel.selectedOptions[0];
        var insFee = Number(insOpt.dataset.fee);
        var d1 = new Date(document.getElementById('calc-start').value);
        var d2 = new Date(document.getElementById('calc-end').value);
        var days = Math.ceil((d2 - d1) / (1000*60*60*24));
        if(isNaN(days) || days < 1) days = 1;
        // Add-ons are priced per day, so they scale with the duration the
        // same way the base rental does.
        var addonPerDay = 0;
        document.querySelectorAll('.protection-check[data-addon-fee]').forEach(function(cb){
            if (cb.checked) addonPerDay += Number(cb.dataset.addonFee) || 0;
        });
        var addons = addonPerDay * days;
        var base = rate * days;
        var total = base + insFee + addons;
        var setSum = function(id, txt, numeric){
            var el = document.getElementById(id);
            if (!el) return;
            // Totals marked data-count="manual" are owned by this script, so the
            // scroll observer never touches them; animate the change instead.
            if (numeric !== undefined && el.dataset.count === 'manual' && window.CarvellaNumber) {
                var prev = Number(el.dataset.shown || numeric);
                el.dataset.shown = String(numeric);
                window.CarvellaNumber.tween(el, prev, numeric, { duration: 0.5, prefix: '\u20B1', final: formatPeso(numeric) });
            } else {
                el.innerText = txt;
            }
            if (window.pulseValue) window.pulseValue(el);
        };
        setSum('summary-days', days + ' Day' + (days > 1 ? 's' : ''));
        setSum('summary-base', formatPeso(base), base);
        setSum('summary-insurance', formatPeso(insFee), insFee);
        setSum('summary-addons', formatPeso(addons), addons);
        setSum('summary-total', formatPeso(total), total);
        document.getElementById('calc-reserve-btn').href = BASE + '/customer/reservation.php?vehicle_id=' + vehicleId;
    };

    document.addEventListener('DOMContentLoaded', function(){
        calculateLiveQuote();
        runCostCalculator();
    });
})();
</script>

<?php require_once __DIR__ . '/includes/cookie-consent.php'; ?>
<?php require_once __DIR__ . '/includes/footer.php'; 
/* End of index.php */
?>
