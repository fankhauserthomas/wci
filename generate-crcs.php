<?php
// CRC-Generator für alle Tische
require_once 'hp-db-config.php';

function generateCRCFile($pngPath, $tischName, $namen) {
    // Eindeutige Checksum basierend auf Tischname und Namen-Daten erstellen
    $data_string = $tischName . '|';
    foreach ($namen as $gast) {
        $data_string .= $gast['name'] . ':' . $gast['anzahl'];
        if (!empty($gast['bemerkung'])) {
            $data_string .= ':' . $gast['bemerkung'];
        }
        $data_string .= '|';
    }
    
    // SHA-256 Hash des Dateninhalts + PNG-Dateigröße für zusätzliche Eindeutigkeit
    $png_size = file_exists($pngPath) ? filesize($pngPath) : 0;
    $checksum = hash('sha256', $data_string . $png_size);
    
    // CRC-Datei erstellen
    $crc_path = str_replace('.png', '.crc', $pngPath);
    file_put_contents($crc_path, $checksum);
    
    return $checksum;
}

try {
    $conn = getHpDbConnection();
    if (!$conn) {
        throw new Exception("Datenbankverbindung fehlgeschlagen");
    }
    
    $result = $conn->query("SELECT DISTINCT tisch FROM hp_arrangements WHERE tisch IS NOT NULL ORDER BY tisch");
    if (!$result) {
        throw new Exception("Query fehlgeschlagen: " . $conn->error);
    }
    
    $tische = [];
    while ($row = $result->fetch_assoc()) {
        $tische[] = $row['tisch'];
    }
    
    echo "<h2>🔍 CRC-Dateien generieren</h2>";
    
    $total_generated = 0;
    
    foreach ($tische as $tisch) {
        // Namen für diesen Tisch holen
        $stmt = $conn->prepare("
            SELECT name, anzahl, bemerkung 
            FROM hp_arrangements 
            WHERE tisch = ? AND name IS NOT NULL AND name != ''
            ORDER BY name
        ");
        $stmt->bind_param('s', $tisch);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $namen = [];
        while ($row = $result->fetch_assoc()) {
            $namen[] = $row;
        }
        
        $pngPath = __DIR__ . '/erd/' . $tisch . '.png';
        
        if (file_exists($pngPath)) {
            $checksum = generateCRCFile($pngPath, $tisch, $namen);
            $crc_short = substr($checksum, 0, 8);
            echo "<p>✅ <strong>$tisch</strong>: CRC $crc_short erstellt</p>";
            $total_generated++;
        } else {
            echo "<p>⚠️ <strong>$tisch</strong>: PNG-Datei nicht gefunden</p>";
        }
    }
    
    echo "<hr>";
    echo "<p><strong>🎉 Fertig!</strong> $total_generated CRC-Dateien erstellt.</p>";
    
} catch (Exception $e) {
    echo "<p><strong>❌ Fehler:</strong> " . $e->getMessage() . "</p>";
}
?>
