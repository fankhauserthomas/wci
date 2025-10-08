# GET_AV_CAP_RANGE.PHP - Documentation

## Übersicht

`get_av_cap_range.php` ist eine erweiterte Version von `get_av_cap.php`, die einen spezifischen Datumsbereich für den Import von Verfügbarkeitsdaten unterstützt.

## Hauptunterschiede zu get_av_cap.php

| Feature | get_av_cap.php | get_av_cap_range.php |
|---------|---------------|---------------------|
| Datumsbereich | Fix (1 Jahr) | Flexibel (von/bis) |
| API-Calls | 1 (mit vielen Daten) | Optimiert nach Bedarf |
| Parameter | `hutID` | `hutID`, `von`, `bis` |
| Datenfilterung | Keine | Ja (exakter Bereich) |
| Use Case | Cronjob, Full Sync | Import-Button, Partielle Updates |

## API Endpunkt

```
GET /wci/api/imps/get_av_cap_range.php
```

## Parameter

| Parameter | Typ | Pflicht | Format | Beschreibung |
|-----------|-----|---------|--------|--------------|
| `hutID` oder `hutId` | Integer | ✅ | Numerisch | Hütten-ID (z.B. 675) |
| `von` | String | ✅ | YYYY-MM-DD | Start-Datum |
| `bis` | String | ✅ | YYYY-MM-DD | End-Datum |

## Intelligente API-Strategie

Da die HRS API **mindestens 10-11 Tage** zurückgibt, optimiert das Script die Anfragen:

### Strategie 1: Kurzer Zeitraum (≤ 11 Tage)
```
von: 2025-01-01
bis: 2025-01-07 (7 Tage)

→ 1 API-Call mit from=2025-01-01
→ API liefert 11 Tage (2025-01-01 bis 2025-01-11)
→ Script filtert auf 2025-01-01 bis 2025-01-07
→ Speichert nur 7 Tage
```

### Strategie 2: Langer Zeitraum (> 11 Tage)
```
von: 2025-01-01
bis: 2025-01-31 (31 Tage)

→ API-Call 1: from=2025-01-01 (liefert Tage 1-11)
→ API-Call 2: from=2025-01-12 (liefert Tage 12-22)
→ API-Call 3: from=2025-01-23 (liefert Tage 23-33)
→ Script filtert auf 2025-01-01 bis 2025-01-31
→ Speichert nur 31 Tage
```

## Beispiel-Anfragen

### Web (Browser)
```
https://example.com/wci/api/imps/get_av_cap_range.php?hutID=675&von=2025-01-01&bis=2025-01-15
```

### CLI (Terminal)
```bash
# Kurzer Bereich (1 Woche)
php get_av_cap_range.php --hutID=675 --von=2025-01-01 --bis=2025-01-07

# Langer Bereich (1 Monat)
php get_av_cap_range.php --hutID=675 --von=2025-01-01 --bis=2025-01-31

# Mehrere Monate
php get_av_cap_range.php --hutID=675 --von=2025-01-01 --bis=2025-03-31
```

### JavaScript (Timeline Import)
```javascript
const dateFromStr = '2025-01-01';
const dateToStr = '2025-01-15';

const response = await fetch(
  `../api/imps/get_av_cap_range.php?hutID=675&von=${dateFromStr}&bis=${dateToStr}`
);
const result = await response.json();

if (result.success) {
  console.log(`Saved ${result.summary.totalDaysSaved} days`);
  console.log(`Used ${result.summary.apiCalls} API calls`);
}
```

## Response Format

### Erfolgreiche Antwort
```json
{
  "success": true,
  "database": {
    "local": true,
    "local_count": 15,
    "local_deleted": 15,
    "local_has_hut_id": true,
    "local_has_categories": true,
    "remote": true,
    "remote_count": 15,
    "remote_deleted": 15,
    "remote_has_hut_id": true,
    "remote_has_categories": true,
    "errors": []
  },
  "summary": {
    "hutId": "675",
    "requestedRange": "2025-01-01 - 2025-01-15",
    "retrievedRange": "2025-01-01 - 2025-01-25",
    "savedRange": "2025-01-01 - 2025-01-15",
    "totalDaysRetrieved": 25,
    "totalDaysSaved": 15,
    "apiCalls": 2,
    "dataRetrieved": "2025-10-08 14:32:15",
    "categoryStats": {
      "kat_1958": { "avg": 12.5, "count": 15, "max": 18, "total": 187 },
      "kat_2293": { "avg": 8.3, "count": 15, "max": 12, "total": 125 },
      "kat_2381": { "avg": 5.1, "count": 15, "max": 8, "total": 77 },
      "kat_6106": { "avg": 3.2, "count": 15, "max": 6, "total": 48 }
    }
  }
}
```

