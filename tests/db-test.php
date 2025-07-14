<?php
// db-test.php - Datenbankverbindung testen
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Datenbank Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .container { max-width: 600px; margin: 0 auto; }
        .success { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗄️ Datenbank Test</h1>
        
        <?php
        try {
            require_once 'config-simple.php';
            
            if (isset($mysqli) && $mysqli instanceof mysqli) {
                echo '<p class="success">✅ Datenbankverbindung erfolgreich!</p>';
                echo '<p>Server Info: ' . htmlspecialchars($mysqli->server_info) . '</p>';
                echo '<p>Host Info: ' . htmlspecialchars($mysqli->host_info) . '</p>';
                
                // Test-Query
                $result = $mysqli->query("SELECT COUNT(*) as count FROM `AV-Res` LIMIT 1");
                if ($result) {
                    $row = $result->fetch_assoc();
                    echo '<p class="success">✅ Test-Query erfolgreich: ' . $row['count'] . ' Reservierungen gefunden</p>';
                } else {
                    echo '<p class="error">❌ Test-Query fehlgeschlagen: ' . $mysqli->error . '</p>';
                }
                
            } else {
                echo '<p class="error">❌ Datenbankverbindung fehlgeschlagen</p>';
            }
            
        } catch (Exception $e) {
            echo '<p class="error">❌ Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        ?>
        
        <hr>
        <p><a href="index.php">← Zurück zum Dashboard</a></p>
    </div>
</body>
</html>
