<?php
/**
 * import_webimp.php - Neue Version ohne UNIQUE INDEX
 *
 * Zweck
 * -----
 * - Importiert ALLE Zeilen aus der Quelltabelle `AV-Res-webImp` in die Zieltabelle `AV-Res`.
 * - Verwendet manuelle UPSERT-Logik:
 *   • Lokale Reservierungen (av_id = 0): Immer INSERT
 *   • HRS-Reservierungen (av_id > 0): UPDATE wenn vorhanden, sonst INSERT
 * - Mappt/normalisiert Felder wie zuvor.
 *
 * Optionen
 * --------
 * - CLI:   --dry-run  → nur lesen/analysieren, keine Schreibvorgänge
 * - HTTP:  ?dry-run=1 → dito
 */

// -----------------------------------------------------------------------------
// 1) Optionen & Bootstrap
// -----------------------------------------------------------------------------
$isCli  = (PHP_SAPI === 'cli');
$dryRun = false;

// CLI-Flag
if ($isCli && isset($argv) && is_array($argv)) {
    $dryRun = in_array('--dry-run', $argv, true);
}
// HTTP-Flag
if (!$isCli && isset($_GET['dry-run'])) {
    $dryRun = filter_var($_GET['dry-run'], FILTER_VALIDATE_BOOLEAN);
}

// JSON Response für UI
$jsonResponse = isset($_GET['json']) && $_GET['json'] == '1';

// Config laden
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    $configPath = __DIR__ . '/config.php';
}

if (!file_exists($configPath)) {
    $error = "config.php nicht gefunden. Erwartete Pfade:\n- " . __DIR__ . "/../config.php\n- " . __DIR__ . "/config.php";
    
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    } else {
        echo $error . "\n";
    }
    exit(1);
}

require_once $configPath;

// Prüfe DB-Verbindung
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $error = "Keine gültige mysqli-Verbindung in config.php gefunden.";
    
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    } else {
        echo $error . "\n";
    }
    exit(1);
}

$truncateSource = true; // Nach erfolgreichem Import WebImp-Tabelle leeren

// -----------------------------------------------------------------------------
// 2) Daten analysieren
// -----------------------------------------------------------------------------
$countResult = $mysqli->query("SELECT COUNT(*) as cnt FROM `AV-Res-webImp`");
$countRow = $countResult->fetch_assoc();
$totalRecords = (int)$countRow['cnt'];

if ($totalRecords === 0) {
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'Keine Daten zum Importieren gefunden',
            'total' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'sourceCleared' => false
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo "Keine Daten in AV-Res-webImp zum Importieren gefunden.\n";
    }
    exit(0);
}

