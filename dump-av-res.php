<?php
/**
 * dump-av-res.php - Einseitiger Dump der Tabelle AV-Res
 * 
 * Kopiert die komplette Tabelle AV-Res von der lokalen DB (192.168.15.14) 
 * zur Remote-DB (booking.franzsennhuette.at) als 1:1 Dump.
 * 
 * WICHTIG: Die lokale Tabelle wird NICHT verändert!
 */

require_once __DIR__ . '/config.php';

// Helper function für Memory-Formatierung
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// Statistik-Datei
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    if (!mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        throw new RuntimeException("Log-Verzeichnis konnte nicht erstellt werden: {$logDir}");
    }
}
$statsFile = $logDir . '/av-res-dump-stats.json';

// Lade aktuelle Statistiken
$stats = [];
if (file_exists($statsFile)) {
    $stats = json_decode(file_get_contents($statsFile), true) ?: [];
}

// Update Statistiken
$currentTime = time();
$stats['total_runs'] = ($stats['total_runs'] ?? 0) + 1;
$stats['last_run'] = $currentTime;
$stats['last_run_formatted'] = date('Y-m-d H:i:s', $currentTime);
$stats['first_run'] = $stats['first_run'] ?? $currentTime;
$stats['first_run_formatted'] = date('Y-m-d H:i:s', $stats['first_run']);

// Speichere Statistiken
file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT));

$logPrefix = "[" . date('Y-m-d H:i:s') . "] [AV-RES-DUMP]";

// Performance-Messung
$startTime = microtime(true);
$memoryStart = memory_get_usage(true);

