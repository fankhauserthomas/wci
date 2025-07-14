# WebCheckin Sicherheitssystem - Dokumentation

## 🔐 Authentifizierungssystem

### Übersicht

Das WebCheckin-System ist jetzt mit einem robusten Authentifizierungsmechanismus geschützt, der verhindert, dass unbefugte Personen auf die Anwendung und Datenbank zugreifen können.

### 🔑 Standardpasswort

**WICHTIG:** Das aktuelle Systempasswort lautet: `FranzSenn2025!Secure`

**SICHERHEITSHINWEIS:** Ändern Sie dieses Passwort in der Datei `auth.php` in Zeile 6:

```php
private static $APP_PASSWORD = 'IhrNeuesSicheresPasswort!';
```

### 📁 Neue Dateien

#### Core-Authentifizierung:

- `auth.php` - Zentrale Authentifizierungsklasse
- `login.html` - Anmeldeseite
- `authenticate.php` - Login-Endpoint
- `checkAuth.php` - Session-Validierung
- `logout.php` - Abmelde-Endpoint

#### Zusätzliche Sicherheit:

- `js/auth-protection.js` - Frontend-Schutz
- `.htaccess` - Server-Sicherheitskonfiguration

### 🔒 Sicherheitsfeatures

#### Session-Management:

- **Session-Timeout:** 8 Stunden automatische Abmeldung
- **IP-Logging:** Alle Login-Versuche werden protokolliert
- **Brute-Force-Schutz:** 1-Sekunde Verzögerung bei falschen Passwörtern

#### Datenbankschutz:

- **Automatische Auth-Prüfung:** Alle API-Endpoints sind geschützt
- **Einheitliche Fehlerbehandlung:** Sichere JSON-Responses
- **Connection-Logging:** Datenbankfehler werden protokolliert

#### Frontend-Schutz:

- **Automatische Weiterleitung:** Unauthentifizierte Benutzer → Login
- **Session-Monitoring:** Alle 5 Minuten Session-Check
- **Sichere Fetch-Wrapper:** Automatische Auth-Behandlung

#### Server-Sicherheit:

- **Datei-Schutz:** Sensitive PHP-Dateien nicht direkt zugänglich
- **Security-Headers:** XSS-, Clickjacking- und Content-Type-Schutz
- **Rate-Limiting:** Schutz vor automatisierten Angriffen

### 🚀 Funktionsweise

#### 1. Anmeldung:

1. Benutzer besucht beliebige Seite
2. Automatische Weiterleitung zu `login.html` wenn nicht angemeldet
3. Passwort-Eingabe
4. Session wird erstellt bei korrektem Passwort
5. Weiterleitung zur ursprünglich gewünschten Seite

#### 2. Session-Management:

- Session läuft nach 8 Stunden ab
- Automatische Session-Checks alle 5 Minuten
- Bei Ablauf: Automatische Weiterleitung zum Login

#### 3. Datenbankzugriff:

- Jeder API-Call prüft automatisch die Authentifizierung
- Bei ungültiger Session: 401-Error mit Weiterleitung
- Einheitliche Fehlerbehandlung in allen PHP-Endpoints

### 🛠️ Administration

#### Passwort ändern:

1. Öffnen Sie `auth.php`
2. Ändern Sie Zeile 6: `private static $APP_PASSWORD = 'NeuesPasswort';`
3. Verwenden Sie ein starkes Passwort (min. 12 Zeichen, Groß-/Kleinbuchstaben, Zahlen, Sonderzeichen)

#### Session-Timeout anpassen:

In `auth.php`, Zeile 27:

```php
if ($sessionAge > (8 * 3600)) { // 8 Stunden in Sekunden
```

#### Logging prüfen:

- Login-Versuche werden in PHP-Error-Log protokolliert
- Suchbegriff: "WebCheckin Login"

### 🔧 Integration in bestehende Seiten

Für neue HTML-Seiten fügen Sie hinzu:

```html
<script src="js/auth-protection.js"></script>
```

Für neue PHP-APIs verwenden Sie:

```php
require_once 'config.php';
// Auth-Check ist automatisch in config.php integriert
```

### ⚠️ Wichtige Hinweise

1. **Passwort-Sicherheit:** Verwenden Sie ein starkes, einzigartiges Passwort
2. **HTTPS:** Verwenden Sie das System nur über HTTPS in Produktion
3. **Backup:** Sichern Sie die auth.php regelmäßig
4. **Updates:** Überprüfen Sie regelmäßig die Sicherheitskonfiguration
5. **Logs:** Überwachen Sie die Login-Logs auf verdächtige Aktivitäten

### 🎯 Nächste Schritte

1. **Passwort ändern:** Ersetzen Sie das Standardpasswort
2. **Testen:** Überprüfen Sie alle Funktionen nach der Implementierung
3. **HTTPS aktivieren:** Stellen Sie sicher, dass HTTPS aktiv ist
4. **Monitoring:** Überwachen Sie die Logs auf ungewöhnliche Aktivitäten

Das System ist jetzt vollständig geschützt und einsatzbereit! 🛡️
