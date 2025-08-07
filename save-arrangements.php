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

// Clean up arrangements data - remove null/empty elements from arrays
function cleanArrangements($arrangements) {
    $cleaned = [];
    foreach ($arrangements as $arrId => $items) {
        if (is_array($items)) {
            $cleanedItems = [];
            foreach ($items as $item) {
                if ($item !== null && $item !== '' && is_array($item)) {
                    $cleanedItems[] = $item;
                }
            }
            if (!empty($cleanedItems)) {
                $cleaned[$arrId] = $cleanedItems;
            }
        }
    }
    return $cleaned;
}

$arrangements = cleanArrangements($arrangements);
error_log("Cleaned arrangements: " . json_encode($arrangements));

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    // Debug: Log der eingehenden Daten
    error_log("Save Arrangements - Guest ID: " . $guestId);
    error_log("Save Arrangements - Arrangements: " . json_encode($arrangements));
    error_log("Save Arrangements - Guest Remark: " . ($guestRemark ?? 'NULL'));
    
    // Prüfen ob Gast in hp_data existiert, wenn nicht, erstellen
    $checkGuestStmt = $hpConn->prepare("SELECT iid FROM hp_data WHERE iid = ?");
    if (!$checkGuestStmt) {
        throw new Exception("Check guest prepare failed: " . $hpConn->error);
    }
    $checkGuestStmt->bind_param("i", $guestId);
    $checkGuestStmt->execute();
    $checkResult = $checkGuestStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        error_log("Guest $guestId not found in hp_data, attempting to create from res table");
        
        // Versuchen, Gast aus res Tabelle zu erstellen
        $createGuestStmt = $hpConn->prepare("INSERT INTO hp_data (iid, name, von, bis, bem) SELECT iid, name, von, bis, '' FROM res WHERE iid = ?");
        if (!$createGuestStmt) {
            throw new Exception("Create guest prepare failed: " . $hpConn->error);
        }
        $createGuestStmt->bind_param("i", $guestId);
        if (!$createGuestStmt->execute()) {
            // Wenn es fehlschlägt, versuchen wir ein minimales Insert
            error_log("Could not create guest from res table, creating minimal entry");
            $createMinimalStmt = $hpConn->prepare("INSERT INTO hp_data (iid, name, von, bis, bem) VALUES (?, 'Unknown Guest', CURDATE(), CURDATE(), '')");
            if (!$createMinimalStmt) {
                throw new Exception("Create minimal guest prepare failed: " . $hpConn->error);
            }
            $createMinimalStmt->bind_param("i", $guestId);
            if (!$createMinimalStmt->execute()) {
                throw new Exception("Create minimal guest failed: " . $createMinimalStmt->error);
            }
            error_log("Created minimal guest entry for guest: " . $guestId);
        } else {
            error_log("Created guest entry from res table for guest: " . $guestId);
        }
    } else {
        error_log("Guest $guestId already exists in hp_data");
    }
    
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
                
                // Debug: Log what we're processing
                error_log("Processing arrangement ID $arrIdInt with items: " . json_encode($items));
                
                // Skip if items is not an array or is empty
                if (!is_array($items)) {
                    error_log("Skipping arrangement ID $arrIdInt: items is not an array");
                    continue;
                }
                
                foreach ($items as $index => $item) {
                    // Skip null/empty items (from sparse arrays)
                    if ($item === null || $item === '') {
                        error_log("Skipping empty item at index $index for arrangement ID $arrIdInt");
                        continue;
                    }
                    
                    if (!is_array($item)) {
                        error_log("Skipping non-array item at index $index for arrangement ID $arrIdInt: " . json_encode($item));
                        continue;
                    }
                    
                    $anz = intval($item['anz'] ?? 1);
                    $bem = trim($item['bem'] ?? '');
                    
                    error_log("Processing item: arr_id=$arrIdInt, anz=$anz, bem='$bem'");
                    
                    // Nur einfügen wenn Anzahl > 0
                    if ($anz > 0) {
                        if (!$insertStmt->bind_param("iiis", $guestId, $arrIdInt, $anz, $bem)) {
                            throw new Exception("Insert bind_param failed: " . $insertStmt->error);
                        }
                        if (!$insertStmt->execute()) {
                            throw new Exception("Insert execute failed: " . $insertStmt->error);
                        }
                        $insertCount++;
                        error_log("Successfully inserted arrangement: guest=$guestId, arr_id=$arrIdInt, anz=$anz");
                    } else {
                        error_log("Skipping item with anz=0: arr_id=$arrIdInt");
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
