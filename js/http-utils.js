/**
 * Robuste HTTP-Utility mit automatischen Wiederholungen und besserer Fehlerbehandlung
 * Speziell für instabile WLAN-Verbindungen optimiert
 * 
 * Verwendung:
 * - HttpUtils.requestJson(url, options, retryOptions)
 * - HttpUtils.postJson(url, data, retryOptions)
 * - HttpUtils.batchRequest(requests, options)
 */

class HttpUtils {
  static defaultOptions = {
    retries: 3,
    retryDelay: 1000, // Start with 1 second
    timeout: 10000,   // 10 seconds timeout
    backoffMultiplier: 2, // Exponential backoff
    retryOn: [408, 429, 500, 502, 503, 504, 0], // HTTP status codes and network errors (0)
  };

  /**
   * Robuster HTTP-Request mit automatischen Wiederholungen
   */
  static async request(url, options = {}, retryOptions = {}) {
    const opts = { ...this.defaultOptions, ...retryOptions };
    const fetchOptions = {
      timeout: opts.timeout,
      ...options
    };

    for (let attempt = 0; attempt <= opts.retries; attempt++) {
      try {
        console.log(`[HTTP] ${attempt > 0 ? `Retry ${attempt}: ` : ''}${fetchOptions.method || 'GET'} ${url}`);

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), opts.timeout);

        const response = await fetch(url, {
          ...fetchOptions,
          signal: controller.signal
        });

        clearTimeout(timeoutId);

        // Check if we should retry based on status code
        if (!response.ok && opts.retryOn.includes(response.status)) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        console.log(`[HTTP] ✓ Success: ${response.status} ${url}`);
        return response;

      } catch (error) {
        const isLastAttempt = attempt === opts.retries;
        const shouldRetry = this.shouldRetry(error, opts.retryOn);

        console.warn(`[HTTP] ✗ Attempt ${attempt + 1} failed: ${error.message}`);

        if (isLastAttempt || !shouldRetry) {
          console.error(`[HTTP] ✗ Final failure after ${attempt + 1} attempts: ${url}`);
          throw new Error(`Network request failed after ${attempt + 1} attempts: ${error.message}`);
        }

        // Wait before retry with exponential backoff
        const delay = opts.retryDelay * Math.pow(opts.backoffMultiplier, attempt);
        console.log(`[HTTP] ⏳ Waiting ${delay}ms before retry...`);
        await this.delay(delay);
      }
    }
  }

  /**
   * Robuster JSON-Request
   */
  static async requestJson(url, options = {}, retryOptions = {}) {
    const response = await this.request(url, options, retryOptions);

    try {
      const data = await response.json();
      return data;
    } catch (error) {
      throw new Error(`Invalid JSON response: ${error.message}`);
    }
  }

  /**
   * Robuster POST-Request mit JSON-Daten
   */
  static async postJson(url, data, retryOptions = {}) {
    return this.requestJson(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(data)
    }, retryOptions);
  }

  /**
   * Batch-Request mit Parallelisierung und Fehlerbehandlung
   */
  static async batchRequest(requests, options = {}) {
    const {
      concurrency = 3,     // Max parallel requests
      retryOptions = {},
      onProgress = null    // Progress callback
    } = options;

    const results = [];
    const errors = [];
    let completed = 0;

    // Process requests in batches
    for (let i = 0; i < requests.length; i += concurrency) {
      const batch = requests.slice(i, i + concurrency);

      const batchPromises = batch.map(async (request, batchIndex) => {
        const globalIndex = i + batchIndex;
        try {
          const result = await this.requestJson(request.url, request.options, retryOptions);
          results[globalIndex] = { success: true, data: result, request };
        } catch (error) {
          console.error(`[BATCH] Request ${globalIndex} failed:`, error);
          results[globalIndex] = { success: false, error: error.message, request };
          errors.push({ index: globalIndex, error, request });
        }

        completed++;
        if (onProgress) {
          onProgress(completed, requests.length, results[globalIndex]);
        }
      });

      await Promise.all(batchPromises);
    }

    return { results, errors, successCount: results.filter(r => r.success).length };
  }

  /**
   * Prüft ob ein Fehler für Retry geeignet ist
   */
  static shouldRetry(error, retryOn) {
    // Network errors (AbortError, TypeError, etc.)
    if (error.name === 'AbortError' || error.name === 'TypeError') {
      return retryOn.includes(0);
    }

    // HTTP status codes
    const match = error.message.match(/HTTP (\d+):/);
    if (match) {
      return retryOn.includes(parseInt(match[1]));
    }

    // Other network-related errors
    return error.message.includes('fetch') ||
      error.message.includes('network') ||
      error.message.includes('timeout');
  }

  /**
   * Promise-basierte Verzögerung
   */
  static delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  /**
   * Connection Status Monitor
   */
  static createConnectionMonitor() {
    let isOnline = navigator.onLine;
    let connectionQuality = 'unknown';

    const monitor = {
      isOnline: () => isOnline,
      getQuality: () => connectionQuality,

      // Test connection quality
      async testConnection() {
        try {
          const start = performance.now();
          // Use a small HEAD request to test connection
          const response = await fetch(window.location.origin + '/wci/ping.php', {
            method: 'HEAD',
            cache: 'no-cache'
          });
          const end = performance.now();
          const latency = end - start;

          if (latency < 100) connectionQuality = 'excellent';
          else if (latency < 500) connectionQuality = 'good';
          else if (latency < 1000) connectionQuality = 'fair';
          else connectionQuality = 'poor';

          return { latency, quality: connectionQuality };
        } catch {
          connectionQuality = 'offline';
          return { latency: Infinity, quality: connectionQuality };
        }
      }
    };

    // Listen for online/offline events
    window.addEventListener('online', () => {
      isOnline = true;
      console.log('[CONNECTION] Back online');
    });

    window.addEventListener('offline', () => {
      isOnline = false;
      connectionQuality = 'offline';
      console.log('[CONNECTION] Gone offline');
    });

    return monitor;
  }

  /**
   * Status-Anzeige für Verbindungsqualität
   */
  static showConnectionStatus(monitor) {
    const existing = document.getElementById('connection-status');
    if (existing) existing.remove();

    const status = document.createElement('div');
    status.id = 'connection-status';
    status.style.cssText = `
      position: fixed; top: 10px; right: 10px; z-index: 9999;
      padding: 5px 10px; border-radius: 4px; font-size: 12px;
      color: white; pointer-events: none; transition: opacity 0.3s;
    `;

    const quality = monitor.getQuality();
    const isOnline = monitor.isOnline();

    if (!isOnline) {
      status.textContent = '🔴 Offline';
      status.style.backgroundColor = '#dc3545';
    } else {
      switch (quality) {
        case 'excellent':
          status.textContent = '🟢 Ausgezeichnet';
          status.style.backgroundColor = '#28a745';
          break;
        case 'good':
          status.textContent = '🟡 Gut';
          status.style.backgroundColor = '#ffc107';
          break;
        case 'fair':
          status.textContent = '🟠 Mäßig';
          status.style.backgroundColor = '#fd7e14';
          break;
        case 'poor':
          status.textContent = '🔴 Schlecht';
          status.style.backgroundColor = '#dc3545';
          break;
        default:
          status.textContent = '⚪ Unbekannt';
          status.style.backgroundColor = '#6c757d';
      }
    }

    document.body.appendChild(status);
    setTimeout(() => {
      if (status.parentNode) {
        status.style.opacity = '0';
        setTimeout(() => status.remove(), 300);
      }
    }, 3000);
  }

  /**
   * Permanente Verbindungsqualität-Anzeige
   */
  static createPermanentStatusIndicator() {
    // Prüfe ob bereits ein HTML-Indikator existiert
    let indicator = document.getElementById('connection-indicator');
    if (indicator) {
      console.log('[HTTP] Using existing HTML connection indicator');
      // Click-Handler hinzufügen falls noch nicht vorhanden
      if (!indicator.hasAttribute('data-click-handler')) {
        indicator.addEventListener('click', () => {
          if (window.connectionMonitor) {
            HttpUtils.showDetailedConnectionStatus(window.connectionMonitor);
          }
        });
        indicator.setAttribute('data-click-handler', 'true');
      }
      return indicator;
    }

    // Fallback: JavaScript-Indikator erstellen
    indicator = document.createElement('div');
    indicator.id = 'connection-indicator';
    indicator.className = 'connection-status';

    const statusDot = document.createElement('div');
    statusDot.className = 'status-dot';

    const statusText = document.createElement('span');
    statusText.className = 'status-text';
    statusText.textContent = 'Verbindung prüfen...';

    indicator.appendChild(statusDot);
    indicator.appendChild(statusText);

    // Tooltip für Details
    indicator.title = 'Verbindungsqualität: Unbekannt - Klicken für Details';

    // Click für Details
    indicator.addEventListener('click', () => {
      if (window.connectionMonitor) {
        HttpUtils.showDetailedConnectionStatus(window.connectionMonitor);
      }
    });

    document.body.appendChild(indicator);
    return indicator;
  }

  /**
   * Update der permanenten Anzeige
   */
  static updatePermanentIndicator(monitor) {
    const indicator = document.getElementById('connection-indicator');
    if (!indicator) return;

    const isOnline = monitor.isOnline();
    const quality = monitor.getQuality();

    // Status-Dot finden
    const statusDot = indicator.querySelector('.status-dot');
    const statusText = indicator.querySelector('.status-text');

    if (!isOnline) {
      if (statusDot) statusDot.style.backgroundColor = '#dc3545';
      if (statusText) statusText.textContent = 'Offline';
      indicator.title = 'Verbindungsqualität: Offline - Klicken für Details';
    } else {
      switch (quality) {
        case 'excellent':
          if (statusDot) statusDot.style.backgroundColor = '#28a745';
          if (statusText) statusText.textContent = 'Ausgezeichnet';
          indicator.title = 'Verbindungsqualität: Ausgezeichnet - Klicken für Details';
          break;
        case 'good':
          if (statusDot) statusDot.style.backgroundColor = '#28a745';
          if (statusText) statusText.textContent = 'Gut';
          indicator.title = 'Verbindungsqualität: Gut - Klicken für Details';
          break;
        case 'fair':
          if (statusDot) statusDot.style.backgroundColor = '#ffc107';
          if (statusText) statusText.textContent = 'Mäßig';
          indicator.title = 'Verbindungsqualität: Mäßig - Klicken für Details';
          break;
        case 'poor':
          if (statusDot) statusDot.style.backgroundColor = '#fd7e14';
          if (statusText) statusText.textContent = 'Schlecht';
          indicator.title = 'Verbindungsqualität: Schlecht - Klicken für Details';
          break;
        default:
          if (statusDot) statusDot.style.backgroundColor = '#6c757d';
          if (statusText) statusText.textContent = 'Verbindung prüfen...';
          indicator.title = 'Verbindungsqualität: Unbekannt - Klicken für Details';
      }
    }
  }

  /**
   * Erweiterte Statusanzeige mit mehr Details
   */
  static showDetailedConnectionStatus(monitor) {
    const existing = document.getElementById('detailed-connection-status');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'detailed-connection-status';
    modal.style.position = 'fixed';
    modal.style.top = '50%';
    modal.style.left = '50%';
    modal.style.transform = 'translate(-50%, -50%)';
    modal.style.zIndex = '10001';
    modal.style.background = 'white';
    modal.style.borderRadius = '8px';
    modal.style.boxShadow = '0 4px 20px rgba(0,0,0,0.3)';
    modal.style.padding = '20px';
    modal.style.minWidth = '320px';
    modal.style.border = '1px solid #ddd';
    modal.style.fontFamily = 'Arial, sans-serif';

    const quality = monitor.getQuality();
    const isOnline = monitor.isOnline();

    modal.innerHTML = '<h3 style="margin: 0 0 15px 0; color: #333; font-size: 18px;">🌐 Verbindungsstatus</h3>' +
      '<div style="margin-bottom: 10px;"><strong>Status:</strong> ' + (isOnline ? '🟢 Online' : '🔴 Offline') + '</div>' +
      '<div style="margin-bottom: 10px;"><strong>Qualität:</strong> ' + this.getQualityLabel(quality) + '</div>' +
      '<div style="margin-bottom: 15px; font-size: 13px; color: #666;">' + this.getQualityDescription(quality) + '</div>' +
      '<div style="text-align: right;"><button id="connectionStatusOkBtn" style="background: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">OK</button></div>';

    // Backdrop
    const backdrop = document.createElement('div');
    backdrop.id = 'connection-backdrop';
    backdrop.style.position = 'fixed';
    backdrop.style.top = '0';
    backdrop.style.left = '0';
    backdrop.style.right = '0';
    backdrop.style.bottom = '0';
    backdrop.style.background = 'rgba(0,0,0,0.5)';
    backdrop.style.zIndex = '10000';
    backdrop.onclick = () => {
      modal.remove();
      backdrop.remove();
    };

    document.body.appendChild(backdrop);
    document.body.appendChild(modal);

    // Event-Listener für OK-Button
    setTimeout(() => {
      const okBtn = document.getElementById('connectionStatusOkBtn');
      if (okBtn) {
        okBtn.addEventListener('click', () => {
          modal.remove();
          backdrop.remove();
        });
      }
    }, 100);
  }

  static getQualityLabel(quality) {
    switch (quality) {
      case 'excellent': return '🟢 Ausgezeichnet';
      case 'good': return '🟢 Gut';
      case 'fair': return '🟡 Mäßig';
      case 'poor': return '🟠 Schlecht';
      case 'offline': return '🔴 Offline';
      default: return '⚪ Unbekannt';
    }
  }

  static getQualityDescription(quality) {
    switch (quality) {
      case 'excellent': return 'Sehr schnelle Verbindung (&lt;100ms). Alle Funktionen arbeiten optimal.';
      case 'good': return 'Gute Verbindung (100-500ms). Normale Geschwindigkeit erwartet.';
      case 'fair': return 'Mäßige Verbindung (500ms-1s). Leichte Verzögerungen möglich.';
      case 'poor': return 'Langsame Verbindung (&gt;1s). Deutliche Verzögerungen zu erwarten.';
      case 'offline': return 'Keine Internetverbindung verfügbar.';
      default: return 'Verbindung wird getestet...';
    }
  }

  /**
   * Wrapper für alte fetch-Aufrufe - macht Migration einfacher
   */
  static async legacyFetch(url, options = {}) {
    const retryOptions = {
      retries: 2,
      retryDelay: 800,
      timeout: 8000
    };

    try {
      return await this.request(url, options, retryOptions);
    } catch (error) {
      // Fallback zu normalem fetch für Kompatibilität
      console.warn('[HTTP] Falling back to regular fetch:', error.message);
      return fetch(url, options);
    }
  }

  /**
   * Initialisiert globale HTTP-Verbesserungen
   */
  static init() {
    // Connection Monitor starten
    const monitor = this.createConnectionMonitor();

    // Globale Variable für anderen Code
    window.connectionMonitor = monitor;

    // Permanente Statusanzeige erstellen
    if (document.body) {
      this.createPermanentStatusIndicator();
    } else {
      document.addEventListener('DOMContentLoaded', () => {
        this.createPermanentStatusIndicator();
      });
    }

    // Globale Update-Funktion für alle Indikatoren
    const updateAllIndicators = async () => {
      try {
        await monitor.testConnection();
        this.updatePermanentIndicator(monitor);

        // Update Navigation-Status falls vorhanden
        if (window.updateNavigationStatus && typeof window.updateNavigationStatus === 'function') {
          window.updateNavigationStatus();
        }
      } catch (e) {
        // Silent fail für Background-Tests
      }
    };

    // Globale Update-Funktion verfügbar machen
    window.updateConnectionStatus = updateAllIndicators;

    // Verbindungstest alle 30 Sekunden mit Update
    setInterval(updateAllIndicators, 30000);

    // Initial connection test nach 1 Sekunde
    setTimeout(updateAllIndicators, 1000);

    console.log('[HTTP] HttpUtils initialized with permanent status indicator');

    return monitor;
  }

  /**
   * Erweiterte Request-Methode mit automatischem Loading-Overlay
   */
  static async requestWithLoading(url, options = {}, retryOptions = {}, loadingMessage = null) {
    const operationType = loadingMessage || this.getOperationTypeFromUrl(url);

    return window.LoadingOverlay ?
      LoadingOverlay.wrap(async () => {
        return this.request(url, options, retryOptions);
      }, operationType) :
      this.request(url, options, retryOptions);
  }

  /**
   * JSON-Request mit automatischem Loading-Overlay
   */
  static async requestJsonWithLoading(url, options = {}, retryOptions = {}, loadingMessage = null) {
    const operationType = loadingMessage || this.getOperationTypeFromUrl(url);

    return window.LoadingOverlay ?
      LoadingOverlay.wrap(async () => {
        return this.requestJson(url, options, retryOptions);
      }, operationType) :
      this.requestJson(url, options, retryOptions);
  }

  /**
   * POST JSON mit automatischem Loading-Overlay
   */
  static async postJsonWithLoading(url, data, retryOptions = {}, loadingMessage = null) {
    const operationType = loadingMessage || this.getOperationTypeFromUrl(url);

    return window.LoadingOverlay ?
      LoadingOverlay.wrap(async () => {
        return this.postJson(url, data, retryOptions);
      }, operationType) :
      this.postJson(url, data, retryOptions);
  }

  /**
   * Batch-Request mit Progress-Anzeige und Loading-Overlay
   */
  static async batchRequestWithLoading(requests, options = {}, loadingMessage = 'Batch-Operation läuft...') {
    const {
      concurrency = 3,
      retryOptions = {},
      onProgress = null
    } = options;

    // Wenn LoadingOverlay verfügbar ist, verwende es
    if (window.LoadingOverlay) {
      return LoadingOverlay.batchRequestWithLoading(requests, {
        concurrency,
        retryOptions,
        onProgress: (completed, total, result) => {
          if (onProgress) onProgress(completed, total, result);
        }
      }, loadingMessage);
    } else {
      // Fallback ohne Loading-Overlay
      return this.batchRequest(requests, options);
    }
  }

  /**
   * Ermittelt Operationstyp aus URL für automatische Messages
   */
  static getOperationTypeFromUrl(url) {
    if (url.includes('getReservation')) return 'Reservierungsdaten werden geladen...';
    if (url.includes('getNames')) return 'Namensliste wird geladen...';
    if (url.includes('update')) return 'Daten werden gespeichert...';
    if (url.includes('delete')) return 'Einträge werden gelöscht...';
    if (url.includes('print')) return 'Druckauftrag wird vorbereitet...';
    if (url.includes('toggle')) return 'Status wird geändert...';
    if (url.includes('Arrangement')) return 'Arrangement wird zugewiesen...';
    if (url.includes('Diet')) return 'Diät wird zugewiesen...';
    if (url.includes('Checkin')) return 'Check-in wird verarbeitet...';
    if (url.includes('Checkout')) return 'Check-out wird verarbeitet...';
    return 'Daten werden verarbeitet...';
  }

}

// Auto-initialize wenn im Browser geladen
if (typeof window !== 'undefined') {
  window.HttpUtils = HttpUtils;

  // Auto-init nach DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => HttpUtils.init());
  } else {
    HttpUtils.init();
  }
}

// Export für Node.js/Module wenn verfügbar
if (typeof module !== 'undefined' && module.exports) {
  module.exports = HttpUtils;
}
