<?php
/*
 * HutQuota Smart Update Test
 * Demonstriert die intelligente Update-Strategie für sich ändernde Quotas
 */

require_once __DIR__ . '/hrs_login_debug.php';
require_once __DIR__ . '/config.php';

echo "=== HutQuota Smart Update Test ===\n";
echo "Zeit: " . date('Y-m-d H:i:s') . "\n\n";

function testSmartUpdate() {
    $hrs = new HRSLoginDebug();
    
    echo "🔄 Teste Smart Update-Mechanismus...\n\n";
    
    // 1. Ersten Import durchführen (1 Monat)
    echo "1️⃣ Erster Import (1 Monat):\n";
    $firstImport = $hrs->testFullHutQuotaImport(1, 675);
    
    if ($firstImport) {
        echo "✅ Erster Import: " . $firstImport['imported'] . " neu, " . $firstImport['updated'] . " aktualisiert\n\n";
        
        // 2. Zweiten Import durchführen (3 Monate) - sollte Updates und neue Einträge haben
        echo "2️⃣ Zweiter Import (3 Monate) - Test auf Überschneidungen:\n";
        $secondImport = $hrs->testFullHutQuotaImport(3, 675);
        
        if ($secondImport) {
            echo "✅ Zweiter Import: " . $secondImport['imported'] . " neu, " . $secondImport['updated'] . " aktualisiert\n\n";
            
            // 3. Gleichen Import wiederholen - sollte nur Updates haben
            echo "3️⃣ Dritter Import (identisch) - Test auf Idempotenz:\n";
            $thirdImport = $hrs->testFullHutQuotaImport(3, 675);
            
            if ($thirdImport) {
                echo "✅ Dritter Import: " . $thirdImport['imported'] . " neu, " . $thirdImport['updated'] . " aktualisiert\n\n";
                
                echo "📊 Smart Update Test-Ergebnisse:\n";
                echo "   Import 1 (1 Monat): " . $firstImport['imported'] . " neu, " . $firstImport['updated'] . " aktualisiert\n";
                echo "   Import 2 (3 Monate): " . $secondImport['imported'] . " neu, " . $secondImport['updated'] . " aktualisiert\n";
                echo "   Import 3 (identisch): " . $thirdImport['imported'] . " neu, " . $thirdImport['updated'] . " aktualisiert\n\n";
                
                // Erwartete Ergebnisse überprüfen
                if ($secondImport['imported'] > $firstImport['imported']) {
                    echo "✅ Test ERFOLGREICH: Zweiter Import hat mehr Daten (3 Monate > 1 Monat)\n";
                } else {
                    echo "⚠️ Test WARNUNG: Zweiter Import hat nicht mehr Daten als erwartet\n";
                }
                
                if ($thirdImport['imported'] == 0 && $thirdImport['updated'] >= 0) {
                    echo "✅ Test ERFOLGREICH: Dritter Import ist idempotent (keine neuen Einträge)\n";
                } else {
                    echo "⚠️ Test WARNUNG: Dritter Import sollte idempotent sein\n";
                }
                
                return true;
            }
        }
    }
    
    echo "❌ Test FEHLGESCHLAGEN\n";
    return false;
}

