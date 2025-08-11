<?php
// WCI System Analysis Dashboard mit Netzwerk-Visualisierung
// Grafische Darstellung der Datei-Abhängigkeiten

// API Handler - muss vor allem HTML-Output stehen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Alle Error-Ausgaben unterdrücken für sauberes JSON
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Buffer starten um ungewollte Ausgaben zu verhindern
    ob_start();
    
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'run_analysis':
            $command = './complete-network-analysis.sh';
            $output = [];
            $return_var = 0;
            
            exec($command . ' 2>&1', $output, $return_var);
            
            $success = $return_var === 0;
            
            // Buffer leeren
            ob_clean();
            echo json_encode([
                'success' => $success,
                'output' => implode("\n", $output),
                'return_code' => $return_var
            ]);
            exit;
            
        case 'get_stats':
            $stats = calculateSystemStats();
            
            // Buffer leeren
            ob_clean();
            echo json_encode(['success' => true, 'stats' => $stats]);
            exit;
            
        case 'generate_cleanup_suggestions':
            $suggestions = generateCleanupSuggestions();
            
            // Buffer leeren
            ob_clean();
            echo json_encode(['success' => true, 'suggestions' => $suggestions]);
            exit;
            
        case 'get_network_data':
            $networkData = generateNetworkData();
            
            // Buffer leeren
            ob_clean();
            echo json_encode(['success' => true, 'data' => $networkData]);
            exit;
    }
    
    // Falls unbekannte Action
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

function generateNetworkData() {
    $analysisFile = 'analysis_results.txt';
    if (!file_exists($analysisFile)) {
        return ['nodes' => [], 'links' => []];
    }
    
    $content = file_get_contents($analysisFile);
    $nodes = [];
    $links = [];
    $nodeIndex = 0;
    $fileToIndex = [];
    
    // Parse analysis results to extract dependencies
    $lines = explode("\n", $content);
    $currentFile = null;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Detect file headers - Updated pattern for new format
        if (preg_match('/^FILE: (.+?) \(\d+\/\d+\)$/', $line, $matches)) {
            $currentFile = $matches[1];
            
            // Add node if not exists
            if (!isset($fileToIndex[$currentFile])) {
                $fileType = getFileType($currentFile);
                $nodes[] = [
                    'id' => $nodeIndex,
                    'name' => basename($currentFile), // Show only filename for clarity
                    'fullPath' => $currentFile,
                    'type' => $fileType,
                    'group' => getFileGroup($fileType),
                    'size' => getFileSize($currentFile),
                    'connections' => 0
                ];
                $fileToIndex[$currentFile] = $nodeIndex++;
            }
        }
        
        // Detect PHP includes and requires
        if ($currentFile && (
            preg_match('/📝 Line \d+: (?:require|include)(?:_once)?\s+[\'"]([^\'"]+)[\'"]/', $line, $matches) ||
            preg_match('/📄 Line \d+:.*[\'"]([^\'"]*(\.php|\.html|\.css|\.js))[\'"]/', $line, $matches)
        )) {
            $dependency = $matches[1];
            
            // Clean up dependency path
            $dependency = str_replace(['../', './'], '', $dependency);
            
            // Skip if dependency is the same as current file
            if ($dependency === $currentFile) continue;
            
            // Add dependency node if not exists
            if (!isset($fileToIndex[$dependency])) {
                $fileType = getFileType($dependency);
                $nodes[] = [
                    'id' => $nodeIndex,
                    'name' => basename($dependency),
                    'fullPath' => $dependency,
                    'type' => $fileType,
                    'group' => getFileGroup($fileType),
                    'size' => getFileSize($dependency),
                    'connections' => 0
                ];
                $fileToIndex[$dependency] = $nodeIndex++;
            }
            
            // Add link
            $sourceIndex = $fileToIndex[$currentFile];
            $targetIndex = $fileToIndex[$dependency];
            
            // Avoid duplicate links
            $linkExists = false;
            foreach ($links as $existingLink) {
                if ($existingLink['source'] === $sourceIndex && $existingLink['target'] === $targetIndex) {
                    $linkExists = true;
                    break;
                }
            }
            
            if (!$linkExists) {
                $links[] = [
                    'source' => $sourceIndex,
                    'target' => $targetIndex,
                    'type' => 'dependency'
                ];
                
                // Increment connection counts
                $nodes[$sourceIndex]['connections']++;
                $nodes[$targetIndex]['connections']++;
            }
        }
    }
    
    return ['nodes' => $nodes, 'links' => $links];
}

