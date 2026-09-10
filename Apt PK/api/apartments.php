<?php
// Handles apartments (apartments) - CRUD, image gallery uploads, and apartment type management.
require_once __DIR__ . '/../config/database.php';
requireLogin();

header('Content-Type: application/json');
$db = getDB();
$user = currentUser();

// Normalize single/multi file input from <input name="image[]">
function collectApartmentImages() {
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
function saveApartmentImages() {
    $saved = [];
    foreach (collectApartmentImages() as $f) {
        if ($f['error'] === UPLOAD_ERR_NO_FILE) continue;
        $uploadError = validateUpload($f);
        if ($uploadError) {
            echo json_encode(['error' => $uploadError]);
            exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $ext = safeUploadExtension($finfo->file($f['tmp_name']));
        $filename = 'apartment_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        if (move_uploaded_file($f['tmp_name'], UPLOAD_PATH . 'apartments/' . $filename)) {
            $saved[] = $filename;
        }
    }
    return $saved;
}

// Insert saved filenames into the apartment_images gallery
function insertApartmentImages($db, $apartmentId, array $filenames) {
    $stmt = $db->prepare("INSERT INTO apartment_images (apartment_id, image) VALUES (?, ?)");
    foreach ($filenames as $filename) {
        $stmt->execute([$apartmentId, $filename]);
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
        $apartment_number = trim($_POST['apartment_number'] ?? '');
        $apartment_type = strtolower(trim($_POST['apartment_type'] ?? ''));
        $floor = intval($_POST['floor'] ?? 1);
        $rental_price = floatval($_POST['rental_price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $amenities = trim($_POST['amenities'] ?? '');

        if (empty($apartment_number) || $rental_price <= 0) {
            echo json_encode(['error' => 'Apartment number and valid price required.']);
            exit;
        }

        $validTypes = $db->query("SELECT name FROM apartment_types")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($apartment_type, $validTypes)) {
            echo json_encode(['error' => 'Invalid apartment type selected.']);
            exit;
        }

        $images = saveApartmentImages();
        $primary = $images[0] ?? null;

        $stmt = $db->prepare("INSERT INTO apartments (apartment_number, apartment_type, floor, rental_price, image, description, amenities) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$apartment_number, $apartment_type, $floor, $rental_price, $primary, $description, $amenities]);
        $apartmentId = $db->lastInsertId();
        insertApartmentImages($db, $apartmentId, $images);
        echo json_encode(['success' => true, 'id' => $apartmentId]);
        exit;
    }

    if ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        $apartment_number = trim($_POST['apartment_number'] ?? '');
        $apartment_type = strtolower(trim($_POST['apartment_type'] ?? ''));
        $floor = intval($_POST['floor'] ?? 1);
        $rental_price = floatval($_POST['rental_price'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        $description = trim($_POST['description'] ?? '');
        $amenities = trim($_POST['amenities'] ?? '');

        if (empty($apartment_number)) {
            echo json_encode(['error' => 'Apartment name is required.']);
            exit;
        }

        // Ensure the new name is not already used by another apartment
        $dup = $db->prepare("SELECT id FROM apartments WHERE apartment_number = ? AND id != ?");
        $dup->execute([$apartment_number, $id]);
        if ($dup->fetch()) {
            echo json_encode(['error' => 'An apartment with this name already exists.']);
            exit;
        }

        $validTypes = $db->query("SELECT name FROM apartment_types")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($apartment_type, $validTypes)) {
            echo json_encode(['error' => 'Invalid apartment type selected.']);
            exit;
        }

        // Add new images to the gallery (existing images are kept)
        $newImages = saveApartmentImages();
        if ($newImages) {
            insertApartmentImages($db, $id, $newImages);
            // Set a primary image if the apartment has none yet
            $stmt = $db->prepare("SELECT image FROM apartments WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                $db->prepare("UPDATE apartments SET image = ? WHERE id = ?")->execute([$newImages[0], $id]);
            }
        }

        $stmt = $db->prepare("UPDATE apartments SET apartment_number=?, apartment_type=?, floor=?, rental_price=?, status=?, description=?, amenities=? WHERE id=?");
        $stmt->execute([$apartment_number, $apartment_type, $floor, $rental_price, $status, $description, $amenities, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_image') {
        $imgId = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT ri.*, r.image AS primary_image FROM apartment_images ri JOIN apartments r ON ri.apartment_id = r.id WHERE ri.id = ?");
        $stmt->execute([$imgId]);
        $img = $stmt->fetch();
        if (!$img) {
            echo json_encode(['error' => 'Image not found.']);
            exit;
        }
        @unlink(UPLOAD_PATH . 'apartments/' . $img['image']);
        $db->prepare("DELETE FROM apartment_images WHERE id = ?")->execute([$imgId]);
        // If the deleted image was the primary, promote the next gallery image
        if ($img['image'] === $img['primary_image']) {
            $next = $db->prepare("SELECT image FROM apartment_images WHERE apartment_id = ? ORDER BY id LIMIT 1");
            $next->execute([$img['apartment_id']]);
            $db->prepare("UPDATE apartments SET image = ? WHERE id = ?")->execute([$next->fetchColumn() ?: null, $img['apartment_id']]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);

        if (apartmentHasActiveTenant($id)) {
            echo json_encode(['error' => 'This apartment has an active tenant and cannot be deleted. Deactivate the tenant or end their tenancy first.']);
            exit;
        }

        // Remove image files (primary + gallery)
        $old = $db->prepare("SELECT image FROM apartments WHERE id = ?");
        $old->execute([$id]);
        $oldImg = $old->fetchColumn();
        if ($oldImg) @unlink(UPLOAD_PATH . 'apartments/' . $oldImg);
        $gal = $db->prepare("SELECT image FROM apartment_images WHERE apartment_id = ?");
        $gal->execute([$id]);
        foreach ($gal->fetchAll(PDO::FETCH_COLUMN) as $g) {
            @unlink(UPLOAD_PATH . 'apartments/' . $g);
        }
        $db->prepare("DELETE FROM apartment_images WHERE apartment_id = ?")->execute([$id]);

        $stmt = $db->prepare("DELETE FROM apartments WHERE id = ?");
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
            $stmt = $db->prepare("INSERT INTO apartment_types (name, charge_period) VALUES (?, ?)");
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
        $stmt = $db->prepare("SELECT name, charge_period FROM apartment_types WHERE id = ?");
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
        $dup = $db->prepare("SELECT COUNT(*) FROM apartment_types WHERE name = ? AND id != ?");
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn() > 0) {
            echo json_encode(['error' => 'This type already exists.']);
            exit;
        }
        $db->prepare("UPDATE apartments SET apartment_type = ? WHERE apartment_type = ?")->execute([$name, $old['name']]);
        $db->prepare("UPDATE apartment_types SET name = ?, charge_period = ? WHERE id = ?")->execute([$name, $charge_period, $id]);
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
        $stmt = $db->prepare("SELECT name FROM apartment_types WHERE id = ?");
        $stmt->execute([$id]);
        $old = $stmt->fetch();
        if (!$old) {
            echo json_encode(['error' => 'Type not found.']);
            exit;
        }
        $count = $db->prepare("SELECT COUNT(*) FROM apartments WHERE apartment_type = ?");
        $count->execute([$old['name']]);
        if ($count->fetchColumn() > 0) {
            echo json_encode(['error' => 'Cannot delete: this type is assigned to apartment(s).']);
            exit;
        }
        $db->prepare("DELETE FROM apartment_types WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// GET: List apartment types (for dynamic dropdowns)
if (($_GET['types'] ?? '') === '1') {
    $types = $db->query("SELECT id, name, charge_period FROM apartment_types ORDER BY name")->fetchAll();
    echo json_encode($types);
    exit;
}

// GET: List apartments
$filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? '';

$sql = "SELECT r.*, rt.charge_period FROM apartments r LEFT JOIN apartment_types rt ON rt.name = r.apartment_type WHERE 1=1";
$params = [];

if ($filter) {
    $sql .= " AND r.status = ?";
    $params[] = $filter;
}
if ($type) {
    $sql .= " AND r.apartment_type = ?";
    $params[] = $type;
}
if ($search) {
    $sql .= " AND (r.apartment_number LIKE ? OR r.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY r.apartment_number ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$apartments = $stmt->fetchAll();

// Attach the full image gallery to each apartment
$galStmt = $db->prepare("SELECT id, apartment_id, image FROM apartment_images ORDER BY id ASC");
$galStmt->execute();
$gallery = [];
foreach ($galStmt->fetchAll() as $gi) {
    $gallery[$gi['apartment_id']][] = ['id' => (int)$gi['id'], 'image' => $gi['image']];
}
foreach ($apartments as &$apartment) {
    $apartment['images'] = $gallery[$apartment['id']] ?? [];
    // If the primary image column is empty, fall back to the first gallery image
    if (empty($apartment['image']) && !empty($apartment['images'])) {
        $apartment['image'] = $apartment['images'][0]['image'];
    }
}

echo json_encode($apartments);
