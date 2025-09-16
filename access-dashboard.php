<?php
// WCI Access Analytics Dashboard
header('Content-Type: text/html; charset=utf-8');

// Filter-Konfiguration für häufige System-Files
$filterSystemFiles = isset($_GET['filter']) ? $_GET['filter'] === 'true' : true;
$hiddenFiles = isset($_GET['hidden']) ? explode(',', $_GET['hidden']) : [];
$systemFiles = [
    'ping.php',
    'sync_matrix.php', 
    'syncTrigger.php',
    'checkAuth.php',
    'api-access-stats.php'
];

// Log-Datei analysieren - Korrekter Pfad für Docker Setup
$logFile = '/home/vadmin/lemp/logs/apache2/access.log';
// Fallback für verschiedene Umgebungen
if (!file_exists($logFile)) {
    $logFile = '../../logs/apache2/access.log';
}
if (!file_exists($logFile)) {
    $logFile = '/var/log/apache2/access.log';
}

$data = [
    'total_requests' => 0,
    'unique_files' => [],
    'all_files' => [], // Alle Files ohne Filter
    'users' => [],
    'hourly_stats' => array_fill(0, 24, 0),
    'daily_stats' => [],
    'top_files' => [],
    'error_count' => 0,
    'file_types' => [],
    'recent_activity' => [],
    'performance' => [],
    'file_stats' => [], // Detaillierte File-Statistiken
    'user_agents' => [],
    'response_codes' => [],
    'peak_hours' => [],
    'file_sizes' => []
];

if (file_exists($logFile)) {
    // Speicher-effiziente Verarbeitung - begrenze Speicher und nutze tail für große Dateien
    ini_set('memory_limit', '256M');
    
    $fileSize = filesize($logFile);
    if ($fileSize > 50 * 1024 * 1024) { // Wenn größer als 50MB
        $output = shell_exec("tail -n 10000 " . escapeshellarg($logFile));
        $lines = $output ? explode("\n", trim($output)) : [];
    } else {
        // Für kleinere Dateien verwende file()
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) $lines = [];
    }
    
    foreach ($lines as $line) {
        if (empty($line)) continue;
        
        // Apache Log Format parsen - Korrekte Regex für "METHOD /path HTTP/1.1"
        if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"\s]+)[^"]*" (\d+) (\d+)/', $line, $matches)) {
            $ip = $matches[1];
            $datetime = $matches[2];
            $method = $matches[3];
            $url = $matches[4];
            $status = intval($matches[5]);
            $bytes = intval($matches[6]);
            
            $data['total_requests']++;
            
            // Response Codes sammeln
            if (!isset($data['response_codes'][$status])) {
                $data['response_codes'][$status] = 0;
            }
            $data['response_codes'][$status]++;
            
            // IP-Adressen sammeln
            if (!isset($data['users'][$ip])) {
                $data['users'][$ip] = [
                    'count' => 0,
                    'last_seen' => $datetime,
                    'errors' => 0
                ];
            }
            $data['users'][$ip]['count']++;
            $data['users'][$ip]['last_seen'] = $datetime;
            if ($status >= 400) {
                $data['users'][$ip]['errors']++;
            }
            
            // Tagesstatistik
            $date = substr($datetime, 0, 11); // DD/MMM/YYYY
            if (!isset($data['daily_stats'][$date])) {
                $data['daily_stats'][$date] = 0;
            }
            $data['daily_stats'][$date]++;
            
            // Stunde extrahieren
            if (preg_match('/\d+\/\w+\/\d+:(\d+):/', $datetime, $timeMatch)) {
                $hour = intval($timeMatch[1]);
                $data['hourly_stats'][$hour]++;
            }
            
            // Datei-URL bereinigen - nur den Pfad ohne HTTP/1.1
            $file = parse_url($url, PHP_URL_PATH);
            if ($file && $file !== '/') {
                // /wci/ Prefix entfernen falls vorhanden
                $file = str_replace('/wci/', '', $file);
                $originalFile = basename($file);
                
                if (!empty($originalFile) && $originalFile !== '/') {
                    // ALLE Files sammeln (ohne Filter)
                    if (!isset($data['all_files'][$originalFile])) {
                        $data['all_files'][$originalFile] = 0;
                    }
                    $data['all_files'][$originalFile]++;
                    
                    // Detaillierte File-Statistiken
                    if (!isset($data['file_stats'][$originalFile])) {
                        $data['file_stats'][$originalFile] = [
                            'requests' => 0,
                            'bytes' => 0,
                            'errors' => 0,
                            'first_access' => $datetime,
                            'last_access' => $datetime,
                            'methods' => [],
                            'users' => [],
                            'avg_size' => 0
                        ];
                    }
                    
                    $data['file_stats'][$originalFile]['requests']++;
                    $data['file_stats'][$originalFile]['bytes'] += $bytes;
                    $data['file_stats'][$originalFile]['last_access'] = $datetime;
                    $data['file_stats'][$originalFile]['users'][$ip] = true;
                    
                    if (!isset($data['file_stats'][$originalFile]['methods'][$method])) {
                        $data['file_stats'][$originalFile]['methods'][$method] = 0;
                    }
                    $data['file_stats'][$originalFile]['methods'][$method]++;
                    
                    if ($status >= 400) {
                        $data['file_stats'][$originalFile]['errors']++;
                    }
                    
                    // File-Größe speichern
                    if ($bytes > 0) {
                        $data['file_sizes'][$originalFile] = $bytes;
                    }
                    
                    // System-Files filtern für gefilterte Ansicht
                    $isSystemFile = in_array($originalFile, $systemFiles);
                    $isHidden = in_array($originalFile, $hiddenFiles);
                    
                    if (!$isHidden && (!$filterSystemFiles || !$isSystemFile)) {
                        if (!isset($data['unique_files'][$originalFile])) {
                            $data['unique_files'][$originalFile] = 0;
                        }
                        $data['unique_files'][$originalFile]++;
                        
                        // File-Type analysieren
                        $ext = strtolower(pathinfo($originalFile, PATHINFO_EXTENSION));
                        if ($ext) {
                            if (!isset($data['file_types'][$ext])) {
                                $data['file_types'][$ext] = 0;
                            }
                            $data['file_types'][$ext]++;
                        }
                    }
                }
            }
            
            // Fehler zählen
            if ($status >= 400) {
                $data['error_count']++;
            }
            
            // Recent Activity (letzte 10)
            $data['recent_activity'][] = [
                'time' => $datetime,
                'ip' => $ip,
                'file' => $file,
                'status' => $status,
                'method' => $method
            ];
        }
    }
    
    // Top Files sortieren
    arsort($data['unique_files']);
    $data['top_files'] = array_slice($data['unique_files'], 0, 10, true);
    
    // Alle Files sortieren
    arsort($data['all_files']);
    
    // Recent Activity limitieren
    $data['recent_activity'] = array_slice(array_reverse($data['recent_activity']), 0, 20);
}

