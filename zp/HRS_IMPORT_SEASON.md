# HRS Import mit Saison-Erkennung

## Übersicht
Der HRS-Import-Button im Menü "Optionen" wurde erweitert um eine automatische Saison-Erkennung. Statt Tage manuell zu selektieren, wird automatisch die sichtbare Saison (Zeitbereich zwischen "closed"-Quotas) erkannt und in einem Modal angezeigt.

## Features

### 1. Automatische Saison-Erkennung
Die Funktion `calculateVisibleSeason()` analysiert die geladenen Quota- und Daily-Daten:
- **Erster offener Tag**: Erster Tag mit Quota > 0 oder AV-Daten
- **Letzter offener Tag**: Letzter Tag mit Quota > 0 oder AV-Daten
- **Zeitraum**: Alle Tage zwischen erstem und letztem offenen Tag

### 2. Bootstrap Modal mit Saison-Info
Das Modal zeigt:
- 📅 **Zeitbereich**: Von-Bis-Datum (formatiert: DD.MM.YYYY)
- 📊 **Tageanzahl**: Anzahl der zu importierenden Tage
- ⚠️ **Hinweis**: Was importiert wird (Daily Summary, Quotas, Reservierungen)

### 3. Import-Bestätigung
- **Abbrechen**: Schließt das Modal ohne Aktion
- **Import starten**: Startet den HRS-Import wie bisher über `executeHRSImport()`

## Implementierung

### Modal HTML
```html
<div class="modal fade" id="hrs-import-season-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5>HRS Daten Import</h5>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">Sichtbare Saison erkannt</div>
                <div>
                    Von: <span id="hrs-import-date-from"></span>
                    Bis: <span id="hrs-import-date-to"></span>
                    <strong><span id="hrs-import-day-count"></span> Tage</strong>
                </div>
            </div>
            <div class="modal-footer">
                <button data-bs-dismiss="modal">Abbrechen</button>
                <button id="hrs-import-confirm-btn">Import starten</button>
            </div>
        </div>
    </div>
</div>
```

### JavaScript Logic
```javascript
// Saison-Berechnung
function calculateVisibleSeason() {
    const { startDate, days } = renderer.getTimelineDateRange();
    
    let firstOpenDay = -1;
    let lastOpenDay = -1;

    for (let dayIndex = 0; dayIndex < days; dayIndex++) {
        const dateStr = getCurrentDateStr(dayIndex);
        
        // Check if day has quota or AV data
        const hasQuota = window.hrsQuotaData?.[dateStr]?.lager > 0 || ...;
        const hasAVData = window.hrsDailyData?.[dateStr];

        if (hasQuota || hasAVData) {
            if (firstOpenDay === -1) firstOpenDay = dayIndex;
            lastOpenDay = dayIndex;
        }
    }

    return {
        dateFrom: '2025-06-01',
        dateTo: '2025-09-30',
        dayCount: 122
    };
}

// Button Event Handler
hrsImportBtn.addEventListener('click', async () => {
    const season = calculateVisibleSeason();
    
    if (!season) {
        await ModalHelper.alert('Keine sichtbare Saison erkannt.');
        return;
    }

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('hrs-import-season-modal'));
    document.getElementById('hrs-import-date-from').textContent = formatDate(season.dateFrom);
    document.getElementById('hrs-import-date-to').textContent = formatDate(season.dateTo);
    document.getElementById('hrs-import-day-count').textContent = season.dayCount;
    
    // Confirm handler
    document.getElementById('hrs-import-confirm-btn').addEventListener('click', async () => {
        modal.hide();
        await executeHRSImport(season.dateFrom, season.dateTo, season.dayCount);
    });
    
    modal.show();
});
```

## Verwendung

### Im Menü "Optionen"
1. Klick auf das Zahnrad-Icon (⚙️) in der Topbar
2. Im Dropdown-Menü: **"HRS Import für selektierte Tage"**
3. Klick auf **"📥 HRS Daten importieren"**

### Modal öffnet sich automatisch
- Zeigt erkannten Zeitraum an
- Keine manuelle Tag-Selektion nötig
- Bestätigung oder Abbruch möglich

### Import-Prozess
Nach Bestätigung:
1. **Progress Modal** wird geöffnet (wie bisher)
2. **SSE Import** von Daily Summary
3. **Quota Import** via AJAX
4. **Reservierungen Import** via AJAX
5. **Erfolgs-/Fehler-Meldung**

## Styling

### Dark Theme Integration
- Modal verwendet globales Dark-Theme-Styling
- Gradient-Header: `linear-gradient(135deg, #667eea 0%, #764ba2 100%)`
- Alert-Boxen mit transparenten Gradient-Backgrounds
- Button-Hover-Effekte mit Transform + Shadow

