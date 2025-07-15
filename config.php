<?php
// config.php – hier die Zugangsdaten zur MySQL-Datenbank
// $dbHost = 'booking.franzsennhuette.at';
// $dbUser = 'booking_franzsen';
// $dbPass = '~2Y@76';
// $dbName = 'booking_franzsen';

$dbHost = '192.168.15.14';
$dbUser = 'root';
$dbPass = 'Fsh2147m!1';
$dbName = 'booking_franzsen';

// Verbindung herstellen
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'DB-Verbindung fehlgeschlagen']));
}
$mysqli->set_charset('utf8mb4');
define('API_PWD', '31011972');