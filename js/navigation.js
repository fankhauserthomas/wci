/**
 * Navigation System für WCI Buchungssystem
 * Einheitliche Navigation zwischen allen Seiten
 */

class NavigationSystem {
  static currentPage = '';
  static breadcrumbs = [];

  /**
   * Initialisiert das Navigation-System
   */
  static init() {
    this.detectCurrentPage();
    this.setupNavigation();
    this.setupMobileNavigation();
    this.setupBreadcrumbs();
    this.setupSyncButton();
    this.initializeConnectionIndicator();

    console.log('[NAV] Navigation system initialized for page:', this.currentPage);
  }

  /**
   * Erkennt die aktuelle Seite
   */
  static detectCurrentPage() {
    const path = window.location.pathname;
    const filename = path.split('/').pop() || 'index.html';

    const pageMap = {
      'index.html': 'dashboard',
      'reservierungen.html': 'reservations',
      'reservation.html': 'reservation',
      'ReservationDetails.html': 'reservation-details',
      'GastDetail.html': 'guest-details',
      'calendar.html': 'calendar',
      'reports.html': 'reports',
      'statistiken.html': 'reports'
    };

    this.currentPage = pageMap[filename] || 'dashboard';
  }

  /**
   * Erstellt die Hauptnavigation
   */
  static setupNavigation() {
    const existingNav = document.querySelector('.main-navigation');
    if (existingNav) return; // Bereits vorhanden

    const nav = this.createMainNavigation();
    document.body.insertBefore(nav, document.body.firstChild);

    // Active state setzen
    this.setActiveNavItem();
  }

  /**
   * Erstellt das Hauptnavigation-Element
   */
  static createMainNavigation() {
    const nav = document.createElement('nav');
    nav.className = 'main-navigation';
    nav.innerHTML = `
      <div class="nav-brand">
        <img src="pic/logo.png" alt="Franzsen Hütte" class="nav-logo" onerror="this.style.display='none'">
        <span class="nav-title">Buchungssystem</span>
      </div>
      
      <div class="nav-primary">
        <a href="index.php" class="nav-item" data-page="dashboard">
          📊 Dashboard
        </a>
        <a href="reservierungen.html" class="nav-item" data-page="reservations">
          📋 Reservierungen
        </a>
        <a href="statistiken.html" class="nav-item" data-page="reports">
          📈 Statistiken
        </a>
      </div>
      
      <div class="nav-actions">
        <div class="connection-indicator" id="connection-indicator" title="Verbindungsstatus">🔴</div>
        <button class="nav-sync" title="Synchronisieren" onclick="NavigationSystem.triggerSync()">🔄</button>
        <button class="mobile-nav-toggle" onclick="NavigationSystem.toggleMobileNav()">☰</button>
      </div>
    `;

    return nav;
  }

  /**
   * Setzt den aktiven Navigation-Punkt
   */
  static setActiveNavItem() {
    document.querySelectorAll('.nav-item').forEach(item => {
      item.classList.remove('active');
      if (item.dataset.page === this.currentPage) {
        item.classList.add('active');
      }
    });
  }

  /**
   * Setup Mobile Navigation
   */
  static setupMobileNavigation() {
    const mobileNav = document.createElement('nav');
    mobileNav.className = 'mobile-navigation';
    mobileNav.innerHTML = `
      <a href="index.php" class="mobile-nav-item" data-page="dashboard">📊 Dashboard</a>
      <a href="reservierungen.html" class="mobile-nav-item" data-page="reservations">📋 Reservierungen</a>
      <a href="statistiken.html" class="mobile-nav-item" data-page="reports">📈 Statistiken</a>
    `;

    const mainNav = document.querySelector('.main-navigation');
    if (mainNav) {
      mainNav.after(mobileNav);
    }
  }

  /**
   * Toggle Mobile Navigation
   */
  static toggleMobileNav() {
    const mobileNav = document.querySelector('.mobile-navigation');
    if (mobileNav) {
      mobileNav.classList.toggle('active');
    }
  }

