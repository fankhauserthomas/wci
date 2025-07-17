<?php
require_once 'SyncManager.php';

echo "🔍 === DETAILED REMOTE TRIGGER TEST === 🔍\n\n";

try {
    $sync = new SyncManager();
    
    // 1. Test verschiedene Remote UPDATE Varianten
    echo "1️⃣ Testing Remote UPDATE Variations...\n";
    
    $testIds = [6895, 6896, 6897];
    
    foreach ($testIds as $testId) {
        echo "\nTesting ID $testId:\n";
        
        // Queue vor Update prüfen
        $beforeCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE record_id = $testId")->fetch_assoc();
        echo "  Queue entries before: {$beforeCount['count']}\n";
        
        // UPDATE mit verschiedenen Methoden
        $updateQuery = "UPDATE `AV-ResNamen` SET bem = 'Remote Test " . date('H:i:s') . "' WHERE id = $testId";
        echo "  Running: $updateQuery\n";
        
        $result = $sync->remoteDb->query($updateQuery);
        echo "  UPDATE result: " . ($result ? "SUCCESS" : "FAILED");
        if (!$result) {
            echo " (Error: " . $sync->remoteDb->error . ")";
        }
        echo "\n";
        
        // Affected rows prüfen
        echo "  Affected rows: " . $sync->remoteDb->affected_rows . "\n";
        
        // Queue nach Update prüfen
        sleep(1);
        $afterCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE record_id = $testId")->fetch_assoc();
        echo "  Queue entries after: {$afterCount['count']}\n";
        
        if ($afterCount['count'] > $beforeCount['count']) {
            echo "  ✅ Trigger worked!\n";
            
            // Details des Queue-Eintrags
            $queueEntry = $sync->remoteDb->query("SELECT * FROM sync_queue_remote WHERE record_id = $testId ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
            echo "  Queue entry: ID {$queueEntry['id']}, Operation: {$queueEntry['operation']}, Status: {$queueEntry['status']}\n";
        } else {
            echo "  ❌ Trigger did NOT fire!\n";
        }
    }
    
    // 2. Test Trigger mit direkter Session
    echo "\n2️⃣ Testing with Direct Session...\n";
    
    // Prüfe aktuelle Session-Variablen
    $sessionVars = $sync->remoteDb->query("SHOW SESSION VARIABLES LIKE 'sql_log_bin'")->fetch_assoc();
    if ($sessionVars) {
        echo "sql_log_bin: {$sessionVars['Value']}\n";
    }
    
    // Test ob @sync_in_progress funktioniert
    $sync->remoteDb->query("SET @test_var = 1");
    $testVar = $sync->remoteDb->query("SELECT @test_var as value")->fetch_assoc();
    echo "Session variable test: " . ($testVar['value'] == 1 ? "WORKS" : "FAILED") . "\n";
    
    // 3. Trigger-Status prüfen
    echo "\n3️⃣ Trigger Status Check...\n";
    $triggers = $sync->remoteDb->query("SHOW TRIGGERS LIKE 'AV-ResNamen'")->fetch_all(MYSQLI_ASSOC);
    foreach ($triggers as $trigger) {
        echo "Trigger: {$trigger['Trigger']} - Definer: {$trigger['Definer']} - Status: Active\n";
    }
    
    // 4. Permissions prüfen
    echo "\n4️⃣ Permission Check...\n";
    $user = $sync->remoteDb->query("SELECT USER() as user, CURRENT_USER() as current_user")->fetch_assoc();
    echo "Connection User: {$user['user']}\n";
    echo "Current User: {$user['current_user']}\n";
    
    // 5. Manueller Trigger Test (bypassing MySQL trigger)
    echo "\n5️⃣ Manual Trigger Simulation...\n";
    $manualTestId = 6898;
    
    // Simuliere was der Trigger machen sollte
    $manualInsert = $sync->remoteDb->query("
        INSERT INTO sync_queue_remote (record_id, operation, created_at) 
        VALUES ($manualTestId, 'manual_test', NOW())
    ");
    
    echo "Manual queue insert: " . ($manualInsert ? "SUCCESS" : "FAILED") . "\n";
    
    if ($manualInsert) {
        // Cleanup
        $sync->remoteDb->query("DELETE FROM sync_queue_remote WHERE record_id = $manualTestId AND operation = 'manual_test'");
        echo "Cleanup: SUCCESS\n";
    }
    
    echo "\n6️⃣ Recommendation...\n";
    echo "If triggers are not firing automatically, possible causes:\n";
    echo "- DEFINER permissions issue\n";
    echo "- sql_log_bin setting\n";
    echo "- MySQL replication settings\n";
    echo "- Trigger disabled by admin\n";
    
} catch (Exception $e) {
    echo "❌ TEST ERROR: " . $e->getMessage() . "\n";
}
?>
