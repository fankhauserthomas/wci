<?php
// test_av_resdet_sync.php - Spezieller Test für AV_ResDet Sync-Problem

require 'SyncManager.php';

echo "=== AV_ResDet SYNC TEST ===\n\n";

try {
    $sync = new SyncManager();
    
    // Test-Record aus AV_ResDet holen
    $testQuery = $sync->localDb->query("SELECT * FROM AV_ResDet LIMIT 1");
    if (!$testQuery || !($testRecord = $testQuery->fetch_assoc())) {
        echo "❌ No test record found in AV_ResDet\n";
        exit;
    }
    
    $testId = $testRecord['ID']; // Großgeschrieben!
    echo "Test Record ID: $testId\n";
    echo "Test Record Data:\n";
    foreach ($testRecord as $field => $value) {
        echo "  $field: " . var_export($value, true) . "\n";
    }
    echo "\n";
    
    // Teste Primary Key Funktion
    $primaryKey = $sync->testGetPrimaryKey('AV_ResDet');
    echo "Primary Key detected: '$primaryKey'\n";
    
    if ($primaryKey === 'ID') {
        echo "✅ Primary Key detection correct\n";
    } else {
        echo "❌ Primary Key detection WRONG - should be 'ID'\n";
    }
    echo "\n";
    
    // Simuliere einen Update im lokalen AV_ResDet
    echo "=== SIMULATING UPDATE ===\n";
    
    // Ändere ein Feld
    $newNote = "Test Update " . date('H:i:s');
    $updateSql = "UPDATE AV_ResDet SET note = ? WHERE ID = ?";
    $stmt = $sync->localDb->prepare($updateSql);
    $stmt->bind_param('si', $newNote, $testId);
    
    if ($stmt->execute()) {
        echo "✅ Local update successful - note changed to: '$newNote'\n";
        
        // Prüfe ob Trigger funktioniert (sollte Queue-Eintrag erstellen)
        $queueCheck = $sync->localDb->query("SELECT * FROM sync_queue_local WHERE table_name = 'AV_ResDet' AND record_id = $testId ORDER BY created_at DESC LIMIT 1");
        
        if ($queueCheck && $queueEntry = $queueCheck->fetch_assoc()) {
            echo "✅ Queue entry created:\n";
            echo "  Operation: " . $queueEntry['operation'] . "\n";
            echo "  Status: " . $queueEntry['status'] . "\n";
            echo "  Created: " . $queueEntry['created_at'] . "\n";
            
            // Teste manuellen Sync
            echo "\n=== MANUAL SYNC TEST ===\n";
            $syncResult = $sync->sync();
            
            if ($syncResult['success']) {
                echo "✅ Sync completed successfully\n";
                
                // Prüfe Remote-Datenbank
                if ($sync->remoteDb) {
                    $remoteCheck = $sync->remoteDb->query("SELECT note FROM AV_ResDet WHERE ID = $testId");
                    if ($remoteCheck && $remoteRecord = $remoteCheck->fetch_assoc()) {
                        if ($remoteRecord['note'] === $newNote) {
                            echo "✅ Remote record updated correctly: '{$remoteRecord['note']}'\n";
                        } else {
                            echo "❌ Remote record NOT updated correctly\n";
                            echo "  Expected: '$newNote'\n";  
                            echo "  Found: '{$remoteRecord['note']}'\n";
                        }
                    } else {
                        echo "❌ Remote record not found\n";
                    }
                } else {
                    echo "⚠️ Remote DB not available for verification\n";
                }
            } else {
                echo "❌ Sync failed: " . $syncResult['error'] . "\n";
            }
            
        } else {
            echo "❌ No queue entry created - triggers may not be working\n";
        }
        
    } else {
        echo "❌ Local update failed: " . $stmt->error . "\n";
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
