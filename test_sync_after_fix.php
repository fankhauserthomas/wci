<?php
require_once 'SyncManager.php';

echo "=== SYNC TEST NACH TRIGGER-FIX ===\n\n";

try {
    $sync = new SyncManager();
    
    if (!$sync->remoteDb) {
        echo "❌ Remote DB nicht verfügbar\n";
        exit;
    }
    
    echo "✅ SyncManager initialisiert\n\n";
    
    // Test Queue-based Sync
    echo "--- QUEUE-BASED SYNC TEST ---\n";
    $result = $sync->syncOnPageLoad('definer_fix_test');
    
    if ($result['success']) {
        echo "✅ Sync erfolgreich!\n";
        print_r($result['results']);
    } else {
        echo "❌ Sync fehlgeschlagen: " . $result['error'] . "\n";
    }
    
    echo "\n--- TIMESTAMP-BASED SYNC TEST (Fallback) ---\n";
    
    // Test mit einem kleinen Zeitfenster
    $result = $sync->forceSyncLatest(1, 'AV-Res');
    
    if ($result['success']) {
        echo "✅ Timestamp-Sync erfolgreich!\n";
        print_r($result['results']);
    } else {
        echo "❌ Timestamp-Sync fehlgeschlagen: " . $result['error'] . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ EXCEPTION: " . $e->getMessage() . "\n";
}

echo "\n=== TEST ABGESCHLOSSEN ===\n";
?>
