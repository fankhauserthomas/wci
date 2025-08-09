<?php
// test-hp-header-direct.php - Direkter Test der HP-Header-API ohne Auth
require_once 'hp-db-config.php';

header('Content-Type: application/json');

$resId = 6202; // Test mit einer bekannten ResID

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    error_log("Getting HP arrangements for header display - res_id: $resId");
    
    // Lade verfügbare Arrangements aus hparr Tabelle
    $availableArrangements = [];
    $arrQuery = "SELECT iid, bez FROM hparr ORDER BY sort, bez";
    $arrResult = $hpConn->query($arrQuery);
    
    if ($arrResult) {
        while ($row = $arrResult->fetch_assoc()) {
            $availableArrangements[$row['iid']] = $row['bez'];
        }
        error_log("Found " . count($availableArrangements) . " available arrangements in hparr table");
    }
    
    // Fallback wenn keine hparr Tabelle vorhanden
    if (empty($availableArrangements)) {
        $availableArrangements = [
            1 => 'HP Fleisch',
            2 => 'HP Vegetarisch', 
            3 => 'BHP Fleisch',
            4 => 'BHP Vegetarisch',
            5 => 'À la Carte',
            6 => 'FSTK'
        ];
    }
    
    // Lade Gäste für diese Reservierung über resid-Verknüpfung
    // AV-Res.id <-> hp_data.resid
    $guestsQuery = "
        SELECT iid, nam as name 
        FROM hp_data 
        WHERE resid = ?
        ORDER BY iid
    ";
    
    $stmt = $hpConn->prepare($guestsQuery);
    if (!$stmt) {
        throw new Exception('Guest query prepare failed: ' . $hpConn->error);
    }
    
    $stmt->bind_param("i", $resId);
    $stmt->execute();
    $guestsResult = $stmt->get_result();
    
    $arrangements = [];
    $totalItems = 0;
    $guestCount = 0;
    $guestNames = [];
    
    // Verarbeite jeden Gast
    while ($guest = $guestsResult->fetch_assoc()) {
        $guestId = $guest['iid'];
        $guestName = $guest['name'] ?? "Guest $guestId";
        $guestCount++;
        $guestNames[$guestId] = $guestName;
        
        error_log("Processing guest: ID=$guestId, Name='$guestName'");
        
        // Lade Arrangements für diesen Gast aus hpdet Tabelle
        // hp_data.iid <-> hpdet.hp_id
        $arrQuery = "
            SELECT 
                hd.arr_id,
                hd.anz,
                hd.bem,
                hd.ts,
                TIMESTAMPDIFF(SECOND, hd.ts, NOW()) as seconds_ago
            FROM hpdet hd
            WHERE hd.hp_id = ?
            ORDER BY hd.arr_id, hd.ts DESC
        ";
        
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
            $bem = trim($arr['bem'] ?? '');
            $secondsAgo = intval($arr['seconds_ago'] ?? 0);
            
            // Name des Arrangements bestimmen
            $arrName = $availableArrangements[$arrId] ?? "Arrangement $arrId";
            
            // Bestimme Zeitklasse basierend auf seconds_ago (wie in tisch-uebersicht.php)
            $timeClass = 'time-old'; // Standard: schwarz (>=2 Minuten)
            if ($secondsAgo < 60) {
                $timeClass = 'time-fresh'; // rot (<1 Minute)
            } elseif ($secondsAgo < 120) {
                $timeClass = 'time-recent'; // gold (<2 Minuten)
            } else {
                // Zusätzliche Regel: Wenn Timestamp-Datum vom Vortag oder früher ist
                $currentDate = date('Y-m-d');
                $tsDate = date('Y-m-d', strtotime($arr['ts']));
                if ($tsDate < $currentDate) {
                    $timeClass = 'time-future'; // himmelblau für Timestamps von vortag oder früher
                }
            }
            
            error_log("Found arrangement: ID=$arrId, Name='$arrName', Count=$anz, Remark='$bem', Guest='$guestName', TimeClass='$timeClass'");
            
            // Erstelle eindeutigen Schlüssel für Gruppierung (Arrangement + Bemerkung)
            $groupKey = $bem ? "$arrId-$bem" : $arrId;
            $displayName = $bem ? "$arrName ($bem)" : $arrName;
            
            if (!isset($arrangements[$groupKey])) {
                $arrangements[$groupKey] = [
                    'id' => $arrId,
                    'name' => $arrName,
                    'remark' => $bem,
                    'display_name' => $displayName,
                    'total_count' => 0,
                    'time_class' => $timeClass,
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
                'remark' => $bem,
                'time_class' => $timeClass,
                'seconds_ago' => $secondsAgo
            ];
            
            // Verwende die "frischeste" Zeitklasse wenn mehrere Einträge vorhanden
            $currentTimeClass = $arrangements[$groupKey]['time_class'];
            if ($timeClass === 'time-fresh' || 
                ($timeClass === 'time-recent' && $currentTimeClass === 'time-old') ||
                ($timeClass === 'time-future' && $currentTimeClass === 'time-old')) {
                $arrangements[$groupKey]['time_class'] = $timeClass;
            }
            
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
        'guest_names' => $guestNames
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Get HP arrangements header error: " . $e->getMessage());
    echo json_encode([
        'error' => $e->getMessage(),
        'debug_info' => [
            'res_id' => $resId,
            'error_line' => $e->getLine(),
            'error_file' => $e->getFile()
        ]
    ], JSON_PRETTY_PRINT);
}
?>
