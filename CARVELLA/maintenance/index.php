<?php
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
redirect(BASE_URL . '/maintenance/dashboard.php');
exit();
?>