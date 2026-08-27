<?php
// Paystack API calls and payment recording, shared by api/paystack.php and the webhook.

// Low-level Paystack API call (best-effort cURL)
function paystackApiCall($method, $endpoint, $payload = null) {
    $url = PAYSTACK_API . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $result = curl_exec($ch);
    curl_close($ch);
    if (!$result) {
        return null;
    }
    return json_decode($result, true);
}

// Verify a Paystack transaction reference
function paystackVerify($reference) {
    $res = paystackApiCall('GET', '/transaction/verify/' . urlencode($reference));
    if (!$res || !isset($res['status']) || $res['status'] !== true) {
        return null;
    }
    return $res['data'];
}

// Map a Paystack channel to the AMS payment_method value
// (records the actual medium the tenant used — Momo, Bank, Card, etc.)
function paystackMethodFromChannel($channel) {
    $c = strtolower((string)$channel);
    if (in_array($c, ['mobile_money', 'momo', 'mobilemoney', 'mtn_mobile_money', 'vodafone_cash', 'airteltigo_money'])) {
        return 'mobile_money';
    }
    if (in_array($c, ['bank', 'bank_transfer', 'transfer', 'pay_with_bank', 'eft', 'nuban'])) {
        return 'bank_transfer';
    }
    if ($c === 'card') return 'card';
    if ($c === 'ussd') return 'ussd';
    if ($c === 'qr') return 'qr';
    return 'paystack';
}

// Human-readable payment method label (e.g. mobile_money -> Mobile Money)
function paystackMethodLabel($method) {
    return ucwords(str_replace('_', ' ', (string)$method));
}

