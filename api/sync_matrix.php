<?php
// api/sync_matrix.php - Live Sync-Log API für Matrix-Display

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// CORS für AJAX requests
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

try {
    $logFile = __DIR__ . '/../logs/sync.log';
    $maxLines = 50;
    $logs = [];
    
    // Check if log file exists
    if (!file_exists($logFile)) {
        // Create some dummy logs for demo
        $logs = [
            [
                'timestamp' => date('Y-m-d H:i:s'),
                'message' => 'No sync logs found - system idle'
            ]
        ];
    } else {
        // Read last lines from log file
        $lines = [];
        $file = new SplFileObject($logFile);
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        
        // Start from the last N lines
        $startLine = max(0, $totalLines - $maxLines);
        $file->seek($startLine);
        
        while (!$file->eof()) {
            $line = trim($file->fgets());
            if (!empty($line)) {
                $lines[] = $line;
            }
        }
        
        // Parse log lines
        foreach ($lines as $line) {
            // Parse format: [timestamp] message
            if (preg_match('/^\[([^\]]+)\]\s*(.+)$/', $line, $matches)) {
                $timestamp = $matches[1];
                $message = $matches[2];
                
                // Clean up message (remove [SyncManager] prefix if present)
                $message = preg_replace('/^\[SyncManager\]\s*/', '', $message);
                
                // Shorten very long messages
                if (strlen($message) > 80) {
                    $message = substr($message, 0, 77) . '...';
                }
                
                $logs[] = [
                    'timestamp' => $timestamp,
                    'message' => $message
                ];
            }
        }
    }
    
    // Add current system status
    $currentTime = date('Y-m-d H:i:s');
    
    // Check if SyncManager is available
    if (class_exists('SyncManager') || file_exists(__DIR__ . '/../SyncManager.php')) {
        $systemStatus = 'Sync system operational';
    } else {
        $systemStatus = 'Sync system offline';
    }
    
    // Add a current status line if no recent logs
    if (empty($logs) || count($logs) < 3) {
        $logs[] = [
            'timestamp' => $currentTime,
            'message' => $systemStatus
        ];
        
        $logs[] = [
            'timestamp' => $currentTime,
            'message' => 'Multi-table sync: AV-ResNamen, AV-Res, AV_ResDet, zp_zimmer'
        ];
    }
    
    // Limit to maxLines
    $logs = array_slice($logs, -$maxLines);
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'count' => count($logs),
        'timestamp' => $currentTime
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'logs' => [[
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'API Error: ' . $e->getMessage()
        ]]
    ]);
}
?>
