<?php
// debug-search.php - Debug der hierarchischen Suche

require 'config.php';

$searchTerm = 'test';
$likeTerm = '%' . $searchTerm . '%';

echo "=== Debug hierarchische Suche ===\n";
echo "Suchterm: $searchTerm\n\n";

// Test 1: Reservierungen finden
echo "1. Suche Reservierungen:\n";
$stmt = $mysqli->prepare("SELECT id, av_id, nachname, vorname FROM `AV-Res` WHERE nachname LIKE ? OR vorname LIKE ? LIMIT 3");
$stmt->bind_param('ss', $likeTerm, $likeTerm);
$stmt->execute();
$result = $stmt->get_result();

$foundAVIds = [];
while ($row = $result->fetch_assoc()) {
    echo "- Res ID: {$row['id']}, AV-ID: {$row['av_id']}, Name: {$row['nachname']} {$row['vorname']}\n";
    if ($row['av_id']) {
        $foundAVIds[] = $row['av_id'];
    }
}
$stmt->close();

echo "Gefundene AV-IDs: " . implode(', ', $foundAVIds) . "\n\n";

// Test 2: Namen zu diesen AV-IDs finden
if (!empty($foundAVIds)) {
    echo "2. Suche Namen zu den AV-IDs:\n";
    $placeholders = str_repeat('?,', count($foundAVIds) - 1) . '?';
    $stmt = $mysqli->prepare("SELECT av_id, nachname, vorname FROM `AV-ResNamen` WHERE av_id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($foundAVIds)), ...$foundAVIds);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        echo "- AV-ID: {$row['av_id']}, Name: {$row['nachname']} {$row['vorname']}\n";
    }
    $stmt->close();
} else {
    echo "2. Keine AV-IDs gefunden\n";
}

echo "\n3. Namen mit Suchkriterium:\n";
$stmt = $mysqli->prepare("SELECT av_id, nachname, vorname FROM `AV-ResNamen` WHERE nachname LIKE ? OR vorname LIKE ? LIMIT 5");
$stmt->bind_param('ss', $likeTerm, $likeTerm);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    echo "- AV-ID: {$row['av_id']}, Name: {$row['nachname']} {$row['vorname']}\n";
}
$stmt->close();

$mysqli->close();
?>
