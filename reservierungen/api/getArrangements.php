<?php
// getArrangements.php
header('Content-Type: application/json; charset=utf-8');
require 'config.php';

$sql = "SELECT ID, kbez FROM arr ORDER BY sort";
$result = $mysqli->query($sql);
if (! $result) {
    http_response_code(500);
    echo json_encode([
      'error' => 'SQL-Fehler: ' . $mysqli->error
    ]);
    exit;
}

$arrangements = [];
while ($row = $result->fetch_assoc()) {
    $arrangements[] = [
        'id'   => $row['ID'],
        'kbez' => $row['kbez']
    ];
}

echo json_encode($arrangements);
