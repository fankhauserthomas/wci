<?php
// test_sync_fields.php - Analysiert welche Felder beim Sync verwendet werden

require 'SyncManager.php';

echo "=== SYNC FIELD ANALYSIS ===\n\n";

try {
    $sync = new SyncManager();
    
    // Prüfe Tabellenstrukturen
    $tables = ['AV-ResNamen', 'AV-Res', 'AV_ResDet', 'zp_zimmer'];
    
    foreach ($tables as $table) {
        echo "--- $table STRUCTURE ---\n";
        
        // Lokale Struktur
        $local_result = $sync->localDb->query("DESCRIBE `$table`");
        $local_fields = [];
        if ($local_result) {
            while ($row = $local_result->fetch_assoc()) {
                $local_fields[] = $row['Field'];
            }
        }
        
        // Remote Struktur  
        $remote_fields = [];
        if ($sync->remoteDb) {
            $remote_result = $sync->remoteDb->query("DESCRIBE `$table`");
            if ($remote_result) {
                while ($row = $remote_result->fetch_assoc()) {
                    $remote_fields[] = $row['Field'];
                }
            }
        }
        
        echo "Local fields: " . implode(', ', $local_fields) . "\n";
        echo "Remote fields: " . implode(', ', $remote_fields) . "\n";
        
        // Unterschiede finden
        $missing_remote = array_diff($local_fields, $remote_fields);
        $missing_local = array_diff($remote_fields, $local_fields);
        
        if (!empty($missing_remote)) {
            echo "❌ MISSING IN REMOTE: " . implode(', ', $missing_remote) . "\n";
        }
        if (!empty($missing_local)) {
            echo "❌ MISSING IN LOCAL: " . implode(', ', $missing_local) . "\n";
        }
        if (empty($missing_remote) && empty($missing_local)) {
            echo "✅ STRUCTURES MATCH\n";
        }
        
        // Prüfe was beim Sync tatsächlich verwendet wird
        echo "\n--- SYNC BEHAVIOR CHECK ---\n";
        
        // Teste einen einzelnen Record-Sync
        $test_query = $sync->localDb->query("SELECT * FROM `$table` LIMIT 1");
        if ($test_query && $test_record = $test_query->fetch_assoc()) {
            echo "Test record ID: " . $test_record['id'] . "\n";
            echo "Available fields in record: " . implode(', ', array_keys($test_record)) . "\n";
            
            // Prüfe welche Felder beim UPDATE verwendet würden
            $sync_fields = array_filter(array_keys($test_record), function($field) {
                return !in_array($field, ['id', 'sync_timestamp', 'sync_hash', 'sync_source', 'sync_version']);
            });
            echo "Fields that would be synced: " . implode(', ', $sync_fields) . "\n";
            
            // Speziell für AV_ResDet: zeige alle Feldwerte
            if ($table == 'AV_ResDet') {
                echo "\n--- AV_ResDet DETAILED ANALYSIS ---\n";
                foreach ($test_record as $field => $value) {
                    if (!in_array($field, ['id'])) {
                        echo "$field: " . var_export($value, true) . "\n";
                    }
                }
            }
        }
        
        echo "\n" . str_repeat("=", 50) . "\n\n";
    }
    
    // Prüfe SyncManager Code direkt
    echo "--- SYNCMANAGER CODE ANALYSIS ---\n";
    
    // Lese SyncManager.php Datei um zu sehen wie UPDATE-Queries erstellt werden
    $syncManagerContent = file_get_contents('SyncManager.php');
    
    // Suche nach UPDATE-Statements
    if (preg_match_all('/UPDATE.*?`([^`]+)`.*?SET\s+(.*?)\s+WHERE/s', $syncManagerContent, $matches)) {
        echo "Found UPDATE patterns:\n";
        for ($i = 0; $i < count($matches[0]); $i++) {
            echo "Table: " . $matches[1][$i] . "\n";
            echo "SET clause: " . trim($matches[2][$i]) . "\n\n";
        }
    }
    
    // Suche nach syncRecord Methode
    if (preg_match('/function\s+syncRecord.*?\{(.*?)\n\s*\}/s', $syncManagerContent, $match)) {
        echo "syncRecord method found - analyzing field handling...\n";
        // Prüfe ob alle Felder verwendet werden
        if (strpos($match[1], 'array_keys') !== false) {
            echo "✅ Uses array_keys() - should sync all fields\n";
        } else {
            echo "❌ May not sync all fields - hardcoded field list?\n";
        }
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
