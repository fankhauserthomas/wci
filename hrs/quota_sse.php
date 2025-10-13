<?php
/**
 * Quota SSE (Server-Sent Events) Endpoint
 * ========================================
 * Provides real-time updates during quota processing
 * 
 * Usage: /wci/hrs/quota_sse.php?session_id=unique_session_id
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

// Flush any existing output
if (ob_get_level()) {
    ob_end_clean();
}

function sendSSE($eventType, $data, $id = null) {
    $timestamp = date('Y-m-d H:i:s.u');
    error_log("SSE: Sending event - $eventType at $timestamp");
    
    if ($id) {
        echo "id: $id\n";
    }
    echo "event: $eventType\n";
    echo "data: " . json_encode($data) . "\n\n";
    
    // Force immediate output
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
    
    // Additional flush for some servers
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

function getSessionFile($sessionId) {
    $tmpDir = sys_get_temp_dir();
    return $tmpDir . '/quota_sse_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) . '.json';
}

$sessionId = $_GET['session_id'] ?? null;
if (!$sessionId) {
    sendSSE('error', ['message' => 'Missing session_id parameter']);
    exit();
}

$sessionFile = getSessionFile($sessionId);
$lastModified = 0;
$eventCounter = 0;

// Send initial connection confirmation
sendSSE('connected', [
    'session_id' => $sessionId,
    'timestamp' => time(),
    'message' => 'SSE connection established'
], $eventCounter++);

// Keep connection alive and monitor for updates
while (true) {
    if (connection_aborted()) {
        break;
    }
    
    if (file_exists($sessionFile)) {
        $currentModified = filemtime($sessionFile);
        
        if ($currentModified > $lastModified) {
            $lastModified = $currentModified;
            
            $content = file_get_contents($sessionFile);
            if ($content) {
                $data = json_decode($content, true);
                if ($data && isset($data['events'])) {
                    // Send all new events
                    foreach ($data['events'] as $event) {
                        if (!isset($event['sent'])) {
                            sendSSE($event['type'], $event['data'], $eventCounter++);
                            $event['sent'] = true;
                        }
                    }
                    
                    // Update the session file to mark events as sent
                    file_put_contents($sessionFile, json_encode($data));
                }
            }
        }
        
        // Check if session is marked as complete
        if (file_exists($sessionFile)) {
            $content = file_get_contents($sessionFile);
            $data = json_decode($content, true);
            if ($data && isset($data['completed']) && $data['completed']) {
                sendSSE('completed', ['message' => 'Session completed'], $eventCounter++);
                // Clean up session file after a delay
                sleep(2);
                @unlink($sessionFile);
                break;
            }
        }
    }
    
    // Send heartbeat every 30 seconds
    if ($eventCounter % 30 === 0) {
        sendSSE('heartbeat', ['timestamp' => time()], $eventCounter);
    }
    
    sleep(1); // Check for updates every second
    $eventCounter++;
    
    // Timeout after 10 minutes
    if ($eventCounter > 600) {
        sendSSE('timeout', ['message' => 'Session timeout'], $eventCounter);
        break;
    }
}

// Clean up
if (file_exists($sessionFile)) {
    @unlink($sessionFile);
}
?>