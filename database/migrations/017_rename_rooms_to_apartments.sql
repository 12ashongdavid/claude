-- =====================================================
-- Migration 017: Rename rooms to apartments
-- Renames the rooms/room_types/room_images tables and every
-- room_id/room_number/room_type column (plus their auto-named
-- indexes) so the schema matches the app's "apartment"
-- terminology end to end, identically to a fresh setup.sql install.
-- =====================================================
USE pk_ams;

RENAME TABLE rooms TO apartments;
RENAME TABLE room_types TO apartment_types;
RENAME TABLE room_images TO apartment_images;

ALTER TABLE apartments
    CHANGE COLUMN room_number apartment_number VARCHAR(10) NOT NULL,
    CHANGE COLUMN room_type apartment_type VARCHAR(50) NOT NULL DEFAULT 'single',
    DROP INDEX room_number,
    ADD UNIQUE INDEX apartment_number (apartment_number);

ALTER TABLE tenancies
    CHANGE COLUMN room_id apartment_id INT NOT NULL,
    RENAME INDEX room_id TO apartment_id;

ALTER TABLE rent_payments
    CHANGE COLUMN room_id apartment_id INT NOT NULL,
    RENAME INDEX room_id TO apartment_id;

ALTER TABLE utility_bills
    CHANGE COLUMN room_id apartment_id INT NOT NULL,
    RENAME INDEX room_id TO apartment_id;

ALTER TABLE maintenance_requests
    CHANGE COLUMN room_id apartment_id INT NOT NULL,
    RENAME INDEX room_id TO apartment_id;

ALTER TABLE apartment_images
    CHANGE COLUMN room_id apartment_id INT NOT NULL,
    DROP INDEX idx_room_images_room,
    ADD INDEX idx_apartment_images_apartment (apartment_id);

ALTER TABLE booking_requests
    CHANGE COLUMN room_id apartment_id INT,
    RENAME INDEX room_id TO apartment_id;
