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
      margin-left: 10px;
    }
    
    .logout-button:hover {
      background: #c82333;
    }
    
    .reset-button {
      background: #6c757d;
      color: white;
      border: none;
      padding: 8px 12px;
      border-radius: 6px;
      font-size: 0.85rem;
      cursor: pointer;
      transition: background-color 0.2s ease;
    }
    
    .reset-button:hover {
      background: #5a6268;
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
      cursor: grab;
      position: relative;
    }
    
    .dashboard-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    .dashboard-card.dragging {
      opacity: 0.5;
      cursor: grabbing;
      transform: rotate(3deg);
      z-index: 1000;
    }
    
    .dashboard-card.drag-over {
      border: 2px dashed #007bff;
      background-color: rgba(0, 123, 255, 0.1);
    }
    
    .drag-handle {
      position: absolute;
      top: 10px;
      right: 10px;
      cursor: grab;
      color: #bdc3c7;
      font-size: 18px;
      padding: 5px;
      border-radius: 3px;
      transition: color 0.2s;
    }
    
    .drag-handle:hover {
      color: #7f8c8d;
      background-color: rgba(0, 0, 0, 0.05);
    }
    
    .dashboard-card.dragging .drag-handle {
      cursor: grabbing;
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
    
    /* Multi-button layout */
    .card-buttons {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }
    
    .card-buttons .card-button {
      flex: 1;
      min-width: 120px;
    }
    
    .card-button.hrs-debug {
      background: #e74c3c;
      font-weight: bold;
    }
    
    .card-button.hrs-debug:hover {
      background: #c0392b;
    }
    
    .card-button.system-analysis {
      background: #9b59b6;
      font-weight: bold;
    }
    
    .card-button.system-analysis:hover {
      background: #8e44ad;
    }
    
    .card-button.cache-clear {
      background: #f39c12;
      font-weight: bold;
      color: white;
    }
    
    .card-button.cache-clear:hover {
      background: #e67e22;
    }
    
    .card-button.cache-clear:active {
      background: #d35400;
      transform: scale(0.98);
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

    /* Sync-Info Karte Styling */
    .sync-info-content {
      padding: 10px 0;
    }

    .sync-stat {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px solid #f0f0f0;
    }

    .sync-stat:last-child {
      border-bottom: none;
    }

    .sync-stat-label {
      font-weight: 500;
      color: #555;
    }

    .sync-stat-value {
      font-weight: bold;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 14px;
    }

    .sync-stat-value.active {
      background: #d4edda;
      color: #155724;
    }

    .sync-stat-value.inactive {
      background: #f8d7da;
      color: #721c24;
    }

    .sync-stat-value.neutral {
      background: #e2e3e5;
      color: #383d41;
    }

    .sync-last-run {
      font-size: 12px;
      color: #666;
      margin-top: 10px;
      text-align: center;
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
        <button class="reset-button" onclick="resetDashboardOrder()" title="Dashboard-Anordnung zurücksetzen">↻ Reset Layout</button>
        <button class="logout-button" onclick="logout()">Abmelden</button>
      </div>
    </div>
  </header>

  <main class="dashboard">
    <!-- Main Navigation -->
    <div class="dashboard-grid" id="dashboard-container">
      <!-- Sync-Info Karte -->
      <div class="dashboard-card" draggable="true" data-card-id="sync-info">
        <div class="drag-handle">⋮⋮</div>
        <h2 class="card-title">🔄 Synchronisation</h2>
        <div id="sync-info-content">
          <p class="card-description">Lade Sync-Informationen...</p>
        </div>
      </div>

      <div class="dashboard-card" draggable="true" data-card-id="reservierungen">
        <div class="drag-handle">⋮⋮</div>
        <h2 class="card-title">Reservierungen</h2>
        <p class="card-description">
          Übersicht aller Reservierungen mit An-/Abreise-Filtern, Suche und Check-in/Check-out Verwaltung.
        </p>
        <a href="reservierungen.html" class="card-button">Reservierungen verwalten</a>
      </div>

      <div class="dashboard-card" draggable="true" data-card-id="statistiken">
        <div class="drag-handle">⋮⋮</div>
        <h2 class="card-title">Statistiken</h2>
        <p class="card-description">
          Detaillierte Statistiken über Anreisen, Abreisen, Auslastung und Wochenübersicht.
        </p>
        <a href="statistiken.html" class="card-button">Statistiken anzeigen</a>
      </div>

      <div class="dashboard-card" draggable="true" data-card-id="belegungsanalyse">
        <div class="drag-handle">⋮⋮</div>
        <h2 class="card-title">🏔️ Belegungsanalyse</h2>
        <p class="card-description">
          Interaktive Darstellung der täglichen Hausbelegung mit HRS/Lokal-Aufschlüsselung, freien Kapazitäten und Kategorien-Details.
        </p>
        <div class="card-buttons">
          <a href="belegung/belegung.php" class="card-button">📊 Diagramm</a>
          <a href="belegung/belegung_tab.php" class="card-button secondary">📋 Tabelle & Analyse</a>
        </div>
      </div>

      <div class="dashboard-card" draggable="true" data-card-id="zimmerplan">
        <div class="drag-handle">⋮⋮</div>
        <h2 class="card-title">Zimmerplan</h2>
        <p class="card-description">
          Zeitliche Darstellung der Reservierungen.
        </p>
        <a href="zp/timeline-unified.html" class="card-button">Zimmerplan öffnen</a>
      </div>

      <div class="dashboard-card" draggable="true" data-card-id="system-tools">
        <div class="drag-handle">⋮⋮</div>
        <h2 class="card-title">System-Tools</h2>
        <p class="card-description">
          Verbindungstest, System-Status und Wartungstools für die WebCheckin-Anwendung.
        </p>
        <div class="card-buttons">
          <a href="loading-test.html" class="card-button">System Tools</a>
          <a href="hrs_login_debug.php" class="card-button hrs-debug">🔧 HRS Debug</a>
          <a href="system-analysis.php" class="card-button system-analysis">🔍 System Analyse</a>
          <button onclick="clearBrowserCache()" class="card-button cache-clear" title="Browser-Cache, localStorage und sessionStorage leeren">🧹 Cache Leeren</button>
        </div>
      </div>
    </div>

    <!-- Access Analytics Widget -->
    <div class="dashboard-grid">
      <div class="dashboard-card" draggable="true" data-card-id="access-analytics" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
        <div class="drag-handle" style="color: rgba(255,255,255,0.7);">⋮⋮</div>
        <?php include 'access-widget.php'; ?>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="dashboard-grid">
      <div class="dashboard-card" draggable="true" data-card-id="tisch-uebersicht">
        <div class="drag-handle">⋮⋮</div>
        <h2 class="card-title">Tischübersicht</h2>
        <p class="card-description">
          Aktuelle Tischbelegung der anwesenden Gäste
        </p>
        <a href="tisch-uebersicht.php" class="card-button">Tischübersicht anzeigen</a>
      </div>

      <div class="dashboard-card" draggable="true" data-card-id="mobile-ansicht">
        <div class="drag-handle">⋮⋮</div>
        <h2 class="card-title">Mobile Ansicht</h2>
        <p class="card-description">
          Optimierte Ansichten für mobile Geräte und Tablets.
        </p>
        <a href="reservierungen.html?mobile=1" class="card-button">Mobile Version</a>
      </div>

      <div class="dashboard-card sync-matrix-card" draggable="true" data-card-id="sync-matrix">
        <div class="drag-handle">⋮⋮</div>
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
      
      // Initialize Dashboard Drag & Drop
      // Small delay to ensure all widgets are loaded
      setTimeout(() => {
        initializeDashboardDragAndDrop();
      }, 100);
    });
    
    // Dashboard Drag and Drop functionality
    function initializeDashboardDragAndDrop() {
      let draggedElement = null;
      let draggedOver = null;
      
      // Load saved order from localStorage
      loadDashboardOrder();
      
      // Load sync info
      loadSyncInfo();
      
      // Auto-refresh sync info every 30 seconds
      setInterval(loadSyncInfo, 30000);
      
      // Add event listeners to all draggable cards
      const cards = document.querySelectorAll('.dashboard-card[draggable="true"]');
      
      cards.forEach(card => {
        // Drag start
        card.addEventListener('dragstart', function(e) {
          draggedElement = this;
          this.classList.add('dragging');
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/html', this.outerHTML);
        });
        
        // Drag end
        card.addEventListener('dragend', function(e) {
          this.classList.remove('dragging');
          
          // Remove drag-over class from all cards
          cards.forEach(c => c.classList.remove('drag-over'));
          
          // Save new order
          saveDashboardOrder();
        });
        
        // Drag over
        card.addEventListener('dragover', function(e) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          
          if (this !== draggedElement) {
            this.classList.add('drag-over');
            draggedOver = this;
          }
        });
        
        // Drag enter
        card.addEventListener('dragenter', function(e) {
          e.preventDefault();
        });
        
        // Drag leave
        card.addEventListener('dragleave', function(e) {
          // Only remove if we're actually leaving this element
          if (!this.contains(e.relatedTarget)) {
            this.classList.remove('drag-over');
          }
        });
        
        // Drop
        card.addEventListener('drop', function(e) {
          e.preventDefault();
          this.classList.remove('drag-over');
          
          if (this !== draggedElement && draggedElement) {
            // Get the containers
            const draggedContainer = draggedElement.parentNode;
            const targetContainer = this.parentNode;
            
            // If dropping in the same container, reorder
            if (draggedContainer === targetContainer) {
              const allCards = Array.from(targetContainer.children);
              const draggedIndex = allCards.indexOf(draggedElement);
              const targetIndex = allCards.indexOf(this);
              
              if (draggedIndex < targetIndex) {
                targetContainer.insertBefore(draggedElement, this.nextSibling);
              } else {
                targetContainer.insertBefore(draggedElement, this);
              }
            } else {
              // Moving between containers - insert before target
              targetContainer.insertBefore(draggedElement, this);
            }
          }
        });
      });
      
      // Also add drop listeners to grid containers for empty spaces
      const grids = document.querySelectorAll('.dashboard-grid');
      grids.forEach(grid => {
        grid.addEventListener('dragover', function(e) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
        });
        
        grid.addEventListener('drop', function(e) {
          e.preventDefault();
          
          // If dropped on empty space in grid, append to end
          const rect = this.getBoundingClientRect();
          const afterElement = getDragAfterElement(this, e.clientY);
          
          if (afterElement == null) {
            this.appendChild(draggedElement);
          } else {
            this.insertBefore(draggedElement, afterElement);
          }
        });
      });
    }
    
    // Helper function to determine which element to insert after
    function getDragAfterElement(container, y) {
      const draggableElements = [...container.querySelectorAll('.dashboard-card:not(.dragging)')];
      
      return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        
        if (offset < 0 && offset > closest.offset) {
          return { offset: offset, element: child };
        } else {
          return closest;
        }
      }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
    
    // Save dashboard order to localStorage
    function saveDashboardOrder() {
      const allCards = document.querySelectorAll('.dashboard-card[data-card-id]');
      const order = Array.from(allCards).map((card, index) => ({
        id: card.dataset.cardId,
        container: card.parentNode.classList.contains('dashboard-grid') ? 
          Array.from(document.querySelectorAll('.dashboard-grid')).indexOf(card.parentNode) : 0,
        position: Array.from(card.parentNode.children).indexOf(card)
      }));
      
      localStorage.setItem('dashboardOrder', JSON.stringify(order));
      console.log('Dashboard order saved:', order);
      
      // Show save confirmation
      showSaveConfirmation();
    }
    
    // Load dashboard order from localStorage
    function loadDashboardOrder() {
      const savedOrder = localStorage.getItem('dashboardOrder');
      if (!savedOrder) return;
      
      try {
        const order = JSON.parse(savedOrder);
        const grids = Array.from(document.querySelectorAll('.dashboard-grid'));
        
        // Group cards by container
        const cardsByContainer = {};
        order.forEach(item => {
          if (!cardsByContainer[item.container]) {
            cardsByContainer[item.container] = [];
          }
          cardsByContainer[item.container].push(item);
        });
        
        // Sort each container by position and reorder
        Object.keys(cardsByContainer).forEach(containerIndex => {
          const grid = grids[parseInt(containerIndex)];
          if (grid) {
            const containerCards = cardsByContainer[containerIndex]
              .sort((a, b) => a.position - b.position);
            
            containerCards.forEach(item => {
              const card = document.querySelector(`[data-card-id="${item.id}"]`);
              if (card && card.parentNode !== grid) {
                grid.appendChild(card);
              }
            });
            
            // Reorder within container
            containerCards.forEach((item, index) => {
              const card = document.querySelector(`[data-card-id="${item.id}"]`);
              if (card) {
                const currentIndex = Array.from(grid.children).indexOf(card);
                if (currentIndex !== index) {
                  if (index === 0) {
                    grid.insertBefore(card, grid.firstChild);
                  } else {
                    const afterCard = grid.children[index - 1];
                    if (afterCard && afterCard.nextSibling) {
                      grid.insertBefore(card, afterCard.nextSibling);
                    } else {
                      grid.appendChild(card);
                    }
                  }
                }
              }
            });
          }
        });
        
        console.log('Dashboard order loaded:', order);
      } catch (e) {
        console.error('Error loading dashboard order:', e);
        localStorage.removeItem('dashboardOrder');
      }
    }
    
    // Show save confirmation
    function showSaveConfirmation() {
      const notification = document.createElement('div');
      notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #28a745;
        color: white;
        padding: 10px 20px;
        border-radius: 5px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        z-index: 1000;
        font-size: 14px;
        opacity: 0;
        transition: opacity 0.3s ease;
      `;
      notification.textContent = 'Dashboard-Anordnung gespeichert!';
      document.body.appendChild(notification);
      
      // Fade in
      setTimeout(() => notification.style.opacity = '1', 10);
      
      // Remove after 3 seconds
      setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => document.body.removeChild(notification), 300);
      }, 3000);
    }
    
    // Add reset function for dashboard order
    window.resetDashboardOrder = function() {
      localStorage.removeItem('dashboardOrder');
      location.reload();
    };
    
    // Debug function to show current order
    window.debugDashboardOrder = function() {
      const allCards = document.querySelectorAll('.dashboard-card[data-card-id]');
      const grids = document.querySelectorAll('.dashboard-grid');
      
      console.log('=== Dashboard Debug ===');
      console.log('Total grids:', grids.length);
      console.log('Total cards:', allCards.length);
      
      grids.forEach((grid, gridIndex) => {
        const cards = grid.querySelectorAll('.dashboard-card[data-card-id]');
        console.log(`Grid ${gridIndex}:`, Array.from(cards).map(c => c.dataset.cardId));
      });
      
      const saved = localStorage.getItem('dashboardOrder');
      if (saved) {
        console.log('Saved order:', JSON.parse(saved));
      } else {
        console.log('No saved order found');
      }
    };
    
    // Cache clearing function with multiple methods
    function clearBrowserCache() {
      let successCount = 0;
      let totalMethods = 0;
      const results = [];
      
      // Show loading notification
      const notification = showNotification('🧹 Cache wird geleert...', 'info', 0);
      
      // Method 1: Clear localStorage
      try {
        const localStorageSize = localStorage.length;
        localStorage.clear();
        results.push(`✅ localStorage: ${localStorageSize} Einträge gelöscht`);
        successCount++;
      } catch (e) {
        results.push(`❌ localStorage: Fehler - ${e.message}`);
      }
      totalMethods++;
      
      // Method 2: Clear sessionStorage
      try {
        const sessionStorageSize = sessionStorage.length;
        sessionStorage.clear();
        results.push(`✅ sessionStorage: ${sessionStorageSize} Einträge gelöscht`);
        successCount++;
      } catch (e) {
        results.push(`❌ sessionStorage: Fehler - ${e.message}`);
      }
      totalMethods++;
      
      // Method 3: Clear IndexedDB (if available)
      if ('indexedDB' in window) {
        try {
          indexedDB.databases().then(databases => {
            databases.forEach(db => {
              indexedDB.deleteDatabase(db.name);
            });
            results.push(`✅ IndexedDB: ${databases.length} Datenbanken gelöscht`);
          }).catch(e => {
            results.push(`❌ IndexedDB: Fehler - ${e.message}`);
          });
          successCount++;
        } catch (e) {
          results.push(`❌ IndexedDB: Fehler - ${e.message}`);
        }
        totalMethods++;
      }
      
      // Method 4: Service Worker Cache (if available)
      if ('serviceWorker' in navigator && 'caches' in window) {
        caches.keys().then(cacheNames => {
          if (cacheNames.length > 0) {
            const deletePromises = cacheNames.map(cacheName => caches.delete(cacheName));
            Promise.all(deletePromises).then(() => {
              results.push(`✅ Service Worker Cache: ${cacheNames.length} Caches gelöscht`);
              successCount++;
            }).catch(e => {
              results.push(`❌ Service Worker Cache: Fehler - ${e.message}`);
            });
          } else {
            results.push(`ℹ️ Service Worker Cache: Keine Caches gefunden`);
            successCount++;
          }
        }).catch(e => {
          results.push(`❌ Service Worker Cache: Fehler - ${e.message}`);
        });
        totalMethods++;
      }
      
      // Method 5: Force reload with cache bypass
      setTimeout(() => {
        hideNotification(notification);
        
        // Show results
        const resultText = results.join('\n');
        console.log('Cache Clear Results:\n' + resultText);
        
        // Show success notification with reload option
        const confirmReload = confirm(
          `Cache erfolgreich geleert!\n\n` +
          `Ergebnisse:\n${resultText}\n\n` +
          `Möchten Sie die Seite neu laden, um alle Änderungen zu übernehmen?\n` +
          `(Empfohlen für vollständige Cache-Löschung)`
        );
        
        if (confirmReload) {
          // Force reload with cache bypass (Ctrl+F5 equivalent)
          window.location.reload(true);
        } else {
          showNotification('✅ Cache geleert! Reload empfohlen für vollständige Wirkung.', 'success', 5000);
        }
      }, 1000);
    }
    
    // Sync Info Loader
    async function loadSyncInfo() {
      try {
        const response = await fetch('sync-info-api.php?t=' + Date.now());
        const data = await response.json();
        
        if (data.success) {
          const stats = data.stats;
          const syncInfoContent = document.getElementById('sync-info-content');
          
          syncInfoContent.innerHTML = `
            <div class="sync-stat">
              <span class="sync-stat-label">Cron-Status:</span>
              <span class="sync-stat-value ${stats.cronActive ? 'active' : 'inactive'}">
                ${stats.cronActive ? '✅ Aktiv' : '❌ Inaktiv'}
              </span>
            </div>
            <div class="sync-stat">
              <span class="sync-stat-label">Records heute:</span>
              <span class="sync-stat-value neutral">${stats.recordsToday}</span>
            </div>
            <div class="sync-stat">
              <span class="sync-stat-label">Records Woche:</span>
              <span class="sync-stat-value neutral">${stats.recordsThisWeek}</span>
            </div>
            <div class="sync-stat">
              <span class="sync-stat-label">Fehler heute:</span>
              <span class="sync-stat-value ${stats.errorsToday > 0 ? 'inactive' : 'active'}">${stats.errorsToday}</span>
            </div>
            <div class="sync-last-run">
              Letzter Sync: ${stats.lastSync}
            </div>
          `;
        } else {
          document.getElementById('sync-info-content').innerHTML = `
            <p class="card-description" style="color: #e74c3c;">❌ Fehler beim Laden der Sync-Informationen</p>
          `;
        }
      } catch (error) {
        document.getElementById('sync-info-content').innerHTML = `
          <p class="card-description" style="color: #e74c3c;">❌ Verbindungsfehler</p>
        `;
      }
    }
    
    // Enhanced notification system for cache clearing
    function showNotification(message, type = 'info', duration = 3000) {
      const notification = document.createElement('div');
      const colors = {
        info: { bg: '#3498db', text: 'white' },
        success: { bg: '#27ae60', text: 'white' },
        error: { bg: '#e74c3c', text: 'white' },
        warning: { bg: '#f39c12', text: 'white' }
      };
      
      const color = colors[type] || colors.info;
      
      notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${color.bg};
        color: ${color.text};
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        z-index: 10000;
        font-size: 14px;
        opacity: 0;
        transition: all 0.3s ease;
        max-width: 350px;
        word-wrap: break-word;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
      `;
      
      notification.innerHTML = message;
      document.body.appendChild(notification);
      
      // Fade in
      requestAnimationFrame(() => {
        notification.style.opacity = '1';
        notification.style.transform = 'translateY(0)';
      });
      
      // Auto-remove if duration is set
      if (duration > 0) {
        setTimeout(() => hideNotification(notification), duration);
      }
      
      return notification;
    }
    
    function hideNotification(notification) {
      if (notification && notification.parentNode) {
        notification.style.opacity = '0';
        notification.style.transform = 'translateY(-20px)';
        setTimeout(() => {
          if (notification.parentNode) {
            document.body.removeChild(notification);
          }
        }, 300);
      }
    }
    
    // Add keyboard shortcut for cache clearing (Ctrl+Shift+R)
    document.addEventListener('keydown', function(e) {
      if (e.ctrlKey && e.shiftKey && e.key === 'R') {
        e.preventDefault();
        clearBrowserCache();
      }
    });
  </script>
</body>
</html>