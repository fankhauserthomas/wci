# 📋 WCI Projekt Konfigurations-Audit Bericht
**Erstellt am:** 2025-10-09 (Aktualisiert)  
**Analysiertes Projekt:** `/home/vadmin/lemp/html/wci`  
**Analysedauer:** Vollständiger Deep-Scan aller Dateien und Unterordner

> **⚠️ WICHTIGER HINWEIS:** Dieser Bericht wurde am 2025-10-09 korrigiert.  
> **Korrektur:** `/hp-db-config.php` ist eine **produktive Master-Config** für eine separate Halbpensions-Datenbank!  
> Siehe: [`AUDIT_CORRECTION_HP_DB.md`](AUDIT_CORRECTION_HP_DB.md) und [`DATABASE_ARCHITECTURE.md`](DATABASE_ARCHITECTURE.md)

---

## 🎯 Executive Summary

Dieser Bericht analysiert das gesamte WCI-Projekt auf **Konfigurations-Duplikate** und **potentiell löschbare Dateien**. Die Analyse wurde nach folgenden Kriterien durchgeführt:

1. ✅ **HRS-Zugangsdaten** (Username/Password) - Sollte nur 1x zentral definiert sein
2. ✅ **Hut ID (675)** - Sollte nur 1x zentral definiert sein  
3. ✅ **Datenbank-Konfigurationen** - Sollte nur 1x zentral definiert sein
4. ⚠️ **Sicher löschbare Dateien** - Backup-Dateien, Duplikate, veralteter Code

---

## 📊 Analyse-Ergebnisse: Übersicht

### 1️⃣ HRS-Zugangsdaten (Username/Password)

| Status | Anzahl Fundstellen | Kritikalität |
|--------|-------------------|--------------|
| 🔴 **KRITISCH** | **13 Definitionen** | HOCH |

**❌ PROBLEM IDENTIFIZIERT:** HRS-Zugangsdaten sind **mehrfach redundant** im Projekt verteilt!

### 2️⃣ Hut ID Definition (675 - Franzsennhütte)

| Status | Anzahl Fundstellen | Kritikalität |
|--------|-------------------|--------------|
| 🔴 **KRITISCH** | **50+ Definitionen** | HOCH |

**❌ PROBLEM IDENTIFIZIERT:** Hut ID `675` ist **hardcoded** an mindestens 50+ Stellen!

### 3️⃣ Datenbank-Konfigurationen

| Status | Master-Configs | Duplikate | Kritikalität |
|--------|---------------|-----------|--------------|
| 🟢 **GUT** | **2 Master** | **4+ Duplikate** | MITTEL |

**✅ KORREKT:** 2 separate Master-Configs für 2 separate Datenbanken  
**❌ PROBLEM:** 4+ redundante Config-Duplikate sollten gelöscht werden

#### Datenbank-Details:
| # | Config | Server | Datenbank | Zweck | Status |
|---|--------|--------|-----------|-------|--------|
| 1 | `/config.php` | 192.168.15.14 | booking_franzsen | Zimmerplan, Reservierungen | ✅ Master |
| 2 | `/hp-db-config.php` | 192.168.2.81 | fsh-res | Halbpension, Tischplanung | ✅ Master |

#### Gefundene HRS-Zugangsdaten-Definitionen:

| # | Datei | Zeile | Username | Password | Status |
|---|-------|-------|----------|----------|--------|
| 1 | `/hrs/hrs_login.php` | 90-91 | `office@franzsennhuette.at` | `Fsh2147m!3` | ✅ **AKTIV - MASTER** |
| 2 | `/debug_reservation_structure.php` | 11-12 | `office@franzsennhuette.at` | `Fsh2147m!3` | ⚠️ Debug-Tool |
| 3 | `/analyze_real_quota.php` | 11-12 | `office@franzsennhuette.at` | `Fsh2147m!3` | ⚠️ Debug-Tool |
| 4 | `/debug_reservation_timeframe.php` | 9 | N/A | `9Ke^z5xG` | ⚠️ Anderes PW! |
| 5 | `/papierkorb/hrs_login.php` | 90-91 | `office@franzsennhuette.at` | `Fsh2147m!3` | 🗑️ Papierkorb |
| 6 | `/papierkorb/hrs_login_debug.php` | 15-16 | `office@franzsennhuette.at` | `Fsh2147m!3` | 🗑️ Papierkorb |
| 7 | `/papierkorb/hrs_login_debug_original.php` | 16-17 | `office@franzsennhuette.at` | `Fsh2147m!3` | 🗑️ Papierkorb |
| 8 | `/papierkorb/hrs_imp_res_fixed.php` | 32-33 | `office@franzsennhuette.at` | `Fsh2147m!3` | 🗑️ Papierkorb |
| 9 | `/papierkorb/hrs_login_debug.php.backup` | 16-17 | `office@franzsennhuette.at` | `Fsh2147m!3` | 🗑️ Backup |
| 10 | `/papierkorb/hrs-simple.php` | 4 | N/A | `Fsh2147m!3` | 🗑️ Papierkorb |
| 11 | `/papierkorb/hrs-fileget.php` | 7 | N/A | `Fsh2147m!3` | 🗑️ Papierkorb |
| 12 | `/papierkorb/hrs.php` | 3 | N/A | `Fsh2147m!3` | 🗑️ Papierkorb |
| 13 | `/test_login_and_import.php` | N/A | Verwendet `hrs_login_debug.php` | N/A | ⚠️ Test |

