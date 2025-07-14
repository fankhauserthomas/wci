# WebCheckin - Hotel Reservation Management System

## Übersicht

WebCheckin ist ein Hotel-Reservierungsmanagementsystem für die Franzsenhuette, das Check-In/Check-Out-Prozesse, Zimmerplanung und Gästedatenmanagement vereinfacht.

## Produktionsumgebung

- **Server**: FreeBSD mit Apache/mod_fcgid
- **PHP Version**: 7.x+
- **Datenbank**: MySQL (remote: booking.franzsennhuette.at)
- **Authentifizierung**: Session-basiert mit Password-Schutz

## Hauptfunktionen

- ✅ Dashboard mit Übersicht aller Reservierungen
- ✅ Check-In/Check-Out Management
- ✅ Zimmerplanung und Belegungsübersicht
- ✅ Gästedetails und Arrangementsverwaltung
- ✅ Statistiken und Reporting
- ✅ QR-Code-Generierung für mobile Zugriffe

## Projektstruktur

### Produktionsdateien (Root-Verzeichnis)

- `index.php` - Hauptseite mit Dashboard und Navigation
- `login.html` - Anmeldeseite
- `reservation.html` - Reservierungsübersicht
- `statistiken.html` - Statistiken und Reports
- `zimmerplan-daypilot.html` - Zimmerplanung
- `*Detail.html` - Detailseiten für Gäste und Reservierungen

### API-Endpunkte

- `get*.php` - Datenabfrage-Endpunkte
- `update*.php` - Datenaktualisierungs-Endpunkte
- `toggle*.php` - Status-Toggle-Funktionen

### Assets

- `js/` - JavaScript-Bibliotheken und Utilities
- `libs/` - Externe Bibliotheken (jQuery, QR-Code)
- `pic/` - Icons und Grafiken
- `*.css` - Stylesheets

### Entwicklungsverzeichnisse

- `tests/` - Test-Skripte und Entwicklungsdateien
- `debug/` - Debug-Tools und Diagnoseskripte
- `backups/` - Backup-Dateien und alte Versionen
- `docs/` - Dokumentation und Deployment-Skripte

## Sicherheit

### Authentifizierung

- Passwort-basierte Sessions (Passwort: er1234tf)
- Session-Timeout nach Inaktivität
- Schutz sensibler Endpunkte über `.htaccess`

### Geschützte Verzeichnisse/Dateien

- Alle Konfigurationsdateien (`config*.php`)
- Authentifizierungs-Backend (`auth*.php`)
- Test-Verzeichnis (`tests/`)
- Debug-Verzeichnis (`debug/`)
- Backup-Verzeichnis (`backups/`)

## Deployment

### Voraussetzungen

- PHP 7.x+ mit Session-Unterstützung
- MySQL-Zugang zur Datenbank
- Apache mit .htaccess-Unterstützung

### Installation

1. Dateien in Webserver-Verzeichnis kopieren
2. Datenbankverbindung in `config-simple.php` konfigurieren
3. `.htaccess` Berechtigungen prüfen
4. Über `login.html` anmelden

### Wartung

- Debug-Tools in `debug/` für Diagnose verfügbar
- Test-Skripte in `tests/` für Funktionsprüfung
- Deployment-Skript in `docs/deploy.sh`

## Support

Bei Problemen oder Fragen wenden Sie sich an den Systemadministrator.

---

_Letzte Aktualisierung: $(Get-Date)_
