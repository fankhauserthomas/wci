<?php
// Cron-Job Script für automatischen Sync
// Verwendung: */15 * * * * /usr/bin/php /home/vadmin/lemp/html/wci/sync-cron.php >> /var/log/sync.log 2>&1

require_once 'SyncManager.php';

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starte automatischen Sync...\n";
    
    $syncManager = new SyncManager();
    
    // Force Sync der letzten 1 Stunde für AV-ResNamen
    $result = $syncManager->forceSyncLatest(1, 'AV-ResNamen');
    
    if ($result['success']) {
        echo "[" . date('Y-m-d H:i:s') . "] AV-ResNamen Sync erfolgreich: " . json_encode($result['results']) . "\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] AV-ResNamen Sync Fehler: " . $result['error'] . "\n";
    }
    
    // Optional: Andere Tabellen syncen wenn sie in syncTables aktiviert sind
    $reflectionClass = new ReflectionClass($syncManager);
    $syncTablesProperty = $reflectionClass->getProperty('syncTables');
    $syncTablesProperty->setAccessible(true);
    $syncTables = $syncTablesProperty->getValue($syncManager);
    
    foreach (['AV-Res', 'AV_ResDet', 'zp_zimmer'] as $table) {
        if (in_array($table, $syncTables)) {
            $tableResult = $syncManager->forceSyncLatest(1, $table);
            if ($tableResult['success']) {
                echo "[" . date('Y-m-d H:i:s') . "] $table Sync erfolgreich: " . json_encode($tableResult['results']) . "\n";
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] $table Sync Fehler: " . $tableResult['error'] . "\n";
            }
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Automatischer Sync abgeschlossen\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] KRITISCHER FEHLER: " . $e->getMessage() . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Stack Trace: " . $e->getTraceAsString() . "\n";
}
?>
