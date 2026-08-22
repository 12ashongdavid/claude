-- =====================================================
-- Apartment Management System — Database Schema + Seed Data
-- PK's Luxury Apartments, Haatso, Accra
--
-- This file is SELF-CONTAINED: it creates the database,
-- all tables (including every column/enum used by the app),
-- and sample seed data. Use it for a FRESH install only.
--
-- For existing databases, apply the ordered scripts in
-- database/migrations/ instead.
-- =====================================================

CREATE DATABASE IF NOT EXISTS pk_ams CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE pk_ams;

-- =====================================================
-- USERS TABLE
-- =====================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('admin', 'staff', 'tenant') NOT NULL DEFAULT 'tenant',
    profile_picture VARCHAR(255) DEFAULT 'default.png',
    date_of_birth DATE,
    must_change_password TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    last_login DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- ROOMS TABLE
-- =====================================================
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) UNIQUE NOT NULL,
    room_type VARCHAR(50) NOT NULL DEFAULT 'single',
    floor INT DEFAULT 1,
    size_sqm DECIMAL(6,2),
    rental_price DECIMAL(10,2) NOT NULL,
    status ENUM('available', 'occupied', 'maintenance') DEFAULT 'available',
    image VARCHAR(255) DEFAULT NULL,
    description TEXT,
    amenities TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- ROOM TYPES TABLE (admin-manageable, not hard-coded)
-- charge_period: how the room's rental_price is billed
-- =====================================================
CREATE TABLE room_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    charge_period ENUM('monthly', 'daily') NOT NULL DEFAULT 'monthly',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TENANCIES TABLE
-- =====================================================
CREATE TABLE tenancies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE,
    monthly_rent DECIMAL(10,2) NOT NULL,
    security_deposit DECIMAL(10,2) DEFAULT 0,
    status ENUM('active', 'expired', 'terminated') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- RENT PAYMENTS TABLE
-- =====================================================
CREATE TABLE rent_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'mobile_money', 'bank_transfer', 'cheque', 'card', 'ussd', 'qr', 'paystack') NOT NULL,
    reference_number VARCHAR(50),
    month_covered VARCHAR(7) NOT NULL,
    status ENUM('completed', 'pending', 'failed') DEFAULT 'completed',
    received_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- UTILITY BILLS TABLE
-- =====================================================
CREATE TABLE utility_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    bill_type ENUM('water', 'electricity') NOT NULL DEFAULT 'water',
    amount DECIMAL(10,2) NOT NULL,
    billing_month VARCHAR(7) NOT NULL,
    payment_date DATE,
    payment_method ENUM('cash', 'mobile_money', 'bank_transfer', 'cheque', 'card', 'ussd', 'qr', 'paystack'),
    status ENUM('paid', 'unpaid', 'overdue') DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- MAINTENANCE REQUESTS TABLE
-- =====================================================
CREATE TABLE maintenance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    room_id INT NOT NULL,
    category ENUM('plumbing', 'electrical', 'structural', 'pest_control', 'appliance', 'other') NOT NULL,
    subject VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('submitted', 'in_progress', 'resolved', 'closed') DEFAULT 'submitted',
    assigned_to INT,
    assigned_name VARCHAR(100),
    assigned_phone VARCHAR(20),
    resolution_notes TEXT,
    resolved_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- NOTIFICATIONS TABLE
-- =====================================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'success', 'payment', 'maintenance', 'utility', 'announcement') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    link VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- ROOM IMAGES TABLE (multiple images per residence)
-- =====================================================
CREATE TABLE room_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    image VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    INDEX idx_room_images_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- PAYSTACK TRANSACTIONS TABLE (idempotency + audit trail)
-- =====================================================
CREATE TABLE paystack_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(100) NOT NULL UNIQUE,
    pay_type VARCHAR(20) NOT NULL,
    tenant_id INT,
    amount DECIMAL(10,2) NOT NULL,
    bill_id INT,
    month_covered VARCHAR(7),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pst_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- BOOKING REQUESTS TABLE (Prospective Tenants)
-- =====================================================
CREATE TABLE booking_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20) NOT NULL,
    room_id INT,
    preferred_date DATE,
    message TEXT,
    payment_type ENUM('none', 'down_payment', 'full_payment') DEFAULT 'none',
    payment_amount DECIMAL(10,2) DEFAULT 0,
    payment_method VARCHAR(30) DEFAULT NULL,
    payment_reference VARCHAR(100) DEFAULT NULL,
    payment_status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- FEEDBACK / COMPLAINTS TABLE (tenants + public)