// Prüfe auf Duplikate in der Quelltabelle
$dupCheckResult = $mysqli->query("
    SELECT av_id, COUNT(*) as cnt 
    FROM `AV-Res-webImp` 
    GROUP BY av_id 
    HAVING COUNT(*) > 1 
    LIMIT 5
");
$sourceDuplicates = [];
while ($row = $dupCheckResult->fetch_assoc()) {
    $sourceDuplicates[] = "av_id {$row['av_id']} ({$row['cnt']}x)";
}

// Prüfe wieviele av_ids bereits in der Zieltabelle existieren (nur HRS-Reservierungen)
$existingCheckResult = $mysqli->query("
    SELECT COUNT(DISTINCT w.av_id) as existing_count
    FROM `AV-Res-webImp` w
    INNER JOIN `AV-Res` a ON w.av_id = a.av_id
    WHERE w.av_id > 0
");
$existingRow = $existingCheckResult->fetch_assoc();
$existingCount = (int)$existingRow['existing_count'];

// Lade alle existierenden av_ids (nur HRS) MIT den aktuellen Daten zum Vergleich
$existingAvIds = [];
$existingData = [];

// Setze SQL Mode um DATETIME-Probleme zu umgehen
$mysqli->query("SET SESSION sql_mode = ''");

$existingQuery = $mysqli->query("
    SELECT av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi, 
           gruppe, bem_av, nachname, vorname, handy, email, email_date,
           storno, arr, vorgang
    FROM `AV-Res` WHERE av_id > 0
");

// Normalize die Daten in PHP statt in SQL
while ($row = $existingQuery->fetch_assoc()) {
    $av_id = (int)$row['av_id'];
    $existingAvIds[$av_id] = true;
    
    // Normalisiere String-Felder: leere Strings zu NULL
    $existingData[$av_id] = [
        'anreise' => $row['anreise'],
        'abreise' => $row['abreise'], 
        'lager' => (int)$row['lager'],
        'betten' => (int)$row['betten'],
        'dz' => (int)$row['dz'],
        'sonder' => (int)$row['sonder'],
        'hp' => (int)$row['hp'],
        'vegi' => (int)$row['vegi'],
        'gruppe' => ($row['gruppe'] === '' || $row['gruppe'] === null) ? null : $row['gruppe'],
        'bem_av' => ($row['bem_av'] === '' || $row['bem_av'] === null || $row['bem_av'] === '-') ? null : $row['bem_av'],
        'nachname' => ($row['nachname'] === '' || $row['nachname'] === null) ? null : $row['nachname'],
        'vorname' => ($row['vorname'] === '' || $row['vorname'] === null) ? null : $row['vorname'],
        'handy' => ($row['handy'] === '' || $row['handy'] === null) ? null : $row['handy'],
        'email' => ($row['email'] === '' || $row['email'] === null) ? null : $row['email'],
        'email_date' => ($row['email_date'] === '' || $row['email_date'] === null || $row['email_date'] === '0000-00-00 00:00:00') ? null : $row['email_date'],
        'storno' => (int)$row['storno'],
        'arr' => (int)$row['arr'],
        'vorgang' => ($row['vorgang'] === '' || $row['vorgang'] === null) ? null : $row['vorgang']
    ];
}

$debugInfo = [
    'totalRecords' => $totalRecords,
    'sourceDuplicates' => $sourceDuplicates,
    'existingInTarget' => $existingCount,
    'indexStatus' => 'Manuelle UPSERT-Logik ohne UNIQUE INDEX',
    'existingAvIdsCount' => count($existingAvIds)
];

// -----------------------------------------------------------------------------
// 3) Quell-SELECT mit Mapping/Normalisierung
// -----------------------------------------------------------------------------
$sourceSql = "
SELECT
  w.av_id,
  w.anreise,
  w.abreise,
  COALESCE(w.lager,  0) AS lager,
  COALESCE(w.betten, 0) AS betten,
  COALESCE(w.dz,     0) AS dz,
  COALESCE(w.sonder, 0) AS sonder,
  COALESCE(w.hp,     0) AS hp,
  COALESCE(w.vegi,   0) AS vegi,
  w.gruppe,
  w.bem_av,
  w.nachname,
  w.vorname,
  w.handy,
  w.email,
  w.email_date,
  CASE
    WHEN UPPER(TRIM(w.vorgang)) = 'DISCARDED' THEN 1
    WHEN UPPER(TRIM(w.vorgang)) = 'CONFIRMED' THEN 0
    ELSE 0
  END AS storno,
  CASE WHEN COALESCE(w.hp,0)=1 THEN 1 ELSE 4 END AS arr,
  COALESCE(w.`timestamp`, NOW()) AS `timestamp`,
  w.vorgang
FROM `AV-Res-webImp` w
ORDER BY w.av_id
";

$sourceResult = $mysqli->query($sourceSql);

// -----------------------------------------------------------------------------
// 4) Prepared Statements vorbereiten
// -----------------------------------------------------------------------------

// UPDATE Statement für existierende HRS-Reservierungen
$updateSql = "UPDATE `AV-Res` SET
   `anreise`    = ?,
   `abreise`    = ?,
   `lager`      = ?,
   `betten`     = ?,
   `dz`         = ?,
   `sonder`     = ?,
   `hp`         = ?,
   `vegi`       = ?,
   `gruppe`     = ?,
   `bem_av`     = ?,
   `nachname`   = ?,
   `vorname`    = ?,
   `handy`      = ?,
   `email`      = ?,
   `email_date` = ?,
   `storno`     = ?,
   `arr`        = ?,
   `vorgang`    = ?
WHERE `av_id` = ?";

// INSERT Statement für neue Reservierungen
$insertSql = "INSERT INTO `AV-Res` (
   `av_id`, `anreise`, `abreise`,
   `lager`, `betten`, `dz`, `sonder`,
   `hp`, `vegi`, `gruppe`, `bem_av`,
   `nachname`, `vorname`, `handy`, `email`,
   `email_date`, `storno`, `arr`, `timestamp`, `vorgang`,
   `id64`, `card`, `hund`, `origin`)
VALUES
  (?, ?, ?,
   ?, ?, ?, ?,
   ?, ?, ?, ?,
   ?, ?, ?, ?,
   ?, ?, ?, ?, ?,
   '', '', 0, 0)";

$updateStmt = null;
$insertStmt = null;

if (!$dryRun) {
    $updateStmt = $mysqli->prepare($updateSql);
    if (!$updateStmt) {
        throw new Exception("UPDATE prepare fehlgeschlagen: " . $mysqli->error);
    }

    $insertStmt = $mysqli->prepare($insertSql);
    if (!$insertStmt) {
        throw new Exception("INSERT prepare fehlgeschlagen: " . $mysqli->error);
    }
}

// -----------------------------------------------------------------------------
// ZENTRALE FUNKTION: Prüft ob ein Record aktualisiert werden muss
// -----------------------------------------------------------------------------
function shouldUpdate($existing, $newData) {
    $changes = [];
    
    // Normalisiere existierende Daten (trim auf String-Felder)
    $existing_gruppe = trim($existing['gruppe'] ?? '');
    $existing_gruppe = ($existing_gruppe === '' || $existing_gruppe === '-') ? null : $existing_gruppe;
    
    $existing_bem_av = trim($existing['bem_av'] ?? '');
    $existing_bem_av = ($existing_bem_av === '' || $existing_bem_av === '-') ? null : $existing_bem_av;
    
    $existing_handy = trim($existing['handy'] ?? '');
    $existing_handy = ($existing_handy === '') ? null : $existing_handy;
    
    $existing_email = trim($existing['email'] ?? '');
    $existing_email = ($existing_email === '') ? null : $existing_email;
    
    // Vergleiche nur relevante Felder (NICHT: email_date, vorgang, nachname, vorname, arr)
    if ($existing['anreise'] !== $newData['anreise']) {
        $changes[] = "anreise: '{$existing['anreise']}' → '{$newData['anreise']}'";
    }
    if ($existing['abreise'] !== $newData['abreise']) {
        $changes[] = "abreise: '{$existing['abreise']}' → '{$newData['abreise']}'";
    }
    if ((int)$existing['lager'] !== $newData['lager']) {
        $changes[] = "lager: {$existing['lager']} → {$newData['lager']}";
    }
    if ((int)$existing['betten'] !== $newData['betten']) {
        $changes[] = "betten: {$existing['betten']} → {$newData['betten']}";
    }
    if ((int)$existing['dz'] !== $newData['dz']) {
        $changes[] = "dz: {$existing['dz']} → {$newData['dz']}";
    }
    if ((int)$existing['sonder'] !== $newData['sonder']) {
        $changes[] = "sonder: {$existing['sonder']} → {$newData['sonder']}";
    }
    if ((int)$existing['hp'] !== $newData['hp']) {
        $changes[] = "hp: {$existing['hp']} → {$newData['hp']}";
    }
    if ((int)$existing['vegi'] !== $newData['vegi']) {
        $changes[] = "vegi: {$existing['vegi']} → {$newData['vegi']}";
    }
    if ($existing_gruppe !== $newData['gruppe']) {
        $changes[] = "gruppe: '" . ($existing_gruppe ?: 'NULL') . "' → '" . ($newData['gruppe'] ?: 'NULL') . "'";
    }
    if ($existing_bem_av !== $newData['bem_av']) {
        $changes[] = "bem_av: '" . ($existing_bem_av ?: 'NULL') . "' → '" . ($newData['bem_av'] ?: 'NULL') . "'";
    }
    // HINWEIS: nachname, vorname und arr werden NICHT verglichen (HRS-Probleme, administrative Änderungen)
    if ($existing_handy !== $newData['handy']) {
        $changes[] = "handy: '" . ($existing_handy ?: 'NULL') . "' → '" . ($newData['handy'] ?: 'NULL') . "'";
    }
    if ($existing_email !== $newData['email']) {
        $changes[] = "email: '" . ($existing_email ?: 'NULL') . "' → '" . ($newData['email'] ?: 'NULL') . "'";
    }
    if ((int)$existing['storno'] !== $newData['storno']) {
        $changes[] = "storno: {$existing['storno']} → {$newData['storno']}";
    }
    
    return $changes;
}

// -----------------------------------------------------------------------------
// 5) Import ausführen
// -----------------------------------------------------------------------------
$total = 0;
$inserted = 0;
$updated  = 0;
$unchanged = 0;
$errors = [];
$dryRunOutput = []; // Sammle Output für JSON Response

if (!$dryRun) {
    $mysqli->begin_transaction();
}

try {
    while ($row = $sourceResult->fetch_assoc()) {
        $total++;

        // Daten aufbereiten und normalisieren (mit trim() für String-Felder)
        $av_id = (int)$row['av_id'];
        $anreise = $row['anreise'] ?: null;
        $abreise = $row['abreise'] ?: null;
        $lager = (int)$row['lager'];
        $betten = (int)$row['betten'];
        $dz = (int)$row['dz'];
        $sonder = (int)$row['sonder'];
        $hp = (int)$row['hp'];
        $vegi = (int)$row['vegi'];
        
        // String-Felder: trim() und dann auf null/empty prüfen
        $gruppe_raw = trim($row['gruppe'] ?? '');
        $gruppe = ($gruppe_raw === '' || $gruppe_raw === '-') ? null : $gruppe_raw;
        
        $bem_av_raw = trim($row['bem_av'] ?? '');
        $bem_av = ($bem_av_raw === '' || $bem_av_raw === '-') ? null : $bem_av_raw;
        
        $nachname_raw = trim($row['nachname'] ?? '');
        $nachname = ($nachname_raw === '') ? null : $nachname_raw;
        
        $vorname_raw = trim($row['vorname'] ?? '');
        $vorname = ($vorname_raw === '') ? null : $vorname_raw;
        
        $handy_raw = trim($row['handy'] ?? '');
        $handy = ($handy_raw === '') ? null : $handy_raw;
        
        $email_raw = trim($row['email'] ?? '');
        $email = ($email_raw === '') ? null : $email_raw;
        
        $email_date = ($row['email_date'] === '' || $row['email_date'] === null || $row['email_date'] === '0000-00-00 00:00:00') ? null : $row['email_date'];
        $storno = (int)$row['storno'];
        $arr = (int)$row['arr'];
        $timestamp = $row['timestamp'];
        
        $vorgang_raw = trim($row['vorgang'] ?? '');
        $vorgang = ($vorgang_raw === '') ? null : $vorgang_raw;

        if ($dryRun) {
            // Nur simulieren
            if ($av_id > 0 && isset($existingAvIds[$av_id])) {
                // Verwende zentrale shouldUpdate Funktion
                $existing = $existingData[$av_id];
                $newData = [
                    'anreise' => $anreise,
                    'abreise' => $abreise,
                    'lager' => $lager,
                    'betten' => $betten,
                    'dz' => $dz,
                    'sonder' => $sonder,
                    'hp' => $hp,
                    'vegi' => $vegi,
                    'gruppe' => $gruppe,
                    'bem_av' => $bem_av,
                    'handy' => $handy,
                    'email' => $email,
                    'storno' => $storno
                ];
                
                $changes = shouldUpdate($existing, $newData);
                
                if (!empty($changes)) {
                    $updated++;
                    if ($jsonResponse) {
                        $dryRunOutput[] = [
                            'action' => 'UPDATE',
                            'av_id' => $av_id,
                            'name' => "$nachname, $vorname",
                            'changes' => $changes
                        ];
                    } else {
                        echo "UPDATE AV-ID $av_id ({$nachname}, {$vorname}):\n";
                        foreach ($changes as $change) {
                            echo "  - $change\n";
                        }
                        echo "\n";
                    }
                } else {
                    $unchanged++;
                    if ($jsonResponse) {
                        $dryRunOutput[] = [
                            'action' => 'UNCHANGED',
                            'av_id' => $av_id,
                            'name' => "$nachname, $vorname"
                        ];
                    } else {
                        echo "UNVERÄNDERT AV-ID $av_id ({$nachname}, {$vorname})\n";
                    }
                }
            } else {
                $inserted++;
                if ($jsonResponse) {
                    $dryRunOutput[] = [
                        'action' => 'INSERT',
                        'av_id' => $av_id,
                        'name' => "$nachname, $vorname"
                    ];
                } else {
                    echo "NEU EINFÜGEN AV-ID $av_id ({$nachname}, {$vorname})\n";
                }
            }
            continue;
        }

        // Entscheidung: UPDATE oder INSERT?
        if ($av_id > 0 && isset($existingAvIds[$av_id])) {
            // Verwende zentrale shouldUpdate Funktion für echten Import
            $existing = $existingData[$av_id];
            $newData = [
                'anreise' => $anreise,
                'abreise' => $abreise,
                'lager' => $lager,
                'betten' => $betten,
                'dz' => $dz,
                'sonder' => $sonder,
                'hp' => $hp,
                'vegi' => $vegi,
                'gruppe' => $gruppe,
                'bem_av' => $bem_av,
                'handy' => $handy,
                'email' => $email,
                'storno' => $storno
            ];
            
            $changes = shouldUpdate($existing, $newData);
            
            if (!empty($changes)) {
                // UPDATE für existierende HRS-Reservierung mit echten Änderungen
                $updateStmt->bind_param(
                    "sssiiiiiissssssiisi",
                    $anreise, $abreise, $lager, $betten, $dz, $sonder,
                    $hp, $vegi, $gruppe, $bem_av, $nachname, $vorname,
                    $handy, $email, $email_date, $storno, $arr, $vorgang,
                    $av_id
                );

                if ($updateStmt->execute()) {
                    $updated++;
                    // Aktualisiere lokale Kopie der Daten
                    $existingData[$av_id] = [
                        'anreise' => $anreise, 'abreise' => $abreise,
                        'lager' => $lager, 'betten' => $betten, 'dz' => $dz, 'sonder' => $sonder,
                        'hp' => $hp, 'vegi' => $vegi, 'gruppe' => $gruppe, 'bem_av' => $bem_av,
                        'nachname' => $nachname, 'vorname' => $vorname, 'handy' => $handy,
                        'email' => $email, 'email_date' => $email_date, 'storno' => $storno,
                        'arr' => $arr, 'vorgang' => $vorgang
                    ];
                } else {
                    $errors[] = "UPDATE Fehler für av_id $av_id: " . $updateStmt->error;
                }
            } else {
                // Keine Änderungen - Datensatz ist identisch
                $unchanged++;
            }
        } else {
            // INSERT für neue Reservierung (HRS oder lokal)
            $insertStmt->bind_param(
                "issiiiiiisssssssiiss",
                $av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder,
                $hp, $vegi, $gruppe, $bem_av, $nachname, $vorname,
                $handy, $email, $email_date, $storno, $arr, $timestamp, $vorgang
            );

            if ($insertStmt->execute()) {
                $inserted++;
                // Für HRS-Reservierungen: av_id zu existierenden hinzufügen
                if ($av_id > 0) {
                    $existingAvIds[$av_id] = true;
                }
            } else {
                $errors[] = "INSERT Fehler für av_id $av_id: " . $insertStmt->error;
            }
        }
    }

    if (!$dryRun) {
        $mysqli->commit();

        // Optional: Quelltabelle leeren
        $sourceCleared = false;
        if ($truncateSource && $errors === []) {
            $clearResult = $mysqli->query("TRUNCATE TABLE `AV-Res-webImp`");
            $sourceCleared = (bool)$clearResult;
        }
    } else {
        $sourceCleared = false;
    }

    // Erfolgs-Antwort
    $result = [
        'success' => true,
        'message' => $dryRun ? 'Dry-Run erfolgreich' : 'Import erfolgreich',
        'total' => $total,
        'inserted' => $inserted,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'errors' => $errors,
        'sourceCleared' => $sourceCleared,
        'debug' => $debugInfo
    ];
    
    // Füge dry-run Details hinzu wenn verfügbar
    if ($dryRun && $jsonResponse && !empty($dryRunOutput)) {
        $result['dryRunDetails'] = $dryRunOutput;
    }

    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } else {
        echo ($dryRun ? "=== DRY-RUN MODUS ===\n" : "=== IMPORT ABGESCHLOSSEN ===\n");
        echo "Total: $total\n";
        echo "Eingefügt: $inserted\n";
        echo "Aktualisiert: $updated\n";
        echo "Unverändert: $unchanged\n";
        if (!empty($errors)) {
            echo "Fehler: " . count($errors) . "\n";
            foreach ($errors as $error) {
                echo "  - $error\n";
            }
        }
        if ($sourceCleared) {
            echo "Quelltabelle wurde geleert.\n";
        }
    }

} catch (Exception $e) {
    if (!$dryRun) {
        $mysqli->rollback();
    }

    $error = "Import Fehler: " . $e->getMessage();

    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $error,
            'total' => $total,
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'debug' => $debugInfo
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo $error . "\n";
    }
    exit(1);
}
?>
