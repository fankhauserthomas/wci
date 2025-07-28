<?php
// Debug-Datei für das Sync-System
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Sync Queue Debug</h1>";

// SyncManager laden
require_once 'SyncManager.php';

try {
    $sync = new SyncManager();
    
    echo "<h2>1. Datenbankverbindungen</h2>";
    echo "✅ Lokale DB: " . ($sync->localDb ? "Verbunden" : "❌ Fehler") . "<br>";
    echo "✅ Remote DB: " . ($sync->remoteDb ? "Verbunden" : "❌ Fehler") . "<br>";
    
    echo "<h2>2. Queue-Tabellen Status</h2>";
    
    // Lokale Queue prüfen
    echo "<h3>📊 Lokale Queue (sync_queue_local):</h3>";
    $result = $sync->localDb->query("
        SELECT status, COUNT(*) as count, table_name, 
               AVG(attempts) as avg_attempts,
               MAX(last_attempt) as last_attempt
        FROM sync_queue_local 
        GROUP BY status, table_name
        ORDER BY table_name, status
    ");
    
    if ($result && $result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>Tabelle</th><th>Status</th><th>Anzahl</th><th>Ø Versuche</th><th>Letzter Versuch</th></tr>";
        while ($row = $result->fetch_assoc()) {
            $statusColor = $row['status'] == 'failed' ? 'red' : ($row['status'] == 'pending' ? 'orange' : 'green');
            echo "<tr>";
            echo "<td>{$row['table_name']}</td>";
            echo "<td style='color: $statusColor'><b>{$row['status']}</b></td>";
            echo "<td>{$row['count']}</td>";
            echo "<td>" . round($row['avg_attempts'], 1) . "</td>";
            echo "<td>{$row['last_attempt']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "📭 Keine Einträge in lokaler Queue<br>";
    }
    
    // Remote Queue prüfen (falls verfügbar)
    if ($sync->remoteDb) {
        echo "<h3>📊 Remote Queue (sync_queue_remote):</h3>";
        $result = $sync->remoteDb->query("
            SELECT status, COUNT(*) as count, table_name, 
                   AVG(attempts) as avg_attempts,
                   MAX(last_attempt) as last_attempt
            FROM sync_queue_remote 
            GROUP BY status, table_name
            ORDER BY table_name, status
        ");
        
        if ($result && $result->num_rows > 0) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Tabelle</th><th>Status</th><th>Anzahl</th><th>Ø Versuche</th><th>Letzter Versuch</th></tr>";
            while ($row = $result->fetch_assoc()) {
                $statusColor = $row['status'] == 'failed' ? 'red' : ($row['status'] == 'pending' ? 'orange' : 'green');
                echo "<tr>";
                echo "<td>{$row['table_name']}</td>";
                echo "<td style='color: $statusColor'><b>{$row['status']}</b></td>";
                echo "<td>{$row['count']}</td>";
                echo "<td>" . round($row['avg_attempts'], 1) . "</td>";
                echo "<td>{$row['last_attempt']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "📭 Keine Einträge in Remote Queue<br>";
        }
    }
    
    echo "<h2>3. Fehlgeschlagene Einträge Details</h2>";
    
    // Failed Items Details mit erweiterten Informationen
    $failedItems = $sync->localDb->query("
        SELECT id, record_id, table_name, operation, attempts, 
               created_at, last_attempt, old_data, error_message
        FROM sync_queue_local 
        WHERE status = 'failed'
        ORDER BY last_attempt DESC
        LIMIT 20
    ");
    
    if ($failedItems && $failedItems->num_rows > 0) {
        echo "<h3>❌ Fehlgeschlagene lokale Queue-Einträge (mit Fehlerdetails):</h3>";
        echo "<table border='1' style='border-collapse: collapse; font-size: 11px; width: 100%;'>";
        echo "<tr><th>ID</th><th>Record</th><th>Tabelle</th><th>Operation</th><th>Versuche</th><th>Letzter Versuch</th><th>Fehler</th><th>Aktion</th></tr>";
        while ($row = $failedItems->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['record_id']}</td>";
            echo "<td>{$row['table_name']}</td>";
            echo "<td style='color: red'><b>{$row['operation']}</b></td>";
            echo "<td>{$row['attempts']}</td>";
            echo "<td>" . substr($row['last_attempt'], 5, 11) . "</td>";
            echo "<td style='max-width: 200px; word-wrap: break-word; font-size: 10px;'>" . 
                 htmlspecialchars(substr($row['error_message'] ?? 'Kein Fehlerdetail', 0, 100)) . "</td>";
            echo "<td>";
            echo "<form method='post' style='display: inline; margin: 2px;'>";
            echo "<input type='hidden' name='action' value='retry_single'>";
            echo "<input type='hidden' name='queue_id' value='{$row['id']}'>";
            echo "<button type='submit' style='background: orange; color: white; padding: 2px 5px; border: none; border-radius: 3px; font-size: 10px;'>🔄 Retry</button>";
            echo "</form>";
            echo "<form method='post' style='display: inline; margin: 2px;'>";
            echo "<input type='hidden' name='action' value='debug_single'>";
            echo "<input type='hidden' name='queue_id' value='{$row['id']}'>";
            echo "<button type='submit' style='background: blue; color: white; padding: 2px 5px; border: none; border-radius: 3px; font-size: 10px;'>🔍 Debug</button>";
            echo "</form>";
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "✅ Keine fehlgeschlagenen Einträge in lokaler Queue<br>";
    }
    
    echo "<h2>4. Debug-Aktionen</h2>";
    
    // Reset Failed Button
    echo "<form method='post' style='display: inline-block; margin: 5px;'>";
    echo "<input type='hidden' name='action' value='reset_failed'>";
    echo "<button type='submit' style='background: orange; color: white; padding: 10px; border: none; border-radius: 5px;'>🔄 Failed Status zurücksetzen</button>";
    echo "</form>";
    
    // Clear Queue Button
    echo "<form method='post' style='display: inline-block; margin: 5px;'>";
    echo "<input type='hidden' name='action' value='clear_queue'>";
    echo "<button type='submit' style='background: red; color: white; padding: 10px; border: none; border-radius: 5px;'>🗑️ Queue leeren</button>";
    echo "</form>";
    
    // Manual Sync Button
    echo "<form method='post' style='display: inline-block; margin: 5px;'>";
    echo "<input type='hidden' name='action' value='manual_sync'>";
    echo "<button type='submit' style='background: green; color: white; padding: 10px; border: none; border-radius: 5px;'>🔄 Manueller Sync</button>";
    echo "</form>";
    
    // Test Single Record
    echo "<form method='post' style='display: inline-block; margin: 5px;'>";
    echo "<input type='hidden' name='action' value='test_record'>";
    echo "<input type='number' name='record_id' placeholder='Record ID' required style='padding: 5px;'>";
    echo "<select name='table_name' required style='padding: 5px;'>";
    echo "<option value='AV-ResNamen'>AV-ResNamen</option>";
    echo "<option value='AV-Res'>AV-Res</option>";
    echo "<option value='AV_ResDet'>AV_ResDet</option>";
    echo "<option value='zp_zimmer'>zp_zimmer</option>";
    echo "</select>";
    echo "<button type='submit' style='background: blue; color: white; padding: 5px; border: none; border-radius: 3px;'>🧪 Test Record</button>";
    echo "</form>";
    
    // Actions verarbeiten
    if ($_POST['action'] ?? false) {
        echo "<h2>5. Action Ergebnis</h2>";
        
        switch ($_POST['action']) {
            case 'reset_failed':
                $result = $sync->localDb->query("UPDATE sync_queue_local SET status = 'pending', attempts = 0 WHERE status = 'failed'");
                $affected = $sync->localDb->affected_rows;
                echo "<div style='background: lightgreen; padding: 10px; border-radius: 5px;'>✅ $affected fehlgeschlagene Einträge zurückgesetzt</div>";
                break;
                
            case 'retry_single':
                $queueId = intval($_POST['queue_id']);
                $result = $sync->localDb->query("UPDATE sync_queue_local SET status = 'pending', attempts = 0 WHERE id = $queueId");
                $affected = $sync->localDb->affected_rows;
                echo "<div style='background: lightgreen; padding: 10px; border-radius: 5px;'>✅ Queue-Eintrag $queueId zurückgesetzt</div>";
                break;
                
            case 'debug_single':
                $queueId = intval($_POST['queue_id']);
                
                // Queue-Eintrag Details
                $stmt = $sync->localDb->prepare("SELECT * FROM sync_queue_local WHERE id = ?");
                $stmt->bind_param('i', $queueId);
                $stmt->execute();
                $queueItem = $stmt->get_result()->fetch_assoc();
                
                echo "<div style='background: lightyellow; padding: 10px; border-radius: 5px;'>";
                echo "<h3>🔍 Debug für Queue-Eintrag $queueId:</h3>";
                
                if ($queueItem) {
                    echo "<h4>Queue-Eintrag Details:</h4>";
                    echo "<pre>" . json_encode($queueItem, JSON_PRETTY_PRINT) . "</pre>";
                    
                    $recordId = $queueItem['record_id'];
                    $tableName = $queueItem['table_name'];
                    $operation = $queueItem['operation'];
                    
                    // Lokale Daten prüfen
                    echo "<h4>🔍 Lokale Daten für Record $recordId in $tableName:</h4>";
                    $stmt2 = $sync->localDb->prepare("SELECT * FROM `$tableName` WHERE id = ?");
                    $stmt2->bind_param('i', $recordId);
                    $stmt2->execute();
                    $localRecord = $stmt2->get_result()->fetch_assoc();
                    
                    if ($localRecord) {
                        echo "✅ Record existiert in lokaler DB<br>";
                        echo "<details><summary>Lokale Daten anzeigen</summary>";
                        echo "<pre>" . json_encode($localRecord, JSON_PRETTY_PRINT) . "</pre>";
                        echo "</details>";
                    } else {
                        echo "❌ Record $recordId NICHT in lokaler DB gefunden!<br>";
                    }
                    
                    // Remote Daten prüfen
                    if ($sync->remoteDb) {
                        echo "<h4>🔍 Remote Daten für Record $recordId in $tableName:</h4>";
                        $stmt3 = $sync->remoteDb->prepare("SELECT * FROM `$tableName` WHERE id = ?");
                        $stmt3->bind_param('i', $recordId);
                        $stmt3->execute();
                        $remoteRecord = $stmt3->get_result()->fetch_assoc();
                        
                        if ($remoteRecord) {
                            echo "✅ Record existiert in Remote DB<br>";
                            echo "<details><summary>Remote Daten anzeigen</summary>";
                            echo "<pre>" . json_encode($remoteRecord, JSON_PRETTY_PRINT) . "</pre>";
                            echo "</details>";
                            
                            // Vergleich
                            if ($localRecord && $remoteRecord) {
                                echo "<h4>🔍 Unterschiede Local ↔ Remote:</h4>";
                                $differences = [];
                                foreach ($localRecord as $key => $localValue) {
                                    $remoteValue = $remoteRecord[$key] ?? 'NICHT_VORHANDEN';
                                    if ($localValue != $remoteValue) {
                                        $differences[$key] = [
                                            'local' => $localValue,
                                            'remote' => $remoteValue
                                        ];
                                    }
                                }
                                
                                if (!empty($differences)) {
                                    echo "<pre>" . json_encode($differences, JSON_PRETTY_PRINT) . "</pre>";
                                } else {
                                    echo "✅ Keine Unterschiede gefunden<br>";
                                }
                            }
                        } else {
                            echo "❌ Record NICHT in Remote DB gefunden<br>";
                        }
                    }
                    
                    // Spalten-Struktur vergleichen
                    echo "<h4>🔍 Tabellen-Struktur Vergleich:</h4>";
                    
                    // Lokale Spalten
                    $localCols = $sync->localDb->query("SHOW COLUMNS FROM `$tableName`");
                    $localColumns = [];
                    while ($col = $localCols->fetch_assoc()) {
                        $localColumns[$col['Field']] = [
                            'Type' => $col['Type'],
                            'Null' => $col['Null'],
                            'Default' => $col['Default'],
                            'Key' => $col['Key'],
                            'Extra' => $col['Extra']
                        ];
                    }
                    
                    // Remote Spalten (falls verfügbar)
                    $remoteColumns = [];
                    if ($sync->remoteDb) {
                        $remoteCols = $sync->remoteDb->query("SHOW COLUMNS FROM `$tableName`");
                        while ($col = $remoteCols->fetch_assoc()) {
                            $remoteColumns[$col['Field']] = [
                                'Type' => $col['Type'],
                                'Null' => $col['Null'],
                                'Default' => $col['Default'],
                                'Key' => $col['Key'],
                                'Extra' => $col['Extra']
                            ];
                        }
                    }
                    
                    echo "<details><summary>🔍 Detaillierter Spalten-Vergleich anzeigen</summary>";
                    echo "<table border='1' style='border-collapse: collapse; font-size: 11px; width: 100%;'>";
                    echo "<tr><th>Spalte</th><th>Lokal Typ</th><th>Remote Typ</th><th>Lokal NULL</th><th>Remote NULL</th><th>Lokal Default</th><th>Remote Default</th><th>Status</th></tr>";
                    
                    $allColumns = array_unique(array_merge(array_keys($localColumns), array_keys($remoteColumns)));
                    $schemaProblems = [];
                    
                    foreach ($allColumns as $colName) {
                        $local = $localColumns[$colName] ?? null;
                        $remote = $remoteColumns[$colName] ?? null;
                        
                        $localType = $local ? $local['Type'] : 'FEHLT';
                        $remoteType = $remote ? $remote['Type'] : 'FEHLT';
                        $localNull = $local ? $local['Null'] : 'FEHLT';
                        $remoteNull = $remote ? $remote['Null'] : 'FEHLT';
                        $localDefault = $local ? ($local['Default'] ?? 'NULL') : 'FEHLT';
                        $remoteDefault = $remote ? ($remote['Default'] ?? 'NULL') : 'FEHLT';
                        
                        $issues = [];
                        if ($localType !== $remoteType) $issues[] = 'Typ';
                        if ($localNull !== $remoteNull) $issues[] = 'NULL';
                        if ($localDefault !== $remoteDefault) $issues[] = 'Default';
                        
                        $status = empty($issues) ? '✅' : '❌ ' . implode(', ', $issues);
                        $rowColor = empty($issues) ? '' : 'background-color: #ffebee;';
                        
                        if (!empty($issues)) {
                            $schemaProblems[$colName] = $issues;
                        }
                        
                        echo "<tr style='$rowColor'>";
                        echo "<td><b>$colName</b></td>";
                        echo "<td>$localType</td>";
                        echo "<td>$remoteType</td>";
                        echo "<td>$localNull</td>";
                        echo "<td>$remoteNull</td>";
                        echo "<td>$localDefault</td>";
                        echo "<td>$remoteDefault</td>";
                        echo "<td>$status</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                    echo "</details>";
                    
                    // NULL-Wert Analyse
                    if ($localRecord) {
                        echo "<h4>🔍 NULL-Wert und Constraint Analyse:</h4>";
                        
                        $nullIssues = [];
                        $constraintIssues = [];
                        
                        foreach ($localRecord as $colName => $value) {
                            $remoteCol = $remoteColumns[$colName] ?? null;
                            
                            if ($remoteCol) {
                                // NULL-Constraint prüfen
                                if ($remoteCol['Null'] === 'NO' && ($value === null || $value === '')) {
                                    $nullIssues[] = "$colName: Wert '$value' aber Remote erwartet NOT NULL";
                                }
                                
                                // Default-Wert prüfen
                                if ($value === null && $remoteCol['Default'] !== null && $remoteCol['Default'] !== 'NULL') {
                                    $constraintIssues[] = "$colName: NULL-Wert würde Default '{$remoteCol['Default']}' verwenden";
                                }
                                
                                // Spezielle MySQL-Constraints
                                if (strpos($remoteCol['Type'], 'timestamp') !== false && $remoteCol['Null'] === 'NO') {
                                    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
                                        $constraintIssues[] = "$colName: Ungültiger Timestamp '$value' für NOT NULL TIMESTAMP";
                                    }
                                }
                            }
                        }
                        
                        if (!empty($nullIssues) || !empty($constraintIssues)) {
                            echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 5px;'>";
                            echo "<h5>⚠️ Potentielle Probleme gefunden:</h5>";
                            
                            if (!empty($nullIssues)) {
                                echo "<h6 style='color: red;'>❌ NULL-Constraint Probleme:</h6>";
                                echo "<ul>";
                                foreach ($nullIssues as $issue) {
                                    echo "<li style='color: red;'>$issue</li>";
                                }
                                echo "</ul>";
                            }
                            
                            if (!empty($constraintIssues)) {
                                echo "<h6 style='color: orange;'>⚠️ Default-Wert Warnungen:</h6>";
                                echo "<ul>";
                                foreach ($constraintIssues as $issue) {
                                    echo "<li style='color: orange;'>$issue</li>";
                                }
                                echo "</ul>";
                            }
                            echo "</div>";
                        } else {
                            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px;'>✅ Keine NULL/Constraint-Probleme erkannt</div>";
                        }
                        
                        // Lösungsvorschläge
                        if (!empty($nullIssues)) {
                            echo "<h4>🛠️ Automatische Reparatur-Vorschläge:</h4>";
                            echo "<form method='post' style='background: #f8f9fa; padding: 10px; border-radius: 5px;'>";
                            echo "<input type='hidden' name='action' value='fix_null_values'>";
                            echo "<input type='hidden' name='queue_id' value='$queueId'>";
                            echo "<p><strong>Reparatur-Optionen:</strong></p>";
                            echo "<label><input type='checkbox' name='fix_empty_strings' checked> Leere Strings durch NULL ersetzen</label><br>";
                            echo "<label><input type='checkbox' name='fix_zero_timestamps' checked> Ungültige Timestamps reparieren</label><br>";
                            echo "<label><input type='checkbox' name='use_defaults' checked> Default-Werte für NOT NULL Spalten verwenden</label><br>";
                            echo "<button type='submit' style='background: orange; color: white; padding: 5px 10px; border: none; border-radius: 5px; margin-top: 10px;'>🔧 Automatisch reparieren</button>";
                            echo "</form>";
                        }
                    }
                    
                    // Test-Sync für diesen spezifischen Eintrag
                    echo "<h4>🧪 Test-Sync ausführen:</h4>";
                    echo "<form method='post' style='display: inline;'>";
                    echo "<input type='hidden' name='action' value='test_sync_single'>";
                    echo "<input type='hidden' name='queue_id' value='$queueId'>";
                    echo "<button type='submit' style='background: green; color: white; padding: 5px 10px; border: none; border-radius: 5px;'>🧪 Test Sync für diesen Eintrag</button>";
                    echo "</form>";
                    
                } else {
                    echo "❌ Queue-Eintrag $queueId nicht gefunden<br>";
                }
                echo "</div>";
                break;
                
            case 'test_sync_single':
                $queueId = intval($_POST['queue_id']);
                
                echo "<div style='background: lightblue; padding: 10px; border-radius: 5px;'>";
                echo "<h3>🧪 Test-Sync für Queue-Eintrag $queueId:</h3>";
                
                // Queue-Eintrag holen
                $stmt = $sync->localDb->prepare("SELECT * FROM sync_queue_local WHERE id = ?");
                $stmt->bind_param('i', $queueId);
                $stmt->execute();
                $queueItem = $stmt->get_result()->fetch_assoc();
                
                if ($queueItem) {
                    try {
                        // Detailliertes Sync mit Logging
                        $recordId = $queueItem['record_id'];
                        $tableName = $queueItem['table_name'];
                        $operation = $queueItem['operation'];
                        
                        echo "<p><strong>Operation:</strong> $operation für Record $recordId in $tableName</p>";
                        
                        // Simuliere Sync-Prozess mit detailliertem Logging
                        ob_start();
                        
                        switch ($operation) {
                            case 'insert':
                            case 'update':
                                $success = $sync->syncInsertUpdateMultiTable($recordId, $tableName, $sync->localDb, $sync->remoteDb);
                                break;
                            case 'delete':
                                $success = $sync->syncDeleteMultiTable($recordId, $tableName, $sync->remoteDb, $queueItem['old_data']);
                                break;
                            default:
                                $success = false;
                                echo "Unbekannte Operation: $operation\n";
                        }
                        
                        $output = ob_get_clean();
                        
                        if ($success) {
                            echo "<div style='background: lightgreen; padding: 5px; border-radius: 3px;'>✅ Test-Sync ERFOLGREICH</div>";
                            // Queue-Eintrag als erfolgreich markieren
                            $sync->localDb->query("DELETE FROM sync_queue_local WHERE id = $queueId");
                        } else {
                            echo "<div style='background: lightcoral; padding: 5px; border-radius: 3px;'>❌ Test-Sync FEHLGESCHLAGEN</div>";
                            // Fehler-Details hinzufügen
                            $errorMsg = $sync->localDb->error ?: 'Unbekannter Fehler';
                            $stmt = $sync->localDb->prepare("UPDATE sync_queue_local SET error_message = ? WHERE id = ?");
                            $stmt->bind_param('si', $errorMsg, $queueId);
                            $stmt->execute();
                        }
                        
                        if ($output) {
                            echo "<h4>Debug Output:</h4>";
                            echo "<pre style='background: #f8f8f8; padding: 10px; border-radius: 5px; font-size: 11px;'>" . htmlspecialchars($output) . "</pre>";
                        }
                        
                    } catch (Exception $e) {
                        echo "<div style='background: red; color: white; padding: 5px; border-radius: 3px;'>❌ EXCEPTION: " . htmlspecialchars($e->getMessage()) . "</div>";
                        
                        // Exception in Queue speichern
                        $errorMsg = $e->getMessage();
                        $stmt = $sync->localDb->prepare("UPDATE sync_queue_local SET error_message = ? WHERE id = ?");
                        $stmt->bind_param('si', $errorMsg, $queueId);
                        $stmt->execute();
                    }
                } else {
                    echo "❌ Queue-Eintrag nicht gefunden<br>";
                }
                echo "</div>";
                break;
                
            case 'fix_null_values':
                $queueId = intval($_POST['queue_id']);
                $fixEmptyStrings = isset($_POST['fix_empty_strings']);
                $fixZeroTimestamps = isset($_POST['fix_zero_timestamps']);
                $useDefaults = isset($_POST['use_defaults']);
                
                echo "<div style='background: lightblue; padding: 10px; border-radius: 5px;'>";
                echo "<h3>🔧 NULL-Wert Reparatur für Queue-Eintrag $queueId:</h3>";
                
                // Queue-Eintrag holen
                $stmt = $sync->localDb->prepare("SELECT * FROM sync_queue_local WHERE id = ?");
                $stmt->bind_param('i', $queueId);
                $stmt->execute();
                $queueItem = $stmt->get_result()->fetch_assoc();
                
                if ($queueItem) {
                    $recordId = $queueItem['record_id'];
                    $tableName = $queueItem['table_name'];
                    $primaryKey = $sync->testGetPrimaryKey($tableName);
                    
                    echo "<p><strong>Repariere Record $recordId in $tableName...</strong></p>";
                    
                    // Lokale Daten holen
                    $stmt2 = $sync->localDb->prepare("SELECT * FROM `$tableName` WHERE `$primaryKey` = ?");
                    $stmt2->bind_param('i', $recordId);
                    $stmt2->execute();
                    $localRecord = $stmt2->get_result()->fetch_assoc();
                    
                    if ($localRecord) {
                        // Remote Spalten-Info holen
                        $remoteCols = $sync->remoteDb->query("SHOW COLUMNS FROM `$tableName`");
                        $remoteColumns = [];
                        while ($col = $remoteCols->fetch_assoc()) {
                            $remoteColumns[$col['Field']] = [
                                'Type' => $col['Type'],
                                'Null' => $col['Null'],
                                'Default' => $col['Default']
                            ];
                        }
                        
                        $fixes = [];
                        $fixedData = $localRecord;
                        
                        foreach ($localRecord as $colName => $value) {
                            $remoteCol = $remoteColumns[$colName] ?? null;
                            
                            if ($remoteCol) {
                                $originalValue = $value;
                                $needsFix = false;
                                
                                // 1. Leere Strings reparieren
                                if ($fixEmptyStrings && $value === '' && $remoteCol['Null'] === 'NO') {
                                    if ($remoteCol['Default'] !== null && $remoteCol['Default'] !== 'NULL') {
                                        $fixedData[$colName] = $remoteCol['Default'];
                                        $needsFix = true;
                                    } else {
                                        // Typ-spezifische Defaults
                                        if (strpos($remoteCol['Type'], 'int') !== false) {
                                            $fixedData[$colName] = 0;
                                            $needsFix = true;
                                        } elseif (strpos($remoteCol['Type'], 'varchar') !== false || strpos($remoteCol['Type'], 'text') !== false) {
                                            $fixedData[$colName] = '';
                                            $needsFix = true;
                                        }
                                    }
                                }
                                
                                // 2. Ungültige Timestamps reparieren
                                if ($fixZeroTimestamps && strpos($remoteCol['Type'], 'timestamp') !== false) {
                                    if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
                                        if ($remoteCol['Null'] === 'YES') {
                                            $fixedData[$colName] = null;
                                        } else {
                                            $fixedData[$colName] = date('Y-m-d H:i:s');
                                        }
                                        $needsFix = true;
                                    }
                                }
                                
                                // 3. NULL-Werte mit Defaults reparieren
                                if ($useDefaults && $value === null && $remoteCol['Null'] === 'NO') {
                                    if ($remoteCol['Default'] !== null && $remoteCol['Default'] !== 'NULL') {
                                        $fixedData[$colName] = $remoteCol['Default'];
                                        $needsFix = true;
                                    }
                                }
                                
                                if ($needsFix) {
                                    $fixes[] = "$colName: '$originalValue' → '{$fixedData[$colName]}'";
                                }
                            }
                        }
                        
                        if (!empty($fixes)) {
                            echo "<h4>🔧 Angewendete Korrekturen:</h4>";
                            echo "<ul>";
                            foreach ($fixes as $fix) {
                                echo "<li>$fix</li>";
                            }
                            echo "</ul>";
                            
                            // Lokale Daten aktualisieren
                            $updateColumns = [];
                            $updateValues = [];
                            $types = '';
                            
                            foreach ($fixedData as $col => $val) {
                                if ($col !== $primaryKey && $fixedData[$col] !== $localRecord[$col]) {
                                    $updateColumns[] = "`$col` = ?";
                                    $updateValues[] = $val;
                                    $types .= 's';
                                }
                            }
                            
                            if (!empty($updateColumns)) {
                                $updateValues[] = $recordId;
                                $types .= 'i';
                                
                                $updateSql = "UPDATE `$tableName` SET " . implode(', ', $updateColumns) . " WHERE `$primaryKey` = ?";
                                $updateStmt = $sync->localDb->prepare($updateSql);
                                $updateStmt->bind_param($types, ...$updateValues);
                                
                                if ($updateStmt->execute()) {
                                    echo "<div style='background: lightgreen; padding: 5px; border-radius: 3px;'>✅ Lokale Daten erfolgreich repariert</div>";
                                    
                                    // Queue-Eintrag zurücksetzen
                                    $sync->localDb->query("UPDATE sync_queue_local SET status = 'pending', attempts = 0, error_message = 'Fixed NULL values' WHERE id = $queueId");
                                    echo "<div style='background: lightgreen; padding: 5px; border-radius: 3px;'>✅ Queue-Eintrag auf 'pending' zurückgesetzt</div>";
                                } else {
                                    echo "<div style='background: lightcoral; padding: 5px; border-radius: 3px;'>❌ Fehler beim Aktualisieren: " . $updateStmt->error . "</div>";
                                }
                            }
                        } else {
                            echo "<div style='background: lightyellow; padding: 5px; border-radius: 3px;'>ℹ️ Keine Korrekturen erforderlich</div>";
                        }
                    } else {
                        echo "❌ Record nicht gefunden<br>";
                    }
                } else {
                    echo "❌ Queue-Eintrag nicht gefunden<br>";
                }
                echo "</div>";
                break;
                
            case 'clear_queue':
                $result1 = $sync->localDb->query("DELETE FROM sync_queue_local");
                $affected1 = $sync->localDb->affected_rows;
                if ($sync->remoteDb) {
                    $result2 = $sync->remoteDb->query("DELETE FROM sync_queue_remote");
                    $affected2 = $sync->remoteDb->affected_rows;
                    echo "<div style='background: lightcoral; padding: 10px; border-radius: 5px;'>🗑️ Lokale Queue: $affected1 gelöscht, Remote Queue: $affected2 gelöscht</div>";
                } else {
                    echo "<div style='background: lightcoral; padding: 10px; border-radius: 5px;'>🗑️ Lokale Queue: $affected1 gelöscht</div>";
                }
                break;
                
            case 'manual_sync':
                $result = $sync->syncOnPageLoad('debug_manual');
                echo "<div style='background: lightblue; padding: 10px; border-radius: 5px;'>";
                echo "<h3>🔄 Manueller Sync Ergebnis:</h3>";
                echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";
                echo "</div>";
                break;
                
            case 'test_record':
                $recordId = intval($_POST['record_id']);
                $tableName = $_POST['table_name'];
                
                echo "<div style='background: lightyellow; padding: 10px; border-radius: 5px;'>";
                echo "<h3>🧪 Test Record $recordId in $tableName:</h3>";
                
                // Prüfe ob Record existiert
                $stmt = $sync->localDb->prepare("SELECT * FROM `$tableName` WHERE id = ?");
                $stmt->bind_param('i', $recordId);
                $stmt->execute();
                $record = $stmt->get_result()->fetch_assoc();
                
                if ($record) {
                    echo "✅ Record existiert in lokaler DB<br>";
                    echo "<pre>" . json_encode($record, JSON_PRETTY_PRINT) . "</pre>";
                    
                    // Prüfe Remote
                    if ($sync->remoteDb) {
                        $stmt2 = $sync->remoteDb->prepare("SELECT * FROM `$tableName` WHERE id = ?");
                        $stmt2->bind_param('i', $recordId);
                        $stmt2->execute();
                        $remoteRecord = $stmt2->get_result()->fetch_assoc();
                        
                        if ($remoteRecord) {
                            echo "✅ Record existiert auch in Remote DB<br>";
                        } else {
                            echo "❌ Record NICHT in Remote DB gefunden<br>";
                        }
                    }
                } else {
                    echo "❌ Record $recordId nicht in lokaler DB gefunden<br>";
                }
                echo "</div>";
                break;
        }
        
        echo "<br><a href='?'>🔄 Seite neu laden</a><br>";
    }
    
    echo "<h2>6. Logs</h2>";
    $logFile = __DIR__ . '/logs/sync.log';
    if (file_exists($logFile)) {
        $logs = file_get_contents($logFile);
        $logLines = explode("\n", $logs);
        $recentLogs = array_slice($logLines, -20); // Letzte 20 Zeilen
        
        echo "<h3>📝 Letzte 20 Log-Einträge:</h3>";
        echo "<div style='background: #f0f0f0; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: scroll;'>";
        foreach ($recentLogs as $line) {
            if (trim($line)) {
                $color = 'black';
                if (strpos($line, 'ERROR') !== false || strpos($line, 'FAILED') !== false) {
                    $color = 'red';
                } elseif (strpos($line, 'SUCCESS') !== false) {
                    $color = 'green';
                } elseif (strpos($line, 'SYNC') !== false) {
                    $color = 'blue';
                }
                echo "<div style='color: $color;'>" . htmlspecialchars($line) . "</div>";
            }
        }
        echo "</div>";
    } else {
        echo "📝 Keine Log-Datei gefunden ($logFile)<br>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: red; color: white; padding: 10px; border-radius: 5px;'>";
    echo "<h2>❌ DEBUG FEHLER</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>
