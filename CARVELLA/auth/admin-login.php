<?php
/* auth/admin-login.php - Admin login form and authentication handler. */
$pageTitle = 'Admin Login | CARVELLA Fleet Console';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
redirectIfLoggedIn();

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $lockedFor = login_is_locked($email);
    if ($lockedFor > 0) {
        $error = 'Too many failed attempts. Please try again in ' . ceil($lockedFor / 60) . ' minute(s).';
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // One response for every failure mode, so no reply reveals which addresses hold an admin account.
        $failure = 'Invalid email or password.';

        if (!$user || !password_verify($password, $user['password'])) {
            login_throttle_hit($email);
            $error = $failure;
        } elseif ($user['role'] !== 'admin' || ($user['status'] ?? 'active') !== 'active') {
            login_throttle_hit($email);
            $error = $failure;
        } else {
            login_throttle_clear($email);
            // loginUser() first: logActivity() attributes the entry to $_SESSION['user_id'].
            loginUser($user);
            logActivity('login', 'Administrator signed in');
            redirect(BASE_URL . '/admin/dashboard.php');
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<main id="main-content" tabindex="-1" class="flex-grow flex items-center justify-center px-4 py-16 md:py-24 relative overflow-hidden">
<div class="absolute -top-24 -right-24 w-96 h-96 bg-primary-container/30 rounded-full blur-3xl pointer-events-none"></div>
<div class="absolute -bottom-24 -left-24 w-96 h-96 bg-secondary-fixed/40 rounded-full blur-3xl pointer-events-none"></div>
<div class="auth-card relative w-full max-w-xl bg-surface-container-lowest rounded-3xl border border-outline-variant/60 shadow-luxury-2 p-6 md:p-12">
<?php if ($error): ?>
<div class="mb-6 flex items-center gap-3 px-4 py-3 rounded-2xl bg-error-container text-on-error-container text-body-sm font-body-sm">
<i aria-hidden="true" class="bi bi-exclamation-circle text-lg"></i>
<span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>
<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-secondary-fixed text-on-secondary-fixed font-label-sm text-label-sm uppercase tracking-wider">
<i aria-hidden="true" class="bi bi-shield-lock text-sm"></i>Taskar Headquarters &bull; Restricted Access
</span>
<h1 class="text-headline-xl font-headline-xl text-on-surface tracking-tight mt-2 mb-2">CARVELLA Fleet Console</h1>
<p class="text-body-md font-body-md text-on-surface-variant max-w-xl mb-6">Restricted administrator sign-in for fleet operations, reservations, maintenance, and reporting.</p>
<div class="mb-8">
<span class="inline-flex px-6 py-2 rounded-full bg-primary text-on-primary font-label-lg font-label-lg font-semibold shadow-sm items-center justify-center gap-2">
<i aria-hidden="true" class="bi bi-shield-lock text-base"></i>Admin Sign In
</span>
</div>
<form method="POST" action="" class="space-y-5" novalidate>
<?= csrf_field() ?>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="email">Administrator Email</label>
<div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
<input type="email" id="email" name="email" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" placeholder="admin@carvella.com" value="<?= e($email) ?>" autocomplete="email" maxlength="255" required>
</div>
</div>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="password">Admin Password</label>
<div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
<input type="password" id="password" name="password" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" autocomplete="current-password" maxlength="200" required>
<button type="button" aria-label="Show password" class="text-outline hover:text-primary transition-colors">
<i class="bi bi-eye text-[20px]" aria-hidden="true"></i>
</button>
</div>
</div>
<button type="submit" class="w-full py-3.5 rounded-full bg-primary text-on-primary font-label-lg text-label-lg font-bold shadow-luxury-glow hover:bg-secondary transition-all active:scale-[0.99]">
Enter Fleet Console
</button>
</form>
<p class="text-center text-body-sm font-body-sm text-on-surface-variant mt-4 mb-0">
<a href="<?= BASE_URL ?>/auth/forgot-password.php" class="font-semibold text-primary hover:underline">Forgot password?</a>
</p>
<p class="text-center text-body-md font-body-md text-on-surface-variant mt-4 mb-0">Not an administrator? <a href="<?= BASE_URL ?>/auth/login.php" class="text-primary font-bold hover:underline">Client login here</a></p>
</div>
</main>
<script>
document.querySelectorAll('button[aria-label="Show password"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = this.closest('div').querySelector('input');
        var icon = this.querySelector('.bi');
        var hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        this.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
        if (icon) {
          icon.classList.toggle('bi-eye-slash', hidden);
          icon.classList.toggle('bi-eye', !hidden);
        }
    });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of auth/admin-login.php */
?>