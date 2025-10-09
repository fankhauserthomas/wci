# 🎯 QUOTA-OPTIMIERUNG & VERWALTUNG - IMPLEMENTIERUNGSPLAN

**Projekt:** Timeline Quota-Management System  
**Datum:** 2025-10-09  
**Status:** 📋 Planning Phase

---

## 📊 ÜBERSICHT

Implementierung eines vollständigen Quota-Management-Systems in der Timeline mit:
- Quota-Eingabe für selektierte Tage
- Automatische Quota-Optimierung (Gast-Umverteilung)
- Mehrtägige/Eintägige Quota-Konvertierung
- Intelligente Quota-Aufteilung bei Überlappung

---

## 🎯 PHASE 1: QUOTA-EINGABE & SPEICHERUNG

### 1.1 Frontend: Quota-Eingabe Modal (PRIORITÄTS-BASIERT)

**Datei:** `/zp/timeline-unified.js`

#### Features:
- [ ] Modal für Quota-Eingabe bei Histogram-Tag-Selektion
- [ ] **NEUE LOGIK:** Prioritäts-basierte Zuteilung
  - **Zielkapazität** (Gesamt): Slider/Input (z.B. 28 Plätze)
  - **Priorität 1-4** mit Kategorie + Max-Wert:
    - Priorität 1: [Dropdown: Lager] + [Input: leer oder Wert]
    - Priorität 2: [Dropdown: Betten] + [Input: 10]
    - Priorität 3: [Dropdown: Sonder] + [Input: 2]
    - Priorität 4: [Dropdown: DZ] + [Input: 4]
- [ ] **Auto-Berechnung** bei Änderung der Zielkapazität:
  - Wenn Zielkapazität <= Summe Prio-Werte: Cutoff nach Priorität
  - Wenn Zielkapazität > Summe Prio-Werte: Restkapazität zu Prio 1 (ohne Limit)
- [ ] Formular-Felder:
  - `target_capacity` (Zielauslastung Gesamt am Tag)
  - `priority_1_category` + `priority_1_max` (optional, ∞ wenn leer)
  - `priority_2_category` + `priority_2_max`
  - `priority_3_category` + `priority_3_max`
  - `priority_4_category` + `priority_4_max`
  - `date_from` (Start - automatisch: erster selektierter Tag)
  - `date_to` (Ende - automatisch: letzter selektierter Tag)
- [ ] Validierung:
  - Zielkapazität >= 0
  - Wenn aktuelle Belegung > Zielkapazität: **Quotas = 0** (alle Kategorien)
  - Keine negativen Werte
  - Nur Ganzzahlen
  - Keine Duplikate bei Kategorie-Auswahl

