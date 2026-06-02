-- Hostel Management System — PostgreSQL (Render Postgres)
-- Run once: psql "$DATABASE_URL" -f HMS/database/schema.postgresql.sql

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'student'
        CHECK (role IN ('student', 'warden', 'university_admin', 'super_admin')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reg_no VARCHAR(60) NULL,
    nin VARCHAR(28) NULL,
    phone VARCHAR(30) NULL,
    institution VARCHAR(200) NULL,
    is_active SMALLINT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS hostels (
    id SERIAL PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    location VARCHAR(255) NOT NULL,
    nearby_institutions VARCHAR(500) NULL,
    map_latitude DECIMAL(10, 7) NULL,
    map_longitude DECIMAL(11, 7) NULL,
    description TEXT,
    rent_period_start DATE NULL,
    rent_period_end DATE NULL,
    is_active SMALLINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    managed_by INT NULL REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS hostel_images (
    id SERIAL PRIMARY KEY,
    hostel_id INT NOT NULL REFERENCES hostels(id) ON DELETE CASCADE,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rooms (
    id SERIAL PRIMARY KEY,
    hostel_id INT NOT NULL REFERENCES hostels(id) ON DELETE CASCADE,
    room_number VARCHAR(50) NOT NULL,
    capacity INT NOT NULL,
    current_occupancy INT NOT NULL DEFAULT 0,
    gender VARCHAR(10) NOT NULL DEFAULT 'mixed'
        CHECK (gender IN ('male', 'female', 'mixed')),
    monthly_fee DECIMAL(12, 2) NOT NULL
);

CREATE TABLE IF NOT EXISTS room_images (
    id SERIAL PRIMARY KEY,
    room_id INT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bookings (
    id SERIAL PRIMARY KEY,
    student_id INT NOT NULL REFERENCES users(id),
    room_id INT NOT NULL REFERENCES rooms(id),
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'approved', 'rejected', 'checked_in', 'checked_out')),
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    months INT NOT NULL DEFAULT 1,
    start_date DATE NULL,
    end_date DATE NULL,
    total_due DECIMAL(12, 2) NOT NULL DEFAULT 0,
    approved_by INT NULL,
    checked_in_by INT NULL,
    checked_out_by INT NULL,
    approved_at TIMESTAMP NULL,
    checked_in_at TIMESTAMP NULL,
    checked_out_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    booking_id INT NULL REFERENCES bookings(id) ON DELETE CASCADE,
    student_user_id INT NULL,
    room_id INT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    method VARCHAR(20) NOT NULL DEFAULT 'mobile_money'
        CHECK (method IN ('cash', 'mobile_money', 'bank')),
    provider VARCHAR(10) DEFAULT 'other'
        CHECK (provider IN ('mtn', 'airtel', 'other')),
    transaction_ref VARCHAR(191),
    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'successful', 'failed')),
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    gateway VARCHAR(30) NULL,
    merchant_reference VARCHAR(120) NULL,
    gateway_tracking_id VARCHAR(120) NULL,
    gateway_status VARCHAR(80) NULL,
    callback_payload TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_payments_room_prebooking ON payments (room_id, status, booking_id);

CREATE TABLE IF NOT EXISTS maintenance_requests (
    id SERIAL PRIMARY KEY,
    booking_id INT NULL REFERENCES bookings(id) ON DELETE SET NULL,
    student_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    room_id INT NULL REFERENCES rooms(id) ON DELETE SET NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open', 'in_progress', 'resolved', 'closed')),
    priority VARCHAR(10) NOT NULL DEFAULT 'medium'
        CHECK (priority IN ('low', 'medium', 'high')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'system'
        CHECK (type IN ('booking', 'maintenance', 'payment', 'system')),
    is_read SMALLINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id SERIAL PRIMARY KEY,
    actor_user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    details TEXT,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_prt_user ON password_reset_tokens (user_id);
CREATE INDEX IF NOT EXISTS idx_prt_expires ON password_reset_tokens (expires_at);

INSERT INTO users (name, email, password_hash, role, is_active)
VALUES
    ('Super Admin 1', 'shamirah0mar915@gmail.com', '$2y$10$ZDVqhQpI03/CGwQnu5Ut4.Es1n6Xa/zVzvn/EUPV.5OcAegU4QGnW', 'super_admin', 1),
    ('Super Admin 2', 'joymarynl203@gmail.com', '$2y$10$ZDVqhQpI03/CGwQnu5Ut4.Es1n6Xa/zVzvn/EUPV.5OcAegU4QGnW', 'super_admin', 1)
ON CONFLICT (email) DO UPDATE SET
    name = EXCLUDED.name,
    password_hash = EXCLUDED.password_hash,
    role = EXCLUDED.role,
    is_active = EXCLUDED.is_active;
