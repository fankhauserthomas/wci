<?php
require_once 'config.php';

echo "=== TESTING EXACT PARAMETER COUNT ===\n";

// Test the exact INSERT we want to do
$av_id = 5484399;
$anreise = '2025-08-20';
$abreise = '2025-08-21';
$lager = 2;
$betten = 0;
$dz = 0;
$sonder = 0;
$hp = 1;
$vegi = 0;
$gruppe = 'Manion';
$nachname = 'Manion';
$vorname = 'Harry';
$handy = '+4915751828440';
$email = 'manionharry@gmail.com';
$vorgang = 'CONFIRMED';
$email_date = '2025-08-22 10:29:00';

$insertSql = "INSERT INTO `AV-Res-webImp` (
    av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
    gruppe, nachname, vorname, handy, email, vorgang, email_date
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

echo "SQL:\n$insertSql\n\n";

$params = array($av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder, $hp, $vegi,
               $gruppe, $nachname, $vorname, $handy, $email, $vorgang, $email_date);

echo "Parameter count: " . count($params) . "\n";
echo "Placeholder count: " . substr_count($insertSql, '?') . "\n\n";

echo "Parameters and their types:\n";
foreach ($params as $i => $param) {
    $type = is_int($param) ? 'i' : 's';
    echo sprintf("%2d: %-20s (type: %s)\n", $i+1, var_export($param, true), $type);
}

echo "\nType string: ";
foreach ($params as $param) {
    echo is_int($param) ? 'i' : 's';
}
echo "\n";

echo "Type string length: " . strlen(implode('', array_map(function($p) { return is_int($p) ? 'i' : 's'; }, $params))) . "\n";

// Test the actual INSERT
echo "\n=== TESTING ACTUAL INSERT ===\n";
try {
    $stmt = $mysqli->prepare($insertSql);
    if (!$stmt) {
        echo "Prepare failed: " . $mysqli->error . "\n";
        exit(1);
    }
    
    $typeString = '';
    foreach ($params as $param) {
        $typeString .= is_int($param) ? 'i' : 's';
    }
    
    echo "Using type string: $typeString\n";
    
    $stmt->bind_param($typeString, ...$params);
    
    if ($stmt->execute()) {
        echo "INSERT successful! Inserted ID: " . $mysqli->insert_id . "\n";
    } else {
        echo "INSERT failed: " . $stmt->error . "\n";
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
?>
