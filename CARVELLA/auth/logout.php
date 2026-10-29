<?php
/* auth/logout.php - Session logout and redirect. */
require_once __DIR__ . '/../config/auth.php';

// Logging out must be a POST: a GET is linkable, so an <img> tag elsewhere forces a sign-out.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

// A repeated logout is a success, not an error: fail soft to the same redirect the non-POST path uses.
if (!csrf_is_valid($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

// Only a signed-in user produces a logout row, so anonymous visitors cannot pollute activity_logs.
if (isLoggedIn()) {
    logActivity('logout', 'Signed out');
}

destroySession();

header("Location: " . BASE_URL . "/index.php");
exit();

/* End of auth/logout.php */
