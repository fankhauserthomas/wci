<?php
// WCI Simple Access Dashboard - Fokus auf Dateizugriffe

// Anti-Cache Headers - Verhindert Browser-Caching
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

// Papierkorb-Funktion (Einzeldatei)
if (isset($_POST['move_to_trash']) && isset($_POST['filename'])) {
    // Basis-Verzeichnis bestimmen
    $baseDir = '/var/www/html/wci';
    if (!is_dir($baseDir)) {
        $baseDir = '/home/vadmin/lemp/html/wci';
    }
    if (!is_dir($baseDir)) {
        $baseDir = __DIR__;
    }
    
    $trashDir = $baseDir . '/trash';
    if (!is_dir($trashDir)) {
        mkdir($trashDir, 0755, true);
    }
    
    $filename = basename($_POST['filename']);
    $sourcePath = $baseDir . '/' . $filename;
    $trashPath = $trashDir . '/' . $filename;
    
    if (file_exists($sourcePath) && !file_exists($trashPath)) {
        if (rename($sourcePath, $trashPath)) {
            $moveMessage = "✅ Datei '$filename' in Papierkorb verschoben";
        } else {
            $moveMessage = "❌ Fehler beim Verschieben von '$filename'";
        }
    }
}

// Bulk-Papierkorb-Funktion
if (isset($_POST['bulk_move_to_trash'])) {
    $selectedFiles = json_decode($_POST['bulk_move_to_trash'], true);
    
    // Basis-Verzeichnis bestimmen
    $baseDir = '/var/www/html/wci';
    if (!is_dir($baseDir)) {
        $baseDir = '/home/vadmin/lemp/html/wci';
    }
    if (!is_dir($baseDir)) {
        $baseDir = __DIR__;
    }
    
    $trashDir = $baseDir . '/trash';
    if (!is_dir($trashDir)) {
        mkdir($trashDir, 0755, true);
    }
    
    $movedCount = 0;
    $errorFiles = [];
    
    foreach ($selectedFiles as $filename) {
        $filename = basename($filename);
        $sourcePath = $baseDir . '/' . $filename;
        $trashPath = $trashDir . '/' . $filename;
        
        if (file_exists($sourcePath) && !file_exists($trashPath)) {
            if (rename($sourcePath, $trashPath)) {
                $movedCount++;
            } else {
                $errorFiles[] = $filename;
            }
        }
    }
    
    if ($movedCount > 0) {
        $moveMessage = "✅ $movedCount Datei(en) in Papierkorb verschoben";
        if (!empty($errorFiles)) {
            $moveMessage .= " | ❌ Fehler bei: " . implode(', ', $errorFiles);
        }
    } else {
        $moveMessage = "❌ Keine Dateien konnten verschoben werden";
    }
}

// Filter-Optionen
$showTrash = isset($_GET['show_trash']) ? $_GET['show_trash'] === 'true' : false;
$filterSystemFiles = isset($_GET['filter']) ? $_GET['filter'] === 'true' : true;

$systemFiles = [
    'ping.php', 'sync_matrix.php', 'syncTrigger.php', 'checkAuth-simple.php',
    'checkAuth.php', 'api-access-stats.php', 'access-widget.php', 'access-dashboard.php'
];

// Log-Datei analysieren
$logFile = '/home/vadmin/lemp/logs/apache2/access.log';
if (!file_exists($logFile)) {
    $logFile = '../../logs/apache2/access.log';
}
if (!file_exists($logFile)) {
    $logFile = '/var/log/apache2/access.log';
}

$fileStats = [];

// Speicher-effiziente Log-Verarbeitung
ini_set('memory_limit', '256M');

