<?php
// index.php - WebCheckin Dashboard mit Authentifizierung
require_once 'auth-simple.php';

// Authentifizierung prüfen
if (!AuthManager::checkSession()) {
    header('Location: login.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WebCheckin - Franz-Senn-Hütte</title>
  <link rel="stylesheet" href="style.css">
  <style>
    body {
      font-family: sans-serif;
      background-color: #ffffff;
      color: #2c3e50;
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    
    *, *::before, *::after {
      box-sizing: border-box;
    }
    
    .dashboard {
      max-width: 1000px;
      margin: 0 auto;
      padding: 20px;
    }
    
    header {
      background: white;
      border-bottom: 1px solid #e9ecef;
      padding: 15px 0;
      margin-bottom: 30px;
    }
    
    .header-content {
      max-width: 1000px;
      margin: 0 auto;
      padding: 0 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    .header-title {
      font-size: 1.5rem;
      color: #2ecc71;
      margin: 0;
    }
    
    .logout-button {
      background: #dc3545;
      color: white;
      border: none;
      padding: 8px 16px;
      border-radius: 6px;
      font-size: 0.9rem;
      cursor: pointer;
      transition: background-color 0.2s ease;
    }
    
    .logout-button:hover {
      background: #c82333;
    }
    
    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    
    .dashboard-card {
      background: white;
      border-radius: 8px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      border: 1px solid #e9ecef;
    }
    
    .dashboard-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    .card-title {
      font-size: 1.2rem;
      font-weight: bold;
      color: #2c3e50;
      margin-bottom: 10px;
    }
    
    .card-description {
      color: #7f8c8d;
      margin-bottom: 20px;
      line-height: 1.5;
      font-size: 0.9rem;
    }
    
    .card-button {
      background: #2ecc71;
      color: white;
      border: none;
      padding: 10px 20px;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      transition: background-color 0.2s ease;
      text-decoration: none;
      display: inline-block;
      width: 100%;
      text-align: center;
    }
    
    .card-button:hover {
      background: #27ae60;
      text-decoration: none;
      color: white;
    }
    
    .card-button.secondary {
      background: #95a5a6;
    }
    
    .card-button.secondary:hover {
      background: #7f8c8d;
    }
    
    .quick-actions {
      display: flex;
      gap: 10px;
      margin-top: 15px;
    }
    
    .quick-actions .card-button {
      flex: 1;
      padding: 8px 12px;
      font-size: 0.85rem;
    }

    /* Matrix-Style Sync-Karte */
    .sync-matrix-card {
      background: #000000;
      border: 1px solid #00ff00;
      box-shadow: 0 0 10px rgba(0, 255, 0, 0.3);
    }

    .sync-matrix-card .card-title {
      color: #00ff00;
      text-shadow: 0 0 5px #00ff00;
    }

    .sync-matrix-card .card-description {
      color: #88ff88;
    }

    .matrix-display {
      background: #000000;
      border: 1px solid #003300;
      border-radius: 4px;
      height: 200px;
      overflow: hidden;
      position: relative;
      font-family: 'Courier New', monospace;
      padding: 8px;
    }

    .matrix-content {
      height: 100%;
      overflow-y: auto;
      scrollbar-width: thin;
      scrollbar-color: #00ff00 #000000;
    }

    .matrix-content::-webkit-scrollbar {
      width: 6px;
    }

    .matrix-content::-webkit-scrollbar-track {
      background: #000000;
    }

    .matrix-content::-webkit-scrollbar-thumb {
      background: #00ff00;
      border-radius: 3px;
    }

    .matrix-line {
      color: #00ff00;
      font-size: 11px;
      line-height: 1.3;
      margin: 1px 0;
      font-family: 'Courier New', monospace;
      text-shadow: 0 0 2px #00ff00;
      opacity: 0.9;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .matrix-line.success {
      color: #00ff00;
    }

    .matrix-line.warning {
      color: #ffff00;
      text-shadow: 0 0 2px #ffff00;
    }

    .matrix-line.error {
      color: #ff0000;
      text-shadow: 0 0 2px #ff0000;
    }

    .matrix-line.info {
      color: #00ffff;
      text-shadow: 0 0 2px #00ffff;
    }

    .matrix-line:before {
      content: '> ';
      opacity: 0.7;
    }

    /* Blinking cursor effect */
    .matrix-cursor {
      animation: blink 1s infinite;
    }

    @keyframes blink {
      0%, 50% { opacity: 1; }
      51%, 100% { opacity: 0; }
    }

    /* Matrix Controls */
    .matrix-controls {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-top: 10px;
      padding: 8px;
      background: #001100;
      border-radius: 4px;
    }

    .matrix-sync-btn {
      background: #00ff00;
      color: #000000;
      border: none;
      padding: 6px 12px;
      border-radius: 3px;
      font-family: 'Courier New', monospace;
      font-size: 11px;
      font-weight: bold;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .matrix-sync-btn:hover {
      background: #00cc00;
      box-shadow: 0 0 8px #00ff00;
    }

    .matrix-sync-btn:active {
      background: #008800;
    }

    .matrix-status {
      color: #00ff00;
      font-family: 'Courier New', monospace;
      font-size: 10px;
      text-shadow: 0 0 2px #00ff00;
    }
    
    .connection-status {
      position: fixed;
      bottom: 20px;
      left: 20px;
      background: white;
      border: 1px solid #e9ecef;
      border-radius: 8px;
      padding: 10px 15px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
      cursor: pointer;
      transition: transform 0.2s ease;
      z-index: 1000;
    }
    
    .connection-status:hover {
      transform: translateY(-1px);
    }
    
    .connection-status .status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #6c757d;
      display: inline-block;
      margin-right: 8px;
    }
    
    .connection-status .status-text {
      font-size: 0.85rem;
      color: #2c3e50;
    }
    
    @media (max-width: 768px) {
      .dashboard {
        padding: 15px;
      }
      
      .header-content {
        padding: 0 15px;
      }
      
      .header-title {
        font-size: 1.2rem;
      }
      
      .dashboard-grid {
        grid-template-columns: 1fr;
        gap: 15px;
      }
      
      .connection-status {
        bottom: 15px;
        right: 15px;
        padding: 8px 12px;
      }
      
      .quick-actions {
        flex-direction: column;
        gap: 8px;
      }
    }
  </style>
</head>
<body>
  <header>
    <div class="header-content">
      <div class="header-left">
        <h1 class="header-title">Franz-Senn-Hütte WebCheckin</h1>
      </div>
      <div class="header-right">
        <button class="logout-button" onclick="logout()">Abmelden</button>
      </div>
    </div>
  </header>

  <main class="dashboard">
    <!-- Main Navigation -->
    <div class="dashboard-grid">
      <div class="dashboard-card">
        <h2 class="card-title">Reservierungen</h2>
        <p class="card-description">
          Übersicht aller Reservierungen mit An-/Abreise-Filtern, Suche und Check-in/Check-out Verwaltung.
        </p>
        <a href="reservierungen.html" class="card-button">Reservierungen verwalten</a>
      </div>

      <div class="dashboard-card">
        <h2 class="card-title">Statistiken</h2>
        <p class="card-description">
          Detaillierte Statistiken über Anreisen, Abreisen, Auslastung und Wochenübersicht.
        </p>
        <a href="statistiken.html" class="card-button">Statistiken anzeigen</a>
      </div>

      <div class="dashboard-card">
        <h2 class="card-title">Zimmerplan</h2>
        <p class="card-description">
          Zeitliche Darstellung der Reservierungen.
        </p>
        <a href="zp/timeline-unified.html" class="card-button">Zimmerplan öffnen</a>
      </div>

      <div class="dashboard-card">
        <h2 class="card-title">System-Tools</h2>
        <p class="card-description">
          Verbindungstest, System-Status und Wartungstools für die WebCheckin-Anwendung.
        </p>
        <a href="loading-test.html" class="card-button">Tools öffnen</a>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="dashboard-grid">
      <div class="dashboard-card">
        <h2 class="card-title">Schnellzugriff</h2>
        <p class="card-description">
          Direkte Links zu häufig verwendeten Funktionen und Ansichten.
        </p>
        <div class="quick-actions">
          <a href="reservierungen.html?filter=heute-anreise" class="card-button">Anreise heute</a>
          <a href="reservierungen.html?filter=heute-abreise" class="card-button secondary">Abreise heute</a>
        </div>
      </div>

      <div class="dashboard-card">
        <h2 class="card-title">Mobile Ansicht</h2>
        <p class="card-description">
          Optimierte Ansichten für mobile Geräte und Tablets.
        </p>
        <a href="reservierungen.html?mobile=1" class="card-button">Mobile Version</a>
      </div>

      <div class="dashboard-card sync-matrix-card">
        <h2 class="card-title">Sync Matrix - Live</h2>
        <p class="card-description">
          Live-Anzeige aller Synchronisations-Vorgänge im Matrix-Style.
        </p>
        <div class="matrix-display" id="syncMatrix">
          <div class="matrix-content" id="matrixContent">
            <div class="matrix-line">System initialisiert...</div>
            <div class="matrix-line">Warte auf Sync-Events...</div>
          </div>
        </div>
        <div class="matrix-controls">
          <button class="matrix-sync-btn" onclick="triggerDashboardSync()">🔄 Manual Sync</button>
          <span class="matrix-status">Auto-Sync: ON (30s)</span>
        </div>
      </div>
    </div>
  </main>

  <!-- Connection Status -->
  <div class="connection-status" id="connection-indicator">
    <div class="status-dot"></div>
    <span class="status-text">Verbindung prüfen...</span>
  </div>

  <!-- Scripts -->
  <script src="js/http-utils.js"></script>
  <script src="js/loading-overlay.js"></script>
  
  <script>
    // Logout-Funktion
    function logout() {
      if (confirm('Möchten Sie sich wirklich abmelden?')) {
        // Session beenden
        fetch('logout.php', { method: 'POST' })
          .then(() => {
            window.location.href = 'login.html';
          })
          .catch(() => {
            // Fallback wenn logout.php nicht erreichbar
            window.location.href = 'login.html';
          });
      }
    }

    document.addEventListener('DOMContentLoaded', async () => {
      // Set up connection monitoring
      setupConnectionMonitoring();
    });

    function setupConnectionMonitoring() {
      // Connection status update function
      function updateConnectionStatus() {
        const monitor = window.connectionMonitor;
        if (!monitor) return;

        const indicator = document.getElementById('connection-indicator');
        if (!indicator) return;

        const dot = indicator.querySelector('.status-dot');
        const text = indicator.querySelector('.status-text');
        
        const quality = monitor.getQuality();
        const isOnline = monitor.isOnline();
        
        if (!isOnline) {
          dot.style.backgroundColor = '#dc3545';
          text.textContent = 'Offline';
        } else {
          switch (quality) {
            case 'excellent':
            case 'good':
              dot.style.backgroundColor = '#28a745';
              text.textContent = 'Online';
              break;
            case 'fair':
              dot.style.backgroundColor = '#ffc107';
              text.textContent = 'Langsam';
              break;
            case 'poor':
              dot.style.backgroundColor = '#fd7e14';
              text.textContent = 'Sehr langsam';
              break;
            default:
              dot.style.backgroundColor = '#6c757d';
              text.textContent = 'Unbekannt';
          }
        }
      }

      // Update every 10 seconds
      setInterval(updateConnectionStatus, 10000);
      setTimeout(updateConnectionStatus, 2000);

      // Click handler for detailed status
      document.getElementById('connection-indicator')?.addEventListener('click', () => {
        if (window.connectionMonitor && window.HttpUtils) {
          HttpUtils.showDetailedConnectionStatus(window.connectionMonitor);
        }
      });
    }

    // Global connection status functions
    window.updateConnectionStatus = function() {
      if (window.connectionMonitor && window.HttpUtils) {
        window.connectionMonitor.testConnection().then(() => {
          HttpUtils.updatePermanentIndicator(window.connectionMonitor);
          setupConnectionMonitoring();
        }).catch(() => {
          // Silent fail
        });
      }
    };

    // Matrix-Style Sync Display
    class SyncMatrix {
      constructor() {
        this.container = document.getElementById('matrixContent');
        this.maxLines = 50;
        this.updateInterval = 2000; // 2 seconds
        this.syncInterval = 30000; // 30 seconds - trigger sync
        this.lastUpdate = 0;
        this.init();
      }

      init() {
        if (this.container) {
          this.startUpdating();
          this.startAutoSync();
          this.addLine('Matrix System Online', 'success');
          this.addLine('Monitoring sync activities...', 'info');
          this.addLine('Auto-sync enabled (30s interval)', 'info');
        }
      }

      addLine(text, type = 'success') {
        if (!this.container) return;

        const line = document.createElement('div');
        line.className = `matrix-line ${type}`;
        
        const timestamp = new Date().toLocaleTimeString('de-DE', {
          hour12: false,
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit'
        });
        
        line.textContent = `${timestamp} ${text}`;
        
        this.container.appendChild(line);
        
        // Remove old lines if too many
        while (this.container.children.length > this.maxLines) {
          this.container.removeChild(this.container.firstChild);
        }
        
        // Auto-scroll to bottom
        this.container.scrollTop = this.container.scrollHeight;
      }

      async fetchSyncLogs() {
        try {
          const response = await fetch('api/sync_matrix.php');
          const data = await response.json();
          
          if (data.success && data.logs) {
            // Only add new logs since last update
            const newLogs = data.logs.filter(log => 
              new Date(log.timestamp).getTime() > this.lastUpdate
            );
            
            newLogs.forEach(log => {
              this.addLine(log.message, this.getLogType(log.message));
            });
            
            if (newLogs.length > 0) {
              this.lastUpdate = new Date().getTime();
            }
          }
        } catch (error) {
          this.addLine(`Connection error: ${error.message}`, 'error');
        }
      }

      getLogType(message) {
        message = message.toLowerCase();
        if (message.includes('error') || message.includes('failed') || message.includes('exception')) {
          return 'error';
        } else if (message.includes('warning') || message.includes('retry')) {
          return 'warning';
        } else if (message.includes('success') || message.includes('completed') || message.includes('✓')) {
          return 'success';
        } else {
          return 'info';
        }
      }

      startUpdating() {
        this.fetchSyncLogs();
        setInterval(() => this.fetchSyncLogs(), this.updateInterval);
      }

      startAutoSync() {
        // Initial sync after 5 seconds
        setTimeout(() => this.triggerSync('dashboard_auto'), 5000);
        
        // Regular auto-sync every 30 seconds
        setInterval(() => this.triggerSync('dashboard_auto'), this.syncInterval);
      }

      async triggerSync(triggerType = 'dashboard_manual') {
        try {
          this.addLine(`Triggering ${triggerType} sync...`, 'info');
          
          const response = await fetch('syncTrigger.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=sync&trigger=${encodeURIComponent(triggerType)}`
          });
          
          const result = await response.json();
          
          if (result.success) {
            const stats = result.results?.summary || result.results;
            if (stats && stats.total_operations) {
              const localToRemote = stats.total_operations.local_to_remote || 0;
              const remoteToLocal = stats.total_operations.remote_to_local || 0;
              
              if (localToRemote > 0 || remoteToLocal > 0) {
                this.addLine(`Sync completed: L→R:${localToRemote} R→L:${remoteToLocal}`, 'success');
              } else {
                this.addLine('Sync completed: No changes needed', 'success');
              }
            } else {
              this.addLine('Sync completed successfully', 'success');
            }
          } else {
            this.addLine(`Sync failed: ${result.error || 'Unknown error'}`, 'error');
          }
        } catch (error) {
          this.addLine(`Sync error: ${error.message}`, 'error');
        }
      }

      // Manual sync trigger (for button)
      manualSync() {
        this.triggerSync('dashboard_manual');
      }
    }

    // Initialize Matrix when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
      window.syncMatrix = new SyncMatrix();
      
      // Global function for manual sync trigger
      window.triggerDashboardSync = function() {
        if (window.syncMatrix) {
          window.syncMatrix.manualSync();
        }
      };
    });
  </script>
</body>
</html>