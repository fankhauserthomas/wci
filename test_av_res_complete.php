<?php
require_once 'SyncManager.php';

echo "=== COMPLETE AV-RES SYNC TEST ===\n";
echo "Testing AV-Res table with all operations in both directions\n\n";

try {
    $sync = new SyncManager();
    
    // 1. SYSTEM STATUS CHECK
    echo "1. SYSTEM STATUS CHECK:\n";
    echo "========================\n";
    echo "✓ Local DB connected: " . ($sync->localDb ? 'YES' : 'NO') . "\n";
    echo "✓ Remote DB connected: " . ($sync->remoteDb ? 'YES' : 'NO') . "\n";
    echo "✓ Queue tables exist: " . ($sync->checkQueueTables() ? 'YES' : 'NO') . "\n\n";
    
    // 2. CHECK AV-RES TABLE STRUCTURE
    echo "2. AV-RES TABLE STRUCTURE CHECK:\n";
    echo "=================================\n";
    
    // Local table structure
    $localColumns = $sync->localDb->query("SHOW COLUMNS FROM `AV-Res`");
    echo "Local AV-Res columns: ";
    $localCols = [];
    while ($row = $localColumns->fetch_assoc()) {
        $localCols[] = $row['Field'];
    }
    echo implode(', ', $localCols) . "\n";
    
    // Remote table structure
    $remoteColumns = $sync->remoteDb->query("SHOW COLUMNS FROM `AV-Res`");
    echo "Remote AV-Res columns: ";
    $remoteCols = [];
    while ($row = $remoteColumns->fetch_assoc()) {
        $remoteCols[] = $row['Field'];
    }
    echo implode(', ', $remoteCols) . "\n";
    
    // Check sync_timestamp column
    $hasSyncTimestamp = in_array('sync_timestamp', $localCols) && in_array('sync_timestamp', $remoteCols);
    echo "✓ sync_timestamp column: " . ($hasSyncTimestamp ? 'YES' : 'NO') . "\n\n";
    
    // 3. GET CURRENT RECORD COUNTS
    echo "3. INITIAL RECORD COUNTS:\n";
    echo "==========================\n";
    $localCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    $remoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    echo "Local AV-Res records: $localCount\n";
    echo "Remote AV-Res records: $remoteCount\n\n";
    
    // 4. PREPARE TEST DATA
    echo "4. PREPARING TEST DATA:\n";
    echo "========================\n";
    
    // Generate unique test IDs to avoid conflicts
    $testId1 = 99990 + rand(1, 9);
    $testId2 = 99991 + rand(1, 9);
    $testId3 = 99992 + rand(1, 9);
    
    echo "Test IDs: $testId1, $testId2, $testId3\n\n";
    
    // 5. TEST CASE 1: INSERT (Local → Remote)
    echo "5. TEST CASE 1: INSERT (Local → Remote)\n";
    echo "========================================\n";
    
    // Insert test record in local database
    $stmt = $sync->localDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 999, '2025-08-01', '2025-08-07', 2, 'Test Reservation Local', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId1);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        echo "✓ Test record $testId1 inserted in LOCAL database\n";
        
        // Trigger sync
        echo "Triggering sync...\n";
        $syncResult = $sync->syncOnPageLoad('test_insert_local_to_remote');
        echo "Sync result: " . json_encode($syncResult) . "\n";
        
        // Check if record appeared in remote
        $stmt = $sync->remoteDb->prepare("SELECT * FROM `AV-Res` WHERE id = ?");
        $stmt->bind_param('i', $testId1);
        $stmt->execute();
        $remoteRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($remoteRecord) {
            echo "✅ INSERT Local → Remote: SUCCESS\n";
            echo "   Remote record found: av_id={$remoteRecord['av_id']}, anreise={$remoteRecord['anreise']}, abreise={$remoteRecord['abreise']}\n";
        } else {
            echo "❌ INSERT Local → Remote: FAILED - Record not found in remote\n";
        }
    } else {
        echo "❌ Failed to insert test record in local database\n";
    }
    echo "\n";
    
    // 6. TEST CASE 2: UPDATE (Remote → Local)
    echo "6. TEST CASE 2: UPDATE (Remote → Local)\n";
    echo "========================================\n";
    
    // Insert initial record in both databases
    $stmt = $sync->localDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 998, '2025-08-01', '2025-08-07', 2, 'Original Local', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId2);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $sync->remoteDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 998, '2025-08-01', '2025-08-07', 2, 'Original Remote', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId2);
    $stmt->execute();
    $stmt->close();
    
    echo "✓ Initial record $testId2 created in both databases\n";
    
    // Wait a moment to ensure different timestamps
    sleep(1);
    
    // Update record in remote (should sync to local)
    $stmt = $sync->remoteDb->prepare("
        UPDATE `AV-Res` 
        SET bem = 'UPDATED FROM REMOTE', betten = 4, sync_timestamp = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('i', $testId2);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        echo "✓ Test record $testId2 updated in REMOTE database\n";
        
        // Trigger sync
        echo "Triggering sync...\n";
        $syncResult = $sync->syncOnPageLoad('test_update_remote_to_local');
        echo "Sync result: " . json_encode($syncResult) . "\n";
        
        // Check if update appeared in local
        $stmt = $sync->localDb->prepare("SELECT * FROM `AV-Res` WHERE id = ?");
        $stmt->bind_param('i', $testId2);
        $stmt->execute();
        $localRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($localRecord && $localRecord['bem'] === 'UPDATED FROM REMOTE' && $localRecord['betten'] == 4) {
            echo "✅ UPDATE Remote → Local: SUCCESS\n";
            echo "   Local record updated: bem={$localRecord['bem']}, betten={$localRecord['betten']}\n";
        } else {
            echo "❌ UPDATE Remote → Local: FAILED\n";
            if ($localRecord) {
                echo "   Local record: bem={$localRecord['bem']}, betten={$localRecord['betten']}\n";
            }
        }
    } else {
        echo "❌ Failed to update test record in remote database\n";
    }
    echo "\n";
    
    // 7. TEST CASE 3: DELETE (Local → Remote)
    echo "7. TEST CASE 3: DELETE (Local → Remote)\n";
    echo "========================================\n";
    
    // Insert test record in both databases
    $stmt = $sync->localDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 997, '2025-08-01', '2025-08-07', 2, 'To be deleted', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId3);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $sync->remoteDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 997, '2025-08-01', '2025-08-07', 2, 'To be deleted', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId3);
    $stmt->execute();
    $stmt->close();
    
    echo "✓ Test record $testId3 created in both databases\n";
    
    // Delete from local (should sync to remote)
    $stmt = $sync->localDb->prepare("DELETE FROM `AV-Res` WHERE id = ?");
    $stmt->bind_param('i', $testId3);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        echo "✓ Test record $testId3 deleted from LOCAL database\n";
        
        // Trigger sync
        echo "Triggering sync...\n";
        $syncResult = $sync->syncOnPageLoad('test_delete_local_to_remote');
        echo "Sync result: " . json_encode($syncResult) . "\n";
        
        // Check if record was deleted from remote
        $stmt = $sync->remoteDb->prepare("SELECT * FROM `AV-Res` WHERE id = ?");
        $stmt->bind_param('i', $testId3);
        $stmt->execute();
        $remoteRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$remoteRecord) {
            echo "✅ DELETE Local → Remote: SUCCESS\n";
            echo "   Record successfully deleted from remote\n";
        } else {
            echo "❌ DELETE Local → Remote: FAILED - Record still exists in remote\n";
        }
    } else {
        echo "❌ Failed to delete test record from local database\n";
    }
    echo "\n";
    
    // 8. REVERSE DIRECTION TESTS
    echo "8. REVERSE DIRECTION TESTS:\n";
    echo "============================\n";
    
    // Generate new test IDs for reverse tests
    $testId4 = 99980 + rand(1, 9);
    $testId5 = 99981 + rand(1, 9);
    $testId6 = 99982 + rand(1, 9);
    
    // 8a. INSERT (Remote → Local)
    echo "8a. INSERT (Remote → Local):\n";
    echo "-----------------------------\n";
    
    $stmt = $sync->remoteDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 996, '2025-08-15', '2025-08-20', 3, 'Test Reservation Remote', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId4);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        echo "✓ Test record $testId4 inserted in REMOTE database\n";
        
        $syncResult = $sync->syncOnPageLoad('test_insert_remote_to_local');
        echo "Sync result: " . json_encode($syncResult) . "\n";
        
        $stmt = $sync->localDb->prepare("SELECT * FROM `AV-Res` WHERE id = ?");
        $stmt->bind_param('i', $testId4);
        $stmt->execute();
        $localRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($localRecord) {
            echo "✅ INSERT Remote → Local: SUCCESS\n";
        } else {
            echo "❌ INSERT Remote → Local: FAILED\n";
        }
    }
    echo "\n";
    
    // 8b. UPDATE (Local → Remote)
    echo "8b. UPDATE (Local → Remote):\n";
    echo "-----------------------------\n";
    
    // Create initial record
    $stmt = $sync->localDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 995, '2025-08-01', '2025-08-07', 2, 'Original', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId5);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $sync->remoteDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 995, '2025-08-01', '2025-08-07', 2, 'Original', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId5);
    $stmt->execute();
    $stmt->close();
    
    sleep(1);
    
    // Update in local
    $stmt = $sync->localDb->prepare("
        UPDATE `AV-Res` 
        SET bem = 'UPDATED FROM LOCAL', betten = 5, sync_timestamp = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('i', $testId5);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        echo "✓ Test record $testId5 updated in LOCAL database\n";
        
        $syncResult = $sync->syncOnPageLoad('test_update_local_to_remote');
        echo "Sync result: " . json_encode($syncResult) . "\n";
        
        $stmt = $sync->remoteDb->prepare("SELECT * FROM `AV-Res` WHERE id = ?");
        $stmt->bind_param('i', $testId5);
        $stmt->execute();
        $remoteRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($remoteRecord && $remoteRecord['bem'] === 'UPDATED FROM LOCAL' && $remoteRecord['betten'] == 5) {
            echo "✅ UPDATE Local → Remote: SUCCESS\n";
        } else {
            echo "❌ UPDATE Local → Remote: FAILED\n";
        }
    }
    echo "\n";
    
    // 8c. DELETE (Remote → Local)
    echo "8c. DELETE (Remote → Local):\n";
    echo "-----------------------------\n";
    
    // Create test record
    $stmt = $sync->localDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 994, '2025-08-01', '2025-08-07', 2, 'To be deleted', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId6);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $sync->remoteDb->prepare("
        INSERT INTO `AV-Res` (id, av_id, anreise, abreise, betten, bem, storno, vorgang, sync_timestamp)
        VALUES (?, 994, '2025-08-01', '2025-08-07', 2, 'To be deleted', 0, 'test', NOW())
    ");
    $stmt->bind_param('i', $testId6);
    $stmt->execute();
    $stmt->close();
    
    // Delete from remote
    $stmt = $sync->remoteDb->prepare("DELETE FROM `AV-Res` WHERE id = ?");
    $stmt->bind_param('i', $testId6);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        echo "✓ Test record $testId6 deleted from REMOTE database\n";
        
        $syncResult = $sync->syncOnPageLoad('test_delete_remote_to_local');
        echo "Sync result: " . json_encode($syncResult) . "\n";
        
        $stmt = $sync->localDb->prepare("SELECT * FROM `AV-Res` WHERE id = ?");
        $stmt->bind_param('i', $testId6);
        $stmt->execute();
        $localRecord = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$localRecord) {
            echo "✅ DELETE Remote → Local: SUCCESS\n";
        } else {
            echo "❌ DELETE Remote → Local: FAILED\n";
        }
    }
    echo "\n";
    
    // 9. FINAL RECORD COUNTS
    echo "9. FINAL RECORD COUNTS:\n";
    echo "========================\n";
    $finalLocalCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    $finalRemoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    echo "Final Local AV-Res records: $finalLocalCount\n";
    echo "Final Remote AV-Res records: $finalRemoteCount\n";
    echo "Record count difference: " . abs($finalLocalCount - $finalRemoteCount) . "\n\n";
    
    // 10. QUEUE STATUS CHECK
    echo "10. FINAL QUEUE STATUS:\n";
    echo "========================\n";
    $localQueue = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE status = 'pending'")->fetch_assoc()['count'];
    $remoteQueue = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE status = 'pending'")->fetch_assoc()['count'];
    echo "Pending local queue items: $localQueue\n";
    echo "Pending remote queue items: $remoteQueue\n\n";
    
    // 11. CLEANUP TEST DATA
    echo "11. CLEANUP TEST DATA:\n";
    echo "=======================\n";
    $testIds = [$testId1, $testId2, $testId4, $testId5]; // $testId3, $testId6 already deleted
    
    foreach ($testIds as $id) {
        // Clean local
        $sync->localDb->query("DELETE FROM `AV-Res` WHERE id = $id");
        // Clean remote
        $sync->remoteDb->query("DELETE FROM `AV-Res` WHERE id = $id");
    }
    echo "✓ Test data cleaned up\n\n";
    
    echo "=== AV-RES COMPLETE SYNC TEST FINISHED ===\n";
    
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
