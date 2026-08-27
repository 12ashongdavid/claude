<?php
// Utility bills for the logged-in tenant, and starting an online payment
// for one via Paystack.
require_once __DIR__ . '/_bootstrap.php';
$user = requireMobileAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action !== 'initialize') {
        mobileJsonOut(['error' => 'Invalid action.'], 400);
    }

    $billId = intval($_POST['bill_id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM utility_bills WHERE id = ? AND tenant_id = ? AND status != 'paid'");
    $stmt->execute([$billId, $user['id']]);
    $bill = $stmt->fetch();
    if (!$bill) {
        mobileJsonOut(['error' => 'Bill not found or already paid.'], 404);
    }
    $amount = round(floatval($bill['amount']), 2);

    require_once __DIR__ . '/../config/paystack_helper.php';
    $email = $user['email'] ?: $user['username'] . '@pkluxury.com';
    $reference = 'PKMOB-' . strtoupper(bin2hex(random_bytes(8)));
    $metadata = ['ams_type' => 'utility', 'tenant_id' => $user['id'], 'bill_id' => $billId];

    $payload = [
        'email' => $email,
        'amount' => intval(round($amount * 100)),
        'currency' => 'GHS',
        'reference' => $reference,
        'callback_url' => MOBILE_PAYSTACK_CALLBACK_URL . '&from=utility',
        'metadata' => $metadata,
    ];

    $res = paystackApiCall('POST', '/transaction/initialize', $payload);
    if (!$res || !isset($res['status']) || $res['status'] !== true) {
        $msg = ($res['message'] ?? 'Payment service is temporarily unavailable.') ?: 'Payment service is temporarily unavailable.';
        mobileJsonOut(['error' => 'Payment initialization failed: ' . $msg], 502);
    }

    mobileJsonOut(['success' => true, 'authorization_url' => $res['data']['authorization_url'], 'reference' => $reference]);
}

// GET: own bills only
$stmt = $db->prepare("SELECT * FROM utility_bills WHERE tenant_id = ? ORDER BY billing_month DESC, id DESC LIMIT 100");
$stmt->execute([$user['id']]);
mobileJsonOut(['bills' => $stmt->fetchAll()]);
