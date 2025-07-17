-- ====================================================
-- Queue-Tabellen erweitern für Multi-Table Support
-- ====================================================

-- Lokale Queue erweitern
ALTER TABLE sync_queue_local ADD COLUMN table_name VARCHAR(50) DEFAULT 'AV-ResNamen' AFTER record_id;

-- Remote Queue erweitern  
ALTER TABLE sync_queue_remote ADD COLUMN table_name VARCHAR(50) DEFAULT 'AV-ResNamen' AFTER record_id;

-- Bestehende AV-ResNamen Trigger aktualisieren
DROP TRIGGER IF EXISTS av_resnamen_queue_insert;
DROP TRIGGER IF EXISTS av_resnamen_queue_update;
DROP TRIGGER IF EXISTS av_resnamen_queue_delete;

-- Neue AV-ResNamen Trigger mit table_name
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
