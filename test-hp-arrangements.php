<?php
require_once 'hp-db-config.php';

try {
    $hpConnection = getHpDbConnection();
    echo "HP Database connection: OK\n";
    
    // Test query to get all arrangements
    $stmt = $hpConnection->prepare("SELECT id, bez FROM hparr ORDER BY id LIMIT 5");
    $stmt->execute();
    $result = $stmt->get_result();
    $arrangements = $result->fetch_all(MYSQLI_ASSOC);
    
    echo "Found " . count($arrangements) . " arrangements:\n";
    foreach ($arrangements as $arr) {
        echo "- {$arr['id']}: {$arr['bez']}\n";
    }
    
    // Test query to check if we have any hp_data entries
    $stmt = $hpConnection->prepare("SELECT COUNT(*) as count FROM hp_data");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    echo "\nHP data entries: {$row['count']}\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
