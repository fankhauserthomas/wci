<?php
/**
 * sync-cronjob.php - Echtes Sync-Script für automatische Cronjob-Ausführung
 * 
 * Dieses Script führt tatsächliche Synchronisation durch (nicht nur Statistik-Anzeige)
 * und ist für die Ausführung via Cronjob optimiert.
 */

// Keine HTML-Ausgabe für Cronjob
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/SyncManager.php';

$logPrefix = "[" . date('Y-m-d H:i:s') . "] [CRONJOB]";

try {
    echo "$logPrefix Starte automatischen Sync-Cronjob\n";
    
    // SyncManager initialisieren
    $sync = new SyncManager();
    
    // Führe tatsächlichen Sync durch
    $result = $sync->syncOnPageLoad('cronjob_auto');
    
    if ($result && isset($result['success']) && $result['success']) {
        $localToRemote = isset($result['results']['local_to_remote']) ? 
            (is_array($result['results']['local_to_remote']) ? count($result['results']['local_to_remote']) : $result['results']['local_to_remote']) : 0;
        $remoteToLocal = isset($result['results']['remote_to_local']) ? 
            (is_array($result['results']['remote_to_local']) ? count($result['results']['remote_to_local']) : $result['results']['remote_to_local']) : 0;
        $totalSynced = $localToRemote + $remoteToLocal;
        
        echo "$logPrefix Sync erfolgreich abgeschlossen\n";
        echo "$logPrefix Records Local→Remote: $localToRemote\n";
        echo "$logPrefix Records Remote→Local: $remoteToLocal\n";
        echo "$logPrefix Gesamt synchronisiert: $totalSynced\n";
        
        if ($totalSynced > 0) {
            echo "$logPrefix ✅ Daten wurden synchronisiert\n";
        } else {
            echo "$logPrefix ℹ️ Keine neuen Daten zum Synchronisieren\n";
        }
        
    } else {
        $errorMsg = $result['error'] ?? 'Unbekannter Fehler';
        echo "$logPrefix ❌ Sync fehlgeschlagen: $errorMsg\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "$logPrefix ❌ Exception: " . $e->getMessage() . "\n";
    echo "$logPrefix Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "$logPrefix Cronjob beendet\n";
exit(0);
?>
