<?php
// debug-db.php - Direkter Datenbank-Test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Datenbank Debug</h1>";
echo "<p>Zeit: " . date('Y-m-d H:i:s') . "</p>";

echo "<h3>1. Config laden:</h3>";
try {
    require_once __DIR__ . '/config-simple.php';
    echo "✅ config-simple.php geladen<br>";
} catch (Exception $e) {
    echo "❌ Config-Fehler: " . $e->getMessage() . "<br>";
    die();
}

echo "<h3>2. MySQL Verbindung:</h3>";
if (isset($mysqli) && $mysqli instanceof mysqli) {
    echo "✅ MySQLi-Objekt vorhanden<br>";
    echo "Host: " . $mysqli->host_info . "<br>";
    echo "Server: " . $mysqli->server_info . "<br>";
    
    if ($mysqli->connect_error) {
        echo "❌ Connect Error: " . $mysqli->connect_error . "<br>";
    } else {
        echo "✅ Verbindung OK<br>";
    }
} else {
    echo "❌ Kein MySQLi-Objekt<br>";
    die();
}

echo "<h3>3. Test Query:</h3>";
try {
    $result = $mysqli->query("SHOW TABLES");
    if ($result) {
        echo "✅ SHOW TABLES erfolgreich<br>";
        echo "Tabellen: " . $result->num_rows . "<br>";
        
        while ($row = $result->fetch_row()) {
            echo "- " . $row[0] . "<br>";
        }
    } else {
        echo "❌ SHOW TABLES fehlgeschlagen: " . $mysqli->error . "<br>";
    }
} catch (Exception $e) {
    echo "❌ Query-Fehler: " . $e->getMessage() . "<br>";
}

echo "<h3>4. AV-Res Table Test:</h3>";
try {
    $result = $mysqli->query("SELECT COUNT(*) as total FROM `AV-Res` LIMIT 1");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✅ AV-Res gefunden: " . $row['total'] . " Einträge<br>";
    } else {
        echo "❌ AV-Res Query fehlgeschlagen: " . $mysqli->error . "<br>";
    }
} catch (Exception $e) {
    echo "❌ AV-Res Fehler: " . $e->getMessage() . "<br>";
}

echo "<h3>5. getDashboardStats-noauth.php Test:</h3>";
echo '<a href="getDashboardStats-noauth.php" target="_blank">📊 Direkt aufrufen</a><br>';

echo '<p><a href="index.php">← Zurück zum Dashboard</a></p>';
?>
