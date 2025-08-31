<?php
// get-all-hp-data.php - Abrufen aller HP-Arrangements und Check-in Daten für ein Datum

require_once 'config.php';
require_once 'hp-db-config.php';

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
    
    // Einfaches File-Caching für 30 Sekunden (reduziert DB-Last)
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
    
    // Reservierungen aus Hauptdatenbank abrufen
    $dateColumn = ($type === 'arrival') ? 'anreise' : 'abreise';
    $query = "
        SELECT 
            id,
            av_id,
            nachname,
            vorname
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
                    SUM(d.anz) AS sumarr
                FROM res r
                LEFT JOIN resdet d ON d.resid = r.iid
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
    $totalNamesData = [];
    if (!empty($reservations)) {
        $resIds = array_column($reservations, 'id');
        $placeholders = str_repeat('?,', count($resIds) - 1) . '?';
        
        // Check-ins abrufen
        $checkQuery = "
            SELECT 
                av_id,
                COUNT(*) as total_names,
                SUM(CASE WHEN checked_in IS NOT NULL THEN 1 ELSE 0 END) as checked_count
            FROM `AV-ResNamen` 
            WHERE av_id IN ($placeholders)
            GROUP BY av_id
        ";
        
        $stmt2 = $mysqli->prepare($checkQuery);
        $stmt2->bind_param(str_repeat('i', count($resIds)), ...$resIds);
        $stmt2->execute();
        $checkResult = $stmt2->get_result();
        
        while ($row = $checkResult->fetch_assoc()) {
            $checkedInData[$row['av_id']] = (int)$row['checked_count'];
            $totalNamesData[$row['av_id']] = (int)$row['total_names'];
        }
    }
    
    // 3. Ergebnisse zusammenbauen
    $result = [];
    foreach ($reservations as $res) {
        $resId = $res['id'];
        $avId = $res['av_id'];
        
        $result[] = [
            'res_id' => $resId,
            'av_id' => $avId,
            'name' => $res['nachname'] . ' ' . $res['vorname'],
            'hp_arrangements' => $hpData[$resId] ?? 0, // HP-DB nutzt r.iid = AV-Res.id
            'checked_in_count' => $checkedInData[$resId] ?? 0,
            'total_names' => $totalNamesData[$resId] ?? 0
        ];
    }
    
    $responseData = [
        'success' => true,
        'data' => $result,
        'date' => $date,
        'type' => $type,
        'hp_db_available' => ($hpConn !== false)
    ];
    
    // Cache speichern für künftige Anfragen
    file_put_contents($cacheFile, json_encode($responseData));
    header('X-Cache: MISS');
    
    echo json_encode($responseData);
    
} catch (Exception $e) {
    error_log('HP-Arrangements Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
