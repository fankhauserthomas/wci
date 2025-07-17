# Multi-Table Sync System - Setup Anleitung

## 1. Queue-Tabellen erweitern (BEIDE Datenbanken)

Führe das SQL-Skript `extend_queue_tables.sql` aus:

```sql
-- Lokale Queue erweitern
ALTER TABLE sync_queue_local ADD COLUMN table_name VARCHAR(50) DEFAULT 'AV-ResNamen' AFTER record_id;

-- Remote Queue erweitern  
ALTER TABLE sync_queue_remote ADD COLUMN table_name VARCHAR(50) DEFAULT 'AV-ResNamen' AFTER record_id;
```

## 2. Bestehende AV-ResNamen Trigger aktualisieren (REMOTE Datenbank)

```sql
-- Alte Trigger löschen
DROP TRIGGER IF EXISTS av_resnamen_queue_insert;
DROP TRIGGER IF EXISTS av_resnamen_queue_update;
DROP TRIGGER IF EXISTS av_resnamen_queue_delete;

-- Neue Trigger mit table_name
CREATE TRIGGER `av_resnamen_queue_insert` AFTER INSERT ON `AV-ResNamen`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV-ResNamen', 'insert', NOW());
    END IF;
END;

CREATE TRIGGER `av_resnamen_queue_update` AFTER UPDATE ON `AV-ResNamen`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV-ResNamen', 'update', NOW());
    END IF;
END;

CREATE TRIGGER `av_resnamen_queue_delete` BEFORE DELETE ON `AV-ResNamen`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, old_data, created_at)
        VALUES (OLD.id, 'AV-ResNamen', 'delete', CONCAT('Name: ', COALESCE(OLD.vorname, ''), ' ', COALESCE(OLD.nachname, '')), NOW());
    END IF;
END;
```

## 3. Neue Trigger für AV-Res (REMOTE Datenbank)

```sql
CREATE TRIGGER `av_res_queue_insert` AFTER INSERT ON `AV-Res`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV-Res', 'insert', NOW());
    END IF;
END;

CREATE TRIGGER `av_res_queue_update` AFTER UPDATE ON `AV-Res`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV-Res', 'update', NOW());
    END IF;
END;

CREATE TRIGGER `av_res_queue_delete` BEFORE DELETE ON `AV-Res`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, old_data, created_at)
        VALUES (OLD.id, 'AV-Res', 'delete', CONCAT('ResNr: ', COALESCE(OLD.ResNr, '')), NOW());
    END IF;
END;
```

## 4. Neue Trigger für AV_ResDet (REMOTE Datenbank)

```sql
CREATE TRIGGER `av_resdet_queue_insert` AFTER INSERT ON `AV_ResDet`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV_ResDet', 'insert', NOW());
    END IF;
END;

CREATE TRIGGER `av_resdet_queue_update` AFTER UPDATE ON `AV_ResDet`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV_ResDet', 'update', NOW());
    END IF;
END;

CREATE TRIGGER `av_resdet_queue_delete` BEFORE DELETE ON `AV_ResDet`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, old_data, created_at)
        VALUES (OLD.id, 'AV_ResDet', 'delete', CONCAT('ResNr: ', COALESCE(OLD.ResNr, ''), ' Zimmer: ', COALESCE(OLD.Zimmer, '')), NOW());
    END IF;
END;
```

## 5. Neue Trigger für zp_zimmer (REMOTE Datenbank)

```sql
CREATE TRIGGER `zp_zimmer_queue_insert` AFTER INSERT ON `zp_zimmer`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'zp_zimmer', 'insert', NOW());
    END IF;
END;

CREATE TRIGGER `zp_zimmer_queue_update` AFTER UPDATE ON `zp_zimmer`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'zp_zimmer', 'update', NOW());
    END IF;
END;

CREATE TRIGGER `zp_zimmer_queue_delete` BEFORE DELETE ON `zp_zimmer`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, old_data, created_at)
        VALUES (OLD.id, 'zp_zimmer', 'delete', CONCAT('Zimmer: ', COALESCE(OLD.zimmer, ''), ' Kategorie: ', COALESCE(OLD.kategorie, '')), NOW());
    END IF;
END;
```

## 6. Test ausführen

Nach dem Setup der Trigger teste das System:

```bash
php test_multi_table_sync.php
```

## Wichtige Hinweise:

1. **Queue table_name Spalte**: Neue Spalte ermöglicht Multi-Table Support
2. **Trigger-Schutz**: Alle Trigger prüfen `@sync_in_progress` um Loops zu vermeiden  
3. **old_data**: DELETE Trigger speichern sinnvolle Identifikatoren für Debugging
4. **Skalierbarkeit**: System kann einfach um weitere Tabellen erweitert werden

## SyncManager Features:

- ✅ **Multi-Table Support**: Synchronisiert AV-ResNamen, AV-Res, AV_ResDet, zp_zimmer
- ✅ **Queue-basiert**: Garantierte Änderungsverfolgung via Trigger
- ✅ **Trigger-Schutz**: Verhindert Sync-Loops mit @sync_in_progress Flag
- ✅ **Fallback**: Timestamp-basierte Sync als Backup
- ✅ **Dynamisch**: Arbeitet mit unterschiedlichen Spaltenstrukturen
- ✅ **Robust**: Fehlerbehandlung und Retry-Mechanismus