function getFileType($filename) {
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    switch (strtolower($ext)) {
        case 'php': return 'php';
        case 'html': case 'htm': return 'html';
        case 'js': return 'js';
        case 'css': return 'css';
        case 'sql': return 'sql';
        default: return 'other';
    }
}

function getFileGroup($fileType) {
    $groups = [
        'php' => 1,
        'html' => 2,
        'js' => 3,
        'css' => 4,
        'sql' => 5,
        'other' => 6
    ];
    return $groups[$fileType] ?? 6;
}

function getFileSize($filename) {
    // Bereinige den Pfad
    $filename = str_replace(['../', './'], '', $filename);
    
    if (file_exists($filename) && is_file($filename)) {
        return filesize($filename);
    }
    
    // Versuche verschiedene Pfade
    $possiblePaths = [
        $filename,
        './' . $filename,
        basename($filename)
    ];
    
    foreach ($possiblePaths as $path) {
        if (file_exists($path) && is_file($path)) {
            return filesize($path);
        }
    }
    
    return 1000; // Default size
}

function calculateSystemStats() {
    $files = glob('*.{php,html,htm,js,css,sql,txt,md}', GLOB_BRACE);
    
    $stats = [
        'total_files' => count($files),
        'php_files' => count(glob('*.php')),
        'html_files' => count(glob('*.{html,htm}', GLOB_BRACE)),
        'js_files' => count(glob('*.js')),
        'css_files' => count(glob('*.css')),
        'total_size' => 0,
        'last_modified' => 0
    ];
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $stats['total_size'] += filesize($file);
            $stats['last_modified'] = max($stats['last_modified'], filemtime($file));
        }
    }
    
    $stats['total_size'] = formatBytes($stats['total_size']);
    $stats['last_modified'] = date('d.m.Y H:i', $stats['last_modified']);
    
    return $stats;
}

function generateCleanupSuggestions() {
    $analysisFile = 'analysis_results.txt';
    if (!file_exists($analysisFile)) {
        return [];
    }
    
    $suggestions = [
        'orphaned_files' => [],
        'large_files' => [],
        'old_files' => [],
        'duplicate_patterns' => []
    ];
    
    $files = glob('*.{php,html,htm,js,css,sql,txt,md}', GLOB_BRACE);
    $currentTime = time();
    
    foreach ($files as $file) {
        if (!is_file($file)) continue;
        
        $fileSize = filesize($file);
        $fileAge = $currentTime - filemtime($file);
        
        // Large files (> 100KB)
        if ($fileSize > 100000) {
            $suggestions['large_files'][] = [
                'file' => $file,
                'size' => formatBytes($fileSize),
                'reason' => 'Große Datei könnte optimiert werden'
            ];
        }
        
        // Old files (> 1 year)
        if ($fileAge > 365 * 24 * 3600) {
            $suggestions['old_files'][] = [
                'file' => $file,
                'age' => date('d.m.Y', filemtime($file)),
                'reason' => 'Sehr alte Datei, möglicherweise veraltet'
            ];
        }
    }
    
    return $suggestions;
}

