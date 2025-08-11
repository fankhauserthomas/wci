<?php
echo "<h1>Simple File Test</h1>";

$logFile = '/home/vadmin/lemp/html/wci/access.log';
echo "<p>Checking file: $logFile</p>";

echo "<p>file_exists(): " . (file_exists($logFile) ? "YES" : "NO") . "</p>";
echo "<p>is_readable(): " . (is_readable($logFile) ? "YES" : "NO") . "</p>";
echo "<p>filesize(): " . (file_exists($logFile) ? filesize($logFile) : "N/A") . "</p>";

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "<p>Lines count: " . count($lines) . "</p>";
    
    echo "<h2>First 3 lines:</h2>";
    for($i = 0; $i < min(3, count($lines)); $i++) {
        echo "<p>Line $i: " . htmlspecialchars($lines[$i]) . "</p>";
    }
}
?>
