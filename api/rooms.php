<?php
// =====================================================
// API: Rooms CRUD (with image upload)
// =====================================================
require_once __DIR__ . '/../config/database.php';
requireLogin();

header('Content-Type: application/json');
$db = getDB();
$user = currentUser();

// Normalize single/multi file input from <input name="image[]">
function collectRoomImages() {
    $out = [];
    if (empty($_FILES['image'])) return $out;
    $f = $_FILES['image'];
    if (!is_array($f['name'])) {
        $f = [
            'name' => [$f['name']], 'tmp_name' => [$f['tmp_name']],
            'error' => [$f['error']], 'size' => [$f['size']], 'type' => [$f['type']],
        ];
    }
    for ($i = 0; $i < count($f['name']); $i++) {
        $out[] = [
            'name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i],
            'error' => $f['error'][$i], 'size' => $f['size'][$i], 'type' => $f['type'][$i],
        ];
    }
    return $out;
}

// Upload every valid file in the request; returns saved filenames.
// Exits with a JSON error if any selected file is invalid.
function saveRoomImages() {
    $saved = [];
    foreach (collectRoomImages() as $f) {
        if ($f['error'] === UPLOAD_ERR_NO_FILE) continue;
        $uploadError = validateUpload($f);
        if ($uploadError) {
            echo json_encode(['error' => $uploadError]);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $ext = safeUploadExtension($finfo->file($f['tmp_name']));
        $filename = 'room_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (move_uploaded_file($f['tmp_name'], UPLOAD_PATH . 'rooms/' . $filename)) {
            $saved[] = $filename;
        }
    }
    return $saved;
}