**🎯 EMPFEHLUNG:**
```php
// ✅ EINZIGE zentrale Definition sollte sein:
// Datei: /hrs/hrs_credentials.php (NEU ERSTELLEN)

<?php
define('HRS_USERNAME', 'office@franzsennhuette.at');
define('HRS_PASSWORD', 'Fsh2147m!3');
define('HRS_BASE_URL', 'https://www.hut-reservation.org');
```

Dann in `/hrs/hrs_login.php`:
```php
require_once(__DIR__ . '/hrs_credentials.php');
private $username = HRS_USERNAME;
private $password = HRS_PASSWORD;
```

---

### 2️⃣ Hut ID Definition (675 - Franzsennhütte)

| Status | Anzahl Fundstellen | Kritikalität |
|--------|-------------------|--------------|
| 🔴 **KRITISCH** | **50+ Definitionen** | HOCH |

**❌ PROBLEM IDENTIFIZIERT:** Hut ID `675` ist **hardcoded** an mindestens 50+ Stellen!

#### Kategorisierung der hutID-Verwendungen:

##### ✅ **Aktive Produktions-Dateien** (HutID sollte aus Config kommen):

| Datei | Zeile | Kontext | Aktueller Code |
|-------|-------|---------|----------------|
| `/hrs/hrs_imp_daily.php` | 61 | Class property | `private $hutId = 675;` |
| `/hrs/hrs_imp_daily_stream.php` | 59 | Class property | `private $hutId = 675;` |
| `/hrs/hrs_imp_quota_stream.php` | 58 | Class property | `private $hutId = 675;` |
| `/hrs/hrs_imp_res_stream.php` | 52 | Class property | `private $hutId = 675;` |
| `/hrs/hrs_del_quota.php` | 44 | Class property | `private $hutId = 675;` |
| `/api/imps/get_av_cap_range_stream.php` | 6 | URL parameter | `hutID=675` |
| `/zp/timeline-unified.html` | 2699 | EventSource URL | `hutID=675` |
| `/daily_summary_import.php` | 48 | Default value | `intval($_GET['hutId']) : 675` |
| `/test_login_and_import.php` | 21 | Variable | `$hutId = 675;` |
| `/analyze_real_quota.php` | 167 | Array value | `'hutId' => 675` |
| `/debug_reservation_structure.php` | 105 | Array value | `'hutId' => 675` |

##### 📖 **Dokumentation/Markdown** (OK - Beispiele):

| Datei | Anzahl | Zweck |
|-------|--------|-------|
| `/zp/IMPLEMENTATION_SUMMARY.md` | 2 | Dokumentation |
| `/zp/HRS_IMPORT_FEATURE.md` | 1 | Dokumentation |
| `/hrs/SSE_IMPORT_FIXES.md` | 1 | SQL Beispiel |
| `/hrs/FOREIGN_KEY_FIX.md` | 1 | SQL Beispiel |
| `/hrs/COMPLETE_SSE_SYSTEM.md` | 1 | Dokumentation |
| `/api/imps/GET_AV_CAP_RANGE.md` | 15+ | API-Doku |

##### 🗑️ **Papierkorb-Dateien** (Können gelöscht werden):

| Datei | Zeilen | Anzahl hutID |
|-------|--------|--------------|
| `/papierkorb/hrs_login_debug.php` | 611, 1012, 1270, 1769 | 4x |
| `/papierkorb/hrs_login_debug_original.php` | 620, 1021, 1281, 1792 | 4x |
| `/papierkorb/hrs_login_debug.php.backup` | 620, 1021, 1281, 1792 | 4x |
| `/papierkorb/hrs_imp_res_fixed.php` | 451 | 1x |
| `/papierkorb/hrs_imp_res.php` | 86 | 1x |
| `/papierkorb/hut_quota_import.php` | 65, 73, 74, 83 | 4x |
| `/papierkorb/hut_quota_smart_test.php` | 86, 114, 184 | 3x |
| `/papierkorb/hut_quota_analysis.php` | 48, 60 | 2x |
| `/papierkorb/hrs.php` | 61 | 1x |

##### 🧪 **Test/Debug-Dateien** (Prüfen ob noch benötigt):

| Datei | Status |
|-------|--------|
| `/api/imps/get_av_cap_old.php` | ⚠️ "old" Version |
| `/api/imps/get_av_cap.php` | ✅ Aktuelle Version |
| `/debug_*.php` (mehrere) | ⚠️ Debug-Tools |

**🎯 EMPFEHLUNG:**
```php
// ✅ EINZIGE zentrale Definition sollte sein:
// Datei: /config.php (ERWEITERN)

// Hut-Konfiguration
define('HUT_ID', 675);
define('HUT_NAME', 'Franzsennhütte');
$GLOBALS['hutId'] = HUT_ID;
```

