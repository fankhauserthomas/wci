<?php
// config.php – hier die Zugangsdaten zur MySQL-Datenbank

// Lokale DB (primär)
$GLOBALS['dbHost'] = '192.168.15.14';
$GLOBALS['dbUser'] = 'root';
$GLOBALS['dbPass'] = 'Fsh2147m!1';
$GLOBALS['dbName'] = 'booking_franzsen';

$dbHost = $GLOBALS['dbHost'];
$dbUser = $GLOBALS['dbUser'];
$dbPass = $GLOBALS['dbPass'];
$dbName = $GLOBALS['dbName'];

// Remote DB Konfiguration (für Sync)
$remoteDbHost = 'booking.franzsennhuette.at';
$remoteDbUser = 'booking_franzsen';
$remoteDbPass = '~2Y@76';
$remoteDbName = 'booking_franzsen';

// Verbindung herstellen (lokal)
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'DB-Verbindung fehlgeschlagen']));
}
$mysqli->set_charset('utf8mb4');

// Make mysqli available globally
$GLOBALS['mysqli'] = $mysqli;

// Sync-Konfiguration
define('SYNC_ENABLED', true);
define('SYNC_BATCH_SIZE', 50);
define('API_PWD', '31011972');

// Auto-Sync bei kritischen Operationen
function triggerAutoSync($action = 'api_call') {
    if (!defined('SYNC_ENABLED') || !SYNC_ENABLED) return;
    
    // Non-blocking sync trigger
    $cmd = "php " . __DIR__ . "/syncTrigger.php?action=$action > /dev/null 2>&1 &";
    exec($cmd);
}