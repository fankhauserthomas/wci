# ZP Folder URL Centralization - Portierung Guide

## Übersicht
Alle hardcodierten URLs und IP-Adressen im `/zp` Ordner wurden zentralisiert und sind jetzt über die `config.php` konfigurierbar. Dies ermöglicht einfache Portierung auf andere Hosts.

## Geänderte Dateien

### Konfigurationssystem erweitert

#### `/home/vadmin/lemp/html/wci/config.php`
- **Erweitert um**: Alle URL-Parameter, API-Endpunkte, Pfade
- **Neue Konstanten**: 
  - `BASE_URL`, `WCI_PATH`, `ZP_PATH`, `RESERVATIONS_PATH`, `PIC_PATH`, `HRS_PATH`, `API_PATH`
  - `TIMELINE_ENDPOINTS` Array mit allen API-Endpunkten
  - Vollständige URL-Konstanten für absolute Referenzen

#### `/home/vadmin/lemp/html/wci/zp/getConfig.php`
- **Erweitert um**: Neue URL-Struktur, Pfade, und vollständige Endpunkt-Konfiguration
- **Liefert**: Zentrale Konfiguration an JavaScript-Clients

#### `/home/vadmin/lemp/html/wci/zp/wci-config.js`
- **Neue Funktion**: `getEndpoint(endpointName, params)` für URL-Generierung
- **Erweitert um**: Alle verfügbaren API-Endpunkte und Asset-Pfade
- **Fallback-System**: Robuste Behandlung wenn Konfiguration nicht verfügbar ist

### Refactorierte Dateien

#### PHP-Dateien
- **`getZimmerplanData.php`**: Hardcodierte `/wci/pic/dog.svg` → `PIC_PATH . '/dog.svg'`
- **`roomplan.php`**: Relative Pfade zu Bildern durch Konstanten ersetzt
- **Alle anderen PHP-Dateien**: Nutzen bereits `config.php` korrekt

#### JavaScript-Dateien
- **`timeline-unified copy.js`**: 
  - Neue `timelineFetch()` Funktion für konfigurationsabhängige HTTP-Requests
  - Alle `fetch()` Aufrufe durch `timelineFetch()` ersetzt
  - Hardcodierte URLs entfernt
- **`timeline-unified-f2.js`**: 
  - Alle API-Endpunkte nutzen `WCIConfig.getEndpoint()`
  - Bild-URLs verwenden Konfigurationssystem
  - Fallback-Mechanismen für Kompatibilität

#### HTML-Dateien
- **`timeline-unified.html`**: 
  - Alle `wciFetch()` Aufrufe nutzen `WCIConfig.getEndpoint()`
  - EventSource-URLs verwenden Konfigurationspfade
  - Relative `../` Pfade durch Konfiguration ersetzt
- **`quota-input-modal.html`**: 
  - HRS-API Aufrufe nutzen Konfigurationssystem
- **`quota-debug-test.html`**: 
  - Hardcodierte URLs durch konfigurierte URLs ersetzt

## Portierungsanleitung

### 1. Basis-URL ändern
```php
// In /home/vadmin/lemp/html/wci/config.php Zeile ~32-33
define('BASE_URL', 'http://NEUE-IP:PORT');          // Hier anpassen!
define('FALLBACK_BASE_URL', 'http://ANDERE-IP:PORT'); // Optional: Testbare Fallback-URL
```

### 2. Alle anderen Parameter bleiben unverändert
- `WCI_PATH = '/wci'` (normalerweise unverändert)
- Alle anderen Pfade werden automatisch aktualisiert
- Endpunkt-Konfiguration wird automatisch angepasst

### 3. Keine weiteren Änderungen notwendig!
Das gesamte System passt sich automatisch an die neue BASIS-URL an.

## Beispiel: Portierung auf neuen Server

**Alt** (hardcodiert überall):
```
http://192.168.15.14:8080/wci/...
```

**Neu** (nur eine Änderung in config.php):
```php
define('BASE_URL', 'http://10.0.0.100:9080');
```

**Automatisch angepasst werden**:
- Alle JavaScript fetch() Aufrufe
- Alle PHP API-Referenzen  
- Alle HTML EventSource URLs
- Alle Bild-/Asset-Pfade
- Alle HRS Integration-URLs

## Zusätzliche Robustheit

### Fallback-Mechanismen
- JavaScript funktioniert auch wenn `WCIConfig` nicht geladen ist
- Zentrale Fallback-URL in config.php (`FALLBACK_BASE_URL`)
- PHP-Konstanten haben sichere Standardwerte
- Relative Pfade als Backup verfügbar

### Fallback-Testing
- **Test-Tool**: `fallback-test.html` - Live-Monitor für Fallback-Auslösungen
- Überwacht alle `fetch()` Aufrufe und zeigt an, wann Fallbacks verwendet werden
- Simuliert Konfigurationsausfälle für Tests
- Zeigt verwendete URLs in Echtzeit an

### Entwickler-Freundlich
- Konfiguration über Browser-Konsole inspizierbar: `WCIConfig.get()`
- Debug-Informationen in Netzwerk-Tab sichtbar
- Endpunkt-URLs leicht testbar: `WCIConfig.getEndpoint('updateRoomDetail')`

## Migration abgeschlossen ✅

Das System ist jetzt **100% portabel** durch Änderung einer einzigen Zeile in der `config.php`.

### Testen der Migration
1. Werte in `config.php` temporär ändern
2. Browser-Konsole prüfen: `WCIConfig.get('urls.base')`
3. Netzwerk-Tab prüfen: alle Requests sollten neue URLs verwenden
4. Funktionalität testen: Timeline laden, Updates durchführen

**System erfolgreich portierbar gemacht! 🎯**