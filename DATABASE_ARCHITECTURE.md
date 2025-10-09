# 🗄️ WCI Datenbank-Architektur

**Erstellt am:** 2025-10-09  
**Projekt:** WCI Booking System - Franzsennhütte

---

## 📊 Übersicht: Zwei separate Datenbanken

Das WCI-System verwendet **zwei vollständig getrennte Datenbanken**:

| # | Datenbank | Server | Zweck | Config-Datei |
|---|-----------|--------|-------|--------------|
| 1 | **booking_franzsen** | 192.168.15.14 | Zimmerplan, Reservierungen, HRS-Import | `/config.php` |
| 2 | **fsh-res** (HP-DB) | 192.168.2.81 | Halbpension, Arrangements, Tischplanung | `/hp-db-config.php` |

---

## 1️⃣ Booking-Datenbank (booking_franzsen)

### Server-Konfiguration:
```
Host:     192.168.15.14 (lokal)
User:     root
Password: Fsh2147m!1
Database: booking_franzsen
```

### Remote-Sync:
```
Host:     booking.franzsennhuette.at
User:     booking_franzsen
Password: ~2Y@76
Database: booking_franzsen
```

### Haupttabellen:
| Tabelle | Zweck | Zeilen (ca.) |
|---------|-------|--------------|
| `AV-Res` | Aktive Reservierungen (Produktion) | ~900+ |
| `AV-Res-webImp` | Importierte Reservierungen (Staging) | ~30+ |
| `av_belegung` | Tagesbelegung & Verfügbarkeit | ~400+ |
| `hut_quota` | HRS Quota-Änderungen | Variable |
| `daily_summary` | Tages-Zusammenfassungen | Variable |
| `Zimmer` | Zimmerdefinitionen | 51 |

### Verwendung in Dateien:
```php
// Standard-Verbindung
require_once(__DIR__ . '/config.php');
$mysqli = $GLOBALS['mysqli'];

// Oder direkt:
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
```

### Verwendende Module:
- ✅ Zimmerplan (`/zp/`)
- ✅ HRS-Import (`/hrs/`)
- ✅ Reservierungsverwaltung (`/reservierungen/`)
- ✅ API-Endpunkte (`/api/`)
- ✅ Belegungs-Tools (`/belegung/`)

---

## 2️⃣ Halbpensions-Datenbank (fsh-res)

### Server-Konfiguration:
```
Host:     192.168.2.81 (separater Server!)
User:     fsh
Password: er1234tf
Database: fsh-res
```

**⚠️ WICHTIG:** Dies ist ein **anderer physischer Server**!

### Haupttabellen:
| Tabelle | Zweck | Beschreibung |
|---------|-------|--------------|
| `hp_arrangements` | HP-Arrangements | Halbpensions-Zuordnungen zu Reservierungen |
| `hp_data` | HP-Stammdaten | Halbpensions-Konfiguration |
| `tisch_planung` | Tischplanung | Tisch-Zuordnungen für Gäste |
| (weitere) | ... | Analyse erforderlich |

### Verwendung in Dateien:
```php
// HP-DB Verbindung
require_once(__DIR__ . '/hp-db-config.php');
$hpConn = getHpDbConnection();

if ($hpConn === false) {
    die('HP-DB nicht verfügbar');
}

// Abfrage
$result = $hpConn->query("SELECT * FROM hp_arrangements");
```

### Verwendende Module:
- ✅ HP-Arrangements (`/get-hp-arrangements-new.php`)
- ✅ Arrangement-Cells (`/get-arrangement-cells-data.php`)
- ✅ HP-API (`/reservierungen/api/get-all-hp-data.php`)
- ✅ HP-Speichern (`/reservierungen/api/save-hp-arrangements-table.php`)
- ✅ Tisch-Übersicht (`/tisch-namen-uebersicht.php`)
- ✅ Tisch-Planung (`/tisch-uebersicht-resid.php`)
- ✅ Beleg-Druck (`/print-receipt.php`)
- ✅ Sync-System (`/sync-database.php`)
- ✅ CRC-Generator (`/generate-crcs.php`)

### Helfer-Funktionen:
```php
// Connection holen (cached)
function getHpDbConnection() {
    global $HP_DB_CONFIG;
    static $hpConnection = null;
    // ... cached connection ...
    return $hpConnection;
}

// Verfügbarkeit prüfen
function isHpDbAvailable() {
    $conn = getHpDbConnection();
    return $conn !== false;
}
```

---

## 🔄 Datenbank-Interaktionen

### Szenarien mit beiden DBs:

#### Szenario 1: Reservierung mit HP
```
1. Kunde bucht Zimmer
   → booking_franzsen.AV-Res (Zimmerreservierung)
   
2. Kunde wählt Halbpension
   → fsh-res.hp_arrangements (HP-Zuordnung)
   
3. Tisch wird zugewiesen
   → fsh-res.tisch_planung (Tischzuordnung)
```

#### Szenario 2: Sync zwischen Systemen
```php
// In sync-database.php:

// 1. Booking-DB lesen
require_once('config.php');
$bookingData = $mysqli->query("SELECT * FROM `AV-Res` WHERE ...");

// 2. HP-DB aktualisieren
require_once('hp-db-config.php');
$hpConn = getHpDbConnection();
foreach ($bookingData as $reservation) {
    $hpConn->query("INSERT INTO hp_arrangements ...");
}
```

---

## 🔒 Sicherheits-Überlegungen

### Firewall-Regeln erforderlich:
```
Webserver → 192.168.15.14:3306 (Booking-DB)
Webserver → 192.168.2.81:3306   (HP-DB)
```

