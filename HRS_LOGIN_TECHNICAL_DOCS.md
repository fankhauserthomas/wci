# HRS Login Module - Technical Documentation

## 📋 Überblick

Die `hrs_login.php` ist ein vollständig dokumentiertes, eigenständiges Authentifizierungs-Modul für das HRS (Hut Reservation System). Es implementiert den kompletten Login-Flow der HRS-API mit detailliertem internen Logging und robuster Error-Behandlung.

## 🏗️ Architektur

### Klassen-Design
```
HRSLogin
├── Private Properties
│   ├── $baseUrl           - API-Endpoint
│   ├── $defaultHeaders    - Browser-Simulation Headers
│   ├── $csrfToken        - X-XSRF-TOKEN für API-Security
│   ├── $cookies          - Session-Cookie Storage
│   ├── $username         - HRS Credentials
│   └── $password         - HRS Credentials
│
├── Core Methods
│   ├── __construct()     - Initialisierung + Header-Setup
│   ├── login()           - 4-Schritt-Authentifizierung
│   ├── makeRequest()     - Universeller HTTP-Client
│   └── extractCookies()  - Cookie-Parser für Session-Management
│
├── Debug Methods
│   ├── debug()           - Standard Debug-Output
│   ├── debugError()      - Error-Ausgabe mit ❌
│   └── debugSuccess()    - Success-Ausgabe mit ✅
│
└── Public API
    ├── getCsrfToken()    - CSRF-Token für externe Calls
    ├── getCookies()      - Cookie-Array für Debugging
    └── isLoggedIn()      - Login-Status Check
```

## 🔄 Login-Flow Dokumentation

### Schritt 1: Session-Initialisierung
```http
GET /login HTTP/1.1
Host: www.hut-reservation.org
```
**Zweck:** Initiale Session-Cookies sammeln
**Result:** Session-ID, erste Cookies

### Schritt 2: CSRF-Token Abruf
```http
GET /api/v1/csrf HTTP/1.1
Host: www.hut-reservation.org
Cookie: [Session-Cookies]
```
**Zweck:** Security-Token für API-Calls
**Result:** JSON Response mit CSRF-Token

### Schritt 3: Email-Verifikation
```http
POST /api/v1/users/verifyEmail HTTP/1.1
Content-Type: application/json
X-XSRF-TOKEN: [CSRF-Token]
Cookie: [Session-Cookies]

{"userEmail":"office@franzsennhuette.at","isLogin":true}
```
**Zweck:** Email-Adresse validieren
**Result:** Verification Success + Updated Cookies

### Schritt 4: Final Login
```http
POST /api/v1/users/login HTTP/1.1
Content-Type: application/x-www-form-urlencoded
X-XSRF-TOKEN: [Updated-CSRF-Token]
Cookie: [Updated-Session-Cookies]

username=office%40franzsennhuette.at&password=Fsh2147m%213
```
**Zweck:** Credentials-basierte Authentifizierung
**Result:** Authenticated Session + Final Cookies

## 🔧 Kritische Implementierungs-Details

### CSRF-Token Management
- **Initial:** API Response `/api/v1/csrf`
- **Update 1:** Cookie `XSRF-TOKEN` nach verifyEmail
- **Update 2:** Cookie `XSRF-TOKEN` nach login
- **Usage:** Header `X-XSRF-TOKEN` für alle API-Calls

### Cookie-Handling
```php
// Automatische Cookie-Extraktion
private function extractCookies($headers) {
    // Parsed Set-Cookie Headers
    // Speichert in $this->cookies Array
    // Automatisch in nachfolgenden Requests verwendet
}
```

### Content-Type Switching
- **verifyEmail:** `application/json` (JSON Payload)
- **login:** `application/x-www-form-urlencoded` (Form Data)

### Browser-Simulation Headers
```php
private $defaultHeaders = array(
    'Accept: application/json, text/plain, */*',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)...',
    'Sec-Ch-Ua: "Google Chrome";v="137"...',
    // Vollständige Browser-Mimicking
);
```

## 📊 Debug-Output Format

```
[13:00:48.266] HRS Login class initialized
[13:00:48.266] === LoginAsync START ===
[13:00:48.496] ✅ SUCCESS: Login-Seite erfolgreich geladen
[13:00:48.977] 🔐 Verwende CSRF-Token: ad20c16c-5122... (aus Cookie)
[13:00:49.566] 📤 verifyEmail Request Details:
[13:00:49.566]    Header: Content-Type: application/json
[13:00:49.987] ✅ SUCCESS: 🎉 Final Login erfolgreich
[13:00:49.987] 🍪 Session-Cookies nach erfolgreichem Login:
[13:00:49.987] === LoginAsync COMPLETE - Ready for API calls ===
```

## 🚀 Usage Examples

### Basic Authentication
```php
require_once 'hrs_login.php';

$hrsAuth = new HRSLogin();
if ($hrsAuth->login()) {
    echo "✅ Erfolgreich eingeloggt!";
} else {
    echo "❌ Login fehlgeschlagen!";
}
```

### API Calls nach Login
```php
$hrsAuth = new HRSLogin();
if ($hrsAuth->login()) {
    $headers = ['X-XSRF-TOKEN: ' . $hrsAuth->getCsrfToken()];
    $response = $hrsAuth->makeRequest('/api/v1/reservations', 'GET', null, $headers);
    
    if ($response && $response['status'] == 200) {
        $data = json_decode($response['body'], true);
        // Process reservation data...
    }
}
```

### Status-Checking
```php
$hrsAuth = new HRSLogin();
$hrsAuth->login();

echo "Login-Status: " . ($hrsAuth->isLoggedIn() ? 'OK' : 'Failed') . "\n";
echo "CSRF-Token: " . substr($hrsAuth->getCsrfToken(), 0, 20) . "...\n";
echo "Cookies: " . count($hrsAuth->getCookies()) . " gesetzt\n";
```

## ⚠️ Security & Deployment Notes

### Production Considerations
- **Credentials:** Hardcoded → Nur für interne Systeme
- **SSL:** Alle Requests über HTTPS mit Certificate Verification
- **Timeouts:** 30s Request-Timeout für Stability
- **Error-Handling:** Comprehensive logging für Debugging

### Error-Scenarios
- **Network Issues:** cURL Errors mit detailliertem Output
- **HTTP Errors:** Status Code != 200 mit Response-Details
- **API Changes:** JSON-Parser mit Fallback-Handling
- **Session Expiry:** Cookie-Loss Detection

## 📈 Performance Characteristics

- **Login-Zeit:** ~1.7 Sekunden (4 HTTP Requests)
- **Memory-Usage:** Minimal (nur Cookie + Token Storage)
- **Dependencies:** Nur cURL (Standard PHP Extension)
- **Retry-Logic:** Keine (Fail-Fast für Debugging)

## 🔄 Integration Patterns

### Singleton Pattern (empfohlen)
```php
class HRSAuthManager {
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new HRSLogin();
            self::$instance->login();
        }
        return self::$instance;
    }
}
```

### Dependency Injection (wie in hrs_imp_res.php)
```php
class SomeHRSService {
    private $hrsAuth;
    
    public function __construct(HRSLogin $auth) {
        $this->hrsAuth = $auth;
    }
}
```

---

**Status:** Production Ready ✅  
**Last Update:** 2025-08-22  
**Dependencies:** PHP 7.0+, cURL Extension
