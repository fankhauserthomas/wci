<?php
// Get available HP arrangements and current data for reservation

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include authentication
require_once 'auth-simple.php';

// Include HP database connection
require_once 'hp-db-config.php';

// Get reservation ID
$resId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$resId) {
    http_response_code(400);
    echo json_encode(['error' => 'Keine gültige Reservierungs-ID']);
    exit;
}

try {
    // Get HP database connection
    $hpConnection = getHpDbConnection();
    
    $response = [
        'arrangements' => [],
        'current_data' => [],
        'success' => true
    ];
    
    // 1. Get available arrangements from hparr table
    $stmt = $hpConnection->prepare("SELECT id, bez FROM hparr ORDER BY bez");
    $stmt->execute();
    $result = $stmt->get_result();
    $arrangements = $result->fetch_all(MYSQLI_ASSOC);
    
    foreach ($arrangements as $arr) {
        $response['arrangements'][] = [
            'id' => $arr['id'],
            'bez' => $arr['bez']
        ];
    }
    
    // 2. Get current data for this reservation
    // Find hp_data entry for this reservation
    $stmt = $hpConnection->prepare("SELECT id FROM hp_data WHERE resid = ?");
    $stmt->bind_param("i", $resId);
    $stmt->execute();
    $result = $stmt->get_result();
    $hp_data = $result->fetch_assoc();
    
    if ($hp_data) {
        $hp_data_id = $hp_data['id'];
        
        // Get current hpdet entries for this hp_data
        $stmt = $hpConnection->prepare("
            SELECT d.*, a.bez as arr_name 
            FROM hpdet d 
            JOIN hparr a ON d.arr_id = a.id 
            WHERE d.hp_id = ?
        ");
        $stmt->bind_param("i", $hp_data_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current_details = $result->fetch_all(MYSQLI_ASSOC);
        
        foreach ($current_details as $detail) {
            $response['current_data'][] = [
                'arr_id' => $detail['arr_id'],
                'arr_name' => $detail['bez'],
                'anz' => $detail['anz'],
                'bem' => $detail['bem']
            ];
        }
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Error in get-hp-arrangements-table.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Datenbankfehler: ' . $e->getMessage(),
        'success' => false
    ]);
}
?>
