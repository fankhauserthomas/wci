<?php
// Erweiterte Constraint- und Schema-Analyse für Sync-Probleme
require_once 'SyncManager.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Database Schema & Constraint Analyzer</h1>";

try {
    $sync = new SyncManager();
    
    $tableName = $_GET['table'] ?? 'AV_ResDet';
    $recordId = $_GET['record'] ?? null;
    
    echo "<form method='get' style='background: #f8f9fa; padding: 10px; border-radius: 5px; margin-bottom: 20px;'>";
    echo "Tabelle: <select name='table'>";
    $tables = ['AV-ResNamen', 'AV-Res', 'AV_ResDet', 'zp_zimmer'];
    foreach ($tables as $table) {
        $selected = ($table === $tableName) ? 'selected' : '';
        echo "<option value='$table' $selected>$table</option>";
    }
    echo "</select> ";
    echo "Record ID: <input type='number' name='record' value='$recordId' placeholder='Optional'> ";
    echo "<button type='submit'>🔍 Analysieren</button>";
    echo "</form>";
    
    echo "<h2>📊 Schema-Vergleich für Tabelle: $tableName</h2>";
    
    // Lokale Schema-Analyse
    echo "<h3>🏠 Lokale Datenbank Schema:</h3>";
    $localResult = $sync->localDb->query("SHOW FULL COLUMNS FROM `$tableName`");
    $localSchema = [];
    
    if ($localResult) {
        echo "<table border='1' style='border-collapse: collapse; font-size: 12px; width: 100%;'>";
        echo "<tr><th>Spalte</th><th>Typ</th><th>NULL</th><th>Key</th><th>Default</th><th>Extra</th><th>Collation</th></tr>";
        
        while ($col = $localResult->fetch_assoc()) {
            $localSchema[$col['Field']] = $col;
            
            echo "<tr>";
            echo "<td><b>{$col['Field']}</b></td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>" . ($col['Null'] === 'YES' ? '✅ NULL' : '❌ NOT NULL') . "</td>";
            echo "<td>" . ($col['Key'] ?: '-') . "</td>";
            echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . ($col['Extra'] ?: '-') . "</td>";
            echo "<td>" . ($col['Collation'] ?: '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Remote Schema-Analyse
    if ($sync->remoteDb) {
        echo "<h3>🌐 Remote Datenbank Schema:</h3>";
        $remoteResult = $sync->remoteDb->query("SHOW FULL COLUMNS FROM `$tableName`");
        $remoteSchema = [];
        
        if ($remoteResult) {
            echo "<table border='1' style='border-collapse: collapse; font-size: 12px; width: 100%;'>";
            echo "<tr><th>Spalte</th><th>Typ</th><th>NULL</th><th>Key</th><th>Default</th><th>Extra</th><th>Collation</th></tr>";
            
            while ($col = $remoteResult->fetch_assoc()) {
                $remoteSchema[$col['Field']] = $col;
                
                echo "<tr>";
                echo "<td><b>{$col['Field']}</b></td>";
                echo "<td>{$col['Type']}</td>";
                echo "<td>" . ($col['Null'] === 'YES' ? '✅ NULL' : '❌ NOT NULL') . "</td>";
                echo "<td>" . ($col['Key'] ?: '-') . "</td>";
                echo "<td>" . ($col['Default'] ?? 'NULL') . "</td>";
                echo "<td>" . ($col['Extra'] ?: '-') . "</td>";
                echo "<td>" . ($col['Collation'] ?: '-') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Schema-Unterschiede
        echo "<h3>⚠️ Schema-Unterschiede:</h3>";
        $differences = [];
        
        $allColumns = array_unique(array_merge(array_keys($localSchema), array_keys($remoteSchema)));
        
        foreach ($allColumns as $colName) {
            $local = $localSchema[$colName] ?? null;
            $remote = $remoteSchema[$colName] ?? null;
            
            if (!$local) {
                $differences[] = "❌ Spalte '$colName' existiert nur in Remote DB";
            } elseif (!$remote) {
                $differences[] = "❌ Spalte '$colName' existiert nur in Lokaler DB";
            } else {
                if ($local['Type'] !== $remote['Type']) {
                    $differences[] = "⚠️ Spalte '$colName': Typ unterschiedlich (Lokal: {$local['Type']}, Remote: {$remote['Type']})";
                }
                if ($local['Null'] !== $remote['Null']) {
                    $differences[] = "⚠️ Spalte '$colName': NULL-Constraint unterschiedlich (Lokal: {$local['Null']}, Remote: {$remote['Null']})";
                }
                if (($local['Default'] ?? 'NULL') !== ($remote['Default'] ?? 'NULL')) {
                    $localDefault = $local['Default'] ?? 'NULL';
                    $remoteDefault = $remote['Default'] ?? 'NULL';
                    $differences[] = "⚠️ Spalte '$colName': Default unterschiedlich (Lokal: $localDefault, Remote: $remoteDefault)";
                }
            }
        }
        
        if (!empty($differences)) {
            echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px;'>";
            echo "<ul>";
            foreach ($differences as $diff) {
                echo "<li>$diff</li>";
            }
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px;'>✅ Keine Schema-Unterschiede gefunden!</div>";
        }
    }
    
    // Spezifischer Record-Test
    if ($recordId) {
        echo "<h2>🧪 Record-spezifische Analyse für ID: $recordId</h2>";
        
        $primaryKey = ($tableName === 'AV_ResDet') ? 'ID' : 'id';
        
        // Lokale Daten
        $stmt = $sync->localDb->prepare("SELECT * FROM `$tableName` WHERE `$primaryKey` = ?");
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $localRecord = $stmt->get_result()->fetch_assoc();
        
        echo "<h3>🏠 Lokale Daten:</h3>";
        if ($localRecord) {
            echo "<table border='1' style='border-collapse: collapse; font-size: 12px;'>";
            echo "<tr><th>Spalte</th><th>Wert</th><th>Typ</th><th>Länge</th><th>NULL?</th></tr>";
            
            foreach ($localRecord as $col => $value) {
                $valueType = gettype($value);
                $valueLength = is_string($value) ? strlen($value) : '-';
                $isNull = $value === null ? '✅' : '❌';
                $displayValue = $value === null ? 'NULL' : (is_string($value) && strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value);
                
                echo "<tr>";
                echo "<td><b>$col</b></td>";
                echo "<td>" . htmlspecialchars($displayValue) . "</td>";
                echo "<td>$valueType</td>";
                echo "<td>$valueLength</td>";
                echo "<td>$isNull</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Constraint-Validierung
            if ($sync->remoteDb && !empty($remoteSchema)) {
                echo "<h3>⚠️ Constraint-Validierung:</h3>";
                $violations = [];
                
                foreach ($localRecord as $colName => $value) {
                    $remoteCol = $remoteSchema[$colName] ?? null;
                    
                    if ($remoteCol) {
                        // NOT NULL Constraint
                        if ($remoteCol['Null'] === 'NO' && $value === null) {
                            $violations[] = "❌ $colName: NULL-Wert aber Remote erwartet NOT NULL";
                        }
                        
                        // String-Länge
                        if (is_string($value) && preg_match('/varchar\((\d+)\)/', $remoteCol['Type'], $matches)) {
                            $maxLength = intval($matches[1]);
                            if (strlen($value) > $maxLength) {
                                $violations[] = "❌ $colName: String zu lang (" . strlen($value) . " > $maxLength)";
                            }
                        }
                        
                        // Timestamp-Validierung
                        if (strpos($remoteCol['Type'], 'timestamp') !== false && $value) {
                            if ($value === '0000-00-00 00:00:00' && $remoteCol['Null'] === 'NO') {
                                $violations[] = "❌ $colName: Ungültiger Timestamp '0000-00-00 00:00:00' für NOT NULL";
                            }
                        }
                    }
                }
                
                if (!empty($violations)) {
                    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>";
                    echo "<ul>";
                    foreach ($violations as $violation) {
                        echo "<li>$violation</li>";
                    }
                    echo "</ul>";
                    echo "</div>";
                } else {
                    echo "<div style='background: #d4edda; padding: 10px; border-radius: 5px;'>✅ Alle Constraints sind erfüllt!</div>";
                }
            }
        } else {
            echo "<div style='background: #f8d7da; padding: 10px; border-radius: 5px;'>❌ Record $recordId nicht in lokaler DB gefunden</div>";
        }
        
        // Remote Daten
        if ($sync->remoteDb) {
            $stmt2 = $sync->remoteDb->prepare("SELECT * FROM `$tableName` WHERE `$primaryKey` = ?");
            $stmt2->bind_param('i', $recordId);
            $stmt2->execute();
            $remoteRecord = $stmt2->get_result()->fetch_assoc();
            
            echo "<h3>🌐 Remote Daten:</h3>";
            if ($remoteRecord) {
                echo "<table border='1' style='border-collapse: collapse; font-size: 12px;'>";
                echo "<tr><th>Spalte</th><th>Wert</th><th>Typ</th><th>Länge</th><th>NULL?</th></tr>";
                
                foreach ($remoteRecord as $col => $value) {
                    $valueType = gettype($value);
                    $valueLength = is_string($value) ? strlen($value) : '-';
                    $isNull = $value === null ? '✅' : '❌';
                    $displayValue = $value === null ? 'NULL' : (is_string($value) && strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value);
                    
                    echo "<tr>";
                    echo "<td><b>$col</b></td>";
                    echo "<td>" . htmlspecialchars($displayValue) . "</td>";
                    echo "<td>$valueType</td>";
                    echo "<td>$valueLength</td>";
                    echo "<td>$isNull</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<div style='background: #fff3cd; padding: 10px; border-radius: 5px;'>⚠️ Record $recordId nicht in Remote DB gefunden (das ist wahrscheinlich das Problem!)</div>";
            }
        }
    }
    
    echo "<br><p><a href='sync-debug.php' style='background: green; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🔍 Zurück zum Sync-Debug</a></p>";
    
} catch (Exception $e) {
    echo "<div style='background: red; color: white; padding: 10px; border-radius: 5px;'>";
    echo "<h2>❌ Fehler bei Schema-Analyse:</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>
