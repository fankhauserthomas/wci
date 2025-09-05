<?php
// API für Sync-Konfiguration und -Management
require_once 'SyncManager.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'status':
            echo json_encode(getStatus());
            break;
            
        case 'saveCron':
            echo json_encode(saveCronJob());
            break;
            
        case 'test':
            echo json_encode(testSync());
            break;
            
        case 'force':
            echo json_encode(forceSync());
            break;
            
        case 'fullSync':
            echo json_encode(fullSync());
            break;
            
        case 'stats':
            echo json_encode(getStats());
            break;
            
        case 'logs':
            echo json_encode(getLogs());
            break;
            
        case 'clearLogs':
            echo json_encode(clearLogs());
            break;
            
        case 'downloadLogs':
            downloadLogs();
            break;
            
        case 'checkTables':
            echo json_encode(checkTables());
            break;
            
        case 'initQueues':
            echo json_encode(initializeQueues());
            break;
            
        case 'resetSync':
            echo json_encode(resetSync());
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function getStatus() {
    try {
        $syncManager = new SyncManager();
        
        // Prüfe verschiedene Status-Aspekte
        $queueTables = checkQueueTablesExist();
        $remoteConnected = testRemoteConnection();
        $lastSync = getLastSyncTime();
        $cronActive = isCronActive();
        $activeTables = getActiveSyncTables();
        
        return [
            'success' => true,
            'queueTables' => $queueTables,
            'remoteConnected' => $remoteConnected,
            'lastSync' => $lastSync,
            'cronActive' => $cronActive,
            'activeTables' => $activeTables,
            'allOk' => $queueTables && $remoteConnected
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function saveCronJob() {
    try {
        $interval = $_POST['cronInterval'] ?? 'disabled';
        $customCron = $_POST['customCron'] ?? '';
        $tables = $_POST['tables'] ?? ['AV-ResNamen'];
        
        if ($interval === 'disabled') {
            return removeCronJob();
        }
        
        $cronExpression = '';
        switch ($interval) {
            case '1':
                $cronExpression = '* * * * *';  // Jede Minute
                break;
            case '5':
                $cronExpression = '*/5 * * * *';  // Alle 5 Minuten
                break;
            case '15':
                $cronExpression = '*/15 * * * *';  // Alle 15 Minuten
                break;
            case '30':
                $cronExpression = '*/30 * * * *';  // Alle 30 Minuten
                break;
            case '60':
                $cronExpression = '0 * * * *';  // Stündlich
                break;
            case '240':
                $cronExpression = '0 */4 * * *';  // Alle 4 Stunden
                break;
            case 'custom':
                $cronExpression = trim($customCron);
                break;
        }
        
        if (empty($cronExpression)) {
            return ['success' => false, 'error' => 'Ungültiger Cron-Ausdruck'];
        }
        
        // Validiere Cron-Ausdruck (basic)
        if (!preg_match('/^[\d\*\/\-,\s]+$/', $cronExpression)) {
            return ['success' => false, 'error' => 'Ungültiges Cron-Format'];
        }
        
        // Log-Verzeichnis erstellen
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Verwende das Manual-Script für Cron-Management
        $scriptPath = __DIR__ . '/manage-sync-cron.sh';
        
        // Erstelle angepasstes Cron-Line für das Script
        $cronLine = "$cronExpression /usr/bin/php " . __DIR__ . "/sync-cron.php >> " . $logDir . "/sync.log 2>&1";
        
        // Zuerst alten entfernen
        $removeResult = shell_exec("$scriptPath remove 2>&1");
        
        // Aktualisiere das Script mit neuer Cron-Expression
        $scriptContent = file_get_contents($scriptPath);
        $scriptContent = preg_replace(
            '/CRON_LINE="[^"]*"/',
            'CRON_LINE="' . addslashes($cronLine) . '"',
            $scriptContent
        );
        file_put_contents($scriptPath, $scriptContent);
        
        // Installiere neuen Cron-Job
        $result = shell_exec("$scriptPath install 2>&1");
        
        logMessage("Cron-Job erstellt/aktualisiert: $cronExpression via Manual-Script");
        
        return [
            'success' => true,
            'action' => 'erstellt/aktualisiert',
            'cronExpression' => $cronExpression,
            'result' => $result,
            'tables' => $tables,
            'method' => 'manual-script'
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function removeCronJob() {
    try {
        // Verwende das Manual-Script für Cron-Management
        $scriptPath = __DIR__ . '/manage-sync-cron.sh';
        $result = shell_exec("$scriptPath remove 2>&1");
        
        logMessage("Cron-Job entfernt via Manual-Script");
        
        return [
            'success' => true,
            'action' => 'entfernt',
            'result' => $result,
            'method' => 'manual-script'
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testSync() {
    try {
        $syncManager = new SyncManager();
        $result = $syncManager->syncOnPageLoad('config_test');
        
        logMessage("Test-Sync durchgeführt");
        
        return [
            'success' => true,
            'testResult' => $result,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function forceSync() {
    try {
        $syncManager = new SyncManager();
        $result = $syncManager->forceSyncLatest(1, 'AV-ResNamen');
        
        logMessage("Force-Sync durchgeführt für letzte Stunde");
        
        return [
            'success' => true,
            'forceResult' => $result,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function fullSync() {
    try {
        $syncManager = new SyncManager();
        
        // Force Sync für längeren Zeitraum (24 Stunden)
        $results = [];
        $tables = ['AV-ResNamen']; // Nur aktive Tabellen
        
        foreach ($tables as $table) {
            $result = $syncManager->forceSyncLatest(24, $table);
            $results[$table] = $result;
        }
        
        logMessage("Vollständiger Sync durchgeführt für 24 Stunden");
        
        return [
            'success' => true,
            'fullSyncResults' => $results,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getStats() {
    try {
        $logFile = __DIR__ . '/logs/sync.log';
        $stats = [
            'syncsToday' => 0,
            'syncsThisWeek' => 0,
            'recordsToday' => 0,
            'errorsToday' => 0,
            'avgDuration' => 0
        ];
        
        if (file_exists($logFile)) {
            $logs = file_get_contents($logFile);
            $today = date('Y-m-d');
            $weekAgo = date('Y-m-d', strtotime('-7 days'));
            
            // Zähle Syncs heute
            $todayLines = array_filter(explode("\n", $logs), function($line) use ($today) {
                return strpos($line, "[$today") !== false && strpos($line, 'Sync erfolgreich') !== false;
            });
            $stats['syncsToday'] = count($todayLines);
            
            // Zähle Syncs diese Woche
            $lines = explode("\n", $logs);
            foreach ($lines as $line) {
                if (strpos($line, 'Sync erfolgreich') !== false) {
                    preg_match('/\[(\d{4}-\d{2}-\d{2})/', $line, $matches);
                    if ($matches && $matches[1] >= $weekAgo) {
                        $stats['syncsThisWeek']++;
                    }
                }
            }
            
            // Zähle Records heute
            $recordLines = array_filter(explode("\n", $logs), function($line) use ($today) {
                return strpos($line, "[$today") !== false && preg_match('/\d+ records/', $line);
            });
            $stats['recordsToday'] = count($recordLines);
            
            // Zähle Fehler heute
            $errorLines = array_filter(explode("\n", $logs), function($line) use ($today) {
                return strpos($line, "[$today") !== false && (strpos($line, 'Fehler') !== false || strpos($line, 'ERROR') !== false);
            });
            $stats['errorsToday'] = count($errorLines);
        }
        
        return [
            'success' => true,
            'stats' => $stats
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getLogs() {
    try {
        $logFile = __DIR__ . '/logs/sync.log';
        
        if (!file_exists($logFile)) {
            return ['success' => true, 'logs' => 'Keine Logs verfügbar'];
        }
        
        $logs = file_get_contents($logFile);
        
        // Nur die letzten 200 Zeilen für bessere Performance
        $lines = explode("\n", $logs);
        $lines = array_slice($lines, -200);
        
        return [
            'success' => true,
            'logs' => implode("\n", $lines),
            'lineCount' => count($lines)
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function clearLogs() {
    try {
        $logFile = __DIR__ . '/logs/sync.log';
        
        if (file_exists($logFile)) {
            // Backup erstellen
            $backupFile = $logFile . '.backup.' . date('Y-m-d_H-i-s');
            copy($logFile, $backupFile);
            
            // Log-Datei leeren
            file_put_contents($logFile, '');
            
            logMessage("Logs geleert (Backup: " . basename($backupFile) . ")");
        }
        
        return ['success' => true, 'message' => 'Logs erfolgreich geleert'];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function downloadLogs() {
    try {
        $logFile = __DIR__ . '/logs/sync.log';
        
        if (!file_exists($logFile)) {
            header('HTTP/1.1 404 Not Found');
            echo 'Log-Datei nicht gefunden';
            return;
        }
        
        $filename = 'sync-logs-' . date('Y-m-d_H-i-s') . '.log';
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($logFile));
        
        readfile($logFile);
        
    } catch (Exception $e) {
        header('Content-Type: text/plain');
        echo 'Fehler beim Download: ' . $e->getMessage();
    }
}

function checkTables() {
    try {
        require_once 'config.php';
        
        // Verwende die korrekten Variablennamen aus config.php
        global $dbHost, $dbUser, $dbPass, $dbName;
        
        $localDb = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($localDb->connect_error) {
            throw new Exception('Local DB Verbindung fehlgeschlagen');
        }
        
        $tables = ['AV-ResNamen', 'AV-Res', 'AV_ResDet', 'zp_zimmer'];
        $results = [];
        
        foreach ($tables as $table) {
            $result = $localDb->query("SHOW CREATE TABLE `$table`");
            if ($result) {
                $row = $result->fetch_assoc();
                $results[$table] = [
                    'exists' => true,
                    'structure' => $row['Create Table'] ?? 'N/A'
                ];
            } else {
                $results[$table] = [
                    'exists' => false,
                    'error' => $localDb->error
                ];
            }
        }
        
        $localDb->close();
        
        return [
            'success' => true,
            'tables' => $results
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function initializeQueues() {
    try {
        $syncManager = new SyncManager();
        
        // Verwende die createQueueTables Methode falls verfügbar
        $reflection = new ReflectionClass($syncManager);
        if ($reflection->hasMethod('createQueueTables')) {
            $method = $reflection->getMethod('createQueueTables');
            $method->setAccessible(true);
            $result = $method->invoke($syncManager);
            
            logMessage("Queue-Tabellen initialisiert");
            
            return [
                'success' => true,
                'message' => 'Queue-Tabellen erfolgreich initialisiert',
                'details' => $result
            ];
        } else {
            return [
                'success' => false,
                'error' => 'createQueueTables Methode nicht verfügbar'
            ];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function resetSync() {
    try {
        require_once 'config.php';
        
        // Verwende die korrekten Variablennamen aus config.php
        global $dbHost, $dbUser, $dbPass, $dbName;
        
        $localDb = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($localDb->connect_error) {
            throw new Exception('DB Verbindung fehlgeschlagen');
        }
        
        // Lösche Queue-Tabellen
        $queueTables = ['sync_queue_AV-ResNamen', 'sync_queue_AV-Res', 'sync_queue_AV_ResDet', 'sync_queue_zp_zimmer'];
        $results = [];
        
        foreach ($queueTables as $table) {
            $result = $localDb->query("DELETE FROM `$table`");
            $results[$table] = $result ? "Geleert ({$localDb->affected_rows} Einträge)" : "Fehler: " . $localDb->error;
        }
        
        $localDb->close();
        
        logMessage("Sync-Reset durchgeführt - Queue-Tabellen geleert");
        
        return [
            'success' => true,
            'message' => 'Sync erfolgreich zurückgesetzt',
            'details' => $results
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Hilfsfunktionen

function checkQueueTablesExist() {
    try {
        require_once 'config.php';
        
        // Verwende die korrekten Variablennamen aus config.php
        global $dbHost, $dbUser, $dbPass, $dbName;
        
        $localDb = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($localDb->connect_error) return false;
        
        $result = $localDb->query("SHOW TABLES LIKE 'sync_queue_%'");
        $exists = $result && $result->num_rows > 0;
        
        $localDb->close();
        return $exists;
        
    } catch (Exception $e) {
        return false;
    }
}

function testRemoteConnection() {
    try {
        // Verwende SyncManager für Remote-Test
        $syncManager = new SyncManager();
        
        // Zugriff auf private remoteDb Property
        $reflection = new ReflectionClass($syncManager);
        $remoteDbProperty = $reflection->getProperty('remoteDb');
        $remoteDbProperty->setAccessible(true);
        $remoteDb = $remoteDbProperty->getValue($syncManager);
        
        return $remoteDb !== null && !$remoteDb->connect_error;
        
    } catch (Exception $e) {
        return false;
    }
}

function getLastSyncTime() {
    try {
        $logFile = __DIR__ . '/logs/sync.log';
        
        if (!file_exists($logFile)) return null;
        
        $logs = file_get_contents($logFile);
        $lines = explode("\n", $logs);
        
        // Suche nach letztem Sync-Eintrag
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (strpos($lines[$i], 'Sync erfolgreich') !== false || strpos($lines[$i], 'Sync completed') !== false) {
                preg_match('/\[([\d\-\s:]+)\]/', $lines[$i], $matches);
                return $matches[1] ?? null;
            }
        }
        
        return null;
        
    } catch (Exception $e) {
        return null;
    }
}

function isCronActive() {
    try {
        // Prüfe die vadmin Crontab (da das Script dort läuft)
        $output = shell_exec('sudo -u vadmin crontab -l 2>/dev/null') ?: '';
        return strpos($output, 'sync-cron.php') !== false;
        
    } catch (Exception $e) {
        // Fallback: Prüfe via Script
        try {
            $scriptPath = __DIR__ . '/manage-sync-cron.sh';
            $output = shell_exec("sudo -u vadmin $scriptPath status 2>&1");
            return strpos($output, 'ist AKTIV') !== false;
        } catch (Exception $e2) {
            return false;
        }
    }
}

function getActiveSyncTables() {
    try {
        $syncManager = new SyncManager();
        
        // Zugriff auf private syncTables Property
        $reflection = new ReflectionClass($syncManager);
        $syncTablesProperty = $reflection->getProperty('syncTables');
        $syncTablesProperty->setAccessible(true);
        
        return $syncTablesProperty->getValue($syncManager);
        
    } catch (Exception $e) {
        return [];
    }
}

function logMessage($message) {
    try {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        
        $logFile = $logDir . '/sync.log';
        
        // Erstelle Datei falls nicht vorhanden
        if (!file_exists($logFile)) {
            touch($logFile);
            chmod($logFile, 0666);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] CONFIG: $message\n";
        
        // Verwende error_log als Fallback falls file_put_contents fehlschlägt
        if (!file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX)) {
            error_log("SYNC CONFIG: $message");
        }
        
    } catch (Exception $e) {
        // Als letzter Ausweg: system error log
        error_log("SYNC CONFIG LOG ERROR: " . $e->getMessage() . " - Original message: $message");
    }
}
?>
