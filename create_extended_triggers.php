<?php
require_once 'SyncManager.php';

echo "🚀 === CREATING TRIGGERS FOR EXTENDED SYNC === 🚀\n\n";

try {
    $sync = new SyncManager();
    
    // Tabellen-Definitionen
    $tables = [
        'AV-Res' => [
            'queue_suffix' => 'av_res',
            'trigger_prefix' => 'avres'
        ],
        'AV_ResDet' => [
            'queue_suffix' => 'av_resdet', 
            'trigger_prefix' => 'avresdet'
        ],
        'zp_zimmer' => [
            'queue_suffix' => 'zp_zimmer',
            'trigger_prefix' => 'zpzimmer'
        ]
    ];
    
    echo "1️⃣ Creating Triggers in LOCAL Database...\n\n";
    
    foreach ($tables as $tableName => $config) {
        echo "Creating triggers for table: $tableName\n";
        
        $queueTable = "sync_queue_local_" . $config['queue_suffix'];
        $prefix = $config['trigger_prefix'];
        
        // Drop existing triggers
        $dropTriggers = [
            "DROP TRIGGER IF EXISTS {$prefix}_queue_insert",
            "DROP TRIGGER IF EXISTS {$prefix}_queue_update", 
            "DROP TRIGGER IF EXISTS {$prefix}_queue_delete"
        ];
        
        foreach ($dropTriggers as $sql) {
            $result = $sync->localDb->query($sql);
            echo "  " . ($result ? "✅" : "❌") . " $sql\n";
        }
        
        // Create INSERT trigger
        $insertTrigger = "
            CREATE TRIGGER {$prefix}_queue_insert
                AFTER INSERT ON `$tableName`
                FOR EACH ROW
            BEGIN
                IF @sync_in_progress IS NULL THEN
                    INSERT INTO $queueTable (record_id, operation, created_at)
                    VALUES (NEW.id, 'insert', NOW());
                END IF;
            END
        ";
        
        $result = $sync->localDb->query($insertTrigger);
        echo "  " . ($result ? "✅" : "❌") . " INSERT trigger created\n";
        if (!$result) echo "    Error: " . $sync->localDb->error . "\n";
        
        // Create UPDATE trigger  
        $updateTrigger = "
            CREATE TRIGGER {$prefix}_queue_update
                AFTER UPDATE ON `$tableName`
                FOR EACH ROW
            BEGIN
                IF @sync_in_progress IS NULL THEN
                    INSERT INTO $queueTable (record_id, operation, created_at)
                    VALUES (NEW.id, 'update', NOW());
                END IF;
            END
        ";
        
        $result = $sync->localDb->query($updateTrigger);
        echo "  " . ($result ? "✅" : "❌") . " UPDATE trigger created\n";
        if (!$result) echo "    Error: " . $sync->localDb->error . "\n";
        
        // Create DELETE trigger
        $deleteTrigger = "
            CREATE TRIGGER {$prefix}_queue_delete
                BEFORE DELETE ON `$tableName`
                FOR EACH ROW
            BEGIN
                IF @sync_in_progress IS NULL THEN
                    INSERT INTO $queueTable (record_id, operation, old_data, created_at)
                    VALUES (OLD.id, 'delete', CONCAT('Record ID: ', OLD.id), NOW());
                END IF;
            END
        ";
        
        $result = $sync->localDb->query($deleteTrigger);
        echo "  " . ($result ? "✅" : "❌") . " DELETE trigger created\n";
        if (!$result) echo "    Error: " . $sync->localDb->error . "\n";
        
        echo "\n";
    }
    
    echo "2️⃣ Creating Triggers in REMOTE Database...\n\n";
    
    foreach ($tables as $tableName => $config) {
        echo "Creating triggers for table: $tableName\n";
        
        $queueTable = "sync_queue_remote_" . $config['queue_suffix'];
        $prefix = $config['trigger_prefix'];
        
        // Drop existing triggers
        $dropTriggers = [
            "DROP TRIGGER IF EXISTS {$prefix}_queue_insert",
            "DROP TRIGGER IF EXISTS {$prefix}_queue_update",
            "DROP TRIGGER IF EXISTS {$prefix}_queue_delete"
        ];
        
        foreach ($dropTriggers as $sql) {
            $result = $sync->remoteDb->query($sql);
            echo "  " . ($result ? "✅" : "❌") . " $sql\n";
        }
        
        // Create INSERT trigger
        $insertTrigger = "
            CREATE TRIGGER {$prefix}_queue_insert
                AFTER INSERT ON `$tableName`
                FOR EACH ROW
            BEGIN
                IF @sync_in_progress IS NULL THEN
                    INSERT INTO $queueTable (record_id, operation, created_at)
                    VALUES (NEW.id, 'insert', NOW());
                END IF;
            END
        ";
        
        $result = $sync->remoteDb->query($insertTrigger);
        echo "  " . ($result ? "✅" : "❌") . " INSERT trigger created\n";
        if (!$result) echo "    Error: " . $sync->remoteDb->error . "\n";
        
        // Create UPDATE trigger
        $updateTrigger = "
            CREATE TRIGGER {$prefix}_queue_update
                AFTER UPDATE ON `$tableName`
                FOR EACH ROW
            BEGIN
                IF @sync_in_progress IS NULL THEN
                    INSERT INTO $queueTable (record_id, operation, created_at)
                    VALUES (NEW.id, 'update', NOW());
                END IF;
            END
        ";
        
        $result = $sync->remoteDb->query($updateTrigger);
        echo "  " . ($result ? "✅" : "❌") . " UPDATE trigger created\n";
        if (!$result) echo "    Error: " . $sync->remoteDb->error . "\n";
        
        // Create DELETE trigger
        $deleteTrigger = "
            CREATE TRIGGER {$prefix}_queue_delete
                BEFORE DELETE ON `$tableName`
                FOR EACH ROW
            BEGIN
                IF @sync_in_progress IS NULL THEN
                    INSERT INTO $queueTable (record_id, operation, old_data, created_at)
                    VALUES (OLD.id, 'delete', CONCAT('Record ID: ', OLD.id), NOW());
                END IF;
            END
        ";
        
        $result = $sync->remoteDb->query($deleteTrigger);
        echo "  " . ($result ? "✅" : "❌") . " DELETE trigger created\n";
        if (!$result) echo "    Error: " . $sync->remoteDb->error . "\n";
        
        echo "\n";
    }
    
    echo "3️⃣ Verifying Trigger Creation...\n\n";
    
    // Prüfe Local Triggers
    $localTriggers = $sync->localDb->query("SHOW TRIGGERS")->fetch_all(MYSQLI_ASSOC);
    $localCount = 0;
    foreach ($localTriggers as $trigger) {
        if (strpos($trigger['Trigger'], '_queue_') !== false) {
            $localCount++;
        }
    }
    echo "Local triggers created: $localCount\n";
    
    // Prüfe Remote Triggers  
    $remoteTriggers = $sync->remoteDb->query("SHOW TRIGGERS")->fetch_all(MYSQLI_ASSOC);
    $remoteCount = 0;
    foreach ($remoteTriggers as $trigger) {
        if (strpos($trigger['Trigger'], '_queue_') !== false) {
            $remoteCount++;
        }
    }
    echo "Remote triggers created: $remoteCount\n";
    
    echo "\n✅ Trigger creation completed!\n";
    echo "Next: Update SyncManager.php to handle multiple tables\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
