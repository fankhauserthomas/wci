<?php
// test.php - Basis PHP-Test
?>
<!DOCTYPE html>
<html>
<head>
    <title>PHP Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .ok { color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>PHP Basis-Test</h1>
    
    <h2>System-Information:</h2>
    <ul>
        <li class="ok">✅ PHP Version: <?php echo phpversion(); ?></li>
        <li class="ok">✅ Server: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unbekannt'; ?></li>
        <li class="ok">✅ Document Root: <?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Unbekannt'; ?></li>
        <li class="ok">✅ Current Script: <?php echo __FILE__; ?></li>
        <li class="ok">✅ Datum/Zeit: <?php echo date('Y-m-d H:i:s'); ?></li>
    </ul>
    
    <h2>PHP-Extensions:</h2>
    <ul>
        <li><?php echo extension_loaded('mysqli') ? '✅' : '❌'; ?> MySQLi</li>
        <li><?php echo extension_loaded('pdo') ? '✅' : '❌'; ?> PDO</li>
        <li><?php echo extension_loaded('session') ? '✅' : '❌'; ?> Sessions</li>
        <li><?php echo extension_loaded('json') ? '✅' : '❌'; ?> JSON</li>
    </ul>
    
    <h2>Datei-Tests:</h2>
    <ul>
        <li><?php echo file_exists('config.php') ? '✅' : '❌'; ?> config.php</li>
        <li><?php echo file_exists('auth.php') ? '✅' : '❌'; ?> auth.php</li>
        <li><?php echo file_exists('login.html') ? '✅' : '❌'; ?> login.html</li>
        <li><?php echo file_exists('reservierungen.html') ? '✅' : '❌'; ?> reservierungen.html</li>
    </ul>
    
    <p><strong>Fazit:</strong> <span class="ok">PHP läuft korrekt!</span></p>
    
    <p><a href="index.php">→ Zurück zum Dashboard</a></p>
</body>
</html>
