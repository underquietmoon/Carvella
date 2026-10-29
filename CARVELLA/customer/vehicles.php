<?php
/* customer/vehicles.php - Customer fleet listing with filters. */
$pageTitle = 'Vehicles | CARVELLA Luxury Fleet Dagupan';
require_once __DIR__ . '/../config/auth.php';

// Safety sync: ensure vehicle status matches active rentals.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && function_exists('refreshVehicleAvailabilityStatus')) {
    $syncVehs = $conn->query("SELECT id FROM vehicles WHERE status <> 'archived'");
    if ($syncVehs) {
        while ($sv = $syncVehs->fetch_assoc()) {
            refreshVehicleAvailabilityStatus($conn, (int)$sv['id']);
        }
        $syncVehs->close();
    }
}

/* Filter values are normalised so MySQL matches columns case-insensitively and the right pill lights up. */
$brand = trim((string)($_GET['brand'] ?? ''));
$type = strtolower(trim((string)($_GET['type'] ?? '')));
$transFilter = strtolower(trim((string)($_GET['trans'] ?? '')));
$search = (string)($_GET['search'] ?? '');

/* Only maintenance, unavailable and archived units are hidden; a rented car stays listed and reservable. */
/* on_road marks a unit out now vs reserved for later, so the card can say "Rented" or "Reserved". */
$onRoadStatuses = "'" . implode("','", RENTAL_BLOCKING_RENTAL_STATUSES) . "'";
$publicColumns = 'id, brand, model, year, type, seats, transmission, fuel_type, daily_rate, image, status,
                  EXISTS(SELECT 1 FROM rentals ren WHERE ren.vehicle_id = vehicles.id AND ren.status IN (' . $onRoadStatuses . ')) AS on_road';
$query = "SELECT $publicColumns FROM vehicles";
$params = [];
$types = '';

/* Brand stats are a fleet-wide aggregate so a filtered-out brand never vanishes from the dropdown. */
$brandStats = [];
$brandRows = $conn->query("SELECT brand, COUNT(*) AS total, COALESCE(SUM(status = 'available'), 0) AS ready FROM vehicles WHERE status IN ('available', 'rented') GROUP BY brand");
if ($brandRows) {
    while ($br = $brandRows->fetch_assoc()) {
        $brandName = trim((string)($br['brand'] ?? ''));
        if ($brandName === '') {
            $brandName = 'Other';
        }
        if (!isset($brandStats[$brandName])) {
            $brandStats[$brandName] = ['available' => 0, 'total' => 0];
        }
        $brandStats[$brandName]['total'] += (int)$br['total'];
        $brandStats[$brandName]['available'] += (int)$br['ready'];
    }
    $brandRows->free();
}
ksort($brandStats, SORT_NATURAL | SORT_FLAG_CASE);

$fleetListed = 0;
foreach ($brandStats as $brandStat) {
    $fleetListed += $brandStat['total'];
}

/* The brand is rewritten to the fleet's spelling so the dropdown's active state matches the query rows. */
if ($brand !== '') {
    foreach (array_keys($brandStats) as $candidate) {
        if (strcasecmp($brand, $candidate) === 0) {
            $brand = $candidate;
            break;
        }
    }
}

/* The status restriction is a normal condition, so only one WHERE is emitted and prepare() never sees two. */
$conditions = ["status IN ('available', 'rented')"];
if ($brand) {
    $conditions[] = "brand = ?";
    $params[] = $brand;
    $types .= 's';
}
if ($type) {
    $conditions[] = "type = ?";
    $params[] = $type;
    $types .= 's';
}
/* The gearbox filter lives in the query, not a client toggle that would only filter the loaded cards. */
if ($transFilter) {
    $conditions[] = "LOWER(transmission) = ?";
    $params[] = $transFilter;
    $types .= 's';
}
if ($search) {
    $conditions[] = "(brand LIKE ? OR model LIKE ? OR year LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}
$where = " WHERE " . implode(' AND ', $conditions);
$query .= $where;

// Sorted on rate alone so cars keep their slot as status changes; id breaks rate ties to keep order stable.
$query .= " ORDER BY daily_rate ASC, id ASC";

/* The COUNT and "ready" badge are one aggregate over the whole filtered set, not just this page of cards. */
$totalVehicles = 0;
$readyCount = 0;
$countStmt = $conn->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(status = 'available'), 0) AS ready FROM vehicles" . $where);
if ($countStmt === false) {
    error_log('Carvella fleet count query failed: ' . $conn->error);
} else {
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    if ($countStmt->execute()) {
        $agg = $countStmt->get_result()->fetch_assoc();
        $totalVehicles = (int)$agg['count'];
        $readyCount = (int)$agg['ready'];
    } else {
        error_log('Carvella fleet count query execute failed: ' . $countStmt->error);
    }
    $countStmt->close();
}
$reservedCount = max(0, $totalVehicles - $readyCount);

