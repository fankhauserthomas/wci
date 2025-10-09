# 📊 QUOTA-MANAGEMENT SYSTEM - IMPLEMENTATION COMPLETE

**Status:** ✅ Vollständig implementiert  
**Datum:** 2025-10-09  
**Version:** 1.0

---

## 🎯 WAS WURDE IMPLEMENTIERT

### ✅ Backend API

**Datei:** `/hrs/hrs_update_quota_timeline.php` (NEU)

**Features:**
- ✅ Prioritäts-basierte Quota-Verteilung
- ✅ Automatische Aufteilung mehrtägiger Quotas
- ✅ Intelligente Split-Logik bei Überlappung
- ✅ Atomische Transaktionen (COMMIT/ROLLBACK)
- ✅ Validation & Error Handling

**Funktionen:**
```php
updateQuotas($selectedDays, $targetCapacity, $priorities)
calculateQuotaDistribution($targetCapacity, $priorities, $currentOccupancy)
splitQuotaWithPreservation($quota, $selectedDays)
createSingleDayQuota($date, $categoryValues)
deleteQuota($quotaId)
```

**API Endpoint:**
```
POST /wci/hrs/hrs_update_quota_timeline.php

Request Body:
{
  "selectedDays": ["2026-03-01", "2026-03-02", "2026-03-03"],
  "targetCapacity": 28,
  "priorities": [
    {"category": "lager", "max": 12},
    {"category": "betten", "max": 10},
    {"category": "sonder", "max": 2},
    {"category": "dz", "max": 4}
  ],
  "operation": "update"
}

Response:
{
  "success": true,
  "calculatedQuotas": {"lager": 12, "betten": 10, "sonder": 2, "dz": 4},
  "affectedDays": ["2026-03-01", "2026-03-02", "2026-03-03"],
  "deletedQuotas": [123, 124],
  "createdQuotas": [125, 126, 127],
  "message": "3 Tage erfolgreich aktualisiert"
}
```

---

### ✅ Frontend Modal

**Datei:** `/zp/quota-input-modal.html` (NEU)

**Features:**
- ✅ Bootstrap 5 Modal mit Dark Theme
- ✅ Zielkapazität Slider + Input (0-200)
- ✅ 4 Prioritäts-Zeilen (Kategorie + Max-Wert)
- ✅ Live-Preview berechneter Quotas
- ✅ Warnung bei Überbelegung
- ✅ Validierung (keine Duplikate)

**JavaScript Klasse:**
```javascript
class QuotaInputModal {
    show(selectedDays)
    calculateQuotaDistribution()
    updatePreview()
    validate()
    save()
}
```

---

### ✅ Integration in Timeline

**Datei:** `/zp/quota-management-integration.js` (NEU)

**Features:**
- ✅ Context-Menu bei Rechtsklick auf selektierte Tage
- ✅ "Quota bearbeiten" Button
- ✅ "Selektion aufheben" Button
- ✅ Reload Histogram nach Quota-Update

**Erweiterte Funktionen:**
```javascript
TimelineUnifiedRenderer.prototype.showQuotaContextMenu(x, y)
TimelineUnifiedRenderer.prototype.openQuotaModal()
TimelineUnifiedRenderer.prototype.reloadHistogramData()
```

---

## 🚀 INSTALLATION

### Schritt 1: Modal einbinden

Füge in `/zp/timeline-unified.html` VOR `</body>` ein:

```html
<!-- Include Quota Modal -->
<script>
fetch('/wci/zp/quota-input-modal.html')
    .then(response => response.text())
    .then(html => {
        const temp = document.createElement('div');
        temp.innerHTML = html;
        document.body.appendChild(temp);
    });
</script>
```

### Schritt 2: Integration-Script einbinden

Füge in `/zp/timeline-unified.html` NACH `timeline-unified.js` ein:

```html
<script src="timeline-unified.js"></script>
<script src="quota-management-integration.js"></script>
```

### Schritt 3: Datenbank-Check

