<?php
// Renders a printable receipt for a paid utility bill.
require_once __DIR__ . '/config/database.php';
requireLogin();

$db = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    die('Invalid bill ID.');
}

$stmt = $db->prepare("SELECT ub.*, u.full_name as tenant_name, u.phone as tenant_phone, u.email as tenant_email, r.room_number, r.room_type FROM utility_bills ub JOIN users u ON ub.tenant_id = u.id JOIN rooms r ON ub.room_id = r.id WHERE ub.id = ?");
$stmt->execute([$id]);
$bill = $stmt->fetch();

if (!$bill) {
    die('Bill not found.');
}

$user = currentUser();
if ($user['role'] === 'tenant' && $bill['tenant_id'] != $user['id']) {
    die('Access denied.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utility Bill Receipt — <?= strtoupper($bill['bill_type']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #1A1A1A; background: #f5f5f5; }
        .receipt {
            max-width: 600px;
            margin: 30px auto;
            background: #fff;
            padding: 40px;
            border: 1px solid #E0DED8;
            border-radius: 8px;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 3px solid #2C2C2C;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .receipt-header h1 { font-size: 1.5rem; color: #2C2C2C; margin-bottom: 4px; }
        .receipt-header p { color: #909090; font-size: 0.85rem; }
        .receipt-badge {
            display: inline-block;
            background: rgba(76,175,80,0.12);
            color: #2E7D32;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 10px;
        }
        .receipt-section { margin-bottom: 20px; }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #ECEAF7;
            font-size: 0.9rem;
        }
        .receipt-row:last-child { border-bottom: none; }
        .receipt-row .label { color: #909090; }
        .receipt-row .value { font-weight: 600; color: #1A1A1A; }
        .receipt-total {
            border-top: 3px solid #2C2C2C;
            margin-top: 16px;
            padding-top: 16px;
            display: flex;
            justify-content: space-between;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .receipt-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #E0DED8;
            color: #B0B0B0;
            font-size: 0.78rem;
        }
        .print-btn {
            display: block;
            max-width: 200px;
            margin: 20px auto;
            padding: 10px 24px;
            background: #2C2C2C;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            text-align: center;
        }
        .print-btn:hover { background: #181512; }
        @media print {
            .print-btn { display: none; }
            body { background: #fff; }
            .receipt { border: none; box-shadow: none; margin: 0 auto; padding: 30px; }
        }
    </style>
</head>
<body>
<a class="print-btn no-print" href="utilities.php" style="text-decoration:none;"><i class='bx bx-arrow-back'></i> Back to Utility Bills</a>
<button class="print-btn no-print" onclick="window.print()"><i class='bx bx-printer'></i> Print Receipt</button>

<div class="receipt">
    <div class="receipt-header">
        <h1><span class="brand-gold">PK's</span> Luxury Apartments</h1>
        <p>Haatso, Accra, Ghana</p>
        <p style="margin-top:2px;">Tel: 0241234567 &bull; info@pkluxury.com</p>
        <div class="receipt-badge"><i class='bx bx-check-circle'></i> Payment Confirmed</div>
    </div>

    <div class="receipt-section">
        <div class="receipt-row">
            <span class="label">Bill ID</span>
            <span class="value">#<?= $bill['id'] ?></span>
        </div>
        <div class="receipt-row">
            <span class="label">Payment Date</span>
            <span class="value"><?= $bill['payment_date'] ? date('F j, Y', strtotime($bill['payment_date'])) : '—' ?></span>
        </div>
    </div>

    <div class="receipt-section" style="background:#ECEAF7;border-radius:8px;padding:16px;">
        <div class="receipt-row" style="border-bottom-color:#D4D6E5;">
            <span class="label">Tenant Name</span>
            <span class="value"><?= sanitize($bill['tenant_name']) ?></span>
        </div>
        <div class="receipt-row" style="border-bottom-color:#D4D6E5;">
            <span class="label">Phone</span>
            <span class="value"><?= sanitize($bill['tenant_phone'] ?? 'N/A') ?></span>
        </div>
        <div class="receipt-row" style="border-bottom-color:#D4D6E5;">
            <span class="label">Room Number</span>
            <span class="value"><?= sanitize($bill['room_number']) ?> (<?= sanitize(ucfirst($bill['room_type'])) ?>)</span>
        </div>
        <div class="receipt-row" style="border-bottom:none;">
            <span class="label">Bill Type</span>
            <span class="value"><?= ucfirst($bill['bill_type']) ?></span>
        </div>
    </div>

    <div class="receipt-section">
        <div class="receipt-row">
            <span class="label">Billing Month</span>
            <span class="value"><?= date('F Y', strtotime($bill['billing_month'] . '-01')) ?></span>
        </div>
        <?php if ($bill['payment_method']): ?>
        <div class="receipt-row">
            <span class="label">Payment Method</span>
            <span class="value"><?= ucwords(str_replace('_', ' ', $bill['payment_method'])) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="receipt-total">
        <span>Amount Paid</span>
        <span style="color:#2E7D32;"><?= formatCurrency($bill['amount']) ?></span>
    </div>

    <div class="receipt-footer">
        <p>Thank you for your payment. This is a computer-generated receipt.</p>
        <p style="margin-top:4px;">PK's Luxury Apartments &bull; Haatso, Accra, Ghana</p>
        <p style="margin-top:4px;">Generated: <?= date('M j, Y g:i A') ?></p>
    </div>
</div>
</body>
</html>