<?php
/**
 * AV-Res Backup und Restore System
 * Erstellt Sicherheitskopien der AV-Res Tabelle mit Zeitstempel
 */

require_once '../config.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create_backup':
        createBackup();
        break;
    case 'list_backups':
        listBackups();
        break;
    case 'restore_backup':
        $backupName = $_POST['backup_name'] ?? '';
        restoreBackup($backupName);
        break;
    case 'delete_backup':
        $backupName = $_POST['backup_name'] ?? '';
        deleteBackup($backupName);
        break;
    default:
        echo json_encode(['error' => 'Ungültige Aktion']);
        break;
}

function createBackup() {
    global $mysqli;
    
    try {
        $timestamp = date('Y-m-d_H-i-s');
        $backupTableName = "AV_Res_Backup_$timestamp";
        
        // Backup-Tabelle erstellen
        $sql = "CREATE TABLE `$backupTableName` LIKE `AV-Res`";
        if (!$mysqli->query($sql)) {
            throw new Exception("Fehler beim Erstellen der Backup-Tabelle: " . $mysqli->error);
        }
        
        // Daten kopieren
        $sql = "INSERT INTO `$backupTableName` SELECT * FROM `AV-Res`";
        if (!$mysqli->query($sql)) {
            throw new Exception("Fehler beim Kopieren der Daten: " . $mysqli->error);
        }
        
        // Anzahl Datensätze prüfen
        $result = $mysqli->query("SELECT COUNT(*) as count FROM `$backupTableName`");
        $count = $result->fetch_assoc()['count'];
        
        echo json_encode([
            'success' => true,
            'backup_name' => $backupTableName,
            'timestamp' => $timestamp,
            'record_count' => $count,
            'message' => "Backup erfolgreich erstellt: $backupTableName ($count Datensätze)"
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function listBackups() {
    global $mysqli;
    
    try {
        // Alle Backup-Tabellen finden
        $result = $mysqli->query("SHOW TABLES LIKE 'AV_Res_Backup_%'");
        $backups = [];
        
        while ($row = $result->fetch_array()) {
            $tableName = $row[0];
            
            // Datensatz-Anzahl ermitteln
            $countResult = $mysqli->query("SELECT COUNT(*) as count FROM `$tableName`");
            $count = $countResult->fetch_assoc()['count'];
            
            // Erstellungszeit aus Tabellennamen extrahieren
            if (preg_match('/AV_Res_Backup_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $tableName, $matches)) {
                $timestamp = $matches[1];
                $datetime = DateTime::createFromFormat('Y-m-d_H-i-s', $timestamp);
                $readableDate = $datetime ? $datetime->format('d.m.Y H:i:s') : $timestamp;
            } else {
                $readableDate = 'Unbekannt';
            }
            
            $backups[] = [
                'table_name' => $tableName,
                'timestamp' => $timestamp ?? 'unknown',
                'readable_date' => $readableDate,
                'record_count' => $count
            ];
        }
        
        // Nach Datum sortieren (neueste zuerst)
        usort($backups, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
        
        echo json_encode([
            'success' => true,
            'backups' => $backups
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function restoreBackup($backupName) {
    global $mysqli;
    
    try {
        if (empty($backupName)) {
            throw new Exception("Backup-Name nicht angegeben");
        }
        
        // Sicherheitscheck: Backup-Tabelle existiert?
        $result = $mysqli->query("SHOW TABLES LIKE '$backupName'");
        if ($result->num_rows === 0) {
            throw new Exception("Backup-Tabelle '$backupName' nicht gefunden");
        }
        
        // Vor Restore ein Backup der aktuellen Daten erstellen
        $preRestoreBackup = "AV_Res_PreRestore_" . date('Y-m-d_H-i-s');
        $mysqli->query("CREATE TABLE `$preRestoreBackup` LIKE `AV-Res`");
        $mysqli->query("INSERT INTO `$preRestoreBackup` SELECT * FROM `AV-Res`");
        
        // Transaction starten
        $mysqli->begin_transaction();
        
        // Aktuelle Daten löschen
        if (!$mysqli->query("DELETE FROM `AV-Res`")) {
            throw new Exception("Fehler beim Löschen der aktuellen Daten: " . $mysqli->error);
        }
        
        // Backup-Daten wiederherstellen
        if (!$mysqli->query("INSERT INTO `AV-Res` SELECT * FROM `$backupName`")) {
            throw new Exception("Fehler beim Wiederherstellen der Backup-Daten: " . $mysqli->error);
        }
        
        // Anzahl wiederhergestellter Datensätze
        $result = $mysqli->query("SELECT COUNT(*) as count FROM `AV-Res`");
        $count = $result->fetch_assoc()['count'];
        
        $mysqli->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Backup erfolgreich wiederhergestellt: $count Datensätze",
            'pre_restore_backup' => $preRestoreBackup,
            'restored_count' => $count
        ]);
        
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function deleteBackup($backupName) {
    global $mysqli;
    
    try {
        if (empty($backupName)) {
            throw new Exception("Backup-Name nicht angegeben");
        }
        
        // Sicherheitscheck: Nur Backup-Tabellen löschen
        if (!preg_match('/^AV_Res_(Backup|PreRestore)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $backupName)) {
            throw new Exception("Ungültiger Backup-Name: $backupName");
        }
        
        if (!$mysqli->query("DROP TABLE IF EXISTS `$backupName`")) {
            throw new Exception("Fehler beim Löschen der Backup-Tabelle: " . $mysqli->error);
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Backup '$backupName' erfolgreich gelöscht"
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
?>
