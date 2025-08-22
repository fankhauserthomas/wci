<?php
/**
 * HRS Import Reservations - CLI Version
 * Exakte Kopie der Login-Logik von hrs_login_debug.php ohne Browser-Ausgabe
 * Import nach AV-Res-webImp mit korrekter Field-Mapping (reservationNumber als av_id)
 * 
 * Usage: php hrs_imp_res_fixed.php 20.08.2025 31.08.2025
 */

// CLI Parameter verarbeiten
$dateFrom = isset($argv[1]) ? $argv[1] : null;
$dateTo = isset($argv[2]) ? $argv[2] : null;

if (!$dateFrom || !$dateTo) {
    echo "Usage: php hrs_imp_res_fixed.php <dateFrom> <dateTo>\n";
    echo "Example: php hrs_imp_res_fixed.php 20.08.2025 31.08.2025\n";
    exit(1);
}

// Datenbankverbindung
require_once 'config.php';

class HRSImportReservations {
    private $baseUrl = 'https://www.hut-reservation.org';
    private $defaultHeaders;
    private $csrfToken;
    private $cookies = array();
    private $debugOutput = array();
    private $mysqli;
    
    // Exakt gleiche Zugangsdaten wie in hrs_login_debug.php
    private $username = 'office@franzsennhuette.at';
    private $password = 'Fsh2147m!3';
    
    public function __construct() {
        $this->mysqli = $GLOBALS['mysqli'];
        
        // Exakt gleiche Headers wie in hrs_login_debug.php
        $this->defaultHeaders = array(
            'Accept: application/json, text/plain, */*',
            'Accept-Encoding: gzip, deflate, br, zstd',
            'Accept-Language: de-DE,de;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Referer: https://www.hut-reservation.org',
            'Sec-Ch-Ua: "Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
        );
        
        echo "✔ HRSImportReservations initialisiert\n";
    }
    
    private function debug($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $this->debugOutput[] = "[$timestamp] $message";
        echo "[$timestamp] $message\n";
    }
    
    private function debugError($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $this->debugOutput[] = "[$timestamp] ❌ ERROR: $message";
        echo "[$timestamp] ❌ ERROR: $message\n";
    }
    
    private function debugSuccess($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $this->debugOutput[] = "[$timestamp] ✅ SUCCESS: $message";
        echo "[$timestamp] ✅ SUCCESS: $message\n";
    }
    
