<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dashboard-Statistiken Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .container { max-width: 800px; margin: 0 auto; }
        .success { color: green; background: #d4edda; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { color: red; background: #f8d7da; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { color: blue; background: #d1ecf1; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .stat-box { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0; }
        button { padding: 10px 20px; margin: 5px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        input { padding: 8px; margin: 5px; border: 1px solid #ccc; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Dashboard-Statistiken Test</h1>
        
        <div id="status" class="info">Bereit zum Testen...</div>
        
        <h3>Test Dashboard-Statistiken:</h3>
        <input type="date" id="testDate" value="<?php echo date('Y-m-d'); ?>">
        <button onclick="testDashboardStats()">Statistiken laden</button>
        <button onclick="testWithoutAuth()">Test ohne Auth</button>
        
        <div id="results"></div>
        
        <hr>
        <p><a href="index.php">← Zurück zum Dashboard</a></p>
    </div>

    <script>
        function updateStatus(message, type = 'info') {
            const status = document.getElementById('status');
            status.innerHTML = message;
            status.className = type;
        }

        async function testDashboardStats() {
            const date = document.getElementById('testDate').value || '<?php echo date('Y-m-d'); ?>';
            
            try {
                updateStatus('🔄 Lade Dashboard-Statistiken...', 'info');
                
                const response = await fetch(`getDashboardStats-simple.php?date=${date}`);
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const stats = await response.json();
                
                if (stats.error) {
                    updateStatus('❌ Fehler: ' + stats.error, 'error');
                    return;
                }
                
                updateStatus('✅ Statistiken erfolgreich geladen!', 'success');
                displayStats(stats);
                
            } catch (error) {
                updateStatus('❌ Verbindungsfehler: ' + error.message, 'error');
            }
        }

        async function testWithoutAuth() {
            try {
                updateStatus('🔄 Teste Statistiken ohne Authentifizierung...', 'info');
                
                // Zuerst abmelden
                await fetch('logout.php');
                
                // Dann Statistiken testen
                const date = document.getElementById('testDate').value || '<?php echo date('Y-m-d'); ?>';
                const response = await fetch(`getDashboardStats-simple.php?date=${date}`);
                
                if (response.status === 401) {
                    updateStatus('✅ Authentifizierung funktioniert: Zugriff ohne Login verweigert', 'success');
                } else if (response.ok) {
                    updateStatus('⚠️ Warnung: Zugriff ohne Authentifizierung möglich', 'error');
                    const stats = await response.json();
                    displayStats(stats);
                } else {
                    updateStatus('❌ Unerwarteter Fehler: ' + response.statusText, 'error');
                }
                
            } catch (error) {
                updateStatus('❌ Test-Fehler: ' + error.message, 'error');
            }
        }

        function displayStats(stats) {
            const results = document.getElementById('results');
            results.innerHTML = `
                <div class="stat-box">
                    <h4>📅 Statistiken für ${stats.date}</h4>
                    <p><strong>Anreisen heute:</strong> ${stats.arrivals_today.reservations} Reservierungen / ${stats.arrivals_today.guests} Gäste</p>
                    <p><strong>Abreisen morgen:</strong> ${stats.departures_tomorrow.reservations} Reservierungen / ${stats.departures_tomorrow.guests} Gäste</p>
                    <p><strong>Gäste im Haus:</strong> ${stats.current_guests}</p>
                    <p><strong>Ausstehende Check-ins:</strong> ${stats.pending_checkins}</p>
                </div>
            `;
        }

        // Auto-Test beim Laden
        document.addEventListener('DOMContentLoaded', () => {
            // Automatisch testen nach 1 Sekunde
            setTimeout(testDashboardStats, 1000);
        });
    </script>
</body>
</html>
