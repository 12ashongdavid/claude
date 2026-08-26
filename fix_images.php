<?php
// =====================================================
// One-time fix: sync rooms.image from room_images
// Run once in the browser, then DELETE this file.
// =====================================================
require_once __DIR__ . '/config/database.php';
requireRole(['admin']);
$db = getDB();

$fixed = 0;
$rows = $db->query("
    SELECT r.id, ri.image
    FROM rooms r
    JOIN room_images ri ON ri.room_id = r.id
    WHERE r.image IS NULL OR r.image = ''
    GROUP BY r.id
    ORDER BY r.id, MIN(ri.id)
")->fetchAll();

$stmt = $db->prepare("UPDATE rooms SET image = ? WHERE id = ?");
foreach ($rows as $row) {
    $stmt->execute([$row['image'], $row['id']]);
    $fixed++;
}

header('Content-Type: text/plain');
echo "Done. Fixed {$fixed} rooms with missing primary image.\n";
echo "DELETE this file (fix_images.php) when finished.";