#### Code-Struktur:
```javascript
class QuotaInputModal {
    constructor(renderer) { 
        this.priorities = [
            { category: 'lager', max: null }, // null = unbegrenzt
            { category: 'betten', max: 10 },
            { category: 'sonder', max: 2 },
            { category: 'dz', max: 4 }
        ];
    }
    
    show(selectedDays) {
        // 1. Berechne date_from/date_to aus Selektion
        // 2. Lade aktuelle Belegung für Bereich
        // 3. Zeige Formular mit Zielkapazität + Prioritäten
        // 4. Auto-Update bei Änderung
    }
    
    calculateQuotaDistribution(targetCapacity, priorities) {
        // WICHTIG: Prioritäts-basierte Zuteilung
        
        const result = { lager: 0, betten: 0, dz: 0, sonder: 0 };
        let remainingCapacity = targetCapacity;
        
        // Durchlaufe Prioritäten von 1 bis 4
        for (const prio of priorities) {
            if (remainingCapacity <= 0) break;
            
            const maxValue = prio.max !== null ? prio.max : Infinity;
            const allocated = Math.min(remainingCapacity, maxValue);
            
            result[prio.category] = allocated;
            remainingCapacity -= allocated;
        }
        
        return result;
    }
    
    // BEISPIELE:
    // targetCapacity = 10, priorities = [lager:∞, betten:10, sonder:2, dz:4]
    // → lager: 10, betten: 0, sonder: 0, dz: 0
    
    // targetCapacity = 15
    // → lager: 10 (nimmt was übrig), betten: 5 (von max 10), sonder: 0, dz: 0
    // FALSCH! Prio 1 (Lager) hat KEIN Limit, also:
    // → lager: 15, betten: 0, sonder: 0, dz: 0
    
    // KORREKTUR: Prio 1 MIT max-Wert für korrektes Verhalten
    // targetCapacity = 15, priorities = [lager:12, betten:10, sonder:2, dz:4]
    // → lager: 12, betten: 3, sonder: 0, dz: 0
    
    // targetCapacity = 21
    // → lager: 12, betten: 8, sonder: 1, dz: 0
    
    // targetCapacity = 28
    // → lager: 12, betten: 10, sonder: 2, dz: 4
    
    // targetCapacity = 59 (über Summe)
    // → lager: 33 (12 + Rest 21), betten: 10, sonder: 2, dz: 4
    
    validate(formData) {
        // Validierungs-Logik
        if (formData.targetCapacity < 0) return false;
        
        // Check: Aktuelle Belegung > Ziel?
        const currentOccupancy = this.getCurrentOccupancy();
        if (currentOccupancy > formData.targetCapacity) {
            // WARNUNG: Quotas werden auf 0 gesetzt!
            return { 
                warning: true, 
                message: 'Aktuelle Belegung überschreitet Zielkapazität. Quotas werden auf 0 gesetzt.',
                quotas: { lager: 0, betten: 0, dz: 0, sonder: 0 }
            };
        }
        
        return true;
    }
    
    async save(formData) {
        // Berechne finale Quotas basierend auf Prioritäten
        const quotas = this.calculateQuotaDistribution(
            formData.targetCapacity, 
            formData.priorities
        );
        
        // API-Call zu updateQuota.php
        await fetch('/api/updateQuota.php', {
            method: 'POST',
            body: JSON.stringify({
                selectedDays: this.selectedDays,
                quotas: quotas,
                targetCapacity: formData.targetCapacity,
                priorities: formData.priorities
            })
        });
    }
}
```

**Abhängigkeiten:**
- `selectedHistogramDays` Set (bereits vorhanden)
- CSS für Modal-Styling
- API-Endpoint `/api/updateQuota.php`

---

### 1.2 Backend: Quota-Update API

**Datei:** `/api/updateQuota.php` (NEU)

#### Funktionalität:

```php
<?php
// Input:
// - selectedDays: ['2026-03-01', '2026-03-02', ...]
// - targetCapacity: 28 (Zielauslastung Gesamt)
// - priorities: [
//     {category: 'lager', max: 12},
//     {category: 'betten', max: 10},
//     {category: 'sonder', max: 2},
//     {category: 'dz', max: 4}
//   ]

// Output:
// - success: true/false
// - affected_quotas: [quota_ids...]
// - calculated_quotas: {lager: 12, betten: 10, sonder: 2, dz: 4}
// - message: 'Quotas erfolgreich aktualisiert'

// WICHTIG: Prioritäts-basierte Berechnung!
function calculateQuotaDistribution($targetCapacity, $priorities, $currentOccupancy) {
    // Spezialfall: Belegung > Ziel → Alle Quotas = 0
    if ($currentOccupancy > $targetCapacity) {
        return [
            'lager' => 0,
            'betten' => 0,
            'dz' => 0,
            'sonder' => 0,
            'warning' => 'Aktuelle Belegung überschreitet Zielkapazität'
        ];
    }
    
    $result = ['lager' => 0, 'betten' => 0, 'dz' => 0, 'sonder' => 0];
    $remaining = $targetCapacity;
    
    // Durchlaufe Prioritäten (1-4)
    foreach ($priorities as $prio) {
        if ($remaining <= 0) break;
        
        $category = $prio['category'];
        $maxValue = $prio['max'] ?? PHP_INT_MAX; // NULL = unbegrenzt
        
        $allocated = min($remaining, $maxValue);
        $result[$category] = $allocated;
        $remaining -= $allocated;
    }
    
    // Rest zu Priorität 1 (wenn max nicht erreicht)
    if ($remaining > 0) {
        $firstPrio = $priorities[0];
        $category = $firstPrio['category'];
        $result[$category] += $remaining;
    }
    
    return $result;
}

// BEISPIELE (siehe Frontend)
```

