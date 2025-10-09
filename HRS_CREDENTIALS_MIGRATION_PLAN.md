# 🎯 Zentralisierung: HRS Credentials & HutID

**Erstellt:** 2025-10-09  
**Basierend auf:** PROJECT_CONFIGURATION_AUDIT_README.md  
**Ziel:** Einmalige zentrale Definition aller HRS-bezogenen Konfigurationen

---

## 📋 IST-Zustand (Audit-Ergebnisse)

### 🔴 Problem 1: HRS-Credentials (13 Duplikate)

| # | Datei | Username | Password | Status |
|---|-------|----------|----------|--------|
| 1 | `/hrs/hrs_login.php` | `office@franzsennhuette.at` | `Fsh2147m!3` | ✅ MASTER |
| 2 | `/debug_reservation_structure.php` | `office@franzsennhuette.at` | `Fsh2147m!3` | ⚠️ Debug |
| 3 | `/analyze_real_quota.php` | `office@franzsennhuette.at` | `Fsh2147m!3` | ⚠️ Debug |
| 4 | `/debug_reservation_timeframe.php` | N/A | `9Ke^z5xG` | ⚠️ ANDERES PW! |
| 5-13 | Papierkorb/Backups | Verschiedene | Verschiedene | 🗑️ Archiviert |

### 🔴 Problem 2: HutID (50+ Duplikate)

**Gefunden in:**
- 5x Produktions-Importer (`hrs_imp_*.php`)
- 1x API-Endpunkt (`get_av_cap_range_stream.php`)
- 1x Timeline (`timeline-unified.html`)
- 3x Debug-Tools
- 20+ Dokumentations-Dateien
- 20+ Papierkorb-Dateien

---

## 🎯 SOLL-Zustand

### Zentrale Konfigurationsdatei erstellen:

```
/hrs/hrs_credentials.php
```

**Inhalt:**
```php
<?php
/**
 * HRS System - Zentrale Konfiguration
 * 
 * Diese Datei enthält ALLE HRS-bezogenen Credentials und Konfigurationen.
 * NIEMALS direkt committen - sollte in .gitignore!
 * 
 * @created 2025-10-09
 */

// === HRS Login Credentials ===
define('HRS_USERNAME', 'office@franzsennhuette.at');
define('HRS_PASSWORD', 'Fsh2147m!3');
define('HRS_BASE_URL', 'https://www.hut-reservation.org');

// === Hütten-Konfiguration ===
define('HUT_ID', 675);
define('HUT_NAME', 'Franzsennhütte');

// === Legacy-Support (für alten Code) ===
$GLOBALS['hutId'] = HUT_ID;

// === Optional: HRS-API Endpoints ===
define('HRS_API_QUOTA', HRS_BASE_URL . '/api/v1/manage/hutQuota');
define('HRS_API_RESERVATION', HRS_BASE_URL . '/api/v1/manage/reservation/list');
define('HRS_API_AVAILABILITY', HRS_BASE_URL . '/getHutAvailability');

// === Cleanup-Funktionen ===
function getHrsCredentials() {
    return [
        'username' => HRS_USERNAME,
        'password' => HRS_PASSWORD,
        'base_url' => HRS_BASE_URL
    ];
}

function getHutConfig() {
    return [
        'id' => HUT_ID,
        'name' => HUT_NAME
    ];
}
```

---

## 📝 Migrations-Plan

### Phase 1: Vorbereitung (Tag 1)

#### 1.1 Zentrale Config erstellen
```bash
cd /home/vadmin/lemp/html/wci
nano hrs/hrs_credentials.php
# Inhalt einfügen (siehe oben)
chmod 600 hrs/hrs_credentials.php  # Nur Owner kann lesen
```

#### 1.2 .gitignore erweitern
```bash
echo "" >> .gitignore
echo "# HRS Credentials (SECURITY)" >> .gitignore
echo "hrs/hrs_credentials.php" >> .gitignore
```