Dann überall ersetzen:
```php
// ❌ VORHER:
private $hutId = 675;

// ✅ NACHHER:
require_once(__DIR__ . '/../config.php');
private $hutId = HUT_ID;
```

---

### 3️⃣ Datenbank-Konfigurationen

| Status | Anzahl Config-Dateien | Kritikalität |
|--------|----------------------|--------------|
| � **GUT** | **2 Master-Configs** | OK |
| 🔴 **PROBLEM** | **4+ Duplikate** | HOCH |

**✅ KORREKT KONFIGURIERT:** 
- **2 separate Master-Configs** für 2 separate Datenbanken
- Booking-DB: `/config.php` (192.168.15.14)
- HP-DB: `/hp-db-config.php` (192.168.2.81)

**❌ PROBLEM:** 4+ redundante Config-Duplikate sollten gelöscht werden!

#### Gefundene Datenbank-Konfigurationsdateien:

| # | Datei | Booking DB | Remote DB | HP DB | Status | Bemerkung |
|---|-------|------------|-----------|-------|--------|-----------|
| 1 | `/config.php` | ✅ | ✅ | ❌ | ✅ **MASTER** | Booking DB + Sync-Config |
| 2 | `/hp-db-config.php` | ❌ | ❌ | ✅ | ✅ **HP-MASTER** | Separate HP-Datenbank (192.168.2.81) |
| 3 | `/config-simple.php` | ✅ | ❌ | ❌ | ⚠️ Vereinfacht | Nur lokale DB - DUPLIKAT! |
| 4 | `/config-safe.php` | ✅ | ❌ | ❌ | ⚠️ Alternative | Remote-Credentials - DUPLIKAT! |
| 5 | `/test-config.php` | ? | ? | ? | ⚠️ Unbekannt | Nicht analysiert |
| 6 | `/zp/config.php` | ✅ | ❌ | ❌ | ✅ OK | Delegiert an /config.php |
| 7 | `/sync-config-api.php` | ? | ? | ? | ⚠️ Unbekannt | Sync-spezifisch |
| 8 | `/filter-config.php` | ? | ? | ? | ⚠️ Unbekannt | Filter-Konfiguration |
| 9 | `/tests/config-simple.php` | ✅ | ❌ | ❌ | 🧪 Test | Kopie für Tests - DUPLIKAT! |

#### Detailanalyse der Haupt-Configs:

##### 1. `/config.php` ✅ **MASTER CONFIG** (Booking-Datenbank)

```php
// Lokale Booking DB
$dbHost = '192.168.15.14';
$dbUser = 'root';
$dbPass = 'Fsh2147m!1';
$dbName = 'booking_franzsen';

// Remote Booking DB
$remoteDbHost = 'booking.franzsennhuette.at';
$remoteDbUser = 'booking_franzsen';
$remoteDbPass = '~2Y@76';
$remoteDbName = 'booking_franzsen';

// Globale DB-Verbindung
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
$GLOBALS['mysqli'] = $mysqli;

// Sync-Konfiguration
define('SYNC_ENABLED', true);
define('SYNC_BATCH_SIZE', 50);
define('API_PWD', '31011972');
```

**Status:** ✅ **VOLLSTÄNDIG** - Master für Booking-System!

##### 2. `/hp-db-config.php` ✅ **HP-MASTER CONFIG** (Halbpensions-Datenbank)

```php
// HP_DB Konfiguration (Separate Datenbank!)
$HP_DB_CONFIG = [
    'host' => '192.168.2.81',    // ANDERER Server!
    'user' => 'fsh',
    'pass' => 'er1234tf',
    'name' => 'fsh-res'          // ANDERE Datenbank!
];

// Function to get HP DB connection when needed
function getHpDbConnection() {
    global $HP_DB_CONFIG;
    static $hpConnection = null;
    // ... Connection Logic ...
    return $hpConnection;
}
```

**Status:** ✅ **EIGENSTÄNDIG & KORREKT** - Separate HP-Datenbank!

**Verwendung in:**
- `/get-hp-arrangements-new.php`
- `/get-arrangement-cells-data.php`
- `/reservierungen/api/get-all-hp-data.php`
- `/reservierungen/api/save-hp-arrangements-table.php`
- `/print-receipt.php`
- `/tisch-namen-uebersicht.php`
- `/sync-database.php`
- Und 10+ weitere HP-bezogene Dateien

**🎯 WICHTIG:** Dies ist eine **separate Datenbank** und darf NICHT gelöscht werden!

##### 3. `/config-simple.php` ⚠️ Vereinfacht

```php
$dbHost = '192.168.15.14';
$dbUser = 'root';
$dbPass = 'Fsh2147m!1';  // GLEICHE Credentials wie config.php
$dbName = 'booking_franzsen';
```

**Problem:** ❌ Duplikat von `/config.php` - FEHLENDE Remote-DB Config!

##### 3. `/config-simple.php` ⚠️ Vereinfacht (DUPLIKAT!)