#### Schritte:
1. [ ] Validiere Input-Parameter
2. [ ] Prüfe Hütten-Kapazität
3. [ ] Bestimme Operation-Mode
4. [ ] Führe Quota-Update durch (siehe Phase 2)
5. [ ] Logge Änderungen
6. [ ] Return JSON Response

**Abhängigkeiten:**
- Datenbank-Zugriff auf `hut_quota` + `hut_quota_categories`
- Transaktions-Support (COMMIT/ROLLBACK)

---

## 🔀 PHASE 2: MEHRTÄGIGE QUOTAS AUFTEILEN

### 2.1 Eintägige Quotas Generieren

**Funktion:** Mehrtägige Quotas in Eintägige konvertieren (NUR für selektierte Tage)

#### Logik:
```php
function splitQuotaIntoSingleDays($quotaId, $selectedDays) {
    // 1. Lade Original-Quota aus DB
    $originalQuota = getQuotaById($quotaId);
    
    // 2. Filter nur Tage innerhalb der Selektion
    $relevantDays = array_intersect($selectedDays, 
                                    getDatesInRange($originalQuota->date_from, 
                                                   $originalQuota->date_to));
    
    // 3. Erstelle Eintägige Quotas
    foreach ($relevantDays as $day) {
        createSingleDayQuota([
            'date_from' => $day,
            'date_to' => addDays($day, 1), // date_to - date_from = 1 Tag
            'quota_lager' => $originalQuota->quota_lager,
            'quota_betten' => $originalQuota->quota_betten,
            'quota_dz' => $originalQuota->quota_dz,
            'quota_sonder' => $originalQuota->quota_sonder
        ]);
    }
}
```

**Datei:** `/api/splitQuota.php` (NEU)

#### Features:
- [ ] Identifiziere betroffene mehrtägige Quotas
- [ ] Generiere eintägige Quotas für JEDEN selektierten Tag
- [ ] Behalte Quota-Werte bei (1:1 Kopie)
- [ ] Lösche NICHT die Original-Quota (siehe Phase 2.3)

---

### 2.2 Quota-Teilung mit Erhalt

**Szenario:** Selektion liegt INNERHALB einer mehrtägigen Quota

**Beispiel:**
```
Original-Quota: 01.03. - 10.03. (10 Tage)
Selektion:      03.03. - 05.03. (3 Tage)

Ergebnis:
- Quota 1: 01.03. - 02.03. (2 Tage) - MEHRTÄGIG ERHALTEN
- Quota 2: 03.03. - 03.03. (1 Tag)  - EINTÄGIG NEU
- Quota 3: 04.03. - 04.03. (1 Tag)  - EINTÄGIG NEU
- Quota 4: 05.03. - 05.03. (1 Tag)  - EINTÄGIG NEU
- Quota 5: 06.03. - 10.03. (5 Tage) - MEHRTÄGIG ERHALTEN
```