// Unused Files finden - Erweiterte Suche
$allFiles = [];

// Alle PHP, HTML, JS, CSS Dateien im Hauptverzeichnis
$patterns = ['*.php', '*.html', '*.js', '*.css'];
foreach ($patterns as $pattern) {
    $files = glob($pattern);
    foreach ($files as $file) {
        $allFiles[] = $file;
    }
}

// Alle Dateien in Unterverzeichnissen (api, js, css, etc.)
$subDirs = ['api', 'js', 'css', 'libs', 'zp', 'hrs'];
foreach ($subDirs as $dir) {
    if (is_dir($dir)) {
        foreach ($patterns as $pattern) {
            $files = glob($dir . '/' . $pattern);
            foreach ($files as $file) {
                $allFiles[] = $file;
            }
        }
    }
}

// Zusätzlich auch rekursive Suche in allen Ordnern
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator('.', RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    $extension = strtolower($file->getExtension());
    if (in_array($extension, ['php', 'html', 'js', 'css'])) {
        $relativePath = str_replace('./', '', $file->getPathname());
        if (!in_array($relativePath, $allFiles)) {
            $allFiles[] = $relativePath;
        }
    }
}

// Eindeutige Liste erstellen und sortieren
$allFiles = array_unique($allFiles);
sort($allFiles);

$accessedFiles = array_keys($data['unique_files']);
$unusedFiles = [];

// Detaillierte Analyse der ungenutzten Dateien
foreach ($allFiles as $file) {
    if (!in_array($file, $accessedFiles)) {
        $unusedFiles[] = [
            'name' => $file,
            'size' => file_exists($file) ? filesize($file) : 0,
            'modified' => file_exists($file) ? filemtime($file) : 0,
            'extension' => strtolower(pathinfo($file, PATHINFO_EXTENSION)),
            'directory' => dirname($file)
        ];
    }
}

