<?php
require_once __DIR__ . '/config.php';

echo "Prüfe hut_quota Tabelle...\n\n";

// Tabellenstruktur prüfen
$result = $mysqli->query("DESCRIBE hut_quota");
echo "=== TABELLENSTRUKTUR ===\n";
while ($row = $result->fetch_assoc()) {
    echo "- {$row['Field']} ({$row['Type']})\n";
}

echo "\n=== BEISPIEL-DATEN ===\n";
$result = $mysqli->query("SELECT * FROM hut_quota WHERE id IN (2133, 2132) LIMIT 2");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    foreach ($row as $key => $value) {
        echo "- $key: $value\n";
    }
} else {
    echo "Keine Daten mit IDs 2133, 2132 gefunden\n";
}

echo "\n=== ALLE SPALTEN MIT DATUM ===\n";
$result = $mysqli->query("DESCRIBE hut_quota");
while ($row = $result->fetch_assoc()) {
    if (stripos($row['Field'], 'dat') !== false || stripos($row['Field'], 'date') !== false) {
        echo "- {$row['Field']} ({$row['Type']})\n";
    }
}
?>
