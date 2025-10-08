# Reservierungs-Import Workflow - Komplette Dokumentation

## Übersicht

Der Reservierungs-Import läuft in **zwei Stufen**:

```
HRS API → AV-Res-webImp (Zwischentabelle) → AV-Res (Production)
         └─────────────────┘                   └──────────────┘
         Step 1: HRS Import                    Step 2: WebImp → Production
         hrs_imp_res_stream.php                import_webimp.php
```

## Warum zwei Stufen?

### Vorteile des Zwischentabellen-Ansatzes:

1. **Sicherheit:** Daten werden erst geprüft, bevor sie in Production kommen
2. **Dry-Run:** Import kann testweise ausgeführt werden ohne Production zu ändern
3. **Backup:** Automatisches Backup vor jedem Production-Import
4. **Korrektur:** Bei Fehlern im HRS-Import können Daten manuell korrigiert werden
5. **Review:** Admin kann Änderungen vor Übernahme prüfen

## Step 1: HRS Import → AV-Res-webImp

### Datei: `/wci/hrs/hrs_imp_res_stream.php`

**Was passiert:**
- Reservierungen werden von HRS API abgerufen
- Daten werden in `AV-Res-webImp` (Zwischentabelle) gespeichert
- ON DUPLICATE KEY UPDATE → bestehende Einträge werden aktualisiert
- **Noch keine Änderung in Production!**

**Tabelle:** `AV-Res-webImp`
```sql
CREATE TABLE `AV-Res-webImp` (
  `local_id` int NOT NULL AUTO_INCREMENT,
  `hrs_reservation_id` int NOT NULL,
  `hut_id` int DEFAULT NULL,
  `guest_id` int DEFAULT NULL,
  `guest_name` varchar(255),
  `arrival_date` date DEFAULT NULL,
  `departure_date` date DEFAULT NULL,
  `status` varchar(50),               -- CONFIRMED, DISCARDED, SUBMITTED, ON_WAITING_LIST
  `half_board` tinyint(1),
  `vegetarian` tinyint(1),
  `mountain_guide` tinyint(1),
  `number_of_guests` int,
  `number_of_children` int,
  `category_type` varchar(10),
  `is_winteraum` tinyint(1),
  `created_at` datetime,
  `updated_at` datetime,
  PRIMARY KEY (`local_id`),
  UNIQUE KEY `unique_hrs_reservation` (`hrs_reservation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**SSE Output:**
```
[22:21:45] ℹ️ Rufe Reservierungen von HRS ab...
[22:21:46] ℹ️ 30 Reservierungen erhalten
[22:21:46] ✓ Reservierung 5658588 importiert
[22:21:46] ✓ Reservierung 5639508 importiert
...
[22:21:48] ✅ Import abgeschlossen: 30 von 30 Reservierungen importiert
```

**Status nach Step 1:**
- ✅ `AV-Res-webImp` hat neue Daten
- ❌ `AV-Res` ist noch unverändert
- ⚠️ **User muss jetzt "WebImp → Production" Button klicken!**

---

## Step 2: WebImp → Production

### Datei: `/wci/hrs/import_webimp.php`

**Was passiert:**
1. **Backup erstellen:** `AV-Res` → `AV_Res_PreImport_YYYY-MM-DD_HH-MM-SS`
2. **Daten filtern:** Nur CONFIRMED und DISCARDED verarbeiten
3. **Status-Mapping:** CONFIRMED → storno=false, DISCARDED → storno=true
4. **HP-Mapping:** hp=0 → arr=5, hp=1 → arr=1
5. **Daten vergleichen:** Nur Änderungen übertragen
6. **Production updaten:** INSERT/UPDATE in `AV-Res`
7. **Zwischentabelle leeren:** `AV-Res-webImp` wird geleert (optional)

**Tabelle:** `AV-Res` (Production)
```sql
CREATE TABLE `AV-Res` (
  `av_id` int NOT NULL AUTO_INCREMENT,
  `anreise` date NOT NULL,
  `abreise` date NOT NULL,
  `lager` varchar(5),
  `betten` int,
  `dz` int,
  `sonder` int,
  `arr` int,                          -- 1=mit HP, 5=ohne HP
  `storno` tinyint(1) DEFAULT 0,      -- 0=aktiv, 1=storniert
  `gruppe` varchar(255),
  `bem_av` text,
  `handy` varchar(50),
  `email` varchar(255),
  `vorgang` int,                      -- hrs_reservation_id
  `updated_at` timestamp,
  PRIMARY KEY (`av_id`),
  UNIQUE KEY `unique_vorgang` (`vorgang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**UI-Buttons in belegung_tab.php:**

1. **"WebImp → Production"** (Grün)
   - Führt echten Import aus
   - Erstellt Backup
   - Leert Zwischentabelle nach Erfolg

2. **"Dry-Run Test"** (Orange)
   - Simuliert Import
   - Zeigt detaillierte Analyse
   - **Keine** Änderungen in Production
   - Zwischentabelle bleibt erhalten