// Record a successful Paystack charge into the AMS ledger.
// Idempotent: returns 'duplicate' if the reference was already recorded.
function paystackRecordPayment($reference) {
    $db = getDB();

    // Idempotency guard
    $stmt = $db->prepare("SELECT id FROM paystack_transactions WHERE reference = ?");
    $stmt->execute([$reference]);
    if ($stmt->fetch()) {
        return ['status' => 'duplicate', 'kind' => ''];
    }

    $txn = paystackVerify($reference);
    if (!$txn) {
        return ['status' => 'failed', 'message' => 'Could not verify transaction. Please try again.'];
    }
    if (($txn['status'] ?? '') !== 'success') {
        return ['status' => 'failed', 'message' => 'Transaction is not successful.'];
    }

    $meta = $txn['metadata'] ?? [];
    $type = $meta['ams_type'] ?? '';
    $tenantId = intval($meta['tenant_id'] ?? 0);
    $amount = round(intval($txn['amount'] ?? 0) / 100, 2);
    $paidAt = substr($txn['paid_at'] ?? date('Y-m-d'), 0, 10);
    $channel = $txn['channel'] ?? ($txn['authorization']['channel'] ?? '');
    $method = paystackMethodFromChannel($channel);
    $methodLabel = paystackMethodLabel($method);

    if ($type === 'rent') {
        $month = preg_match('/^\d{4}-\d{2}$/', (string)($meta['month_covered'] ?? '')) ? $meta['month_covered'] : date('Y-m');
        $months = max(1, min(12, intval($meta['months'] ?? 1)));

        $stmt = $db->prepare("SELECT room_id FROM tenancies WHERE tenant_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$tenantId]);
        $ten = $stmt->fetch();
        if (!$ten) {
            return ['status' => 'failed', 'message' => 'No active tenancy found for tenant.'];
        }

        // Advance rent: split the total across each covered month (same reference)
        // Defense-in-depth: never insert a month that is already covered.
        $existing = $db->prepare("SELECT month_covered FROM rent_payments WHERE tenant_id = ? AND status = 'completed'");
        $existing->execute([$tenantId]);
        $coveredSet = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));
        $perMonth = round($amount / $months, 2);
        $stmtIns = $db->prepare("INSERT INTO rent_payments (tenant_id, room_id, amount, payment_date, payment_method, reference_number, month_covered, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 'Auto-recorded via Paystack')");
        $endMonth = $month;
        $lastPaymentId = 0;
        for ($i = 0; $i < $months; $i++) {
            $m = date('Y-m', strtotime($month . ' +' . $i . ' months'));
            if (isset($coveredSet[$m])) {
                return ['status' => 'duplicate', 'kind' => 'rent'];
            }
            $stmtIns->execute([$tenantId, $ten['room_id'], $perMonth, $paidAt, $method, $reference, $m]);
            $lastPaymentId = (int)$db->lastInsertId();
            $endMonth = $m;
            $coveredSet[$m] = true;
        }

        $stmt = $db->prepare("INSERT INTO paystack_transactions (reference, pay_type, tenant_id, amount, month_covered) VALUES (?, 'rent', ?, ?, ?)");
        $stmt->execute([$reference, $tenantId, $amount, $month]);

        $monthLabel = $months > 1 ? $month . ' to ' . $endMonth . ' (' . $months . ' months)' : $month;

        $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Payment Received', ?, 'payment')");
            $stmt->execute([$tenantId, "Your rent payment of " . formatCurrency($amount) . " for $monthLabel has been received via $methodLabel. Ref: $reference"]);
            sendSMS($tenant['phone'], "Dear " . $tenant['full_name'] . ", your rent payment of GH₵ " . number_format($amount, 2) . " for $monthLabel has been received via $methodLabel. Ref: $reference. Thank you!");
        }

        return ['status' => 'recorded', 'kind' => 'rent', 'id' => $lastPaymentId];
    }

    if ($type === 'utility') {
        $billId = intval($meta['bill_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM utility_bills WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$billId, $tenantId]);
        $bill = $stmt->fetch();
        if (!$bill) {
            return ['status' => 'failed', 'message' => 'Utility bill not found for tenant.'];
        }
        if ($bill['status'] === 'paid') {
            return ['status' => 'duplicate', 'kind' => 'utility'];
        }

        $stmt = $db->prepare("UPDATE utility_bills SET status = 'paid', payment_date = ?, payment_method = ? WHERE id = ?");
        $stmt->execute([$paidAt, $method, $billId]);

        $stmt = $db->prepare("INSERT INTO paystack_transactions (reference, pay_type, tenant_id, amount, bill_id) VALUES (?, 'utility', ?, ?, ?)");
        $stmt->execute([$reference, $tenantId, $amount, $billId]);

        $stmt = $db->prepare("SELECT full_name, phone FROM users WHERE id = ?");
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();
        if ($tenant) {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Utility Bill Paid', ?, 'utility')");
            $stmt->execute([$tenantId, "Your " . $bill['bill_type'] . " bill of " . formatCurrency($amount) . " for " . $bill['billing_month'] . " has been paid via $methodLabel. Ref: $reference"]);
            sendSMS($tenant['phone'], "Dear " . $tenant['full_name'] . ", your " . $bill['bill_type'] . " bill of GH₵ " . number_format($amount, 2) . " for " . $bill['billing_month'] . " has been paid via $methodLabel. Ref: $reference. Thank you!");
        }

        return ['status' => 'recorded', 'kind' => 'utility', 'id' => $billId];
    }

    if ($type === 'booking') {
        $bookingId = intval($meta['booking_id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM booking_requests WHERE id = ?");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();
        if (!$booking) {
            return ['status' => 'failed', 'message' => 'Booking request not found.'];
        }
        if ($booking['payment_status'] === 'completed') {
            return ['status' => 'duplicate', 'kind' => 'booking'];
        }

        $stmt = $db->prepare("UPDATE booking_requests SET payment_status = 'completed', payment_reference = ? WHERE id = ?");
        $stmt->execute([$reference, $bookingId]);

        $stmt = $db->prepare("INSERT INTO paystack_transactions (reference, pay_type, tenant_id, amount) VALUES (?, 'booking', 0, ?)");
        $stmt->execute([$reference, $amount]);

        sendSMS($booking['phone'], "Dear " . $booking['full_name'] . ", your booking payment of GH₵ " . number_format($amount, 2) . " has been received via $methodLabel. Ref: $reference. Thank you!");

        return ['status' => 'recorded', 'kind' => 'booking', 'id' => $bookingId];
    }

    return ['status' => 'failed', 'message' => 'Unknown payment type.'];
}
