<?php
/**
 * Backup-Vergleichsanalyse für WebImp Import-Validierung
 * Vergleicht Datensätze zwischen verschiedenen Backup-Zuständen
 */

require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create_backup':
        createBackup();
        break;
    case 'list_backups':
        listBackups();
        break;
    case 'restore_backup':
        restoreBackup();
        break;
    case 'delete_backup':
        deleteBackup();
        break;
    case 'list_backups_for_analysis':
        listBackupsForAnalysis();
        break;
        break;
    case 'compare_backups':
        $baseline = $_POST['baseline'] ?? '';
        $afterOld = $_POST['after_old'] ?? '';
        $afterNew = $_POST['after_new'] ?? '';
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        
        if (empty($baseline) || empty($afterOld) || empty($afterNew)) {
            echo json_encode(['success' => false, 'error' => 'Alle drei Backups müssen ausgewählt werden']);
            exit;
        }
        
        compareBackups($baseline, $afterOld, $afterNew, $startDate, $endDate);
        break;
        
    case 'analyze_daily_occupancy':
        $baseline = $_POST['baseline'] ?? '';
        $afterOld = $_POST['after_old'] ?? '';
        $afterNew = $_POST['after_new'] ?? '';
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        
        if (empty($baseline) || empty($afterOld) || empty($afterNew)) {
            echo json_encode(['success' => false, 'error' => 'Alle drei Backups müssen ausgewählt werden']);
            exit;
        }
        
        analyzeDailyOccupancy($baseline, $afterOld, $afterNew, $startDate, $endDate);
        break;
        
    case 'analyze_backup_content':
        $backup_name = $_POST['backup_name'] ?? '';
        if (empty($backup_name)) {
            echo json_encode(['success' => false, 'error' => 'Backup-Name erforderlich']);
            exit;
        }
        analyzeBackupContent($backup_name);
        break;
        
    case 'debug_problematic_records':
        debugProblematicRecords();
        break;
    case 'analyze_specific_records':
        $av_ids = $_POST['av_ids'] ?? '';
        analyzeSpecificRecords($av_ids);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Unbekannte Aktion']);
}

function listBackupsForAnalysis() {
    global $mysqli;
    
    $backups = [];
    
    // Suche nach beiden Namensformaten
    $patterns = [
        'AV_Res_Backup_%',      // Neue Format
        'av_res_backup_%'        // Alte Format falls vorhanden
    ];
    
    foreach ($patterns as $pattern) {
        $tablesResult = $mysqli->query("SHOW TABLES LIKE '$pattern'");
        
        while ($row = $tablesResult->fetch_array()) {
            $tableName = $row[0];
            
            // Parse verschiedene Formate
            if (preg_match('/AV_Res_Backup_(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/', $tableName, $matches)) {
                $timestamp = sprintf('%s-%s-%s %s:%s:%s', $matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6]);
            } elseif (preg_match('/av_res_backup_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $tableName, $matches)) {
                $timestamp = sprintf('%s-%s-%s %s:%s:%s', $matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6]);
            } else {
                continue; // Überspringe unbekannte Formate
            }
            
            // Anzahl Datensätze ermitteln
            $countResult = $mysqli->query("SELECT COUNT(*) as count FROM `$tableName`");
            $count = $countResult ? $countResult->fetch_assoc()['count'] : 0;
            
            $backups[] = [
                'name' => $tableName,
                'timestamp' => $timestamp,
                'count' => $count
            ];
        }
    }
    
    // Nach Timestamp sortieren (neueste zuerst)
    usort($backups, function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });
    
    echo json_encode(['success' => true, 'backups' => $backups]);
}