  /**
   * Setup Breadcrumbs basierend auf aktueller Seite
   */
  static setupBreadcrumbs() {
    const breadcrumbConfigs = {
      'dashboard': [],
      'reservations': [
        { label: '🏠 Dashboard', url: 'index.html' }
      ],
      'reservation': [
        { label: '🏠 Dashboard', url: 'index.html' },
        { label: '📋 Reservierungen', url: 'reservierungen.html' }
      ],
      'reservation-details': [
        { label: '🏠 Dashboard', url: 'index.html' },
        { label: '📋 Reservierungen', url: 'reservierungen.html' },
        { label: '📝 Reservation', url: 'reservation.html' + window.location.search }
      ],
      'guest-details': [
        { label: '🏠 Dashboard', url: 'index.html' },
        { label: '📋 Reservierungen', url: 'reservierungen.html' },
        { label: '📝 Reservation', url: 'reservation.html' + window.location.search }
      ],
      'reports': [
        { label: '🏠 Dashboard', url: 'index.html' }
      ]
    };

    const config = breadcrumbConfigs[this.currentPage] || [];
    if (config.length > 0) {
      this.createBreadcrumbs(config);
    }
  }

  /**
   * Erstellt Breadcrumb Navigation
   */
  static createBreadcrumbs(breadcrumbs) {
    const existingBreadcrumb = document.querySelector('.breadcrumb-nav');
    if (existingBreadcrumb) return;

    const breadcrumbNav = document.createElement('nav');
    breadcrumbNav.className = 'breadcrumb-nav';

    let breadcrumbHTML = '';
    breadcrumbs.forEach((crumb, index) => {
      if (index > 0) {
        breadcrumbHTML += '<span class="breadcrumb-separator">›</span>';
      }
      breadcrumbHTML += `<span class="breadcrumb-item"><a href="${crumb.url}">${crumb.label}</a></span>`;
    });

    // Aktuelle Seite hinzufügen
    if (breadcrumbs.length > 0) {
      breadcrumbHTML += '<span class="breadcrumb-separator">›</span>';
    }
    breadcrumbHTML += `<span class="breadcrumb-item breadcrumb-current">${this.getCurrentPageLabel()}</span>`;

    breadcrumbNav.innerHTML = breadcrumbHTML;

    const mainNav = document.querySelector('.main-navigation');
    const mobileNav = document.querySelector('.mobile-navigation');
    if (mobileNav) {
      mobileNav.after(breadcrumbNav);
    } else if (mainNav) {
      mainNav.after(breadcrumbNav);
    }
  }

  /**
   * Gibt das Label der aktuellen Seite zurück
   */
  static getCurrentPageLabel() {
    const labels = {
      'dashboard': '📊 Dashboard',
      'reservations': '📋 Reservierungen',
      'reservation': '📝 Reservation',
      'reservation-details': '👤 Reservierungsdetails',
      'guest-details': '👤 Gastdetails',
      'reports': '📈 Statistiken'
    };

    let label = labels[this.currentPage] || '📄 Seite';

    // Spezielle Labels für dynamische Inhalte
    if (this.currentPage === 'reservation') {
      const urlParams = new URLSearchParams(window.location.search);
      const resId = urlParams.get('id');
      if (resId) {
        label = `📝 Reservation #${resId}`;
      }
    }

    return label;
  }

  /**
   * Erstellt Page Actions basierend auf aktueller Seite
   */
  static setupPageActions() {
    // Keine page-actions für reservation.html da bereits Action Bar vorhanden
    if (this.currentPage === 'reservation') {
      return;
    }

    const pageActionsConfigs = {
      'dashboard': this.createDashboardActions,
      'reservations': this.createReservationsActions,
      'reports': this.createReportsActions
    };

    const createActions = pageActionsConfigs[this.currentPage];
    if (createActions) {
      const actions = createActions.call(this);
      if (actions) {
        this.insertPageActions(actions);
      }
    }
  }

  /**
   * Erstellt Dashboard Actions
   */
  static createDashboardActions() {
    return `
      <div class="page-actions dashboard-actions">
        <div class="action-group">
          <span>Dashboard Übersicht</span>
        </div>
        <div class="action-group">
          <button class="action-primary" onclick="location.href='reservierungen.html'">
            📋 Reservierungen verwalten
          </button>
          <button class="action-secondary" onclick="NavigationSystem.triggerSync()">
            🔄 Daten synchronisieren
          </button>
        </div>
      </div>
    `;
  }

  /**
   * Erstellt Reservierungen Actions
   */
  static createReservationsActions() {
    return `
      <div class="page-actions reservations-actions">
        <div class="action-group filters">
          <button class="filter-btn active" data-filter="arrival" onclick="NavigationSystem.filterReservations('arrival', this)">Anreise</button>
          <button class="filter-btn" data-filter="departure" onclick="NavigationSystem.filterReservations('departure', this)">Abreise</button>
          <input type="date" class="filter-date" onchange="NavigationSystem.filterByDate(this.value)">
        </div>
        
        <div class="action-group search">
          <input type="text" placeholder="Suche Name..." class="search-input" oninput="NavigationSystem.searchReservations(this.value)">
          <button class="search-btn">🔍</button>
        </div>
        
        <div class="action-group">
          <button class="action-secondary" onclick="NavigationSystem.exportData()">📤 Export</button>
        </div>
      </div>
    `;
  }

