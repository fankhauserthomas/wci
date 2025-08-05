<?php
// test-config.php - Quick test of database connections

header('Content-Type: application/json');

try {
    require 'config.php';
    
    $result = [
        'timestamp' => date('Y-m-d H:i:s'),
        'main_db' => false,
        'hp_db' => false,
        'errors' => []
    ];
    
    // Test main database
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli']) {
        $testQuery = $GLOBALS['mysqli']->query("SELECT 1 as test");
        if ($testQuery) {
            $result['main_db'] = true;
            $testQuery->free();
        } else {
            $result['errors'][] = 'Main DB query failed: ' . $GLOBALS['mysqli']->error;
        }
    } else {
        $result['errors'][] = 'Main DB connection not available';
    }
    
    // Test HP database
    if (isset($GLOBALS['hpDb']) && $GLOBALS['hpDb']) {
        $testQuery = $GLOBALS['hpDb']->query("SELECT 1 as test");
        if ($testQuery) {
            $result['hp_db'] = true;
            $testQuery->free();
        } else {
            $result['errors'][] = 'HP DB query failed: ' . $GLOBALS['hpDb']->error;
        }
    } else {
        $result['errors'][] = 'HP DB connection not available (this is OK)';
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
?>
