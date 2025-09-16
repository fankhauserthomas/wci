<?php
// get-hp-arrangements.php - Lädt HP Arrangements für eine Reservierung
require_once __DIR__ . '/auth.php';
require_once 'hp-db-config.php';

// Authentifizierung prüfen
if (!AuthManager::checkSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht authentifiziert']);
    exit;
}

header('Content-Type: application/json');

if (!isset($_GET['res_id']) || !is_numeric($_GET['res_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Reservierungs-ID']);
    exit;
}

$resId = intval($_GET['res_id']);

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    // Lade alle verfügbaren Arrangements aus arr Tabelle (HP-DB) oder fallback auf AV-Res DB
    $availableArrangements = [];
    
    // Versuche zuerst HP-DB arr Tabelle
    $arrangementsQuery = "SELECT iid, bez FROM arr ORDER BY bez";
    $arrangementsResult = $hpConn->query($arrangementsQuery);
    
    if (!$arrangementsResult) {
        // Fallback: Verwende AV-Res Datenbank arr Tabelle
        error_log("HP arr table not found, using AV-Res database");
        
        // Verbinde mit AV-Res DB
        require_once 'config.php';
        global $mysqli;
        
        if ($mysqli) {
            $arrangementsQuery = "SELECT id as iid, kbez as bez FROM arr ORDER BY kbez";
            $arrangementsResult = $mysqli->query($arrangementsQuery);
            
            if ($arrangementsResult) {
                while ($row = $arrangementsResult->fetch_assoc()) {
                    $availableArrangements[$row['iid']] = $row['bez'];
                }
            }
        }
        
        // Wenn auch das nicht funktioniert, verwende Standard-Arrangements
        if (empty($availableArrangements)) {
            $availableArrangements = [
                1 => 'HP Fleisch',
                2 => 'HP Veg.',
                3 => 'BHP Fleisch', 
                4 => 'BHP Veg.',
                5 => 'a la carte',
                6 => 'FSTK'
            ];
        }
    } else {
        while ($row = $arrangementsResult->fetch_assoc()) {
            $availableArrangements[$row['iid']] = $row['bez'];
        }
    }
    
    // Lade HP Arrangements für diese Reservierung
    // Zuerst aus hp_data die Gäste für diese Reservierung finden
    $guestsQuery = "SELECT iid, name FROM hp_data WHERE resid = ?";
    $stmt = $hpConn->prepare($guestsQuery);
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $hpConn->error);
    }
    
    $stmt->bind_param("i", $resId);
    $stmt->execute();
    $guestsResult = $stmt->get_result();
    
    $arrangements = [];
    $totalItems = 0;
    
    while ($guest = $guestsResult->fetch_assoc()) {
        $guestId = $guest['iid'];
        $guestName = $guest['name'];
        
        // Lade Arrangements für diesen Gast
        $arrQuery = "
            SELECT 
                hd.arr_id,
                hd.anz,
                hd.bem
            FROM hpdet hd
            WHERE hd.hp_id = ?
            ORDER BY hd.arr_id
        ";
        
        $arrStmt = $hpConn->prepare($arrQuery);
        if (!$arrStmt) {
            continue;
        }
        
        $arrStmt->bind_param("i", $guestId);
        $arrStmt->execute();
        $arrResult = $arrStmt->get_result();
        
        while ($arr = $arrResult->fetch_assoc()) {
            $arrId = $arr['arr_id'];
            $arrName = $availableArrangements[$arrId] ?? 'Arrangement ' . $arrId;
            $anz = intval($arr['anz']);
            $bem = $arr['bem'] ?? '';
            
            // Gruppiere nach Arrangement-ID
            if (!isset($arrangements[$arrId])) {
                $arrangements[$arrId] = [
                    'id' => $arrId,
                    'name' => $arrName,
                    'total_count' => 0,
                    'guests' => [],
                    'details' => []
                ];
            }
            
            $arrangements[$arrId]['total_count'] += $anz;
            $arrangements[$arrId]['guests'][$guestId] = $guestName;
            $arrangements[$arrId]['details'][] = [
                'guest_id' => $guestId,
                'guest_name' => $guestName,
                'count' => $anz,
                'remark' => $bem
            ];
            
            $totalItems += $anz;
        }
        
        $arrStmt->close();
    }
    
    $stmt->close();
    
    // Sortiere Arrangements nach Namen
    uasort($arrangements, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
    
    echo json_encode([
        'success' => true,
        'arrangements' => array_values($arrangements),
        'available_arrangements' => $availableArrangements,
        'total_items' => $totalItems,
        'guest_count' => $guestsResult->num_rows
    ]);
    
} catch (Exception $e) {
    error_log("Get HP arrangements error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
