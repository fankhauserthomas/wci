<?php
// config-safe.php - Sichere Datenbank-Konfiguration ohne Auth-Check für HTML-Seiten
// Diese Datei wird nur von den API-Endpoints verwendet, die eine Authentifizierung benötigen

// Datenbankzugangsdaten
$dbHost = 'booking.franzsennhuette.at';
$dbUser = 'booking_franzsen';
$dbPass = '~2Y@76';
$dbName = 'booking_franzsen';

// Sichere Verbindung herstellen
try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    
    if ($mysqli->connect_error) {
        error_log("Database connection failed: " . $mysqli->connect_error);
        http_response_code(500);
        die(json_encode([
            'error' => 'Datenbankverbindung fehlgeschlagen',
            'message' => 'Bitte kontaktieren Sie den Administrator.'
        ]));
    }
    
    $mysqli->set_charset('utf8mb4');
    
} catch (Exception $e) {
    error_log("Database connection exception: " . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'error' => 'Datenbankfehler',
        'message' => 'Ein unerwarteter Fehler ist aufgetreten.'
    ]));
}

define('API_PWD', '31011972');
?>
