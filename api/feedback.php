<?php
// =====================================================
// API: Feedback Reports (tenant + public submissions, admin management)
// PK's Luxury Apartments — Apartment Management System
// =====================================================
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
$db = getDB();

// ---- POST: submit feedback (tenant or public) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'submit';

    if ($action === 'submit') {
        $category = $_POST['category'] ?? 'general';
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        $validCategories = ['general', 'complaint', 'suggestion', 'maintenance', 'feedback', 'other'];
        if (!in_array($category, $validCategories)) {
            $category = 'general';
        }

        if (empty($message)) {
            echo json_encode(['error' => 'Message is required.']);
            exit;
        }

        // Auto-generate subject if not provided
        if (empty($subject)) {
            $catLabel = ucfirst($category);
            $subject = $catLabel . ': ' . mb_substr($message, 0, 80) . (mb_strlen($message) > 80 ? '...' : '');
        }

        $userId = null;
        $reporterName = trim($_POST['reporter_name'] ?? '');
        $reporterEmail = trim($_POST['reporter_email'] ?? '');
        $reporterPhone = trim($_POST['reporter_phone'] ?? '');

        if (isLoggedIn()) {
            $u = currentUser();
            $userId = $u['id'];
            if (empty($reporterName)) $reporterName = $u['full_name'];
            if (empty($reporterEmail)) $reporterEmail = $u['email'] ?? '';
            if (empty($reporterPhone)) $reporterPhone = $u['phone'] ?? '';
        }

        if (empty($reporterName)) {
            echo json_encode(['error' => 'Your name is required.']);
            exit;
        }
        if (!empty($reporterPhone) && !validatePhone($reporterPhone)) {
            echo json_encode(['error' => 'Phone number must be exactly 10 digits (numbers only).']);
            exit;
        }
        if (!empty($reporterEmail) && !validateEmail($reporterEmail)) {
            echo json_encode(['error' => 'Please enter a valid email address.']);
            exit;
        }

        $stmt = $db->prepare("INSERT INTO feedback_reports (user_id, reporter_name, reporter_email, reporter_phone, category, subject, message) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $reporterName, $reporterEmail, $reporterPhone, $category, $subject, $message]);

        // Notify all active admins in one query
        $admins = $db->query("SELECT id, phone FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
        if ($admins) {
            $placeholders = implode(',', array_fill(0, count($admins), '(?, ?, ?, ?)'));
            $notifSql = "INSERT INTO notifications (user_id, title, message, type) VALUES $placeholders";
            $notifParams = [];
            foreach ($admins as $admin) {
                $notifParams[] = $admin['id'];
                $notifParams[] = 'New Feedback Report';
                $notifParams[] = "$reporterName submitted a $category report: $subject";
                $notifParams[] = 'info';
            }
            $db->prepare($notifSql)->execute($notifParams);

            foreach ($admins as $admin) {
                sendSMS($admin['phone'], "New $category report from $reporterName: $subject");
            }
        }

        echo json_encode(['success' => true, 'message' => 'Your report has been submitted. We will review it shortly.']);
        exit;
    }

    if ($action === 'reply') {
        requireRole(['admin', 'staff']);
        $csrf = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrf)) {
            echo json_encode(['error' => 'Invalid security token.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        $reply = trim($_POST['admin_reply'] ?? '');
        $status = $_POST['status'] ?? 'in_progress';

        if (empty($reply)) {
            echo json_encode(['error' => 'Reply cannot be empty.']);
            exit;
        }

        $validStatuses = ['new', 'in_progress', 'resolved', 'closed'];
        if (!in_array($status, $validStatuses)) {
            $status = 'in_progress';
        }

        $adminUser = currentUser();
        $stmt = $db->prepare("UPDATE feedback_reports SET admin_reply = ?, admin_reply_by = ?, admin_reply_at = NOW(), status = ? WHERE id = ?");
        $stmt->execute([$reply, $adminUser['id'], $status, $id]);

        // Notify the reporter
        $stmt2 = $db->prepare("SELECT user_id, reporter_name, reporter_phone FROM feedback_reports WHERE id = ?");
        $stmt2->execute([$id]);
        $report = $stmt2->fetch();
        if ($report && $report['user_id']) {
            $stmt3 = $db->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Report Update', ?, 'info')");
            $stmt3->execute([$report['user_id'], "Your report has been updated. Admin reply: $reply"]);
        }
        if ($report && $report['reporter_phone']) {
            $replySnippet = mb_substr($reply, 0, 140);
            sendSMS($report['reporter_phone'], "Dear " . $report['reporter_name'] . ", your report has been updated by PK's Luxury Apartments. Reply: " . $replySnippet . (mb_strlen($reply) > 140 ? '...' : ''));
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'update_status') {
        requireRole(['admin', 'staff']);
        $csrf = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrf)) {
            echo json_encode(['error' => 'Invalid security token.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'new';
        $validStatuses = ['new', 'in_progress', 'resolved', 'closed'];
        if (!in_array($status, $validStatuses)) {
            $status = 'new';
        }

        $stmt = $db->prepare("UPDATE feedback_reports SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        requireRole(['admin']);
        $csrf = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrf)) {
            echo json_encode(['error' => 'Invalid security token.']);
            exit;
        }

        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM feedback_reports WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
        exit;
    }
}

// ---- GET: list feedback ----
requireLogin();
if (!in_array(currentUser()['role'], ['admin', 'staff'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';

$sql = "SELECT fr.*, u.full_name AS admin_reply_by_name FROM feedback_reports fr LEFT JOIN users u ON fr.admin_reply_by = u.id WHERE 1=1";
$params = [];

if ($statusFilter) {
    $sql .= " AND fr.status = ?";
    $params[] = $statusFilter;
}
if ($categoryFilter) {
    $sql .= " AND fr.category = ?";
    $params[] = $categoryFilter;
}

$sql .= " ORDER BY fr.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

echo json_encode($reports);