  /**
   * Erstellt Reservation Actions
   */
  static createReservationActions() {
    return `
      <div class="page-actions reservation-actions">
        <div class="action-group navigation">
          <button class="action-back" onclick="NavigationSystem.goBack()">‹ Zurück</button>
          <span class="current-reservation">${this.getCurrentPageLabel()}</span>
        </div>
        
        <div class="action-group bulk">
          <button class="action-bulk" onclick="NavigationSystem.bulkCheckin()">📥 Bulk Check-in</button>
          <button class="action-bulk" onclick="NavigationSystem.bulkCheckout()">📤 Bulk Check-out</button>
        </div>
        
        <div class="action-group print">
          <button class="action-print" onclick="NavigationSystem.printSelected()">🖨️ Drucken</button>
        </div>
        
        <div class="action-group">
          <button class="action-primary" onclick="NavigationSystem.addPerson()">+ Person hinzufügen</button>
        </div>
      </div>
    `;
  }

  /**
   * Fügt Page Actions ein
   */
  static insertPageActions(actionsHTML) {
    const existingActions = document.querySelector('.page-actions');
    if (existingActions) return;

    const breadcrumbNav = document.querySelector('.breadcrumb-nav');
    const mainNav = document.querySelector('.main-navigation');
    const mobileNav = document.querySelector('.mobile-navigation');

    const actionsDiv = document.createElement('div');
    actionsDiv.innerHTML = actionsHTML;
    const actions = actionsDiv.firstElementChild;

    if (breadcrumbNav) {
      breadcrumbNav.after(actions);
    } else if (mobileNav) {
      mobileNav.after(actions);
    } else if (mainNav) {
      mainNav.after(actions);
    }
  }

  /**
   * Navigation Event Handlers
   */
  static goBack() {
    if (document.referrer && document.referrer.includes(window.location.origin)) {
      history.back();
    } else {
      // Fallback Navigation
      switch (this.currentPage) {
        case 'reservation':
          location.href = 'reservierungen.html';
          break;
        case 'reservation-details':
        case 'guest-details':
          const urlParams = new URLSearchParams(window.location.search);
          const resId = urlParams.get('id');
          location.href = `reservation.html${resId ? '?id=' + resId : ''}`;
          break;
        default:
          location.href = 'index.html';
      }
    }
  }

  /**
   * Setup Sync Button
   */
  static setupSyncButton() {
    const syncBtn = document.querySelector('.nav-sync');
    if (syncBtn) {
      syncBtn.addEventListener('click', () => {
        this.triggerSync();
      });
    }
  }

  /**
   * Sync Button Handler
   */
  static triggerSync() {
    const syncBtn = document.querySelector('.nav-sync');
    if (syncBtn) {
      syncBtn.classList.add('nav-loading');
      syncBtn.innerHTML = '⏳';

      // Führe Sync aus wenn verfügbar
      if (window.syncUtils && typeof window.syncUtils.triggerSync === 'function') {
        window.syncUtils.triggerSync().finally(() => {
          syncBtn.classList.remove('nav-loading');
          syncBtn.innerHTML = '🔄';
        });
      } else {
        // Fallback - simuliere Sync
        setTimeout(() => {
          syncBtn.classList.remove('nav-loading');
          syncBtn.innerHTML = '🔄';
          console.log('[NAV] Sync completed (fallback)');
        }, 2000);
      }
    }
  }

  /**
   * Placeholder Handlers für Actions - Integration mit bestehenden Funktionen
   */
  static filterReservations(type, button) {
    // Integration mit bestehender Filter-Logik
    document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
    button.classList.add('active');

    // Trigger existing filter logic if available
    if (window.filterByType) {
      window.filterByType(type);
    } else {
      console.log('[NAV] Filter reservations by:', type);
    }
  }

  static searchReservations(query) {
    // Integration mit bestehender Suche
    if (window.searchReservations) {
      window.searchReservations(query);
    } else {
      console.log('[NAV] Search reservations:', query);
    }
  }

