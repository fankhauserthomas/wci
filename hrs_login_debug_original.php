<?php
/**
 * HRS Login Debug - PHP Implementation mit engmaschiger Browser-Ausgabe
 * Basiert auf VB.NET HRSPlaywrightLoginTwoStep Klasse
 */

class HRSLoginDebug {
    private $baseUrl = 'https://www.hut-reservation.org';
    private $defaultHeaders;
    private $csrfToken;
    private $cookies = array();
    private $debugOutput = array();
    private $verbose = true;
    
    // TODO: Hier deine Zugangsdaten eintragen
    private $username = 'office@franzsennhuette.at';  // <-- Hier Username eintragen
    private $password = 'Fsh2147m!3';  // <-- Hier Passwort eintragen
    
    public function __construct() {
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
        
        $this->debug("✔ HRSLoginDebug Konstruktor initialisiert");
    }
    
    private function debug($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $this->debugOutput[] = "[$timestamp] $message";
        
        if ($this->verbose) {
            // Sofortige Browser-Ausgabe
            echo "<div style='font-family: monospace; font-size: 12px; margin: 2px 0; padding: 3px; background: #f0f0f0; border-left: 3px solid #007cba;'>";
            echo htmlspecialchars("[$timestamp] $message");
            echo "</div>\n";
            flush();
            ob_flush();
        }
    }
    
    private function debugError($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $this->debugOutput[] = "[$timestamp] ❌ ERROR: $message";
        
        if ($this->verbose) {
            echo "<div style='font-family: monospace; font-size: 12px; margin: 2px 0; padding: 3px; background: #ffe6e6; border-left: 3px solid #ff0000; color: #cc0000;'>";
            echo htmlspecialchars("[$timestamp] ❌ ERROR: $message");
            echo "</div>\n";
            flush();
            ob_flush();
        }
    }
    
    private function debugSuccess($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $this->debugOutput[] = "[$timestamp] ✅ SUCCESS: $message";
        
        if ($this->verbose) {
            echo "<div style='font-family: monospace; font-size: 12px; margin: 2px 0; padding: 3px; background: #e6ffe6; border-left: 3px solid #00aa00; color: #006600;'>";
            echo htmlspecialchars("[$timestamp] ✅ SUCCESS: $message");
            echo "</div>\n";
            flush();
            ob_flush();
        }
    }
    
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
    
    public function getDailySummaryAsync($hutId, $dateFrom) {
        $this->debug("=== GetDailySummaryAsync START ===");
        $this->debug("HutId: $hutId, DateFrom: $dateFrom");
        
        // URL Parameter aufbauen
        $params = array(
            'hutId' => $hutId,
            'dateFrom' => $dateFrom
        );
        
        $url = '/api/v1/manage/reservation/dailySummary?' . http_build_query($params);
        
        $headers = array(
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
            'X-XSRF-TOKEN: ' . $this->csrfToken
        );
        
        $this->debug("📡 API Call: $url");
        $response = $this->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            $this->debugError("GetDailySummary fehlgeschlagen - Status: " . ($response['status'] ?? 'unknown'));
            $this->debugError("Response Body: " . substr($response['body'] ?? '', 0, 500));
            return false;
        }
        
        $this->debugSuccess("DailySummary erfolgreich abgerufen");
        $this->debug("Response: " . strlen($response['body']) . " Zeichen erhalten");
        
