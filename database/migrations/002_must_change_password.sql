-- =====================================================
-- Migration 002: Force password change on first login
-- Adds users.must_change_password so seeded accounts
-- (admin/staff/tenant) must set a new password.
-- =====================================================
USE pk_ams;

ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0 AFTER date_of_birth;
