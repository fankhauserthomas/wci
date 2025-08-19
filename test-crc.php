<?php
// Test der CRC-Generierung
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
    
    echo "Data String: $data_string\n";
    echo "PNG Size: $png_size\n";
    echo "Checksum: $checksum\n";
    
    // CRC-Datei erstellen
    $crc_path = str_replace('.png', '.crc', $pngPath);
    $result = file_put_contents($crc_path, $checksum);
    echo "CRC File: $crc_path, Written: " . ($result !== false ? "YES" : "NO") . "\n";
    
    return $checksum;
}

// Test mit einer existierenden PNG-Datei
$test_png = __DIR__ . '/erd/Kr1.png';
$test_namen = [
    ['name' => 'Alpinewelten', 'anzahl' => 7, 'bemerkung' => '']
];

echo "Testing CRC generation for Kr1...\n";
generateCRCFile($test_png, 'Kr1', $test_namen);
?>
