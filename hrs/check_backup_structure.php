<?php
require_once '../config.php';

$table = 'AV_Res_Backup_2025-08-28_02-39-48';

echo "Checking structure of table: $table\n";

$result = $mysqli->query("DESCRIBE `$table`");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} else {
    echo "Error: " . $mysqli->error . "\n";
}

echo "\nFirst few records:\n";
$result = $mysqli->query("SELECT * FROM `$table` LIMIT 3");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        print_r(array_keys($row));
        break; // Nur die Feldnamen anzeigen
    }
} else {
    echo "Error: " . $mysqli->error . "\n";
}
?>