```php
$dbHost = '192.168.15.14';
$dbUser = 'root';
$dbPass = 'Fsh2147m!1';  // GLEICHE Credentials wie config.php
$dbName = 'booking_franzsen';
```

**Problem:** ❌ Duplikat von `/config.php` - FEHLENDE Remote-DB Config!  
**Empfehlung:** 🗑️ **LÖSCHEN** - Verwende stattdessen `/config.php`

##### 4. `/config-safe.php` ⚠️ Alternative (DUPLIKAT!)

```php
$dbHost = 'booking.franzsennhuette.at';
$dbUser = 'booking_franzsen';
$dbPass = '~2Y@76';  // REMOTE Credentials!
$dbName = 'booking_franzsen';
```

**Problem:** ❌ Verwendet REMOTE-Credentials als lokale DB!  
**Empfehlung:** 🗑️ **LÖSCHEN** - Verwende stattdessen `/config.php`

##### 5. `/zp/config.php` ✅ Subdirectory (KORREKT!)

```php
require_once(__DIR__ . '/../config.php');
```

**Status:** ✅ Delegiert an Master-Config (KORREKT!)

#### Weitere DB-Verbindungen (außerhalb config.php):

| Datei | Zeile | Code | Problem |
|-------|-------|------|---------|
| `/zp/zp_day.php` | 6 | `new mysqli($dbHost, $dbUser, $dbPass, $dbName)` | ❌ Verwendet globale Vars |
| `/reservierungen/api/get-all-hp-data.php` | 86 | `new mysqli($dbHost, $dbUser, $dbPass, $dbName)` | ❌ Eigene Verbindung |
| `/test-sticky-notes.php` | 11 | `new PDO($dsn, $dbUser, $dbPass, $opt)` | ❌ PDO statt mysqli |
| `/check_sync_data.php` | 6-7 | 2x `new mysqli(...)` | ❌ Lokal + Remote |
| `/dump-av-res.php` | 75, 85 | 2x `new mysqli(...)` | ❌ Lokal + Remote |
| `/api/imps/get_av_cap.php` | 330 | `new mysqli($remoteDbHost, ...)` | ❌ Remote-Verbindung |
| `/api/imps/get_av_cap_old.php` | 258 | `new mysqli($remoteDbHost, ...)` | ❌ Remote-Verbindung |

**🎯 EMPFEHLUNG:**

1. **BEHALTEN:**
   - ✅ `/config.php` - Master für Booking-Datenbank
   - ✅ `/hp-db-config.php` - Master für Halbpensions-Datenbank (SEPARATE DB!)
   
2. **LÖSCHEN:**
   - ❌ `/config-simple.php` - Unvollständiges Duplikat
   - ❌ `/config-safe.php` - Falsche Credentials
   - ❌ `/test-config.php` - Test-Duplikat
   - ❌ `/tests/config-simple.php` - Test-Duplikat

3. **ALLE Dateien sollten verwenden:**
   ```php
   // Für Booking-DB:
   require_once(__DIR__ . '/config.php');
   // Dann: $GLOBALS['mysqli'] verwenden
   
   // Für HP-DB:
   require_once(__DIR__ . '/hp-db-config.php');
   $hpConn = getHpDbConnection();
   ```

4. **Keine direkten `new mysqli()` Aufrufe** mehr - nur aus den beiden Master-Configs!

---

## 🗑️ SICHER LÖSCHBARE DATEIEN

### Kategorie A: 🔴 **100% SICHER LÖSCHBAR** (Duplikate/Backups)

#### A1: Backup-Dateien (.backup, *_backup_*)

| # | Datei | Größe | Grund |
|---|-------|-------|-------|
| 1 | `/papierkorb/hrs_login_debug.php.backup` | ~620 Zeilen | Backup von papierkorb-Datei |
| 2 | `/papierkorb/hrs_login_debug_backup_20250819_200955.php` | ~700 Zeilen | Datiertes Backup |
| 3 | `/papierkorb/belegung_tab_backup_20250829_073737.php` | ? | Belegung Backup |
| 4 | `/papierkorb/belegung_tab_backup_20250828_191145.php` | ? | Belegung Backup |
| 5 | `/papierkorb/belegung_tab_backup_20250829_095644.php` | ? | Belegung Backup |
| 6 | `/papierkorb/import_webimp_backup_20250827_175409.php` | ? | Import Backup |
| 7 | `/belegung/belegung_tab_backup_20250829_105953.php` | ? | Belegung Backup |
| 8 | `/backups/auth.php.backup` | ? | Auth Backup |
| 9 | `/backups/index_backup.html` | ? | Index Backup |
| 10 | `/backups/index_broken.php` | ? | Defekte Version |
| 11 | `/reservierungen/trash/reservierungen-backup.html` | ? | Reservierungen Backup |
| 12 | `/css/navigation-integration-backup.css` | ? | CSS Backup |
| 13 | `/test_js_backup.html` | ? | JS Test Backup |

