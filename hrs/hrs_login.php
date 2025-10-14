<?php
/**
 * HRS Login Authentication Module
 * ================================
 * 
 * Standalone wiederverwendbare Authentifizierungs-Klasse für das HRS (Hut Reservation System).
 * Diese Implementierung basiert exakt auf der erfolgreichen hrs_login_debug.php Version und
 * implementiert den kompletten 2-Schritt-Login-Prozess der HRS-API.
 * 
 * ARCHITEKTUR ÜBERSICHT:
 * ----------------------
 * 1. Stateful Cookie-Management für Session-Persistenz
 * 2. CSRF-Token-Handling für API-Security
 * 3. Exakte HTTP-Header-Simulation für Browser-Mimicking
 * 4. Robuste Error-Handling mit detailliertem Debug-Output
 * 
 * AUTHENTIFIZIERUNGS-FLOW:
 * ------------------------
 * Step 1: GET /login               → Initial page load für Session-Setup
 * Step 2: GET /api/v1/csrf         → CSRF-Token für Security abrufen
 * Step 3: POST /api/v1/users/verifyEmail → Email-Validierung mit CSRF
 * Step 4: POST /api/v1/users/login → Final login mit Credentials
 * 
 * KRITISCHE IMPLEMENTIERUNGS-DETAILS:
 * -----------------------------------
 * - CSRF-Token wird zwischen Steps aktualisiert (Cookie-basiert)
 * - Exakte Header-Simulation verhindert Bot-Detection
 * - Cookie-Extraktion für Session-Maintaining zwischen Requests
 * - Content-Type wechselt zwischen JSON und Form-Data je nach Endpoint
 * 
 * VERWENDUNG:
 * -----------
 * require_once 'hrs_login.php';
 * $hrsAuth = new HRSLogin();
 * if ($hrsAuth->login()) {
 *     $response = $hrsAuth->makeRequest('/api/v1/your-endpoint', 'GET');
 *     // Weitere authentifizierte API-Calls...
 * }
 * 
 * SICHERHEITSHINWEISE:
 * -------------------
 * - Credentials sind hardcoded → Nur für interne Systeme verwenden
 * - SSL/TLS wird für alle Requests enforced
 * - Session-Cookies werden automatisch verwaltet
 * 
 * @author  Based on hrs_login_debug.php success implementation
 * @version 2.0 - Modular standalone version
 * @created 2025-08-22
 * @dependencies None (self-contained)
 */

/**
 * HRS Login Authentication Class
 * ==============================
 * 
 * Zentrale Klasse für die Authentifizierung gegen das HRS-API.
 * Implementiert stateful Session-Management mit Cookie- und CSRF-Token-Handling.
 */
class HRSLogin {
    
    /**
     * API Base URL
     * @var string $baseUrl HRS Production API Endpoint
     */
    private $baseUrl = 'https://www.hut-reservation.org';
    
    /**
     * Standard HTTP Headers für Browser-Simulation
     * @var array $defaultHeaders Statische Headers für alle Requests
     */
    private $defaultHeaders;
    
    /**
     * CSRF-Token für API-Security
     * @var string $csrfToken Aktueller X-XSRF-TOKEN für authentifizierte Requests
     */
    private $csrfToken;
    
    /**
     * Session-Cookies Container
     * @var array $cookies Key-Value Pairs aller gesetzten Cookies
     */
    private $cookies = array();
    
    /**
     * HRS Authentication Credentials
     * @var string $username HRS Username (loaded from config)
     * @var string $password HRS Password (loaded from config)
     */
    private $username;
    private $password;
    
    /**
     * Konstruktor - Initialisiert Standard-Headers und Debug-System
     * 
     * IMPLEMENTIERUNGS-DETAILS:
     * - Browser-Headers simulieren Chrome 137 auf Windows 10
     * - Accept-Encoding mit gzip/deflate für Performance
     * - Sec-Fetch-* Headers für moderne Browser-Compliance
     * - User-Agent exakt wie in erfolgreicher hrs_login_debug.php
     */
    public function __construct() {
        // Load credentials from config if available
        $this->username = defined('HRS_USERNAME') ? HRS_USERNAME : 'office@franzsennhuette.at';
        $this->password = defined('HRS_PASSWORD') ? HRS_PASSWORD : 'Fsh2147m!3';
        
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
        
        $this->debug("HRS Login class initialized");
    }
    
