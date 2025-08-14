<?php
require 'config.php';

$mysqli = $GLOBALS['mysqli'];

echo "Alle Tabellen in der Datenbank:\n";
$result = $mysqli->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    echo "- " . $row[0] . "\n";
}

echo "\nTabellen mit 'reserv' im Namen:\n";
$result = $mysqli->query("SHOW TABLES LIKE '%reserv%'");
while ($row = $result->fetch_array()) {
    echo "- " . $row[0] . "\n";
}

echo "\nTabellen mit 'AV' im Namen:\n";
$result = $mysqli->query("SHOW TABLES LIKE '%AV%'");
while ($row = $result->fetch_array()) {
    echo "- " . $row[0] . "\n";
}
?>
