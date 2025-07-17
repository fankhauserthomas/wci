<?php
require_once 'SyncManager.php';

echo "🔧 === FALLBACK TRIGGER CREATION === 🔧\n\n";

try {
    $sync = new SyncManager();
    
    echo "Remote MySQL Version doesn't support multiple triggers.\n";
    echo "Using combined trigger approach...\n\n";
    
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
    
    echo "Creating REMOTE triggers with combined approach...\n\n";
    
    foreach ($tables as $tableName => $config) {
        echo "Processing table: $tableName\n";
        
        $queueTable = "sync_queue_remote_" . $config['queue_suffix'];
        $prefix = $config['trigger_prefix'];
        
        // Drop existing triggers first
        $sync->remoteDb->query("DROP TRIGGER IF EXISTS {$prefix}_sync");
        
        // Single combined trigger for INSERT/UPDATE
        $combinedTrigger = "
            CREATE TRIGGER {$prefix}_sync
                AFTER INSERT ON `$tableName`
                FOR EACH ROW
            BEGIN
                IF @sync_in_progress IS NULL THEN
                    INSERT INTO $queueTable (record_id, operation, created_at)
                    VALUES (NEW.id, 'insert', NOW());
                END IF;
            END
        ";
        
        $result = $sync->remoteDb->query($combinedTrigger);
        echo "  " . ($result ? "✅" : "❌") . " Combined INSERT trigger created\n";
        if (!$result) echo "    Error: " . $sync->remoteDb->error . "\n";
        
        // Separate DELETE trigger (BEFORE DELETE)
        $deleteTrigger = "
            CREATE TRIGGER {$prefix}_delete_sync
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
    
    echo "Note: Remote triggers only capture INSERT and DELETE.\n";
    echo "UPDATE operations will need special handling.\n\n";
    
    echo "✅ Fallback trigger creation completed!\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
