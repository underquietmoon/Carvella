<?php
/* maintenance_staff/dashboard.php - Legacy underscore-path dashboard wrapper around maintenance/dashboard.php. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
require __DIR__ . '/../maintenance/dashboard.php';

/* End of maintenance_staff/dashboard.php */
