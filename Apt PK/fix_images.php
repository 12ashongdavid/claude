<?php
// One-off script to backfill apartments.image from apartment_images - run it once, then delete this file.
require_once __DIR__ . '/config/database.php';
requireRole(['admin']);
$db = getDB();

$fixed = 0;
$rows = $db->query("
    SELECT r.id, ri.image
    FROM apartments r
    JOIN apartment_images ri ON ri.apartment_id = r.id
    WHERE r.image IS NULL OR r.image = ''
    GROUP BY r.id
    ORDER BY r.id, MIN(ri.id)
")->fetchAll();

$stmt = $db->prepare("UPDATE apartments SET image = ? WHERE id = ?");
foreach ($rows as $row) {
    $stmt->execute([$row['image'], $row['id']]);
    $fixed++;
}

header('Content-Type: text/plain');
echo "Done. Fixed {$fixed} apartments with missing primary image.\n";
echo "DELETE this file (fix_images.php) when finished.";
