-- =====================================================
-- Migration 003: Dynamic room types (admin-manageable)
-- 1) Create the room_types table
-- 2) Seed with the existing types (lowercase, matching rooms.room_type)
-- 3) Widen rooms.room_type from ENUM to VARCHAR so admin-added types can be stored
-- =====================================================
USE pk_ams;

CREATE TABLE IF NOT EXISTS room_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO room_types (name) VALUES ('single'), ('double'), ('studio'), ('penthouse');

ALTER TABLE rooms MODIFY room_type VARCHAR(50) NOT NULL DEFAULT 'single';
