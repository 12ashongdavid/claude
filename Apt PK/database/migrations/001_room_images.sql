-- =====================================================
-- Migration 001: Room images (single cover image column)
-- Adds rooms.image so each residence can have a cover photo.
-- For existing databases created before image support.
-- =====================================================
USE pk_ams;

ALTER TABLE rooms ADD COLUMN image VARCHAR(255) DEFAULT NULL AFTER status;
