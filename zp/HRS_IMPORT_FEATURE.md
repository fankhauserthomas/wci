# HRS Import-Funktion für Timeline

## Übersicht
Die Timeline bietet nun eine integrierte Import-Funktion, um HRS-Daten (Daily Summary, Quota, Reservierungen) für selektierte Tage direkt zu importieren.

## Funktionsweise

### Zugriff
Der Import-Button befindet sich im **Optionen-Menü** (⚙️) der Timeline-Toolbar:

```
⚙️ Optionen → HRS Import für selektierte Tage
                📥 HRS Daten importieren
```

### Voraussetzungen

1. **Tage im Histogram selektieren:**
   - Mindestens ein Tag muss selektiert sein
   - Einfacher Klick: Einzelner Tag
   - `Strg` + Klick: Mehrere Tage einzeln
   - `Shift` + Klick: Range von Tagen

2. **Datums-Bereich:**
   - System ermittelt automatisch `dat_min` und `dat_max` aus Selektion
   - `dat_min` = Frühester selektierter Tag
   - `dat_max` = Spätester selektierter Tag

### Import-Prozess

#### 1. Bestätigungs-Dialog
Nach Klick auf den Import-Button erscheint ein Bestätigungs-Dialog:

```
HRS Daten importieren?

Von: 2025-10-15
Bis: 2025-10-22
Tage: 8

Dies importiert:
• Daily Summary
• Quota (Kapazitäten)
• Reservierungen
• AV Capacity Update

Dies kann einige Minuten dauern.
```

#### 2. Import-Ablauf

Die Funktion ruft nacheinander **vier** APIs auf:

**a) Daily Summary Import**
```
URL: /wci/hrs/hrs_imp_daily.php?from=YYYY-MM-DD&to=YYYY-MM-DD
```
- Importiert tägliche Zusammenfassungen
- Tabellen: `daily_summary`, `daily_summary_categories`
- Enthält Belegungszahlen, Kategorien, etc.

**b) Quota Import**
```
URL: /wci/hrs/hrs_imp_quota.php?from=YYYY-MM-DD&to=YYYY-MM-DD
```
- Importiert Kapazitätsänderungen
- Tabellen: `hut_quota`, `hut_quota_categories`, `hut_quota_languages`
- Enthält verfügbare Betten pro Kategorie

**c) Reservierungen Import**
```
URL: /wci/hrs/hrs_imp_res.php?from=YYYY-MM-DD&to=YYYY-MM-DD
```
- Importiert Reservierungen
- Tabelle: `AV-Res-webImp`
- Enthält Buchungen, Gästedaten, etc.

**d) AV Capacity Update (mit Datumsbereich)** ⭐ NEU
```
URL: /wci/api/imps/get_av_cap_range.php?hutID=675&von=YYYY-MM-DD&bis=YYYY-MM-DD
```
- Aktualisiert AV-Kapazitäten für den exakten Datumsbereich
- Optimiert API-Calls (HRS API gibt min. 10-11 Tage zurück)
- **≤ 11 Tage**: 1 API-Call ausreichend
- **> 11 Tage**: Multiple API-Calls mit 11-Tage-Schritten
- Filtert Ergebnisse auf den gewünschten Zeitraum
- Stellt vollständige Abdeckung des Zeitraums sicher

#### 3. Erfolgs-Meldung

Nach erfolgreichem Import erscheint eine Zusammenfassung:

```
✅ Import erfolgreich!

Daily: ✓ (8 Tage)
Quota: ✓ (45 Einträge)
Reservierungen: ✓ (123 Res.)
AV Capacity: ✓ (8 Tage, 1 API Calls)

Die Ansicht wird jetzt neu geladen.
```

**AV Capacity Details:**
- Zeigt Anzahl der gespeicherten Tage
- Zeigt Anzahl der benötigten API-Calls
- Optimiert für verschiedene Zeiträume

#### 4. Automatisches Neu-Laden

Nach erfolgreichem Import:
- Timeline-Daten werden automatisch neu geladen
- Alle Visualisierungen (Master, Rooms, Histogram) werden aktualisiert
- Keine manuelle Seitenaktualisierung nötig

### UI-Feedback

#### Button-Zustände

**Inaktiv (Standard):**
```
📥 HRS Daten importieren
```
- Lila Gradient-Hintergrund
- Klickbar

**Während Import:**
```
⏳ Importiere...
```
- Button deaktiviert (disabled)
- Zeigt laufenden Import an

**Nach Fehler:**
```
📥 HRS Daten importieren
```
- Kehrt zum Standard-Zustand zurück
- Fehler-Dialog mit Details

### Fehlerbehandlung