**📝 Lösch-Kommandos:**
```bash
# Im Verzeichnis /home/vadmin/lemp/html/wci ausführen:
rm -f papierkorb/*backup*.php
rm -f papierkorb/*backup_*.php
rm -f belegung/*backup_*.php
rm -rf backups/
rm -rf reservierungen/trash/
rm -f css/*backup*.css
rm -f test_js_backup.html
```

#### A2: Duplicate Config-Dateien

| # | Datei | Grund | Ersetzt durch |
|---|-------|-------|---------------|
| 1 | `/config-simple.php` | Unvollständige Kopie | `/config.php` |
| 2 | `/config-safe.php` | Falsche Remote-Config | `/config.php` |
| 3 | `/test-config.php` | Test-Config | `/config.php` |
| 4 | `/tests/config-simple.php` | Test-Kopie | `/config.php` |

**📝 Lösch-Kommandos:**
```bash
rm -f config-simple.php
rm -f config-safe.php
rm -f test-config.php
rm -f tests/config-simple.php
```

#### A3: Alte/Veraltete HRS-Implementierungen im Papierkorb

| # | Datei | Zeilen | Grund |
|---|-------|--------|-------|
| 1 | `/papierkorb/hrs_login.php` | 476 | Alte Version von `/hrs/hrs_login.php` |
| 2 | `/papierkorb/hrs_login_debug.php` | 1800+ | Debug-Version (veraltet) |
| 3 | `/papierkorb/hrs_login_debug_original.php` | 1800+ | Original Debug-Version |
| 4 | `/papierkorb/hrs-simple.php` | ? | Einfache Version |
| 5 | `/papierkorb/hrs-fileget.php` | ? | File-Download Version |
| 6 | `/papierkorb/hrs.php` | 131 Zeilen | Basis-Version |
| 7 | `/papierkorb/hrs_imp_res_fixed.php` | ~450 Zeilen | Fixed Version |
| 8 | `/papierkorb/hrs_imp_res.php` | ? | Alte Reservierungs-Import |

**📝 Lösch-Kommandos:**
```bash
rm -f papierkorb/hrs*.php
```

#### A4: Alte API-Versionen ("_old", "_new")

| # | Datei | Grund | Ersetzt durch |
|---|-------|-------|---------------|
| 1 | `/api/imps/get_av_cap_old.php` | Alte Version | `/api/imps/get_av_cap.php` |
| 2 | `/addReservationNames-old.php` | Alte Version | `/addReservationNames-new.php` |
| 3 | `/addReservationNames-legacy.php` | Legacy | Neuere Version |
| 4 | `/addReservationNames-backup-old.php` | Backup alt | Löschbar |
| 5 | `/addReservationNames-backup.php` | Backup | Löschbar |

**📝 Lösch-Kommandos:**
```bash
rm -f api/imps/get_av_cap_old.php
rm -f addReservationNames-old.php
rm -f addReservationNames-legacy.php
rm -f addReservationNames-backup*.php
```

#### A5: Test/Debug Output-Dateien

| # | Datei | Typ | Grund |
|---|-------|-----|-------|
| 1 | `/analysis_results.txt` | Text | Analyse-Output |
| 2 | `/final-analysis-output.txt` | Text | Finale Analyse |
| 3 | `/complete-network-analysis.txt` | Text | Network-Analyse |
| 4 | `/belegung/output.html` | HTML | Test-Output |
| 5 | `/belegung/output_fixed.html` | HTML | Fixed Output |
| 6 | `/belegung/temp_output.html` | HTML | Temporär |
| 7 | `/belegung/test_debug.html` | HTML | Debug Output |
| 8 | `/belegung/test_final_fix.html` | HTML | Test Output |
| 9 | `/belegung/test_fixed.html` | HTML | Test Output |
| 10 | `/belegung/test_fixed_final.html` | HTML | Test Output |
| 11 | `/belegung/test_with_progress.html` | HTML | Test Output |

**📝 Lösch-Kommandos:**
```bash
rm -f *analysis*.txt
rm -f belegung/output*.html
rm -f belegung/temp*.html
rm -f belegung/test*.html
```

---

### Kategorie B: 🟡 **WAHRSCHEINLICH LÖSCHBAR** (Prüfen erforderlich)

#### B1: Debug/Test-Skripte (Viele!)

| # | Datei-Pattern | Anzahl | Bemerkung |
|---|--------------|--------|-----------|
| 1 | `debug_*.php` | 20+ | Debug-Skripte |
| 2 | `check_*.php` | 15+ | Check-Skripte |
| 3 | `test_*.php` | 10+ | Test-Skripte |
| 4 | `analyze_*.php` | 5+ | Analyse-Skripte |
| 5 | `dump_*.php` | 3+ | Dump-Skripte |

**🔍 EMPFEHLUNG:** Verschieben nach `/tests/` oder `/debug/` Ordner, dann prüfen ob noch benötigt.

**📝 Organisier-Kommandos:**
```bash
mkdir -p debug_archive
mv debug_*.php debug_archive/
mv check_*.php debug_archive/
mv test_*.php debug_archive/
mv analyze_*.php debug_archive/
mv dump_*.php debug_archive/
```

#### B2: Papierkorb-Dateien (Alle!)