### Credential-Verwaltung:

**✅ KORREKT:**
```php
// Zwei separate Config-Dateien
/config.php        → Booking-DB Credentials
/hp-db-config.php  → HP-DB Credentials
```

**❌ FALSCH:**
```php
// Eine Config für beide DBs
// (Wäre verwirrend und fehleranfällig!)
```

### Backup-Strategie:

```bash
# Booking-DB Backup
mysqldump -h 192.168.15.14 -u root -p booking_franzsen > booking_backup.sql

# HP-DB Backup (separater Server!)
mysqldump -h 192.168.2.81 -u fsh -p fsh-res > hp_backup.sql
```

---

## 📝 Best Practices

### 1. Immer die richtigen Config-Dateien verwenden

```php
// ✅ FÜR BOOKING-DATEN:
require_once(__DIR__ . '/config.php');
$result = $mysqli->query("SELECT * FROM `AV-Res`");

// ✅ FÜR HP-DATEN:
require_once(__DIR__ . '/hp-db-config.php');
$hpConn = getHpDbConnection();
$result = $hpConn->query("SELECT * FROM hp_arrangements");
```

### 2. Connection-Pooling nutzen

```php
// ✅ HP-DB verwendet Singleton-Pattern
$hpConn = getHpDbConnection(); // Cached!

// ❌ Nicht jedes Mal neu verbinden
$hpConn = new mysqli(...); // Overhead!
```

### 3. Fehlerbehandlung

```php
// HP-DB könnte offline sein
if (!isHpDbAvailable()) {
    // Fallback: HP-Features deaktivieren
    $hpEnabled = false;
} else {
    $hpConn = getHpDbConnection();
    // ... HP-Abfragen ...
}
```

### 4. Transaktionen über beide DBs

```php
// ⚠️ PROBLEM: Keine verteilten Transaktionen!
// Lösung: Manuelle Rollback-Strategie

try {
    // 1. Booking-DB
    $mysqli->begin_transaction();
    $mysqli->query("INSERT INTO `AV-Res` ...");
    
    // 2. HP-DB
    $hpConn->begin_transaction();
    $hpConn->query("INSERT INTO hp_arrangements ...");
    
    // Beide committen
    $mysqli->commit();
    $hpConn->commit();
    
} catch (Exception $e) {
    // Rollback auf beiden DBs
    $mysqli->rollback();
    $hpConn->rollback();
    throw $e;
}
```

---

## 🔍 Analyse-Tools

### Check Booking-DB:
```php
// /check_db_table.php
require_once('config.php');
$tables = $mysqli->query("SHOW TABLES");
```

### Check HP-DB:
```php
// /debug-hp-tables.php
require_once('hp-db-config.php');
$hpConn = getHpDbConnection();
$tables = $hpConn->query("SHOW TABLES");
```

### Struktur-Vergleich:
```php
// /check-hp-db-structure.php
require_once('hp-db-config.php');
$hpConn = getHpDbConnection();
$structure = $hpConn->query("DESCRIBE hp_arrangements");
```

---

## 📈 Zukünftige Erweiterungen

### Option 1: Datenbank-Abstraktions-Layer

```php
// /lib/DatabaseManager.php (ZUKÜNFTIG)

class DatabaseManager {
    private static $bookingDb = null;
    private static $hpDb = null;
    
    public static function getBookingDb() {
        if (self::$bookingDb === null) {
            require_once(__DIR__ . '/../config.php');
            self::$bookingDb = $GLOBALS['mysqli'];
        }
        return self::$bookingDb;
    }
    
    public static function getHpDb() {
        if (self::$hpDb === null) {
            require_once(__DIR__ . '/../hp-db-config.php');
            self::$hpDb = getHpDbConnection();
        }
        return self::$hpDb;
    }
}

// Verwendung:
$bookingDb = DatabaseManager::getBookingDb();
$hpDb = DatabaseManager::getHpDb();
```

### Option 2: Umgebungsvariablen (ENV)

```bash
# .env (ZUKÜNFTIG)
BOOKING_DB_HOST=192.168.15.14
BOOKING_DB_USER=root
BOOKING_DB_PASS=Fsh2147m!1
BOOKING_DB_NAME=booking_franzsen

HP_DB_HOST=192.168.2.81
HP_DB_USER=fsh
HP_DB_PASS=er1234tf
HP_DB_NAME=fsh-res
```

---

## ✅ Checkliste: DB-Architektur verstanden

- [ ] Ich verstehe, dass es **2 separate Datenbanken** gibt
- [ ] Booking-DB (`/config.php`) für Zimmerplan & Reservierungen
- [ ] HP-DB (`/hp-db-config.php`) für Halbpension & Tischplanung
- [ ] HP-DB läuft auf **separatem Server** (192.168.2.81)
- [ ] `getHpDbConnection()` verwenden für HP-Zugriff
- [ ] `$GLOBALS['mysqli']` verwenden für Booking-Zugriff
- [ ] Beide Config-Dateien sind **produktiv** und dürfen **nicht gelöscht** werden
- [ ] Bei DB-Änderungen beide Systeme berücksichtigen

---

**🏁 ENDE DER DATENBANK-ARCHITEKTUR DOKUMENTATION**

*Für weitere Informationen siehe:*
- `PROJECT_CONFIGURATION_AUDIT_README.md` - Vollständiger Audit-Bericht
- `/config.php` - Booking-DB Konfiguration
- `/hp-db-config.php` - HP-DB Konfiguration