#### Keine Selektion
```
⚠️ Bitte selektieren Sie zuerst Tage im Histogram 
(Strg+Klick oder Shift+Klick).
```

#### Import-Fehler
```
❌ Fehler beim Import:

[Fehlermeldung]

Bitte prüfen Sie die Console für Details.
```

#### Mögliche Fehlerursachen:
- HRS-API nicht erreichbar
- Netzwerk-Timeout
- Ungültige Datums-Range
- Datenbankfehler
- PHP-Fehler in Import-Skripten

### Console-Logging

Der Import-Prozess loggt detaillierte Informationen:

```javascript
📥 Importing Daily Summary...
✅ Daily Summary imported: {success: true, imported: 8, ...}
📥 Importing Quota...
✅ Quota imported: {success: true, imported: 45, ...}
📥 Importing Reservations...
✅ Reservations imported: {success: true, imported: 123, ...}
```

Bei Fehlern:
```javascript
❌ Import failed: Error: Network request failed
```

## Technische Details

### Code-Integration

**Button im Settings-Menü** (`timeline-unified.js`):
```javascript
<div class="topbar-menu-section">
    <label>HRS Import für selektierte Tage</label>
    <button id="timeline-hrs-import-btn" class="topbar-link">
        📥 HRS Daten importieren
    </button>
    <p class="topbar-hint">
        Importiert Daily, Quota & Res für selektierte Tage
    </p>
</div>
```

**Event-Handler** (`timeline-unified.html`):
```javascript
hrsImportBtn.addEventListener('click', async (event) => {
    // 1. Check selection
    if (!renderer?.isHistogramDaySelectionActive) {
        alert('Bitte selektieren Sie zuerst Tage...');
        return;
    }
    
    // 2. Calculate date range
    const { startDate } = renderer.getTimelineDateRange();
    const selectedIndices = Array.from(renderer.selectedHistogramDays);
    const dateFrom = /* calculate from min index */;
    const dateTo = /* calculate from max index */;
    
    // 3. Confirm
    const confirmed = confirm(`Import von ${dateFrom} bis ${dateTo}?`);
    if (!confirmed) return;
    
    // 4. Import (sequential)
    await fetch(`../hrs/hrs_imp_daily.php?from=${dateFrom}&to=${dateTo}`);
    await fetch(`../hrs/hrs_imp_quota.php?from=${dateFrom}&to=${dateTo}`);
    await fetch(`../hrs/hrs_imp_res.php?from=${dateFrom}&to=${dateTo}`);
    
    // 5. Reload
    await window.reloadTimelineData();
});
```

### API-Endpoints

Alle drei Endpoints erwarten identische Parameter:

**Query-Parameter:**
- `from` (string): Start-Datum im Format `YYYY-MM-DD`
- `to` (string): End-Datum im Format `YYYY-MM-DD`

**Response-Format (JSON):**
```json
{
    "success": true,
    "imported": 8,
    "message": "Import completed successfully",
    "details": {
        "total": 8,
        "inserted": 5,
        "updated": 3,
        "skipped": 0
    }
}
```

### Datums-Berechnung

```javascript
// Get timeline date range
const { startDate } = renderer.getTimelineDateRange();

// Selected day indices (e.g. [5, 6, 7, 8, 10, 12])
const selectedIndices = Array.from(renderer.selectedHistogramDays)
    .sort((a, b) => a - b);

// Calculate date range
const minIndex = selectedIndices[0];
const maxIndex = selectedIndices[selectedIndices.length - 1];

const dateFrom = new Date(startDate.getTime() + minIndex * MS_IN_DAY);
const dateTo = new Date(startDate.getTime() + maxIndex * MS_IN_DAY);

// Format for API: YYYY-MM-DD
const dateFromStr = dateFrom.toISOString().split('T')[0];
const dateToStr = dateTo.toISOString().split('T')[0];
```

### Sequential vs. Parallel Imports

**Aktuell: Sequential (nacheinander)**
```javascript
await fetch(daily);  // Warte auf Completion
await fetch(quota);  // Dann Quota
await fetch(res);    // Dann Reservierungen
```

**Grund für Sequential:**
- Reservierungen können von Quota abhängen
- Daily Summary sollte vor Res verfügbar sein
- Vermeidet Datenbank-Locks

**Alternative: Parallel (gleichzeitig)**
```javascript
const [daily, quota, res] = await Promise.all([
    fetch(daily),
    fetch(quota),
    fetch(res)
]);
```
- Schneller, aber riskanter bei Abhängigkeiten

## Verwendungsbeispiele

