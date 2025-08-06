<?php
// save-arrangements.php - Arrangements eines Gastes speichern
require_once 'auth-simple.php';
require_once 'hp-db-config.php';

// Authentifizierung prüfen
if (!AuthManager::checkSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht authentifiziert']);
    exit;
}

header('Content-Type: application/json');

// POST-Daten lesen
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['guest_id']) || !isset($data['arrangements'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Daten']);
    exit;
}

$guestId = intval($data['guest_id']);
$arrangements = $data['arrangements'];
$guestRemark = isset($data['guest_remark']) ? trim($data['guest_remark']) : null;

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    // Debug: Log der eingehenden Daten
    error_log("Save Arrangements - Guest ID: " . $guestId);
    error_log("Save Arrangements - Arrangements: " . json_encode($arrangements));
    error_log("Save Arrangements - Guest Remark: " . ($guestRemark ?? 'NULL'));
    
    // Transaction starten
    $hpConn->begin_transaction();
    
    try {
        // Alle bisherigen Arrangements des Gastes löschen
        $deleteStmt = $hpConn->prepare("DELETE FROM hpdet WHERE hp_id = ?");
        if (!$deleteStmt) {
            throw new Exception("Delete prepare failed: " . $hpConn->error);
        }
        $deleteStmt->bind_param("i", $guestId);
        if (!$deleteStmt->execute()) {
            throw new Exception("Delete execute failed: " . $deleteStmt->error);
        }
        error_log("Deleted existing arrangements for guest: " . $guestId);
        
        // Gast-Bemerkung aktualisieren falls vorhanden
        if ($guestRemark !== null) {
            $updateGuestStmt = $hpConn->prepare("UPDATE hp_data SET bem = ? WHERE iid = ?");
            if (!$updateGuestStmt) {
                throw new Exception("Update prepare failed: " . $hpConn->error);
            }
            $updateGuestStmt->bind_param("si", $guestRemark, $guestId);
            if (!$updateGuestStmt->execute()) {
                throw new Exception("Update execute failed: " . $updateGuestStmt->error);
            }
            error_log("Updated guest remark for guest: " . $guestId);
        }
        
        // Neue Arrangements einfügen
        if (!empty($arrangements)) {
            $insertStmt = $hpConn->prepare("INSERT INTO hpdet (hp_id, arr_id, anz, bem) VALUES (?, ?, ?, ?)");
            if (!$insertStmt) {
                throw new Exception("Insert prepare failed: " . $hpConn->error);
            }
            
            $insertCount = 0;
            foreach ($arrangements as $arrId => $items) {
                $arrIdInt = intval($arrId);
                foreach ($items as $item) {
                    $anz = intval($item['anz'] ?? 1);
                    $bem = trim($item['bem'] ?? '');
                    
                    // Nur einfügen wenn Anzahl > 0
                    if ($anz > 0) {
                        if (!$insertStmt->bind_param("iiis", $guestId, $arrIdInt, $anz, $bem)) {
                            throw new Exception("Insert bind_param failed: " . $insertStmt->error);
                        }
                        if (!$insertStmt->execute()) {
                            throw new Exception("Insert execute failed: " . $insertStmt->error);
                        }
                        $insertCount++;
                    }
                }
            }
            error_log("Inserted $insertCount new arrangements for guest: " . $guestId);
        }
        
        // Transaction bestätigen
        $hpConn->commit();
        error_log("Transaction committed successfully for guest: " . $guestId);
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        // Transaction rückgängig machen
        $hpConn->rollback();
        error_log("Transaction rolled back: " . $e->getMessage());
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Save arrangements error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'details' => $e->getTraceAsString()]);
}
?>
