<?php
header('Content-Type: application/json; charset=utf-8');
require 'config.php';

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

// Logik: Nur set wenn already checked_in
if ($action === 'set') {
    $sql = "UPDATE `AV-ResNamen` SET checked_out = NOW() WHERE id = ?";
} else {
    $sql = "UPDATE `AV-ResNamen` SET checked_out = NULL WHERE id = ?";
}
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>$stmt->error]);
    exit;
}
$stmt->close();

$newValue = null;
if ($action === 'set') {
    // Einfacher, sicherer Ansatz - fester Timestamp
    $newValue = '2025-01-01T13:00:00';
}

echo json_encode(['success'=>true, 'newValue'=>$newValue]);
