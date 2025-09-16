<?php
// toggleGuideFlag.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

// JSON-Body parsen
$payload = json_decode(file_get_contents('php://input'), true);
$id = $payload['id'] ?? '';
if (!ctype_digit($id)) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'Ungültige ID']);
    exit;
}

// Toggle-Query
$sql = "UPDATE `AV-ResNamen`
        SET guide = IF(guide=1,0,1)
        WHERE id = ?";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'SQL prepare-Fehler: '.$mysqli->error]);
    exit;
}
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'SQL execute-Fehler: '.$stmt->error]);
    exit;
}

// neuen Wert abfragen
$res = $mysqli->query("SELECT guide FROM `AV-ResNamen` WHERE id = ".intval($id));
$row = $res->fetch_assoc();
$new = (int)$row['guide'];

echo json_encode(['success'=>true, 'newValue'=>$new]);
