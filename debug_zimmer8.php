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
    
    echo "<h2>Debug: Zimmer 8 (Zimmer 14) - Analyse</h2>";
    echo "<hr>";
    
    // 1. Grunddaten von Zimmer 8
    echo "<h3>1. Zimmer Grunddaten:</h3>";
    $stmt = $pdo->prepare("SELECT * FROM zp_zimmer WHERE id = 8");
    $stmt->execute();
    $zimmer = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($zimmer);
    echo "</pre>";
    
    // 2. Alle Reservierungen für dieses Zimmer im Zeitraum
    echo "<h3>2. Reservierungen für Zimmer 8 (22.-23.09.2025):</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            rd.*,
            r.storno,
            r.datum as res_datum,
            CASE WHEN r.storno IS NULL OR r.storno = 0 THEN 'AKTIV' ELSE 'STORNIERT' END as status
        FROM AV_ResDet rd
        LEFT JOIN `AV-Res` r ON rd.av_id = r.id
        WHERE rd.zimmer_id = 8 
        AND rd.von <= '2025-09-23' 
        AND rd.bis > '2025-09-22'
        ORDER BY rd.von
    ");
    $stmt->execute();
    $reservierungen = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($reservierungen)) {
        echo "<p><strong>Keine Reservierungen gefunden für diesen Zeitraum!</strong></p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>AV_ID</th><th>Anzahl</th><th>Von</th><th>Bis</th><th>Status</th><th>Storno</th></tr>";
        foreach ($reservierungen as $res) {
            $bgColor = ($res['status'] == 'STORNIERT') ? 'background: #ffcccc;' : 'background: #ccffcc;';
            echo "<tr style='$bgColor'>";
            echo "<td>{$res['id']}</td>";
            echo "<td>{$res['av_id']}</td>";
            echo "<td>{$res['anz']}</td>";
            echo "<td>{$res['von']}</td>";
            echo "<td>{$res['bis']}</td>";
            echo "<td>{$res['status']}</td>";
            echo "<td>{$res['storno']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Berechnung wie in getRooms.php
    echo "<h3>3. Verfügbarkeitsberechnung:</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            z.id,
            z.caption,
            z.kapazitaet,
            COALESCE(SUM(CASE WHEN r.storno IS NULL OR r.storno = 0 THEN rd.anz ELSE 0 END), 0) as belegt,
            z.kapazitaet - COALESCE(SUM(CASE WHEN r.storno IS NULL OR r.storno = 0 THEN rd.anz ELSE 0 END), 0) as frei
        FROM zp_zimmer z
        LEFT JOIN AV_ResDet rd ON z.id = rd.zimmer_id 
            AND rd.von <= '2025-09-23' 
            AND rd.bis > '2025-09-22'
        LEFT JOIN `AV-Res` r ON rd.av_id = r.id
        WHERE z.id = 8
        GROUP BY z.id, z.caption, z.kapazitaet
    ");
    $stmt->execute();
    $berechnung = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Kapazität</th><th>Belegt</th><th>Frei</th><th>Anzeige</th></tr>";
    echo "<tr>";
    echo "<td>{$berechnung['kapazitaet']}</td>";
    echo "<td>{$berechnung['belegt']}</td>";
    echo "<td>{$berechnung['frei']}</td>";
    echo "<td>{$berechnung['frei']}/{$berechnung['kapazitaet']}</td>";
    echo "</tr>";
    echo "</table>";
    
    // 4. Frontend-Check: Was zeigt das JavaScript?
    echo "<h3>4. Was sollte im Frontend angezeigt werden:</h3>";
    echo "<p>Laut aktueller Berechnung: <strong>{$berechnung['frei']}/{$berechnung['kapazitaet']}</strong></p>";
    echo "<p>In deinem Screenshot siehst du: <strong>0/0 (von 4)</strong></p>";
    echo "<p><em>→ Das deutet darauf hin, dass das Frontend noch alte/falsche Daten verwendet!</em></p>";
    
} catch (PDOException $e) {
    echo "Datenbankfehler: " . $e->getMessage();
}
?>