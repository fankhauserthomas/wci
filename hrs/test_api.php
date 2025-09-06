<?php
// Simple test API to verify JSON output
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Get input
$input = json_decode(file_get_contents('php://input'), true);

// Log the request for debugging
error_log('TEST API Called with: ' . print_r($input, true));

try {
    if (!$input || !isset($input['action'])) {
        throw new Exception('Keine gültige Aktion angegeben');
    }
    
    switch ($input['action']) {
        case 'test_connection':
            $result = array(
                'success' => true,
                'message' => 'Test-Verbindung erfolgreich',
                'timestamp' => date('Y-m-d H:i:s')
            );
            break;
            
        case 'delete_single':
            if (!isset($input['hrs_id'])) {
                throw new Exception('HRS-ID fehlt');
            }
            $result = array(
                'success' => true,
                'message' => 'Test-Löschung simuliert',
                'hrs_id' => $input['hrs_id'],
                'name' => $input['name'] ?? 'Unbekannt'
            );
            break;
            
        case 'delete_multiple':
            if (!isset($input['quotas']) || !is_array($input['quotas'])) {
                throw new Exception('Quotas-Array fehlt');
            }
            
            $details = array();
            foreach ($input['quotas'] as $quota) {
                $details[] = array(
                    'success' => true,
                    'hrs_id' => $quota['hrs_id'] ?? 'unknown',
                    'name' => $quota['name'] ?? 'Unbekannt',
                    'message' => 'Test-Löschung simuliert'
                );
            }
            
            $result = array(
                'success' => true,
                'deleted_count' => count($input['quotas']),
                'total_count' => count($input['quotas']),
                'details' => $details,
                'message' => 'Alle Test-Löschungen simuliert'
            );
            break;
            
        default:
            throw new Exception('Unbekannte Aktion: ' . $input['action']);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => array(
            'input' => $input,
            'timestamp' => date('Y-m-d H:i:s')
        )
    ), JSON_UNESCAPED_UNICODE);
}

exit;
?>
