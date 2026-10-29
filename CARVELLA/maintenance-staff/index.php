<?php
/* maintenance-staff/index.php - Maintenance-staff entry URL that redirects to its dashboard. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
header('Location: ' . BASE_URL . '/maintenance-staff/dashboard.php');
exit();

/* End of maintenance-staff/index.php */
