<?php
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

$sql = "SELECT id, country as name FROM countries ORDER BY name";
$result = $mysqli->query($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL-Fehler: ' . $mysqli->error]);
    exit;
}

$countries = [];
while ($row = $result->fetch_assoc()) {
    $countries[] = $row;
}

echo json_encode($countries);
