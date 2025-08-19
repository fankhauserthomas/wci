<?php
/**
 * DailySummary Import Script
 * Importiert DailySummary-Daten von HRS in die lokale Datenbank
 */

require_once 'hrs_login_debug.php';

// HTML-Ausgabe starten
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DailySummary Import</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #fafafa; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #28a745; color: white; padding: 15px; margin: -20px -20px 20px -20px; border-radius: 8px 8px 0 0; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px; color: #856404; }
        .status { font-weight: bold; margin: 10px 0; }
        .debug-output { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; max-height: 600px; overflow-y: auto; }
        .final-result { margin: 20px 0; padding: 15px; border-radius: 4px; font-weight: bold; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📅 DailySummary Import</h1>
        <p>Importiert Tagesübersichten von HRS API in lokale Datenbank</p>
    </div>
    
    <div class="warning">
        <strong>ℹ️ INFO:</strong> Dieses Script importiert DailySummary-Daten für die nächsten ~50 Tage (5 Sequenzen à 10 Tage)
    </div>
    
    <div class="status">🔄 Import läuft...</div>
    <div class="debug-output">
<?php
// Output Buffering aktivieren für sofortige Ausgabe
ob_start();
flush();

// Parameter verarbeiten
$hutId = isset($_GET['hutId']) ? intval($_GET['hutId']) : 675;
$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : date('d.m.Y');
$sequences = isset($_GET['sequences']) ? intval($_GET['sequences']) : 5;

// Debug: Parameter anzeigen
echo "<div style='background: #e6f3ff; padding: 10px; margin: 10px 0; border: 1px solid #007cba; border-radius: 4px;'>";
echo "<h4>📋 Parameter:</h4>";
echo "<ul>";
echo "<li><strong>HutId:</strong> " . htmlspecialchars($hutId) . "</li>";
echo "<li><strong>StartDate:</strong> " . htmlspecialchars($startDate) . "</li>";
echo "<li><strong>Sequences:</strong> " . htmlspecialchars($sequences) . " (à 10 Tage)</li>";
echo "</ul>";
echo "<p><small>💡 <strong>URL Usage:</strong> <code>daily_summary_import.php?hutId=675&startDate=19.08.2025&sequences=5</code></small></p>";
echo "</div>";

try {
    // HRS Login und DailySummary Import
    $hrs = new HRSLoginDebug();
    
    // 1. Initialize
    if (!$hrs->initializeAsync()) {
        throw new Exception("Initialize fehlgeschlagen");
    }
    
    // 2. Login
    if (!$hrs->loginAsync()) {
        throw new Exception("Login fehlgeschlagen");
    }
    
    // 3. DailySummary-Daten abrufen
    echo "<h4>📅 DailySummary-Daten abrufen...</h4>";
    $dailySummaryData = $hrs->getDailySummarySequence($hutId, $startDate, $sequences);
    
    if (!$dailySummaryData || !is_array($dailySummaryData)) {
        throw new Exception("DailySummary-Daten konnten nicht abgerufen werden");
    }
    
    echo "<div style='background: #e6ffe6; padding: 10px; margin: 10px 0; border: 1px solid #00aa00; border-radius: 4px;'>";
    echo "<strong>✅ " . count($dailySummaryData) . " Tage erfolgreich von HRS abgerufen</strong>";
    echo "</div>";
    
    // 4. In Datenbank importieren
    echo "<h4>💾 Import in Datenbank...</h4>";
    $importResult = $hrs->importDailySummaryToDb($dailySummaryData, $hutId);
    
    if (!$importResult) {
        throw new Exception("Datenbank-Import fehlgeschlagen");
    }
    
    // Erfolgs-Ausgabe
    echo "</div>";
    echo '<div class="final-result success">✅ ERFOLG: DailySummary-Import erfolgreich abgeschlossen!</div>';
    
    echo '<div style="background: #e6ffe6; padding: 15px; margin: 15px 0; border: 1px solid #00aa00; border-radius: 4px;">';
    echo '<h3>📊 Import-Statistik:</h3>';
    echo '<ul>';
    echo '<li><strong>Erfolgreich importiert:</strong> ' . $importResult['imported'] . ' Tage</li>';
    echo '<li><strong>Fehler:</strong> ' . $importResult['errors'] . '</li>';
    echo '<li><strong>Gesamt verarbeitet:</strong> ' . $importResult['total'] . ' Tage</li>';
    echo '<li><strong>Zeitraum:</strong> ' . $startDate . ' + ' . ($sequences * 10) . ' Tage</li>';
    echo '<li><strong>Kategorien pro Tag:</strong> ~4 (ML, MBZ, 2BZ, SK)</li>';
    echo '</ul>';
    
    // Datenbankabfrage zur Bestätigung
    require_once __DIR__ . '/config.php';
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli']) {
        $mysqli = $GLOBALS['mysqli'];
        $result = $mysqli->query("SELECT COUNT(*) as count FROM daily_summary WHERE hut_id = $hutId");
        $row = $result->fetch_assoc();
        $totalDays = $row['count'];
        
        $result2 = $mysqli->query("SELECT COUNT(*) as count FROM daily_summary_categories ds_cat JOIN daily_summary ds ON ds_cat.daily_summary_id = ds.id WHERE ds.hut_id = $hutId");
        $row2 = $result2->fetch_assoc();
        $totalCategories = $row2['count'];
        
        echo '<li><strong>In DB gespeichert:</strong> ' . $totalDays . ' Tage, ' . $totalCategories . ' Kategorien</li>';
    }
    
    echo '</div>';
    
    echo '<h3>📝 Nächste Schritte:</h3>';
    echo '<ul>';
    echo '<li>✅ DailySummary-Daten werden täglich aktualisiert</li>';
    echo '<li>✅ Kategorien-spezifische Auswertungen möglich</li>';
    echo '<li>✅ Historische Vergleiche verfügbar</li>';
    echo '<li>🔄 Regelmäßige Imports empfohlen (täglich)</li>';
    echo '</ul>';
    
} catch (Exception $e) {
    echo "</div>";
    echo '<div class="final-result error">❌ FEHLER: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

ob_end_flush();
?>

</div>
</body>
</html>
