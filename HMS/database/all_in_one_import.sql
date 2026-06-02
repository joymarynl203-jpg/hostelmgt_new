-- HMS single-file import for phpMyAdmin
-- Consolidated from:
--   database/schema.sql
--   database/migrations/001_hostel_rent_period.sql
--   database/migrations/002_payment_pay_first_deposit.sql
--   database/migrations/003_hostel_map_coordinates.sql
--   database/migrations/004_users_is_active.sql
--   database/migrations/005_users_institution.sql
--   database/migrations/006_users_nin.sql
--   database/migrations/007_super_admin_role.sql
--   database/migrations/008_password_reset_tokens.sql
--   database/migrations/009_hostels_nearby_institutions.sql
--   database/migrations/010_hostel_room_images.sql
--   database/migrations/011_hostel_room_image_galleries.sql
--
-- Recommended: create/select your DB in phpMyAdmin before import.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'warden', 'university_admin', 'super_admin') NOT NULL DEFAULT 'student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users
    ADD COLUMN reg_no VARCHAR(60) NULL AFTER created_at,
    ADD COLUMN nin VARCHAR(28) NULL DEFAULT NULL AFTER reg_no,
    ADD COLUMN phone VARCHAR(30) NULL AFTER nin;

ALTER TABLE users
    ADD COLUMN institution VARCHAR(200) NULL DEFAULT NULL AFTER phone;

ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER institution;

CREATE TABLE hostels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(255) NOT NULL,
    nearby_institutions VARCHAR(500) NULL,
    map_latitude DECIMAL(10, 7) NULL DEFAULT NULL,
    map_longitude DECIMAL(11, 7) NULL DEFAULT NULL,
    description TEXT,
    rent_period_start DATE NULL,
    rent_period_end DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE hostels ADD COLUMN managed_by INT NULL AFTER is_active;

CREATE TABLE hostel_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostel_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hostel_images_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id) ON DELETE CASCADE
);

CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hostel_id INT NOT NULL,
    room_number VARCHAR(50) NOT NULL,
    capacity INT NOT NULL,
    current_occupancy INT NOT NULL DEFAULT 0,
    gender ENUM('male', 'female', 'mixed') NOT NULL DEFAULT 'mixed',
    monthly_fee DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_rooms_hostel FOREIGN KEY (hostel_id) REFERENCES hostels(id)
        ON DELETE CASCADE
);

CREATE TABLE room_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_room_images_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
);

CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    room_id INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'checked_in', 'checked_out') NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    CONSTRAINT fk_bookings_student FOREIGN KEY (student_id) REFERENCES users(id),
    CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms(id)
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NULL,
    student_user_id INT NULL,
    room_id INT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('cash', 'mobile_money', 'bank') NOT NULL DEFAULT 'mobile_money',
    provider ENUM('mtn', 'airtel', 'other') DEFAULT 'other',
    transaction_ref VARCHAR(191),
    status ENUM('pending', 'successful', 'failed') NOT NULL DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_payments_room_prebooking ON payments (room_id, status, booking_id);

ALTER TABLE payments
    ADD COLUMN gateway VARCHAR(30) NULL AFTER provider,
    ADD COLUMN merchant_reference VARCHAR(120) NULL AFTER gateway,
    ADD COLUMN gateway_tracking_id VARCHAR(120) NULL AFTER merchant_reference,
    ADD COLUMN gateway_status VARCHAR(80) NULL AFTER gateway_tracking_id,
    ADD COLUMN callback_payload TEXT NULL AFTER gateway_status;

ALTER TABLE bookings
    ADD COLUMN months INT NOT NULL DEFAULT 1 AFTER updated_at,
    ADD COLUMN start_date DATE NULL AFTER months,
    ADD COLUMN end_date DATE NULL AFTER start_date,
    ADD COLUMN total_due DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER end_date,
    ADD COLUMN approved_by INT NULL AFTER status,
    ADD COLUMN checked_in_by INT NULL AFTER approved_by,
    ADD COLUMN checked_out_by INT NULL AFTER checked_in_by,
    ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by,
    ADD COLUMN checked_in_at TIMESTAMP NULL AFTER approved_at,
    ADD COLUMN checked_out_at TIMESTAMP NULL AFTER checked_in_at;

CREATE TABLE maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NULL,
    student_id INT NOT NULL,
    room_id INT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    CONSTRAINT fk_mr_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    CONSTRAINT fk_mr_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mr_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    type ENUM('booking', 'maintenance', 'payment', 'system') NOT NULL DEFAULT 'system',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    details TEXT,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prt_token_hash (token_hash),
    KEY idx_prt_user (user_id),
    KEY idx_prt_expires (expires_at),
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Seed fixed super admin records for portal access (idempotent by unique email)
INSERT INTO users (name, email, password_hash, role, is_active)
VALUES
    ('Super Admin 1', 'shamirah0mar915@gmail.com', '$2y$10$ZDVqhQpI03/CGwQnu5Ut4.Es1n6Xa/zVzvn/EUPV.5OcAegU4QGnW', 'super_admin', 1),
    ('Super Admin 2', 'joymarynl203@gmail.com', '$2y$10$ZDVqhQpI03/CGwQnu5Ut4.Es1n6Xa/zVzvn/EUPV.5OcAegU4QGnW', 'super_admin', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = VALUES(is_active);

SET FOREIGN_KEY_CHECKS = 1;