function compareBackups($baseline, $afterOld, $afterNew, $startDate, $endDate) {
    global $mysqli;
    
    $analysis = [
        'date_range' => ['start' => $startDate, 'end' => $endDate],
        'backups' => ['baseline' => $baseline, 'after_old' => $afterOld, 'after_new' => $afterNew],
        'record_counts' => [],
        'field_changes' => [],
        'daily_occupancy' => []
    ];
    
    $dateFilter = '';
    if (!empty($startDate) && !empty($endDate)) {
        $dateFilter = "WHERE av_id > 0 AND anreise >= '$startDate' AND abreise <= '$endDate'";
    } else {
        $dateFilter = "WHERE av_id > 0";
    }
    
    // Datensatz-Anzahlen ermitteln
    foreach (['baseline' => $baseline, 'after_old' => $afterOld, 'after_new' => $afterNew] as $key => $table) {
        $result = $mysqli->query("SELECT COUNT(*) as count FROM `$table` $dateFilter");
        $analysis['record_counts'][$key] = $result ? $result->fetch_assoc()['count'] : 0;
    }
    
    // Feld-Änderungen analysieren
    $analysis['field_changes'] = analyzeFieldChanges($baseline, $afterOld, $afterNew, $dateFilter);
    
    // Tägliche Belegung analysieren
    $analysis['daily_occupancy'] = analyzeDailyOccupancyData($baseline, $afterOld, $afterNew, $startDate, $endDate);
    
    // Detaillierte Änderungs-Analyse
    $analysis['changes_old_vs_baseline'] = compareBackupRecords($baseline, $afterOld, $startDate, $endDate);
    $analysis['changes_new_vs_baseline'] = compareBackupRecords($baseline, $afterNew, $startDate, $endDate);
    $analysis['changes_new_vs_old'] = compareBackupRecords($afterOld, $afterNew, $startDate, $endDate);
    
    echo json_encode(['success' => true, 'analysis' => $analysis]);
}

function analyzeFieldChanges($baseline, $afterOld, $afterNew, $dateFilter) {
    global $mysqli;
    
    $changes = [
        'old_vs_baseline' => [],
        'new_vs_baseline' => [],
        'new_vs_old' => []
    ];
    
    $fields = ['storno', 'lager', 'betten', 'dz', 'sonder'];
    
    // Convert dateFilter for JOINs with table prefixes
    $andDateFilter = '';
    if (!empty($dateFilter)) {
        $andDateFilter = str_replace('WHERE av_id > 0 AND anreise >=', 'AND b.av_id > 0 AND b.anreise >=', $dateFilter);
        $andDateFilter = str_replace('AND abreise <=', 'AND b.abreise <=', $andDateFilter);
        $andDateFilter = str_replace('WHERE av_id > 0', 'AND b.av_id > 0', $andDateFilter);
    }
    
    foreach ($fields as $field) {
        // Alte Methode vs Baseline
        $sql = "
            SELECT 
                COUNT(*) as changes
            FROM `$baseline` b
            JOIN `$afterOld` o ON b.av_id = o.av_id
            WHERE b.$field != o.$field $andDateFilter
        ";
        $result = $mysqli->query($sql);
        $changes['old_vs_baseline'][$field] = $result ? $result->fetch_assoc()['changes'] : 0;
        
        // Neue Methode vs Baseline  
        $sql = "
            SELECT 
                COUNT(*) as changes
            FROM `$baseline` b
            JOIN `$afterNew` n ON b.av_id = n.av_id
            WHERE b.$field != n.$field $andDateFilter
        ";
        $result = $mysqli->query($sql);
        $changes['new_vs_baseline'][$field] = $result ? $result->fetch_assoc()['changes'] : 0;
        
        // Neue vs Alte Methode
        $andDateFilterOld = '';
        if (!empty($dateFilter)) {
            $andDateFilterOld = str_replace('WHERE av_id > 0 AND anreise >=', 'AND o.av_id > 0 AND o.anreise >=', $dateFilter);
            $andDateFilterOld = str_replace('AND abreise <=', 'AND o.abreise <=', $andDateFilterOld);
            $andDateFilterOld = str_replace('WHERE av_id > 0', 'AND o.av_id > 0', $andDateFilterOld);
        }
        
        $sql = "
            SELECT 
                COUNT(*) as changes
            FROM `$afterOld` o
            JOIN `$afterNew` n ON o.av_id = n.av_id
            WHERE o.$field != n.$field $andDateFilterOld
        ";
        $result = $mysqli->query($sql);
        $changes['new_vs_old'][$field] = $result ? $result->fetch_assoc()['changes'] : 0;
    }
    
    return $changes;
}

function analyzeDailyOccupancyData($baseline, $afterOld, $afterNew, $startDate, $endDate) {
    global $mysqli;
    
    $daily = [];
    
    if (empty($startDate) || empty($endDate)) {
        return $daily;
    }
    
    $currentDate = $startDate;
    while ($currentDate <= $endDate) {
        $dateData = [
            'date' => $currentDate,
            'baseline' => getOccupancyForDate($baseline, $currentDate),
            'after_old' => getOccupancyForDate($afterOld, $currentDate),
            'after_new' => getOccupancyForDate($afterNew, $currentDate)
        ];
        
        $daily[] = $dateData;
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }
    
    return $daily;
}

