<?php
// =====================================================
// API: Paystack — initialize + verify callback
// PK's Luxury Apartments — Apartment Management System
// =====================================================
ob_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paystack_helper.php';

header('Content-Type: application/json');
function jsonOut($data) { ob_clean(); echo json_encode($data); if (ob_get_level()) ob_end_flush(); flush(); }

// ---- Verify callback (no session required; Paystack redirects here) ----
if (($_GET['action'] ?? '') === 'verify') {
    $reference = $_GET['reference'] ?? '';
    if ($reference === '') {
        header('Location: ../index.php');
        exit;
    }
    $from = ($_GET['from'] ?? '') === 'utility' ? 'utility' : (($_GET['from'] ?? '') === 'booking' ? 'booking' : 'rent');
    $result = paystackRecordPayment($reference);
    $status = $result['status'] ?? 'failed';

    if ($status === 'recorded') {
        $id = intval($result['id'] ?? 0);
        if (($result['kind'] ?? '') === 'utility') {
            setFlash('success', 'Your utility bill was paid successfully and recorded automatically.');
            header('Location: ../dashboard.php');
        } elseif (($result['kind'] ?? '') === 'booking') {
            setFlash('success', 'Your booking payment was successful and recorded automatically.');
            header('Location: ../index.php');
        } else {
            setFlash('success', 'Your rent payment was successful and recorded automatically.');
            header('Location: ../dashboard.php');
        }
        exit;
    }

    if ($status === 'duplicate') {
        setFlash('success', 'This payment was already recorded successfully.');
        header('Location: ../dashboard.php');
        exit;
    }

    setFlash('error', 'Payment was not completed — no charge was made. You can try again.');
    if ($from === 'utility') {
        header('Location: ../utilities.php?paid=0');
    } elseif ($from === 'booking') {
        header('Location: ../booking.php?paid=0');
    } else {
        header('Location: ../payments.php?paid=0');
    }
    exit;
}

// ---- Everything else requires a logged-in session ----
requireLogin();
$db = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Invalid request.']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf)) {
    jsonOut(['error' => 'Invalid security token.']);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action !== 'initialize') {
    jsonOut(['error' => 'Invalid action.']);
    exit;
}

// Only tenants can pay for themselves via Paystack
if ($user['role'] !== 'tenant') {
    http_response_code(403);
    jsonOut(['error' => 'Only tenants can make payments online.']);
    exit;
}

$type = $_POST['type'] ?? '';

if ($type === 'rent') {
    $month = $_POST['month_covered'] ?? '';
    $months = intval($_POST['months'] ?? 1);

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        jsonOut(['error' => 'Invalid billing month.']);
        exit;
    }
    if ($months < 1 || $months > 12) {
        jsonOut(['error' => 'You can pay between 1 and 12 months of rent.']);
        exit;
    }
    $minMonth = date('Y-m');
    $maxMonth = date('Y-m', strtotime('+11 months'));
    if ($month < $minMonth) {
        jsonOut(['error' => 'The paying-from month cannot be in the past.']);
        exit;
    }
    $endMonth = date('Y-m', strtotime($month . ' +' . ($months - 1) . ' months'));
    if ($endMonth > $maxMonth) {
        jsonOut(['error' => 'Advance rent cannot exceed one year from now (up to ' . date('F Y', strtotime($maxMonth . '-01')) . ').']);
        exit;
    }

    // Never charge for months the tenant has already paid for
    $paidMonths = getTenantPaidMonths($user['id']);
    $overlap = [];
    for ($i = 0; $i < $months; $i++) {
        $m = date('Y-m', strtotime($month . ' +' . $i . ' months'));
        if (in_array($m, $paidMonths, true)) {
            $overlap[] = date('F Y', strtotime($m . '-01'));
        }
    }
    if ($overlap) {
        jsonOut(['error' => 'You have already paid rent for ' . implode(', ', $overlap) . '. Please select only months you have not paid for.']);
        exit;
    }

    $stmt = $db->prepare("SELECT room_id, monthly_rent FROM tenancies WHERE tenant_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user['id']]);
    $ten = $stmt->fetch();
    if (!$ten) {
        jsonOut(['error' => 'No active tenancy found for your account.']);
        exit;
    }

    // Server-side amount: months x monthly rent (never trusts the client)
    $amount = round((float)$ten['monthly_rent'] * $months, 2);
    if ($amount <= 0) {
        jsonOut(['error' => 'Please enter a valid amount.']);
        exit;
    }

    $metadata = ['ams_type' => 'rent', 'tenant_id' => $user['id'], 'month_covered' => $month, 'months' => $months];
} elseif ($type === 'utility') {
    $billId = intval($_POST['bill_id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM utility_bills WHERE id = ? AND tenant_id = ? AND status != 'paid'");
    $stmt->execute([$billId, $user['id']]);
    $bill = $stmt->fetch();
    if (!$bill) {
        jsonOut(['error' => 'Bill not found or already paid.']);
        exit;
    }
    $amount = round(floatval($bill['amount']), 2);
    $metadata = ['ams_type' => 'utility', 'tenant_id' => $user['id'], 'bill_id' => $billId];
} else {
    jsonOut(['error' => 'Invalid payment type.']);
    exit;
}

$email = $user['email'] ?: $user['username'] . '@pkluxury.com';
$reference = 'PKAMS-' . strtoupper(bin2hex(random_bytes(8)));

$payload = [
    'email' => $email,
    'amount' => intval(round($amount * 100)),
    'currency' => 'GHS',
    'reference' => $reference,
    'callback_url' => PAYSTACK_CALLBACK_URL . '&from=' . $type,
    'metadata' => $metadata,
];

$res = paystackApiCall('POST', '/transaction/initialize', $payload);
if (!$res || !isset($res['status']) || $res['status'] !== true) {
    $msg = ($res['message'] ?? 'Payment service is temporarily unavailable.') ?: 'Payment service is temporarily unavailable.';
    jsonOut(['error' => 'Payment initialization failed: ' . $msg]);
    exit;
}

jsonOut([
    'success' => true,
    'authorization_url' => $res['data']['authorization_url'],
    'reference' => $reference,
]);