$pg = paginationBounds(paginationPage(), $totalVehicles, 12);
$perPage = $pg['per_page'];
$offset  = $pg['offset'];

$query .= " LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
// A failed prepare() returns false here, so log it and render an empty fleet instead of calling ->execute() on it.
if ($stmt === false) {
    error_log('Carvella fleet query failed: ' . $conn->error);
    $stmt = null;
} else {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        error_log('Carvella fleet query execute failed: ' . $stmt->error);
        $stmt->close();
        $stmt = null;
    }
}

$cars = [];
if ($stmt instanceof mysqli_stmt) {
    $result = $stmt->get_result();
    $stmt->close();
    while ($row = $result->fetch_assoc()) {
        $cars[] = $row;
    }
}

$groupMap = [
    'sedan'     => 'sedan',
    'suv'       => 'suv',
    'mpv'       => 'mpv',
    'pickup'    => 'pickup',
    'van'       => 'van',
];
$categoryLabel = [
    'sedan'     => 'Sedan',
    'suv'       => 'SUV',
    'mpv'       => 'MPV',
    'pickup'    => 'Pickup',
    'van'       => 'Van',
];

// Transmission pills list gearbox types actually in the fleet and are counted fleet-wide (respect selected brand filter).
$transLabel = [
    'automatic' => 'Automatic',
    'manual'    => 'Manual',
];
$transOrder = ['automatic', 'manual'];
$transCounts = [];
$transCountSql = "SELECT transmission, COUNT(*) AS c FROM vehicles WHERE status IN ('available', 'rented')";
$transCountParams = [];
$transCountTypes = '';
if ($brand !== '') {
    $transCountSql .= " AND brand = ?";
    $transCountParams[] = $brand;
    $transCountTypes .= 's';
}
$transCountSql .= " GROUP BY transmission";
$transCountStmt = $conn->prepare($transCountSql);
if ($transCountStmt) {
    if (!empty($transCountParams)) {
        $transCountStmt->bind_param($transCountTypes, ...$transCountParams);
    }
    if ($transCountStmt->execute()) {
        $transRows = $transCountStmt->get_result();
        if ($transRows) {
            while ($tr = $transRows->fetch_assoc()) {
                $key = strtolower(trim((string)($tr['transmission'] ?? '')));
                if ($key === '') {
                    continue;
                }
                $transCounts[$key] = ($transCounts[$key] ?? 0) + (int)$tr['c'];
            }
        }
    }
    $transCountStmt->close();
}
$transKeys = array_values(array_filter($transOrder, static fn($k) => isset($transCounts[$k])));
foreach (array_keys($transCounts) as $key) {
    if (!in_array($key, $transKeys, true)) {
        $transKeys[] = $key;
    }
}

