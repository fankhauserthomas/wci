# Quota Management - Complete Flow Documentation

## Überblick

Das Quota-Management-System verwaltet Quotas sowohl in der **lokalen Datenbank** als auch im **HRS-System**.

## Architektur

### 1. Frontend (Timeline UI)
- `timeline-unified.html` - Hauptanwendung
- `quota-input-modal.html` - Quota-Eingabe-Modal
- `quota-management-integration.js` - Integration Layer

### 2. Backend APIs

#### A. Lokale Datenbank-Verwaltung
**`hrs_update_quota_timeline.php`**
- Erstellt/Löscht Quotas in lokaler DB (`hut_quota`, `hut_quota_categories`)
- Berechnet Quota-Verteilung nach Prioritäten
- Ruft HRS-Upload-Scripts auf

#### B. HRS-System-Integration
**`hrs_create_quota_batch.php`**
- Upload von Quotas ins HRS-System
- Unterstützt kategorie-spezifische Werte (Lager, Betten, DZ, Sonder)
- Gibt HRS-IDs zurück für DB-Update

**`hrs_del_quota_batch.php`**
- Löscht Quotas im HRS-System
- Arbeitet mit hrs_id Werten

## Ablauf beim Speichern

```
┌─────────────────────────────────────────────────┐
│ 1. USER: Quotas im Modal eingeben              │
└──────────────────┬──────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────┐
│ 2. FRONTEND: Berechnung mit neuer Formel       │
│    Quota = Ziel + AV - Intern (min. 0)         │
└──────────────────┬──────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────┐
│ 3. API: hrs_update_quota_timeline.php          │
│    ├─ Validierung                               │
│    ├─ Berechnung der Verteilung                 │
│    ├─ Lösche alte Quotas (lokal)                │
│    ├─ Erstelle neue Quotas (lokal)              │
│    └─ Commit Transaktion                        │
└──────────────────┬──────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────┐
│ 4. HRS UPLOAD: uploadQuotasToHRS()             │
│    ├─ Lösche alte HRS-Quotas                    │
│    │  └─ Call: hrs_del_quota_batch.php          │
│    │                                             │
│    ├─ Erstelle neue HRS-Quotas                  │
│    │  └─ Call: hrs_create_quota_batch.php       │
│    │                                             │
│    └─ Update lokale DB mit HRS-IDs              │
└──────────────────┬──────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────┐
│ 5. FRONTEND: Reload Timeline & Close Modal     │
└─────────────────────────────────────────────────┘
```

## Datenstrukturen

### Request (Frontend → Backend)
```json
{
  "selectedDays": ["2026-02-15", "2026-02-16"],
  "targetCapacity": 77,
  "priorities": [
    {"category": "lager", "max": null},
    {"category": "betten", "max": 10},
    {"category": "dz", "max": 2},
    {"category": "sonder", "max": 4}
  ],
  "operation": "update"
}
```

### Response (Backend → Frontend)
```json
{
  "success": true,
  "calculatedQuotas": {
    "lager": 67,
    "betten": 10,
    "dz": 0,
    "sonder": 0
  },
  "affectedDays": ["2026-02-15", "2026-02-16"],
  "deletedQuotas": [3703],
  "createdQuotas": [3708, 3709],
  "hrsUpload": {
    "deleted": 1,
    "created": 2,
    "errors": []
  },
  "message": "2 Tage erfolgreich aktualisiert (lokal + HRS)"
}
```

### HRS Create Payload
```json
{
  "quotas": [
    {
      "db_id": 3708,
      "title": "Timeline-150226",
      "date_from": "2026-02-15",
      "date_to": "2026-02-16",
      "capacity": 77,
      "categories": {
        "lager": 67,
        "betten": 10,
        "dz": 0,
        "sonder": 0
      }
    }
  ]
}
```

## Datenbank-Schema

