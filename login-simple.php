<?php
// login-simple.php - Einfache Login-Seite

require_once __DIR__ . '/auth.php';

// Prüfe ob bereits eingeloggt
if (AuthManager::checkSession()) {
    // Weiterleitung zur ursprünglich angeforderten Seite oder Dashboard
    $redirect = $_GET['redirect'] ?? 'index.php';
    header('Location: ' . $redirect);
    exit;
}

// Verarbeite Login-Versuch
$error = '';
if ($_POST['password'] ?? '') {
    if (AuthManager::authenticate($_POST['password'])) {
        $redirect = $_GET['redirect'] ?? 'index.php';
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = 'Falsches Passwort!';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WCI - Login</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        
        .login-header {
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #333;
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        
        .login-header p {
            color: #666;
            margin: 10px 0 0 0;
            font-size: 14px;
        }
        
        .form-group {
            margin: 20px 0;
            text-align: left;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .login-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
        }
        
        .error {
            background: #ffe6e6;
            color: #d00;
            padding: 10px;
            border-radius: 4px;
            margin: 15px 0;
            border: 1px solid #ffcccc;
        }
        
        .security-note {
            margin-top: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            font-size: 12px;
            color: #666;
        }
        
        .icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="icon">🔒</div>
            <h1>WCI System</h1>
            <p>Ultra-Secure File Safety Analyzer</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="password">Passwort:</label>
                <input type="password" id="password" name="password" required autofocus>
            </div>
            
            <button type="submit" class="login-btn">🔓 Anmelden</button>
        </form>
        
        <div class="security-note">
            <strong>🛡️ Sicherheitshinweis:</strong><br>
            Diese Seite ist durch erweiterte Sicherheitsmaßnahmen geschützt.<br>
            Zugriff nur für autorisierte Benutzer.
        </div>
    </div>
    
    <script>
        // Auto-Focus auf Passwort-Feld
        document.getElementById('password').focus();
        
        // Enter-Taste Submit
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('form').submit();
            }
        });
    </script>
</body>
</html>
