<?php
/* admin/maintenance-dashboard.php - Legacy admin URL that redirects maintenance staff to their dashboard. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
header('Location: ' . BASE_URL . '/maintenance/dashboard.php');
exit();
/* End of admin/maintenance-dashboard.php */
?>