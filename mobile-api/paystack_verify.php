<?php
// Paystack redirects the tenant's browser here after checkout (no bearer
// token available at this point — Paystack doesn't forward one). Records
// the payment the same way the website's callback does, then bounces the
// browser back into the PWA with the outcome in the URL, since there's no
// session to stash a flash message in.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mobile_auth.php';
require_once __DIR__ . '/../config/paystack_helper.php';

$reference = $_GET['reference'] ?? '';
if ($reference === '') {
    header('Location: ' . MOBILE_APP_URL . '/?paid=0');
    exit;
}

$result = paystackRecordPayment($reference);
$status = $result['status'] ?? 'failed';

if ($status === 'recorded' || $status === 'duplicate') {
    $kind = $result['kind'] ?? 'rent';
    header('Location: ' . MOBILE_APP_URL . '/?paid=1&kind=' . urlencode($kind));
    exit;
}

header('Location: ' . MOBILE_APP_URL . '/?paid=0');
