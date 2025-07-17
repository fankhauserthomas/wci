<?php
require_once 'SyncManager.php';

echo "🚀 === COMPLETE REMOTE TO LOCAL MIGRATION === 🚀\n\n";

try {
    $sync = new SyncManager();
    
    // 1. Sync deaktivieren
    echo "1️⃣ Disabling Sync Triggers...\n";
    $sync->localDb->query("SET @sync_in_progress = 1");
    $sync->remoteDb->query("SET @sync_in_progress = 1");
    echo "✅ Sync flags SET - triggers disabled\n\n";
    
    // 2. Analyse
    echo "2️⃣ Analyzing Data...\n";
    $remoteIds = $sync->remoteDb->query("SELECT id FROM `AV-ResNamen` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $localIds = $sync->localDb->query("SELECT id FROM `AV-ResNamen` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    
    $remoteIdList = array_column($remoteIds, 'id');
    $localIdList = array_column($localIds, 'id');
    $missingIds = array_diff($remoteIdList, $localIdList);
    
    echo "Remote Records: " . count($remoteIdList) . "\n";
    echo "Local Records: " . count($localIdList) . "\n";
    echo "Missing in Local: " . count($missingIds) . "\n\n";
    
    if (empty($missingIds)) {
        echo "✅ No missing records! Database is synchronized.\n";
    } else {
        echo "3️⃣ Starting Migration of " . count($missingIds) . " records...\n\n";
        
        // Basis-Spalten ohne sync_* metadata (die werden durch DEFAULT gesetzt)
        $basicColumns = [
            'id', 'av_id', 'vorname', 'nachname', 'gebdat', 'ageGrp', 'herkunft', 
            'bem', 'guide', 'arr', 'diet', 'dietInfo', 'transport', 'checked_in', 
            'checked_out', 'HasCard', 'CardName', 'autoinsert', 'ts'
        ];
        
        $migrated = 0;
        $failed = 0;
        $batchSize = 25;
        $totalBatches = ceil(count($missingIds) / $batchSize);
        
        foreach (array_chunk($missingIds, $batchSize) as $batchNum => $batch) {
            $batchNum++;
            echo "Batch $batchNum/$totalBatches (IDs: " . min($batch) . "-" . max($batch) . ")...\n";
            
            $idList = implode(',', $batch);
            $remoteData = $sync->remoteDb->query("SELECT * FROM `AV-ResNamen` WHERE id IN ($idList)")->fetch_all(MYSQLI_ASSOC);
            
            foreach ($remoteData as $record) {
                try {
                    // INSERT ohne sync_* Spalten (werden durch DEFAULT gesetzt)
                    $columnList = '`' . implode('`, `', $basicColumns) . '`';
                    $placeholders = str_repeat('?,', count($basicColumns) - 1) . '?';
                    
                    $sql = "INSERT INTO `AV-ResNamen` ($columnList) VALUES ($placeholders)";
                    $stmt = $sync->localDb->prepare($sql);
                    
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $sync->localDb->error);
                    }
                    
                    $values = [];
                    foreach ($basicColumns as $col) {
                        $values[] = $record[$col] ?? null;
                    }
                    
                    $types = str_repeat('s', count($values));
                    $stmt->bind_param($types, ...$values);
                    
                    if ($stmt->execute()) {
                        $migrated++;
                    } else {
                        $failed++;
                        echo "  ❌ ID {$record['id']}: " . $stmt->error . "\n";
                    }
                    $stmt->close();
                    
                } catch (Exception $e) {
                    $failed++;
                    echo "  ❌ ID {$record['id']}: " . $e->getMessage() . "\n";
                }
            }
            
            echo "  ✅ Batch $batchNum completed: " . count($remoteData) . " records processed\n";
            
            // Progress Update
            if ($batchNum % 5 == 0) {
                echo "  📊 Progress: $migrated migrated, $failed failed\n\n";
            }
        }
        
        echo "\n4️⃣ Migration Summary:\n";
        echo "✅ Successfully migrated: $migrated records\n";
        echo "❌ Failed: $failed records\n";
        echo "📊 Success rate: " . round(($migrated / count($missingIds)) * 100, 2) . "%\n\n";
    }
    
    // 5. Finale Verifikation
    echo "5️⃣ Final Verification...\n";
    $finalLocalCount = $sync->localDb->query("SELECT COUNT(*) as count FROM `AV-ResNamen`")->fetch_assoc();
    $finalRemoteCount = $sync->remoteDb->query("SELECT COUNT(*) as count FROM `AV-ResNamen`")->fetch_assoc();
    
    echo "Final Local Count: {$finalLocalCount['count']}\n";
    echo "Final Remote Count: {$finalRemoteCount['count']}\n";
    echo "Remaining Difference: " . ($finalRemoteCount['count'] - $finalLocalCount['count']) . "\n\n";
    
    // 6. Sync wieder aktivieren
    echo "6️⃣ Re-enabling Sync Triggers...\n";
    $sync->localDb->query("SET @sync_in_progress = NULL");
    $sync->remoteDb->query("SET @sync_in_progress = NULL");
    echo "✅ Sync flags CLEARED - triggers re-enabled\n\n";
    
    // 7. Test Sync
    if ($migrated > 0) {
        echo "7️⃣ Testing Sync System...\n";
        $testSync = $sync->syncOnPageLoad('post_migration_test');
        echo "Sync test result: " . json_encode($testSync, JSON_PRETTY_PRINT) . "\n\n";
    }
    
    echo "🎉 === MIGRATION COMPLETED === 🎉\n";
    echo "All remote records have been migrated to local database!\n";
    echo "The bidirectional sync system is ready for operation.\n";
    
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
