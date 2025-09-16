<?php
// Save HP arrangements table data

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include HP database connection
require_once __DIR__ . '/../../hp-db-config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST erlaubt']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$resId = isset($input['resId']) ? intval($input['resId']) : 0;
$arrangements = isset($input['arrangements']) ? $input['arrangements'] : [];

if (!$resId) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine gültige Reservierungs-ID']);
    exit;
}

try {
    // Get HP database connection
    $hpConnection = getHpDbConnection();
    $hpConnection->begin_transaction();
    
    // 1. Find or create hp_data entry for this reservation
    $stmt = $hpConnection->prepare("SELECT id FROM hp_data WHERE resid = ?");
    $stmt->bind_param("i", $resId);
    $stmt->execute();
    $result = $stmt->get_result();
    $hp_data = $result->fetch_assoc();
    
    if (!$hp_data) {
        // Create new hp_data entry
        $stmt = $hpConnection->prepare("INSERT INTO hp_data (resid, created_at) VALUES (?, NOW())");
        $stmt->bind_param("i", $resId);
        $stmt->execute();
        $hp_data_id = $hpConnection->insert_id;
    } else {
        $hp_data_id = $hp_data['id'];
    }
    
    // 2. Delete existing hpdet entries for this hp_data
    $stmt = $hpConnection->prepare("DELETE FROM hpdet WHERE hp_id = ?");
    $stmt->bind_param("i", $hp_data_id);
    $stmt->execute();
    
    // 3. Insert new hpdet entries
    $saved_arrangements = [];
    foreach ($arrangements as $arr) {
        $arr_id = intval($arr['arr_id']);
        $anz = intval($arr['anz']);
        $bem = trim($arr['bem']);
        
        // Only save if anzahl > 0
        if ($arr_id > 0 && $anz > 0) {
            $stmt = $hpConnection->prepare("
                INSERT INTO hpdet (hp_id, arr_id, anz, bem, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("iiis", $hp_data_id, $arr_id, $anz, $bem);
            $stmt->execute();
            
            // Get arrangement name for response
            $stmt = $hpConnection->prepare("SELECT bez FROM hparr WHERE id = ?");
            $stmt->bind_param("i", $arr_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $arr_name = $row ? $row['bez'] : '';
            
            $saved_arrangements[] = [
                'arr_id' => $arr_id,
                'arr_name' => $arr_name,
                'anz' => $anz,
                'bem' => $bem
            ];
        }
    }
    
    $hpConnection->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'HP Arrangements erfolgreich gespeichert',
        'saved_arrangements' => $saved_arrangements,
        'total_count' => count($saved_arrangements)
    ]);
    
} catch (Exception $e) {
    $hpConnection->rollBack();
    error_log("Error in save-hp-arrangements-table.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Fehler beim Speichern: ' . $e->getMessage(),
        'success' => false
    ]);
}
?>
