<?php
// index.php - WebCheckin Dashboard mit Authentifizierung
require_once __DIR__ . '/../auth.php';

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
  <link rel="stylesheet" href="../include/style.css">
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
      background: #0056b3;
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
    
    .connection-status {
      position: fixed;
      bottom: 20px;
      right: 20px;
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
        <a href="../reservierungen/reservierungen.html" class="card-button">Reservierungen verwalten</a>
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
          Zeitliche Darstellung der Reservierungen mit Überlappungen und Auslastungsübersicht.
        </p>
        <a href="simple-timeline.html" class="card-button">Zimmerplan öffnen</a>
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
          <a href="../reservierungen/reservierungen.html?filter=heute-anreise" class="card-button">Anreise heute</a>
          <a href="../reservierungen/reservierungen.html?filter=heute-abreise" class="card-button secondary">Abreise heute</a>
        </div>
      </div>

      <div class="dashboard-card">
        <h2 class="card-title">Mobile Ansicht</h2>
        <p class="card-description">
          Optimierte Ansichten für mobile Geräte und Tablets.
        </p>
        <a href="../reservierungen/reservierungen.html?mobile=1" class="card-button">Mobile Version</a>
      </div>
    </div>
  </main>

  <!-- Connection Status -->
  <div class="connection-status" id="connection-indicator">
    <div class="status-dot"></div>
    <span class="status-text">Verbindung prüfen...</span>
  </div>

  <!-- Scripts -->
  <script src="../include/js/http-utils.js"></script>
  <script src="../include/js/loading-overlay.js"></script>
  
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
  </script>
</body>
</html>
    
    .dashboard-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
    }
    
    .card-icon {
      font-size: 1.8rem;
      margin-bottom: 8px;
      display: block;
    }
    
    .card-title {
      font-size: 1.1rem;
      font-weight: bold;
      color: var(--text);
      margin-bottom: 8px;
    }
    
    .card-description {
      color: var(--text-muted);
      margin-bottom: 16px;
      line-height: 1.4;
      font-size: 0.9rem;
    }
    
    .card-button {
      background: var(--primary);
      color: white;
      border: none;
      padding: 8px 16px;
      border-radius: 5px;
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
      background: var(--primary-dark);
      text-decoration: none;
      color: white;
    }
    
    .card-button.secondary {
      background: var(--secondary);
    }
    
    .card-button.secondary:hover {
      background: #0056b3;
    }
    
    .quick-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 40px;
      padding: 0;
    }
    
    .stat-card {
      background: linear-gradient(135deg, #007bff, #0056b3);
      color: white;
      padding: 24px 18px;
      border-radius: 12px;
      text-align: center;
      box-shadow: 0 4px 12px rgba(0, 123, 255, 0.4);
      border: 2px solid rgba(255, 255, 255, 0.15);
      min-height: 110px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(0, 123, 255, 0.5);
    }
    
    .stat-number {
      font-size: 2.0rem;
      font-weight: bold;
      margin-bottom: 6px;
      text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
      line-height: 1.2;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    
    .stat-label {
      font-size: 0.9rem;
      opacity: 0.95;
      font-weight: 500;
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
    }
    
    .footer-info {
      text-align: center;
      color: var(--text-muted);
      font-size: 0.9rem;
      margin-top: 40px;
      padding-top: 20px;
      border-top: 1px solid var(--border);
    }

    .section-separator {
      border-top: 2px solid var(--border);
      margin: 30px 0;
      opacity: 0.5;
    }

    .section-header {
      font-size: 1.3rem; 
      color: var(--text); 
      margin-bottom: 16px; 
      text-align: center; 
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Authentication Controls */
    .auth-controls {
      position: absolute;
      top: 20px;
      right: 20px;
      z-index: 100;
    }

    .logout-button {
      background: linear-gradient(135deg, #dc3545, #c82333);
      color: white;
      border: none;
      padding: 8px 16px;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
    }

    .logout-button:hover {
      background: linear-gradient(135deg, #c82333, #a71e2a);
      transform: translateY(-1px);
      box-shadow: 0 4px 8px rgba(220, 53, 69, 0.3);
    }

    /* Mobile Responsive Design */
    @media (max-width: 768px) {
      .dashboard {
        padding: 15px;
      }
      
      .dashboard-title {
        font-size: 2rem;
      }
      
      .dashboard-grid {
        grid-template-columns: 1fr;
        gap: 16px;
      }
      
      .quick-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
      }

      .stat-card {
        padding: 20px 16px;
        min-height: 95px;
      }

      .stat-number {
        font-size: 1.8rem;
      }
    }
  </style>
</head>
<body>
  <main class="dashboard">
    <div class="dashboard-header">
      <h1 class="dashboard-title">Franz-Senn-Hütte</h1>
      <p class="dashboard-subtitle">Zentrale Verwaltung für Reservierungen</p>
      <div class="auth-controls">
        <button id="logoutButton" class="logout-button" onclick="handleLogout()">
          🚪 Abmelden
        </button>
      </div>
    </div>

    <!-- Quick Stats Section -->
    <div style="margin-bottom: 20px;">
      <h2 class="section-header">Tagesübersicht</h2>
      <div class="quick-stats">
        <div class="stat-card">
          <div class="stat-number" id="todayArrivals">-</div>
          <div class="stat-label">Anreisen heute</div>
        </div>
        <div class="stat-card">
          <div class="stat-number" id="tomorrowDepartures">-</div>
          <div class="stat-label">Abreisen morgen</div>
        </div>
        <div class="stat-card">
          <div class="stat-number" id="currentGuests">-</div>
          <div class="stat-label">Gäste im Haus</div>
        </div>
        <div class="stat-card">
          <div class="stat-number" id="pendingCheckins">-</div>
          <div class="stat-label">Offene Check-ins</div>
        </div>
      </div>
    </div>

    <div class="section-separator"></div>

    <!-- Main Navigation Section -->
    <div style="margin-bottom: 20px;">
      <h2 class="section-header">Hauptfunktionen</h2>
      <div class="dashboard-grid">
        <div class="dashboard-card">
          <h2 class="card-title">Reservierungsliste</h2>
          <p class="card-description">
            Übersicht aller Reservierungen mit An-/Abreise-Filtern, Suche und Check-in/Check-out Verwaltung.
          </p>
          <a href="../reservierungen/reservierungen.html" class="card-button">Reservierungen anzeigen</a>
        </div>

        <div class="dashboard-card">
          <h2 class="card-title">Zimmerplan</h2>
          <p class="card-description">
            Zeitliche Darstellung der Reservierungen mit Überlappungen und Auslastungsübersicht.
          </p>
          <a href="simple-timeline.html" class="card-button">Zimmerplan öffnen</a>
        </div>

        <div class="dashboard-card">
          <h2 class="card-title">System-Tools</h2>
          <p class="card-description">
            Verbindungstest, System-Status und Wartungstools für die WebCheckin-Anwendung.
          </p>
          <a href="loading-test.html" class="card-button">Tools öffnen</a>
        </div>
      </div>
    </div>

    <div class="section-separator"></div>

    <!-- Quick Actions Section -->
    <div style="margin-bottom: 20px;">
      <h2 class="section-header">Schnellzugriff</h2>
      <div class="dashboard-grid">
        <div class="dashboard-card">
          <h2 class="card-title">Schnellzugriff</h2>
          <p class="card-description">
            Direkte Links zu häufig verwendeten Funktionen und Ansichten.
          </p>
          <div style="display: flex; gap: 8px; margin-top: 16px;">
            <a href="../reservierungen/reservierungen.html?filter=heute-anreise" class="card-button" style="flex: 1; font-size: 0.85rem; padding: 6px 12px;">Anreise heute</a>
            <a href="../reservierungen/reservierungen.html?filter=morgen-abreise" class="card-button secondary" style="flex: 1; font-size: 0.85rem; padding: 6px 12px;">Abreise morgen</a>
          </div>
        </div>

        <div class="dashboard-card">
          <h2 class="card-title">Mobile Ansicht</h2>
          <p class="card-description">
            Optimierte Ansichten für mobile Geräte und Tablets.
          </p>
          <a href="../reservierungen/reservierungen.html?mobile=1" class="card-button">Mobile Version</a>
        </div>
      </div>
    </div>

    <div class="footer-info">
      <p>WebCheckin Dashboard • Hotel-Reservierungsverwaltung</p>
      <p>Für Support und Fragen wenden Sie sich an das IT-Team</p>
      <p>
        <a href="dashboard-stats-test.php" style="color: #007bff; text-decoration: none;">📊 Stats-Debug</a> | 
        <a href="debug-db.php" style="color: #007bff; text-decoration: none;">🗄️ DB-Debug</a> | 
        <a href="getDashboardStats-debug.php" style="color: #007bff; text-decoration: none;">🔧 API-Debug</a>
      </p>
    </div>
  </main>

  <!-- Scripts -->
  <script src="../include/js/http-utils.js"></script>
  <script src="../include/js/loading-overlay.js"></script>
  
  <script>
    async function handleLogout() {
      if (confirm('Möchten Sie sich wirklich abmelden?')) {
        try {
          await fetch('logout.php');
        } catch (error) {
          console.error('Logout error:', error);
        }
        window.location.href = 'login.html';
      }
    }

    document.addEventListener('DOMContentLoaded', async () => {
      // Load quick stats
      await loadQuickStats();
    });

    async function loadQuickStats() {
      try {
        // Get today's date
        const today = new Date().toISOString().slice(0, 10);
        
        console.log('Loading stats for date:', today);
        
        // Load dashboard statistics
        const response = await fetch(`getDashboardStats-debug.php?date=${today}&cache=${Date.now()}`, {
          cache: 'no-cache',
          headers: {
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          }
        });
        
        console.log('Response status:', response.status);
        console.log('Response headers:', [...response.headers.entries()]);
        
        if (!response.ok) {
          const errorText = await response.text();
          console.error('Response error:', errorText);
          throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        
        const statsText = await response.text();
        console.log('Raw response:', statsText);
        
        const stats = JSON.parse(statsText);
        console.log('Parsed stats:', stats);
        
        if (stats.error) {
          throw new Error(stats.error);
        }
        
        // Update arrivals today with format: count / total_guests
        const arrivalsText = `${stats.arrivals_today.reservations} / ${stats.arrivals_today.guests}`;
        document.getElementById('todayArrivals').textContent = arrivalsText;
        
        // Update departures tomorrow with format: count / total_guests  
        const departuresText = `${stats.departures_tomorrow.reservations} / ${stats.departures_tomorrow.guests}`;
        document.getElementById('tomorrowDepartures').textContent = departuresText;
        
        // Update current guests in house
        document.getElementById('currentGuests').textContent = stats.current_guests || 0;
        
        // Update pending check-ins
        document.getElementById('pendingCheckins').textContent = stats.pending_checkins || 0;

        console.log('Stats updated successfully');

      } catch (error) {
        console.error('Error loading stats:', error);
        // Set fallback values
        document.getElementById('todayArrivals').textContent = 'Fehler';
        document.getElementById('tomorrowDepartures').textContent = 'Fehler';
        document.getElementById('currentGuests').textContent = 'Fehler';
        document.getElementById('pendingCheckins').textContent = 'Fehler';
      }
    }
  </script>
</body>
</html>
