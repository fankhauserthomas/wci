# 🎉 Holiday Manager für Timeline

## Überblick

Das Holiday Manager System integriert Feiertage aus Deutschland, Österreich und Italien in die Timeline-Ansicht. Es nutzt die kostenlose **Nager.Date API** (https://date.nager.at/) für aktuelle Feiertagsdaten.

## Features

### ✅ Implementiert

- **Multi-Country Support**: DE, AT, IT Feiertage
- **Intelligent Caching**: 24h Cache zur Performance-Optimierung
- **Visual Integration**: Flaggen-Badges in Timeline-Tagesheadern
- **CORS-Proxy**: Fallback über eigenen Server für API-Zugriff
- **Fallback-Daten**: Wichtigste Feiertage falls API nicht verfügbar
- **Debug-Integration**: Holiday-Debug-Panel in bestehender Debug-Konsole

### 🎨 Visuelle Darstellung

```
Mo, 15.01 🇩🇪🇦🇹  (Dreikönigstag in DE/AT)
Di, 01.05 🇩🇪🇦🇹🇮🇹  (Tag der Arbeit in allen Ländern)
Sa, 25.12 🇩🇪🇦🇹🇮🇹  (Weihnachten in allen Ländern)
```

## Technische Details

### Dateien

1. **`holiday-manager.js`** - Haupt-Holiday-Manager-Klasse
2. **`api-proxy.php`** - CORS-Proxy für externe API-Aufrufe  
3. **`timeline-unified.html`** - Erweitert um Holiday-Integration
4. **`holiday-test.html`** - Standalone Test für Holiday Manager

### API-Endpunkte

- **Primär**: `https://date.nager.at/api/v3/PublicHolidays/{year}/{countryCode}`
- **Proxy**: `/wci/api-proxy.php?url=...` (falls CORS-Probleme)

### Caching-Strategie

```javascript
// Cache-Key Format: "{countryCode}_{year}"
// Beispiele: "DE_2025", "AT_2025", "IT_2025"

// Cache-Dauer: 24 Stunden
// Fallback: Bekannte Haupt-Feiertage pro Land
```

## Integration

### Automatische Integration

```javascript
// Holiday Manager erweitert automatisch TimelineUnifiedRenderer
proto.formatDateCaption = function(date, options = {}) {
    let caption = originalFormatDateCaption.call(this, date, options);
    
    // Feiertags-Badge hinzufügen
    const holidayInfo = window.holidayManager.getHolidayInfo(date, this.holidaysData);
    const holidayBadge = window.holidayManager.createHolidayBadge(holidayInfo);
    
    return caption + holidayBadge;
};
```

### Manuelle Verwendung

```javascript
// Feiertage für Zeitraum laden
const holidays = await window.holidayManager.loadHolidaysForDateRange(startDate, endDate);

// Feiertag für bestimmtes Datum prüfen
const holidayInfo = window.holidayManager.getHolidayInfo(new Date('2025-12-25'), holidays);

// Badge erstellen
const badge = window.holidayManager.createHolidayBadge(holidayInfo);
```

## Debug & Testing

### Debug-Konsole

1. Timeline öffnen
2. "Debug Console" Button klicken  
3. "🎉 Holidays" Button klicken
4. Holiday-Status und Cache-Info anzeigen

### Test-Seite

```
http://localhost/wci/zp/holiday-test.html
```

Zeigt Holiday Manager isoliert mit Test-Daten für 2025.

## Konfiguration

### Länder aktivieren/deaktivieren

```javascript
// In holiday-manager.js
this.enabledCountries = ['DE', 'AT', 'IT']; // Anpassen nach Bedarf
```

### Cache-Dauer ändern

```javascript
// In holiday-manager.js  
this.cacheExpiry = 24 * 60 * 60 * 1000; // 24h in Millisekunden
```

### Styling anpassen

```css
/* In timeline-unified.html */
.holiday-badge {
    /* Basis-Styling für alle Holiday-Badges */
}

.holiday-badge[title*="🇩🇪"] {
    /* Deutschland-spezifisches Styling */
}
```

## API-Datenstruktur

### Nager.Date Response

```json
{
  "date": "2025-01-01",
  "localName": "Neujahr", 
  "name": "New Year's Day",
  "countryCode": "DE",
  "fixed": false,
  "global": true,
  "counties": null,
  "launchYear": null,
  "types": ["Public"]
}
```

### Internes Holiday-Info Format

```javascript
{
  country: "DE",
  localName: "Neujahr",
  name: "New Year's Day", 
  global: true,
  counties: null,
  types: ["Public"]
}
```

## Fehlerbehebung

### CORS-Probleme

Falls direkte API-Aufrufe fehlschlagen:

1. Prüfe `api-proxy.php` Installation
2. Teste Proxy: `/wci/api-proxy.php?url=https://date.nager.at/api/v3/PublicHolidays/2025/DE`
3. Fallback-Daten werden automatisch verwendet

### Cache-Probleme  

```javascript
// Cache löschen
window.holidayManager.holidayCache.clear();
window.holidayManager.lastCacheTime.clear();

// Neu laden erzwingen
await window.holidayManager.triggerHolidayReload();
```

### Timeline Integration

Falls Feiertags-Badges nicht erscheinen:

1. Console-Logs prüfen
2. Debug-Panel öffnen: "🎉 Holidays" 
3. `window.holidayManager` in Browser-Console prüfen
4. Timeline Renderer Status prüfen: `window.timelineRenderer`

## Performance

- **Initiale Ladung**: ~300-800ms pro Land/Jahr
- **Cache-Hit**: <1ms  
- **Fallback**: <5ms
- **Memory Usage**: ~50KB für 3 Jahre × 3 Länder

## Zukunft / Erweiterungen

### Geplante Features

- [ ] **Schulferien-Integration** (Nager.Date v3.1 School Holidays API)
- [ ] **Lokale Feiertage** (Bundesland/Region-spezifisch)  
- [ ] **Custom Holidays** (Firmen-Events, etc.)
- [ ] **Holiday-Filter** (Ein/Ausblenden nach Land)
- [ ] **Erweiterte Tooltips** (Feiertagsdetails)

### API-Erweiterungen

- [ ] Unterstützung für weitere Länder (CH, FR, etc.)
- [ ] Historische Feiertage (Jahre < 1990) 
- [ ] Bewegliche Feiertage (Ostern-Berechnung)

---

**Erstellt**: Oktober 2025  
**Version**: 1.0  
**API**: Nager.Date v3  
**Kompatibilität**: Timeline Unified Renderer