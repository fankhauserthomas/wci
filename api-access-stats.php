<?php
// Live Stats API für Dashboard
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Filter-Konfiguration
$systemFiles = [
    'ping.php',
    'sync_matrix.php', 
    'syncTrigger.php',
    'checkAuth-simple.php',
    'checkAuth.php',
    'api-access-stats.php'
];

$logFile = '/home/vadmin/lemp/logs/apache2/access.log';
// Fallback für verschiedene Umgebungen
if (!file_exists($logFile)) {
    $logFile = '../../logs/apache2/access.log';
}
if (!file_exists($logFile)) {
    $logFile = '/var/log/apache2/access.log';
}

$stats = [
    'timestamp' => time(),
    'requests' => 0,
    'files' => 0,
    'users' => 0,
    'errors' => 0,
    'recent' => [],
    'hourly' => array_fill(0, 24, 0),
    'top_files' => [],
    'status' => 'ok'
];

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $uniqueFiles = [];
    $uniqueUsers = [];
    $allFiles = [];
    
    // Nur die letzten 100 Einträge für Live-Updates
    $recentLines = array_slice($lines, -100);
    
    foreach ($recentLines as $line) {
        if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"\s]+)[^"]*" (\d+)/', $line, $matches)) {
            $ip = $matches[1];
            $datetime = $matches[2];
            $method = $matches[3];
            $url = $matches[4];
            $status = intval($matches[5]);
            
            $stats['requests']++;
            $uniqueUsers[$ip] = true;
            
            // Stunde extrahieren
            if (preg_match('/\d+\/\w+\/\d+:(\d+):/', $datetime, $timeMatch)) {
                $hour = intval($timeMatch[1]);
                $stats['hourly'][$hour]++;
            }
            
            $file = parse_url($url, PHP_URL_PATH);
            if ($file && $file !== '/') {
                // /wci/ Prefix entfernen falls vorhanden
                $file = str_replace('/wci/', '', $file);
                $file = basename($file);
                
                if (!empty($file) && $file !== '/' && !in_array($file, $systemFiles)) {
                    $uniqueFiles[$file] = true;
                    if (!isset($allFiles[$file])) {
                        $allFiles[$file] = 0;
                    }
                    $allFiles[$file]++;
                }
            }
            
            if ($status >= 400) {
                $stats['errors']++;
            }
            
            // Recent Activity
            $stats['recent'][] = [
                'time' => date('H:i:s', strtotime(str_replace(['[', ']'], '', $datetime))),
                'ip' => $ip,
                'file' => $file,
                'status' => $status
            ];
        }
    }
    
    $stats['files'] = count($uniqueFiles);
    $stats['users'] = count($uniqueUsers);
    
    // Top Files
    arsort($allFiles);
    $stats['top_files'] = array_slice($allFiles, 0, 5, true);
    
    // Recent Activity limitieren
    $stats['recent'] = array_slice(array_reverse($stats['recent']), 0, 10);
} else {
    $stats['status'] = 'no_logs';
}

echo json_encode($stats, JSON_PRETTY_PRINT);
