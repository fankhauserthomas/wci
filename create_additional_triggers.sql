-- ====================================================
-- TRIGGER für AV-Res Tabelle
-- ====================================================

-- INSERT Trigger für AV-Res
CREATE TRIGGER `av_res_queue_insert` AFTER INSERT ON `AV-Res`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV-Res', 'insert', NOW());
    END IF;
END;

-- UPDATE Trigger für AV-Res
CREATE TRIGGER `av_res_queue_update` AFTER UPDATE ON `AV-Res`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV-Res', 'update', NOW());
    END IF;
END;

-- DELETE Trigger für AV-Res
CREATE TRIGGER `av_res_queue_delete` BEFORE DELETE ON `AV-Res`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, old_data, created_at)
        VALUES (OLD.id, 'AV-Res', 'delete', CONCAT('ResNr: ', COALESCE(OLD.ResNr, '')), NOW());
    END IF;
END;

-- ====================================================
-- TRIGGER für AV_ResDet Tabelle
-- ====================================================

-- INSERT Trigger für AV_ResDet
CREATE TRIGGER `av_resdet_queue_insert` AFTER INSERT ON `AV_ResDet`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV_ResDet', 'insert', NOW());
    END IF;
END;

-- UPDATE Trigger für AV_ResDet
CREATE TRIGGER `av_resdet_queue_update` AFTER UPDATE ON `AV_ResDet`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'AV_ResDet', 'update', NOW());
    END IF;
END;

-- DELETE Trigger für AV_ResDet
CREATE TRIGGER `av_resdet_queue_delete` BEFORE DELETE ON `AV_ResDet`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, old_data, created_at)
        VALUES (OLD.id, 'AV_ResDet', 'delete', CONCAT('ResNr: ', COALESCE(OLD.ResNr, ''), ' Zimmer: ', COALESCE(OLD.Zimmer, '')), NOW());
    END IF;
END;

-- ====================================================
-- TRIGGER für zp_zimmer Tabelle
-- ====================================================

-- INSERT Trigger für zp_zimmer
CREATE TRIGGER `zp_zimmer_queue_insert` AFTER INSERT ON `zp_zimmer`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'zp_zimmer', 'insert', NOW());
    END IF;
END;

-- UPDATE Trigger für zp_zimmer
CREATE TRIGGER `zp_zimmer_queue_update` AFTER UPDATE ON `zp_zimmer`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
        VALUES (NEW.id, 'zp_zimmer', 'update', NOW());
    END IF;
END;

-- DELETE Trigger für zp_zimmer
CREATE TRIGGER `zp_zimmer_queue_delete` BEFORE DELETE ON `zp_zimmer`
 FOR EACH ROW BEGIN
    IF @sync_in_progress IS NULL THEN
        INSERT INTO sync_queue_remote (record_id, table_name, operation, old_data, created_at)
        VALUES (OLD.id, 'zp_zimmer', 'delete', CONCAT('Zimmer: ', COALESCE(OLD.zimmer, ''), ' Kategorie: ', COALESCE(OLD.kategorie, '')), NOW());
    END IF;
END;
