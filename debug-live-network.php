<?php
// Debug script for live network graph

echo "<h2>Debug Live Network Graph</h2>\n";

// Check log file path
$logFile = '/home/vadmin/lemp/logs/apache2/access.log';
echo "<p>Log file path: $logFile</p>\n";
echo "<p>File exists: " . (file_exists($logFile) ? 'YES' : 'NO') . "</p>\n";
echo "<p>File readable: " . (is_readable($logFile) ? 'YES' : 'NO') . "</p>\n";

// Try alternative paths
$altPaths = [
    '/home/vadmin/lemp/logs/apache2/access.log',
    '/var/log/apache2/access.log',
    __DIR__ . '/../logs/apache2/access.log'
];

foreach ($altPaths as $path) {
    echo "<p>Path: $path - Exists: " . (file_exists($path) ? 'YES' : 'NO') . " - Readable: " . (is_readable($path) ? 'YES' : 'NO') . "</p>\n";
}

// Test reading the correct log file
$correctLogFile = '/home/vadmin/lemp/logs/apache2/access.log';
if (file_exists($correctLogFile) && is_readable($correctLogFile)) {
    echo "<h3>Reading log file:</h3>\n";
    $lines = file($correctLogFile);
    echo "<p>Total lines: " . count($lines) . "</p>\n";
    
    // Show last 5 lines
    echo "<h4>Last 5 lines:</h4>\n<pre>\n";
    for ($i = max(0, count($lines) - 5); $i < count($lines); $i++) {
        echo htmlspecialchars($lines[$i]) . "\n";
    }
    echo "</pre>\n";
    
    // Test parsing logic
    echo "<h3>Parsing test:</h3>\n";
    $files = [];
    $connections = [];
    $users = [];
    
    // Process last 100 lines
    $recentLines = array_slice($lines, -100);
    foreach ($recentLines as $line) {
        if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"]+) HTTP\/[\d\.]+" (\d+) (\d+) "([^"]*)" "([^"]*)"/', $line, $matches)) {
            $ip = $matches[1];
            $timestamp = $matches[2];
            $method = $matches[3];
            $url = $matches[4];
            $status = $matches[5];
            $size = $matches[6];
            $referer = $matches[7];
            $userAgent = $matches[8];
            
            // Extract file from URL
            if (preg_match('/\/wci\/([^?\s]+)/', $url, $fileMatch)) {
                $file = $fileMatch[1];
                
                // Skip certain files
                $skipFiles = ['ping.php', 'favicon.ico', 'sync_matrix.php'];
                if (in_array($file, $skipFiles)) continue;
                
                // Count files
                if (!isset($files[$file])) {
                    $files[$file] = ['count' => 0, 'users' => []];
                }
                $files[$file]['count']++;
                if (!in_array($ip, $files[$file]['users'])) {
                    $files[$file]['users'][] = $ip;
                }
                
                // Count users
                if (!in_array($ip, $users)) {
                    $users[] = $ip;
                }
                
                echo "<p>Found file: $file (IP: $ip, Status: $status)</p>\n";
            }
        }
    }
    
    echo "<h4>Summary:</h4>\n";
    echo "<p>Files found: " . count($files) . "</p>\n";
    echo "<p>Users found: " . count($users) . "</p>\n";
    
    foreach ($files as $file => $data) {
        echo "<p>$file: {$data['count']} requests from " . count($data['users']) . " users</p>\n";
    }
}
?>
