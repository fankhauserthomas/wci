<?php
require_once 'config.php';

echo "<h1>QR-Code Scanner Debug</h1>";

try {
    // Prüfe CardName Spalte
    echo "<h2>1. CardName Analyse</h2>";
    $stmt = $mysqli->prepare("SELECT COUNT(*) as total, COUNT(CardName) as with_cardname FROM `AV-ResNamen`");
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    
    echo "<p>Gesamt Einträge: {$stats['total']}</p>";
    echo "<p>Mit CardName: {$stats['with_cardname']}</p>";
    
    // Beispiele von CardName Einträgen
    echo "<h2>2. Beispiel CardName Einträge</h2>";
    $stmt = $mysqli->prepare("SELECT av_id, vorname, nachname, CardName FROM `AV-ResNamen` WHERE CardName IS NOT NULL AND CardName != '' LIMIT 10");
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<table border='1'>";
    echo "<tr><th>AV-ID</th><th>Vorname</th><th>Nachname</th><th>CardName</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['av_id']}</td>";
        echo "<td>{$row['vorname']}</td>";
        echo "<td>{$row['nachname']}</td>";
        echo "<td>{$row['CardName']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Test verschiedene Barcode-Formate
    echo "<h2>3. Test verschiedene Barcodes</h2>";
    $testBarcodes = ['test123', 'TEST123', '123', 'schmidt', 'SCHMIDT'];
    
    foreach ($testBarcodes as $barcode) {
        echo "<h3>Test: '$barcode'</h3>";
        
        // Exakte Suche
        $stmt = $mysqli->prepare("SELECT av_id, vorname, nachname, CardName FROM `AV-ResNamen` WHERE CardName = ? LIMIT 1");
        $stmt->bind_param('s', $barcode);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo "<p>✅ Exakte Übereinstimmung: {$row['vorname']} {$row['nachname']} (AV-ID: {$row['av_id']})</p>";
        } else {
            echo "<p>❌ Keine exakte Übereinstimmung</p>";
            
            // Ähnliche Suche
            $likePattern = '%' . $barcode . '%';
            $stmt = $mysqli->prepare("SELECT av_id, vorname, nachname, CardName FROM `AV-ResNamen` WHERE CardName LIKE ? LIMIT 3");
            $stmt->bind_param('s', $likePattern);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                echo "<p>🔍 Ähnliche CardNames:</p>";
                while ($row = $result->fetch_assoc()) {
                    echo "<p>&nbsp;&nbsp;• {$row['CardName']} → {$row['vorname']} {$row['nachname']}</p>";
                }
            }
            
            // Namen-Suche
            $namePattern = '%' . $barcode . '%';
            $stmt = $mysqli->prepare("SELECT av_id, vorname, nachname, CardName FROM `AV-ResNamen` WHERE CONCAT(nachname, ' ', vorname) LIKE ? OR CONCAT(vorname, ' ', nachname) LIKE ? LIMIT 3");
            $stmt->bind_param('ss', $namePattern, $namePattern);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                echo "<p>👤 Ähnliche Namen:</p>";
                while ($row = $result->fetch_assoc()) {
                    echo "<p>&nbsp;&nbsp;• {$row['vorname']} {$row['nachname']} (CardName: {$row['CardName']})</p>";
                }
            }
        }
        echo "<hr>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>Fehler: " . $e->getMessage() . "</p>";
}
?>