function analyzeQuotaChanges() {
    echo "\n🔍 Analysiere Quota-Änderungen in der Datenbank...\n";
    
    if (!isset($GLOBALS['mysqli']) || !$GLOBALS['mysqli']) {
        $mysqli = new mysqli($GLOBALS['dbHost'], $GLOBALS['dbUser'], $GLOBALS['dbPass'], $GLOBALS['dbName']);
        if ($mysqli->connect_error) {
            echo "❌ Datenbankverbindung fehlgeschlagen\n";
            return false;
        }
        $mysqli->set_charset('utf8mb4');
    } else {
        $mysqli = $GLOBALS['mysqli'];
    }
    
    // Quotas mit überlappenden Zeiträumen suchen
    $sql = "SELECT q1.hrs_id as id1, q1.date_from as from1, q1.date_to as to1, q1.title as title1,
                   q2.hrs_id as id2, q2.date_from as from2, q2.date_to as to2, q2.title as title2
            FROM hut_quota q1
            JOIN hut_quota q2 ON q1.hut_id = q2.hut_id 
            WHERE q1.hrs_id != q2.hrs_id
            AND q1.hut_id = 675
            AND (q1.date_from <= q2.date_to AND q1.date_to >= q2.date_from)
            ORDER BY q1.date_from ASC
            LIMIT 10";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "📅 Überlappende Quotas gefunden:\n";
    $overlaps = 0;
    while ($row = $result->fetch_assoc()) {
        echo "   HRS-ID " . $row['id1'] . " (" . $row['from1'] . " - " . $row['to1'] . ") überschneidet mit\n";
        echo "   HRS-ID " . $row['id2'] . " (" . $row['from2'] . " - " . $row['to2'] . ")\n";
        echo "   Titles: '" . substr($row['title1'], 0, 30) . "' vs '" . substr($row['title2'], 0, 30) . "'\n\n";
        $overlaps++;
    }
    $stmt->close();
    
    if ($overlaps == 0) {
        echo "✅ Keine überlappenden Quotas gefunden\n";
    } else {
        echo "⚠️ $overlaps überlappende Quota-Paare gefunden\n";
    }
    
    // Quotas nach Monaten
    $sql = "SELECT DATE_FORMAT(date_from, '%Y-%m') as month, COUNT(*) as count
            FROM hut_quota 
            WHERE hut_id = 675
            GROUP BY DATE_FORMAT(date_from, '%Y-%m')
            ORDER BY month ASC";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "\n📊 Quotas nach Monaten:\n";
    while ($row = $result->fetch_assoc()) {
        echo "   " . $row['month'] . ": " . $row['count'] . " Quotas\n";
    }
    $stmt->close();
    
    return true;
}

// CLI Test ausführen
if (php_sapi_name() === 'cli') {
    $testResult = testSmartUpdate();
    analyzeQuotaChanges();
    
    echo "\n" . str_repeat("=", 50) . "\n";
    if ($testResult) {
        echo "🎉 SMART UPDATE TEST ERFOLGREICH ABGESCHLOSSEN!\n";
    } else {
        echo "❌ SMART UPDATE TEST FEHLGESCHLAGEN!\n";
    }
    echo str_repeat("=", 50) . "\n";
} else {
    // Web Interface
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>HutQuota Smart Update Test</title>
        <style>
            body { font-family: monospace; margin: 20px; background: #f5f5f5; }
            .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
            .btn { display: inline-block; padding: 10px 20px; margin: 5px; background: #007cba; color: white; text-decoration: none; border-radius: 4px; }
            .output { background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; border-radius: 4px; white-space: pre-wrap; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔄 HutQuota Smart Update Test</h1>
            
            <div class="output">
                <?php
                if (isset($_GET['run'])) {
                    $testResult = testSmartUpdate();
                    analyzeQuotaChanges();
                    
                    if ($testResult) {
                        echo "\n🎉 SMART UPDATE TEST ERFOLGREICH ABGESCHLOSSEN!\n";
                    } else {
                        echo "\n❌ SMART UPDATE TEST FEHLGESCHLAGEN!\n";
                    }
                } else {
                    echo "Klicken Sie auf 'Test starten' um den Smart Update-Mechanismus zu testen.\n\n";
                    echo "Der Test führt mehrere Imports mit unterschiedlichen Parametern durch\n";
                    echo "und überprüft, ob das System korrekt mit sich ändernden Quotas umgeht.\n";
                }
                ?>
            </div>
            
            <div style="margin-top: 20px;">
                <a href="?run=1" class="btn">🚀 Test starten</a>
                <a href="hut_quota_import.php" class="btn">🔙 Zurück zum Import</a>
                <a href="hut_quota_import.php?action=validate&hut_id=675" class="btn">🔍 DB validieren</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>
