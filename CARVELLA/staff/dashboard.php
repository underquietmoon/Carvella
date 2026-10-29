<?php
/* staff/dashboard.php - Staff dashboard wrapper around maintenance/dashboard.php. */
require_once __DIR__ . '/../config/auth.php';
requireMaintenance();
require __DIR__ . '/../maintenance/dashboard.php';

/* End of staff/dashboard.php */
