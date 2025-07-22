# Zimmerplan (ZP) - Entwicklungsumgebung

## Verschobene Dateien

Die folgenden Dateien wurden erfolgreich in den `zp/` Unterordner kopiert:

### Frontend
- **simple-timeline.html** - Hauptseite der Timeline-Anwendung
- **timeline.css** - Stylesheet für die Timeline
- **timeline.js** - JavaScript-Logik für die Timeline
- **js/http-utils.js** - HTTP-Utilities für stabile Verbindungen

### Backend API
- **getRooms.php** - API-Endpunkt für Zimmer-Daten
- **getZimmerplanData.php** - API-Endpunkt für Zimmerplan-Daten
- **config.php** - Datenbank-Konfiguration

## Zugriff

Die Anwendung ist jetzt unter folgender URL erreichbar:
`http://192.168.15.14:8080/wci/zp/simple-timeline.html`

## Automatisches Laden

**NEU:** Die Anwendung lädt jetzt automatisch beim Seitenaufruf echte Daten für einen erweiterten Zeitraum:
- **Von:** 1 Woche vor heute
- **Bis:** 10 Wochen nach heute
- **Status:** Anzeige des Lade-Status in der Benutzeroberfläche

## Entwicklung

Alle Pfade wurden automatisch angepasst und sind funktionsbereit:
- CSS und JS verwenden relative Pfade
- PHP-APIs verwenden lokale config.php
- Keine manuellen Pfadanpassungen erforderlich
- Automatisches Fallback bei Fehlern

## Nächste Schritte

Sie können jetzt die Entwicklung im `zp/` Ordner fortsetzen, ohne die Hauptanwendung zu beeinträchtigen.