-- =====================================================
CREATE TABLE feedback_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    reporter_name VARCHAR(100) NOT NULL,
    reporter_email VARCHAR(100) DEFAULT NULL,
    reporter_phone VARCHAR(20) DEFAULT NULL,
    category ENUM('general', 'complaint', 'suggestion', 'maintenance', 'feedback', 'other') NOT NULL DEFAULT 'general',
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'in_progress', 'resolved', 'closed') DEFAULT 'new',
    admin_reply TEXT DEFAULT NULL,
    admin_reply_by INT DEFAULT NULL,
    admin_reply_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_reply_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- TENANT AGREEMENTS TABLE (admin/staff upload tenancy documents)
-- =====================================================
CREATE TABLE tenant_agreements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_size INT DEFAULT 0,
    file_type VARCHAR(50) DEFAULT 'application/pdf',
    uploaded_by INT NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_agr_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- ANNOUNCEMENTS TABLE (target_tenant_id NULL = broadcast)
-- =====================================================
CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    content TEXT NOT NULL,
    target_tenant_id INT NULL,
    created_by INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (target_tenant_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- RENT REMINDER LOG (weekly rent-due reminder tracking)
-- =====================================================
CREATE TABLE rent_reminder_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    month_covered VARCHAR(7) NOT NULL,
    week_no TINYINT NOT NULL,
    sent_at DATETIME NOT NULL,
    UNIQUE KEY uq_tenant_month_week (tenant_id, month_covered, week_no),
    KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- SEED DATA
-- =====================================================

-- Default admin (password: Admin@123 — change on first login)
INSERT INTO users (username, password, full_name, email, phone, role) VALUES
('admin', '$2y$10$68H/ROzsisuaxDQePhdQo.GXlUMdiSZWI6Hw2dveH7qHtqHcRAvYq', 'PK Admin', 'admin@pkluxury.com', '0241234567', 'admin');

-- Sample staff (password: Admin@123 — change on first login)
INSERT INTO users (username, password, full_name, email, phone, role) VALUES
('staff1', '$2y$10$68H/ROzsisuaxDQePhdQo.GXlUMdiSZWI6Hw2dveH7qHtqHcRAvYq', 'Kofi Mensah', 'kofi@pkluxury.com', '0249876543', 'staff');

-- Sample tenants (password: Admin@123 — change on first login)
INSERT INTO users (username, password, full_name, email, phone, role) VALUES
('tenant1', '$2y$10$68H/ROzsisuaxDQePhdQo.GXlUMdiSZWI6Hw2dveH7qHtqHcRAvYq', 'Ama Asante', 'ama@email.com', '0245551234', 'tenant'),
('tenant2', '$2y$10$68H/ROzsisuaxDQePhdQo.GXlUMdiSZWI6Hw2dveH7qHtqHcRAvYq', 'Kwame Boateng', 'kwame@email.com', '0245555678', 'tenant');

-- Sample room types (charge_period defaults to 'monthly')
INSERT INTO room_types (name) VALUES ('single'), ('double'), ('studio'), ('penthouse');

-- Sample rooms
INSERT INTO rooms (room_number, room_type, floor, size_sqm, rental_price, description, amenities) VALUES
('A101', 'single', 1, 18.5, 1500.00, 'Cozy single room on the ground floor', 'WiFi, Shared Bathroom, Wardrobe'),
('A102', 'single', 1, 18.5, 1500.00, 'Cozy single room on the ground floor', 'WiFi, Shared Bathroom, Wardrobe'),
('B201', 'double', 2, 28.0, 2500.00, 'Spacious double room on the second floor', 'WiFi, Private Bathroom, Kitchenette, Wardrobe'),
('B202', 'double', 2, 28.0, 2500.00, 'Spacious double room on the second floor', 'WiFi, Private Bathroom, Kitchenette, Wardrobe'),
('C301', 'studio', 3, 35.0, 3500.00, 'Modern studio apartment on the third floor', 'WiFi, Private Bathroom, Full Kitchen, Balcony'),
('D401', 'penthouse', 4, 55.0, 6000.00, 'Luxury penthouse with panoramic views', 'WiFi, En-suite, Full Kitchen, Balcony, Parking'),
('A103', 'single', 1, 18.5, 1500.00, 'Cozy single room on the ground floor', 'WiFi, Shared Bathroom, Wardrobe'),
('B302', 'double', 3, 28.0, 2500.00, 'Spacious double room on the third floor', 'WiFi, Private Bathroom, Kitchenette, Wardrobe'),
('C303', 'studio', 3, 35.0, 3500.00, 'Modern studio apartment on the third floor', 'WiFi, Private Bathroom, Full Kitchen, Balcony'),
('D402', 'penthouse', 4, 55.0, 6000.00, 'Luxury penthouse with panoramic views', 'WiFi, En-suite, Full Kitchen, Balcony, Parking');

-- Sample tenancies
INSERT INTO tenancies (tenant_id, room_id, start_date, end_date, monthly_rent, security_deposit, status) VALUES
(3, 1, '2025-01-01', '2025-12-31', 1500.00, 3000.00, 'active'),
(4, 3, '2025-03-01', '2026-02-28', 2500.00, 5000.00, 'active');

-- Update room statuses
UPDATE rooms SET status = 'occupied' WHERE id IN (1, 3);

-- Sample rent payments
INSERT INTO rent_payments (tenant_id, room_id, amount, payment_date, payment_method, reference_number, month_covered, status, received_by) VALUES
(3, 1, 1500.00, '2025-01-05', 'mobile_money', 'MM-20250105-001', '2025-01', 'completed', 2),
(3, 1, 1500.00, '2025-02-03', 'mobile_money', 'MM-20250203-001', '2025-02', 'completed', 2),
(3, 1, 1500.00, '2025-03-04', 'bank_transfer', 'BT-20250304-001', '2025-03', 'completed', 2),
(4, 3, 2500.00, '2025-03-05', 'cash', 'CASH-20250305-001', '2025-03', 'completed', 2),
(4, 3, 2500.00, '2025-04-02', 'mobile_money', 'MM-20250402-001', '2025-04', 'completed', 2);

-- Sample utility bills
INSERT INTO utility_bills (tenant_id, room_id, bill_type, amount, billing_month, payment_date, payment_method, status) VALUES
(3, 1, 'water', 85.00, '2025-03', '2025-03-10', 'cash', 'paid'),
(4, 3, 'water', 120.00, '2025-03', '2025-03-12', 'mobile_money', 'paid'),
(3, 1, 'water', 90.00, '2025-04', NULL, NULL, 'unpaid'),
(4, 3, 'water', 110.00, '2025-04', NULL, NULL, 'unpaid');

-- Sample maintenance requests
INSERT INTO maintenance_requests (tenant_id, room_id, category, subject, description, priority, status, assigned_to) VALUES
(3, 1, 'plumbing', 'Leaking faucet in bathroom', 'The bathroom faucet has been dripping constantly for two days. Water is wasting and creating puddles on the floor.', 'medium', 'in_progress', 2),
(4, 3, 'electrical', 'Power outlet not working', 'The power outlet near the bed is not working. I have tried plugging in different devices but none work.', 'high', 'submitted', NULL);

-- Sample notifications
INSERT INTO notifications (user_id, title, message, type, is_read, link) VALUES
(3, 'Rent Reminder', 'Your rent for July 2025 is due in 5 days. Please make payment to avoid late fees.', 'warning', 0, 'payments.php'),
(3, 'Maintenance Update', 'Your maintenance request "Leaking faucet" has been assigned to a technician.', 'maintenance', 0, 'maintenance.php'),
(4, 'Welcome!', 'Welcome to PK Luxury Apartments management system. Your account is now active.', 'success', 1, NULL),
(1, 'New Booking Request', 'A new booking request has been submitted for Room B202.', 'info', 0, 'bookings.php');

-- Sample announcements
INSERT INTO announcements (title, content, created_by) VALUES
('Water Maintenance Notice', 'There will be a scheduled water outage on Saturday from 9 AM to 2 PM for pipe maintenance. Please store water accordingly.', 1),
('Rent Payment Reminder', 'All tenants are reminded that rent is due by the 5th of every month. Late payments will incur a 5% penalty after the 10th.', 1);
