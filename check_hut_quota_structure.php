<?php
require_once __DIR__ . '/config.php';

echo "=== hut_quota Table Structure ===\n\n";

$result = $mysqli->query("SHOW CREATE TABLE hut_quota");
if ($result) {
    $row = $result->fetch_assoc();
    echo $row['Create Table'] . "\n\n";
} else {
    echo "ERROR: " . $mysqli->error . "\n";
}

echo "\n=== Sample Records ===\n\n";
$result = $mysqli->query("SELECT * FROM hut_quota LIMIT 3");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "No records or error: " . $mysqli->error . "\n";
}

?>
