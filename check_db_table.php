<?php
// Quick database table checker
require_once __DIR__ . '/config.php';

echo "<h2>Database Connection Test</h2>";

if ($mysqli->connect_error) {
    echo "❌ Connection failed: " . $mysqli->connect_error . "<br>";
    exit;
}

echo "✅ Database connection successful<br>";

// Check if AV-Res-webImp table exists
$result = $mysqli->query("SHOW TABLES LIKE 'AV-Res-webImp'");
if ($result && $result->num_rows > 0) {
    echo "✅ Table 'AV-Res-webImp' exists<br>";
    
    // Show table structure
    $structure = $mysqli->query("DESCRIBE `AV-Res-webImp`");
    if ($structure) {
        echo "<h3>Table Structure:</h3>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        while ($row = $structure->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($row['Default']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} else {
    echo "❌ Table 'AV-Res-webImp' does not exist<br>";
    echo "<h3>Creating table...</h3>";
    
    $createSql = "CREATE TABLE `AV-Res-webImp` (
        `av_id` int(11) NOT NULL,
        `anreise` date DEFAULT NULL,
        `abreise` date DEFAULT NULL,
        `lager` int(11) DEFAULT 0,
        `betten` int(11) DEFAULT 0,
        `dz` int(11) DEFAULT 0,
        `sonder` int(11) DEFAULT 0,
        `hp` tinyint(1) DEFAULT 0,
        `vegi` int(11) DEFAULT 0,
        `gruppe` varchar(255) DEFAULT NULL,
        `bem_av` text DEFAULT NULL,
        `nachname` varchar(100) DEFAULT NULL,
        `vorname` varchar(100) DEFAULT NULL,
        `handy` varchar(50) DEFAULT NULL,
        `email` varchar(255) DEFAULT NULL,
        `email_date` datetime DEFAULT NULL,
        `vorgang` varchar(100) DEFAULT NULL,
        `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`av_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($mysqli->query($createSql)) {
        echo "✅ Table 'AV-Res-webImp' created successfully<br>";
    } else {
        echo "❌ Error creating table: " . $mysqli->error . "<br>";
    }
}

// Show all tables in database
echo "<h3>All Tables in Database:</h3>";
$tables = $mysqli->query("SHOW TABLES");
if ($tables) {
    echo "<ul>";
    while ($row = $tables->fetch_array()) {
        echo "<li>" . htmlspecialchars($row[0]) . "</li>";
    }
    echo "</ul>";
}

$mysqli->close();
?>
