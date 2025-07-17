<?php
require_once 'SyncManager.php';

echo "🔍 === COLUMN ANALYSIS === 🔍\n\n";

try {
    $sync = new SyncManager();
    
    // Prüfe Local Spalten-Definitionen
    echo "Local AV-ResNamen columns:\n";
    $localCols = $sync->localDb->query("SHOW COLUMNS FROM `AV-ResNamen`")->fetch_all(MYSQLI_ASSOC);
    foreach ($localCols as $col) {
        if (in_array($col['Field'], ['sync_source', 'sync_timestamp', 'sync_hash', 'sync_version'])) {
            echo "  {$col['Field']}: {$col['Type']} - {$col['Null']} - Default: {$col['Default']}\n";
        }
    }
    
    echo "\nRemote AV-ResNamen columns:\n";
    $remoteCols = $sync->remoteDb->query("SHOW COLUMNS FROM `AV-ResNamen`")->fetch_all(MYSQLI_ASSOC);
    foreach ($remoteCols as $col) {
        if (in_array($col['Field'], ['sync_source', 'sync_timestamp', 'sync_hash', 'sync_version'])) {
            echo "  {$col['Field']}: {$col['Type']} - {$col['Null']} - Default: {$col['Default']}\n";
        }
    }
    
    // Simple Migration ohne sync_source
    echo "\n🚀 === SIMPLE MIGRATION (without sync metadata) === 🚀\n\n";
    
    // Sync deaktivieren
    $sync->localDb->query("SET @sync_in_progress = 1");
    $sync->remoteDb->query("SET @sync_in_progress = 1");
    echo "✅ Sync disabled\n";
    
    // Fehlende IDs finden
    $remoteIds = $sync->remoteDb->query("SELECT id FROM `AV-ResNamen` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $localIds = $sync->localDb->query("SELECT id FROM `AV-ResNamen` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    
    $remoteIdList = array_column($remoteIds, 'id');
    $localIdList = array_column($localIds, 'id');
    $missingIds = array_diff($remoteIdList, $localIdList);
    
    echo "Missing IDs: " . count($missingIds) . "\n";
    
    if (!empty($missingIds)) {
        // Nur Basis-Spalten ohne sync_* metadata
        $basicColumns = ['id', 'av_id', 'vorname', 'nachname', 'gebdat', 'ageGrp', 'herkunft', 'bem', 'guide', 'arr', 'diet', 'dietInfo', 'transport', 'checked_in', 'checked_out', 'HasCard', 'CardName', 'autoinsert', 'ts'];
        
        $migrated = 0;
        $batchSize = 10; // Kleinere Batches für bessere Kontrolle
        
        foreach (array_chunk($missingIds, $batchSize) as $batch) {
            $idList = implode(',', $batch);
            echo "Processing batch: " . implode(', ', $batch) . "\n";
            
            // Hole Remote-Daten
            $remoteData = $sync->remoteDb->query("SELECT * FROM `AV-ResNamen` WHERE id IN ($idList)")->fetch_all(MYSQLI_ASSOC);
            
            foreach ($remoteData as $record) {
                try {
                    // Baue INSERT ohne sync_* Spalten
                    $columnList = '`' . implode('`, `', $basicColumns) . '`';
                    $placeholders = str_repeat('?,', count($basicColumns) - 1) . '?';
                    
                    $sql = "INSERT INTO `AV-ResNamen` ($columnList) VALUES ($placeholders)";
                    $stmt = $sync->localDb->prepare($sql);
                    
                    $values = [];
                    foreach ($basicColumns as $col) {
                        $values[] = $record[$col] ?? null;
                    }
                    
                    $types = str_repeat('s', count($values));
                    $stmt->bind_param($types, ...$values);
                    
                    if ($stmt->execute()) {
                        $migrated++;
                        echo "  ✅ ID {$record['id']}\n";
                    } else {
                        echo "  ❌ ID {$record['id']}: " . $stmt->error . "\n";
                    }
                    $stmt->close();
                    
                } catch (Exception $e) {
                    echo "  ❌ ID {$record['id']}: " . $e->getMessage() . "\n";
                }
            }
            
            if ($migrated >= 20) break; // Test limit
        }
        
        echo "\nMigrated: $migrated records\n";
    }
    
    // Sync wieder aktivieren
    $sync->localDb->query("SET @sync_in_progress = NULL");
    $sync->remoteDb->query("SET @sync_in_progress = NULL");
    echo "✅ Sync re-enabled\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
