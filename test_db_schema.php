<?php
// test_db_schema.php - Test to see what fields are actually required
require_once 'config.php';

try {
    // Try a minimal insert to see what's required
    $stmt = $mysqli->prepare("INSERT INTO `AV-Res` (nachname) VALUES (?)");
    $nachname = "TestMinimal";
    $stmt->bind_param('s', $nachname);
    
    if (!$stmt->execute()) {
        echo "Error: " . $stmt->error . "\n";
    } else {
        echo "Minimal insert worked! ID: " . $mysqli->insert_id . "\n";
        // Clean up
        $mysqli->query("DELETE FROM `AV-Res` WHERE id = " . $mysqli->insert_id);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>
