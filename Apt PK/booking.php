<?php
// Standalone booking page — walks a prospective tenant through their details and an optional deposit payment.
require_once __DIR__ . '/config/database.php';
$db = getDB();

$user = isLoggedIn() ? currentUser() : null;
$paymentEnabled = true;

$availableRooms = $db->query("SELECT r.*, rt.charge_period FROM rooms r LEFT JOIN room_types rt ON rt.name = r.room_type WHERE r.status = 'available' ORDER BY r.rental_price ASC")->fetchAll();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $room_id = !empty($_POST['room_id']) ? intval($_POST['room_id']) : null;
        $preferred_date = $_POST['preferred_date'] ?? null;
        $message = trim($_POST['message'] ?? '');
        $payment_type = $_POST['payment_type'] ?? 'none';
        $payment_method = $_POST['payment_method'] ?? 'paystack';

        $emailCheck = validateEmailDetailed($email);
        if (empty($full_name) || empty($phone) || empty($email)) {
            $error = 'Please provide your name, phone number, and email address.';
        } elseif (!validatePhone($phone)) {
            $error = 'Phone number must be exactly 10 digits.';
        } elseif (!$emailCheck['valid']) {
            $error = $emailCheck['message'];
        } else {
            $payment_amount = 0;
            $payment_reference = '';
            $payment_status = 'pending';

            if ($payment_type !== 'none' && $room_id) {
                $room = $db->prepare("SELECT rental_price FROM rooms WHERE id = ?");
                $room->execute([$room_id]);
                $roomData = $room->fetch();
                if ($roomData) {
                    $payment_amount = $payment_type === 'down_payment'
                        ? round(floatval($roomData['rental_price']) * 0.5, 2)
                        : round(floatval($roomData['rental_price']), 2);
                }
            }

            if ($payment_type !== 'none' && $payment_method === 'bank_transfer') {
                $payment_status = 'pending';
            } elseif ($payment_type !== 'none' && $payment_method === 'cash') {
                $payment_status = 'pending';
            }

            $stmt = $db->prepare("INSERT INTO booking_requests (full_name, email, phone, room_id, preferred_date, message, payment_type, payment_amount, payment_method, payment_reference, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$full_name, $email, $phone, $room_id, $preferred_date, $message, $payment_type, $payment_amount, $payment_method, $payment_reference, $payment_status]);
            $bookingId = $db->lastInsertId();

            // Mark residence as occupied when a booking with payment is made
            if ($payment_type !== 'none' && $room_id) {
                $db->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ? AND status = 'available'")->execute([$room_id]);
            }

            $admins = $db->query("SELECT id, phone FROM users WHERE role = 'admin'")->fetchAll();
            $room_label = $room_id ? " (Residence #" . $room_id . ")" : "";
            $payment_note = $payment_type !== 'none' ? " Payment: " . formatCurrency($payment_amount) . "." : "";
            $notifMsg = "$full_name has submitted a booking request$room_label.$payment_note";
            if ($admins) {
                $placeholders = implode(',', array_fill(0, count($admins), '(?, ?, ?, ?)'));
                $notifSql = "INSERT INTO notifications (user_id, title, message, type) VALUES $placeholders";
                $notifParams = [];
                foreach ($admins as $admin) {
                    $notifParams[] = $admin['id'];
                    $notifParams[] = 'New Booking Request';
                    $notifParams[] = $notifMsg;
                    $notifParams[] = 'info';
                }
                $db->prepare($notifSql)->execute($notifParams);
                sendSMS($admins[0]['phone'], "New booking request from $full_name$room_label. Phone: $phone." . $payment_note);
            }

            sendSMS($phone, "Dear $full_name, your booking request has been received" . ($payment_type !== 'none' ? " with a payment of " . formatCurrency($payment_amount) : "") . ". PK's Luxury Apartments will contact you within 24 hours.");

            $success = 'Your booking request has been submitted! We will contact you within 24 hours.';

            // Initialize Paystack if needed
            if ($payment_type !== 'none' && $payment_method === 'paystack' && $payment_amount > 0) {
                $email_for_ps = !empty($email) ? $email : 'guest@pkluxury.com';
                $reference = 'PKBOOK-' . strtoupper(bin2hex(random_bytes(8)));
                $metadata = ['ams_type' => 'booking', 'booking_id' => intval($bookingId), 'tenant_id' => 0];

                $payload = [
                    'email' => $email_for_ps,
                    'amount' => intval(round($payment_amount * 100)),
                    'currency' => 'GHS',
                    'reference' => $reference,
                    'callback_url' => PAYSTACK_CALLBACK_URL . '&from=booking',
                    'metadata' => $metadata,
                ];

                require_once __DIR__ . '/config/paystack_helper.php';
                $res = paystackApiCall('POST', '/transaction/initialize', $payload);
                if ($res && isset($res['status']) && $res['status'] === true) {
                    header('Location: ' . $res['data']['authorization_url']);
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Residence — PK's Luxury Apartments</title>
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="css/style.css?v=18">
    <script>
    (function(){
        var saved = localStorage.getItem('pk-theme');
        var dark = saved || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', dark);
    })();
    </script>
    <style>
        .booking-page { min-height:100vh; display:flex; flex-direction:column; }
        .booking-page .booking-form-section { flex:1; display:flex; align-items:center; justify-content:center; padding:40px 20px; }
        .booking-steps { display:flex; gap:0; margin-bottom:32px; max-width:500px; margin-left:auto; margin-right:auto; }
        .booking-step { flex:1; text-align:center; padding:12px 8px; position:relative; font-size:0.82rem; font-weight:600; color:var(--text-muted); }
        .booking-step.active { color:var(--primary); }
        .booking-step.done { color:var(--success); }
        .booking-step::after { content:''; position:absolute; bottom:0; left:0; right:0; height:3px; background:var(--border); border-radius:2px; }
        .booking-step.active::after { background:var(--primary); }
        .booking-step.done::after { background:var(--success); }
        .price-display { font-size:1.1rem; font-weight:700; color:var(--primary); margin-top:4px; }
        .payment-options { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:8px; }
        .payment-option { padding:12px; border:2px solid var(--border); border-radius:var(--radius); cursor:pointer; text-align:center; transition:var(--spring); }
        .payment-option:hover { border-color:var(--primary); }
        .payment-option.selected { border-color:var(--primary); background:var(--primary-bg); }
        .payment-option i { font-size:1.5rem; display:block; margin-bottom:4px; color:var(--primary); }
        .payment-option span { font-size:0.82rem; font-weight:600; }
    </style>
</head>
<body class="booking-page">
    <header style="padding:16px 24px;background:var(--bg);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
        <a href="index.php" style="text-decoration:none;color:var(--text);font-weight:700;font-size:1.1rem;"><i class="bx bx-home"></i> PK's Luxury Apartments</a>
        <div style="display:flex;gap:12px;align-items:center;">
            <a href="index.php" style="text-decoration:none;color:var(--text-secondary);font-size:0.85rem;">Back to Home</a>
            <?php if ($user): ?>
                <a href="dashboard.php" class="btn btn-sm btn-outline">Dashboard</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-sm btn-primary">Sign In</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="booking-form-section">
        <div class="booking-form-card" style="max-width:560px;width:100%;">
            <div class="booking-steps">
                <div class="booking-step active" id="step1Indicator"><i class="bx bx-user"></i> Your Details</div>
                <div class="booking-step" id="step2Indicator"><i class="bx bx-credit-card"></i> Deposit</div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= sanitize($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= sanitize($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="booking.php" id="bookingForm">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                <!-- Step 1: Details -->
                <div id="step1">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" class="form-control" placeholder="Your full name"
                                value="<?= sanitize($user ? $user['full_name'] : ($_POST['full_name'] ?? '')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" name="phone" class="form-control" placeholder="024XXXXXXX" pattern="[0-9]{10}" maxlength="10"
                                oninput="this.value=this.value.replace(/[^0-9]/g,'')"
                                value="<?= sanitize($user ? $user['phone'] : ($_POST['phone'] ?? '')) ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" class="form-control" placeholder="e.g. name@example.com" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"
                            value="<?= sanitize($user ? ($user['email'] ?? '') : ($_POST['email'] ?? '')) ?>" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Preferred Residence *</label>
                            <select name="room_id" id="bookingRoomSelect" class="form-control" required onchange="updateBookingPrice()">
                                <option value="">Select a residence...</option>
                                <?php foreach ($availableRooms as $r): ?>
                                <option value="<?= $r['id'] ?>"
                                    data-price="<?= $r['rental_price'] ?>"
                                    data-period="<?= ($r['charge_period'] ?? 'monthly') === 'daily' ? 'day' : 'month' ?>"
                                    <?= (isset($_POST['room_id']) && $_POST['room_id'] == $r['id']) ? 'selected' : '' ?>>
                                    <?= sanitize($r['room_number']) ?> — <?= ucfirst($r['room_type']) ?> — GH&#8373; <?= number_format($r['rental_price'], 0) ?>/<?= ($r['charge_period'] ?? 'monthly') === 'daily' ? 'day' : 'mo' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Preferred Move-in Date</label>
                            <input type="date" name="preferred_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= sanitize($_POST['preferred_date'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" class="form-control" rows="3" placeholder="Any special requests..."><?= sanitize($_POST['message'] ?? '') ?></textarea>
                    </div>
                    <button type="button" class="btn btn-primary btn-block btn-lg" onclick="goToStep2()">Continue to Deposit</button>
                </div>

                <!-- Step 2: Payment -->
                <div id="step2" style="display:none;">
                    <div id="selectedRoomSummary" style="padding:16px;background:var(--surface);border-radius:var(--radius);margin-bottom:20px;text-align:center;">
                        <div style="font-size:0.82rem;color:var(--text-muted);margin-bottom:4px;">Selected Residence</div>
                        <div id="summaryRoomName" style="font-weight:700;font-size:1.05rem;"></div>
                        <div id="summaryRoomPrice" class="price-display"></div>
                    </div>

                    <div class="form-group">
                        <label>Deposit Option *</label>
                        <div class="payment-options">
                            <div class="payment-option" onclick="selectPaymentType('none', this)">
                                <i class="bx bx-time-five"></i>
                                <span>Pay Later</span>
                            </div>
                            <div class="payment-option" onclick="selectPaymentType('down_payment', this)" id="downPayOption">
                                <i class="bx bx-dollar"></i>
                                <span>50% Deposit Now</span>
                            </div>
                            <div class="payment-option" onclick="selectPaymentType('full_payment', this)" id="fullPayOption">
                                <i class="bx bx-check-circle"></i>
                                <span>Pay Full Amount</span>
                            </div>
                        </div>
                        <input type="hidden" name="payment_type" id="paymentTypeInput" value="none">
                        <div style="padding:10px 16px;background:var(--warning-bg);border-radius:var(--radius);margin-top:10px;font-size:0.82rem;color:var(--warning);border:1px solid var(--warning);display:flex;align-items:center;gap:8px;">
                            <i class='bx bx-info-circle' style="font-size:1.1rem;flex-shrink:0;"></i>
                            <span><strong>Note:</strong> Balance must be paid within 1 week of move-in.</span>
                        </div>
                    </div>

                    <div id="paymentMethodSection" style="display:none;">
                        <div class="form-group">
                            <label>Payment Method</label>
                            <select name="payment_method" id="paymentMethodInput" class="form-control" onchange="updatePaymentMethodInfo()">
                                <option value="paystack">Mobile Money</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div id="paymentMethodInfo" style="padding:12px;background:var(--surface);border-radius:var(--radius);font-size:0.82rem;color:var(--text-secondary);margin-bottom:16px;display:none;border:1px dashed var(--border);"></div>
                        <div id="paymentAmountSummary" style="text-align:center;padding:16px;background:var(--primary-bg);border-radius:var(--radius);margin-bottom:16px;">
                            <div style="font-size:0.82rem;color:var(--text-muted);">Amount to Pay</div>
                            <div id="paymentAmountDisplay" style="font-size:1.4rem;font-weight:700;color:var(--primary);"></div>
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;">
                        <button type="button" class="btn btn-secondary btn-block" onclick="goToStep1()">Back</button>
                        <button type="submit" class="btn btn-primary btn-block btn-lg" id="submitBookingBtn">Submit Booking</button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <script>
    let selectedPrice = 0;
    let selectedPeriod = 'month';

    function updateBookingPrice() {
        const sel = document.getElementById('bookingRoomSelect');
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.value) {
            selectedPrice = parseFloat(opt.dataset.price) || 0;
            selectedPeriod = opt.dataset.period || 'month';
        } else {
            selectedPrice = 0;
        }
        document.getElementById('downPayOption').querySelector('span').textContent = '50% (' + fmtCcy(selectedPrice * 0.5) + ')';
        document.getElementById('fullPayOption').querySelector('span').textContent = 'Full (' + fmtCcy(selectedPrice) + ')';
    }

    function goToStep2() {
        const room = document.getElementById('bookingRoomSelect').value;
        if (!room) { alert('Please select a residence.'); return; }
        const name = document.querySelector('input[name="full_name"]').value.trim();
        const phone = document.querySelector('input[name="phone"]').value.trim();
        if (!name || !phone) { alert('Please provide your name and phone number.'); return; }
        if (!/^[0-9]{10}$/.test(phone)) { alert('Phone number must be exactly 10 digits (numbers only).'); return; }

        const sel = document.getElementById('bookingRoomSelect');
        document.getElementById('summaryRoomName').textContent = sel.options[sel.selectedIndex].text;
        document.getElementById('summaryRoomPrice').textContent = fmtCcy(selectedPrice) + '/' + selectedPeriod;

        updateBookingPrice();

        document.getElementById('step1').style.display = 'none';
        document.getElementById('step2').style.display = 'block';
        document.getElementById('step1Indicator').classList.remove('active');
        document.getElementById('step1Indicator').classList.add('done');
        document.getElementById('step2Indicator').classList.add('active');
        window.scrollTo(0, 0);
    }

    function goToStep1() {
        document.getElementById('step1').style.display = 'block';
        document.getElementById('step2').style.display = 'none';
        document.getElementById('step1Indicator').classList.add('active');
        document.getElementById('step1Indicator').classList.remove('done');
        document.getElementById('step2Indicator').classList.remove('active');
        window.scrollTo(0, 0);
    }

    function selectPaymentType(type, el) {
        document.querySelectorAll('.payment-option').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
        document.getElementById('paymentTypeInput').value = type;

        const methodSection = document.getElementById('paymentMethodSection');
        if (type === 'none') {
            methodSection.style.display = 'none';
            document.getElementById('submitBookingBtn').textContent = 'Submit Booking';
        } else {
            methodSection.style.display = 'block';
            const amount = type === 'down_payment' ? selectedPrice * 0.5 : selectedPrice;
            document.getElementById('paymentAmountDisplay').textContent = fmtCcy(amount);
            document.getElementById('submitBookingBtn').textContent = 'Submit & Pay ' + fmtCcy(amount);
            updatePaymentMethodInfo();
        }
    }

    function updatePaymentMethodInfo() {
        const method = document.getElementById('paymentMethodInput').value;
        const amount = document.getElementById('paymentTypeInput').value === 'down_payment' ? selectedPrice * 0.5 : selectedPrice;
        const info = document.getElementById('paymentMethodInfo');
        const btn = document.getElementById('submitBookingBtn');
        const type = document.getElementById('paymentTypeInput').value;
        const label = type === 'down_payment' ? '50% Deposit' : 'Full Amount';
        const balanceNote = type !== 'none' ? '<br><span style="font-size:0.78rem;color:var(--warning);font-weight:600;">Balance must be paid within 1 week of move-in.</span>' : '';

        if (method === 'paystack') {
            info.innerHTML = 'You will be redirected to complete your ' + label.toLowerCase() + ' via mobile money.' + balanceNote;
            btn.textContent = 'Submit & Pay ' + fmtCcy(amount);
        } else {
            info.innerHTML = '<strong>Bank Transfer:</strong> After submitting, you will receive our bank details. Please transfer the exact amount and send your receipt to confirm.' + balanceNote;
            btn.textContent = 'Submit Booking (Pay via Bank)';
        }
        info.style.display = 'block';
    }

    function fmtCcy(n) { return 'GH\u20B5 ' + Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    </script>
    <script src="js/email-validator.js"></script>
</body>
</html>