### Fehler-Antwort
```json
{
  "success": false,
  "error": "Parameter von (start date) is required",
  "request": {
    "hutId": "675",
    "von": null,
    "bis": null
  },
  "suggestions": [
    "Check if hutId exists and is numeric",
    "Verify date format is YYYY-MM-DD",
    "Ensure von date is before or equal to bis date",
    "Verify the API endpoint is accessible",
    "Check if the database has the required columns"
  ],
  "timestamp": "2025-10-08 14:32:15"
}
```

## Response-Felder erklärt

### summary.requestedRange
Der vom Benutzer angeforderte Datumsbereich.

### summary.retrievedRange
Der tatsächlich von der HRS API abgerufene Bereich (immer größer oder gleich dem angeforderten Bereich).

### summary.savedRange
Der in die Datenbank gespeicherte Bereich (entspricht dem angeforderten Bereich).

### summary.totalDaysRetrieved
Gesamtanzahl der von der API abgerufenen Tage (vor Filterung).

### summary.totalDaysSaved
Anzahl der tatsächlich gespeicherten Tage (nach Filterung).

### summary.apiCalls
Anzahl der benötigten API-Aufrufe zur Abdeckung des Zeitraums.

### categoryStats
Statistiken für jede Zimmerkategorie:
- **avg**: Durchschnittliche Anzahl freier Betten
- **count**: Anzahl der Tage mit Daten
- **max**: Maximum freier Betten
- **total**: Summe aller freien Betten

## Datenbank-Operationen

### Tabelle: `av_belegung`

Das Script führt folgende Operationen aus:

1. **DELETE**: Entfernt existierende Daten im Datumsbereich
2. **INSERT**: Fügt neue Daten ein

### Spalten

**Basis-Spalten:**
- `datum` (DATE) - Datum
- `free_place` (INT) - Anzahl freier Plätze
- `hut_status` (VARCHAR) - Hüttenstatus

**Optionale Spalten:**
- `hut_id` (INT) - Hütten-ID (falls vorhanden)
- `kat_1958` (INT) - Kategorie 1958 freie Betten
- `kat_2293` (INT) - Kategorie 2293 freie Betten
- `kat_2381` (INT) - Kategorie 2381 freie Betten
- `kat_6106` (INT) - Kategorie 6106 freie Betten

Das Script erkennt automatisch, welche Spalten existieren und passt die INSERTs entsprechend an.

## Performance-Überlegungen

### API-Call-Optimierung

```
Zeitraum     API-Calls    Effizienz
1-11 Tage    1            ⭐⭐⭐⭐⭐ Optimal
12-22 Tage   2            ⭐⭐⭐⭐ Sehr gut
23-33 Tage   3            ⭐⭐⭐ Gut
1 Monat      3-4          ⭐⭐⭐ Gut
3 Monate     9-10         ⭐⭐ Akzeptabel
1 Jahr       34-35        ⭐ Langsam
```

### Empfehlungen

**Für tägliche Updates:**
```bash
# Nur heute und morgen
php get_av_cap_range.php --hutID=675 --von=2025-10-08 --bis=2025-10-09
# → 1 API-Call
```

**Für wöchentliche Updates:**
```bash
# Aktuelle Woche
php get_av_cap_range.php --hutID=675 --von=2025-10-06 --bis=2025-10-12
# → 1 API-Call
```

**Für monatliche Updates:**
```bash
# Aktueller Monat
php get_av_cap_range.php --hutID=675 --von=2025-10-01 --bis=2025-10-31
# → 3 API-Calls
```

## Fehlerbehandlung

### Häufige Fehler

#### 1. Fehlende Parameter
```json
{
  "success": false,
  "error": "Parameter von (start date) is required"
}
```
**Lösung:** Alle drei Parameter (hutID, von, bis) angeben.

#### 2. Ungültiges Datumsformat
```json
{
  "success": false,
  "error": "Invalid von date format. Use YYYY-MM-DD"
}
```
**Lösung:** Korrektes Format verwenden: `2025-01-01`

#### 3. Ungültige Datumsreihenfolge
```json
{
  "success": false,
  "error": "von date must be before or equal to bis date"
}
```
**Lösung:** Sicherstellen, dass `von` <= `bis`

