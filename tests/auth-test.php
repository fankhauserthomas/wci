<?php
// auth-test.php - Test der Authentifizierung
require_once 'auth-simple.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    if (AuthManager::authenticate($password)) {
        $message = "✅ Login erfolgreich!";
        $success = true;
    } else {
        $message = "❌ Falsches Passwort!";
        $success = false;
    }
} else if (isset($_GET['logout'])) {
    AuthManager::logout();
    $message = "🚪 Abgemeldet!";
    $success = false;
}

$isAuthenticated = AuthManager::isAuthenticated();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Auth Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .container { max-width: 600px; margin: 0 auto; }
        .success { color: green; background: #d4edda; padding: 10px; border-radius: 4px; }
        .error { color: red; background: #f8d7da; padding: 10px; border-radius: 4px; }
        .info { color: blue; background: #d1ecf1; padding: 10px; border-radius: 4px; }
        input, button { padding: 8px; margin: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Authentifizierung Test</h1>
        
        <?php if (isset($message)): ?>
            <div class="<?php echo $success ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="info">
            <strong>Status:</strong> <?php echo $isAuthenticated ? 'Eingeloggt ✅' : 'Nicht eingeloggt ❌'; ?>
        </div>
        
        <?php if (!$isAuthenticated): ?>
            <h3>Login:</h3>
            <form method="POST">
                <input type="password" name="password" placeholder="Passwort eingeben..." required>
                <button type="submit">Anmelden</button>
            </form>
            <p><small>Test-Passwort: er1234tf</small></p>
        <?php else: ?>
            <h3>Eingeloggt:</h3>
            <p>Session-Zeit: <?php echo isset($_SESSION['auth_time']) ? date('Y-m-d H:i:s', $_SESSION['auth_time']) : 'Unbekannt'; ?></p>
            <p>IP-Adresse: <?php echo isset($_SESSION['user_ip']) ? $_SESSION['user_ip'] : 'Unbekannt'; ?></p>
            <a href="?logout=1">🚪 Abmelden</a>
        <?php endif; ?>
        
        <hr>
        <p><a href="index.php">← Zurück zum Dashboard</a></p>
    </div>
</body>
</html>
