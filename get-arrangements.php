<?php
// get-arrangements.php - Verfügbare Arrangements laden
require_once 'hp-db-config.php';

header('Content-Type: application/json');

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    // Alle verfügbaren Arrangement-Arten laden
    $sql = "SELECT iid, bez FROM hparr ORDER BY sort, bez";
    $result = $hpConn->query($sql);
    
    $arrangements = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $arrangements[$row['iid']] = $row['bez'];
        }
    }
    
    echo json_encode($arrangements);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
