<?php
// updateReservationNames.php - Update Vorname/Nachname in AV-Res table

header('Content-Type: application/json');
require 'config.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Ungültige JSON-Daten');
    }
    
    $id = (int)($input['id'] ?? 0);
    $vorname = trim($input['vorname'] ?? '');
    $nachname = trim($input['nachname'] ?? '');
    
    if (!$id) {
        throw new Exception('Reservierungs-ID fehlt');
    }
    
    // Update the reservation names
    $sql = "UPDATE `AV-Res` SET vorname = ?, nachname = ? WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('SQL-Fehler: ' . $mysqli->error);
    }
    
    $stmt->bind_param('ssi', $vorname, $nachname, $id);
    
    if (!$stmt->execute()) {
        throw new Exception('Fehler beim Aktualisieren: ' . $stmt->error);
    }
    
    $affected_rows = $stmt->affected_rows;
    
    echo json_encode([
        'success' => true,
        'message' => 'Namen erfolgreich aktualisiert',
        'affected_rows' => $affected_rows,
        'data' => [
            'id' => $id,
            'vorname' => $vorname,
            'nachname' => $nachname
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
