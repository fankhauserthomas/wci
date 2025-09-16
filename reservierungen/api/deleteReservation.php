<?php
// deleteReservation.php - Backend für komplettes Löschen von Reservierungen
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    // Input lesen
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Keine gültigen JSON-Daten erhalten');
    }
    
    $resId = (int)($data['id'] ?? 0);
    
    if ($resId <= 0) {
        throw new Exception('Ungültige Reservierungs-ID');
    }

    // Prüfe ob Reservierung existiert
    $stmt = $mysqli->prepare("SELECT id, nachname, vorname FROM `AV-Res` WHERE id = ?");
    $stmt->bind_param('i', $resId);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservation = $result->fetch_assoc();
    $stmt->close();
    
    if (!$reservation) {
        throw new Exception('Reservierung nicht gefunden');
    }

    // Transaction starten für komplettes Löschen
    $mysqli->begin_transaction();

    try {
        // 1. Alle AV_ResDet Datensätze löschen (Detail-Datensätze)
        $stmt1 = $mysqli->prepare("DELETE FROM AV_ResDet WHERE resid = ?");
        $stmt1->bind_param('i', $resId);
        
        if (!$stmt1->execute()) {
            throw new Exception('Fehler beim Löschen der Detail-Datensätze: ' . $stmt1->error);
        }
        
        $deletedDetails = $stmt1->affected_rows;
        $stmt1->close();

        // 2. Hauptreservierung aus AV-Res löschen
        $stmt2 = $mysqli->prepare("DELETE FROM `AV-Res` WHERE id = ?");
        $stmt2->bind_param('i', $resId);
        
        if (!$stmt2->execute()) {
            throw new Exception('Fehler beim Löschen der Hauptreservierung: ' . $stmt2->error);
        }
        
        if ($stmt2->affected_rows === 0) {
            throw new Exception('Reservierung konnte nicht gelöscht werden (möglicherweise bereits gelöscht)');
        }
        
        $stmt2->close();

        // Transaction committen
        $mysqli->commit();
        
        // Auto-sync auslösen falls verfügbar
        if (function_exists('triggerAutoSync')) {
            triggerAutoSync('delete_reservation');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Reservierung erfolgreich gelöscht',
            'deletedDetails' => $deletedDetails,
            'reservation' => [
                'id' => $resId,
                'name' => trim(($reservation['nachname'] ?? '') . ' ' . ($reservation['vorname'] ?? ''))
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
