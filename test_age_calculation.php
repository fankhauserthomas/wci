<?php
require_once 'config.php';

// MySQL Error Mode lockern (wie in reservierungen/api/getReservationNames.php)
$mysqli->query("SET sql_mode = ''");
$mysqli->query("SET SESSION sql_mode = ''");

// Check Ages table
echo "=== Ages Table Data ===\n";
$result = $mysqli->query("SELECT * FROM Ages ORDER BY von");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, Bezeichnung: {$row['bez']}, Von: {$row['von']}, Bis: {$row['bis']}\n";
    }
} else {
    echo "Error: " . $mysqli->error . "\n";
}

echo "\n=== Test Age Calculation ===\n";
// Test age calculation for a specific person
$testSql = "
SELECT
  n.id,
  n.nachname,
  n.vorname,
  n.gebdat,
  TIMESTAMPDIFF(YEAR, n.gebdat, CURDATE()) as calculated_age,
  CASE 
    WHEN n.gebdat IS NULL OR n.gebdat = '0000-00-00' THEN ''
    ELSE COALESCE(ag.bez, CONCAT(TIMESTAMPDIFF(YEAR, n.gebdat, CURDATE()), ' J.'))
  END AS alter_bez,
  CASE 
    WHEN n.gebdat IS NULL OR n.gebdat = '0000-00-00' THEN 0
    ELSE 
      COALESCE(
        (SELECT id FROM Ages WHERE TIMESTAMPDIFF(YEAR, n.gebdat, CURDATE()) BETWEEN von AND bis LIMIT 1),
        0
      )
  END AS ageGrp
FROM `AV-ResNamen` AS n
LEFT JOIN `Ages` AS ag ON TIMESTAMPDIFF(YEAR, n.gebdat, CURDATE()) BETWEEN ag.von AND ag.bis
WHERE n.gebdat IS NOT NULL AND n.gebdat != '0000-00-00' AND n.gebdat > '1900-01-01'
LIMIT 5
";

$result = $mysqli->query($testSql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "Name: {$row['vorname']} {$row['nachname']}, ";
        echo "Geburtsdatum: {$row['gebdat']}, ";
        echo "Berechnet: {$row['calculated_age']} Jahre, ";
        echo "Anzeige: '{$row['alter_bez']}', ";
        echo "Gruppe: {$row['ageGrp']}\n";
    }
} else {
    echo "Error: " . $mysqli->error . "\n";
}

$mysqli->close();
?>
