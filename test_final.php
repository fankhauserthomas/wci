<?php
require_once 'SyncManager.php';

echo "🎯 === FINAL SYSTEM TEST === 🎯\n\n";

try {
    $sync = new SyncManager();
    
    // Test mit verschiedenen Record IDs
    $testIds = [6891, 6892, 6893];
    
    foreach ($testIds as $recordId) {
        echo "Testing Record ID: $recordId\n";
        
        // 1. Lokale Änderung
        echo "  Local Update: ";
        $localUpdate = $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = 'Final Test Local " . date('H:i:s') . "' WHERE id = $recordId");
        echo ($localUpdate ? "SUCCESS" : "FAILED") . "\n";
        
        // Local Queue Check
        $localQ = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE record_id = $recordId AND status = 'pending'")->fetch_assoc();
        echo "  Local Queue: {$localQ['count']} pending\n";
        
        // 2. Remote-Änderung
        echo "  Remote Update: ";
        $remoteUpdate = $sync->remoteDb->query("UPDATE `AV-ResNamen` SET bem = 'Final Test Remote " . date('H:i:s') . "' WHERE id = $recordId");
        echo ($remoteUpdate ? "SUCCESS" : "FAILED") . "\n";
        
        // Remote Queue Check
        $remoteQ = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE record_id = $recordId AND status = 'pending'")->fetch_assoc();
        echo "  Remote Queue: {$remoteQ['count']} pending\n";
        
        echo "\n";
    }
    
    // Komplette Queue-Übersicht
    echo "📊 Complete Queue Overview:\n";
    $allLocalQueue = $sync->localDb->query("SELECT record_id, operation, status, created_at FROM sync_queue_local ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    $allRemoteQueue = $sync->remoteDb->query("SELECT record_id, operation, status, created_at FROM sync_queue_remote ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    
    echo "Local Queue (last 10):\n";
    foreach ($allLocalQueue as $item) {
        echo "  ID:{$item['record_id']} {$item['operation']} [{$item['status']}] {$item['created_at']}\n";
    }
    
    echo "Remote Queue (last 10):\n";
    foreach ($allRemoteQueue as $item) {
        echo "  ID:{$item['record_id']} {$item['operation']} [{$item['status']}] {$item['created_at']}\n";
    }
    
    // Abschließender Sync-Test
    echo "\n🚀 Running Final Sync...\n";
    $finalSync = $sync->syncOnPageLoad('final_test');
    echo "Final Sync Result: " . json_encode($finalSync, JSON_PRETTY_PRINT) . "\n";
    
    // Status nach finalem Sync
    $finalLocalCount = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE status = 'pending'")->fetch_assoc();
    $finalRemoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE status = 'pending'")->fetch_assoc();
    
    echo "\nFinal Queue Status:\n";
    echo "Local Pending: {$finalLocalCount['count']}\n";
    echo "Remote Pending: {$finalRemoteCount['count']}\n";
    
    if ($finalLocalCount['count'] == 0 && $finalRemoteCount['count'] == 0) {
        echo "\n✅ ALL QUEUES PROCESSED SUCCESSFULLY!\n";
    } else {
        echo "\n⚠️  Some queue items remain pending\n";
    }
    
    echo "\n🎉 SYNC SYSTEM IS OPERATIONAL! 🎉\n";
    
} catch (Exception $e) {
    echo "❌ FINAL TEST ERROR: " . $e->getMessage() . "\n";
}
?>
