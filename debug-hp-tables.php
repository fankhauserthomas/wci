<?php
require_once 'hp-db-config.php';

$hpConn = getHpDbConnection();
if (!$hpConn) {
    die('HP-Datenbank nicht verfügbar');
}

echo "<h2>Verfügbare Tabellen in fsh-res:</h2>";
$result = $hpConn->query("SHOW TABLES");
while ($row = $result->fetch_array()) {
    echo $row[0] . "<br>";
}

echo "<h2>Struktur von hpdet:</h2>";
$result = $hpConn->query("DESCRIBE hpdet");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . "<br>";
    }
} else {
    echo "Tabelle hpdet existiert nicht<br>";
}

echo "<h2>Alle Tabellen mit 'hp' im Namen:</h2>";
$result = $hpConn->query("SHOW TABLES LIKE '%hp%'");
while ($row = $result->fetch_array()) {
    echo $row[0] . "<br>";
}
?>
