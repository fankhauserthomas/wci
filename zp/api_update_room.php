<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

try {
    // Nur POST erlauben
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Nur POST-Anfragen erlaubt');
    }
    
    // JSON-Input lesen
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Ungültiger JSON-Input');
    }
    
    // Parameter validieren
    $reservationId = $input['reservation_id'] ?? null;
    $newZimmerId = $input['new_zimmer_id'] ?? null;
    
    if (!$reservationId || !is_numeric($reservationId)) {
        throw new Exception('Ungültige Reservierungs-ID');
    }
    
    if (!$newZimmerId || !is_numeric($newZimmerId)) {
        throw new Exception('Ungültige Zimmer-ID');
    }
    
    // Verwende die globale $mysqli Verbindung aus config.php
    global $mysqli;
    if (!$mysqli) {
        throw new Exception('Datenbankverbindung nicht verfügbar');
    }
    
    // Prüfe ob Reservierung existiert
    $checkStmt = $mysqli->prepare("
        SELECT r.ID, r.zimID as old_zimmer_id, r.bez as name 
        FROM AV_ResDet r 
        WHERE r.ID = ?
    ");
    $checkStmt->bind_param("i", $reservationId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $reservation = $result->fetch_assoc();
    
    if (!$reservation) {
        throw new Exception("Reservierung ID {$reservationId} nicht gefunden");
    }
    
    $oldZimmerId = $reservation['old_zimmer_id'];
    $guestName = $reservation['name'] ?? 'Unbekannt';
    
    // Prüfe ob Zimmer existiert
    $zimmerStmt = $mysqli->prepare("SELECT caption FROM zp_zimmer WHERE id = ?");
    $zimmerStmt->bind_param("i", $newZimmerId);
    $zimmerStmt->execute();
    $zimmerResult = $zimmerStmt->get_result();
    $zimmer = $zimmerResult->fetch_assoc();
    
    if (!$zimmer) {
        throw new Exception("Zimmer ID {$newZimmerId} nicht gefunden");
    }
    
    $zimmerName = $zimmer['caption'];
    
    // Update durchführen
    $updateStmt = $mysqli->prepare("
        UPDATE AV_ResDet 
        SET zimID = ? 
        WHERE ID = ?
    ");
    $updateStmt->bind_param("ii", $newZimmerId, $reservationId);
    $updateResult = $updateStmt->execute();
    
    if (!$updateResult) {
        throw new Exception('Update fehlgeschlagen: ' . $mysqli->error);
    }
    
    $affectedRows = $mysqli->affected_rows;
    
    if ($affectedRows === 0) {
        throw new Exception('Keine Zeilen aktualisiert - möglicherweise bereits korrekte Zimmer-ID');
    }
    
    // Erfolgreiche Antwort
    echo json_encode([
        'success' => true,
        'message' => 'Zimmerwechsel erfolgreich gespeichert',
        'data' => [
            'reservation_id' => $reservationId,
            'old_zimmer_id' => $oldZimmerId,
            'new_zimmer_id' => $newZimmerId,
            'zimmer_name' => $zimmerName,
            'guest_name' => $guestName,
            'affected_rows' => $affectedRows,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>