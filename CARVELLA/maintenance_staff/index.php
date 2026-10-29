<?php
/* maintenance_staff/index.php - Legacy underscore-path entry URL that redirects to its dashboard. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
header('Location: ' . BASE_URL . '/maintenance_staff/dashboard.php');
exit();

/* End of maintenance_staff/index.php */
