<?php
// splitReservationDetail.php - API für das Teilen von Reservierungsdetails
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    // Input lesen
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Keine gültigen JSON-Daten erhalten');
    }
    
    $detailId = (int)($data['detailId'] ?? 0);
    
    if ($detailId <= 0) {
        throw new Exception('Ungültige Detail-ID');
    }

    // Original-Detail laden
    $stmt = $mysqli->prepare("SELECT * FROM AV_ResDet WHERE ID = ?");
    $stmt->bind_param("i", $detailId);
    $stmt->execute();
    $result = $stmt->get_result();
    $originalDetail = $result->fetch_assoc();
    
    if (!$originalDetail) {
        throw new Exception('Detail nicht gefunden');
    }
    
    $currentAnzahl = (int)$originalDetail['anz'];
    
    if ($currentAnzahl <= 1) {
        throw new Exception('Kann nur Details mit Anzahl > 1 teilen');
    }
    
    // Transaktions-Start
    $mysqli->begin_transaction();
    
    try {
        // Original Detail auf Anzahl 1 setzen
        $stmt = $mysqli->prepare("UPDATE AV_ResDet SET anz = 1 WHERE ID = ?");
        $stmt->bind_param("i", $detailId);
        $stmt->execute();
        
        // Neues Detail erstellen durch Kopieren des Originals mit neuer Anzahl
        $newAnzahl = $currentAnzahl - 1;
        
        $stmt = $mysqli->prepare("
            INSERT INTO AV_ResDet (
                tab, zimID, resid, bez, anz, von, bis, arr, col, note, dx, dy, ParentID, hund
            )
            SELECT tab, zimID, resid, bez, ?, von, bis, arr, col, note, dx, dy, ParentID, hund
            FROM AV_ResDet 
            WHERE ID = ?
        ");
        
        $stmt->bind_param("ii", $newAnzahl, $detailId);
        $stmt->execute();
        $newDetailId = $mysqli->insert_id;
        
        // Commit der Transaktion
        $mysqli->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Detail erfolgreich geteilt',
            'originalDetailId' => $detailId,
            'newDetailId' => $newDetailId,
            'originalAnzahl' => 1,
            'newAnzahl' => $newAnzahl
        ]);
        
    } catch (Exception $e) {
        // Rollback bei Fehler
        $mysqli->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>