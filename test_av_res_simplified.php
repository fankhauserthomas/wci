<?php
require_once 'SyncManager.php';

echo "=== SIMPLIFIED AV-RES SYNC TEST ===\n";
echo "Testing AV-Res table with all operations in both directions\n\n";

try {
    $sync = new SyncManager();
    
    // 1. SYSTEM STATUS CHECK
    echo "1. SYSTEM STATUS CHECK:\n";
    echo "========================\n";
    echo "✓ Local DB connected: " . ($sync->localDb ? 'YES' : 'NO') . "\n";
    echo "✓ Remote DB connected: " . ($sync->remoteDb ? 'YES' : 'NO') . "\n";
    echo "✓ Queue tables exist: " . ($sync->checkQueueTables() ? 'YES' : 'NO') . "\n\n";
    
    // 2. GET CURRENT RECORD COUNTS
    echo "2. INITIAL RECORD COUNTS:\n";
    echo "==========================\n";
    $localCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    $remoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    echo "Local AV-Res records: $localCount\n";
    echo "Remote AV-Res records: $remoteCount\n\n";
    
    // 3. GET A TEMPLATE RECORD
    echo "3. GETTING TEMPLATE RECORD:\n";
    echo "============================\n";
    $templateRecord = $sync->localDb->query("SELECT * FROM `AV-Res` LIMIT 1")->fetch_assoc();
    if ($templateRecord) {
        echo "✓ Template record found: ID {$templateRecord['id']}\n";
        echo "  Columns: " . implode(', ', array_keys($templateRecord)) . "\n\n";
    } else {
        echo "❌ No template record found!\n";
        exit;
    }
    
    // Generate unique test IDs
    $testId1 = 99990 + rand(1, 9);
    $testId2 = 99991 + rand(1, 9);
    $testId3 = 99992 + rand(1, 9);
    echo "Test IDs: $testId1, $testId2, $testId3\n\n";
    
    // 4. TEST CASE 1: INSERT (Local → Remote)
    echo "4. TEST CASE 1: INSERT (Local → Remote)\n";
    echo "========================================\n";
    
    // Create new record based on template
    $templateRecord['id'] = $testId1;
    $templateRecord['bem'] = 'TEST INSERT LOCAL';
    $templateRecord['sync_timestamp'] = date('Y-m-d H:i:s');
    
    // Build INSERT query dynamically
    $columns = array_keys($templateRecord);
    $columnList = '`' . implode('`, `', $columns) . '`';
    $placeholders = str_repeat('?,', count($columns) - 1) . '?';
    
    $sql = "INSERT INTO `AV-Res` ($columnList) VALUES ($placeholders)";
    $stmt = $sync->localDb->prepare($sql);
    
    $values = array_values($templateRecord);
    $types = str_repeat('s', count($values));
    $stmt->bind_param($types, ...$values);
    
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
        
        if ($remoteRecord && $remoteRecord['bem'] === 'TEST INSERT LOCAL') {
            echo "✅ INSERT Local → Remote: SUCCESS\n";
            echo "   Remote record found with bem: {$remoteRecord['bem']}\n";
        } else {
            echo "❌ INSERT Local → Remote: FAILED\n";
            if ($remoteRecord) {
                echo "   Remote record bem: {$remoteRecord['bem']}\n";
            } else {
                echo "   Record not found in remote\n";
            }
        }
    } else {
        echo "❌ Failed to insert test record in local database\n";
    }
    echo "\n";
    
    // 5. TEST CASE 2: UPDATE (Remote → Local)
    echo "5. TEST CASE 2: UPDATE (Remote → Local)\n";
    echo "========================================\n";
    
    // Create initial record in both databases
    $templateRecord['id'] = $testId2;
    $templateRecord['bem'] = 'ORIGINAL';
    $templateRecord['sync_timestamp'] = date('Y-m-d H:i:s');
    
    // Insert in local
    $stmt = $sync->localDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    
    // Insert in remote
    $stmt = $sync->remoteDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    
    echo "✓ Initial record $testId2 created in both databases\n";
    
    // Wait to ensure different timestamps
    sleep(1);
    
    // Update record in remote
    $stmt = $sync->remoteDb->prepare("
        UPDATE `AV-Res` 
        SET bem = 'UPDATED FROM REMOTE', betten = ?, sync_timestamp = NOW()
        WHERE id = ?
    ");
    $newBetten = $templateRecord['betten'] + 1;
    $stmt->bind_param('ii', $newBetten, $testId2);
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
        
        if ($localRecord && $localRecord['bem'] === 'UPDATED FROM REMOTE' && $localRecord['betten'] == $newBetten) {
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
    
    // 6. TEST CASE 3: DELETE (Local → Remote)
    echo "6. TEST CASE 3: DELETE (Local → Remote)\n";
    echo "========================================\n";
    
    // Create record in both databases
    $templateRecord['id'] = $testId3;
    $templateRecord['bem'] = 'TO BE DELETED';
    $templateRecord['sync_timestamp'] = date('Y-m-d H:i:s');
    
    // Insert in local
    $stmt = $sync->localDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    
    // Insert in remote
    $stmt = $sync->remoteDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    
    echo "✓ Test record $testId3 created in both databases\n";
    
    // Delete from local
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
    
    // 7. REVERSE DIRECTION TESTS
    echo "7. REVERSE DIRECTION TESTS:\n";
    echo "============================\n";
    
    $testId4 = 99980 + rand(1, 9);
    $testId5 = 99981 + rand(1, 9);
    $testId6 = 99982 + rand(1, 9);
    
    // 7a. INSERT (Remote → Local)
    echo "7a. INSERT (Remote → Local):\n";
    echo "-----------------------------\n";
    
    $templateRecord['id'] = $testId4;
    $templateRecord['bem'] = 'TEST INSERT REMOTE';
    $templateRecord['sync_timestamp'] = date('Y-m-d H:i:s');
    
    $stmt = $sync->remoteDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
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
        
        if ($localRecord && $localRecord['bem'] === 'TEST INSERT REMOTE') {
            echo "✅ INSERT Remote → Local: SUCCESS\n";
        } else {
            echo "❌ INSERT Remote → Local: FAILED\n";
        }
    }
    echo "\n";
    
    // 7b. UPDATE (Local → Remote)
    echo "7b. UPDATE (Local → Remote):\n";
    echo "-----------------------------\n";
    
    // Create record in both
    $templateRecord['id'] = $testId5;
    $templateRecord['bem'] = 'ORIGINAL UPDATE TEST';
    $templateRecord['sync_timestamp'] = date('Y-m-d H:i:s');
    
    $stmt = $sync->localDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $sync->remoteDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    
    sleep(1);
    
    // Update in local
    $stmt = $sync->localDb->prepare("
        UPDATE `AV-Res` 
        SET bem = 'UPDATED FROM LOCAL', betten = ?, sync_timestamp = NOW()
        WHERE id = ?
    ");
    $newBetten = $templateRecord['betten'] + 2;
    $stmt->bind_param('ii', $newBetten, $testId5);
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
        
        if ($remoteRecord && $remoteRecord['bem'] === 'UPDATED FROM LOCAL' && $remoteRecord['betten'] == $newBetten) {
            echo "✅ UPDATE Local → Remote: SUCCESS\n";
        } else {
            echo "❌ UPDATE Local → Remote: FAILED\n";
        }
    }
    echo "\n";
    
    // 7c. DELETE (Remote → Local)
    echo "7c. DELETE (Remote → Local):\n";
    echo "-----------------------------\n";
    
    // Create record in both
    $templateRecord['id'] = $testId6;
    $templateRecord['bem'] = 'DELETE TEST';
    $templateRecord['sync_timestamp'] = date('Y-m-d H:i:s');
    
    $stmt = $sync->localDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $sync->remoteDb->prepare($sql);
    $values = array_values($templateRecord);
    $stmt->bind_param($types, ...$values);
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
    
    // 8. FINAL STATUS
    echo "8. FINAL STATUS:\n";
    echo "=================\n";
    $finalLocalCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    $finalRemoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-Res`")->fetch_assoc()['count'];
    echo "Final Local AV-Res records: $finalLocalCount\n";
    echo "Final Remote AV-Res records: $finalRemoteCount\n";
    echo "Record count difference: " . abs($finalLocalCount - $finalRemoteCount) . "\n\n";
    
    // 9. CLEANUP
    echo "9. CLEANUP TEST DATA:\n";
    echo "=====================\n";
    $testIds = [$testId1, $testId2, $testId4, $testId5]; // $testId3, $testId6 already deleted
    
    foreach ($testIds as $id) {
        $sync->localDb->query("DELETE FROM `AV-Res` WHERE id = $id");
        $sync->remoteDb->query("DELETE FROM `AV-Res` WHERE id = $id");
    }
    echo "✓ Test data cleaned up\n\n";
    
    echo "=== AV-RES SIMPLIFIED SYNC TEST COMPLETED ===\n";
    
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