#### 1.3 Template erstellen (für Git)
```bash
cp hrs/hrs_credentials.php hrs/hrs_credentials.php.template
# In Template: Passwort durch 'HIER_PASSWORT_EINTRAGEN' ersetzen
git add hrs/hrs_credentials.php.template
```

---

### Phase 2: HRS-Login anpassen (Tag 1)

#### 2.1 `/hrs/hrs_login.php` migrieren

**Aktuell (Zeile 90-91):**
```php
private $username = 'office@franzsennhuette.at';
private $password = 'Fsh2147m!3';
```

**NEU:**
```php
private $username;
private $password;

public function __construct() {
    // Lade zentrale Credentials
    require_once(__DIR__ . '/hrs_credentials.php');
    $this->username = HRS_USERNAME;
    $this->password = HRS_PASSWORD;
}
```

#### 2.2 Test durchführen
```bash
# HRS-Login testen
php -r "require 'hrs/hrs_login.php'; echo 'OK';"
```

---

### Phase 3: HRS-Importer migrieren (Tag 2)

#### 3.1 Alle hrs_imp_*.php Dateien anpassen

**Zu ändernde Dateien:**
1. `/hrs/hrs_imp_daily.php` (Zeile 61)
2. `/hrs/hrs_imp_daily_stream.php` (Zeile 59)
3. `/hrs/hrs_imp_quota_stream.php` (Zeile 58)
4. `/hrs/hrs_imp_res_stream.php` (Zeile 52)
5. `/hrs/hrs_del_quota.php` (Zeile 44)

**Aktuell:**
```php
private $hutId = 675;
```

**NEU:**
```php
private $hutId;

public function __construct() {
    require_once(__DIR__ . '/hrs_credentials.php');
    $this->hutId = HUT_ID;
}
```

#### 3.2 Test durchführen
```bash
# Quota-Import testen
# In Browser: /wci/hrs/hrs_imp_quota_stream.php
```

---

### Phase 4: API-Endpunkte migrieren (Tag 2)

#### 4.1 `/api/imps/get_av_cap_range_stream.php`

**Aktuell (Zeile 6):**
```php
$apiUrl = "https://www.hut-reservation.org/getHutAvailability?hutID=675&step=WIZARD&from=";
```

**NEU (am Anfang der Datei):**
```php
require_once(__DIR__ . '/../../hrs/hrs_credentials.php');

// Später im Code:
$apiUrl = HRS_API_AVAILABILITY . "?hutID=" . HUT_ID . "&step=WIZARD&from=";
```

---

### Phase 5: Frontend migrieren (Tag 2)

#### 5.1 `/zp/timeline-unified.html`

**Problem:** JavaScript kann nicht direkt auf PHP-Konstanten zugreifen!

**Lösung:** Neuen API-Endpunkt erstellen:

**Neue Datei:** `/api/getHrsConfig.php`
```php
<?php
require_once(__DIR__ . '/../hrs/hrs_credentials.php');

header('Content-Type: application/json');
header('Cache-Control: max-age=3600'); // 1 Stunde cachen

echo json_encode([
    'hutId' => HUT_ID,
    'hutName' => HUT_NAME,
    'baseUrl' => HRS_BASE_URL
]);
```

**In timeline-unified.html (am Anfang):**
```javascript
let HRS_CONFIG = null;

async function loadHrsConfig() {
    const response = await fetch('/wci/api/getHrsConfig.php');
    HRS_CONFIG = await response.json();
}

// Beim Start laden:
await loadHrsConfig();

// Dann verwenden:
const hutId = HRS_CONFIG.hutId; // statt hardcoded 675
```

---

### Phase 6: Debug-Tools migrieren (Tag 3)

#### 6.1 Debug-Dateien anpassen

**Zu ändernde Dateien:**
1. `/debug_reservation_structure.php`
2. `/analyze_real_quota.php`
3. `/debug_reservation_timeframe.php`
4. `/test_login_and_import.php`
5. `/daily_summary_import.php`

