<?php
/* technician/index.php - Technician entry URL that redirects to the technician dashboard. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
header('Location: ' . BASE_URL . '/technician/dashboard.php');
exit();

/* End of technician/index.php */
