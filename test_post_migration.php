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
    
    // 6. Verifikation der Synchronisation
    echo "6️⃣ Sync Verification...\n";
    
    // Prüfe ob lokale Änderung auf Remote angekommen ist
    $remoteValue = $sync->remoteDb->query("SELECT bem FROM `AV-ResNamen` WHERE id = $testId")->fetch_assoc();
    echo "Local change synced to Remote: ";
    if ($remoteValue && $remoteValue['bem'] === $testValue) {
        echo "✅ SUCCESS\n";
    } else {
        echo "❌ FAILED (Remote value: " . ($remoteValue['bem'] ?? 'NULL') . ")\n";
    }
    
    // Prüfe ob Remote-Änderung auf Local angekommen ist
    $localValue = $sync->localDb->query("SELECT bem FROM `AV-ResNamen` WHERE id = $testId2")->fetch_assoc();
    echo "Remote change synced to Local: ";
    if ($localValue && $localValue['bem'] === $testValue2) {
        echo "✅ SUCCESS\n";
    } else {
        echo "❌ FAILED (Local value: " . ($localValue['bem'] ?? 'NULL') . ")\n";
    }
    echo "\n";
    
    // 7. Queue-Status nach Sync
    echo "7️⃣ Queue Status After Sync...\n";
    $finalLocalQueue = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE status = 'pending'")->fetch_assoc();
    $finalRemoteQueue = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE status = 'pending'")->fetch_assoc();
    
    echo "Pending Local Queue: {$finalLocalQueue['count']}\n";
    echo "Pending Remote Queue: {$finalRemoteQueue['count']}\n";
    
    if ($finalLocalQueue['count'] == 0 && $finalRemoteQueue['count'] == 0) {
        echo "✅ All queues processed successfully!\n";
    } else {
        echo "⚠️ Some queue items remain pending\n";
    }
    echo "\n";
    
    // 8. Test Trigger-Schutz
    echo "8️⃣ Testing Trigger Protection...\n";
    
    // Setze Sync-Flag
    $sync->localDb->query("SET @sync_in_progress = 1");
    echo "Sync flag SET (triggers disabled)\n";
    
    $protectedUpdate = $sync->localDb->query("UPDATE `AV-ResNamen` SET bem = 'Protected Test' WHERE id = 6893");
    echo "Protected UPDATE: " . ($protectedUpdate ? "SUCCESS" : "FAILED") . "\n";
    
    $protectedQueueCheck = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE record_id = 6893")->fetch_assoc();
    echo "Queue entries for protected record: {$protectedQueueCheck['count']} (should be 0)\n";
    
    // Flag zurücksetzen
    $sync->localDb->query("SET @sync_in_progress = NULL");
    echo "Sync flag CLEARED (triggers re-enabled)\n\n";
    
    // 9. Finale Bewertung
    echo "9️⃣ Final Assessment...\n";
    
    $allTests = [
        'Local Change Creation' => $queueCheck !== false,
        'Remote Change Creation' => $queueCheck2 !== false,
        'Sync Execution' => $syncResult['success'] === true,
        'Local to Remote Sync' => ($remoteValue && $remoteValue['bem'] === $testValue),
        'Remote to Local Sync' => ($localValue && $localValue['bem'] === $testValue2),
        'Queue Processing' => ($finalLocalQueue['count'] == 0 && $finalRemoteQueue['count'] == 0),
        'Trigger Protection' => ($protectedQueueCheck['count'] == 0)
    ];
    
    $passedTests = 0;
    $totalTests = count($allTests);
    
    foreach ($allTests as $testName => $passed) {
        echo ($passed ? "✅" : "❌") . " $testName\n";
        if ($passed) $passedTests++;
    }
    
    echo "\n📊 Test Results: $passedTests/$totalTests passed (" . round(($passedTests/$totalTests)*100, 1) . "%)\n";
    
    if ($passedTests === $totalTests) {
        echo "\n🎉 === ALL TESTS PASSED === 🎉\n";
        echo "The bidirectional sync system is fully operational!\n";
    } else {
        echo "\n⚠️ === SOME TESTS FAILED === ⚠️\n";
        echo "Please check the failed tests above.\n";
    }
    
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
}
?>
