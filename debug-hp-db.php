<?php
// debug-hp-db.php - Debug HP database structure for arrangements
require_once __DIR__ . '/auth.php';
require_once 'hp-db-config.php';

if (!AuthManager::checkSession()) {
    echo "Authentication required";
    exit;
}

echo "<h2>HP Database Structure Debug</h2>";

try {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        echo "<div style='color: red'>ERROR: Could not connect to HP database</div>";
        exit;
    }
    
    echo "<div style='color: green'>Connected to HP database successfully</div>";
    
    // List all tables
    echo "<h3>All Tables:</h3>";
    $result = $hpConn->query("SHOW TABLES");
    if ($result) {
        echo "<ul>";
        while ($row = $result->fetch_array()) {
            echo "<li>" . $row[0] . "</li>";
        }
        echo "</ul>";
    }
    
    // Check for arrangement-related tables
    echo "<h3>Arrangement-related Tables:</h3>";
    $result = $hpConn->query("SHOW TABLES LIKE '%arr%'");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_array()) {
            $tableName = $row[0];
            echo "<h4>Table: $tableName</h4>";
            $desc = $hpConn->query("DESCRIBE $tableName");
            if ($desc) {
                echo "<table border='1' cellpadding='5'>";
                echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                while ($field = $desc->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>" . $field['Field'] . "</td>";
                    echo "<td>" . $field['Type'] . "</td>";
                    echo "<td>" . $field['Null'] . "</td>";
                    echo "<td>" . $field['Key'] . "</td>";
                    echo "<td>" . ($field['Default'] ?? 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
                
                // Show some sample data
                $sample = $hpConn->query("SELECT * FROM $tableName LIMIT 5");
                if ($sample && $sample->num_rows > 0) {
                    echo "<p><strong>Sample data:</strong></p>";
                    echo "<table border='1' cellpadding='3'>";
                    $first = true;
                    while ($row = $sample->fetch_assoc()) {
                        if ($first) {
                            echo "<tr>";
                            foreach ($row as $key => $value) {
                                echo "<th>$key</th>";
                            }
                            echo "</tr>";
                            $first = false;
                        }
                        echo "<tr>";
                        foreach ($row as $value) {
                            echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
                        }
                        echo "</tr>";
                    }
                    echo "</table>";
                }
            }
            echo "<br>";
        }
    } else {
        echo "<div style='color: orange'>No arrangement-related tables found</div>";
    }
    
    // Check the main AV-Res database for arrangement info
    echo "<h3>Check AV-Res database for arrangement structure:</h3>";
    require_once 'config.php';
    if (isset($mysqli)) {
        $result = $mysqli->query("SHOW TABLES LIKE '%arr%'");
        if ($result && $result->num_rows > 0) {
            echo "<h4>AV-Res arrangement tables:</h4>";
            while ($row = $result->fetch_array()) {
                $tableName = $row[0];
                echo "<p><strong>$tableName</strong></p>";
                $desc = $mysqli->query("DESCRIBE $tableName");
                if ($desc) {
                    echo "<table border='1' cellpadding='3'>";
                    echo "<tr><th>Field</th><th>Type</th></tr>";
                    while ($field = $desc->fetch_assoc()) {
                        echo "<tr><td>" . $field['Field'] . "</td><td>" . $field['Type'] . "</td></tr>";
                    }
                    echo "</table>";
                    
                    // Sample data
                    $sample = $mysqli->query("SELECT * FROM $tableName LIMIT 5");
                    if ($sample && $sample->num_rows > 0) {
                        echo "<p>Sample data:</p>";
                        echo "<table border='1' cellpadding='3'>";
                        $first = true;
                        while ($row = $sample->fetch_assoc()) {
                            if ($first) {
                                echo "<tr>";
                                foreach ($row as $key => $value) {
                                    echo "<th>$key</th>";
                                }
                                echo "</tr>";
                                $first = false;
                            }
                            echo "<tr>";
                            foreach ($row as $value) {
                                echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
                            }
                            echo "</tr>";
                        }
                        echo "</table>";
                    }
                }
                echo "<br>";
            }
        } else {
            echo "<div style='color: orange'>No arrangement tables in AV-Res database either</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red'>ERROR: " . $e->getMessage() . "</div>";
}
?>
