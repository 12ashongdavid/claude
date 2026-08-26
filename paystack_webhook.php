<?php
// =====================================================
// Paystack Webhook — auto-records successful charges
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/paystack_helper.php';

$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$computed = hash_hmac('sha512', $input, PAYSTACK_SECRET_KEY);

if (!hash_equals($computed, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = json_decode($input, true);
if (($event['event'] ?? '') === 'charge.success') {
    $reference = $event['data']['reference'] ?? '';
    if ($reference !== '') {
        paystackRecordPayment($reference);
    }
}

http_response_code(200);
echo 'OK';
