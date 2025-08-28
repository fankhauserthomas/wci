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

// Lade alle existierenden av_ids (nur HRS)
$existingAvIds = [];
$existingQuery = $mysqli->query("SELECT av_id FROM `AV-Res` WHERE av_id > 0");
while ($row = $existingQuery->fetch_assoc()) {
    $existingAvIds[(int)$row['av_id']] = true;
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
// 5) Import ausführen
// -----------------------------------------------------------------------------
$total = 0;
$inserted = 0;
$updated  = 0;
$unchanged = 0;
$errors = [];

if (!$dryRun) {
    $mysqli->begin_transaction();
}

try {
    while ($row = $sourceResult->fetch_assoc()) {
        $total++;

        // Daten aufbereiten
        $av_id = (int)$row['av_id'];
        $anreise = $row['anreise'] ?: null;
        $abreise = $row['abreise'] ?: null;
        $lager = (int)$row['lager'];
        $betten = (int)$row['betten'];
        $dz = (int)$row['dz'];
        $sonder = (int)$row['sonder'];
        $hp = (int)$row['hp'];
        $vegi = (int)$row['vegi'];
        $gruppe = $row['gruppe'] ?: null;
        $bem_av = $row['bem_av'] ?: null;
        $nachname = $row['nachname'] ?: null;
        $vorname = $row['vorname'] ?: null;
        $handy = $row['handy'] ?: null;
        $email = $row['email'] ?: null;
        $email_date = $row['email_date'] ?: null;
        $storno = (int)$row['storno'];
        $arr = (int)$row['arr'];
        $timestamp = $row['timestamp'];
        $vorgang = $row['vorgang'] ?: null;

        if ($dryRun) {
            // Nur simulieren
            if ($av_id > 0 && isset($existingAvIds[$av_id])) {
                $updated++;
            } else {
                $inserted++;
            }
            continue;
        }

        // Entscheidung: UPDATE oder INSERT?
        if ($av_id > 0 && isset($existingAvIds[$av_id])) {
            // UPDATE für existierende HRS-Reservierung
            $updateStmt->bind_param(
                "sssiiiiiissssssiisi",
                $anreise, $abreise, $lager, $betten, $dz, $sonder,
                $hp, $vegi, $gruppe, $bem_av, $nachname, $vorname,
                $handy, $email, $email_date, $storno, $arr, $vorgang,
                $av_id
            );

            if ($updateStmt->execute()) {
                if ($updateStmt->affected_rows > 0) {
                    $updated++;
                } else {
                    $unchanged++;
                }
            } else {
                $errors[] = "UPDATE Fehler für av_id $av_id: " . $updateStmt->error;
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
