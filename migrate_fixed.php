<?php
require_once 'SyncManager.php';

echo "🔄 === FIXED REMOTE TO LOCAL MIGRATION === 🔄\n\n";

try {
    $sync = new SyncManager();
    
    // 1. Sync temporär deaktivieren
    echo "1️⃣ Disabling Sync Triggers...\n";
    $sync->localDb->query("SET @sync_in_progress = 1");
    $sync->remoteDb->query("SET @sync_in_progress = 1");
    echo "✅ Sync flags SET - triggers disabled\n\n";
    
    // 2. Analysiere Remote vs Local
    echo "2️⃣ Analyzing Remote vs Local Data...\n";
    
    // Hole alle Remote IDs
    $remoteIds = $sync->remoteDb->query("SELECT id FROM `AV-ResNamen` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $remoteIdList = array_column($remoteIds, 'id');
    echo "Remote Records: " . count($remoteIdList) . "\n";
    
    // Hole alle Local IDs
    $localIds = $sync->localDb->query("SELECT id FROM `AV-ResNamen` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $localIdList = array_column($localIds, 'id');
    echo "Local Records: " . count($localIdList) . "\n";
    
    // Finde fehlende IDs
    $missingIds = array_diff($remoteIdList, $localIdList);
    echo "Missing in Local: " . count($missingIds) . "\n\n";
    
    if (empty($missingIds)) {
        echo "✅ No missing records found! Local database is up to date.\n";
    } else {
        echo "3️⃣ Missing Record IDs: " . implode(', ', array_slice($missingIds, 0, 20)) . (count($missingIds) > 20 ? '...' : '') . "\n\n";
        
        // 4. Spalten-Mapping vorbereiten (FIXED)
        echo "4️⃣ Preparing Column Mapping...\n";
        $remoteColumns = [];
        $result = $sync->remoteDb->query("SHOW COLUMNS FROM `AV-ResNamen`");
        while ($row = $result->fetch_assoc()) {
            $remoteColumns[] = $row['Field'];
        }
        
        $localColumns = [];
        $result = $sync->localDb->query("SHOW COLUMNS FROM `AV-ResNamen`");
        while ($row = $result->fetch_assoc()) {
            $localColumns[] = $row['Field'];
        }
        
        // Nur Spalten die in BEIDEN existieren, ohne sync_timestamp und sync_source
        $commonColumns = array_intersect($remoteColumns, $localColumns);
        $commonColumns = array_filter($commonColumns, function($col) {
            return !in_array($col, ['sync_timestamp', 'sync_source']);
        });
        
        echo "Common Columns: " . implode(', ', $commonColumns) . "\n\n";
        
        // 5. Migration durchführen
        echo "5️⃣ Starting Migration...\n";
        $migrated = 0;
        $failed = 0;
        $batchSize = 50;
        $totalBatches = ceil(count($missingIds) / $batchSize);
        
        for ($batch = 0; $batch < $totalBatches; $batch++) {
            $batchIds = array_slice($missingIds, $batch * $batchSize, $batchSize);
            $idList = implode(',', $batchIds);
            
            echo "Batch " . ($batch + 1) . "/$totalBatches (IDs: " . min($batchIds) . "-" . max($batchIds) . ")...\n";
            
            // Hole Remote-Daten für diesen Batch
            $stmt = $sync->remoteDb->prepare("SELECT * FROM `AV-ResNamen` WHERE id IN ($idList)");
            $stmt->execute();
            $remoteRecords = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            foreach ($remoteRecords as $record) {
                try {
                    // INSERT Query vorbereiten - FIXED VERSION
                    $columnList = '`' . implode('`, `', $commonColumns) . '`, `sync_timestamp`, `sync_source`';
                    $placeholders = str_repeat('?,', count($commonColumns)) . 'NOW(), "migration"';
                    
                    $sql = "INSERT INTO `AV-ResNamen` ($columnList) VALUES ($placeholders)";
                    $stmt = $sync->localDb->prepare($sql);
                    
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $sync->localDb->error);
                    }
                    
                    // Parameter sammeln
                    $values = [];
                    $types = '';
                    foreach ($commonColumns as $col) {
                        $values[] = $record[$col] ?? null;
                        $types .= 's';
                    }
                    
                    if (!empty($values)) {
                        $stmt->bind_param($types, ...$values);
                    }
                    
                    $result = $stmt->execute();
                    if (!$result) {
                        throw new Exception("Execute failed: " . $stmt->error);
                    }
                    
                    $stmt->close();
                    $migrated++;
                    
                    if ($migrated % 25 == 0) {
                        echo "  ✅ Migrated: $migrated records\n";
                    }
                    
                } catch (Exception $e) {
                    $failed++;
                    echo "  ❌ Failed ID {$record['id']}: " . $e->getMessage() . "\n";
                    
                    // Stop bei zu vielen Fehlern
                    if ($failed > 10) {
                        echo "  ⚠️ Too many failures, stopping migration\n";
                        break 2;
                    }
                }
            }
        }
        
        echo "\n6️⃣ Migration Summary:\n";
        echo "✅ Successfully migrated: $migrated records\n";
        echo "❌ Failed: $failed records\n";
        echo "📊 Success rate: " . round(($migrated / count($missingIds)) * 100, 2) . "%\n\n";
    }
    
    // 7. Finale Verifikation
    echo "7️⃣ Final Verification...\n";
    $finalLocalCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-ResNamen`")->fetch_assoc();
    $finalRemoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-ResNamen`")->fetch_assoc();
    
    echo "Final Local Count: {$finalLocalCount['count']}\n";
    echo "Final Remote Count: {$finalRemoteCount['count']}\n";
    echo "Difference: " . ($finalRemoteCount['count'] - $finalLocalCount['count']) . "\n\n";
    
    // 8. Sync wieder aktivieren
    echo "8️⃣ Re-enabling Sync Triggers...\n";
    $sync->localDb->query("SET @sync_in_progress = NULL");
    $sync->remoteDb->query("SET @sync_in_progress = NULL");
    echo "✅ Sync flags CLEARED - triggers re-enabled\n\n";
    
    echo "🎉 === MIGRATION COMPLETED === 🎉\n";
    echo "Ready for normal sync operations!\n";
    
} catch (Exception $e) {
    echo "❌ MIGRATION ERROR: " . $e->getMessage() . "\n";
    
    // Sicherheitshalber Sync wieder aktivieren
    try {
        if (isset($sync)) {
            $sync->localDb->query("SET @sync_in_progress = NULL");
            $sync->remoteDb->query("SET @sync_in_progress = NULL");
            echo "🛡️ Sync flags reset for safety\n";
        }
    } catch (Exception $cleanupError) {
        echo "⚠️ Could not reset sync flags: " . $cleanupError->getMessage() . "\n";
    }
}
?>