if (file_exists($logFile)) {
    $fileSize = filesize($logFile);
    if ($fileSize > 50 * 1024 * 1024) {
        $output = shell_exec("tail -n 10000 " . escapeshellarg($logFile));
        $lines = $output ? explode("\n", trim($output)) : [];
    } else {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) $lines = [];
    }
    
    foreach ($lines as $line) {
        if (empty($line)) continue;
        
        if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"\s]+)[^"]*" (\d+) (\d+)/', $line, $matches)) {
            $ip = $matches[1];
            $datetime = $matches[2];
            $method = $matches[3];
            $url = $matches[4];
            $status = intval($matches[5]);
            $bytes = intval($matches[6]);
            
            $file = parse_url($url, PHP_URL_PATH);
            if ($file && $file !== '/') {
                $file = str_replace('/wci/', '', $file);
                $filename = basename($file);
                
                if (!empty($filename) && $filename !== '/') {
                    if (!isset($fileStats[$filename])) {
                        $fileStats[$filename] = [
                            'requests' => 0,
                            'last_access' => $datetime,
                            'first_access' => $datetime,
                            'ips' => [],
                            'methods' => [],
                            'errors' => 0,
                            'total_bytes' => 0
                        ];
                    }
                    
                    $fileStats[$filename]['requests']++;
                    $fileStats[$filename]['last_access'] = $datetime;
                    $fileStats[$filename]['ips'][$ip] = true;
                    $fileStats[$filename]['methods'][$method] = true;
                    $fileStats[$filename]['total_bytes'] += $bytes;
                    
                    if ($status >= 400) {
                        $fileStats[$filename]['errors']++;
                    }
                }
            }
        }
    }
}

// Rekursive Funktion zum Scannen aller Dateien
function scanDirectoryRecursive($dir, $baseDir = '') {
    $files = [];
    if (!is_dir($dir)) return $files;
    
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $fullPath = $dir . '/' . $item;
        $relativePath = $baseDir ? $baseDir . '/' . $item : $item;
        
        // Versteckte Ordner und .git ausschließen
        if ($item[0] === '.' || $item === '.git') {
            continue;
        }
        
        if (is_file($fullPath)) {
            $files[] = [
                'name' => $item,
                'path' => $relativePath,
                'full_path' => $fullPath,
                'size' => filesize($fullPath),
                'modified' => filemtime($fullPath)
            ];
        } elseif (is_dir($fullPath) && $item !== 'trash') {
            // Rekursiv in Unterverzeichnisse, aber Papierkorb ausschließen
            $subFiles = scanDirectoryRecursive($fullPath, $relativePath);
            $files = array_merge($files, $subFiles);
        }
    }
    return $files;
}

// Dateisystem-Informationen sammeln - Korrekter Pfad für Docker Setup
$baseDir = '/var/www/html/wci';
// Fallback für verschiedene Umgebungen
if (!is_dir($baseDir)) {
    $baseDir = '/home/vadmin/lemp/html/wci';
}
if (!is_dir($baseDir)) {
    $baseDir = __DIR__; // Aktuelles Verzeichnis als Fallback
}

$trashDir = $baseDir . '/trash';
$allFiles = scanDirectoryRecursive($baseDir);
$trashFiles = is_dir($trashDir) ? array_diff(scandir($trashDir), ['.', '..']) : [];

// Datei-Informationen erweitern mit allen gefundenen Dateien
foreach ($allFiles as $fileInfo) {
    $filename = $fileInfo['name'];
    $relativePath = $fileInfo['path'];
    
    if (!isset($fileStats[$filename])) {
        $fileStats[$filename] = [
            'requests' => 0,
            'last_access' => null,
            'first_access' => null,
            'ips' => [],
            'methods' => [],
            'errors' => 0,
            'total_bytes' => 0
        ];
    }
    
    $fileStats[$filename]['file_size'] = $fileInfo['size'];
    $fileStats[$filename]['last_modified'] = $fileInfo['modified'];
    $fileStats[$filename]['exists'] = true;
    $fileStats[$filename]['relative_path'] = $relativePath;
    $fileStats[$filename]['full_path'] = $fileInfo['full_path'];
}

// Papierkorb-Dateien hinzufügen
foreach ($trashFiles as $file) {
    $filePath = $trashDir . '/' . $file;
    if (is_file($filePath)) {
        if (!isset($fileStats[$file])) {
            $fileStats[$file] = [
                'requests' => 0,
                'last_access' => null,
                'first_access' => null,
                'ips' => [],
                'methods' => [],
                'errors' => 0,
                'total_bytes' => 0
            ];
        }
        
        $fileStats[$file]['file_size'] = filesize($filePath);
        $fileStats[$file]['last_modified'] = filemtime($filePath);
        $fileStats[$file]['in_trash'] = true;
    }
}