function getOccupancyForDate($tableName, $date) {
    global $mysqli;
    
    $sql = "
        SELECT 
            COALESCE(SUM(CASE WHEN storno = 0 THEN lager ELSE 0 END), 0) as lager,
            COALESCE(SUM(CASE WHEN storno = 0 THEN betten ELSE 0 END), 0) as betten,
            COALESCE(SUM(CASE WHEN storno = 0 THEN dz ELSE 0 END), 0) as dz,
            COALESCE(SUM(CASE WHEN storno = 0 THEN sonder ELSE 0 END), 0) as sonder
        FROM `$tableName`
        WHERE av_id > 0 AND anreise <= '$date' AND abreise >= '$date'
    ";
    
    $result = $mysqli->query($sql);
    if ($result) {
        $row = $result->fetch_assoc();
        return [
            'lager' => (int)$row['lager'],
            'betten' => (int)$row['betten'], 
            'dz' => (int)$row['dz'],
            'sonder' => (int)$row['sonder'],
            'total' => (int)$row['lager'] + (int)$row['betten'] + (int)$row['dz'] + (int)$row['sonder']
        ];
    }
    
    return ['lager' => 0, 'betten' => 0, 'dz' => 0, 'sonder' => 0, 'total' => 0];
}

function formatOccupancyTypes($lager, $betten, $dz, $sonder) {
    $types = [];
    if ($dz > 0) $types[] = "DZ($dz)";
    if ($betten > 0) $types[] = "Bett($betten)";
    if ($lager > 0) $types[] = "Lager($lager)";
    if ($sonder > 0) $types[] = "Sonder($sonder)";
    return implode('+', $types) ?: 'Keine';
}

