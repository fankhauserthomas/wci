<?php
/**
 * Debug-Seite für Belegungsanalyse
 * Zeigt Rohdaten aus der Datenbank
 */

require_once '../config.php';

$startDate = $_GET['start'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime($startDate . ' +31 days'));

echo "<h1>🔍 Debug: Belegungsdaten</h1>";
echo "<p><strong>Zeitraum:</strong> $startDate bis $endDate</p>";

// Test 1: Grundlegende Reservierungen
echo "<h2>1. Grundlegende Reservierungen (ohne Storno)</h2>";
$sql1 = "SELECT COUNT(*) as anzahl FROM `AV-Res` WHERE (storno IS NULL OR storno != 1)";
$result1 = $mysqli->query($sql1);
$row1 = $result1->fetch_assoc();
echo "<p>Gesamte aktive Reservierungen: <strong>" . $row1['anzahl'] . "</strong></p>";

// Test 2: Reservierungen im Zeitraum (alte Methode)
echo "<h2>2. Anreisen im Zeitraum</h2>";
$sql2 = "SELECT DATE(anreise) as tag, COUNT(*) as anzahl, SUM(sonder+lager+betten+dz) as personen 
         FROM `AV-Res` 
         WHERE anreise >= ? AND anreise <= ? AND (storno IS NULL OR storno != 1)
         GROUP BY DATE(anreise) ORDER BY tag LIMIT 10";
$stmt2 = $mysqli->prepare($sql2);
$stmt2->bind_param('ss', $startDate, $endDate);
$stmt2->execute();
$result2 = $stmt2->get_result();

echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>Datum</th><th>Reservierungen</th><th>Personen</th></tr>";
while ($row2 = $result2->fetch_assoc()) {
    echo "<tr><td>{$row2['tag']}</td><td>{$row2['anzahl']}</td><td>{$row2['personen']}</td></tr>";
}
echo "</table>";

// Test 3: Aufenthalte im Zeitraum (neue Methode)
echo "<h2>3. Personen im Haus (neue Methode)</h2>";
echo "<p><em>Alle Personen die zwischen Anreise und Abreise im Haus sind...</em></p>";

// Vereinfachte Abfrage für Test
$sql3 = "SELECT 
    DATE(anreise) as anreise_tag,
    DATE(abreise) as abreise_tag,
    DATEDIFF(abreise, anreise) as naechte,
    vorname, nachname,
    sonder, lager, betten, dz,
    CASE WHEN av_id > 0 THEN 'HRS' ELSE 'Lokal' END as quelle
FROM `AV-Res` 
WHERE (
    (anreise >= ? AND anreise <= ?) OR 
    (abreise >= ? AND abreise <= ?) OR 
    (anreise <= ? AND abreise >= ?)
) AND (storno IS NULL OR storno != 1)
ORDER BY anreise
LIMIT 20";

$stmt3 = $mysqli->prepare($sql3);
$stmt3->bind_param('ssssss', $startDate, $endDate, $startDate, $endDate, $startDate, $endDate);
$stmt3->execute();
$result3 = $stmt3->get_result();

echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>Name</th><th>Anreise</th><th>Abreise</th><th>Nächte</th><th>Personen</th><th>Quelle</th></tr>";
while ($row3 = $result3->fetch_assoc()) {
    $personen = $row3['sonder'] + $row3['lager'] + $row3['betten'] + $row3['dz'];
    echo "<tr>";
    echo "<td>{$row3['nachname']}, {$row3['vorname']}</td>";
    echo "<td>{$row3['anreise_tag']}</td>";
    echo "<td>{$row3['abreise_tag']}</td>";
    echo "<td>{$row3['naechte']}</td>";
    echo "<td>S:{$row3['sonder']} L:{$row3['lager']} B:{$row3['betten']} D:{$row3['dz']} = {$personen}</td>";
    echo "<td>{$row3['quelle']}</td>";
    echo "</tr>";
}
echo "</table>";

// Test 4: Datums-Generator testen
echo "<h2>4. Datums-Generator Test</h2>";
$sql4 = "SELECT DATE(DATE_ADD(?, INTERVAL seq.seq DAY)) as tag
FROM (
    SELECT 0 as seq UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL
    SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
) seq
WHERE DATE(DATE_ADD(?, INTERVAL seq.seq DAY)) <= ?
ORDER BY tag";

$stmt4 = $mysqli->prepare($sql4);
$stmt4->bind_param('sss', $startDate, $startDate, $endDate);
$stmt4->execute();
$result4 = $stmt4->get_result();

echo "<p>Generierte Tage: ";
$tage = [];
while ($row4 = $result4->fetch_assoc()) {
    $tage[] = $row4['tag'];
}
echo implode(', ', array_slice($tage, 0, 10)) . (count($tage) > 10 ? '... (' . count($tage) . ' total)' : '') . "</p>";

// Test 5: Komplette Join-Abfrage testen
echo "<h2>5. Vollständige Join-Abfrage</h2>";
$sql5 = "SELECT 
    dates.tag,
    COUNT(*) as reservierungen,
    SUM(sonder) as sonder, SUM(lager) as lager, SUM(betten) as betten, SUM(dz) as dz,
    SUM(sonder+lager+betten+dz) as total_personen
FROM (
    SELECT DATE(DATE_ADD(?, INTERVAL seq.seq DAY)) as tag
    FROM (
        SELECT 0 as seq UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL
        SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
    ) seq
    WHERE DATE(DATE_ADD(?, INTERVAL seq.seq DAY)) <= ?
) dates
LEFT JOIN `AV-Res` res ON (
    dates.tag >= DATE(res.anreise) 
    AND dates.tag < DATE(res.abreise)
    AND (res.storno IS NULL OR res.storno != 1)
)
GROUP BY dates.tag
ORDER BY dates.tag
LIMIT 10";

$stmt5 = $mysqli->prepare($sql5);
$stmt5->bind_param('sss', $startDate, $startDate, $endDate);
$stmt5->execute();
$result5 = $stmt5->get_result();

echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
echo "<tr><th>Datum</th><th>Reservierungen</th><th>Sonder</th><th>Lager</th><th>Betten</th><th>DZ</th><th>Total</th></tr>";
while ($row5 = $result5->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row5['tag']}</td>";
    echo "<td>{$row5['reservierungen']}</td>";
    echo "<td>{$row5['sonder']}</td>";
    echo "<td>{$row5['lager']}</td>";
    echo "<td>{$row5['betten']}</td>";
    echo "<td>{$row5['dz']}</td>";
    echo "<td><strong>{$row5['total_personen']}</strong></td>";
    echo "</tr>";
}
echo "</table>";

$mysqli->close();
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { width: 100%; max-width: 800px; }
th, td { padding: 8px; text-align: left; border: 1px solid #ddd; }
th { background-color: #f2f2f2; }
h2 { color: #333; margin-top: 30px; }
</style>
