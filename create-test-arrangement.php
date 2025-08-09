<?php
// create-test-arrangement.php - Erstelle Test-Arrangement für Zeitklassen-Demo
require_once 'hp-db-config.php';

$hpConn = getHpDbConnection();
if (!$hpConn) {
    echo "HP-Datenbank nicht verfügbar\n";
    exit;
}

// Erstelle ein neues Arrangement für Guest ID 36934 mit aktuellem Timestamp
$guestId = 36934; // Oliver Gottschalk
$arrId = 3; // BHP Fleisch
$anz = 2;
$bem = 'Test-Zeitklasse';

$insertQuery = "INSERT INTO hpdet (hp_id, arr_id, anz, bem, ts) VALUES (?, ?, ?, ?, NOW())";
$stmt = $hpConn->prepare($insertQuery);

if ($stmt) {
    $stmt->bind_param("iiis", $guestId, $arrId, $anz, $bem);
    if ($stmt->execute()) {
        echo "✅ Test-Arrangement erstellt: Guest $guestId, Arr $arrId, Count $anz, Remark '$bem'\n";
        echo "Das sollte als 'time-fresh' (rot) angezeigt werden.\n";
    } else {
        echo "❌ Fehler beim Erstellen: " . $stmt->error . "\n";
    }
    $stmt->close();
} else {
    echo "❌ Prepare failed: " . $hpConn->error . "\n";
}

// Zeige aktuelle Zeit für Verifizierung
echo "Aktuelle Zeit: " . date('Y-m-d H:i:s') . "\n";

echo "\n=== Aktualisierte Arrangements für ResID 6202 ===\n";
$testQuery = "
    SELECT h.nam, d.arr_id, d.anz, d.bem, d.ts,
           TIMESTAMPDIFF(SECOND, d.ts, NOW()) as seconds_ago,
           a.bez as arrangement_name
    FROM hp_data h 
    LEFT JOIN hpdet d ON h.iid = d.hp_id
    LEFT JOIN hparr a ON d.arr_id = a.iid
    WHERE h.resid = 6202 AND d.arr_id IS NOT NULL
    ORDER BY d.ts DESC
";

$result = $hpConn->query($testQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $secondsAgo = $row['seconds_ago'];
        $timeClass = 'time-old';
        if ($secondsAgo < 60) {
            $timeClass = 'time-fresh';
        } elseif ($secondsAgo < 120) {
            $timeClass = 'time-recent';
        }
        
        echo "- " . $row['arrangement_name'] . ": " . $row['anz'] . "x";
        if ($row['bem']) {
            echo " (" . $row['bem'] . ")";
        }
        echo " - " . $row['seconds_ago'] . "s ago [$timeClass]\n";
    }
}
?>
