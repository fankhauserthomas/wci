# Cookie-basierte Persistenz für Timeline-Einstellungen

## Übersicht

Die Timeline-Anwendung speichert automatisch alle Benutzereinstellungen in Cookies und localStorage, sodass diese nach einem Page Reload erhalten bleiben.

## Gespeicherte Einstellungen

### 1. **Datumsbereich (Wochen vor/nach heute)**
- **Cookie-Name**: `timeline_config`
- **Eigenschaften**:
  - `weeksPast`: Anzahl der Wochen in die Vergangenheit (0-52, Standard: 2)
  - `weeksFuture`: Anzahl der Wochen in die Zukunft (4-208, Standard: 104)
- **UI-Elemente**:
  - Input: `#timeline-weeks-past`
  - Input: `#timeline-weeks-future`
- **Speichern**: Automatisch bei Änderung über `updateTimelineWeekRange()`
- **Laden**: Beim Page Load über `loadThemeConfiguration()`

### 2. **Tagesbreite (Day Width)**
- **Cookie-Name**: `timeline_config`
- **Eigenschaft**: `dayWidth` (40-250px, Standard: 90)
- **Steuerung**: Pinch-Zoom, Mausrad-Zoom
- **Speichern**: Automatisch bei Zoom-Änderung
- **Laden**: Beim Page Load und Theme-Wechsel

### 3. **Zimmerhöhe (Room Height)**
- **Speicherung**: Über `applyPersistentSizingFromStorage()`
- **Steuerung**: Pinch-Resize, vertikale Zoom-Gesten
- **Laden**: Automatisch beim Renderer-Init

### 4. **Theme-Preset**
- **LocalStorage-Name**: `timeline_theme_preset`
- **Werte**: `professional`, `dark`, `midnight`, `ocean`, `forest`, `sunset`, `earth`, `rainbow`, `grayscale`, `custom`
- **UI-Element**: Select `#timeline-preset-select`

### 5. **Radial Menu Size**
- **LocalStorage-Name**: `timeline_menu_size`
- **Bereich**: 100-400px (Standard: 220)
- **UI-Element**: Slider `#timeline-menu-size`

## Implementierung

### Cookie-Speicherung

```javascript
function persistTimelineThemeConfig(config) {
    try {
        const configString = JSON.stringify(config);
        const expires = new Date();
        expires.setFullYear(expires.getFullYear() + 1); // 1 Jahr
        document.cookie = `timeline_config=${encodeURIComponent(configString)}; expires=${expires.toUTCString()}; path=/`;
        localStorage.setItem('timeline_config', configString);
    } catch (error) {
        console.warn('Timeline-Konfiguration konnte nicht gespeichert werden:', error);
    }
}
```

### Cookie-Laden

```javascript
function loadThemeConfiguration() {
    // 1. Versuche aus Cookie
    const cookieValue = document.cookie
        .split('; ')
        .find(row => row.startsWith('timeline_config='))
        ?.split('=')[1];

    if (cookieValue) {
        try {
            const config = JSON.parse(decodeURIComponent(cookieValue));
            return this.addMissingDefaults(config);
        } catch (e) {
            console.warn('Fehler beim Laden der Theme-Konfiguration:', e);
        }
    }

    // 2. Fallback: localStorage
    const localStorageValue = localStorage.getItem('timeline_config');
    if (localStorageValue) {
        try {
            const config = JSON.parse(localStorageValue);
            return this.addMissingDefaults(config);
        } catch (e) {
            console.warn('Fehler beim Laden der localStorage-Konfiguration:', e);
        }
    }

    // 3. Default: Professional Theme
    return {
        sidebar: { bg: '#2c3e50', text: '#ecf0f1', fontSize: 12 },
        header: { bg: '#34495e', text: '#ecf0f1', fontSize: 10 },
        master: { bg: '#2c3e50', bar: '#3498db', fontSize: 10, barHeight: 14 },
        room: { bg: '#2c3e50', bar: '#27ae60', fontSize: 10, barHeight: 16 },
        histogram: { bg: '#34495e', bar: '#e74c3c', text: '#ecf0f1', fontSize: 9 },
        dayWidth: 90,
        weeksPast: 2,
        weeksFuture: 104
    };
}
```

