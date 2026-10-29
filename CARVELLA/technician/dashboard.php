<?php
/* technician/dashboard.php - Technician dashboard wrapper around maintenance/dashboard.php. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
require __DIR__ . '/../maintenance/dashboard.php';

/* End of technician/dashboard.php */