#### 4. Ungültige hutID
```json
{
  "success": false,
  "error": "hutID must be numeric"
}
```
**Lösung:** Nur numerische hutID verwenden (z.B. 675, nicht "XYZ")

## Integration in Timeline

### Import-Button Workflow

```javascript
// 1. Benutzer selektiert Tage im Histogram
// 2. Benutzer klickt "HRS Daten importieren"
// 3. System berechnet von/bis aus Selektion
// 4. Sequential Import:

// Step 1: Daily Summary
await fetch(`../hrs/hrs_imp_daily.php?from=${von}&to=${bis}`);

// Step 2: Quota
await fetch(`../hrs/hrs_imp_quota.php?from=${von}&to=${bis}`);

// Step 3: Reservations
await fetch(`../hrs/hrs_imp_res.php?from=${von}&to=${bis}`);

// Step 4: AV Capacity (NEU mit Datumsbereich)
await fetch(`../api/imps/get_av_cap_range.php?hutID=675&von=${von}&bis=${bis}`);

// 5. Timeline neu laden
```

### Vorteile gegenüber get_av_cap.php

1. **Präzise Updates**: Nur der benötigte Zeitraum wird aktualisiert
2. **Schneller**: Weniger Daten = schnellere API-Aufrufe
3. **Konsistent**: Gleicher Zeitraum wie andere Import-Schritte
4. **Transparent**: Benutzer sieht exakt, was aktualisiert wird

## CLI-Output-Beispiel

```
🔍 DEBUG INFO:
Hut ID: 675
Requested Range: 2025-01-01 - 2025-01-15
Total Days: 15

📅 API STRATEGY:
API Calls Required: 2
Request Dates: 2025-01-01, 2025-01-12

🔍 API Request: https://www.hut-reservation.org/api/v1/reservation/getHutAvailability?hutId=675&step=WIZARD&from=2025-01-01
  ↪ HTTP 200, Size: 45,678 bytes
  ✓ Retrieved 11 days

🔍 API Request: https://www.hut-reservation.org/api/v1/reservation/getHutAvailability?hutId=675&step=WIZARD&from=2025-01-12
  ↪ HTTP 200, Size: 43,210 bytes
  ✓ Retrieved 11 days

📦 TOTAL DATA RETRIEVED: 22 days

✂️  FILTERED TO RANGE: 15 days

💾 SAVING TO DATABASE...

✅ SUCCESS - AVAILABILITY API (DATE RANGE)
Hut ID: 675
Requested Range: 2025-01-01 - 2025-01-15
Retrieved Range: 2025-01-01 - 2025-01-22
Saved Range: 2025-01-01 - 2025-01-15
Total Days Retrieved: 22
Total Days Saved: 15
API Calls: 2

📊 DATABASE RESULTS:
Local DB: ✅ Success (15 records)
Remote DB: ✅ Success (15 records)

🏠 CATEGORY STATISTICS:
Category 1958: avg 12.5 (max: 18)
Category 2293: avg 8.3 (max: 12)
Category 2381: avg 5.1 (max: 8)
Category 6106: avg 3.2 (max: 6)
```

## Cronjob-Integration

Obwohl dieser Endpunkt hauptsächlich für den Import-Button gedacht ist, kann er auch in Cronjobs verwendet werden:

```bash
#!/bin/bash
# update_av_cap_week.sh - Wöchentliches Update

# Berechne Datum von heute + 7 Tage
VON=$(date +%Y-%m-%d)
BIS=$(date -d "+7 days" +%Y-%m-%d)

# Führe Update aus
php /var/www/html/wci/api/imps/get_av_cap_range.php \
  --hutID=675 \
  --von=$VON \
  --bis=$BIS

# Log für Cron
echo "$(date): AV Capacity updated from $VON to $BIS" >> /var/log/av_cap_range.log
```

## Version History

### Version 1.0.0 (2025-10-08)
- Initial Release
- Intelligente API-Call-Optimierung
- Datumsbereich-Filterung
- CLI und Web Unterstützung
- Kategorie-Statistiken
- Duplikat-Entfernung

## Siehe auch

- `get_av_cap.php` - Original Version (1 Jahr, fixer Zeitraum)
- `hrs_imp_daily.php` - Daily Summary Import
- `hrs_imp_quota.php` - Quota Import
- `hrs_imp_res.php` - Reservierungen Import
- `HRS_IMPORT_FEATURE.md` - Import-Button Dokumentation
