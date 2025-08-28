<?php
require_once 'config.php';

echo "=== Analyse der av_id Werte in AV-Res ===\n\n";

// Überprüfe av_id Verteilung
$sql = "SELECT av_id, COUNT(*) as anzahl FROM `AV-Res` GROUP BY av_id ORDER BY anzahl DESC LIMIT 20";
$result = $mysqli->query($sql);

echo "Top 20 av_id Werte und ihre Häufigkeit:\n";
echo "av_id\t\tAnzahl\n";
echo "-----\t\t------\n";

while ($row = $result->fetch_assoc()) {
    echo "{$row['av_id']}\t\t{$row['anzahl']}\n";
}

// Überprüfe WebImp Daten
echo "\n\n=== Analyse der av_id Werte in AV-Res-webImp ===\n\n";

$sql2 = "SELECT av_id, COUNT(*) as anzahl FROM `AV-Res-webImp` GROUP BY av_id ORDER BY anzahl DESC LIMIT 20";
$result2 = $mysqli->query($sql2);

echo "Top 20 av_id Werte und ihre Häufigkeit:\n";
echo "av_id\t\tAnzahl\n";
echo "-----\t\t------\n";

while ($row = $result2->fetch_assoc()) {
    echo "{$row['av_id']}\t\t{$row['anzahl']}\n";
}

// Schaue welche av_id > 0 in beiden Tabellen existieren
echo "\n\n=== Duplikate zwischen AV-Res und AV-Res-webImp (av_id > 0) ===\n\n";

$sql3 = "SELECT 
    w.av_id,
    w.vorname as webimp_vorname,
    w.nachname as webimp_nachname,
    w.anreise as webimp_anreise,
    r.vorname as res_vorname,
    r.nachname as res_nachname,
    r.anreise as res_anreise
FROM `AV-Res-webImp` w
LEFT JOIN `AV-Res` r ON w.av_id = r.av_id
WHERE w.av_id > 0 AND r.av_id IS NOT NULL
ORDER BY w.av_id
LIMIT 10";

$result3 = $mysqli->query($sql3);

if ($result3->num_rows > 0) {
    echo "Gefundene Duplikate (erste 10):\n";
    while ($row = $result3->fetch_assoc()) {
        echo "AV-ID {$row['av_id']}: WebImp='{$row['webimp_nachname']}, {$row['webimp_vorname']}' ({$row['webimp_anreise']}) <-> Res='{$row['res_nachname']}, {$row['res_vorname']}' ({$row['res_anreise']})\n";
    }
} else {
    echo "Keine Duplikate gefunden.\n";
}

// Überprüfe aktuelle Indexes auf AV-Res
echo "\n\n=== Aktuelle Indexes auf AV-Res ===\n\n";

$sql4 = "SHOW INDEX FROM `AV-Res`";
$result4 = $mysqli->query($sql4);

while ($row = $result4->fetch_assoc()) {
    echo "Key: {$row['Key_name']}, Column: {$row['Column_name']}, Unique: {$row['Non_unique']}\n";
}

$mysqli->close();
?>
