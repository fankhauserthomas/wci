<?php
/**
 * Automatisierter HRS Import mit Backup und Reporting
 * ---------------------------------------------------
 * Ablauf:
 *   1. Backup der Tabelle `AV-Res`
 *   2. Import der gewünschten Datentypen (Reservierungen / Daily Summaries / Quotas)
 *   3. WebImp Dry-Run zur Validierung
 *   4. WebImp → Production Übernahme (optional, falls Reservierungen importiert wurden)
 *   5. Bei Fehlern: Wiederherstellung aus Backup
 *   6. Ausgabe eines JSON-Berichts für Cron/Automatisierung
 */

declare(strict_types=1);

$scriptStart = microtime(true);
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../config.php';

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    $error = 'Keine gültige MySQL-Verbindung verfügbar.';
    $response = [
        'success' => false,
        'error' => $error,
        'steps' => [],
        'duration_seconds' => round(microtime(true) - $scriptStart, 3)
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}

// -----------------------------------------------------------------------------
// Hilfsfunktionen
// -----------------------------------------------------------------------------

/**
 * Wandelt verschieden formatierte Datumsangaben in `Y-m-d` um.
 */
function normalizeDate(string $input): string
{
    $value = trim($input);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
        $dt = DateTime::createFromFormat('d.m.Y', $value);
        if ($dt === false) {
            throw new InvalidArgumentException("Ungültiges Datum: {$input}");
        }
        return $dt->format('Y-m-d');
    }

    throw new InvalidArgumentException("Datum muss im Format YYYY-MM-DD oder DD.MM.YYYY übergeben werden: {$input}");
}

/**
 * Formatiert `Y-m-d` als `d.m.Y`.
 */
function formatDateForCli(string $ymd): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    if ($dt === false) {
        throw new InvalidArgumentException("Ungültiges Datum (Y-m-d erwartet): {$ymd}");
    }
    return $dt->format('d.m.Y');
}

/**
 * Zerlegt Datentyp-Parameter in gültige Werte.
 */
function parseDataTypes($input): array
{
    $allowed = ['reservations', 'daily', 'quotas'];
    $aliases = [
        'reservation' => 'reservations',
        'reservierungen' => 'reservations',
        'daily_summary' => 'daily',
        'daily_summaries' => 'daily',
        'daily_summarys' => 'daily',
        'daily-summary' => 'daily',
        'quotas' => 'quotas',
        'quota' => 'quotas'
    ];

    if ($input === null || $input === '' || $input === []) {
        return $allowed;
    }

    if (is_string($input)) {
        $parts = preg_split('/[\s,;]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
    } elseif (is_array($input)) {
        $parts = $input;
    } else {
        throw new InvalidArgumentException('Datentyp-Parameter konnte nicht interpretiert werden.');
    }

    $result = [];
    foreach ($parts as $part) {
        $key = strtolower(trim((string) $part));
        if ($key === '') {
            continue;
        }
        if (isset($aliases[$key])) {
            $key = $aliases[$key];
        }
        if (!in_array($key, $allowed, true)) {
            throw new InvalidArgumentException("Unbekannter Datentyp: {$part}");
        }
        if (!in_array($key, $result, true)) {
            $result[] = $key;
        }
    }

    if (empty($result)) {
        return $allowed;
    }

    return $result;
}

/**
 * Erstellt einen Eintrag im Schritt-Array.
 */
function initStep(array &$steps, string $key, string $label): void
{
    $steps[$key] = [
        'label' => $label,
        'status' => 'pending',
        'started_at' => null,
        'finished_at' => null,
        'duration_seconds' => null,
        'message' => null,
        'details' => null
    ];
}

/**
 * Markiert einen Schritt als gestartet.
 */
function startStep(array &$steps, string $key): void
{
    $steps[$key]['status'] = 'running';
    $steps[$key]['started_at'] = date('c');
}

/**
 * Markiert einen Schritt als abgeschlossen.
 */
function finishStep(array &$steps, string $key, string $status, ?string $message = null, $details = null): void
{
    $steps[$key]['status'] = $status;
    $steps[$key]['finished_at'] = date('c');
    if ($steps[$key]['started_at']) {
        $steps[$key]['duration_seconds'] = round(strtotime($steps[$key]['finished_at']) - strtotime($steps[$key]['started_at']), 2);
    }
    if ($message !== null) {
        $steps[$key]['message'] = $message;
    }
    if ($details !== null) {
        $steps[$key]['details'] = $details;
    }
}

/**
 * Führt einen Shell-Befehl aus und gibt stdout/stderr/exit-code zurück.
 */
function runCommand(string $command): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException("Konnte Befehl nicht ausführen: {$command}");
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'command' => $command,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit_code' => $exitCode
    ];
}