/* Count vehicles per category for filter pills (respect selected brand and transmission filters). */
$typeCounts = [];
$typeCountSql = "SELECT type, COUNT(*) AS c FROM vehicles WHERE status IN ('available', 'rented')";
$typeCountParams = [];
$typeCountTypes = '';
if ($brand !== '') {
    $typeCountSql .= " AND brand = ?";
    $typeCountParams[] = $brand;
    $typeCountTypes .= 's';
}
if ($transFilter !== '') {
    $typeCountSql .= " AND LOWER(transmission) = ?";
    $typeCountParams[] = $transFilter;
    $typeCountTypes .= 's';
}
if ($search !== '') {
    $typeCountSql .= " AND (brand LIKE ? OR model LIKE ? OR year LIKE ?)";
    $searchTerm = "%$search%";
    $typeCountParams[] = $searchTerm;
    $typeCountParams[] = $searchTerm;
    $typeCountParams[] = $searchTerm;
    $typeCountTypes .= 'sss';
}
$typeCountSql .= " GROUP BY type";
$typeCountStmt = $conn->prepare($typeCountSql);
if ($typeCountStmt) {
    if (!empty($typeCountParams)) {
        $typeCountStmt->bind_param($typeCountTypes, ...$typeCountParams);
    }
    if ($typeCountStmt->execute()) {
        $typeRows = $typeCountStmt->get_result();
        if ($typeRows) {
            while ($tr = $typeRows->fetch_assoc()) {
                $tkey = strtolower(trim((string)($tr['type'] ?? '')));
                if ($tkey === '') {
                    continue;
                }
                $typeCounts[$tkey] = ($typeCounts[$tkey] ?? 0) + (int)$tr['c'];
            }
        }
    }
    $typeCountStmt->close();
}

/* "All Units" count should reflect current filters except type. */
$allUnitsCount = 0;
$allCountSql = "SELECT COUNT(*) AS c FROM vehicles WHERE status IN ('available', 'rented')";
$allCountParams = [];
$allCountTypes = '';
if ($brand !== '') {
    $allCountSql .= " AND brand = ?";
    $allCountParams[] = $brand;
    $allCountTypes .= 's';
}
if ($transFilter !== '') {
    $allCountSql .= " AND LOWER(transmission) = ?";
    $allCountParams[] = $transFilter;
    $allCountTypes .= 's';
}
if ($search !== '') {
    $allCountSql .= " AND (brand LIKE ? OR model LIKE ? OR year LIKE ?)";
    $searchTerm = "%$search%";
    $allCountParams[] = $searchTerm;
    $allCountParams[] = $searchTerm;
    $allCountParams[] = $searchTerm;
    $allCountTypes .= 'sss';
}
$allCountStmt = $conn->prepare($allCountSql);
if ($allCountStmt) {
    if (!empty($allCountParams)) {
        $allCountStmt->bind_param($allCountTypes, ...$allCountParams);
    }
    if ($allCountStmt->execute()) {
        $allRes = $allCountStmt->get_result()->fetch_assoc();
        $allUnitsCount = (int)($allRes['c'] ?? 0);
    }
    $allCountStmt->close();
}
if ($allUnitsCount === 0 && $type === '') {
    $allUnitsCount = (int)$totalVehicles;
} else if ($allUnitsCount === 0) {
    $allUnitsCount = array_sum($typeCounts);
}

/* Pill classes lifted verbatim from the JS constants this page used to
   ship, so the anchors render pixel-identically to the buttons they
   replace - including the smooth swap and press-scale keyed on
   .veh-filter-pill in style.css and its motion exclusion in app-motion.js. */
$pillActive    = 'veh-filter-pill px-3.5 py-1.5 rounded-full bg-primary text-on-primary font-label-sm text-label-sm font-semibold whitespace-nowrap shadow-sm transition-all duration-150';
$pillInactive  = 'veh-filter-pill px-3.5 py-1.5 rounded-full bg-surface-container-low hover:bg-surface-container text-tertiary hover:text-on-surface font-label-sm text-label-sm border border-outline-variant/40 whitespace-nowrap transition-all duration-150';
$transActive   = 'veh-filter-pill veh-trans-pill px-3.5 py-1.5 rounded-full bg-primary text-on-primary font-label-sm text-label-sm font-semibold whitespace-nowrap shadow-sm transition-all duration-150';
$transInactive = 'veh-filter-pill veh-trans-pill px-3.5 py-1.5 rounded-full bg-surface-container-low hover:bg-surface-container text-tertiary hover:text-on-surface font-label-sm text-label-sm border border-outline-variant/40 whitespace-nowrap transition-all duration-150';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20">

<?php if ($flash = getFlash('success')): ?>
<div class="max-w-container-max-width mx-auto px-6 md:px-12 mb-6">
  <div class="bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-2xl px-4 py-3 flex items-center gap-3 text-body-sm" role="status" data-flash>
    <i class="bi bi-check-circle text-[20px]" aria-hidden="true"></i>
    <span><?= e($flash) ?></span>
  </div>
