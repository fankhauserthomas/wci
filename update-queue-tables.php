<?php
// Script um error_message Spalte zu den Queue-Tabellen hinzuzufügen
require_once 'SyncManager.php';

try {
    $sync = new SyncManager();
    
    echo "<h1>🔧 Queue-Tabellen Update</h1>";
    
    // Lokale Datenbank
    echo "<h2>Lokale Datenbank:</h2>";
    
    // Prüfe ob Spalte bereits existiert
    $result = $sync->localDb->query("SHOW COLUMNS FROM sync_queue_local LIKE 'error_message'");
    if ($result->num_rows > 0) {
        echo "✅ error_message Spalte existiert bereits in sync_queue_local<br>";
    } else {
        echo "➕ Füge error_message Spalte zu sync_queue_local hinzu...<br>";
        $success = $sync->localDb->query("ALTER TABLE sync_queue_local ADD COLUMN error_message TEXT DEFAULT NULL");
        if ($success) {
            echo "✅ error_message Spalte erfolgreich hinzugefügt<br>";
        } else {
            echo "❌ Fehler beim Hinzufügen: " . $sync->localDb->error . "<br>";
        }
    }
    
    // Index hinzufügen
    $indexExists = $sync->localDb->query("SHOW INDEX FROM sync_queue_local WHERE Key_name = 'idx_sync_queue_local_status_attempts'");
    if ($indexExists->num_rows > 0) {
        echo "✅ Index für status/attempts existiert bereits<br>";
    } else {
        echo "➕ Füge Index für bessere Performance hinzu...<br>";
        $success = $sync->localDb->query("CREATE INDEX idx_sync_queue_local_status_attempts ON sync_queue_local(status, attempts)");
        if ($success) {
            echo "✅ Index erfolgreich erstellt<br>";
        } else {
            echo "❌ Fehler beim Index erstellen: " . $sync->localDb->error . "<br>";
        }
    }
    
    // Remote Datenbank (falls verfügbar)
    if ($sync->remoteDb) {
        echo "<h2>Remote Datenbank:</h2>";
        
        $result = $sync->remoteDb->query("SHOW COLUMNS FROM sync_queue_remote LIKE 'error_message'");
        if ($result->num_rows > 0) {
            echo "✅ error_message Spalte existiert bereits in sync_queue_remote<br>";
        } else {
            echo "➕ Füge error_message Spalte zu sync_queue_remote hinzu...<br>";
            $success = $sync->remoteDb->query("ALTER TABLE sync_queue_remote ADD COLUMN error_message TEXT DEFAULT NULL");
            if ($success) {
                echo "✅ error_message Spalte erfolgreich hinzugefügt<br>";
            } else {
                echo "❌ Fehler beim Hinzufügen: " . $sync->remoteDb->error . "<br>";
            }
        }
        
        $indexExists = $sync->remoteDb->query("SHOW INDEX FROM sync_queue_remote WHERE Key_name = 'idx_sync_queue_remote_status_attempts'");
        if ($indexExists->num_rows > 0) {
            echo "✅ Index für status/attempts existiert bereits<br>";
        } else {
            echo "➕ Füge Index für bessere Performance hinzu...<br>";
            $success = $sync->remoteDb->query("CREATE INDEX idx_sync_queue_remote_status_attempts ON sync_queue_remote(status, attempts)");
            if ($success) {
                echo "✅ Index erfolgreich erstellt<br>";
            } else {
                echo "❌ Fehler beim Index erstellen: " . $sync->remoteDb->error . "<br>";
            }
        }
    }
    
    echo "<br><h2>✅ Update abgeschlossen!</h2>";
    echo "<p><a href='sync-debug.php' style='background: green; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔍 Zurück zum Debug-Tool</a></p>";
    
} catch (Exception $e) {
    echo "<div style='background: red; color: white; padding: 10px; border-radius: 5px;'>";
    echo "<h2>❌ Fehler beim Update:</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>
