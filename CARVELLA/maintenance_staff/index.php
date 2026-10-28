<?php
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
header('Location: ' . BASE_URL . '/maintenance_staff/dashboard.php');
exit();

