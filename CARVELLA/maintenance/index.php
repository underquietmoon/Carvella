<?php
/* maintenance/index.php - Maintenance entry URL that redirects to the maintenance dashboard. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
redirect(BASE_URL . '/maintenance/dashboard.php');
exit();
/* End of maintenance/index.php */
?>