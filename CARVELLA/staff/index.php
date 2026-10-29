<?php
/* staff/index.php - Staff entry URL that redirects to the staff dashboard. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
header('Location: ' . BASE_URL . '/staff/dashboard.php');
exit();

/* End of staff/index.php */