/**
 * Schneidet Logs auf handliche Länge.
 */
function truncateLog(string $log, int $limitLines = 200): array
{
    $lines = preg_split('/\r?\n/', trim($log));
    $lines = array_values(array_filter($lines, static fn($line) => $line !== ''));

    if (count($lines) <= $limitLines) {
        return $lines;
    }

    $headCount = (int) floor($limitLines / 2);
    $tailCount = $limitLines - $headCount;

    $head = array_slice($lines, 0, $headCount);
    $tail = array_slice($lines, -$tailCount);

    return array_merge($head, ['… Ausgabe gekürzt …'], $tail);
}

/**
 * Führt ein PHP-Skript mit Argumenten aus.
 */
function runPhpScript(string $scriptPath, array $args = []): array
{
    $components = [escapeshellarg(PHP_BINARY), escapeshellarg($scriptPath)];
    foreach ($args as $arg) {
        $components[] = escapeshellarg($arg);
    }
    $command = implode(' ', $components);
    return runCommand($command);
}

/**
 * Erstellt Backup der Tabelle `AV-Res`.
 */
function createAvResBackup(mysqli $mysqli): array
{
    $timestamp = date('Y-m-d_H-i-s');
    $backupName = "AV_Res_Backup_{$timestamp}";

    $createSql = "CREATE TABLE `{$backupName}` LIKE `AV-Res`";
    if (!$mysqli->query($createSql)) {
        return [
            'success' => false,
            'error' => 'Fehler beim Erstellen der Backup-Tabelle: ' . $mysqli->error
        ];
    }

    $copySql = "INSERT INTO `{$backupName}` SELECT * FROM `AV-Res`";
    if (!$mysqli->query($copySql)) {
        $mysqli->query("DROP TABLE IF EXISTS `{$backupName}`");
        return [
            'success' => false,
            'error' => 'Fehler beim Kopieren der Daten: ' . $mysqli->error
        ];
    }

    $countResult = $mysqli->query("SELECT COUNT(*) AS cnt FROM `{$backupName}`");
    $count = $countResult ? (int) $countResult->fetch_assoc()['cnt'] : 0;

    return [
        'success' => true,
        'backup_name' => $backupName,
        'record_count' => $count
    ];
}

/**
 * Stellt `AV-Res` anhand einer Backup-Tabelle wieder her.
 */
