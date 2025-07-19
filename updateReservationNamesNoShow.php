<?php
// updateReservationNamesNoShow.php - Backend für NoShow Status Update
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

try {
    // Input lesen
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Keine gültigen JSON-Daten erhalten');
    }
    
    $ids = $data['ids'] ?? [];
    $noShow = $data['noshow'] ?? false;
    
    if (!is_array($ids) || empty($ids)) {
        throw new Exception('Keine gültigen IDs erhalten');
    }
    
    // IDs validieren und zu integers konvertieren
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($id) { return $id > 0; });
    
    if (empty($ids)) {
        throw new Exception('Keine gültigen IDs nach Validierung');
    }
    
    // NoShow Status als 1 oder 0 für MySQL
    $noShowValue = $noShow ? 1 : 0;
    
    // Prepared Statement für Batch-Update
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    
    $sql = "UPDATE `AV-ResNamen` SET 
                `NoShow` = ?, 
                `sync_timestamp` = NOW() 
            WHERE id IN ($placeholders)";
    
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    // Parameters: noShow-Wert + alle IDs
    $types = 'i' . str_repeat('i', count($ids));
    $params = array_merge([$noShowValue], $ids);
    
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    if ($affectedRows === 0) {
        // Prüfe ob die IDs überhaupt existieren
        $checkPlaceholders = str_repeat('?,', count($ids) - 1) . '?';
        $checkStmt = $mysqli->prepare("SELECT COUNT(*) as count FROM `AV-ResNamen` WHERE id IN ($checkPlaceholders)");
        $checkStmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $checkStmt->execute();
        $result = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if ($result['count'] == 0) {
            throw new Exception('Keine der angegebenen Namen gefunden');
        } else {
            // Namen existieren, aber Update hatte keinen Effekt (vielleicht schon der richtige Status)
            error_log("NoShow update had no effect - names might already have the target status");
        }
    }
    
    // Auto-sync auslösen falls verfügbar
    if (function_exists('triggerAutoSync')) {
        triggerAutoSync('update_noshow');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'NoShow Status erfolgreich aktualisiert',
        'updated_count' => $affectedRows,
        'target_status' => $noShow,
        'ids' => $ids
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
