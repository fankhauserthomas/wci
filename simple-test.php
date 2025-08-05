<?php
// simple-test.php - Test main database only

header('Content-Type: application/json');

try {
    require 'config.php';
    
    $result = [
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'OK',
        'main_db' => false
    ];
    
    // Test main database
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli']) {
        $testQuery = $GLOBALS['mysqli']->query("SELECT 1 as test");
        if ($testQuery) {
            $result['main_db'] = true;
            $testQuery->free();
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
