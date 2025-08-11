<?php
// Direkter Log-Parser Test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Live Network Graph - Log Parser Test</h2>";

$logFile = '/home/vadmin/lemp/logs/apache2/access.log';

if (!file_exists($logFile)) {
    echo "<p style='color: red;'>Log file not found: $logFile</p>";
    exit;
}

echo "<p>Log file found: $logFile</p>";

// Zeige die letzten 10 Zeilen
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
echo "<p>Total lines: " . count($lines) . "</p>";

echo "<h3>Last 10 log lines:</h3>";
$lastLines = array_slice($lines, -10);
foreach ($lastLines as $i => $line) {
    echo "<div style='border: 1px solid #ccc; padding: 5px; margin: 2px; font-family: monospace; font-size: 12px;'>";
    echo "<strong>Line " . ($i + 1) . ":</strong><br>";
    echo htmlspecialchars($line);
    echo "</div>";
}

echo "<h3>Testing Regex Patterns:</h3>";

$accessedFiles = [];
$processed = 0;
$matched = 0;

// Test verschiedene Regex-Patterns
$patterns = [
    'pattern1' => '/(\d+\.\d+\.\d+\.\d+).*?\[([^\]]+)\] "(\w+) ([^"]*)" (\d+) (\d+)/',
    'pattern2' => '/^(\S+).*?\[([^\]]+)\] "(\w+) ([^"]+)" (\d+) (\d+)/',
    'pattern3' => '/(\d+\.\d+\.\d+\.\d+).*?"(GET|POST|PUT|DELETE) ([^"]*)".*?(\d+) (\d+)/'
];

foreach ($patterns as $name => $pattern) {
    echo "<h4>Testing $name: <code>" . htmlspecialchars($pattern) . "</code></h4>";
    
    $testMatches = 0;
    $testFiles = [];
    
    foreach ($lastLines as $line) {
        if (preg_match($pattern, $line, $matches)) {
            $testMatches++;
            
            if ($name == 'pattern1') {
                $url = $matches[4] ?? '';
            } elseif ($name == 'pattern2') {
                $url = $matches[4] ?? '';
            } elseif ($name == 'pattern3') {
                $url = $matches[3] ?? '';
            }
            
            // URL bereinigen
            $cleanUrl = preg_replace('/\s+HTTP\/[\d.]+$/', '', $url);
            $file = parse_url($cleanUrl, PHP_URL_PATH);
            
            if ($file && $file !== '/') {
                $file = str_replace('/wci/', '', $file);
                $file = ltrim($file, '/');
                $file = basename($file);
                
                if (!empty($file) && !empty(pathinfo($file, PATHINFO_EXTENSION))) {
                    $testFiles[$file] = ($testFiles[$file] ?? 0) + 1;
                }
            }
            
            echo "<p style='font-size: 11px;'>✓ Match: " . htmlspecialchars(implode(' | ', $matches)) . "</p>";
        }
    }
    
    echo "<p><strong>$name Results:</strong> $testMatches matches, " . count($testFiles) . " files found</p>";
    
    if (count($testFiles) > 0) {
        echo "<ul>";
        foreach ($testFiles as $filename => $count) {
            echo "<li>$filename: $count requests</li>";
        }
        echo "</ul>";
    }
    
    echo "<hr>";
}

echo "<h3>Final Test - Using Best Pattern:</h3>";

// Verwende das beste Pattern
$finalFiles = [];
$finalMatches = 0;

foreach ($lines as $line) {
    // Einfachstes Pattern - nur IP und GET/POST suchen
    if (preg_match('/(\d+\.\d+\.\d+\.\d+).*?"(GET|POST) ([^"]*)"/', $line, $matches)) {
        $finalMatches++;
        $url = $matches[3];
        
        // URL bereinigen
        $cleanUrl = preg_replace('/\s+HTTP\/[\d.]+$/', '', $url);
        $cleanUrl = preg_replace('/\?.*$/', '', $cleanUrl); // Remove query parameters
        
        if (strpos($cleanUrl, '/wci/') !== false) {
            $file = basename($cleanUrl);
            
            if (!empty($file) && !in_array($file, ['favicon.ico', 'ping.php']) && !empty(pathinfo($file, PATHINFO_EXTENSION))) {
                $finalFiles[$file] = ($finalFiles[$file] ?? 0) + 1;
            }
        }
    }
    
    if ($finalMatches >= 100) break; // Limit für Performance
}

echo "<p><strong>Final Results:</strong> $finalMatches matches, " . count($finalFiles) . " files found</p>";

if (count($finalFiles) > 0) {
    echo "<h4>Found files:</h4>";
    arsort($finalFiles);
    foreach ($finalFiles as $filename => $count) {
        echo "<p><strong>$filename</strong>: $count requests</p>";
    }
} else {
    echo "<p style='color: red;'>No files found! Check regex pattern and log format.</p>";
}
?>
