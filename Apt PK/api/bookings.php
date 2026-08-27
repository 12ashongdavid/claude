<?php
// Handles booking requests from prospective tenants, including the optional deposit or full payment.
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }
    $action = $_POST['action'] ?? 'submit';

    if ($action === 'submit') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $room_id = !empty($_POST['room_id']) ? intval($_POST['room_id']) : null;
        $preferred_date = $_POST['preferred_date'] ?? null;
        $message = trim($_POST['message'] ?? '');
        $payment_type = $_POST['payment_type'] ?? 'none';
        $payment_method = $_POST['payment_method'] ?? 'paystack';

        if (empty($full_name) || empty($phone) || empty($email)) {
            echo json_encode(['error' => 'Name, phone, and email are required.']);
            exit;
        }
        if (!validatePhone($phone)) {
            echo json_encode(['error' => 'Phone number must be exactly 10 digits.']);
            exit;
        }
        $emailCheck = validateEmailDetailed($email);
        if (!$emailCheck['valid']) {
            echo json_encode(['error' => $emailCheck['message']]);
            exit;
        }
        if ($preferred_date && $preferred_date < date('Y-m-d')) {
            echo json_encode(['error' => "Your preferred view-in date can't be in the past. Please choose today or a later date."]);
            exit;
        }

        $validPaymentTypes = ['none', 'down_payment', 'full_payment'];
        if (!in_array($payment_type, $validPaymentTypes)) {
            $payment_type = 'none';
        }

        $payment_amount = 0;
        $payment_reference = trim($_POST['payment_reference'] ?? '');
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

        if ($payment_type !== 'none' && !empty($payment_reference)) {
            $payment_status = 'completed';
        } elseif ($payment_type !== 'none' && $payment_method === 'bank_transfer') {
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

        // Notify admins (batch insert)
        $admins = $db->query("SELECT id, phone FROM users WHERE role = 'admin'")->fetchAll();
        $room_label = $room_id ? " for Residence #" . $room_id : "";
        $payment_note = $payment_type !== 'none' ? " Payment: " . formatCurrency($payment_amount) . " ($payment_type)." : "";
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
            // SMS to first admin only (non-blocking)
            sendSMS($admins[0]['phone'], "New booking request from $full_name$room_label. Phone: $phone." . $payment_note);
        }

        sendSMS($phone, "Dear $full_name, your booking request has been received" . ($payment_type !== 'none' ? " with a payment of " . formatCurrency($payment_amount) : "") . ". PK's Luxury Apartments will contact you within 24 hours.");

        echo json_encode(['success' => true, 'booking_id' => $bookingId, 'message' => 'Booking request submitted successfully! We will contact you soon.']);
        exit;
    }

    if ($action === 'update_status') {
        requireRole(['admin', 'staff']);
        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'pending';

        $stmt = $db->prepare("SELECT * FROM booking_requests WHERE id = ?");
        $stmt->execute([$id]);
        $booking = $stmt->fetch();

        $stmt = $db->prepare("UPDATE booking_requests SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);

        // If rejected and room was reserved by payment, free it back to available
        if ($status === 'rejected' && $booking && $booking['payment_type'] !== 'none' && $booking['room_id']) {
            $db->prepare("UPDATE rooms SET status = 'available' WHERE id = ? AND status = 'occupied'")->execute([$booking['room_id']]);
        }

        // If approved with payment, auto-create tenant account
        if ($status === 'approved' && $booking && $booking['payment_type'] !== 'none' && $booking['room_id']) {
            $booking_full_name = $booking['full_name'];
            $booking_email = $booking['email'] ?? '';
            $booking_phone = $booking['phone'];
            $booking_room_id = $booking['room_id'];
            $booking_payment_amount = floatval($booking['payment_amount']);
            $booking_payment_method = $booking['payment_method'] ?: 'mobile_money';
            $booking_payment_reference = $booking['payment_reference'] ?? '';

            // Check if a user with this phone already exists
            $checkStmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
            $checkStmt->execute([$booking_phone]);
            if (!$checkStmt->fetch()) {
                // Generate username from full name
                $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode(' ', $booking_full_name)[0]));
                $username = $baseUsername;
                $suffix = 1;
                while (true) {
                    $uCheck = $db->prepare("SELECT id FROM users WHERE username = ?");
                    $uCheck->execute([$username]);
                    if (!$uCheck->fetch()) break;
                    $username = $baseUsername . $suffix++;
                }

                // Generate temporary password
                $temp_password = generateTempPassword();
                $hashed = password_hash($temp_password, PASSWORD_DEFAULT);

                // Create user account
                $stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, phone, role, must_change_password) VALUES (?, ?, ?, ?, ?, 'tenant', 1)");
                $stmt->execute([$username, $hashed, $booking_full_name, $booking_email, $booking_phone]);
                $tenant_id = $db->lastInsertId();

                // Get room rental price for tenancy
                $roomStmt = $db->prepare("SELECT rental_price FROM rooms WHERE id = ?");
                $roomStmt->execute([$booking_room_id]);
                $rental_price = floatval($roomStmt->fetchColumn() ?: 0);

                // Create tenancy
                $start_date = date('Y-m-d');
                $end_date = date('Y-m-d', strtotime($start_date . '+1 year'));
                $stmt = $db->prepare("INSERT INTO tenancies (tenant_id, room_id, start_date, end_date, monthly_rent, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$tenant_id, $booking_room_id, $start_date, $end_date, $rental_price]);

                // Mark room occupied
                $db->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?")->execute([$booking_room_id]);

                // Record deposit as first rent payment (deducted from rent)
                if ($booking_payment_amount > 0) {
                    $ref = $booking_payment_reference ?: generateRef('RNT');
                    $month_covered = date('Y-m');
                    $stmt = $db->prepare("INSERT INTO rent_payments (tenant_id, room_id, amount, payment_date, payment_method, reference_number, month_covered, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 'Deposit from booking')");
                    $stmt->execute([$tenant_id, $booking_room_id, $booking_payment_amount, date('Y-m-d'), $booking_payment_method, $ref, $month_covered]);
                }

                // Send SMS with credentials
                sendSMS($booking_phone, "Dear $booking_full_name, your PK's Luxury Apartments tenant account has been created. Username: $username. Temporary password: $temp_password. Deposit of " . formatCurrency($booking_payment_amount) . " has been deducted from your rent. Please log in and change your password.");
            }
        }

        $stmt = $db->prepare("SELECT full_name, phone FROM booking_requests WHERE id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if ($req) {
            $status_msg = ucfirst(str_replace('_', ' ', $status));
            sendSMS($req['phone'], "Dear " . $req['full_name'] . ", your booking request status is now: $status_msg. Thank you for choosing PK's Luxury Apartments.");
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'confirm_payment') {
        requireRole(['admin', 'staff']);
        $csrf = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrf)) {
            echo json_encode(['error' => 'Invalid security token.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        $refInput = trim($_POST['payment_reference'] ?? '');
        $reference = $refInput !== '' ? $refInput : 'ADMIN-' . strtoupper(bin2hex(random_bytes(4)));

        $stmt = $db->prepare("UPDATE booking_requests SET payment_status = 'completed', payment_reference = ? WHERE id = ?");
        $stmt->execute([$reference, $id]);

        $stmt = $db->prepare("SELECT full_name, phone FROM booking_requests WHERE id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if ($req) {
            sendSMS($req['phone'], "Dear " . $req['full_name'] . ", your booking payment has been confirmed. Thank you for choosing PK's Luxury Apartments.");
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reject_payment') {
        requireRole(['admin', 'staff']);
        $csrf = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrf)) {
            echo json_encode(['error' => 'Invalid security token.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE booking_requests SET payment_status = 'failed' WHERE id = ?");
        $stmt->execute([$id]);

        $stmt = $db->prepare("SELECT full_name, phone FROM booking_requests WHERE id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if ($req) {
            sendSMS($req['phone'], "Dear " . $req['full_name'] . ", we could not confirm your booking payment. Please contact PK's Luxury Apartments for assistance.");
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

// GET: List bookings
requireLogin();
if (!in_array(currentUser()['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$status_filter = $_GET['status'] ?? '';
$sql = "SELECT br.*, r.room_number FROM booking_requests br LEFT JOIN rooms r ON br.room_id = r.id WHERE 1=1";
$params = [];

if ($status_filter) {
    $sql .= " AND br.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY br.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

echo json_encode($bookings);
