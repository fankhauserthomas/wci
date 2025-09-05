<?php
require 'config.php';

echo "Verfügbare Tabellen:\n";
$result = $mysqli->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    echo "- " . $row[0] . "\n";
}

echo "\nTabellen mit 'Res' im Namen:\n";
$result = $mysqli->query("SHOW TABLES LIKE '%Res%'");
while ($row = $result->fetch_row()) {
    echo "- " . $row[0] . "\n";
}

$mysqli->close();
?>
