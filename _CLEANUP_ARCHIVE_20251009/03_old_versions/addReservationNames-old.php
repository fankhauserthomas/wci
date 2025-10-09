<?php
// reservierungen/api/addReservationNames.php
header('Content-Type: application/json');
require 'config.php';

$payload = json_decode(file_get_contents('php://input'), true);
$id      = $payload['id'] ?? '';
$entries = $payload['entries'] ?? [];

if (!ctype_digit($id) || !is_array($entries)) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'Ungültige Daten']);
    exit;
}

// Bereite INSERT vor
$sql = "INSERT INTO `AV-ResNamen`
        (av_id, vorname, nachname)
        VALUES (?, ?, ?)";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'SQL-Fehler: '.$mysqli->error]);
    exit;
}

foreach ($entries as $e) {
    $vn = trim($e['vorname']);
    $nn = trim($e['nachname']);
    if ($vn === '' && $nn === '') continue;
    $stmt->bind_param('iss', $id, $vn, $nn);
    if (!$stmt->execute()) {
        // Bei erstem Fehler abbrechen
        http_response_code(500);
        echo json_encode(['success'=>false, 'error'=>'Insert-Fehler: '.$stmt->error]);
        exit;
    }
}

echo json_encode(['success'=>true]);
