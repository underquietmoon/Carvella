<?php
/* auth/login.php - Customer login form and authentication handler. */
$pageTitle = 'Member Login | CARVELLA Dagupan Hub';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
redirectIfLoggedIn();

$error = '';
$email = '';
$email_flagged = false;
$password_flagged = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password';
        $email_flagged = $email === '';
        $password_flagged = $password === '';
    } else {
        $lockedFor = login_is_locked($email);
        if ($lockedFor > 0) {
            $error = 'Too many failed attempts. Please try again in ' . ceil($lockedFor / 60) . ' minute(s).';
        } else {
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // One generic message for every failure mode, so a distinct reply cannot reveal admin addresses.
            $failure = 'Invalid email or password.';

            if (!$user || !password_verify($password, $user['password'])) {
                login_throttle_hit($email);
                $error = $failure;
            } elseif (($user['status'] ?? 'active') !== 'active') {
                login_throttle_hit($email);
                $error = $failure;
            } else {
                login_throttle_clear($email);
                // loginUser() first: logActivity() attributes the entry to $_SESSION['user_id'].
                loginUser($user);
                logActivity('login', 'Member signed in');
                if ($user['role'] === 'admin') {
                    redirect(BASE_URL . '/admin/dashboard.php');
                } elseif (in_array($user['role'] ?? '', maintenanceRoles(), true)) {
                    redirect(BASE_URL . '/maintenance/dashboard.php');
                }
                redirect(BASE_URL . '/customer/dashboard.php');
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<main id="main-content" tabindex="-1" class="flex-grow flex items-center justify-center px-4 py-14 md:py-20 relative overflow-hidden">
<div class="auth-card w-full max-w-xl rounded-3xl overflow-hidden">
<div class="flex justify-center pt-8 md:pt-10 px-6">
<a href="<?= BASE_URL ?>/index.php" class="group inline-flex" aria-label="CARVELLA home">
<!-- Navbar pair, not the inverse: dark-ink vanishes on a dark card, and the display swap causes no flash. -->
<img class="h-16 w-auto object-contain transition-transform duration-300 group-hover:scale-105 theme-logo-light" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO.png?v=2" alt="CARVELLA" width="718" height="548" loading="eager">
<img class="h-16 w-auto object-contain transition-transform duration-300 group-hover:scale-105 theme-logo-dark" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO white.png?v=2" alt="CARVELLA" width="718" height="548" loading="eager">
</a>
</div>
<div class="px-6 pb-8 pt-6 md:px-12 md:pb-12">
<?php if ($error): ?>
<div class="auth-alert auth-alert--error mb-6" role="alert">
<i class="bi bi-exclamation-circle text-lg shrink-0 mt-0.5" aria-hidden="true"></i>
<span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>
<div class="mb-6">
<h1 class="text-headline-xl font-headline-xl text-on-surface tracking-tight mb-2">Welcome Back</h1>
<p class="text-body-md font-body-md text-on-surface-variant">Sign in to manage your Pangasinan reservations, view your digital key passes, and access contactless fleet mobility.</p>
</div>
<nav class="mb-8" aria-label="Account">
<div class="auth-tabs w-full sm:w-auto">
<a href="<?= BASE_URL ?>/auth/login.php" aria-current="page">
<i class="bi bi-box-arrow-in-right text-base" aria-hidden="true"></i>Sign In
</a>
<a href="<?= BASE_URL ?>/auth/register.php">
<i class="bi bi-person-plus text-base" aria-hidden="true"></i>Register
</a>
</div>
</nav>
<form method="POST" action="" class="space-y-5" id="login-form" novalidate>
<?= csrf_field() ?>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="email">Email Address</label>
<div class="auth-field<?= $email_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-envelope auth-field__icon" aria-hidden="true"></i>
<input type="email" id="email" name="email" placeholder="you@example.com" value="<?= e($email) ?>" autocomplete="email" maxlength="255" required<?= $email_flagged ? ' aria-invalid="true"' : '' ?>>
</div>
</div>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="password">Password</label>
<div class="auth-field<?= $password_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-lock auth-field__icon" aria-hidden="true"></i>
<input type="password" id="password" name="password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" autocomplete="current-password" maxlength="200" required<?= $password_flagged ? ' aria-invalid="true"' : '' ?>>
<button type="button" class="auth-reveal" data-reveal-for="password" aria-label="Show password" aria-pressed="false">
<i class="bi bi-eye text-[20px]" aria-hidden="true"></i>
</button>
</div>
</div>
<!-- Above the submit rather than beside the field: the field is a pill that
     already has the reveal button in it, and a third element there crowds
     the label on a narrow phone. -->
<p class="text-right -mt-2 mb-0">
<a href="<?= BASE_URL ?>/auth/forgot-password.php" class="text-body-sm font-body-sm font-semibold text-primary hover:underline">Forgot password?</a>
</p>
<button type="submit" class="auth-submit -mt-3">
<span class="auth-submit__idle">Sign In to Carvella</span>
<span class="auth-submit__busy">
<i class="bi bi-arrow-clockwise text-[20px] auth-spin" aria-hidden="true"></i>Signing in&hellip;
</span>
</button>
</form>
<!-- Matches register.php: the tab pill above already carries "Register",
     so this line is only the way out of the form. -->
<p class="text-center text-body-sm font-body-sm text-outline mt-6 mb-0">
<a href="<?= BASE_URL ?>/index.php" class="text-primary font-semibold hover:underline">Back to home</a>
</p>
</div>
</div>
</main>
<script>
/* Password reveal. aria-pressed carries the state so the label and the
   icon cannot disagree, and it is read as a toggle rather than an action. */
document.querySelectorAll('[data-reveal-for]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-reveal-for'));
        if (!input) return;
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        var icon = btn.querySelector('.bi');
        if (icon) {
          icon.classList.toggle('bi-eye-slash', hidden);
          icon.classList.toggle('bi-eye', !hidden);
        }
    });
});

/* Sign-in: an empty field turns red on click/Enter instead of sending the
   form. The check runs on window's capture phase, ahead of the shared
   double-submit lock in main.js, so a blocked submit never leaves the
   button disabled. */
(function () {
    var form = document.getElementById('login-form');
    if (!form) return;

    var items = ['email', 'password'].map(function (id) {
        var input = document.getElementById(id);
        return input ? { input: input, field: input.closest('.auth-field') } : null;
    }).filter(Boolean);

    function isEmpty(input) {
        return input.id === 'password' ? input.value === '' : input.value.trim() === '';
    }

    function paint(item, invalid) {
        if (item.field) item.field.classList.toggle('auth-field--invalid', invalid);
        item.input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
    }

    window.addEventListener('submit', function (event) {
        if (event.target !== form) return;

        var firstEmpty = null;
        items.forEach(function (item) {
            var empty = isEmpty(item.input);
            paint(item, empty);
            if (empty && !firstEmpty) firstEmpty = item.input;
        });

        if (firstEmpty) {
            event.preventDefault();
            event.stopPropagation();
            firstEmpty.focus();
        }
    }, true);

    items.forEach(function (item) {
        item.input.addEventListener('input', function () {
            if (!isEmpty(item.input)) paint(item, false);
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of auth/login.php */
?>