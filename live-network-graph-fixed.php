<?php
// Live Network Graph basierend auf Apache Access Logs - FIXED VERSION
header('Content-Type: text/html; charset=utf-8');

// Log-Datei analysieren
$logFile = '/home/vadmin/lemp/logs/apache2/access.log';

$accessedFiles = [];
$fileConnections = [];
$userSessions = [];

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Einfaches, robustes Regex für Apache Logs
        if (preg_match('/(\d+\.\d+\.\d+\.\d+).*?"(GET|POST) ([^"]*)"/', $line, $matches)) {
            $ip = $matches[1];
            $method = $matches[2];
            $url = $matches[3];
            
            // URL bereinigen - Query-Parameter und HTTP-Version entfernen
            $cleanUrl = preg_replace('/\s+HTTP\/[\d.]+$/', '', $url);
            $cleanUrl = preg_replace('/\?.*$/', '', $cleanUrl);
            
            // Nur URLs mit /wci/ verarbeiten
            if (strpos($cleanUrl, '/wci/') !== false) {
                $file = basename($cleanUrl);
                
                // Skip certain files und leere Dateinamen
                $skipFiles = ['ping.php', 'favicon.ico', ''];
                
                if (!empty($file) && !in_array($file, $skipFiles) && !empty(pathinfo($file, PATHINFO_EXTENSION))) {
                    // File erfassen
                    if (!isset($accessedFiles[$file])) {
                        $accessedFiles[$file] = [
                            'requests' => 0,
                            'users' => [],
                            'methods' => [],
                            'last_access' => date('Y-m-d H:i:s'),
                            'file_type' => strtolower(pathinfo($file, PATHINFO_EXTENSION))
                        ];
                    }
                    $accessedFiles[$file]['requests']++;
                    $accessedFiles[$file]['users'][$ip] = true;
                    $accessedFiles[$file]['methods'][$method] = true;
                    
                    // Session-based connections (gleicher User aufeinanderfolgende Requests)
                    if (!isset($userSessions[$ip])) {
                        $userSessions[$ip] = [];
                    }
                    $userSessions[$ip][] = ['file' => $file, 'time' => time()];
                }
            }
        }
    }
    
    // Session-basierte Verbindungen erstellen
    foreach ($userSessions as $ip => $session) {
        for ($i = 0; $i < count($session) - 1; $i++) {
            $fromFile = $session[$i]['file'];
            $toFile = $session[$i + 1]['file'];
            
            if ($fromFile !== $toFile) {
                $connectionKey = $fromFile . '->' . $toFile;
                if (!isset($fileConnections[$connectionKey])) {
                    $fileConnections[$connectionKey] = [
                        'from' => $fromFile,
                        'to' => $toFile,
                        'strength' => 0
                    ];
                }
                $fileConnections[$connectionKey]['strength']++;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WCI Live Network Graph</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        .header {
            background: rgba(0,0,0,0.3);
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 20px rgba(0,0,0,0.3);
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }
        
        .subtitle {
            font-size: 1.1em;
            opacity: 0.8;
            margin-bottom: 20px;
        }
        
        .stats-bar {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .stat-item {
            background: rgba(255,255,255,0.1);
            padding: 10px 20px;
            border-radius: 25px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            font-weight: 500;
        }
        
        .network-container {
            height: 80vh;
            margin: 20px 0;
        }
        
        canvas {
            background: rgba(0,0,0,0.2);
            border-radius: 10px;
            cursor: move;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .controls {
            position: fixed;
            top: 120px;
            right: 20px;
            background: rgba(0,0,0,0.8);
            padding: 15px;
            border-radius: 10px;
            font-size: 0.8em;
        }
        
        .legend {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: rgba(0,0,0,0.8);
            padding: 15px;
            border-radius: 10px;
            font-size: 0.8em;
        }
        
        .legend-color {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            vertical-align: middle;
        }
        
        .file-tooltip {
            position: absolute;
            background: rgba(0,0,0,0.9);
            color: white;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ccc;
            font-size: 0.8em;
            pointer-events: none;
            z-index: 1000;
            max-width: 300px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🌐 WCI Live Network Graph</h1>
        <div class="subtitle">Echte File-Verbindungen basierend auf Apache Access Logs</div>
        
        <div class="stats-bar">
            <div class="stat-item">
                📁 Files: <?= count($accessedFiles) ?>
            </div>
            <div class="stat-item">
                🔗 Connections: <?= count($fileConnections) ?>
            </div>
            <div class="stat-item">
                📈 Total Requests: <?= array_sum(array_column($accessedFiles, 'requests')) ?>
            </div>
            <div class="stat-item">
                👥 Users: <?= count($accessedFiles) > 0 ? count(array_unique(array_merge(...array_column($accessedFiles, 'users')))) : 0 ?>
            </div>
        </div>
    </div>
    
    <div class="network-container">
        <canvas id="networkCanvas"></canvas>
    </div>
    
    <div class="controls">
        <div style="margin-bottom: 10px;"><strong>Controls:</strong></div>
        <div>🖱️ Drag: Move view</div>
        <div>🎯 Click: Select node</div>
        <div>⚡ Double-click: Center node</div>
        <div>🔄 Space: Reset positions</div>
        <div style="margin-top: 10px;">
            <button id="pauseBtn" style="background: #e74c3c; color: white; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer;">
                ⏸️ Pause
            </button>
        </div>
    </div>
    
    <div class="legend">
        <div style="margin-bottom: 10px;"><strong>File Types:</strong></div>
        <div style="margin: 5px 0;">
            <div class="legend-color" style="background: #3498db;"></div>
            PHP Files
        </div>
        <div style="margin: 5px 0;">
            <div class="legend-color" style="background: #e74c3c;"></div>
            HTML Files
        </div>
        <div style="margin: 5px 0;">
            <div class="legend-color" style="background: #f39c12;"></div>
            CSS/JS Files
        </div>
        <div style="margin: 5px 0;">
            <div class="legend-color" style="background: #2ecc71;"></div>
            Other Files
        </div>
        <div style="margin-top: 10px; font-size: 0.7em; opacity: 0.7;">
            Node size = Request count<br>
            Line thickness = Connection strength
        </div>
    </div>
    
    <div id="tooltip" class="file-tooltip" style="display: none;"></div>
    
    <script>
        // Debug output
        console.log('Debug: Starting Live Network Graph');
        
        // Network Data from PHP
        const files = <?= json_encode($accessedFiles) ?>;
        const connections = <?= json_encode(array_values($fileConnections)) ?>;
        
        console.log('Files data:', files);
        console.log('Connections data:', connections);
        console.log('Files count:', Object.keys(files).length);
        console.log('Connections count:', connections.length);
        
        // Canvas Setup
        const canvas = document.getElementById('networkCanvas');
        const ctx = canvas.getContext('2d');
        
        function resizeCanvas() {
            canvas.width = window.innerWidth - 40;
            canvas.height = window.innerHeight * 0.8;
        }
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);
        
        // Color mapping for file types
        const fileTypeColors = {
            'php': '#3498db',
            'html': '#e74c3c',
            'css': '#f39c12',
            'js': '#f39c12',
            'png': '#9b59b6',
            'jpg': '#9b59b6',
            'gif': '#9b59b6',
            'default': '#2ecc71'
        };
        
        // Create nodes
        const nodes = [];
        const nodeMap = {};
        
        console.log('Creating nodes from files...');
        
        Object.entries(files).forEach(([filename, data], index) => {
            console.log('Processing file:', filename, data);
            
            const node = {
                id: filename,
                label: filename,
                x: Math.random() * (canvas.width - 200) + 100,
                y: Math.random() * (canvas.height - 200) + 100,
                vx: 0,
                vy: 0,
                radius: Math.max(8, Math.min(25, Math.sqrt(data.requests) * 3)),
                color: fileTypeColors[data.file_type] || fileTypeColors.default,
                requests: data.requests,
                users: Object.keys(data.users).length,
                methods: Object.keys(data.methods),
                lastAccess: data.last_access,
                selected: false
            };
            nodes.push(node);
            nodeMap[filename] = node;
        });
        
        console.log('Created nodes:', nodes.length);
        
        // Create links
        const links = [];
        connections.forEach(conn => {
            const fromNode = nodeMap[conn.from];
            const toNode = nodeMap[conn.to];
            
            if (fromNode && toNode) {
                links.push({
                    source: fromNode,
                    target: toNode,
                    strength: conn.strength,
                    width: Math.max(1, Math.min(5, conn.strength))
                });
            }
        });
        
        console.log('Created links:', links.length);
        
        // Physics simulation variables
        let isPaused = false;
        
        function draw() {
            console.log('Draw called, nodes:', nodes.length, 'links:', links.length);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Test drawing - simple circle
            ctx.beginPath();
            ctx.arc(100, 100, 20, 0, Math.PI * 2);
            ctx.fillStyle = 'red';
            ctx.fill();
            
            // Draw nodes
            nodes.forEach((node, index) => {
                console.log('Drawing node', index, node.label);
                ctx.beginPath();
                ctx.arc(node.x, node.y, node.radius, 0, Math.PI * 2);
                ctx.fillStyle = node.color;
                ctx.fill();
                
                if (node.selected) {
                    ctx.beginPath();
                    ctx.arc(node.x, node.y, node.radius + 3, 0, Math.PI * 2);
                    ctx.strokeStyle = '#ffffff';
                    ctx.lineWidth = 2;
                    ctx.stroke();
                }
                
                // Label
                ctx.fillStyle = '#ffffff';
                ctx.font = '10px Arial';
                ctx.textAlign = 'center';
                ctx.fillText(node.label, node.x, node.y - node.radius - 5);
            });
            
            // Draw links
            links.forEach(link => {
                ctx.beginPath();
                ctx.moveTo(link.source.x, link.source.y);
                ctx.lineTo(link.target.x, link.target.y);
                ctx.strokeStyle = `rgba(255, 255, 255, ${0.3 + (link.strength / 10) * 0.4})`;
                ctx.lineWidth = link.width;
                ctx.stroke();
                
                // Arrow
                const angle = Math.atan2(link.target.y - link.source.y, link.target.x - link.source.x);
                const arrowX = link.target.x - Math.cos(angle) * (link.target.radius + 5);
                const arrowY = link.target.y - Math.sin(angle) * (link.target.radius + 5);
                
                ctx.beginPath();
                ctx.moveTo(arrowX, arrowY);
                ctx.lineTo(arrowX - Math.cos(angle - 0.5) * 8, arrowY - Math.sin(angle - 0.5) * 8);
                ctx.lineTo(arrowX - Math.cos(angle + 0.5) * 8, arrowY - Math.sin(angle + 0.5) * 8);
                ctx.closePath();
                ctx.fillStyle = ctx.strokeStyle;
                ctx.fill();
            });
        }
        
        function animate() {
            draw();
            requestAnimationFrame(animate);
        }
        
        animate();
        
        console.log('🌐 Live Network Graph loaded with', nodes.length, 'nodes and', links.length, 'connections');
    </script>
</body>
</html>