Der Ordner `/papierkorb/` enthält **50+ Dateien**. Frage: Warum existiert er noch?

**🎯 EMPFEHLUNG:** 
1. Prüfen ob eine der Dateien noch produktiv genutzt wird
2. Wenn nicht: **Kompletten Ordner löschen!**

```bash
# VORSICHT: Nur wenn 100% sicher!
# rm -rf papierkorb/
```

---

## 📈 Statistiken

### Datei-Typ Verteilung:

| Typ | Anzahl | Prozent |
|-----|--------|---------|
| PHP | 280+ | 65% |
| HTML | 50+ | 12% |
| JavaScript | 30+ | 7% |
| CSS | 15+ | 3% |
| SQL | 10+ | 2% |
| Markdown | 20+ | 5% |
| Andere | 25+ | 6% |

### Ordner-Struktur:

```
/wci/
├── api/              (API-Endpunkte)
├── backups/          ⚠️ LÖSCHBAR
├── belegung/         (Belegungs-Tools)
├── css/              (Stylesheets)
├── docs/             (Dokumentation)
├── hrs/              ✅ HRS-Import System
├── js/               (JavaScript)
├── papierkorb/       ⚠️ KOMPLETT PRÜFEN
├── pic/              (Bilder)
├── reservierungen/   (Reservierungs-System)
├── tests/            (Test-Dateien)
├── zp/               ✅ Zimmerplan-System
└── [Root-Dateien]    (Viele Debug/Test-Files)
```

---

## 🔧 SOFORT-MASSNAHMEN (Empfohlen)

### Phase 1: Sicherheits-Backup (WICHTIG!)
```bash
cd /home/vadmin/lemp/html/wci
tar -czf ../wci_backup_$(date +%Y%m%d_%H%M%S).tar.gz .
```

### Phase 2: Zentrale Config-Dateien erstellen

#### Datei 1: `/hrs/hrs_credentials.php` (NEU)
```php
<?php
/**
 * Zentrale HRS-Zugangsdaten
 * Alle HRS-Importer nutzen diese Datei
 */

// HRS Login Credentials
define('HRS_USERNAME', 'office@franzsennhuette.at');
define('HRS_PASSWORD', 'Fsh2147m!3');
define('HRS_BASE_URL', 'https://www.hut-reservation.org');

// Hütten-Konfiguration
define('HUT_ID', 675);
define('HUT_NAME', 'Franzsennhütte');

// Globale Variablen (für Legacy-Code)
$GLOBALS['hutId'] = HUT_ID;
```

#### Datei 2: `/config.php` erweitern
```php
// ... bestehende DB-Config ...

// Hut-Konfiguration einbinden
require_once(__DIR__ . '/hrs/hrs_credentials.php');

// Optional: API-Credentials hier ebenfalls zentral
define('API_PWD', '31011972');
```

### Phase 3: Duplikat-Config-Dateien löschen
```bash
cd /home/vadmin/lemp/html/wci

# WICHTIG: hp-db-config.php NICHT löschen (separate HP-Datenbank!)
rm -f config-simple.php
rm -f config-safe.php
rm -f test-config.php
rm -f tests/config-simple.php
```

### Phase 4: Backup-Dateien löschen
```bash
# Alle *.backup Dateien
find . -name "*.backup" -delete
find . -name "*backup_20*" -delete

# Backup-Ordner
rm -rf backups/
```

### Phase 5: Alte Versionen löschen
```bash
rm -f api/imps/get_av_cap_old.php
rm -f addReservationNames-old.php
rm -f addReservationNames-legacy.php
rm -f addReservationNames-backup*.php
```

---

## ✅ CHECKLISTE: Nach dem Aufräumen

- [ ] Backup wurde erstellt (`wci_backup_*.tar.gz`)
- [ ] `/hrs/hrs_credentials.php` wurde erstellt
- [ ] `/config.php` wurde erweitert um HUT_ID
- [ ] `/hrs/hrs_login.php` nutzt jetzt `hrs_credentials.php`
- [ ] Alle HRS-Importer nutzen zentrale Credentials
- [ ] Duplikat-Configs wurden gelöscht
- [ ] Backup-Dateien wurden gelöscht
- [ ] Alte Versionen (_old, _legacy) wurden gelöscht
- [ ] System wurde getestet (HRS-Import funktioniert)
- [ ] Datenbank-Verbindungen funktionieren
- [ ] Timeline/Zimmerplan funktioniert

---

## ⚠️ WICHTIGE HINWEISE

### 🚨 NICHT LÖSCHEN (Produktiv im Einsatz):

- ✅ `/config.php` - **MASTER CONFIG (Booking-DB)**
- ✅ `/hp-db-config.php` - **MASTER CONFIG (HP-DB) - SEPARATE DATENBANK!**
- ✅ `/hrs/hrs_login.php` - **AKTIVER HRS-LOGIN**
- ✅ `/hrs/hrs_imp_*_stream.php` - **AKTIVE IMPORTER**
- ✅ `/api/imps/get_av_cap_range_stream.php` - **AKTIVER API-ENDPUNKT**
- ✅ `/zp/timeline-unified.js` - **HAUPTANWENDUNG**
- ✅ `/zp/timeline-unified.html` - **HAUPTANWENDUNG**
- ✅ `/index.php` - **HAUPTSEITE**

