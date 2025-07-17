<?php
require_once 'SyncManager.php';

echo "=== COMPLETE MULTI-TABLE SYNC SYSTEM TEST ===\n";
echo "Testing all 4 tables: AV-ResNamen, AV-Res, AV_ResDet, zp_zimmer\n\n";

try {
    $sync = new SyncManager();
    
    echo "1. SYSTEM STATUS CHECK:\n";
    echo "========================\n";
    
    // Check database connections
    echo "✓ Local DB connected: " . ($sync->localDb ? "YES" : "NO") . "\n";
    echo "✓ Remote DB connected: " . ($sync->remoteDb ? "YES" : "NO") . "\n";
    
    // Check queue tables
    $queueExists = $sync->checkQueueTables();
    echo "✓ Queue tables exist: " . ($queueExists ? "YES" : "NO") . "\n";
    
    if (!$queueExists) {
        echo "❌ Queue tables missing! Multi-table sync not possible.\n";
        exit(1);
    }
    
    echo "\n2. QUEUE STATUS CHECK:\n";
    echo "======================\n";
    
    // Check local queue status
    $localQueueCount = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE status = 'pending'")->fetch_assoc()['count'];
    echo "Local queue pending items: $localQueueCount\n";
    
    // Check remote queue status  
    $remoteQueueCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE status = 'pending'")->fetch_assoc()['count'];
    echo "Remote queue pending items: $remoteQueueCount\n";
    
    // Show queue breakdown by table
    echo "\nLocal queue by table:\n";
    $localBreakdown = $sync->localDb->query("
        SELECT table_name, COUNT(*) as count 
        FROM sync_queue_local 
        WHERE status = 'pending' 
        GROUP BY table_name
    ");
    while ($row = $localBreakdown->fetch_assoc()) {
        echo "  - {$row['table_name']}: {$row['count']} items\n";
    }
    
    echo "\nRemote queue by table:\n";
    $remoteBreakdown = $sync->remoteDb->query("
        SELECT table_name, COUNT(*) as count 
        FROM sync_queue_remote 
        WHERE status = 'pending' 
        GROUP BY table_name
    ");
    while ($row = $remoteBreakdown->fetch_assoc()) {
        echo "  - {$row['table_name']}: {$row['count']} items\n";
    }
    
    echo "\n3. TESTING MULTI-TABLE SYNC:\n";
    echo "=============================\n";
    
    // Perform main sync
    $result = $sync->syncOnPageLoad('complete_multi_table_test');
    
    if ($result['success']) {
        echo "✅ Multi-table sync SUCCESSFUL!\n";
        if (isset($result['results'])) {
            echo "Sync results:\n";
            foreach ($result['results'] as $key => $value) {
                echo "  - $key: $value records\n";
            }
        }
    } else {
        echo "❌ Multi-table sync FAILED: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
    
    echo "\n4. TESTING INDIVIDUAL TABLE FORCE SYNC:\n";
    echo "========================================\n";
    
    $tables = ['AV-ResNamen', 'AV-Res', 'AV_ResDet', 'zp_zimmer'];
    
    foreach ($tables as $tableName) {
        echo "\nTesting $tableName:\n";
        echo str_repeat('-', 30) . "\n";
        
        // Test timestamp-based force sync (last 24 hours)
        $result = $sync->forceSyncLatest(24, $tableName);
        
        if ($result['success']) {
            $syncedCount = $result['results']['remote_to_local'];
            echo "✅ $tableName force sync successful: $syncedCount records synced\n";
        } else {
            echo "❌ $tableName force sync failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        }
        
        // Check table record counts
        try {
            $localCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `$tableName`")->fetch_assoc()['count'];
            $remoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `$tableName`")->fetch_assoc()['count'];
            
            echo "  Local $tableName records: $localCount\n";
            echo "  Remote $tableName records: $remoteCount\n";
            
            if ($localCount == $remoteCount) {
                echo "  ✅ Record counts match!\n";
            } else {
                echo "  ⚠️  Record count mismatch (difference: " . abs($localCount - $remoteCount) . ")\n";
            }
            
        } catch (Exception $e) {
            echo "  ❌ Error checking record counts: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n5. QUEUE STATUS AFTER SYNC:\n";
    echo "============================\n";
    
    // Check queue status after sync
    $finalLocalCount = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE status = 'pending'")->fetch_assoc()['count'];
    $finalRemoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE status = 'pending'")->fetch_assoc()['count'];
    
    echo "Final local queue pending: $finalLocalCount\n";
    echo "Final remote queue pending: $finalRemoteCount\n";
    
    if ($finalLocalCount == 0 && $finalRemoteCount == 0) {
        echo "✅ All queues processed successfully!\n";
    } else {
        echo "⚠️  Some queue items remain unprocessed\n";
        
        // Show failed items
        $failedLocal = $sync->localDb->query("SELECT table_name, COUNT(*) as count FROM sync_queue_local WHERE status = 'failed' GROUP BY table_name");
        if ($failedLocal->num_rows > 0) {
            echo "Failed local items:\n";
            while ($row = $failedLocal->fetch_assoc()) {
                echo "  - {$row['table_name']}: {$row['count']} failed\n";
            }
        }
        
        $failedRemote = $sync->remoteDb->query("SELECT table_name, COUNT(*) as count FROM sync_queue_remote WHERE status = 'failed' GROUP BY table_name");
        if ($failedRemote->num_rows > 0) {
            echo "Failed remote items:\n";
            while ($row = $failedRemote->fetch_assoc()) {
                echo "  - {$row['table_name']}: {$row['count']} failed\n";
            }
        }
    }
    
    echo "\n6. TRIGGER TEST:\n";
    echo "================\n";
    
    // Test trigger functionality by making a small change
    echo "Testing triggers with a small update...\n";
    
    // Find a test record in AV-ResNamen
    $testRecord = $sync->localDb->query("SELECT id, bem FROM `AV-ResNamen` LIMIT 1")->fetch_assoc();
    
    if ($testRecord) {
        $testId = $testRecord['id'];
        $originalBem = $testRecord['bem'];
        $testBem = "Multi-Table Test " . date('H:i:s');
        
        echo "Updating AV-ResNamen record $testId...\n";
        
        // Update locally (should trigger queue entry)
        $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = '$testBem' WHERE id = $testId");
        
        // Check if queue entry was created
        $queueEntry = $sync->localDb->query("
            SELECT * FROM sync_queue_local 
            WHERE record_id = $testId AND table_name = 'AV-ResNamen' AND operation = 'update' 
            ORDER BY created_at DESC LIMIT 1
        ")->fetch_assoc();
        
        if ($queueEntry) {
            echo "✅ Trigger created queue entry: {$queueEntry['id']}\n";
            
            // Run sync to process the queue entry
            $syncResult = $sync->syncOnPageLoad('trigger_test');
            
            if ($syncResult['success']) {
                echo "✅ Queue entry processed successfully\n";
                
                // Check if remote was updated
                $remoteRecord = $sync->remoteDb->query("SELECT bem FROM `AV-ResNamen` WHERE id = $testId")->fetch_assoc();
                
                if ($remoteRecord && $remoteRecord['bem'] == $testBem) {
                    echo "✅ Remote record updated correctly\n";
                } else {
                    echo "❌ Remote record not updated correctly\n";
                }
                
                // Restore original value
                $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = '$originalBem' WHERE id = $testId");
                $sync->syncOnPageLoad('cleanup');
                
            } else {
                echo "❌ Failed to process queue entry\n";
            }
        } else {
            echo "❌ No queue entry created - triggers might not be working\n";
        }
    } else {
        echo "❌ No test record found in AV-ResNamen\n";
    }
    
    echo "\n=== MULTI-TABLE SYNC TEST COMPLETED ===\n";
    
    // Summary
    $totalSynced = 0;
    if (isset($result['results'])) {
        $totalSynced = array_sum($result['results']);
    }
    
    echo "\nSUMMARY:\n";
    echo "- System Status: " . ($sync->localDb && $sync->remoteDb && $queueExists ? "✅ OPERATIONAL" : "❌ ISSUES DETECTED") . "\n";
    echo "- Tables Configured: " . count($sync->syncTables ?? []) . " tables\n";
    echo "- Total Records Synced: $totalSynced\n";
    echo "- Queue Processing: " . (($finalLocalCount + $finalRemoteCount) == 0 ? "✅ COMPLETE" : "⚠️ PENDING ITEMS") . "\n";
    
    echo "\nThe Multi-Table Sync System is " . 
         (($sync->localDb && $sync->remoteDb && $queueExists) ? "READY FOR PRODUCTION! 🚀" : "NEEDS ATTENTION ⚠️") . "\n";
    
} catch (Exception $e) {
    echo "❌ CRITICAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== END OF TEST ===\n";
?>
