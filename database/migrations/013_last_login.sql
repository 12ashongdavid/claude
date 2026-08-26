-- Migration 013: Add last_login column to users table
-- Tracks when each user last logged in; used for auto-deactivation of inactive accounts.

ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL AFTER is_active;