</div>
<?php endif; ?>
<?php if ($flash = getFlash('error')): ?>
<div class="max-w-container-max-width mx-auto px-6 md:px-12 mb-6">
  <div class="bg-error-container text-on-error-container border border-error-container rounded-2xl px-4 py-3 flex items-center gap-3 text-body-sm" role="alert" data-flash>
    <i class="bi bi-exclamation-circle text-[20px]" aria-hidden="true"></i>
    <span><?= e($flash) ?></span>
  </div>
</div>
<?php endif; ?>

<!-- 01. HERO SECTION & DAGUPAN LOCATION BADGE -->
<section class="max-w-container-max-width mx-auto px-6 md:px-12 pt-6 pb-8">
<div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-surface-container-low via-surface to-surface-variant p-6 md:p-8 border border-outline-variant/40">
<div class="absolute -right-24 -top-24 w-96 h-96 bg-primary-container/10 rounded-full blur-3xl pointer-events-none" data-parallax="0.18"></div>
<div class="absolute -left-12 -bottom-12 w-80 h-80 bg-secondary-fixed/30 rounded-full blur-2xl pointer-events-none"></div>
<div class="relative z-10 max-w-4xl">
<h1 class="text-headline-xl md:text-display-hero font-headline-xl md:font-display-hero text-on-surface tracking-tight leading-tight hero-entrance-child" style="--hero-delay: 190ms">
Dagupan City Fleet &amp; Vehicle Catalog
</h1>
<p class="mt-3 text-body-lg font-body-lg text-tertiary max-w-4xl leading-relaxed hero-entrance-child" style="--hero-delay: 290ms">Stationed at AB Fernandez Ave Central Hub, Pangasinan. 100% Insured with GPS tracking and Physical Key Handover at Dagupan Central Hub Counter. Seamless on-site counter dispatch for coastal escapes, business tours, and North Luzon transit.</p>
</div>
</div>
</section>

<!-- 02. INTERACTIVE FILTER TOOLBAR -->
<section class="max-w-container-max-width mx-auto px-6 md:px-12 mb-10">
<div class="bg-surface-container-lowest rounded-3xl px-6 py-5 border border-outline-variant/40 shadow-sm space-y-5">
<!-- Category Pills + Reset -->
<div class="flex items-center flex-wrap gap-3">
<span class="hidden lg:inline-flex items-center gap-1.5 text-label-sm font-label-sm text-tertiary uppercase font-semibold tracking-wider shrink-0 mr-1">
<i aria-hidden="true" class="bi bi-sliders text-sm text-primary"></i>
Filter by Category
</span>
<div class="flex flex-wrap items-center gap-2">
<a href="<?= e(queryUrl(['type' => null])) ?>" data-cat="all"<?= $type === '' ? ' aria-current="true"' : '' ?> class="<?= $type === '' ? $pillActive : $pillInactive ?>">All Units (<?= (int)$allUnitsCount ?>)</a>
<?php foreach (array_keys($groupMap) as $typeKey): ?>
<?php $catCount = $typeCounts[$typeKey] ?? 0; ?><a href="<?= e(queryUrl(['type' => $typeKey])) ?>" data-cat="<?= $typeKey ?>"<?= $type === $typeKey ? ' aria-current="true"' : '' ?> class="<?= $type === $typeKey ? $pillActive : $pillInactive ?>"><?= htmlspecialchars($categoryLabel[$typeKey]) ?>s<?= $catCount > 0 ? ' (' . (int)$catCount . ')' : '' ?></a>
<?php endforeach; ?>
</div>
<a href="<?= htmlspecialchars(BASE_URL); ?>/customer/vehicles.php" class="ml-auto w-9 h-9 rounded-full border border-outline-variant/60 hover:bg-surface-container text-tertiary hover:text-on-surface transition-all flex items-center justify-center shrink-0" title="Reset Filters" aria-label="Reset Filters">
<i aria-hidden="true" class="bi bi-arrow-clockwise text-base"></i>
</a>
</div>
<!-- Transmission Filter -->
<div class="flex flex-col">
<div class="flex justify-between items-center mb-2.5">
<label class="text-label-sm font-label-sm text-tertiary flex items-center gap-1">
<i aria-hidden="true" class="bi bi-gear text-sm"></i>
Transmission
</label>
<span class="text-label-sm font-label-sm text-tertiary">Narrow the fleet by gearbox type</span>
</div>
<div class="flex flex-wrap items-center gap-2">
<a href="<?= e(queryUrl(['trans' => null])) ?>" data-trans="all"<?= $transFilter === '' ? ' aria-current="true"' : '' ?> class="<?= $transFilter === '' ? $transActive : $transInactive ?>">All Transmissions</a>
<?php foreach ($transKeys as $transKey): ?>
<a href="<?= e(queryUrl(['trans' => $transKey])) ?>" data-trans="<?= htmlspecialchars($transKey) ?>"<?= $transFilter === $transKey ? ' aria-current="true"' : '' ?> class="<?= $transFilter === $transKey ? $transActive : $transInactive ?>"><?= htmlspecialchars($transLabel[$transKey] ?? ucfirst($transKey)) ?> (<?= (int)$transCounts[$transKey] ?>)</a>
<?php endforeach; ?>
</div>
</div>
</div>
</section>

