<?php
require_once 'SyncManager.php';

echo "🔧 === REMOTE TRIGGER REPAIR === 🔧\n\n";

try {
    $sync = new SyncManager();
    
    // 1. Zeige aktuelle Remote-Trigger
    echo "1️⃣ Current Remote Trigger Definition...\n";
    $triggerDef = $sync->remoteDb->query("SHOW CREATE TRIGGER av_resnamen_queue_update")->fetch_assoc();
    if ($triggerDef) {
        echo "Current UPDATE trigger:\n";
        echo $triggerDef['SQL Original Statement'] . "\n\n";
    } else {
        echo "UPDATE trigger not found!\n\n";
    }
    
    // 2. Prüfe Queue-Tabelle Struktur
    echo "2️⃣ Remote Queue Table Structure...\n";
    $queueStructure = $sync->remoteDb->query("DESCRIBE sync_queue_remote")->fetch_all(MYSQLI_ASSOC);
    foreach ($queueStructure as $column) {
        echo "Column: {$column['Field']} ({$column['Type']}) - {$column['Null']} - {$column['Default']}\n";
    }
    echo "\n";
    
    // 3. Test Direct Insert
    echo "3️⃣ Testing Direct Queue Insert...\n";
    $directInsert = $sync->remoteDb->query("
        INSERT INTO sync_queue_remote (record_id, operation, created_at) 
        VALUES (9999, 'test', NOW())
    ");
    echo "Direct insert result: " . ($directInsert ? "SUCCESS" : "FAILED") . "\n";
    if (!$directInsert) {
        echo "Error: " . $sync->remoteDb->error . "\n";
    }
    
    // 4. Cleanup test insert
    $sync->remoteDb->query("DELETE FROM sync_queue_remote WHERE record_id = 9999");
    
    // 5. Test Manual Trigger Execution
    echo "4️⃣ Testing Manual Trigger Logic...\n";
    
    // Simuliere Trigger-Code
    $testRecordId = 6897;
    $manualTriggerCode = "
        INSERT INTO sync_queue_remote (record_id, operation, created_at)
        VALUES ($testRecordId, 'manual_test', NOW())
    ";
    
    $manualResult = $sync->remoteDb->query($manualTriggerCode);
    echo "Manual trigger code: " . ($manualResult ? "SUCCESS" : "FAILED") . "\n";
    if (!$manualResult) {
        echo "Error: " . $sync->remoteDb->error . "\n";
    }
    
    // Check if manual insert worked
    $manualCheck = $sync->remoteDb->query("SELECT * FROM sync_queue_remote WHERE record_id = $testRecordId")->fetch_assoc();
    if ($manualCheck) {
        echo "Manual queue entry created: ID {$manualCheck['id']}\n";
        // Cleanup
        $sync->remoteDb->query("DELETE FROM sync_queue_remote WHERE id = {$manualCheck['id']}");
    } else {
        echo "Manual queue entry NOT created\n";
    }
    
    echo "\n5️⃣ Suggestion: Try recreating Remote triggers manually...\n";
    echo "The triggers might have syntax issues or permission problems.\n";
    
} catch (Exception $e) {
    echo "❌ REPAIR ERROR: " . $e->getMessage() . "\n";
}
?>
