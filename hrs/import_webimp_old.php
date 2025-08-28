<?php
/**
 * import_webimp.php
 *
 * Zweck
 * -----
 * - Importiert ALLE Zeilen aus der Quelltabelle `AV-Res-webImp` in die Zieltabelle `AV-Res`.
 * - Mappt/normalisiert Felder:
 *     • NULL → 0 bei numerischen Feldern,
 *     • vorgang: CONFIRMED → storno=0, DISCARDED → storno=1, sonst 0,
 *     • arr: hp=1 → 1 (HP), sonst 4 (à-la-carte),
 *     • timestamp: aus Quelle oder NOW() (nur bei INSERT verwendet).
 * - Verwendet manuelles UPSERT für HRS-Reservierungen (av_id > 0):
 *   • Prüfe ob av_id bereits existiert → UPDATE
 *   • Sonst → INSERT
 * - WICHTIG: Lokale Reservierungen (av_id = 0) werden immer als INSERT behandelt.
 * - `timestamp` wird nur bei echten INSERTS gesetzt.
 * - `id` in `AV-Res` bleibt bei Updates unverändert (AUTO_INCREMENT nur bei echten INSERTS).
 *
 * Voraussetzungen
 * ---------------
 * - config.php stellt eine mysqli-Verbindung bereit:
 *     $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
 *     $mysqli->set_charset('utf8mb4');
 *
 * Optionen
 * --------
 * - CLI:   --dry-run  → nur lesen/zählen, keine Schreibvorgänge
 * - HTTP:  ?dry-run=1 → dito
 *
 * Nachlauf
 * --------
 * - Optional: Quelltabelle `AV-Res-webImp` nach erfolgreichem Import leeren (TRUNCATE),
 *             siehe $truncateSource unten.
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

// Quelltabelle nach erfolgreichem Import leeren?
$truncateSource = true;  // bei Bedarf auf false setzen

// -----------------------------------------------------------------------------
// 2) DB-Verbindung (bereitgestellt durch config.php)
// -----------------------------------------------------------------------------
require_once '../config.php'; // stellt $mysqli bereit
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Exceptions bei Fehlern

// -----------------------------------------------------------------------------
// 3) UNIQUE-Index auf AV-Res.av_id (für ON DUPLICATE KEY UPDATE nötig)
//    Falls bereits vorhanden, wird der Fehler abgefangen und ignoriert.
//    Zusätzlich prüfen wir ob der Index tatsächlich existiert.
// -----------------------------------------------------------------------------
try {
    // Prüfe erst ob der Index bereits existiert
    $indexCheck = $mysqli->query("SHOW INDEX FROM `AV-Res` WHERE Key_name = 'uniq_av_id'");
    $indexExists = $indexCheck->num_rows > 0;
    
    if (!$indexExists) {
        $mysqli->query("CREATE UNIQUE INDEX uniq_av_id ON `AV-Res` (av_id)");
        if ($jsonResponse) {
            // Debug-Info in JSON Response
            $debugInfo = "UNIQUE Index auf av_id wurde erstellt";
        }
    } else {
        if ($jsonResponse) {
            $debugInfo = "UNIQUE Index auf av_id existiert bereits";
        }
    }
} catch (Throwable $e) {
    // Index-Erstellung fehlgeschlagen - das ist ein kritisches Problem
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Index-Fehler',
            'message' => 'UNIQUE Index auf av_id konnte nicht erstellt werden: ' . $e->getMessage(),
            'details' => 'Ohne diesen Index funktioniert UPSERT nicht korrekt'
        ], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    throw $e;
}

// -----------------------------------------------------------------------------
// 4) Zunächst prüfen ob Daten vorhanden sind und Duplikate analysieren
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

$debugInfo = [
    'totalRecords' => $totalRecords,
    'sourceDuplicates' => $sourceDuplicates,
    'existingInTarget' => $existingCount,
    'indexStatus' => $debugInfo ?? 'unbekannt'
];

// -----------------------------------------------------------------------------
// 5) Quell-SELECT mit Mapping/Normalisierung (kein VIEW; alles im Code)
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
WHERE w.av_id > 0
ORDER BY w.av_id
";

$sourceResult = $mysqli->query($sourceSql);

// -----------------------------------------------------------------------------
// 6) Prepared Statement für UPSERT auf `AV-Res`
//    - INSERT setzt Defaults für id64='', card='', hund=0, origin=0 (wie gewünscht).
//    - UPDATE-Teil OHNE `timestamp` → verhindert „Zwangs-Updates" durch Zeitdifferenzen.
// -----------------------------------------------------------------------------
$upsertSql = "
INSERT INTO `AV-Res`
  (`av_id`, `anreise`, `abreise`,
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
   '', '', 0, 0)
ON DUPLICATE KEY UPDATE
   `anreise`    = VALUES(`anreise`),
   `abreise`    = VALUES(`abreise`),
   `lager`      = VALUES(`lager`),
   `betten`     = VALUES(`betten`),
   `dz`         = VALUES(`dz`),
   `sonder`     = VALUES(`sonder`),
   `hp`         = VALUES(`hp`),
   `vegi`       = VALUES(`vegi`),
   `gruppe`     = VALUES(`gruppe`),
   `bem_av`     = VALUES(`bem_av`),
   `nachname`   = VALUES(`nachname`),
   `vorname`    = VALUES(`vorname`),
   `handy`      = VALUES(`handy`),
   `email`      = VALUES(`email`),
   `email_date` = VALUES(`email_date`),
   `storno`     = VALUES(`storno`),
   `arr`        = VALUES(`arr`),
   `vorgang`    = VALUES(`vorgang`)
";

$upsertStmt = $mysqli->prepare($upsertSql);

/**
 * Parametertypen:
 * - 20 Platzhalter vor den festen Defaults ('', '', 0, 0)
 * - Typenfolge (20):
 *   1  av_id       s  (BIGINT als String binden – portabel auf 32/64-bit)
 *   2  anreise     s
 *   3  abreise     s
 *   4  lager       i
 *   5  betten      i
 *   6  dz          i
 *   7  sonder      i
 *   8  hp          i
 *   9  vegi        i
 *   10 gruppe      s
 *   11 bem_av      s
 *   12 nachname    s
 *   13 vorname     s
 *   14 handy       s
 *   15 email       s
 *   16 email_date  s
 *   17 storno      i
 *   18 arr         i
 *   19 timestamp   s   (nur INSERT-seitig relevant)
 *   20 vorgang     s
 */
