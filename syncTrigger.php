<?php
// Disable HTML error output for clean JSON
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config.php';
require_once 'SyncManager.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// CORS für AJAX requests
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

try {
    // Check if sync is enabled
    if (!defined('SYNC_ENABLED') || !SYNC_ENABLED) {
        throw new Exception('Sync is disabled');
    }
    
    $action = $_POST['action'] ?? $_GET['action'] ?? 'sync';
    $trigger = $_POST['trigger'] ?? $_GET['trigger'] ?? 'manual';
    
    $syncManager = new SyncManager();
    
    switch ($action) {
        case 'sync':
            $result = $syncManager->syncOnPageLoad($trigger);
            echo json_encode($result);
            break;
            
        case 'status':
            // Quick sync status check
            echo json_encode([
                'success' => true,
                'sync_enabled' => SYNC_ENABLED,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
