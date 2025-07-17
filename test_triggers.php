<?php
require_once 'SyncManager.php';

echo "🔍 === TRIGGER DIAGNOSTIC === 🔍\n\n";

try {
    $sync = new SyncManager();
    
    // 1. Prüfe lokale Trigger
    echo "1️⃣ Checking Local Triggers...\n";
    $localTriggers = $sync->localDb->query("SHOW TRIGGERS FROM booking_franzsen WHERE `Table` = 'AV-ResNamen'")->fetch_all(MYSQLI_ASSOC);
    foreach ($localTriggers as $trigger) {
        echo "Local: {$trigger['Trigger']} ({$trigger['Event']} {$trigger['Timing']})\n";
    }
    echo "\n";
    
    // 2. Prüfe Remote-Trigger
    echo "2️⃣ Checking Remote Triggers...\n";
    $remoteTriggers = $sync->remoteDb->query("SHOW TRIGGERS FROM booking_franzsen WHERE `Table` = 'AV-ResNamen'")->fetch_all(MYSQLI_ASSOC);
    foreach ($remoteTriggers as $trigger) {
        echo "Remote: {$trigger['Trigger']} ({$trigger['Event']} {$trigger['Timing']})\n";
    }
    echo "\n";
    
    // 3. Test Remote-Update mit expliziter Queue-Prüfung
    echo "3️⃣ Testing Remote Update with Queue Check...\n";
    $recordId = 6895;
    
    // Queue vor Update prüfen
    $queueBefore = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE record_id = $recordId")->fetch_assoc();
    echo "Queue entries before: {$queueBefore['count']}\n";
    
    // Remote UPDATE
    $updateResult = $sync->remoteDb->query("UPDATE `AV-ResNamen` SET bem = 'Remote Trigger Test " . date('H:i:s') . "' WHERE id = $recordId");
    echo "Remote UPDATE result: " . ($updateResult ? "SUCCESS" : "FAILED") . "\n";
    
    if (!$updateResult) {
        echo "MySQL Error: " . $sync->remoteDb->error . "\n";
    }
    
    // Queue nach Update prüfen
    $queueAfter = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE record_id = $recordId")->fetch_assoc();
    echo "Queue entries after: {$queueAfter['count']}\n";
    
    // Neuester Queue-Eintrag zeigen
    $latestQueue = $sync->remoteDb->query("SELECT * FROM sync_queue_remote WHERE record_id = $recordId ORDER BY created_at DESC LIMIT 1")->fetch_assoc();
    if ($latestQueue) {
        echo "Latest queue entry: ID {$latestQueue['id']}, Operation: {$latestQueue['operation']}, Created: {$latestQueue['created_at']}\n";
    } else {
        echo "No queue entry found!\n";
    }
    echo "\n";
    
    // 4. Test @sync_in_progress Variable auf Remote
    echo "4️⃣ Testing @sync_in_progress on Remote...\n";
    
    // Variable prüfen
    $varCheck = $sync->remoteDb->query("SELECT @sync_in_progress as flag")->fetch_assoc();
    echo "Current @sync_in_progress value: " . ($varCheck['flag'] ?? 'NULL') . "\n";
    
    // Variable setzen und testen
    $sync->remoteDb->query("SET @sync_in_progress = 1");
    $recordId2 = 6896;
    
    $queueBefore2 = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE record_id = $recordId2")->fetch_assoc();
    echo "Queue entries before protected update: {$queueBefore2['count']}\n";
    
    $protectedUpdate = $sync->remoteDb->query("UPDATE `AV-ResNamen` SET bem = 'Protected Remote Test' WHERE id = $recordId2");
    echo "Protected remote UPDATE: " . ($protectedUpdate ? "SUCCESS" : "FAILED") . "\n";
    
    $queueAfter2 = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE record_id = $recordId2")->fetch_assoc();
    echo "Queue entries after protected update: {$queueAfter2['count']} (should be same as before)\n";
    
    // Variable zurücksetzen
    $sync->remoteDb->query("SET @sync_in_progress = NULL");
    echo "Reset @sync_in_progress to NULL\n";
    
} catch (Exception $e) {
    echo "❌ DIAGNOSTIC ERROR: " . $e->getMessage() . "\n";
}
?>
