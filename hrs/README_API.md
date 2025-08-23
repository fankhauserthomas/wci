# HRS Import APIs - Einheitliche Schnittstelle

## Übersicht

Alle 3 HRS-Importer wurden vereinheitlicht und bieten sowohl CLI- als auch JSON-API-Zugang:

- `hrs_imp_quota.php` - Kapazitäts-Quota Import
- `hrs_imp_daily.php` - Daily Summary Import  
- `hrs_imp_res.php` - Reservierungen Import

## Einheitliche Parameter

**CLI Usage:**
```bash
php hrs_imp_quota.php 22.08.2025 31.08.2025
php hrs_imp_daily.php 22.08.2025 31.08.2025
php hrs_imp_res.php 22.08.2025 31.08.2025
```

**Web/JSON API Usage:**
```
hrs_imp_quota.php?from=22.08.2025&to=31.08.2025
hrs_imp_daily.php?from=22.08.2025&to=31.08.2025
hrs_imp_res.php?from=22.08.2025&to=31.08.2025
```

## JSON Response Format

**Erfolgreiche Response:**
```json
{
  "success": true,
  "message": "Import completed successfully",
  "dateFrom": "22.08.2025",
  "dateTo": "31.08.2025",
  "log": "Detailed execution log..."
}
```

**Fehler Response:**
```json
{
  "success": false,
  "error": "Error description",
  "log": "Detailed execution log..."
}
```

## Include-Verwendung

Die Dateien können direkt als Include verwendet werden:

```php
// Include-Beispiel
$_GET['from'] = '22.08.2025';
$_GET['to'] = '31.08.2025';

ob_start();
include 'hrs/hrs_imp_quota.php';
$json_response = ob_get_clean();
$result = json_decode($json_response, true);

if ($result['success']) {
    echo "Import erfolgreich!";
} else {
    echo "Fehler: " . $result['error'];
}
```

## Funktionen

### hrs_imp_quota.php
- **Ziel:** Kapazitäts-Quota-Änderungen
- **Tabellen:** `hut_quota`, `hut_quota_categories`, `hut_quota_languages`
- **API:** `/api/v1/manage/hutQuota`

### hrs_imp_daily.php  
- **Ziel:** Tägliche Zusammenfassungen
- **Tabellen:** `daily_summary`, `daily_summary_categories`
- **API:** `/api/v1/manage/reservation/dailySummary`
- **Features:** Duplikat-Schutz, 10-Tage-Blöcke

### hrs_imp_res.php
- **Ziel:** Reservierungen
- **Tabellen:** `AV-Res-webImp`
- **API:** `/api/v1/manage/reservation/list`
- **Features:** Paging, DELETE+INSERT Strategie

## Test-URLs

```
http://localhost:8080/wci/hrs/hrs_imp_quota.php?from=22.08.2025&to=25.08.2025
http://localhost:8080/wci/hrs/hrs_imp_daily.php?from=22.08.2025&to=25.08.2025  
http://localhost:8080/wci/hrs/hrs_imp_res.php?from=22.08.2025&to=25.08.2025
```

Alle APIs liefern JSON-Response mit `Content-Type: application/json` Header.
