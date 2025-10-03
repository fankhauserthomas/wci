<?php
// updateReservationNamesLogis.php - aktualisiert die Logis-Zuordnung für ausgewählte Namen
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$payload = json_decode(file_get_contents('php://input'), true);
$ids = $payload['ids'] ?? null;
$logisId = $payload['logis'] ?? null;

if (!is_array($ids) || empty($ids) || $logisId === null || !ctype_digit((string)$logisId)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Ungültige Parameter'
    ]);
    exit;
}

// Sicherstellen, dass Spalte vorhanden ist
$columnCheck = $mysqli->query("SHOW COLUMNS FROM `AV-ResNamen` LIKE 'logis'");
if (!$columnCheck || $columnCheck->num_rows === 0) {
    if ($columnCheck) {
        $columnCheck->free();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Logis-Feld in AV-ResNamen nicht vorhanden.'
    ]);
    exit;
}
if ($columnCheck) {
    $columnCheck->free();
}

$stmt = $mysqli->prepare('UPDATE `AV-ResNamen` SET logis = ? WHERE id = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'SQL prepare error: ' . $mysqli->error
    ]);
    exit;
}

foreach ($ids as $id) {
    if (!ctype_digit((string)$id)) {
        continue;
    }
    $stmt->bind_param('ii', $logisId, $id);
    if (!$stmt->execute()) {
        $stmt->close();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'SQL execute error: ' . $stmt->error
        ]);
        exit;
    }
}

$stmt->close();

echo json_encode(['success' => true]);