Stelle sicher dass folgende Tabellen existieren:
- `hut_quota` (id, date_from, date_to, created_at, updated_at)
- `hut_quota_categories` (id, hut_quota_id, category_id, total_beds)

---

## 📖 VERWENDUNG

### 1. Tage im Histogram selektieren

- **Einzelner Tag:** Click auf Tag
- **Mehrere Tage:** Shift + Click oder Drag
- **Bereich:** Click auf Start-Tag, dann Shift+Click auf End-Tag

### 2. Context-Menu öffnen

- **Rechtsklick** auf einem selektierten Tag
- Oder: **Doppelklick** auf einem selektierten Tag (optional)

### 3. Quota bearbeiten

**Modal öffnet sich mit:**
- Selektierter Zeitraum (z.B. "01.03.2026 - 05.03.2026 (5 Tage)")
- Aktuelle Belegung (z.B. "20 Plätze")

**Zielkapazität eingeben:**
- Slider oder Input verwenden
- Zahl zwischen 0 und 200

**Prioritäten festlegen:**
1. **Priorität 1:** Kategorie auswählen + Max-Wert (oder leer für unbegrenzt)
2. **Priorität 2:** Kategorie auswählen + Max-Wert
3. **Priorität 3:** Kategorie auswählen + Max-Wert
4. **Priorität 4:** Kategorie auswählen + Max-Wert

**Live-Preview prüfen:**
- Berechnete Quotas werden sofort angezeigt
- Bei Überbelegung: Warnung + Quotas = 0

**Speichern:**
- Click auf "💾 Speichern"
- Erfolgs-Meldung mit Details
- Histogram wird automatisch neu geladen

---

## 🎨 BEISPIELE

### Beispiel 1: Standard-Verteilung

**Input:**
```
Zielkapazität: 28
Prio 1: Lager, Max: 12
Prio 2: Betten, Max: 10
Prio 3: Sonder, Max: 2
Prio 4: DZ, Max: 4
```

**Output:**
```
Lager:  12
Betten: 10
Sonder: 2
DZ:     4
Gesamt: 28 ✅
```

### Beispiel 2: Geringe Kapazität

**Input:**
```
Zielkapazität: 10
Prio 1: Lager, Max: 12
Prio 2: Betten, Max: 10
Prio 3: Sonder, Max: 2
Prio 4: DZ, Max: 4
```

**Output:**
```
Lager:  10 (nur Prio 1)
Betten: 0
Sonder: 0
DZ:     0
Gesamt: 10 ✅
```

### Beispiel 3: Überschuss-Kapazität

**Input:**
```
Zielkapazität: 59
Prio 1: Lager, Max: 12
Prio 2: Betten, Max: 10
Prio 3: Sonder, Max: 2
Prio 4: DZ, Max: 4
```

**Output:**
```
Lager:  33 (12 + Rest 21)
Betten: 10
Sonder: 2
DZ:     4
Gesamt: 59 ✅
```

### Beispiel 4: Überbelegung

**Input:**
```
Zielkapazität: 20
Aktuelle Belegung: 25 (!)
```

**Output:**
```
⚠️ WARNUNG: Belegung > Ziel

Lager:  0
Betten: 0
Sonder: 0
DZ:     0
Gesamt: 0 (alle auf 0 gesetzt)
```

---

## 🧪 TESTING

### Test 1: Einfache Quota-Erstellung

1. Selektiere 3 aufeinanderfolgende Tage (z.B. 01.-03. März)
2. Öffne Quota-Modal
3. Setze Zielkapazität: 28
4. Behalte Standard-Prioritäten
5. Speichern
6. **Erwartung:** 3 neue eintägige Quotas erstellt

### Test 2: Mehrtägige Quota aufteilen

1. Erstelle mehrtägige Quota (01.-10. März)
2. Selektiere Teilbereich (03.-05. März)
3. Öffne Quota-Modal
4. Ändere Werte
5. Speichern
6. **Erwartung:**
   - Original-Quota gelöscht
   - Neue Quotas: 01.-02. (mehrtägig), 03.-05. (eintägig), 06.-10. (mehrtägig)

