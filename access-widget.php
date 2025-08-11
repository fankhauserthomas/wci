<?php
// WCI Access Analytics Widget für Dashboard Integration

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
    'requests' => 0,
    'files' => 0, 
    'users' => 0,
    'errors' => 0,
    'last_activity' => null
];

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $uniqueFiles = [];
    $uniqueUsers = [];
    
    foreach ($lines as $line) {
        if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"\s]+)[^"]*" (\d+)/', $line, $matches)) {
            $stats['requests']++;
            $uniqueUsers[$matches[1]] = true;
            
            $url = $matches[4];
            $file = parse_url($url, PHP_URL_PATH);
            if ($file && $file !== '/') {
                // /wci/ Prefix entfernen falls vorhanden
                $file = str_replace('/wci/', '', $file);
                $file = basename($file);
                if (!empty($file) && $file !== '/' && !in_array($file, $systemFiles)) {
                    $uniqueFiles[$file] = true;
                }
            }
            
            if (intval($matches[5]) >= 400) {
                $stats['errors']++;
            }
            
            $stats['last_activity'] = $matches[2];
        }
    }
    
    $stats['files'] = count($uniqueFiles);
    $stats['users'] = count($uniqueUsers);
}
?>

<div class="access-analytics-widget" style="
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    padding: 20px;
    color: white;
    margin: 15px 0;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
" id="accessWidget">
    <div style="display: flex; align-items: center; margin-bottom: 15px;">
        <span style="font-size: 1.5em; margin-right: 10px;">📊</span>
        <h3 style="margin: 0;">Access Analytics</h3>
        <span id="liveIndicator" style="
            margin-left: 15px;
            width: 8px;
            height: 8px;
            background: #4caf50;
            border-radius: 50%;
            animation: pulse 2s infinite;
        "></span>
        <a href="access-dashboard.php" style="
            margin-left: auto;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            transition: all 0.3s ease;
        " onmouseover="this.style.background='rgba(255,255,255,0.3)'" 
           onmouseout="this.style.background='rgba(255,255,255,0.2)'">
            📈 Details
        </a>
    </div>
    
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 15px;">
        <div style="text-align: center;">
            <div id="requestCount" style="font-size: 1.8em; font-weight: bold;"><?= number_format($stats['requests']) ?></div>
            <div style="font-size: 0.8em; opacity: 0.8;">Requests</div>
        </div>
        <div style="text-align: center;">
            <div id="fileCount" style="font-size: 1.8em; font-weight: bold;"><?= $stats['files'] ?></div>
            <div style="font-size: 0.8em; opacity: 0.8;">Files</div>
        </div>
        <div style="text-align: center;">
            <div id="userCount" style="font-size: 1.8em; font-weight: bold;"><?= $stats['users'] ?></div>
            <div style="font-size: 0.8em; opacity: 0.8;">Users</div>
        </div>
        <div style="text-align: center;">
            <div id="errorCount" style="font-size: 1.8em; font-weight: bold; color: <?= $stats['errors'] > 0 ? '#ffeb3b' : '#4caf50' ?>;">
                <?= $stats['errors'] ?>
            </div>
            <div style="font-size: 0.8em; opacity: 0.8;">Errors</div>
        </div>
    </div>
    
    <?php if ($stats['last_activity']): ?>
    <div id="lastActivity" style="font-size: 0.8em; opacity: 0.8; text-align: center;">
        Last Activity: <?= date('H:i:s', strtotime(str_replace(['[', ']'], '', $stats['last_activity']))) ?>
    </div>
    <?php endif; ?>
</div>

<style>
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}
</style>

<script>
// Live-Update für Access Analytics Widget
function updateAccessWidget() {
    fetch('api-access-stats.php')
        .then(response => response.json())
        .then(data => {
            if (data.status === 'ok') {
                document.getElementById('requestCount').textContent = data.requests.toLocaleString();
                document.getElementById('fileCount').textContent = data.files;
                document.getElementById('userCount').textContent = data.users;
                
                const errorElement = document.getElementById('errorCount');
                errorElement.textContent = data.errors;
                errorElement.style.color = data.errors > 0 ? '#ffeb3b' : '#4caf50';
                
                // Live Indicator blinken lassen bei Update
                const indicator = document.getElementById('liveIndicator');
                indicator.style.background = '#4caf50';
                setTimeout(() => {
                    indicator.style.background = '#667eea';
                }, 500);
            }
        })
        .catch(error => {
            console.log('Access stats update failed:', error);
            document.getElementById('liveIndicator').style.background = '#f44336';
        });
}

// Update alle 10 Sekunden
setInterval(updateAccessWidget, 10000);

// Einmal sofort ausführen
setTimeout(updateAccessWidget, 2000);
</script>