<!-- 03. VEHICLES GRID -->
<section class="max-w-container-max-width mx-auto px-6 md:px-12">
<div class="flex items-center justify-between mb-6">
<div>
<h2 class="text-headline-md font-headline-md text-on-surface">Fleet at Dagupan Hub</h2>
</div>
<div class="relative shrink-0">
<button type="button" id="units-ready" class="hidden sm:inline-flex items-center gap-1.5 text-label-sm font-label-sm bg-surface-container hover:bg-surface-container-high px-3 py-1 rounded-full text-primary font-bold transition-colors cursor-pointer" aria-haspopup="true" aria-expanded="false" aria-controls="units-ready-menu">
<span id="units-ready-text"><?= $readyCount ?> Vehicle<?= $readyCount !== 1 ? 's' : '' ?> Ready<?= $reservedCount > 0 ? ' · ' . $reservedCount . ' On Rental' : '' ?></span>
<i id="units-ready-caret" class="bi bi-chevron-down text-[12px] transition-transform duration-200" aria-hidden="true"></i>
</button>
<div id="units-ready-menu" class="hidden absolute right-0 mt-2 w-64 max-h-80 overflow-y-auto bg-surface-container-lowest border border-outline-variant/40 rounded-2xl shadow-xl p-2 z-40" role="menu" aria-labelledby="units-ready">
<p class="px-3 pt-2 pb-1.5 text-label-sm font-label-sm text-tertiary uppercase tracking-wider font-semibold">Fleet by brand</p>
<a href="<?= e(queryUrl(['brand' => null])) ?>" class="veh-brand-option w-full flex items-center justify-between gap-3 px-3 py-2 rounded-xl text-body-sm text-on-surface hover:bg-surface-container transition-colors<?= $brand === '' ? ' bg-primary/10 text-primary font-semibold' : '' ?>" role="menuitem">
<span class="font-medium">All brands</span>
<span class="text-label-sm font-label-sm text-tertiary whitespace-nowrap"><?= (int)$fleetListed ?> listed</span>
</a>
<?php foreach ($brandStats as $brandName => $brandStat): ?>
<a href="<?= e(queryUrl(['brand' => $brandName])) ?>" class="veh-brand-option w-full flex items-center justify-between gap-3 px-3 py-2 rounded-xl text-body-sm text-on-surface hover:bg-surface-container transition-colors<?= $brand === $brandName ? ' bg-primary/10 text-primary font-semibold' : '' ?>" role="menuitem">
<span class="font-medium truncate"><?= htmlspecialchars($brandName) ?></span>
<span class="text-label-sm font-label-sm whitespace-nowrap <?= $brandStat['available'] > 0 ? 'text-emerald-700' : 'text-tertiary' ?>"><?= (int)$brandStat['available'] ?> ready<?php if ($brandStat['total'] > $brandStat['available']): ?> · <?= (int)($brandStat['total'] - $brandStat['available']) ?> out<?php endif; ?></span>
</a>
<?php endforeach; ?>
</div>
</div>
</div>

