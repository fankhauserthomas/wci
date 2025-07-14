<?php
// logout-simple.php - Logout ohne .htaccess-Blockierung
require_once __DIR__ . '/auth-simple.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

try {
    AuthManager::logout();
    
    echo json_encode([
        'success' => true,
        'message' => 'Erfolgreich abgemeldet'
    ]);
} catch (Exception $e) {
    error_log('Logout error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Abmelden'
    ]);
}
?>
