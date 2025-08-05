<?php
// hp-db-test.php - Test HP database separately

header('Content-Type: application/json');

try {
    // Include the separate HP DB config
    require 'hp-db-config.php';
    
    $result = [
        'timestamp' => date('Y-m-d H:i:s'),
        'hp_db_available' => false,
        'hp_db_test' => false
    ];
    
    // Check if HP DB is available
    $result['hp_db_available'] = isHpDbAvailable();
    
    if ($result['hp_db_available']) {
        $hpConn = getHpDbConnection();
        if ($hpConn) {
            $testQuery = $hpConn->query("SELECT 1 as test");
            if ($testQuery) {
                $result['hp_db_test'] = true;
                $testQuery->free();
            }
        }
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}
