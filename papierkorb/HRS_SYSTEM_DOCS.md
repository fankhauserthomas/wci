# HRS Import System - Dokumentation

## Überblick

Das neue modulare HRS Import System besteht aus zwei Hauptkomponenten:

### 1. hrs_login.php
**Standalone HRS-Authentifizierung Module**
- ✅ Vollständig eigenständige `HRSLogin` Klasse
- ✅ Komplette Cookie- und CSRF-Token-Verwaltung
- ✅ Wiederverwendbar für alle HRS-API-Projekte

**Methoden:**
- `login()` - Führt kompletten Login-Prozess durch
- `makeRequest($url, $method, $data, $headers)` - Authentifizierte API-Calls
- `getCsrfToken()` - Aktueller CSRF-Token
- `isLoggedIn()` - Login-Status prüfen
- `getCookies()` - Aktuelle Cookie-Collection

### 2. hrs_imp_res.php
**Reservierungs-Import System**
- ✅ Verwendet `hrs_login.php` via Dependency Injection
- ✅ CLI-Interface: `php hrs_imp_res.php dateFrom dateTo`
- ✅ Sichere Datenbankoperationen mit DELETE+INSERT
- ✅ Vollständige Datenvalidierung und -mapping

## Verwendung

### Für Reservierungs-Import:
```bash
php hrs_imp_res.php 2024-01-01 2024-01-31
```

### Für andere HRS-API-Projekte:
```php
require_once 'hrs_login.php';

$hrsLogin = new HRSLogin();
if ($hrsLogin->login()) {
    $response = $hrsLogin->makeRequest('/api/v1/your-endpoint', 'GET');
    // Weitere API-Calls...
}
```

## Migration abgeschlossen

- ❌ `hrs_import_system.php` - GELÖSCHT (verursachte DB-Integritätsprobleme)
- ✅ `hrs_login.php` - NEU (modulares Authentifizierungs-System)
- ✅ `hrs_imp_res.php` - NEU (sauberes Import-System)
- ✅ `test_hrs_login.php` - Beispiel für eigenständige Nutzung

**Status:** Production Ready 🚀
