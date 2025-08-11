<?php
// Teste die Datenextraktion direkt
error_reporting(E_ALL);
ini_set('display_errors', 1);

$logFile = '/home/vadmin/lemp/logs/apache2/access.log';
echo "<h2>Live Network Graph - Data Test</h2>";

if (!file_exists($logFile)) {
    echo "<p style='color: red;'>Log file not found: $logFile</p>";
    exit;
}

$accessedFiles = [];
$fileConnections = [];

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
echo "<p>Total log lines: " . count($lines) . "</p>";

$processed = 0;
$matched = 0;

foreach ($lines as $line) {
    $processed++;
    
    if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"\s]+)[^"]*" (\d+) (\d+) "([^"]*)"/', $line, $matches)) {
        $matched++;
        $ip = $matches[1];
        $datetime = $matches[2];
        $method = $matches[3];
        $url = $matches[4];
        $status = intval($matches[5]);
        $referer = $matches[7];
        
        // Only successful requests
        if ($status < 400) {
            $file = parse_url($url, PHP_URL_PATH);
            if ($file && $file !== '/') {
                $file = str_replace('/wci/', '', $file);
                $file = basename($file);
                
                // Skip certain files
                $skipFiles = ['ping.php', 'favicon.ico', ''];
                
                if (!empty($file) && !in_array($file, $skipFiles)) {
                    // File erfassen
                    if (!isset($accessedFiles[$file])) {
                        $accessedFiles[$file] = [
                            'requests' => 0,
                            'users' => [],
                            'methods' => [],
                            'last_access' => $datetime,
                            'file_type' => strtolower(pathinfo($file, PATHINFO_EXTENSION))
                        ];
                    }
                    $accessedFiles[$file]['requests']++;
                    $accessedFiles[$file]['users'][$ip] = true;
                    $accessedFiles[$file]['methods'][$method] = true;
                }
            }
        }
    }
    
    if ($processed >= 1000) break; // Limit für Test
}

echo "<p>Processed lines: $processed</p>";
echo "<p>Matched lines: $matched</p>";
echo "<p>Found files: " . count($accessedFiles) . "</p>";

echo "<h3>Files found:</h3>";
foreach ($accessedFiles as $filename => $data) {
    echo "<p><strong>$filename</strong> - {$data['requests']} requests, " . count($data['users']) . " users, type: {$data['file_type']}</p>";
}

echo "<h3>JSON Data:</h3>";
echo "<pre>" . json_encode($accessedFiles, JSON_PRETTY_PRINT) . "</pre>";
?>
