<?php
/**
 * HutQuota Analysis Script
 * Analysiert HutQuota-Daten von HRS und schlägt Tabellenstruktur vor
 */

require_once 'hrs_login_debug.php';

// HTML-Ausgabe starten
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HutQuota Analyse</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #fafafa; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #ff8c00; color: white; padding: 15px; margin: -20px -20px 20px -20px; border-radius: 8px 8px 0 0; }
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
        <h1>🏠 HutQuota Analyse</h1>
        <p>Analysiert Kapazitätsänderungen von HRS API und schlägt Tabellenstruktur vor</p>
    </div>
    
    <div class="warning">
        <strong>ℹ️ INFO:</strong> Dieses Script analysiert HutQuota-Daten für die kommenden Monate und schlägt eine Datenbank-Struktur vor
    </div>
    
    <div class="status">🔄 Analyse läuft...</div>
    <div class="debug-output">
<?php
// Output Buffering aktivieren für sofortige Ausgabe
ob_start();
flush();

// Parameter verarbeiten
$hutId = isset($_GET['hutId']) ? intval($_GET['hutId']) : 675;
$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : date('d.m.Y');
$months = isset($_GET['months']) ? intval($_GET['months']) : 2;

// Debug: Parameter anzeigen
echo "<div style='background: #e6f3ff; padding: 10px; margin: 10px 0; border: 1px solid #007cba; border-radius: 4px;'>";
echo "<h4>📋 Parameter:</h4>";
echo "<ul>";
echo "<li><strong>HutId:</strong> " . htmlspecialchars($hutId) . "</li>";
echo "<li><strong>StartDate:</strong> " . htmlspecialchars($startDate) . "</li>";
echo "<li><strong>Months:</strong> " . htmlspecialchars($months) . "</li>";
echo "</ul>";
echo "<p><small>💡 <strong>URL Usage:</strong> <code>hut_quota_analysis.php?hutId=675&startDate=19.08.2025&months=3</code></small></p>";
echo "</div>";

try {
    // HRS Login und HutQuota Analyse
    $hrs = new HRSLoginDebug();
    
    // 1. Initialize
    if (!$hrs->initializeAsync()) {
        throw new Exception("Initialize fehlgeschlagen");
    }
    
    // 2. Login
    if (!$hrs->loginAsync()) {
        throw new Exception("Login fehlgeschlagen");
    }
    
    // 3. HutQuota-Daten abrufen
    echo "<h4>🏠 HutQuota-Daten abrufen...</h4>";
    $hutQuotaData = $hrs->getHutQuotaSequence($hutId, $startDate, $months);
    
    if (!$hutQuotaData || !is_array($hutQuotaData)) {
        throw new Exception("HutQuota-Daten konnten nicht abgerufen werden");
    }
    
    echo "<div style='background: #fff3e6; padding: 10px; margin: 10px 0; border: 1px solid #ff8c00; border-radius: 4px;'>";
    echo "<strong>✅ " . count($hutQuotaData) . " Kapazitätsänderungen erfolgreich von HRS abgerufen</strong>";
    echo "</div>";
    
    // 4. Datenstruktur analysieren
    echo "<h4>📊 Datenstruktur-Analyse...</h4>";
    $analysisResult = $hrs->analyzeHutQuotaStructure($hutQuotaData);
    
    if (!$analysisResult) {
        throw new Exception("Datenstruktur-Analyse fehlgeschlagen");
    }
    
    // Erfolgs-Ausgabe
    echo "</div>";
    echo '<div class="final-result success">✅ ERFOLG: HutQuota-Analyse erfolgreich abgeschlossen!</div>';
    
    echo '<div style="background: #fff3e6; padding: 15px; margin: 15px 0; border: 1px solid #ff8c00; border-radius: 4px;">';
    echo '<h3>📊 Analyse-Statistik:</h3>';
    echo '<ul>';
    echo '<li><strong>Analysierte Einträge:</strong> ' . count($hutQuotaData) . ' Kapazitätsänderungen</li>';
    echo '<li><strong>Zeitraum:</strong> ' . $startDate . ' + ' . $months . ' Monate</li>';
    echo '<li><strong>Datenfelder:</strong> ~20 Haupt-Attribute pro Eintrag</li>';
    echo '<li><strong>Kategorien:</strong> Meist 4 (ML, MBZ, 2BZ, SK) pro Zeitraum</li>';
    echo '<li><strong>Sprachen:</strong> DE_DE, EN (teils weitere)</li>';
    echo '</ul>';
    
    // Beispiel-Statistiken
    $modes = array();
    $totalCapacity = 0;
    $categoryStats = array();
    
    foreach ($hutQuotaData as $quota) {
        $mode = $quota['mode'] ?? 'UNKNOWN';
        $modes[$mode] = ($modes[$mode] ?? 0) + 1;
        $totalCapacity += intval($quota['capacity'] ?? 0);
        
        if (isset($quota['hutBedCategoryDTOs'])) {
            foreach ($quota['hutBedCategoryDTOs'] as $cat) {
                $catId = $cat['categoryId'];
                $categoryStats[$catId] = ($categoryStats[$catId] ?? 0) + intval($cat['totalBeds'] ?? 0);
            }
        }
    }
    
    echo '<li><strong>Modi-Verteilung:</strong>';
    foreach ($modes as $mode => $count) {
        echo " $mode($count)";
    }
    echo '</li>';
    
    echo '<li><strong>Durchschnittliche Kapazität:</strong> ' . round($totalCapacity / count($hutQuotaData)) . ' Plätze</li>';
    
    echo '<li><strong>Kategorie-Summen:</strong>';
    foreach ($categoryStats as $catId => $total) {
        $catName = '';
        switch ($catId) {
            case 1958: $catName = 'ML'; break;
            case 2293: $catName = 'MBZ'; break;
            case 2381: $catName = '2BZ'; break;
            case 6106: $catName = 'SK'; break;
            default: $catName = "Cat$catId"; break;
        }
        echo " $catName($total)";
    }
    echo '</li>';
    
    echo '</ul>';
    echo '</div>';
    
    echo '<h3>📝 Empfohlene Tabellen:</h3>';
    echo '<ol>';
    echo '<li><strong>hut_quota</strong> - Haupt-Tabelle mit Zeiträumen, Modi und Gesamtkapazität</li>';
    echo '<li><strong>hut_quota_categories</strong> - Kategorien-spezifische Bettenzahlen</li>';
    echo '<li><strong>hut_quota_languages</strong> - Mehrsprachige Beschreibungen</li>';
    echo '</ol>';
    
    echo '<h3>🔄 Nächste Schritte:</h3>';
    echo '<ul>';
    echo '<li>✅ Tabellen in Datenbank anlegen (SQL oben)</li>';
    echo '<li>✅ Import-Funktionen implementieren</li>';
    echo '<li>✅ Regelmäßige Updates einrichten</li>';
    echo '<li>🔄 Integration mit DailySummary für Vollbild</li>';
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
