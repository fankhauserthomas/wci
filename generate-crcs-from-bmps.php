<?php
// generate-crcs-from-bmps.php - Erstellt CRC-Dateien aus vorhandenen BMP-Dateien

$erdDir = __DIR__ . '/erd/';

// Alle BMP-Dateien im erd/ Verzeichnis finden
$bmpFiles = glob($erdDir . '*.bmp');

if (empty($bmpFiles)) {
    echo "Keine BMP-Dateien im erd/ Verzeichnis gefunden.\n";
    exit(1);
}

echo "🔍 Gefundene BMP-Dateien: " . count($bmpFiles) . "\n";
echo "📝 Erstelle CRC-Dateien...\n\n";

$successful = 0;
$errors = 0;

foreach ($bmpFiles as $bmpPath) {
    $filename = basename($bmpPath);
    $tischName = pathinfo($filename, PATHINFO_FILENAME);
    
    echo "📄 Verarbeite: $filename ... ";
    
    try {
        // BMP-Dateiinhalt lesen
        $bmpContent = file_get_contents($bmpPath);
        if ($bmpContent === false) {
            throw new Exception("Konnte BMP-Datei nicht lesen");
        }
        
        // CRC-Daten erstellen (im gleichen Format wie tisch-namen-uebersicht.php)
        $crcData = [
            'file' => $filename,
            'size' => filesize($bmpPath),
            'crc32' => sprintf('%08x', crc32($bmpContent)),
            'sha256' => hash('sha256', $bmpContent),
            'created' => date('Y-m-d H:i:s')
        ];
        
        // CRC-Datei-Pfad
        $crcPath = str_replace('.bmp', '.crc', $bmpPath);
        
        // JSON-formatierte CRC-Datei schreiben
        $jsonData = json_encode($crcData, JSON_PRETTY_PRINT);
        $result = file_put_contents($crcPath, $jsonData);
        if ($result === false) {
            throw new Exception("Konnte CRC-Datei nicht schreiben");
        }
        
        echo "✅ OK (CRC32: " . $crcData['crc32'] . ", SHA-256: " . substr($crcData['sha256'], 0, 8) . "...)\n";
        $successful++;
        
    } catch (Exception $e) {
        echo "❌ Fehler: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n🎯 Zusammenfassung:\n";
echo "   ✅ Erfolgreich: $successful\n";
echo "   ❌ Fehler: $errors\n";

if ($successful > 0) {
    echo "\n📂 CRC-Dateien erstellt in: $erdDir\n";
    echo "💡 Format: JSON mit CRC32 und SHA-256 Checksummen\n";
}

if ($errors > 0) {
    echo "\n⚠️  Es gab $errors Fehler beim Verarbeiten der Dateien.\n";
    exit(1);
} else {
    echo "\n🎉 Alle BMP-Dateien erfolgreich verarbeitet!\n";
    exit(0);
}
?>