$upsertStmt->bind_param(
    "sssiiiiiisssssssiiss",
    $p_av_id,
    $p_anreise,
    $p_abreise,
    $p_lager,
    $p_betten,
    $p_dz,
    $p_sonder,
    $p_hp,
    $p_vegi,
    $p_gruppe,
    $p_bem_av,
    $p_nachname,
    $p_vorname,
    $p_handy,
    $p_email,
    $p_email_date,
    $p_storno,
    $p_arr,
    $p_timestamp,
    $p_vorgang
);

// -----------------------------------------------------------------------------
// 7) Import ausführen (Transaktion) + Statistiken
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

        // Pflicht: valide av_id
        if ($row['av_id'] === null || (int)$row['av_id'] <= 0) {
            $errors[] = "Zeile {$total}: Ungültige av_id (" . ($row['av_id'] ?? 'NULL') . ")";
            continue;
        }

        // Sanftes Trimming für Textfelder und Normalisierung von '' zu NULL
        foreach (['gruppe','bem_av','nachname','vorname','handy','email','vorgang'] as $k) {
            if (array_key_exists($k, $row)) {
                if ($row[$k] === null) {
                    // NULL bleibt NULL
                    continue;
                } else {
                    $trimmed = trim((string)$row[$k]);
                    // Leere Strings werden zu NULL normalisiert
                    $row[$k] = ($trimmed === '') ? null : $trimmed;
                }
            }
        }

        // Bind-Parameter belegen (Reihenfolge MUSS zum bind_param passen)
        $p_av_id      = (string)$row['av_id'];                    // BIGINT → string
        
        // DATETIME-Felder: leere Strings zu NULL normalisieren
        $p_anreise    = (isset($row['anreise']) && trim($row['anreise']) !== '') ? $row['anreise'] : null;
        $p_abreise    = (isset($row['abreise']) && trim($row['abreise']) !== '') ? $row['abreise'] : null;
        $p_email_date = (isset($row['email_date']) && trim($row['email_date']) !== '') ? $row['email_date'] : null;
        
        $p_lager      = (int)($row['lager']   ?? 0);
        $p_betten     = (int)($row['betten']  ?? 0);
        $p_dz         = (int)($row['dz']      ?? 0);
        $p_sonder     = (int)($row['sonder']  ?? 0);
        $p_hp         = (int)($row['hp']      ?? 0);
        $p_vegi       = (int)($row['vegi']    ?? 0);
        $p_gruppe     = $row['gruppe'];     // Bereits normalisiert (NULL oder nicht-leerer String)
        $p_bem_av     = $row['bem_av'];     // Bereits normalisiert (NULL oder nicht-leerer String)
        $p_nachname   = $row['nachname'];   // Bereits normalisiert (NULL oder nicht-leerer String)
        $p_vorname    = $row['vorname'];    // Bereits normalisiert (NULL oder nicht-leerer String)
        $p_handy      = $row['handy'];      // Bereits normalisiert (NULL oder nicht-leerer String)
        $p_email      = $row['email'];      // Bereits normalisiert (NULL oder nicht-leerer String)
        $p_storno     = (int)($row['storno'] ?? 0);
        $p_arr        = (int)($row['arr']    ?? 4);
        $p_timestamp  = $row['timestamp']  ?? date('Y-m-d H:i:s'); // nur INSERT-seitig
        $p_vorgang    = $row['vorgang'];    // Bereits normalisiert (NULL oder nicht-leerer String)

        if ($dryRun) {
            // Im Dry-Run nicht schreiben; nur zählen
            continue;
        }

        try {
            // UPSERT ausführen
            $upsertStmt->execute();

            // Affected Rows interpretieren:
            //   1 → Insert
            //   2 → Update (mind. 1 der update-Felder hat sich geändert)
            //   0 → Unverändert (Werte identisch)
            $aff = $upsertStmt->affected_rows;
            if ($aff === 1)      $inserted++;
            elseif ($aff === 2)  $updated++;
            else                 $unchanged++;
            
        } catch (Throwable $e) {
            $errors[] = "Zeile {$total} (av_id: {$p_av_id}): " . $e->getMessage();
        }
    }

    // Transaktion committen
    if (!$dryRun) {
        $mysqli->commit();
    }

    // Optional: Quelltabelle leeren (erst NACH Commit)
    $sourceCleared = false;
    if (!$dryRun && $truncateSource && empty($errors)) {
        $mysqli->query("TRUNCATE TABLE `AV-Res-webImp`");
        $sourceCleared = true;
    }

} catch (Throwable $e) {
    if (!$dryRun) {
        $mysqli->rollback();
    }
    
    $errorMessage = "Import-Fehler: " . $e->getMessage();
    
    if ($jsonResponse) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Import-Fehler',
            'message' => $errorMessage,
            'details' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    } else if ($isCli) {
        fwrite(STDERR, $errorMessage . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Import-Fehler', 'details' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit(1);
} finally {
    if (isset($sourceResult) && $sourceResult instanceof mysqli_result) {
        $sourceResult->free();
    }
    if (isset($upsertStmt) && $upsertStmt instanceof mysqli_stmt) {
        $upsertStmt->close();
    }
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
}

// -----------------------------------------------------------------------------
// 8) Ausgabe
// -----------------------------------------------------------------------------
if ($jsonResponse) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'dryRun' => $dryRun,
        'total' => $total,
        'inserted' => $inserted,
        'updated' => $updated,
        'unchanged' => $unchanged,
        'sourceCleared' => $sourceCleared ?? false,
        'errors' => $errors,
        'debug' => $debugInfo ?? [],
        'message' => $dryRun ? 
            "Dry-Run: {$total} Datensätze analysiert" : 
            "Import erfolgreich: {$inserted} neu, {$updated} aktualisiert, {$unchanged} unverändert"
    ], JSON_UNESCAPED_UNICODE);
} else if ($isCli) {
    if ($dryRun) {
        echo "[Dry-Run] Gelesen: {$total} | (keine Schreibvorgänge)\n";
    } else {
        echo "Fertig. Gelesen: {$total} | Inserted: {$inserted} | Updated: {$updated} | Unverändert: {$unchanged}";
        if ($sourceCleared ?? false) echo " | Quelle geleert";
        echo PHP_EOL;
    }
    
    if (!empty($errors)) {
        echo "\nFehler:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'dryRun'     => $dryRun,
        'total'      => $total,
        'inserted'   => $inserted,
        'updated'    => $updated,
        'unchanged'  => $unchanged,
        'sourceCleared' => $sourceCleared ?? false,
        'errors'     => $errors
    ], JSON_UNESCAPED_UNICODE);
}
