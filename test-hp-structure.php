<?php
// test-hp-structure.php - Test der HP-Datenbankstruktur
require_once 'hp-db-config.php';

$hpConn = getHpDbConnection();
if (!$hpConn) {
    echo "HP-Datenbank nicht verfügbar\n";
    exit;
}

echo "=== HP-Datenbank Tabellen ===\n";
$tables = $hpConn->query("SHOW TABLES");
if ($tables) {
    while ($table = $tables->fetch_array()) {
        echo "- " . $table[0] . "\n";
    }
}

echo "\n=== hp_data Spalten ===\n";
$columns = $hpConn->query("SHOW COLUMNS FROM hp_data");
if ($columns) {
    while ($col = $columns->fetch_assoc()) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
}

echo "\n=== Beispieldaten aus hp_data (erste 3 Zeilen) ===\n";
$sample = $hpConn->query("SELECT * FROM hp_data LIMIT 3");
if ($sample) {
    while ($row = $sample->fetch_assoc()) {
        echo "ID: " . $row['iid'] . ", ResID: " . ($row['resid'] ?? 'NULL') . ", Name: " . ($row['nam'] ?? 'NULL') . "\n";
    }
}

echo "\n=== hpdet Spalten ===\n";
$detColumns = $hpConn->query("SHOW COLUMNS FROM hpdet");
if ($detColumns) {
    while ($col = $detColumns->fetch_assoc()) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
}

echo "\n=== hparr Spalten ===\n";
$arrColumns = $hpConn->query("SHOW COLUMNS FROM hparr");
if ($arrColumns) {
    while ($col = $arrColumns->fetch_assoc()) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
}

echo "\n=== Verfügbare Arrangements ===\n";
$arrangements = $hpConn->query("SELECT iid, bez FROM hparr ORDER BY sort, bez");
if ($arrangements) {
    while ($arr = $arrangements->fetch_assoc()) {
        echo "- " . $arr['iid'] . ": " . $arr['bez'] . "\n";
    }
}
?>
