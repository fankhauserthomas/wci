/**
 * Globales Loading-Overlay System
 * Sperrt das gesamte GUI und zeigt animiertes Wartesymbol
 */
class LoadingOverlay {
  static overlay = null;
  static isVisible = false;
  static currentOperations = new Set();
  static delayedOperations = new Map(); // RequestId -> {timeout, startTime}
  static DELAY_THRESHOLD = 800; // 800ms threshold for showing loading

  /**
   * Zeigt das Loading-Overlay mit optionaler Verzögerung
   */
  static show(message = 'Lade Daten...', immediate = false) {
    if (this.isVisible) {
      // Nur Message aktualisieren wenn bereits sichtbar
      this.updateMessage(message);
      return;
    }

    // Sofort anzeigen wenn immediate=true
    if (immediate) {
      this.showImmediate(message);
      return;
    }

    // Prüfen ob verzögert angezeigt werden soll
    const requestId = `show-${Date.now()}-${Math.random()}`;
    this.scheduleDelayedShow(requestId, message);
    return requestId;
  }

  /**
   * Zeigt das Loading-Overlay sofort an
   */
  static showImmediate(message = 'Lade Daten...') {
    this.createOverlay();
    this.updateMessage(message);
    this.overlay.classList.add('visible');
    this.isVisible = true;

    // GUI komplett sperren
    document.body.style.overflow = 'hidden';
    document.body.style.userSelect = 'none';
    document.body.style.pointerEvents = 'none';
    document.body.classList.add('loading-active');
    this.overlay.style.pointerEvents = 'auto'; // Overlay selbst kann Events empfangen

    console.log('[LOADING] Overlay shown immediately:', message);
  }

  /**
   * Zeigt das Loading-Overlay mit Verzögerung an (nur wenn länger als DELAY_THRESHOLD)
   */
  static showWithDelay(message = 'Lade Daten...', delayMs = this.DELAY_THRESHOLD) {
    const requestId = `delayed-${Date.now()}-${Math.random()}`;
    const startTime = Date.now();

    const timeoutId = setTimeout(() => {
      // Nur anzeigen wenn Request noch läuft
      if (this.delayedOperations.has(requestId)) {
        this.showImmediate(message);
        const operation = this.delayedOperations.get(requestId);
        operation.shown = true;
        console.log(`[LOADING] Delayed overlay shown after ${Date.now() - startTime}ms:`, message);
      }
    }, delayMs);

    this.delayedOperations.set(requestId, {
      timeout: timeoutId,
      startTime: startTime,
      shown: false,
      message: message
    });

    return requestId;
  }

  /**
   * Plant verzögerte Anzeige
   */
  static scheduleDelayedShow(requestId, message) {
    const startTime = Date.now();

    const timeoutId = setTimeout(() => {
      if (this.delayedOperations.has(requestId)) {
        this.showImmediate(message);
        const operation = this.delayedOperations.get(requestId);
        operation.shown = true;
        console.log(`[LOADING] Delayed overlay shown after ${Date.now() - startTime}ms:`, message);
      }
    }, this.DELAY_THRESHOLD);

    this.delayedOperations.set(requestId, {
      timeout: timeoutId,
      startTime: startTime,
      shown: false,
      message: message
    });
  }

  /**
   * Versteckt das Loading-Overlay für eine spezifische Anfrage
   */
  static hideForRequest(requestId) {
    if (!requestId) {
      return this.hide();
    }

    // Verzögerte Operation abbrechen falls noch nicht angezeigt
    if (this.delayedOperations.has(requestId)) {
      const operation = this.delayedOperations.get(requestId);
      const duration = Date.now() - operation.startTime;

      clearTimeout(operation.timeout);
      this.delayedOperations.delete(requestId);

      // Nur verstecken wenn diese Operation das Overlay angezeigt hat
      if (operation.shown) {
        this.forceHide();
        console.log(`[LOADING] Fast operation completed in ${duration}ms, overlay was shown`);
      } else {
        console.log(`[LOADING] Fast operation completed in ${duration}ms, overlay was not shown`);
      }
      return;
    }

    // Fallback auf normale hide-Logik
    this.hide();
  }

