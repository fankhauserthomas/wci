<?php
// DEBUG: Direkte Regex-Test für Apache-Logs

$logFile = '/home/vadmin/lemp/html/wci/access.log';
echo "<h1>🔍 Regex Debug Test</h1>";

if (!file_exists($logFile)) {
    die("❌ Log-Datei nicht gefunden: $logFile");
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
echo "<p>📄 Log-Datei gefunden: " . count($lines) . " Zeilen</p>";

// Test die ersten 10 Zeilen
echo "<h2>🔍 Erste 10 Log-Zeilen:</h2>";
for ($i = 0; $i < min(10, count($lines)); $i++) {
    $line = $lines[$i];
    echo "<p><strong>Zeile $i:</strong><br>";
    echo "<code>" . htmlspecialchars($line) . "</code><br>";
    
    // Test aktuelles Regex
    if (preg_match('/(\d+\.\d+\.\d+\.\d+).*?"(GET|POST|HEAD) ([^"]*)"/', $line, $matches)) {
        echo "✅ <span style='color:green'>REGEX MATCH!</span><br>";
        echo "IP: {$matches[1]}<br>";
        echo "Method: {$matches[2]}<br>";
        echo "URL: {$matches[3]}<br>";
        
        $cleanUrl = preg_replace('/\?.*$/', '', $matches[3]);
        echo "Clean URL: $cleanUrl<br>";
        
        if (strpos($cleanUrl, '/wci/') !== false) {
            echo "🎯 <span style='color:blue'>WCI URL gefunden!</span><br>";
            $file = basename($cleanUrl);
            echo "File: $file<br>";
            
            if (!empty($file) && 
                (strpos($file, '.php') !== false || 
                 strpos($file, '.html') !== false ||
                 strpos($file, '.js') !== false) && 
                !in_array($file, ['ping.php', 'favicon.ico'])) {
                echo "🟢 <span style='color:green'>GÜLTIGE DATEI!</span><br>";
            } else {
                echo "🔴 <span style='color:red'>Datei gefiltert</span><br>";
            }
        } else {
            echo "⚪ Keine WCI URL<br>";
        }
    } else {
        echo "❌ <span style='color:red'>KEIN REGEX MATCH</span><br>";
    }
    echo "</p><hr>";
}
?>
