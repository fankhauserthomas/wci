<?php
// get-hp-arrangements.php - Lädt HP Arrangements für eine Reservierung (nur HP-Datenbank!)
require_once 'hp-db-config.php';

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
    
    // Debug-Logging
    error_log("Getting HP arrangements for res_id: $resId");
    
    // Prüfe zuerst, ob arr Tabelle existiert
    $arrTableExists = false;
    $availableArrangements = [];
    
    $tableCheck = $hpConn->query("SHOW TABLES LIKE 'arr'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $arrTableExists = true;
        
        // Lade alle verfügbaren Arrangements aus arr Tabelle
        $arrangementsQuery = "SELECT iid, bez FROM arr ORDER BY bez";
        $arrangementsResult = $hpConn->query($arrangementsQuery);
        
        if ($arrangementsResult) {
            while ($row = $arrangementsResult->fetch_assoc()) {
                $availableArrangements[$row['iid']] = $row['bez'];
            }
        }
        error_log("Found " . count($availableArrangements) . " available arrangements in arr table");
    } else {
        error_log("arr table does not exist in HP database");
        // Fallback: Standard-Arrangements definieren
        $availableArrangements = [
            1 => 'HP Fleisch',
            2 => 'HP Vegetarisch', 
            3 => 'BHP Fleisch',
            4 => 'BHP Vegetarisch',
            5 => 'À la Carte',
            6 => 'FSTK'
        ];
    }
    
    // Lade HP Arrangements für diese Reservierung über resid-Verknüpfung
    // hp_data.resid entspricht AV-Res.id
    // Zuerst prüfen welche Spalten verfügbar sind
    $columnsQuery = "SHOW COLUMNS FROM hp_data";
    $columnsResult = $hpConn->query($columnsQuery);
    $availableColumns = [];
    
    if ($columnsResult) {
        while ($col = $columnsResult->fetch_assoc()) {
            $availableColumns[] = $col['Field'];
        }
        error_log("Available columns in hp_data: " . implode(', ', $availableColumns));
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
    
    error_log("Using name column: $nameColumn");
    
    $guestsQuery = "SELECT iid, $nameColumn as guest_name FROM hp_data WHERE resid = ?";
    $stmt = $hpConn->prepare($guestsQuery);
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $hpConn->error);
    }
    
    $stmt->bind_param("i", $resId);
    $stmt->execute();
    $guestsResult = $stmt->get_result();
    
    $arrangements = [];
    $totalItems = 0;
    $guestCount = 0;
    
    while ($guest = $guestsResult->fetch_assoc()) {
        $guestId = $guest['iid'];
        $guestName = $guest['guest_name'] ?? "Guest $guestId";
        $guestCount++;
        
        error_log("Processing guest: ID=$guestId, Name='$guestName'");
        
        // Lade Arrangements für diesen Gast aus hpdet Tabelle
        $arrQuery = "SELECT arr_id, anz, bem FROM hpdet WHERE hp_id = ? ORDER BY arr_id";
        
        $arrStmt = $hpConn->prepare($arrQuery);
        if (!$arrStmt) {
            error_log("Could not prepare hpdet query: " . $hpConn->error);
            continue;
        }
        
        $arrStmt->bind_param("i", $guestId);
        $arrStmt->execute();
        $arrResult = $arrStmt->get_result();
        
        while ($arr = $arrResult->fetch_assoc()) {
            $arrId = $arr['arr_id'];
            $anz = intval($arr['anz']);
            $bem = $arr['bem'] ?? '';
            
            // Name des Arrangements bestimmen
            $arrName = $availableArrangements[$arrId] ?? "Arrangement $arrId";
            
            error_log("Found arrangement: ID=$arrId, Name='$arrName', Count=$anz, Remark='$bem', Guest='$guestName'");
            
            // Gruppiere nach Bemerkung (wenn vorhanden) und Arrangement-ID
            $displayName = $bem ? "$arrName ($bem)" : $arrName;
            $groupKey = $bem ? "$arrId-$bem" : $arrId;
            
            if (!isset($arrangements[$groupKey])) {
                $arrangements[$groupKey] = [
                    'id' => $arrId,
                    'name' => $arrName,
                    'remark' => $bem,
                    'display_name' => $displayName,
                    'total_count' => 0,
                    'guests' => [],
                    'details' => []
                ];
            }
            
            $arrangements[$groupKey]['total_count'] += $anz;
            $arrangements[$groupKey]['guests'][$guestId] = $guestName;
            $arrangements[$groupKey]['details'][] = [
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
    
    // Sortiere Arrangements nach display_name
    uasort($arrangements, function($a, $b) {
        return strcmp($a['display_name'], $b['display_name']);
    });
    
    $arrangementsArray = array_values($arrangements);
    
    error_log("Returning " . count($arrangementsArray) . " arrangement types with total $totalItems items for $guestCount guests");
    
    echo json_encode([
        'success' => true,
        'arrangements' => $arrangementsArray,
        'available_arrangements' => $availableArrangements,
        'total_items' => $totalItems,
        'guest_count' => $guestCount,
        'arr_table_exists' => $arrTableExists
    ]);
    
} catch (Exception $e) {
    error_log("Get HP arrangements error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'debug_info' => [
            'res_id' => $resId,
            'error_line' => $e->getLine(),
            'error_file' => $e->getFile()
        ]
    ]);
}
?>