  /**
   * Versteckt das Loading-Overlay (normal)
   */
  static hide() {
    if (!this.isVisible || this.currentOperations.size > 0) {
      return;
    }

    this.forceHide();
  }

  /**
   * Versteckt das Loading-Overlay sofort (ignoriert aktive Operationen)
   */
  static forceHide() {
    if (this.overlay) {
      this.overlay.classList.remove('visible');
      setTimeout(() => {
        if (this.overlay && !this.isVisible) {
          document.body.removeChild(this.overlay);
          this.overlay = null;
        }
      }, 300); // Fade-out Animation Zeit
    }

    // GUI entsperren
    document.body.style.overflow = '';
    document.body.style.userSelect = '';
    document.body.style.pointerEvents = '';
    document.body.classList.remove('loading-active');

    this.isVisible = false;
    console.log('[LOADING] Overlay hidden (forced)');
  }

  /**
   * Aktualisiert die Nachricht
   */
  static updateMessage(message) {
    if (this.overlay) {
      const messageElement = this.overlay.querySelector('.loading-message');
      if (messageElement) {
        messageElement.textContent = message;
      }
    }
  }

  /**
   * Erstellt das Overlay-Element
   */
  static createOverlay() {
    if (this.overlay) return;

    this.overlay = document.createElement('div');
    this.overlay.className = 'loading-overlay';
    this.overlay.innerHTML = `
      <div class="loading-content">
        <div class="loading-spinner">
          <div class="spinner-ring"></div>
          <div class="spinner-ring"></div>
          <div class="spinner-ring"></div>
        </div>
        <div class="loading-message">Lade Daten...</div>
        <div class="loading-progress">
          <div class="progress-bar">
            <div class="progress-fill"></div>
          </div>
          <div class="progress-text"></div>
        </div>
      </div>
    `;

    document.body.appendChild(this.overlay);
  }

  /**
   * Zeigt Progress-Informationen (für Batch-Operationen)
   */
  static showProgress(current, total, message = null) {
    if (!this.isVisible) {
      this.show(message || `Verarbeitung läuft...`);
    }

    if (this.overlay) {
      const progressContainer = this.overlay.querySelector('.loading-progress');
      const progressBar = this.overlay.querySelector('.progress-fill');
      const progressText = this.overlay.querySelector('.progress-text');

      if (progressContainer && progressBar && progressText) {
        progressContainer.style.display = 'block';
        const percentage = Math.round((current / total) * 100);
        progressBar.style.width = `${percentage}%`;
        progressText.textContent = `${current} von ${total} (${percentage}%)`;

        if (message) {
          this.updateMessage(message);
        }
      }
    }
  }

  /**
   * Versteckt Progress-Anzeige
   */
  static hideProgress() {
    if (this.overlay) {
      const progressContainer = this.overlay.querySelector('.loading-progress');
      if (progressContainer) {
        progressContainer.style.display = 'none';
      }
    }
  }

  /**
   * Operation registrieren (für mehrere parallele Operationen)
   */
  static registerOperation(operationId, message = 'Operation läuft...', useDelay = true) {
    this.currentOperations.add(operationId);

    if (useDelay) {
      const requestId = this.showWithDelay(message);
      // Verbinde Operation mit Request-ID für cleanup
      this.currentOperations.add(`${operationId}:${requestId}`);
      return { operationId, requestId };
    } else {
      this.showImmediate(message);
      return { operationId, requestId: null };
    }
  }

