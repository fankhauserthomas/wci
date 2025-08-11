<?php
// Professional WCI Access Analysis System
// Tiefgreifende Analyse aller Dateizugriffe mit erweiterten Statistiken

require_once 'auth-simple.php';

// Check authentication
if (!AuthManager::checkSession()) {
    header('Location: login-simple.php');
    exit;
}

class WCIAccessAnalyzerPro {
    private $logFile = '/home/vadmin/lemp/logs/apache2/access.log';
    private $wciPath = '/wci/';
    
    public function analyzeAccess($days = 30) {
        if (!file_exists($this->logFile)) {
            return ['error' => 'Log-Datei nicht gefunden'];
        }
        
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $cutoffDate = date('Y-m-d', strtotime("-$days days"));
        
        $stats = [
            'total_requests' => 0,
            'unique_ips' => [],
            'files' => [],
            'file_types' => [],
            'hourly_activity' => array_fill(0, 24, 0),
            'daily_activity' => [],
            'response_codes' => [],
            'user_agents' => [],
            'duplicates' => [],
            'rapid_repeats' => [],
            'session_analysis' => [],
            'file_dependencies' => []
        ];
        
        $lastRequests = []; // Für Duplicate-Erkennung
        
        foreach ($lines as $line) {
            if (!preg_match('/^(\S+) - - \[([^\]]+)\] "(\S+) (\S+) ([^"]+)" (\d+) (\d+) "([^"]*)" "([^"]*)"/', $line, $matches)) {
                continue;
            }
            
            $ip = $matches[1];
            $datetime = $matches[2];
            $method = $matches[3];
            $url = $matches[4];
            $protocol = $matches[5];
            $status = intval($matches[6]);
            $size = intval($matches[7]);
            $referrer = $matches[8];
            $userAgent = $matches[9];
            
            // Nur WCI-Requests analysieren
            if (strpos($url, $this->wciPath) !== 0) {
                continue;
            }
            
            // Datum-Filter
            $requestDate = DateTime::createFromFormat('d/M/Y:H:i:s O', $datetime);
            if (!$requestDate || $requestDate->format('Y-m-d') < $cutoffDate) {
                continue;
            }
            
            $stats['total_requests']++;
            
            // Clean URL (ohne Query-Parameter)
            $cleanUrl = strtok($url, '?');
            $fileName = basename($cleanUrl);
            $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
            
            // Unique IPs
            if (!isset($stats['unique_ips'][$ip])) {
                $stats['unique_ips'][$ip] = 0;
            }
            $stats['unique_ips'][$ip]++;
            
            // File-Statistiken
            if (!isset($stats['files'][$cleanUrl])) {
                $stats['files'][$cleanUrl] = [
                    'count' => 0,
                    'ips' => [],
                    'methods' => [],
                    'status_codes' => [],
                    'first_seen' => $datetime,
                    'last_seen' => $datetime,
                    'avg_size' => 0,
                    'total_size' => 0,
                    'referrers' => []
                ];
            }
            
            $stats['files'][$cleanUrl]['count']++;
            $stats['files'][$cleanUrl]['ips'][$ip] = true;
            $stats['files'][$cleanUrl]['methods'][$method] = ($stats['files'][$cleanUrl]['methods'][$method] ?? 0) + 1;
            $stats['files'][$cleanUrl]['status_codes'][$status] = ($stats['files'][$cleanUrl]['status_codes'][$status] ?? 0) + 1;
            $stats['files'][$cleanUrl]['last_seen'] = $datetime;
            $stats['files'][$cleanUrl]['total_size'] += $size;
            $stats['files'][$cleanUrl]['avg_size'] = $stats['files'][$cleanUrl]['total_size'] / $stats['files'][$cleanUrl]['count'];
            
            if ($referrer && $referrer !== '-') {
                $stats['files'][$cleanUrl]['referrers'][$referrer] = ($stats['files'][$cleanUrl]['referrers'][$referrer] ?? 0) + 1;
            }
            
            // File-Type-Statistiken
            if ($fileExt) {
                $stats['file_types'][$fileExt] = ($stats['file_types'][$fileExt] ?? 0) + 1;
            }
            
            // Zeitanalyse
            $hour = intval($requestDate->format('H'));
            $stats['hourly_activity'][$hour]++;
            
            $day = $requestDate->format('Y-m-d');
            $stats['daily_activity'][$day] = ($stats['daily_activity'][$day] ?? 0) + 1;
            
            // Response-Codes
            $stats['response_codes'][$status] = ($stats['response_codes'][$status] ?? 0) + 1;
            
            // User-Agents
            $shortAgent = substr($userAgent, 0, 100);
            $stats['user_agents'][$shortAgent] = ($stats['user_agents'][$shortAgent] ?? 0) + 1;
            
            // Duplicate/Rapid-Repeat Erkennung
            $requestKey = $ip . '|' . $cleanUrl;
            $timestamp = $requestDate->getTimestamp();
            
            if (isset($lastRequests[$requestKey])) {
                $timeDiff = $timestamp - $lastRequests[$requestKey]['timestamp'];
                
                if ($timeDiff < 2) { // Weniger als 2 Sekunden = Rapid Repeat
                    if (!isset($stats['rapid_repeats'][$cleanUrl])) {
                        $stats['rapid_repeats'][$cleanUrl] = 0;
                    }
                    $stats['rapid_repeats'][$cleanUrl]++;
                }
                
                if ($timeDiff < 10) { // Weniger als 10 Sekunden = Potential Duplicate
                    if (!isset($stats['duplicates'][$cleanUrl])) {
                        $stats['duplicates'][$cleanUrl] = 0;
                    }
                    $stats['duplicates'][$cleanUrl]++;
                }
            }
            
            $lastRequests[$requestKey] = [
                'timestamp' => $timestamp,
                'url' => $cleanUrl
            ];
        }
        
        // Post-Processing
        arsort($stats['files']);
        arsort($stats['unique_ips']);
        arsort($stats['file_types']);
        arsort($stats['response_codes']);
        arsort($stats['user_agents']);
        arsort($stats['rapid_repeats']);
        arsort($stats['duplicates']);
        
        return $stats;
    }
    