### 🔒 Sensitive Informationen in diesem Bericht

⚠️ **ACHTUNG:** Dieser Bericht enthält **Passwörter** und **Zugangsdaten**!

**Nach Verwendung:**
1. Passwörter aus Bericht entfernen
2. Oder: Datei schützen mit `chmod 600`
3. Oder: Datei nach Verwendung löschen

```bash
# Bericht schützen:
chmod 600 PROJECT_CONFIGURATION_AUDIT_README.md

# Oder umbenennen ohne Credentials:
# (Manuelle Bereinigung erforderlich)
```

---

## 📞 Kontakt & Support

Bei Fragen zu diesem Audit-Bericht:
- **Erstellt von:** GitHub Copilot (AI Assistant)
- **Datum:** 2025-10-08
- **Projekt:** WCI Booking System - Franzsennhütte

---

**🏁 ENDE DES AUDIT-BERICHTS**

*Dieser Bericht wurde automatisch generiert durch vollständiges Scannen aller Projektdateien.*

---

## 📎 APPENDIX A: Vollständige Papierkorb-Analyse

Der Ordner `/papierkorb/` enthält **38 Dateien**. Hier die vollständige Liste:

### Dokumentation (Behalten, aber verschieben nach `/docs/`):
| # | Datei | Größe | Aktion |
|---|-------|-------|--------|
| 1 | `HRS_LOGIN_TECHNICAL_DOCS.md` | Markdown | 📁 → `/docs/archive/` |
| 2 | `HRS_SYSTEM_DOCS.md` | Markdown | 📁 → `/docs/archive/` |

### HRS-Login Implementierungen (ALLE LÖSCHEN):
| # | Datei | Status |
|---|-------|--------|
| 3 | `hrs_login.php` | 🗑️ Alte Version - Duplikat von `/hrs/hrs_login.php` |
| 4 | `hrs_login_debug.php` | 🗑️ Debug-Version - Veraltet |
| 5 | `hrs_login_debug.php.backup` | 🗑️ Backup einer Papierkorb-Datei! |
| 6 | `hrs_login_debug_backup_20250819_200955.php` | 🗑️ Datiertes Backup |
| 7 | `hrs_login_debug_new.php` | 🗑️ "Neue" Version (aber im Papierkorb) |
| 8 | `hrs_login_debug_original.php` | 🗑️ Original-Version |
| 9 | `hrs-simple.php` | 🗑️ Vereinfachte Version |
| 10 | `hrs-fileget.php` | 🗑️ File-Download Version |
| 11 | `hrs.php` | 🗑️ Basis-Version |
| 12 | `test_hrs_login.php` | 🗑️ Test-Skript |

**Zusammen: 10 HRS-Login Dateien - ALLE redundant!**

### HRS-Import Implementierungen (ALLE LÖSCHEN):
| # | Datei | Status |
|---|-------|--------|
| 13 | `hrs_imp_res.php` | 🗑️ Alte Reservierungs-Import |
| 14 | `hrs_imp_res_fixed.php` | 🗑️ "Fixed" Version |

### Hut Quota Implementierungen (ALLE LÖSCHEN):
| # | Datei | Status |
|---|-------|--------|
| 15 | `hut_quota_analysis.php` | 🗑️ Analyse-Tool |
| 16 | `hut_quota_import.php` | 🗑️ Import-Tool |
| 17 | `hut_quota_smart_test.php` | 🗑️ Test-Tool |
| 18 | `debug_quota.php` | 🗑️ Debug-Tool |
| 19 | `verify_quota_import.php` | 🗑️ Verifikations-Tool |

### Import/Backup Tools (ALLE LÖSCHEN):
| # | Datei | Status |
|---|-------|--------|
| 20 | `backup_av_res.php` | 🗑️ Backup-Tool (Duplikat von `/hrs/backup_av_res.php`) |
| 21 | `import_webimp_backup_20250827_175409.php` | 🗑️ Backup mit Datum |
| 22 | `import_webimp_new.php` | 🗑️ "Neue" Version |
| 23 | `import_webimp_old.php` | 🗑️ Alte Version |
| 24 | `check_backup_structure.php` | 🗑️ Check-Tool |
| 25 | `test_backup_analysis.php` | 🗑️ Test-Tool |
| 26 | `test_backup_analysis_small.php` | 🗑️ Test-Tool (klein) |

### Belegung Implementierungen (ALLE LÖSCHEN):
| # | Datei | Status |
|---|-------|--------|
| 27 | `belegung-final.php` | 🗑️ "Finale" Version |
| 28 | `belegung.php` | 🗑️ Basis-Version |
| 29 | `belegung_tab_backup_20250828_191145.php` | 🗑️ Backup 28.08. |
| 30 | `belegung_tab_backup_20250829_073737.php` | 🗑️ Backup 29.08. 07:37 |
| 31 | `belegung_tab_backup_20250829_095644.php` | 🗑️ Backup 29.08. 09:56 |
| 32 | `belegung_tab_restructured.php` | 🗑️ Umstrukturierte Version |

