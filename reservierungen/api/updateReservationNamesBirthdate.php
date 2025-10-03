<?php
// updateReservationNamesBirthdate.php - aktualisiert das Geburtsdatum eines Namenseintrags
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige JSON-Daten']);
    exit;
}

$id = isset($payload['id']) ? (int)$payload['id'] : 0;
$birthdate = $payload['gebdat'] ?? null;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige ID']);
    exit;
}

$normalizedDate = null;
if (is_string($birthdate) && trim($birthdate) !== '') {
    $birthdate = trim($birthdate);
    // Erwartet Format YYYY-MM-DD
    $dt = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$dt || $dt->format('Y-m-d') !== $birthdate) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültiges Datumsformat. Erwartet YYYY-MM-DD.']);
        exit;
    }
    $normalizedDate = $dt->format('Y-m-d');
}

$stmt = $mysqli->prepare('UPDATE `AV-ResNamen` SET gebdat = ? WHERE id = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SQL prepare error: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param('si', $normalizedDate, $id);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SQL execute error: ' . $stmt->error]);
    exit;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'gebdat' => $normalizedDate
]);
