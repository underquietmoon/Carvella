<?php
/* customer/profile.php - Customer profile view and update form. */

$pageTitle = 'My Profile - Carvella';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['update_profile'])) {
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $address = sanitize($_POST['address'] ?? '');

        if ($first_name === '' || $last_name === '') {
            $error = 'First and last name are required';
        } elseif (mb_strlen($first_name) > 100 || mb_strlen($last_name) > 100) {
            $error = 'Names must be 100 characters or fewer';
        } elseif (!preg_match('/^[0-9]{11}$/', $phone)) {
            // Digits-only 11-digit number, identical to the registration rule.
            $error = 'Please enter an 11-digit mobile number (e.g. 09171234567)';
        } elseif (mb_strlen($address) > 255) {
            $error = 'Address must be 255 characters or fewer';
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, address = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $first_name, $last_name, $phone, $address, $userId);

            if ($stmt->execute()) {
                $success = 'Profile updated successfully';
                $_SESSION['name'] = $first_name . ' ' . $last_name;
            } else {
                // Surfaced rather than swallowed: a silent failure here left the
                // form reporting nothing while the change was never written.
                $error = 'Failed to update profile. Please try again.';
            }
            $stmt->close();
        }
    }

    if (isset($_POST['change_password'])) {
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // The session can outlive the row (an admin deletes the account while
        // the cookie is still live). password_verify() is typed string in
        // PHP 8, so passing the null from a missing row was a TypeError and a
        // blank page rather than a message.
        if (!$user || !isset($user['password'])) {
            destroySession();
            setFlash('error', 'Your account is no longer available. Please sign in again.');
            redirect(BASE_URL . '/auth/login.php');
        }

        // minlength="6" on the input is a hint to the browser, not a check.
        // Without this a crafted POST set a one-character password.
        if (strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters';
        } elseif (!password_verify($current_password, $user['password'])) {
            $error = 'Current password is incorrect';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match';
        } elseif ($new_password === $current_password) {
            $error = 'New password must be different from your current password';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed, $userId);
            // execute() returning true only means the statement was accepted.
            // affected_rows is what proves a row was actually written.
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = 'Password changed successfully';
            } else {
                $error = 'Failed to change password. Please try again.';
            }
            $stmt->close();
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // Same reasoning as above, on the read path this time: rendering
    // $user['first_name'] against a missing row emitted a warning and an empty
    // profile. Ending the session is the honest outcome.
    destroySession();
    setFlash('error', 'Your account is no longer available. Please sign in again.');
    redirect(BASE_URL . '/auth/login.php');
}

