-- =====================================================
-- Migration 007: Paystack as a payment method
-- Adds 'paystack' to the payment_method enums on
-- rent_payments (NOT NULL) and utility_bills (NULL).
-- =====================================================
USE pk_ams;

ALTER TABLE rent_payments
    MODIFY payment_method ENUM('cash', 'mobile_money', 'bank_transfer', 'cheque', 'paystack') NOT NULL;

ALTER TABLE utility_bills
    MODIFY payment_method ENUM('cash', 'mobile_money', 'bank_transfer', 'cheque', 'paystack');
