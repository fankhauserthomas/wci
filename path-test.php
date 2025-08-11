<?php
echo "<h1>Path Test</h1>";

$paths = [
    '/home/vadmin/lemp/html/wci/access.log',
    './access.log', 
    'access.log',
    __DIR__ . '/access.log'
];

foreach($paths as $path) {
    echo "<p><strong>Path: $path</strong><br>";
    echo "exists: " . (file_exists($path) ? "YES" : "NO") . "<br>";
    echo "readable: " . (is_readable($path) ? "YES" : "NO") . "</p>";
}

// Versuche auch direkte Log-Verarbeitung
echo "<h2>Direct Test mit relativem Pfad:</h2>";
$logFile = './access.log';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "<p>Lines found: " . count($lines) . "</p>";
    
    // Test Regex an erster Zeile
    if (count($lines) > 0) {
        $line = $lines[0];
        echo "<p>First line: " . htmlspecialchars(substr($line, 0, 100)) . "...</p>";
        
        if (preg_match('/(\d+\.\d+\.\d+\.\d+).*?"(GET|POST|HEAD) ([^"]*)"/', $line, $matches)) {
            echo "<p>✅ REGEX MATCHED! URL: {$matches[3]}</p>";
        } else {
            echo "<p>❌ Regex failed</p>";
        }
    }
}
?>
