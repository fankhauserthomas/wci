<?php
require_once 'config.php';

echo "=== CHECKING AV-Res-webImp TABLE STRUCTURE ===\n";

$result = $mysqli->query("DESCRIBE `AV-Res-webImp`");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . " - " . $row['Type'] . " - " . ($row['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    }
} else {
    echo "Error: " . $mysqli->error . "\n";
}

echo "\n=== TESTING INSERT STATEMENT ===\n";

// Test values
$av_id = "TEST123";
$anreise = "2025-08-20";
$abreise = "2025-08-21";
$lager = 2;
$betten = 0;
$dz = 0;
$sonder = 0;
$hp = 1;
$vegi = 0;
$vorname = "Test";
$nachname = "User";
$gruppe = "TestGroup";
$handy = "+123456789";
$email = "test@example.com";
$email_date = "2025-08-22 10:22:00";
$vorgang = "CONFIRMED";

$insertSql = "INSERT INTO `AV-Res-webImp` (
    av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
    vorname, nachname, gruppe, handy, email, email_date, vorgang
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

echo "SQL: $insertSql\n";
echo "Parameter count: " . substr_count($insertSql, '?') . "\n";

$params = array($av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder, $hp, $vegi,
               $vorname, $nachname, $gruppe, $handy, $email, $email_date, $vorgang);
echo "Values count: " . count($params) . "\n";

echo "Parameters:\n";
foreach ($params as $i => $param) {
    $type = is_int($param) ? 'i' : 's';
    echo "  " . ($i+1) . ": $param (type: $type)\n";
}

echo "\nType string should be: ";
foreach ($params as $param) {
    echo is_int($param) ? 'i' : 's';
}
echo "\n";

?>
