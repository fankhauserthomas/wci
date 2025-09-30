<?php
/**
 * WebImp Import Script - Komplett neu nach spezifischen Vorgaben
 * 
 * Vorgaben:
 * - SUBMITTED/ON_WAITING_LIST: Ignorieren (nicht behandeln)
 * - CONFIRMED/DISCARDED: Verarbeiten
 * - Mapping: CONFIRMED → storno=false, DISCARDED → storno=true
 * - Mapping: hp=0 → arr=5, hp=1 → arr=1
 * - Vergleichsfelder: anreise, abreise, lager, betten, dz, sonder, gruppe, bem_av, handy, email, vorgang
 * - Dry-Run Funktion implementiert
 */

// -----------------------------------------------------------------------------
// 1) Optionen & Konfiguration
// -----------------------------------------------------------------------------
$isCli = (PHP_SAPI === 'cli');
$dryRun = false;

// CLI-Flags
if ($isCli && isset($argv)) {
    foreach ($argv as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
        }
    }
}

// HTTP-Flags
if (!$isCli) {
    if (isset($_GET['dry-run'])) {
        $dryRun = true;
    }
}

// JSON Response für UI
$jsonResponse = isset($_GET['json']) && $_GET['json'] == '1';

// Config laden
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die("Config-Datei nicht gefunden: $configPath\n");
}

require_once $configPath;

// DB-Verbindung prüfen
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die("Keine gültige MySQL-Verbindung verfügbar\n");
}

// -----------------------------------------------------------------------------
// 2) Hilfsfunktionen
// -----------------------------------------------------------------------------

/**
 * Erstellt automatisches Backup vor Import
 */