try {
    echo "$logPrefix === AUSFÜHRUNGSSTATISTIK ===\n";
    echo "$logPrefix Ausführung #" . $stats['total_runs'] . "\n";
    echo "$logPrefix Erste Ausführung: " . $stats['first_run_formatted'] . "\n";
    echo "$logPrefix Letzte Ausführung: " . $stats['last_run_formatted'] . "\n";
    if ($stats['total_runs'] > 1) {
        $daysSinceFirst = round(($currentTime - $stats['first_run']) / 86400, 1);
        $avgPerDay = round($stats['total_runs'] / max($daysSinceFirst, 1), 1);
        echo "$logPrefix Läuft seit: $daysSinceFirst Tagen\n";
        echo "$logPrefix Durchschnitt: $avgPerDay Ausführungen/Tag\n";
    }
    echo "$logPrefix \n";
    
    echo "$logPrefix Starte AV-Res Dump von lokal zu remote\n";
    echo "$logPrefix Start-Zeit: " . date('Y-m-d H:i:s.') . substr(microtime(), 2, 3) . "\n";
    echo "$logPrefix Start-Memory: " . formatBytes($memoryStart) . "\n";
    
    // Lokale Verbindung (Source)
    $dbConnectStart = microtime(true);
    $localDb = new mysqli($GLOBALS['dbHost'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], $GLOBALS['dbName']);
    if ($localDb->connect_error) {
        throw new Exception("Lokale DB Verbindung fehlgeschlagen: " . $localDb->connect_error);
    }
    $localDb->set_charset('utf8mb4');
    $dbConnectTime = microtime(true) - $dbConnectStart;
    echo "$logPrefix Lokale DB Verbindung erfolgreich (192.168.15.14) [" . round($dbConnectTime * 1000, 2) . "ms]\n";
    
    // Remote Verbindung (Target)
    $remoteConnectStart = microtime(true);
    $remoteDb = new mysqli($remoteDbHost, $remoteDbUser, $remoteDbPass, $remoteDbName);
    if ($remoteDb->connect_error) {
        throw new Exception("Remote DB Verbindung fehlgeschlagen: " . $remoteDb->connect_error);
    }
    $remoteDb->set_charset('utf8mb4');
    $remoteConnectTime = microtime(true) - $remoteConnectStart;
    echo "$logPrefix Remote DB Verbindung erfolgreich (booking.franzsennhuette.at) [" . round($remoteConnectTime * 1000, 2) . "ms]\n";
    
    // 1. Prüfe ob Tabelle AV-Res in lokaler DB existiert
    $checkLocal = $localDb->query("SHOW TABLES LIKE 'AV-Res'");
    if ($checkLocal->num_rows == 0) {
        throw new Exception("Tabelle 'AV-Res' existiert nicht in lokaler DB");
    }
    echo "$logPrefix Tabelle AV-Res in lokaler DB gefunden\n";
    
    // 2. Hole Anzahl Datensätze aus lokaler DB
    $countStart = microtime(true);
    $countResult = $localDb->query("SELECT COUNT(*) as count FROM `AV-Res`");
    $localCount = $countResult->fetch_assoc()['count'];
    $countTime = microtime(true) - $countStart;
    echo "$logPrefix Anzahl Datensätze in lokaler AV-Res: $localCount [" . round($countTime * 1000, 2) . "ms]\n";
    
    if ($localCount == 0) {
        echo "$logPrefix WARNUNG: Lokale Tabelle AV-Res ist leer!\n";
        echo "$logPrefix Dump wird trotzdem fortgesetzt (leert Remote-Tabelle)\n";
    }
    
    // 3. Backup der Remote-Tabelle erstellen (Sicherheit)
    echo "$logPrefix Erstelle Backup der Remote-Tabelle...\n";
    $backupName = "AV_Res_backup_" . date('Y_m_d_H_i_s');
    $remoteDb->query("DROP TABLE IF EXISTS `$backupName`");
    $backupResult = $remoteDb->query("CREATE TABLE `$backupName` AS SELECT * FROM `AV-Res`");
    if ($backupResult) {
        echo "$logPrefix Backup erstellt: $backupName\n";
    } else {
        echo "$logPrefix WARNUNG: Backup konnte nicht erstellt werden: " . $remoteDb->error . "\n";
    }
    
    // 4. Hole komplette Tabellenstruktur von lokaler DB
    echo "$logPrefix Hole Tabellenstruktur von lokaler DB...\n";
    $structureStart = microtime(true);
    $createTableResult = $localDb->query("SHOW CREATE TABLE `AV-Res`");
    if (!$createTableResult) {
        throw new Exception("Fehler beim Abrufen der Tabellenstruktur: " . $localDb->error);
    }
    $createTableRow = $createTableResult->fetch_assoc();
    $createTableSQL = $createTableRow['Create Table'];
    $structureTime = microtime(true) - $structureStart;
    echo "$logPrefix Tabellenstruktur gelesen [" . round($structureTime * 1000, 2) . "ms]\n";
    echo "$logPrefix DEBUG: CREATE TABLE Länge: " . strlen($createTableSQL) . " Zeichen\n";
    
    // 5. Remote-Tabelle komplett neu erstellen (mit allen Indizes, Keys, etc.)
    echo "$logPrefix Erstelle Remote-Tabelle neu mit Original-Struktur...\n";
    $recreateStart = microtime(true);
    $remoteDb->query("DROP TABLE IF EXISTS `AV-Res`");
    $createResult = $remoteDb->query($createTableSQL);
    if (!$createResult) {
        throw new Exception("Fehler beim Erstellen der Remote-Tabelle: " . $remoteDb->error);
    }
    $recreateTime = microtime(true) - $recreateStart;
    echo "$logPrefix Remote-Tabelle mit identischer Struktur erstellt [" . round($recreateTime * 1000, 2) . "ms]\n";
    
    // 6. Daten von lokal zu remote kopieren (in Batches)
    $batchSize = 100;
    $offset = 0;
    $totalCopied = 0;
    $copyStartTime = microtime(true);
    
    echo "$logPrefix Starte Datenkopierung (Batch-Größe: $batchSize)...\n";
    
    while (true) {
        $batchStart = microtime(true);
        
        // Hole Batch aus lokaler DB
        $selectQuery = "SELECT * FROM `AV-Res` LIMIT $batchSize OFFSET $offset";
        $selectStart = microtime(true);
        $result = $localDb->query($selectQuery);
        $selectTime = microtime(true) - $selectStart;
        
        if (!$result) {
            throw new Exception("Fehler beim Lesen lokaler Daten: " . $localDb->error);
        }
        
        if ($result->num_rows == 0) {
            break; // Keine weiteren Daten
        }
        
        // Bereite INSERT Statement vor
        $insertValues = [];
        $columns = null;
        $processStart = microtime(true);
        
        while ($row = $result->fetch_assoc()) {
            if ($columns === null) {
                $columns = array_keys($row);
                echo "$logPrefix DEBUG: Erkannte Spalten: " . implode(', ', $columns) . "\n";
            }
            
            $escapedValues = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $escapedValues[] = 'NULL';
                } else {
                    $escapedValues[] = "'" . $remoteDb->real_escape_string($value) . "'";
                }
            }
            $insertValues[] = '(' . implode(',', $escapedValues) . ')';
        }
        $processTime = microtime(true) - $processStart;
        
        if (!empty($insertValues)) {
            $columnNames = '`' . implode('`,`', $columns) . '`';
            $insertQuery = "INSERT INTO `AV-Res` ($columnNames) VALUES " . implode(',', $insertValues);
            
            $insertStart = microtime(true);
            $insertResult = $remoteDb->query($insertQuery);
            $insertTime = microtime(true) - $insertStart;
            
            if (!$insertResult) {
                throw new Exception("Fehler beim Einfügen in Remote-DB: " . $remoteDb->error);
            }
            
            $batchCount = count($insertValues);
            $totalCopied += $batchCount;
            $batchTime = microtime(true) - $batchStart;
            $currentMemory = memory_get_usage(true);
            
            echo "$logPrefix Batch #" . (intval($offset/$batchSize) + 1) . " kopiert: $batchCount Datensätze (Gesamt: $totalCopied) ";
            echo "[SELECT: " . round($selectTime * 1000, 1) . "ms, ";
            echo "PROCESS: " . round($processTime * 1000, 1) . "ms, ";
            echo "INSERT: " . round($insertTime * 1000, 1) . "ms, ";
            echo "TOTAL: " . round($batchTime * 1000, 1) . "ms] ";
            echo "[Memory: " . formatBytes($currentMemory) . "]\n";
        }
        
        $offset += $batchSize;
    }
    
    $copyTotalTime = microtime(true) - $copyStartTime;
    
    // 7. Verifikation
    $verifyStart = microtime(true);
    $remoteCountResult = $remoteDb->query("SELECT COUNT(*) as count FROM `AV-Res`");
    $remoteCount = $remoteCountResult->fetch_assoc()['count'];
    $verifyTime = microtime(true) - $verifyStart;
    
    // Performance-Statistiken
    $totalTime = microtime(true) - $startTime;
    $memoryEnd = memory_get_usage(true);
    $memoryPeak = memory_get_peak_usage(true);
    
    echo "$logPrefix ✅ DUMP ABGESCHLOSSEN\n";
    echo "$logPrefix Lokal (Source): $localCount Datensätze\n";
    echo "$logPrefix Remote (Target): $remoteCount Datensätze\n";
    echo "$logPrefix Kopiert: $totalCopied Datensätze\n";
    echo "$logPrefix \n";
    echo "$logPrefix === PERFORMANCE STATISTIKEN ===\n";
    echo "$logPrefix Gesamt-Zeit: " . round($totalTime, 2) . "s\n";
    echo "$logPrefix Kopier-Zeit: " . round($copyTotalTime, 2) . "s (" . round(($copyTotalTime/$totalTime)*100, 1) . "%)\n";
    echo "$logPrefix Verifikation: " . round($verifyTime * 1000, 2) . "ms\n";
    echo "$logPrefix Durchsatz: " . round($totalCopied / $totalTime, 0) . " Datensätze/Sekunde\n";
    echo "$logPrefix Memory Start: " . formatBytes($memoryStart) . "\n";
    echo "$logPrefix Memory End: " . formatBytes($memoryEnd) . "\n";
    echo "$logPrefix Memory Peak: " . formatBytes($memoryPeak) . "\n";
    echo "$logPrefix Memory Verbrauch: " . formatBytes($memoryPeak - $memoryStart) . "\n";
    echo "$logPrefix \n";
    
    if ($localCount == $remoteCount) {
        echo "$logPrefix ✅ VERIFIKATION ERFOLGREICH: Alle Daten korrekt kopiert\n";
    } else {
        echo "$logPrefix ❌ VERIFIKATION FEHLGESCHLAGEN: Anzahl stimmt nicht überein!\n";
        exit(1);
    }
    
    // Verbindungen schließen
    $localDb->close();
    $remoteDb->close();
    
    echo "$logPrefix Dump erfolgreich abgeschlossen\n";
    
    // Update finale Statistiken
    $endTime = time();
    $stats['last_success'] = $endTime;
    $stats['last_success_formatted'] = date('Y-m-d H:i:s', $endTime);
    $stats['last_duration'] = round($totalTime, 2);
    $stats['last_records_copied'] = $totalCopied;
    $stats['total_records_copied'] = ($stats['total_records_copied'] ?? 0) + $totalCopied;
    $stats['success_runs'] = ($stats['success_runs'] ?? 0) + 1;
    
    file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT));
    
} catch (Exception $e) {
    echo "$logPrefix ❌ FEHLER: " . $e->getMessage() . "\n";
    echo "$logPrefix Stack trace: " . $e->getTraceAsString() . "\n";
    
    // Update Error-Statistiken
    $stats['last_error'] = time();
    $stats['last_error_formatted'] = date('Y-m-d H:i:s');
    $stats['last_error_message'] = $e->getMessage();
    $stats['error_runs'] = ($stats['error_runs'] ?? 0) + 1;
    
    file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT));
    
    exit(1);
}
?>