    public function findUnusedFiles() {
        $allFiles = [];
        $wciDir = __DIR__;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wciDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (preg_match('/\.(php|html|htm|css|js)$/i', $file->getFilename())) {
                $relativePath = str_replace($wciDir, '', $file->getPathname());
                $relativePath = $this->wciPath . ltrim($relativePath, '/');
                $allFiles[] = $relativePath;
            }
        }
        
        // Aus Logs genutzte Dateien extrahieren
        $usedFiles = [];
        if (file_exists($this->logFile)) {
            $logContent = file_get_contents($this->logFile);
            preg_match_all('/"[A-Z]+ (' . preg_quote($this->wciPath, '/') . '[^\\s?]+)/', $logContent, $matches);
            $usedFiles = array_unique($matches[1]);
        }
        
        $unusedFiles = array_diff($allFiles, $usedFiles);
        
        $unusedWithDetails = [];
        foreach ($unusedFiles as $file) {
            $fullPath = str_replace($this->wciPath, $wciDir . '/', $file);
            if (file_exists($fullPath)) {
                $unusedWithDetails[] = [
                    'file' => $file,
                    'size' => filesize($fullPath),
                    'modified' => filemtime($fullPath),
                    'type' => pathinfo($file, PATHINFO_EXTENSION)
                ];
            }
        }
        
        return $unusedWithDetails;
    }
    
    public function getLogRotationInfo() {
        if (!file_exists($this->logFile)) {
            return ['error' => 'Log-Datei nicht gefunden'];
        }
        
        $size = filesize($this->logFile);
        $lines = count(file($this->logFile));
        $created = filectime($this->logFile);
        $modified = filemtime($this->logFile);
        
        $ageHours = (time() - $created) / 3600;
        $bytesPerHour = $ageHours > 0 ? $size / $ageHours : 0;
        $linesPerHour = $ageHours > 0 ? $lines / $ageHours : 0;
        
        return [
            'current_size' => $size,
            'current_lines' => $lines,
            'age_hours' => $ageHours,
            'bytes_per_hour' => $bytesPerHour,
            'lines_per_hour' => $linesPerHour,
            'projected_year_size' => $bytesPerHour * 24 * 365,
            'projected_year_lines' => $linesPerHour * 24 * 365
        ];
    }
}

