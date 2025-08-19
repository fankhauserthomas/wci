<?php
// generate-crcs-from-pngs.php - Erstellt CRC-Dateien aus vorhandenen PNG-Dateien

$erdDir = __DIR__ . '/erd/';

// Alle PNG-Dateien im erd/ Verzeichnis finden
$pngFiles = glob($erdDir . '*.png');

if (empty($pngFiles)) {
    echo "Keine PNG-Dateien im erd/ Verzeichnis gefunden.\n";
    exit(1);
}

echo "🔍 Gefundene PNG-Dateien: " . count($pngFiles) . "\n";
echo "📝 Erstelle CRC-Dateien...\n\n";

$successful = 0;
$errors = 0;

foreach ($pngFiles as $pngPath) {
    $filename = basename($pngPath);
    $tischName = pathinfo($filename, PATHINFO_FILENAME);
    
    echo "📄 Verarbeite: $filename ... ";
    
    try {
        // PNG-Dateiinhalt lesen
        $pngContent = file_get_contents($pngPath);
        if ($pngContent === false) {
            throw new Exception("Konnte PNG-Datei nicht lesen");
        }
        
        // SHA-256 Checksum des PNG-Inhalts erstellen
        $checksum = hash('sha256', $pngContent);
        
        // CRC-Datei-Pfad
        $crcPath = str_replace('.png', '.crc', $pngPath);
        
        // CRC-Datei schreiben
        $result = file_put_contents($crcPath, $checksum);
        if ($result === false) {
            throw new Exception("Konnte CRC-Datei nicht schreiben");
        }
        
        echo "✅ OK (CRC: " . substr($checksum, 0, 8) . "...)\n";
        $successful++;
        
    } catch (Exception $e) {
        echo "❌ Fehler: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n📊 Zusammenfassung:\n";
echo "✅ Erfolgreich: $successful\n";
echo "❌ Fehler: $errors\n";
echo "📁 CRC-Dateien im Verzeichnis: " . $erdDir . "\n";

// Alle CRC-Dateien auflisten
$crcFiles = glob($erdDir . '*.crc');
if (!empty($crcFiles)) {
    echo "\n📋 Erstellte CRC-Dateien:\n";
    foreach ($crcFiles as $crcFile) {
        $filename = basename($crcFile);
        $checksum = file_get_contents($crcFile);
        echo "  $filename: " . substr($checksum, 0, 16) . "...\n";
    }
}
?>
