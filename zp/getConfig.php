<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config.php';

try {
    // Bereitstellung der Konfiguration für JavaScript-Clients
    $config = [
        // Hütten-Informationen
        'hut' => [
            'id' => HUT_ID,
            'name' => HUT_NAME,
            'short' => HUT_SHORT
        ],
        
        // URL-Konfiguration
        'urls' => [
            'base' => BASE_URL,
            'fallback' => FALLBACK_BASE_URL,
            'wci' => API_BASE_URL,
            'zp' => BASE_URL . ZP_PATH,
            'reservations' => BASE_URL . RESERVATIONS_PATH,
            'pictures' => BASE_URL . PIC_PATH,
            'hrs' => BASE_URL . HRS_PATH,
            'api' => BASE_URL . API_PATH
        ],
        
        // Pfade (relativ ohne Domain)
        'paths' => [
            'wci' => WCI_PATH,
            'zp' => ZP_PATH,
            'reservations' => RESERVATIONS_PATH,
            'pictures' => PIC_PATH,
            'hrs' => HRS_PATH,
            'api' => API_PATH
        ],
        
        // HRS-Konfiguration (ohne Passwörter!)
        'hrs' => [
            'base_url' => HRS_BASE_URL,
            'hut_id' => HUT_ID,
            'api_base' => HRS_API_BASE
        ],
        
        // Timeline-Einstellungen
        'timeline' => [
            'default_room_height' => DEFAULT_ROOM_HEIGHT,
            'default_day_width' => DEFAULT_DAY_WIDTH,
            'master_bar_height' => MASTER_BAR_HEIGHT,
            'max_occupancy_days' => MAX_OCCUPANCY_DAYS,
            'default_timeline_days' => DEFAULT_TIMELINE_DAYS
        ],
        
        // API-Endpunkte (alle verfügbaren Endpunkte)
        'endpoints' => array_merge(
            TIMELINE_ENDPOINTS,
            [
                'rooms' => ZP_PATH . '/getRooms.php',
                'av_reservation_data' => ZP_PATH . '/getAVReservationData.php',
                'update_room_detail' => ZP_PATH . '/updateRoomDetail.php',
                'assign_rooms' => ZP_PATH . '/assignRoomsToReservation.php'
            ]
        )
    ];
    
    // Erfolgreiche Antwort
    echo json_encode([
        'success' => true,
        'config' => $config,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>