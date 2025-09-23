<?php
header('Content-Type: text/html; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Database connection
$host = '192.168.15.14';
$dbname = 'av';
$username = 'wciapp';
$password = '9Ke^z5xG';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Debug: Masterreservierung Zeitraum-Analyse</h2>";
    echo "<hr>";
    
    // 1. Suche eine konkrete Masterreservierung mit Details
    echo "<h3>1. Aktuelle Masterreservierungen:</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            r.id as master_id,
            r.datum as master_datum,
            r.von as master_von,
            r.bis as master_bis,
            r.storno,
            COUNT(rd.id) as anzahl_details
        FROM `AV-Res` r
        LEFT JOIN AV_ResDet rd ON r.id = rd.av_id
        WHERE r.storno IS NULL OR r.storno = 0
        ORDER BY r.datum DESC
        LIMIT 10
    ");
    $stmt->execute();
    $masterReservierungen = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Master ID</th><th>Datum</th><th>Von</th><th>Bis</th><th>Anzahl Details</th></tr>";
    foreach ($masterReservierungen as $master) {
        echo "<tr>";
        echo "<td>{$master['master_id']}</td>";
        echo "<td>{$master['master_datum']}</td>";
        echo "<td>{$master['master_von']}</td>";
        echo "<td>{$master['master_bis']}</td>";
        echo "<td>{$master['anzahl_details']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 2. Nehme die erste Masterreservierung und analysiere Details
    if (!empty($masterReservierungen)) {
        $testMaster = $masterReservierungen[0];
        $masterId = $testMaster['master_id'];
        
        echo "<h3>2. Details für Masterreservierung {$masterId}:</h3>";
        echo "<p><strong>Zeitraum:</strong> {$testMaster['master_von']} bis {$testMaster['master_bis']}</p>";
        
        $stmt = $pdo->prepare("
            SELECT 
                rd.*,
                z.caption as zimmer_name,
                z.kapazitaet as zimmer_kapazitaet
            FROM AV_ResDet rd
            LEFT JOIN zp_zimmer z ON rd.zimmer_id = z.id
            WHERE rd.av_id = ?
            ORDER BY rd.zimmer_id
        ");
        $stmt->execute([$masterId]);
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($details)) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Zimmer</th><th>Kapazität</th><th>Reserviert</th><th>Von</th><th>Bis</th></tr>";
            foreach ($details as $detail) {
                echo "<tr>";
                echo "<td>{$detail['zimmer_name']} (ID: {$detail['zimmer_id']})</td>";
                echo "<td>{$detail['zimmer_kapazitaet']}</td>";
                echo "<td>{$detail['anz']}</td>";
                echo "<td>{$detail['von']}</td>";
                echo "<td>{$detail['bis']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // 3. Berechne verfügbare Plätze für jedes Zimmer in diesem Zeitraum
            echo "<h3>3. Verfügbare Plätze pro Zimmer für Zeitraum {$testMaster['master_von']} - {$testMaster['master_bis']}:</h3>";
            
            $stmt = $pdo->prepare("
                SELECT 
                    z.id,
                    z.caption,
                    z.kapazitaet,
                    COALESCE(SUM(CASE 
                        WHEN r.storno IS NULL OR r.storno = 0 THEN rd.anz 
                        ELSE 0 
                    END), 0) as belegt,
                    z.kapazitaet - COALESCE(SUM(CASE 
                        WHEN r.storno IS NULL OR r.storno = 0 THEN rd.anz 
                        ELSE 0 
                    END), 0) as frei
                FROM zp_zimmer z
                LEFT JOIN AV_ResDet rd ON z.id = rd.zimmer_id 
                    AND rd.von < ? 
                    AND rd.bis > ?
                LEFT JOIN `AV-Res` r ON rd.av_id = r.id
                WHERE z.id IN (" . implode(',', array_column($details, 'zimmer_id')) . ")
                GROUP BY z.id, z.caption, z.kapazitaet
                ORDER BY z.id
            ");
            $stmt->execute([$testMaster['master_bis'], $testMaster['master_von']]);
            $verfugbarkeit = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Zimmer</th><th>Kapazität</th><th>Belegt</th><th>Frei</th><th>Anzeige Format</th></tr>";
            foreach ($verfugbarkeit as $v) {
                echo "<tr>";
                echo "<td>{$v['caption']} (ID: {$v['id']})</td>";
                echo "<td>{$v['kapazitaet']}</td>";
                echo "<td>{$v['belegt']}</td>";
                echo "<td>{$v['frei']}</td>";
                echo "<td><strong>0/{$v['frei']} (von {$v['kapazitaet']})</strong></td>";
                echo "</tr>";
            }
            echo "</table>";
            
            echo "<h3>4. Erklärung des Formats:</h3>";
            echo "<ul>";
            echo "<li><strong>0</strong> = Aktuell zugewiesene Personen im Draft (immer 0 beim Start)</li>";
            echo "<li><strong>{freie_plätze}</strong> = Verfügbare Plätze im Zeitraum der Masterreservierung</li>";
            echo "<li><strong>(von {gesamtkapazität})</strong> = Gesamtkapazität des Zimmers</li>";
            echo "</ul>";
            
        } else {
            echo "<p>Keine Details für diese Masterreservierung gefunden.</p>";
        }
    }
    
} catch (PDOException $e) {
    echo "Datenbankfehler: " . $e->getMessage();
}
?>