### hut_quota (Haupttabelle)
```sql
CREATE TABLE `hut_quota` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hrs_id` int NOT NULL COMMENT 'Original ID aus HRS System',
  `hut_id` int NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `mode` enum('SERVICED','UNSERVICED','CLOSED') NOT NULL,
  ...
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_hrs_id` (`hrs_id`)
)
```

### hut_quota_categories (Kategorie-Details)
```sql
CREATE TABLE `hut_quota_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hut_quota_id` int NOT NULL,
  `category_id` int NOT NULL,
  `total_beds` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `hut_quota_id` (`hut_quota_id`)
)
```

### Category IDs (HRS System)
- `1958` - Matratzenlager (ML)
- `2293` - Mehrbettzimmer (MBZ)
- `2381` - Zweibettzimmer (2BZ)
- `6106` - Sonderkategorie (SK)

## HRS-ID Generierung

**Lokal erstellte Quotas:**
```php
// Format: 9MMDDHHSS (9-digit number starting with 9)
$hrs_id = 900000000 + (int)date('mdHis');
```

Beispiel: `900101133045` = 9 (lokal) + 01 (Jan) + 01 (Tag) + 13 (Stunde) + 30 (Minute) + 45 (Sekunde)

**HRS-System Quotas:**
- Original HRS-IDs sind < 900000000
- Werden vom HRS-System bei Erstellung vergeben

## Fehlerbehandlung

### Lokal
- Bei Fehler: Rollback der DB-Transaktion
- Keine Änderungen in lokaler DB

### HRS
- HRS-Fehler werden geloggt aber blockieren nicht die lokale Speicherung
- Quotas sind in lokaler DB vorhanden
- HRS-Upload kann später wiederholt werden

## Testing

### Lokaler Test
```bash
php test_quota_api.php
```

### Kompletter Flow Test
```bash
php test_complete_quota_flow.php
```

### Frontend Test
1. Timeline öffnen
2. Rechtsklick auf Histogram-Tage
3. "Quotas verwalten"
4. Werte eingeben
5. Speichern
6. Prüfen: Timeline aktualisiert + HRS-System synchronisiert

## Monitoring

### Logs
- **Backend**: `error_log()` in PHP
- **HRS Delete**: `hrs/debug_hrs_delete.log`
- **HRS Create**: Siehe `hrs_create_quota_batch.php` Logs

### Datenbank-Check
```sql
-- Quotas ohne HRS-ID (noch nicht hochgeladen)
SELECT * FROM hut_quota WHERE hrs_id >= 900000000;

-- Quotas mit HRS-ID (synchronisiert)
SELECT * FROM hut_quota WHERE hrs_id < 900000000;
```

## Performance

- **Lokale DB**: ~50ms pro Quota
- **HRS Upload**: ~500ms pro Quota (inkl. 500ms Pause)
- **Gesamt für 10 Tage**: ~5-10 Sekunden

## Troubleshooting

### "Elemente für Priorität X nicht gefunden"
- Modal wurde nicht korrekt geladen
- Cache-Buster erhöhen in `timeline-unified.html`
- Browser-Cache leeren

### "HTTP 400: Bad Request" beim Reload
- URL-Format-Fehler (doppeltes `?`)
- Fixed in `quota-management-integration.js`

### HRS-Upload schlägt fehl
- Prüfe HRS-Login: `hrs_login.php`
- Prüfe Netzwerk-Verbindung zu HRS
- Prüfe HRS-API-Limits

### Quotas nur lokal, nicht im HRS
- `hrsUpload` Sektion in Response prüfen
- Logs in `hrs_create_quota_batch.php` checken
- Manuell Upload wiederholen mit Script

## Maintenance

### Cleanup alte lokale Quotas
```sql
DELETE FROM hut_quota_categories 
WHERE hut_quota_id IN (
  SELECT id FROM hut_quota WHERE date_to < CURDATE()
);

DELETE FROM hut_quota WHERE date_to < CURDATE();
```

### Re-Sync mit HRS
Wenn lokal Quotas existieren aber nicht im HRS:
```php
// Script erstellen das alle lokalen Quotas ohne echte HRS-ID neu hochlädt
```
