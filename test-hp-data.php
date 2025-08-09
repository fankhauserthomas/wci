<?php
// test-hp-data.php - Teste HP-Arrangements für Reservierungen
require_once 'hp-db-config.php';

$hpConn = getHpDbConnection();
if (!$hpConn) {
    echo "HP-Datenbank nicht verfügbar\n";
    exit;
}

echo "=== Reservierungen mit HP-Arrangements ===\n";
$query = "
    SELECT h.resid, h.nam, COUNT(d.id) as arrangement_count 
    FROM hp_data h 
    LEFT JOIN hpdet d ON h.iid = d.hp_id 
    WHERE h.resid IS NOT NULL 
    GROUP BY h.resid 
    HAVING arrangement_count > 0 
    LIMIT 5
";

$result = $hpConn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "ResID: " . $row['resid'] . ", Name: " . $row['nam'] . ", Arrangements: " . $row['arrangement_count'] . "\n";
    }
}

echo "\n=== Details für ResID 6202 ===\n";
$detailQuery = "
    SELECT h.iid, h.nam, d.arr_id, d.anz, d.bem, d.ts,
           TIMESTAMPDIFF(SECOND, d.ts, NOW()) as seconds_ago,
           a.bez as arrangement_name
    FROM hp_data h 
    LEFT JOIN hpdet d ON h.iid = d.hp_id
    LEFT JOIN hparr a ON d.arr_id = a.iid
    WHERE h.resid = 6202
    ORDER BY d.ts DESC
";

$detailResult = $hpConn->query($detailQuery);
if ($detailResult) {
    while ($row = $detailResult->fetch_assoc()) {
        echo "Guest: " . $row['nam'] . ", Arrangement: " . ($row['arrangement_name'] ?? 'None') . 
             ", Count: " . ($row['anz'] ?? '0') . ", Remark: " . ($row['bem'] ?? '') . 
             ", Seconds ago: " . ($row['seconds_ago'] ?? 'N/A') . "\n";
    }
}

echo "\n=== Test der Header-API mit ResID 6202 ===\n";
$testResId = 6202;
include 'get-hp-arrangements-header.php';
?>