#### Logik:
```php
function splitQuotaWithPreservation($quotaId, $selectedDays) {
    $quota = getQuotaById($quotaId);
    $allDays = getDatesInRange($quota->date_from, $quota->date_to);
    
    // 1. Identifiziere 3 Bereiche
    $beforeSelection = array_diff($allDays, $selectedDays); // Vor Selektion
    $insideSelection = array_intersect($allDays, $selectedDays); // In Selektion
    $afterSelection = array_diff($allDays, $selectedDays); // Nach Selektion
    
    // 2. Erstelle neue Quotas
    $newQuotas = [];
    
    // BEREICH 1: Vor Selektion (mehrtägig, wenn > 1 Tag)
    if (count($beforeSelection) > 0) {
        $newQuotas[] = createQuota([
            'date_from' => min($beforeSelection),
            'date_to' => addDays(max($beforeSelection), 1),
            'values' => $quota->values,
            'is_multi_day' => count($beforeSelection) > 1
        ]);
    }
    
    // BEREICH 2: Selektierte Tage (IMMER eintägig)
    foreach ($insideSelection as $day) {
        $newQuotas[] = createQuota([
            'date_from' => $day,
            'date_to' => addDays($day, 1),
            'values' => $quota->values,
            'is_multi_day' => false
        ]);
    }
    
    // BEREICH 3: Nach Selektion (mehrtägig, wenn > 1 Tag)
    if (count($afterSelection) > 0) {
        $newQuotas[] = createQuota([
            'date_from' => min($afterSelection),
            'date_to' => addDays(max($afterSelection), 1),
            'values' => $quota->values,
            'is_multi_day' => count($afterSelection) > 1
        ]);
    }
    
    return $newQuotas;
}
```

**Datei:** `/api/splitQuotaWithPreservation.php` (NEU)

#### Features:
- [ ] Erkenne Überlappung mit selektiertem Bereich
- [ ] Teile Quota in max. 3 Bereiche (before/inside/after)
- [ ] Erzeuge eintägige Quotas NUR für selektierte Tage
- [ ] Behalte mehrtägige Quotas für nicht-selektierte Bereiche
- [ ] Transaktions-Sicherheit (alles oder nichts)

---

### 2.3 Alte Quotas Löschen

**Wichtig:** Löschung erfolgt NACH Erzeugung neuer Quotas!

#### Logik:
```php
function replaceQuotas($selectedDays, $newQuotaValues) {
    // START TRANSACTION
    $db->beginTransaction();
    
    try {
        // 1. Identifiziere betroffene Quotas
        $affectedQuotas = findQuotasInDateRange($selectedDays);
        
        // 2. Für jede betroffene Quota:
        foreach ($affectedQuotas as $quota) {
            // 2a. Prüfe ob vollständig in Selektion
            if (isFullyInsideSelection($quota, $selectedDays)) {
                // Vollständig -> Lösche komplett
                deleteQuota($quota->id);
            } else {
                // Teilweise -> Split mit Erhalt
                $newQuotas = splitQuotaWithPreservation($quota->id, $selectedDays);
            }
        }
        
        // 3. Erstelle neue Quotas für selektierte Tage
        foreach ($selectedDays as $day) {
            createSingleDayQuota([
                'date_from' => $day,
                'date_to' => addDays($day, 1),
                'quota_lager' => $newQuotaValues['lager'],
                'quota_betten' => $newQuotaValues['betten'],
                'quota_dz' => $newQuotaValues['dz'],
                'quota_sonder' => $newQuotaValues['sonder']
            ]);
        }
        
        // COMMIT
        $db->commit();
        return ['success' => true];
        
    } catch (Exception $e) {
        // ROLLBACK bei Fehler
        $db->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
```

**Datei:** `/api/replaceQuotas.php` (NEU)

#### Features:
- [ ] Transaktions-Management (BEGIN/COMMIT/ROLLBACK)
- [ ] Identifiziere betroffene Quotas
- [ ] Lösche nur relevante alte Quotas
- [ ] Erstelle neue Quotas ATOMISCH
- [ ] Error-Handling mit Rollback

---

## 🎨 PHASE 3: FRONTEND-INTEGRATION

### 3.1 Histogram Tag-Selektion Erweitern

**Datei:** `/zp/timeline-unified.js`

#### Features:
- [x] Multi-Tag-Selektion bereits vorhanden
- [ ] Context-Menu mit "Quota bearbeiten" Button
- [ ] Öffne Quota-Input-Modal bei Click
- [ ] Zeige aktuelle Quotas als Vorschlag

#### Code:
```javascript
// In handleHistogramDayClick()
if (this.selectedHistogramDays.size > 0) {
    // Zeige Context-Menu mit Quota-Button
    this.showHistogramContextMenu(mouseX, mouseY);
}

showHistogramContextMenu(x, y) {
    const menu = document.createElement('div');
    menu.className = 'histogram-context-menu';
    
    const quotaButton = document.createElement('button');
    quotaButton.textContent = '📊 Quota bearbeiten';
    quotaButton.onclick = () => this.openQuotaModal();
    
    menu.appendChild(quotaButton);
    // Position & Append
}
```