  static filterByDate(date) {
    // Integration mit bestehender Datum-Filter
    if (window.filterByDate) {
      window.filterByDate(date);
    } else {
      console.log('[NAV] Filter by date:', date);
    }
  }

  static exportData() {
    // Integration mit bestehender Export-Funktion
    if (window.exportToCSV) {
      window.exportToCSV();
    } else {
      console.log('[NAV] Export data triggered');
      alert('Export-Funktion wird implementiert...');
    }
  }

  static bulkCheckin() {
    // Integration mit bestehender Bulk Check-in
    if (typeof bulkCheckinBtn !== 'undefined' && bulkCheckinBtn.click) {
      bulkCheckinBtn.click();
    } else if (window.bulkCheckin) {
      window.bulkCheckin();
    } else {
      console.log('[NAV] Bulk check-in triggered');
      alert('Bulk Check-in: Markiere zuerst Gäste in der Liste');
    }
  }

  static bulkCheckout() {
    // Integration mit bestehender Bulk Check-out
    if (typeof bulkCheckoutBtn !== 'undefined' && bulkCheckoutBtn.click) {
      bulkCheckoutBtn.click();
    } else if (window.bulkCheckout) {
      window.bulkCheckout();
    } else {
      console.log('[NAV] Bulk check-out triggered');
      alert('Bulk Check-out: Markiere zuerst Gäste in der Liste');
    }
  }

  static printSelected() {
    // Integration mit bestehender Print-Funktion
    if (typeof printSelectedBtn !== 'undefined' && printSelectedBtn.click) {
      printSelectedBtn.click();
    } else if (window.printSelected) {
      window.printSelected();
    } else {
      console.log('[NAV] Print selected triggered');
      alert('Drucken: Markiere zuerst Einträge zum Drucken');
    }
  }

  static addPerson() {
    // Integration mit bestehender "Person hinzufügen" Funktion
    if (typeof addNameBtn !== 'undefined' && addNameBtn.click) {
      addNameBtn.click();
    } else if (window.addNewName) {
      window.addNewName();
    } else {
      console.log('[NAV] Add person triggered');
      // Fallback - versuche Modal zu öffnen
      const addModal = document.querySelector('.modal');
      if (addModal) {
        addModal.style.display = 'block';
      } else {
        alert('Person hinzufügen: Funktion wird geladen...');
      }
    }
  }

  /**
   * Initialisiert den Connection-Indikator in der Navigation
   */
  static initializeConnectionIndicator() {
    // Mehrere Versuche mit längeren Verzögerungen für verschiedene Lade-Szenarien
    const attempts = [100, 500, 1000, 2000];

    attempts.forEach((delay, index) => {
      setTimeout(() => {
        const indicator = document.getElementById('connection-indicator');
        if (!indicator) return;

        // Click-Handler hinzufügen (nur einmal)
        if (!indicator.hasAttribute('data-click-handler')) {
          indicator.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            console.log('[NAV] Connection indicator clicked');

            if (window.HttpUtils && window.connectionMonitor) {
              window.HttpUtils.showDetailedConnectionStatus(window.connectionMonitor);
            } else if (window.HttpUtils) {
              // Fallback - versuche Status zu testen
              window.HttpUtils.showDetailedConnectionStatus({
                isOnline: () => navigator.onLine,
                getQuality: () => 'unknown',
                getLastCheck: () => new Date(),
                getAverageLatency: () => 0
              });
            } else {
              alert('Verbindungsmonitoring wird geladen...');
            }
          });
          indicator.setAttribute('data-click-handler', 'true');
          console.log('[NAV] Click handler added to connection indicator');
        }

        // HttpUtils initialisieren falls verfügbar
        if (window.HttpUtils) {
          console.log('[NAV] Initializing connection indicator in navigation (attempt ' + (index + 1) + ')');
          window.HttpUtils.createPermanentStatusIndicator();

          // Initial status check
          if (window.connectionMonitor) {
            window.connectionMonitor.testConnection().then(() => {
              window.HttpUtils.updatePermanentIndicator(window.connectionMonitor);
            }).catch(() => {
              // Silent fail - indicator will show offline state
            });
          }
        }
      }, delay);
    });
  }
}

// Auto-Initialisierung
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    NavigationSystem.init();
    NavigationSystem.setupPageActions();
  });
} else {
  NavigationSystem.init();
  NavigationSystem.setupPageActions();
}

// Export für andere Module
if (typeof window !== 'undefined') {
  window.NavigationSystem = NavigationSystem;
}
