-- Migration 016: Add verification_code to booking_requests
-- Admin receives this code to confirm payment was made.

ALTER TABLE booking_requests
    ADD COLUMN verification_code VARCHAR(6) DEFAULT NULL AFTER payment_status;