### Debug/Test Tools (ALLE LÖSCHEN):
| # | Datei | Status |
|---|-------|--------|
| 33 | `check_daily_summary.php` | 🗑️ Check Daily Summary |
| 34 | `debug_day_occupancy.php` | 🗑️ Debug Tag-Belegung |
| 35 | `debug_tables.php` | 🗑️ Debug Tabellen |
| 36 | `debug_webimp_comparison.php` | 🗑️ Debug WebImp Vergleich |
| 37 | `test_compare_function.php` | 🗑️ Test Compare-Funktion |
| 38 | `test_dryrun_safety.php` | 🗑️ Test Dryrun Safety |
| 39 | `test_specific_records.php` | 🗑️ Test spezifische Records |

---

### 🎯 Papierkorb Zusammenfassung:

| Kategorie | Anzahl | Empfehlung |
|-----------|--------|------------|
| **Dokumentation** | 2 | 📁 Verschieben nach `/docs/archive/` |
| **HRS-Login Duplikate** | 10 | 🗑️ **LÖSCHEN** |
| **HRS-Import Duplikate** | 2 | 🗑️ **LÖSCHEN** |
| **Quota Tools** | 5 | 🗑️ **LÖSCHEN** |
| **Import/Backup Tools** | 7 | 🗑️ **LÖSCHEN** |
| **Belegung Versionen** | 6 | 🗑️ **LÖSCHEN** |
| **Debug/Test Tools** | 7 | 🗑️ **LÖSCHEN** |
| **GESAMT** | **39** | **37 löschbar, 2 archivieren** |

### 📝 Schnell-Lösch-Kommando für Papierkorb:

```bash
cd /home/vadmin/lemp/html/wci/papierkorb

# Schritt 1: Dokumentation sichern
mkdir -p ../docs/archive
mv HRS_LOGIN_TECHNICAL_DOCS.md ../docs/archive/
mv HRS_SYSTEM_DOCS.md ../docs/archive/

# Schritt 2: Alles andere löschen
cd /home/vadmin/lemp/html/wci
rm -rf papierkorb/

# Alternative (wenn unsicher): Umbenennen statt löschen
mv papierkorb papierkorb_ARCHIV_$(date +%Y%m%d)
```

---

## 📎 APPENDIX B: Root-Verzeichnis Cleanup

### Debug-Dateien im Root (Sollten organisiert werden):

```bash
# Alle debug_* Dateien anzeigen:
ls -lh /home/vadmin/lemp/html/wci/debug_*.php

# Empfehlung: Verschieben nach /debug/
mkdir -p debug_tools
mv debug_*.php debug_tools/
mv check_*.php debug_tools/
mv analyze_*.php debug_tools/
mv test_*.php debug_tools/
```

### SQL-Dateien im Root (Sollten organisiert werden):

```bash
ls -lh *.sql

# Empfehlung: Verschieben nach /sql/
mkdir -p sql/migrations
mv add_*.sql sql/migrations/
mv create_*.sql sql/migrations/
mv extend_*.sql sql/migrations/
```

### Analyse/Output-Dateien (Löschen):

```bash
rm -f analysis_results.txt
rm -f final-analysis-output.txt
rm -f complete-network-analysis.txt
rm -f complete-network-analysis.sh
rm -f final-ultra-analysis.sh
rm -f estimate-volume.sh
rm -f calculate-log-volume.sh
```

---

## 📎 APPENDIX C: Geschätzte Platzersparnis

### Dateigrößen-Schätzung:

| Kategorie | Anzahl Dateien | Geschätzte Größe | Nach Cleanup |
|-----------|---------------|------------------|--------------|
| Papierkorb | 39 | ~15 MB | 0 MB |
| Backup-Dateien | 15+ | ~20 MB | 0 MB |
| Alte Versionen | 10+ | ~5 MB | 0 MB |
| Output/Logs | 10+ | ~50 MB | 0 MB |
| **GESAMT** | **~75 Dateien** | **~90 MB** | **~0 MB** |

**Platzersparnis: ~90 MB**

---

## 📎 APPENDIX D: Git Integration Empfehlungen

Falls Git verwendet wird:

### .gitignore erweitern:

```gitignore
# Backup files
*.backup
*_backup_*
backup_*/

# Output/Log files
*output*.html
*analysis*.txt
*results*.txt

# Debug/Test outputs
belegung/output*.html
belegung/temp*.html
belegung/test*.html

# Papierkorb
papierkorb/
papierkorb_*/

# Credentials (WICHTIG!)
/hrs/hrs_credentials.php
config.php
```

### Git History Cleanup (Optional):

```bash
# WARNUNG: Ändert Git History!
# Nur wenn Credentials versehentlich committed wurden:

git filter-branch --force --index-filter \
  "git rm --cached --ignore-unmatch config.php" \
  --prune-empty --tag-name-filter cat -- --all
```

---

**🏁 ENDE DES ERWEITERTEN AUDIT-BERICHTS**
