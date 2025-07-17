<?php
require_once 'SyncManager.php';

echo "=== Multi-Table Sync System Test ===\n";

try {
    $sync = new SyncManager();
    
    echo "\n1. Queue Tables Check:\n";
    $queueExists = $sync->queueTablesExist();
    echo "Queue tables exist: " . ($queueExists ? "✓ YES" : "✗ NO") . "\n";
    
    echo "\n2. Testing Multi-Table Sync:\n";
    $result = $sync->syncOnPageLoad('multi_table_test');
    
    if ($result['success']) {
        echo "✓ Sync successful!\n";
        if (isset($result['results'])) {
            echo "Results:\n";
            foreach ($result['results'] as $key => $value) {
                echo "  - $key: $value\n";
            }
        }
    } else {
        echo "✗ Sync failed: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
    
    echo "\n3. Testing Force Sync for each table:\n";
    $tables = ['AV-ResNamen', 'AV-Res', 'AV_ResDet', 'zp_zimmer'];
    
    foreach ($tables as $table) {
        echo "\nTesting $table:\n";
        $result = $sync->forceSyncLatest(24, $table); // 24 Stunden
        
        if ($result['success']) {
            echo "✓ $table sync successful: " . $result['results']['remote_to_local'] . " records\n";
        } else {
            echo "✗ $table sync failed: " . ($result['error'] ?? 'Unknown error') . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>
