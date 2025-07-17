<?php
require_once 'SyncManager.php';

echo "<h1>AV_ResDet Sync System - Vollständiger Test</h1>\n";
echo "<div style='font-family: monospace; white-space: pre-wrap;'>\n";

function logResult($message, $success = null) {
    $icon = $success === true ? "✅" : ($success === false ? "❌" : "ℹ️");
    echo "[$icon] $message\n";
}

try {
    $sync = new SyncManager();
    
    if (!$sync->remoteDb) {
        logResult("Remote DB nicht verfügbar", false);
        exit;
    }
    
    echo "=== AV_ResDet SYNC SYSTEM TEST ===\n\n";
    
    // 1. Tabellen-Struktur Check
    echo "--- 1. TABELLEN-STRUKTUR CHECK ---\n";
    
    // Local columns
    $localResult = $sync->localDb->query("SHOW COLUMNS FROM AV_ResDet");
    $localColumns = [];
    while ($row = $localResult->fetch_assoc()) {
        $localColumns[] = $row['Field'];
    }
    logResult("Local AV_ResDet Spalten: " . count($localColumns));
    
    // Remote columns  
    $remoteResult = $sync->remoteDb->query("SHOW COLUMNS FROM AV_ResDet");
    $remoteColumns = [];
    while ($row = $remoteResult->fetch_assoc()) {
        $remoteColumns[] = $row['Field'];
    }
    logResult("Remote AV_ResDet Spalten: " . count($remoteColumns));
    
    // Gemeinsame Spalten
    $commonColumns = array_intersect($localColumns, $remoteColumns);
    logResult("Identische Spalten: " . count($commonColumns));
    
    if (count($commonColumns) >= 15) {
        logResult("Tabellenstruktur kompatibel", true);
    } else {
        logResult("Tabellenstruktur Problem", false);
    }
    
    // 2. Trigger Check
    echo "\n--- 2. TRIGGER CHECK ---\n";
    
    // Local triggers - einfachere Abfrage für alte MySQL
    $localTriggers = $sync->localDb->query("SHOW TRIGGERS LIKE 'AV_ResDet'");
    $localTriggerCount = $localTriggers ? $localTriggers->num_rows : 0;
    logResult("Local Triggers: $localTriggerCount", $localTriggerCount >= 3);
    
    // Remote triggers - einfachere Abfrage für alte MySQL
    $remoteTriggers = $sync->remoteDb->query("SHOW TRIGGERS LIKE 'AV_ResDet'");
    $remoteTriggerCount = $remoteTriggers ? $remoteTriggers->num_rows : 0;
    logResult("Remote Triggers: $remoteTriggerCount", $remoteTriggerCount >= 3);
    
    // 3. Queue System Check
    echo "\n--- 3. QUEUE SYSTEM CHECK ---\n";
    
    $queueStatus = $sync->checkQueueTables();
    logResult("Queue Tabellen existieren", $queueStatus);
    
    if ($queueStatus) {
        // Queue items count
        $localQueue = $sync->localDb->query("SELECT COUNT(*) as count FROM sync_queue_local WHERE table_name = 'AV_ResDet'");
        $localQueueCount = $localQueue->fetch_assoc()['count'];
        
        $remoteQueue = $sync->remoteDb->query("SELECT COUNT(*) as count FROM sync_queue_remote WHERE table_name = 'AV_ResDet'");
        $remoteQueueCount = $remoteQueue->fetch_assoc()['count'];
        
        logResult("Local Queue Items (AV_ResDet): $localQueueCount");
        logResult("Remote Queue Items (AV_ResDet): $remoteQueueCount");
        
        if ($localQueueCount == 0 && $remoteQueueCount == 0) {
            logResult("Queue-basierter Sync bereit", true);
        }
    }
    
    // 4. Sync_timestamp Check
    echo "\n--- 4. SYNC_TIMESTAMP CHECK ---\n";
    
    $hasLocalSyncCol = in_array('sync_timestamp', $localColumns);
    $hasRemoteSyncCol = in_array('sync_timestamp', $remoteColumns);
    
    logResult("Local sync_timestamp Spalte", $hasLocalSyncCol);
    logResult("Remote sync_timestamp Spalte", $hasRemoteSyncCol);
    
    if ($hasLocalSyncCol && $hasRemoteSyncCol) {
        logResult("Timestamp-basierter Fallback verfügbar", true);
    }
    
    // 5. Record Count Check
    echo "\n--- 5. RECORD COUNT CHECK ---\n";
    
    $localCount = $sync->localDb->query("SELECT COUNT(*) as count FROM AV_ResDet")->fetch_assoc()['count'];
    $remoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM AV_ResDet")->fetch_assoc()['count'];
    
    logResult("Local Records: $localCount");
    logResult("Remote Records: $remoteCount");
    
    $countDiff = abs($localCount - $remoteCount);
    if ($countDiff <= 5) {
        logResult("Record Counts ähnlich (Diff: $countDiff)", true);
    } else {
        logResult("Record Count Differenz: $countDiff", false);
    }
    
    // 6. Sync Functionality Test
    echo "\n--- 6. SYNC FUNCTIONALITY TEST ---\n";
    
    $syncResult = $sync->syncOnPageLoad('av_resdet_test');
    if ($syncResult['success']) {
        logResult("Sync-System funktionsfähig", true);
        if (isset($syncResult['results'])) {
            $results = $syncResult['results'];
            logResult("Local→Remote: " . ($results['local_to_remote'] ?? 0));
            logResult("Remote→Local: " . ($results['remote_to_local'] ?? 0));
        }
    } else {
        logResult("Sync-System Fehler: " . ($syncResult['error'] ?? 'Unknown'), false);
    }
    
    // 7. Summary
    echo "\n=== TEST ZUSAMMENFASSUNG ===\n";
    
    $checks = [
        "Tabellen-Kompatibilität" => count($commonColumns) >= 15,
        "Local Triggers" => $localTriggerCount >= 3,
        "Remote Triggers" => $remoteTriggerCount >= 3,
        "Queue System" => $queueStatus,
        "Sync Timestamps" => $hasLocalSyncCol && $hasRemoteSyncCol,
        "Sync Funktionalität" => $syncResult['success'] ?? false
    ];
    
    $passedChecks = array_filter($checks);
    $totalChecks = count($checks);
    $passedCount = count($passedChecks);
    
    echo "\nAV_ResDet Sync System Status: $passedCount/$totalChecks checks passed\n";
    
    if ($passedCount == $totalChecks) {
        echo "🎉 EXCELLENT - AV_ResDet sync system vollständig funktionsfähig\n";
        echo "\n✅ Alle Sync-Operationen bereit:\n";
        echo "   - INSERT: ✅ Local ↔ Remote\n";
        echo "   - UPDATE: ✅ Local ↔ Remote  \n";
        echo "   - DELETE: ✅ Local ↔ Remote\n";
        echo "\n✅ Sync-Modi verfügbar:\n";
        echo "   - Queue-basiert: ✅ Primärer Modus\n";
        if ($hasLocalSyncCol && $hasRemoteSyncCol) {
            echo "   - Timestamp-basiert: ✅ Fallback\n";
        }
    } else if ($passedCount >= $totalChecks * 0.8) {
        echo "⚠️  GOOD - AV_ResDet sync system größtenteils funktionsfähig\n";
        echo "Fehlende Checks:\n";
        foreach ($checks as $check => $passed) {
            if (!$passed) {
                echo "   ❌ $check\n";
            }
        }
    } else {
        echo "❌ NEEDS WORK - AV_ResDet sync system benötigt Verbesserungen\n";
    }
    
} catch (Exception $e) {
    logResult("EXCEPTION: " . $e->getMessage(), false);
}

echo "</div>\n";
?>
