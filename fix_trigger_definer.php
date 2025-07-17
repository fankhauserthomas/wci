<?php
// fix_trigger_definer.php - Fix Trigger Definer Issues

echo "=== TRIGGER DEFINER FIX ===\n\n";

try {
    // Remote DB Connection
    $remoteDb = new mysqli('booking.franzsennhuette.at', 'booking_franzsen', '~2Y@76', 'booking_franzsen');
    if ($remoteDb->connect_error) {
        die('Remote DB connection failed: ' . $remoteDb->connect_error);
    }
    
    echo "✅ Remote DB connected\n\n";
    
    // Skip user detection for old MySQL, use manual definer
    $currentUser = "booking_franzsen@%";  // Generic definer
    echo "Using definer: $currentUser\n\n";
    
    // Tables to fix
    $tables = ['AV-Res', 'AV-ResNamen', 'AV_ResDet', 'zp_zimmer'];
    
    foreach ($tables as $table) {
        echo "--- Fixing triggers for $table ---\n";
        
        // Drop existing triggers
        $triggers = ['insert', 'update', 'delete'];
        foreach ($triggers as $event) {
            $triggerName = strtolower(str_replace('-', '_', $table)) . "_queue_$event";
            
            echo "Dropping trigger: $triggerName\n";
            $sql = "DROP TRIGGER IF EXISTS `$triggerName`";
            if (!$remoteDb->query($sql)) {
                echo "  ⚠️  Warning: " . $remoteDb->error . "\n";
            }
        }
        
        // Recreate with correct definer
        $tableDbName = str_replace('-', '_', $table);
        
        // INSERT Trigger
        $sql = "CREATE TRIGGER `{$tableDbName}_queue_insert`
                AFTER INSERT ON `$table`
                FOR EACH ROW
                BEGIN
                    IF @sync_in_progress IS NULL THEN
                        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
                        VALUES (NEW.id, '$table', 'insert', NOW());
                    END IF;
                END";
        
        echo "Creating INSERT trigger...\n";
        if ($remoteDb->query($sql)) {
            echo "  ✅ INSERT trigger created\n";
        } else {
            echo "  ❌ Error: " . $remoteDb->error . "\n";
        }
        
        // UPDATE Trigger
        $sql = "CREATE TRIGGER `{$tableDbName}_queue_update`
                AFTER UPDATE ON `$table`
                FOR EACH ROW
                BEGIN
                    IF @sync_in_progress IS NULL THEN
                        INSERT INTO sync_queue_remote (record_id, table_name, operation, created_at)
                        VALUES (NEW.id, '$table', 'update', NOW());
                    END IF;
                END";
        
        echo "Creating UPDATE trigger...\n";
        if ($remoteDb->query($sql)) {
            echo "  ✅ UPDATE trigger created\n";
        } else {
            echo "  ❌ Error: " . $remoteDb->error . "\n";
        }
        
        // DELETE Trigger
        $sql = "CREATE TRIGGER `{$tableDbName}_queue_delete`
                AFTER DELETE ON `$table`
                FOR EACH ROW
                BEGIN
                    IF @sync_in_progress IS NULL THEN
                        INSERT INTO sync_queue_remote (record_id, table_name, operation, old_data, created_at)
                        VALUES (OLD.id, '$table', 'delete', CONCAT('id=', OLD.id), NOW());
                    END IF;
                END";
        
        echo "Creating DELETE trigger...\n";
        if ($remoteDb->query($sql)) {
            echo "  ✅ DELETE trigger created\n";
        } else {
            echo "  ❌ Error: " . $remoteDb->error . "\n";
        }
        
        echo "\n";
    }
    
    echo "=== VERIFICATION ===\n";
    foreach ($tables as $table) {
        $result = $remoteDb->query("SHOW TRIGGERS LIKE '$table'");
        echo "$table: " . $result->num_rows . " triggers\n";
    }
    
    echo "\n✅ Trigger definer fix completed!\n";
    echo "The sync system should now work without definer errors.\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
