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
define('FALLBACK_BASE_URL', 'http://192.168.15.14:8080'); // Fallback-URL für JavaScript wenn Config nicht verfügbar
define('WCI_PATH', '/wci');                                 // WCI-Pfad relativ zur Basis-URL
define('API_BASE_URL', BASE_URL . WCI_PATH);               // Vollständige API-Basis-URL

// Spezifische Pfade (relativ)
define('ZP_PATH', WCI_PATH . '/zp');                       // Timeline-Pfad
define('RESERVATIONS_PATH', WCI_PATH . '/reservierungen'); // Reservierungen-Pfad
define('PIC_PATH', WCI_PATH . '/pic');                     // Bilder-Pfad
define('HRS_PATH', WCI_PATH . '/hrs');                     // HRS-Pfad
define('API_PATH', WCI_PATH . '/api');                     // API-Pfad

// Vollständige URLs für absolute Referenzen
define('FULL_ZP_URL', BASE_URL . ZP_PATH);                 // Vollständige Timeline-URL
define('FULL_RESERVATIONS_URL', BASE_URL . RESERVATIONS_PATH); // Vollständige Reservierungen-URL
define('FULL_PIC_URL', BASE_URL . PIC_PATH);               // Vollständige Bilder-URL
define('FULL_HRS_URL', BASE_URL . HRS_PATH);               // Vollständige HRS-URL
define('FULL_API_URL', BASE_URL . API_PATH);               // Vollständige API-URL

// API-Endpunkt-Konfiguration (für JavaScript)
define('TIMELINE_ENDPOINTS', [
    // Zimmerplan/Timeline Endpunkte
    'updateRoomDetail' => ZP_PATH . '/updateRoomDetail.php',
    'updateRoomDetailAttributes' => ZP_PATH . '/updateRoomDetailAttributes.php',
    'updateReservationMasterData' => ZP_PATH . '/updateReservationMasterData.php',
    'getReservationMasterData' => ZP_PATH . '/getReservationMasterData.php',
    'assignRoomsToReservation' => ZP_PATH . '/assignRoomsToReservation.php',
    'getArrangements' => ZP_PATH . '/getArrangements.php',
    'getOrigins' => ZP_PATH . '/getOrigins.php',
    'quotaInputModal' => ZP_PATH . '/quota-input-modal.html',
    'getArrangementsRoot' => WCI_PATH . '/get-arrangements.php',
    
    // Reservierungen API Endpunkte
    'splitReservationDetail' => RESERVATIONS_PATH . '/api/splitReservationDetail.php',
    'splitReservationByDate' => RESERVATIONS_PATH . '/api/splitReservationByDate.php',
    'deleteReservationDetail' => RESERVATIONS_PATH . '/api/deleteReservationDetail.php',
    'deleteReservationAllDetails' => RESERVATIONS_PATH . '/api/deleteReservationAllDetails.php',
    'updateReservationDesignation' => RESERVATIONS_PATH . '/api/updateReservationDesignation.php',
    
    // HRS Import Endpunkte
    'hrsImportDaily' => HRS_PATH . '/hrs_imp_daily_stream.php',
    'hrsImportQuota' => HRS_PATH . '/hrs_imp_quota_stream.php',
    'hrsImportReservations' => HRS_PATH . '/hrs_imp_res_stream.php',
    'hrsWriteQuota' => HRS_PATH . '/hrs_write_quota_v3.php',
    
    // AV (AlpenVerein) API Endpunkte
    'avCapacityRange' => API_PATH . '/imps/get_av_cap_range_stream.php',
    
    // Bilder/Assets
    'cautionIcon' => PIC_PATH . '/caution.svg',
    'dogIcon' => PIC_PATH . '/DogProfile.svg',
    'dogProfile' => PIC_PATH . '/dog.svg',
    'leaveIcon' => PIC_PATH . '/leave.png',
    'dogPng' => PIC_PATH . '/DogProfile.png'
]);

// JSON-enkodierte Endpunkte für JavaScript-Export
define('TIMELINE_ENDPOINTS_JSON', json_encode(TIMELINE_ENDPOINTS));

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