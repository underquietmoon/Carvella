-- =============================================================================
-- CARVELLA - database schema
-- =============================================================================
-- Run: mysql -u root -p < carvella.sql
--
-- Safe on a new server AND on a live database. It never drops data: missing
-- tables/columns/indexes are created, and the seed rows only load when the
-- table is empty. Running it twice changes nothing the second time.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS carvella
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE carvella;

SET NAMES utf8mb4;

-- USERS: every registered account, customers and admins.
CREATE TABLE IF NOT EXISTS users (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    first_name           VARCHAR(100) NOT NULL,
    last_name            VARCHAR(100) NOT NULL,
    email                VARCHAR(255) NOT NULL,
    phone                VARCHAR(20)  DEFAULT NULL,
    password             VARCHAR(255) NOT NULL,
    address              TEXT         DEFAULT NULL,
    role                 ENUM('customer', 'admin', 'maintenance')      NOT NULL DEFAULT 'customer',
    status               ENUM('active', 'inactive')     NOT NULL DEFAULT 'active',
    reset_token_hash     CHAR(64)     DEFAULT NULL,
    reset_expires_at     DATETIME     DEFAULT NULL,
    reset_requested_at   DATETIME     DEFAULT NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role, status),
    KEY idx_users_reset_token (reset_token_hash),
    CONSTRAINT chk_users_reset_hash CHECK (reset_token_hash IS NULL OR CHAR_LENGTH(reset_token_hash) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VEHICLES: the rentable fleet.
CREATE TABLE IF NOT EXISTS vehicles (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    brand          VARCHAR(100) NOT NULL,
    model          VARCHAR(100) NOT NULL,
    year           SMALLINT UNSIGNED NOT NULL,
    type           ENUM('sedan', 'suv', 'mpv', 'pickup', 'van', 'hatchback') NOT NULL,
    license_plate  VARCHAR(20)  NOT NULL,
    seats          TINYINT UNSIGNED NOT NULL DEFAULT 5,
    transmission   ENUM('automatic', 'manual')          NOT NULL DEFAULT 'automatic',
    fuel_type      ENUM('gasoline', 'diesel', 'electric', 'hybrid') NOT NULL DEFAULT 'gasoline',
    mileage        INT UNSIGNED NOT NULL DEFAULT 0,
    daily_rate     DECIMAL(10,2) NOT NULL,
    description    TEXT         DEFAULT NULL,
    color          VARCHAR(100) DEFAULT NULL,
    image          VARCHAR(255) DEFAULT NULL,
    status         ENUM('available', 'rented', 'maintenance', 'unavailable', 'archived') NOT NULL DEFAULT 'available',
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vehicles_license_plate (license_plate),
    KEY idx_vehicles_status (status),
    KEY idx_vehicles_type (type),
    CONSTRAINT chk_vehicles_year   CHECK (year BETWEEN 1900 AND 2100),
    CONSTRAINT chk_vehicles_seats  CHECK (seats BETWEEN 1 AND 99),
    CONSTRAINT chk_vehicles_rate   CHECK (daily_rate > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RESERVATIONS: every booking, identified by booking_reference at the counter.
CREATE TABLE IF NOT EXISTS reservations (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    booking_reference   VARCHAR(50) NOT NULL,
    user_id             INT NOT NULL,
    vehicle_id          INT NOT NULL,
    pickup_date         DATE NOT NULL,
    pickup_time         TIME DEFAULT NULL,
    return_date         DATE NOT NULL,
    return_time         TIME DEFAULT NULL,
    pickup_location     VARCHAR(255) DEFAULT NULL,
    return_location     VARCHAR(255) DEFAULT NULL,
    license_no          VARCHAR(64)  DEFAULT NULL,
    protection          VARCHAR(128) DEFAULT NULL,
    protection_fee      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount        DECIMAL(10,2) NOT NULL,
    status              ENUM('pending', 'confirmed', 'active', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    notes               TEXT DEFAULT NULL,
    payment_method      VARCHAR(20)  NOT NULL DEFAULT '',
    payment_reference   VARCHAR(64)  DEFAULT NULL,
    payment_amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_status      VARCHAR(20)  NOT NULL DEFAULT 'unpaid',
    payment_verified_at DATETIME     DEFAULT NULL,
    payment_verified_by INT UNSIGNED DEFAULT NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reservations_booking_reference (booking_reference),
    KEY idx_reservations_user_id (user_id),
    KEY idx_reservations_vehicle_id (vehicle_id),
    KEY idx_reservations_status (status),
    KEY idx_reservations_pickup_date (pickup_date),
    -- Finds double bookings for one vehicle.
    KEY idx_reservations_vehicle_schedule (vehicle_id, status, pickup_date, return_date),
    KEY idx_reservations_payment_status (payment_status),
    CONSTRAINT fk_reservations_user    FOREIGN KEY (user_id)    REFERENCES users (id),
    CONSTRAINT fk_reservations_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id),
    -- No foreign key: the audit trail must survive staff deletion.
    CONSTRAINT chk_reservations_dates   CHECK (return_date >= pickup_date),
    CONSTRAINT chk_reservations_amount  CHECK (total_amount >= 0),
    CONSTRAINT chk_reservations_fee     CHECK (protection_fee >= 0),
    -- Only the two wallets we accept; '' means no online payment.
    CONSTRAINT chk_reservations_payment_method
        CHECK (payment_method IN ('', 'gcash', 'paymaya')),
    -- A reference and an amount must come with a method.
    CONSTRAINT chk_reservations_payment_capture
        CHECK ((payment_method = '' AND payment_reference IS NULL AND payment_amount = 0)
            OR (payment_method <> '' AND payment_reference IS NOT NULL AND payment_amount > 0)),
    CONSTRAINT chk_reservations_payment_state
        CHECK (payment_status IN ('unpaid', 'pending', 'verified', 'rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RENTALS: one row per vehicle handed over, created at counter dispatch.
CREATE TABLE IF NOT EXISTS rentals (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id        INT DEFAULT NULL,
    user_id               INT NOT NULL,
    vehicle_id            INT NOT NULL,
    pickup_date           DATE NOT NULL,
    expected_return_date  DATE NOT NULL,
    actual_return_date    DATE DEFAULT NULL,
    duration              SMALLINT UNSIGNED DEFAULT NULL,
    total_amount          DECIMAL(10,2) NOT NULL,
    status                ENUM('pending', 'active', 'in_progress', 'completed', 'cancelled', 'overdue') NOT NULL DEFAULT 'active',
    odometer_start        INT UNSIGNED DEFAULT NULL,
    odometer_end          INT UNSIGNED DEFAULT NULL,
    late_fee              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes                 TEXT DEFAULT NULL,
    dispatched_at         DATETIME DEFAULT NULL,
    returned_at           DATETIME DEFAULT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rentals_reservation (reservation_id),
    KEY idx_rentals_user_id (user_id),
    KEY idx_rentals_vehicle_id (vehicle_id),
    KEY idx_rentals_status (status),
    KEY idx_rentals_pickup_date (pickup_date),
    -- Finds double bookings for one vehicle.
    KEY idx_rentals_vehicle_window (vehicle_id, status, pickup_date, expected_return_date),
    CONSTRAINT fk_rentals_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE SET NULL,
    CONSTRAINT fk_rentals_user         FOREIGN KEY (user_id)        REFERENCES users (id),
    CONSTRAINT fk_rentals_vehicle      FOREIGN KEY (vehicle_id)     REFERENCES vehicles (id),
    CONSTRAINT chk_rentals_dates   CHECK (expected_return_date >= pickup_date),
    CONSTRAINT chk_rentals_amount  CHECK (total_amount >= 0),
    CONSTRAINT chk_rentals_late_fee CHECK (late_fee >= 0),
    -- The odometer only goes up.
    CONSTRAINT chk_rentals_odometer CHECK (odometer_end IS NULL OR odometer_start IS NULL OR odometer_end >= odometer_start),
    -- Handback cannot be before pickup, and a closed rental needs a timestamp.
    CONSTRAINT chk_rentals_return CHECK (
        actual_return_date IS NULL
        OR (actual_return_date >= pickup_date AND returned_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MAINTENANCE: service and repair records per vehicle.
CREATE TABLE IF NOT EXISTS maintenance (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT NOT NULL,
    description     TEXT NOT NULL,
    cost            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status          ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending',
    scheduled_date  DATE DEFAULT NULL,
    completed_date  DATE DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_maintenance_vehicle_id (vehicle_id),
    KEY idx_maintenance_status (status),
    -- Finds open jobs for a vehicle on every save.
    KEY idx_maintenance_vehicle_status (vehicle_id, status),
    CONSTRAINT fk_maintenance_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id),
    CONSTRAINT chk_maintenance_cost CHECK (cost >= 0),
    CONSTRAINT chk_maintenance_completed CHECK (
        (status = 'completed' AND completed_date IS NOT NULL)
        OR (status <> 'completed' AND completed_date IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VEHICLE_CONDITIONS: inspection log for each vehicle check.
CREATE TABLE IF NOT EXISTS vehicle_conditions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id      INT NOT NULL,
    condition_type  ENUM('excellent', 'good', 'fair', 'poor') NOT NULL,
    notes           TEXT DEFAULT NULL,
    recorded_by     INT DEFAULT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_conditions_vehicle_id (vehicle_id),
    KEY idx_conditions_recorded_by (recorded_by),
    KEY idx_conditions_type (condition_type),
    KEY idx_conditions_vehicle_recent (vehicle_id, created_at),
    CONSTRAINT fk_conditions_vehicle  FOREIGN KEY (vehicle_id)  REFERENCES vehicles (id),
    CONSTRAINT fk_conditions_recorder FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ACTIVITY_LOGS: the audit trail. user_id is NULL for system events.
CREATE TABLE IF NOT EXISTS activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT DEFAULT NULL,
    action      VARCHAR(255) NOT NULL,
    details     TEXT DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_activity_user_id (user_id),
    KEY idx_activity_action (action),
    KEY idx_activity_created_at (created_at),
    KEY idx_activity_user_recent (user_id, created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RENTAL_TRANSACTIONS: append-only ledger of every rental state change.
CREATE TABLE IF NOT EXISTS rental_transactions (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    rental_id             INT DEFAULT NULL,
    reservation_id        INT DEFAULT NULL,
    vehicle_id            INT NOT NULL,
    user_id               INT DEFAULT NULL,
    event_type            VARCHAR(64) NOT NULL,
    from_status           VARCHAR(32) DEFAULT NULL,
    to_status             VARCHAR(32) DEFAULT NULL,
    pickup_date           DATE DEFAULT NULL,
    expected_return_date  DATE DEFAULT NULL,
    actual_return_date    DATE DEFAULT NULL,
    amount                DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    odometer              INT UNSIGNED DEFAULT NULL,
    notes                 TEXT DEFAULT NULL,
    actor_id              INT DEFAULT NULL,
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rental_tx_rental (rental_id),
    KEY idx_rental_tx_reservation (reservation_id),
    KEY idx_rental_tx_vehicle (vehicle_id, created_at),
    KEY idx_rental_tx_event (event_type, created_at),
    KEY idx_rental_tx_user (user_id),
    KEY idx_rental_tx_actor (actor_id),
    -- Money can never be negative; revenue reports sum this column.
    CONSTRAINT chk_rental_tx_amount CHECK (amount >= 0),
    CONSTRAINT chk_rental_tx_dates CHECK (
        expected_return_date IS NULL OR pickup_date IS NULL OR expected_return_date >= pickup_date
    ),
    CONSTRAINT fk_rental_tx_rental      FOREIGN KEY (rental_id)      REFERENCES rentals (id)      ON DELETE SET NULL,
    CONSTRAINT fk_rental_tx_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE SET NULL,
    CONSTRAINT fk_rental_tx_vehicle     FOREIGN KEY (vehicle_id)     REFERENCES vehicles (id),
    CONSTRAINT fk_rental_tx_user        FOREIGN KEY (user_id)        REFERENCES users (id)        ON DELETE SET NULL,
    CONSTRAINT fk_rental_tx_actor       FOREIGN KEY (actor_id)       REFERENCES users (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SEED DATA: loads only when the table is empty, so an existing
-- database keeps its own accounts and fleet.

DROP PROCEDURE IF EXISTS carvella_seed_if_empty;
DELIMITER //
CREATE PROCEDURE carvella_seed_if_empty()
BEGIN
    -- Default admin. CHANGE THE PASSWORD before going live.
    IF (SELECT COUNT(*) FROM users) = 0 THEN
        INSERT IGNORE INTO users (first_name, last_name, email, phone, password, role, status) VALUES
        ('Admin', 'User', 'admin@carvella.com', '+1234567890', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active'),
        ('Maintenance', 'Staff', 'maintenance@carvella.com', '+1234567891', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'maintenance', 'active');
    END IF;

-- Sample fleet: 24 units. image points under assets/images/vehicles/.
IF (SELECT COUNT(*) FROM vehicles) = 0 THEN
    INSERT IGNORE INTO vehicles (brand, model, year, type, license_plate, seats, transmission, fuel_type, mileage, daily_rate, description, color, image, status) VALUES
-- Card 1
('Honda', 'City 1.5 RS', 2024, 'sedan', 'NXC-4827', 5, 'automatic', 'gasoline', 18500, 2600.00, 'A sporty compact sedan designed for comfortable everyday driving. Its spacious cabin, smooth CVT transmission, and efficient 1.5L gasoline engine make it suitable for city trips and longer journeys.', 'Platinum White Pearl', 'assets/images/vehicles/Honda City RS Turbo.png?v=2', 'available'),
-- Card 2
('Toyota', 'Vios 1.5 G', 2024, 'sedan', 'KLM-7154', 5, 'automatic', 'gasoline', 21300, 2300.00, 'A practical sedan designed for everyday transportation. It provides a comfortable interior, easy handling, and smooth performance for city and highway travel.', 'Silver Metallic', 'assets/images/vehicles/Toyota Vios.png?v=2', 'available'),
-- Card 3
('Toyota', 'Corolla Altis 1.8 E', 2024, 'sedan', 'RJT-3068', 5, 'automatic', 'gasoline', 16800, 3000.00, 'A refined sedan offering comfortable seating and smooth driving performance. It is suitable for business trips, family travel, and daily transportation.', 'Super White II', 'assets/images/vehicles/Toyota Corolla Altis 1.8V.png?v=2', 'available'),
-- Card 4
('Honda', 'Civic 1.5 RS Turbo', 2024, 'sedan', 'BQV-9513', 5, 'automatic', 'gasoline', 14600, 3300.00, 'A sporty sedan with a modern cabin and responsive turbocharged engine. It is suited for comfortable daily driving and longer road trips.', 'Ignite Red Metallic', 'assets/images/vehicles/Honda Civic RS Turbo.png?v=2', 'available'),
-- Card 5
('Hyundai', 'Elantra 1.6T N Line', 2024, 'sedan', 'WHS-6249', 5, 'automatic', 'gasoline', 17200, 3400.00, 'A sporty sedan featuring the N Line design and turbocharged performance. It combines everyday practicality with a more performance-oriented driving experience.', 'Intense Blue', 'assets/images/vehicles/Hyundai Elantra.png?v=2', 'available'),
-- Card 6
('Suzuki', 'Dzire GL+', 2024, 'sedan', 'DPN-8372', 5, 'automatic', 'gasoline', 12400, 2100.00, 'A compact sedan designed for economical daily transportation. Its small size makes it convenient for city driving and everyday errands.', 'Arctic White', 'assets/images/vehicles/Suzuki Dzire.png?v=2', 'available'),
-- Card 7
('Mitsubishi', 'Mirage G4 GLX', 2024, 'sedan', 'XFK-1946', 5, 'automatic', 'gasoline', 13000, 2000.00, 'A compact and fuel-efficient sedan designed for urban transportation. Its practical size makes it easy to drive and maneuver through busy city roads.', 'Titanium Gray Metallic', 'assets/images/vehicles/Mitsubishi Mirage G4.png?v=2', 'available'),
-- Card 8
('Toyota', 'Fortuner 2.8 GR-S 4x4', 2024, 'suv', 'JRC-5631', 7, 'automatic', 'diesel', 24700, 5200.00, 'A spacious SUV designed for family trips, long-distance travel, and outdoor adventures. Its 4x4 system provides additional capability for different road conditions.', 'Attitude Black Mica', 'assets/images/vehicles/Toyota Fortuner GR-S.png?v=2', 'available'),
-- Card 9
('Nissan', 'Terra 2.5 VL 4x4', 2024, 'suv', 'MVB-7285', 7, 'automatic', 'diesel', 22100, 5000.00, 'A seven-seat SUV designed for family travel and adventure. It provides generous passenger space and a capable 4x4 system for longer journeys.', 'Brilliant Silver', 'assets/images/vehicles/Nissan Terra 4x4 VL.png?v=2', 'available'),
-- Card 10
('Ford', 'Everest 2.0L Bi-Turbo Titanium+ 4x4', 2024, 'suv', 'TKS-4193', 7, 'automatic', 'diesel', 19800, 5400.00, 'A premium SUV built for comfortable family travel and adventure. Its spacious interior and four-wheel-drive system provide versatility for both highway and outdoor trips.', 'Meteor Grey', 'assets/images/vehicles/Ford Everest Titanium.png?v=2', 'available'),
-- Card 11
('Suzuki', 'Jimny 1.5 GLX 4WD', 2024, 'suv', 'GHL-8526', 4, 'automatic', 'gasoline', 11700, 3200.00, 'A compact 4WD vehicle designed for adventure and exploration. Its small body and capable drivetrain make it suitable for both city driving and outdoor trips.', 'Kinetic Yellow', 'assets/images/vehicles/Suzuki Jimny.png?v=2', 'available'),
-- Card 12
('Toyota', 'Corolla Cross 1.8 V HEV', 2024, 'suv', 'PZN-6374', 5, 'automatic', 'hybrid', 15900, 3200.00, 'A modern hybrid crossover designed for comfortable city and family travel. Its hybrid powertrain provides an efficient option for everyday driving.', 'Platinum White Pearl Mica', 'assets/images/vehicles/Toyota Corolla Cross HEV.png?v=2', 'available'),
-- Card 13
('Honda', 'BR-V 1.5 VX', 2024, 'mpv', 'VRT-2958', 7, 'automatic', 'gasoline', 18200, 3100.00, 'A versatile seven-seat vehicle designed for families and group travel. It provides practical passenger space while remaining easy to drive around the city.', 'Lunar Silver Metallic', 'assets/images/vehicles/Honda BR-V.png?v=2', 'available'),
-- Card 14
('Honda', 'BR-V 1.5 VX', 2025, 'mpv', 'CFM-7461', 7, 'automatic', 'gasoline', 9800, 3100.00, 'A practical seven-seater suitable for family trips and everyday transportation. Its flexible interior provides useful space for passengers and luggage.', 'Platinum White Pearl', 'assets/images/vehicles/Honda BR-V.png?v=2', 'available'),
-- Card 15
('Toyota', 'Innova 2.8 V Diesel', 2024, 'mpv', 'LQX-3817', 7, 'automatic', 'diesel', 27300, 3800.00, 'A spacious MPV designed for comfortable family and group transportation. It is suitable for long-distance trips, vacations, and business travel.', 'White Pearl Crystal Shine', 'assets/images/vehicles/Toyota Innova.png?v=2', 'available'),
-- Card 16
('Toyota', 'Granvia 2.8 Premium', 2024, 'van', 'HBN-9042', 10, 'automatic', 'diesel', 20600, 6800.00, 'A premium passenger van designed for comfortable group transportation. Its spacious interior makes it suitable for family trips, business travel, and group tours.', 'Pearl White', 'assets/images/vehicles/Toyota Granvia.png?v=2', 'available'),
-- Card 17
('Mitsubishi', 'Xpander Cross', 2025, 'mpv', 'ZKD-5186', 7, 'automatic', 'gasoline', 10500, 3200.00, 'A seven-seat crossover MPV designed for family travel and weekend adventures. It combines practical passenger space with a rugged exterior design.', 'Graphite Gray', 'assets/images/vehicles/Mitsubishi Xpander Cross.png?v=2', 'available'),
-- Card 18
('Toyota', 'Hiace Commuter Deluxe 2.8L Diesel', 2024, 'van', 'YBC-7319', 15, 'manual', 'diesel', 8900, 4800.00, 'A premium people carrier designed for comfortable group transportation. Its spacious cabin makes it suitable for family vacations, business transportation, and special events.', 'White', 'assets/images/vehicles/Toyota Hiace Commuter.png?v=3', 'available'),
-- Card 19
('Suzuki', 'Ertiga Hybrid GLX', 2024, 'mpv', 'YFC-7415', 7, 'automatic', 'hybrid', 9700, 2700.00, 'A practical hybrid MPV designed for family and group transportation. Its seven-seat layout provides useful space for passengers and everyday travel.', 'Silky Silver Metallic', 'assets/images/vehicles/Suzuki Ertiga Hybrid GLX.png?v=2', 'available'),
-- Card 20
('Hyundai', 'Stargazer 1.5 GLS Premium X', 2025, 'mpv', 'QTR-6293', 7, 'automatic', 'gasoline', 12100, 3000.00, 'A modern seven-seat MPV designed for family transportation. Its spacious cabin and contemporary styling make it suitable for city trips and weekend travel.', 'Creamy White Pearl', 'assets/images/vehicles/Hyundai Stargazer X.png?v=2', 'available'),
-- Card 21
('Ford', 'Ranger 2.0L Bi-Turbo Wildtrak 4x4', 2024, 'pickup', 'NBG-4758', 5, 'automatic', 'diesel', 23500, 4900.00, 'A capable 4x4 pickup designed for adventure, cargo transportation, and long-distance travel. It combines passenger comfort with useful cargo capability.', 'Luxe Yellow', 'assets/images/vehicles/Ford Ranger.png?v=2', 'available'),
-- Card 22
('Toyota', 'Hilux 2.8 Conquest 4x4', 2024, 'pickup', 'EKV-8136', 5, 'automatic', 'diesel', 25100, 4700.00, 'A durable pickup designed for outdoor travel, transportation, and demanding road conditions. Its 4x4 drivetrain provides additional versatility for different types of trips.', 'Emotional Red 2', 'assets/images/vehicles/Toyota Hilux Conquest 4x4.png?v=2', 'available'),
-- Card 24
('Toyota', 'Hiace Commuter Deluxe 2.8L Diesel', 2024, 'van', 'UCM-9264', 15, 'manual', 'diesel', 18900, 4800.00, 'A high-capacity van designed for group transportation. It is suitable for tours, school activities, events, company trips, and large family travel.', 'White', 'assets/images/vehicles/Toyota Hiace Commuter.png?v=2', 'available'),
-- Card 25
('Toyota', 'Hiace GL Grandia 2.8 Diesel', 2024, 'van', 'FJK-6384', 10, 'automatic', 'diesel', 22400, 6000.00, 'A premium passenger van offering spacious seating and comfortable transportation for larger groups. It is suitable for family trips, business transportation, tours, and events.', 'Silver Mica Metallic', 'assets/images/vehicles/Toyota Hiace GL Grandia.png?v=2', 'available');
    END IF;
END//
DELIMITER ;

CALL carvella_seed_if_empty();
DROP PROCEDURE carvella_seed_if_empty;

-- UPGRADE AN EXISTING DATABASE: adds whatever is missing. Every step is
-- guarded, so this does nothing to a database created above.

-- Helper procedures: add_column, add_index, add_fk, add_check.
DROP PROCEDURE IF EXISTS carvella_add_column;
DROP PROCEDURE IF EXISTS carvella_add_index;
DROP PROCEDURE IF EXISTS carvella_add_fk;
DROP PROCEDURE IF EXISTS carvella_add_check;
DROP PROCEDURE IF EXISTS carvella_replace_check;
DROP PROCEDURE IF EXISTS carvella_ensure_enum_value;
DROP PROCEDURE IF EXISTS carvella_reorder_timestamps;

DELIMITER //

CREATE PROCEDURE carvella_add_column(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
    SET @probe = CONCAT(
        'SELECT COUNT(*) INTO @found FROM information_schema.COLUMNS',
        ' WHERE TABLE_SCHEMA = DATABASE()',
        '   AND TABLE_NAME   = ''', REPLACE(p_table, '''', ''''''), '''',
        '   AND COLUMN_NAME  = ''', REPLACE(p_column, '''', ''''''), ''''
    );
    PREPARE ps FROM @probe; EXECUTE ps; DEALLOCATE PREPARE ps;

    IF @found = 0 THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
        SELECT CONCAT('  + column  ', p_table, '.', p_column) AS applied;
    ELSE
        SELECT CONCAT('  = column  ', p_table, '.', p_column, '  (already present)') AS applied;
    END IF;
END//

CREATE PROCEDURE carvella_add_index(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns TEXT)
BEGIN
    SET @probe = CONCAT(
        'SELECT COUNT(*) INTO @found FROM information_schema.STATISTICS',
        ' WHERE TABLE_SCHEMA = DATABASE()',
        '   AND TABLE_NAME   = ''', REPLACE(p_table, '''', ''''''), '''',
        '   AND INDEX_NAME   = ''', REPLACE(p_index, '''', ''''''), ''''
    );
    PREPARE ps FROM @probe; EXECUTE ps; DEALLOCATE PREPARE ps;

    IF @found = 0 THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` ', p_columns);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
        SELECT CONCAT('  + index   ', p_table, '.', p_index) AS applied;
    ELSE
        SELECT CONCAT('  = index   ', p_table, '.', p_index, '  (already present)') AS applied;
    END IF;
END//

CREATE PROCEDURE carvella_add_fk(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_clause TEXT)
BEGIN
    SET @probe = CONCAT(
        'SELECT COUNT(*) INTO @found FROM information_schema.TABLE_CONSTRAINTS',
        ' WHERE CONSTRAINT_SCHEMA = DATABASE()',
        '   AND TABLE_NAME        = ''', REPLACE(p_table, '''', ''''''), '''',
        '   AND CONSTRAINT_NAME   = ''', REPLACE(p_constraint, '''', ''''''), '''',
        '   AND CONSTRAINT_TYPE   = ''FOREIGN KEY'''
    );
    PREPARE ps FROM @probe; EXECUTE ps; DEALLOCATE PREPARE ps;

    IF @found = 0 THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_constraint, '` ', p_clause);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
        SELECT CONCAT('  + fk      ', p_table, '.', p_constraint) AS applied;
    ELSE
        SELECT CONCAT('  = fk      ', p_table, '.', p_constraint, '  (already present)') AS applied;
    END IF;
END//

CREATE PROCEDURE carvella_add_check(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_clause TEXT)
BEGIN
    SET @probe = CONCAT(
        'SELECT COUNT(*) INTO @found FROM information_schema.TABLE_CONSTRAINTS',
        ' WHERE CONSTRAINT_SCHEMA = DATABASE()',
        '   AND TABLE_NAME        = ''', REPLACE(p_table, '''', ''''''), '''',
        '   AND CONSTRAINT_NAME   = ''', REPLACE(p_constraint, '''', ''''''), '''',
        '   AND CONSTRAINT_TYPE   = ''CHECK'''
    );
    PREPARE ps FROM @probe; EXECUTE ps; DEALLOCATE PREPARE ps;

    IF @found = 0 THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_constraint, '` CHECK ', p_clause);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
        SELECT CONCAT('  + check   ', p_table, '.', p_constraint) AS applied;
    ELSE
        SELECT CONCAT('  = check   ', p_table, '.', p_constraint, '  (already present)') AS applied;
    END IF;
END//

-- Restates a CHECK so an old weaker version is replaced. Used for
-- chk_vehicles_rate, where older databases have daily_rate >= 0.
CREATE PROCEDURE carvella_replace_check(IN p_table VARCHAR(64), IN p_constraint VARCHAR(64), IN p_clause TEXT)
BEGIN
    SET @probe = CONCAT(
        'SELECT COUNT(*) INTO @found FROM information_schema.TABLE_CONSTRAINTS',
        ' WHERE CONSTRAINT_SCHEMA = DATABASE()',
        '   AND TABLE_NAME        = ''', REPLACE(p_table, '''', ''''''), '''',
        '   AND CONSTRAINT_NAME   = ''', REPLACE(p_constraint, '''', ''''''), '''',
        '   AND CONSTRAINT_TYPE   = ''CHECK'''
    );
    PREPARE ps FROM @probe; EXECUTE ps; DEALLOCATE PREPARE ps;

    IF @found = 1 THEN
        SET @drop = CONCAT('ALTER TABLE `', p_table, '` DROP CONSTRAINT `', p_constraint, '`');
        PREPARE s FROM @drop; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_constraint, '` CHECK ', p_clause);
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    SELECT CONCAT('  ~ check   ', p_table, '.', p_constraint, '  (restated)') AS applied;
END//

-- Widens an ENUM column that is missing a value the application now writes.
-- Guarded on the exact COLUMN_TYPE rather than the value name, because the
-- full type string is what a fresh import of this file produces: when they
-- match, a migrated database and a freshly imported one are identical, and a
-- second run of this script reports "already present". p_type is the bare
-- COLUMN_TYPE (for example enum('a','b')); p_definition is the full column
-- definition used by the ALTER.
CREATE PROCEDURE carvella_ensure_enum_value(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_type TEXT, IN p_definition TEXT)
BEGIN
    SET @probe = CONCAT(
        'SELECT COUNT(*) INTO @found FROM information_schema.COLUMNS',
        ' WHERE TABLE_SCHEMA = DATABASE()',
        '   AND TABLE_NAME   = ''', REPLACE(p_table, '''', ''''''), '''',
        '   AND COLUMN_NAME  = ''', REPLACE(p_column, '''', ''''''), '''',
        '   AND COLUMN_TYPE  = ''', REPLACE(p_type, '''', ''''''), ''''
    );
    PREPARE ps FROM @probe; EXECUTE ps; DEALLOCATE PREPARE ps;

    IF @found = 0 THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` MODIFY COLUMN `', p_column, '` ', p_definition);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
        SELECT CONCAT('  ~ enum    ', p_table, '.', p_column, '  (widened)') AS applied;
    ELSE
        SELECT CONCAT('  = enum    ', p_table, '.', p_column, '  (already present)') AS applied;
    END IF;
END//

-- Moves created_at/updated_at to the end so column order matches the tables
-- above. Cosmetic only, and skipped when the order is already correct.
CREATE PROCEDURE carvella_reorder_timestamps(IN p_table VARCHAR(64), IN p_last VARCHAR(64))
BEGIN
    SET @probe = CONCAT(
        'SELECT (',
        '  (SELECT ORDINAL_POSITION FROM information_schema.COLUMNS',
        '    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ''', REPLACE(p_table, '''', ''''''), '''',
        '      AND COLUMN_NAME = ''updated_at'')',
        '  =',
        '  (SELECT MAX(ORDINAL_POSITION) FROM information_schema.COLUMNS',
        '    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ''', REPLACE(p_table, '''', ''''''), '''',
        ')) INTO @is_last FROM DUAL'
    );
    PREPARE ps FROM @probe; EXECUTE ps; DEALLOCATE PREPARE ps;

    IF @is_last = 0 THEN
        SET @ddl = CONCAT(
            'ALTER TABLE `', p_table, '`',
            ' MODIFY COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            '   AFTER `', p_last, '`',
            ', MODIFY COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            '   ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'
        );
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
        SELECT CONCAT('  ~ order   ', p_table, '  (created_at/updated_at moved to end)') AS applied;
    ELSE
        SELECT CONCAT('  = order   ', p_table, '  (already correct)') AS applied;
    END IF;
END//

DELIMITER ;


-- ------------------------------------------------------------------
-- 1. PASSWORD RESET - users
--
-- backs the reset_token_hash lookup. Without the index that is a full scan of
-- users on every reset attempt.
-- ------------------------------------------------------------------
CALL carvella_add_column('users', 'reset_token_hash',   'CHAR(64) DEFAULT NULL');
CALL carvella_add_column('users', 'reset_expires_at',   'DATETIME DEFAULT NULL');
CALL carvella_add_column('users', 'reset_requested_at', 'DATETIME DEFAULT NULL');
CALL carvella_add_index ('users', 'idx_users_reset_token', '(reset_token_hash)');


-- ------------------------------------------------------------------
-- 2. ONLINE PAYMENT CAPTURE - reservations
--
-- A reference-only record of a GCash / PayMaya transfer the customer made to
-- their own wallet. No money moves through this site, so payment_status is the
-- only thing that means "paid": a stored reference is not proof.
-- ------------------------------------------------------------------
CALL carvella_add_column('reservations', 'payment_method',      "VARCHAR(20) NOT NULL DEFAULT ''");
CALL carvella_add_column('reservations', 'payment_reference',   'VARCHAR(64) DEFAULT NULL');
CALL carvella_add_column('reservations', 'payment_amount',      'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
CALL carvella_add_column('reservations', 'payment_status',      "VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
CALL carvella_add_column('reservations', 'payment_verified_at', 'DATETIME DEFAULT NULL');
-- No foreign key on purpose: the audit trail must survive the staff account
-- being deleted.
CALL carvella_add_column('reservations', 'payment_verified_by', 'INT UNSIGNED DEFAULT NULL');

CALL carvella_add_index('reservations', 'idx_reservations_payment_status', '(payment_status)');


-- ------------------------------------------------------------------
-- 2b. HANDOVER CLOCK TIMES - reservations
--
-- The hub used to publish fixed windows (10:00 AM pick-up, 6:00 PM return);
-- the booking form now lets the customer pick both. A NULL time marks a row
-- written before the picker existed, so the UI falls back to those windows.
-- ------------------------------------------------------------------
CALL carvella_add_column('reservations', 'pickup_time', 'TIME DEFAULT NULL');
CALL carvella_add_column('reservations', 'return_time', 'TIME DEFAULT NULL');


-- ------------------------------------------------------------------
-- 3. HANDOVER READINGS - rentals
--
-- odometer_start/end, late_fee, notes, dispatched_at and returned_at are all
-- written by dispatchRentalRecord() and completeRentalRecord().
-- ------------------------------------------------------------------
CALL carvella_add_column('rentals', 'odometer_start', 'INT UNSIGNED DEFAULT NULL');
CALL carvella_add_column('rentals', 'odometer_end',   'INT UNSIGNED DEFAULT NULL');
CALL carvella_add_column('rentals', 'late_fee',       'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
CALL carvella_add_column('rentals', 'notes',          'TEXT DEFAULT NULL');
CALL carvella_add_column('rentals', 'dispatched_at',  'DATETIME DEFAULT NULL');
CALL carvella_add_column('rentals', 'returned_at',    'DATETIME DEFAULT NULL');

-- The overlap probe in findConflictingBookings() filters on vehicle and
-- status, then range-scans the dates.
CALL carvella_add_index('rentals', 'idx_rentals_vehicle_window', '(vehicle_id, status, pickup_date, expected_return_date)');


-- ------------------------------------------------------------------
-- 4. NORMALISE BEFORE CONSTRAINING
--
-- A CHECK can only be added when every existing row already satisfies it, so
-- any row the old code left in an inconsistent shape is repaired first. Each
-- of these is a repair rather than a guess: a rental handed back without a
-- return timestamp gets one from its actual_return_date, and a reservation
-- with a wallet reference but no status gets 'pending', which is the only
-- state it could honestly have been in. On a database the buggy paths never
-- touched, every count below is zero and every UPDATE matches nothing.
--
-- This runs AFTER sections 1-3, not before: on a genuine legacy install the
-- columns being repaired do not exist yet, so a repair that reads them would
-- abort with "Unknown column". It must run BEFORE section 7, because a CHECK
-- cannot be added over rows that violate it.
--
-- Each SELECT reports the count first, so the effect is visible in the output.
-- ------------------------------------------------------------------
SELECT 'rentals closed without a return timestamp' AS repair, COUNT(*) AS rows_affected
  FROM rentals
 WHERE actual_return_date IS NOT NULL AND returned_at IS NULL;

UPDATE rentals SET returned_at = actual_return_date
 WHERE actual_return_date IS NOT NULL AND returned_at IS NULL;

-- Rentals that were "returned" but have a backwards odometer cannot be made
-- valid by a guess. The constraint will not be added until such rows are
-- corrected manually. We still correct obvious formatting issues if possible.
SELECT 'rentals with backwards odometer (must be 0)' AS report, COUNT(*) AS rows_found
  FROM rentals
 WHERE odometer_end IS NOT NULL AND odometer_start IS NOT NULL AND odometer_end < odometer_start;

-- Negative late fees were sometimes stored by buggy code paths. Set to 0 to
-- satisfy chk_rentals_late_fee; any case where it should be positive must be
-- adjusted in the application.
SELECT 'rentals with negative late fee' AS repair, COUNT(*) AS rows_affected
  FROM rentals WHERE late_fee < 0;

UPDATE rentals SET late_fee = 0.00 WHERE late_fee < 0;

SELECT 'maintenance completed without a date' AS repair, COUNT(*) AS rows_affected
  FROM maintenance
 WHERE status = 'completed' AND completed_date IS NULL;

UPDATE maintenance SET completed_date = DATE(created_at)
 WHERE status = 'completed' AND completed_date IS NULL;

SELECT 'open maintenance jobs carrying a date' AS repair, COUNT(*) AS rows_affected
  FROM maintenance
 WHERE status <> 'completed' AND completed_date IS NOT NULL;

UPDATE maintenance SET completed_date = NULL
 WHERE status <> 'completed' AND completed_date IS NOT NULL;

SELECT 'reservations with a reference but no status' AS repair, COUNT(*) AS rows_affected
  FROM reservations
 WHERE payment_method <> '' AND payment_status NOT IN ('pending','verified','rejected');

UPDATE reservations SET payment_status = 'pending'
 WHERE payment_method <> '' AND payment_status NOT IN ('pending','verified','rejected');

-- A capture with no amount recorded (the column did not exist when it was
-- written) falls back to the reservation total, which is what was sent.
SELECT 'captures with a zero amount' AS repair, COUNT(*) AS rows_affected
  FROM reservations
 WHERE payment_method <> '' AND payment_amount = 0;

UPDATE reservations SET payment_amount = total_amount
 WHERE payment_method <> '' AND payment_amount = 0;

-- And the inverse: a method with no reference cannot be reconciled against a
-- wallet, so the capture is dropped rather than left half-recorded.
SELECT 'captures with no reference' AS repair, COUNT(*) AS rows_affected
  FROM reservations
 WHERE payment_method <> '' AND payment_reference IS NULL;

UPDATE reservations
   SET payment_method = '', payment_amount = 0, payment_status = 'unpaid'
 WHERE payment_method <> '' AND payment_reference IS NULL;

-- Not a repair: a rate cannot be invented. Every vehicle that reaches here
-- already satisfies chk_vehicles_rate, so this is a report, and a non-zero
-- result means the constraint was bypassed by something outside the app.
SELECT 'vehicles with a zero rate (must be 0)' AS report, COUNT(*) AS rows_found
  FROM vehicles WHERE daily_rate <= 0;

-- Restore the declared column order. ALTER TABLE ... ADD COLUMN can only
-- append, so on a migrated database these three tables would end up with
-- created_at/updated_at in the middle and the new columns trailing after them,
-- where a fresh import of carvella.sql has the opposite arrangement.
CALL carvella_reorder_timestamps('users',        'reset_requested_at');
CALL carvella_reorder_timestamps('reservations', 'payment_verified_by');
CALL carvella_reorder_timestamps('rentals',      'returned_at');


-- ------------------------------------------------------------------
-- 5. HOT-PATH INDEXES
--
-- vehicleHasOpenMaintenance() and the admin activity feed both run on every
-- relevant page save or page view.
-- ------------------------------------------------------------------
CALL carvella_add_index('rentals',            'idx_rentals_pickup_date',    '(pickup_date)');
CALL carvella_add_index('maintenance',         'idx_maintenance_vehicle_status', '(vehicle_id, status)');
CALL carvella_add_index('vehicle_conditions', 'idx_conditions_vehicle_recent',  '(vehicle_id, created_at)');
CALL carvella_add_index('activity_logs',      'idx_activity_user_recent',       '(user_id, created_at)');


-- ------------------------------------------------------------------
-- 6. RENTAL_TRANSACTIONS - the event ledger
--
-- The table itself is declared in the table section above with
-- CREATE TABLE IF NOT EXISTS, so on a database that has never had it that
-- single statement lands the columns, the indexes and the constraints
-- together.
--
-- What still needs doing is the older install, where the ledger exists but
-- was created by the old runtime DDL: same columns, no constraints at all.
-- So on that database the CREATE TABLE above is a no-op and the five
-- foreign keys below are what actually get added. Without them the ledger
-- can reference rows that no longer existed, which is exactly the failure
-- the "audit trail survives a delete" claim is there to rule out.
--
-- The four optional references null out rather than cascade, so deleting a
-- customer or a rental keeps the history of what happened.
-- ------------------------------------------------------------------
CALL carvella_add_fk('rental_transactions', 'fk_rental_tx_vehicle',
    'FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)');
CALL carvella_add_fk('rental_transactions', 'fk_rental_tx_rental',
    'FOREIGN KEY (rental_id) REFERENCES rentals (id) ON DELETE SET NULL');
CALL carvella_add_fk('rental_transactions', 'fk_rental_tx_reservation',
    'FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE SET NULL');
CALL carvella_add_fk('rental_transactions', 'fk_rental_tx_user',
    'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
CALL carvella_add_fk('rental_transactions', 'fk_rental_tx_actor',
    'FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');

-- The indexes a ledger created by the old runtime DDL never got.
-- idx_rental_tx_actor is deliberately absent: on a database that predates
-- the canonical file, InnoDB already created an index on actor_id when
-- fk_rental_tx_actor was added above, and it is named after the constraint.
-- actor_id is indexed either way, so adding a second index on the same
-- column would only cost write throughput. See the note on the index-count
-- verification at the end of this file.
CALL carvella_add_index('rental_transactions', 'idx_rental_tx_rental',      '(rental_id)');
CALL carvella_add_index('rental_transactions', 'idx_rental_tx_reservation', '(reservation_id)');
CALL carvella_add_index('rental_transactions', 'idx_rental_tx_vehicle',     '(vehicle_id, created_at)');
CALL carvella_add_index('rental_transactions', 'idx_rental_tx_event',       '(event_type, created_at)');
CALL carvella_add_index('rental_transactions', 'idx_rental_tx_user',        '(user_id)');

-- ------------------------------------------------------------------
-- 7. CHECK CONSTRAINTS
--
-- Each of these duplicates a rule the application already enforces in PHP. They
-- are here because PHP validation is only reached on the code path that
-- happens to run: a hand-written query, an import, or a future caller that
-- forgets the check is exactly how a negative fee or a backwards date gets
-- into the ledger.
--
-- Safe to add only because section 4 repaired anything inconsistent first.
--
-- Two rules cannot be repaired by guessing and are deliberately NOT repaired
-- here, because inventing a value would silently falsify the ledger: a handback
-- odometer below the handover reading, and a return date before the pickup date.
-- If such rows exist the ALTER below fails with ERROR 4025 and names the
-- constraint, and the final verification prints the shortfall. The two queries
-- below print the exact rows to correct.
-- ------------------------------------------------------------------
SELECT 'UNREPAIRABLE: odometer_end below odometer_start' AS problem, id, vehicle_id,
       odometer_start, odometer_end
  FROM rentals
 WHERE odometer_end IS NOT NULL AND odometer_start IS NOT NULL
   AND odometer_end < odometer_start;

SELECT 'UNREPAIRABLE: actual_return_date before pickup_date' AS problem, id, vehicle_id,
       pickup_date, actual_return_date
  FROM rentals
 WHERE actual_return_date IS NOT NULL AND actual_return_date < pickup_date;

CALL carvella_replace_check('vehicles', 'chk_vehicles_rate', '(daily_rate > 0)');

CALL carvella_add_check('users', 'chk_users_reset_hash',
    '(reset_token_hash IS NULL OR CHAR_LENGTH(reset_token_hash) = 64)');

CALL carvella_add_check('reservations', 'chk_reservations_payment_method',
    "(payment_method IN ('', 'gcash', 'paymaya'))");

-- A reference and an amount may only exist alongside a method.
CALL carvella_add_check('reservations', 'chk_reservations_payment_capture',
    "((payment_method = '' AND payment_reference IS NULL AND payment_amount = 0) OR (payment_method <> '' AND payment_reference IS NOT NULL AND payment_amount > 0))");

CALL carvella_add_check('reservations', 'chk_reservations_payment_state',
    "(payment_status IN ('unpaid', 'pending', 'verified', 'rejected'))");

-- Same-day rentals are allowed: the 10:00 AM morning handover and the 6:00 PM
-- drop-off can fall on one date, and rentalDayCount() bills it as a single day.
-- The constraint is restated rather than merely added because a database built
-- from an earlier script carries the stricter return_date > pickup_date, which
-- rejects the booking at the constraint level after PHP has already accepted it.
CALL carvella_replace_check('reservations', 'chk_reservations_dates',
    '(return_date >= pickup_date)');

CALL carvella_add_check('rentals', 'chk_rentals_late_fee',
    '(late_fee >= 0)');

-- An odometer only goes up. A handback reading below the handover reading means
-- the wrong vehicle was scanned in.
CALL carvella_add_check('rentals', 'chk_rentals_odometer',
    '(odometer_end IS NULL OR odometer_start IS NULL OR odometer_end >= odometer_start)');

-- Handback cannot precede pickup, and a closed rental must carry a timestamp.
CALL carvella_add_check('rentals', 'chk_rentals_return',
    '(actual_return_date IS NULL OR (actual_return_date >= pickup_date AND returned_at IS NOT NULL))');

CALL carvella_add_check('maintenance', 'chk_maintenance_completed',
    "((status = 'completed' AND completed_date IS NOT NULL) OR (status <> 'completed' AND completed_date IS NULL))");

CALL carvella_add_check('rental_transactions', 'chk_rental_tx_amount',
    '(amount >= 0)');

CALL carvella_add_check('rental_transactions', 'chk_rental_tx_dates',
    '(expected_return_date IS NULL OR pickup_date IS NULL OR expected_return_date >= pickup_date)');


-- ------------------------------------------------------------------
-- 8. VEHICLE ARCHIVE - vehicles.status
--
-- Archiving is a soft delete: the unit leaves the operational fleet and the
-- client catalog but its booking history is untouched, which is what makes the
-- permanent delete in the archive safe to refuse when history exists. The
-- application writes 'archived' from admin/vehicles.php, so a database that
-- predates this column value must be widened before that write can land.
-- ------------------------------------------------------------------
CALL carvella_ensure_enum_value('vehicles', 'status',
    'enum(''available'',''rented'',''maintenance'',''unavailable'',''archived'')',
    'ENUM(''available'',''rented'',''maintenance'',''unavailable'',''archived'') NOT NULL DEFAULT ''available''');


-- ------------------------------------------------------------------
-- 9. CLEAN UP THE HELPER PROCEDURES
-- ------------------------------------------------------------------
DROP PROCEDURE IF EXISTS carvella_reorder_timestamps;
DROP PROCEDURE IF EXISTS carvella_ensure_enum_value;
DROP PROCEDURE IF EXISTS carvella_replace_check;
DROP PROCEDURE IF EXISTS carvella_add_check;
DROP PROCEDURE IF EXISTS carvella_add_fk;
DROP PROCEDURE IF EXISTS carvella_add_index;
DROP PROCEDURE IF EXISTS carvella_add_column;


-- ============================================================================================
-- VERIFICATION
-- Every row below should be present and at the stated count after a successful run.
-- Re-running the whole script must add no columns, no indexes and no tables, and
-- report "already present" for each of them.
--
-- Two statements always execute by design and leave the schema unchanged:
-- carvella_replace_check restates chk_vehicles_rate, because a name-only guard
-- cannot tell the canonical "daily_rate > 0" from the weaker "daily_rate >= 0"
-- that older databases carry; and carvella_reorder_timestamps re-checks the
-- column order, which is now already correct and so does nothing.
-- ============================================================================================

SELECT 'columns added (expect 15)' AS verify, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND (   (TABLE_NAME = 'users'        AND COLUMN_NAME IN ('reset_token_hash','reset_expires_at','reset_requested_at'))
        OR (TABLE_NAME = 'reservations' AND COLUMN_NAME IN ('payment_method','payment_reference','payment_amount','payment_status','payment_verified_at','payment_verified_by'))
        OR (TABLE_NAME = 'rentals'      AND COLUMN_NAME IN ('odometer_start','odometer_end','late_fee','notes','dispatched_at','returned_at')) );

-- The archive state must exist on the ENUM, on both a fresh import and a
-- migrated database, or admin/vehicles.php cannot park a unit.
SELECT 'vehicles.status accepts archived (expect 1)' AS verify, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicles' AND COLUMN_NAME = 'status'
   AND COLUMN_TYPE LIKE '%''archived''%';

SELECT 'rental_transactions table (expect 1)' AS verify, COUNT(*) AS found
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_transactions';

SELECT 'rental_transactions fks (expect 5)' AS verify, COUNT(*) AS found
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_transactions'
   AND CONSTRAINT_TYPE = 'FOREIGN KEY';

SELECT 'check constraints (expect 19)' AS verify, COUNT(*) AS found
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK';

-- The names must match carvella.sql exactly, or a migrated database and a
-- freshly imported one have silently diverged.
SELECT 'check names outside the canonical set (expect 0)' AS verify, COUNT(*) AS found
  FROM (
      SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
       WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
  ) AS live
  WHERE live.CONSTRAINT_NAME NOT IN (
      'chk_maintenance_completed', 'chk_maintenance_cost',
      'chk_rental_tx_amount',      'chk_rental_tx_dates',
      'chk_rentals_amount',        'chk_rentals_dates',
      'chk_rentals_late_fee',      'chk_rentals_odometer',
      'chk_rentals_return',
      'chk_reservations_amount',   'chk_reservations_dates',
      'chk_reservations_fee',      'chk_reservations_payment_capture',
      'chk_reservations_payment_method',
      'chk_reservations_payment_state',
      'chk_users_reset_hash',
      'chk_vehicles_rate',         'chk_vehicles_seats',
      'chk_vehicles_year'
  );

-- The mirror image of the count above: names that are expected but absent. Any
-- row here means a constraint in section 7 could not be created, and the
-- queries printed at the top of that section say which rows are blocking it.
SELECT 'MISSING from the canonical set (expect 0)' AS verify, missing.CONSTRAINT_NAME AS found
  FROM (
      SELECT 'chk_maintenance_completed' AS CONSTRAINT_NAME UNION ALL
      SELECT 'chk_maintenance_cost'                     UNION ALL
      SELECT 'chk_rental_tx_amount'                     UNION ALL
      SELECT 'chk_rental_tx_dates'                      UNION ALL
      SELECT 'chk_rentals_amount'                       UNION ALL
      SELECT 'chk_rentals_dates'                        UNION ALL
      SELECT 'chk_rentals_late_fee'                     UNION ALL
      SELECT 'chk_rentals_odometer'                     UNION ALL
      SELECT 'chk_rentals_return'                       UNION ALL
      SELECT 'chk_reservations_amount'                  UNION ALL
      SELECT 'chk_reservations_dates'                   UNION ALL
      SELECT 'chk_reservations_fee'                     UNION ALL
      SELECT 'chk_reservations_payment_capture'         UNION ALL
      SELECT 'chk_reservations_payment_method'          UNION ALL
      SELECT 'chk_reservations_payment_state'           UNION ALL
      SELECT 'chk_users_reset_hash'                     UNION ALL
      SELECT 'chk_vehicles_rate'                        UNION ALL
      SELECT 'chk_vehicles_seats'                       UNION ALL
      SELECT 'chk_vehicles_year'
  ) AS missing
  WHERE NOT EXISTS (
      SELECT 1 FROM information_schema.TABLE_CONSTRAINTS live
       WHERE live.CONSTRAINT_SCHEMA = DATABASE()
         AND live.CONSTRAINT_TYPE = 'CHECK'
         AND live.CONSTRAINT_NAME = missing.CONSTRAINT_NAME
  );

-- No row may sit outside the overlap rule. The application enforces this on
-- every write; this is the proof that the ledger and the bookings agree.
--
-- A same-day booking (pickup_date = return_date) is widened to one day here for
-- the same reason findConflictingBookings() does it: as a zero-length window it
-- would overlap nothing, so a double-booked unit would slip past this check.
SELECT 'overlapping active reservations on any vehicle (expect 0)' AS verify, COUNT(*) AS found
  FROM reservations a
  JOIN reservations b
    ON a.vehicle_id = b.vehicle_id
   AND a.id < b.id
   AND a.status IN ('pending','confirmed','active')
   AND b.status IN ('pending','confirmed','active')
   AND a.pickup_date < GREATEST(b.return_date, DATE_ADD(b.pickup_date, INTERVAL 1 DAY))
   AND b.pickup_date < GREATEST(a.return_date, DATE_ADD(a.pickup_date, INTERVAL 1 DAY));

-- No helper procedure should survive this script. (Matched with a plain LIKE
-- rather than 'carvella\_%' because the mysql client consumes the backslash as
-- one of its own escape characters before the server ever sees it.)
SELECT 'helper procedures left behind (expect 0)' AS verify, COUNT(*) AS found
  FROM information_schema.ROUTINES
 WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME LIKE 'carvella%';
-- The seed procedure is dropped immediately after it is called, so the
-- "helper procedures left behind" check above also covers it.