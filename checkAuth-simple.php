<?php
// checkAuth-simple.php - Session-Check ohne .htaccess-Blockierung
require_once __DIR__ . '/auth-simple.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    if (AuthManager::isAuthenticated()) {
        echo json_encode([
            'authenticated' => true,
            'session_info' => AuthManager::getSessionInfo()
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'authenticated' => false,
            'message' => 'Nicht angemeldet'
        ]);
    }
} catch (Exception $e) {
    error_log('Auth check error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'authenticated' => false,
        'message' => 'Fehler bei der Überprüfung'
    ]);
}
?>
