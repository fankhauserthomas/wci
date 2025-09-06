<?php
require_once __DIR__ . '/config.php';

echo "=== AKTUELLE QUOTAS ===\n";
$result = $mysqli->query("SELECT id, hrs_id, title, date_from, date_to FROM hut_quota ORDER BY id DESC LIMIT 10");
while ($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']}, HRS-ID: {$row['hrs_id']}, Title: {$row['title']}, Von: {$row['date_from']}, Bis: {$row['date_to']}\n";
}
?>
