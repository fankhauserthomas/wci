<?php
// SUPER SIMPLE LOG PARSER TEST
header('Content-Type: text/html; charset=utf-8');
echo "<h1>Super Simple Log Parser Test</h1>";

$logFile = '/home/vadmin/lemp/logs/apache2/access.log';
echo "<p>Log file: $logFile</p>";

if (!file_exists($logFile)) {
    echo "<p style='color: red;'>ERROR: Log file not found!</p>";
    exit;
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
echo "<p>Total lines: " . count($lines) . "</p>";

echo "<h2>Last 5 lines from log:</h2>";
$lastLines = array_slice($lines, -5);
foreach ($lastLines as $i => $line) {
    echo "<p>Line " . ($i+1) . ": <code>" . htmlspecialchars($line) . "</code></p>";
}

echo "<h2>Testing simple regex:</h2>";
$files = [];
$processed = 0;
$matched = 0;

foreach ($lastLines as $line) {
    $processed++;
    
    // SUPER EINFACHES REGEX - nur IP und URL suchen
    if (preg_match('/\d+\.\d+\.\d+\.\d+.*"GET ([^"]*)"/', $line, $matches)) {
        $matched++;
        $url = $matches[1];
        echo "<p>✅ Match: URL = <strong>" . htmlspecialchars($url) . "</strong></p>";
        
        // Prüfe auf /wci/
        if (strpos($url, '/wci/') !== false) {
            $file = basename($url);
            echo "<p style='margin-left: 20px;'>→ WCI File: <strong>$file</strong></p>";
            
            if (!empty($file) && strpos($file, '.php') !== false) {
                $files[$file] = ($files[$file] ?? 0) + 1;
                echo "<p style='margin-left: 40px; color: green;'>→ Added to list!</p>";
            }
        }
    } else {
        echo "<p>❌ No match: " . htmlspecialchars(substr($line, 0, 100)) . "...</p>";
    }
}

echo "<h2>Final Results:</h2>";
echo "<p>Processed: $processed, Matched: $matched</p>";
echo "<p>Files found: " . count($files) . "</p>";

if (count($files) > 0) {
    echo "<ul>";
    foreach ($files as $filename => $count) {
        echo "<li><strong>$filename</strong>: $count requests</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: red;'>NO FILES FOUND!</p>";
}

// Jetzt teste das JSON
$accessedFiles = [];
foreach ($files as $filename => $count) {
    $accessedFiles[$filename] = [
        'requests' => $count,
        'users' => ['192.168.1.1' => true],
        'methods' => ['GET' => true],
        'last_access' => date('Y-m-d H:i:s'),
        'file_type' => 'php'
    ];
}

echo "<h2>JSON Output Test:</h2>";
echo "<pre>" . json_encode($accessedFiles, JSON_PRETTY_PRINT) . "</pre>";

echo "<h2>JavaScript Test:</h2>";
echo "<script>";
echo "const files = " . json_encode($accessedFiles) . ";";
echo "console.log('Files from PHP:', files);";
echo "console.log('Files count:', Object.keys(files).length);";
echo "</script>";
?>
