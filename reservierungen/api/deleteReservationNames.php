<?php
// deleteReservationNames.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

// JSON-Body auslesen
$data = json_decode(file_get_contents('php://input'), true);
$ids  = $data['ids'] ?? [];

if (!is_array($ids) || empty($ids)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Keine Zeilen markiert.']);
    exit;
}

$stmt = $mysqli->prepare("DELETE FROM `AV-ResNamen` WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SQL prepare error: ' . $mysqli->error]);
    exit;
}

foreach ($ids as $id) {
    if (!ctype_digit((string)$id)) continue;
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'SQL execute error: ' . $stmt->error]);
        exit;
    }
}
$stmt->close();

echo json_encode(['success' => true]);
