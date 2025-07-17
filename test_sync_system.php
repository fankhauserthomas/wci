<?php
require_once 'SyncManager.php';

echo "🧪 === POST-MIGRATION SYNC TEST === 🧪\n\n";

try {
    $sync = new SyncManager();
    
    // 1. Aktuelle Datenstände prüfen
    echo "1️⃣ Current Database Status...\n";
    $localCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-ResNamen`")->fetch_assoc();
    $remoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-ResNamen`")->fetch_assoc();
    
    echo "Local Records: {$localCount['count']}\n";
    echo "Remote Records: {$remoteCount['count']}\n";
    echo "Difference: " . ($localCount['count'] - $remoteCount['count']) . "\n\n";
    
    // 2. Queue-Status prüfen
    echo "2️⃣ Queue Status Check...\n";
    $localQueue = $sync->localDb->query("SELECT status, COUNT(*) as count FROM sync_queue_local GROUP BY status")->fetch_all(MYSQLI_ASSOC);
    $remoteQueue = $sync->remoteDb->query("SELECT status, COUNT(*) as count FROM sync_queue_remote GROUP BY status")->fetch_all(MYSQLI_ASSOC);
    
    echo "Local Queue:\n";
    foreach ($localQueue as $item) {
        echo "  {$item['status']}: {$item['count']}\n";
    }
    if (empty($localQueue)) echo "  (empty)\n";
    
    echo "Remote Queue:\n";
    foreach ($remoteQueue as $item) {
        echo "  {$item['status']}: {$item['count']}\n";
    }
    if (empty($remoteQueue)) echo "  (empty)\n";
    echo "\n";
    
    // 3. Test lokale Änderung
    echo "3️⃣ Testing Local Change...\n";
    $testId = 6891;
    $testValue = "Post-Migration Test Local " . date('H:i:s');
    
    $localUpdate = $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = '$testValue' WHERE id = $testId");
    echo "Local UPDATE (ID $testId): " . ($localUpdate ? "SUCCESS" : "FAILED") . "\n";
    
    // Prüfe Queue-Eintrag
    sleep(1); // Kurz warten
    $queueCheck = $sync->localDb->query("SELECT * FROM sync_queue_local WHERE record_id = $testId ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    if ($queueCheck) {
        echo "Queue Entry Created: ID {$queueCheck['id']}, Operation: {$queueCheck['operation']}, Status: {$queueCheck['status']}\n";
    } else {
        echo "❌ No queue entry found!\n";
    }
    echo "\n";
    
    // 4. Test Remote-Änderung
    echo "4️⃣ Testing Remote Change...\n";
    $testId2 = 6892;
    $testValue2 = "Post-Migration Test Remote " . date('H:i:s');
    
    $remoteUpdate = $sync->remoteDb->query("UPDATE `AV-ResNamen` SET bem = '$testValue2' WHERE id = $testId2");
    echo "Remote UPDATE (ID $testId2): " . ($remoteUpdate ? "SUCCESS" : "FAILED") . "\n";
    
    // Prüfe Queue-Eintrag
    sleep(1);
    $queueCheck2 = $sync->remoteDb->query("SELECT * FROM sync_queue_remote WHERE record_id = $testId2 ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    if ($queueCheck2) {
        echo "Queue Entry Created: ID {$queueCheck2['id']}, Operation: {$queueCheck2['operation']}, Status: {$queueCheck2['status']}\n";
    } else {
        echo "❌ No queue entry found!\n";
    }
    echo "\n";
    
    // 5. Bidirektionaler Sync Test
    echo "5️⃣ Running Bidirectional Sync...\n";
    $syncResult = $sync->syncOnPageLoad('post_migration_test');
    echo "Sync Result: " . json_encode($syncResult, JSON_PRETTY_PRINT) . "\n\n";
    
    // 2. Test lokale Änderung
    echo "2️⃣ Creating Local Change (Record 6891)...\n";
    $localChange = $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = 'Queue Test Local " . date('H:i:s') . "' WHERE id = 6891");
    echo "Local UPDATE: " . ($localChange ? "SUCCESS" : "FAILED") . "\n";
    
    // Queue-Status nach lokaler Änderung prüfen
    $localQueue = $sync->localDb->query("SELECT * FROM sync_queue_local WHERE record_id = 6891 ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    echo "Local Queue Entry: " . ($localQueue ? "CREATED (ID: {$localQueue['id']}, Operation: {$localQueue['operation']})" : "NOT FOUND") . "\n\n";
    
    // 3. Test Remote-Änderung
    echo "3️⃣ Creating Remote Change (Record 6892)...\n";
    $remoteChange = $sync->remoteDb->query("UPDATE `AV-ResNamen` SET bem = 'Queue Test Remote " . date('H:i:s') . "' WHERE id = 6892");
    echo "Remote UPDATE: " . ($remoteChange ? "SUCCESS" : "FAILED") . "\n";
    
    // Queue-Status nach Remote-Änderung prüfen
    $remoteQueue = $sync->remoteDb->query("SELECT * FROM sync_queue_remote WHERE record_id = 6892 ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    echo "Remote Queue Entry: " . ($remoteQueue ? "CREATED (ID: {$remoteQueue['id']}, Operation: {$remoteQueue['operation']})" : "NOT FOUND") . "\n\n";
    
    // 4. Bidirektionale Synchronisation
    echo "4️⃣ Running Bidirectional Sync...\n";
    $syncResult = $sync->syncOnPageLoad('bidirectional_test');
    echo "Sync Result: " . json_encode($syncResult, JSON_PRETTY_PRINT) . "\n\n";
    
    // 5. Queue-Status nach Sync prüfen
    echo "5️⃣ Checking Queue Status After Sync...\n";
    $localQueueCount = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE status = 'pending'")->fetch_assoc();
    $remoteQueueCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE status = 'pending'")->fetch_assoc();
    
    echo "Pending Local Queue Items: " . $localQueueCount['count'] . "\n";
    echo "Pending Remote Queue Items: " . $remoteQueueCount['count'] . "\n\n";
    
    // 6. Test Trigger-Schutz
    echo "6️⃣ Testing Trigger Protection...\n";
    
    // Sync-Flag setzen
    $sync->localDb->query("SET @sync_in_progress = 1");
    echo "Sync flag SET (triggers should be disabled)\n";
    
    // UPDATE während Sync-Flag aktiv
    $protectedUpdate = $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = 'Protected Update Test' WHERE id = 6893");
    echo "Protected UPDATE: " . ($protectedUpdate ? "SUCCESS" : "FAILED") . "\n";
    
    // Prüfe ob Queue-Eintrag erstellt wurde (sollte NICHT der Fall sein)
    $protectedQueue = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE record_id = 6893")->fetch_assoc();
    echo "Queue entries for protected record 6893: " . $protectedQueue['count'] . " (should be 0)\n";
    
    // Flag zurücksetzen
    $sync->localDb->query("SET @sync_in_progress = NULL");
    echo "Sync flag CLEARED (triggers re-enabled)\n\n";
    
    // 7. Test normaler Trigger nach Flag-Reset
    echo "7️⃣ Testing Trigger After Flag Reset...\n";
    $normalUpdate = $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = 'Normal Update Test' WHERE id = 6894");
    echo "Normal UPDATE: " . ($normalUpdate ? "SUCCESS" : "FAILED") . "\n";
    
    $normalQueue = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE record_id = 6894")->fetch_assoc();
    echo "Queue entries for normal record 6894: " . $normalQueue['count'] . " (should be 1)\n\n";
    
    // 8. Finale Queue-Statistiken
    echo "8️⃣ Final Queue Statistics...\n";
    $localStats = $sync->localDb->query("
        SELECT 
            status, 
            COUNT(*) as count 
        FROM sync_queue_local 
        GROUP BY status
    ")->fetch_all(MYSQLI_ASSOC);
    
    $remoteStats = $sync->remoteDb->query("
        SELECT 
            status, 
            COUNT(*) as count 
        FROM sync_queue_remote 
        GROUP BY status
    ")->fetch_all(MYSQLI_ASSOC);
    
    echo "Local Queue Status:\n";
    foreach ($localStats as $stat) {
        echo "  {$stat['status']}: {$stat['count']}\n";
    }
    
    echo "Remote Queue Status:\n";
    foreach ($remoteStats as $stat) {
        echo "  {$stat['status']}: {$stat['count']}\n";
    }
    
    echo "\n✅ === TEST COMPLETED === ✅\n";
    
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
}
?>