<?php if ($totalVehicles > 0): ?>
<div id="fleet-card-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
<?php foreach ($cars as $v): ?>
<?php
$group = $groupMap[$v['type']] ?? 'sedan';
$label = $categoryLabel[$v['type']] ?? ucfirst($v['type']);
$rate = (float)$v['daily_rate'];
$trans = strtolower($v['transmission']);
$name = htmlspecialchars($v['year'] . ' ' . $v['brand'] . ' ' . $v['model']);
$cardBrand = trim((string)($v['brand'] ?? '')) !== '' ? trim((string)$v['brand']) : 'Other';
$isAvail = ($v['status'] === 'available');
$isOnRoad = !empty($v['on_road']);
/* A reserved unit (approved booking, keys not collected) and a rented one (out
   on the road) are both 'rented' in the database; only the label differs. */
$statusPillLabel = $isAvail
    ? 'Available Now'
    : ($isOnRoad ? 'Rented · Reserve Only' : 'Reserved · Reserve now');
?>
<div class="veh-card glass-card card-spotlight rounded-3xl overflow-hidden flex flex-col transition-all duration-300 group" data-category="<?= $group ?>" data-brand="<?= htmlspecialchars($cardBrand) ?>" data-price="<?= $rate ?>" data-trans="<?= htmlspecialchars($trans) ?>" data-seats="<?= (int)$v['seats'] ?>" data-available="<?= $v['status'] === 'available' ? 'yes' : 'no' ?>">
<div class="flex flex-col w-full flex-1">
<div class="relative bg-gradient-to-br from-surface-container-low via-primary/5 to-surface-variant aspect-[4/3] overflow-hidden">
<?php
/* Always resolves to a real file: the card derivative, a fallback size, the
   master, or the placeholder. A missing photo is never a broken <img>. */
$thumb = vehicleCardImage($v['image']);
$srcset = vehicleImageSrcset($v['image'], BASE_URL);
/* The card is one of a 3-up grid inside a 92rem container, so it renders at
   roughly 430px wide on desktop and 330px on a phone. Without a matching
   sizes attribute the browser assumes 100vw and keeps fetching the 900px
   candidate, which is where the 2.6MB fleet payload came from. */
$sizes = '(min-width: 1280px) 430px, (min-width: 1024px) 31vw, (min-width: 768px) 45vw, 88vw';
?>
<?php if ($thumb !== ''): ?>
<img class="w-full h-full object-cover object-center relative z-10" src="<?= BASE_URL ?>/<?= htmlspecialchars($thumb) ?>"<?php if ($srcset !== ''): ?> srcset="<?= htmlspecialchars($srcset) ?>" sizes="<?= htmlspecialchars($sizes) ?>"<?php endif; ?> alt="<?= $name ?>" width="900" height="672" loading="lazy" decoding="async">
<?php endif; ?>
<!-- Scrim: keeps the status pills legible over bright body panels. -->
<div class="absolute inset-0 z-10 bg-gradient-to-t from-black/45 via-black/5 to-black/25 pointer-events-none"></div>
<div class="absolute top-3 left-3 flex flex-col gap-1.5 items-start z-20">
<?php if ($isAvail): ?>
<span class="px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-800 text-label-sm font-label-sm uppercase font-bold flex items-center gap-1 shadow-xs">
<span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span> Available Now
</span>
<?php else: ?>
<span class="px-2.5 py-1 rounded-full bg-amber-100 text-amber-800 text-label-sm font-label-sm uppercase font-bold flex items-center gap-1 shadow-xs">
<span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span> <?= htmlspecialchars($statusPillLabel) ?>
</span>
<?php endif; ?>
</div>
<!-- Type chip, bottom-right: fills the corner the crop leaves empty and gives
     the eye a second anchor beside the rate. -->
