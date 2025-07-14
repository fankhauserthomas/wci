<?php
header('Content-Type: application/json; charset=utf-8');
require 'config.php';

$data = json_decode(file_get_contents('php://input'), true);
$ids  = $data['ids'] ?? [];
$diet = $data['diet'] ?? '';

if (!is_array($ids) || !ctype_digit((string)$diet)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Ungültige Daten']);
    exit;
}

$stmt = $mysqli->prepare("UPDATE `AV-ResNamen` SET diet=? WHERE id=?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$mysqli->error]);
    exit;
}
foreach ($ids as $id) {
    if (!ctype_digit((string)$id)) continue;
    $stmt->bind_param('ii',$diet,$id);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>$stmt->error]);
        exit;
    }
}
$stmt->close();
echo json_encode(['success'=>true]);
