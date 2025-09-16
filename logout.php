<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    AuthManager::logout();

    $redirectTarget = 'login.html';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Location: ' . $redirectTarget);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Erfolgreich abgemeldet',
        'redirect' => $redirectTarget,
    ]);
} catch (Exception $e) {
    error_log('Logout error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Logout-Fehler',
    ]);
}
?>