// Insert saved filenames into the room_images gallery
function insertRoomImages($db, $roomId, array $filenames) {
    $stmt = $db->prepare("INSERT INTO room_images (room_id, image) VALUES (?, ?)");
    foreach ($filenames as $filename) {
        $stmt->execute([$roomId, $filename]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($user['role'], ['admin', 'staff'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }

    if ($action === 'add') {
        $room_number = trim($_POST['room_number'] ?? '');
        $room_type = strtolower(trim($_POST['room_type'] ?? ''));
        $floor = intval($_POST['floor'] ?? 1);
        $rental_price = floatval($_POST['rental_price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $amenities = trim($_POST['amenities'] ?? '');

        if (empty($room_number) || $rental_price <= 0) {
            echo json_encode(['error' => 'Residence number and valid price required.']);
            exit;
        }

        $validTypes = $db->query("SELECT name FROM room_types")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($room_type, $validTypes)) {
            echo json_encode(['error' => 'Invalid residence type selected.']);
            exit;
        }

        $images = saveRoomImages();
        $primary = $images[0] ?? null;

        $stmt = $db->prepare("INSERT INTO rooms (room_number, room_type, floor, rental_price, image, description, amenities) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$room_number, $room_type, $floor, $rental_price, $primary, $description, $amenities]);
        $roomId = $db->lastInsertId();
        insertRoomImages($db, $roomId, $images);
        echo json_encode(['success' => true, 'id' => $roomId]);
        exit;
    }

    if ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $room_number = trim($_POST['room_number'] ?? '');
        $room_type = strtolower(trim($_POST['room_type'] ?? ''));
        $floor = intval($_POST['floor'] ?? 1);
        $rental_price = floatval($_POST['rental_price'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        $description = trim($_POST['description'] ?? '');
        $amenities = trim($_POST['amenities'] ?? '');

        if (empty($room_number)) {
            echo json_encode(['error' => 'Residence name is required.']);
            exit;
        }

        // Ensure the new name is not already used by another residence
        $dup = $db->prepare("SELECT id FROM rooms WHERE room_number = ? AND id != ?");
        $dup->execute([$room_number, $id]);
        if ($dup->fetch()) {
            echo json_encode(['error' => 'A residence with this name already exists.']);
            exit;
        }

        $validTypes = $db->query("SELECT name FROM room_types")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($room_type, $validTypes)) {
            echo json_encode(['error' => 'Invalid residence type selected.']);
            exit;
        }

        // Add new images to the gallery (existing images are kept)
        $newImages = saveRoomImages();
        if ($newImages) {
            insertRoomImages($db, $id, $newImages);
            // Set a primary image if the residence has none yet
            $stmt = $db->prepare("SELECT image FROM rooms WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                $db->prepare("UPDATE rooms SET image = ? WHERE id = ?")->execute([$newImages[0], $id]);
            }
        }

        $stmt = $db->prepare("UPDATE rooms SET room_number=?, room_type=?, floor=?, rental_price=?, status=?, description=?, amenities=? WHERE id=?");
        $stmt->execute([$room_number, $room_type, $floor, $rental_price, $status, $description, $amenities, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_image') {
        $imgId = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT ri.*, r.image AS primary_image FROM room_images ri JOIN rooms r ON ri.room_id = r.id WHERE ri.id = ?");
        $stmt->execute([$imgId]);
        $img = $stmt->fetch();
        if (!$img) {
            echo json_encode(['error' => 'Image not found.']);
            exit;
        }
        @unlink(UPLOAD_PATH . 'rooms/' . $img['image']);
        $db->prepare("DELETE FROM room_images WHERE id = ?")->execute([$imgId]);
        // If the deleted image was the primary, promote the next gallery image
        if ($img['image'] === $img['primary_image']) {
            $next = $db->prepare("SELECT image FROM room_images WHERE room_id = ? ORDER BY id LIMIT 1");
            $next->execute([$img['room_id']]);
            $db->prepare("UPDATE rooms SET image = ? WHERE id = ?")->execute([$next->fetchColumn() ?: null, $img['room_id']]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        // Remove image files (primary + gallery)
        $old = $db->prepare("SELECT image FROM rooms WHERE id = ?");
        $old->execute([$id]);
        $oldImg = $old->fetchColumn();
        if ($oldImg) @unlink(UPLOAD_PATH . 'rooms/' . $oldImg);
        $gal = $db->prepare("SELECT image FROM room_images WHERE room_id = ?");
        $gal->execute([$id]);
        foreach ($gal->fetchAll(PDO::FETCH_COLUMN) as $g) {
            @unlink(UPLOAD_PATH . 'rooms/' . $g);
        }
        $db->prepare("DELETE FROM room_images WHERE room_id = ?")->execute([$id]);

        $stmt = $db->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'add_type') {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $name = strtolower(trim($_POST['name'] ?? ''));
        $charge_period = ($_POST['charge_period'] ?? 'monthly') === 'daily' ? 'daily' : 'monthly';
        if (empty($name)) {
            echo json_encode(['error' => 'Type name is required.']);
            exit;
        }
        try {
            $stmt = $db->prepare("INSERT INTO room_types (name, charge_period) VALUES (?, ?)");
            $stmt->execute([$name, $charge_period]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'This type already exists.']);
        }
        exit;
    }

    if ($action === 'edit_type') {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $name = strtolower(trim($_POST['name'] ?? ''));
        $charge_period = ($_POST['charge_period'] ?? 'monthly') === 'daily' ? 'daily' : 'monthly';
        if (empty($name)) {
            echo json_encode(['error' => 'Type name is required.']);
            exit;
        }
        $stmt = $db->prepare("SELECT name, charge_period FROM room_types WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            echo json_encode(['error' => 'Type not found.']);
            exit;
        }
        if ($old['name'] === $name && $old['charge_period'] === $charge_period) {
            echo json_encode(['success' => true]);
            exit;
        }
        $dup = $db->prepare("SELECT COUNT(*) FROM room_types WHERE name = ? AND id != ?");
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn() > 0) {
            echo json_encode(['error' => 'This type already exists.']);
            exit;
        }
        $db->prepare("UPDATE rooms SET room_type = ? WHERE room_type = ?")->execute([$name, $old['name']]);
        $db->prepare("UPDATE room_types SET name = ?, charge_period = ? WHERE id = ?")->execute([$name, $charge_period, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_type') {
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT name FROM room_types WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            echo json_encode(['error' => 'Type not found.']);
            exit;
        }
        $count = $db->prepare("SELECT COUNT(*) FROM rooms WHERE room_type = ?");
        $count->execute([$old['name']]);
        if ($count->fetchColumn() > 0) {
            echo json_encode(['error' => 'Cannot delete: this type is assigned to residence(s).']);
            exit;
        }
        $db->prepare("DELETE FROM room_types WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// GET: List room types (for dynamic dropdowns)
if (($_GET['types'] ?? '') === '1') {
    $types = $db->query("SELECT id, name, charge_period FROM room_types ORDER BY name")->fetchAll();
    echo json_encode($types);
    exit;
}

// GET: List rooms
$filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? '';

$sql = "SELECT r.*, rt.charge_period FROM rooms r LEFT JOIN room_types rt ON rt.name = r.room_type WHERE 1=1";
$params = [];

if ($filter) {
    $sql .= " AND r.status = ?";
    $params[] = $filter;
}
if ($type) {
    $sql .= " AND r.room_type = ?";
    $params[] = $type;
}
if ($search) {
    $sql .= " AND (r.room_number LIKE ? OR r.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY r.room_number ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rooms = $stmt->fetchAll();

// Attach the full image gallery to each residence
$galStmt = $db->prepare("SELECT id, room_id, image FROM room_images ORDER BY id ASC");
$galStmt->execute();
$gallery = [];
foreach ($galStmt->fetchAll() as $gi) {
    $gallery[$gi['room_id']][] = ['id' => (int)$gi['id'], 'image' => $gi['image']];
}
foreach ($rooms as &$room) {
    $room['images'] = $gallery[$room['id']] ?? [];
    // If the primary image column is empty, fall back to the first gallery image
    if (empty($room['image']) && !empty($room['images'])) {
        $room['image'] = $room['images'][0]['image'];
    }
}

echo json_encode($rooms);