**Pattern:**

**Aktuell:**
```php
$username = 'office@franzsennhuette.at';
$password = 'Fsh2147m!3';
$hutId = 675;
```

**NEU:**
```php
require_once(__DIR__ . '/hrs/hrs_credentials.php');

$username = HRS_USERNAME;
$password = HRS_PASSWORD;
$hutId = HUT_ID;
```

---

### Phase 7: config.php erweitern (Tag 3)

#### 7.1 HutID in Master-Config

**`/config.php` erweitern:**
```php
// ... bestehende DB-Config ...

// === Hut-Konfiguration ===
// (wird auch von hrs_credentials.php definiert, hier für Non-HRS Code)
if (!defined('HUT_ID')) {
    define('HUT_ID', 675);
    define('HUT_NAME', 'Franzsennhütte');
    $GLOBALS['hutId'] = HUT_ID;
}
```

**Vorteil:** Code ohne HRS-Kontext kann trotzdem HUT_ID nutzen.

---

## 🔍 Automatische Migration (Script)

### Script: `migrate_hrs_credentials.sh`

```bash
#!/bin/bash
# migrate_hrs_credentials.sh - Automatische Migration

set -e

echo "=== HRS Credentials Migration ==="
echo ""

# 1. Zentrale Config erstellen
if [ ! -f "hrs/hrs_credentials.php" ]; then
    echo "✓ Erstelle hrs/hrs_credentials.php"
    cat > hrs/hrs_credentials.php << 'EOF'
<?php
/**
 * HRS System - Zentrale Konfiguration
 */

define('HRS_USERNAME', 'office@franzsennhuette.at');
define('HRS_PASSWORD', 'Fsh2147m!3');
define('HRS_BASE_URL', 'https://www.hut-reservation.org');
define('HUT_ID', 675);
define('HUT_NAME', 'Franzsennhütte');

$GLOBALS['hutId'] = HUT_ID;
EOF
    chmod 600 hrs/hrs_credentials.php
else
    echo "⊘ hrs/hrs_credentials.php existiert bereits"
fi

# 2. .gitignore erweitern
if ! grep -q "hrs/hrs_credentials.php" .gitignore 2>/dev/null; then
    echo "✓ Füge zu .gitignore hinzu"
    echo "" >> .gitignore
    echo "# HRS Credentials" >> .gitignore
    echo "hrs/hrs_credentials.php" >> .gitignore
else
    echo "⊘ .gitignore bereits aktualisiert"
fi

# 3. Template erstellen
if [ ! -f "hrs/hrs_credentials.php.template" ]; then
    echo "✓ Erstelle Template"
    sed 's/Fsh2147m!3/HIER_PASSWORT_EINTRAGEN/g' hrs/hrs_credentials.php > hrs/hrs_credentials.php.template
fi

echo ""
echo "=== Migration vorbereitet ==="
echo ""
echo "Nächste Schritte (manuell):"
echo "1. Dateien in /hrs/ anpassen (siehe PLAN)"
echo "2. API-Endpunkte anpassen"
echo "3. Frontend anpassen (timeline-unified.html)"
echo "4. Debug-Tools anpassen"
echo "5. System testen"
echo ""
```

---

## ✅ Test-Checkliste

### Nach jeder Phase testen:

- [ ] **Phase 2:** HRS-Login funktioniert
  ```bash
  # Test in Browser: /wci/hrs/hrs_imp_daily_stream.php
  ```

- [ ] **Phase 3:** Alle Importer funktionieren
  - [ ] Quota-Import
  - [ ] Reservierungs-Import
  - [ ] Daily Summary Import
  - [ ] AV Capacity Import

- [ ] **Phase 4:** API-Endpunkte funktionieren
  ```bash
  curl http://localhost/wci/api/imps/get_av_cap_range_stream.php
  ```

