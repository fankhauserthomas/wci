<?php
// GetCardPrinters.php
header('Content-Type: application/json');
require 'config.php';

$sql = "SELECT ID, bez, kbez FROM CartPrinters ORDER BY bez";
if (!$result = $mysqli->query($sql)) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL-Fehler: ' . $mysqli->error]);
    exit;
}

$printers = [];
while ($row = $result->fetch_assoc()) {
    $printers[] = [
        'id'   => $row['ID'],
        'bez'  => $row['bez'],
        'kbez' => $row['kbez']
    ];
}

echo json_encode($printers);
