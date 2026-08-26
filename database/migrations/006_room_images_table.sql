-- =====================================================
-- Migration 006: Room gallery (multiple images per residence)
-- Creates room_images and seeds it from the existing
-- rooms.image column so every residence has a gallery.
-- =====================================================
USE pk_ams;

CREATE TABLE IF NOT EXISTS room_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    image VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    INDEX idx_room_images_room (room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO room_images (room_id, image)
SELECT id, image FROM rooms WHERE image IS NOT NULL AND image != '';
