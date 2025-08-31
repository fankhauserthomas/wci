<?php
/* ==============================================
   EINFACHER API TEST
   ============================================== */

header('Content-Type: application/json; charset=utf-8');

// Config laden
require_once '../../config.php';

// Einfache mysqli Verbindung
$conn = new mysqli($GLOBALS['dbHost'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], $GLOBALS['dbName']);

if ($conn->connect_error) {
    echo json_encode([
        'success' => false,
        'error' => 'Verbindung fehlgeschlagen: ' . $conn->connect_error
    ]);
    exit;
}

$conn->set_charset("utf8mb4");

// Einfache Abfrage
$date = $_GET['date'] ?? date('Y-m-d');
$sql = "SELECT id, nachname, vorname, anreise, abreise, (betten + dz + lager + sonder) as anzahl FROM `AV-Res` WHERE DATE(anreise) = ? LIMIT 10";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reservations = [];
    while ($row = $result->fetch_assoc()) {
        $reservations[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'date' => $date,
        'count' => count($reservations),
        'data' => $reservations
    ], JSON_PRETTY_PRINT);
    
    $stmt->close();
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Query preparation failed: ' . $conn->error
    ]);
}

$conn->close();
?>