// Nach Größe sortieren (größte zuerst)
usort($unusedFiles, function($a, $b) {
    return $b['size'] - $a['size'];
});
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WCI Access Analytics Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .dashboard {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        
        .header .subtitle {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.75em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .chart-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .chart-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        .chart-title {
            font-size: 1.1em;
            margin-bottom: 15px;
            color: #333;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 8px;
        }
        
        .full-width-card {
            grid-column: 1 / -1;
        }
        
        .compact-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85em;
        }
        
        .compact-table th,
        .compact-table td {
            padding: 6px 10px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .compact-table th {
            background: #f8f9ff;
            font-weight: 600;
            color: #667eea;
        }
        
        .compact-table tr:hover {
            background: #f8f9ff;
        }
        
        .progress-bar {
            background: #e0e0e0;
            border-radius: 10px;
            height: 4px;
            margin: 2px 0;
        }
        
        .progress-fill {
            background: #667eea;
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .metric-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 0.8em;
        }
        
        .three-col-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        
        .file-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            margin: 5px 0;
            background: #f8f9ff;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }
        
        .file-item:hover {
            background: #e8f0ff;
            transform: translateX(5px);
        }
        
        .file-name {
            flex: 1;
            font-weight: 500;
        }
        
        .file-count {
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: bold;
        }
        
        .hide-file-btn {
            background: #f0f0f0;
            border: none;
            border-radius: 15px;
            padding: 4px 8px;
            cursor: pointer;
            font-size: 0.8em;
            transition: all 0.3s ease;
        }
        
        .hide-file-btn:hover {
            background: #ff6b6b;
            transform: scale(1.1);
        }
        
        .hidden-file-tag {
            background: #ffebee;
            color: #c62828;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid #ffcdd2;
        }
        
        .hidden-file-tag:hover {
            background: #c62828;
            color: white;
            transform: scale(1.05);
        }
        
        .unused-files {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-top: 20px;
        }
        
        .unused-item {
            background: #fff5f5;
            border-left: 4px solid #e53e3e;
            padding: 10px 15px;
            margin: 5px 0;
            border-radius: 5px;
            color: #c53030;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .mini-hide-btn {
            background: #ffeb3b;
            border: none;
            border-radius: 10px;
            padding: 2px 6px;
            cursor: pointer;
            font-size: 0.7em;
            transition: all 0.3s ease;
        }
        
        .mini-hide-btn:hover {
            background: #ff6b6b;
            transform: scale(1.1);
        }
        
        .activity-feed {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .activity-time {
            font-size: 0.8em;
            color: #666;
            width: 120px;
        }
        
        .activity-details {
            flex: 1;
            margin-left: 15px;
        }
        
        .status-200 { color: #38a169; }
        .status-404 { color: #e53e3e; }
        .status-500 { color: #d69e2e; }
        
        .refresh-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 25px;
            font-size: 1em;
            cursor: pointer;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: #5a6fd8;
            transform: scale(1.1);
        }
        
        .color-1 { color: #667eea; }
        .color-2 { color: #764ba2; }
        .color-3 { color: #f093fb; }
        .color-4 { color: #4facfe; }
    </style>
</head>
<body>
    <div class="dashboard">
            <div class="header">
            <h1>🏨 WCI Access Analytics</h1>
            <div class="subtitle">Live System Usage Dashboard</div>
            
            <!-- Filter Controls -->
            <div style="margin-top: 20px;">
                <div style="background: rgba(255,255,255,0.2); border-radius: 25px; padding: 15px 25px; display: inline-block;">
                    <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                        <!-- Quick Filter Toggle -->
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 5px;">
                            <input type="checkbox" id="filterToggle" <?= $filterSystemFiles ? 'checked' : '' ?> 
                                   onchange="toggleSystemFilter()">
                            System-Files ausblenden
                        </label>
                        
                        <!-- Advanced Filter Dropdown -->
                        <select id="filterPreset" onchange="applyFilterPreset()" style="
                            background: rgba(255,255,255,0.3);
                            border: none;
                            border-radius: 15px;
                            padding: 5px 10px;
                            color: white;
                        ">
                            <option value="all">🔍 Alle Files</option>
                            <option value="business" <?= $filterSystemFiles ? 'selected' : '' ?>>💼 Business Focus</option>
                            <option value="user_only">👥 Nur User-Aktivität</option>
                            <option value="errors_only">🚨 Nur Fehler</option>
                        </select>
                        
                        <!-- Network Graph Link -->
                        <a href="live-network-graph.php" target="_blank" style="
                            background: rgba(46, 204, 113, 0.8);
                            color: white;
                            text-decoration: none;
                            padding: 8px 15px;
                            border-radius: 20px;
                            font-size: 0.85em;
                            font-weight: bold;
                            transition: all 0.3s ease;
                        " onmouseover="this.style.background='rgba(46, 204, 113, 1)'" 
                           onmouseout="this.style.background='rgba(46, 204, 113, 0.8)'">
                            🌐 Live Network Graph
                        </a>
                        
                        <!-- Status Display -->
                        <span id="filterStatus" style="font-size: 0.8em; opacity: 0.8;">
                            <?php 
                            $statusText = '';
                            if ($filterSystemFiles) {
                                $statusText .= '✅ System gefiltert (' . count($systemFiles) . ')';
                            } else {
                                $statusText .= '📊 Ungefiltert';
                            }
                            if (!empty($hiddenFiles)) {
                                $statusText .= ' + ' . count($hiddenFiles) . ' ausgeblendet';
                            }
                            echo $statusText;
                            ?>
                        </span>
                        
                        <!-- Clear Hidden Button -->
                        <?php if (!empty($hiddenFiles)): ?>
                        <button onclick="clearAllHiddenFiles()" style="
                            background: rgba(255,107,107,0.8);
                            border: none;
                            border-radius: 15px;
                            color: white;
                            padding: 5px 10px;
                            cursor: pointer;
                            font-size: 0.75em;
                            transition: all 0.3s ease;
                        " onmouseover="this.style.background='rgba(255,107,107,1)'" 
                           onmouseout="this.style.background='rgba(255,107,107,0.8)'">
                            🗑️ Alle einblenden
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value color-1"><?= number_format($data['total_requests']) ?></div>
                <div class="stat-label">Total Requests</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value color-2"><?= count($allFiles) ?></div>
                <div class="stat-label">All Files</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value color-3"><?= count($data['unique_files']) ?></div>
                <div class="stat-label">Filtered Files</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value color-4"><?= count($data['users']) ?></div>
                <div class="stat-label">Unique Users</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value color-1"><?= $data['error_count'] ?></div>
                <div class="stat-label">Errors</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value color-2"><?= count($data['file_types']) ?></div>
                <div class="stat-label">File Types</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value color-3"><?php 
                    $totalBytes = array_sum($data['file_sizes']);
                    echo $totalBytes > 1024*1024 ? round($totalBytes/(1024*1024), 1).'MB' : round($totalBytes/1024, 1).'KB';
                ?></div>
                <div class="stat-label">Total Traffic</div>
            </div>
            
            <div class="stat-card" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);">
                <div class="stat-value" style="color: #8b0000; font-weight: bold;"><?= count($unusedFiles) ?></div>
                <div class="stat-label">🚫 Unused Files</div>
            </div>
        </div>
        
        <!-- Comprehensive File Statistics -->
        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-title">📊 All Files Access Statistics</div>
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>Requests</th>
                                <th>Users</th>
                                <th>Errors</th>
                                <th>Size</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Berechne durchschnittliche Größen
                            foreach ($data['file_stats'] as $file => &$stats) {
                                $stats['avg_size'] = $stats['requests'] > 0 ? $stats['bytes'] / $stats['requests'] : 0;
                                $stats['unique_users'] = count($stats['users']);
                            }
                            
                            // Sortiere nach Requests
                            uasort($data['file_stats'], function($a, $b) {
                                return $b['requests'] - $a['requests'];
                            });
                            
                            $maxRequests = max(array_column($data['file_stats'], 'requests'));
                            foreach (array_slice($data['file_stats'], 0, 50, true) as $file => $stats): 
                                $progressWidth = $maxRequests > 0 ? ($stats['requests'] / $maxRequests) * 100 : 0;
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($file) ?></strong>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?= $progressWidth ?>%"></div>
                                        </div>
                                    </td>
                                    <td><?= number_format($stats['requests']) ?></td>
                                    <td><?= $stats['unique_users'] ?></td>
                                    <td style="color: <?= $stats['errors'] > 0 ? '#e53e3e' : '#38a169' ?>">
                                        <?= $stats['errors'] ?>
                                    </td>
                                    <td><?= $stats['avg_size'] > 1024 ? round($stats['avg_size']/1024, 1).'KB' : round($stats['avg_size']).'B' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="chart-title">👥 User Activity Details</div>
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="compact-table">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th>Requests</th>
                                <th>Errors</th>
                                <th>Last Seen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            uasort($data['users'], function($a, $b) {
                                return $b['count'] - $a['count'];
                            });
                            
                            foreach (array_slice($data['users'], 0, 20, true) as $ip => $userStats): 
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($ip) ?></strong></td>
                                    <td><?= number_format($userStats['count']) ?></td>
                                    <td style="color: <?= $userStats['errors'] > 0 ? '#e53e3e' : '#38a169' ?>">
                                        <?= $userStats['errors'] ?>
                                    </td>
                                    <td style="font-size: 0.75em;">
                                        <?= date('H:i:s', strtotime(str_replace(['[', ']'], '', $userStats['last_seen']))) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Extended Analytics -->
        <div class="three-col-grid">
            <div class="chart-card">
                <div class="chart-title">📈 Response Codes</div>
                <?php foreach ($data['response_codes'] as $code => $count): ?>
                    <div class="metric-row">
                        <span style="color: <?= $code >= 400 ? '#e53e3e' : '#38a169' ?>">
                            <?= $code ?>
                        </span>
                        <span><?= number_format($count) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="chart-card">
                <div class="chart-title">📅 Daily Activity</div>
                <?php 
                arsort($data['daily_stats']);
                foreach (array_slice($data['daily_stats'], 0, 7, true) as $date => $count): 
                ?>
                    <div class="metric-row">
                        <span><?= $date ?></span>
                        <span><?= number_format($count) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="chart-card">
                <div class="chart-title">⚡ Peak Hours</div>
                <?php 
                $hourlyWithIndex = [];
                foreach ($data['hourly_stats'] as $hour => $count) {
                    if ($count > 0) {
                        $hourlyWithIndex[$hour] = $count;
                    }
                }
                arsort($hourlyWithIndex);
                foreach (array_slice($hourlyWithIndex, 0, 8, true) as $hour => $count): 
                ?>
                    <div class="metric-row">
                        <span><?= sprintf('%02d:00', $hour) ?></span>
                        <span><?= number_format($count) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- File Timeline & Methods -->
        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-title">⏰ Recent File Activity</div>
                <div style="max-height: 300px; overflow-y: auto;">
                    <?php 
                    $timelineFiles = [];
                    foreach ($data['file_stats'] as $file => $stats) {
                        $timelineFiles[] = [
                            'file' => $file,
                            'first' => $stats['first_access'],
                            'last' => $stats['last_access'],
                            'requests' => $stats['requests']
                        ];
                    }
                    
                    usort($timelineFiles, function($a, $b) {
                        return strtotime(str_replace(['[', ']'], '', $b['last'])) - strtotime(str_replace(['[', ']'], '', $a['last']));
                    });
                    
                    foreach (array_slice($timelineFiles, 0, 15) as $item):
                        $lastTime = date('H:i:s', strtotime(str_replace(['[', ']'], '', $item['last'])));
                        $firstTime = date('H:i:s', strtotime(str_replace(['[', ']'], '', $item['first'])));
                    ?>
                        <div style="border-left: 3px solid #667eea; padding-left: 15px; margin: 8px 0; padding: 8px 0 8px 15px;">
                            <div style="font-weight: bold; color: #333; font-size: 0.85em;">
                                <?= htmlspecialchars($item['file']) ?>
                                <span style="color: #667eea; font-weight: normal;">
                                    (<?= $item['requests'] ?>)
                                </span>
                            </div>
                            <div style="font-size: 0.7em; color: #666;">
                                <?= $firstTime ?> → <?= $lastTime ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="chart-title">🔧 HTTP Methods</div>
                <?php 
                $allMethods = [];
                foreach ($data['file_stats'] as $fileStats) {
                    foreach ($fileStats['methods'] as $method => $count) {
                        if (!isset($allMethods[$method])) {
                            $allMethods[$method] = 0;
                        }
                        $allMethods[$method] += $count;
                    }
                }
                arsort($allMethods);
                
                $totalMethods = array_sum($allMethods);
                foreach ($allMethods as $method => $count): 
                    $percentage = $totalMethods > 0 ? ($count / $totalMethods) * 100 : 0;
                ?>
                    <div style="margin: 10px 0;">
                        <div class="metric-row">
                            <span style="font-weight: bold;"><?= $method ?></span>
                            <span><?= number_format($count) ?> (<?= round($percentage, 1) ?>%)</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $percentage ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-title">📈 Hourly Activity Distribution</div>
                <canvas id="hourlyChart" width="400" height="200"></canvas>
            </div>
            
            <div class="chart-card">
                <div class="chart-title">📁 Top Accessed Files</div>
                <div class="file-list">
                    <?php foreach ($data['top_files'] as $file => $count): ?>
                        <div class="file-item" id="file-<?= htmlspecialchars($file) ?>">
                            <span class="file-name"><?= htmlspecialchars($file) ?></span>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="file-count"><?= $count ?></span>
                                <button class="hide-file-btn" onclick="toggleFileVisibility('<?= htmlspecialchars($file) ?>')" 
                                        title="File ausblenden">
                                    👁️
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (!empty($hiddenFiles)): ?>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f0;">
                    <div style="font-size: 0.9em; color: #666; margin-bottom: 10px;">
                        🙈 Ausgeblendete Files (<?= count($hiddenFiles) ?>):
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 5px;">
                        <?php foreach ($hiddenFiles as $hiddenFile): ?>
                            <span class="hidden-file-tag" onclick="toggleFileVisibility('<?= htmlspecialchars($hiddenFile) ?>')">
                                <?= htmlspecialchars($hiddenFile) ?> ❌
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-title">🗂️ File Types Distribution</div>
                <canvas id="fileTypesChart" width="400" height="200"></canvas>
            </div>
            
            <div class="activity-feed">
                <div class="chart-title">🔄 Recent Activity</div>
                <?php foreach (array_slice($data['recent_activity'], 0, 10) as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-time"><?= date('H:i:s', strtotime(str_replace(['[', ']'], '', $activity['time']))) ?></div>
                        <div class="activity-details">
                            <strong><?= htmlspecialchars($activity['file']) ?></strong>
                            <span class="status-<?= $activity['status'] ?>">[<?= $activity['status'] ?>]</span>
                            from <?= $activity['ip'] ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Unused Files Detailed Analysis Card -->
        <?php if (!empty($unusedFiles)): ?>
        <div class="chart-card full-width-card" style="margin-top: 20px;">
            <div class="chart-title">🚫 Ungenutzte Dateien - Vollständige Analyse (<?= count($unusedFiles) ?> Dateien)</div>
            
            <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <button onclick="hideAllUnusedFiles()" style="
                    background: #ff9800;
                    border: none;
                    border-radius: 6px;
                    color: white;
                    padding: 8px 16px;
                    cursor: pointer;
                    font-weight: 500;
                    transition: all 0.3s ease;
                " onmouseover="this.style.background='#f57c00'" 
                   onmouseout="this.style.background='#ff9800'">
                    🙈 Alle ausblenden
                </button>
                
                <button onclick="showUnusedFileStats()" style="
                    background: #2196f3;
                    border: none;
                    border-radius: 6px;
                    color: white;
                    padding: 8px 16px;
                    cursor: pointer;
                    font-weight: 500;
                    transition: all 0.3s ease;
                " onmouseover="this.style.background='#1976d2'" 
                   onmouseout="this.style.background='#2196f3'">
                    📊 Statistiken
                </button>
                
                <select id="unusedFileFilter" onchange="filterUnusedFiles()" style="
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    background: white;
                ">
                    <option value="all">Alle Dateitypen</option>
                    <option value="php">Nur PHP</option>
                    <option value="html">Nur HTML</option>
                    <option value="js">Nur JavaScript</option>
                    <option value="css">Nur CSS</option>
                </select>
                
                <span style="color: #666; font-size: 0.9em;">
                    Gesamtgröße: <?php 
                        $totalUnusedSize = array_sum(array_column($unusedFiles, 'size'));
                        echo $totalUnusedSize > 1024*1024 ? 
                            number_format($totalUnusedSize/(1024*1024), 2).' MB' : 
                            number_format($totalUnusedSize/1024, 1).' KB';
                    ?>
                </span>
            </div>

            <!-- Statistics Summary -->
            <div id="unusedStats" style="display: none; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; text-align: center;">
                    <?php 
                    $extensionCounts = [];
                    $oldFiles = 0;
                    foreach ($unusedFiles as $file) {
                        $ext = $file['extension'] ?: 'none';
                        $extensionCounts[$ext] = ($extensionCounts[$ext] ?? 0) + 1;
                        if (time() - $file['modified'] > 90*24*60*60) $oldFiles++; // 90 Tage
                    }
                    ?>
                    <div>
                        <div style="font-size: 1.5em; color: #e74c3c; font-weight: bold;"><?= count($unusedFiles) ?></div>
                        <div style="font-size: 0.9em; color: #666;">Gesamt</div>
                    </div>
                    <?php foreach ($extensionCounts as $ext => $count): ?>
                    <div>
                        <div style="font-size: 1.2em; color: #34495e; font-weight: bold;"><?= $count ?></div>
                        <div style="font-size: 0.9em; color: #666;">.<?= $ext ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div>
                        <div style="font-size: 1.2em; color: #f39c12; font-weight: bold;"><?= $oldFiles ?></div>
                        <div style="font-size: 0.9em; color: #666;">&gt;90 Tage alt</div>
                    </div>
                </div>
            </div>

            <!-- File List -->
            <div style="max-height: 500px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 8px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9em;">
                    <thead style="background: #f5f5f5; position: sticky; top: 0;">
                        <tr>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">📁 Datei</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">📏 Größe</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">📅 Geändert</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">📂 Ordner</th>
                            <th style="padding: 12px; text-align: center; border-bottom: 1px solid #ddd;">⚙️ Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unusedFiles as $index => $file): ?>
                        <tr class="unused-file-row" data-extension="<?= $file['extension'] ?>" 
                            style="border-bottom: 1px solid #f0f0f0; <?= $index % 2 == 0 ? 'background: #fafafa;' : '' ?>">
                            <td style="padding: 10px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="
                                        background: <?= 
                                            $file['extension'] === 'php' ? '#8e44ad' : 
                                            ($file['extension'] === 'html' ? '#e67e22' : 
                                            ($file['extension'] === 'js' ? '#f1c40f' : 
                                            ($file['extension'] === 'css' ? '#3498db' : '#95a5a6'))) ?>;
                                        color: white;
                                        padding: 2px 6px;
                                        border-radius: 3px;
                                        font-size: 0.8em;
                                        font-weight: bold;
                                    "><?= strtoupper($file['extension']) ?></span>
                                    <code style="color: #2c3e50;"><?= htmlspecialchars($file['name']) ?></code>
                                </div>
                            </td>
                            <td style="padding: 10px; color: #666;">
                                <?= $file['size'] > 1024 ? 
                                    ($file['size'] > 1024*1024 ? 
                                        number_format($file['size']/(1024*1024), 2).' MB' : 
                                        number_format($file['size']/1024, 1).' KB') : 
                                    $file['size'].' B' ?>
                            </td>
                            <td style="padding: 10px; color: #666;">
                                <?php 
                                $days = floor((time() - $file['modified']) / (24*60*60));
                                $color = $days > 90 ? '#e74c3c' : ($days > 30 ? '#f39c12' : '#27ae60');
                                ?>
                                <span style="color: <?= $color ?>;">
                                    <?= $days ?> Tage
                                </span>
                            </td>
                            <td style="padding: 10px; color: #666;">
                                <?= $file['directory'] === '.' ? 'Root' : htmlspecialchars($file['directory']) ?>
                            </td>
                            <td style="padding: 10px; text-align: center;">
                                <div style="display: flex; gap: 5px; justify-content: center;">
                                    <button onclick="toggleFileVisibility('<?= htmlspecialchars($file['name']) ?>')" 
                                            title="Aus Statistiken ausblenden" style="
                                        background: #95a5a6;
                                        border: none;
                                        border-radius: 4px;
                                        color: white;
                                        padding: 4px 8px;
                                        cursor: pointer;
                                        font-size: 0.8em;
                                    ">👁️</button>
                                    <button onclick="viewFile('<?= htmlspecialchars($file['name']) ?>')" 
                                            title="Datei anzeigen" style="
                                        background: #3498db;
                                        border: none;
                                        border-radius: 4px;
                                        color: white;
                                        padding: 4px 8px;
                                        cursor: pointer;
                                        font-size: 0.8em;
                                    ">👀</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 6px; font-size: 0.9em;">
                <strong>💡 Hinweis:</strong> Dies sind Dateien, die in den Access-Logs keine Requests haben. 
                Sie könnten veraltet sein oder nur intern/administrativ verwendet werden.
            </div>
        </div>
        <?php endif; ?>
        
        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-title">🗂️ File Types Distribution</div>
                <canvas id="fileTypesChart" width="400" height="200"></canvas>
            </div>
            
            <div class="activity-feed">
                <div class="chart-title">🔄 Recent Activity</div>
                <?php foreach (array_slice($data['recent_activity'], 0, 10) as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-time"><?= date('H:i:s', strtotime(str_replace(['[', ']'], '', $activity['time']))) ?></div>
                        <div class="activity-details">
                            <strong><?= htmlspecialchars($activity['file']) ?></strong>
                            <span class="status-<?= $activity['status'] ?>">[<?= $activity['status'] ?>]</span>
                            from <?= $activity['ip'] ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <?php if (!empty($unusedFiles)): ?>
        <div class="unused-files">
            <div class="chart-title">⚠️ Potentially Unused Files (<?= count($unusedFiles) ?>)</div>
            <div style="margin-bottom: 15px;">
                <button onclick="hideAllUnusedFiles()" style="
                    background: #ff9800;
                    border: none;
                    border-radius: 20px;
                    color: white;
                    padding: 8px 15px;
                    cursor: pointer;
                    margin-right: 10px;
                    transition: all 0.3s ease;
                ">🙈 Alle unused Files ausblenden</button>
                <span style="font-size: 0.8em; color: #666;">
                    Bulk-Action: Blendet alle ungenutzten Files aus den Statistiken aus
                </span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">
                <?php foreach (array_slice($unusedFiles, 0, 20) as $file): ?>
                    <div class="unused-item">
                        <?= htmlspecialchars($file) ?>
                        <button class="mini-hide-btn" onclick="toggleFileVisibility('<?= htmlspecialchars($file) ?>')" 
                                title="Ausblenden">👁️</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($unusedFiles) > 20): ?>
                <div style="margin-top: 15px; color: #666; font-style: italic;">
                    ... and <?= count($unusedFiles) - 20 ?> more files
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <button class="refresh-btn" onclick="window.location.reload()">🔄 Refresh</button>
    
    <script>
        // Hourly Chart
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        const hourlyChart = new Chart(hourlyCtx, {
            type: 'line',
            data: {
                labels: Array.from({length: 24}, (_, i) => i + ':00'),
                datasets: [{
                    label: 'Requests per Hour',
                    data: <?= json_encode(array_values($data['hourly_stats'])) ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        
        // File Types Chart
        const fileTypesCtx = document.getElementById('fileTypesChart').getContext('2d');
        const fileTypesData = <?= json_encode($data['file_types']) ?>;
        const fileTypesChart = new Chart(fileTypesCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(fileTypesData),
                datasets: [{
                    data: Object.values(fileTypesData),
                    backgroundColor: [
                        '#667eea',
                        '#764ba2', 
                        '#f093fb',
                        '#4facfe',
                        '#43e97b',
                        '#38f9d7',
                        '#ffecd2',
                        '#fcb69f'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            window.location.reload();
        }, 30000);
        
        // Filter Toggle Function
        function toggleSystemFilter() {
            const isChecked = document.getElementById('filterToggle').checked;
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('filter', isChecked);
            window.location.href = currentUrl.toString();
        }
        
        // Advanced Filter Presets
        function applyFilterPreset() {
            const preset = document.getElementById('filterPreset').value;
            const currentUrl = new URL(window.location);
            
            switch(preset) {
                case 'all':
                    currentUrl.searchParams.set('filter', 'false');
                    break;
                case 'business':
                    currentUrl.searchParams.set('filter', 'true');
                    break;
                case 'user_only':
                    currentUrl.searchParams.set('filter', 'user_only');
                    break;
                case 'errors_only':
                    currentUrl.searchParams.set('filter', 'errors_only');
                    break;
            }
            
            window.location.href = currentUrl.toString();
        }
        
        // Individual File Visibility Toggle
        function toggleFileVisibility(filename) {
            const currentUrl = new URL(window.location);
            let hiddenFiles = currentUrl.searchParams.get('hidden');
            hiddenFiles = hiddenFiles ? hiddenFiles.split(',') : [];
            
            const fileIndex = hiddenFiles.indexOf(filename);
            
            if (fileIndex > -1) {
                // File ist hidden -> wieder anzeigen
                hiddenFiles.splice(fileIndex, 1);
            } else {
                // File ist sichtbar -> ausblenden
                hiddenFiles.push(filename);
            }
            
            // URL Parameter aktualisieren
            if (hiddenFiles.length > 0) {
                currentUrl.searchParams.set('hidden', hiddenFiles.join(','));
            } else {
                currentUrl.searchParams.delete('hidden');
            }
            
            // Seite neu laden
            window.location.href = currentUrl.toString();
        }
        
        // Clear All Hidden Files
        function clearAllHiddenFiles() {
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.delete('hidden');
            window.location.href = currentUrl.toString();
        }
        
        // Hide All Unused Files (Bulk Action)
        function hideAllUnusedFiles() {
            const unusedFiles = <?= json_encode(array_column($unusedFiles, 'name')) ?>;
            const currentUrl = new URL(window.location);
            let hiddenFiles = currentUrl.searchParams.get('hidden');
            hiddenFiles = hiddenFiles ? hiddenFiles.split(',') : [];
            
            // Alle unused files zur Hidden-Liste hinzufügen
            unusedFiles.forEach(file => {
                if (!hiddenFiles.includes(file)) {
                    hiddenFiles.push(file);
                }
            });
            
            currentUrl.searchParams.set('hidden', hiddenFiles.join(','));
            window.location.href = currentUrl.toString();
        }
        
        // Show/Hide Unused File Statistics
        function showUnusedFileStats() {
            const statsDiv = document.getElementById('unusedStats');
            if (statsDiv.style.display === 'none' || statsDiv.style.display === '') {
                statsDiv.style.display = 'block';
            } else {
                statsDiv.style.display = 'none';
            }
        }
        
        // Filter Unused Files by Extension
        function filterUnusedFiles() {
            const filter = document.getElementById('unusedFileFilter').value;
            const rows = document.querySelectorAll('.unused-file-row');
            
            rows.forEach(row => {
                const extension = row.getAttribute('data-extension');
                if (filter === 'all' || filter === extension) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // View File Content (opens in new tab)
        function viewFile(filename) {
            window.open(filename, '_blank');
        }
    </script>
</body>
</html>
