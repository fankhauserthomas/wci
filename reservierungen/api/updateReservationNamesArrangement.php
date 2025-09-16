<?php
// updateReservationNamesArrangement.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

// JSON-Körper einlesen
$data = json_decode(file_get_contents('php://input'), true);
$ids = $data['ids'] ?? null;
$arrId = $data['arr'] ?? null;

if (!is_array($ids) || !$arrId || !ctype_digit((string)$arrId)) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'Ungültige Parameter']);
    exit;
}

// Prepared Statement zum Updaten
$stmt = $mysqli->prepare("UPDATE `AV-ResNamen` SET arr = ? WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'SQL prepare error: '.$mysqli->error]);
    exit;
}

foreach ($ids as $id) {
    if (!ctype_digit((string)$id)) continue;
    $stmt->bind_param('ii', $arrId, $id);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success'=>false, 'error'=>'SQL execute error: '.$stmt->error]);
        exit;
    }
}
$stmt->close();

echo json_encode(['success'=>true]);