$analyzer = new WCIAccessAnalyzerPro();
$days = intval($_GET['days'] ?? 30);
$analysis = $analyzer->analyzeAccess($days);
$unusedFiles = $analyzer->findUnusedFiles();
$logInfo = $analyzer->getLogRotationInfo();

function formatBytes($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit = 0;
    while ($size > 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, 2) . ' ' . $units[$unit];
}

function formatNumber($num) {
    return number_format($num, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WCI Professional Access Analysis</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .pro-container {
            max-width: 1600px;
            margin: 20px auto;
            padding: 20px;
        }
        
        .analysis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .analysis-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }
        
        .card-header {
            font-size: 1.2em;
            font-weight: bold;
            margin-bottom: 15px;
            color: #2c3e50;
        }
        
        .stat-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
            font-family: monospace;
            font-size: 0.9em;
        }
        
        .stat-value {
            font-weight: bold;
            color: #e74c3c;
        }
        
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        
        .chart-container {
            width: 100%;
            height: 200px;
            margin: 20px 0;
            position: relative;
        }
        
        .back-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
            z-index: 1000;
        }
        
        .period-selector {
            margin: 20px 0;
            text-align: center;
        }
        
        .period-btn {
            margin: 0 5px;
            padding: 8px 16px;
            border: 1px solid #3498db;
            background: white;
            color: #3498db;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }
        
        .period-btn.active {
            background: #3498db;
            color: white;
        }
        
        .overview-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .overview-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            text-align: center;
            border-left: 4px solid #007bff;
        }
        
        .big-number {
            font-size: 2em;
            font-weight: bold;
            color: #007bff;
        }
        
        .label {
            color: #6c757d;
            font-size: 0.9em;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <a href="index.php" class="back-button">← Dashboard</a>
    
    <div class="pro-container">
        <h1>🔍 WCI Professional Access Analysis</h1>
        
        <div class="period-selector">
            <a href="?days=1" class="period-btn <?php echo $days==1?'active':''; ?>">24h</a>
            <a href="?days=7" class="period-btn <?php echo $days==7?'active':''; ?>">7 Tage</a>
            <a href="?days=30" class="period-btn <?php echo $days==30?'active':''; ?>">30 Tage</a>
            <a href="?days=90" class="period-btn <?php echo $days==90?'active':''; ?>">90 Tage</a>
            <a href="?days=365" class="period-btn <?php echo $days==365?'active':''; ?>">1 Jahr</a>
        </div>
        
        <!-- Overview Statistics -->
        <div class="overview-stats">
            <div class="overview-card">
                <div class="big-number"><?php echo formatNumber($analysis['total_requests'] ?? 0); ?></div>
                <div class="label">Gesamt Requests</div>
            </div>
            <div class="overview-card">
                <div class="big-number"><?php echo count($analysis['unique_ips'] ?? []); ?></div>
                <div class="label">Unique IPs</div>
            </div>
            <div class="overview-card">
                <div class="big-number"><?php echo count($analysis['files'] ?? []); ?></div>
                <div class="label">Verwendete Dateien</div>
            </div>
            <div class="overview-card">
                <div class="big-number"><?php echo count($unusedFiles); ?></div>
                <div class="label">Unbenutzte Dateien</div>
            </div>
        </div>
        
        <!-- Log Rotation Info -->
        <div class="analysis-card">
            <div class="card-header">📊 Log-Volumen & Prognose</div>
            <?php if ($logInfo && !isset($logInfo['error'])): ?>
            <div class="overview-stats">
                <div class="overview-card">
                    <div class="big-number"><?php echo formatBytes($logInfo['current_size']); ?></div>
                    <div class="label">Aktuelle Größe</div>
                </div>
                <div class="overview-card">
                    <div class="big-number"><?php echo formatNumber($logInfo['current_lines']); ?></div>
                    <div class="label">Zeilen</div>
                </div>
                <div class="overview-card">
                    <div class="big-number"><?php echo formatBytes($logInfo['projected_year_size']); ?></div>
                    <div class="label">Prognose 1 Jahr</div>
                </div>
                <div class="overview-card">
                    <div class="big-number"><?php echo round($logInfo['age_hours'], 1); ?>h</div>
                    <div class="label">Log-Alter</div>
                </div>
            </div>
            
            <?php if ($logInfo['projected_year_size'] > 1024*1024*1024*10): // > 10GB ?>
            <div class="warning">
                ⚠️ <strong>Warnung:</strong> Projizierte Jahres-Log-Größe über 10GB. 
                Log-Rotation empfohlen!
            </div>
            <?php else: ?>
            <div class="success">
                ✅ Log-Volumen ist vertretbar für 1-Jahres-Speicherung
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div class="analysis-grid">
            <!-- Most Accessed Files -->
            <div class="analysis-card">
                <div class="card-header">🔥 Meist aufgerufene Dateien</div>
                <div class="stat-list">
                    <?php foreach (array_slice($analysis['files'] ?? [], 0, 20, true) as $file => $data): ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars(basename($file)); ?></span>
                            <span class="stat-value"><?php echo $data['count']; ?>×</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Rapid Repeats -->
            <div class="analysis-card">
                <div class="card-header">⚡ Schnelle Wiederholungen (&lt;2s)</div>
                <div class="stat-list">
                    <?php foreach (array_slice($analysis['rapid_repeats'] ?? [], 0, 15, true) as $file => $count): ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars(basename($file)); ?></span>
                            <span class="stat-value"><?php echo $count; ?>×</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($analysis['rapid_repeats'])): ?>
                    <div class="success">✅ Keine schnellen Wiederholungen erkannt</div>
                <?php endif; ?>
            </div>
            
            <!-- Duplicate Requests -->
            <div class="analysis-card">
                <div class="card-header">🔄 Doppelte Anfragen (&lt;10s)</div>
                <div class="stat-list">
                    <?php foreach (array_slice($analysis['duplicates'] ?? [], 0, 15, true) as $file => $count): ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars(basename($file)); ?></span>
                            <span class="stat-value"><?php echo $count; ?>×</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($analysis['duplicates'])): ?>
                    <div class="success">✅ Keine verdächtigen Doppelaufrufe</div>
                <?php endif; ?>
            </div>
            
            <!-- File Types -->
            <div class="analysis-card">
                <div class="card-header">📁 Dateitypen</div>
                <div class="stat-list">
                    <?php foreach ($analysis['file_types'] ?? [] as $type => $count): ?>
                        <div class="stat-item">
                            <span>.<?php echo htmlspecialchars($type); ?></span>
                            <span class="stat-value"><?php echo $count; ?>×</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Unique IPs -->
            <div class="analysis-card">
                <div class="card-header">👥 Benutzer-IPs</div>
                <div class="stat-list">
                    <?php foreach (array_slice($analysis['unique_ips'] ?? [], 0, 15, true) as $ip => $count): ?>
                        <div class="stat-item">
                            <span><?php echo htmlspecialchars($ip); ?></span>
                            <span class="stat-value"><?php echo $count; ?>×</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Response Codes -->
            <div class="analysis-card">
                <div class="card-header">📊 HTTP Status Codes</div>
                <div class="stat-list">
                    <?php foreach ($analysis['response_codes'] ?? [] as $code => $count): ?>
                        <div class="stat-item">
                            <span>HTTP <?php echo $code; ?></span>
                            <span class="stat-value"><?php echo $count; ?>×</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Unused Files -->
        <div class="analysis-card">
            <div class="card-header">🗑️ Nicht verwendete Dateien (<?php echo count($unusedFiles); ?>)</div>
            <div class="stat-list">
                <?php foreach (array_slice($unusedFiles, 0, 50) as $file): ?>
                    <div class="stat-item">
                        <span><?php echo htmlspecialchars($file['file']); ?></span>
                        <span class="stat-value"><?php echo formatBytes($file['size']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($unusedFiles) > 50): ?>
                <div class="warning">... und <?php echo count($unusedFiles) - 50; ?> weitere</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
