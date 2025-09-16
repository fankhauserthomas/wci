<?php
// get-all-hp-data.php - Abrufen aller HP-Arrangements und Check-in Daten für ein Datum

require_once 'config.php';
require_once 'hp-db-config.php';

// Anti-Cache Headers - Verhindert Browser/Proxy-Caching
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

// Hilfsfunktion für Sortiergruppen-Beschreibungen
function getSortGroupDescription(
    $group,
    $hpArrangements,
    $totalNames,
    $checkedInCount,
    $reservedPersons = 0,
    $type = 'arrival',
    $checkedOutCount = 0
) {
    if ($type === 'departure') {
        switch ($group) {
            case 'B':
                return "Teilweise ausgecheckt: $checkedOutCount von $checkedInCount Gästen sind bereits ausgecheckt";
            case 'C':
                if ($checkedInCount > 0) {
                    return "Noch keine Abreise verbucht: 0 von $checkedInCount Gästen ausgecheckt";
                }
                return "Keine Check-ins oder Abreisen erfasst";
            case 'D':
                return "Alle Gäste ausgecheckt: $checkedOutCount von $checkedInCount";
            default:
                return "Abreise-Status unklar (Check-ins: $checkedInCount, Check-outs: $checkedOutCount)";
        }
    }

    switch ($group) {
        case 'A':
            return "Diskrepanz: HP-Arrangements ($hpArrangements) ≠ Total Names ($totalNames), Check-ins vorhanden ($checkedInCount)";
        case 'B':
            return "HP-Arrangements vorhanden ($hpArrangements), aber keine Check-ins";
        case 'C':
            return "Keine HP-Arrangements und keine Check-ins";
        case 'D':
            return "Ausgeglichen: HP-Arrangements ($hpArrangements) = Reserved Persons ($reservedPersons)";
        case 'X':
        default:
            return "Nicht klassifiziert: HP=$hpArrangements, Names=$totalNames, Check-ins=$checkedInCount, Reserved=$reservedPersons";
    }
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    // Parameter
    $date = $_GET['date'] ?? date('Y-m-d');
    $type = $_GET['type'] ?? 'arrival';
    
    // CACHING DEAKTIVIERT für sofortige Updates
    // Das 30-Sekunden-Caching war die Ursache für die Verzögerung!
    /*
    $cacheKey = md5("hp-data-{$date}-{$type}");
    $cacheFile = sys_get_temp_dir() . "/hp_cache_{$cacheKey}.json";
    $cacheMaxAge = 30; // 30 Sekunden Cache
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        if ($cachedData) {
            header('X-Cache: HIT');
            echo json_encode($cachedData);
            exit;
        }
    }
    */
    
    // Hauptdatenbank-Verbindung (für AV-Daten) - verwende mysqli statt PDO
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_error) {
        throw new Exception('AV-Datenbank Verbindung fehlgeschlagen: ' . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');
    
    // HP-Datenbank-Verbindung
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        error_log('HP-Datenbank nicht verfügbar - verwende Fallback-Werte');
    }
    
    // Reservierungen aus Hauptdatenbank abrufen - ERWEITERT um Belegungsfelder
    $dateColumn = ($type === 'arrival') ? 'anreise' : 'abreise';
    $query = "
        SELECT 
            id,
            av_id,
            nachname,
            vorname,
            sonder,
            betten,
            lager,
            dz,
            (COALESCE(sonder, 0) + COALESCE(betten, 0) + COALESCE(lager, 0) + COALESCE(dz, 0)) AS total_reserved_persons
        FROM `AV-Res` 
        WHERE DATE($dateColumn) = ?
        ORDER BY nachname, vorname
    ";
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('Query preparation failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservations = $result->fetch_all(MYSQLI_ASSOC);
    
    // PERFORMANCE OPTIMIZATION: Bulk queries instead of N+1 pattern
    
    // 1. Bulk HP-Arrangements abrufen (falls HP-DB verfügbar)
    $hpData = [];
    if ($hpConn && !empty($reservations)) {
        // Verwende die IDs aus AV-Res (diese entsprechen r.iid in der HP-Datenbank)
        $resIds = array_column($reservations, 'id');
        
        if (!empty($resIds)) {
            $placeholders = str_repeat('?,', count($resIds) - 1) . '?';
            $hpQuery = "
                SELECT 
                    r.iid,
                    SUM(hd.anz) AS sumarr
                FROM res r
                LEFT JOIN hp_data d ON d.resid = r.iid
                LEFT JOIN hpdet hd ON hd.hp_id = d.id
                WHERE r.iid IN ($placeholders)
                GROUP BY r.iid
            ";
            
            $hpStmt = $hpConn->prepare($hpQuery);
            if ($hpStmt && $hpStmt->execute($resIds)) {
                $hpResult = $hpStmt->get_result();
                while ($row = $hpResult->fetch_assoc()) {
                    $hpData[$row['iid']] = (int)($row['sumarr'] ?? 0);
                }
            }
        }
    }
    
    // 2. Bulk Check-in Counts aus AV-ResNamen abrufen
    $checkedInData = [];
    $checkedOutData = [];
    $totalNamesData = [];
    if (!empty($reservations)) {
        $resIds = array_column($reservations, 'id');
        $placeholders = str_repeat('?,', count($resIds) - 1) . '?';

        // Check-ins abrufen
        $checkQuery = "
            SELECT 
                av_id,
                SUM(CASE WHEN NoShow = 0 THEN 1 ELSE 0 END) as total_names,
                SUM(CASE 
                    WHEN NoShow = 0
                    AND checked_in IS NOT NULL 
                    AND CAST(checked_in AS CHAR) != '' 
                    AND CAST(checked_in AS CHAR) != '0000-00-00 00:00:00'
                    AND checked_in > '1970-01-01 00:00:00'
                THEN 1 ELSE 0 END) as checked_in_count,
                SUM(CASE 
                    WHEN NoShow = 0
                    AND checked_out IS NOT NULL 
                    AND CAST(checked_out AS CHAR) != '' 
                    AND CAST(checked_out AS CHAR) != '0000-00-00 00:00:00'
                    AND checked_out > '1970-01-01 00:00:00'
                THEN 1 ELSE 0 END) as checked_out_count
            FROM `AV-ResNamen` 
            WHERE av_id IN ($placeholders)
            GROUP BY av_id
        ";

        $stmt2 = $mysqli->prepare($checkQuery);
        $stmt2->bind_param(str_repeat('i', count($resIds)), ...$resIds);
        $stmt2->execute();
        $checkResult = $stmt2->get_result();
        
        while ($row = $checkResult->fetch_assoc()) {
            $checkedInData[$row['av_id']] = (int)$row['checked_in_count'];
            $checkedOutData[$row['av_id']] = (int)$row['checked_out_count'];
            $totalNamesData[$row['av_id']] = (int)$row['total_names'];
        }
    }
    
    // 3. Ergebnisse zusammenbauen - ERWEITERT um reservierte Personen und Sortiergruppen
    $result = [];
    foreach ($reservations as $res) {
        $resId = $res['id'];
        $avId = $res['av_id'];
        
        $hpArrangements = $hpData[$resId] ?? 0;
        $checkedInCount = $checkedInData[$resId] ?? 0;
        $checkedOutCount = $checkedOutData[$resId] ?? 0;
        $totalNames = $totalNamesData[$resId] ?? 0;
        $reservedPersons = (int)($res['total_reserved_persons'] ?? 0);

        // Sortiergruppen bestimmen
        $sortGroup = '';
        $sortPriority = 0;
        
        if ($type === 'departure') {
            if ($checkedInCount > 0 && $checkedOutCount >= $checkedInCount) {
                // Alle ausgecheckt → blau, ganz unten
                $sortGroup = 'D';
                $sortPriority = 3;
            } elseif ($checkedOutCount > 0) {
                // Teilweise ausgecheckt → orange, ganz oben
                $sortGroup = 'B';
                $sortPriority = 1;
            } else {
                // Noch niemand ausgecheckt → neutral in der Mitte
                $sortGroup = 'C';
                $sortPriority = 2;
            }
        } else {
            if (($hpArrangements != $reservedPersons) && ($checkedInCount > 0)) {
                // Gruppe A: HP-Arrangements ≠ Total Names UND Check-ins vorhanden
                $sortGroup = 'A';
                $sortPriority = 1;
            } elseif (($hpArrangements > 0) && ($checkedInCount == 0)) {
                // Gruppe B: HP-Arrangements vorhanden ABER keine Check-ins
                $sortGroup = 'B';
                $sortPriority = 2;
            } elseif (($hpArrangements == 0) && ($checkedInCount == 0)) {
                // Gruppe C: Keine HP-Arrangements UND keine Check-ins
                $sortGroup = 'C';
                $sortPriority = 3;
            } elseif ($hpArrangements == $reservedPersons) {
                // Gruppe D: HP-Arrangements = Reserved Persons (ausgeglichen)
                $sortGroup = 'D';
                $sortPriority = 4;
            } else {
                // Fallback für nicht klassifizierte Fälle
                $sortGroup = 'X';
                $sortPriority = 5;
            }
        }
        
        $result[] = [
            'res_id' => $resId,
            'av_id' => $avId,
            'name' => $res['nachname'] . ' ' . $res['vorname'],
            'hp_arrangements' => $hpArrangements,
            'checked_in_count' => $checkedInCount,
            'checked_out_count' => $checkedOutCount,
            'total_names' => $totalNames,
            'reserved_persons' => $reservedPersons, // Sonder+Betten+Lager+DZ
            'breakdown' => [
                'sonder' => (int)($res['sonder'] ?? 0),
                'betten' => (int)($res['betten'] ?? 0),
                'lager' => (int)($res['lager'] ?? 0),
                'dz' => (int)($res['dz'] ?? 0)
            ],
            'sort_group' => $sortGroup,
            'sort_priority' => $sortPriority,
            'sort_description' => getSortGroupDescription(
                $sortGroup,
                $hpArrangements,
                $totalNames,
                $checkedInCount,
                $reservedPersons,
                $type,
                $checkedOutCount
            )
        ];
    }
    
    // Optional: Ergebnisse nach Sortiergruppe sortieren
    usort($result, function($a, $b) {
        if ($a['sort_priority'] == $b['sort_priority']) {
            return strcmp($a['name'], $b['name']); // Alphabetisch innerhalb der Gruppe
        }
        return $a['sort_priority'] <=> $b['sort_priority'];
    });
    
    $responseData = [
        'success' => true,
        'data' => $result,
        'date' => $date,
        'type' => $type,
        'hp_db_available' => ($hpConn !== false)
    ];
    
    // Cache speichern DEAKTIVIERT - für sofortige Updates
    // file_put_contents($cacheFile, json_encode($responseData));
    header('X-Cache: DISABLED');
    
    echo json_encode($responseData);
    
} catch (Exception $e) {
    error_log('HP-Arrangements Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