---

### 3.2 Quota-Differenz Visualisierung

**Datei:** `/zp/timeline-unified.js`

#### Features:
- [ ] Ampel-Farben für Quota-Erfüllung:
  - 🟢 Grün: Quota erreicht (Belegung >= Quota)
  - 🟡 Gelb: Fast erreicht (Differenz < 5)
  - 🔴 Rot: Unterschritten (Differenz >= 5)
- [ ] Rahmen um Histogram-Tag
- [ ] Tooltip mit Details

#### Code:
```javascript
renderQuotaStatus(dayIndex, detail) {
    if (!detail.quota) return;
    
    const diff_lager = detail.lager - detail.quota.lager;
    const diff_betten = detail.betten - detail.quota.betten;
    const diff_dz = detail.dz - detail.quota.dz;
    const diff_sonder = detail.sonder - detail.quota.sonder;
    
    // Gesamtdifferenz
    const totalDiff = diff_lager + diff_betten + diff_dz + diff_sonder;
    
    // Ampel-Farbe
    let statusColor = '#22c55e'; // Grün
    if (totalDiff < 0 && totalDiff >= -5) statusColor = '#eab308'; // Gelb
    if (totalDiff < -5) statusColor = '#ef4444'; // Rot
    
    // Zeichne Rahmen
    this.ctx.strokeStyle = statusColor;
    this.ctx.lineWidth = 3;
    this.ctx.strokeRect(x, area.y, dayWidth, area.height);
}
```

---

### 3.3 Live-Update nach Quota-Änderung

**Datei:** `/zp/timeline-unified.js`

#### Features:
- [ ] Reload Histogram-Daten nach Quota-Speicherung
- [ ] Smooth Transition (fade-in neue Werte)
- [ ] Toast-Notification "Quotas aktualisiert"

#### Code:
```javascript
async reloadHistogramData() {
    const { startDate, endDate } = this.getTimelineDateRange();
    const response = await fetch(`/zp/getHistogramSource.php?start=${startDate}&end=${endDate}&cb=${Date.now()}`);
    const data = await response.json();
    
    // Update internal data
    this.setHistogramSource(data.data.histogram, data.data.storno, data.data.availability);
    
    // Re-render
    this.scheduleRender('quota_updated');
    
    // Show notification
    this.showToast('✅ Quotas erfolgreich aktualisiert', 'success');
}
```

---

## 🔧 PHASE 4: QUOTA-OPTIMIERUNG

### 4.1 Optimierungs-Algorithmus

**Datei:** `/api/optimizeQuota.php` (NEU)

#### Logik (aus belegung_tab.php adaptiert):

```php
function optimizeQuotaForDays($selectedDays, $targetQuotas) {
    // 1. Sammle alle Reservierungen für selektierte Tage
    $reservations = getReservationsForDays($selectedDays);
    
    // 2. Analysiere Belegung vs. Quota
    $analysis = [];
    foreach ($selectedDays as $day) {
        $current = getCurrentOccupancy($day);
        $target = $targetQuotas[$day];
        
        $analysis[$day] = [
            'current' => $current,
            'target' => $target,
            'diff' => $current - $target,
            'action' => $current > $target ? 'reduce' : 'increase'
        ];
    }
    
    // 3. Generiere Optimierungs-Vorschläge
    $suggestions = [];
    
    // Tage mit Überbelegung
    $overbooked = array_filter($analysis, fn($a) => $a['diff'] > 0);
    
    // Tage mit Unterbelegung
    $underbooked = array_filter($analysis, fn($a) => $a['diff'] < 0);
    
    // 4. Verschiebe-Vorschläge generieren
    foreach ($overbooked as $day => $data) {
        // Finde verschiebbare Reservierungen
        $movableRes = findMovableReservations($day);
        
        foreach ($movableRes as $res) {
            // Prüfe ob Verschiebung auf anderen Tag möglich
            $targetDays = findAvailableDays($res, $underbooked);
            
            if ($targetDays) {
                $suggestions[] = [
                    'reservation_id' => $res->id,
                    'from_date' => $day,
                    'to_date' => $targetDays[0],
                    'guest_name' => $res->guest_name,
                    'reason' => 'Quota-Optimierung'
                ];
            }
        }
    }
    
    return $suggestions;
}
```