### UI-Update beim Laden

```javascript
function updateTimelineToolbarValues() {
    const renderer = getTimelineRenderer();
    const toolbar = document.getElementById('timeline-toolbar');
    if (!renderer || !toolbar) return;

    const config = renderer.themeConfig || {};

    // Preset auswählen
    const presetSelect = document.getElementById('timeline-preset-select');
    if (presetSelect) {
        let presetKey = detectTimelinePreset(config);
        presetSelect.value = presetKey;
    }

    // Weeks Past/Future
    const weeksPastInput = document.getElementById('timeline-weeks-past');
    const weeksFutureInput = document.getElementById('timeline-weeks-future');
    if (weeksPastInput) {
        weeksPastInput.value = String(config.weeksPast ?? 2);
    }
    if (weeksFutureInput) {
        weeksFutureInput.value = String(config.weeksFuture ?? 104);
    }

    // Menu Size
    const menuSizeSlider = document.getElementById('timeline-menu-size');
    const menuSizeDisplay = document.getElementById('timeline-menu-size-display');
    if (menuSizeSlider && menuSizeDisplay) {
        const storedSize = localStorage.getItem('timeline_menu_size');
        const currentSize = storedSize ? parseInt(storedSize, 10) : (renderer.radialMenu?.size ?? 220);
        menuSizeSlider.value = String(currentSize);
        menuSizeDisplay.textContent = `${currentSize}px`;
    }
}
```

## Workflow

### Beim Page Load

1. Browser lädt `timeline-unified.html`
2. `DOMContentLoaded` Event fired
3. `TimelineUnifiedRenderer` konstruktor ruft `loadThemeConfiguration()` auf
4. Config wird aus Cookie/localStorage geladen
5. `setupTimelineToolbar()` bindet Event Listener
6. `updateTimelineToolbarValues()` setzt Input-Werte aus Config
7. Renderer verwendet Config für initiales Rendering

### Bei Änderungen

#### Wochen-Bereich ändern:
1. User ändert `#timeline-weeks-past` oder `#timeline-weeks-future`
2. `change` Event trigger
3. `handleWeeksChange()` validiert Werte (clamp)
4. `updateTimelineWeekRange(past, future)` aufrufen
5. `persistTimelineThemeConfig()` speichert in Cookie + localStorage
6. `renderer.refreshThemeConfiguration()` lädt neue Config
7. `reloadTimelineData()` lädt Daten für neuen Zeitbereich

#### Tagesbreite ändern (Zoom):
1. User nutzt Pinch-Zoom oder Mausrad
2. Renderer aktualisiert `this.DAY_WIDTH`
3. Renderer ruft intern `persistTimelineThemeConfig()` auf
4. Config mit neuem `dayWidth` wird gespeichert
5. Beim nächsten Page Load wird der Wert wiederhergestellt

#### Theme-Preset ändern:
1. User wählt Preset aus `#timeline-preset-select`
2. `change` Event trigger
3. `applyTimelinePreset(presetKey)` anwenden
4. `persistTimelineThemeConfig()` mit neuem Theme
5. LocalStorage speichert Preset-Name separat

## Cookie-Details

### timeline_config Cookie

```
Name: timeline_config
Value: URL-encoded JSON string
Path: /
Expires: +1 Jahr
Size: ~500-1000 bytes
```

**Beispiel-Wert (decoded):**
```json
{
  "sidebar": { "bg": "#2c3e50", "text": "#ecf0f1", "fontSize": 12 },
  "header": { "bg": "#34495e", "text": "#ecf0f1", "fontSize": 10 },
  "master": { "bg": "#2c3e50", "bar": "#3498db", "fontSize": 10, "barHeight": 14 },
  "room": { "bg": "#2c3e50", "bar": "#27ae60", "fontSize": 10, "barHeight": 16 },
  "histogram": { "bg": "#34495e", "bar": "#e74c3c", "text": "#ecf0f1", "fontSize": 9 },
  "dayWidth": 120,
  "weeksPast": 4,
  "weeksFuture": 52
}
```

