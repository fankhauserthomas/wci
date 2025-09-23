<?php
require_once 'config.php';

echo "=== TABELLEN ANZEIGEN ===\n";
$result = $mysqli->query("SHOW TABLES LIKE '%Res%'");
while ($row = $result->fetch_array()) {
    echo "Tabelle: " . $row[0] . "\n";
}

echo "\n=== AV_RESDET STRUKTUR ===\n";
$result = $mysqli->query("DESCRIBE AV_ResDet");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "Fehler: " . $mysqli->error . "\n";
}
?>