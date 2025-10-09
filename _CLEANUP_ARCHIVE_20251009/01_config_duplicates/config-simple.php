<?php
// config-simple.php - Einfache Datenbankverbindung ohne Auth
// $dbHost = 'booking.franzsennhuette.at';
// $dbUser = 'booking_franzsen';
// $dbPass = '~2Y@76';
// $dbName = 'booking_franzsen';

$dbHost = '192.168.15.14';
$dbUser = 'root';
$dbPass = 'Fsh2147m!1';
$dbName = 'booking_franzsen';

try {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    
    if ($mysqli->connect_error) {
        http_response_code(500);
        die(json_encode(array('error' => 'DB-Verbindung fehlgeschlagen')));
    }
    
    $mysqli->set_charset('utf8mb4');
    
    // Erfolg-Kommentar für Debug
    // echo "<!-- DB-Verbindung erfolgreich -->";
    
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(array('error' => 'Datenbankfehler')));
}

define('API_PWD', '31011972');
?>
