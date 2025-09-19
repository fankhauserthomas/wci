<?php
// deleteReservationAllDetails.php - Backend für das Löschen aller AV_ResDet Datensätze einer Reservierung
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    // Input lesen
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Keine gültigen JSON-Daten erhalten');
    }
    
    $resId = (int)($data['resId'] ?? 0);
    
    if ($resId <= 0) {
        throw new Exception('Ungültige Reservierungs-ID');
    }

    // Prüfe ob Reservierung existiert und hole alle zugehörigen Detail-Datensätze
    $stmt = $mysqli->prepare("SELECT ID, resid, zimID, von, bis, bez FROM AV_ResDet WHERE resid = ?");
    $stmt->bind_param('i', $resId);
    $stmt->execute();
    $result = $stmt->get_result();
    $details = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if (empty($details)) {
        throw new Exception('Keine Detail-Datensätze für diese Reservierung gefunden');
    }

    // Transaction starten für sicheres Löschen aller Details
    $mysqli->begin_transaction();

    try {
        // Alle Detail-Datensätze der Reservierung löschen
        $stmt = $mysqli->prepare("DELETE FROM AV_ResDet WHERE resid = ?");
        $stmt->bind_param('i', $resId);
        
        if (!$stmt->execute()) {
            throw new Exception('Fehler beim Löschen der Detail-Datensätze: ' . $stmt->error);
        }
        
        $deletedCount = $stmt->affected_rows;
        $stmt->close();

        if ($deletedCount === 0) {
            throw new Exception('Keine Detail-Datensätze konnten gelöscht werden');
        }

        // Transaction committen
        $mysqli->commit();
        
        // Auto-sync auslösen falls verfügbar
        if (function_exists('triggerAutoSync')) {
            triggerAutoSync('delete_all_details');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Alle Detail-Datensätze der Reservierung erfolgreich gelöscht',
            'deletedCount' => $deletedCount,
            'resId' => $resId,
            'deletedDetails' => $details
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