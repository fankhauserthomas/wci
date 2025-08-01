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
        
        // Sofortige Browser-Ausgabe
        echo "<div style='font-family: monospace; font-size: 12px; margin: 2px 0; padding: 3px; background: #f0f0f0; border-left: 3px solid #007cba;'>";
        echo htmlspecialchars("[$timestamp] $message");
        echo "</div>\n";
        flush();
        ob_flush();
    }
    
    private function debugError($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $this->debugOutput[] = "[$timestamp] ❌ ERROR: $message";
        
        echo "<div style='font-family: monospace; font-size: 12px; margin: 2px 0; padding: 3px; background: #ffe6e6; border-left: 3px solid #ff0000; color: #cc0000;'>";
        echo htmlspecialchars("[$timestamp] ❌ ERROR: $message");
        echo "</div>\n";
        flush();
        ob_flush();
    }
    
    private function debugSuccess($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $this->debugOutput[] = "[$timestamp] ✅ SUCCESS: $message";
        
        echo "<div style='font-family: monospace; font-size: 12px; margin: 2px 0; padding: 3px; background: #e6ffe6; border-left: 3px solid #00aa00; color: #006600;'>";
        echo htmlspecialchars("[$timestamp] ✅ SUCCESS: $message");
        echo "</div>\n";
        flush();
        ob_flush();
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
    
    public function testFullWorkflow($dateFrom = '01.08.2024', $dateTo = '01.09.2025', $size = 100) {
        $this->debug("🚀 === FULL WORKFLOW TEST START ===");
        $this->debug("📅 Parameter: dateFrom=$dateFrom, dateTo=$dateTo, size=$size");
        
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
                echo "<div style='background: #f8f8f8; padding: 10px; margin: 10px 0; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;'>";
                echo "<pre>" . htmlspecialchars(json_encode($reservationData, JSON_PRETTY_PRINT)) . "</pre>";
                echo "</div>";
                
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
                
                echo "<div style='background: #e6ffe6; padding: 10px; margin: 10px 0; border: 1px solid #00aa00; border-radius: 4px;'>";
                echo "<h4>🎉 Import-Ergebnis:</h4>";
                echo "<ul>";
                echo "<li><strong>Erfolgreich importiert:</strong> " . $importResult['imported'] . "</li>";
                echo "<li><strong>Fehler:</strong> " . $importResult['errors'] . "</li>";
                echo "<li><strong>Gesamt verarbeitet:</strong> " . $importResult['total'] . "</li>";
                echo "</ul>";
                echo "</div>";
                
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
    
    public function getCurrentCookies() {
        return $this->cookies;
    }
    
    public function getCurrentCsrfToken() {
        return $this->csrfToken;
    }
}

// HTML-Ausgabe starten
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

// Debug: Parameter anzeigen
echo "<div style='background: #e6f3ff; padding: 10px; margin: 10px 0; border: 1px solid #007cba; border-radius: 4px;'>";
echo "<h4>📋 Aktuelle Parameter:</h4>";
echo "<ul>";
echo "<li><strong>dateFrom:</strong> " . htmlspecialchars($dateFrom) . "</li>";
echo "<li><strong>dateTo:</strong> " . htmlspecialchars($dateTo) . "</li>";
echo "<li><strong>size:</strong> " . htmlspecialchars($size) . "</li>";
echo "</ul>";
echo "<p><small>💡 <strong>CLI Usage:</strong> <code>php hrs_login_debug.php [dateFrom] [dateTo] [size]</code><br>";
echo "💡 <strong>URL Usage:</strong> <code>hrs_login_debug.php?dateFrom=01.08.2024&dateTo=01.09.2025&size=100</code></small></p>";
echo "</div>";

// Test ausführen
$hrs = new HRSLoginDebug();
$success = $hrs->testFullWorkflow($dateFrom, $dateTo, $size);

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
    echo '<div class="final-result error">❌ FEHLER: Test fehlgeschlagen - siehe Debug-Ausgabe oben</div>';
}

ob_end_flush();
?>

<h3>📝 Nächste Schritte:</h3>
<ul>
    <li>✅ Login-Mechanismus implementiert</li>
    <li>✅ CSRF-Token-Handling</li>
    <li>✅ Cookie-Management</li>
    <li>✅ Quota-API-Aufruf</li>
    <li>🔄 Bei Bedarf weitere API-Endpoints hinzufügen</li>
</ul>

</div>
</body>
</html>