$initial = strtoupper(substr((string)($user['first_name'] ?? ''), 0, 1));
$memberYear = date('Y', strtotime($user['created_at'] ?? 'now'));

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<main id="main-content" tabindex="-1" class="flex-grow pt-28 pb-20 px-4 sm:px-6 md:px-12 max-w-container-max-width mx-auto w-full">

  <!-- Breadcrumb -->
  <nav class="flex items-center gap-2 text-label-sm font-label-sm text-tertiary mb-3">
    <a class="hover:text-primary transition-colors" href="<?php echo BASE_URL; ?>/customer/dashboard.php">Customer Portal</a>
    <i aria-hidden="true" class="bi bi-chevron-right text-xs"></i>
    <span class="text-on-surface font-semibold">My Profile</span>
  </nav>

  <!-- Page Header -->
  <section class="mb-8">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
      <div>
        <h1 class="text-headline-xl font-headline-xl text-on-surface tracking-tight">My Profile</h1>
        <p class="text-body-md font-body-md text-tertiary mt-1">Manage your personal information, contact details, and account security.</p>
      </div>
    </div>
  </section>

  <!-- Flash Alerts -->
  <?php if ($error): ?>
    <div class="mb-6 bg-error-container text-on-error-container rounded-2xl px-4 py-3 flex items-center gap-3 text-body-sm">
      <i aria-hidden="true" class="bi bi-exclamation-circle text-xl"></i>
      <?php echo htmlspecialchars($error); ?>
    </div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="mb-6 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-2xl px-4 py-3 flex items-center gap-3 text-body-sm">
      <i aria-hidden="true" class="bi bi-check-circle text-xl"></i>
      <?php echo htmlspecialchars($success); ?>
    </div>
  <?php endif; ?>

  <!-- Identity Card & Stats Row -->
  <section class="mb-8">
    <div class="rounded-3xl bg-surface-container-lowest border border-outline-variant/70 shadow-luxury-1 p-6 md:p-8">
      <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6">
        <!-- Initials Avatar -->
        <div class="w-20 h-20 rounded-full bg-secondary text-on-secondary flex items-center justify-center text-headline-lg font-headline-lg font-extrabold flex-shrink-0 shadow-luxury-glow">
          <?php echo htmlspecialchars($initial); ?>
        </div>
        <!-- Name & Details -->
        <div class="flex-1 text-center sm:text-left">
          <h2 class="text-headline-md font-headline-md text-on-surface font-bold">
            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
          </h2>
          <div class="flex flex-wrap items-center justify-center sm:justify-start gap-3 mt-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-container-lowest border border-outline-variant/60 text-body-sm font-body-sm text-on-surface">
              <i aria-hidden="true" class="bi bi-envelope text-primary text-base"></i>
              <?php echo htmlspecialchars($user['email']); ?>
            </span>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-surface-container-lowest border border-outline-variant/60 text-body-sm font-body-sm text-on-surface">
              <i aria-hidden="true" class="bi bi-award text-primary text-base"></i>
              Member Since <strong><?php echo htmlspecialchars($memberYear); ?></strong>
            </span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Account Information Form -->
  <section class="mb-8">
    <div class="rounded-3xl bg-surface-container-lowest border border-outline-variant/70 shadow-luxury-1 overflow-hidden">
      <div class="px-6 md:px-8 pt-6 md:pt-8 pb-4">
        <div class="flex items-center gap-3">
          <span class="w-12 h-12 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center">
            <i aria-hidden="true" class="bi bi-person text-[22px]"></i>
          </span>
          <div>
            <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Account Information</h2>
            <p class="text-body-sm font-body-sm text-tertiary">Update your personal details and contact information.</p>
          </div>
        </div>
      </div>
      <form method="POST" class="px-6 md:px-8 pb-8">
<?= csrf_field() ?>
        <input type="hidden" name="update_profile" value="1">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <!-- First Name -->
          <div>
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">First Name</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-person-badge text-[20px] text-outline"></i>
              <input type="text" name="first_name" maxlength="100" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
            </div>
          </div>
          <!-- Last Name -->
          <div>
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Last Name</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-person-badge text-[20px] text-outline"></i>
              <input type="text" name="last_name" maxlength="100" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
            </div>
          </div>
          <!-- Email (read-only) -->
          <div>
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Email</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-high/50 border border-outline-variant/50 opacity-70 cursor-not-allowed">
              <i aria-hidden="true" class="bi bi-envelope text-[20px] text-outline"></i>
              <input type="email" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface-variant placeholder:text-outline" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
            </div>
          </div>
          <!-- Phone -->
          <div>
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Phone</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-telephone text-[20px] text-outline"></i>
              <input type="tel" name="phone" inputmode="numeric" maxlength="11" placeholder="09171234567" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
            </div>
          </div>
          <!-- Address -->
          <div class="md:col-span-2">
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Address</label>
            <div class="flex items-start gap-3 px-4 py-3.5 rounded-2xl bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-geo-alt-fill text-[20px] text-outline mt-1"></i>
              <textarea name="address" maxlength="255" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline resize-none" rows="3"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
            </div>
          </div>
        </div>
        <!-- Save Button -->
        <div class="mt-6 flex items-center gap-3">
          <button type="submit" class="px-6 py-3 rounded-full bg-primary text-on-primary font-label-lg font-bold shadow-luxury-glow hover:bg-secondary active:scale-95 transition-all inline-flex items-center gap-2">
            <i aria-hidden="true" class="bi bi-floppy text-base"></i>
            Update Profile
          </button>
        </div>
      </form>
    </div>
  </section>

  <!-- Change Password Section -->
  <section class="mb-8">
    <div class="rounded-3xl bg-surface-container-lowest border border-outline-variant/70 shadow-luxury-1 overflow-hidden">
      <div class="px-6 md:px-8 pt-6 md:pt-8 pb-4">
        <div class="flex items-center gap-3">
          <span class="w-12 h-12 rounded-2xl bg-primary/10 text-primary inline-flex items-center justify-center">
            <i aria-hidden="true" class="bi bi-shield text-[22px]"></i>
          </span>
          <div>
            <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Change Password</h2>
            <p class="text-body-sm font-body-sm text-tertiary">Secure your account with a strong password.</p>
          </div>
        </div>
      </div>
      <form method="POST" class="px-6 md:px-8 pb-8">
