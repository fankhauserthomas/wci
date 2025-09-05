<?php
// test-simple-search.php - Teste die vereinfachte Suche

require 'config.php';

$searchTerm = 'test';
$likeTerm = '%' . $searchTerm . '%';

echo "Testing search for: $searchTerm\n\n";

// Test Verbindung
echo "Database connection: ";
if ($mysqli->ping()) {
    echo "OK\n";
} else {
    echo "FAILED\n";
    exit;
}

// Test AV-Res Tabelle
echo "\nTesting AV-Res table:\n";
$stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM `AV-Res`");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    echo "Total records in AV-Res: " . $row['count'] . "\n";
    $stmt->close();
} else {
    echo "Error: " . $mysqli->error . "\n";
}

// Test AV-ResNamen Tabelle
echo "\nTesting AV-ResNamen table:\n";
$stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM `AV-ResNamen`");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    echo "Total records in AV-ResNamen: " . $row['count'] . "\n";
    $stmt->close();
} else {
    echo "Error: " . $mysqli->error . "\n";
}

// Test Search in AV-Res
echo "\nTesting search in AV-Res:\n";
$stmt = $mysqli->prepare("SELECT nachname, vorname FROM `AV-Res` WHERE nachname LIKE ? LIMIT 3");
if ($stmt) {
    $stmt->bind_param('s', $likeTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['nachname'] . ", " . $row['vorname'] . "\n";
    }
    $stmt->close();
} else {
    echo "Error: " . $mysqli->error . "\n";
}

$mysqli->close();
?>
