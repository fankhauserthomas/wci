<?php
// deleteReservationDetail.php - Backend für das Löschen einzelner AV_ResDet Datensätze
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

    // Prüfe ob Detail-Datensatz existiert und hole Info
    $stmt = $mysqli->prepare("SELECT ID, resid, zimID, von, bis, bez FROM AV_ResDet WHERE ID = ?");
    $stmt->bind_param('i', $detailId);
    $stmt->execute();
    $result = $stmt->get_result();
    $detail = $result->fetch_assoc();
    $stmt->close();
    
    if (!$detail) {
        throw new Exception('Detail-Datensatz nicht gefunden');
    }

    // Transaction starten für sicheres Löschen
    $mysqli->begin_transaction();

    try {
        // Detail-Datensatz löschen
        $stmt = $mysqli->prepare("DELETE FROM AV_ResDet WHERE ID = ?");
        $stmt->bind_param('i', $detailId);
        
        if (!$stmt->execute()) {
            throw new Exception('Fehler beim Löschen des Detail-Datensatzes: ' . $stmt->error);
        }
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('Detail-Datensatz konnte nicht gelöscht werden (möglicherweise bereits gelöscht)');
        }
        
        $stmt->close();

        // Transaction committen
        $mysqli->commit();
        
        // Auto-sync auslösen falls verfügbar
        if (function_exists('triggerAutoSync')) {
            triggerAutoSync('delete_detail');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Detail-Datensatz erfolgreich gelöscht',
            'deletedDetail' => [
                'id' => $detailId,
                'resid' => $detail['resid'],
                'zimID' => $detail['zimID'],
                'von' => $detail['von'],
                'bis' => $detail['bis'],
                'bez' => $detail['bez']
            ]
        ]);

    } catch (Exception $e) {
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