<?php
// check-hp-db-structure.php - Prüft HP-Datenbank Struktur
require_once 'auth-simple.php';
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
            echo "<li><strong>" . $row[0] . "</strong></li>";
        }
        echo "</ul>";
    }
    
    // Check specific tables we need
    $tables = ['hp_data', 'hpdet', 'arr'];
    
    foreach ($tables as $table) {
        echo "<h3>Table: $table</h3>";
        $result = $hpConn->query("DESCRIBE $table");
        if ($result) {
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
            echo "<tr style='background: #f0f0f0;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
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
            echo "<div style='color: red'>Table $table does not exist or cannot be accessed</div>";
            echo "<div>Error: " . $hpConn->error . "</div>";
        }
        echo "<br>";
    }
    
    // Check connection between databases
    echo "<h3>Connection Test (resid linkage):</h3>";
    echo "Testing link: hp_data.resid ↔ AV-Res.id<br><br>";
    
    // Get sample data from hp_data
    $result = $hpConn->query("SELECT resid, iid, name FROM hp_data LIMIT 10");
    if ($result && $result->num_rows > 0) {
        echo "<h4>Sample hp_data entries:</h4>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f0f0f0;'><th>hp_data.resid</th><th>hp_data.iid</th><th>hp_data.name</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . ($row['resid'] ?? 'NULL') . "</td>";
            echo "<td>" . ($row['iid'] ?? 'NULL') . "</td>";
            echo "<td>" . ($row['name'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div style='color: red'>No data found in hp_data table</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red'>ERROR: " . $e->getMessage() . "</div>";
}
?>
