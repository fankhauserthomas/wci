<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    if (AuthManager::checkSession()) {
        echo json_encode([
            'authenticated' => true,
            'session_info' => AuthManager::getSessionInfo()
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'authenticated' => false,
            'message' => 'Session abgelaufen oder nicht angemeldet',
            'redirect' => 'login.html'
        ]);
    }
} catch (Exception $e) {
    error_log("Auth check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'authenticated' => false,
        'error' => 'Server-Fehler'
    ]);
}
?>
