<?php
require_once 'config.php';
require_once __DIR__ . '/auth.php';

// Auth prüfen
if (!checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht authentifiziert']);
    exit;
}

header('Content-Type: application/json');

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    // Query für alle Arrangement-Zelldaten mit Zeitklassen
    $sql = "
        SELECT DISTINCT 
            r.iid AS guest_id,
            arr.id AS arr_id,
            COALESCE(ad.text, '') AS content,
            hp_det.ts,
            CASE 
                WHEN hp_det.ts IS NULL THEN 'time-old'
                WHEN TIMESTAMPDIFF(MINUTE, hp_det.ts, NOW()) < 1 THEN 'time-fresh'
                WHEN TIMESTAMPDIFF(MINUTE, hp_det.ts, NOW()) < 2 THEN 'time-recent'  
                WHEN DATE(hp_det.ts) < CURDATE() THEN 'time-future'
                ELSE 'time-old'
            END AS timeClass
        FROM res r
        LEFT JOIN ti ON r.tisch = ti.id
        CROSS JOIN arr
        LEFT JOIN arr_det ad ON ad.res = r.iid AND ad.arr = arr.id
        LEFT JOIN hp_det ON hp_det.res = r.iid AND hp_det.arr = arr.id
        WHERE r.active = 1 
        AND ti.id IS NOT NULL 
        AND ti.bez != ''
        ORDER BY ti.bez, r.nam
    ";
    
    $stmt = $hpConn->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $results
    ]);
    
} catch (Exception $e) {
    error_log("get-arrangement-cells-data.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
