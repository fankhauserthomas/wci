<?php
/*
 * HutQuota Import Tool
 * Intelligenter Import von HRS HutQuota-Daten mit Smart Update-Strategie
 */

// Include HRS Login Debug-Klasse
require_once __DIR__ . '/hrs_login_debug.php';

// Output buffering für bessere Formatierung
ob_start();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HutQuota Import Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #2c3e50; color: white; padding: 15px; margin: -20px -20px 20px -20px; border-radius: 8px 8px 0 0; }
        .action-buttons { margin: 20px 0; }
        .btn { display: inline-block; padding: 10px 20px; margin: 5px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; }
        .btn:hover { background: #2980b9; }
        .btn.analyze { background: #e67e22; }
        .btn.analyze:hover { background: #d35400; }
        .btn.import { background: #27ae60; }
        .btn.import:hover { background: #229954; }
        .btn.danger { background: #e74c3c; }
        .btn.danger:hover { background: #c0392b; }
        .debug-output { background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; margin: 15px 0; border-radius: 4px; font-family: monospace; white-space: pre-wrap; max-height: 600px; overflow-y: auto; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .info { color: #17a2b8; font-weight: bold; }
        .parameter-form { background: #e3f2fd; padding: 15px; margin: 15px 0; border-radius: 4px; }
        .parameter-form input, .parameter-form select { margin: 5px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .result-summary { background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 15px 0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏠 HutQuota Import Tool</h1>
            <p>Intelligenter Import von HRS HutQuota-Daten mit Smart Update-Strategie</p>
        </div>

        <div class="parameter-form">
            <h3>📋 Parameter</h3>
            <form method="GET">
                <label>Aktion:</label>
                <select name="action">
                    <option value="">-- Wählen Sie eine Aktion --</option>
                    <option value="analyze" <?= ($_GET['action'] ?? '') === 'analyze' ? 'selected' : '' ?>>📊 Datenstruktur analysieren</option>
                    <option value="import" <?= ($_GET['action'] ?? '') === 'import' ? 'selected' : '' ?>>📥 HutQuota importieren</option>
                    <option value="validate" <?= ($_GET['action'] ?? '') === 'validate' ? 'selected' : '' ?>>🔍 Import validieren</option>
                </select>
                
                <label>Monate:</label>
                <input type="number" name="months" value="<?= htmlspecialchars($_GET['months'] ?? '3') ?>" min="1" max="12">
                
                <label>Hütten-ID:</label>
                <input type="number" name="hut_id" value="<?= htmlspecialchars($_GET['hut_id'] ?? '675') ?>">
                
                <button type="submit" class="btn">🚀 Ausführen</button>
            </form>
        </div>

        <div class="action-buttons">
            <a href="?action=analyze&months=3" class="btn analyze">📊 3 Monate analysieren</a>
            <a href="?action=import&months=3&hut_id=675" class="btn import">📥 3 Monate importieren</a>
            <a href="?action=validate&hut_id=675" class="btn">🔍 Import validieren</a>
            <a href="hrs_login_debug.php" class="btn">🔄 Zum Haupt-Tool</a>
        </div>

        <div class="debug-output">
<?php

$action = $_GET['action'] ?? '';
$months = isset($_GET['months']) ? intval($_GET['months']) : 3;
$hutId = isset($_GET['hut_id']) ? intval($_GET['hut_id']) : 675;

echo "=== HutQuota Import Tool - " . date('Y-m-d H:i:s') . " ===\n";
echo "Aktion: " . htmlspecialchars($action) . "\n";
echo "Monate: $months\n";
echo "Hütten-ID: $hutId\n";
echo "=====================================\n\n";

if (!empty($action)) {
    try {
        $hrs = new HRSLoginDebug();
        
        if ($action === 'analyze') {
            echo "🔍 Analysiere HutQuota-Datenstruktur für $months Monate...\n\n";
            $result = $hrs->analyzeHutQuotaStructure($months);
            
        } elseif ($action === 'import') {
            echo "📥 Starte HutQuota-Import für $months Monate, Hütten-ID: $hutId...\n\n";
            
            // Zuerst analysieren
            echo "1️⃣ Schritt 1: Datenstruktur analysieren\n";
            $analyzeResult = $hrs->analyzeHutQuotaStructure($months);
            
            if ($analyzeResult) {
                echo "\n2️⃣ Schritt 2: Import durchführen\n";
                $importResult = $hrs->testFullHutQuotaImport($months, $hutId);
                
                if ($importResult && is_array($importResult)) {
                    echo "\n" . str_repeat("=", 50) . "\n";
                    echo "✅ IMPORT ERFOLGREICH ABGESCHLOSSEN!\n";
                    echo "📊 Statistiken:\n";
                    echo "   📥 Neu importiert: " . $importResult['imported'] . "\n";
                    echo "   🔄 Aktualisiert: " . $importResult['updated'] . "\n";
                    echo "   ❌ Fehler: " . $importResult['errors'] . "\n";
                    echo "   📈 Gesamt verarbeitet: " . $importResult['total'] . "\n";
                    echo str_repeat("=", 50) . "\n";
                    
                    // Zusätzliche Validierung
                    echo "\n3️⃣ Schritt 3: Import validieren\n";
                    $hrs->validateImportedQuotas($hutId);
                }
            }
            
        } elseif ($action === 'validate') {
            echo "🔍 Validiere importierte HutQuota-Daten für Hütten-ID: $hutId...\n\n";
            
            require_once __DIR__ . '/config.php';
            
            if (!isset($GLOBALS['mysqli']) || !$GLOBALS['mysqli']) {
                $mysqli = new mysqli($GLOBALS['dbHost'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], $GLOBALS['dbName']);
                if ($mysqli->connect_error) {
                    echo "❌ Datenbankverbindung fehlgeschlagen: " . $mysqli->connect_error . "\n";
                } else {
                    $mysqli->set_charset('utf8mb4');
                    validateDatabase($mysqli, $hutId);
                }
            } else {
                validateDatabase($GLOBALS['mysqli'], $hutId);
            }
        }
        
    } catch (Exception $e) {
        echo "❌ FEHLER: " . $e->getMessage() . "\n";
        echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
    }
}

function validateDatabase($mysqli, $hutId) {
    echo "📊 Datenbankvalidierung für Hütten-ID: $hutId\n";
    echo str_repeat("-", 40) . "\n";
    
    // Gesamtanzahl Quotas
    $sql = "SELECT COUNT(*) as count FROM hut_quota WHERE hut_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $hutId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $quotaCount = $row['count'];
    $stmt->close();
    
    echo "🏠 Gesamt HutQuotas: $quotaCount\n";
    
    // Quotas nach Modus
    $sql = "SELECT mode, COUNT(*) as count FROM hut_quota WHERE hut_id = ? GROUP BY mode";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $hutId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "📋 Quotas nach Modus:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   " . $row['mode'] . ": " . $row['count'] . "\n";
    }
    $stmt->close();
    
    // Kategorien
    $sql = "SELECT COUNT(*) as count FROM hut_quota_categories hqc 
            JOIN hut_quota hq ON hqc.hut_quota_id = hq.id 
            WHERE hq.hut_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $hutId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $categoryCount = $row['count'];
    $stmt->close();
    
    echo "🛏️ Gesamt Kategorien: $categoryCount\n";
    
    // Kategorien-Details
    $sql = "SELECT hqc.category_id, COUNT(*) as count, SUM(hqc.total_beds) as total_beds
            FROM hut_quota_categories hqc 
            JOIN hut_quota hq ON hqc.hut_quota_id = hq.id 
            WHERE hq.hut_id = ? 
            GROUP BY hqc.category_id 
            ORDER BY hqc.category_id";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $hutId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "🏷️ Kategorien-Details:\n";
    while ($row = $result->fetch_assoc()) {
        $categoryName = getCategoryName($row['category_id']);
        echo "   Kategorie " . $row['category_id'] . " ($categoryName): " . $row['count'] . " Quotas, " . $row['total_beds'] . " Betten gesamt\n";
    }
    $stmt->close();
    
    // Sprachen
    $sql = "SELECT COUNT(*) as count FROM hut_quota_languages hql 
            JOIN hut_quota hq ON hql.hut_quota_id = hq.id 
            WHERE hq.hut_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $hutId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $languageCount = $row['count'];
    $stmt->close();
    
    echo "🌐 Gesamt Sprachen: $languageCount\n";
    
    // Aktuelle Quotas
    $sql = "SELECT hrs_id, date_from, date_to, title, mode, capacity, is_recurring
            FROM hut_quota 
            WHERE hut_id = ? 
            AND date_to >= CURDATE()
            ORDER BY date_from ASC 
            LIMIT 10";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $hutId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "\n📅 Aktuelle/Zukünftige Quotas (max. 10):\n";
    while ($row = $result->fetch_assoc()) {
        $recurring = $row['is_recurring'] ? " (wiederkehrend)" : "";
        echo "   HRS-ID " . $row['hrs_id'] . ": " . $row['date_from'] . " - " . $row['date_to'] . 
             " (" . $row['mode'] . ", " . $row['capacity'] . " Plätze)" . $recurring . "\n";
        echo "     \"" . substr($row['title'], 0, 60) . "\"\n";
    }
    $stmt->close();
    
    echo "\n✅ Validierung abgeschlossen\n";
}

function getCategoryName($categoryId) {
    $categories = [
        1958 => 'ML/DORM',
        2293 => 'MBZ/SB',
        2381 => '2BZ/DR',
        6106 => 'SK/SC'
    ];
    return $categories[$categoryId] ?? 'Unbekannt';
}

?>
        </div>

        <div class="result-summary">
            <h3>📋 Wichtige Hinweise zum HutQuota-Import:</h3>
            <ul>
                <li><strong>🔄 Smart Update:</strong> Das System erkennt Änderungen an Quotas und aktualisiert sie entsprechend</li>
                <li><strong>🗑️ Automatische Bereinigung:</strong> Obsolete Quotas werden automatisch gelöscht</li>
                <li><strong>🔐 HRS-ID Tracking:</strong> Quotas werden über ihre HRS-ID eindeutig identifiziert</li>
                <li><strong>⚡ Transaktionale Sicherheit:</strong> Bei Fehlern wird ein Rollback durchgeführt</li>
                <li><strong>📊 Kategorien & Sprachen:</strong> Vollständiger Import aller Quota-Details</li>
            </ul>
        </div>

        <div class="action-buttons">
            <h3>🔗 Verwandte Tools:</h3>
            <a href="hrs_login_debug.php" class="btn">🏠 HRS Login Debug</a>
            <a href="daily_summary_import.php" class="btn">📊 DailySummary Import</a>
            <a href="hut_quota_analysis.php" class="btn">🔍 HutQuota Analyse</a>
        </div>
    </div>
</body>
</html>

<?php
ob_end_flush();
?>
