<?php
/* includes/theme-toggle.php - Dark/light theme toggle widget. */
/**
 * Theme toggle.
 *
 * Four presentations, because the control lives in very different contexts
 * and one shape does not suit all of them:
 *
 *   'car' (default) - an inline-SVG car in side profile, used on the
 *       customer's profile page as a design piece. Headlamps burn warm in
 *       light mode and the rear lamp lights up in dark mode, so the toggle
 *       states the current theme as a scene rather than as an abstract
 *       sun/moon. Drawn as SVG so it never depends on the icon font or on a
 *       font CDN being reachable.
 *   'img' - the admin navbar beside the notification bell. The control
 *       itself is an image: a rendered toggle labelled for the light theme
 *       in light mode and one labelled for the dark theme in dark mode, so
 *       the current mode is spelled out by the picture. Both states are the
 *       same button swapped purely off the .dark class.
 *   'switch' - the labelled sun/moon pill. The default button for dark and
 *       light: a plain switch-style pill with a Light/Dark label - no car,
 *       no images - used everywhere outside the admin image toggle.
 *   'fab' - a compact rounded-square button (four corners, dashed
 *       hairline) pinned above the back-to-top control on public pages,
 *       styled as a matched pair with it. A switch pill in the navbar
 *       crowded the header and read as a second primary action, so the
 *       public control stays out of the navigation entirely.
 *
 * In every case the resting state is pure CSS off the .dark class (see
 * theme.css), so the control still works before or without JavaScript;
 * app-motion.js only layers a spring on the icon morph.
 *
 * @var string $themeToggleVariant 'car', 'img', 'switch' or 'fab'.
 * @var string $themeToggleClass   Extra classes for layout context.
 */
$themeToggleVariant = $themeToggleVariant ?? 'car';
$themeToggleClass   = $themeToggleClass   ?? '';
$isFab    = $themeToggleVariant === 'fab';
$isSwitch = $themeToggleVariant === 'switch';
?>

<?php if ($themeToggleVariant === 'car'): ?>
<button type="button" data-theme-toggle
        class="theme-toggle theme-toggle--car <?= htmlspecialchars($themeToggleClass) ?>"
        aria-label="Switch to dark theme"
        title="Switch theme"
        aria-pressed="false">
    <span class="theme-toggle__car" aria-hidden="true">
        <svg viewBox="0 0 40 30" fill="none" xmlns="http://www.w3.org/2000/svg" class="car-svg">
            <path class="car-body" d="M6.0 4.4L7.4 3.6Q10 2.2 14 1.8L26 1.8Q30 2.2 32.6 3.6L34 4.4L36.6 6.0Q38.6 7.4 38.8 10.0L39.0 18.0Q39.2 21 38.2 23.4Q36.8 26.2 34 27.6Q30.4 29.4 24 29.8Q20 30.0 16 29.8Q9.6 29.4 6 27.6Q3.2 26.2 1.8 23.4Q0.8 21 1.0 18.0L1.2 10.0Q1.4 7.4 3.4 6.0Z"/>
            <path class="car-crease" d="M12.8 2.6L17.6 3.8L17.8 4.4L12.6 3.4Z"/>
            <path class="car-crease" d="M27.2 2.6L22.4 3.8L22.2 4.4L27.4 3.4Z"/>
            <path class="car-duct" d="M3.2 15.8L4.8 15.2L5.4 20.4L4.8 22.0Q4.0 22.4 3.4 21.6L3.0 20.2Z"/>
            <path class="car-duct" d="M36.8 15.8L35.2 15.2L34.6 20.4L35.2 22.0Q36.0 22.4 36.6 21.6L37.0 20.2Z"/>
            <rect class="car-kidney" x="13.0" y="3.8" width="14.0" height="17.6" rx="2.8"/>
            <rect class="car-grille" x="13.8" y="4.6" width="5.4" height="16.0" rx="1.8"/>
            <rect class="car-grille" x="20.8" y="4.6" width="5.4" height="16.0" rx="1.8"/>
            <rect class="car-slat" x="14.7" y="5.8" width="1.6" height="13.6" rx="0.8"/>
            <rect class="car-slat" x="16.7" y="5.8" width="1.6" height="13.6" rx="0.8"/>
            <rect class="car-slat" x="21.7" y="5.8" width="1.6" height="13.6" rx="0.8"/>
            <rect class="car-slat" x="23.7" y="5.8" width="1.6" height="13.6" rx="0.8"/>
            <path class="car-intake" d="M8.8 22.0L9.6 21.4L30.4 21.4L31.2 22.0L31.6 23.4Q31.4 24.8 30.0 25.6L10.0 25.6Q8.6 24.8 8.4 23.4Z"/>
            <path class="car-splitter" d="M2.6 27.2L3.2 25.9L7.0 25.6L11.2 26.8L15.0 27.6L20 27.9L25.0 27.6L28.8 26.8L33.0 25.6L36.8 25.9L37.4 27.2Q34.6 29.8 27 29.9Q20 30.0 13 29.9Q5.4 29.8 2.6 27.2Z"/>
            <ellipse class="car-halo" cx="8.8" cy="9.6" rx="5.2" ry="3.6"/>
            <ellipse class="car-halo" cx="31.2" cy="9.6" rx="5.2" ry="3.6"/>
            <path class="car-lamp" d="M4.6 12.0L4.6 8.2L5.6 7.4L11.2 6.6L12.8 7.8L12.8 10.4L12.0 12.0Z"/>
            <path class="car-lamp" d="M35.4 12.0L35.4 8.2L34.4 7.4L28.8 6.6L27.2 7.8L27.2 10.4L28.0 12.0Z"/>
            <path class="car-drl" d="M5.0 9.0L10.6 7.2L11.4 8.0L5.4 10.0Z"/>
            <path class="car-drl" d="M35.0 9.0L29.4 7.2L28.6 8.0L34.6 10.0Z"/>
            <circle class="car-core" cx="11.0" cy="9.4" r="1.2"/>
            <circle class="car-core" cx="29.0" cy="9.4" r="1.2"/>
        </svg>
    </span>