    /**
     * Debug-Ausgabe mit Timestamp
     * 
     * @param string $message Debug-Nachricht
     */
    public function debug($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $logMessage = "[$timestamp] $message";
        error_log($logMessage); // Nur error_log, kein echo!
    }
    
    /**
     * Error-Debug mit rotem Marker
     * 
     * @param string $message Error-Nachricht
     */
    public function debugError($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $logMessage = "[$timestamp] ❌ ERROR: $message";
        error_log($logMessage); // Nur error_log, kein echo!
    }
    
    /**
     * Success-Debug mit grünem Marker
     * 
     * @param string $message Success-Nachricht
     */
    public function debugSuccess($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $logMessage = "[$timestamp] ✅ SUCCESS: $message";
        error_log($logMessage); // Nur error_log, kein echo!
    }
    
    /**
     * Universeller HTTP-Request-Handler mit Session-Management
     * 
     * Diese Methode ist das Herzstück der Kommunikation mit der HRS-API.
     * Sie handled automatisch:
     * - Cookie-Management für Session-Persistenz
     * - Header-Zusammenstellung mit Default + Custom Headers
     * - SSL-Verifizierung für Security
     * - Response-Parsing für Header/Body-Separation
     * - Cookie-Extraktion aus Set-Cookie Headers
     * 
     * VERWENDUNG NACH LOGIN:
     * $response = $hrsLogin->makeRequest('/api/v1/reservations', 'GET', null, [
     *     'X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken()
     * ]);
     * 
     * @param string $url        API-Endpoint (relativ zur baseUrl)
     * @param string $method     HTTP-Method (GET, POST, DELETE)
     * @param mixed  $data       Request Body (JSON string oder Form data)
     * @param array  $customHeaders Zusätzliche Headers für den Request
     * 
     * @return array|false Response-Array mit [status, headers, body] oder false bei Fehler
     */
    public function makeRequest($url, $method = 'GET', $data = null, $customHeaders = array()) {
        $fullUrl = $this->baseUrl . $url;
        $this->debug("→ $method $fullUrl");
        
        // cURL Session initialisieren mit Production-Settings
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);     // Return response als string
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);     // Follow redirects automatisch
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);     // SSL-Certificate verification
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);              // 30s timeout für API-Calls
        curl_setopt($ch, CURLOPT_HEADER, true);             // Include headers in response
        curl_setopt($ch, CURLOPT_NOBODY, false);            // Include body in response
        
        // Headers zusammenbauen: Default + Custom + Cookies
        $headers = $this->defaultHeaders;
        if (!empty($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }
        
        // Cookie-Header für Session-Management automatisch hinzufügen
        if (!empty($this->cookies)) {
            $cookieString = '';
            foreach ($this->cookies as $name => $value) {
                $cookieString .= "$name=$value; ";
            }
            $cookieHeader = 'Cookie: ' . rtrim($cookieString, '; ');
            $headers[] = $cookieHeader;
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // HTTP Method konfigurieren mit korrekten Body-Settings
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
            // GET ist default, keine zusätzliche Konfiguration nötig
        }
        
        // Request ausführen und Response verarbeiten
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        // cURL Error-Handling
        if (curl_error($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->debugError("cURL Error: $error");
            return false;
        }
        
        curl_close($ch);
        
        // Response in Header und Body separieren
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Session-Cookies aus Response-Headers extrahieren und speichern
        $this->extractCookies($headerString);
        
        return array(
            'status' => $httpCode,
            'headers' => $headerString,
            'body' => $body
        );
    }
    
    /**
     * Cookie-Extraktion aus HTTP Response Headers
     * 
     * Parsed alle Set-Cookie Headers und speichert die Cookies in $this->cookies
     * für die Verwendung in nachfolgenden Requests. Essentiell für Session-Management.
     * 
     * PARSER-LOGIK:
     * - Sucht nach "Set-Cookie:" Headers (case-insensitive)
     * - Splittet nach Semikolon (Cookie-Attribute ignorieren)
     * - Extrahiert Name=Value Paare
     * - Überschreibt existierende Cookies mit neuen Werten
     * 
     * @param string $headers Complete HTTP Response Headers als String
     */
    private function extractCookies($headers) {
        $lines = explode("\n", $headers);
        foreach ($lines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookie = trim(substr($line, 11));              // "Set-Cookie: " entfernen
                $parts = explode(';', $cookie);                 // Cookie-Attribute abtrennen
                $nameValue = explode('=', $parts[0], 2);        // Name=Value extrahieren
                if (count($nameValue) == 2) {
                    $this->cookies[trim($nameValue[0])] = trim($nameValue[1]);
                }
            }
        }
    }
    
    /**
     * Hauptmethode für HRS-Authentifizierung
     * 
     * Implementiert den kompletten 4-Schritt Login-Prozess der HRS-API:
     * 
     * SCHRITT 1: GET /login
     * - Lädt die Login-Seite für initiale Session-Erstellung
     * - Sammelt erste Session-Cookies
     * 
     * SCHRITT 2: GET /api/v1/csrf  
     * - Holt CSRF-Token für API-Security
     * - Speichert Token für nachfolgende Requests
     * 
     * SCHRITT 3: POST /api/v1/users/verifyEmail
     * - Verifiziert Email-Adresse mit JSON Payload
     * - Verwendet CSRF-Token im X-XSRF-TOKEN Header
     * - Aktualisiert Session-Cookies und CSRF-Token
     * 
     * SCHRITT 4: POST /api/v1/users/login
     * - Final login mit Username/Password
     * - Verwendet Form-Encoding (application/x-www-form-urlencoded)
     * - Verwendet aktualisierte CSRF-Token aus Cookie
     * 
     * KRITISCHE IMPLEMENTIERUNGS-DETAILS:
     * - CSRF-Token wird zwischen Steps aus Cookie aktualisiert
     * - Content-Type wechselt von JSON zu Form-Data
     * - Alle Cookies werden persistent zwischen Requests gehalten
     * - Exakte Header-Simulation verhindert Bot-Detection
     * 
     * @return bool true bei erfolgreichem Login, false bei Fehler
     */
    public function login() {
        $this->debug("=== LoginAsync START ===");
        $this->debug("Username: " . $this->username);
        $this->debug("Password: " . str_repeat('*', strlen($this->password)));
        
        // SCHRITT 1: Login-Seite laden für Session-Initialisierung
        $this->debug("→ SCHRITT 1: Lade Login-Seite für Session-Setup");
        $response = $this->makeRequest('/login');
        if (!$response || $response['status'] != 200) {
            $this->debugError("Login-Seite konnte nicht geladen werden");
            return false;
        }
        $this->debugSuccess("Login-Seite erfolgreich geladen - Session initialisiert");
        
        // SCHRITT 2: CSRF-Token für API-Security abrufen
        $this->debug("→ SCHRITT 2: CSRF-Token abrufen");
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
        
        // CSRF-Token Selection: Cookie hat Priorität über API-Response (wie VB.NET Code)
        $cookieCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $this->csrfToken;
        $this->debug("🔐 Verwende CSRF-Token: " . substr($cookieCsrfToken, 0, 20) . "... (aus " . (isset($this->cookies['XSRF-TOKEN']) ? 'Cookie' : 'API') . ")");
        
        // SCHRITT 3: Email-Verifikation mit JSON Content-Type
        $this->debug("→ SCHRITT 3: Email-Verifikation (verifyEmail)");
        $verifyData = json_encode(array(
            'userEmail' => $this->username,
            'isLogin' => true
        ));
        
        $verifyHeaders = array(
            'Content-Type: application/json',               // JSON für verifyEmail
            'Origin: https://www.hut-reservation.org',
            'X-XSRF-TOKEN: ' . $cookieCsrfToken            // CSRF-Protection
        );
        
        $this->debug("📤 verifyEmail Request Details:");
        foreach ($verifyHeaders as $header) {
            $this->debug("   Header: " . $header);
        }
        $this->debug("   Body: " . $verifyData);
        
        $verifyResponse = $this->makeRequest('/api/v1/users/verifyEmail', 'POST', $verifyData, $verifyHeaders);
        
        if (!$verifyResponse || $verifyResponse['status'] != 200) {
            $this->debugError("verifyEmail fehlgeschlagen - Status: " . ($verifyResponse['status'] ?? 'unknown'));
            $this->debugError("Response Headers: " . substr($verifyResponse['headers'] ?? '', 0, 500));
            $this->debugError("Response Body: " . substr($verifyResponse['body'] ?? '', 0, 500));
            
            // Debug: Cookie-Status bei Fehler anzeigen
            $this->debug("🍪 Cookie-Status beim verifyEmail-Fehler:");
            foreach ($this->cookies as $name => $value) {
                $this->debug("   $name = " . substr($value, 0, 30) . "...");
            }
            
            return false;
        }
        
        $this->debugSuccess("verifyEmail erfolgreich - Email-Adresse verifiziert");
        $this->debug("verifyEmail Response: " . substr($verifyResponse['body'], 0, 200) . "...");
        
        // CSRF-Token Update: Cookie-Version hat Priorität nach verifyEmail
        $updatedCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $cookieCsrfToken;
        $this->debug("🔄 CSRF-Token nach verifyEmail: " . substr($updatedCsrfToken, 0, 20) . "...");
        
        // SCHRITT 4: Final Login mit Credentials in Form-Encoding
        $this->debug("→ SCHRITT 4: Final Login mit Credentials");
        $loginData = 'username=' . urlencode($this->username) . '&password=' . urlencode($this->password);
        
        $loginHeaders = array(
            'Content-Type: application/x-www-form-urlencoded',  // Form-Data für Login
            'Origin: https://www.hut-reservation.org',
            'X-XSRF-TOKEN: ' . $updatedCsrfToken               // Aktualisierter CSRF-Token
        );
        
        $this->debug("📤 Login Request Details:");
        foreach ($loginHeaders as $header) {
            $this->debug("   Header: " . $header);
        }
        $this->debug("   Body: username=" . $this->username . "&password=" . str_repeat('*', strlen($this->password)));
        
        $loginResponse = $this->makeRequest('/api/v1/users/login', 'POST', $loginData, $loginHeaders);
        
        if (!$loginResponse || $loginResponse['status'] != 200) {
            $this->debugError("Final Login fehlgeschlagen - Status: " . ($loginResponse['status'] ?? 'unknown'));
            $this->debugError("Response Headers: " . substr($loginResponse['headers'] ?? '', 0, 500));
            $this->debugError("Response Body: " . substr($loginResponse['body'] ?? '', 0, 500));
            
            // Debug: Cookie-Status bei Login-Fehler
            $this->debug("🍪 Cookie-Status beim Login-Fehler:");
            foreach ($this->cookies as $name => $value) {
                $this->debug("   $name = " . substr($value, 0, 30) . "...");
            }
            
            return false;
        }
        
        $this->debugSuccess("🎉 Final Login erfolgreich - Authentifizierung abgeschlossen!");
        $this->debug("Login Response: " . substr($loginResponse['body'], 0, 200) . "...");
        
        // Session-Status nach erfolgreichem Login
        $this->debug("🍪 Session-Cookies nach erfolgreichem Login:");
        foreach ($this->cookies as $name => $value) {
            $this->debug("   $name = " . substr($value, 0, 30) . "...");
        }
        
        // CSRF-Token für nachfolgende API-Calls finalisieren
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $this->csrfToken = $this->cookies['XSRF-TOKEN'];
            $this->debug("🔄 CSRF-Token für API-Calls finalisiert: " . substr($this->csrfToken, 0, 20) . "...");
        }
        
        $this->debug("=== LoginAsync COMPLETE - Ready for API calls ===");
        return true;
    }
    
    /**
     * Public API Methods für externe Verwendung
     * ==========================================
     */
    
    /**
     * CSRF-Token für authentifizierte API-Calls abrufen
     * 
     * Verwendung in nachfolgenden API-Calls:
     * $headers = ['X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken()];
     * 
     * @return string Aktueller CSRF-Token oder null wenn nicht eingeloggt
     */
    public function getCsrfToken() {
        return $this->csrfToken;
    }
    
    /**
     * Alle Session-Cookies für externe Debugging abrufen
     * 
     * @return array Key-Value Array aller gesetzten Cookies
     */
    public function getCookies() {
        return $this->cookies;
    }
    
    /**
     * Login-Status prüfen
     * 
     * Prüft ob sowohl CSRF-Token als auch Session-Cookies vorhanden sind.
     * Garantiert nicht, dass die Session noch aktiv ist (Server-side check nötig).
     * 
     * @return bool true wenn Login-Daten vorhanden, false sonst
     */
    public function isLoggedIn() {
        return !empty($this->csrfToken) && !empty($this->cookies);
    }
}
?>
