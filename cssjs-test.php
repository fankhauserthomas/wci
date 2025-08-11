<?php
// DIREKTER TEST für CSS/JS Files

$logFile = '/var/log/apache2/access.log';
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

echo "<h1>🎯 CSS/JS Direct Test</h1>";

$cssJsFiles = [];
$testUrls = [];

foreach ($lines as $line) {
    // Test verschiedene URL-Patterns
    if (preg_match('/(\d+\.\d+\.\d+\.\d+).*?"(GET|POST|HEAD) ([^"]*)"/', $line, $matches)) {
        $url = $matches[3];
        
        // URL bereinigen
        $cleanUrl = preg_replace('/\?.*$/', '', $url);
        $cleanUrl = preg_replace('/\s+HTTP\/\d\.\d.*$/', '', $cleanUrl);
        $cleanUrl = trim($cleanUrl);
        
        // Nur WCI URLs
        if (strpos($cleanUrl, '/wci/') !== false) {
            $file = basename($cleanUrl);
            
            // Speziell CSS/JS testen
            if (preg_match('/\.(css|js)$/i', $file)) {
                if (!isset($cssJsFiles[$file])) {
                    $cssJsFiles[$file] = 0;
                }
                $cssJsFiles[$file]++;
                
                // Sammle auch Test-URLs
                if (count($testUrls) < 5) {
                    $testUrls[] = [
                        'original' => $url,
                        'clean' => $cleanUrl,
                        'file' => $file
                    ];
                }
            }
        }
    }
}

echo "<p><strong>Found CSS/JS files:</strong> " . count($cssJsFiles) . "</p>";

echo "<h2>📁 CSS/JS Files:</h2>";
foreach ($cssJsFiles as $file => $count) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $color = $ext === 'css' ? 'purple' : 'orange';
    echo "<p style='color: $color;'>📁 <strong>$file</strong> -> $count requests (.$ext)</p>";
}

echo "<h2>🔍 Sample URLs:</h2>";
foreach ($testUrls as $url) {
    echo "<p>";
    echo "<strong>Original:</strong> " . htmlspecialchars($url['original']) . "<br>";
    echo "<strong>Clean:</strong> " . htmlspecialchars($url['clean']) . "<br>";
    echo "<strong>File:</strong> " . htmlspecialchars($url['file']) . "<br>";
    echo "</p><hr>";
}
?>
