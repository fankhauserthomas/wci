/**
 * Globales Loading-Overlay System
 * Sperrt das gesamte GUI und zeigt animiertes Wartesymbol
 */
class LoadingOverlay {
  static overlay = null;
  static isVisible = false;
  static currentOperations = new Set();

  /**
   * Zeigt das Loading-Overlay
   */
  static show(message = 'Lade Daten...') {
    if (this.isVisible) {
      // Nur Message aktualisieren wenn bereits sichtbar
      this.updateMessage(message);
      return;
    }

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
    
    console.log('[LOADING] Overlay shown:', message);
  }

  /**
   * Versteckt das Loading-Overlay
   */
  static hide() {
    if (!this.isVisible || this.currentOperations.size > 0) {
      return;
    }

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
    console.log('[LOADING] Overlay hidden');
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
  static registerOperation(operationId, message = 'Operation läuft...') {
    this.currentOperations.add(operationId);
    this.show(message);
    return operationId;
  }

  /**
   * Operation abmelden
   */
  static unregisterOperation(operationId) {
    this.currentOperations.delete(operationId);
    if (this.currentOperations.size === 0) {
      setTimeout(() => this.hide(), 500); // Kurze Verzögerung für bessere UX
    }
  }

  /**
   * Wrapper für Async-Operationen
   */
  static async wrap(operation, message = 'Operation läuft...') {
    const operationId = this.registerOperation(`op-${Date.now()}`, message);
    
    try {
      const result = await operation();
      return result;
    } finally {
      this.unregisterOperation(operationId);
    }
  }

  /**
   * Wrapper für Fetch-Operationen mit automatischer Message-Generierung
   */
  static async wrapFetch(fetchOperation, operationType = 'Daten') {
    return this.wrap(fetchOperation, `${operationType} werden geladen...`);
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

    const operationId = this.registerOperation(`batch-${Date.now()}`, loadingMessage);

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
      this.unregisterOperation(operationId);
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
        this.hide();
      }
    });

    console.log('[LOADING] LoadingOverlay initialized');
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
