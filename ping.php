<?php
// ping.php - Simple health check endpoint
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Allow CORS if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

try {
    echo json_encode([
        'status' => 'ok',
        'timestamp' => time(),
        'datetime' => date('Y-m-d H:i:s'),
        'server' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'php_version' => PHP_VERSION,
        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
?>