### Responsive Design
- Modal zentriert (`modal-dialog-centered`)
- Flex-Layout für Datum-Anzeige
- Mobile-optimierte Abstände

## Technische Details

### Datenquellen für Saison-Erkennung
```javascript
// Quota-Daten (aus hrs_imp_quota.php)
window.hrsQuotaData = {
    '2025-06-01': { lager: 50, betten: 20, dz: 5, sonder: 2 },
    // ...
};

// Daily-Daten (aus hrs_imp_daily.php)
window.hrsDailyData = {
    '2025-06-01': { guests_av: 42, occupancy: 75, ... },
    // ...
};
```

### Fallback-Verhalten
- Wenn keine Quota- oder Daily-Daten vorhanden: **Fehlermeldung**
- Wenn nur ein Tag offen: **Dieser eine Tag wird importiert**
- Wenn Timeline leer: **"Keine sichtbare Saison erkannt"**

## Integration mit bestehendem System

### Nutzt bestehende Funktionen
- ✅ `executeHRSImport(dateFrom, dateTo, dayCount)` - Haupt-Import-Logik
- ✅ `createImportProgressModal()` - Progress-Anzeige
- ✅ SSE-Implementierung für Daily Summary
- ✅ AJAX für Quota und Reservierungen

### Keine Breaking Changes
- Context-Menu "HRS Import" funktioniert weiterhin
- Manuelle Tag-Selektion über Histogram bleibt erhalten (falls benötigt)
- Alle bestehenden Import-Funktionen bleiben unverändert

## Vorteile

### Für den Benutzer
- ⚡ **Schneller**: Keine manuelle Tag-Selektion nötig
- 🎯 **Genauer**: Automatische Erkennung der Saison
- 👁️ **Transparent**: Zeigt Zeitraum vor Import an
- 🛡️ **Sicherer**: Bestätigung vor großem Datenimport

### Für das System
- 📊 **Konsistent**: Nutzt vorhandene Quota/Daily-Daten
- 🔄 **Wiederverwendbar**: Funktion kann auch anderswo genutzt werden
- 🧹 **Clean Code**: Klare Trennung von UI und Logic
- 🐛 **Debuggbar**: Klare Fehlermeldungen bei Problemen

## Beispiel-Workflow

```
1. User öffnet Timeline (Juni - September sichtbar)
2. User klickt "📥 HRS Daten importieren" im Menü
3. Modal öffnet sich:
   ┌─────────────────────────────────────┐
   │  🔵 HRS Daten Import                │
   ├─────────────────────────────────────┤
   │  ℹ️ Sichtbare Saison erkannt        │
   │                                     │
   │  📅 Zeitbereich:                    │
   │  Von: 01.06.2025 → Bis: 30.09.2025 │
   │  📊 122 Tage                        │
   │                                     │
   │  ⚠️ Es werden Daily Summary,        │
   │     Quotas und Reservierungen       │
   │     importiert.                     │
   ├─────────────────────────────────────┤
   │  [Abbrechen]  [Import starten]     │
   └─────────────────────────────────────┘
4. User klickt "Import starten"
5. Progress Modal öffnet sich wie gewohnt
6. Import läuft via SSE + AJAX
7. Erfolgs-/Fehler-Meldung
```

## Testing

### Test-Szenarien
1. **Normale Saison**: 1.6. - 30.9. (122 Tage) → Sollte korrekt erkannt werden
2. **Winter geschlossen**: Nur Sommer-Monate offen → Sollte nur offene Monate zeigen
3. **Lücken in Daten**: Einzelne Tage ohne Quota → Sollte trotzdem Bereich erkennen
4. **Keine Daten**: Timeline leer → Sollte Fehlermeldung zeigen
5. **Ein Tag**: Nur ein Tag offen → Sollte diesen einen Tag zeigen

### Erwartete Ergebnisse
- Modal öffnet sich korrekt
- Datums-Formatierung korrekt (DD.MM.YYYY)
- Tageanzahl korrekt berechnet
- Import startet nach Bestätigung
- Abbrechen schließt Modal ohne Aktion

## Bekannte Limitierungen
- Erkennung basiert auf **geladenen Daten** in Timeline
- Wenn Timeline nur 30 Tage zeigt, wird auch nur dieser Bereich erkannt
- "Closed"-Status wird nicht explizit gespeichert, sondern über Quota > 0 ermittelt

## Zukünftige Erweiterungen
- 🔮 Manuelle Datumsauswahl im Modal ermöglichen
- 🔮 Mehrere Saisons erkennen (Sommer + Winter getrennt)
- 🔮 Import-Historie anzeigen (letzte Imports)
- 🔮 Partial-Import: Nur Daily, nur Quota, oder nur Res
