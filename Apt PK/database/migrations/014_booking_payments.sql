-- Migration 014: Add payment columns to booking_requests
-- Supports down-payment (50%) and full-payment booking options.

ALTER TABLE booking_requests
    ADD COLUMN payment_type ENUM('none','down_payment','full_payment') DEFAULT 'none' AFTER message,
    ADD COLUMN payment_amount DECIMAL(10,2) DEFAULT 0 AFTER payment_type,
    ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL AFTER payment_amount,
    ADD COLUMN payment_reference VARCHAR(100) DEFAULT NULL AFTER payment_method,
    ADD COLUMN payment_status ENUM('pending','completed','failed') DEFAULT 'pending' AFTER payment_reference;
