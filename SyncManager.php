<?php
class SyncManager {
    public $localDb;
    public $remoteDb;
    private $logFile;
    
    // Tabellen die synchronisiert werden sollen
    private $syncTables = [
        'AV-ResNamen',
        'AV-Res', 
        'AV_ResDet',
        'zp_zimmer'
    ];
    
    public function __construct() {
        $this->logFile = __DIR__ . '/logs/sync.log';
        
        // Lokale DB
        $this->localDb = new mysqli('192.168.15.14', 'root', 'Fsh2147m!1', 'booking_franzsen');
        if ($this->localDb->connect_error) {
            throw new Exception('Local DB connection failed: ' . $this->localDb->connect_error);
        }
        $this->localDb->set_charset('utf8mb4');
        
        // Remote DB
        $this->remoteDb = new mysqli('booking.franzsennhuette.at', 'booking_franzsen', '~2Y@76', 'booking_franzsen');
        if ($this->remoteDb->connect_error) {
            $this->log('Remote DB connection failed: ' . $this->remoteDb->connect_error);
            $this->remoteDb = null; // Continue without remote sync
        } else {
            $this->remoteDb->set_charset('utf8mb4');
        }
    }
    
    public function syncOnPageLoad($triggerAction = 'page_load') {
        try {
            $this->log("=== Sync triggered by: $triggerAction ===");
            
            if (!$this->remoteDb) {
                $this->log("Remote DB not available, skipping sync");
                return ['success' => true, 'message' => 'Remote DB not available'];
            }
            
            // Prüfe ob Queue-Tabellen existieren
            if ($this->queueTablesExist()) {
                $this->log("Queue tables found, using QUEUE-BASED sync");
                return $this->syncQueues();
            } else {
                $this->log("Queue tables not found, using TIMESTAMP-BASED sync (fallback)");
                return $this->syncTimestampBased();
            }
            
        } catch (Exception $e) {
            $this->log("SYNC ERROR: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Neue Queue-basierte Sync-Methode
    public function syncQueues() {
        try {
            $this->log("=== Queue-based Sync started ===");
            
            $detailedResults = [
                'sync_mode' => 'queue-based',
                'timestamp' => date('Y-m-d H:i:s'),
                'tables_configured' => $this->syncTables,
                'local_to_remote' => [
                    'total_processed' => 0,
                    'tables' => []
                ],
                'remote_to_local' => [
                    'total_processed' => 0,
                    'tables' => []
                ],
                'summary' => []
            ];
            
            // 1. Lokale Queue abarbeiten → Remote
            $this->log("--- Processing Local Queue → Remote ---");
            $localResults = $this->processQueueDetailed(
                $this->localDb, 'sync_queue_local',
                $this->remoteDb, 'local', 'remote'
            );
            $detailedResults['local_to_remote'] = $localResults;
            
            // 2. Remote Queue abarbeiten → Local  
            $this->log("--- Processing Remote Queue → Local ---");
            $remoteResults = $this->processQueueDetailed(
                $this->remoteDb, 'sync_queue_remote',
                $this->localDb, 'remote', 'local'
            );
            $detailedResults['remote_to_local'] = $remoteResults;
            
            // Summary erstellen
            $detailedResults['summary'] = $this->createSyncSummary($detailedResults);
            
            $this->log("=== Queue Sync completed ===");
            $this->logDetailedResults($detailedResults);
            
            return ['success' => true, 'results' => $detailedResults];
            
        } catch (Exception $e) {
            $this->log("QUEUE SYNC ERROR: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Fallback: Timestamp-basierte Synchronisation
    private function syncTimestampBased() {
        $detailedResults = [
            'sync_mode' => 'timestamp-based',
            'timestamp' => date('Y-m-d H:i:s'),
            'tables_configured' => $this->syncTables,
            'local_to_remote' => [
                'total_processed' => 0,
                'tables' => []
            ],
            'remote_to_local' => [
                'total_processed' => 0,
                'tables' => []
            ],
            'summary' => []
        ];
        
        // Alle konfigurierten Tabellen synchronisieren
        foreach ($this->syncTables as $tableName) {
            $this->log("--- Syncing table: $tableName ---");
            
            // 1. Remote → Local Sync (neue Remote-Daten holen)
            $this->log("--- Starting Remote to Local sync for $tableName ---");
            $remoteToLocal = $this->syncFromTimestamp($this->remoteDb, $this->localDb, 'remote', 'local', $tableName);
            $detailedResults['remote_to_local']['tables'][$tableName] = [
                'processed' => $remoteToLocal,
                'operations' => ['insert' => 0, 'update' => $remoteToLocal, 'delete' => 0]
            ];
            $detailedResults['remote_to_local']['total_processed'] += $remoteToLocal;
            
            // 2. Local → Remote Sync (lokale Änderungen senden)
            $this->log("--- Starting Local to Remote sync for $tableName ---");
            $localToRemote = $this->syncFromTimestamp($this->localDb, $this->remoteDb, 'local', 'remote', $tableName);
            $detailedResults['local_to_remote']['tables'][$tableName] = [
                'processed' => $localToRemote,
                'operations' => ['insert' => 0, 'update' => $localToRemote, 'delete' => 0]
            ];
            $detailedResults['local_to_remote']['total_processed'] += $localToRemote;
        }
        
        // Summary erstellen
        $detailedResults['summary'] = $this->createSyncSummary($detailedResults);
        
        $this->log("=== Timestamp Sync completed ===");
        $this->logDetailedResults($detailedResults);
        
        return ['success' => true, 'results' => $detailedResults];
    }
    
    // Öffentliche Methode für manuellen Sync mit custom Zeitfenster
    public function forceSyncLatest($hours = 1, $tableName = 'AV-ResNamen') {
        try {
            $this->log("=== FORCE SYNC: last $hours hours for $tableName ===");
            $results = [
                'local_to_remote' => 0,
                'remote_to_local' => 0,
                'conflicts' => 0
            ];
            
            // Custom timestamp
            $customTime = date('Y-m-d H:i:s', strtotime("-$hours hours"));
            $this->log("Custom sync time: $customTime");
            
            // Remote → Local (das ist was wir brauchen!)
            $this->log("--- Starting Remote to Local FORCE sync for $tableName ---");
            $results['remote_to_local'] = $this->syncFromTimestampCustom($this->remoteDb, $this->localDb, 'remote', 'local', $customTime, $tableName);
            
            $this->log("=== Force Sync completed: " . json_encode($results) . " ===");
            return ['success' => true, 'results' => $results];
            
        } catch (Exception $e) {
            $this->log("FORCE SYNC ERROR: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Prüfe ob Queue-Tabellen existieren
    private function queueTablesExist() {
        $localExists = $this->localDb->query("SHOW TABLES LIKE 'sync_queue_local'")->num_rows > 0;
        $remoteExists = $this->remoteDb && $this->remoteDb->query("SHOW TABLES LIKE 'sync_queue_remote'")->num_rows > 0;
        
        $this->log("Queue tables exist - Local: " . ($localExists ? 'YES' : 'NO') . ", Remote: " . ($remoteExists ? 'YES' : 'NO'));
        return $localExists && $remoteExists;
    }
    
    // Öffentliche Methode für Queue-Tabellen Check
    public function checkQueueTables() {
        return $this->queueTablesExist();
    }
    
    // Zentrale Primary Key Erkennung für verschiedene Tabellen
    private function getPrimaryKey($tableName) {
        switch ($tableName) {
            case 'AV_ResDet':
                return 'ID'; // AV_ResDet verwendet großgeschriebenes ID
            default:
                return 'id'; // Standard für alle anderen Tabellen
        }
    }
    
    // Öffentliche Test-Methode für Primary Key
    public function testGetPrimaryKey($tableName) {
        return $this->getPrimaryKey($tableName);
    }
    
    // Queue-Verarbeitung mit Multi-Table Support
    private function processQueue($sourceDb, $queueTable, $targetDb, $from, $to) {
        $result = $this->processQueueDetailed($sourceDb, $queueTable, $targetDb, $from, $to);
        return $result['total_processed'];
    }
    
    // Detaillierte Queue-Verarbeitung mit Multi-Table Support
    private function processQueueDetailed($sourceDb, $queueTable, $targetDb, $from, $to) {
        $result = [
            'total_processed' => 0,
            'tables' => []
        ];
        
        // Initialize table counters
        foreach ($this->syncTables as $tableName) {
            $result['tables'][$tableName] = [
                'processed' => 0,
                'operations' => ['insert' => 0, 'update' => 0, 'delete' => 0]
            ];
        }
        
        // Hole pending Queue-Einträge für alle Tabellen
        $stmt = $sourceDb->prepare("
            SELECT id, record_id, table_name, operation, old_data, attempts
            FROM `$queueTable`
            WHERE status = 'pending'
            ORDER BY created_at ASC
            LIMIT 100
        ");
        
        $stmt->execute();
        $queueItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $this->log("Found " . count($queueItems) . " pending queue items in $queueTable");
        
        foreach ($queueItems as $item) {
            $success = false;
            $tableName = $item['table_name'] ?? 'AV-ResNamen'; // Fallback für bestehende Einträge
            $operation = $item['operation'];
            
            // Initialisiere Tabelle wenn nicht in Liste
            if (!isset($result['tables'][$tableName])) {
                $result['tables'][$tableName] = [
                    'processed' => 0,
                    'operations' => ['insert' => 0, 'update' => 0, 'delete' => 0]
                ];
            }
            
            // Markiere als processing
            $this->updateQueueStatus($sourceDb, $queueTable, $item['id'], 'processing');
            
            try {
                switch ($operation) {
                    case 'insert':
                    case 'update':
                        $success = $this->syncInsertUpdateMultiTable($item['record_id'], $tableName, $sourceDb, $targetDb);
                        break;
                        
                    case 'delete':
                        $success = $this->syncDeleteMultiTable($item['record_id'], $tableName, $targetDb, $item['old_data']);
                        break;
                }
                
                if ($success) {
                    // Erfolgreich → Queue-Eintrag löschen
                    $this->deleteQueueItem($sourceDb, $queueTable, $item['id']);
                    $result['total_processed']++;
                    $result['tables'][$tableName]['processed']++;
                    $result['tables'][$tableName]['operations'][$operation]++;
                    $this->log("Queue item {$item['id']}: {$operation} {$tableName} record {$item['record_id']} SUCCESS");
                } else {
                    // Fehlgeschlagen → Retry-Counter erhöhen
                    $this->incrementQueueAttempts($sourceDb, $queueTable, $item['id']);
                    $this->log("Queue item {$item['id']}: {$operation} {$tableName} record {$item['record_id']} FAILED");
                }
                
            } catch (Exception $e) {
                $this->incrementQueueAttempts($sourceDb, $queueTable, $item['id']);
                $this->log("Queue item {$item['id']}: EXCEPTION: " . $e->getMessage());
            }
        }
        
        return $result;
    }
    
    // Queue INSERT/UPDATE Synchronisation mit Multi-Table Support
    private function syncInsertUpdateMultiTable($recordId, $tableName, $sourceDb, $targetDb) {
        // Hole aktuelle Daten
        $stmt = $sourceDb->prepare("SELECT * FROM `$tableName` WHERE id = ?");
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $sourceData = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$sourceData) {
            $this->log("Source record $recordId in $tableName not found - might be deleted");
            return true; // Behandeln als erfolgreich da Record nicht mehr existiert
        }
        
        // TRIGGER-SCHUTZ: Sync-Flag setzen
        $this->setSyncFlag($targetDb, true);
        
        try {
            // Spalten dynamisch ermitteln
            $sourceColumns = $this->getTableColumns($sourceDb, $tableName);
            $targetColumns = $this->getTableColumns($targetDb, $tableName);
            
            // Prüfe ob Target-Record existiert
            $stmt = $targetDb->prepare("SELECT id FROM `$tableName` WHERE id = ?");
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($exists) {
                return $this->updateRecordDynamicMultiTable($targetDb, $sourceData, $tableName, 'queue', $sourceColumns, $targetColumns);
            } else {
                return $this->insertRecordDynamicMultiTable($targetDb, $sourceData, $tableName, 'queue', $sourceColumns, $targetColumns);
            }
            
        } finally {
            // TRIGGER-SCHUTZ: Flag immer zurücksetzen
            $this->setSyncFlag($targetDb, false);
        }
    }
    
    // Queue DELETE Synchronisation mit Multi-Table Support
    private function syncDeleteMultiTable($recordId, $tableName, $targetDb, $oldData) {
        $this->log("Deleting record $recordId from $tableName" . ($oldData ? " (was: $oldData)" : ""));
        
        // TRIGGER-SCHUTZ: Sync-Flag setzen
        $this->setSyncFlag($targetDb, true);
        
        try {
            $stmt = $targetDb->prepare("DELETE FROM `$tableName` WHERE id = ?");
            $stmt->bind_param('i', $recordId);
            $result = $stmt->execute();
            $affectedRows = $targetDb->affected_rows;
            $stmt->close();
            
            if ($result && $affectedRows > 0) {
                $this->log("DELETE record $recordId from $tableName: SUCCESS ($affectedRows rows)");
                return true;
            } else if ($result && $affectedRows === 0) {
                $this->log("DELETE record $recordId from $tableName: SUCCESS (record already deleted)");
                return true; // Record war schon gelöscht
            } else {
                $this->log("DELETE record $recordId from $tableName: FAILED");
                return false;
            }
            
        } finally {
            // TRIGGER-SCHUTZ: Flag immer zurücksetzen
            $this->setSyncFlag($targetDb, false);
        }
    }
    
    // Legacy Methoden für Rückwärtskompatibilität
    private function syncInsertUpdate($recordId, $sourceDb, $targetDb) {
        return $this->syncInsertUpdateMultiTable($recordId, 'AV-ResNamen', $sourceDb, $targetDb);
    }
    
    private function syncDelete($recordId, $targetDb, $oldData) {
        return $this->syncDeleteMultiTable($recordId, 'AV-ResNamen', $targetDb, $oldData);
    }
    
    // Trigger-Schutz Methoden
    private function setSyncFlag($db, $active) {
        if ($active) {
            $db->query("SET @sync_in_progress = 1");
            $this->log("🛡️ Sync flag SET - triggers disabled");
        } else {
            $db->query("SET @sync_in_progress = NULL");
            $this->log("🛡️ Sync flag CLEARED - triggers enabled");
        }
    }
    
    // Queue Management Methoden
    private function updateQueueStatus($db, $queueTable, $queueId, $status) {
        $stmt = $db->prepare("UPDATE `$queueTable` SET status = ?, last_attempt = NOW() WHERE id = ?");
        $stmt->bind_param('si', $status, $queueId);
        $stmt->execute();
        $stmt->close();
    }
    
    private function deleteQueueItem($db, $queueTable, $queueId) {
        $stmt = $db->prepare("DELETE FROM `$queueTable` WHERE id = ?");
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $stmt->close();
    }
    
    private function incrementQueueAttempts($db, $queueTable, $queueId) {
        $stmt = $db->prepare("
            UPDATE `$queueTable` 
            SET attempts = attempts + 1, 
                status = CASE WHEN attempts >= 2 THEN 'failed' ELSE 'pending' END,
                last_attempt = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $stmt->close();
    }

    
    private function syncFromTimestamp($sourceDb, $targetDb, $from, $to, $tableName = 'AV-ResNamen') {
        $lastSyncTime = date('Y-m-d H:i:s', strtotime('-48 hours'));
        return $this->syncFromTimestampCustom($sourceDb, $targetDb, $from, $to, $lastSyncTime, $tableName);
    }
    
    private function syncFromTimestampCustom($sourceDb, $targetDb, $from, $to, $customTime, $tableName = 'AV-ResNamen') {
        $synced = 0;
        
        $this->log("=== Starting syncFromTimestamp: $from → $to for $tableName ===");
        
        // Prüfe ob Tabelle existiert
        $result = $sourceDb->query("SHOW TABLES LIKE '$tableName'");
        if ($result->num_rows === 0) {
            $this->log("$tableName table not found in $from database");
            return 0;
        }
        $this->log("✓ $tableName table found in $from database");
        
        // Hole alle Spalten der Tabelle um dynamisch zu arbeiten
        $columns = $this->getTableColumns($sourceDb, $tableName);
        $this->log("Available columns in $from $tableName: " . implode(', ', $columns));
        
        // Prüfe ob sync_timestamp Spalte existiert
        if (!in_array('sync_timestamp', $columns)) {
            $this->log("sync_timestamp column not found in $from $tableName");
            return 0;
        }
        $this->log("✓ sync_timestamp column found in $from database");
        
        // Hole Records mit custom time
        $lastSyncTime = $customTime;
        $this->log("Looking for records newer than: $lastSyncTime");
        
        $stmt = $sourceDb->prepare("
            SELECT id, sync_timestamp, sync_source 
            FROM `$tableName` 
            WHERE sync_timestamp > ? 
            ORDER BY sync_timestamp ASC
            LIMIT 50
        ");
        
        $stmt->bind_param('s', $lastSyncTime);
        $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $this->log("Found " . count($records) . " records to potentially sync from $from to $to");
        
        if (count($records) === 0) {
            $this->log("No records found to sync");
            return 0;
        }
        
        foreach ($records as $record) {
            $recordId = $record['id'];
            $this->log("Processing record $recordId (source: " . ($record['sync_source'] ?? 'NULL') . ", timestamp: " . $record['sync_timestamp'] . ")");
            
            // Prüfe ob Record bereits im Target existiert
            $stmt = $targetDb->prepare("SELECT id, sync_timestamp FROM `$tableName` WHERE id = ?");
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $targetRecord = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $shouldSync = false;
            if (!$targetRecord) {
                $shouldSync = true;
                $this->log("Record $recordId: NOT FOUND in target, will INSERT");
            } else {
                $sourceTime = strtotime($record['sync_timestamp']);
                $targetTime = strtotime($targetRecord['sync_timestamp'] ?? '1970-01-01');
                $this->log("Record $recordId: Source time: " . date('Y-m-d H:i:s', $sourceTime) . ", Target time: " . date('Y-m-d H:i:s', $targetTime));
                
                if ($sourceTime > $targetTime) {
                    $shouldSync = true;
                    $this->log("Record $recordId: SOURCE NEWER, will UPDATE");
                } else {
                    $this->log("Record $recordId: target is up-to-date, SKIPPING");
                }
            }
            
            if ($shouldSync) {
                if ($this->syncSingleRecord($recordId, $sourceDb, $targetDb, $from, $to, $columns, $tableName, !$targetRecord)) {
                    $synced++;
                    $this->log("Record $recordId: SYNC SUCCESS ✓");
                } else {
                    $this->log("Record $recordId: SYNC FAILED ✗");
                }
            }
        }
        
        $this->log("=== Completed syncFromTimestamp: $from → $to for $tableName, synced $synced records ===");
        return $synced;
    }
    
    private function getTableColumns($db, $tableName) {
        $columns = [];
        $result = $db->query("SHOW COLUMNS FROM `$tableName`");
        
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
        
        return $columns;
    }
    
    private function syncSingleRecord($recordId, $sourceDb, $targetDb, $from, $to, $columns, $tableName, $isInsert) {
        try {
            // Hole komplette Source-Daten
            $stmt = $sourceDb->prepare("SELECT * FROM `$tableName` WHERE id = ?");
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $sourceData = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$sourceData) {
                $this->log("ERROR: Source record $recordId not found");
                return false;
            }
            
            // Target-Spalten prüfen
            $targetColumns = $this->getTableColumns($targetDb, $tableName);
            
            if ($isInsert) {
                return $this->insertRecordDynamicMultiTable($targetDb, $sourceData, $tableName, $to, $columns, $targetColumns);
            } else {
                return $this->updateRecordDynamicMultiTable($targetDb, $sourceData, $tableName, $to, $columns, $targetColumns);
            }
            
        } catch (Exception $e) {
            $this->log("ERROR syncing record $recordId: " . $e->getMessage());
            return false;
        }
    }
    

    
    private function insertRecordDynamic($db, $data, $source, $sourceColumns, $targetColumns) {
        return $this->insertRecordDynamicMultiTable($db, $data, 'AV-ResNamen', $source, $sourceColumns, $targetColumns);
    }
    
    private function updateRecordDynamic($db, $data, $source, $sourceColumns, $targetColumns) {
        return $this->updateRecordDynamicMultiTable($db, $data, 'AV-ResNamen', $source, $sourceColumns, $targetColumns);
    }
    
    // Multi-Table INSERT Methode
    private function insertRecordDynamicMultiTable($db, $data, $tableName, $source, $sourceColumns, $targetColumns) {
        try {
            // Nur Spalten verwenden die in beiden Tabellen existieren (außer sync_timestamp)
            $commonColumns = array_intersect($sourceColumns, $targetColumns);
            $commonColumns = array_filter($commonColumns, function($col) {
                return $col !== 'sync_timestamp'; // wird durch NOW() gesetzt
            });
            
            $this->log("INSERT $tableName using columns: " . implode(', ', $commonColumns));
            
            $columnList = '`' . implode('`, `', $commonColumns) . '`, `sync_timestamp`';
            $placeholders = str_repeat('?,', count($commonColumns)) . 'NOW()';
            
            $sql = "INSERT INTO `$tableName` ($columnList) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);
            
            if (!$stmt) {
                $this->log("PREPARE ERROR: " . $db->error);
                return false;
            }
            
            // Parameter sammeln
            $values = [];
            $types = '';
            foreach ($commonColumns as $col) {
                $values[] = $data[$col] ?? null;
                $types .= 's'; // Alle als String, MySQL konvertiert automatisch
            }
            
            if (!empty($values)) {
                $stmt->bind_param($types, ...$values);
            }
            
            $result = $stmt->execute();
            if (!$result) {
                $this->log("EXECUTE ERROR: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
            $stmt->close();
            $this->log("INSERT SUCCESS for $tableName record {$data['id']}");
            return true;
            
        } catch (Exception $e) {
            $this->log("INSERT EXCEPTION: " . $e->getMessage());
            return false;
        }
    }
    
    // Multi-Table UPDATE Methode
    private function updateRecordDynamicMultiTable($db, $data, $tableName, $source, $sourceColumns, $targetColumns) {
        try {
            $primaryKey = $this->getPrimaryKey($tableName);
            
            // Nur Spalten verwenden die in beiden Tabellen existieren (außer primary key und sync_timestamp)
            $commonColumns = array_intersect($sourceColumns, $targetColumns);
            $commonColumns = array_filter($commonColumns, function($col) use ($primaryKey) {
                return $col !== $primaryKey && $col !== 'sync_timestamp';
            });
            
            $this->log("UPDATE $tableName using columns: " . implode(', ', $commonColumns));
            
            $setClause = [];
            foreach ($commonColumns as $col) {
                $setClause[] = "`$col` = ?";
            }
            $setClause[] = "`sync_timestamp` = NOW()";
            
            $sql = "UPDATE `$tableName` SET " . implode(', ', $setClause) . " WHERE `$primaryKey` = ?";
            $stmt = $db->prepare($sql);
            
            if (!$stmt) {
                $this->log("PREPARE ERROR: " . $db->error);
                return false;
            }
            
            // Parameter sammeln
            $values = [];
            $types = '';
            foreach ($commonColumns as $col) {
                $values[] = $data[$col] ?? null;
                $types .= 's';
            }
            // Primary Key für WHERE clause (dynamisch je nach Tabelle)
            $values[] = $data[$primaryKey];
            $types .= 'i';
            
            $stmt->bind_param($types, ...$values);
            $result = $stmt->execute();
            
            if (!$result) {
                $this->log("EXECUTE ERROR: " . $stmt->error);
                $stmt->close();
                return false;
            }
            
            $stmt->close();
            $this->log("UPDATE SUCCESS for $tableName record {$data[$primaryKey]}");
            return true;
            
        } catch (Exception $e) {
            $this->log("UPDATE EXCEPTION: " . $e->getMessage());
            return false;
        }
    }
    

    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logDir = dirname($this->logFile);
        
        // Try to create log directory if it doesn't exist
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        // Try to write log, but don't fail if it can't
        @file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
        
        // Also log to error_log as fallback
        error_log("[SyncManager] $message");
    }
    
    // Erstelle eine zusammenfassende Übersicht
    private function createSyncSummary($results) {
        $summary = [
            'sync_mode' => $results['sync_mode'],
            'tables_processed' => [],
            'total_operations' => [
                'local_to_remote' => $results['local_to_remote']['total_processed'],
                'remote_to_local' => $results['remote_to_local']['total_processed']
            ],
            'table_details' => []
        ];
        
        // Sammle alle betroffenen Tabellen
        $allTables = array_unique(array_merge(
            array_keys($results['local_to_remote']['tables']),
            array_keys($results['remote_to_local']['tables'])
        ));
        
        foreach ($allTables as $tableName) {
            $localOps = $results['local_to_remote']['tables'][$tableName] ?? ['processed' => 0, 'operations' => ['insert' => 0, 'update' => 0, 'delete' => 0]];
            $remoteOps = $results['remote_to_local']['tables'][$tableName] ?? ['processed' => 0, 'operations' => ['insert' => 0, 'update' => 0, 'delete' => 0]];
            
            $totalProcessed = $localOps['processed'] + $remoteOps['processed'];
            
            if ($totalProcessed > 0) {
                $summary['tables_processed'][] = $tableName;
            }
            
            $summary['table_details'][$tableName] = [
                'total_processed' => $totalProcessed,
                'local_to_remote' => $localOps['processed'],
                'remote_to_local' => $remoteOps['processed'],
                'operations' => [
                    'insert' => $localOps['operations']['insert'] + $remoteOps['operations']['insert'],
                    'update' => $localOps['operations']['update'] + $remoteOps['operations']['update'],
                    'delete' => $localOps['operations']['delete'] + $remoteOps['operations']['delete']
                ]
            ];
        }
        
        return $summary;
    }
    
    // Logge detaillierte Ergebnisse in schönem Format
    private function logDetailedResults($results) {
        $this->log("📊 ===== SYNC RESULTS SUMMARY =====");
        $this->log("🔧 Sync Mode: " . strtoupper($results['sync_mode']));
        $this->log("📅 Timestamp: " . $results['timestamp']);
        $this->log("📋 Configured Tables: " . implode(', ', $results['tables_configured']));
        
        $summary = $results['summary'];
        $this->log("📈 Total Operations: Local→Remote: {$summary['total_operations']['local_to_remote']}, Remote→Local: {$summary['total_operations']['remote_to_local']}");
        
        if (!empty($summary['tables_processed'])) {
            $this->log("📊 Tables with Activity: " . implode(', ', $summary['tables_processed']));
            
            foreach ($summary['table_details'] as $tableName => $details) {
                if ($details['total_processed'] > 0) {
                    $ops = $details['operations'];
                    $this->log("  📋 $tableName: Total={$details['total_processed']} | L→R={$details['local_to_remote']} | R→L={$details['remote_to_local']} | I={$ops['insert']} U={$ops['update']} D={$ops['delete']}");
                }
            }
        } else {
            $this->log("✅ No sync operations needed - all tables up to date");
        }
        
        $this->log("📊 ===== END SYNC SUMMARY =====");
    }
    
    public function __destruct() {
        if ($this->localDb) $this->localDb->close();
        if ($this->remoteDb) $this->remoteDb->close();
    }
}
?>