function createAutoBackup() {
    global $mysqli;
    
    try {
        $timestamp = date('Y-m-d_H-i-s');
        $backupTableName = "AV_Res_PreImport_$timestamp";
        
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
        
        return [
            'success' => true,
            'backup_name' => $backupTableName,
            'record_count' => $count
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Normalisiert String-Werte (trim, null-Behandlung)
 */
function normalizeString($value) {
    if ($value === null || $value === '') return null;
    $trimmed = trim($value);
    return ($trimmed === '' || $trimmed === '-') ? null : $trimmed;
}

/**
 * Prüft ob ein Update erforderlich ist basierend auf den Vergleichsfeldern
 * HINWEIS: vorgang ist KEIN Vergleichsfeld - nur storno wird verglichen (gemappt aus vorgang)
 */
function needsUpdate($existing, $new) {
    $fieldsToCompare = ['anreise', 'abreise', 'lager', 'betten', 'dz', 'sonder', 'gruppe', 'bem_av', 'handy', 'email', 'storno'];
    
    foreach ($fieldsToCompare as $field) {
        if ($existing[$field] != $new[$field]) {
            return true;
        }
    }
    
    return false;
}

/**
 * Sammelt detaillierte Änderungen für Logging
 * HINWEIS: vorgang ist KEIN Vergleichsfeld - nur storno wird verglichen (gemappt aus vorgang)
 */
function getChanges($existing, $newData) {
    $changes = [];
    $fields = ['anreise', 'abreise', 'lager', 'betten', 'dz', 'sonder', 'gruppe', 'bem_av', 'handy', 'email', 'storno'];
    
    foreach ($fields as $field) {
        $existingValue = $existing[$field];
        $newValue = $newData[$field];
        
        // String-Felder normalisieren
        if (in_array($field, ['gruppe', 'bem_av', 'handy', 'email'])) {
            $existingValue = normalizeString($existingValue);
            $newValue = normalizeString($newValue);
        }
        
        if ($existingValue !== $newValue) {
            // Bessere Darstellung von NULL/leeren Werten
            $existingDisplay = formatValueForDisplay($existingValue, $field);
            $newDisplay = formatValueForDisplay($newValue, $field);
            $changes[] = "$field: '$existingDisplay' → '$newDisplay'";
        }
    }
    
    return $changes;
}

function formatValueForDisplay($value, $field) {
    // Numerische Felder: NULL → 0
    if (in_array($field, ['lager', 'betten', 'dz', 'sonder', 'storno'])) {
        if ($value === null || $value === '') {
            return '0';
        }
        return (string)$value;
    }
    
    // String-Felder: NULL → leer
    if (in_array($field, ['gruppe', 'bem_av', 'handy', 'email'])) {
        if ($value === null) {
            return '';
        }
        return (string)$value;
    }
    
    // Andere Felder (Datum): wie sie sind
    return $value === null ? '' : (string)$value;
}

// -----------------------------------------------------------------------------
// 3) Datenanalyse
// -----------------------------------------------------------------------------

// Prüfe WebImp-Tabelle
$countResult = $mysqli->query("SELECT COUNT(*) as total FROM `AV-Res-webImp`");
$countRow = $countResult->fetch_assoc();
$totalWebImpRecords = (int)$countRow['total'];

if ($totalWebImpRecords === 0) {
    $result = [
        'success' => true,
        'message' => 'Keine Daten in WebImp-Tabelle gefunden',
        'total' => 0,
        'inserted' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'skipped' => 0,
        'errors' => []
    ];
    
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } else {
        echo "Keine Daten in WebImp-Tabelle gefunden.\n";
    }
    exit;
}

// Status-Verteilung analysieren
$statusResult = $mysqli->query("
    SELECT vorgang, COUNT(*) as cnt 
    FROM `AV-Res-webImp` 
    GROUP BY vorgang
");

$statusStats = [];
while ($row = $statusResult->fetch_assoc()) {
    $statusStats[$row['vorgang']] = $row['cnt'];
}

// Alle existierenden AV-Res Datensätze laden
$existingData = [];
$existingResult = $mysqli->query("
    SELECT av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
           gruppe, bem_av, nachname, vorname, handy, email, email_date,
           storno, arr, vorgang, timestamp
    FROM `AV-Res`
");

while ($row = $existingResult->fetch_assoc()) {
    $existingData[$row['av_id']] = $row;
}

$debugInfo = [
    'totalWebImpRecords' => $totalWebImpRecords,
    'statusStats' => $statusStats,
    'existingInTarget' => count($existingData)
];

// -----------------------------------------------------------------------------
// 4) Hauptabfrage - nur CONFIRMED und DISCARDED verarbeiten
// -----------------------------------------------------------------------------

$sourceSql = "
SELECT
    av_id,
    anreise,
    abreise,
    COALESCE(lager, 0) AS lager,
    COALESCE(betten, 0) AS betten,
    COALESCE(dz, 0) AS dz,
    COALESCE(sonder, 0) AS sonder,
    COALESCE(hp, 0) AS hp,
    COALESCE(vegi, 0) AS vegi,
    gruppe,
    bem_av,
    nachname,
    vorname,
    handy,
    email,
    email_date,
    vorgang,
    COALESCE(timestamp, NOW()) AS timestamp
FROM `AV-Res-webImp`
WHERE UPPER(TRIM(vorgang)) IN ('CONFIRMED', 'DISCARDED')
ORDER BY av_id
";

$sourceResult = $mysqli->query($sourceSql);

// -----------------------------------------------------------------------------
// 5) Prepared Statements (nur für echten Import)
// -----------------------------------------------------------------------------

if (!$dryRun) {
    $updateSql = "UPDATE `AV-Res` SET
        anreise = ?, abreise = ?, lager = ?, betten = ?, dz = ?, sonder = ?,
        hp = ?, vegi = ?, gruppe = ?, bem_av = ?, nachname = ?, vorname = ?,
        handy = ?, email = ?, email_date = ?, storno = ?, arr = ?, vorgang = ?, timestamp = ?
        WHERE av_id = ?";

    $insertSql = "INSERT INTO `AV-Res` (
        av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
        gruppe, bem_av, nachname, vorname, handy, email, email_date,
        storno, arr, vorgang, timestamp
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $updateStmt = $mysqli->prepare($updateSql);
    $insertStmt = $mysqli->prepare($insertSql);

    if (!$updateStmt || !$insertStmt) {
        die("Fehler beim Vorbereiten der SQL-Statements: " . $mysqli->error . "\n");
    }

    // Automatisches Backup vor echtem Import erstellen
    if (!$dryRun) {
        $backupResult = createAutoBackup();
        if (!$backupResult['success']) {
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Backup fehlgeschlagen: ' . $backupResult['error']]);
                exit;
            } else {
                die("FEHLER: Backup fehlgeschlagen: " . $backupResult['error'] . "\nImport abgebrochen!\n");
            }
        }
        // Backup-Info für später sammeln
        $result['backup_info'] = "Backup erstellt: " . $backupResult['backup_name'] . " (" . $backupResult['record_count'] . " Datensätze)";
        
        // Nur bei non-JSON Output sofort ausgeben
        if (!$jsonResponse) {
            echo "Erstelle Sicherheitskopie der AV-Res Tabelle...\n";
            echo "✓ " . $result['backup_info'] . "\n\n";
        }
    }

    $mysqli->begin_transaction();
}

// -----------------------------------------------------------------------------
// 6) Import-Schleife
// -----------------------------------------------------------------------------

$stats = [
    'total' => 0,
    'inserted' => 0,
    'updated' => 0,
    'unchanged' => 0,
    'skipped' => 0,
    'errors' => []
];

$dryRunOutput = [];

try {
    while ($row = $sourceResult->fetch_assoc()) {
        $stats['total']++;
        
        // Daten normalisieren
        $av_id = (int)$row['av_id'];
        $anreise = $row['anreise'] ?: null;
        $abreise = $row['abreise'] ?: null;
        $lager = (int)$row['lager'];
        $betten = (int)$row['betten'];
        $dz = (int)$row['dz'];
        $sonder = (int)$row['sonder'];
        $hp = (int)$row['hp'];
        $vegi = (int)$row['vegi'];
        
        $gruppe = normalizeString($row['gruppe']);
        $bem_av = normalizeString($row['bem_av']);
        $nachname = normalizeString($row['nachname']);
        $vorname = normalizeString($row['vorname']);
        $handy = normalizeString($row['handy']);
        $email = normalizeString($row['email']);
        
        $email_date = ($row['email_date'] === '' || $row['email_date'] === null || $row['email_date'] === '0000-00-00 00:00:00') ? null : $row['email_date'];
        $timestamp = $row['timestamp'];
        $vorgang = strtoupper(trim($row['vorgang'] ?? ''));

        // Mapping nach Vorgaben
        $storno = ($vorgang === 'DISCARDED') ? 1 : 0;  // CONFIRMED → 0, DISCARDED → 1
        $arr = ($hp === 1) ? 1 : 5;  // hp=1 → arr=1, hp=0 → arr=5

        // Neue Daten für Vergleich (vorgang wird zu storno gemappt, nicht direkt verglichen)
        $newData = [
            'anreise' => $anreise,
            'abreise' => $abreise,
            'lager' => $lager,
            'betten' => $betten,
            'dz' => $dz,
            'sonder' => $sonder,
            'gruppe' => $gruppe,
            'bem_av' => $bem_av,
            'handy' => $handy,
            'email' => $email,
            'storno' => $storno  // Gemappt aus vorgang: CONFIRMED→0, DISCARDED→1
        ];

        // Prüfe ob Datensatz existiert
        $exists = isset($existingData[$av_id]);

        if ($dryRun) {
            // Dry-Run Simulation
            if ($exists) {
                if (needsUpdate($existingData[$av_id], $newData)) {
                    $stats['updated']++;
                    $changes = getChanges($existingData[$av_id], $newData);
                    
                    if ($jsonResponse) {
                        $dryRunOutput[] = [
                            'action' => 'UPDATE',
                            'av_id' => $av_id,
                            'name' => "$nachname, $vorname",
                            'status' => $vorgang,
                            'changes' => $changes
                        ];
                    } else {
                        echo "UPDATE AV-ID $av_id ($nachname, $vorname) [$vorgang]:\n";
                        foreach ($changes as $change) {
                            echo "  - $change\n";
                        }
                        echo "\n";
                    }
                } else {
                    $stats['unchanged']++;
                    if ($jsonResponse) {
                        $dryRunOutput[] = [
                            'action' => 'UNCHANGED',
                            'av_id' => $av_id,
                            'name' => "$nachname, $vorname",
                            'status' => $vorgang
                        ];
                    } else {
                        echo "UNVERÄNDERT AV-ID $av_id ($nachname, $vorname) [$vorgang]\n";
                    }
                }
            } else {
                $stats['inserted']++;
                if ($jsonResponse) {
                    $dryRunOutput[] = [
                        'action' => 'INSERT',
                        'av_id' => $av_id,
                        'name' => "$nachname, $vorname",
                        'status' => $vorgang
                    ];
                } else {
                    echo "NEU EINFÜGEN AV-ID $av_id ($nachname, $vorname) [$vorgang]\n";
                }
            }
        } else {
            // ECHTER IMPORT - nur wenn NICHT dry-run!
            if ($exists) {
                if (needsUpdate($existingData[$av_id], $newData)) {
                    // UPDATE ausführen
                    $updateStmt->bind_param(
                        "ssiiiiiisssssssiissi",
                        $anreise, $abreise, $lager, $betten, $dz, $sonder,
                        $hp, $vegi, $gruppe, $bem_av, $nachname, $vorname,
                        $handy, $email, $email_date, $storno, $arr, $vorgang, $timestamp,
                        $av_id
                    );
                    
                    if ($updateStmt->execute()) {
                        $stats['updated']++;
                    } else {
                        $stats['errors'][] = "UPDATE Fehler für AV-ID $av_id: " . $updateStmt->error;
                    }
                } else {
                    $stats['unchanged']++;
                }
            } else {
                // INSERT ausführen
                $insertStmt->bind_param(
                    "issiiiiiisssssssiiss",
                    $av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder,
                    $hp, $vegi, $gruppe, $bem_av, $nachname, $vorname,
                    $handy, $email, $email_date, $storno, $arr, $vorgang, $timestamp
                );
                
                if ($insertStmt->execute()) {
                    $stats['inserted']++;
                } else {
                    $stats['errors'][] = "INSERT Fehler für AV-ID $av_id: " . $insertStmt->error;
                }
            }
        }
    }

    // Bei erfolgreichem echten Import: Commit und WebImp-Tabelle leeren
    if (!$dryRun && empty($stats['errors'])) {
        $mysqli->commit();
        $mysqli->query("DELETE FROM `AV-Res-webImp`");
        $sourceCleared = true;
    } elseif (!$dryRun) {
        $mysqli->rollback();
    }

} catch (Exception $e) {
    if (!$dryRun) {
        $mysqli->rollback();
    }
    $stats['errors'][] = "Import-Fehler: " . $e->getMessage();
}

// -----------------------------------------------------------------------------
// 7) Ergebnis ausgeben
// -----------------------------------------------------------------------------

$result = [
    'success' => empty($stats['errors']),
    'message' => $dryRun ? 'Dry-Run erfolgreich abgeschlossen' : 'Import erfolgreich abgeschlossen',
    'total' => $stats['total'],
    'inserted' => $stats['inserted'],
    'updated' => $stats['updated'],
    'unchanged' => $stats['unchanged'],
    'skipped' => $stats['skipped'],
    'errors' => $stats['errors'],
    'sourceCleared' => !$dryRun && empty($stats['errors']),
    'debug' => $debugInfo
];

// Dry-Run Details hinzufügen
if ($dryRun && $jsonResponse && !empty($dryRunOutput)) {
    $result['dryRunDetails'] = $dryRunOutput;
}

if ($jsonResponse) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    echo ($dryRun ? "=== DRY-RUN MODUS ===\n" : "=== IMPORT ABGESCHLOSSEN ===\n");
    echo "Verarbeitet: {$stats['total']}\n";
    echo "Neu eingefügt: {$stats['inserted']}\n";
    echo "Aktualisiert: {$stats['updated']}\n";
    echo "Unverändert: {$stats['unchanged']}\n";
    echo "Übersprungen: {$stats['skipped']}\n";
    
    if (!empty($stats['errors'])) {
        echo "\nFehler (" . count($stats['errors']) . "):\n";
        foreach ($stats['errors'] as $error) {
            echo "- $error\n";
        }
    }
    
    if (!$dryRun && empty($stats['errors'])) {
        echo "\nWebImp-Tabelle wurde geleert.\n";
    }
    
    echo "\nStatus-Verteilung WebImp: " . json_encode($debugInfo['statusStats']) . "\n";
    echo "Existierende Datensätze in AV-Res: {$debugInfo['existingInTarget']}\n";
}
?>
