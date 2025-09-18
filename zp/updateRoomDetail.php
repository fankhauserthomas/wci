<?php
// updateRoomDetail.php - Speichert Drag-and-Drop-Änderungen aus dem Zimmerplan

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!is_array($payload)) {
        $payload = $_POST ?? [];
    }

    $detailId = isset($payload['detail_id']) ? (int)$payload['detail_id'] : 0;
    $reservationId = isset($payload['res_id']) ? (int)$payload['res_id'] : 0;
    $roomId = isset($payload['room_id']) ? (int)$payload['room_id'] : 0;
    $startDate = isset($payload['start_date']) ? trim($payload['start_date']) : '';
    $endDate = isset($payload['end_date']) ? trim($payload['end_date']) : '';

    if ($detailId <= 0) {
        throw new InvalidArgumentException('detail_id fehlt oder ist ungültig.');
    }

    if ($reservationId <= 0) {
        throw new InvalidArgumentException('res_id fehlt oder ist ungültig.');
    }

    if ($roomId <= 0) {
        throw new InvalidArgumentException('room_id fehlt oder ist ungültig.');
    }

    if (empty($startDate) || empty($endDate)) {
        throw new InvalidArgumentException('Start- oder Enddatum fehlt.');
    }

    $startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
    $endDateObj = DateTime::createFromFormat('Y-m-d', $endDate);

    if (!$startDateObj || !$endDateObj) {
        throw new InvalidArgumentException('Datum muss im Format YYYY-MM-DD vorliegen.');
    }

    if ($endDateObj < $startDateObj) {
        throw new InvalidArgumentException('Enddatum darf nicht vor dem Startdatum liegen.');
    }

    $updateSql = "UPDATE AV_ResDet SET zimID = ?, von = ?, bis = ? WHERE ID = ? AND resid = ?";
    $stmt = $mysqli->prepare($updateSql);

    if (!$stmt) {
        throw new RuntimeException('Prepare fehlgeschlagen: ' . $mysqli->error);
    }

    $von = $startDateObj->format('Y-m-d');
    $bis = $endDateObj->format('Y-m-d');

    if (!$stmt->bind_param('issii', $roomId, $von, $bis, $detailId, $reservationId)) {
        throw new RuntimeException('Bind fehlgeschlagen: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Ausführung fehlgeschlagen: ' . $stmt->error);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    // Auch wenn keine Zeile "geändert" wurde (gleiche Werte), gilt die Operation als erfolgreich
    triggerAutoSync('update_room_detail');

    echo json_encode([
        'success' => true,
        'detail_id' => $detailId,
        'rows_affected' => $affected
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
