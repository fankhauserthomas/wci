<?php
// getLogis.php - liefert verfügbare Logis-Einträge (Zimmerarten)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$sql = "SELECT ID, kbez, bez, sort FROM logis ORDER BY sort, kbez";
$result = $mysqli->query($sql);
if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'SQL-Fehler: ' . $mysqli->error
    ]);
    exit;
}

$logis = [];
while ($row = $result->fetch_assoc()) {
    $logis[] = [
        'id' => (int)$row['ID'],
        'kbez' => $row['kbez'],
        'bez' => $row['bez'],
        'sort' => isset($row['sort']) ? (int)$row['sort'] : null
    ];
}

$result->free();

echo json_encode($logis);
