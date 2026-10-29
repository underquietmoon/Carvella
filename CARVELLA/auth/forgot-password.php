<?php
/* auth/forgot-password.php - Password reset request form and handler. */
$pageTitle = 'Reset Password | CARVELLA Dagupan Hub';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/password-reset.php';
redirectIfLoggedIn();

/* No email is ever sent: the address box alone identifies the account, safe only without mail transport. */

$error = '';
$success = '';
$email = '';
$email_flagged = false;
$password_flagged = false;
$confirm_password_flagged = false;

$token = trim((string)($_POST['token'] ?? ($_GET['token'] ?? '')));

/* The token lives in the session, not the URL, so it stays out of the address bar and history. */
$sessionToken = (string)($_SESSION['password_reset_token'] ?? '');
if ($sessionToken !== '') {
    $token = $sessionToken;
}

/* Validity decides which of the two forms is offered, so it is resolved before
   the POST is handled rather than inside the password branch. */
$linkIsGood = false;
if ($token !== '') {
    $link = passwordResetFindUser($conn, $token);
    $linkIsGood = $link['ok'];

    if (!$linkIsGood) {
        /* Expiry gets its own wording because it is a normal outcome and the
           remedy is obvious. An unknown token is not described further: the
           detail belongs in the log, not in a reply to whoever presented it. */
        $error = $link['reason'] === 'expired'
            ? 'That reset has expired. Please start again.'
            : 'That reset is not valid. It may already have been used.';
        /* A dead token must not keep the password form up, and must not be
           reused as the basis for the next attempt. */
        unset($_SESSION['password_reset_token']);
        $token = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $linkIsGood) {
    /* The password form. It posts a token, so this branch and the request
       branch below are mutually exclusive. */
    verify_csrf();

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
        $password_flagged = true;
    } elseif (strlen($password) > 200) {
        $error = 'Password must be 200 characters or fewer';
        $password_flagged = true;
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match';
        $confirm_password_flagged = true;
    } else {
        $applied = passwordResetApply($conn, $token, $password);

        if ($applied['success']) {
            /* The token is single-use and passwordResetApply() has already
               cleared it in the database; drop this session's copy too, so a
               second submit cannot reach the apply step with a stale value. */
            unset($_SESSION['password_reset_token']);

            /* Defensive: redirectIfLoggedIn() at the top of this file has
               already sent a signed-in visitor away, so normally there is no
               session to end and the CSRF token is left alone. The branch is
               here so a session cannot survive a password change in the one
               case that slips past it. */
            if (!empty($_SESSION['user_id'])) {
                destroySession();
                session_start();
            }
            setFlash('success', 'Your password has been changed. You can sign in with it now.');
            redirect(BASE_URL . '/auth/login.php');
        }

        if ($applied['reason'] === 'unchanged') {
            /* The token is still live, so the form stays up and the visitor
               just picks something else. */
            $error = 'Choose a password different from your current one.';
        } elseif ($applied['reason'] === 'expired') {
            $error = 'That reset expired while this page was open. Start again below.';
            $linkIsGood = false;
        } elseif ($applied['reason'] === 'consumed') {
            $error = 'That reset has already been used. Start again below.';
            $linkIsGood = false;
        } else {
            $error = 'That reset is not valid. Start again below.';
            $linkIsGood = false;
        }

        /* Whatever ended the attempt, the token is no longer usable, so the
           session must not keep offering the form against it. */
        if (!$linkIsGood) {
            unset($_SESSION['password_reset_token']);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* The email box. */
    verify_csrf();

    $email = sanitize($_POST['email'] ?? '');

    $lockedFor = passwordResetIsThrottled();
    if ($lockedFor > 0) {
        $error = 'Too many reset requests. Please try again in ' . ceil($lockedFor / 60) . ' minute(s).';
    } elseif ($email === '') {
        $error = 'Please enter your email address.';
        $email_flagged = true;
    } elseif (!preg_match('/^[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*@[A-Za-z0-9]+(?:\.[A-Za-z0-9]+)*\.[A-Za-z]{2,}$/', $email)) {
        $error = 'Please enter a valid email address.';
        $email_flagged = true;
    } else {
        /* Counted before the lookup, not after: the request itself is the
           thing being limited, and now that each outcome gets its own reply a
           caller could otherwise walk the whole user table for free. */
        passwordResetThrottleHit();

        /* Restartable: no mail is sent, so there is no mailbox to protect and no
           reason to refuse a second attempt. */
        $issued = passwordResetIssue($conn, $email, true);

        /* Each outcome stops here and reports itself. Nothing proceeds to a
           reset unless the address is a real, active account: 'unknown' never
           receives a token, so there is no reset to proceed to in the first
           place. */
        if ($issued['status'] === 'unknown') {
            $error = 'No account is registered with ' . $email . '. Check the address, or create an account instead.';
        } elseif ($issued['status'] === 'inactive') {
            $error = 'That account has been deactivated. Please contact support to restore access.';
        } elseif ($issued['status'] === 'cooldown') {
            /* Unreachable while $restartable is true. Handled rather than
               ignored so that turning the flag off later degrades to the
               explicit message instead of an undefined-index warning. */
            $wait = max(1, (int)ceil(((int)($issued['wait'] ?? PASSWORD_RESET_COOLDOWN_SECONDS)) / 60));
            $error = 'A reset was already requested for that address. Please try again in ' . $wait . ' minute(s).';
        } elseif ($issued['status'] === 'error') {
            $error = 'We could not start a password reset right now. Please try again in a few minutes.';
        } else {
            /* Confirmed: a real, active account. The token is live in the
               database at this point, so the form below is genuinely usable -
               which is why it is only ever offered on this branch. */
            $userId = (int)$issued['user']['id'];
            $account = (string)$issued['user']['email'];

            /* Held in the session, not in the URL. The form posts it back from
               a hidden field, so the flow stays a normal CSRF-protected POST
               without the token ever entering the address bar. */
            $_SESSION['password_reset_token'] = $issued['token'];
            $token = $issued['token'];
            $linkIsGood = true;

            logActivity('password_reset_requested', 'Password reset started for user ' . $userId . ' (' . $account . ')');

            $success = 'Account found for ' . $account . '. Choose a new password below.';
            $email = '';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<main id="main-content" tabindex="-1" class="flex-grow flex items-center justify-center px-4 py-14 md:py-20 relative overflow-hidden">
<div class="auth-card w-full max-w-xl rounded-3xl overflow-hidden">
<div class="flex justify-center pt-8 md:pt-10 px-6">
<a href="<?= BASE_URL ?>/index.php" class="group inline-flex" aria-label="CARVELLA home">
<img class="h-16 w-auto object-contain transition-transform duration-300 group-hover:scale-105 theme-logo-light" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO.png?v=2" alt="CARVELLA" width="718" height="548" loading="eager">
<img class="h-16 w-auto object-contain transition-transform duration-300 group-hover:scale-105 theme-logo-dark" src="<?= BASE_URL ?>/assets/images/Logo/CARVELLA LOGO white.png?v=2" alt="CARVELLA" width="718" height="548" loading="eager">
</a>
</div>
<div class="px-6 pb-8 pt-6 md:px-12 md:pb-12">
<?php if ($error): ?>
<div class="auth-alert auth-alert--error mb-6" role="alert">
<i class="bi bi-exclamation-circle text-lg shrink-0 mt-0.5" aria-hidden="true"></i>
<span><?= e($error) ?></span>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div class="auth-alert auth-alert--ok mb-6" role="status">
<i class="bi bi-check-circle text-lg shrink-0 mt-0.5" aria-hidden="true"></i>
<span><?= e($success) ?></span>
</div>
<?php endif; ?>
<div class="mb-6">
<span class="auth-eyebrow"><i class="bi bi-<?= $linkIsGood ? 'arrow-counterclockwise' : 'key' ?> text-[14px]" aria-hidden="true"></i>Account Recovery</span>
<?php if ($linkIsGood): ?>
<h1 class="text-headline-xl font-headline-xl text-on-surface tracking-tight mt-3 mb-2">Choose a New Password</h1>
<p class="text-body-md font-body-md text-on-surface-variant">Pick something you have not used here before. You will be signed out everywhere once it changes.</p>
<?php else: ?>
<h1 class="text-headline-xl font-headline-xl text-on-surface tracking-tight mt-3 mb-2">Forgot Your Password?</h1>
<p class="text-body-md font-body-md text-on-surface-variant">Enter the email address on your account, then choose a new password.</p>
<?php endif; ?>
</div>
<?php if ($linkIsGood): ?>
<form method="POST" action="" class="space-y-5" id="reset-password-form" novalidate>
<?= csrf_field() ?>
<input type="hidden" name="token" value="<?= e($token) ?>">
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="password">New Password</label>
<div class="auth-field<?= $password_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-lock auth-field__icon" aria-hidden="true"></i>
<input type="password" id="password" name="password" autocomplete="new-password" maxlength="200" placeholder="Min. 8 characters" required minlength="8" aria-describedby="pw-strength-label" autofocus<?= $password_flagged ? ' aria-invalid="true"' : '' ?>>
<button type="button" class="auth-reveal" data-reveal-for="password" aria-label="Show password" aria-pressed="false">
<i class="bi bi-eye text-[20px]" aria-hidden="true"></i>
</button>
</div>
<!-- Advisory only, same as register.php: the server accepts any 8+ character
     password, so the meter reports and never gates submit. -->
<div class="auth-strength" id="pw-strength" data-score="0" aria-hidden="true">
<span class="auth-strength__seg"></span><span class="auth-strength__seg"></span><span class="auth-strength__seg"></span><span class="auth-strength__seg"></span>
</div>
<span class="auth-strength__label" id="pw-strength-label">Use 8+ characters with a number or symbol.</span>
</div>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="confirm_password">Confirm New Password</label>
<div class="auth-field<?= $confirm_password_flagged ? ' auth-field--invalid' : '' ?>" id="confirm-field">
<i class="bi bi-lock auth-field__icon" aria-hidden="true"></i>
<input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" maxlength="200" placeholder="Repeat password" required minlength="8" aria-describedby="pw-match"<?= $confirm_password_flagged ? ' aria-invalid="true"' : '' ?>>
<button type="button" class="auth-reveal" data-reveal-for="confirm_password" aria-label="Show password" aria-pressed="false">
<i class="bi bi-eye text-[20px]" aria-hidden="true"></i>
</button>
</div>
<p class="auth-field-error<?= $confirm_password_flagged ? '' : ' hidden' ?>" id="pw-match">
<i class="bi bi-exclamation-circle text-[15px]" aria-hidden="true"></i>Passwords do not match
</p>
</div>
<button type="submit" class="auth-submit">
<span class="auth-submit__idle">
<i class="bi bi-check-lg text-[20px]" aria-hidden="true"></i>Change My Password
</span>
<span class="auth-submit__busy">
<i class="bi bi-arrow-clockwise text-[20px] auth-spin" aria-hidden="true"></i>Saving&hellip;
</span>
</button>
</form>
<?php elseif ($error !== '' && $token !== ''): ?>
<p class="text-body-md font-body-md text-on-surface-variant mt-0 mb-0">
We could not use that reset. Nothing has changed on your account.
</p>
<a href="<?= BASE_URL ?>/auth/forgot-password.php" class="auth-submit mt-6">
<span class="auth-submit__idle">
<i class="bi bi-envelope text-[20px]" aria-hidden="true"></i>Start Again
</span>
</a>
<?php else: ?>
<form method="POST" action="" class="space-y-5" id="reset-request-form" novalidate>
<?= csrf_field() ?>
<div>
<label class="block text-label-lg font-label-lg text-on-surface mb-2" for="email">Email Address</label>
<div class="auth-field<?= $email_flagged ? ' auth-field--invalid' : '' ?>">
<i class="bi bi-envelope auth-field__icon" aria-hidden="true"></i>
<input type="email" id="email" name="email" placeholder="you@example.com" value="<?= e($email) ?>" autocomplete="email" maxlength="255" required autofocus<?= $email_flagged ? ' aria-invalid="true"' : '' ?>>
</div>
<p class="text-body-sm font-body-sm text-outline mt-3 mb-0">Used only to find your account. You will not be emailed.</p>
</div>
<button type="submit" class="auth-submit">
<span class="auth-submit__idle">
<i class="bi bi-envelope text-[20px]" aria-hidden="true"></i>Continue
</span>
<span class="auth-submit__busy">
<i class="bi bi-arrow-clockwise text-[20px] auth-spin" aria-hidden="true"></i>Checking&hellip;
</span>
</button>
</form>
<?php endif; ?>
<p class="text-center text-body-sm font-body-sm text-outline mt-6 mb-0">
Remembered it? <a href="<?= BASE_URL ?>/auth/login.php" class="text-primary font-semibold hover:underline">Back to sign in</a>
</p>
</div>
</div>
</main>
<?php if ($linkIsGood): ?>
<script>
/* Password reveal, shared with the login and register cards. */
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

/* Strength meter + match hint, advisory only. Nothing here blocks submit or
   calls setCustomValidity, which would fight the server's own message. */
(function () {
    var pw = document.getElementById('password');
    var confirmPw = document.getElementById('confirm_password');
    var meter = document.getElementById('pw-strength');
    var label = document.getElementById('pw-strength-label');
    var match = document.getElementById('pw-match');
    var field = document.getElementById('confirm-field');
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

        if (confirmPw && match && field) {
            var mismatch = confirmPw.value.length > 0 && confirmPw.value !== pw.value;
            match.classList.toggle('hidden', !mismatch);
            field.classList.toggle('auth-field--invalid', mismatch);
        }
    }

    pw.addEventListener('input', update);
    if (confirmPw) confirmPw.addEventListener('input', update);
    update();
})();
</script>
<?php endif; ?>
<script>
/* Both recovery forms: a blank field on click/Enter turns red and the
   submission is held back. Window capture runs before main.js's shared
   double-submit lock, so the button is never left disabled. */
(function () {
    function isEmpty(input) {
        return (input.id === 'password' || input.id === 'confirm_password')
            ? input.value === ''
            : input.value.trim() === '';
    }

    function guard(formId, ids) {
        var form = document.getElementById(formId);
        if (!form) return;

        var items = ids.map(function (id) {
            var input = document.getElementById(id);
            return input ? { input: input, field: input.closest('.auth-field') } : null;
        }).filter(Boolean);

        window.addEventListener('submit', function (event) {
            if (event.target !== form) return;

            var firstEmpty = null;
            items.forEach(function (item) {
                var empty = isEmpty(item.input);
                if (item.field) item.field.classList.toggle('auth-field--invalid', empty);
                item.input.setAttribute('aria-invalid', empty ? 'true' : 'false');
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
                if (isEmpty(item.input)) return;
                // The confirmation is also invalid while it does not match.
                if (item.input.id === 'confirm_password') {
                    var pw = document.getElementById('password');
                    if (pw && item.input.value !== pw.value) return;
                }
                if (item.field) item.field.classList.remove('auth-field--invalid');
                item.input.setAttribute('aria-invalid', 'false');
            });
        });
    }

    guard('reset-request-form', ['email']);
    guard('reset-password-form', ['password', 'confirm_password']);
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; 
/* End of auth/forgot-password.php */
?>