- [ ] **Phase 5:** Frontend funktioniert
  - [ ] Timeline lädt
  - [ ] Histogram wird angezeigt
  - [ ] Keine Console-Fehler

- [ ] **Phase 6:** Debug-Tools funktionieren
  - [ ] Debug-Scripts laufen durch
  - [ ] Keine Credential-Fehler

---

## 📊 Vorher/Nachher Vergleich

| Metrik | Vorher | Nachher |
|--------|--------|---------|
| **HRS Username Definitionen** | 13 | 1 ✅ |
| **HRS Password Definitionen** | 13 | 1 ✅ |
| **HutID Definitionen** | 50+ | 1 ✅ |
| **Wartbarkeit** | 😞 Schwierig | 😊 Einfach |
| **Sicherheit** | ⚠️ Credentials im Git | ✅ .gitignore |
| **Bei Passwort-Änderung** | 13 Dateien ändern | 1 Datei ändern |

---

## 🚀 Timeline

### Empfohlener Zeitplan:

**Tag 1 (2h):**
- ✅ Zentrale Config erstellen
- ✅ .gitignore anpassen
- ✅ hrs_login.php migrieren
- ✅ Testen

**Tag 2 (3h):**
- ✅ Alle HRS-Importer migrieren
- ✅ API-Endpunkte migrieren
- ✅ Frontend API erstellen
- ✅ Testen

**Tag 3 (2h):**
- ✅ Debug-Tools migrieren
- ✅ config.php erweitern
- ✅ Finales Testing
- ✅ Dokumentation

**GESAMT: ~7 Stunden**

---

## ⚠️ Risiken & Mitigation

### Risiko 1: Passwort vergessen
**Mitigation:** Template-Datei im Git + Backup außerhalb Git

### Risiko 2: Alte Dateien brechen
**Mitigation:** Schrittweise Migration + Tests nach jeder Phase

### Risiko 3: Cache-Probleme (Frontend)
**Mitigation:** Hard-Refresh (Ctrl+Shift+R) nach Änderungen

### Risiko 4: Produktions-Ausfall
**Mitigation:** 
- Backup vor Migration
- Migration außerhalb der Stoßzeiten
- Git-Commit nach jeder erfolgreichen Phase

---

## 📝 Erfolgs-Kriterien

Nach erfolgreicher Migration:

1. ✅ Nur 1x Username/Password definiert (in `hrs_credentials.php`)
2. ✅ Nur 1x HutID definiert (in `hrs_credentials.php` + `config.php`)
3. ✅ Credentials nicht mehr im Git
4. ✅ Alle HRS-Funktionen arbeiten weiterhin
5. ✅ Frontend lädt ohne Fehler
6. ✅ Debug-Tools funktionieren
7. ✅ Dokumentation aktualisiert

---

## 🔗 Weitere Optimierungen (Optional)

### Nach erfolgreicher Migration:

1. **Credentials-Rotation:**
   ```php
   // hrs_credentials.php erweitern:
   define('HRS_PASSWORD_LAST_CHANGED', '2025-10-09');
   // Reminder nach 90 Tagen
   ```

2. **Environment Variables (noch sicherer):**
   ```php
   define('HRS_USERNAME', getenv('HRS_USERNAME') ?: 'fallback@email.at');
   define('HRS_PASSWORD', getenv('HRS_PASSWORD') ?: 'CHANGE_ME');
   ```

3. **Credentials-Validator:**
   ```php
   function validateHrsCredentials() {
       if (HRS_PASSWORD === 'HIER_PASSWORT_EINTRAGEN') {
           throw new Exception('HRS Credentials nicht konfiguriert!');
       }
   }
   ```

---

**Plan erstellt von:** GitHub Copilot AI Assistant  
**Datum:** 2025-10-09  
**Basierend auf:** Vollständigem Projekt-Audit  
**Geschätzte Umsetzungsdauer:** 7 Stunden über 3 Tage