<span class="absolute bottom-3 right-3 z-20 px-2.5 py-1 rounded-full bg-black/35 backdrop-blur-sm text-white text-label-sm font-label-sm font-semibold capitalize"><?= htmlspecialchars($label) ?></span>
</div>
<div class="p-5 flex-1 flex flex-col justify-between">
<div>
<div class="flex items-center justify-between mb-1">
<span class="text-label-sm font-label-sm text-primary uppercase font-bold"><?= e($label) ?></span>
<span class="text-label-sm font-label-sm text-tertiary"><?= (int)$v['year'] ?> Release</span>
</div>
<h3 class="text-headline-sm font-headline-sm text-on-surface group-hover:text-primary transition-colors"><?= $name ?></h3>
<div class="grid grid-cols-2 gap-2 my-4">
<div class="flex items-center gap-1.5 text-body-sm font-body-sm text-tertiary bg-surface-container-low px-2.5 py-1.5 rounded-xl">
<i aria-hidden="true" class="bi bi-people text-[16px] text-primary"></i>
<span class=""><?= (int)$v['seats'] ?> Passengers</span>
</div>
<div class="flex items-center gap-1.5 text-body-sm font-body-sm text-tertiary bg-surface-container-low px-2.5 py-1.5 rounded-xl">
<i aria-hidden="true" class="bi bi-gear text-[16px] text-primary"></i>
<span class=""><?= htmlspecialchars(ucfirst($v['transmission'])) ?></span>
</div>
<div class="flex items-center gap-1.5 text-body-sm font-body-sm text-tertiary bg-surface-container-low px-2.5 py-1.5 rounded-xl">
<i aria-hidden="true" class="bi bi-fuel-pump text-[16px] text-primary"></i>
<span class=""><?= htmlspecialchars(ucfirst($v['fuel_type'])) ?></span>
</div>
<div class="flex items-center gap-1.5 text-body-sm font-body-sm text-tertiary bg-surface-container-low px-2.5 py-1.5 rounded-xl">
<i aria-hidden="true" class="bi bi-signpost-split text-[16px] text-primary"></i>
<span class="">Unlimited Mileage</span>
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
<?php /* A reserved or rented unit links to the same detail page, but the CTA is
       "Reserve now" rather than "Rent": the car is spoken for, so it is only
       bookable for a later window. Both words are in the accessible name
       because the button is read out of context by a screen reader. */ ?>
<a href="<?= htmlspecialchars(BASE_URL); ?>/customer/vehicle-details.php?id=<?= (int)$v['id'] ?>" aria-label="<?= $isAvail ? 'Rent' : 'Reserve now' ?> this <?= htmlspecialchars($v['year'] . ' ' . $v['brand'] . ' ' . $v['model']) ?>" class="px-4 py-2.5 rounded-full <?= $isAvail ? 'bg-primary hover:bg-secondary text-on-primary' : 'bg-surface-container-high hover:bg-surface-container text-on-surface-variant' ?> font-label-md text-label-md font-semibold <?= $isAvail ? 'pill-accent-glow' : '' ?> active:scale-95 transition-all inline-flex items-center">
<span><?= $isAvail ? 'Rent' : 'Reserve now' ?></span>
</a>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php /* Below the grid rather than in a toolbar so it reads as part of the
         result set; renderPagination keeps brand/type/trans/search on every
         page link. */ ?>
<div class="mt-8">
<?= renderPagination($pg, ['summary' => true, 'noun' => 'vehicles']) ?>
</div>
<?php else: ?>
<div class="text-center py-12 bg-surface-container-lowest rounded-3xl border border-outline-variant/60">
<i aria-hidden="true" class="bi bi-search empty-state-icon text-[48px] text-outline mb-4 block"></i>
<p class="text-headline-sm font-headline-sm text-on-surface mb-2">No vehicles found</p>
<p class="text-body-md font-body-md text-tertiary mb-6">No vehicles match your current filters. Try adjusting your search criteria.</p>
<a href="<?= htmlspecialchars(BASE_URL); ?>/customer/vehicles.php" class="px-6 py-3 rounded-full bg-primary text-on-primary font-label-lg font-bold shadow-luxury-glow hover:bg-secondary active:scale-95 transition-all inline-flex items-center gap-2">
<i aria-hidden="true" class="bi bi-arrow-clockwise text-base"></i>
<span>View All Vehicles</span>
</a>
</div>
<?php endif; ?>
</section>

