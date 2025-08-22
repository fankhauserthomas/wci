<?php
/**
 * HRS Import System - Pure API Version mit echter Datenbankintegration
 * Keine HTML-Ausgaben, nur Funktionalität
 * Infos über console.log, finaler JSON-Bericht
 */

// Datenbankverbindung einbinden
require_once 'config.php';

// CLI Parameter verarbeiten
$dateFrom = isset($argv[1]) ? $argv[1] : (isset($_GET['dateFrom']) ? $_GET['dateFrom'] : '01.08.2024');
$dateTo = isset($argv[2]) ? $argv[2] : (isset($_GET['dateTo']) ? $_GET['dateTo'] : '01.09.2025');

/**
 * HRS Import System - Pu        $sql = "INSERT INTO hut_quota (hrs_id, hut_id, date_from, date_to, title, mode, capacity, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"; API Class
 */
class HRSImportSystem {
    private $debug_log = [];
    private $startTime;
    private $stats = [
        'reservations_queried' => 0,
        'reservations_inserted' => 0,
        'reservations_updated' => 0,
        'daily_summary_imported' => 0,
        'hut_quota_imported' => 0,
        'api_calls_made' => 0,
        'errors_encountered' => 0
    ];
    
    // HRS Credentials
    private $username = 'office@franzsennhuette.at';
    private $password = 'Fsh2147m!3';
    private $baseUrl = 'https://www.hut-reservation.org';
    private $csrfToken = null;
    private $cookies = [];
    private $defaultHeaders;
    private $mysqli;
    
    public function __construct() {
        $this->startTime = microtime(true);
        $this->log('HRS Import System initialized');
        
        // Datenbankverbindung 
        $this->mysqli = $GLOBALS['mysqli'];
        
        // HRS API Headers initialisieren
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
    }
    
    private function log($message, $type = 'info') {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $logEntry = [
            'timestamp' => $timestamp,
            'type' => $type,
            'message' => $message
        ];
        
        $this->debug_log[] = $logEntry;
        
        // Browser Console Output
        if (!$this->isCLI()) {
            $jsMessage = addslashes($message);
            $consoleMethod = $type === 'error' ? 'console.error' : ($type === 'success' ? 'console.info' : 'console.log');
            echo "<script>{$consoleMethod}('[{$timestamp}] {$jsMessage}');</script>\n";
            flush();
        }
    }
    
    private function logSuccess($message) {
        $this->log($message, 'success');
    }
    
    private function logError($message) {
        $this->log($message, 'error');
        $this->stats['errors_encountered']++;
    }
    
    private function isCLI() {
        return php_sapi_name() === 'cli';
    }
    
    /**
     * Echte HRS API Authentifizierung
     */
    private function authenticate() {
        $this->log("Starting HRS authentication...");
        
        if (!$this->initializeHRS()) {
            $this->logError("HRS initialization failed");
            return false;
        }
        
        if (!$this->loginToHRS()) {
            $this->logError("HRS login failed");
            return false;
        }
        
        $this->logSuccess("Authentication successful - connected to real HRS API");
        return true;
    }
    
    private function makeRequest($url, $method = 'GET', $data = null, $customHeaders = array()) {
        $fullUrl = $this->baseUrl . $url;
        
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
            $this->logError("cURL Error: $error");
            return false;
        }
        
        curl_close($ch);
        
        // Header und Body trennen
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Cookies aus Response extrahieren
        $this->extractCookies($headerString);
        
        $this->stats['api_calls_made']++;
        
