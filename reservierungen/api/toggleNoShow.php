<?php
// toggleNoShow.php - Simple NoShow toggle like toggleGuideFlag.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// JSON-Body parsen
$payload = json_decode(file_get_contents('php://input'), true);

$id = $payload['id'] ?? null;
if (!is_numeric($id) || (int)$id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige ID']);
    exit;
}
$id = (int)$id;

// Toggle-Query genau wie bei toggleGuideFlag
$sql = "UPDATE `AV-ResNamen` SET NoShow = IF(NoShow=1,0,1) WHERE id = ?";
$stmt = $mysqli->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SQL prepare-Fehler: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param('i', $id);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SQL execute-Fehler: ' . $stmt->error]);
    exit;
}

// Neuen Wert abfragen
$res = $mysqli->query("SELECT NoShow FROM `AV-ResNamen` WHERE id = " . intval($id));
$row = $res->fetch_assoc();
$newValue = (int)$row['NoShow'];

echo json_encode(['success' => true, 'newValue' => $newValue]);
?>
