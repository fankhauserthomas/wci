<?php
// Vereinfachte Debug-Version des Live Network Graphs

error_reporting(E_ALL);
ini_set('display_errors', 1);

$logFile = '/home/vadmin/lemp/logs/apache2/access.log';
$accessedFiles = [];
$fileConnections = [];

echo "<h1>Debug: Live Network Graph Data</h1>";

if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "<p>Log lines: " . count($lines) . "</p>";
    
    $processed = 0;
    
    foreach ($lines as $line) {
        if (preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) ([^"\s]+)[^"]*" (\d+) (\d+) "([^"]*)"/', $line, $matches)) {
            $ip = $matches[1];
            $datetime = $matches[2];
            $method = $matches[3];
            $url = $matches[4];
            $status = intval($matches[5]);
            $referer = $matches[7];
            
            if ($status < 400) {
                $file = parse_url($url, PHP_URL_PATH);
                if ($file && $file !== '/') {
                    $file = str_replace('/wci/', '', $file);
                    $file = basename($file);
                    
                    if (!empty($file) && $file !== 'favicon.ico' && $file !== 'ping.php') {
                        if (!isset($accessedFiles[$file])) {
                            $accessedFiles[$file] = [
                                'requests' => 0,
                                'users' => [],
                                'methods' => [],
                                'last_access' => $datetime,
                                'file_type' => strtolower(pathinfo($file, PATHINFO_EXTENSION))
                            ];
                        }
                        $accessedFiles[$file]['requests']++;
                        $accessedFiles[$file]['users'][$ip] = true;
                        $accessedFiles[$file]['methods'][$method] = true;
                    }
                }
            }
        }
        
        $processed++;
        if ($processed >= 5000) break; // Limit für Performance
    }
    
    echo "<h2>Statistics</h2>";
    echo "<p>Files found: " . count($accessedFiles) . "</p>";
    echo "<p>Connections: " . count($fileConnections) . "</p>";
    echo "<p>Total requests: " . array_sum(array_column($accessedFiles, 'requests')) . "</p>";
    
    if (count($accessedFiles) > 0) {
        echo "<h3>Top 10 Files:</h3>";
        $sortedFiles = $accessedFiles;
        arsort($sortedFiles);
        $top10 = array_slice($sortedFiles, 0, 10, true);
        
        foreach ($top10 as $filename => $data) {
            echo "<p><strong>$filename</strong>: {$data['requests']} requests, " . 
                 count($data['users']) . " users, type: {$data['file_type']}</p>";
        }
        
        echo "<h3>Now creating HTML with live data...</h3>";
        
        // Jetzt erstelle die HTML-Version
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Simple Live Network</title>
            <style>
                body { background: #2c3e50; color: white; font-family: Arial; }
                canvas { background: rgba(0,0,0,0.3); border: 1px solid #ccc; }
                .stats { padding: 20px; }
            </style>
        </head>
        <body>
            <div class="stats">
                <h1>WCI Live Network Graph</h1>
                <p>Files: <?= count($accessedFiles) ?></p>
                <p>Total Requests: <?= array_sum(array_column($accessedFiles, 'requests')) ?></p>
            </div>
            
            <canvas id="canvas" width="1200" height="800"></canvas>
            
            <script>
                console.log('Starting simple network graph...');
                
                const files = <?= json_encode($accessedFiles) ?>;
                console.log('Files loaded:', Object.keys(files).length);
                
                const canvas = document.getElementById('canvas');
                const ctx = canvas.getContext('2d');
                
                // Create simple nodes
                const nodes = [];
                let index = 0;
                
                Object.entries(files).forEach(([filename, data]) => {
                    const x = 100 + (index % 10) * 100;
                    const y = 100 + Math.floor(index / 10) * 80;
                    
                    nodes.push({
                        x: x,
                        y: y,
                        radius: Math.max(5, Math.sqrt(data.requests) * 2),
                        color: data.file_type === 'php' ? '#3498db' : '#e74c3c',
                        label: filename,
                        requests: data.requests
                    });
                    
                    index++;
                });
                
                console.log('Nodes created:', nodes.length);
                
                function draw() {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    
                    // Draw nodes
                    nodes.forEach(node => {
                        ctx.beginPath();
                        ctx.arc(node.x, node.y, node.radius, 0, Math.PI * 2);
                        ctx.fillStyle = node.color;
                        ctx.fill();
                        
                        // Draw label
                        ctx.fillStyle = 'white';
                        ctx.font = '10px Arial';
                        ctx.fillText(node.label, node.x + node.radius + 5, node.y);
                    });
                }
                
                draw();
                console.log('Graph drawn successfully!');
            </script>
        </body>
        </html>
        <?php
    } else {
        echo "<p style='color: red;'>No files found in logs!</p>";
    }
} else {
    echo "<p style='color: red;'>Log file not found: $logFile</p>";
}
?>
