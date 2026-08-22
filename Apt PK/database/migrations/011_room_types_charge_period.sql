-- =====================================================
-- Migration 011: Room type billing period (BUG FIX)
-- Adds room_types.charge_period (monthly/daily). The app
-- reads and writes this column in api/rooms.php, index.php,
-- rooms.php and tenants.php; without it those pages throw
-- "Unknown column 'charge_period'".
-- =====================================================
USE pk_ams;

ALTER TABLE room_types
    ADD COLUMN charge_period ENUM('monthly', 'daily') NOT NULL DEFAULT 'monthly' AFTER name;