### Beispiel 1: Einzelner Tag
```
1. Klick auf Tag 15.10.2025
2. Klick auf "📥 HRS Daten importieren"
3. Bestätigen
→ Import für 15.10.2025
```

### Beispiel 2: Range-Selektion
```
1. Klick auf Tag 15.10.2025
2. Shift + Klick auf Tag 22.10.2025
3. Klick auf "📥 HRS Daten importieren"
4. Bestätigen
→ Import für 15.10.2025 bis 22.10.2025 (8 Tage)
```

### Beispiel 3: Einzelne Tage
```
1. Klick auf Tag 15.10.2025
2. Strg + Klick auf Tag 18.10.2025
3. Strg + Klick auf Tag 22.10.2025
4. Klick auf "📥 HRS Daten importieren"
5. Bestätigen
→ Import für 15.10.2025 bis 22.10.2025
   (inkl. 16., 17., 19., 20., 21.)
```

**Hinweis:** Auch nicht-selektierte Tage innerhalb der Range werden importiert!

## Performance-Hinweise

### Import-Dauer

Geschätzte Dauer pro API:
- **Daily Summary**: ~2-5 Sekunden pro 10 Tage
- **Quota**: ~3-8 Sekunden pro 10 Tage
- **Reservierungen**: ~5-15 Sekunden pro 10 Tage (abhängig von Anzahl)

**Beispiel für 30 Tage:**
- Daily: ~10 Sekunden
- Quota: ~15 Sekunden
- Res: ~30 Sekunden
- **Total: ~55 Sekunden**

### Optimierungs-Tipps

1. **Kleine Ranges bevorzugen:**
   - Besser: 3× 10 Tage als 1× 30 Tage
   - Fehler betreffen kleinere Bereiche

2. **Nicht-Peak-Zeiten nutzen:**
   - Früh morgens oder spät abends
   - Weniger HRS-Last

3. **Bei Fehlern:**
   - Range verkleinern
   - Einzelne Tage neu importieren

## Bekannte Limitierungen

1. **Range-Gap-Problem:**
   - Selektion: Tag 5, 8, 12
   - Import: Tag 5 bis 12 (inkl. 6, 7, 9, 10, 11)
   - Lösung: Gezielt kleinere Ranges selektieren

2. **Kein Progress-Indicator:**
   - Nur "⏳ Importiere..." ohne Fortschritt
   - Verbesserung: Progress-Bar in Zukunft

3. **Keine Abbruch-Funktion:**
   - Import kann nicht gestoppt werden
   - Lösung: Warten bis Timeout/Fehler

4. **Keine Differential-Updates:**
   - Immer vollständiger Import für Range
   - Existierende Daten werden überschrieben

## Zukünftige Erweiterungen

1. **Progress-Indicator:**
   - "Importiere Daily... (1/3)"
   - "Importiere Quota... (2/3)"
   - "Importiere Res... (3/3)"

2. **Parallel-Import-Option:**
   - Checkbox "Parallel importieren (schneller, aber riskanter)"

3. **Selective Import:**
   - Checkboxen: ☑ Daily ☑ Quota ☑ Res
   - Nur gewünschte Daten importieren

4. **Import-Historie:**
   - Log aller Imports mit Zeitstempel
   - "Letzter Import: 08.10.2025 14:30"

5. **Auto-Refresh-Option:**
   - Checkbox "Automatisch aktualisieren nach Import"
   - Verhindert unnötige Reloads

6. **Dry-Run-Modus:**
   - "Vorschau" zeigt was importiert würde
   - Ohne tatsächlichen Import

## Troubleshooting

### Problem: Button ist ausgegraut
**Lösung:** Mindestens einen Tag im Histogram selektieren

### Problem: Import dauert sehr lange
**Ursachen:**
- Viele Tage selektiert (>30)
- Viele Reservierungen im Zeitraum
- HRS-Server langsam

**Lösung:**
- Range verkleinern
- Später erneut versuchen

### Problem: Fehler "Network request failed"
**Ursachen:**
- Internet-Verbindung unterbrochen
- HRS-API nicht erreichbar
- Firewall blockiert Request

**Lösung:**
- Internet-Verbindung prüfen
- VPN deaktivieren
- Später erneut versuchen

### Problem: Import erfolgreich, aber Daten fehlen
**Ursachen:**
- HRS lieferte leere Antwort
- Zeitraum hat keine Daten
- Datenbank-Sync fehlgeschlagen

**Lösung:**
- Console-Logs prüfen
- Direkt HRS-API testen
- Datenbank-Logs prüfen

---

**Version:** 1.0.0  
**Datum:** 2025-10-08  
**Autor:** GitHub Copilot  
**Abhängigkeiten:** Histogram Day Selection Feature
