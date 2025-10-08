# Histogram Target Value Adjustment - Test-Anleitung

## Übersicht
Dieses Dokument beschreibt, wie die neue Zielwert-Anpassung für das Histogram getestet werden kann.

## Voraussetzungen
- Timeline-Ansicht mit Histogram-Daten geladen
- Mindestens 5 Tage mit unterschiedlichen Werten im sichtbaren Bereich

## Test-Szenarien

### Test 1: Basis-Funktionalität
**Ziel:** Prüfen ob Selektion und Mittelwert korrekt funktionieren

**Schritte:**
1. Öffne die Timeline-Ansicht
2. Scrolle zum Histogram-Bereich (ganz unten)
3. Klicke auf einen Tag mit mittlerer Balkenhöhe
4. Beobachte:
   - ✅ Blauer Rahmen um den Tag
   - ✅ Console-Log mit Datum
   - ✅ Console-Log mit Zielwert (entspricht Balkenhöhe)
   - ✅ Keine Pfeile (delta = 0)

**Erwartetes Ergebnis:**
```
📅 Histogram days selected: ['2025-10-15']
📊 Average capacity of 1 selected days: 85
🎯 Initial target value: 85
```

---

### Test 2: Multi-Selektion
**Ziel:** Mittelwert über mehrere Tage berechnen

**Schritte:**
1. Selektiere Tag 1 (Wert ~80)
2. `Strg` + Klick auf Tag 2 (Wert ~100)
3. `Strg` + Klick auf Tag 3 (Wert ~90)
4. Beobachte:
   - ✅ 3 Tage mit blauem Rahmen
   - ✅ Gelbe Ziellinie bei ~90
   - ✅ Pfeile zeigen:
     - Tag 1: ↑ grün +10
     - Tag 2: ↓ rot -10
     - Tag 3: keine Pfeil (delta ~0)
   - ✅ "Ziel: 90" Label rechts

**Erwartetes Ergebnis:**
```
📅 Histogram days selected: ['2025-10-15', '2025-10-16', '2025-10-17']
📊 Average capacity of 3 selected days: 90
🎯 Initial target value: 90
```

---

### Test 3: Mausrad-Erhöhung
**Ziel:** Zielwert via Mausrad nach oben anpassen

**Schritte:**
1. Behalte Selektion aus Test 2
2. Positioniere Maus über Tag 2 (mittlerer Tag)
3. Scrolle Mausrad **4× nach oben** (weg von dir)
4. Beobachte:
   - ✅ Ziellinie wandert nach oben
   - ✅ Zielwert: 90 + 20 = 110
   - ✅ Alle Pfeile zeigen nach oben (grün)
   - ✅ Delta-Werte aktualisieren sich:
     - Tag 1: +30
     - Tag 2: +10
     - Tag 3: +20

**Erwartetes Ergebnis:**
```
🎯 Histogram target value adjusted: 95
🎯 Histogram target value adjusted: 100
🎯 Histogram target value adjusted: 105
🎯 Histogram target value adjusted: 110
```

---

### Test 4: Mausrad-Senkung
**Ziel:** Zielwert via Mausrad nach unten anpassen

**Schritte:**
1. Behalte Selektion aus Test 3
2. Scrolle Mausrad **8× nach unten** (zu dir hin)
3. Beobachte:
   - ✅ Ziellinie wandert nach unten
   - ✅ Zielwert: 110 - 40 = 70
   - ✅ Alle Pfeile zeigen nach unten (rot)
   - ✅ Delta-Werte negativ:
     - Tag 1: -10
     - Tag 2: -30
     - Tag 3: -20

**Erwartetes Ergebnis:**
```
🎯 Histogram target value adjusted: 105
🎯 Histogram target value adjusted: 100
🎯 Histogram target value adjusted: 95
🎯 Histogram target value adjusted: 90
🎯 Histogram target value adjusted: 85
🎯 Histogram target value adjusted: 80
🎯 Histogram target value adjusted: 75
🎯 Histogram target value adjusted: 70
```

---

### Test 5: Range-Selektion
**Ziel:** Shift+Click Range-Selection mit Zielwert

**Schritte:**
1. Klick auf Tag 5
2. `Shift` + Klick auf Tag 10
3. Beobachte:
   - ✅ 6 Tage selektiert (5, 6, 7, 8, 9, 10)
   - ✅ Mittelwert über alle 6 Tage berechnet
   - ✅ Ziellinie erscheint
   - ✅ Pfeile für alle 6 Tage

**Erwartetes Ergebnis:**
```
📅 Histogram days selected: ['2025-10-19', '2025-10-20', ..., '2025-10-24']
📊 Average capacity of 6 selected days: 87
🎯 Initial target value: 87
```

---

### Test 6: ESC zum Löschen
**Ziel:** Selektion und Zielwert komplett zurücksetzen

**Schritte:**
1. Behalte beliebige Selektion aus vorherigen Tests
2. Drücke `ESC`-Taste
3. Beobachte:
   - ✅ Alle blauen Rahmen verschwinden
   - ✅ Ziellinie verschwindet
   - ✅ Alle Pfeile verschwinden
   - ✅ "Ziel:"-Label verschwindet