  /**
   * Operation abmelden
   */
  static unregisterOperation(operationData) {
    if (typeof operationData === 'string') {
      // Legacy support
      this.currentOperations.delete(operationData);
      if (this.currentOperations.size === 0) {
        setTimeout(() => this.hide(), 100);
      }
      return;
    }

    if (!operationData) {
      console.warn('[LOADING] unregisterOperation called with null/undefined operationData');
      return;
    }

    const { operationId, requestId } = operationData;

    // Cleanup alle verwandten Operations
    this.currentOperations.delete(operationId);
    if (requestId) {
      this.currentOperations.delete(`${operationId}:${requestId}`);
      this.hideForRequest(requestId);
    } else {
      // Bei immediate operations (kein requestId) force hide verwenden
      if (this.currentOperations.size === 0) {
        setTimeout(() => this.forceHide(), 100);
      }
    }

    // Verstecke Overlay wenn keine aktiven Operationen mehr
    if (this.currentOperations.size === 0) {
      setTimeout(() => this.hide(), 100);
    }
  }

  /**
   * Wrapper für Async-Operationen mit verbesserter Performance
   */
  static async wrap(operation, message = 'Operation läuft...', useDelay = true) {
    const operationData = this.registerOperation(`op-${Date.now()}`, message, useDelay);

    try {
      const result = await operation();
      return result;
    } finally {
      this.unregisterOperation(operationData);
    }
  }

  /**
   * Wrapper für Fetch-Operationen mit automatischer Message-Generierung und optimierter Performance
   */
  static async wrapFetch(fetchOperation, operationType = 'Daten', useDelay = true) {
    return this.wrap(fetchOperation, `${operationType} werden geladen...`, useDelay);
  }

  /**
   * Performance-optimierter HTTP Request Wrapper
   */
  static async httpRequest(url, options = {}, message = null, useDelay = true) {
    const autoMessage = message || this.getOperationTypeFromUrl(url);
    const requestId = useDelay ? this.showWithDelay(autoMessage) : null;

    if (!useDelay) {
      this.showImmediate(autoMessage);
    }

    try {
      const response = await fetch(url, options);

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      return result;

    } finally {
      if (requestId) {
        this.hideForRequest(requestId);
      } else {
        this.hide();
      }
    }
  }

  /**
   * Batch-Request mit Progress-Anzeige
   */
  static async batchRequestWithLoading(requests, options = {}, loadingMessage = 'Batch-Operation läuft...') {
    const {
      concurrency = 3,
      retryOptions = {},
      onProgress = null
    } = options;

    // Verwende immediate Loading für Batch-Operationen
    const operationData = this.registerOperation(`batch-${Date.now()}`, loadingMessage, false);

    try {
      const results = [];
      const errors = [];
      let completed = 0;

      // Show progress bar für Batch-Operationen
      const progressContainer = this.overlay?.querySelector('.loading-progress');
      if (progressContainer) {
        progressContainer.style.display = 'block';
      }

      // Process requests in batches
      for (let i = 0; i < requests.length; i += concurrency) {
        const batch = requests.slice(i, i + concurrency);

        const batchPromises = batch.map(async (request, batchIndex) => {
          const globalIndex = i + batchIndex;
          try {
            const response = await fetch(request.url, request.options);
            if (!response.ok) {
              throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            const result = await response.json();
            results[globalIndex] = { success: true, data: result, request };
          } catch (error) {
            console.error(`[BATCH] Request ${globalIndex} failed:`, error);
            results[globalIndex] = { success: false, error: error.message, request };
            errors.push({ index: globalIndex, error, request });
          }

          completed++;

          // Update progress
          this.showProgress(completed, requests.length, loadingMessage);

          if (onProgress) {
            onProgress(completed, requests.length, results[globalIndex]);
          }
        });

        await Promise.all(batchPromises);
      }

      return { results, errors, successCount: results.filter(r => r.success).length };

    } finally {
      this.hideProgress();
      this.unregisterOperation(operationData);
    }
  }

  /**
   * Konfiguriert das Delay-Verhalten
   */
  static setDelayThreshold(delayMs) {
    this.DELAY_THRESHOLD = delayMs;
    console.log(`[LOADING] Delay threshold set to ${delayMs}ms`);
  }

  /**
   * Performance-Statistiken abrufen
   */
  static getPerformanceStats() {
    return {
      activeOperations: this.currentOperations.size,
      delayedOperations: this.delayedOperations.size,
      delayThreshold: this.DELAY_THRESHOLD,
      isVisible: this.isVisible
    };
  }

  /**
   * Debug-Informationen für aktive Operationen
   */
  static debugOperations() {
    console.log('[LOADING DEBUG] Current Operations:', Array.from(this.currentOperations));
    console.log('[LOADING DEBUG] Delayed Operations:', Array.from(this.delayedOperations.entries()).map(([id, op]) => ({
      id,
      duration: Date.now() - op.startTime,
      shown: op.shown,
      message: op.message
    })));
    console.log('[LOADING DEBUG] Performance Stats:', this.getPerformanceStats());
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

  /**
   * Initialisierung des Overlay-Systems
   */
  static init() {
    // CSS-Styles hinzufügen
    this.addStyles();

    // Event-Listener für Escape-Taste (Debug)
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && e.ctrlKey && e.shiftKey) {
        console.log('[LOADING] Emergency hide triggered');
        this.currentOperations.clear();
        // Alle delayed operations abbrechen
        this.delayedOperations.forEach((op, id) => {
          clearTimeout(op.timeout);
        });
        this.delayedOperations.clear();
        this.forceHide();
      }

      // Debug-Shortcut: Ctrl+Shift+D
      if (e.key === 'D' && e.ctrlKey && e.shiftKey) {
        this.debugOperations();
      }
    });

