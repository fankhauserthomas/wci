<?php
// get-guest-arrangements.php - Arrangements eines Gastes laden
require_once 'auth-simple.php';
require_once 'hp-db-config.php';

// Authentifizierung prüfen
if (!AuthManager::checkSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht authentifiziert']);
    exit;
}

header('Content-Type: application/json');

if (!isset($_GET['guest_id']) || !is_numeric($_GET['guest_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Gast-ID']);
    exit;
}

$guestId = intval($_GET['guest_id']);

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    // Aktuelle Arrangements des Gastes laden
    $sql = "
        SELECT 
            arr_id,
            anz,
            bem
        FROM hpdet
        WHERE hp_id = ?
        ORDER BY arr_id, bem
    ";
    
    $stmt = $hpConn->prepare($sql);
    $stmt->bind_param("i", $guestId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $arrangements = [];
    while ($row = $result->fetch_assoc()) {
        $arrId = $row['arr_id'];
        if (!isset($arrangements[$arrId])) {
            $arrangements[$arrId] = [];
        }
        
        $arrangements[$arrId][] = [
            'anz' => intval($row['anz']),
            'bem' => $row['bem'] ?? ''
        ];
    }
    
    echo json_encode($arrangements);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
