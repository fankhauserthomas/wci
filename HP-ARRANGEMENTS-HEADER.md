# HP-Arrangements im Reservation Header - Implementierung

## Übersicht

Das `#headerArrangements` Element in der Reservierungsansicht (`reservation.html`) wird jetzt mit Live-Daten aus der HP-Datenbank (Tischübersicht) befüllt. Die Implementierung umfasst eine zeitbasierte Farbkodierung, die den Status der Arrangements anzeigt.

## Datenverbindung

### Datenbankverknüpfung
- **AV-Res.id** ↔ **hp_data.resid**: Verknüpfung zwischen Reservierungen
- **hp_data.iid** ↔ **hpdet.hp_id**: Verknüpfung zwischen Gästen und Arrangements

### Tabellen-Struktur
- `hp_data`: Gäste-Informationen (iid, resid, nam, bem, etc.)
- `hpdet`: Arrangement-Details (hp_id, arr_id, anz, bem, ts)
- `hparr`: Verfügbare Arrangements (iid, bez, sort)

## Implementierte Dateien

### 1. `get-hp-arrangements-header.php`
Neue API-Endpoint für die Header-Anzeige:
- Lädt HP-Arrangements für eine spezifische Reservierung
- Berechnet zeitbasierte Klassifizierungen
- Gruppiert Arrangements nach Typ und Bemerkung
- Optimiert für Header-Display (weniger Details als Modal-API)

### 2. Aktualisierte `reservation.js`
- Verwendet neue Header-API (`get-hp-arrangements-header.php`)
- Implementiert zeitbasierte CSS-Klassen
- Automatische Aktualisierung alle 30 Sekunden
- Verbesserte Display-Logik für Arrangements mit Bemerkungen

### 3. Erweiterte `reservation.css`
Neue Zeitklassen-Styles:
- `time-fresh`: Rot (< 1 Minute alt)
- `time-recent`: Gold (< 2 Minuten alt) 
- `time-old`: Weiß (≥ 2 Minuten alt)
- `time-future`: Himmelblau (älter als gestern)

## Zeitklassen-System

### Klassifizierung
Basierend auf `TIMESTAMPDIFF(SECOND, hpdet.ts, NOW())`:

```php
if ($secondsAgo < 60) {
    $timeClass = 'time-fresh';    // Rot - frisch eingegeben
} elseif ($secondsAgo < 120) {
    $timeClass = 'time-recent';   // Gold - kürzlich eingegeben  
} else {
    $timeClass = 'time-old';      // Weiß - Standard
    
    // Spezialfall: Alte Timestamps (vor gestern)
    if ($tsDate < $currentDate) {
        $timeClass = 'time-future'; // Himmelblau
    }
}
```

### Visual Design
```css
.header-arr-item.time-fresh { color: #dc3545; font-weight: 700; }
.header-arr-item.time-recent { color: #ffc107; font-weight: 600; }
.header-arr-item.time-old { color: rgba(255,255,255,0.9); }
.header-arr-item.time-future { color: #87ceeb; font-weight: 500; }
```

## HTML-Struktur

```html
<div class="header-arrangements" id="headerArrangements">
  <div class="header-arr-title">HP Arrangements</div>
  <div class="header-arr-content" id="headerArrContent">
    <div class="header-arr-item time-fresh">
      <span class="arr-display-name">BHP Veg. (FRESH)</span>: 
      <span class="arr-count time-fresh">1x</span>
    </div>
    <!-- Weitere Arrangements... -->
  </div>
  <button class="header-arr-edit-btn">✏️</button>
</div>
```

## API-Response Format

```json
{
  "success": true,
  "arrangements": [
    {
      "id": 4,
      "name": "BHP Veg.",
      "remark": "FRESH",
      "display_name": "BHP Veg. (FRESH)",
      "total_count": 1,
      "time_class": "time-fresh",
      "guests": { "36934": "Oliver Gottschalk" },
      "details": [...]
    }
  ],
  "available_arrangements": {...},
  "total_items": 18,
  "guest_count": 1
}
```

## Features

### Live-Updates
- Automatische Aktualisierung alle 30 Sekunden
- Sofortige Updates nach Arrangement-Änderungen
- Zeitklassen werden live neu berechnet

### Benutzerinteraktion
- Klickbarer Header-Bereich öffnet Detail-Modal
- Edit-Button für direkte Bearbeitung
- Hover-Effekte zeigen Interaktivität

### Responsives Design
- Kompakte Darstellung im Header
- Scrollbare Liste bei vielen Arrangements
- Touch-friendly Controls

## Testing

### Test-Commands
```bash
# Direkter API-Test
php test-hp-header-direct.php

# Frisches Test-Arrangement erstellen
php create-test-arrangement.php

# HP-Datenbankstruktur analysieren
php test-hp-structure.php
```

### Browser-Test
- URL: `http://localhost/wci/reservation.html?id=6202`
- Überprüfe Header-Arrangements-Bereich rechts im grünen Header
- Farbkodierung sollte entsprechend Alter der Einträge variieren

## Wartung

### Logs
- Alle Arrangements-Operationen werden in error_log geschrieben
- Debug-Informationen bei API-Fehlern verfügbar

### Performance
- Optimierte Queries mit prepared statements
- Begrenzte API-Calls durch intelligentes Caching
- Minimal notwendige Datenübertragung für Header-Display

## Backup der ursprünglichen Funktionalität

Die bestehende Modal-Funktionalität (`get-hp-arrangements.php`) bleibt unverändert und funktionsfähig für die Detail-Bearbeitung der Arrangements.