function restoreAvResFromBackup(mysqli $mysqli, string $backupName): array
{
    if ($backupName === '') {
        return [
            'success' => false,
            'error' => 'Kein Backup-Name angegeben.'
        ];
    }

    $check = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($backupName) . "'");
    if (!$check || $check->num_rows === 0) {
        return [
            'success' => false,
            'error' => "Backup-Tabelle '{$backupName}' nicht gefunden."
        ];
    }

    $preRestoreName = 'AV_Res_PreRestore_' . date('Y-m-d_H-i-s');

    try {
        if (!$mysqli->begin_transaction()) {
            throw new RuntimeException('Konnte Transaktion nicht starten: ' . $mysqli->error);
        }

        // Sicherung des aktuellen Stands
        if (!$mysqli->query("CREATE TABLE `{$preRestoreName}` LIKE `AV-Res`")) {
            throw new RuntimeException('Fehler beim Erstellen des PreRestore-Backups: ' . $mysqli->error);
        }
        if (!$mysqli->query("INSERT INTO `{$preRestoreName}` SELECT * FROM `AV-Res`")) {
            throw new RuntimeException('Fehler beim Kopieren des aktuellen Stands: ' . $mysqli->error);
        }

        if (!$mysqli->query("DELETE FROM `AV-Res`")) {
            throw new RuntimeException('Fehler beim Leeren von AV-Res: ' . $mysqli->error);
        }

        if (!$mysqli->query("INSERT INTO `AV-Res` SELECT * FROM `{$backupName}`")) {
            throw new RuntimeException('Fehler beim Wiederherstellen der Backup-Daten: ' . $mysqli->error);
        }

        $mysqli->commit();

        $countResult = $mysqli->query("SELECT COUNT(*) AS cnt FROM `AV-Res`");
        $count = $countResult ? (int) $countResult->fetch_assoc()['cnt'] : 0;

        return [
            'success' => true,
            'restored_records' => $count,
            'pre_restore_backup' => $preRestoreName
        ];
    } catch (Throwable $e) {
        $mysqli->rollback();
        $mysqli->query("DROP TABLE IF EXISTS `{$preRestoreName}`");
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Bewertet die Ausgabe des WebImp-Skripts.
 */
function analyseWebImpOutput(array $result): array
{
    $combined = strtolower($result['stdout'] . '\n' . $result['stderr']);
    $hasErrorToken = strpos($combined, 'fehler') !== false
        || strpos($combined, '❌') !== false
        || strpos($combined, 'import abgebrochen') !== false;

    $success = ($result['exit_code'] === 0) && !$hasErrorToken;

    return [
        'success' => $success,
        'log_excerpt' => [
            'stdout' => truncateLog($result['stdout']),
            'stderr' => truncateLog($result['stderr'])
        ]
    ];
}

// -----------------------------------------------------------------------------
// Eingaben verarbeiten
// -----------------------------------------------------------------------------

try {
    if ($isCli) {
        $cliArgs = $argv;
        array_shift($cliArgs); // Skriptname entfernen

        $options = getopt('', ['from:', 'to:', 'data:']);
        $fromInput = $options['from'] ?? ($cliArgs[0] ?? null);
        $toInput = $options['to'] ?? ($cliArgs[1] ?? null);
        $dataInput = $options['data'] ?? ($cliArgs[2] ?? null);
    } else {
        $fromInput = $_GET['from'] ?? null;
        $toInput = $_GET['to'] ?? null;
        $dataInput = $_GET['data'] ?? ($_GET['types'] ?? null);
        if (isset($_GET['type']) && !$dataInput) {
            $dataInput = $_GET['type'];
        }
    }

    if (!$fromInput || !$toInput) {
        throw new InvalidArgumentException('Parameter "from" und "to" sind erforderlich.');
    }

    $fromDate = normalizeDate((string) $fromInput);
    $toDate = normalizeDate((string) $toInput);

    if ($fromDate > $toDate) {
        throw new InvalidArgumentException('Das Anfangsdatum darf nicht nach dem Enddatum liegen.');
    }

    $dataTypes = parseDataTypes($dataInput);
} catch (Throwable $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'steps' => [],
        'duration_seconds' => round(microtime(true) - $scriptStart, 3)
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}

// -----------------------------------------------------------------------------
// Schritt-Initialisierung
// -----------------------------------------------------------------------------

$steps = [];
initStep($steps, 'backup', 'Backup AV-Res erstellen');
initStep($steps, 'import_reservations', 'Reservierungen importieren');
initStep($steps, 'import_daily', 'Daily Summaries importieren');
initStep($steps, 'import_quotas', 'Quotas importieren');
initStep($steps, 'dry_run', 'WebImp Dry-Run');
initStep($steps, 'production', 'WebImp → Production');
initStep($steps, 'restore', 'Wiederherstellung AV-Res');

$report = [
    'success' => false,
    'from' => $fromDate,
    'to' => $toDate,
    'data_types' => $dataTypes,
    'started_at' => date('c'),
    'steps' => &$steps,
    'errors' => []
];

$backupName = '';
$avResModified = false;
$errorOccurred = false;

// -----------------------------------------------------------------------------
// Schritt 1: Backup erstellen
// -----------------------------------------------------------------------------

startStep($steps, 'backup');
$backupResult = createAvResBackup($mysqli);
if ($backupResult['success']) {
    $backupName = $backupResult['backup_name'];
    finishStep(
        $steps,
        'backup',
        'success',
        "Backup {$backupName} mit {$backupResult['record_count']} Datensätzen erstellt",
        $backupResult
    );
} else {
    $errorOccurred = true;
    finishStep($steps, 'backup', 'failed', $backupResult['error']);
    $report['errors'][] = $backupResult['error'];
}

// -----------------------------------------------------------------------------
// Schritt 2: Import der gewünschten Datentypen
// -----------------------------------------------------------------------------

$scriptMap = [
    'reservations' => __DIR__ . '/../hrs/hrs_imp_res.php',
    'daily' => __DIR__ . '/../hrs/hrs_imp_daily.php',
    'quotas' => __DIR__ . '/../hrs/hrs_imp_quota.php'
];

$cliFrom = formatDateForCli($fromDate);
$cliTo = formatDateForCli($toDate);

foreach (['reservations', 'daily', 'quotas'] as $type) {
    $stepKey = 'import_' . $type;
    if (!in_array($type, $dataTypes, true)) {
        finishStep($steps, $stepKey, 'skipped', 'Übersprungen (nicht angefordert)');
        continue;
    }

    if ($errorOccurred) {
        finishStep($steps, $stepKey, 'skipped', 'Übersprungen (vorheriger Fehler)');
        continue;
    }

    startStep($steps, $stepKey);
    $scriptPath = $scriptMap[$type];

    $result = runPhpScript($scriptPath, [$cliFrom, $cliTo]);

    $logDetails = [
        'command' => $result['command'],
        'stdout' => truncateLog($result['stdout']),
        'stderr' => truncateLog($result['stderr']),
        'exit_code' => $result['exit_code']
    ];

    if ($result['exit_code'] === 0) {
        finishStep(
            $steps,
            $stepKey,
            'success',
            'Import erfolgreich abgeschlossen',
            $logDetails
        );
    } else {
        $errorOccurred = true;
        $message = 'Import fehlgeschlagen (Exit-Code ' . $result['exit_code'] . ')';
        if ($result['stderr'] !== '') {
            $message .= ': ' . trim($result['stderr']);
        }
        finishStep($steps, $stepKey, 'failed', $message, $logDetails);
        $report['errors'][] = $message;
    }
}

// -----------------------------------------------------------------------------
// Schritt 3: Dry-Run (nur wenn bisher kein Fehler)
// -----------------------------------------------------------------------------

if ($errorOccurred) {
    finishStep($steps, 'dry_run', 'skipped', 'Übersprungen (vorheriger Fehler)');
} else {
    startStep($steps, 'dry_run');
    $dryResult = runPhpScript(__DIR__ . '/../hrs/import_webimp.php', ['--dry-run']);
    $analysis = analyseWebImpOutput($dryResult);

    $details = array_merge(
        [
            'command' => $dryResult['command'],
            'exit_code' => $dryResult['exit_code']
        ],
        $analysis['log_excerpt']
    );

    if ($analysis['success']) {
        finishStep($steps, 'dry_run', 'success', 'Dry-Run erfolgreich abgeschlossen', $details);
    } else {
        $errorOccurred = true;
        $message = 'Dry-Run meldete Fehler';
        finishStep($steps, 'dry_run', 'failed', $message, $details);
        $report['errors'][] = $message;
    }
}

// -----------------------------------------------------------------------------
// Schritt 4: WebImp → Production (nur wenn Reservierungen importiert wurden)
// -----------------------------------------------------------------------------

$reservationsRequested = in_array('reservations', $dataTypes, true);

if (!$reservationsRequested) {
    finishStep($steps, 'production', 'skipped', 'Übersprungen (Reservierungsimport nicht angefordert)');
} elseif ($errorOccurred) {
    finishStep($steps, 'production', 'skipped', 'Übersprungen (vorheriger Fehler)');
} else {
    startStep($steps, 'production');
    $prodResult = runPhpScript(__DIR__ . '/../hrs/import_webimp.php');
    $analysis = analyseWebImpOutput($prodResult);

    $details = array_merge(
        [
            'command' => $prodResult['command'],
            'exit_code' => $prodResult['exit_code']
        ],
        $analysis['log_excerpt']
    );

    $avResModified = true; // Skript versucht produktiven Import

    if ($analysis['success']) {
        finishStep($steps, 'production', 'success', 'WebImp Daten in AV-Res übernommen', $details);
    } else {
        $errorOccurred = true;
        $message = 'WebImp Produktion meldete Fehler';
        finishStep($steps, 'production', 'failed', $message, $details);
        $report['errors'][] = $message;
    }
}

// -----------------------------------------------------------------------------
// Schritt 5: Wiederherstellung bei Fehler
// -----------------------------------------------------------------------------

if ($errorOccurred && $backupName !== '') {
    startStep($steps, 'restore');
    $restoreResult = restoreAvResFromBackup($mysqli, $backupName);
    if ($restoreResult['success']) {
        finishStep(
            $steps,
            'restore',
            'success',
            'AV-Res aus Backup wiederhergestellt',
            $restoreResult
        );
    } else {
        finishStep(
            $steps,
            'restore',
            'failed',
            $restoreResult['error'],
            $restoreResult
        );
        $report['errors'][] = 'Backup-Restore fehlgeschlagen: ' . $restoreResult['error'];
    }
} else {
    finishStep(
        $steps,
        'restore',
        $errorOccurred ? 'skipped' : 'skipped',
        $errorOccurred ? 'Übersprungen (kein Backup verfügbar)' : 'Nicht benötigt'
    );
}

$report['success'] = !$errorOccurred;
$report['finished_at'] = date('c');
$report['duration_seconds'] = round(microtime(true) - $scriptStart, 3);
$report['backup_table'] = $backupName ?: null;
$report['av_res_modified'] = $avResModified;

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit($errorOccurred ? 1 : 0);
