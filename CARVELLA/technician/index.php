<?php
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
header('Location: ' . BASE_URL . '/technician/dashboard.php');
exit();