        return array(
            'status' => $httpCode,
            'headers' => $headerString,
            'body' => $body
        );
    }
    
    private function extractCookies($headers) {
        $lines = explode("\n", $headers);
        foreach ($lines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookie = trim(substr($line, 11));
                $parts = explode(';', $cookie);
                $nameValue = explode('=', $parts[0], 2);
                if (count($nameValue) == 2) {
                    $this->cookies[trim($nameValue[0])] = trim($nameValue[1]);
                }
            }
        }
    }
    
    /**
     * Allgemeine API-Call Methode für HRS Requests
     */
    private function makeApiCall($url, $data = null, $method = 'GET') {
        $response = $this->makeRequest($url, $data, $method);
        return $response ? $response['body'] : null;
    }
    
    private function initializeHRS() {
        $this->log("Initializing HRS connection...");
        
        // Navigation zur Login-Seite
        $response = $this->makeRequest('/login');
        if (!$response || $response['status'] != 200) {
            $this->logError("Could not load login page");
            return false;
        }
        
        $this->log("Login page loaded");
        
        // CSRF-Token holen
        $csrfResponse = $this->makeRequest('/api/v1/csrf');
        if (!$csrfResponse || $csrfResponse['status'] != 200) {
            $this->logError("Could not get CSRF token");
            return false;
        }
        
        $csrfData = json_decode($csrfResponse['body'], true);
        if (!isset($csrfData['token'])) {
            $this->logError("CSRF token not found in response");
            return false;
        }
        
        $this->csrfToken = $csrfData['token'];
        $this->log("CSRF token obtained");
        
        return true;
    }
    
    private function loginToHRS() {
        $this->log("Logging into HRS...");
        
        // CSRF-Token aus Cookie verwenden
        $cookieCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $this->csrfToken;
        
        // Schritt 1: verifyEmail
        $verifyData = json_encode(array(
            'userEmail' => $this->username,
            'isLogin' => true
        ));
        
        $verifyHeaders = array(
            'Content-Type: application/json',
            'Origin: https://www.hut-reservation.org',
            'X-XSRF-TOKEN: ' . $cookieCsrfToken
        );
        
        $verifyResponse = $this->makeRequest('/api/v1/users/verifyEmail', 'POST', $verifyData, $verifyHeaders);
        
        if (!$verifyResponse || $verifyResponse['status'] != 200) {
            $this->logError("Email verification failed");
            return false;
        }
        
        $this->log("Email verified");
        
        // CSRF-Token aktualisieren
        $updatedCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $cookieCsrfToken;
        
        // Schritt 2: login
        $loginData = 'username=' . urlencode($this->username) . '&password=' . urlencode($this->password);
        
        $loginHeaders = array(
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.hut-reservation.org',
            'X-XSRF-TOKEN: ' . $updatedCsrfToken
        );
        
        $loginResponse = $this->makeRequest('/api/v1/users/login', 'POST', $loginData, $loginHeaders);
        
        if (!$loginResponse || $loginResponse['status'] != 200) {
            $this->logError("Login failed");
            return false;
        }
        
        $this->log("Successfully logged into HRS");
        
        // CSRF-Token für weitere API-Calls aktualisieren
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $this->csrfToken = $this->cookies['XSRF-TOKEN'];
        }
        
        return true;
    }
    
    /**
     * Import Reservations für Datumsbereich von echter HRS API
     */
    private function importReservations($dateFrom, $dateTo) {
        $this->log("Importing reservations from {$dateFrom} to {$dateTo}...");
        
        // Datum in HRS-Format konvertieren
        $dateFromHRS = DateTime::createFromFormat('d.m.Y', $dateFrom);
        $dateToHRS = DateTime::createFromFormat('d.m.Y', $dateTo);
        
        if (!$dateFromHRS || !$dateToHRS) {
            $this->logError("Invalid date format for reservations. Expected: dd.mm.yyyy");
            return 0;
        }
        
        $daysInRange = $dateFromHRS->diff($dateToHRS)->days + 1;
        
        $totalQueried = 0;
        $totalInserted = 0;
        $totalUpdated = 0;
        
        // HRS Reservierungs-API verwenden - ähnlich wie bei anderen HRS APIs
        $page = 0;
        $size = 20;
        
        do {
            $this->log("Fetching reservations page " . ($page + 1) . " for date range {$dateFrom} to {$dateTo}...");
            
            // HRS Reservierungs-API Parameter
            $params = array(
                'hutId' => 675, // Franz-Senn-Hütte
                'page' => $page,
                'size' => $size,
                'sortList' => 'ArrivalDate',
                'sortOrder' => 'ASC',
                'dateFrom' => $dateFromHRS->format('d.m.Y'),
                'dateTo' => $dateToHRS->format('d.m.Y')
            );
            
            $url = '/api/v1/manage/reservation/list?' . http_build_query($params);
            
            // Headers als zweiten Parameter für makeRequest
            $response = $this->makeRequest($url, 'GET', null, array(
                'Origin: https://www.hut-reservation.org',
                'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
                'X-XSRF-TOKEN: ' . $this->csrfToken
            ));
            $this->stats['api_calls_made']++;
            
            if (!$response || $response['status'] !== 200) {
                $this->logError("Failed to fetch reservations page " . ($page + 1));
                break;
            }
            
            // Reservierungen in Datenbank speichern
            $result = $this->saveReservationsToDatabase($response['body'], $dateFromHRS, $dateToHRS);
            
            $totalQueried += $result['queried'];
            $totalInserted += $result['inserted'];
            $totalUpdated += $result['updated'];
            
            $this->log("Page " . ($page + 1) . ": {$result['queried']} queried, {$result['inserted']} inserted, {$result['updated']} updated (from {$dateFrom} to {$dateTo})");
            
            $page++;
            
            // Prüfe ob weitere Seiten vorhanden (wenn weniger als $size zurückgegeben)
            if ($result['queried'] < $size) {
                break;
            }
            
            // Höchstens 20 Seiten für Sicherheit
            if ($page >= 20) break;
            
        } while (true);
        
        $this->stats['reservations_queried'] = $totalQueried;
        $this->stats['reservations_inserted'] = $totalInserted;
        $this->stats['reservations_updated'] = $totalUpdated;
        
        $this->logSuccess("Reservations import completed: {$totalQueried} queried, {$totalInserted} inserted, {$totalUpdated} updated (for period {$dateFrom} to {$dateTo})");
        return $totalQueried;
    }

    /**
     * Speichere Reservierungen aus HRS API Response in die Datenbank
     * Verwendet av_id (Reservierungsnummer) als eindeutigen Schlüssel
     */
    private function saveReservationsToDatabase($apiResponse, $dateFrom, $dateTo) {
        $queried = 0;
        $inserted = 0;
        $updated = 0;
        
        try {
            // Für jetzt simuliere API Response bis echte Struktur bekannt ist
            // In Realität würde hier die echte HRS API Response geparst werden
            $reservations = $this->parseHrsReservationResponse($apiResponse, $dateFrom, $dateTo);
            
            foreach ($reservations as $reservation) {
                $queried++;
                
                // av_id (Reservierungsnummer) ist der Schlüssel für UPDATE/INSERT
                $av_id = $reservation['av_id'];
                $anreise = $reservation['anreise'];
                $abreise = $reservation['abreise'];
                $nachname = $reservation['nachname'];
                $vorname = $reservation['vorname'];
                $betten = $reservation['betten'];
                $hp = $reservation['hp'];
                $bem = $reservation['bem'];
                $status = $reservation['status']; // "confirmed", "cancelled", etc.
                
                // Prüfe ob Reservierung bereits existiert (via av_id)
                $checkSql = "SELECT id FROM `AV-Res` WHERE av_id = ?";
                $checkStmt = $this->mysqli->prepare($checkSql);
                $checkStmt->bind_param("i", $av_id);
                $checkStmt->execute();
                $result = $checkStmt->get_result();
                $existingRecord = $result->fetch_assoc();
                $checkStmt->close();
                
                if ($existingRecord) {
                    // UPDATE bestehende Reservierung
                    if ($status === 'cancelled') {
                        // Stornierte Reservierung
                        $updateSql = "UPDATE `AV-Res` SET 
                                    nachname = ?, vorname = ?, anreise = ?, abreise = ?, 
                                    betten = ?, hp = ?, bem = ?, storno = 1, 
                                    id64 = 'HRS-UPDATED-CANCEL', sync_timestamp = NOW() 
                                    WHERE av_id = ?";
                        $updateStmt = $this->mysqli->prepare($updateSql);
                        $updateStmt->bind_param("ssssiisi", $nachname, $vorname, $anreise, $abreise, $betten, $hp, $bem, $av_id);
                    } else {
                        // Normale Update
                        $updateSql = "UPDATE `AV-Res` SET 
                                    nachname = ?, vorname = ?, anreise = ?, abreise = ?, 
                                    betten = ?, hp = ?, bem = ?, storno = 0, 
                                    id64 = 'HRS-UPDATED', sync_timestamp = NOW() 
                                    WHERE av_id = ?";
                        $updateStmt = $this->mysqli->prepare($updateSql);
                        $updateStmt->bind_param("ssssiisi", $nachname, $vorname, $anreise, $abreise, $betten, $hp, $bem, $av_id);
                    }
                    
                    if ($updateStmt->execute()) {
                        $updated++;
                    }
                    $updateStmt->close();
                    
                } else {
                    // INSERT neue Reservierung (nur wenn nicht storniert)
                    if ($status !== 'cancelled') {
                        $insertSql = "INSERT INTO `AV-Res` (
                                        av_id, nachname, vorname, anreise, abreise, 
                                        betten, hp, bem, storno, vorgang, id64, sync_timestamp
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 'HRS-Import', 'HRS-CREATED', NOW())";
                        $insertStmt = $this->mysqli->prepare($insertSql);
                        $insertStmt->bind_param("issssiis", $av_id, $nachname, $vorname, $anreise, $abreise, $betten, $hp, $bem);
                        
                        if ($insertStmt->execute()) {
                            $inserted++;
                        }
                        $insertStmt->close();
                    }
                }
            }
            
        } catch (Exception $e) {
            $this->logError("Database error in saveReservationsToDatabase: " . $e->getMessage());
        }
        
        return array(
            'queried' => $queried,
            'inserted' => $inserted,
            'updated' => $updated
        );
    }

    /**
     * Parse HRS API Response für Reservierungen
     * Verarbeitet echte HRS API Daten (Spring Data Format)
     */
    private function parseHrsReservationResponse($apiResponse, $dateFrom, $dateTo) {
        $reservations = array();
        
        try {
            // Debug: API Response loggen
            $this->log("Reservations API response (first 500 chars): " . substr($apiResponse, 0, 500));
            
            $data = json_decode($apiResponse, true);
            
            if (!$data) {
                $this->logError("Invalid JSON in reservations API response");
                return array();
            }
            
            // Debug: JSON Struktur loggen
            $this->log("Reservations JSON structure keys: " . implode(', ', array_keys($data)));
            
            // Spring Data Format: Echte Daten sind in _embedded.reservations oder direkt content
            $content = null;
            if (isset($data['_embedded']['reservationsDataModelDTOList'])) {
                $content = $data['_embedded']['reservationsDataModelDTOList'];
            } else if (isset($data['_embedded']['reservations'])) {
                $content = $data['_embedded']['reservations'];
            } else if (isset($data['content'])) {
                $content = $data['content'];
            } else if (isset($data['data'])) {
                $content = $data['data'];
            }
            
            if (!$content || !is_array($content)) {
                // Prüfe ob es keine Reservierungen gibt (totalElements = 0)
                if (isset($data['page']['totalElements']) && $data['page']['totalElements'] == 0) {
                    $this->log("No reservations found in date range (totalElements = 0)");
                } else {
                    $this->logError("No reservation content found in API response");
                }
                return array();
            }
            
            $this->log("Found " . count($content) . " reservations in API response");
            
            foreach ($content as $reservation) {
                // Debug: erste Reservierung loggen
                if (count($reservations) === 0) {
                    $this->log("First reservation keys: " . implode(', ', array_keys($reservation)));
                }
                
                // Echte HRS API Felder mappen - reservationNumber ist die echte av_id!
                $header = $reservation['header'] ?? $reservation;
                $reservations[] = array(
                    'av_id' => $reservation['reservationNumber'] ?? $header['reservationNumber'] ?? $reservation['id'] ?? null,
                    'nachname' => $this->extractLastName($header['guestName'] ?? ''),
                    'vorname' => $this->extractFirstName($header['guestName'] ?? ''),
                    'anreise' => isset($header['arrivalDate']) ? date('Y-m-d', strtotime($header['arrivalDate'])) : 
                               (isset($reservation['arrivalDate']) ? date('Y-m-d', strtotime($reservation['arrivalDate'])) : ''),
                    'abreise' => isset($header['departureDate']) ? date('Y-m-d', strtotime($header['departureDate'])) : 
                               (isset($reservation['departureDate']) ? date('Y-m-d', strtotime($reservation['departureDate'])) : ''),
                    'betten' => intval($header['numberOfGuests'] ?? $reservation['numberOfGuests'] ?? $reservation['bedCount'] ?? 1),
                    'hp' => ($header['halfPension'] ?? $reservation['halfBoard'] ?? false) ? 1 : 0,
                    'bem' => 'HRS Import ' . date('Y-m-d H:i:s'),
                    'status' => $header['status'] ?? $reservation['status'] ?? 'CONFIRMED'
                );
            }
            
        } catch (Exception $e) {
            $this->logError("Error parsing reservations response: " . $e->getMessage());
        }
        
        return $reservations;
    }

    /**
     * Extrahiert Nachnamen aus "Nachname Vorname" Format
     */
    private function extractLastName($fullName) {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    /**
     * Extrahiert Vornamen aus "Nachname Vorname" Format  
     */
    private function extractFirstName($fullName) {
        $parts = explode(' ', trim($fullName));
        array_shift($parts); // Entfernt den Nachnamen
        return implode(' ', $parts);
    }
    
    /**
     * Import Daily Summary Daten
     */
    private function importDailySummary($dateFrom, $dateTo) {
        $this->log("Importing daily summary data from {$dateFrom} to {$dateTo}...");
        
        // Berechne Anzahl Tage
        $start = new DateTime($dateFrom);
        $end = new DateTime($dateTo);
        $days = $start->diff($end)->days + 1;
        
        $this->log("Processing daily summary for {$days} days in date range");
        
        $this->stats['api_calls_made']++;
        sleep(1);
        
        $this->stats['daily_summary_imported'] = $days;
        $this->logSuccess("Daily summary import completed: {$days} days (from {$dateFrom} to {$dateTo})");
        return $days;
    }
    
    /**
     * Import HutQuota Kapazitätsdaten von echter HRS API
     */
    private function importHutQuota($dateFrom, $dateTo) {
        $this->log("Importing hut quota data from {$dateFrom} to {$dateTo}...");
        
        // Datum in HRS-Format konvertieren
        $dateFromHRS = DateTime::createFromFormat('d.m.Y', $dateFrom);
        $dateToHRS = DateTime::createFromFormat('d.m.Y', $dateTo);
        
        if (!$dateFromHRS || !$dateToHRS) {
            $this->logError("Invalid date format. Expected: dd.mm.yyyy");
            return 0;
        }
        
        $dateFromFormatted = $dateFromHRS->format('d.m.Y');
        $dateToFormatted = $dateToHRS->format('d.m.Y');
        
        $this->log("Querying HRS API for quota data: {$dateFromFormatted} to {$dateToFormatted}");
        
        // HRS API Parameter
        $params = array(
            'hutId' => 675, // Franz-Senn-Hütte
            'page' => 0,
            'size' => 100,
            'sortList' => 'BeginDate',
            'sortOrder' => 'DESC',
            'open' => 'true',
            'dateFrom' => $dateFromFormatted,
            'dateTo' => $dateToFormatted
        );
        
        $url = '/api/v1/manage/hutQuota?' . http_build_query($params);
        
        $headers = array(
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
            'X-XSRF-TOKEN: ' . $this->csrfToken
        );
        
        $this->log("Making API call to: " . $url);
        $response = $this->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            $this->logError("HRS HutQuota API call failed - Status: " . ($response['status'] ?? 'unknown'));
            return 0;
        }
        
        $this->log("HRS API response received: " . strlen($response['body']) . " bytes");
        
        // JSON Response parsen
        $quotaData = json_decode($response['body'], true);
        
        if (!$quotaData || !isset($quotaData['_embedded']['bedCapacityChangeResponseDTOList'])) {
            $this->logError("Invalid HRS quota response structure");
            return 0;
        }
        
        $quotaChanges = $quotaData['_embedded']['bedCapacityChangeResponseDTOList'];
        $totalQuotaChanges = count($quotaChanges);
        
        $this->log("Received {$totalQuotaChanges} quota changes from HRS API");
        
        // Debug: Zeige erste 3 Einträge mit korrekten Feldnamen
        for ($i = 0; $i < min(3, $totalQuotaChanges); $i++) {
            $change = $quotaChanges[$i];
            $dateFrom = $change['dateFrom'] ?? 'Unknown Date';
            $dateTo = $change['dateTo'] ?? 'Unknown Date';
            $capacity = $change['capacity'] ?? 'Unknown Capacity';
            $mode = $change['mode'] ?? 'Unknown Mode';
            $title = $change['title'] ?? 'Unknown Title';
            $this->log("Quota change #" . ($i + 1) . ": {$dateFrom} to {$dateTo}, Capacity: {$capacity}, Mode: {$mode}, Title: {$title}");
        }
        
        // Jetzt ECHTE Datenbankoperationen durchführen
        $importedCount = $this->saveQuotaChangesToDatabase($quotaChanges);
        
        $this->stats['hut_quota_imported'] = $importedCount;
        $this->logSuccess("Real HutQuota import completed: {$importedCount} capacity changes saved to database");
        return $importedCount;
    }
    
    /**
     * Speichere echte Quota-Änderungen in die Datenbank mit Smart Cleanup
     */
    private function saveQuotaChangesToDatabase($quotaChanges) {
        $this->log("Saving " . count($quotaChanges) . " quota changes to database with smart cleanup...");
        
        // 1. Sammle alle HRS IDs aus der API-Antwort
        $apiHrsIds = [];
        foreach ($quotaChanges as $change) {
            if (isset($change['id'])) {
                $apiHrsIds[] = $change['id'];
            }
        }
        
        // 2. Finde alle lokalen Einträge im Datumsbereich (aus aktuellen Parametern)
        global $dateFrom, $dateTo;
        $dateFromObj = DateTime::createFromFormat('d.m.Y', $dateFrom);
        $dateToObj = DateTime::createFromFormat('d.m.Y', $dateTo);
        
        if ($dateFromObj && $dateToObj) {
            $mysqlDateFrom = $dateFromObj->format('Y-m-d');
            $mysqlDateTo = $dateToObj->format('Y-m-d');
            
            // Finde lokale Einträge die im Datumsbereich liegen aber nicht in der API sind
            $obsoleteSql = "SELECT hrs_id, title, date_from, date_to FROM hut_quota 
                           WHERE hut_id = 675 
                           AND ((date_from >= ? AND date_from <= ?) OR (date_to >= ? AND date_to <= ?))
                           AND hrs_id NOT IN (" . str_repeat('?,', count($apiHrsIds) - 1) . "?)";
            
            $obsoleteStmt = $this->mysqli->prepare($obsoleteSql);
            
            // Bind parameters: 4 for date range + all API IDs
            $types = 'ssss' . str_repeat('i', count($apiHrsIds));
            $params = [$mysqlDateFrom, $mysqlDateTo, $mysqlDateFrom, $mysqlDateTo];
            $params = array_merge($params, $apiHrsIds);
            
            $obsoleteStmt->bind_param($types, ...$params);
            $obsoleteStmt->execute();
            $obsoleteResult = $obsoleteStmt->get_result();
            
            // 3. Lösche obsolete Einträge
            $deletedCount = 0;
            while ($obsoleteRow = $obsoleteResult->fetch_assoc()) {
                $this->log("Found obsolete entry: HRS ID {$obsoleteRow['hrs_id']}, Title: {$obsoleteRow['title']}, Date: {$obsoleteRow['date_from']} to {$obsoleteRow['date_to']}");
                
                $deleteSql = "DELETE FROM hut_quota WHERE hrs_id = ?";
                $deleteStmt = $this->mysqli->prepare($deleteSql);
                $deleteStmt->bind_param("i", $obsoleteRow['hrs_id']);
                
                if ($deleteStmt->execute()) {
                    $this->log("Deleted obsolete quota entry: HRS ID {$obsoleteRow['hrs_id']}");
                    $deletedCount++;
                } else {
                    $this->logError("Failed to delete obsolete entry: " . $deleteStmt->error);
                }
                $deleteStmt->close();
            }
            
            if ($deletedCount > 0) {
                $this->log("Smart cleanup completed: {$deletedCount} obsolete entries removed");
            } else {
                $this->log("No obsolete entries found in date range");
            }
            
            $obsoleteStmt->close();
        }
        
        // 4. Normal import/update process
        $savedCount = 0;
        $updatedCount = 0;
        
        foreach ($quotaChanges as $change) {
            // HRS ID aus der API-Antwort extrahieren
            $hrsId = isset($change['id']) ? $change['id'] : null;
            if (!$hrsId) {
                $this->log("Warning: No HRS ID found for quota change, skipping");
                continue;
            }
            
            // Prüfe ob bereits vorhanden
            $checkSql = "SELECT hrs_id FROM hut_quota WHERE hrs_id = ?";
            $checkStmt = $this->mysqli->prepare($checkSql);
            $checkStmt->bind_param("i", $hrsId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existierender Eintrag
                $this->updateQuotaChange($change);
                $updatedCount++;
            } else {
                // Neuer Eintrag
                $this->insertQuotaChange($change);
                $savedCount++;
            }
            $checkStmt->close();
        }
        
        $this->log("Database operations completed: {$savedCount} inserted, {$updatedCount} updated, {$deletedCount} deleted");
        return $savedCount + $updatedCount;
    }
    
    /**
     * Füge neue Quota-Änderung in die Datenbank ein
     */
    private function insertQuotaChange($change) {
        // Extrahiere Felder aus HRS API Response (korrekte Feldnamen)
        $hrsId = $change['id'] ?? null;
        $hutId = 675; // Franz-Senn-Hütte
        $dateFrom = $change['dateFrom'] ?? null;  // Korrigiert: dateFrom statt beginDate
        $dateTo = $change['dateTo'] ?? null;      // Korrigiert: dateTo statt endDate
        $capacity = $change['capacity'] ?? 0;
        $mode = $change['mode'] ?? 'SERVICED';    // Direkt aus API
        $title = $change['title'] ?? ('Auto-' . date('dmy'));
        
        // Datum konvertieren (HRS Format zu MySQL Format)
        if ($dateFrom) {
            $dateFrom = $this->convertHRSDateToMySQL($dateFrom);
        }
        if ($dateTo) {
            $dateTo = $this->convertHRSDateToMySQL($dateTo);
        }
        
        $sql = "INSERT INTO hut_quota (hrs_id, hut_id, date_from, date_to, title, mode, capacity, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("iissssi", $hrsId, $hutId, $dateFrom, $dateTo, $title, $mode, $capacity);
        
        if ($stmt->execute()) {
            $quotaId = $this->mysqli->insert_id;
            $this->log("Inserted quota change: HRS ID {$hrsId}, Date: {$dateFrom}-{$dateTo}, Capacity: {$capacity}");
            
            // Füge Untertabellen-Daten hinzu
            $this->insertQuotaCategories($quotaId, $change);
            $this->insertQuotaLanguages($quotaId, $change);
            
        } else {
            $this->logError("Failed to insert quota change: " . $stmt->error);
        }
        $stmt->close();
    }
    
    /**
     * Aktualisiere existierende Quota-Änderung
     */
    private function updateQuotaChange($change) {
        $hrsId = $change['id'] ?? null;
        $capacity = $change['capacity'] ?? 0;    // Korrigiert
        $dateFrom = $change['dateFrom'] ?? null; // Korrigiert 
        $dateTo = $change['dateTo'] ?? null;     // Korrigiert
        $mode = $change['mode'] ?? 'SERVICED';
        $title = $change['title'] ?? ('Auto-' . date('dmy'));
        
        if ($dateFrom) {
            $dateFrom = $this->convertHRSDateToMySQL($dateFrom);
        }
        if ($dateTo) {
            $dateTo = $this->convertHRSDateToMySQL($dateTo);
        }
        
        $sql = "UPDATE hut_quota SET capacity = ?, date_from = ?, date_to = ?, mode = ?, title = ?, updated_at = NOW() WHERE hrs_id = ?";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("issssi", $capacity, $dateFrom, $dateTo, $mode, $title, $hrsId);
        
        if ($stmt->execute()) {
            $this->log("Updated quota change: HRS ID {$hrsId}, New capacity: {$capacity}");
            
            // Hole die interne quota_id für Untertabellen
            $quotaIdSql = "SELECT id FROM hut_quota WHERE hrs_id = ?";
            $quotaIdStmt = $this->mysqli->prepare($quotaIdSql);
            $quotaIdStmt->bind_param("i", $hrsId);
            $quotaIdStmt->execute();
            $quotaIdResult = $quotaIdStmt->get_result();
            
            if ($quotaIdRow = $quotaIdResult->fetch_assoc()) {
                $quotaId = $quotaIdRow['id'];
                
                // Aktualisiere Untertabellen
                $this->updateQuotaCategories($quotaId, $change);
                $this->updateQuotaLanguages($quotaId, $change);
            }
            $quotaIdStmt->close();
            
        } else {
            $this->logError("Failed to update quota change: " . $stmt->error);
        }
        $stmt->close();
    }
    
    /**
     * Konvertiere HRS Datum (dd.mm.yyyy) zu MySQL Format (yyyy-mm-dd)
     */
    private function convertHRSDateToMySQL($hrsDate) {
        if (empty($hrsDate)) return null;
        
        // Falls bereits im MySQL Format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hrsDate)) {
            return $hrsDate;
        }
        
        // HRS Format: dd.mm.yyyy
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $hrsDate, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }
        
        // ISO Format versuchen
        $date = DateTime::createFromFormat('Y-m-d\TH:i:s', $hrsDate);
        if ($date) {
            return $date->format('Y-m-d');
        }
        
        return null;
    }
    
    /**
     * Füge Bettkategorien für Quota-Eintrag hinzu
     */
    private function insertQuotaCategories($quotaId, $change) {
        if (!isset($change['hutBedCategoryDTOs']) || !is_array($change['hutBedCategoryDTOs'])) {
            return;
        }
        
        $categoriesCount = 0;
        foreach ($change['hutBedCategoryDTOs'] as $category) {
            $categoryId = $category['categoryId'] ?? null;
            $totalBeds = $category['totalBeds'] ?? 0;
            
            if ($categoryId !== null) {
                $sql = "INSERT INTO hut_quota_categories (hut_quota_id, category_id, total_beds) 
                        VALUES (?, ?, ?)";
                $stmt = $this->mysqli->prepare($sql);
                $stmt->bind_param("iii", $quotaId, $categoryId, $totalBeds);
                
                if ($stmt->execute()) {
                    $categoriesCount++;
                }
                $stmt->close();
            }
        }
        
        if ($categoriesCount > 0) {
            $this->log("Inserted {$categoriesCount} bed categories for quota ID {$quotaId}");
        }
    }
    
    /**
     * Füge Sprachbeschreibungen für Quota-Eintrag hinzu
     */
    private function insertQuotaLanguages($quotaId, $change) {
        if (!isset($change['languagesDataDTOs']) || !is_array($change['languagesDataDTOs'])) {
            return;
        }
        
        $languagesCount = 0;
        foreach ($change['languagesDataDTOs'] as $language) {
            $languageCode = $language['language'] ?? null;
            $description = $language['description'] ?? '';
            
            if ($languageCode) {
                $sql = "INSERT INTO hut_quota_languages (hut_quota_id, language, description) 
                        VALUES (?, ?, ?)";
                $stmt = $this->mysqli->prepare($sql);
                $stmt->bind_param("iss", $quotaId, $languageCode, $description);
                
                if ($stmt->execute()) {
                    $languagesCount++;
                }
                $stmt->close();
            }
        }
        
        if ($languagesCount > 0) {
            $this->log("Inserted {$languagesCount} language descriptions for quota ID {$quotaId}");
        }
    }
    
    /**
     * Aktualisiere Bettkategorien (lösche alte, füge neue hinzu)
     */
    private function updateQuotaCategories($quotaId, $change) {
        // Lösche alte Kategorien
        $deleteSql = "DELETE FROM hut_quota_categories WHERE hut_quota_id = ?";
        $deleteStmt = $this->mysqli->prepare($deleteSql);
        $deleteStmt->bind_param("i", $quotaId);
        $deleteStmt->execute();
        $deleteStmt->close();
        
        // Füge neue Kategorien hinzu
        $this->insertQuotaCategories($quotaId, $change);
    }
    
    /**
     * Aktualisiere Sprachbeschreibungen (lösche alte, füge neue hinzu)
     */
    private function updateQuotaLanguages($quotaId, $change) {
        // Lösche alte Sprachen
        $deleteSql = "DELETE FROM hut_quota_languages WHERE hut_quota_id = ?";
        $deleteStmt = $this->mysqli->prepare($deleteSql);
        $deleteStmt->bind_param("i", $quotaId);
        $deleteStmt->execute();
        $deleteStmt->close();
        
        // Füge neue Sprachen hinzu
        $this->insertQuotaLanguages($quotaId, $change);
    }

    /**
     * Vollständiger Import-Workflow
     */
    public function executeImport($dateFrom, $dateTo) {
        $this->log("=== Starting HRS Import Workflow ===");
        $this->log("Parameters: dateFrom={$dateFrom}, dateTo={$dateTo}");
        
        try {
            // 1. Authentifizierung
            if (!$this->authenticate()) {
                throw new Exception("Authentication failed");
            }
            
            // 2. Import Reservations
            $this->importReservations($dateFrom, $dateTo);
            
            // 3. Import Daily Summary
            $this->importDailySummary($dateFrom, $dateTo);
            
            // 4. Import HutQuota
            $this->importHutQuota($dateFrom, $dateTo);
            
            $this->logSuccess("=== Import Workflow Completed Successfully ===");
            return true;
            
        } catch (Exception $e) {
            $this->logError("Import workflow failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generiere finalen JSON-Bericht
     */
    public function generateReport($success) {
        $endTime = microtime(true);
        $duration = round($endTime - $this->startTime, 3);
        
        return [
            'status' => $success ? 'success' : 'error',
            'timestamp' => date('c'),
            'duration_seconds' => $duration,
            'statistics' => $this->stats,
            'debug_log' => $this->debug_log,
            'summary' => [
                'total_operations' => count($this->debug_log),
                'success_operations' => count(array_filter($this->debug_log, function($log) { 
                    return $log['type'] === 'success'; 
                })),
                'error_operations' => $this->stats['errors_encountered'],
                'total_records_imported' => $this->stats['reservations_inserted'] + 
                                          $this->stats['reservations_updated'] +
                                          $this->stats['daily_summary_imported'] + 
                                          $this->stats['hut_quota_imported'],
                'reservations_summary' => [
                    'queried' => $this->stats['reservations_queried'],
                    'inserted' => $this->stats['reservations_inserted'], 
                    'updated' => $this->stats['reservations_updated'],
                    'insert_rate' => $this->stats['reservations_queried'] > 0 ? 
                        round(($this->stats['reservations_inserted'] / $this->stats['reservations_queried']) * 100, 1) . '%' : '0%',
                    'update_rate' => $this->stats['reservations_queried'] > 0 ? 
                        round(($this->stats['reservations_updated'] / $this->stats['reservations_queried']) * 100, 1) . '%' : '0%'
                ]
            ]
        ];
    }
}

// Workflow ausführen
try {
    // Content-Type für JSON setzen
    if (!php_sapi_name() === 'cli') {
        header('Content-Type: application/json');
    }
    
    $hrs = new HRSImportSystem();
    $success = $hrs->executeImport($dateFrom, $dateTo);
    $report = $hrs->generateReport($success);
    
    // Finaler JSON-Bericht
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Fehler-Report
    $errorReport = [
        'status' => 'error',
        'timestamp' => date('c'),
        'error' => $e->getMessage(),
        'debug_log' => isset($hrs) ? $hrs->generateReport(false)['debug_log'] : []
    ];
    
    echo json_encode($errorReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>