## Fallback-Strategie

1. **Cookie laden** - Primäre Quelle (überlebt Browser-Neustarts)
2. **localStorage laden** - Sekundäre Quelle (falls Cookie gelöscht)
3. **Default-Config** - Letzte Option (Professional Theme)

## Browser-Kompatibilität

- **Cookies**: Alle modernen Browser (IE6+)
- **localStorage**: Alle modernen Browser (IE8+)
- **JSON encoding**: Native support (IE8+)
- **URL encoding**: Native support (alle Browser)

## Testing

### Manueller Test

1. Timeline öffnen
2. Weeks Past auf 4 setzen
3. Weeks Future auf 52 setzen
4. Theme auf "Ocean" wechseln
5. Zoom auf ~120px Day Width
6. **F5 drücken (Page Reload)**
7. ✅ Alle Einstellungen sollten erhalten bleiben

### Console-Test

```javascript
// Config auslesen
const config = timelineRenderer.themeConfig;
console.log('Current Config:', config);

// Cookie auslesen
const cookieValue = document.cookie
    .split('; ')
    .find(row => row.startsWith('timeline_config='))
    ?.split('=')[1];
const cookieConfig = JSON.parse(decodeURIComponent(cookieValue));
console.log('Cookie Config:', cookieConfig);

// Manuell speichern
persistTimelineThemeConfig({ ...config, weeksPast: 8 });

// Manuell laden
const loaded = timelineRenderer.loadThemeConfiguration();
console.log('Loaded Config:', loaded);
```

## Troubleshooting

### Problem: Einstellungen werden nicht gespeichert

**Ursachen:**
- Cookies blockiert im Browser
- localStorage voll (5-10MB Limit)
- Private/Incognito Mode

**Lösung:**
- Browser-Einstellungen prüfen
- localStorage leeren: `localStorage.clear()`
- Normalen Browser-Modus verwenden

### Problem: Alte Werte nach Update

**Ursache:** 
- Cached Config enthält keine neuen Properties

**Lösung:**
- `addMissingDefaults()` ergänzt automatisch fehlende Werte
- Alternativ: Cookie manuell löschen

```javascript
// Cookie löschen
document.cookie = 'timeline_config=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
localStorage.removeItem('timeline_config');
location.reload();
```

### Problem: Inkonsistente Werte

**Ursache:**
- Config-Update ohne `persistTimelineThemeConfig()`

**Lösung:**
- Immer `persistTimelineThemeConfig()` nach Änderungen aufrufen
- `renderer.refreshThemeConfiguration()` für vollständiges Reload

## Best Practices

1. **Immer validieren**: Nutze `clampNumber()` für numerische Werte
2. **Fallbacks**: Nutze `??` operator für undefined-Checks
3. **Try-Catch**: Wrap Cookie/localStorage Operationen
4. **Konsistenz**: Update Config UND Renderer gleichzeitig
5. **User Feedback**: Log Warnings bei Speicher-Fehlern

## Erweiterung

Um neue persistente Eigenschaften hinzuzufügen:

1. **Default-Config erweitern** in `loadThemeConfiguration()`
2. **addMissingDefaults() erweitern** für Backward-Kompatibilität
3. **UI-Element hinzufügen** (Input/Slider/Select)
4. **Event Listener** mit `persistTimelineThemeConfig()` Aufruf
5. **updateTimelineToolbarValues()** für UI-Init erweitern
6. **Renderer-Logik** anpassen, um neue Property zu verwenden

## Zusammenfassung

✅ **Vollständig implementiert** - Alle Einstellungen werden automatisch gespeichert und geladen
✅ **Robust** - Fallback-Chain (Cookie → localStorage → Defaults)
✅ **User-Friendly** - Keine manuelle Konfiguration nötig
✅ **Performance** - Minimale Overhead durch lazy Loading
✅ **Kompatibel** - Funktioniert in allen modernen Browsern
