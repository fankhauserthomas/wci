<?php
// check-hp-tables.php - Check HP database structure

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/auth.php';
require_once 'hp-db-config.php';

if (!AuthManager::checkSession()) {
    echo "<div style='color: red'>ERROR: Not authenticated</div>";
    exit;
}

echo "<h2>HP Database Structure Check</h2>";

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        echo "<div style='color: red'>ERROR: Could not connect to HP database</div>";
        exit;
    }
    
    echo "<div style='color: green'>Connected to HP database successfully</div>";
    
    // List all tables
    echo "<h3>Available Tables:</h3>";
    $result = $hpConn->query("SHOW TABLES");
    if ($result) {
        echo "<ul>";
        while ($row = $result->fetch_array()) {
            echo "<li>" . $row[0] . "</li>";
        }
        echo "</ul>";
    }
    
    // Check hpdet table structure
    echo "<h3>hpdet Table Structure:</h3>";
    $result = $hpConn->query("DESCRIBE hpdet");
    if ($result) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . ($row['Field'] ?? '') . "</td>";
            echo "<td>" . ($row['Type'] ?? '') . "</td>";
            echo "<td>" . ($row['Null'] ?? '') . "</td>";
            echo "<td>" . ($row['Key'] ?? '') . "</td>";
            echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . ($row['Extra'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div style='color: red'>ERROR: Table hpdet does not exist or cannot be accessed</div>";
    }
    
    // Check hp_data table structure
    echo "<h3>hp_data Table Structure:</h3>";
    $result = $hpConn->query("DESCRIBE hp_data");
    if ($result) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . ($row['Field'] ?? '') . "</td>";
            echo "<td>" . ($row['Type'] ?? '') . "</td>";
            echo "<td>" . ($row['Null'] ?? '') . "</td>";
            echo "<td>" . ($row['Key'] ?? '') . "</td>";
            echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . ($row['Extra'] ?? '') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div style='color: red'>ERROR: Table hp_data does not exist or cannot be accessed</div>";
    }
    
    // Check if guest 37302 exists in hp_data
    echo "<h3>Guest 37302 in hp_data:</h3>";
    $stmt = $hpConn->prepare("SELECT * FROM hp_data WHERE iid = ?");
    if ($stmt) {
        $guestId = 37302;
        $stmt->bind_param("i", $guestId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            echo "<div style='color: green'>Guest found:</div>";
            echo "<pre>" . print_r($row, true) . "</pre>";
        } else {
            echo "<div style='color: red'>Guest 37302 not found in hp_data table</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red'>ERROR: " . $e->getMessage() . "</div>";
    echo "<div style='color: red'>Stack trace: " . $e->getTraceAsString() . "</div>";
}
?>