#### Features:
- [ ] Analysiere Belegung vs. Quota
- [ ] Identifiziere über-/unterbelegte Tage
- [ ] Generiere Verschiebe-Vorschläge
- [ ] Priorisiere flexible Reservierungen
- [ ] Return Suggestions (nicht automatisch ausführen!)

---

### 4.2 Frontend: Optimierungs-Dialog

**Datei:** `/zp/timeline-unified.js`

#### Features:
- [ ] "Optimieren" Button bei Quota-Modal
- [ ] Zeige Optimierungs-Vorschläge
- [ ] User kann Vorschläge akzeptieren/ablehnen
- [ ] Batch-Update bei Bestätigung

#### Code:
```javascript
async showOptimizationSuggestions() {
    const selectedDays = Array.from(this.selectedHistogramDays);
    const targetQuotas = this.quotaInputModal.getValues();
    
    // API-Call
    const response = await fetch('/api/optimizeQuota.php', {
        method: 'POST',
        body: JSON.stringify({ selectedDays, targetQuotas })
    });
    
    const suggestions = await response.json();
    
    // Zeige Dialog mit Vorschlägen
    const dialog = new OptimizationDialog(suggestions);
    dialog.onConfirm = () => this.applyOptimization(suggestions);
    dialog.show();
}
```

---

## 🗄️ PHASE 5: DATENBANK-SCHEMA

### 5.1 Bestehende Tabellen

**Tabelle:** `hut_quota`
```sql
id                INT PRIMARY KEY AUTO_INCREMENT
date_from         DATE NOT NULL
date_to           DATE NOT NULL
created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**Tabelle:** `hut_quota_categories`
```sql
id                INT PRIMARY KEY AUTO_INCREMENT
hut_quota_id      INT NOT NULL (FK -> hut_quota.id)
category_id       INT NOT NULL (1958=ML, 2293=MBZ, 2381=2BZ, 6106=SK)
total_beds        INT NOT NULL
```

### 5.2 Erweiterungen (Optional)

**Neue Spalten für Tracking:**

```sql
ALTER TABLE hut_quota ADD COLUMN operation_mode ENUM('manual', 'optimized', 'split') DEFAULT 'manual';
ALTER TABLE hut_quota ADD COLUMN parent_quota_id INT NULL; -- Referenz zur Original-Quota bei Split
ALTER TABLE hut_quota ADD COLUMN created_by VARCHAR(100);
ALTER TABLE hut_quota ADD COLUMN notes TEXT;
```

**Neue Tabelle für Optimierungs-Historie:**

```sql
CREATE TABLE quota_optimization_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    optimization_date DATE NOT NULL,
    selected_days TEXT NOT NULL, -- JSON Array
    old_quotas TEXT NOT NULL,    -- JSON
    new_quotas TEXT NOT NULL,    -- JSON
    suggestions TEXT NOT NULL,   -- JSON Array
    applied BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

## 📋 IMPLEMENTIERUNGS-CHECKLISTE

### ✅ ABGESCHLOSSEN

- [x] Quota-Visualisierung im Histogram (5px Balken)
- [x] Mehrtägige Quota-Verbindungslinien
- [x] Quota-Daten aus API laden
- [x] Frontend-Parsing von quota_is_multi_day

### 🚧 IN ARBEIT

- [ ] KEINE - Warten auf weitere Anforderungen

### 📋 TODO - PHASE 1: EINGABE

- [ ] **1.1.1** Quota-Input Modal HTML/CSS erstellen
  - [ ] Zielkapazität Slider/Input
  - [ ] 4x Prioritäts-Zeilen (Dropdown Kategorie + Input Max-Wert)
  - [ ] Live-Preview berechneter Quotas
  - [ ] Warnung bei Belegung > Ziel
