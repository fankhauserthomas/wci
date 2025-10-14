<?php
// config.php – Zentrale Konfigurationsdatei für das Timeline-System

// ==================== HÜTTEN-SPEZIFISCHE KONFIGURATION ====================
// Diese Werte müssen für jede Hütte angepasst werden

// Hütten-Identifikation
define('HUT_ID', 675);                    // HRS Hütten-ID (Franzsennhütte)
define('HUT_NAME', 'Franzsennhütte');    // Hüttenname für Anzeige
define('HUT_SHORT', 'FSH');               // Kurzkürzel der Hütte

// ==================== DATENBANK-KONFIGURATION ====================

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

// ==================== SERVER/URL KONFIGURATION ====================

// Basis-URLs für das System
define('BASE_URL', 'http://192.168.15.14:8080');          // Basis-URL des Systems
define('WCI_PATH', '/wci');                                 // WCI-Pfad relativ zur Basis-URL
define('API_BASE_URL', BASE_URL . WCI_PATH);               // Vollständige API-Basis-URL

// Spezifische Pfade
define('ZP_PATH', WCI_PATH . '/zp');                       // Timeline-Pfad
define('RESERVATIONS_PATH', WCI_PATH . '/reservierungen'); // Reservierungen-Pfad
define('PIC_PATH', WCI_PATH . '/pic');                     // Bilder-Pfad

// ==================== HRS (Hut Reservation System) KONFIGURATION ====================

// HRS Login-Daten
define('HRS_BASE_URL', 'https://www.hut-reservation.org');
define('HRS_USERNAME', 'office@franzsennhuette.at');       // HRS Benutzername
define('HRS_PASSWORD', 'Fsh2147m!3');                      // HRS Passwort

// HRS API-Endpunkte
define('HRS_API_BASE', '/api/v1');
define('HRS_LOGIN_ENDPOINT', HRS_API_BASE . '/users/login');
define('HRS_QUOTA_ENDPOINT', HRS_API_BASE . '/manage/hutQuota');
define('HRS_RESERVATION_ENDPOINT', HRS_API_BASE . '/manage/reservation');

// ==================== TIMELINE-SPEZIFISCHE KONFIGURATION ====================

// Zimmerplan-Einstellungen
define('DEFAULT_ROOM_HEIGHT', 40);        // Standard-Zimmerhöhe in Pixeln
define('DEFAULT_DAY_WIDTH', 60);          // Standard-Tagesbreite in Pixeln
define('MASTER_BAR_HEIGHT', 14);          // Höhe der Master-Balken

// Verfügbarkeits-Einstellungen
define('MAX_OCCUPANCY_DAYS', 365);        // Maximale Tage für Belegungsabfragen
define('DEFAULT_TIMELINE_DAYS', 30);      // Standard-Anzeigedauer Timeline

// ==================== DATENBANK-VERBINDUNG ====================

// Verbindung herstellen (lokal)
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'DB-Verbindung fehlgeschlagen']));
}
$mysqli->set_charset('utf8mb4');

// Make main connection available globally
$GLOBALS['mysqli'] = $mysqli;

// ==================== SYNC-KONFIGURATION ====================

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