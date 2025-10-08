# Histogram Day Selection & Target Value Adjustment

## Übersicht
Das Auslastungsdiagramm (Histogram) unterstützt Windows-Style Multi-Selektion von Tagen mit automatischer Zielwert-Berechnung und visueller Anpassung via Mausrad.

## Funktionsweise

### Selektion-Modi

#### 1. **Einfacher Klick** (Normal Selection)
- Klick auf einen Tag → **Nur dieser Tag wird selektiert**
- Vorherige Selektion wird **gelöscht**

#### 2. **Strg+Klick** (Toggle Selection)
- `Strg` + Klick auf einen Tag → **Tag wird zur Selektion hinzugefügt/entfernt**
- Bereits selektierte Tage bleiben erhalten
- Windows: `Ctrl` + Klick
- Mac: `Cmd` (⌘) + Klick

#### 3. **Umschalt+Klick** (Range Selection)
- `Umschalt` + Klick auf einen Tag → **Alle Tage zwischen letztem und aktuellem Tag werden selektiert**
- Funktioniert nur wenn bereits ein Tag vorher selektiert wurde
- Range-Selektion ist **kumulativ** (vorherige Selektion bleibt erhalten)

### Visualisierung

Selektierte Tage werden mit einem **dreischichtigen Rahmen** markiert:
1. **Äußerer Glow**: Halbtransparenter blauer Rahmen (6px, rgba(99, 102, 241, 0.5))
2. **Innerer Rahmen**: Leuchtender Indigo-Rahmen (3px, #6366f1)
3. **Highlight-Overlay**: Subtiler transparenter Overlay (rgba(99, 102, 241, 0.1))

### Selektion löschen

- **ESC-Taste**: Löscht die gesamte Selektion und Zielwert
- **Einfacher Klick**: Löscht Selektion und wählt nur den angeklickten Tag

## Target Value Adjustment (Zielwert-Anpassung)

### Automatische Mittelwert-Berechnung

Sobald Tage selektiert sind, wird **automatisch der Mittelwert** der Balkenhöhen berechnet:

```javascript
// Beispiel: 3 Tage mit Werten 80, 100, 85
Mittelwert = (80 + 100 + 85) / 3 = 88 (gerundet)
```

Dieser Mittelwert wird als **Zielwert** (Target Value) verwendet.

### Mausrad-Anpassung

**Voraussetzung:** Mindestens ein Tag muss selektiert sein.

1. **Mauszeiger über selektierten Balken** positionieren
2. **Mausrad scrollen:**
   - **↑ Hoch scrollen** (weg von Benutzer) = Zielwert **erhöhen** (+5 pro Schritt)
   - **↓ Runter scrollen** (zu Benutzer) = Zielwert **senken** (-5 pro Schritt)

Der Zielwert kann nicht unter 0 fallen.

### Visuelle Darstellung

#### 1. Ziellinie (Target Line)
- **Farbe:** Amber/Gold (#fbbf24)
- **Stil:** Gestrichelte Linie (8px solid, 4px gap)
- **Position:** Horizontal über alle selektierten Tage
- **Höhe:** Entspricht dem aktuellen Zielwert

#### 2. Richtungspfeile (Direction Arrows)
Von der **Oberkante jedes selektierten Balkens** zum **Zielwert**:

- **↑ Grüner Pfeil** (#10b981): Balken muss **erhöht** werden
- **↓ Roter Pfeil** (#ef4444): Balken muss **gesenkt** werden
- **Pfeilkopf:** 6px Dreieck in Richtung des Ziels

#### 3. Delta-Label
- **Position:** Mittig zwischen Balken-Oberkante und Ziellinie
- **Offset:** 8px rechts vom Pfeil
- **Format:** `+15` (grün) oder `-23` (rot)
- **Hintergrund:** Halbtransparentes Schwarz (75% Opazität)
- **Schriftart:** Bold 11px Arial

#### 4. Zielwert-Label
- **Position:** Rechts neben dem letzten selektierten Tag
- **Text:** `Ziel: 88`
- **Farbe:** Amber (#fbbf24) mit Border (#f59e0b)
- **Schriftart:** Bold 12px Arial

### Beispiel-Visualisierung

```
      Ziel: 88 ←─────┐
  ┌───────────┬───────┼───────┬───────┐
  │           │   ↑+8 │       │   ↓-12│
100├───────────┤   │   ├───────┤   │   │
  │███████████│   │   │███████│   │   │
  │███████████│   │   │███████│   │   │
 80├───────────┤───●───┼───────┤───●───┤ ← Target Line (88)
  │███████████│       │███████│       │
  │███████████│       │███████│███████│
  └───────────┴───────┴───────┴───────┘
     Tag 5      Tag 8    Tag 12  Tag 15
   (aktuell 80) (+8)   (aktuell 100) (-12)
```

## Technische Details

### State Management (Erweitert)

```javascript
// Im TimelineUnifiedRenderer Constructor
this.selectedHistogramDays = new Set();        // Set of day indices
this.lastSelectedDayIndex = null;              // For shift+click range
this.isHistogramDaySelectionActive = false;    // Flag for rendering
this.histogramTargetValue = null;              // Target capacity value
this.histogramTargetActive = false;            // Flag to show target arrows
```

### Neue Methoden

#### `calculateSelectedDaysAverage()`
Berechnet den Mittelwert der Balkenhöhen aller selektierten Tage.

**Returns:** `number` - Gerundeter Mittelwert

**Implementation:**
```javascript
calculateSelectedDaysAverage() {
    if (this.selectedHistogramDays.size === 0) return 0;
    
    const histogramData = this.getHistogramData(startDate, endDate);
    let sum = 0, count = 0;
    
    this.selectedHistogramDays.forEach(dayIndex => {
        const detail = histogramData.dailyDetails[dayIndex];
        if (detail) {
            sum += (detail.total || 0);
            count++;
        }
    });
    
    return count > 0 ? Math.round(sum / count) : 0;
}
```

### Mousewheel Event-Handler (Erweitert)

In `canvas.addEventListener('wheel', ...)`:

```javascript
// Priority check: Histogram target adjustment
if (mouseY in histogram area && isHistogramDaySelectionActive) {
    const dayIndex = findHistogramDayAt(mouseX);
    
    if (dayIndex !== null && selectedHistogramDays.has(dayIndex)) {
        // Initialize target value on first wheel
        if (histogramTargetValue === null) {
            histogramTargetValue = calculateSelectedDaysAverage();
        }
        
        // Adjust target value
        const delta = e.deltaY > 0 ? -5 : 5; // Inverted
        histogramTargetValue = Math.max(0, histogramTargetValue + delta);
        
        render();
        return; // Stop further event processing
    }
}
```

### Arrow Rendering Logic

In `renderHistogramAreaOptimized()` nach Selection Frames:

```javascript
if (histogramTargetActive && histogramTargetValue !== null) {
    // 1. Calculate target Y position
    const targetRatio = histogramTargetValue / scaledMax;
    const targetY = chartBottomY - (targetRatio * availableHeight);
    
    // 2. Draw horizontal target line
    ctx.strokeStyle = '#fbbf24'; // Amber
    ctx.setLineDash([8, 4]);
    // ... draw line across selected days
    
    // 3. For each selected day:
    dailyDetails.forEach((detail, dayIndex) => {
        if (!selectedHistogramDays.has(dayIndex)) return;
        
        const currentValue = detail.total || 0;
        const delta = histogramTargetValue - currentValue;
        
        if (Math.abs(delta) > 1) {
            // Draw arrow from bar top to target
            // Draw arrow head (triangle)
            // Draw delta label (+XX or -XX)
        }
    });
    
    // 4. Draw target value label
    ctx.fillText(`Ziel: ${histogramTargetValue}`, ...);
}
```

### Performance-Optimierung

- **Conditional Rendering:** Pfeile nur wenn `histogramTargetActive === true`
- **Delta-Threshold:** Pfeile nur wenn `|delta| > 1` (verhindert Clutter bei kleinen Unterschieden)
- **Clipping:** Canvas-Clipping verhindert Rendering außerhalb sichtbaren Bereichs
- **Lazy Calculation:** Mittelwert nur bei Selektionsänderung berechnet

## Verwendung

### Beispiel-Workflow

1. **Tage selektieren:**
   - Klick auf Tag 5 (Wert: 80)
   - `Strg` + Klick auf Tag 8 (Wert: 100)
   - `Strg` + Klick auf Tag 12 (Wert: 85)
   - → Mittelwert wird berechnet: **88**
   - → Ziellinie erscheint bei 88
   - → Pfeile zeigen: +8, -12, +3

2. **Zielwert anpassen:**
   - Maus über Tag 8 bewegen
   - Mausrad **3× hoch scrollen**
   - → Neuer Zielwert: 88 + 15 = **103**
   - → Alle Pfeile aktualisieren sich:
     - Tag 5: +23 (statt +8)
     - Tag 8: +3 (statt -12)
     - Tag 12: +18 (statt +3)

3. **Zielwert weiter reduzieren:**
   - Mausrad **6× runter scrollen**
   - → Neuer Zielwert: 103 - 30 = **73**
   - → Alle Pfeile invertieren:
     - Tag 5: -7 (rot)
     - Tag 8: -27 (rot)
     - Tag 12: -12 (rot)

4. **Selektion aufheben:**
   - ESC drücken
   - → Alle Visualisierungen verschwinden

### Console-Logging

```
📅 Histogram days selected: ['2025-10-15', '2025-10-18', '2025-10-22']
📊 Average capacity of 3 selected days: 88
🎯 Initial target value: 88
🎯 Histogram target value adjusted: 103
🎯 Histogram target value adjusted: 73
```

```javascript
// Im TimelineUnifiedRenderer Constructor
this.selectedHistogramDays = new Set();     // Selektierte Tag-Indices
this.lastSelectedDayIndex = null;           // Für Range-Selection
this.isHistogramDaySelectionActive = false; // Render-Flag
this.histogramTargetValue = null;           // Zielwert für Kapazität
this.histogramTargetActive = false;         // Flag für Pfeil-Rendering
```

### Methoden (Komplett)

#### `findHistogramDayAt(mouseX)`
Bestimmt den Tag-Index basierend auf der Mouse-X-Position.

**Returns:** `number | null` - Day-Index oder null wenn außerhalb

#### `handleHistogramDayClick(dayIndex, event)`
Verarbeitet Klicks auf Histogram-Tage mit Ctrl/Shift-Logik.

**Parameters:**
- `dayIndex` - Der geklickte Tag-Index
- `event` - MouseEvent mit ctrlKey/shiftKey/metaKey

**Behavior:**
- Aktualisiert `selectedHistogramDays` Set
- Setzt `lastSelectedDayIndex` für Range-Selection
- Triggert Re-Render
- Loggt selektierte Daten in Console

#### `clearHistogramDaySelection()`
Löscht die gesamte Selektion und rendert neu.

### Rendering

Die Rahmen werden in `renderHistogramAreaOptimized()` gezeichnet, **nach** allen Balken aber **vor** dem finalen `ctx.restore()`.

```javascript
// Pseudo-Code
if (isHistogramDaySelectionActive) {
    selectedHistogramDays.forEach(dayIndex => {
        // Berechne X-Position
        // Zeichne dreischichtigen Rahmen
    });
}
```

### Event-Integration

Die Klick-Erkennung erfolgt in `handleMouseDown()` **vor** der Separator-Erkennung:

```javascript
// Histogram Click hat Priorität bei Y-Position im Histogram-Bereich
if (mouseY >= histogram.y && mouseY <= histogram.y + histogram.height) {
    const dayIndex = findHistogramDayAt(mouseX);
    if (dayIndex !== null) {
        handleHistogramDayClick(dayIndex, event);
        return; // Stop weitere Event-Verarbeitung
    }
}
```

### Keyboard-Handler

ESC-Taste löscht Selektion **vor** anderen ESC-Aktionen (Menüs schließen):

```javascript
window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        if (isHistogramDaySelectionActive) {
            clearHistogramDaySelection();
            return; // Verhindert weitere ESC-Aktionen
        }
        // ... andere ESC-Handler
    }
});
```

## Verwendung

### Beispiel-Workflow

1. **Einzelne Tage markieren:**
   - Klick auf Tag 5
   - `Strg` + Klick auf Tag 8
   - `Strg` + Klick auf Tag 12
   - → Tage 5, 8, 12 sind selektiert

2. **Range hinzufügen:**
   - `Umschalt` + Klick auf Tag 15
   - → Tage 5, 8, 12, 13, 14, 15 sind selektiert

3. **Tag entfernen:**
   - `Strg` + Klick auf Tag 8
   - → Tage 5, 12, 13, 14, 15 sind selektiert

4. **Selektion löschen:**
   - ESC-Taste drücken
   - → Keine Selektion

### Console-Logging

Bei jeder Selektion wird eine Liste der ausgewählten Daten geloggt:

```
📅 Histogram days selected: ['2025-10-15', '2025-10-18', '2025-10-22']
```

## Zukünftige Erweiterungen

Mögliche Features für die Zukunft:

1. **Aktionen auf Zielwert:**
   - "Anwenden"-Button um Zielwert tatsächlich auf Daten zu übertragen
   - API-Integration zur Kapazitäts-Anpassung
   - Bestätigungs-Dialog mit Zusammenfassung

2. **✅ HRS-Import (IMPLEMENTIERT):**
   - **Import-Button im Optionen-Menü**
   - Importiert Daily, Quota & Reservierungen für selektierte Tage
   - Nutzt `dat_min` und `dat_max` aus Selektion
   - Siehe: `HRS_IMPORT_FEATURE.md`

3. **Erweiterte Anpassung:**
   - **Strg+Mausrad**: Feinere Anpassung (±1 statt ±5)
   - **Shift+Mausrad**: Gröbere Anpassung (±10 oder ±20)
   - Direkte Eingabe via Input-Field

3. **Statistiken:**
   - Min/Max/Median der selektierten Tage
   - Standardabweichung anzeigen
   - Histogramm der Verteilung

4. **Visuelle Verbesserungen:**
   - Animation beim Zielwert-Ändern (smooth transitions)
   - Gradient-Pfeile (Farbe je nach Größe des Deltas)
   - Vorschau-Modus (gestrichelte Balken auf Zielhöhe)

5. **Export & Reporting:**
   - CSV-Export mit Ist/Soll-Werten
   - PDF-Report mit Visualisierung
   - Screenshot-Funktion

6. **Keyboard-Shortcuts:**
   - `↑/↓ Pfeiltasten`: Zielwert anpassen
   - `R`: Zielwert auf Mittelwert zurücksetzen
   - `Enter`: Zielwert anwenden

## Browser-Kompatibilität

- ✅ Chrome/Edge (Chromium)
- ✅ Firefox
- ✅ Safari (macOS/iOS)
- ✅ Mobile Browsers (Touch-Events werden unterstützt)
- ⚠️ Mousewheel-Anpassung nur auf Desktop (Touch-Alternative: Slider?)

## Performance

- **Set-basierte Speicherung** für O(1) Lookup und Insert
- **Render-Throttling** bereits im Timeline-System integriert
- **Clipping** verhindert unnötiges Zeichnen außerhalb des sichtbaren Bereichs
- **Conditional Rendering** - Rahmen/Pfeile nur wenn aktiv
- **Delta-Threshold** - Keine Pfeile bei |delta| < 2 (reduziert Clutter)
- **Lazy Calculation** - Mittelwert nur bei Änderung berechnet

---

**Version:** 2.0.0  
**Datum:** 2025-10-08  
**Autor:** GitHub Copilot  
**Features:** Multi-Select, Target Value Adjustment, Direction Arrows
