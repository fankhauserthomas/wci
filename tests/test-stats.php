<?php
// test-stats.php - Direkter Test der Dashboard-Statistiken
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Dashboard Statistiken Test</h1>";

echo "<h3>1. Config-Test:</h3>";
try {
    require_once __DIR__ . '/../config.php';
    echo "✅ config.php geladen<br>";
    
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        echo "✅ MySQL-Verbindung vorhanden<br>";
        echo "Server: " . $mysqli->server_info . "<br>";
    } else {
        echo "❌ Keine MySQL-Verbindung<br>";
    }
} catch (Exception $e) {
    echo "❌ Config-Fehler: " . $e->getMessage() . "<br>";
}

echo "<h3>2. Einfache Query:</h3>";
try {
    $result = $mysqli->query("SELECT COUNT(*) as total FROM `AV-Res` LIMIT 1");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✅ Basis-Query erfolgreich: " . $row['total'] . " Reservierungen total<br>";
    } else {
        echo "❌ Query fehlgeschlagen: " . $mysqli->error . "<br>";
    }
} catch (Exception $e) {
    echo "❌ Query-Fehler: " . $e->getMessage() . "<br>";
}

echo "<h3>3. Statistiken für heute:</h3>";
$today = date('Y-m-d');
echo "Datum: " . $today . "<br>";

try {
    $sql = "SELECT COUNT(*) as count, COALESCE(SUM(sonder + betten + dz + lager), 0) as guests FROM `AV-Res` WHERE DATE(anreise) = ? AND (storno = 0 OR storno IS NULL)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    echo "✅ Anreisen heute: " . $data['count'] . " Reservierungen / " . $data['guests'] . " Gäste<br>";
} catch (Exception $e) {
    echo "❌ Statistik-Fehler: " . $e->getMessage() . "<br>";
}

echo "<h3>4. JSON-Output Test:</h3>";
$testStats = [
    'arrivals_today' => ['reservations' => 3, 'guests' => 8],
    'departures_tomorrow' => ['reservations' => 2, 'guests' => 5],
    'current_guests' => 12,
    'pending_checkins' => 1
];

echo "<pre>" . json_encode($testStats, JSON_PRETTY_PRINT) . "</pre>";

echo '<p><a href="index.php">← Zurück zum Dashboard</a></p>';
?>
