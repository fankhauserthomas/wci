<?php
require_once '../config.php';

echo "Testing compareBackupRecords function...\n";

function compareBackupRecords($baseline_table, $comparison_table, $date_start, $date_end) {
    global $mysqli;
    
    echo "Comparing: $baseline_table vs $comparison_table ($date_start to $date_end)\n";
    
    $changes = [
        'added' => [],
        'removed' => [],
        'modified' => [],
        'unchanged' => 0
    ];
    
    // Test query
    $sql = "SELECT COUNT(*) as count FROM `$baseline_table` WHERE anreise >= '$date_start' AND anreise <= '$date_end'";
    echo "Test SQL: $sql\n";
    
    $result = $mysqli->query($sql);
    if (!$result) {
        echo "SQL Error: " . $mysqli->error . "\n";
        return $changes;
    }
    
    $count = $result->fetch_assoc()['count'];
    echo "Found $count records in baseline\n";
    
    return $changes;
}

$result = compareBackupRecords('AV_Res_Backup_2025-08-28_02-39-48', 'AV_Res_Backup_2025-08-28_03-14-35', '2025-08-28', '2025-08-30');
var_dump($result);
?>
