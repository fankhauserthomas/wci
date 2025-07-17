<?php
require_once 'SyncManager.php';

echo "🎯 === FINAL COMPREHENSIVE SYNC TEST === 🎯\n\n";

try {
    $sync = new SyncManager();
    
    // 1. Queues bereinigen für sauberen Test
    echo "1️⃣ Cleaning Queues for Fresh Test...\n";
    $sync->localDb->query("DELETE FROM sync_queue_local WHERE status IN ('pending', 'processing')");
    $sync->remoteDb->query("DELETE FROM sync_queue_remote WHERE status IN ('pending', 'processing')");
    echo "✅ Queues cleaned\n\n";
    
    // 2. Status Check
    echo "2️⃣ Current Status...\n";
    $localCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-ResNamen`")->fetch_assoc();
    $remoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-ResNamen`")->fetch_assoc();
    echo "Local Records: {$localCount['count']}\n";
    echo "Remote Records: {$remoteCount['count']}\n";
    echo "Database Sync Status: " . (($localCount['count'] == $remoteCount['count']) ? "✅ SYNCHRONIZED" : "⚠️ DIFFERENT") . "\n\n";
    
    // 3. Fresh Test - Lokale Änderung
    echo "3️⃣ Creating Fresh Local Change...\n";
    $localTestId = 6900;
    $localTestValue = "Final Test Local " . date('H:i:s');
    
    $localUpdate = $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = '$localTestValue' WHERE id = $localTestId");
    echo "Local UPDATE (ID $localTestId): " . ($localUpdate ? "SUCCESS" : "FAILED") . "\n";
    
    sleep(1); // Kurz warten für Trigger
    $localQueueEntry = $sync->localDb->query("SELECT * FROM sync_queue_local WHERE record_id = $localTestId ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    echo "Local Queue Entry: " . ($localQueueEntry ? "✅ CREATED" : "❌ MISSING") . "\n\n";
    
    // 4. Fresh Test - Remote Änderung
    echo "4️⃣ Creating Fresh Remote Change...\n";
    $remoteTestId = 6901;
    $remoteTestValue = "Final Test Remote " . date('H:i:s');
    
    $remoteUpdate = $sync->remoteDb->query("UPDATE `AV-ResNamen` SET bem = '$remoteTestValue' WHERE id = $remoteTestId");
    echo "Remote UPDATE (ID $remoteTestId): " . ($remoteUpdate ? "SUCCESS" : "FAILED") . "\n";
    
    sleep(1); // Kurz warten für Trigger
    $remoteQueueEntry = $sync->remoteDb->query("SELECT * FROM sync_queue_remote WHERE record_id = $remoteTestId ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    echo "Remote Queue Entry: " . ($remoteQueueEntry ? "✅ CREATED" : "❌ MISSING") . "\n\n";
    
    // 5. Bidirektionale Synchronisation
    echo "5️⃣ Running Bidirectional Sync...\n";
    $syncResult = $sync->syncOnPageLoad('final_comprehensive_test');
    
    echo "Sync Results:\n";
    echo "  Success: " . ($syncResult['success'] ? "✅ YES" : "❌ NO") . "\n";
    if (isset($syncResult['results'])) {
        echo "  Local → Remote: {$syncResult['results']['local_to_remote']}\n";
        echo "  Remote → Local: {$syncResult['results']['remote_to_local']}\n";
        echo "  Failed: {$syncResult['results']['failed']}\n";
    }
    echo "\n";
    
    // 6. Verifikation
    echo "6️⃣ Verifying Sync Results...\n";
    
    // Prüfe lokale Änderung auf Remote
    $remoteValue = $sync->remoteDb->query("SELECT bem FROM `AV-ResNamen` WHERE id = $localTestId")->fetch_assoc();
    $localToRemoteOk = ($remoteValue && $remoteValue['bem'] === $localTestValue);
    echo "Local change on Remote: " . ($localToRemoteOk ? "✅ SYNCED" : "❌ FAILED") . "\n";
    
    // Prüfe Remote-Änderung auf Local
    $localValue = $sync->localDb->query("SELECT bem FROM `AV-ResNamen` WHERE id = $remoteTestId")->fetch_assoc();
    $remoteToLocalOk = ($localValue && $localValue['bem'] === $remoteTestValue);
    echo "Remote change on Local: " . ($remoteToLocalOk ? "✅ SYNCED" : "❌ FAILED") . "\n\n";
    
    // 7. Queue Status
    echo "7️⃣ Final Queue Status...\n";
    $pendingLocal = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE status = 'pending'")->fetch_assoc();
    $pendingRemote = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE status = 'pending'")->fetch_assoc();
    
    echo "Pending Local: {$pendingLocal['count']}\n";
    echo "Pending Remote: {$pendingRemote['count']}\n";
    $allProcessed = ($pendingLocal['count'] == 0 && $pendingRemote['count'] == 0);
    echo "All Processed: " . ($allProcessed ? "✅ YES" : "❌ NO") . "\n\n";
    
    // 8. Trigger Protection Test
    echo "8️⃣ Testing Trigger Protection...\n";
    
    $sync->localDb->query("SET @sync_in_progress = 1");
    $protectedUpdate = $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = 'Protected' WHERE id = 6902");
    $protectedCount = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE record_id = 6902")->fetch_assoc();
    $sync->localDb->query("SET @sync_in_progress = NULL");
    
    $protectionOk = ($protectedCount['count'] == 0);
    echo "Trigger Protection: " . ($protectionOk ? "✅ WORKING" : "❌ FAILED") . "\n\n";
    
    // 9. Final Score
    echo "9️⃣ Final Test Score...\n";
    
    $tests = [
        'Local Queue Creation' => $localQueueEntry !== false,
        'Remote Queue Creation' => $remoteQueueEntry !== false,
        'Sync Execution' => $syncResult['success'],
        'Local to Remote Sync' => $localToRemoteOk,
        'Remote to Local Sync' => $remoteToLocalOk,
        'Queue Processing' => $allProcessed,
        'Trigger Protection' => $protectionOk
    ];
    
    $passed = 0;
    $total = count($tests);
    
    foreach ($tests as $testName => $result) {
        echo ($result ? "✅" : "❌") . " $testName\n";
        if ($result) $passed++;
    }
    
    $percentage = round(($passed / $total) * 100, 1);
    echo "\n📊 FINAL SCORE: $passed/$total tests passed ($percentage%)\n\n";
    
    if ($passed === $total) {
        echo "🎉 === PERFECT SCORE! === 🎉\n";
        echo "🚀 The bidirectional sync system is FULLY OPERATIONAL!\n";
        echo "✅ Migration successful\n";
        echo "✅ Queue-based sync working\n";
        echo "✅ Trigger protection active\n";
        echo "✅ Bidirectional synchronization confirmed\n";
        echo "\nThe system is ready for production use! 🎯\n";
    } else {
        echo "⚠️ === SOME ISSUES DETECTED === ⚠️\n";
        echo "Please review the failed tests above.\n";
    }
    
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
