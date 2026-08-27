<?php
// Rent payment history for the logged-in tenant, and starting an online
// rent payment via Paystack.
require_once __DIR__ . '/_bootstrap.php';
$user = requireMobileAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action !== 'initialize') {
        mobileJsonOut(['error' => 'Invalid action.'], 400);
    }

    $month = $_POST['month_covered'] ?? '';
    $months = intval($_POST['months'] ?? 1);

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        mobileJsonOut(['error' => 'Invalid billing month.'], 400);
    }
    if ($months < 1 || $months > 12) {
        mobileJsonOut(['error' => 'You can pay between 1 and 12 months of rent.'], 400);
    }
    $minMonth = date('Y-m');
    $maxMonth = date('Y-m', strtotime('+11 months'));
    if ($month < $minMonth) {
        mobileJsonOut(['error' => 'The paying-from month cannot be in the past.'], 400);
    }
    $endMonth = date('Y-m', strtotime($month . ' +' . ($months - 1) . ' months'));
    if ($endMonth > $maxMonth) {
        mobileJsonOut(['error' => 'Advance rent cannot exceed one year from now.'], 400);
    }

    $paidMonths = getTenantPaidMonths($user['id']);
    $overlap = [];
    for ($i = 0; $i < $months; $i++) {
        $m = date('Y-m', strtotime($month . ' +' . $i . ' months'));
        if (in_array($m, $paidMonths, true)) {
            $overlap[] = date('F Y', strtotime($m . '-01'));
        }
    }
    if ($overlap) {
        mobileJsonOut(['error' => 'You have already paid rent for ' . implode(', ', $overlap) . '.'], 400);
    }

    $stmt = $db->prepare("SELECT room_id, monthly_rent FROM tenancies WHERE tenant_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user['id']]);
    $ten = $stmt->fetch();
    if (!$ten) {
        mobileJsonOut(['error' => 'No active tenancy found for your account.'], 400);
    }

    $amount = round((float)$ten['monthly_rent'] * $months, 2);
    if ($amount <= 0) {
        mobileJsonOut(['error' => 'Please enter a valid amount.'], 400);
    }

    require_once __DIR__ . '/../config/paystack_helper.php';
    $email = $user['email'] ?: $user['username'] . '@pkluxury.com';
    $reference = 'PKMOB-' . strtoupper(bin2hex(random_bytes(8)));
    $metadata = ['ams_type' => 'rent', 'tenant_id' => $user['id'], 'month_covered' => $month, 'months' => $months];

    $payload = [
        'email' => $email,
        'amount' => intval(round($amount * 100)),
        'currency' => 'GHS',
        'reference' => $reference,
        'callback_url' => MOBILE_PAYSTACK_CALLBACK_URL . '&from=rent',
        'metadata' => $metadata,
    ];

    $res = paystackApiCall('POST', '/transaction/initialize', $payload);
    if (!$res || !isset($res['status']) || $res['status'] !== true) {
        $msg = ($res['message'] ?? 'Payment service is temporarily unavailable.') ?: 'Payment service is temporarily unavailable.';
        mobileJsonOut(['error' => 'Payment initialization failed: ' . $msg], 502);
    }

    mobileJsonOut(['success' => true, 'authorization_url' => $res['data']['authorization_url'], 'reference' => $reference]);
}

// GET: payment history (own record only)
$stmt = $db->prepare("SELECT rp.*, r.room_number FROM rent_payments rp JOIN rooms r ON rp.room_id = r.id WHERE rp.tenant_id = ? ORDER BY rp.payment_date DESC, rp.id DESC LIMIT 100");
$stmt->execute([$user['id']]);
mobileJsonOut(['payments' => $stmt->fetchAll()]);
