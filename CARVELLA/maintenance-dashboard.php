<?php
/* maintenance-dashboard.php - Root URL that redirects maintenance staff to their dashboard. */
require_once __DIR__ . '/config/auth.php';
requireMaintenance();
header('Location: ' . BASE_URL . '/maintenance/dashboard.php');
exit();
/* End of maintenance-dashboard.php */
?>