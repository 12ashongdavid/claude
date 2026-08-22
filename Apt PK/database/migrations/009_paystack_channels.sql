-- =====================================================
-- Migration 009: Paystack channel fidelity
-- Widen payment_method enums so online payments record
-- the actual medium used (Momo, Bank, Card, USSD, QR),
-- not just 'paystack'.
-- =====================================================
USE pk_ams;

ALTER TABLE rent_payments
    MODIFY payment_method ENUM('cash', 'mobile_money', 'bank_transfer', 'cheque', 'card', 'ussd', 'qr', 'paystack') NOT NULL;

ALTER TABLE utility_bills
    MODIFY payment_method ENUM('cash', 'mobile_money', 'bank_transfer', 'cheque', 'card', 'ussd', 'qr', 'paystack') NULL;
