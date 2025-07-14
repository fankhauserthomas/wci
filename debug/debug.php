<?php
// debug.php - Dashboard OHNE Authentifizierung für Testing
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WebCheckin Dashboard (Debug)</title>
  <link rel="stylesheet" href="style.css">
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .debug-info { background: #f0f0f0; padding: 10px; margin-bottom: 20px; border-radius: 5px; }
    .dashboard {
      max-width: 1200px;
      margin: 0 auto;
      padding: 20px;
    }
    
    .dashboard-header {
      text-align: center;
      margin-bottom: 30px;
      position: relative;
    }
    
    .dashboard-title {
      font-size: 2.2rem;
      color: #007bff;
      margin-bottom: 8px;
    }
    
    .dashboard-subtitle {
      font-size: 1.1rem;
      color: #666;
      margin-bottom: 20px;
    }
  </style>
</head>
<body>
  <div class="debug-info">
    <h3>🔧 Debug-Modus</h3>
    <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
    <p><strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></p>
    <p><strong>Document Root:</strong> <?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'; ?></p>
    <p><strong>Current Dir:</strong> <?php echo __DIR__; ?></p>
  </div>

  <main class="dashboard">
    <div class="dashboard-header">
      <h1 class="dashboard-title">🏔️ Franz-Senn-Hütte</h1>
      <p class="dashboard-subtitle">WebCheckin Dashboard - Debug Version</p>
    </div>

    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.08);">
      <h2>🎯 System-Status</h2>
      <ul>
        <li>✅ PHP funktioniert</li>
        <li>✅ HTML wird gerendert</li>
        <li>🔄 Teste Datenbankverbindung...</li>
      </ul>
      
      <h3>📁 Dateien prüfen:</h3>
      <ul>
        <li>auth.php: <?php echo file_exists('auth.php') ? '✅ Vorhanden' : '❌ Fehlt'; ?></li>
        <li>config.php: <?php echo file_exists('config.php') ? '✅ Vorhanden' : '❌ Fehlt'; ?></li>
        <li>login.html: <?php echo file_exists('login.html') ? '✅ Vorhanden' : '❌ Fehlt'; ?></li>
        <li>getDashboardStats.php: <?php echo file_exists('getDashboardStats.php') ? '✅ Vorhanden' : '❌ Fehlt'; ?></li>
      </ul>

      <h3>🔐 Authentifizierung testen:</h3>
      <?php
      try {
          if (file_exists('auth.php')) {
              require_once 'auth.php';
              echo "<p>✅ AuthManager-Klasse geladen</p>";
              echo "<p>Session-Status: " . (AuthManager::isAuthenticated() ? "Eingeloggt" : "Nicht eingeloggt") . "</p>";
          } else {
              echo "<p>❌ auth.php nicht gefunden</p>";
          }
      } catch (Exception $e) {
          echo "<p>❌ Fehler beim Laden der Auth: " . htmlspecialchars($e->getMessage()) . "</p>";
      }
      ?>

      <h3>🗄️ Datenbankverbindung testen:</h3>
      <?php
      try {
          if (file_exists('config.php')) {
              require_once 'config.php';
              if (isset($mysqli) && $mysqli instanceof mysqli) {
                  echo "<p>✅ Datenbankverbindung erfolgreich</p>";
                  echo "<p>Server Info: " . htmlspecialchars($mysqli->server_info) . "</p>";
              } else {
                  echo "<p>❌ Datenbankverbindung fehlgeschlagen</p>";
              }
          } else {
              echo "<p>❌ config.php nicht gefunden</p>";
          }
      } catch (Exception $e) {
          echo "<p>❌ Datenbankfehler: " . htmlspecialchars($e->getMessage()) . "</p>";
      }
      ?>

      <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
        <h3>🚀 Nächste Schritte:</h3>
        <ol>
          <li><a href="test.php">Basis PHP-Test aufrufen</a></li>
          <li><a href="login.html">Login-Seite testen</a></li>
          <li><a href="index.php">Normale index.php testen</a></li>
          <li><a href="getDashboardStats.php">API-Endpoint testen</a></li>
        </ol>
      </div>
    </div>
  </main>
</body>
</html>