<!-- 05. SMART LOCKER PICKUP WORKFLOW -->
<section class="max-w-container-max-width mx-auto px-6 md:px-12 mt-16">
<div class="text-center max-w-2xl mx-auto mb-10">
<span class="text-label-sm font-label-sm text-primary uppercase font-bold tracking-wider">Zero-Friction Technology</span>
<h2 class="text-headline-lg font-headline-lg text-on-surface mt-1">Physical Key Handover at Dagupan Central Hub Counter</h2>
<p class="text-body-md font-body-md text-tertiary mt-2">Pick up or return any car 24 hours a day at AB Fernandez Ave with streamlined counter dispatch.</p>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
<div class="step-flow-card bg-surface-container-lowest p-6 rounded-3xl border border-outline-variant/30 flex items-start gap-4 shadow-xs">
<div class="step-flow-icon w-12 h-12 rounded-2xl bg-surface-container flex items-center justify-center text-primary font-bold text-headline-sm shrink-0">01</div>
<div>
<h3 class="text-headline-sm font-headline-sm text-on-surface mb-1">Book &amp; OTC Settlement</h3>
<p class="text-body-sm font-body-sm text-tertiary">Present your valid driver's license and settle payment via Cash OTC at Counter on Pickup in under 2 minutes.</p>
</div>
</div>
<div class="step-flow-card bg-surface-container-lowest p-6 rounded-3xl border border-outline-variant/30 flex items-start gap-4 shadow-xs">
<div class="step-flow-icon w-12 h-12 rounded-2xl bg-surface-container flex items-center justify-center text-primary font-bold text-headline-sm shrink-0">02</div>
<div>
<h3 class="text-headline-sm font-headline-sm text-on-surface mb-1">On-Site Counter Dispatch</h3>
<p class="text-body-sm font-body-sm text-tertiary">Meet our counter concierge at AB Fernandez station for vehicle inspection and instant physical key handover.</p>
</div>
</div>
<div class="step-flow-card bg-surface-container-lowest p-6 rounded-3xl border border-outline-variant/30 flex items-start gap-4 shadow-xs">
<div class="step-flow-icon w-12 h-12 rounded-2xl bg-surface-container flex items-center justify-center text-primary font-bold text-headline-sm shrink-0">03</div>
<div>
<h3 class="text-headline-sm font-headline-sm text-on-surface mb-1">Drive &amp; Counter Return</h3>
<p class="text-body-sm font-body-sm text-tertiary">Explore Pangasinan freely. Hand the keys directly to the counter upon return with prompt mileage and fuel check.</p>
</div>
</div>
</div>
</section>

</main>

<script>
(function(){
    /* Filtering, pill state and the ready badge are all server-rendered now,
       so the only behaviour left in JS is opening and closing the brand menu. */
    var unitsReadyBtn = document.getElementById('units-ready');
    var unitsReadyMenu = document.getElementById('units-ready-menu');
    var unitsReadyCaret = document.getElementById('units-ready-caret');

    function closeBrandMenu(){
        if (!unitsReadyMenu) return;
        unitsReadyMenu.classList.add('hidden');
        if (unitsReadyBtn) unitsReadyBtn.setAttribute('aria-expanded', 'false');
        if (unitsReadyCaret) unitsReadyCaret.classList.remove('rotate-180');
    }

    function toggleBrandMenu(){
        if (!unitsReadyMenu) return;
        var isHidden = unitsReadyMenu.classList.contains('hidden');
        unitsReadyMenu.classList.toggle('hidden', !isHidden);
        if (unitsReadyBtn) unitsReadyBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        if (unitsReadyCaret) unitsReadyCaret.classList.toggle('rotate-180', isHidden);
    }

    if (unitsReadyBtn) {
        unitsReadyBtn.addEventListener('click', function(e){
            e.stopPropagation();
            toggleBrandMenu();
        });
    }
    if (unitsReadyMenu) {
        unitsReadyMenu.addEventListener('click', function(e){ e.stopPropagation(); });
    }
    document.addEventListener('click', closeBrandMenu);
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') closeBrandMenu();
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of customer/vehicles.php */
?>