// Sortieren nach letztem Zugriff
uasort($fileStats, function($a, $b) {
    $aTime = $a['last_access'] ? strtotime(str_replace(['[', ']'], '', $a['last_access'])) : 0;
    $bTime = $b['last_access'] ? strtotime(str_replace(['[', ']'], '', $b['last_access'])) : 0;
    return $bTime - $aTime;
});
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WCI Dateizugriff Dashboard</title>
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
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 2.2em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }
        
        .controls {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .controls label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        
        .controls input[type="checkbox"] {
            transform: scale(1.2);
        }
        
        .stats-summary {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            text-align: center;
        }
        
        .stat-item {
            border-left: 4px solid #667eea;
            padding-left: 15px;
        }
        
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }
        
        .file-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        
        .table-header {
            background: #667eea;
            color: white;
            padding: 20px;
            font-size: 1.2em;
            font-weight: bold;
        }
        
        .table-container {
            max-height: 600px;
            overflow-y: auto;
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            position: relative;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85em;
        }
        
        th, td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-bottom: 2px solid #667eea;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .file-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .file-link:hover {
            text-decoration: underline;
        }
        
        .trash-btn {
            background: #e53e3e;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8em;
        }
        
        .trash-btn:hover {
            background: #c53030;
        }
        
        .trash-file {
            background: #fff5f5 !important;
            opacity: 0.7;
        }
        
        .system-file {
            background: #f0f8ff !important;
        }
        
        .no-access {
            color: #999;
            font-style: italic;
        }
        
        .message {
            background: #d4edda;
            color: #155724;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        .access-count {
            font-weight: bold;
            color: #28a745;
        }
        
        .error-count {
            color: #dc3545;
            font-weight: bold;
        }
        
        .file-size {
            color: #666;
            font-size: 0.9em;
        }
        
        .unique-users {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.8em;
            font-weight: bold;
        }
        
        /* Sortierbare Tabelle */
        .sort-indicator {
            font-size: 0.8em;
            opacity: 0.5;
            margin-left: 5px;
        }
        
        th[onclick] {
            user-select: none;
            position: relative;
        }
        
        th[onclick]:hover {
            background: #e8eaf6 !important;
        }
        
        .sort-asc .sort-indicator::after {
            content: ' ↑';
            color: #667eea;
            font-weight: bold;
        }
        
        .sort-desc .sort-indicator::after {
            content: ' ↓';
            color: #667eea;
            font-weight: bold;
        }
        
        /* Bulk Actions */
        .bulk-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8em;
            margin-left: 5px;
        }
        
        .bulk-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .bulk-btn.trash-btn {
            background: #e53e3e;
        }
        
        .bulk-btn.trash-btn:hover {
            background: #c53030;
        }
        
        .file-checkbox {
            transform: scale(1.1);
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="header">
            <h1>📁 WCI Dateizugriff Dashboard</h1>
            <p>Zugriffstatistiken und Dateiverwaltung</p>
        </div>
        
        <?php if (isset($moveMessage)): ?>
            <div class="message <?= strpos($moveMessage, '❌') !== false ? 'error-message' : '' ?>">
                <?= $moveMessage ?>
            </div>
        <?php endif; ?>
        
        <div class="controls">
            <label>
                <input type="checkbox" id="filterSystem" <?= $filterSystemFiles ? 'checked' : '' ?>>
                System-Dateien ausblenden
            </label>
            <label>
                <input type="checkbox" id="showTrash" <?= $showTrash ? 'checked' : '' ?>>
                Papierkorb anzeigen
            </label>
            <button onclick="window.location.reload()" style="background: #667eea; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer;">
                🔄 Aktualisieren
            </button>
        </div>
        
        <div class="stats-summary">
            <div class="stat-item">
                <div class="stat-value"><?= count($fileStats) ?></div>
                <div class="stat-label">Dateien gesamt</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count(array_filter($fileStats, function($f) { return $f['requests'] > 0; })) ?></div>
                <div class="stat-label">Mit Zugriffen</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= count($trashFiles) ?></div>
                <div class="stat-label">Im Papierkorb</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?= array_sum(array_column($fileStats, 'requests')) ?></div>
                <div class="stat-label">Zugriffe gesamt</div>
            </div>
        </div>
        
        <div class="file-table">
            <div class="table-header">
                📊 Dateizugriff Statistiken
                <div style="float: right; display: flex; gap: 10px; align-items: center;">
                    <button onclick="selectAll()" class="bulk-btn">✓ Alle</button>
                    <button onclick="selectNone()" class="bulk-btn">✗ Keine</button>
                    <button onclick="bulkMoveToTrash()" class="bulk-btn trash-btn">🗑️ Auswahl löschen</button>
                </div>
            </div>
            <div class="table-container">
                <table id="fileTable">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll(this)">
                            </th>
                            <th onclick="sortTable(1)" style="cursor: pointer;">
                                📁 Datei <span class="sort-indicator">↕️</span>
                            </th>
                            <th onclick="sortTable(2)" style="cursor: pointer;">
                                📊 Zugriffe <span class="sort-indicator">↕️</span>
                            </th>
                            <th onclick="sortTable(3)" style="cursor: pointer;">
                                👥 Benutzer <span class="sort-indicator">↕️</span>
                            </th>
                            <th onclick="sortTable(4)" style="cursor: pointer;">
                                🕒 Letzter Zugriff <span class="sort-indicator">↕️</span>
                            </th>
                            <th onclick="sortTable(5)" style="cursor: pointer;">
                                📝 Letzte Änderung <span class="sort-indicator">↕️</span>
                            </th>
                            <th onclick="sortTable(6)" style="cursor: pointer;">
                                💾 Größe <span class="sort-indicator">↕️</span>
                            </th>
                            <th onclick="sortTable(7)" style="cursor: pointer;">
                                ❌ Fehler <span class="sort-indicator">↕️</span>
                            </th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fileStats as $filename => $stats): 
                            $isSystemFile = in_array($filename, $systemFiles);
                            $isTrashFile = isset($stats['in_trash']);
                            
                            // Filter anwenden
                            if ($filterSystemFiles && $isSystemFile) continue;
                            if (!$showTrash && $isTrashFile) continue;
                            
                            $rowClass = '';
                            if ($isTrashFile) $rowClass = 'trash-file';
                            elseif ($isSystemFile) $rowClass = 'system-file';
                        ?>
                            <tr class="<?= $rowClass ?>">
                                <td>
                                    <?php if (!$isTrashFile && isset($stats['exists'])): ?>
                                        <input type="checkbox" class="file-checkbox" value="<?= htmlspecialchars($filename) ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isTrashFile): ?>
                                        🗑️ <span title="Im Papierkorb"><?= htmlspecialchars($filename) ?></span>
                                    <?php elseif (isset($stats['exists'])): ?>
                                        <a href="<?= htmlspecialchars(isset($stats['relative_path']) ? $stats['relative_path'] : $filename) ?>" 
                                           class="file-link" target="_blank" 
                                           title="<?= htmlspecialchars(isset($stats['relative_path']) ? $stats['relative_path'] : $filename) ?>">
                                            <?php if (isset($stats['relative_path']) && strpos($stats['relative_path'], '/') !== false): ?>
                                                📁 <?= htmlspecialchars($filename) ?>
                                                <small style="color: #666; display: block; font-size: 0.8em;">
                                                    <?= htmlspecialchars(dirname($stats['relative_path'])) ?>/
                                                </small>
                                            <?php else: ?>
                                                <?= htmlspecialchars($filename) ?>
                                            <?php endif; ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="no-access" title="Datei in den Logs gefunden, aber nicht im Dateisystem">
                                            ❓ <?= htmlspecialchars($filename) ?> <small>(nur in Logs)</small>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="access-count"><?= number_format($stats['requests']) ?></span>
                                </td>
                                <td>
                                    <?php if (count($stats['ips']) > 0): ?>
                                        <span class="unique-users"><?= count($stats['ips']) ?></span>
                                    <?php else: ?>
                                        <span class="no-access">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($stats['last_access']): ?>
                                        <?= date('d.m.Y H:i:s', strtotime(str_replace(['[', ']'], '', $stats['last_access']))) ?>
                                    <?php else: ?>
                                        <span class="no-access">Nie</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($stats['last_modified'])): ?>
                                        <?= date('d.m.Y H:i:s', $stats['last_modified']) ?>
                                    <?php else: ?>
                                        <span class="no-access">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="file-size">
                                    <?php if (isset($stats['file_size'])): ?>
                                        <?= $stats['file_size'] > 1024*1024 ? 
                                            round($stats['file_size']/(1024*1024), 1).'MB' : 
                                            ($stats['file_size'] > 1024 ? 
                                                round($stats['file_size']/1024, 1).'KB' : 
                                                $stats['file_size'].'B') ?>
                                    <?php else: ?>
                                        <span class="no-access">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($stats['errors'] > 0): ?>
                                        <span class="error-count"><?= $stats['errors'] ?></span>
                                    <?php else: ?>
                                        <span style="color: #28a745;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($stats['exists']) && !$isTrashFile): ?>
                                        <form method="post" style="display: inline;" 
                                              onsubmit="return confirm('Datei <?= htmlspecialchars($filename) ?> in Papierkorb verschieben?')">
                                            <input type="hidden" name="filename" value="<?= htmlspecialchars($filename) ?>">
                                            <button type="submit" name="move_to_trash" class="trash-btn">
                                                🗑️ Papierkorb
                                            </button>
                                        </form>
                                    <?php elseif ($isTrashFile): ?>
                                        <span style="color: #999; font-style: italic;">Im Papierkorb</span>
                                    <?php else: ?>
                                        <span class="no-access">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Filter-Controls
        document.getElementById('filterSystem').addEventListener('change', function() {
            updateURL();
        });
        
        document.getElementById('showTrash').addEventListener('change', function() {
            updateURL();
        });
        
        function updateURL() {
            const filterSystem = document.getElementById('filterSystem').checked;
            const showTrash = document.getElementById('showTrash').checked;
            
            const params = new URLSearchParams();
            params.set('filter', filterSystem.toString());
            params.set('show_trash', showTrash.toString());
            
            window.location.href = '?' + params.toString();
        }
        
        // Tabellen-Sortierung
        let currentSort = { column: -1, direction: 'asc' };
        
        function sortTable(columnIndex) {
            const table = document.getElementById('fileTable');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const header = table.querySelectorAll('th')[columnIndex];
            
            // Reset all other headers
            table.querySelectorAll('th').forEach(th => {
                th.classList.remove('sort-asc', 'sort-desc');
            });
            
            // Bestimme Sortierrichtung
            if (currentSort.column === columnIndex) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.direction = 'asc';
            }
            currentSort.column = columnIndex;
            
            // Sortiere Zeilen
            rows.sort((a, b) => {
                let aVal = getCellValue(a, columnIndex);
                let bVal = getCellValue(b, columnIndex);
                
                // Spezielle Behandlung für verschiedene Datentypen
                if (columnIndex === 1 || columnIndex === 2 || columnIndex === 6) { // Zahlen
                    aVal = parseInt(aVal.replace(/[^\d]/g, '')) || 0;
                    bVal = parseInt(bVal.replace(/[^\d]/g, '')) || 0;
                } else if (columnIndex === 3 || columnIndex === 4) { // Datums
                    aVal = aVal === 'Nie' || aVal === '-' ? 0 : new Date(aVal).getTime();
                    bVal = bVal === 'Nie' || bVal === '-' ? 0 : new Date(bVal).getTime();
                } else if (columnIndex === 5) { // Dateigröße
                    aVal = parseFileSize(aVal);
                    bVal = parseFileSize(bVal);
                }
                
                if (currentSort.direction === 'asc') {
                    return aVal > bVal ? 1 : -1;
                } else {
                    return aVal < bVal ? 1 : -1;
                }
            });
            
            // Zeilen neu einfügen
            rows.forEach(row => tbody.appendChild(row));
            
            // Header-Indikator aktualisieren
            header.classList.add(currentSort.direction === 'asc' ? 'sort-asc' : 'sort-desc');
        }
        
        function getCellValue(row, columnIndex) {
            const cell = row.querySelectorAll('td')[columnIndex];
            return cell ? cell.textContent.trim() : '';
        }
        
        function parseFileSize(sizeStr) {
            if (sizeStr === '-' || !sizeStr) return 0;
            
            const num = parseFloat(sizeStr);
            if (sizeStr.includes('MB')) return num * 1024 * 1024;
            if (sizeStr.includes('KB')) return num * 1024;
            return num;
        }
        
        // Bulk Actions
        function toggleAll(masterCheckbox) {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = masterCheckbox.checked;
            });
        }
        
        function selectAll() {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            document.getElementById('selectAllCheckbox').checked = true;
        }
        
        function selectNone() {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('selectAllCheckbox').checked = false;
        }
        
        function bulkMoveToTrash() {
            const selectedFiles = [];
            const checkboxes = document.querySelectorAll('.file-checkbox:checked');
            
            checkboxes.forEach(checkbox => {
                selectedFiles.push(checkbox.value);
            });
            
            if (selectedFiles.length === 0) {
                alert('Keine Dateien ausgewählt!');
                return;
            }
            
            if (confirm(`${selectedFiles.length} Datei(en) in den Papierkorb verschieben?\n\n${selectedFiles.join('\n')}`)) {
                // Sende Bulk-Delete Request
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'bulk_move_to_trash';
                input.value = JSON.stringify(selectedFiles);
                
                form.appendChild(input);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
