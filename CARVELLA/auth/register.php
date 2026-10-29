<?php
/* auth/register.php - Customer registration form and handler. */
$pageTitle = 'Create Account | CARVELLA Dagupan Hub';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
redirectIfLoggedIn();

$error = '';
$success = '';
$namePattern = "/^(?=.*\p{L})[\p{L}\p{M} ]+$/u";
$addressPattern = '/^[A-Za-z0-9 ,.\-#]+$/';
/* Strict address shape: dot-separated local atoms (no leading, trailing or
   doubled dots), a real dot-separated domain, and 1-63 char labels that
   cannot start or end with a hyphen. TLD is the final label, so a domain
   with no dot (e.g. juan@localhost) is refused. */
$emailPattern = '/^[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*@[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*\.[A-Za-z]{2,}$/';
$first_name_flagged = false;
$last_name_flagged = false;
$email_flagged = false;
$address_flagged = false;
$phone_flagged = false;
$password_flagged = false;
$confirm_password_flagged = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $email = strtolower(sanitize($_POST['email'] ?? ''));
    $phone = sanitize($_POST['phone'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($first_name === '' || $last_name === '' || $email === '' || $phone === '' || $address === '' || $password === '') {
        $error = 'Please fill in all the required fields';
        $first_name_flagged = $first_name === '';
        $last_name_flagged = $last_name === '';
        $email_flagged = $email === '';
        $address_flagged = $address === '';
        $phone_flagged = $phone === '';
        $password_flagged = $password === '';
        $confirm_password_flagged = $confirm_password === '';
    } elseif (empty($_POST['agree_terms'])) {
        $error = 'Please agree to the Terms of Service and Privacy Policy';
    } elseif (strlen($email) > 50) {
        $error = 'Email address must be 50 characters or fewer';
        $email_flagged = true;
    } elseif (!preg_match($emailPattern, $email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = 'Please enter a valid email address (e.g. juan@example.com)';
        $email_flagged = true;
    } elseif (mb_strlen($first_name) > 50 || mb_strlen($last_name) > 50) {
        $error = 'Names must be 50 characters or fewer';
        $first_name_flagged = mb_strlen($first_name) > 50;
        $last_name_flagged = mb_strlen($last_name) > 50;
    } elseif (!preg_match($namePattern, $first_name) || !preg_match($namePattern, $last_name)) {
        $error = 'Names may only contain letters and spaces';
        $first_name_flagged = !preg_match($namePattern, $first_name);
        $last_name_flagged = !preg_match($namePattern, $last_name);
    } elseif (mb_strlen($phone) > 20) {
        $error = 'Please enter a valid mobile number (20 characters or fewer)';
        $phone_flagged = true;
    } elseif (!preg_match('/^[0-9]{11}$/', $phone)) {
        $error = 'Please enter an 11-digit mobile number (e.g. 09171234567)';
        $phone_flagged = true;
    } elseif ($address !== '' && !preg_match($addressPattern, $address)) {
        $error = 'Please enter a valid home address (letters, numbers, spaces, commas, periods, hyphens, and # only)';
        $address_flagged = true;
    } elseif (mb_strlen($address) > 50) {
        $error = 'Address must be 50 characters or fewer';
        $address_flagged = true;
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
        $password_flagged = true;
    } elseif (strlen($password) > 200) {
        $error = 'Password must be 200 characters or fewer';
        $password_flagged = true;
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
        $confirm_password_flagged = true;
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = 'Email already registered';
            $email_flagged = true;
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, phone, address, password, role) VALUES (?, ?, ?, ?, ?, ?, 'customer')");
            $stmt->bind_param("ssssss", $first_name, $last_name, $email, $phone, $address, $hashedPassword);

            if ($stmt->execute()) {
                logActivity('created', "New member account registered for $email");
                $success = 'Registration successful! You can now login.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
            $stmt->close();
        }
        $check->close();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<main id="main-content" tabindex="-1" class="flex-grow flex items-center justify-center px-4 py-14 md:py-20 relative overflow-hidden">
<div class="auth-card w-full max-w-2xl rounded-3xl overflow-hidden">
<div class="flex justify-center pt-8 md:pt-10 px-6">
<a href="<?= BASE_URL ?>/index.php" class="group inline-flex" aria-label="CARVELLA home">
<!-- Same light/dark artwork pair as the navbar and login card: a .dark display swap, so no layout shift. -->
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
<?php if ($success): ?>
<div class="auth-alert auth-alert--ok mb-6" role="status">
<i class="bi bi-check-circle text-lg shrink-0 mt-0.5" aria-hidden="true"></i>
<span><?= htmlspecialchars($success) ?> <a href="<?= BASE_URL ?>/auth/login.php" class="font-bold underline">Login now</a></span>
</div>
<?php endif; ?>
<div class="mb-6">
<span class="auth-eyebrow"><i class="bi bi-shield-check text-[14px]" aria-hidden="true"></i>LTO-Compliant Enrollment</span>
<h1 class="text-headline-xl font-headline-xl text-on-surface tracking-tight mt-3 mb-2">Create Your Account</h1>
<p class="text-body-md font-body-md text-on-surface-variant">Join Carvella to reserve the finest fleet in Dagupan City, track bookings, and unlock VIP renter standing.</p>
</div>
<nav class="mb-8" aria-label="Account">
<div class="auth-tabs w-full sm:w-auto">
<a href="<?= BASE_URL ?>/auth/login.php">
<i class="bi bi-box-arrow-in-right text-base" aria-hidden="true"></i>Sign In
</a>
<a href="<?= BASE_URL ?>/auth/register.php" aria-current="page">
<i class="bi bi-person-plus text-base" aria-hidden="true"></i>Register
</a>
</div>
</nav>
<form method="POST" action="" class="space-y-5" novalidate>
<?= csrf_field() ?>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="first_name">First Name</label>
<div class="auth-field<?= $first_name_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-person auth-field__icon" aria-hidden="true"></i>
<input type="text" id="first_name" name="first_name" autocomplete="given-name" maxlength="50" pattern="[A-Za-zÀ-ÖØ-öø-ÿÑñ ]+" placeholder="Juan" required<?= $first_name_flagged ? ' aria-invalid="true"' : '' ?>>
</div>
</div>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="last_name">Last Name</label>
<div class="auth-field<?= $last_name_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-person auth-field__icon" aria-hidden="true"></i>
<input type="text" id="last_name" name="last_name" autocomplete="family-name" maxlength="50" pattern="[A-Za-zÀ-ÖØ-öø-ÿÑñ ]+" placeholder="Dela Cruz" required<?= $last_name_flagged ? ' aria-invalid="true"' : '' ?>>
</div>
</div>
</div>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="email">Email Address</label>
<div class="auth-field<?= $email_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-envelope auth-field__icon" aria-hidden="true"></i>
<input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" autocapitalize="none" spellcheck="false" inputmode="email" maxlength="50" aria-describedby="email-error" required<?= $email_flagged ? ' aria-invalid="true"' : '' ?>>
</div>
<p class="auth-field-error hidden" id="email-error" role="alert">
<i class="bi bi-exclamation-circle text-[15px]" aria-hidden="true"></i>Enter a valid email address, e.g. juan@example.com
</p>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="phone">Philippine Mobile Number</label>
<div class="auth-field<?= $phone_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-telephone auth-field__icon" aria-hidden="true"></i>
<input type="tel" id="phone" name="phone" autocomplete="tel" inputmode="numeric" pattern="[0-9]*" maxlength="11" placeholder="09171234567" required<?= $phone_flagged ? ' aria-invalid="true"' : '' ?>>
</div>
</div>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="address">Home Address</label>
<div class="auth-field<?= $address_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-geo-alt-fill auth-field__icon" aria-hidden="true"></i>
<input type="text" id="address" name="address" autocomplete="street-address" maxlength="50" placeholder="House no., street, barangay, Dagupan City" required<?= $address_flagged ? ' aria-invalid="true"' : '' ?>>
</div>
</div>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="password">Password</label>
<div class="auth-field<?= $password_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-lock auth-field__icon" aria-hidden="true"></i>
<input type="password" id="password" name="password" autocomplete="new-password" maxlength="200" placeholder="Min. 8 characters" required minlength="8" aria-describedby="pw-strength-label"<?= $password_flagged ? ' aria-invalid="true"' : '' ?>>
<button type="button" class="auth-reveal" data-reveal-for="password" aria-label="Show password" aria-pressed="false">
<i class="bi bi-eye text-[20px]" aria-hidden="true"></i>
</button>
</div>
<!-- Advisory only: the server accepts any 8+ password, so the meter reports but never gates submit. -->
<div class="auth-strength" id="pw-strength" data-score="0" aria-hidden="true">
<span class="auth-strength__seg"></span><span class="auth-strength__seg"></span><span class="auth-strength__seg"></span><span class="auth-strength__seg"></span>
</div>
<span class="auth-strength__label" id="pw-strength-label">Use 8+ characters with a number or symbol.</span>
</div>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="confirm_password">Confirm Password</label>
<div class="auth-field<?= $confirm_password_flagged ? ' auth-field--invalid' : '' ?>" id="confirm-field">
<i class="bi bi-lock auth-field__icon" aria-hidden="true"></i>
<input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" maxlength="200" placeholder="Repeat password" required minlength="8" aria-describedby="pw-match"<?= $confirm_password_flagged ? ' aria-invalid="true"' : '' ?>>
<button type="button" class="auth-reveal" data-reveal-for="confirm_password" aria-label="Show password" aria-pressed="false">
<i class="bi bi-eye text-[20px]" aria-hidden="true"></i>
</button>
</div>
<p class="auth-field-error<?= $confirm_password_flagged ? '' : ' hidden' ?>" id="pw-match" role="alert">
<i class="bi bi-exclamation-circle text-[15px]" aria-hidden="true"></i><span id="pw-match-text">Passwords do not match</span>
</p>
</div>
</div>
<label class="relative flex items-start gap-3 cursor-pointer select-none">
<input type="checkbox" name="agree_terms" class="agree-input" required>
<span class="agree-box"><i aria-hidden="true" class="bi bi-check-lg"></i></span>
<span class="text-body-sm font-body-sm text-on-surface-variant">I agree to the <a href="<?= BASE_URL ?>/terms.php" class="text-primary font-semibold hover:underline" target="_blank" rel="noopener">Terms of Service</a> and acknowledge the <a href="<?= BASE_URL ?>/privacy-policy.php" class="text-primary font-semibold hover:underline" target="_blank" rel="noopener">Privacy Policy</a>.</span>
</label>
<button type="submit" class="auth-submit">
<span class="auth-submit__idle">
<i class="bi bi-person-plus text-[20px]" aria-hidden="true"></i>Create My Account
</span>
<span class="auth-submit__busy">
<i class="bi bi-arrow-clockwise text-[20px] auth-spin" aria-hidden="true"></i>Creating your account&hellip;
</span>
</button>
</form>
<!-- The tab pill above already carries "Sign In", so the old
     "Already a member? Sign in instead" line was a duplicate path to the
     same place. Only the way out of the form is kept here. -->
<p class="text-center text-body-sm font-body-sm text-outline mt-6 mb-0">
<a href="<?= BASE_URL ?>/index.php" class="text-primary font-semibold hover:underline">Back to home</a>
</p>
</div>
</div>
</main>
<script>
/* Password reveal, shared with the login card. */
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

/* Names: strip every symbol/number as it is typed, so only letters (any
   language) and spaces can be entered. The server repeats this check;
   maxlength caps the length at 50. A rejected character is flagged so the
   shared validator below reddens the pill for that keystroke. */
document.querySelectorAll('#first_name, #last_name').forEach(function (input) {
    input.addEventListener('input', function () {
        var raw = input.value;
        var stripped = raw.replace(/[^\p{L}\p{M} ]/gu, '');
        var cleaned = stripped.replace(/ {2,}/g, ' ');
        if (cleaned !== raw) {
            input.value = cleaned;
        }
        if (stripped !== raw) {
            input.dataset.rejectedChar = '1';
        } else {
            delete input.dataset.rejectedChar;
        }
    });
});

/* Email: only allow alphanumeric, dots and @ as special characters. */
document.querySelectorAll('#email').forEach(function (input) {
    input.addEventListener('input', function () {
        var raw = input.value;
        var stripped = raw.replace(/[^A-Za-z0-9.@]/g, '');
        if (stripped !== raw) {
            input.value = stripped;
            input.dataset.rejectedChar = '1';
        } else {
            delete input.dataset.rejectedChar;
        }
    });
});

/* Address: only allow letters, numbers, spaces, commas, periods, hyphens and #. */
document.querySelectorAll('#address').forEach(function (input) {
    input.addEventListener('input', function () {
        var raw = input.value;
        var stripped = raw.replace(/[^A-Za-z0-9 ,.\-#]/g, '');
        if (stripped !== raw) {
            input.value = stripped;
            input.dataset.rejectedChar = '1';
        } else {
            delete input.dataset.rejectedChar;
        }
    });
});
/* One validator for every field: a pill turns red once the field has been
   visited and is empty, or when its value is wrong. The server repeats all
   of this, so nothing here gates submit (that would fight the double-submit
   lock in main.js). */
(function () {
    var EMAIL_RE = /^[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*@[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*\.[A-Za-z]{2,}$/;

    function validEmail(value) {
        if (value.length > 50) return false;
        var at = value.indexOf('@');
        if (at < 1 || at !== value.lastIndexOf('@')) return false;
        return EMAIL_RE.test(value);
    }

    var RULES = {
        first_name: function (value) {
            return value.length <= 50 && /^[\p{L}\p{M} ]+$/u.test(value);
        },
        last_name: function (value) {
            return value.length <= 50 && /^[\p{L}\p{M} ]+$/u.test(value);
        },
        email: validEmail,
        phone: function (value) {
            return /^[0-9]{11}$/.test(value);
        },
        address: function (value) {
            return value.trim() !== '' && value.length <= 50 && /^[A-Za-z0-9 ,.\-#]+$/.test(value.trim());
        },
        password: function (value) {
            return value.length >= 8 && value.length <= 200;
        },
        confirm_password: function (value) {
            var pw = document.getElementById('password');
            return value.length >= 8 && !!pw && value === pw.value;
        }
    };

    // Only the confirmation repaints while typing; the others wait for blur,
    // so "juan@" or "0917" mid-entry does not flash red.
    var LIVE = { confirm_password: true };
    var MESSAGE_IDS = { email: 'email-error', confirm_password: 'pw-match' };
    var entries = [];

    Object.keys(RULES).forEach(function (id) {
        var input = document.getElementById(id);
        if (!input) return;
        entries.push({
            input: input,
            field: input.closest('.auth-field'),
            message: MESSAGE_IDS[id] ? document.getElementById(MESSAGE_IDS[id]) : null,
            touched: false
        });
    });

    function update(entry) {
        var value = entry.input.value;
        var rejected = entry.input.dataset.rejectedChar === '1';
        var checked = entry.touched || rejected || (LIVE[entry.input.id] && value !== '');
        var invalid = rejected || (checked && !RULES[entry.input.id](value));

        if (entry.field) {
            entry.field.classList.toggle('auth-field--invalid', invalid);
        }
        entry.input.setAttribute('aria-invalid', invalid ? 'true' : 'false');

        if (entry.message) {
            entry.message.classList.toggle('hidden', !invalid);
        }

        if (entry.input.id === 'confirm_password' && invalid) {
            var text = document.getElementById('pw-match-text');
            if (text) {
                text.textContent = value === ''
                    ? 'Please confirm your password'
                    : (value.length < 8 ? 'Use at least 8 characters' : 'Passwords do not match');
            }
        }
    }

    entries.forEach(function (entry) {
        entry.input.addEventListener('blur', function () {
            entry.touched = true;
            update(entry);
        });

        entry.input.addEventListener('input', function () {
            var isRed = entry.field && entry.field.classList.contains('auth-field--invalid');
            if (entry.touched || entry.input.dataset.rejectedChar === '1' || (LIVE[entry.input.id] && entry.input.value !== '') || isRed) {
                update(entry);
            }

            // The confirmation depends on the password, so one change can
            // invalidate (or repair) the other.
            if (entry.input.id === 'password') {
                entries.forEach(function (other) {
                    if (other.input.id !== 'confirm_password') return;
                    var otherRed = other.field && other.field.classList.contains('auth-field--invalid');
                    if (other.touched || otherRed || other.input.value !== '') {
                        update(other);
                    }
                });
            }
        });
    });
})();

/* Strength meter. Advisory only: the server decides, so the meter reports
   but never gates submit. */
(function () {
    var pw = document.getElementById('password');
    var meter = document.getElementById('pw-strength');
    var label = document.getElementById('pw-strength-label');
    if (!pw || !meter || !label) return;

    var LABELS = ['Use 8+ characters with a number or symbol.', 'Weak', 'Fair', 'Good', 'Strong'];

    function score(value) {
        if (!value) return 0;
        var s = 0;
        if (value.length >= 8) s++;
        if (value.length >= 12) s++;
        if (/[0-9]/.test(value) && /[a-zA-Z]/.test(value)) s++;
        if (/[^a-zA-Z0-9]/.test(value)) s++;
        return Math.min(s, 4);
    }

    function update() {
        var s = score(pw.value);
        meter.setAttribute('data-score', String(s));
        label.textContent = LABELS[s];
    }

    pw.addEventListener('input', update);
    update();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of auth/register.php */
?>