<?= csrf_field() ?>
        <input type="hidden" name="change_password" value="1">
        <div class="space-y-5">
          <!-- Current Password -->
          <div>
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Current Password</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-lock text-[20px] text-outline"></i>
              <input type="password" name="current_password" maxlength="200" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" placeholder="Enter current password" required>
            </div>
          </div>
          <!-- New Password -->
          <div>
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">New Password</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-key text-[20px] text-outline"></i>
              <input type="password" name="new_password" autocomplete="new-password" maxlength="200" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" placeholder="Enter new password" required minlength="8">
            </div>
          </div>
          <!-- Confirm New Password -->
          <div>
            <label class="block text-label-lg font-label-lg text-on-surface mb-2">Confirm New Password</label>
            <div class="flex items-center gap-3 px-4 py-3.5 rounded-full bg-surface-container-low border border-outline-variant/70 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15 transition-all">
              <i aria-hidden="true" class="bi bi-key text-[20px] text-outline"></i>
              <input type="password" name="confirm_password" autocomplete="new-password" maxlength="200" class="w-full bg-transparent border-0 p-0 focus:ring-0 focus:border-0 text-body-md text-on-surface placeholder:text-outline" placeholder="Re-enter new password" required minlength="8">
            </div>
          </div>
        </div>
        <!-- Change Password Button -->
        <div class="mt-6 flex items-center gap-3">
          <button type="submit" class="px-6 py-3 rounded-full bg-surface-container-lowest border border-outline-variant/70 text-on-surface-variant hover:bg-surface-container hover:text-on-surface font-label-lg font-bold transition-all inline-flex items-center gap-2">
            <i aria-hidden="true" class="bi bi-arrow-counterclockwise text-base"></i>
            Change Password
          </button>
        </div>
      </form>
    </div>
    </section>

    <!-- Appearance: the theme toggle rendered from the customer's own theme
         artwork, given room to read as a design piece rather than a utility.
         The control is a picture of the toggle labelled for the current
         theme - the light render in light mode, the dark render in dark mode
         - so the active mode is spelled out by the image itself. -->
    <section class="mt-6">
      <div class="rounded-3xl bg-surface-container-lowest border border-outline-variant/40 overflow-hidden">
        <div class="px-6 py-5 border-b border-outline-variant/25 flex items-center gap-2">
          <i aria-hidden="true" class="bi bi-palette text-[20px] text-primary"></i>
          <h2 class="text-headline-sm font-headline-sm font-bold text-on-surface">Appearance</h2>
        </div>

        <div class="p-5 sm:p-6 flex flex-col sm:flex-row sm:items-center gap-5">
          <div class="shrink-0 flex items-center justify-center">
            <?php $themeToggleVariant = 'img'; $themeToggleClass = 'theme-toggle--img-hero'; include __DIR__ . '/../includes/theme-toggle.php'; ?>
          </div>

          <div class="flex-1 min-w-0">
            <p class="text-body-md font-body-md font-semibold text-on-surface">
              <span class="car-label--day">Daylight</span>
              <span class="car-label--night">Night</span>
            </p>
            <p class="mt-1 text-body-sm font-body-sm text-on-surface-variant">
              Switch between the light and dark look of Carvella. Your choice is
              remembered on this device.
            </p>
          </div>

          <div class="shrink-0 flex items-center gap-1.5 p-1 rounded-full bg-surface-container-low border border-outline-variant/40" role="group" aria-label="Theme">
            <span class="car-mode-pill car-mode-pill--day" data-car-pill="light">Day</span>
            <span class="car-mode-pill car-mode-pill--night" data-car-pill="dark">Night</span>
          </div>
        </div>
      </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of customer/profile.php */
?>