- [ ] **1.1.2** Prioritäts-Verteilungs-Algorithmus (JavaScript)
  - [ ] `calculateQuotaDistribution()` Funktion
  - [ ] Test: Alle Beispiel-Szenarien (10, 15, 21, 28, 59)
  - [ ] Test: Belegung > Ziel → Quotas = 0
- [ ] **1.1.3** Modal öffnen bei Histogram-Tag-Selektion
- [ ] **1.1.4** Formular-Validierung implementieren
  - [ ] Keine negativen Werte
  - [ ] Keine Duplikate bei Kategorie-Auswahl
  - [ ] Max-Werte >= 0 oder leer (∞)
- [ ] **1.1.5** API-Call zu updateQuota.php
- [ ] **1.2.1** Backend API `/api/updateQuota.php` erstellen
- [ ] **1.2.2** Prioritäts-Verteilungs-Algorithmus (PHP)
  - [ ] `calculateQuotaDistribution()` Funktion
  - [ ] Test: Alle Beispiel-Szenarien
  - [ ] Test: Belegung > Ziel → Quotas = 0
- [ ] **1.2.3** Input-Validierung (PHP)
- [ ] **1.2.4** Kapazitäts-Check gegen aktuelle Belegung
- [ ] **1.2.5** Transaktions-Handling

### 📋 TODO - PHASE 2: SPLITTING

- [ ] **2.1.1** Funktion `splitQuotaIntoSingleDays()` erstellen
- [ ] **2.1.2** API-Endpoint `/api/splitQuota.php`
- [ ] **2.1.3** Test: Mehrtägige -> Eintägige Konvertierung
- [ ] **2.2.1** Funktion `splitQuotaWithPreservation()` erstellen
- [ ] **2.2.2** API-Endpoint `/api/splitQuotaWithPreservation.php`
- [ ] **2.2.3** Test: Selektion innerhalb mehrtägiger Quota
- [ ] **2.2.4** Test: Randfälle (Start/Ende überlappend)
- [ ] **2.3.1** Funktion `replaceQuotas()` mit Transaction
- [ ] **2.3.2** API-Endpoint `/api/replaceQuotas.php`
- [ ] **2.3.3** Test: Atomische Updates
- [ ] **2.3.4** Error-Handling & Rollback

### 📋 TODO - PHASE 3: FRONTEND

- [ ] **3.1.1** Context-Menu für selektierte Tage
- [ ] **3.1.2** "Quota bearbeiten" Button
- [ ] **3.1.3** Integration mit Quota-Modal
- [ ] **3.2.1** Quota-Differenz Berechnung
- [ ] **3.2.2** Ampel-Farben Rendering
- [ ] **3.2.3** Tooltip mit Differenz-Details
- [ ] **3.3.1** Live-Reload nach Quota-Update
- [ ] **3.3.2** Toast-Notifications
- [ ] **3.3.3** Smooth Transitions

### 📋 TODO - PHASE 4: OPTIMIERUNG

- [ ] **4.1.1** Algorithmus aus belegung_tab.php analysieren
- [ ] **4.1.2** API `/api/optimizeQuota.php` erstellen
- [ ] **4.1.3** Verschiebe-Logik implementieren
- [ ] **4.1.4** Suggestions JSON generieren
- [ ] **4.2.1** Optimierungs-Dialog UI erstellen
- [ ] **4.2.2** Suggestions-Liste rendern
- [ ] **4.2.3** Accept/Reject Actions
- [ ] **4.2.4** Batch-Update bei Bestätigung

### 📋 TODO - PHASE 5: DATENBANK

- [ ] **5.1.1** Schema-Review
- [ ] **5.2.1** Erweiterungs-Spalten hinzufügen (optional)
- [ ] **5.2.2** Optimization-Log Tabelle erstellen (optional)
- [ ] **5.2.3** Migration-Script erstellen

---

## 🎯 PRIORITÄTEN

