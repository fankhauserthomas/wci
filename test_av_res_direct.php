<?php
require_once 'SyncManager.php';

echo "=== DIRECT AV-RES SYNC TEST ===\n";
echo "Testing AV-Res table sync functionality directly\n\n";

try {
    $sync = new SyncManager();
    
    // 1. SYSTEM STATUS CHECK
    echo "1. SYSTEM STATUS CHECK:\n";
    echo "========================\n";
    echo "✓ Local DB connected: " . ($sync->localDb ? 'YES' : 'NO') . "\n";
    echo "✓ Remote DB connected: " . ($sync->remoteDb ? 'YES' : 'NO') . "\n";
    echo "✓ Queue tables exist: " . ($sync->checkQueueTables() ? 'YES' : 'NO') . "\n\n";
    
    // 2. CHECK AV-RES SYNC READINESS
    echo "2. AV-RES SYNC READINESS:\n";
    echo "==========================\n";
    
    // Check if AV-Res is in sync tables array
    $syncTablesReflection = new ReflectionClass($sync);
    $syncTablesProperty = $syncTablesReflection->getProperty('syncTables');
    $syncTablesProperty->setAccessible(true);
    $syncTables = $syncTablesProperty->getValue($sync);
    
    $isAvResConfigured = in_array('AV-Res', $syncTables);
    echo "✓ AV-Res in sync tables: " . ($isAvResConfigured ? 'YES' : 'NO') . "\n";
    echo "  Configured tables: " . implode(', ', $syncTables) . "\n\n";
    
    // 3. CHECK TABLE STRUCTURE COMPATIBILITY
    echo "3. TABLE STRUCTURE COMPATIBILITY:\n";
    echo "===================================\n";
    
    $localColumns = [];
    $result = $sync->localDb->query("SHOW COLUMNS FROM `AV-Res`");
    while ($row = $result->fetch_assoc()) {
        $localColumns[] = $row['Field'];
    }
    
    $remoteColumns = [];
    $result = $sync->remoteDb->query("SHOW COLUMNS FROM `AV-Res`");
    while ($row = $result->fetch_assoc()) {
        $remoteColumns[] = $row['Field'];
    }
    
    $commonColumns = array_intersect($localColumns, $remoteColumns);
    $localOnly = array_diff($localColumns, $remoteColumns);
    $remoteOnly = array_diff($remoteColumns, $localColumns);
    
    echo "Total local columns: " . count($localColumns) . "\n";
    echo "Total remote columns: " . count($remoteColumns) . "\n";
    echo "Common columns: " . count($commonColumns) . "\n";
    echo "Local only: " . count($localOnly) . " (" . implode(', ', $localOnly) . ")\n";
    echo "Remote only: " . count($remoteOnly) . " (" . implode(', ', $remoteOnly) . ")\n";
    
    $hasSyncTimestamp = in_array('sync_timestamp', $commonColumns);
    echo "✓ sync_timestamp available: " . ($hasSyncTimestamp ? 'YES' : 'NO') . "\n\n";
    
    // 4. TEST QUEUE-BASED SYNC
    echo "4. QUEUE-BASED SYNC TEST:\n";
    echo "==========================\n";
    
    // Check current queue status
    $localQueue = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE table_name = 'AV-Res' AND status = 'pending'")->fetch_assoc()['count'];
    $remoteQueue = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE table_name = 'AV-Res' AND status = 'pending'")->fetch_assoc()['count'];
    
    echo "AV-Res pending queue items - Local: $localQueue, Remote: $remoteQueue\n";
    
    // Run sync to process any pending items
    echo "Running sync to process pending AV-Res items...\n";
    $syncResult = $sync->syncOnPageLoad('av_res_queue_test');
    echo "Sync result: " . json_encode($syncResult['results'] ?? $syncResult) . "\n\n";
    
    // 5. TEST TIMESTAMP-BASED SYNC
    echo "5. TIMESTAMP-BASED SYNC TEST:\n";
    echo "==============================\n";
    
    if ($hasSyncTimestamp) {
        // Force timestamp-based sync for AV-Res
        $forceSyncResult = $sync->forceSyncLatest(24, 'AV-Res');
        echo "Force sync (24h) result: " . json_encode($forceSyncResult['results'] ?? $forceSyncResult) . "\n";
    } else {
        echo "❌ Cannot test timestamp-based sync - sync_timestamp column missing\n";
    }
    echo "\n";
    
    // 6. RECORD COUNT VERIFICATION
    echo "6. RECORD COUNT VERIFICATION:\n";
    echo "==============================\n";
    
    $localCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    $remoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    $countDifference = abs($localCount - $remoteCount);
    
    echo "Local AV-Res records: $localCount\n";
    echo "Remote AV-Res records: $remoteCount\n";
    echo "Count difference: $countDifference\n";
    
    if ($countDifference == 0) {
        echo "✅ PERFECT SYNC - Record counts match exactly\n";
    } else if ($countDifference <= 5) {
        echo "⚠️ MINOR DIFFERENCE - Record counts nearly match (difference: $countDifference)\n";
    } else {
        echo "❌ SYNC ISSUE - Significant record count difference\n";
    }
    echo "\n";
    
    // 7. SAMPLE RECORD COMPARISON
    echo "7. SAMPLE RECORD COMPARISON:\n";
    echo "=============================\n";
    
    // Get sample records for comparison
    $sampleLocal = $sync->localDb->query("
        SELECT id, av_id, anreise, abreise, bem, sync_timestamp 
        FROM `AV-Res` 
        ORDER BY sync_timestamp DESC 
        LIMIT 3
    ")->fetch_all(MYSQLI_ASSOC);
    
    echo "Latest local AV-Res records:\n";
    foreach ($sampleLocal as $record) {
        echo "  ID: {$record['id']}, av_id: {$record['av_id']}, anreise: {$record['anreise']}, sync: {$record['sync_timestamp']}\n";
        
        // Check if same record exists in remote
        $stmt = $sync->remoteDb->prepare("SELECT sync_timestamp FROM `AV-Res` WHERE id = ?");
        $stmt->bind_param('i', $record['id']);
        $stmt->execute();
        $remoteRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($remoteRecord) {
            $localTime = strtotime($record['sync_timestamp']);
            $remoteTime = strtotime($remoteRecord['sync_timestamp']);
            $timeDiff = abs($localTime - $remoteTime);
            
            echo "    Remote sync: {$remoteRecord['sync_timestamp']} (diff: {$timeDiff}s)\n";
            if ($timeDiff <= 10) {
                echo "    ✅ SYNCED\n";
            } else {
                echo "    ⚠️ TIME DIFFERENCE\n";
            }
        } else {
            echo "    ❌ NOT FOUND IN REMOTE\n";
        }
    }
    echo "\n";
    
    // 8. TRIGGER FUNCTIONALITY TEST
    echo "8. TRIGGER FUNCTIONALITY TEST:\n";
    echo "===============================\n";
    
    // Check if triggers exist for AV-Res
    $localTriggers = $sync->localDb->query("SHOW TRIGGERS LIKE 'AV-Res'")->fetch_all(MYSQLI_ASSOC);
    $remoteTriggers = $sync->remoteDb->query("SHOW TRIGGERS LIKE 'AV-Res'")->fetch_all(MYSQLI_ASSOC);
    
    echo "Local AV-Res triggers: " . count($localTriggers) . "\n";
    foreach ($localTriggers as $trigger) {
        echo "  - {$trigger['Trigger']} ({$trigger['Event']})\n";
    }
    
    echo "Remote AV-Res triggers: " . count($remoteTriggers) . "\n";
    foreach ($remoteTriggers as $trigger) {
        echo "  - {$trigger['Trigger']} ({$trigger['Event']})\n";
    }
    
    $expectedTriggers = 3; // INSERT, UPDATE, DELETE
    $localTriggersOk = count($localTriggers) >= $expectedTriggers;
    $remoteTriggersOk = count($remoteTriggers) >= $expectedTriggers;
    
    echo "✓ Local triggers complete: " . ($localTriggersOk ? 'YES' : 'NO') . "\n";
    echo "✓ Remote triggers complete: " . ($remoteTriggersOk ? 'YES' : 'NO') . "\n\n";
    
    // 9. FINAL ASSESSMENT
    echo "9. FINAL ASSESSMENT:\n";
    echo "=====================\n";
    
    $checks = [
        'AV-Res configured' => $isAvResConfigured,
        'Queue tables exist' => $sync->checkQueueTables(),
        'sync_timestamp available' => $hasSyncTimestamp,
        'Record counts match' => $countDifference <= 5,
        'Local triggers complete' => $localTriggersOk,
        'Remote triggers complete' => $remoteTriggersOk
    ];
    
    $passedChecks = 0;
    $totalChecks = count($checks);
    
    foreach ($checks as $check => $passed) {
        echo ($passed ? '✅' : '❌') . " $check\n";
        if ($passed) $passedChecks++;
    }
    
    $percentage = round(($passedChecks / $totalChecks) * 100);
    echo "\nAV-Res Sync System Status: $passedChecks/$totalChecks checks passed ($percentage%)\n";
    
    if ($percentage >= 90) {
        echo "🎉 EXCELLENT - AV-Res sync system fully operational!\n";
    } else if ($percentage >= 70) {
        echo "✅ GOOD - AV-Res sync system mostly functional\n";
    } else {
        echo "⚠️ NEEDS ATTENTION - Some AV-Res sync components need fixes\n";
    }
    
    echo "\n=== AV-RES DIRECT SYNC TEST COMPLETED ===\n";
    
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
