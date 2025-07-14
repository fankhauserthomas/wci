<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Nur POST-Requests erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // JSON-Input lesen
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Passwort erforderlich'
        ]);
        exit;
    }
    
    $password = trim($input['password']);
    
    // Passwort validieren
    if (empty($password)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Bitte geben Sie ein Passwort ein'
        ]);
        exit;
    }
    
    // Authentifizierung versuchen
    if (AuthManager::authenticate($password)) {
        echo json_encode([
            'success' => true,
            'message' => 'Erfolgreich angemeldet',
            'session_info' => AuthManager::getSessionInfo()
        ]);
    } else {
        // Kurze Verzögerung bei falschen Passwörtern (Brute-Force-Schutz)
        sleep(1);
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Falsches Passwort'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Authentication error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ein Server-Fehler ist aufgetreten'
    ]);
}
?>