**Vergleichsfelder:**
```php
$compareFields = [
    'anreise',
    'abreise',
    'lager',
    'betten',
    'dz',
    'sonder',
    'gruppe',
    'bem_av',
    'handy',
    'email',
    'vorgang'
];
```

**Status-Filter:**
```php
// Nur CONFIRMED und DISCARDED werden verarbeitet
$validStatuses = ['CONFIRMED', 'DISCARDED'];

// SUBMITTED und ON_WAITING_LIST werden IGNORIERT
// (bleiben in Zwischentabelle, werden nicht nach Production übertragen)
```

**Mapping-Logik:**
```php
// Status Mapping
if ($status === 'CONFIRMED') {
    $storno = 0; // Aktiv
} elseif ($status === 'DISCARDED') {
    $storno = 1; // Storniert
}

// Half-Board Mapping
if ($half_board == 1) {
    $arr = 1; // Mit Halbpension
} else {
    $arr = 5; // Ohne Halbpension
}

// Kategorie Mapping
$lager = $category_type; // ML, MBZ, 2BZ, SK
```

**Dry-Run Output:**
```
✅ WebImp Dry-Run erfolgreich!

Verarbeitet: 30
Neu eingefügt: 15
Aktualisiert: 10
Unverändert: 5

📋 Detaillierte Änderungen:
- Reservierung 5658588: NEU (2026-02-14, ML, 2 Betten)
- Reservierung 5639508: UPDATE (Gast-Name geändert)
- Reservierung 5629491: UNCHANGED
...

⚠️ Dies war nur ein Test!
Keine Änderungen wurden in die Production-Tabelle übernommen.
```

**Echter Import Output:**
```
✅ WebImp Import erfolgreich!

Verarbeitet: 30
Neu eingefügt: 15
Aktualisiert: 10
Unverändert: 5

💾 Backup: AV_Res_PreImport_2025-10-08_22-25-30 (764 Datensätze)

📝 Zwischentabelle wurde geleert
```

---

## Kompletter Workflow

### Szenario: 14 Tage HRS Import

#### Phase 1: HRS → WebImp (Timeline)

1. **User:** Selektiert 14 Tage im Histogram
2. **User:** Klickt "HRS Import" Button
3. **Modal öffnet:** Zeigt 4 Steps
4. **Step 3 läuft:** Reservations Import (SSE)
   ```
   [22:21:45] ℹ️ Rufe Reservierungen ab...
   [22:21:46] ✓ Res 5658588 importiert
   ...
   [22:21:48] ✅ 30 von 30 importiert
   ```
5. **Status:** `AV-Res-webImp` hat jetzt 30 neue/aktualisierte Einträge
6. **Status:** `AV-Res` (Production) ist noch unverändert

#### Phase 2: WebImp → Production (Belegung)

7. **User:** Öffnet `/wci/belegung/belegung_tab.php`
8. **Optional:** Klickt "Dry-Run Test" zum Prüfen
   ```
   🔍 Analysiere...
   ✅ 15 NEU, 10 UPDATE, 5 UNCHANGED
   (Noch keine Änderungen!)
   ```
9. **User:** Klickt "WebImp → Production"
10. **Bestätigung:** "Sollen die Daten importiert werden?"
11. **Backup:** Automatisches Backup wird erstellt
    ```
    💾 AV_Res_PreImport_2025-10-08_22-25-30
    ```
12. **Import:** Daten werden nach `AV-Res` übertragen
13. **Cleanup:** `AV-Res-webImp` wird geleert
14. **Fertig:** Production-Tabelle aktualisiert!

---

## Technische Details

### Status-Behandlung in WebImp

```php
// import_webimp.php - Lines ~200-250

$statusMap = [
    'CONFIRMED' => ['process' => true, 'storno' => 0],
    'DISCARDED' => ['process' => true, 'storno' => 1],
    'SUBMITTED' => ['process' => false], // IGNORIERT
    'ON_WAITING_LIST' => ['process' => false] // IGNORIERT
];

foreach ($webimpData as $res) {
    $status = $res['status'];
    
    if (!isset($statusMap[$status]) || !$statusMap[$status]['process']) {
        // Skip this reservation
        continue;
    }
    
    $storno = $statusMap[$status]['storno'];
    // ... weiterer Code
}
```

### Vergleichslogik (Change Detection)

```php
// Prüfe ob Reservierung bereits in Production existiert
$checkQuery = "SELECT * FROM `AV-Res` WHERE vorgang = ?";
$stmt = $mysqli->prepare($checkQuery);
$stmt->bind_param('i', $hrs_reservation_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

if ($existing) {
    // Prüfe ob Änderungen vorhanden
    $hasChanges = false;
    foreach ($compareFields as $field) {
        if ($existing[$field] != $newData[$field]) {
            $hasChanges = true;
            break;
        }
    }
    
    if ($hasChanges) {
        // UPDATE
        $updated++;
    } else {
        // UNCHANGED
        $unchanged++;
    }
} else {
    // INSERT
    $inserted++;
}
```

### Backup-System

