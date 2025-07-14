<?php
// db-test-quick.php - Schneller Datenbanktest mit Timeouts
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Schneller DB Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .container { max-width: 600px; margin: 0 auto; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗄️ Schneller Datenbank Test</h1>
        
        <?php
        $startTime = microtime(true);
        
        try {
            // Set a short timeout
            ini_set('default_socket_timeout', 5);
            
            echo '<p>🔄 Teste Datenbankverbindung...</p>';
            
            $dbHost = 'booking.franzsennhuette.at';
            $dbUser = 'booking_franzsen';
            $dbPass = '~2Y@76';
            $dbName = 'booking_franzsen';
            
            // Try to connect with timeout
            $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
            
            if ($mysqli->connect_error) {
                echo '<p class="error">❌ Verbindung fehlgeschlagen: ' . htmlspecialchars($mysqli->connect_error) . '</p>';
            } else {
                echo '<p class="success">✅ Datenbankverbindung erfolgreich!</p>';
                echo '<p>Server Info: ' . htmlspecialchars($mysqli->server_info) . '</p>';
                
                // Quick test query with timeout
                $mysqli->options(MYSQLI_OPT_READ_TIMEOUT, 3);
                $result = $mysqli->query("SELECT 1 as test LIMIT 1");
                if ($result) {
                    echo '<p class="success">✅ Test-Query erfolgreich</p>';
                } else {
                    echo '<p class="error">❌ Test-Query fehlgeschlagen: ' . $mysqli->error . '</p>';
                }
                
                $mysqli->close();
            }
            
        } catch (Exception $e) {
            echo '<p class="error">❌ Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        
        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        echo '<p>⏱️ Test-Dauer: ' . $duration . ' ms</p>';
        ?>
        
        <hr>
        <p><a href="index.php">← Zurück zum Dashboard</a></p>
        <p><a href="minimal-test.php">→ Minimal Test</a></p>
    </div>
</body>
</html>
