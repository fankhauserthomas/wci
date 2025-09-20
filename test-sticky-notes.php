<?php
require_once 'config.php';

try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $dbUser, $dbPass, $opt);
    
    // Check for existing notes
    echo "=== Checking for existing notes ===\n";
    $stmt = $pdo->query("SELECT ID, note, dx, dy FROM AV_ResDet WHERE note IS NOT NULL AND note != '' LIMIT 5");
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($existing) > 0) {
        echo "Found " . count($existing) . " existing notes:\n";
        foreach ($existing as $row) {
            echo "Detail ID: {$row['ID']}, Note: {$row['note']}, dx: {$row['dx']}, dy: {$row['dy']}\n";
        }
    } else {
        echo "No existing notes found.\n";
        
        // Add a test note to a random reservation detail
        echo "\n=== Adding test note ===\n";
        $stmt = $pdo->query("SELECT ID FROM AV_ResDet ORDER BY ID DESC LIMIT 1");
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($latest) {
            $detailId = $latest['ID'];
            $testNote = "Test sticky note\nfor visualization";
            $dx = 50;  // 50px to the right
            $dy = -30; // 30px up
            
            $updateStmt = $pdo->prepare("UPDATE AV_ResDet SET note = ?, dx = ?, dy = ? WHERE ID = ?");
            $result = $updateStmt->execute([$testNote, $dx, $dy, $detailId]);
            
            if ($result) {
                echo "Added test note to ID: $detailId\n";
                echo "Note: '$testNote'\n";
                echo "Position: dx=$dx, dy=$dy\n";
            } else {
                echo "Failed to add test note.\n";
            }
        } else {
            echo "No reservation details found to add test note to.\n";
        }
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>