```php
function createAutoBackup() {
    $timestamp = date('Y-m-d_H-i-s');
    $backupTableName = "AV_Res_PreImport_$timestamp";
    
    // 1. Struktur kopieren
    $mysqli->query("CREATE TABLE `$backupTableName` LIKE `AV-Res`");
    
    // 2. Daten kopieren
    $mysqli->query("INSERT INTO `$backupTableName` SELECT * FROM `AV-Res`");
    
    // 3. Anzahl prüfen
    $result = $mysqli->query("SELECT COUNT(*) as count FROM `$backupTableName`");
    $count = $result->fetch_assoc()['count'];
    
    return [
        'backup_name' => $backupTableName,
        'record_count' => $count
    ];
}
```

**Backup-Tabellen:**
```
AV_Res_PreImport_2025-10-08_22-25-30  (764 rows)
AV_Res_PreImport_2025-10-08_19-15-42  (760 rows)
AV_Res_PreImport_2025-10-07_14-30-11  (755 rows)
...
```

**Backup-Verwaltung:**
- Button "Backup-Verwaltung" in belegung_tab.php
- Zeigt alle Backup-Tabellen
- Ermöglicht Restore aus Backup
- Ermöglicht Löschen alter Backups

---

## SSE-Integration für WebImp → Production

### Aktueller Stand

**Problem:** Der "WebImp → Production" Button nutzt noch **keine** SSE!

**Aktueller Code (belegung_tab.php):**
```javascript
async function importWebImpData(dryRun = false) {
    const url = dryRun 
        ? '/wci/hrs/import_webimp.php?json=1&dry-run=1'
        : '/wci/hrs/import_webimp.php?json=1';
    
    const response = await fetch(url);
    const data = await response.json();
    
    // Zeigt nur Endergebnis, keine Live-Updates!
    alert(`Verarbeitet: ${data.total}\nNeu: ${data.inserted}\n...`);
}
```

### Mögliche Verbesserung (Optional)

**SSE-Version erstellen:**

1. **Neue Datei:** `import_webimp_stream.php`
2. **SSE Events:**
   - `start` - Backup wird erstellt
   - `backup` - Backup-Info
   - `total` - Anzahl Reservierungen in WebImp
   - `progress` - Fortschritt pro Reservierung
   - `log` - INSERT/UPDATE/UNCHANGED
   - `complete` - Import fertig
   - `cleanup` - Zwischentabelle geleert
   - `finish` - Alles fertig

3. **Live-Updates:**
   ```
   [22:25:30] 💾 Erstelle Backup...
   [22:25:31] ✓ Backup erstellt: AV_Res_PreImport_2025-10-08_22-25-30 (764 rows)
   [22:25:31] 📊 30 Reservierungen zu verarbeiten
   [22:25:32] ✓ Res 5658588: NEU eingefügt
   [22:25:32] ✓ Res 5639508: Aktualisiert (Gast-Name geändert)
   [22:25:32] • Res 5629491: Unverändert
   ...
   [22:25:35] 📝 Zwischentabelle geleert (30 Einträge)
   [22:25:35] ✅ Import abgeschlossen: 15 NEU, 10 UPDATE, 5 UNCHANGED
   ```

**Vorteil:**
- User sieht Live-Fortschritt auch bei WebImp → Production
- Konsistente UX über alle Import-Steps
- Bessere Transparenz bei großen Datenmengen

**Aktuell nicht implementiert** - könnte später hinzugefügt werden.

---

## Zusammenfassung

### Zwei-Stufen-System

| Step | Datei | Von | Nach | Button | Live-Updates |
|------|-------|-----|------|--------|--------------|
| 1 | hrs_imp_res_stream.php | HRS API | AV-Res-webImp | Timeline: "HRS Import" | ✅ SSE |
| 2 | import_webimp.php | AV-Res-webImp | AV-Res | Belegung: "WebImp → Production" | ❌ Kein SSE |

### Workflow

1. **HRS Import (Timeline):**
   - Daten von HRS → Zwischentabelle
   - Mit Live-Updates (SSE)
   - Production noch unverändert

2. **WebImp Import (Belegung):**
   - Daten von Zwischentabelle → Production
   - Mit Backup, Filter, Mapping
   - Aktuell ohne Live-Updates

### Status-Filter

- ✅ **CONFIRMED** → verarbeiten (storno=0)
- ✅ **DISCARDED** → verarbeiten (storno=1)
- ❌ **SUBMITTED** → ignorieren
- ❌ **ON_WAITING_LIST** → ignorieren

### Sicherheit

- ✅ Automatisches Backup vor jedem Production-Import
- ✅ Dry-Run Modus zum Testen
- ✅ Vergleichslogik (nur Änderungen)
- ✅ Zwischentabelle als Safety-Net

### Vorteile

1. **Sicherheit:** Backup vor jedem Import
2. **Kontrolle:** Review vor Production
3. **Flexibilität:** Dry-Run, manuelle Korrektur
4. **Transparenz:** Detaillierte Logs
5. **Rollback:** Restore aus Backup möglich

**Status:** Production-ready! 🚀

Das Zwei-Stufen-System ist bewährte Best Practice für kritische Daten-Imports.