</button>
<?php elseif ($themeToggleVariant === 'img'): ?>
<?php
// The 'img' variant was specified to render two photographic toggle images
// from assets/images/theme button/. Neither toggle_light.jpeg nor
// toggle_dark.jpeg exists in the repository, so this variant shipped two
// broken <img> requests and an empty-looking button. Rather than point at
// files that are not there, fall through to the 'switch' presentation, which
// is self-contained and states the current mode in text.
$themeToggleVariant = 'switch';
?>
<button type="button" data-theme-toggle
        class="theme-toggle theme-toggle--switch <?= htmlspecialchars($themeToggleClass) ?>"
        aria-label="Switch to dark theme"
        title="Switch theme"
        aria-pressed="false">
    <span class="theme-toggle__track" aria-hidden="true">
        <span class="theme-toggle__knob"></span>
        <span class="theme-toggle__icons">
            <i aria-hidden="true" class="bi bi-sun theme-toggle__icon theme-toggle__icon--sun"></i>
            <i aria-hidden="true" class="bi bi-moon-stars theme-toggle__icon theme-toggle__icon--moon"></i>
        </span>
    </span>
    <span class="theme-toggle__state" aria-hidden="true">
        <span class="theme-toggle__state--light">Light</span>
        <span class="theme-toggle__state--dark">Dark</span>
    </span>
</button>
<?php else: ?>
<button type="button" data-theme-toggle
        class="theme-toggle theme-toggle--<?= $isFab ? 'fab' : 'switch' ?> <?= htmlspecialchars($themeToggleClass) ?>"
        aria-label="Switch to dark theme"
        title="Switch theme"
        aria-pressed="false">
    <span class="theme-toggle__track" aria-hidden="true">
        <span class="theme-toggle__knob"></span>
        <span class="theme-toggle__icons">
            <i aria-hidden="true" class="bi bi-sun theme-toggle__icon theme-toggle__icon--sun"></i>
            <i aria-hidden="true" class="bi bi-moon-stars theme-toggle__icon theme-toggle__icon--moon"></i>
        </span>
    </span>
    <?php if ($isSwitch): ?>
    <span class="theme-toggle__state" aria-hidden="true">
        <span class="theme-toggle__state--light">Light</span>
        <span class="theme-toggle__state--dark">Dark</span>
    </span>
    <?php endif; ?>
</button>
<?php endif; 
/* End of includes/theme-toggle.php */
?>
