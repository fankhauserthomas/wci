<?php
require_once 'SyncManager.php';

echo "🔧 === EXTENDING SYNC SYSTEM === 🔧\n\n";

try {
    $sync = new SyncManager();
    
    // Neue Tabellen die synchronisiert werden sollen
    $newTables = [
        'AV-Res' => 'av_res',
        'AV_ResDet' => 'av_resdet', 
        'zp_zimmer' => 'zp_zimmer'
    ];
    
    echo "1️⃣ Extending Sync System for Additional Tables...\n";
    foreach ($newTables as $tableName => $queueSuffix) {
        echo "Processing table: $tableName\n";
    }
    echo "\n";
    
    // 2. Prüfe welche Tabellen existieren
    echo "2️⃣ Checking Table Existence...\n";
    
    foreach ($newTables as $tableName => $queueSuffix) {
        echo "Table: $tableName\n";
        
        // Local
        $localExists = $sync->localDb->query("SHOW TABLES LIKE '$tableName'")->num_rows > 0;
        echo "  Local: " . ($localExists ? "✅ EXISTS" : "❌ MISSING") . "\n";
        
        // Remote  
        $remoteExists = $sync->remoteDb->query("SHOW TABLES LIKE '$tableName'")->num_rows > 0;
        echo "  Remote: " . ($remoteExists ? "✅ EXISTS" : "❌ MISSING") . "\n";
        
        if ($localExists && $remoteExists) {
            // Zeige Spalten-Info
            $localCols = $sync->localDb->query("SHOW COLUMNS FROM `$tableName`")->num_rows;
            $remoteCols = $sync->remoteDb->query("SHOW COLUMNS FROM `$tableName`")->num_rows;
            echo "  Columns - Local: $localCols, Remote: $remoteCols\n";
        }
        echo "\n";
    }
    
    // 3. Erstelle Queue-Tabellen
    echo "3️⃣ Creating Queue Tables...\n";
    
    foreach ($newTables as $tableName => $queueSuffix) {
        $localTableExists = $sync->localDb->query("SHOW TABLES LIKE '$tableName'")->num_rows > 0;
        $remoteTableExists = $sync->remoteDb->query("SHOW TABLES LIKE '$tableName'")->num_rows > 0;
        
        if (!$localTableExists || !$remoteTableExists) {
            echo "⚠️ Skipping $tableName - table missing in one or both databases\n";
            continue;
        }
        
        echo "Creating queues for: $tableName\n";
        
        // Local Queue Table
        $localQueueTable = "sync_queue_local_$queueSuffix";
        $createLocalQueue = "
            CREATE TABLE IF NOT EXISTS `$localQueueTable` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `table_name` varchar(50) NOT NULL DEFAULT '$tableName',
                `record_id` int(11) NOT NULL,
                `operation` enum('insert','update','delete') NOT NULL,
                `old_data` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `attempts` int(11) DEFAULT 0,
                `last_attempt` timestamp NULL,
                `status` enum('pending','processing','failed') DEFAULT 'pending',
                PRIMARY KEY (`id`),
                KEY `idx_record_operation` (`record_id`, `operation`),
                KEY `idx_status_created` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        $localResult = $sync->localDb->query($createLocalQueue);
        echo "  Local Queue ($localQueueTable): " . ($localResult ? "✅ CREATED" : "❌ FAILED") . "\n";
        
        // Remote Queue Table
        $remoteQueueTable = "sync_queue_remote_$queueSuffix";
        $createRemoteQueue = "
            CREATE TABLE IF NOT EXISTS `$remoteQueueTable` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `table_name` varchar(50) NOT NULL DEFAULT '$tableName',
                `record_id` int(11) NOT NULL,
                `operation` enum('insert','update','delete') NOT NULL,
                `old_data` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `attempts` int(11) DEFAULT 0,
                `last_attempt` timestamp NULL,
                `status` enum('pending','processing','failed') DEFAULT 'pending',
                PRIMARY KEY (`id`),
                KEY `idx_record_operation` (`record_id`, `operation`),
                KEY `idx_status_created` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        
        $remoteResult = $sync->remoteDb->query($createRemoteQueue);
        echo "  Remote Queue ($remoteQueueTable): " . ($remoteResult ? "✅ CREATED" : "❌ FAILED") . "\n";
        echo "\n";
    }
    
    // 4. Zeige Trigger-Definitionen
    echo "4️⃣ Trigger Definitions for Manual Creation...\n\n";
    
    foreach ($newTables as $tableName => $queueSuffix) {
        $localTableExists = $sync->localDb->query("SHOW TABLES LIKE '$tableName'")->num_rows > 0;
        $remoteTableExists = $sync->remoteDb->query("SHOW TABLES LIKE '$tableName'")->num_rows > 0;
        
        if (!$localTableExists || !$remoteTableExists) {
            continue;
        }
        
        $safeTableName = str_replace(['-', '_'], '', strtolower($tableName));
        $localQueueTable = "sync_queue_local_$queueSuffix";
        $remoteQueueTable = "sync_queue_remote_$queueSuffix";
        
        echo "=== TRIGGERS FOR TABLE: $tableName ===\n\n";
        
        echo "--- LOCAL DATABASE TRIGGERS ---\n";
        echo "DROP TRIGGER IF EXISTS {$safeTableName}_queue_insert;\n";
        echo "DROP TRIGGER IF EXISTS {$safeTableName}_queue_update;\n";
        echo "DROP TRIGGER IF EXISTS {$safeTableName}_queue_delete;\n\n";
        
        echo "DELIMITER \$\$\n";
        echo "CREATE TRIGGER {$safeTableName}_queue_insert\n";
        echo "    AFTER INSERT ON `$tableName`\n";
        echo "    FOR EACH ROW\n";
        echo "BEGIN\n";
        echo "    IF @sync_in_progress IS NULL THEN\n";
        echo "        INSERT INTO $localQueueTable (record_id, operation, created_at)\n";
        echo "        VALUES (NEW.id, 'insert', NOW());\n";
        echo "    END IF;\n";
        echo "END\$\$\n\n";
        
        echo "CREATE TRIGGER {$safeTableName}_queue_update\n";
        echo "    AFTER UPDATE ON `$tableName`\n";
        echo "    FOR EACH ROW\n";
        echo "BEGIN\n";
        echo "    IF @sync_in_progress IS NULL THEN\n";
        echo "        INSERT INTO $localQueueTable (record_id, operation, created_at)\n";
        echo "        VALUES (NEW.id, 'update', NOW());\n";
        echo "    END IF;\n";
        echo "END\$\$\n\n";
        
        echo "CREATE TRIGGER {$safeTableName}_queue_delete\n";
        echo "    BEFORE DELETE ON `$tableName`\n";
        echo "    FOR EACH ROW\n";
        echo "BEGIN\n";
        echo "    IF @sync_in_progress IS NULL THEN\n";
        echo "        INSERT INTO $localQueueTable (record_id, operation, old_data, created_at)\n";
        echo "        VALUES (OLD.id, 'delete', CONCAT('Record ID: ', OLD.id), NOW());\n";
        echo "    END IF;\n";
        echo "END\$\$\n";
        echo "DELIMITER ;\n\n";
        
        echo "--- REMOTE DATABASE TRIGGERS ---\n";
        echo "DROP TRIGGER IF EXISTS {$safeTableName}_queue_insert;\n";
        echo "DROP TRIGGER IF EXISTS {$safeTableName}_queue_update;\n";
        echo "DROP TRIGGER IF EXISTS {$safeTableName}_queue_delete;\n\n";
        
        echo "DELIMITER \$\$\n";
        echo "CREATE TRIGGER {$safeTableName}_queue_insert\n";
        echo "    AFTER INSERT ON `$tableName`\n";
        echo "    FOR EACH ROW\n";
        echo "BEGIN\n";
        echo "    IF @sync_in_progress IS NULL THEN\n";
        echo "        INSERT INTO $remoteQueueTable (record_id, operation, created_at)\n";
        echo "        VALUES (NEW.id, 'insert', NOW());\n";
        echo "    END IF;\n";
        echo "END\$\$\n\n";
        
        echo "CREATE TRIGGER {$safeTableName}_queue_update\n";
        echo "    AFTER UPDATE ON `$tableName`\n";
        echo "    FOR EACH ROW\n";
        echo "BEGIN\n";
        echo "    IF @sync_in_progress IS NULL THEN\n";
        echo "        INSERT INTO $remoteQueueTable (record_id, operation, created_at)\n";
        echo "        VALUES (NEW.id, 'update', NOW());\n";
        echo "    END IF;\n";
        echo "END\$\$\n\n";
        
        echo "CREATE TRIGGER {$safeTableName}_queue_delete\n";
        echo "    BEFORE DELETE ON `$tableName`\n";
        echo "    FOR EACH ROW\n";
        echo "BEGIN\n";
        echo "    IF @sync_in_progress IS NULL THEN\n";
        echo "        INSERT INTO $remoteQueueTable (record_id, operation, old_data, created_at)\n";
        echo "        VALUES (OLD.id, 'delete', CONCAT('Record ID: ', OLD.id), NOW());\n";
        echo "    END IF;\n";
        echo "END\$\$\n";
        echo "DELIMITER ;\n\n";
        
        echo "============================================\n\n";
    }
    
    echo "5️⃣ Next Steps:\n";
    echo "1. Execute the trigger SQL statements above manually in both databases\n";
    echo "2. Update SyncManager.php to include the new tables\n";
    echo "3. Test the extended sync system\n\n";
    
    echo "✅ Queue table creation completed!\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
