<?php
require_once 'config.php';

echo "=== Überprüfung der DATETIME-Felder ===\n\n";

// Schaue nach problematischen email_date Werten
$sql = "SELECT email_date, COUNT(*) as cnt FROM `AV-Res` WHERE av_id > 0 GROUP BY email_date LIMIT 10";
$result = $mysqli->query($sql);

echo "email_date Werte in AV-Res:\n";
while ($row = $result->fetch_assoc()) {
    $emailDate = $row['email_date'];
    $cnt = $row['cnt'];
    if ($emailDate === null) {
        echo "NULL: $cnt\n";
    } else if ($emailDate === '') {
        echo "EMPTY STRING: $cnt\n";
    } else {
        echo "'$emailDate': $cnt\n";
    }
}

echo "\n";

// Schaue nach problematischen email_date Werten in WebImp
$sql2 = "SELECT email_date, COUNT(*) as cnt FROM `AV-Res-webImp` GROUP BY email_date LIMIT 10";
$result2 = $mysqli->query($sql2);

echo "email_date Werte in AV-Res-webImp:\n";
while ($row = $result2->fetch_assoc()) {
    $emailDate = $row['email_date'];
    $cnt = $row['cnt'];
    if ($emailDate === null) {
        echo "NULL: $cnt\n";
    } else if ($emailDate === '') {
        echo "EMPTY STRING: $cnt\n";
    } else {
        echo "'$emailDate': $cnt\n";
    }
}

// Teste den problematischen SELECT
echo "\n=== Test Query ===\n";

try {
    $testQuery = $mysqli->query("
        SELECT av_id, email_date 
        FROM `AV-Res` 
        WHERE av_id > 0 AND (email_date = '' OR email_date = '0000-00-00 00:00:00')
        LIMIT 5
    ");
    
    echo "Problematische DATETIME-Werte gefunden:\n";
    while ($row = $testQuery->fetch_assoc()) {
        echo "av_id {$row['av_id']}: '{$row['email_date']}'\n";
    }
} catch (Exception $e) {
    echo "Query Fehler: " . $e->getMessage() . "\n";
}

$mysqli->close();
?>
