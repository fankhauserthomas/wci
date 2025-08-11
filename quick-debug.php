<?php
// Quick debug for network data

$logFile = '/home/vadmin/lemp/logs/apache2/access.log';
$accessedFiles = [];
$fileConnections = [];

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    echo "<h1>Log Analysis Debug</h1>";
    echo "<p>Total lines in log: " . count($lines) . "</p>";
    
    $recentLines = array_slice($lines, -100); // Last 100 lines
    echo "<p>Processing last 100 lines...</p>";
    
    $processedCount = 0;
    $validFiles = [];
    
    foreach ($recentLines as $line) {
        if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"\s]+)[^"]*" (\d+) (\d+) "([^"]*)"/', $line, $matches)) {
            $ip = $matches[1];
            $url = $matches[4];
            $status = intval($matches[5]);
            
            if ($status < 400 && strpos($url, '/wci/') !== false) {
                $file = str_replace('/wci/', '', parse_url($url, PHP_URL_PATH));
                $file = basename($file);
                
                $skipFiles = ['ping.php', 'favicon.ico'];
                
                if (!empty($file) && !in_array($file, $skipFiles)) {
                    $validFiles[] = $file;
                    $processedCount++;
                    
                    if (!isset($accessedFiles[$file])) {
                        $accessedFiles[$file] = ['requests' => 0];
                    }
                    $accessedFiles[$file]['requests']++;
                }
            }
        }
    }
    
    echo "<p>Valid files found: $processedCount</p>";
    echo "<p>Unique files: " . count($accessedFiles) . "</p>";
    
    echo "<h2>Files and Request Counts:</h2>";
    foreach ($accessedFiles as $file => $data) {
        echo "<p>$file: {$data['requests']} requests</p>";
    }
    
    echo "<h2>Recent valid files (last 20):</h2>";
    $recentValidFiles = array_slice($validFiles, -20);
    foreach ($recentValidFiles as $file) {
        echo "<p>$file</p>";
    }
    
} else {
    echo "<p>ERROR: Log file not found!</p>";
}
?>
