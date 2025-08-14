<?php
require 'config.php';

$mysqli = $GLOBALS['mysqli'];

echo "Struktur der Tabelle AV-ResNamen:\n";
$result = $mysqli->query("DESCRIBE `AV-ResNamen`");
while ($row = $result->fetch_assoc()) {
    echo "- {$row['Field']} ({$row['Type']}) - {$row['Null']} - {$row['Key']}\n";
}
?>