        $this->debug("=== GetDailySummaryAsync COMPLETE ===");
        return $response['body'];
    }
    
    public function getHutQuotaAsync($hutId, $dateFrom, $dateTo, $page = 0, $size = 20) {
        $this->debug("=== GetHutQuotaAsync START ===");
        $this->debug("HutId: $hutId, DateFrom: $dateFrom, DateTo: $dateTo, Page: $page");
        
        // URL Parameter aufbauen
        $params = array(
            'hutId' => $hutId,
            'page' => $page,
            'size' => $size,
            'sortList' => 'BeginDate',
            'sortOrder' => 'DESC',
            'open' => 'true',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo
        );
        
        $url = '/api/v1/manage/hutQuota?' . http_build_query($params);
        
        $headers = array(
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
            'X-XSRF-TOKEN: ' . $this->csrfToken
        );
        
        $this->debug("📡 API Call: $url");
        $response = $this->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            $this->debugError("GetHutQuota fehlgeschlagen - Status: " . ($response['status'] ?? 'unknown'));
            $this->debugError("Response Body: " . substr($response['body'] ?? '', 0, 500));
            return false;
        }
        
        $this->debugSuccess("HutQuota erfolgreich abgerufen");
        $this->debug("Response: " . strlen($response['body']) . " Zeichen erhalten");
        
        $this->debug("=== GetHutQuotaAsync COMPLETE ===");
        return $response['body'];
    }
    
    public function getHutQuotaSequence($hutId, $startDate, $months = 2) {
        $this->debug("=== GetHutQuotaSequence START ===");
        $this->debug("HutId: $hutId, StartDate: $startDate, Months: $months");
        
        $allData = array();
        $currentDate = DateTime::createFromFormat('d.m.Y', $startDate);
        
        if (!$currentDate) {
            $this->debugError("Ungültiges Startdatum: $startDate");
            return false;
        }
        
        // Berechne das Enddatum (Monate später)
        $endDate = clone $currentDate;
        $endDate->modify("+$months months");
        
        $page = 0;
        $hasMorePages = true;
        
        while ($hasMorePages) {
            $dateFromStr = $currentDate->format('d.m.Y');
            $dateToStr = $endDate->format('d.m.Y');
            
            $this->debug("📅 Page $page: Abrufen von $dateFromStr bis $dateToStr");
            
            $quotaData = $this->getHutQuotaAsync($hutId, $dateFromStr, $dateToStr, $page, 20);
            
            if ($quotaData) {
                $decoded = json_decode($quotaData, true);
                if ($decoded && isset($decoded['_embedded']['bedCapacityChangeResponseDTOList'])) {
                    $items = $decoded['_embedded']['bedCapacityChangeResponseDTOList'];
                    $this->debug("✅ Page $page: " . count($items) . " Kapazitätsänderungen erhalten");
                    $allData = array_merge($allData, $items);
                    
                    // Prüfe ob es weitere Seiten gibt
                    $pageInfo = $decoded['page'] ?? array();
                    $totalPages = $pageInfo['totalPages'] ?? 1;
                    $currentPageNum = $pageInfo['number'] ?? 0;
                    
                    if ($currentPageNum >= $totalPages - 1) {
                        $hasMorePages = false;
                        $this->debug("📄 Letzte Seite erreicht (Page $currentPageNum von $totalPages)");
                    } else {
                        $page++;
                    }
                } else {
                    $this->debugError("Fehler beim Dekodieren der JSON-Daten für Page $page");
                    $hasMorePages = false;
                }
            } else {
                $this->debugError("Fehler beim Abrufen der Daten für Page $page");
                $hasMorePages = false;
            }
            
            // Schutz vor Endlos-Schleifen
            if ($page > 10) {
                $this->debug("⚠️ Maximale Seitenzahl erreicht, stoppe Abruf");
                break;
            }
            
            // Kurze Pause zwischen den Requests
            if ($hasMorePages) {
                sleep(1);
            }
        }
        
        $this->debugSuccess("HutQuota-Sequenz abgeschlossen: " . count($allData) . " Kapazitätsänderungen gesamt");
        $this->debug("=== GetHutQuotaSequence COMPLETE ===");
        
        return $allData;
    }
    
    public function analyzeHutQuotaStructure($hutQuotaData) {
        $this->debug("=== AnalyzeHutQuotaStructure START ===");
        
        if (!is_array($hutQuotaData) || empty($hutQuotaData)) {
            $this->debugError("Keine gültigen HutQuota-Daten zum Analysieren");
            return false;
        }
        
        $this->debug("📊 Analysiere " . count($hutQuotaData) . " Kapazitätsänderungen");
        
        // Struktur-Analyse
        $sampleQuota = $hutQuotaData[0];
        
        $this->debug("🔍 Struktur-Analyse basierend auf erstem Eintrag:");
        $this->debug("   ID: " . ($sampleQuota['id'] ?? 'N/A'));
        $this->debug("   DateFrom: " . ($sampleQuota['dateFrom'] ?? 'N/A'));
        $this->debug("   DateTo: " . ($sampleQuota['dateTo'] ?? 'N/A'));
        $this->debug("   Title: " . ($sampleQuota['title'] ?? 'N/A'));
        $this->debug("   Mode: " . ($sampleQuota['mode'] ?? 'N/A'));
        $this->debug("   Capacity: " . ($sampleQuota['capacity'] ?? 'N/A'));
        $this->debug("   IsRecurring: " . ($sampleQuota['isRecurring'] ? 'true' : 'false'));
        
        // Kategorien analysieren
        if (isset($sampleQuota['hutBedCategoryDTOs'])) {
            $this->debug("📋 Bett-Kategorien:");
            foreach ($sampleQuota['hutBedCategoryDTOs'] as $idx => $category) {
                $this->debug("   CategoryId " . $category['categoryId'] . ": " . $category['totalBeds'] . " Betten");
            }
        }
        
        // Sprachen analysieren
        if (isset($sampleQuota['languagesDataDTOs'])) {
            $this->debug("🌐 Sprach-Daten:");
            foreach ($sampleQuota['languagesDataDTOs'] as $lang) {
                $this->debug("   " . $lang['language'] . ": '" . ($lang['description'] ?? '') . "'");
            }
        }
        
        // DB-Tabellen-Vorschlag generieren
        $this->debug("=== DB-Tabellen-Vorschlag ===");
        
        if ($this->verbose) {
            echo "<div style='background: #fff3e6; padding: 15px; margin: 15px 0; border: 1px solid #ff8c00; border-radius: 4px;'>";
            echo "<h4>🏠 Analyse der HutQuota-Datenstruktur</h4>";
            
            echo "<h5>📅 Haupt-Tabelle: hut_quota</h5>";
            echo "<pre style='background: #f8f8f8; padding: 10px; border-radius: 4px;'>
CREATE TABLE hut_quota (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hrs_id INT NOT NULL COMMENT 'Original ID aus HRS System',
    hut_id INT NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    title VARCHAR(255) NOT NULL,
    mode ENUM('SERVICED', 'UNSERVICED', 'CLOSED') NOT NULL,
    capacity INT DEFAULT 0,
    weeks_recurrence INT NULL,
    occurrences_number INT NULL,
    monday BOOLEAN DEFAULT FALSE,
    tuesday BOOLEAN DEFAULT FALSE,
    wednesday BOOLEAN DEFAULT FALSE,
    thursday BOOLEAN DEFAULT FALSE,
    friday BOOLEAN DEFAULT FALSE,
    saturday BOOLEAN DEFAULT FALSE,
    sunday BOOLEAN DEFAULT FALSE,
    series_begin_date DATE NULL,
    series_end_date DATE NULL,
    is_recurring BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hrs_id (hrs_id),
    INDEX idx_hut_dates (hut_id, date_from, date_to),
    INDEX idx_date_range (date_from, date_to),
    INDEX idx_mode (mode)
);
</pre>";

            echo "<h5>🛏️ Kategorien-Tabelle: hut_quota_categories</h5>";
            echo "<pre style='background: #f8f8f8; padding: 10px; border-radius: 4px;'>
CREATE TABLE hut_quota_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hut_quota_id INT NOT NULL,
    category_id INT NOT NULL COMMENT 'HRS Category ID (1958=ML, 2293=MBZ, 2381=2BZ, 6106=SK)',
    total_beds INT DEFAULT 0,
    FOREIGN KEY (hut_quota_id) REFERENCES hut_quota(id) ON DELETE CASCADE,
    INDEX idx_quota_category (hut_quota_id, category_id)
);
</pre>";

            echo "<h5>🌐 Sprach-Tabelle: hut_quota_languages</h5>";
            echo "<pre style='background: #f8f8f8; padding: 10px; border-radius: 4px;'>
CREATE TABLE hut_quota_languages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hut_quota_id INT NOT NULL,
    language VARCHAR(10) NOT NULL COMMENT 'DE_DE, EN, FR, IT',
    description TEXT,
    FOREIGN KEY (hut_quota_id) REFERENCES hut_quota(id) ON DELETE CASCADE,
    INDEX idx_quota_language (hut_quota_id, language)
);
</pre>";

            echo "<h5>🔄 Import-Empfehlungen</h5>";
            echo "<ul>";
            echo "<li>✅ <strong>REPLACE INTO</strong> mit hrs_id als unique key</li>";
            echo "<li>✅ <strong>Datum-Konvertierung:</strong> 'dd.mm.yyyy' → 'yyyy-mm-dd'</li>";
            echo "<li>✅ <strong>Transaktionen</strong> für Konsistenz zwischen Haupt- und Detail-Tabellen</li>";
            echo "<li>✅ <strong>Bulk-Import</strong> für bessere Performance</li>";
            echo "<li>✅ <strong>Validierung</strong> von Datumsperioden und Kapazitäten</li>";
            echo "</ul>";
            
            echo "<h5>📊 Datentyp-Mapping</h5>";
            echo "<ul>";
            echo "<li><strong>CategoryId 1958:</strong> ML (Matratzenlager/DORM)</li>";
            echo "<li><strong>CategoryId 2293:</strong> MBZ (Mehrbettzimmer/SB)</li>";
            echo "<li><strong>CategoryId 2381:</strong> 2BZ (Zweierzimmer/DR)</li>";
            echo "<li><strong>CategoryId 6106:</strong> SK (Sonderkategorie/SC)</li>";
            echo "</ul>";
            
            echo "</div>";
        }
        
        $this->debug("=== AnalyzeHutQuotaStructure COMPLETE ===");
        return true;
    }
    
    public function importHutQuotaToDb($hutQuotaData, $hutId = 675) {
        $this->debug("=== ImportHutQuotaToDb START ===");
        
        if (!is_array($hutQuotaData) || empty($hutQuotaData)) {
            $this->debugError("Keine gültigen HutQuota-Daten zum Import");
            return false;
        }
        
        $this->debug("💾 Importiere " . count($hutQuotaData) . " HutQuota-Einträge für HutId: $hutId");
        
        // Datenbankverbindung
        require_once __DIR__ . '/config.php';
        
        // Create fresh connection if global mysqli not available
        if (!isset($GLOBALS['mysqli']) || !$GLOBALS['mysqli']) {
            $this->debug("⚠️ Global mysqli not available, creating fresh connection...");
            $mysqli = new mysqli($GLOBALS['dbHost'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], $GLOBALS['dbName']);
            if ($mysqli->connect_error) {
                $this->debugError("Fresh MySQL Connection Error: " . $mysqli->connect_error);
                return false;
            }
            $mysqli->set_charset('utf8mb4');
        } else {
            $mysqli = $GLOBALS['mysqli'];
            $this->debug("✅ Using global mysqli connection");
        }
        
        if ($mysqli->connect_error) {
            $this->debugError("MySQL Connection Error: " . $mysqli->connect_error);
            return false;
        }
        
        $this->debugSuccess("Datenbankverbindung erfolgreich hergestellt");
        
        // WICHTIG: Smart Update-Strategie wegen sich ändernder Quotas
        // 1. Sammle alle HRS-IDs aus den neuen Daten
        $newHrsIds = array();
        foreach ($hutQuotaData as $quota) {
            if (isset($quota['id'])) {
                $newHrsIds[] = intval($quota['id']);
            }
        }
        
        $this->debug("🔍 Neue HRS-IDs: " . implode(', ', array_slice($newHrsIds, 0, 5)) . (count($newHrsIds) > 5 ? '...' : ''));
        
        // 2. Finde bestehende HRS-IDs für diese Hütte im aktuellen Zeitraum
        $dateRange = $this->getDateRangeFromQuotas($hutQuotaData);
        if ($dateRange) {
            $existingHrsIds = $this->getExistingHrsIds($mysqli, $hutId, $dateRange['from'], $dateRange['to']);
            $this->debug("🗃️ Bestehende HRS-IDs im Zeitraum: " . implode(', ', $existingHrsIds));
            
            // 3. Finde HRS-IDs die gelöscht werden müssen (nicht mehr in neuen Daten)
            $toDelete = array_diff($existingHrsIds, $newHrsIds);
            if (!empty($toDelete)) {
                $this->debug("🗑️ Zu löschende HRS-IDs: " . implode(', ', $toDelete));
                $this->deleteObsoleteQuotas($mysqli, $toDelete);
            }
        }
        
        $importCount = 0;
        $updateCount = 0;
        $errorCount = 0;
        
        // Auto-commit ausschalten für Transaktionen
        $mysqli->autocommit(false);
        
        foreach ($hutQuotaData as $quotaData) {
            $result = $this->importSingleHutQuota($mysqli, $quotaData, $hutId);
            if ($result === 'inserted') {
                $importCount++;
            } elseif ($result === 'updated') {
                $updateCount++;
            } else {
                $errorCount++;
            }
        }
        
        // Commit der Transaktion
        if ($errorCount == 0) {
            $mysqli->commit();
            $this->debugSuccess("Alle Daten erfolgreich committed");
        } else {
            $mysqli->rollback();
            $this->debugError("Rollback durchgeführt aufgrund von Fehlern");
        }
        
        // Auto-commit wieder einschalten
        $mysqli->autocommit(true);
        
        $this->debugSuccess("HutQuota-Import abgeschlossen: $importCount neu, $updateCount aktualisiert, $errorCount Fehler");
        $this->debug("=== ImportHutQuotaToDb COMPLETE ===");
        
        return array(
            'imported' => $importCount,
            'updated' => $updateCount,
            'errors' => $errorCount,
            'total' => count($hutQuotaData)
        );
    }
    
    private function getDateRangeFromQuotas($hutQuotaData) {
        if (empty($hutQuotaData)) return null;
        
        $dates = array();
        foreach ($hutQuotaData as $quota) {
            if (isset($quota['dateFrom'])) {
                $dates[] = $this->convertDateToMysqlDate($quota['dateFrom']);
            }
            if (isset($quota['dateTo'])) {
                $dates[] = $this->convertDateToMysqlDate($quota['dateTo']);
            }
        }
        
        if (empty($dates)) return null;
        
        sort($dates);
        return array(
            'from' => $dates[0],
            'to' => $dates[count($dates) - 1]
        );
    }
    
    private function getExistingHrsIds($mysqli, $hutId, $dateFrom, $dateTo) {
        $sql = "SELECT hrs_id FROM hut_quota 
                WHERE hut_id = ? 
                AND (date_from <= ? AND date_to >= ?)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('iss', $hutId, $dateTo, $dateFrom);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $hrsIds = array();
        while ($row = $result->fetch_assoc()) {
            $hrsIds[] = intval($row['hrs_id']);
        }
        
        $stmt->close();
        return $hrsIds;
    }
    
    private function deleteObsoleteQuotas($mysqli, $hrsIds) {
        if (empty($hrsIds)) return;
        
        $placeholders = str_repeat('?,', count($hrsIds) - 1) . '?';
        
        // Zuerst Kategorien und Sprachen löschen (Foreign Key Constraints)
        $sqlCategories = "DELETE hqc FROM hut_quota_categories hqc 
                         JOIN hut_quota hq ON hqc.hut_quota_id = hq.id 
                         WHERE hq.hrs_id IN ($placeholders)";
        
        $stmtCat = $mysqli->prepare($sqlCategories);
        $stmtCat->bind_param(str_repeat('i', count($hrsIds)), ...$hrsIds);
        $stmtCat->execute();
        $deletedCategories = $stmtCat->affected_rows;
        $stmtCat->close();
        
        $sqlLanguages = "DELETE hql FROM hut_quota_languages hql 
                        JOIN hut_quota hq ON hql.hut_quota_id = hq.id 
                        WHERE hq.hrs_id IN ($placeholders)";
        
        $stmtLang = $mysqli->prepare($sqlLanguages);
        $stmtLang->bind_param(str_repeat('i', count($hrsIds)), ...$hrsIds);
        $stmtLang->execute();
        $deletedLanguages = $stmtLang->affected_rows;
        $stmtLang->close();
        
        // Dann Haupt-Quotas löschen
        $sqlMain = "DELETE FROM hut_quota WHERE hrs_id IN ($placeholders)";
        $stmtMain = $mysqli->prepare($sqlMain);
        $stmtMain->bind_param(str_repeat('i', count($hrsIds)), ...$hrsIds);
        $stmtMain->execute();
        $deletedMain = $stmtMain->affected_rows;
        $stmtMain->close();
        
        $this->debug("🗑️ Gelöscht: $deletedMain Quotas, $deletedCategories Kategorien, $deletedLanguages Sprachen");
    }
    
    private function importSingleHutQuota($mysqli, $quotaData, $hutId) {
        try {
            // Basis-Daten extrahieren
            $hrsId = intval($quotaData['id'] ?? 0);
            if ($hrsId === 0) {
                $this->debugError("Keine gültige HRS-ID gefunden");
                return false;
            }
            
            $dateFrom = $this->convertDateToMysqlDate($quotaData['dateFrom'] ?? '');
            $dateTo = $this->convertDateToMysqlDate($quotaData['dateTo'] ?? '');
            
            if (!$dateFrom || !$dateTo) {
                $this->debugError("Ungültige Daten für HRS-ID $hrsId");
                return false;
            }
            
            $title = $quotaData['title'] ?? '';
            $mode = $quotaData['mode'] ?? 'SERVICED';
            $capacity = intval($quotaData['capacity'] ?? 0);
            $weeksRecurrence = !empty($quotaData['weeksRecurrence']) ? intval($quotaData['weeksRecurrence']) : null;
            $occurrencesNumber = !empty($quotaData['occurrencesNumber']) ? intval($quotaData['occurrencesNumber']) : null;
            
            // Wochentage
            $monday = ($quotaData['monday'] ?? false) ? 1 : 0;
            $tuesday = ($quotaData['tuesday'] ?? false) ? 1 : 0;
            $wednesday = ($quotaData['wednesday'] ?? false) ? 1 : 0;
            $thursday = ($quotaData['thursday'] ?? false) ? 1 : 0;
            $friday = ($quotaData['friday'] ?? false) ? 1 : 0;
            $saturday = ($quotaData['saturday'] ?? false) ? 1 : 0;
            $sunday = ($quotaData['sunday'] ?? false) ? 1 : 0;
            
            $seriesBeginDate = !empty($quotaData['seriesBeginDate']) ? $this->convertDateToMysqlDate($quotaData['seriesBeginDate']) : null;
            $seriesEndDate = !empty($quotaData['seriesEndDate']) ? $this->convertDateToMysqlDate($quotaData['seriesEndDate']) : null;
            $isRecurring = ($quotaData['isRecurring'] ?? false) ? 1 : 0;
            
            $this->debug("🏠 Import Quota $hrsId: $dateFrom bis $dateTo ($mode, $capacity Plätze)");
            
            // Prüfe ob HRS-ID bereits existiert
            $existsResult = $this->checkQuotaExists($mysqli, $hrsId);
            $isUpdate = $existsResult !== false;
            
            // REPLACE INTO für hut_quota (automatisches INSERT/UPDATE)
            $sql = "REPLACE INTO hut_quota (
                hrs_id, hut_id, date_from, date_to, title, mode, capacity,
                weeks_recurrence, occurrences_number,
                monday, tuesday, wednesday, thursday, friday, saturday, sunday,
                series_begin_date, series_end_date, is_recurring
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                $this->debugError("SQL Prepare Error für hut_quota: " . $mysqli->error);
                return false;
            }
            
            $stmt->bind_param('iissssiiiiiiiiiiiss',
                $hrsId, $hutId, $dateFrom, $dateTo, $title, $mode, $capacity,
                $weeksRecurrence, $occurrencesNumber,
                $monday, $tuesday, $wednesday, $thursday, $friday, $saturday, $sunday,
                $seriesBeginDate, $seriesEndDate, $isRecurring
            );
            
            if (!$stmt->execute()) {
                $this->debugError("SQL Execute Error für hut_quota: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
            // hut_quota_id holen
            $hutQuotaId = $mysqli->insert_id;
            if ($hutQuotaId == 0) {
                // Bei REPLACE kann insert_id 0 sein, dann per SELECT holen
                $selectSql = "SELECT id FROM hut_quota WHERE hrs_id = ?";
                $selectStmt = $mysqli->prepare($selectSql);
                $selectStmt->bind_param('i', $hrsId);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                $row = $result->fetch_assoc();
                $hutQuotaId = $row['id'];
                $selectStmt->close();
            }
            
            $stmt->close();
            
            // Bestehende Kategorien und Sprachen für dieses Quota löschen
            $this->deleteCategoriesAndLanguages($mysqli, $hutQuotaId);
            
            // Kategorien importieren
            if (isset($quotaData['hutBedCategoryDTOs']) && is_array($quotaData['hutBedCategoryDTOs'])) {
                foreach ($quotaData['hutBedCategoryDTOs'] as $categoryData) {
                    if (!$this->importSingleQuotaCategory($mysqli, $hutQuotaId, $categoryData)) {
                        $this->debugError("Fehler beim Import einer Kategorie für Quota $hrsId");
                        return false;
                    }
                }
            }
            
            // Sprachen importieren
            if (isset($quotaData['languagesDataDTOs']) && is_array($quotaData['languagesDataDTOs'])) {
                foreach ($quotaData['languagesDataDTOs'] as $languageData) {
                    if (!$this->importSingleQuotaLanguage($mysqli, $hutQuotaId, $languageData)) {
                        $this->debugError("Fehler beim Import einer Sprache für Quota $hrsId");
                        return false;
                    }
                }
            }
            
            $action = $isUpdate ? 'aktualisiert' : 'eingefügt';
            $this->debug("✅ Quota $hrsId erfolgreich $action (ID: $hutQuotaId)");
            return $isUpdate ? 'updated' : 'inserted';
            
        } catch (Exception $e) {
            $this->debugError("Exception beim Import von Quota: " . $e->getMessage());
            return false;
        }
    }
    
    private function checkQuotaExists($mysqli, $hrsId) {
        $sql = "SELECT id FROM hut_quota WHERE hrs_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $hrsId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ? $row['id'] : false;
    }
    
    private function deleteCategoriesAndLanguages($mysqli, $hutQuotaId) {
        // Kategorien löschen
        $sqlCat = "DELETE FROM hut_quota_categories WHERE hut_quota_id = ?";
        $stmtCat = $mysqli->prepare($sqlCat);
        $stmtCat->bind_param('i', $hutQuotaId);
        $stmtCat->execute();
        $stmtCat->close();
        
        // Sprachen löschen
        $sqlLang = "DELETE FROM hut_quota_languages WHERE hut_quota_id = ?";
        $stmtLang = $mysqli->prepare($sqlLang);
        $stmtLang->bind_param('i', $hutQuotaId);
        $stmtLang->execute();
        $stmtLang->close();
    }
    
    private function importSingleQuotaCategory($mysqli, $hutQuotaId, $categoryData) {
        try {
            $categoryId = intval($categoryData['categoryId'] ?? 0);
            $totalBeds = intval($categoryData['totalBeds'] ?? 0);
            
            if ($categoryId === 0) {
                $this->debugError("Ungültige Category-ID");
                return false;
            }
            
            $this->debug("  🛏️ Kategorie $categoryId: $totalBeds Betten");
            
            $sql = "INSERT INTO hut_quota_categories (
                hut_quota_id, category_id, total_beds
            ) VALUES (?, ?, ?)";
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                $this->debugError("SQL Prepare Error für quota category: " . $mysqli->error);
                return false;
            }
            
            $stmt->bind_param('iii', $hutQuotaId, $categoryId, $totalBeds);
            
            if (!$stmt->execute()) {
                $this->debugError("SQL Execute Error für quota category: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
            $stmt->close();
            return true;
            
        } catch (Exception $e) {
            $this->debugError("Exception beim Import von Quota-Kategorie: " . $e->getMessage());
            return false;
        }
    }
    
    private function importSingleQuotaLanguage($mysqli, $hutQuotaId, $languageData) {
        try {
            $language = $languageData['language'] ?? '';
            $description = $languageData['description'] ?? '';
            
            if (empty($language)) {
                return true; // Leere Sprache überspringen, kein Fehler
            }
            
            $this->debug("  🌐 Sprache $language: '$description'");
            
            $sql = "INSERT INTO hut_quota_languages (
                hut_quota_id, language, description
            ) VALUES (?, ?, ?)";
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                $this->debugError("SQL Prepare Error für quota language: " . $mysqli->error);
                return false;
            }
            
            $stmt->bind_param('iss', $hutQuotaId, $language, $description);
            
            if (!$stmt->execute()) {
                $this->debugError("SQL Execute Error für quota language: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
            $stmt->close();
            return true;
            
        } catch (Exception $e) {
            $this->debugError("Exception beim Import von Quota-Sprache: " . $e->getMessage());
            return false;
        }
    }
    
    public function testFullHutQuotaImport($months = 3, $hutId = 675) {
        $this->debug("=== TestFullHutQuotaImport START ===");
        $this->debug("🔄 Teste kompletten HutQuota-Import für $months Monate");
        
        // 1. HutQuota-Daten abrufen
        $startDate = date('d.m.Y'); // Heute als Startdatum
        $hutQuotaData = $this->getHutQuotaSequence($hutId, $startDate, $months);
        
        if (!$hutQuotaData || empty($hutQuotaData)) {
            $this->debugError("Keine HutQuota-Daten erhalten");
            return false;
        }
        
        $this->debugSuccess("✅ " . count($hutQuotaData) . " HutQuota-Einträge erhalten");
        
        // 2. Datenbank-Import durchführen
        $importResult = $this->importHutQuotaToDb($hutQuotaData, $hutId);
        
        if ($importResult === false) {
            $this->debugError("HutQuota-Import fehlgeschlagen");
            return false;
        }
        
        $this->debugSuccess("✅ HutQuota-Import abgeschlossen:");
        $this->debug("   📥 Neu importiert: " . $importResult['imported']);
        $this->debug("   🔄 Aktualisiert: " . $importResult['updated']);
        $this->debug("   ❌ Fehler: " . $importResult['errors']);
        $this->debug("   📊 Gesamt: " . $importResult['total']);
        
        // 3. Validierung der importierten Daten
        $this->validateImportedQuotas($hutId);
        
        $this->debug("=== TestFullHutQuotaImport COMPLETE ===");
        return $importResult;
    }
    
    private function validateImportedQuotas($hutId) {
        $this->debug("🔍 Validiere importierte HutQuota-Daten...");
        
        require_once __DIR__ . '/config.php';
        
        if (!isset($GLOBALS['mysqli']) || !$GLOBALS['mysqli']) {
            $mysqli = new mysqli($GLOBALS['dbHost'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], $GLOBALS['dbName']);
            if ($mysqli->connect_error) {
                $this->debugError("Datenbankverbindung für Validierung fehlgeschlagen");
                return false;
            }
            $mysqli->set_charset('utf8mb4');
        } else {
            $mysqli = $GLOBALS['mysqli'];
        }
        
        // Anzahl Quotas prüfen
        $sql = "SELECT COUNT(*) as count FROM hut_quota WHERE hut_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $hutId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $quotaCount = $row['count'];
        $stmt->close();
        
        $this->debug("📊 Gesamt Quotas in DB für Hütte $hutId: $quotaCount");
        
        // Anzahl Kategorien prüfen
        $sql = "SELECT COUNT(*) as count FROM hut_quota_categories hqc 
                JOIN hut_quota hq ON hqc.hut_quota_id = hq.id 
                WHERE hq.hut_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $hutId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $categoryCount = $row['count'];
        $stmt->close();
        
        $this->debug("🛏️ Gesamt Kategorien in DB: $categoryCount");
        
        // Anzahl Sprachen prüfen
        $sql = "SELECT COUNT(*) as count FROM hut_quota_languages hql 
                JOIN hut_quota hq ON hql.hut_quota_id = hq.id 
                WHERE hq.hut_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $hutId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $languageCount = $row['count'];
        $stmt->close();
        
        $this->debug("🌐 Gesamt Sprachen in DB: $languageCount");
        
        // Neueste Quotas anzeigen
        $sql = "SELECT hrs_id, date_from, date_to, title, mode, capacity 
                FROM hut_quota 
                WHERE hut_id = ? 
                ORDER BY date_from DESC 
                LIMIT 5";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $hutId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $this->debug("📅 Neueste 5 Quotas:");
        while ($row = $result->fetch_assoc()) {
            $this->debug("   HRS-ID " . $row['hrs_id'] . ": " . $row['date_from'] . " - " . $row['date_to'] . 
                        " (" . $row['mode'] . ", " . $row['capacity'] . " Plätze) - " . substr($row['title'], 0, 30));
        }
        $stmt->close();
        
        return true;
    }
    
    public function getDailySummarySequence($hutId, $startDate, $sequences = 5) {
        $this->debug("=== GetDailySummarySequence START ===");
        $this->debug("HutId: $hutId, StartDate: $startDate, Sequences: $sequences");
        
        $allData = array();
        $currentDate = DateTime::createFromFormat('d.m.Y', $startDate);
        
        if (!$currentDate) {
            $this->debugError("Ungültiges Startdatum: $startDate");
            return false;
        }
        
        // Ein Tag zurück für das "datum-1" Requirement
        $currentDate->modify('-1 day');
        
        for ($i = 0; $i < $sequences; $i++) {
            $dateStr = $currentDate->format('d.m.Y');
            $this->debug("📅 Sequenz " . ($i + 1) . "/" . $sequences . ": Abrufen ab $dateStr");
            
            $summaryData = $this->getDailySummaryAsync($hutId, $dateStr);
            
            if ($summaryData) {
                $decoded = json_decode($summaryData, true);
                if ($decoded && is_array($decoded)) {
                    $this->debug("✅ Sequenz " . ($i + 1) . ": " . count($decoded) . " Tage erhalten");
                    $allData = array_merge($allData, $decoded);
                } else {
                    $this->debugError("Fehler beim Dekodieren der JSON-Daten für Sequenz " . ($i + 1));
                }
            } else {
                $this->debugError("Fehler beim Abrufen der Daten für Sequenz " . ($i + 1));
            }
            
            // 10 Tage weiter für die nächste Sequenz
            $currentDate->modify('+10 days');
            
            // Kurze Pause zwischen den Requests
            sleep(1);
        }
        
        $this->debugSuccess("DailySummary-Sequenz abgeschlossen: " . count($allData) . " Tage gesamt");
        $this->debug("=== GetDailySummarySequence COMPLETE ===");
        
        return $allData;
    }
    
    public function analyzeDailySummaryStructure($dailySummaryData) {
        $this->debug("=== AnalyzeDailySummaryStructure START ===");
        
        if (!is_array($dailySummaryData) || empty($dailySummaryData)) {
            $this->debugError("Keine gültigen DailySummary-Daten zum Analysieren");
            return false;
        }
        
        $this->debug("📊 Analysiere " . count($dailySummaryData) . " Tage");
        
        // Struktur-Analyse
        $sampleDay = $dailySummaryData[0];
        
        $this->debug("🔍 Struktur-Analyse basierend auf erstem Tag:");
        $this->debug("   Day: " . ($sampleDay['day'] ?? 'N/A'));
        $this->debug("   DayOfWeek: " . ($sampleDay['dayOfWeek'] ?? 'N/A'));
        $this->debug("   HutMode: " . ($sampleDay['hutMode'] ?? 'N/A'));
        $this->debug("   NumberOfArrivingGuests: " . ($sampleDay['numberOfArrivingGuests'] ?? 'N/A'));
        $this->debug("   TotalGuests: " . ($sampleDay['totalGuests'] ?? 'N/A'));
        
        // Kategorien analysieren
        if (isset($sampleDay['freePlacesPerCategories'])) {
            $this->debug("📋 Gefundene Kategorien:");
            foreach ($sampleDay['freePlacesPerCategories'] as $idx => $category) {
                $shortLabel = '';
                foreach ($category['categoryData'] as $lang) {
                    if ($lang['language'] == 'DE_DE') {
                        $shortLabel = $lang['shortLabel'];
                        break;
                    }
                }
                $this->debug("   Kategorie $idx: $shortLabel (FreePlaces: " . $category['freePlaces'] . ", AssignedGuests: " . $category['assignedGuests'] . ")");
            }
        }
        
        // DB-Tabellen-Vorschlag generieren
        $this->debug("=== DB-Tabellen-Vorschlag ===");
        
        if ($this->verbose) {
            echo "<div style='background: #e6f3ff; padding: 15px; margin: 15px 0; border: 1px solid #007cba; border-radius: 4px;'>";
            echo "<h4>📊 Analyse der DailySummary-Datenstruktur</h4>";
            
            echo "<h5>🗓️ Haupt-Tabelle: daily_summary</h5>";
            echo "<pre style='background: #f8f8f8; padding: 10px; border-radius: 4px;'>
CREATE TABLE daily_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hut_id INT NOT NULL,
    day DATE NOT NULL,
    day_of_week ENUM('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY') NOT NULL,
    hut_mode ENUM('SERVICED', 'UNSERVICED', 'CLOSED') NOT NULL,
    number_of_arriving_guests INT DEFAULT 0,
    total_guests INT DEFAULT 0,
    half_boards_value INT DEFAULT 0,
    half_boards_is_active BOOLEAN DEFAULT FALSE,
    vegetarians_value INT DEFAULT 0,
    vegetarians_is_active BOOLEAN DEFAULT FALSE,
    children_value INT DEFAULT 0,
    children_is_active BOOLEAN DEFAULT FALSE,
    mountain_guides_value INT DEFAULT 0,
    mountain_guides_is_active BOOLEAN DEFAULT FALSE,
    waiting_list_value INT DEFAULT 0,
    waiting_list_is_active BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hut_day (hut_id, day),
    INDEX idx_hut_day (hut_id, day),
    INDEX idx_day (day)
);
</pre>";

            echo "<h5>🛏️ Detail-Tabelle: daily_summary_categories</h5>";
            echo "<pre style='background: #f8f8f8; padding: 10px; border-radius: 4px;'>
CREATE TABLE daily_summary_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    daily_summary_id INT NOT NULL,
    category_type ENUM('ML', 'MBZ', '2BZ', 'SK') NOT NULL COMMENT 'ML=Matratzenlager, MBZ=Mehrbettzimmer, 2BZ=Zweierzimmer, SK=Sonderkategorie',
    is_winteraum BOOLEAN DEFAULT FALSE,
    free_places INT DEFAULT 0,
    assigned_guests INT DEFAULT 0,
    occupancy_level DECIMAL(5,2) DEFAULT 0.00,
    FOREIGN KEY (daily_summary_id) REFERENCES daily_summary(id) ON DELETE CASCADE,
    INDEX idx_daily_summary_category (daily_summary_id, category_type)
);
</pre>";

            echo "<h5>🔄 Import-Funktion</h5>";
            echo "<p>Die Import-Funktion sollte:</p>";
            echo "<ul>";
            echo "<li>✅ Bestehende Daten für den Tag überschreiben (REPLACE INTO)</li>";
            echo "<li>✅ Alle 4 Kategorien pro Tag importieren</li>";
            echo "<li>✅ Deutsche Datum-Konvertierung (dd.mm.yyyy → yyyy-mm-dd)</li>";
            echo "<li>✅ Validierung der Pflichtfelder</li>";
            echo "</ul>";
            
            echo "</div>";
        }
        
        $this->debug("=== AnalyzeDailySummaryStructure COMPLETE ===");
        return true;
    }
    
    public function importDailySummaryToDb($dailySummaryData, $hutId = 675) {
        $this->debug("=== ImportDailySummaryToDb START ===");
        
        if (!is_array($dailySummaryData) || empty($dailySummaryData)) {
            $this->debugError("Keine gültigen DailySummary-Daten zum Import");
            return false;
        }
        
        $this->debug("💾 Importiere " . count($dailySummaryData) . " Tage für HutId: $hutId");
        
        // Datenbankverbindung
        require_once __DIR__ . '/config.php';
        
        // Create fresh connection if global mysqli not available
        if (!isset($GLOBALS['mysqli']) || !$GLOBALS['mysqli']) {
            $this->debug("⚠️ Global mysqli not available, creating fresh connection...");
            $mysqli = new mysqli($GLOBALS['dbHost'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], $GLOBALS['dbName']);
            if ($mysqli->connect_error) {
                $this->debugError("Fresh MySQL Connection Error: " . $mysqli->connect_error);
                return false;
            }
            $mysqli->set_charset('utf8mb4');
        } else {
            $mysqli = $GLOBALS['mysqli'];
            $this->debug("✅ Using global mysqli connection");
        }
        
        if ($mysqli->connect_error) {
            $this->debugError("MySQL Connection Error: " . $mysqli->connect_error);
            return false;
        }
        
        $this->debugSuccess("Datenbankverbindung erfolgreich hergestellt");
        
        $importCount = 0;
        $errorCount = 0;
        
        // Auto-commit ausschalten für Transaktionen
        $mysqli->autocommit(false);
        
        foreach ($dailySummaryData as $dayData) {
            if ($this->importSingleDailySummary($mysqli, $dayData, $hutId)) {
                $importCount++;
            } else {
                $errorCount++;
            }
        }
        
        // Commit der Transaktion
        if ($errorCount == 0) {
            $mysqli->commit();
            $this->debugSuccess("Alle Daten erfolgreich committed");
        } else {
            $mysqli->rollback();
            $this->debugError("Rollback durchgeführt aufgrund von Fehlern");
        }
        
        // Auto-commit wieder einschalten
        $mysqli->autocommit(true);
        
        $this->debugSuccess("DailySummary-Import abgeschlossen: $importCount erfolgreich, $errorCount Fehler");
        $this->debug("=== ImportDailySummaryToDb COMPLETE ===");
        
        return array(
            'imported' => $importCount,
            'errors' => $errorCount,
            'total' => count($dailySummaryData)
        );
    }
    
    private function importSingleDailySummary($mysqli, $dayData, $hutId) {
        try {
            // Basis-Daten extrahieren
            $day = $this->convertDateToMysqlDate($dayData['day'] ?? '');
            if (!$day) {
                $this->debugError("Ungültiges Datum: " . ($dayData['day'] ?? 'N/A'));
                return false;
            }
            
            $dayOfWeek = $dayData['dayOfWeek'] ?? '';
            $hutMode = $dayData['hutMode'] ?? 'SERVICED';
            $numberOfArrivingGuests = intval($dayData['numberOfArrivingGuests'] ?? 0);
            $totalGuests = intval($dayData['totalGuests'] ?? 0);
            
            // DTO-Daten extrahieren
            $halfBoardsValue = intval($dayData['halfBoardsDTO']['value'] ?? 0);
            $halfBoardsActive = ($dayData['halfBoardsDTO']['isActive'] ?? false) ? 1 : 0;
            
            $vegetariansValue = intval($dayData['vegetariansDTO']['value'] ?? 0);
            $vegetariansActive = ($dayData['vegetariansDTO']['isActive'] ?? false) ? 1 : 0;
            
            $childrenValue = intval($dayData['childrenDTO']['value'] ?? 0);
            $childrenActive = ($dayData['childrenDTO']['isActive'] ?? false) ? 1 : 0;
            
            $mountainGuidesValue = intval($dayData['mountainGuidesDTO']['value'] ?? 0);
            $mountainGuidesActive = ($dayData['mountainGuidesDTO']['isActive'] ?? false) ? 1 : 0;
            
            $waitingListValue = intval($dayData['waitingListDTO']['value'] ?? 0);
            $waitingListActive = ($dayData['waitingListDTO']['isActive'] ?? false) ? 1 : 0;
            
            $this->debug("📅 Import Tag: $day ($dayOfWeek) - Gäste: $totalGuests, Ankunft: $numberOfArrivingGuests");
            
            // REPLACE INTO für daily_summary
            $sql = "REPLACE INTO daily_summary (
                hut_id, day, day_of_week, hut_mode, number_of_arriving_guests, total_guests,
                half_boards_value, half_boards_is_active,
                vegetarians_value, vegetarians_is_active,
                children_value, children_is_active,
                mountain_guides_value, mountain_guides_is_active,
                waiting_list_value, waiting_list_is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                $this->debugError("SQL Prepare Error für daily_summary: " . $mysqli->error);
                return false;
            }
            
            $stmt->bind_param('isssiiiiiiiiiiii',
                $hutId, $day, $dayOfWeek, $hutMode, $numberOfArrivingGuests, $totalGuests,
                $halfBoardsValue, $halfBoardsActive,
                $vegetariansValue, $vegetariansActive,
                $childrenValue, $childrenActive,
                $mountainGuidesValue, $mountainGuidesActive,
                $waitingListValue, $waitingListActive
            );
            
            if (!$stmt->execute()) {
                $this->debugError("SQL Execute Error für daily_summary: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
            // daily_summary_id holen
            $dailySummaryId = $mysqli->insert_id;
            if ($dailySummaryId == 0) {
                // Bei REPLACE kann insert_id 0 sein, dann per SELECT holen
                $selectSql = "SELECT id FROM daily_summary WHERE hut_id = ? AND day = ?";
                $selectStmt = $mysqli->prepare($selectSql);
                $selectStmt->bind_param('is', $hutId, $day);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                $row = $result->fetch_assoc();
                $dailySummaryId = $row['id'];
                $selectStmt->close();
            }
            
            $stmt->close();
            
            // Bestehende Kategorien für diesen Tag löschen
            $deleteSql = "DELETE FROM daily_summary_categories WHERE daily_summary_id = ?";
            $deleteStmt = $mysqli->prepare($deleteSql);
            $deleteStmt->bind_param('i', $dailySummaryId);
            $deleteStmt->execute();
            $deleteStmt->close();
            
            // Kategorien importieren
            if (isset($dayData['freePlacesPerCategories']) && is_array($dayData['freePlacesPerCategories'])) {
                foreach ($dayData['freePlacesPerCategories'] as $categoryData) {
                    if (!$this->importSingleCategory($mysqli, $dailySummaryId, $categoryData)) {
                        $this->debugError("Fehler beim Import einer Kategorie für Tag $day");
                        return false;
                    }
                }
            }
            
            $this->debug("✅ Tag $day erfolgreich importiert (ID: $dailySummaryId)");
            return true;
            
        } catch (Exception $e) {
            $this->debugError("Exception beim Import von Tag: " . $e->getMessage());
            return false;
        }
    }
    
    private function importSingleCategory($mysqli, $dailySummaryId, $categoryData) {
        try {
            // Kategorie-Typ aus deutschen Labels ermitteln
            $categoryType = $this->getCategoryTypeFromLabels($categoryData['categoryData'] ?? []);
            if (!$categoryType) {
                $this->debugError("Unbekannter Kategorie-Typ");
                return false;
            }
            
            $isWinteraum = ($categoryData['isWinteraum'] ?? false) ? 1 : 0;
            $freePlaces = intval($categoryData['freePlaces'] ?? 0);
            $assignedGuests = intval($categoryData['assignedGuests'] ?? 0);
            $occupancyLevel = floatval($categoryData['occupancyLevel'] ?? 0.0);
            
            $this->debug("  🛏️ Kategorie $categoryType: FreePlaces=$freePlaces, AssignedGuests=$assignedGuests, Occupancy=$occupancyLevel%");
            
            $sql = "INSERT INTO daily_summary_categories (
                daily_summary_id, category_type, is_winteraum, free_places, assigned_guests, occupancy_level
            ) VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                $this->debugError("SQL Prepare Error für category: " . $mysqli->error);
                return false;
            }
            
            $stmt->bind_param('isiiii',
                $dailySummaryId, $categoryType, $isWinteraum, $freePlaces, $assignedGuests, $occupancyLevel
            );
            
            if (!$stmt->execute()) {
                $this->debugError("SQL Execute Error für category: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
            $stmt->close();
            return true;
            
        } catch (Exception $e) {
            $this->debugError("Exception beim Import von Kategorie: " . $e->getMessage());
            return false;
        }
    }
    
    private function getCategoryTypeFromLabels($categoryData) {
        // Deutsche Labels zu Kategorie-Typen mapping
        $labelMap = array(
            'Matratzenlager' => 'ML',
            'ML' => 'ML',
            'Mehrbettzimmer' => 'MBZ', 
            'MBZ' => 'MBZ',
            'Zweierzimmer' => '2BZ',
            '2BZ' => '2BZ',
            'Sonderkategorie' => 'SK',
            'SK' => 'SK'
        );
        
        foreach ($categoryData as $langData) {
            if ($langData['language'] == 'DE_DE') {
                $label = $langData['label'] ?? '';
                $shortLabel = $langData['shortLabel'] ?? '';
                
                // Erst shortLabel prüfen, dann label
                if (isset($labelMap[$shortLabel])) {
                    return $labelMap[$shortLabel];
                }
                if (isset($labelMap[$label])) {
                    return $labelMap[$label];
                }
            }
        }
        
        return null; // Unbekannter Typ
    }
    
    private function convertDateToMysqlDate($dateStr) {
        // Konvertiert "19.08.2025" zu "2025-08-19" (nur Datum, keine Zeit)
        if (empty($dateStr)) return null;
        
        $parts = explode('.', $dateStr);
        if (count($parts) == 3) {
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
        return null;
    }
    
    public function importReservationsToDb($jsonData) {
        $this->debug("=== ImportReservationsToDb START ===");
        
        // JSON dekodieren
        $data = json_decode($jsonData, true);
        if (!$data) {
            $this->debugError("JSON konnte nicht dekodiert werden");
            return false;
        }
        
        // Reservierungen aus _embedded extrahieren
        if (!isset($data['_embedded']['reservationsDataModelDTOList'])) {
            $this->debugError("Keine Reservierungen im JSON gefunden");
            $this->debug("🔍 Verfügbare Keys in _embedded: " . implode(', ', array_keys($data['_embedded'] ?? [])));
            return false;
        }
        
        $reservations = $data['_embedded']['reservationsDataModelDTOList'];
        $this->debug("📊 " . count($reservations) . " Reservierungen zum Import gefunden");
        
        // Datenbankverbindung (config.php einbinden)
        require_once __DIR__ . '/config.php';
        
        $this->debug("🔍 Checking database connection...");
        
        // Create fresh connection if global mysqli not available
        if (!isset($GLOBALS['mysqli']) || !$GLOBALS['mysqli']) {
            $this->debug("⚠️ Global mysqli not available, creating fresh connection...");
            $mysqli = new mysqli($GLOBALS['dbHost'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], $GLOBALS['dbName']);
            if ($mysqli->connect_error) {
                $this->debugError("Fresh MySQL Connection Error: " . $mysqli->connect_error);
                return false;
            }
            $mysqli->set_charset('utf8mb4');
        } else {
            $mysqli = $GLOBALS['mysqli'];
            $this->debug("✅ Using global mysqli connection");
        }
        
        if ($mysqli->connect_error) {
            $this->debugError("MySQL Connection Error: " . $mysqli->connect_error);
            return false;
        }
        
        $this->debugSuccess("Datenbankverbindung erfolgreich hergestellt");
        
        $importCount = 0;
        $errorCount = 0;
        
        foreach ($reservations as $reservation) {
            if ($this->importSingleReservation($mysqli, $reservation)) {
                $importCount++;
            } else {
                $errorCount++;
            }
        }
        
        $this->debugSuccess("Import abgeschlossen: $importCount erfolgreich, $errorCount Fehler");
        $this->debug("=== ImportReservationsToDb COMPLETE ===");
        
        return array(
            'imported' => $importCount,
            'errors' => $errorCount,
            'total' => count($reservations)
        );
    }
    
    private function importSingleReservation($mysqli, $reservation) {
        try {
            // Basis-Daten extrahieren
            $av_id = null; // Wird aus body['leftList'] extrahiert
            $email = $reservation['guestEmail'] ?? '';
            
            // Header-Daten
            $header = $reservation['header'];
            $anreise = $this->convertDateToMysql($header['arrivalDate'] ?? '');
            $abreise = $this->convertDateToMysql($header['departureDate'] ?? '');
            $hp = ($header['halfPension'] ?? false) ? 1 : 0;
            $vegi = $header['numberOfVegetarians'] ?? 0;
            
            // Kategorien-Zuordnung (lager, betten, dz, sonder)
            $lager = 0;
            $betten = 0; 
            $dz = 0;
            $sonder = 0;
            
            if (isset($header['assignment'])) {
                foreach ($header['assignment'] as $assignment) {
                    $categoryId = $assignment['categoryId'];
                    $bedOccupied = $assignment['bedOccupied'] ?? 0;
                    
                    switch ($categoryId) {
                        case 1958: $lager = $bedOccupied; break;   // ML/DORM
                        case 2293: $betten = $bedOccupied; break;  // MBZ/SB  
                        case 2381: $dz = $bedOccupied; break;      // 2BZ/DR
                        case 6106: $sonder = $bedOccupied; break;  // SK/SC
                    }
                }
            }
            
            // Body-Daten durchsuchen
            $vorname = '';
            $nachname = '';
            $handy = '';
            $gruppe = '';
            $bem_av = '';
            $email_date = null;
            $vorgang = '';
            
            if (isset($reservation['body']['leftList'])) {
                foreach ($reservation['body']['leftList'] as $item) {
                    switch ($item['label']) {
                        case 'configureReservationListPage.reservationNumber':
                            $av_id = $item['value'] ?? null;
                            break;
                        case 'configureReservationListPage.guestName':
                            $vorname = $item['value'] ?? '';
                            $nachname = $item['optionalValue'] ?? '';
                            break;
                        case 'configureReservationListPage.phone':
                            $handy = $item['value'] ?? '';
                            break;
                        case 'configureReservationListPage.groupName':
                            $gruppe = $item['value'] ?? '';
                            break;
                        case 'configureReservationListPage.comments':
                            $bem_av = $item['value'] ?? '';
                            break;
                        case 'configureReservationListPage.reservationDate':
                            $email_date = $this->convertDateTimeToMysql($item['value'] ?? '');
                            break;
                        case 'configureReservationListPage.status':
                            $vorgang = $item['value'] ?? '';
                            break;
                    }
                }
            }
            
            // Validierung: av_id muss vorhanden sein
            if (!$av_id) {
                $this->debugError("Keine Reservierungsnummer (av_id) gefunden für Reservierung");
                return false;
            }
            
            $this->debug("💾 Import Reservierung $av_id: $nachname $vorname ($anreise-$abreise) L:$lager B:$betten D:$dz S:$sonder");
            
            // Zuerst bestehenden Datensatz mit gleicher av_id löschen (falls vorhanden)
            $deleteSql = "DELETE FROM `AV-Res-webImp` WHERE av_id = ?";
            $deleteStmt = $mysqli->prepare($deleteSql);
            $deleteStmt->bind_param('i', $av_id);
            $deleteStmt->execute();
            $deletedRows = $deleteStmt->affected_rows;
            $deleteStmt->close();
            
            if ($deletedRows > 0) {
                $this->debug("🗑️ Bestehender Datensatz mit av_id $av_id gelöscht");
            }
            
            // Neuen Datensatz einfügen
            $sql = "INSERT INTO `AV-Res-webImp` (
                av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi, 
                gruppe, bem_av, nachname, vorname, handy, email, email_date, vorgang, timestamp
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $mysqli->prepare($sql);
            if (!$stmt) {
                $this->debugError("SQL Prepare Error: " . $mysqli->error);
                return false;
            }
            
            $stmt->bind_param('issiiiiiissssssss', 
                $av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder, $hp, $vegi,
                $gruppe, $bem_av, $nachname, $vorname, $handy, $email, $email_date, $vorgang
            );
            
            if ($stmt->execute()) {
                $action = $deletedRows > 0 ? 'überschrieben' : 'eingefügt';
                $this->debug("✅ Reservierung $av_id erfolgreich $action");
                $stmt->close();
                return true;
            } else {
                $this->debugError("SQL Execute Error für $av_id: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
        } catch (Exception $e) {
            $this->debugError("Exception beim Import von Reservierung: " . $e->getMessage());
            return false;
        }
    }
    
    private function convertDateToMysql($dateStr) {
        // Konvertiert "19.04.2025" zu "2025-04-19 00:00:00" (für datetime Felder)
        if (empty($dateStr)) return null;
        
        $parts = explode('.', $dateStr);
        if (count($parts) == 3) {
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0] . ' 00:00:00';
        }
        return null;
    }
    
    private function convertDateTimeToMysql($dateTimeStr) {
        // Konvertiert "16.04.2025 06:51:37" zu "2025-04-16 06:51:37"
        if (empty($dateTimeStr)) return null;
        
        $parts = explode(' ', $dateTimeStr);
        if (count($parts) == 2) {
            // Datum-Teil konvertieren (ohne Zeit hinzuzufügen)
            $dateParts = explode('.', $parts[0]);
            if (count($dateParts) == 3) {
                $mysqlDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                $timePart = $parts[1];
                return "$mysqlDate $timePart";
            }
        }
        return null;
    }
    
    public function testFullWorkflow($dateFrom = '01.08.2024', $dateTo = '01.09.2025', $size = 100, $verbose = true) {
        $this->verbose = $verbose;
        
        $this->debug("🚀 === FULL WORKFLOW TEST START ===");
        $this->debug("📅 Parameter: dateFrom=$dateFrom, dateTo=$dateTo, size=$size, verbose=" . ($verbose ? 'true' : 'false'));
        
        // Datums-Bereich für DailySummary und HutQuota berechnen
        $dailySummaryStart = $this->calculateDailySummaryStart($dateFrom, $dateTo);
        $hutQuotaStart = $this->calculateHutQuotaStart($dateFrom, $dateTo);
        $daysBetween = $this->calculateDaysBetween($dateFrom, $dateTo);
        
        $this->debug("📊 Berechnete Zeiträume:");
        $this->debug("   📅 DailySummary Start: $dailySummaryStart");
        $this->debug("   🏠 HutQuota Start: $hutQuotaStart");
        $this->debug("   📏 Anzahl Tage: $daysBetween");
        
        // 1. Initialize
        if (!$this->initializeAsync()) {
            $this->debugError("Initialize fehlgeschlagen");
            return false;
        }
        
        // 2. Login
        if (!$this->loginAsync()) {
            $this->debugError("Login fehlgeschlagen");
            return false;
        }
        
        // 3. Test API Call (Reservation List)
        $hutId = 675; // Beispiel HutId
        $this->debug("🔍 === RESERVATION LIST IMPORT TEST ===");
        
        $reservationListResult = $this->getReservationListAsync($hutId, '', $dateFrom, $dateTo, 0, $size);
        
        if ($reservationListResult) {
            $this->debugSuccess("Reservierungsliste erfolgreich abgerufen!");
            $this->debug("JSON-Daten erhalten: " . strlen($reservationListResult) . " Zeichen");
            
            // JSON formatiert anzeigen (ersten Teil)
            $reservationData = json_decode($reservationListResult, true);
            if ($reservationData) {
                $this->debug("📊 Reservierungsliste (JSON decoded):");
                if ($this->verbose) {
                    echo "<div style='background: #f8f8f8; padding: 10px; margin: 10px 0; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;'>";
                    echo "<pre>" . htmlspecialchars(json_encode($reservationData, JSON_PRETTY_PRINT)) . "</pre>";
                    echo "</div>";
                }
                
                // Anzahl Reservierungen anzeigen
                if (isset($reservationData['_embedded']['reservationsDataModelDTOList'])) {
                    $count = count($reservationData['_embedded']['reservationsDataModelDTOList']);
                    $this->debug("📈 Gefundene Reservierungen: $count");
                } else {
                    $this->debug("⚠️ Keine Reservierungen im JSON gefunden");
                    $this->debug("🔍 Verfügbare Keys in _embedded: " . implode(', ', array_keys($reservationData['_embedded'] ?? [])));
                }
            }
            
            // 4. Import in Datenbank
            $this->debug("💾 === DATABASE IMPORT TEST ===");
            $importResult = $this->importReservationsToDb($reservationListResult);
            
            if ($importResult) {
                $this->debugSuccess("Datenbank-Import abgeschlossen!");
                $this->debug("📊 Import-Statistik:");
            $this->debug("   ✅ Erfolgreich: " . $importResult['imported']);
            $this->debug("   ❌ Fehler: " . $importResult['errors']);
            $this->debug("   📊 Gesamt: " . $importResult['total']);
            
            if ($this->verbose) {
                echo "<div style='background: #e6ffe6; padding: 10px; margin: 10px 0; border: 1px solid #00aa00; border-radius: 4px;'>";
                echo "<h4>🎉 Import-Ergebnis:</h4>";
                echo "<ul>";
                echo "<li><strong>Erfolgreich importiert:</strong> " . $importResult['imported'] . "</li>";
                echo "<li><strong>Fehler:</strong> " . $importResult['errors'] . "</li>";
                echo "<li><strong>Gesamt verarbeitet:</strong> " . $importResult['total'] . "</li>";
                echo "</ul>";
                echo "</div>";
            }                // 5. DailySummary Test - ANGEPASST für Parameter-Zeitraum
                $this->debug("📅 === DAILY SUMMARY TEST ===");
                $this->debug("🔍 Teste DailySummary-Abruf für Zeitraum $dateFrom bis $dateTo");
                
                $dailySummaryData = $this->getDailySummaryForDateRange($hutId, $dateFrom, $dateTo);
                
                if ($dailySummaryData && is_array($dailySummaryData)) {
                    $this->debugSuccess("DailySummary-Daten erfolgreich abgerufen!");
                    $this->debug("📊 Gesamtanzahl Tage: " . count($dailySummaryData));
                    
                    // Erste paar Tage zur Anzeige
                    if ($this->verbose) {
                        echo "<div style='background: #f0f8ff; padding: 10px; margin: 10px 0; border: 1px solid #4169e1; border-radius: 4px;'>";
                        echo "<h4>📅 DailySummary Beispiel-Daten (erste 3 Tage):</h4>";
                        $sampleDays = array_slice($dailySummaryData, 0, 3);
                        echo "<pre style='max-height: 300px; overflow-y: auto;'>" . htmlspecialchars(json_encode($sampleDays, JSON_PRETTY_PRINT)) . "</pre>";
                        echo "</div>";
                    }
                    
                    // Datenstruktur analysieren
                    $this->analyzeDailySummaryStructure($dailySummaryData);
                    
                    // 6. DailySummary in Datenbank importieren
                    $this->debug("💾 === DAILY SUMMARY DATABASE IMPORT ===");
                    $dailyImportResult = $this->importDailySummaryToDb($dailySummaryData, $hutId);
                    
                    if ($dailyImportResult) {
                        $this->debugSuccess("DailySummary-Datenbank-Import abgeschlossen!");
                        $this->debug("📊 DailySummary Import-Statistik:");
                        $this->debug("   ✅ Erfolgreich: " . $dailyImportResult['imported']);
                        $this->debug("   ❌ Fehler: " . $dailyImportResult['errors']);
                        $this->debug("   📊 Gesamt: " . $dailyImportResult['total']);
                        
                        if ($this->verbose) {
                            echo "<div style='background: #e6ffe6; padding: 10px; margin: 10px 0; border: 1px solid #00aa00; border-radius: 4px;'>";
                            echo "<h4>📅 DailySummary Import-Ergebnis:</h4>";
                            echo "<ul>";
                            echo "<li><strong>Erfolgreich importiert:</strong> " . $dailyImportResult['imported'] . " Tage</li>";
                            echo "<li><strong>Fehler:</strong> " . $dailyImportResult['errors'] . "</li>";
                            echo "<li><strong>Gesamt verarbeitet:</strong> " . $dailyImportResult['total'] . " Tage</li>";
                            echo "<li><strong>Kategorien pro Tag:</strong> ~4 (ML, MBZ, 2BZ, SK)</li>";
                            echo "</ul>";
                            echo "</div>";
                        }
                        
                    } else {
                        $this->debugError("DailySummary-Datenbank-Import fehlgeschlagen");
                    }
                    
                } else {
                    $this->debugError("DailySummary-Daten konnten nicht abgerufen werden");
                }
                
                // 7. HutQuota Test - ANGEPASST für Parameter-Zeitraum
                $this->debug("🏠 === HUT QUOTA TEST ===");
                $this->debug("🔍 Teste HutQuota-Abruf für Zeitraum $dateFrom bis $dateTo");
                
                $hutQuotaData = $this->getHutQuotaForDateRange($hutId, $dateFrom, $dateTo);
                
                if ($hutQuotaData && is_array($hutQuotaData)) {
                    $this->debugSuccess("HutQuota-Daten erfolgreich abgerufen!");
                    $this->debug("📊 Gesamtanzahl Kapazitätsänderungen: " . count($hutQuotaData));
                    
                    // Erste paar Einträge zur Anzeige
                    if ($this->verbose) {
                        echo "<div style='background: #fff3e6; padding: 10px; margin: 10px 0; border: 1px solid #ff8c00; border-radius: 4px;'>";
                        echo "<h4>🏠 HutQuota Beispiel-Daten (erste 3 Einträge):</h4>";
                        $sampleQuotas = array_slice($hutQuotaData, 0, 3);
                        echo "<pre style='max-height: 300px; overflow-y: auto;'>" . htmlspecialchars(json_encode($sampleQuotas, JSON_PRETTY_PRINT)) . "</pre>";
                        echo "</div>";
                    }
                    
                    // Datenstruktur analysieren
                    $this->analyzeHutQuotaStructure($hutQuotaData);
                    
                    // 8. HutQuota in Datenbank importieren
                    $this->debug("💾 === HUT QUOTA DATABASE IMPORT ===");
                    $hutQuotaImportResult = $this->importHutQuotaToDb($hutQuotaData, $hutId);
                    
                    if ($hutQuotaImportResult) {
                        $this->debugSuccess("HutQuota-Datenbank-Import abgeschlossen!");
                        $this->debug("📊 HutQuota Import-Statistik:");
                        $this->debug("   ✅ Erfolgreich: " . $hutQuotaImportResult['imported']);
                        $this->debug("   ❌ Fehler: " . $hutQuotaImportResult['errors']);
                        $this->debug("   📊 Gesamt: " . $hutQuotaImportResult['total']);
                        
                        if ($this->verbose) {
                            echo "<div style='background: #e6ffe6; padding: 10px; margin: 10px 0; border: 1px solid #00aa00; border-radius: 4px;'>";
                            echo "<h4>🏠 HutQuota Import-Ergebnis:</h4>";
                            echo "<ul>";
                            echo "<li><strong>Erfolgreich importiert:</strong> " . $hutQuotaImportResult['imported'] . " Einträge</li>";
                            echo "<li><strong>Fehler:</strong> " . $hutQuotaImportResult['errors'] . "</li>";
                            echo "<li><strong>Gesamt verarbeitet:</strong> " . $hutQuotaImportResult['total'] . " Einträge</li>";
                            echo "<li><strong>Kategorien:</strong> " . (isset($hutQuotaImportResult['categories']) ? $hutQuotaImportResult['categories'] : '0') . "</li>";
                            echo "<li><strong>Sprachen:</strong> " . (isset($hutQuotaImportResult['languages']) ? $hutQuotaImportResult['languages'] : '0') . "</li>";
                            echo "</ul>";
                            echo "</div>";
                        }
                        
                    } else {
                        $this->debugError("HutQuota-Datenbank-Import fehlgeschlagen");
                    }
                    
                } else {
                    $this->debugError("HutQuota-Daten konnten nicht abgerufen werden");
                }
                
                return true;
            } else {
                $this->debugError("Datenbank-Import fehlgeschlagen");
                return false;
            }
            
        } else {
            $this->debugError("Reservierungsliste-Abruf fehlgeschlagen");
            return false;
        }
    }
    
    public function getJsonReport($success, $stats = array()) {
        $report = array(
            'success' => $success,
            'timestamp' => date('Y-m-d H:i:s'),
            'statistics' => $stats,
            'debug_log' => $this->debugOutput
        );
        
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    public function getCurrentCookies() {
        return $this->cookies;
    }
    
    public function getCurrentCsrfToken() {
        return $this->csrfToken;
    }
    
    // Neue Hilfsfunktionen für Datumsberechnung
    private function calculateDaysBetween($dateFrom, $dateTo) {
        $from = DateTime::createFromFormat('d.m.Y', $dateFrom);
        $to = DateTime::createFromFormat('d.m.Y', $dateTo);
        
        if (!$from || !$to) {
            return 60; // Fallback: 60 Tage
        }
        
        $interval = $from->diff($to);
        return $interval->days;
    }

    private function calculateDailySummaryStart($dateFrom, $dateTo) {
        // DailySummary startet 1 Tag vor dem Anfangsdatum
        $from = DateTime::createFromFormat('d.m.Y', $dateFrom);
        if (!$from) {
            return date('d.m.Y'); // Fallback: heute
        }
        
        $from->modify('-1 day');
        return $from->format('d.m.Y');
    }

    private function calculateHutQuotaStart($dateFrom, $dateTo) {
        // HutQuota startet am Anfangsdatum
        return $dateFrom;
    }

    // Neue Funktion für DailySummary mit Datumsbereich
    public function getDailySummaryForDateRange($hutId, $dateFrom, $dateTo) {
        $this->debug("=== GetDailySummaryForDateRange START ===");
        $this->debug("HutId: $hutId, DateFrom: $dateFrom, DateTo: $dateTo");
        
        $allData = array();
        $currentDate = DateTime::createFromFormat('d.m.Y', $dateFrom);
        $endDate = DateTime::createFromFormat('d.m.Y', $dateTo);
        
        if (!$currentDate || !$endDate) {
            $this->debugError("Ungültige Datumswerte: $dateFrom oder $dateTo");
            return false;
        }
        
        // Ein Tag zurück für das "datum-1" Requirement
        $currentDate->modify('-1 day');
        
        $sequenceCount = 0;
        $maxSequences = 20; // Sicherheitsgrenze
        
        while ($currentDate <= $endDate && $sequenceCount < $maxSequences) {
            $dateStr = $currentDate->format('d.m.Y');
            $this->debug("📅 Sequenz " . ($sequenceCount + 1) . ": Abrufen ab $dateStr");
            
            $summaryData = $this->getDailySummaryAsync($hutId, $dateStr);
            
            if ($summaryData) {
                $decoded = json_decode($summaryData, true);
                if ($decoded && is_array($decoded)) {
                    $this->debug("✅ Sequenz " . ($sequenceCount + 1) . ": " . count($decoded) . " Tage erhalten");
                    
                    // Nur Daten im gewünschten Zeitraum behalten
                    foreach ($decoded as $dayData) {
                        if (isset($dayData['day'])) {
                            $dayDate = DateTime::createFromFormat('d.m.Y', $dayData['day']);
                            $fromDate = DateTime::createFromFormat('d.m.Y', $dateFrom);
                            if ($dayDate && $fromDate && $dayDate >= $fromDate && $dayDate <= $endDate) {
                                $allData[] = $dayData;
                            }
                        }
                    }
                } else {
                    $this->debugError("Fehler beim Dekodieren der JSON-Daten für Sequenz " . ($sequenceCount + 1));
                }
            } else {
                $this->debugError("Fehler beim Abrufen der Daten für Sequenz " . ($sequenceCount + 1));
            }
            
            // 10 Tage weiter für die nächste Sequenz
            $currentDate->modify('+10 days');
            $sequenceCount++;
            
            // Kurze Pause zwischen den Requests
            sleep(1);
        }
        
        $this->debugSuccess("DailySummary-Bereich abgeschlossen: " . count($allData) . " Tage im Zeitraum");
        $this->debug("=== GetDailySummaryForDateRange COMPLETE ===");
        
        return $allData;
    }

    // Neue Funktion für HutQuota mit Datumsbereich
    public function getHutQuotaForDateRange($hutId, $dateFrom, $dateTo) {
        $this->debug("=== GetHutQuotaForDateRange START ===");
        $this->debug("HutId: $hutId, DateFrom: $dateFrom, DateTo: $dateTo");
        
        $allData = array();
        $page = 0;
        $hasMorePages = true;
        $maxPages = 10; // Sicherheitsgrenze
        
        while ($hasMorePages && $page < $maxPages) {
            $this->debug("📅 Page $page: Abrufen von $dateFrom bis $dateTo");
            
            $quotaData = $this->getHutQuotaAsync($hutId, $dateFrom, $dateTo, $page, 20);
            
            if ($quotaData) {
                $decoded = json_decode($quotaData, true);
                if ($decoded && isset($decoded['_embedded']['bedCapacityChangeResponseDTOList'])) {
                    $items = $decoded['_embedded']['bedCapacityChangeResponseDTOList'];
                    $this->debug("✅ Page $page: " . count($items) . " Kapazitätsänderungen erhalten");
                    $allData = array_merge($allData, $items);
                    
                    // Prüfe ob es weitere Seiten gibt
                    $pageInfo = $decoded['page'] ?? array();
                    $totalPages = $pageInfo['totalPages'] ?? 1;
                    $currentPageNum = $pageInfo['number'] ?? 0;
                    
                    if ($currentPageNum >= $totalPages - 1) {
                        $hasMorePages = false;
                        $this->debug("📄 Letzte Seite erreicht (Page $currentPageNum von $totalPages)");
                    } else {
                        $page++;
                    }
                } else {
                    $this->debugError("Fehler beim Dekodieren der JSON-Daten für Page $page");
                    $hasMorePages = false;
                }
            } else {
                $this->debugError("Fehler beim Abrufen der Daten für Page $page");
                $hasMorePages = false;
            }
            
            // Kurze Pause zwischen den Requests
            if ($hasMorePages) {
                sleep(1);
            }
        }
        
        $this->debugSuccess("HutQuota-Bereich abgeschlossen: " . count($allData) . " Kapazitätsänderungen gesamt");
        $this->debug("=== GetHutQuotaForDateRange COMPLETE ===");
        
        return $allData;
    }
}

// Output Buffering aktivieren für sofortige Ausgabe
ob_start();
flush();

// CLI Parameter verarbeiten
$dateFrom = isset($argv[1]) ? $argv[1] : (isset($_GET['dateFrom']) ? $_GET['dateFrom'] : '01.08.2024');
$dateTo = isset($argv[2]) ? $argv[2] : (isset($_GET['dateTo']) ? $_GET['dateTo'] : '01.09.2025');
$size = isset($argv[3]) ? intval($argv[3]) : (isset($_GET['size']) ? intval($_GET['size']) : 100);
$verbose = isset($argv[4]) ? (strtolower($argv[4]) === 'true') : (isset($_GET['verbose']) ? (strtolower($_GET['verbose']) === 'true') : true);

// HTML-Ausgabe nur wenn verbose=true
if ($verbose) {
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HRS Login Debug Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #fafafa; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #007cba; color: white; padding: 15px; margin: -20px -20px 20px -20px; border-radius: 8px 8px 0 0; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px; color: #856404; }
        .status { font-weight: bold; margin: 10px 0; }
        .debug-output { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; max-height: 600px; overflow-y: auto; }
        .final-result { margin: 20px 0; padding: 15px; border-radius: 4px; font-weight: bold; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🔐 HRS Login Debug Test</h1>
        <p>PHP-Implementation basierend auf VB.NET HRSPlaywrightLoginTwoStep</p>
    </div>
    
    <div class="warning">
        <strong>⚠️ WICHTIG:</strong> Vergiss nicht, Username und Passwort in der PHP-Datei zu setzen!<br>
        Zeile ~17-18: <code>$username</code> und <code>$password</code>
    </div>
    
    <div class="status">🔄 Test läuft...</div>
    <div class="debug-output">
<?php
// Output Buffering aktivieren für sofortige Ausgabe
ob_start();
flush();

// CLI Parameter verarbeiten
$dateFrom = isset($argv[1]) ? $argv[1] : (isset($_GET['dateFrom']) ? $_GET['dateFrom'] : '01.08.2024');
$dateTo = isset($argv[2]) ? $argv[2] : (isset($_GET['dateTo']) ? $_GET['dateTo'] : '01.09.2025');
$size = isset($argv[3]) ? intval($argv[3]) : (isset($_GET['size']) ? intval($_GET['size']) : 100);
$verbose = isset($argv[4]) ? (strtolower($argv[4]) === 'true') : (isset($_GET['verbose']) ? (strtolower($_GET['verbose']) === 'true') : true);

// Test ausführen
$hrs = new HRSLoginDebug();

if ($verbose) {
    // Debug: Parameter anzeigen
    echo "<div style='background: #e6f3ff; padding: 10px; margin: 10px 0; border: 1px solid #007cba; border-radius: 4px;'>";
    echo "<h4>📋 Aktuelle Parameter:</h4>";
    echo "<ul>";
    echo "<li><strong>dateFrom:</strong> " . htmlspecialchars($dateFrom) . "</li>";
    echo "<li><strong>dateTo:</strong> " . htmlspecialchars($dateTo) . "</li>";
    echo "<li><strong>size:</strong> " . htmlspecialchars($size) . "</li>";
    echo "<li><strong>verbose:</strong> " . ($verbose ? 'true' : 'false') . "</li>";
    echo "</ul>";
    echo "<p><small>💡 <strong>CLI Usage:</strong> <code>php hrs_login_debug.php [dateFrom] [dateTo] [size] [verbose]</code><br>";
    echo "💡 <strong>URL Usage:</strong> <code>hrs_login_debug.php?dateFrom=01.08.2024&dateTo=01.09.2025&size=100&verbose=true</code></small></p>";
    echo "</div>";
}

$success = $hrs->testFullWorkflow($dateFrom, $dateTo, $size, $verbose);

if ($verbose) {
    // Finale Ausgabe
    echo "</div>";

    if ($success) {
        echo '<div class="final-result success">✅ ERFOLG: Vollständiger Workflow erfolgreich abgeschlossen!</div>';
        
        echo '<h3>📊 Finale Status-Informationen:</h3>';
        echo '<ul>';
        echo '<li><strong>CSRF-Token:</strong> ' . htmlspecialchars(substr($hrs->getCurrentCsrfToken(), 0, 30)) . '...</li>';
        echo '<li><strong>Cookies:</strong> ' . count($hrs->getCurrentCookies()) . ' gesetzt</li>';
        
        $cookies = $hrs->getCurrentCookies();
        foreach ($cookies as $name => $value) {
            echo '<li>🍪 <code>' . htmlspecialchars($name) . '</code>: ' . htmlspecialchars(substr($value, 0, 30)) . '...</li>';
        }
        echo '</ul>';
        
    } else {
        echo '<div class="final-result error">❌ FEHLER: Workflow fehlgeschlagen!</div>';
    }
    
    echo '<h3>📝 Nächste Schritte:</h3>';
    echo '<ul>';
    echo '    <li>✅ Login-Mechanismus implementiert</li>';
    echo '    <li>✅ CSRF-Token-Handling</li>';
    echo '    <li>✅ Cookie-Management</li>';
    echo '    <li>✅ Quota-API-Aufruf</li>';
    echo '    <li>🔄 Bei Bedarf weitere API-Endpoints hinzufügen</li>';
    echo '</ul>';
    
    echo '</div>';
    echo '</body>';
    echo '</html>';
} else {
    // JSON-Output für non-verbose Mode
    $stats = array(
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'size' => $size,
        'verbose' => $verbose
    );
    
    header('Content-Type: application/json');
    echo $hrs->getJsonReport($success, $stats);
}

ob_end_flush();
?>
