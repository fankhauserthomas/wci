<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Login Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .container { max-width: 600px; margin: 0 auto; }
        input, button { padding: 10px; margin: 5px; }
        .success { color: green; background: #d4edda; padding: 10px; border-radius: 4px; }
        .error { color: red; background: #f8d7da; padding: 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Login Test</h1>
        
        <div id="status">Bereit zum Testen...</div>
        
        <h3>1. Test Login-Backend:</h3>
        <input type="password" id="testPassword" placeholder="Passwort: er1234tf">
        <button onclick="testLogin()">Login Testen</button>
        
        <h3>2. Test Session:</h3>
        <button onclick="checkSession()">Session Prüfen</button>
        
        <h3>3. Test Logout:</h3>
        <button onclick="testLogout()">Logout Testen</button>
        
        <hr>
        <p><a href="index.php">← Zurück zum Dashboard</a></p>
        <p><a href="login.html">→ Zur Login-Seite</a></p>
    </div>

    <script>
        function updateStatus(message, isError = false) {
            const status = document.getElementById('status');
            status.innerHTML = message;
            status.className = isError ? 'error' : 'success';
        }

        async function testLogin() {
            const password = document.getElementById('testPassword').value || 'er1234tf';
            
            try {
                updateStatus('🔄 Login wird getestet...');
                
                const response = await fetch('authenticate-simple.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ password: password })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    updateStatus('✅ Login erfolgreich: ' + result.message);
                } else {
                    updateStatus('❌ Login fehlgeschlagen: ' + result.message, true);
                }
            } catch (error) {
                updateStatus('❌ Verbindungsfehler: ' + error.message, true);
            }
        }

        async function checkSession() {
            try {
                updateStatus('🔄 Session wird geprüft...');
                
                const response = await fetch('checkAuth-simple.php');
                const result = await response.json();
                
                if (result.authenticated) {
                    updateStatus('✅ Session aktiv: Benutzer ist angemeldet');
                } else {
                    updateStatus('❌ Session inaktiv: Benutzer ist nicht angemeldet', true);
                }
            } catch (error) {
                updateStatus('❌ Session-Check fehlgeschlagen: ' + error.message, true);
            }
        }

        async function testLogout() {
            try {
                updateStatus('🔄 Logout wird getestet...');
                
                const response = await fetch('logout-simple.php');
                const result = await response.json();
                
                if (result.success) {
                    updateStatus('✅ Logout erfolgreich: ' + result.message);
                } else {
                    updateStatus('❌ Logout fehlgeschlagen: ' + result.message, true);
                }
            } catch (error) {
                updateStatus('❌ Logout-Fehler: ' + error.message, true);
            }
        }
    </script>
</body>
</html>