    console.log(`[LOADING] LoadingOverlay initialized with ${this.DELAY_THRESHOLD}ms delay threshold`);
  }

  /**
   * CSS-Styles hinzufügen
   */
  static addStyles() {
    if (document.getElementById('loading-overlay-styles')) return;

    const style = document.createElement('style');
    style.id = 'loading-overlay-styles';
    style.textContent = `
      .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(3px);
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
      }

      .loading-overlay.visible {
        opacity: 1;
        visibility: visible;
      }

      .loading-content {
        text-align: center;
        color: white;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        padding: 30px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
        min-width: 300px;
      }

      .loading-spinner {
        position: relative;
        width: 60px;
        height: 60px;
        margin: 0 auto 20px auto;
      }

      .spinner-ring {
        position: absolute;
        width: 60px;
        height: 60px;
        border: 3px solid transparent;
        border-radius: 50%;
        animation: spin 1.5s linear infinite;
      }

      .spinner-ring:nth-child(1) {
        border-top-color: #3498db;
        animation-delay: 0s;
      }

      .spinner-ring:nth-child(2) {
        border-right-color: #e74c3c;
        animation-delay: 0.3s;
        width: 50px;
        height: 50px;
        top: 5px;
        left: 5px;
      }

      .spinner-ring:nth-child(3) {
        border-bottom-color: #f39c12;
        animation-delay: 0.6s;
        width: 40px;
        height: 40px;
        top: 10px;
        left: 10px;
      }

      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }

      .loading-message {
        font-size: 16px;
        font-weight: 500;
        margin-bottom: 20px;
        color: #ffffff;
      }

      .loading-progress {
        margin-top: 15px;
        display: none;
      }

      .progress-bar {
        width: 100%;
        height: 6px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 3px;
        overflow: hidden;
        margin-bottom: 10px;
      }

      .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #3498db, #2ecc71);
        width: 0%;
        transition: width 0.3s ease;
        border-radius: 3px;
      }

      .progress-text {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.8);
      }

      /* Zusätzliche Sicherheit für UI-Sperrung */
      body.loading-active {
        overflow: hidden !important;
        user-select: none !important;
      }

      body.loading-active * {
        pointer-events: none !important;
      }

      body.loading-active .loading-overlay {
        pointer-events: auto !important;
      }

      body.loading-active .loading-overlay * {
        pointer-events: auto !important;
      }
    `;

    document.head.appendChild(style);
  }
}

// Auto-Initialisierung
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => LoadingOverlay.init());
} else {
  LoadingOverlay.init();
}

// Export für andere Module
if (typeof module !== 'undefined' && module.exports) {
  module.exports = LoadingOverlay;
} else if (typeof window !== 'undefined') {
  window.LoadingOverlay = LoadingOverlay;
}