### **HIGH PRIORITY** (Sofort)
1. **Phase 1.1-1.2**: Quota-Eingabe Modal + API
2. **Phase 2.3**: Replace-Logik (Löschen + Neu erstellen)
3. **Phase 3.1**: Context-Menu Integration

### **MEDIUM PRIORITY** (Nach Grundfunktion)
4. **Phase 2.1-2.2**: Smart-Splitting Algorithmus
5. **Phase 3.2**: Differenz-Visualisierung
6. **Phase 3.3**: Live-Updates

### **LOW PRIORITY** (Optional/Später)
7. **Phase 4**: Optimierungs-Feature
8. **Phase 5.2**: Erweiterte DB-Features

---

## 📝 OFFENE FRAGEN

1. **Berechtigungen:** Wer darf Quotas ändern? (Admin only?)
2. ~~**Validierung:** Max. Quota = Hütten-Kapazität oder höher erlaubt?~~ 
   - ✅ **GEKLÄRT:** Prioritäts-basierte Zuteilung, Überschuss geht zu Prio 1
3. ~~**Konflikte:** Was passiert bei Quota < aktuelle Belegung?~~
   - ✅ **GEKLÄRT:** Quotas = 0 bei Belegung > Zielkapazität
4. **Historie:** Sollen alte Quota-Werte archiviert werden?
5. **Optimierung:** Automatisch oder nur Vorschläge?
6. **Prioritäten Standard:** Soll es Default-Prioritäten geben oder immer User-Input?
7. **Prio 1 Limit:** Soll Prio 1 immer unbegrenzt sein oder optional mit Max-Wert?

---

## 🎯 NEUE ANFORDERUNGEN (2025-10-09 19:25)

### **Prioritäts-basierte Quota-Zuteilung**

**Konzept:**
- User gibt **Zielkapazität** (Gesamt) ein
- User definiert **4 Prioritäten** mit Kategorie + Max-Wert
- System verteilt Kapazität nach Priorität

**Verteilungs-Logik:**

| Zielkapazität | Priorität 1 (Lager, ∞) | Priorität 2 (Betten, 10) | Priorität 3 (Sonder, 2) | Priorität 4 (DZ, 4) | Ergebnis |
|---------------|------------------------|-------------------------|------------------------|---------------------|----------|
| 10            | 10                     | 0                       | 0                      | 0                   | L:10, B:0, S:0, D:0 |
| 15            | 15*                    | 0                       | 0                      | 0                   | L:15, B:0, S:0, D:0 |
| 21            | 12*                    | 8                       | 1                      | 0                   | L:12, B:8, S:1, D:0 |
| 28            | 12*                    | 10                      | 2                      | 4                   | L:12, B:10, S:2, D:4 |
| 59            | 33*                    | 10                      | 2                      | 4                   | L:33, B:10, S:2, D:4 |

*Hinweis:* Wenn Prio 1 (Lager) **kein Max-Wert** hat (∞), nimmt es allen Rest!
*Korrektur:* Für korrektes Verhalten sollte Prio 1 einen Max-Wert haben (z.B. 12)

**Spezialfall:**
- **Belegung > Zielkapazität** → Alle Quotas = 0 (keine negativen Werte möglich)

**UI-Elemente:**
1. Slider/Input: Zielkapazität (0-200)
2. 4x Dropdown: Kategorie (Lager/Betten/DZ/Sonder)
3. 4x Input: Max-Wert (leer = unbegrenzt)
4. Preview: Berechnete Quotas live anzeigen

---

## 🚀 NÄCHSTE SCHRITTE

**Warten auf:**
- [ ] Weitere Anforderungen vom User
- [ ] Freigabe für Implementierung
- [ ] Klärung offener Fragen

**Bereit für:**
- [x] Code-Implementierung starten
- [x] API-Endpoints erstellen
- [x] Frontend-Komponenten bauen

---

**Letzte Aktualisierung:** 2025-10-09 19:15 UTC  
**Erstellt von:** GitHub Copilot  
**Status:** 📋 Planning Complete - Waiting for Approval