function formatBytes($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $unit = 0;
    while ($size > 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, 2) . ' ' . $units[$unit];
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WCI System Analysis - Netzwerk Visualisierung</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .header h1 {
            font-size: 2.2em;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header p {
            color: #666;
            font-size: 1.1em;
        }
        
        .back-button {
            display: inline-block;
            background: #4CAF50;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .back-button:hover {
            background: #45a049;
            text-decoration: none;
            color: white;
        }
        
        .analysis-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .control-panel {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            height: fit-content;
        }
        
        .network-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .btn-analyze {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 15px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: 600;
            width: 100%;
            margin: 15px 0;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn-analyze:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-analyze:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .network-toolbar {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .network-filter {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            font-size: 0.9em;
        }
        
        .network-legend {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8em;
            color: #666;
        }
        
        .node-sample {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .node-sample.php { background: #8892d9; }
        .node-sample.html { background: #e74c3c; }
        .node-sample.js { background: #f1c40f; }
        .node-sample.css { background: #3498db; }
        .node-sample.sql { background: #e67e22; }
        .node-sample.other { background: #95a5a6; }
        
        #networkCanvas {
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fafafa;
            width: 100%;
            max-width: 100%;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #667eea;
        }
        
        .stat-number {
            font-size: 1.5em;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.8em;
            margin-top: 5px;
        }
        
        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 20px;
            color: #666;
        }
        
        .loading-spinner i {
            font-size: 1.2em;
        }
        
        .terminal-output {
            background: #1a1a1a;
            color: #00ff00;
            font-family: 'Courier New', monospace;
            padding: 20px;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
            margin: 20px 0;
            border: 2px solid #333;
        }
        
        .network-info {
            background: #e8f5e8;
            border: 1px solid #c3e6c3;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .node-tooltip {
            position: absolute;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 0.8em;
            pointer-events: none;
            z-index: 1000;
            white-space: nowrap;
        }
        
        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Zurück zum Dashboard
        </a>
        
        <div class="header">
            <h1><i class="fas fa-project-diagram"></i> WCI Netzwerk-Visualisierung</h1>
            <p>Grafische Darstellung der Datei-Abhängigkeiten im WCI System</p>
        </div>
        
        <div class="analysis-grid">
            <div class="control-panel">
                <h3><i class="fas fa-cogs"></i> Analyse-Steuerung</h3>
                
                <button class="btn-analyze" onclick="runNetworkAnalysis()" id="analyzeBtn">
                    <i class="fas fa-play"></i> Netzwerk-Analyse starten
                </button>
                
                <div class="stats-grid" id="statsGrid">
                    <div class="stat-card">
                        <div class="stat-number" id="totalFiles">-</div>
                        <div class="stat-label">Dateien</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="totalNodes">-</div>
                        <div class="stat-label">Knoten</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="totalConnections">-</div>
                        <div class="stat-label">Verbindungen</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="totalSize">-</div>
                        <div class="stat-label">Größe</div>
                    </div>
                </div>
                
                <div class="network-info">
                    <h4><i class="fas fa-info-circle"></i> Netzwerk-Info</h4>
                    <p><strong>Interaktion:</strong></p>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        <li>Ziehen: Knoten verschieben</li>
                        <li>Mausrad: Zoom</li>
                        <li>Hover: Details anzeigen</li>
                        <li>Klick: Knoten fokussieren</li>
                    </ul>
                </div>
                
                <div class="terminal-output" id="terminalOutput">
[SYSTEM] WCI Netzwerk-Visualisierung bereit
[INFO] Vorhandene Analyse erkannt - Daten werden geladen...
                </div>
            </div>
            
            <div class="network-container">
                <h3><i class="fas fa-project-diagram"></i> Abhängigkeits-Netzwerk</h3>
                
                <div class="network-toolbar">
                    <button onclick="resetZoom()" class="btn-analyze" style="width: auto; margin: 0; padding: 8px 15px;">
                        <i class="fas fa-search-minus"></i> Reset Zoom
                    </button>
                    <select id="networkFilter" class="network-filter" onchange="filterNetwork()">
                        <option value="all">Alle Dateien</option>
                        <option value="php">PHP Dateien</option>
                        <option value="html">HTML Dateien</option>
                        <option value="js">JavaScript</option>
                        <option value="css">CSS Dateien</option>
                    </select>
                    <div class="network-legend">
                        <span class="legend-item"><div class="node-sample php"></div> PHP</span>
                        <span class="legend-item"><div class="node-sample html"></div> HTML</span>
                        <span class="legend-item"><div class="node-sample js"></div> JS</span>
                        <span class="legend-item"><div class="node-sample css"></div> CSS</span>
                        <span class="legend-item"><div class="node-sample sql"></div> SQL</span>
                        <span class="legend-item"><div class="node-sample other"></div> Andere</span>
                    </div>
                </div>
                
                <div id="network-loading" class="loading-spinner hidden">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span>Netzwerk wird generiert...</span>
                </div>
                
                <canvas id="networkCanvas" width="800" height="600"></canvas>
            </div>
        </div>
    </div>
    
    <div id="nodeTooltip" class="node-tooltip hidden"></div>

    <script>
        // Globale Variablen für das Netzwerk
        let networkData = { nodes: [], links: [] };
        let filteredData = { nodes: [], links: [] };
        let canvas, ctx;
        let transform = { x: 0, y: 0, scale: 1 };
        let isDragging = false;
        let dragTarget = null;
        let simulation = null;
        
        // Initialisierung
        document.addEventListener('DOMContentLoaded', function() {
            canvas = document.getElementById('networkCanvas');
            ctx = canvas.getContext('2d');
            
            // Canvas-Größe anpassen
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);
            
            // Event Listener
            canvas.addEventListener('mousedown', onMouseDown);
            canvas.addEventListener('mousemove', onMouseMove);
            canvas.addEventListener('mouseup', onMouseUp);
            canvas.addEventListener('wheel', onWheel);
            canvas.addEventListener('click', onCanvasClick);
            
            loadStats();
            addLog('Netzwerk-Visualisierung initialisiert');
            
            // Auto-load network data if analysis exists
            checkForExistingAnalysis();
        });
        
        async function checkForExistingAnalysis() {
            try {
                addLog('Prüfe auf vorhandene Analyse-Daten...');
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_network_data'
                });
                
                const result = await response.json();
                if (result.success && result.data.nodes.length > 0) {
                    networkData = result.data;
                    filteredData = JSON.parse(JSON.stringify(networkData));
                    
                    // Statistiken aktualisieren
                    document.getElementById('totalNodes').textContent = networkData.nodes.length;
                    document.getElementById('totalConnections').textContent = networkData.links.length;
                    
                    addLog(`✅ Vorhandene Analyse geladen: ${networkData.nodes.length} Knoten, ${networkData.links.length} Verbindungen`, 'SUCCESS');
                    
                    // Netzwerk visualisieren
                    initializeNetwork();
                    startSimulation();
                } else {
                    addLog('ℹ️ Keine vorhandene Analyse gefunden', 'INFO');
                    addLog('👆 Klicken Sie "Netzwerk-Analyse starten" um das Netzwerk zu generieren', 'INFO');
                }
            } catch (error) {
                addLog('❌ Fehler beim Prüfen vorhandener Analysen: ' + error.message, 'ERROR');
                addLog('👆 Klicken Sie "Netzwerk-Analyse starten" um das Netzwerk zu generieren', 'INFO');
            }
        }
        
        function resizeCanvas() {
            const container = canvas.parentElement;
            const rect = container.getBoundingClientRect();
            canvas.width = rect.width - 50;
            canvas.height = Math.min(600, window.innerHeight - 200);
            
            if (networkData.nodes.length > 0) {
                drawNetwork();
            }
        }
        
        function addLog(message, type = 'INFO') {
            const output = document.getElementById('terminalOutput');
            const timestamp = new Date().toLocaleTimeString('de-DE', {
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            const logLine = `[${timestamp}] ${type}: ${message}\n`;
            output.textContent += logLine;
            output.scrollTop = output.scrollHeight;
        }
        
        async function loadStats() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_stats'
                });
                
                const data = await response.json();
                if (data.success) {
                    document.getElementById('totalFiles').textContent = data.stats.total_files;
                    document.getElementById('totalSize').textContent = data.stats.total_size;
                }
            } catch (error) {
                addLog('Fehler beim Laden der Statistiken: ' + error.message, 'ERROR');
            }
        }
        
        async function runNetworkAnalysis() {
            const btn = document.getElementById('analyzeBtn');
            const loading = document.getElementById('network-loading');
            
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analysiere...';
            loading.classList.remove('hidden');
            
            addLog('=== STARTE NETZWERK-ANALYSE ===', 'SYSTEM');
            
            try {
                // Schritt 1: System-Analyse durchführen
                addLog('Führe vollständige System-Analyse durch...');
                const analysisResponse = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=run_analysis'
                });
                
                const analysisResult = await analysisResponse.json();
                if (!analysisResult.success) {
                    throw new Error('Analyse-Skript fehlgeschlagen');
                }
                
                addLog('System-Analyse abgeschlossen');
                
                // Schritt 2: Netzwerk-Daten generieren
                addLog('Generiere Netzwerk-Daten...');
                const networkResponse = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_network_data'
                });
                
                const networkResult = await networkResponse.json();
                if (networkResult.success) {
                    networkData = networkResult.data;
                    filteredData = JSON.parse(JSON.stringify(networkData));
                    
                    // Statistiken aktualisieren
                    document.getElementById('totalNodes').textContent = networkData.nodes.length;
                    document.getElementById('totalConnections').textContent = networkData.links.length;
                    
                    addLog(`Netzwerk geladen: ${networkData.nodes.length} Knoten, ${networkData.links.length} Verbindungen`, 'SUCCESS');
                    
                    // Netzwerk visualisieren
                    initializeNetwork();
                    startSimulation();
                } else {
                    throw new Error('Netzwerk-Daten konnten nicht generiert werden');
                }
                
            } catch (error) {
                addLog('FEHLER: ' + error.message, 'ERROR');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-sync"></i> Analyse wiederholen';
                loading.classList.add('hidden');
            }
        }
        
        function initializeNetwork() {
            // Initiale Positionen für Knoten setzen
            const centerX = canvas.width / 2;
            const centerY = canvas.height / 2;
            
            filteredData.nodes.forEach((node, i) => {
                const angle = (i / filteredData.nodes.length) * 2 * Math.PI;
                const radius = Math.min(canvas.width, canvas.height) / 3;
                
                node.x = centerX + Math.cos(angle) * radius;
                node.y = centerY + Math.sin(angle) * radius;
                node.vx = 0;
                node.vy = 0;
            });
            
            drawNetwork();
        }
        
        function startSimulation() {
            if (simulation) {
                clearInterval(simulation);
            }
            
            const alpha = 0.1;
            const iterations = 300;
            let currentIteration = 0;
            
            simulation = setInterval(() => {
                simulationStep();
                drawNetwork();
                
                currentIteration++;
                if (currentIteration >= iterations) {
                    clearInterval(simulation);
                    addLog('Simulation abgeschlossen');
                }
            }, 16); // ~60 FPS
        }
        
        function simulationStep() {
            const nodes = filteredData.nodes;
            const links = filteredData.links;
            
            // Kräfte zurücksetzen
            nodes.forEach(node => {
                node.vx = (node.vx || 0) * 0.9; // Dämpfung
                node.vy = (node.vy || 0) * 0.9;
            });
            
            // Abstoßungskraft zwischen Knoten
            for (let i = 0; i < nodes.length; i++) {
                for (let j = i + 1; j < nodes.length; j++) {
                    const nodeA = nodes[i];
                    const nodeB = nodes[j];
                    
                    const dx = nodeB.x - nodeA.x;
                    const dy = nodeB.y - nodeA.y;
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    
                    if (distance > 0) {
                        const force = 500 / (distance * distance);
                        const fx = (dx / distance) * force;
                        const fy = (dy / distance) * force;
                        
                        nodeA.vx -= fx;
                        nodeA.vy -= fy;
                        nodeB.vx += fx;
                        nodeB.vy += fy;
                    }
                }
            }
            
            // Anziehungskraft für verknüpfte Knoten
            links.forEach(link => {
                const source = nodes[link.source];
                const target = nodes[link.target];
                
                if (source && target) {
                    const dx = target.x - source.x;
                    const dy = target.y - source.y;
                    const distance = Math.sqrt(dx * dx + dy * dy);
                    
                    if (distance > 0) {
                        const force = (distance - 100) * 0.1;
                        const fx = (dx / distance) * force;
                        const fy = (dy / distance) * force;
                        
                        source.vx += fx;
                        source.vy += fy;
                        target.vx -= fx;
                        target.vy -= fy;
                    }
                }
            });
            
            // Positionen aktualisieren
            nodes.forEach(node => {
                node.x += node.vx;
                node.y += node.vy;
                
                // Grenzen
                const margin = 50;
                if (node.x < margin) { node.x = margin; node.vx = 0; }
                if (node.x > canvas.width - margin) { node.x = canvas.width - margin; node.vx = 0; }
                if (node.y < margin) { node.y = margin; node.vy = 0; }
                if (node.y > canvas.height - margin) { node.y = canvas.height - margin; node.vy = 0; }
            });
        }
        
        function drawNetwork() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            ctx.save();
            ctx.translate(transform.x, transform.y);
            ctx.scale(transform.scale, transform.scale);
            
            // Links zeichnen
            ctx.strokeStyle = '#ccc';
            ctx.lineWidth = 1;
            filteredData.links.forEach(link => {
                const source = filteredData.nodes[link.source];
                const target = filteredData.nodes[link.target];
                
                if (source && target) {
                    ctx.beginPath();
                    ctx.moveTo(source.x, source.y);
                    ctx.lineTo(target.x, target.y);
                    ctx.stroke();
                }
            });
            
            // Knoten zeichnen
            filteredData.nodes.forEach(node => {
                const radius = Math.max(5, Math.min(15, node.connections * 2));
                const color = getNodeColor(node.type);
                
                // Knoten-Schatten
                ctx.shadowBlur = 3;
                ctx.shadowColor = 'rgba(0,0,0,0.3)';
                
                ctx.fillStyle = color;
                ctx.beginPath();
                ctx.arc(node.x, node.y, radius, 0, 2 * Math.PI);
                ctx.fill();
                
                // Rand
                ctx.shadowBlur = 0;
                ctx.strokeStyle = '#fff';
                ctx.lineWidth = 2;
                ctx.stroke();
                
                // Label für wichtige Knoten
                if (node.connections > 3) {
                    ctx.fillStyle = '#333';
                    ctx.font = '10px Arial';
                    ctx.textAlign = 'center';
                    const shortName = node.name.length > 15 ? node.name.substring(0, 12) + '...' : node.name;
                    ctx.fillText(shortName, node.x, node.y + radius + 12);
                }
            });
            
            ctx.restore();
        }
        
        function getNodeColor(type) {
            const colors = {
                'php': '#8892d9',
                'html': '#e74c3c',
                'js': '#f1c40f',
                'css': '#3498db',
                'sql': '#e67e22',
                'other': '#95a5a6'
            };
            return colors[type] || colors.other;
        }
        
        function filterNetwork() {
            const filter = document.getElementById('networkFilter').value;
            
            if (filter === 'all') {
                filteredData = JSON.parse(JSON.stringify(networkData));
            } else {
                const filteredNodes = networkData.nodes.filter(node => node.type === filter);
                const nodeIds = new Set(filteredNodes.map((node, i) => networkData.nodes.indexOf(node)));
                const filteredLinks = networkData.links.filter(link => 
                    nodeIds.has(link.source) && nodeIds.has(link.target)
                );
                
                // Indices neu zuordnen
                const oldToNewIndex = new Map();
                filteredNodes.forEach((node, i) => {
                    const oldIndex = networkData.nodes.indexOf(node);
                    oldToNewIndex.set(oldIndex, i);
                });
                
                filteredData = {
                    nodes: filteredNodes,
                    links: filteredLinks.map(link => ({
                        ...link,
                        source: oldToNewIndex.get(link.source),
                        target: oldToNewIndex.get(link.target)
                    }))
                };
            }
            
            initializeNetwork();
            startSimulation();
            addLog(`Filter angewendet: ${filter} (${filteredData.nodes.length} Knoten)`);
        }
        
        function resetZoom() {
            transform = { x: 0, y: 0, scale: 1 };
            drawNetwork();
            addLog('Zoom zurückgesetzt');
        }
        
        // Event Handler für Maus-Interaktion
        function onMouseDown(e) {
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left - transform.x) / transform.scale;
            const y = (e.clientY - rect.top - transform.y) / transform.scale;
            
            // Prüfen ob Knoten getroffen
            for (let node of filteredData.nodes) {
                const distance = Math.sqrt((x - node.x) ** 2 + (y - node.y) ** 2);
                if (distance < 15) {
                    isDragging = true;
                    dragTarget = node;
                    canvas.style.cursor = 'grabbing';
                    return;
                }
            }
        }
        
        function onMouseMove(e) {
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left - transform.x) / transform.scale;
            const y = (e.clientY - rect.top - transform.y) / transform.scale;
            
            if (isDragging && dragTarget) {
                dragTarget.x = x;
                dragTarget.y = y;
                dragTarget.vx = 0;
                dragTarget.vy = 0;
                drawNetwork();
            } else {
                // Tooltip anzeigen
                let hoverNode = null;
                for (let node of filteredData.nodes) {
                    const distance = Math.sqrt((x - node.x) ** 2 + (y - node.y) ** 2);
                    if (distance < 15) {
                        hoverNode = node;
                        break;
                    }
                }
                
                const tooltip = document.getElementById('nodeTooltip');
                if (hoverNode) {
                    tooltip.innerHTML = `
                        <strong>${hoverNode.name}</strong><br>
                        Pfad: ${hoverNode.fullPath || hoverNode.name}<br>
                        Typ: ${hoverNode.type.toUpperCase()}<br>
                        Verbindungen: ${hoverNode.connections}<br>
                        Größe: ${formatBytes(hoverNode.size)}
                    `;
                    tooltip.style.left = e.clientX + 10 + 'px';
                    tooltip.style.top = e.clientY - 10 + 'px';
                    tooltip.classList.remove('hidden');
                    canvas.style.cursor = 'pointer';
                } else {
                    tooltip.classList.add('hidden');
                    canvas.style.cursor = 'default';
                }
            }
        }
        
        function onMouseUp(e) {
            isDragging = false;
            dragTarget = null;
            canvas.style.cursor = 'default';
        }
        
        function onWheel(e) {
            e.preventDefault();
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const zoom = e.deltaY > 0 ? 0.9 : 1.1;
            const newScale = Math.max(0.1, Math.min(3, transform.scale * zoom));
            
            if (newScale !== transform.scale) {
                transform.x = x - (x - transform.x) * (newScale / transform.scale);
                transform.y = y - (y - transform.y) * (newScale / transform.scale);
                transform.scale = newScale;
                drawNetwork();
            }
        }
        
        function onCanvasClick(e) {
            const rect = canvas.getBoundingClientRect();
            const x = (e.clientX - rect.left - transform.x) / transform.scale;
            const y = (e.clientY - rect.top - transform.y) / transform.scale;
            
            for (let node of filteredData.nodes) {
                const distance = Math.sqrt((x - node.x) ** 2 + (y - node.y) ** 2);
                if (distance < 15) {
                    addLog(`Knoten ausgewählt: ${node.name} (${node.connections} Verbindungen)`);
                    break;
                }
            }
        }
        
        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
    </script>
</body>
</html>
