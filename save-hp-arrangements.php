<?php
// save-hp-arrangements.php - Speichert HP Arrangements für eine Reservierung
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

if (!$data || !isset($data['res_id']) || !isset($data['arrangements'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Daten']);
    exit;
}

$resId = intval($data['res_id']);
$arrangements = $data['arrangements'];

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    // Debug-Logging
    error_log("Save HP Arrangements - Res ID: " . $resId);
    error_log("Save HP Arrangements - Data: " . json_encode($arrangements));
    
    // Transaction starten
    $hpConn->begin_transaction();
    
    try {
        // Zuerst alle Gäste für diese Reservierung finden (nur HP-Datenbank!)
        // hp_data.resid entspricht AV-Res.id
        
        // Prüfe welche Spalten verfügbar sind
        $columnsQuery = "SHOW COLUMNS FROM hp_data";
        $columnsResult = $hpConn->query($columnsQuery);
        $availableColumns = [];
        
        if ($columnsResult) {
            while ($col = $columnsResult->fetch_assoc()) {
                $availableColumns[] = $col['Field'];
            }
        }
        
        // Bestimme welche Spalte für den Namen verwendet werden soll
        $nameColumn = 'iid'; // Fallback auf iid
        if (in_array('nam', $availableColumns)) {
            $nameColumn = 'nam';
        } elseif (in_array('name', $availableColumns)) {
            $nameColumn = 'name';
        } elseif (in_array('bez', $availableColumns)) {
            $nameColumn = 'bez';
        }
        
        $guestsQuery = "SELECT iid, $nameColumn as guest_name FROM hp_data WHERE resid = ?";
        $stmt = $hpConn->prepare($guestsQuery);
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $hpConn->error);
        }
        
        $stmt->bind_param("i", $resId);
        $stmt->execute();
        $guestsResult = $stmt->get_result();
        
        $guestIds = [];
        $guestNames = [];
        while ($guest = $guestsResult->fetch_assoc()) {
            $guestIds[] = $guest['iid'];
            $guestNames[$guest['iid']] = $guest['guest_name'] ?? "Guest " . $guest['iid'];
        }
        
        if (empty($guestIds)) {
            throw new Exception('Keine Gäste für diese Reservierung in HP-Datenbank gefunden (resid=' . $resId . ')');
        }
        
        error_log("Found " . count($guestIds) . " guests in HP database for resid $resId: " . implode(',', $guestIds));
        
        // Alle bestehenden Arrangements für alle Gäste dieser Reservierung löschen
        $placeholders = str_repeat('?,', count($guestIds) - 1) . '?';
        $deleteQuery = "DELETE FROM hpdet WHERE hp_id IN ($placeholders)";
        $deleteStmt = $hpConn->prepare($deleteQuery);
        
        if (!$deleteStmt) {
            throw new Exception('Delete prepare failed: ' . $hpConn->error);
        }
        
        $deleteStmt->bind_param(str_repeat('i', count($guestIds)), ...$guestIds);
        
        if (!$deleteStmt->execute()) {
            throw new Exception('Delete execute failed: ' . $deleteStmt->error);
        }
        
        error_log("Deleted existing arrangements for guests: " . implode(',', $guestIds));
        
        // Neue Arrangements einfügen
        if (!empty($arrangements)) {
            $insertStmt = $hpConn->prepare("INSERT INTO hpdet (hp_id, arr_id, anz, bem) VALUES (?, ?, ?, ?)");
            
            if (!$insertStmt) {
                throw new Exception('Insert prepare failed: ' . $hpConn->error);
            }
            
            $insertCount = 0;
            
            foreach ($arrangements as $arrangement) {
                $arrId = intval($arrangement['arr_id'] ?? 0);
                $totalCount = intval($arrangement['count'] ?? 1);
                $remark = trim($arrangement['remark'] ?? '');
                
                if ($arrId <= 0 || $totalCount <= 0) {
                    continue; // Überspringe ungültige Arrangements
                }
                
                // Verteile das Arrangement auf alle Gäste
                // Standardmäßig gleichmäßig aufteilen, Rest auf ersten Gast
                $countPerGuest = intval($totalCount / count($guestIds));
                $remainder = $totalCount % count($guestIds);
                
                foreach ($guestIds as $index => $guestId) {
                    $guestCount = $countPerGuest;
                    
                    // Ersten Gast bekommt den Rest
                    if ($index === 0) {
                        $guestCount += $remainder;
                    }
                    
                    if ($guestCount > 0) {
                        $insertStmt->bind_param("iiis", $guestId, $arrId, $guestCount, $remark);
                        
                        if (!$insertStmt->execute()) {
                            throw new Exception('Insert execute failed: ' . $insertStmt->error);
                        }
                        
                        $insertCount++;
                        error_log("Inserted arrangement: guest=$guestId, arr_id=$arrId, count=$guestCount, remark='$remark'");
                    }
                }
            }
            
            error_log("Inserted $insertCount new arrangement records");
        }
        
        // Transaction bestätigen
        $hpConn->commit();
        
        echo json_encode(['success' => true, 'message' => 'Arrangements erfolgreich gespeichert']);
        
    } catch (Exception $e) {
        // Transaction rückgängig machen
        $hpConn->rollback();
        error_log("Transaction rolled back: " . $e->getMessage());
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Save HP arrangements error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