**Erwartetes Ergebnis:**
Keine Console-Logs, alles zurückgesetzt.

---

### Test 7: Nur auf Selektierten Tags
**Ziel:** Mausrad funktioniert NUR auf selektierten Tagen

**Schritte:**
1. Selektiere Tag 5 und Tag 8 (mit Strg)
2. Positioniere Maus über Tag 6 (NICHT selektiert)
3. Scrolle Mausrad
4. Beobachte:
   - ✅ Nichts passiert (Tag 6 scrollt normal)
   - ✅ Zielwert bleibt unverändert

5. Positioniere Maus über Tag 5 (selektiert)
6. Scrolle Mausrad
7. Beobachte:
   - ✅ Zielwert ändert sich
   - ✅ Console-Log erscheint

---

### Test 8: Grenzwerte
**Ziel:** Zielwert kann nicht unter 0 fallen

**Schritte:**
1. Selektiere einen Tag mit niedrigem Wert (z.B. 10)
2. Scrolle Mausrad stark nach unten (20× Schritte)
3. Beobachte:
   - ✅ Zielwert sinkt bis 0
   - ✅ Zielwert bleibt bei 0 (nicht negativ)
   - ✅ Ziellinie bleibt am unteren Rand

**Erwartetes Ergebnis:**
```
🎯 Histogram target value adjusted: 5
🎯 Histogram target value adjusted: 0
🎯 Histogram target value adjusted: 0  (bleibt bei 0)
```

---

### Test 9: Visual Inspection
**Ziel:** Visuelle Qualität prüfen

**Checkliste:**
- ✅ Rahmen sind scharf und gut sichtbar (3 Schichten)
- ✅ Ziellinie ist klar erkennbar (gestrichelt)
- ✅ Pfeile sind gerade und proportional
- ✅ Pfeilköpfe zeigen korrekt nach oben/unten
- ✅ Delta-Labels sind gut lesbar
- ✅ "Ziel:"-Label ist prominent platziert
- ✅ Farben sind konsistent:
  - Rahmen: Indigo (#6366f1)
  - Ziellinie: Amber (#fbbf24)
  - Aufwärts: Grün (#10b981)
  - Abwärts: Rot (#ef4444)
- ✅ Keine Überlappungen oder Clipping-Fehler

---

### Test 10: Performance-Test
**Ziel:** System bleibt flüssig bei vielen selektierten Tagen

**Schritte:**
1. Selektiere 1 Tag
2. `Shift` + Klick auf Tag +30 (31 Tage selektiert)
3. Scrolle Mausrad schnell mehrmals
4. Beobachte:
   - ✅ Keine Verzögerungen
   - ✅ Smooth rendering (>30 FPS)
   - ✅ Keine Console-Errors
   - ✅ Alle Pfeile werden korrekt gezeichnet

---

## Bug-Reporting

Falls Fehler gefunden werden, bitte folgende Informationen sammeln:

### Template
```markdown
**Test-Szenario:** Test X - [Name]
**Schritt:** [Nummer]
**Erwartetes Verhalten:** [...]
**Tatsächliches Verhalten:** [...]
**Console-Logs:** [Paste logs]
**Screenshot:** [Wenn möglich]
**Browser:** Chrome/Firefox/Safari [Version]
**System:** Windows/Mac/Linux
```

### Häufige Probleme

#### Problem: Pfeile werden nicht angezeigt
**Mögliche Ursachen:**
- Delta ist zu klein (< 2)
- `histogramTargetActive` ist false
- Balken außerhalb sichtbarem Bereich

**Debug:**
```javascript
console.log('Target Active:', timelineRenderer.histogramTargetActive);
console.log('Target Value:', timelineRenderer.histogramTargetValue);
console.log('Selected Days:', Array.from(timelineRenderer.selectedHistogramDays));
```

#### Problem: Mausrad funktioniert nicht
**Mögliche Ursachen:**
- Maus nicht über selektiertem Tag
- Keine Tage selektiert
- Event-Handler blockiert

**Debug:**
```javascript
// In Browser-Console:
canvas.addEventListener('wheel', (e) => {
    console.log('Wheel:', e.offsetX, e.offsetY, e.deltaY);
});
```

#### Problem: Mittelwert falsch
**Mögliche Ursachen:**
- `histogramData.dailyDetails` nicht korrekt
- Rundungsfehler

**Debug:**
```javascript
const avg = timelineRenderer.calculateSelectedDaysAverage();
console.log('Calculated Average:', avg);
```

---

## Erfolgs-Kriterien

Alle Tests müssen **✅ Bestanden** sein:

- [x] Test 1: Basis-Funktionalität
- [x] Test 2: Multi-Selektion
- [x] Test 3: Mausrad-Erhöhung
- [x] Test 4: Mausrad-Senkung
- [x] Test 5: Range-Selektion
- [x] Test 6: ESC zum Löschen
- [x] Test 7: Nur auf Selektierten Tags
- [x] Test 8: Grenzwerte
- [x] Test 9: Visual Inspection
- [x] Test 10: Performance-Test

---

**Test-Version:** 1.0  
**Erstellt:** 2025-10-08  
**Autor:** GitHub Copilot  
**Status:** Ready for Testing
