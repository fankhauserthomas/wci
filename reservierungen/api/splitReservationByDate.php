<?php
// splitReservationByDate.php - API für das Splitten von Reservierungsdetails nach Datum
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    // Input lesen
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Keine gültigen JSON-Daten erhalten');
    }
    
    $detailId = (int)($data['detailId'] ?? 0);
    $splitDate = $data['splitDate'] ?? '';
    
    if ($detailId <= 0) {
        throw new Exception('Ungültige Detail-ID');
    }
    
    if (empty($splitDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $splitDate)) {
        throw new Exception('Ungültiges Split-Datum');
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
    
    // Prüfen ob Split-Datum im gültigen Bereich liegt
    if ($splitDate <= $originalDetail['von'] || $splitDate >= $originalDetail['bis']) {
        throw new Exception('Split-Datum muss zwischen von und bis liegen');
    }
    
    // Transaktions-Start
    $mysqli->begin_transaction();
    
    try {
        // Neues Detail erstellen ZUERST (bevor wir das Original ändern!)
        // von-Datum = Split-Datum, bis-Datum = ursprüngliches bis-Datum, ParentID = Original-ID
        $stmt = $mysqli->prepare("
            INSERT INTO AV_ResDet (
                tab, zimID, resid, bez, anz, von, bis, arr, col, note, dx, dy, ParentID, hund
            )
            SELECT tab, zimID, resid, bez, anz, ?, bis, arr, col, note, dx, dy, ?, hund
            FROM AV_ResDet 
            WHERE ID = ?
        ");
        
        $stmt->bind_param("sii", $splitDate, $detailId, $detailId);
        $stmt->execute();
        $newDetailId = $mysqli->insert_id;
        
        // Dann Original Detail: bis-Datum auf Split-Datum setzen
        $stmt = $mysqli->prepare("UPDATE AV_ResDet SET bis = ? WHERE ID = ?");
        $stmt->bind_param("si", $splitDate, $detailId);
        $stmt->execute();
        
        // Commit der Transaktion
        $mysqli->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Detail erfolgreich gesplittet',
            'originalDetailId' => $detailId,
            'newDetailId' => $newDetailId,
            'splitDate' => $splitDate
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