function analyzeBackupContent($backupName) {
    global $mysqli;
    
    $analysis = [
        'backup_name' => $backupName,
        'timestamp' => date('Y-m-d H:i:s'),
        'record_count' => 0,
        'date_range' => ['min' => null, 'max' => null],
        'field_stats' => [],
        'sample_records' => []
    ];
    
    // Grundstatistiken
    $result = $mysqli->query("SELECT COUNT(*) as count FROM `$backupName`");
    $analysis['record_count'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Datumsbereich
    $result = $mysqli->query("SELECT MIN(anreise) as min_date, MAX(abreise) as max_date FROM `$backupName`");
    if ($result) {
        $row = $result->fetch_assoc();
        $analysis['date_range'] = ['min' => $row['min_date'], 'max' => $row['max_date']];
    }
    
    // Feld-Statistiken
    $fields = ['storno', 'lager', 'betten', 'dz', 'sonder'];
    foreach ($fields as $field) {
        $result = $mysqli->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN $field > 0 THEN 1 ELSE 0 END) as non_zero,
                MIN($field) as min_val,
                MAX($field) as max_val,
                AVG($field) as avg_val
            FROM `$backupName`
        ");
        
        if ($result) {
            $analysis['field_stats'][$field] = $result->fetch_assoc();
        }
    }
    
    // Beispiel-Datensätze
    $result = $mysqli->query("SELECT * FROM `$backupName` ORDER BY av_id LIMIT 5");
    while ($row = $result->fetch_assoc()) {
        $analysis['sample_records'][] = $row;
    }
    
    echo json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function debugProblematicRecords() {
    global $mysqli;
    
    $debug = [
        'timestamp' => date('Y-m-d H:i:s'),
        'problematic_records' => [],
        'field_analysis' => [],
        'encoding_issues' => [],
        'length_violations' => []
    ];
    
    // 1. Prüfe WebImp Tabelle auf problematische Datensätze
    $webimpSql = "
        SELECT 
            av_id,
            nachname,
            vorname,
            LENGTH(bem_av) as bem_length,
            bem_av,
            LENGTH(nachname) as nachname_length,
            LENGTH(vorname) as vorname_length,
            LENGTH(email) as email_length,
            LENGTH(handy) as handy_length,
            vorgang,
            HEX(SUBSTRING(bem_av, 1, 50)) as bem_hex_sample
        FROM `AV-Res-webImp`
        WHERE 
            av_id <= 0 
            OR LENGTH(bem_av) > 500
            OR bem_av REGEXP '[^[:print:][:space:]]'
            OR nachname LIKE '%Haas%'
            OR nachname LIKE '%Georgi%'
            OR bem_av LIKE '%[0-9]%'
        ORDER BY av_id
    ";
    
    $result = $mysqli->query($webimpSql);
    while ($row = $result->fetch_assoc()) {
        $issues = [];
        
        // Verschiedene Probleme identifizieren
        if ($row['av_id'] <= 0) {
            $issues[] = 'Ungültige AV-ID (≤0)';
        }
        
        if ($row['bem_length'] > 500) {
            $issues[] = "Bemerkung zu lang ({$row['bem_length']} Zeichen)";
        }
        
        if (preg_match('/[^\x20-\x7E\x80-\xFF]/', $row['bem_av'])) {
            $issues[] = 'Nicht-druckbare Zeichen in Bemerkung';
        }
        
        if (strpos($row['bem_av'], '"') !== false || strpos($row['bem_av'], "'") !== false) {
            $issues[] = 'Anführungszeichen in Bemerkung';
        }
        
        if (strpos($row['bem_av'], "\\") !== false) {
            $issues[] = 'Backslashes in Bemerkung';
        }
        
        if (preg_match('/[\r\n\t]/', $row['bem_av'])) {
            $issues[] = 'Zeilenumbrüche/Tabs in Bemerkung';
        }
        
        // Prüfe ob Datensatz in AV-Res existiert
        $existsInAvRes = false;
        if ($row['av_id'] > 0) {
            $checkSql = "SELECT av_id FROM `AV-Res` WHERE av_id = " . (int)$row['av_id'];
            $checkResult = $mysqli->query($checkSql);
            $existsInAvRes = $checkResult && $checkResult->num_rows > 0;
        }
        
        $debug['problematic_records'][] = [
            'av_id' => $row['av_id'],
            'name' => $row['nachname'] . ', ' . $row['vorname'],
            'issues' => $issues,
            'exists_in_av_res' => $existsInAvRes,
            'vorgang' => $row['vorgang'],
            'bem_length' => $row['bem_length'],
            'bem_preview' => mb_substr($row['bem_av'], 0, 100) . (strlen($row['bem_av']) > 100 ? '...' : ''),
            'bem_hex_sample' => $row['bem_hex_sample'],
            'field_lengths' => [
                'nachname' => $row['nachname_length'],
                'vorname' => $row['vorname_length'],
                'email' => $row['email_length'],
                'handy' => $row['handy_length']
            ]
        ];
    }
    
    // 2. Prüfe Feldlängen-Limits
    $fieldLimits = getFieldLimits();
    $debug['field_limits'] = $fieldLimits;
    
    // 3. Prüfe auf Encoding-Probleme
    $encodingCheckSql = "
        SELECT 
            av_id,
            nachname,
            vorname,
            bem_av,
            CHAR_LENGTH(bem_av) as char_length,
            LENGTH(bem_av) as byte_length
        FROM `AV-Res-webImp`
        WHERE CHAR_LENGTH(bem_av) != LENGTH(bem_av)
        LIMIT 10
    ";
    
    $encodingResult = $mysqli->query($encodingCheckSql);
    while ($row = $encodingResult->fetch_assoc()) {
        $debug['encoding_issues'][] = [
            'av_id' => $row['av_id'],
            'name' => $row['nachname'] . ', ' . $row['vorname'],
            'char_length' => $row['char_length'],
            'byte_length' => $row['byte_length'],
            'difference' => $row['byte_length'] - $row['char_length']
        ];
    }
    
    // 4. Statistiken
    $debug['statistics'] = [
        'total_webimp_records' => $mysqli->query("SELECT COUNT(*) as cnt FROM `AV-Res-webImp`")->fetch_assoc()['cnt'],
        'invalid_av_ids' => $mysqli->query("SELECT COUNT(*) as cnt FROM `AV-Res-webImp` WHERE av_id <= 0")->fetch_assoc()['cnt'],
        'long_bemerkungen' => $mysqli->query("SELECT COUNT(*) as cnt FROM `AV-Res-webImp` WHERE LENGTH(bem_av) > 500")->fetch_assoc()['cnt'],
        'confirmed_records' => $mysqli->query("SELECT COUNT(*) as cnt FROM `AV-Res-webImp` WHERE UPPER(TRIM(vorgang)) = 'CONFIRMED'")->fetch_assoc()['cnt'],
        'discarded_records' => $mysqli->query("SELECT COUNT(*) as cnt FROM `AV-Res-webImp` WHERE UPPER(TRIM(vorgang)) = 'DISCARDED'")->fetch_assoc()['cnt']
    ];
    
    echo json_encode($debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function getFieldLimits() {
    global $mysqli;
    
    $limits = [];
    
    // AV-Res Struktur
    $result = $mysqli->query("DESCRIBE `AV-Res`");
    while ($row = $result->fetch_assoc()) {
        $limits['AV-Res'][$row['Field']] = [
            'type' => $row['Type'],
            'null' => $row['Null'],
            'key' => $row['Key'],
            'default' => $row['Default']
        ];
    }
    
    // WebImp Struktur
    $result = $mysqli->query("DESCRIBE `AV-Res-webImp`");
    while ($row = $result->fetch_assoc()) {
        $limits['AV-Res-webImp'][$row['Field']] = [
            'type' => $row['Type'],
            'null' => $row['Null'],
            'key' => $row['Key'],
            'default' => $row['Default']
        ];
    }
    
    return $limits;
}

function analyzeDailyOccupancy($baseline, $afterOld, $afterNew, $startDate, $endDate) {
    global $mysqli;
    
    $analysis = [
        'date_range' => ['start' => $startDate, 'end' => $endDate],
        'backups' => ['baseline' => $baseline, 'after_old' => $afterOld, 'after_new' => $afterNew],
        'daily_data' => []
    ];
    
    if (empty($startDate) || empty($endDate)) {
        echo json_encode(['success' => false, 'error' => 'Start- und Enddatum erforderlich']);
        return;
    }
    
    $currentDate = $startDate;
    while ($currentDate <= $endDate) {
        $dateData = [
            'date' => $currentDate,
            'baseline' => getOccupancyForDate($baseline, $currentDate),
            'after_old' => getOccupancyForDate($afterOld, $currentDate),
            'after_new' => getOccupancyForDate($afterNew, $currentDate)
        ];
        
        // Unterschiede berechnen
        $dateData['differences'] = [
            'old_vs_baseline' => [
                'lager' => $dateData['after_old']['lager'] - $dateData['baseline']['lager'],
                'betten' => $dateData['after_old']['betten'] - $dateData['baseline']['betten'],
                'dz' => $dateData['after_old']['dz'] - $dateData['baseline']['dz'],
                'sonder' => $dateData['after_old']['sonder'] - $dateData['baseline']['sonder'],
                'total' => $dateData['after_old']['total'] - $dateData['baseline']['total']
            ],
            'new_vs_baseline' => [
                'lager' => $dateData['after_new']['lager'] - $dateData['baseline']['lager'],
                'betten' => $dateData['after_new']['betten'] - $dateData['baseline']['betten'],
                'dz' => $dateData['after_new']['dz'] - $dateData['baseline']['dz'],
                'sonder' => $dateData['after_new']['sonder'] - $dateData['baseline']['sonder'],
                'total' => $dateData['after_new']['total'] - $dateData['baseline']['total']
            ],
            'new_vs_old' => [
                'lager' => $dateData['after_new']['lager'] - $dateData['after_old']['lager'],
                'betten' => $dateData['after_new']['betten'] - $dateData['after_old']['betten'],
                'dz' => $dateData['after_new']['dz'] - $dateData['after_old']['dz'],
                'sonder' => $dateData['after_new']['sonder'] - $dateData['after_old']['sonder'],
                'total' => $dateData['after_new']['total'] - $dateData['after_old']['total']
            ]
        ];
        
        $analysis['daily_occupancy'][] = $dateData;
        $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
    }
    
    return $analysis;
}

// Hilfsfunktion zum Vergleichen zweier Backups und Identifizieren von Änderungen
function compareBackupRecords($baseline_table, $comparison_table, $date_start, $date_end) {
    global $mysqli;
    
    $changes = [
        'added' => [],
        'removed' => [],
        'modified' => [],
        'unchanged' => 0
    ];
    
    // Alle Records aus beiden Tabellen laden (AV-ID 0 ausschließen)
    $baseline_sql = "SELECT * FROM `$baseline_table` 
                     WHERE av_id > 0 AND anreise >= '$date_start' AND anreise <= '$date_end' 
                     ORDER BY av_id";
    $comparison_sql = "SELECT * FROM `$comparison_table` 
                       WHERE av_id > 0 AND anreise >= '$date_start' AND anreise <= '$date_end' 
                       ORDER BY av_id";
    
    $baseline_result = $mysqli->query($baseline_sql);
    $baseline_records = [];
    if ($baseline_result) {
        while ($row = $baseline_result->fetch_assoc()) {
            $baseline_records[$row['av_id']] = $row;
        }
    }
    
    $comparison_result = $mysqli->query($comparison_sql);
    $comparison_records = [];
    if ($comparison_result) {
        while ($row = $comparison_result->fetch_assoc()) {
            $comparison_records[$row['av_id']] = $row;
        }
    }
    
    // Hinzugefügte Records (nur in comparison)
    foreach ($comparison_records as $av_id => $record) {
        if (!isset($baseline_records[$av_id])) {
            $changes['added'][] = [
                'av_id' => $av_id,
                'name' => $record['nachname'] . ', ' . $record['vorname'],
                'anreise' => $record['anreise'],
                'abreise' => $record['abreise'],
                'storno' => $record['storno']
            ];
        }
    }
    
    // Entfernte Records (nur in baseline)
    foreach ($baseline_records as $av_id => $record) {
        if (!isset($comparison_records[$av_id])) {
            $changes['removed'][] = [
                'av_id' => $av_id,
                'name' => $record['nachname'] . ', ' . $record['vorname'],
                'anreise' => $record['anreise'],
                'abreise' => $record['abreise'],
                'storno' => $record['storno']
            ];
        }
    }
    
    // Geänderte Records (nur erste 50 zur Performance)
    $count = 0;
    foreach ($baseline_records as $av_id => $baseline_record) {
        if ($count >= 50) break; // Limit für Performance
        
        if (isset($comparison_records[$av_id])) {
            $comparison_record = $comparison_records[$av_id];
            $record_changes = [];
            
            // Wichtige Felder vergleichen
            $important_fields = ['nachname', 'vorname', 'anreise', 'abreise', 'storno', 'lager', 'betten', 'dz', 'sonder', 'bem'];
            
            foreach ($important_fields as $field) {
                if ($baseline_record[$field] != $comparison_record[$field]) {
                    $record_changes[] = [
                        'field' => $field,
                        'from' => $baseline_record[$field],
                        'to' => $comparison_record[$field]
                    ];
                }
            }
            
            if (!empty($record_changes)) {
                $changes['modified'][] = [
                    'av_id' => $av_id,
                    'name' => $comparison_record['nachname'] . ', ' . $comparison_record['vorname'],
                    'anreise' => $comparison_record['anreise'],
                    'abreise' => $comparison_record['abreise'],
                    'storno' => $comparison_record['storno'],
                    'status_old' => $baseline_record['storno'],
                    'changes' => $record_changes
                ];
                $count++;
            } else {
                $changes['unchanged']++;
            }
        }
    }
    
    return $changes;
}

// Spezifische Records analysieren (für Haas Michl/Monique Georgi Problem)
function analyzeSpecificRecords($av_ids_str) {
    global $mysqli;
    
    if (empty($av_ids_str)) {
        echo json_encode(['success' => false, 'error' => 'Keine AV-IDs angegeben']);
        return;
    }
    
    $av_ids = array_filter(array_map('trim', explode(',', $av_ids_str)), 'is_numeric');
    
    if (empty($av_ids)) {
        echo json_encode(['success' => false, 'error' => 'Keine gültigen AV-IDs gefunden']);
        return;
    }
    
    $placeholders = str_repeat('?,', count($av_ids) - 1) . '?';
    
    $analysis = [
        'timestamp' => date('Y-m-d H:i:s'),
        'requested_av_ids' => $av_ids,
        'records_found' => [],
        'backup_comparison' => []
    ];
    
    // In Haupt-Tabelle suchen
    $sql = "SELECT * FROM `AV-Res` WHERE av_id IN ($placeholders)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($av_ids)), ...$av_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $analysis['records_found'][] = [
            'table' => 'AV-Res',
            'av_id' => $row['av_id'],
            'name' => $row['nachname'] . ', ' . $row['vorname'],
            'anreise' => $row['anreise'],
            'abreise' => $row['abreise'],
            'storno' => $row['storno'],
            'lager' => $row['lager'],
            'betten' => $row['betten'],
            'dz' => $row['dz'],
            'sonder' => $row['sonder'],
            'bem' => substr($row['bem'], 0, 100) . (strlen($row['bem']) > 100 ? '...' : ''),
            'vorgang' => $row['vorgang']
        ];
    }
    
    // In WebImp-Tabelle suchen
    $sql = "SELECT * FROM `AV-Res-webImp` WHERE av_id IN ($placeholders)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($av_ids)), ...$av_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $analysis['records_found'][] = [
            'table' => 'AV-Res-webImp',
            'av_id' => $row['av_id'],
            'name' => $row['nachname'] . ', ' . $row['vorname'],
            'anreise' => $row['anreise'],
            'abreise' => $row['abreise'],
            'lager' => $row['lager'],
            'betten' => $row['betten'],
            'dz' => $row['dz'],
            'sonder' => $row['sonder'],
            'vorgang' => $row['vorgang']
        ];
    }
    
    // In Backup-Tabellen suchen
    $backup_tables = [];
    $result = $mysqli->query("SHOW TABLES LIKE 'AV_Res_Backup_%'");
    while ($row = $result->fetch_array()) {
        $backup_tables[] = $row[0];
    }
    
    // Neueste 3 Backups analysieren
    usort($backup_tables, function($a, $b) {
        return strcmp($b, $a); // Neueste zuerst
    });
    
    foreach (array_slice($backup_tables, 0, 3) as $backup_table) {
        $sql = "SELECT * FROM `$backup_table` WHERE av_id IN ($placeholders)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($av_ids)), ...$av_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $backup_records = [];
        while ($row = $result->fetch_assoc()) {
            $backup_records[] = [
                'av_id' => $row['av_id'],
                'name' => $row['nachname'] . ', ' . $row['vorname'],
                'anreise' => $row['anreise'],
                'abreise' => $row['abreise'],
                'storno' => $row['storno'],
                'lager' => $row['lager'],
                'betten' => $row['betten'],
                'dz' => $row['dz'],
                'sonder' => $row['sonder'],
                'bem' => substr($row['bem'], 0, 100) . (strlen($row['bem']) > 100 ? '...' : ''),
                'vorgang' => $row['vorgang']
            ];
        }
        
        if (!empty($backup_records)) {
            $analysis['backup_comparison'][] = [
                'backup_table' => $backup_table,
                'records' => $backup_records
            ];
        }
    }
    
    echo json_encode(['success' => true, 'analysis' => $analysis]);
}