### Test 3: Überlappende Quota

1. Erstelle Quota A (01.-05. März)
2. Erstelle Quota B (04.-08. März)
3. Selektiere 04.-05. März
4. Öffne Quota-Modal, speichern
5. **Erwartung:** Beide Quotas korrekt aufgeteilt

### Test 4: Validierung

1. Setze Prio 1: Lager
2. Setze Prio 2: Lager (Duplikat!)
3. Versuche zu speichern
4. **Erwartung:** Fehler-Meldung "Kategorie-Duplikate nicht erlaubt"

---

## 🔧 TROUBLESHOOTING

### Problem: Modal öffnet sich nicht

**Lösung:**
```javascript
// Console-Check:
console.log(quotaInputModal); // Sollte nicht undefined sein

// Falls undefined:
// 1. Prüfe ob quota-input-modal.html geladen wurde
// 2. Prüfe Browser-Console auf Fehler
// 3. Hard-Refresh (Ctrl+Shift+R)
```

### Problem: API gibt Fehler zurück

**Lösung:**
```bash
# Teste API direkt:
curl -X POST http://localhost/wci/hrs/hrs_update_quota_timeline.php \
  -H "Content-Type: application/json" \
  -d '{
    "selectedDays": ["2026-03-01"],
    "targetCapacity": 28,
    "priorities": [
      {"category": "lager", "max": 12}
    ]
  }'

# Prüfe PHP Error Log:
tail -f /var/log/php-fpm/error.log
```

### Problem: Quotas werden nicht angezeigt

**Lösung:**
```javascript
// Teste Histogram-Daten:
console.log(window.histogramAvailability);

// Sollte quota-Felder enthalten:
// {datum: "2026-03-01", quota_lager: 12, quota_betten: 10, ...}

// Falls nicht:
// 1. Prüfe /zp/getHistogramSource.php
// 2. Prüfe ob Quota-SQL korrekt ist
// 3. Prüfe ob quota_is_multi_day berechnet wird
```

---

## 📊 DATENBANK-SCHEMA

### Tabelle: `hut_quota`

```sql
CREATE TABLE hut_quota (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date_range (date_from, date_to)
);
```

### Tabelle: `hut_quota_categories`

```sql
CREATE TABLE hut_quota_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    hut_quota_id INT NOT NULL,
    category_id INT NOT NULL,
    total_beds INT NOT NULL,
    FOREIGN KEY (hut_quota_id) REFERENCES hut_quota(id) ON DELETE CASCADE,
    INDEX idx_quota_category (hut_quota_id, category_id)
);
```

### Kategorie-IDs

```
1958 = ML  (Matratzenlager)
2293 = MBZ (Mehrbettzimmer)
2381 = 2BZ (Zweibettzimmer)
6106 = SK  (Sonderkategorie)
```

---

## 🎯 NÄCHSTE SCHRITTE (OPTIONAL)

### Phase 4: Quota-Optimierung

- [ ] Algorithmus aus belegung_tab.php portieren
- [ ] Optimierungs-Button im Modal
- [ ] Verschiebe-Vorschläge generieren
- [ ] User-Bestätigung vor Änderungen

### Phase 5: Erweiterte Features

- [ ] Quota-Historie (Audit-Log)
- [ ] Undo/Redo Funktion
- [ ] Batch-Import aus CSV
- [ ] Export zu Excel

---

## 📝 CHANGELOG

**v1.0 (2025-10-09)**
- ✅ Initial Release
- ✅ Prioritäts-basierte Quota-Verteilung
- ✅ Smart-Splitting mehrtägiger Quotas
- ✅ Context-Menu Integration
- ✅ Live-Preview
- ✅ Validierung & Error Handling

---

**Erstellt von:** GitHub Copilot  
**Letzte Aktualisierung:** 2025-10-09 20:00 UTC  
**Status:** ✅ Production Ready
