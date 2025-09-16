<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = ''");
$mysqli->query("SET SESSION sql_mode = ''");

$data = json_decode(file_get_contents('php://input'), true);
$id     = $data['id']     ?? '';
$action = $data['action'] ?? '';

if (!ctype_digit((string)$id) || !in_array($action, ['set','clear'], true)) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'Ungültige Parameter']);
    exit;
}

if ($action === 'set') {
    $sql = "UPDATE `AV-ResNamen` SET checked_in = NOW() WHERE id = ?";
} else {
    $sql = "UPDATE `AV-ResNamen` SET checked_in = NULL WHERE id = ?";
}
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>$stmt->error]);
    exit;
}
$stmt->close();

// Bei set: zurückgeben neuen Timestamp für UI
$newValue = null;
if ($action === 'set') {
    // Hole den echten Timestamp aus der Datenbank
    $getTimestampSql = "SELECT checked_in FROM `AV-ResNamen` WHERE id = ?";
    $getStmt = $mysqli->prepare($getTimestampSql);
    $getStmt->bind_param('i', $id);
    $getStmt->execute();
    $result = $getStmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $newValue = $row['checked_in'];
    }
    $getStmt->close();
}

echo json_encode(['success'=>true, 'newValue'=>$newValue]);