// =====================================================
// BACKUP-MANAGEMENT FUNKTIONEN
// =====================================================

function createBackup() {
    global $mysqli;
    
    try {
        $backupName = 'AV_Res_Backup_' . date('Y-m-d_H-i-s');
        
        // Backup-Tabelle erstellen
        $createSql = "CREATE TABLE `$backupName` LIKE `AV-Res`";
        if (!$mysqli->query($createSql)) {
            throw new Exception("Fehler beim Erstellen der Backup-Tabelle: " . $mysqli->error);
        }
        
        // Daten kopieren
        $copySql = "INSERT INTO `$backupName` SELECT * FROM `AV-Res`";
        if (!$mysqli->query($copySql)) {
            // Tabelle wieder löschen bei Fehler
            $mysqli->query("DROP TABLE IF EXISTS `$backupName`");
            throw new Exception("Fehler beim Kopieren der Daten: " . $mysqli->error);
        }
        
        // Anzahl Datensätze ermitteln
        $countResult = $mysqli->query("SELECT COUNT(*) as count FROM `$backupName`");
        $count = $countResult->fetch_assoc()['count'];
        
        echo json_encode([
            'success' => true,
            'message' => "Backup '$backupName' erfolgreich erstellt",
            'backup_name' => $backupName,
            'record_count' => $count
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
        $backups = [];
        
        // Suche nach Backup-Tabellen
        $patterns = ['AV_Res_Backup_%', 'AV_Res_PreImport_%', 'AV_Res_PreRestore_%'];
        
        foreach ($patterns as $pattern) {
            $tablesResult = $mysqli->query("SHOW TABLES LIKE '$pattern'");
            if ($tablesResult) {
                while ($row = $tablesResult->fetch_array()) {
                    $tableName = $row[0];
                    
                    // Anzahl Datensätze ermitteln
                    $countResult = $mysqli->query("SELECT COUNT(*) as count FROM `$tableName`");
                    $count = $countResult ? $countResult->fetch_assoc()['count'] : 0;
                    
                    // Datum aus Tabellenname extrahieren
                    $readableDate = 'Unbekannt';
                    if (preg_match('/(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $tableName, $matches)) {
                        $readableDate = str_replace('_', ' ', $matches[1]);
                        $readableDate = str_replace('-', ':', substr($readableDate, 11)) . ' ' . substr($readableDate, 0, 10);
                    }
                    
                    $backups[] = [
                        'table_name' => $tableName,
                        'readable_date' => $readableDate,
                        'record_count' => $count
                    ];
                }
            }
        }
        
        // Nach Datum sortieren (neueste zuerst)
        usort($backups, function($a, $b) {
            return strcmp($b['table_name'], $a['table_name']);
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

function restoreBackup() {
    global $mysqli;
    
    try {
        $backupName = $_POST['backup_name'] ?? '';
        if (empty($backupName)) {
            throw new Exception('Backup-Name ist erforderlich');
        }
        
        // Prüfen ob Backup-Tabelle existiert
        $checkResult = $mysqli->query("SHOW TABLES LIKE '$backupName'");
        if ($checkResult->num_rows === 0) {
            throw new Exception("Backup-Tabelle '$backupName' nicht gefunden");
        }
        
        // Pre-Restore Backup erstellen
        $preRestoreBackup = 'AV_Res_PreRestore_' . date('Y-m-d_H-i-s');
        
        $mysqli->begin_transaction();
        
        try {
            // Pre-Restore Backup erstellen
            $createSql = "CREATE TABLE `$preRestoreBackup` LIKE `AV-Res`";
            if (!$mysqli->query($createSql)) {
                throw new Exception("Fehler beim Erstellen des Pre-Restore Backups: " . $mysqli->error);
            }
            
            $copySql = "INSERT INTO `$preRestoreBackup` SELECT * FROM `AV-Res`";
            if (!$mysqli->query($copySql)) {
                throw new Exception("Fehler beim Kopieren der aktuellen Daten: " . $mysqli->error);
            }
            
            // AV-Res Tabelle leeren
            if (!$mysqli->query("DELETE FROM `AV-Res`")) {
                throw new Exception("Fehler beim Leeren der AV-Res Tabelle: " . $mysqli->error);
            }
            
            // Daten aus Backup wiederherstellen
            $restoreSql = "INSERT INTO `AV-Res` SELECT * FROM `$backupName`";
            if (!$mysqli->query($restoreSql)) {
                throw new Exception("Fehler beim Wiederherstellen der Backup-Daten: " . $mysqli->error);
            }
            
            $mysqli->commit();
            
            // Anzahl wiederhergestellter Datensätze
            $countResult = $mysqli->query("SELECT COUNT(*) as count FROM `AV-Res`");
            $count = $countResult->fetch_assoc()['count'];
            
            echo json_encode([
                'success' => true,
                'message' => "Backup '$backupName' erfolgreich wiederhergestellt ($count Datensätze)",
                'pre_restore_backup' => $preRestoreBackup,
                'restored_records' => $count
            ]);
            
        } catch (Exception $e) {
            $mysqli->rollback();
            // Pre-Restore Backup löschen bei Fehler
            $mysqli->query("DROP TABLE IF EXISTS `$preRestoreBackup`");
            throw $e;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}

function deleteBackup() {
    global $mysqli;
    
    try {
        $backupName = $_POST['backup_name'] ?? '';
        if (empty($backupName)) {
            throw new Exception('Backup-Name ist erforderlich');
        }
        
        // Sicherheitsprüfung: Nur Backup-Tabellen löschen
        if (!preg_match('/^(AV_Res_Backup_|AV_Res_PreImport_|AV_Res_PreRestore_)/', $backupName)) {
            throw new Exception('Nur Backup-Tabellen können gelöscht werden');
        }
        
        // Prüfen ob Tabelle existiert
        $checkResult = $mysqli->query("SHOW TABLES LIKE '$backupName'");
        if ($checkResult->num_rows === 0) {
            throw new Exception("Backup-Tabelle '$backupName' nicht gefunden");
        }
        
        // Tabelle löschen
        if (!$mysqli->query("DROP TABLE `$backupName`")) {
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