    // Exakte Kopie der makeRequest-Methode aus hrs_login_debug.php
    private function makeRequest($url, $method = 'GET', $data = null, $customHeaders = array()) {
        $fullUrl = $this->baseUrl . $url;
        $this->debug("→ $method $fullUrl");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        
        // Headers zusammenbauen
        $headers = $this->defaultHeaders;
        if (!empty($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }
        
        // Cookie-Header hinzufügen wenn vorhanden
        if (!empty($this->cookies)) {
            $cookieString = '';
            foreach ($this->cookies as $name => $value) {
                $cookieString .= "$name=$value; ";
            }
            $cookieHeader = 'Cookie: ' . rtrim($cookieString, '; ');
            $headers[] = $cookieHeader;
            $this->debug("🍪 Sende Cookies: " . substr($cookieHeader, 0, 100) . "...");
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // HTTP Method setzen
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        if (curl_error($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->debugError("cURL Error: $error");
            return false;
        }
        
        curl_close($ch);
        
        // Header und Body trennen
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        $this->debug("← HTTP $httpCode (Body: " . strlen($body) . " bytes)");
        
        // Cookies aus Response extrahieren
        $this->extractCookies($headerString);
        
        return array(
            'status' => $httpCode,
            'headers' => $headerString,
            'body' => $body
        );
    }
    
    // Exakte Kopie der extractCookies-Methode aus hrs_login_debug.php
    private function extractCookies($headers) {
        $lines = explode("\n", $headers);
        foreach ($lines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookie = trim(substr($line, 11));
                $parts = explode(';', $cookie);
                $nameValue = explode('=', $parts[0], 2);
                if (count($nameValue) == 2) {
                    $this->cookies[trim($nameValue[0])] = trim($nameValue[1]);
                    $this->debug("🍪 Cookie gesetzt: " . trim($nameValue[0]) . " = " . substr(trim($nameValue[1]), 0, 20) . "...");
                }
            }
        }
    }
    
    // Exakte Kopie der initializeAsync-Methode aus hrs_login_debug.php
    public function initializeAsync() {
        $this->debug("=== InitializeAsync START ===");
        
        // Navigation zur Login-Seite
        $response = $this->makeRequest('/login');
        if (!$response || $response['status'] != 200) {
            $this->debugError("Login-Seite konnte nicht geladen werden");
            return false;
        }
        
        $this->debugSuccess("Login-Seite erfolgreich geladen");
        
        // CSRF-Token holen
        $csrfResponse = $this->makeRequest('/api/v1/csrf');
        if (!$csrfResponse || $csrfResponse['status'] != 200) {
            $this->debugError("CSRF-Token konnte nicht abgerufen werden");
            return false;
        }
        
        $csrfData = json_decode($csrfResponse['body'], true);
        if (!isset($csrfData['token'])) {
            $this->debugError("CSRF-Token nicht in Response gefunden");
            return false;
        }
        
        $this->csrfToken = $csrfData['token'];
        $this->debugSuccess("CSRF-Token erhalten: " . substr($this->csrfToken, 0, 20) . "...");
        
        $this->debug("=== InitializeAsync COMPLETE ===");
        return true;
    }
    
    // Exakte Kopie der loginAsync-Methode aus hrs_login_debug.php
    public function loginAsync() {
        $this->debug("=== LoginAsync START ===");
        $this->debug("Username: " . $this->username);
        $this->debug("Password: " . str_repeat('*', strlen($this->password)));
        
        // CSRF-Token aus Cookie verwenden (wie im VB.NET Code)
        $cookieCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $this->csrfToken;
        $this->debug("🔐 Verwende CSRF-Token: " . substr($cookieCsrfToken, 0, 20) . "... (aus " . (isset($this->cookies['XSRF-TOKEN']) ? 'Cookie' : 'API') . ")");
        
        // Schritt 1: verifyEmail
        $this->debug("→ Schritt 1: verifyEmail");
        $verifyData = json_encode(array(
            'userEmail' => $this->username,
            'isLogin' => true
        ));
        
        $verifyHeaders = array(
            'Content-Type: application/json',
            'Origin: https://www.hut-reservation.org',
            'X-XSRF-TOKEN: ' . $cookieCsrfToken
        );
        
        $this->debug("📤 Sending verifyEmail with headers:");
        foreach ($verifyHeaders as $header) {
            $this->debug("   " . $header);
        }
        $this->debug("📤 POST Body: " . $verifyData);
        
        $verifyResponse = $this->makeRequest('/api/v1/users/verifyEmail', 'POST', $verifyData, $verifyHeaders);
        
        if (!$verifyResponse || $verifyResponse['status'] != 200) {
            $this->debugError("verifyEmail fehlgeschlagen - Status: " . ($verifyResponse['status'] ?? 'unknown'));
            $this->debugError("Response Headers: " . substr($verifyResponse['headers'] ?? '', 0, 500));
            $this->debugError("Response Body: " . substr($verifyResponse['body'] ?? '', 0, 500));
            
            // Debug: Alle aktuellen Cookies anzeigen
            $this->debug("🍪 Aktuelle Cookies beim Fehler:");
            foreach ($this->cookies as $name => $value) {
                $this->debug("   $name = " . substr($value, 0, 30) . "...");
            }
            
            return false;
        }
        
        $this->debugSuccess("verifyEmail erfolgreich");
        $this->debug("verifyEmail Response: " . substr($verifyResponse['body'], 0, 200) . "...");
        
        // CSRF-Token aus Cookie aktualisieren (wichtig!)
        $updatedCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $cookieCsrfToken;
        $this->debug("🔄 CSRF-Token nach verifyEmail: " . substr($updatedCsrfToken, 0, 20) . "...");
        
        // Schritt 2: login
        $this->debug("→ Schritt 2: login");
        $loginData = 'username=' . urlencode($this->username) . '&password=' . urlencode($this->password);
        
        $loginHeaders = array(
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.hut-reservation.org',
            'X-XSRF-TOKEN: ' . $updatedCsrfToken
        );
        
        $this->debug("📤 Sending login with headers:");
        foreach ($loginHeaders as $header) {
            $this->debug("   " . $header);
        }
        $this->debug("📤 POST Body: username=" . $this->username . "&password=" . str_repeat('*', strlen($this->password)));
        
        $loginResponse = $this->makeRequest('/api/v1/users/login', 'POST', $loginData, $loginHeaders);
        
        if (!$loginResponse || $loginResponse['status'] != 200) {
            $this->debugError("Login fehlgeschlagen - Status: " . ($loginResponse['status'] ?? 'unknown'));
            $this->debugError("Response Headers: " . substr($loginResponse['headers'] ?? '', 0, 500));
            $this->debugError("Response Body: " . substr($loginResponse['body'] ?? '', 0, 500));
            
            // Debug: Alle aktuellen Cookies anzeigen
            $this->debug("🍪 Aktuelle Cookies beim Login-Fehler:");
            foreach ($this->cookies as $name => $value) {
                $this->debug("   $name = " . substr($value, 0, 30) . "...");
            }
            
            return false;
        }
        
        $this->debugSuccess("Login erfolgreich!");
        $this->debug("Response Body: " . substr($loginResponse['body'], 0, 200) . "...");
        
        // Finale Cookie-Status
        $this->debug("🍪 Cookies nach erfolgreichem Login:");
        foreach ($this->cookies as $name => $value) {
            $this->debug("   $name = " . substr($value, 0, 30) . "...");
        }
        
        // CSRF-Token für weitere API-Calls aktualisieren
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $this->csrfToken = $this->cookies['XSRF-TOKEN'];
            $this->debug("🔄 CSRF-Token für API-Calls gesetzt: " . substr($this->csrfToken, 0, 20) . "...");
        }
        
        $this->debug("=== LoginAsync COMPLETE ===");
        return true;
    }
    
    // Exakte Kopie der getReservationListAsync-Methode aus hrs_login_debug.php
    public function getReservationListAsync($hutId, $researchFilter = '', $dateFrom = '01.08.2024', $dateTo = '01.09.2025', $page = 0, $size = 100) {
        $this->debug("=== GetReservationListAsync START ===");
        $this->debug("HutId: $hutId, Filter: '$researchFilter', DateFrom: $dateFrom, DateTo: $dateTo");
        
        // URL Parameter aufbauen
        $params = array(
            'hutId' => $hutId,
            'researchFilter' => $researchFilter,
            'status' => 'ALL',
            'hasComment' => 'false',
            'isProfessional' => 'false',
            'hasMountainGuides' => 'false',
            'isDoubleBooking' => 'false',
            'hasUnknownCategory' => 'false',
            'hasNoBedsAssigned' => 'false',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'page' => $page,
            'size' => $size,
            'sortList' => 'SubmissionTs',
            'sortOrder' => 'DESC'
        );
        
        $url = '/api/v1/manage/reservation/list?' . http_build_query($params);
        
        $headers = array(
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
            'X-XSRF-TOKEN: ' . $this->csrfToken
        );
        
        $this->debug("📡 API Call: $url");
        $response = $this->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            $this->debugError("GetReservationList fehlgeschlagen - Status: " . ($response['status'] ?? 'unknown'));
            $this->debugError("Response Body: " . substr($response['body'] ?? '', 0, 500));
            return false;
        }
        
        $this->debugSuccess("ReservationList erfolgreich abgerufen");
        $this->debug("Response: " . strlen($response['body']) . " Zeichen erhalten");
        
        $this->debug("=== GetReservationListAsync COMPLETE ===");
        return $response['body'];
    }
    
