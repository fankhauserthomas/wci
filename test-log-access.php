<?php
// Simple test to read access log

$logFile = '/home/vadmin/lemp/logs/apache2/access.log';

echo "<h1>Log File Test</h1>\n";
echo "<p>File: $logFile</p>\n";
echo "<p>Exists: " . (file_exists($logFile) ? 'YES' : 'NO') . "</p>\n";
echo "<p>Readable: " . (is_readable($logFile) ? 'YES' : 'NO') . "</p>\n";

if (file_exists($logFile) && is_readable($logFile)) {
    $content = file_get_contents($logFile);
    $lines = explode("\n", $content);
    
    echo "<p>File size: " . strlen($content) . " bytes</p>\n";
    echo "<p>Lines: " . count($lines) . "</p>\n";
    
    // Show last few lines
    echo "<h2>Last 3 lines:</h2>\n";
    for ($i = max(0, count($lines) - 4); $i < count($lines) - 1; $i++) {
        if (!empty(trim($lines[$i]))) {
            echo "<pre>" . htmlspecialchars($lines[$i]) . "</pre>\n";
        }
    }
    
    // Test regex parsing
    echo "<h2>Parsing Test:</h2>\n";
    $recentLines = array_slice($lines, -20);
    $parsedCount = 0;
    
    foreach ($recentLines as $line) {
        if (empty(trim($line))) continue;
        
        if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"\s]+)[^"]*" (\d+) (\d+) "([^"]*)"/', $line, $matches)) {
            $parsedCount++;
            $ip = $matches[1];
            $url = $matches[4];
            $status = $matches[5];
            
            if (strpos($url, '/wci/') !== false) {
                $file = str_replace('/wci/', '', parse_url($url, PHP_URL_PATH));
                $file = basename($file);
                echo "<p>✓ Parsed: IP=$ip, File=$file, Status=$status</p>\n";
            }
        } else {
            echo "<p>✗ Failed to parse: " . htmlspecialchars(substr($line, 0, 100)) . "...</p>\n";
        }
    }
    
    echo "<p><strong>Successfully parsed: $parsedCount lines</strong></p>\n";
} else {
    echo "<p><strong>ERROR: Cannot read log file!</strong></p>\n";
}
?>
