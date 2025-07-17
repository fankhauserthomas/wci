<?php
require_once 'SyncManager.php';

echo "<!DOCTYPE html>
<html lang='de'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Sync Debug - Multi-Table Sync System</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; text-align: center; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
        h2 { color: #34495e; border-left: 4px solid #3498db; padding-left: 10px; }
        .sync-info { background: #ecf0f1; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .tables-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0; }
        .table-card { background: #fff; border: 1px solid #bdc3c7; border-radius: 8px; padding: 15px; }
        .table-card h3 { margin-top: 0; color: #2980b9; }
        .operations { display: flex; gap: 10px; margin: 10px 0; }
        .op-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .op-insert { background: #2ecc71; color: white; }
        .op-update { background: #f39c12; color: white; }
        .op-delete { background: #e74c3c; color: white; }
        .summary-stats { display: flex; justify-content: space-around; margin: 20px 0; }
        .stat-box { text-align: center; padding: 15px; background: #3498db; color: white; border-radius: 8px; min-width: 120px; }
        .stat-number { font-size: 24px; font-weight: bold; }
        .stat-label { font-size: 14px; }
        .direction { padding: 10px; margin: 10px 0; border-radius: 5px; }
        .local-to-remote { background: #e8f6f3; border-left: 4px solid #27ae60; }
        .remote-to-local { background: #fef9e7; border-left: 4px solid #f39c12; }
        .no-activity { color: #7f8c8d; font-style: italic; text-align: center; padding: 20px; }
        .badge { padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        .badge-queue { background: #27ae60; color: white; }
        .badge-timestamp { background: #e67e22; color: white; }
        .sync-trigger { margin: 20px 0; text-align: center; }
        .sync-btn { background: #3498db; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .sync-btn:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class='container'>";

echo "<h1>🔄 Multi-Table Sync System - Debug View</h1>";

try {
    $sync = new SyncManager();
    
    if (!$sync->remoteDb) {
        echo "<div style='background: #e74c3c; color: white; padding: 15px; border-radius: 5px; text-align: center;'>
                ❌ Remote Database nicht verfügbar
              </div>";
        exit;
    }
    
    // Sync ausführen
    echo "<div class='sync-trigger'>
            <button class='sync-btn' onclick='triggerSync()'>🔄 Sync Jetzt Ausführen</button>
          </div>";
    
    $result = $sync->syncOnPageLoad('debug_view');
    
    if (!$result['success']) {
        echo "<div style='background: #e74c3c; color: white; padding: 15px; border-radius: 5px;'>
                ❌ Sync Fehler: " . htmlspecialchars($result['error']) . "
              </div>";
        exit;
    }
    
    $data = $result['results'];
    
    // Sync Info
    echo "<div class='sync-info'>
            <h2>ℹ️ Sync Information</h2>
            <p><strong>Sync Mode:</strong> <span class='badge badge-" . ($data['sync_mode'] == 'queue-based' ? 'queue' : 'timestamp') . "'>" . strtoupper($data['sync_mode']) . "</span></p>
            <p><strong>Timestamp:</strong> {$data['timestamp']}</p>
            <p><strong>Configured Tables:</strong> " . implode(', ', $data['tables_configured']) . "</p>
          </div>";
    
    // Summary Statistics
    $summary = $data['summary'];
    echo "<div class='summary-stats'>
            <div class='stat-box'>
                <div class='stat-number'>{$summary['total_operations']['local_to_remote']}</div>
                <div class='stat-label'>Local → Remote</div>
            </div>
            <div class='stat-box'>
                <div class='stat-number'>{$summary['total_operations']['remote_to_local']}</div>
                <div class='stat-label'>Remote → Local</div>
            </div>
            <div class='stat-box'>
                <div class='stat-number'>" . count($summary['tables_processed']) . "</div>
                <div class='stat-label'>Active Tables</div>
            </div>
          </div>";
    
    // Direction Details
    echo "<h2>📊 Sync Directions</h2>";
    
    echo "<div class='direction local-to-remote'>
            <h3>📤 Local → Remote ({$data['local_to_remote']['total_processed']} operations)</h3>";
    
    if ($data['local_to_remote']['total_processed'] > 0) {
        foreach ($data['local_to_remote']['tables'] as $tableName => $tableData) {
            if ($tableData['processed'] > 0) {
                $ops = $tableData['operations'];
                echo "<p><strong>$tableName:</strong> {$tableData['processed']} operations 
                      <span class='operations'>
                        <span class='op-badge op-insert'>I: {$ops['insert']}</span>
                        <span class='op-badge op-update'>U: {$ops['update']}</span>
                        <span class='op-badge op-delete'>D: {$ops['delete']}</span>
                      </span></p>";
            }
        }
    } else {
        echo "<p class='no-activity'>Keine Operationen</p>";
    }
    
    echo "</div>";
    
    echo "<div class='direction remote-to-local'>
            <h3>📥 Remote → Local ({$data['remote_to_local']['total_processed']} operations)</h3>";
    
    if ($data['remote_to_local']['total_processed'] > 0) {
        foreach ($data['remote_to_local']['tables'] as $tableName => $tableData) {
            if ($tableData['processed'] > 0) {
                $ops = $tableData['operations'];
                echo "<p><strong>$tableName:</strong> {$tableData['processed']} operations 
                      <span class='operations'>
                        <span class='op-badge op-insert'>I: {$ops['insert']}</span>
                        <span class='op-badge op-update'>U: {$ops['update']}</span>
                        <span class='op-badge op-delete'>D: {$ops['delete']}</span>
                      </span></p>";
            }
        }
    } else {
        echo "<p class='no-activity'>Keine Operationen</p>";
    }
    
    echo "</div>";
    
    // Table Details Grid
    echo "<h2>📋 Table Details</h2>";
    echo "<div class='tables-grid'>";
    
    foreach ($data['tables_configured'] as $tableName) {
        $details = $summary['table_details'][$tableName] ?? [
            'total_processed' => 0,
            'local_to_remote' => 0,
            'remote_to_local' => 0,
            'operations' => ['insert' => 0, 'update' => 0, 'delete' => 0]
        ];
        
        $isActive = $details['total_processed'] > 0;
        $cardClass = $isActive ? 'table-card' : 'table-card no-activity';
        
        echo "<div class='$cardClass'>
                <h3>$tableName</h3>
                <p><strong>Total Operations:</strong> {$details['total_processed']}</p>
                <p><strong>Local → Remote:</strong> {$details['local_to_remote']}</p>
                <p><strong>Remote → Local:</strong> {$details['remote_to_local']}</p>
                <div class='operations'>
                    <span class='op-badge op-insert'>INSERT: {$details['operations']['insert']}</span>
                    <span class='op-badge op-update'>UPDATE: {$details['operations']['update']}</span>
                    <span class='op-badge op-delete'>DELETE: {$details['operations']['delete']}</span>
                </div>
              </div>";
    }
    
    echo "</div>";
    
    // Raw Data (für debugging)
    echo "<h2>🔧 Raw Sync Data</h2>";
    echo "<pre style='background: #2c3e50; color: #ecf0f1; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px;'>";
    echo htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT));
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<div style='background: #e74c3c; color: white; padding: 15px; border-radius: 5px;'>
            ❌ Exception: " . htmlspecialchars($e->getMessage()) . "
          </div>";
}

echo "</div>
<script>
function triggerSync() {
    location.reload();
}
</script>
</body>
</html>";
?>