    // Import-Logik in AV-Res-webImp (korrigiert)
    public function importReservationsToDatabase($reservations) {
        $this->debug("=== Database Import START ===");
        
        if (empty($reservations)) {
            $this->debugError("Keine Reservierungen zum Importieren");
            return false;
        }
        
        // Lösche alte webImp Einträge für den Zeitraum
        $this->debug("Lösche alte AV-Res-webImp Einträge...");
        $deleteQuery = "DELETE FROM `AV-Res-webImp`";
        if (!$this->mysqli->query($deleteQuery)) {
            $this->debugError("Fehler beim Löschen alter Einträge: " . $this->mysqli->error);
            return false;
        }
        $this->debugSuccess("Alte Einträge gelöscht");
        
        $importCount = 0;
        
        foreach ($reservations as $reservation) {
            // WICHTIG: reservationNumber als av_id verwenden (nicht id!)
            $av_id = $reservation['reservationNumber'] ?? '';
            $reservationId = $reservation['id'] ?? '';
            $guestName = ($reservation['firstName'] ?? '') . ' ' . ($reservation['lastName'] ?? '');
            $guestName = trim($guestName);
            
            // Datumskonvertierung
            $checkinDate = isset($reservation['checkinDate']) ? date('Y-m-d', strtotime($reservation['checkinDate'])) : null;
            $checkoutDate = isset($reservation['checkoutDate']) ? date('Y-m-d', strtotime($reservation['checkoutDate'])) : null;
            
            // Nur Reservierungen mit gültigen Daten importieren
            if (empty($av_id) || empty($guestName) || !$checkinDate || !$checkoutDate) {
                $this->debug("Überspringe ungültige Reservierung: av_id=$av_id, name=$guestName");
                continue;
            }
            
            // Insert in AV-Res-webImp
            $insertQuery = "INSERT INTO `AV-Res-webImp` (
                `av_id`, 
                `Gas_Name`, 
                `Ank_Dat`, 
                `Abr_Dat`,
                `Anz_Pers`,
                `status`,
                `hrs_reservation_id`,
                `import_timestamp`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->mysqli->prepare($insertQuery);
            if (!$stmt) {
                $this->debugError("Prepare failed: " . $this->mysqli->error);
                continue;
            }
            
            $personCount = $reservation['personCount'] ?? 1;
            $status = $reservation['status'] ?? 'UNKNOWN';
            
            $stmt->bind_param('ssssiss', 
                $av_id,
                $guestName,
                $checkinDate,
                $checkoutDate,
                $personCount,
                $status,
                $reservationId
            );
            
            if ($stmt->execute()) {
                $importCount++;
                $this->debug("✓ Importiert: $av_id - $guestName ($checkinDate bis $checkoutDate)");
            } else {
                $this->debugError("Fehler beim Import von $av_id: " . $stmt->error);
            }
            
            $stmt->close();
        }
        
        $this->debugSuccess("Database Import COMPLETE: $importCount Reservierungen importiert");
        return $importCount;
    }
    
