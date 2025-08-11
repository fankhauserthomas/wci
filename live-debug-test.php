<?php
// SCHNELLER DEBUG - Direkter Test der Live-Logs

$logFile = '/var/log/apache2/access.log';

echo "<h1>🔍 Live-Log Debug</h1>";
echo "<p><strong>Log File:</strong> $logFile</p>";
echo "<p><strong>File exists:</strong> " . (file_exists($logFile) ? "YES" : "NO") . "</p>";
echo "<p><strong>Is readable:</strong> " . (is_readable($logFile) ? "YES" : "NO") . "</p>";

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "<p><strong>Total lines:</strong> " . count($lines) . "</p>";
    
    // Zähle echte WCI-Dateien mit erweitertem Filter
    $accessedFiles = [];
    $totalMatches = 0;
    $wciMatches = 0;
    $validFiles = 0;
    $fileTypeStats = [];
    
    foreach ($lines as $line) {
        if (preg_match('/(\d+\.\d+\.\d+\.\d+).*?"(GET|POST|HEAD) ([^"]*)"/', $line, $matches)) {
            $totalMatches++;
            $url = $matches[3];
            $cleanUrl = preg_replace('/\?.*$/', '', $url);
            
            if (strpos($cleanUrl, '/wci/') !== false) {
                $wciMatches++;
                
                // Bessere URL-Bereinigung
                $file = basename($cleanUrl);
                
                // Entferne HTTP-Version und andere Artifacts
                $file = preg_replace('/\s+HTTP\/\d\.\d.*$/', '', $file);
                $file = trim($file);
                
                // DEBUG: Zeige alle WCI-Dateien die gefunden werden
                if (!empty($file) && !in_array($file, ['ping.php', 'favicon.ico'])) {
                    $ext = pathinfo($file, PATHINFO_EXTENSION);
                    if (!isset($fileTypeStats[$ext])) {
                        $fileTypeStats[$ext] = 0;
                    }
                    $fileTypeStats[$ext]++;
                    
                    // ERWEITERTE Dateifilterung mit besserem Regex
                    if (preg_match('/\.(php|html|js|css|svg|png|jpg|jpeg|json)$/i', $file) ||
                        // Oder Dateien ohne Extension
                        (strpos($file, '.') === false && strlen($file) > 2)) {
                        
                        $validFiles++;
                        if (!isset($accessedFiles[$file])) {
                            $accessedFiles[$file] = 0;
                        }
                        $accessedFiles[$file]++;
                    }
                }
            }
        }
    }
    
    echo "<p><strong>Regex matches:</strong> $totalMatches</p>";
    echo "<p><strong>WCI URLs:</strong> $wciMatches</p>";
    echo "<p><strong>Valid files:</strong> $validFiles</p>";
    echo "<p><strong>Unique files:</strong> " . count($accessedFiles) . "</p>";
    
    echo "<h2>📊 File Type Statistics:</h2>";
    foreach ($fileTypeStats as $ext => $count) {
        echo "<p>📁 <strong>.$ext</strong> -> $count requests</p>";
    }
    
    echo "<h2>🎯 Found Files:</h2>";
    foreach ($accessedFiles as $file => $count) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $color = match($ext) {
            'php' => 'blue',
            'html' => 'red', 
            'js' => 'orange',
            'css' => 'purple',
            'svg' => 'green',
            default => 'black'
        };
        echo "<p style='color: $color'>📁 <strong>$file</strong> -> $count requests (.$ext)</p>";
    }
    
    // Zeige ein paar Beispiel-Zeilen
    echo "<h2>📄 Last 5 Log Lines:</h2>";
    for ($i = max(0, count($lines) - 5); $i < count($lines); $i++) {
        echo "<p><small>" . htmlspecialchars($lines[$i]) . "</small></p>";
    }
}
?>
