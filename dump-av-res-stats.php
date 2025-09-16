<?php
/**
 * dump-av-res-stats.php - Zeigt Ausführungsstatistiken für dump-av-res.php
 */

$statsFile = __DIR__ . '/logs/av-res-dump-stats.json';

echo "========================================\n";
echo "AV-RES DUMP - AUSFÜHRUNGSSTATISTIKEN\n";
echo "========================================\n\n";

if (!file_exists($statsFile)) {
    echo "❌ Keine Statistik-Datei gefunden!\n";
    echo "Script wurde noch nie ausgeführt: $statsFile\n";
    exit(1);
}

$stats = json_decode(file_get_contents($statsFile), true);

if (!$stats) {
    echo "❌ Fehler beim Lesen der Statistik-Datei!\n";
    exit(1);
}

// Allgemeine Statistiken
echo "📊 ALLGEMEINE STATISTIKEN:\n";
echo "─────────────────────────────\n";
echo "Gesamte Ausführungen: " . ($stats['total_runs'] ?? 0) . "\n";
echo "Erfolgreiche Läufe: " . ($stats['success_runs'] ?? 0) . "\n";
echo "Fehlgeschlagene Läufe: " . ($stats['error_runs'] ?? 0) . "\n";

if (isset($stats['success_runs']) && isset($stats['total_runs']) && $stats['total_runs'] > 0) {
    $successRate = round(($stats['success_runs'] / $stats['total_runs']) * 100, 1);
    echo "Erfolgsrate: $successRate%\n";
}

echo "\n";

// Zeitstatistiken
echo "🕐 ZEITSTATISTIKEN:\n";
echo "─────────────────────\n";
if (isset($stats['first_run_formatted'])) {
    echo "Erste Ausführung: " . $stats['first_run_formatted'] . "\n";
}
if (isset($stats['last_run_formatted'])) {
    echo "Letzte Ausführung: " . $stats['last_run_formatted'] . "\n";
    
    $timeSinceLastRun = time() - ($stats['last_run'] ?? 0);
    $minutes = floor($timeSinceLastRun / 60);
    $seconds = $timeSinceLastRun % 60;
    echo "Vor: {$minutes}m {$seconds}s\n";
}

if (isset($stats['first_run']) && isset($stats['last_run'])) {
    $totalDays = round(($stats['last_run'] - $stats['first_run']) / 86400, 1);
    if ($totalDays > 0) {
        $avgPerDay = round($stats['total_runs'] / $totalDays, 1);
        echo "Läuft seit: $totalDays Tagen\n";
        echo "Durchschnitt: $avgPerDay Ausführungen/Tag\n";
    }
}

echo "\n";

// Performance
echo "⚡ PERFORMANCE (letzter Lauf):\n";
echo "─────────────────────────────────\n";
if (isset($stats['last_success_formatted'])) {
    echo "Letzter Erfolg: " . $stats['last_success_formatted'] . "\n";
}
if (isset($stats['last_duration'])) {
    echo "Dauer: " . $stats['last_duration'] . "s\n";
}
if (isset($stats['last_records_copied'])) {
    echo "Kopierte Datensätze: " . number_format($stats['last_records_copied']) . "\n";
    
    if (isset($stats['last_duration']) && $stats['last_duration'] > 0) {
        $throughput = round($stats['last_records_copied'] / $stats['last_duration'], 0);
        echo "Durchsatz: " . number_format($throughput) . " Datensätze/Sekunde\n";
    }
}

if (isset($stats['total_records_copied'])) {
    echo "Gesamt kopiert: " . number_format($stats['total_records_copied']) . " Datensätze\n";
}

echo "\n";

// Fehler-Informationen
if (isset($stats['error_runs']) && $stats['error_runs'] > 0) {
    echo "❌ FEHLER-INFORMATIONEN:\n";
    echo "───────────────────────────\n";
    echo "Anzahl Fehler: " . $stats['error_runs'] . "\n";
    
    if (isset($stats['last_error_formatted'])) {
        echo "Letzter Fehler: " . $stats['last_error_formatted'] . "\n";
    }
    if (isset($stats['last_error_message'])) {
        echo "Letzte Fehlermeldung: " . $stats['last_error_message'] . "\n";
    }
    echo "\n";
}

// Status
echo "🟢 AKTUELLER STATUS:\n";
echo "───────────────────────\n";

if (isset($stats['last_success']) && isset($stats['last_run'])) {
    if ($stats['last_success'] >= $stats['last_run']) {
        echo "Status: ✅ ERFOLGREICH\n";
        echo "Letzter Lauf war erfolgreich\n";
    } else {
        echo "Status: ⚠️ FEHLER\n";
        echo "Letzter Lauf war fehlerhaft\n";
    }
}

// Erwartete nächste Ausführung (alle 5 Minuten)
if (isset($stats['last_run'])) {
    $nextRun = $stats['last_run'] + (5 * 60); // 5 Minuten
    $timeUntilNext = $nextRun - time();
    
    if ($timeUntilNext > 0) {
        $minutes = floor($timeUntilNext / 60);
        $seconds = $timeUntilNext % 60;
        echo "Nächste Ausführung: in {$minutes}m {$seconds}s\n";
    } else {
        echo "Nächste Ausführung: ⏰ ÜBERFÄLLIG\n";
    }
}

echo "\n";
echo "📝 Log-Datei: /home/vadmin/lemp/html/wci/logs/av-res-dump.log\n";
echo "📊 Statistik-Datei: $statsFile\n";
echo "\n";
?>