    // Hauptimport-Funktion
    public function importReservations($dateFrom, $dateTo) {
        echo "🚀 Starte HRS Reservierung Import...\n";
        echo "Zeitraum: $dateFrom bis $dateTo\n\n";
        
        // 1. Initialize (Login-Seite und CSRF-Token)
        if (!$this->initializeAsync()) {
            $this->debugError("Initialization fehlgeschlagen");
            return false;
        }
        
        // 2. Login durchführen
        if (!$this->loginAsync()) {
            $this->debugError("Login fehlgeschlagen");
            return false;
        }
        
        // 3. Reservierungen abrufen (HutId 675 = Franz Senn Hütte)
        $hutId = 675;
        $reservationData = $this->getReservationListAsync($hutId, '', $dateFrom, $dateTo, 0, 1000);
        
        if (!$reservationData) {
            $this->debugError("Fehler beim Abrufen der Reservierungen");
            return false;
        }
        
        // 4. JSON parsen
        $data = json_decode($reservationData, true);
        if (!$data) {
            $this->debugError("JSON Decode Fehler: " . json_last_error_msg());
            $this->debug("Response Start: " . substr($reservationData, 0, 200));
            return false;
        }
        
        if (!isset($data['_embedded']) || !isset($data['_embedded']['reservationList'])) {
            $this->debugError("'_embedded.reservationList' Feld nicht gefunden in API Response");
            $this->debug("Verfügbare Felder: " . implode(', ', array_keys($data)));
            if (isset($data['_embedded'])) {
                $this->debug("_embedded Felder: " . implode(', ', array_keys($data['_embedded'])));
            }
            return false;
        }
        
        $reservations = $data['_embedded']['reservationList'];
        $this->debugSuccess("API lieferte " . count($reservations) . " Reservierungen");
        
        // 5. In Datenbank importieren
        $importCount = $this->importReservationsToDatabase($reservations);
        
        if ($importCount !== false) {
            echo "\n✅ IMPORT ERFOLGREICH ABGESCHLOSSEN\n";
            echo "📊 $importCount Reservierungen in AV-Res-webImp importiert\n";
            echo "🗓️ Zeitraum: $dateFrom bis $dateTo\n";
            return true;
        } else {
            echo "\n❌ IMPORT FEHLGESCHLAGEN\n";
            return false;
        }
    }
}

// Script ausführen
try {
    $importer = new HRSImportReservations();
    $success = $importer->importReservations($dateFrom, $dateTo);
    exit($success ? 0 : 1);
} catch (Exception $e) {
    echo "❌ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
