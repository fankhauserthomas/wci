/* ==============================================
   HAUPTAPPLIKATION - APP CONTROLLER
   ============================================== */

class ReservationApp {
    constructor() {
        this.version = '1.0.0';
        this.initialized = false;
        this.modules = {
            dataManager: null,
            tableManager: null,
            eventManager: null
        };

        this.init();
    }

    async init() {
        console.log(`🚀 Reservierungsystem ${this.version} wird initialisiert...`);

        try {
            // DOM Ready Check
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.start());
            } else {
                await this.start();
            }
        } catch (error) {
            console.error('Fehler bei der Initialisierung:', error);
            this.showCriticalError(error);
        }
    }

    async start() {
        Utils.performance.mark('app:init:start');

        try {
            // Module Verfügbarkeit prüfen
            this.checkModuleAvailability();

            // Module referenzieren
            this.linkModules();

            // Event System initialisieren
            this.setupAppEvents();

            // Service Worker registrieren (falls verfügbar)
            this.registerServiceWorker();

            // App als initialisiert markieren
            this.initialized = true;

            Utils.performance.measure('app:init:start');
            console.log('✅ Reservierungsystem erfolgreich initialisiert');

            // Ready Event emittieren
            Utils.eventBus.emit('app:ready', {
                version: this.version,
                modules: Object.keys(this.modules),
                performance: Utils.performance.marks
            });

        } catch (error) {
            console.error('Fehler beim Start der Anwendung:', error);
            this.showCriticalError(error);
        }
    }

    checkModuleAvailability() {
        const requiredModules = ['Utils', 'dataManager', 'tableManager', 'eventManager'];
        const missing = [];

        requiredModules.forEach(module => {
            if (!window[module]) {
                missing.push(module);
            }
        });

        if (missing.length > 0) {
            throw new Error(`Fehlende Module: ${missing.join(', ')}`);
        }
    }

    linkModules() {
        this.modules = {
            dataManager: window.dataManager,
            tableManager: window.tableManager,
            eventManager: window.eventManager
        };
    }

    setupAppEvents() {
        // Global Error Handler
        window.addEventListener('error', (event) => {
            this.handleGlobalError(event.error);
        });

        window.addEventListener('unhandledrejection', (event) => {
            this.handleGlobalError(event.reason);
        });

        // Network Status Monitoring
        Utils.eventBus.on('network:online', () => {
            this.handleNetworkChange(true);
        });

        Utils.eventBus.on('network:offline', () => {
            this.handleNetworkChange(false);
        });

        // Performance Monitoring
        Utils.eventBus.on('performance:warning', (data) => {
            console.warn('Performance Warning:', data);
        });

        // Cache Events
        Utils.eventBus.on('cache:invalidated', (data) => {
            console.log('Cache invalidiert:', data);
        });

        // Visibility Change (Tab Wechsel)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.initialized) {
                this.handleVisibilityChange();
            }
        });
    }

    registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/wci/res_list/sw.js')
                .then(registration => {
                    console.log('Service Worker registriert:', registration);
                })
                .catch(error => {
                    console.log('Service Worker Registrierung fehlgeschlagen:', error);
                });
        }
    }

    handleGlobalError(error) {
        console.error('Global Error:', error);

        // Error Tracking (falls implementiert)
        Utils.eventBus.emit('error:global', {
            error: error.message,
            stack: error.stack,
            timestamp: new Date().toISOString(),
            userAgent: navigator.userAgent,
            url: window.location.href
        });

        // User Feedback
        this.showErrorNotification('Ein unerwarteter Fehler ist aufgetreten.');
    }

    handleNetworkChange(isOnline) {
        const message = isOnline
            ? 'Internetverbindung wiederhergestellt'
            : 'Keine Internetverbindung';

        this.showNetworkNotification(message, isOnline);

        if (isOnline && this.initialized) {
            // Daten neu laden wenn wieder online
            this.modules.eventManager?.loadData();
        }
    }

    handleVisibilityChange() {
        // Auto-refresh wenn Tab wieder aktiv wird
        if (this.getTimeSinceLastUpdate() > 5 * 60 * 1000) { // 5 Minuten
            console.log('Tab wieder aktiv - Daten werden aktualisiert');
            this.modules.eventManager?.loadData();
        }
    }

    getTimeSinceLastUpdate() {
        const lastUpdate = Utils.storage.get('lastDataUpdate');
        return lastUpdate ? Date.now() - lastUpdate : Infinity;
    }

    showCriticalError(error) {
        document.body.innerHTML = `
            <div style="
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: #fee;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                color: #c53030;
                text-align: center;
                padding: 2rem;
            ">
                <div>
                    <h1 style="font-size: 2rem; margin-bottom: 1rem;">⚠️ Systemfehler</h1>
                    <p style="font-size: 1.2rem; margin-bottom: 2rem;">
                        Das Reservierungssystem konnte nicht geladen werden.
                    </p>
                    <details style="text-align: left; background: #fff; padding: 1rem; border-radius: 8px; margin-bottom: 2rem;">
                        <summary style="cursor: pointer; font-weight: bold;">Technische Details</summary>
                        <pre style="margin-top: 1rem; font-size: 0.9rem; overflow: auto;">${error.stack || error.message}</pre>
                    </details>
                    <button onclick="window.location.reload()" style="
                        background: #3182ce;
                        color: white;
                        border: none;
                        padding: 0.75rem 1.5rem;
                        border-radius: 6px;
                        font-size: 1rem;
                        cursor: pointer;
                    ">Seite neu laden</button>
                </div>
            </div>
        `;
    }

    showErrorNotification(message) {
        // Einfache Toast-Notification
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #fed7d7;
            color: #c53030;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid #fca5a5;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 10000;
            max-width: 400px;
            animation: slideIn 0.3s ease-out;
        `;

        toast.innerHTML = `
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <span>⚠️</span>
                <span>${Utils.escapeHtml(message)}</span>
                <button onclick="this.parentElement.parentElement.remove()" style="
                    background: none;
                    border: none;
                    font-size: 1.2rem;
                    cursor: pointer;
                    margin-left: auto;
                ">×</button>
            </div>
        `;

        document.body.appendChild(toast);

        // Auto-remove nach 5 Sekunden
        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 5000);
    }

    showNetworkNotification(message, isOnline) {
        const toast = document.createElement('div');
        const bgColor = isOnline ? '#d1fae5' : '#fef3cd';
        const textColor = isOnline ? '#065f46' : '#92400e';
        const icon = isOnline ? '🟢' : '🔴';

        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: ${bgColor};
            color: ${textColor};
            padding: 1rem 2rem;
            border-radius: 24px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 10000;
            animation: slideUp 0.3s ease-out;
            font-weight: 500;
        `;

        toast.innerHTML = `${icon} ${Utils.escapeHtml(message)}`;

        document.body.appendChild(toast);

        setTimeout(() => {
            if (toast.parentElement) {
                toast.remove();
            }
        }, 3000);
    }

    // Debug Methods
    getSystemInfo() {
        return {
            version: this.version,
            initialized: this.initialized,
            modules: Object.keys(this.modules).reduce((acc, key) => {
                acc[key] = !!this.modules[key];
                return acc;
            }, {}),
            performance: Utils.performance.marks,
            cache: this.modules.dataManager?.getCacheStats(),
            filters: this.modules.eventManager?.getCurrentFilters(),
            table: this.modules.tableManager?.getStats()
        };
    }

    async runDiagnostics() {
        console.group('🔍 System Diagnostics');

        try {
            // Modul Tests
            console.log('Modules:', this.getSystemInfo().modules);

            // API Tests
            console.log('Testing API...');
            const testResult = await fetch('/wci/res_list/api/test-simple.php?date=' + new Date().toISOString().split('T')[0]);
            console.log('API Response:', testResult.status, testResult.statusText);

            // Cache Tests
            console.log('Cache Stats:', this.modules.dataManager?.getCacheStats());

            // DOM Tests
            console.log('DOM Elements:', this.modules.eventManager?.getElementStates());

            console.log('✅ Diagnostics completed');

        } catch (error) {
            console.error('❌ Diagnostics failed:', error);
        }

        console.groupEnd();
    }

    // Public API Methods
    async refreshData() {
        if (this.modules.dataManager) {
            this.modules.dataManager.invalidateCache();
            await this.modules.eventManager?.loadData();
        }
    }

    async exportData() {
        const data = this.modules.tableManager?.currentData || [];
        const csv = this.convertToCSV(data);
        this.downloadFile(csv, 'reservierungen.csv', 'text/csv');
    }

    convertToCSV(data) {
        if (!data.length) return '';

        const headers = ['ID', 'Name', 'Anreise', 'Abreise', 'Gäste', 'Status', 'Herkunft', 'Bemerkung'];
        const rows = data.map(item => [
            item.id,
            item.fullName,
            Utils.formatDate(item.anreise),
            Utils.formatDate(item.abreise),
            item.guest_count,
            item.status,
            item.herkunft || '',
            item.bemerkung || ''
        ]);

        return [headers, ...rows]
            .map(row => row.map(field => `"${String(field).replace(/"/g, '""')}"`).join(','))
            .join('\n');
    }

    downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(url);
    }
}

// CSS Animation hinzufügen
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideUp {
        from { transform: translate(-50%, 100%); opacity: 0; }
        to { transform: translate(-50%, 0); opacity: 1; }
    }
`;
document.head.appendChild(style);

// App starten
const app = new ReservationApp();

// Global verfügbar machen für Debugging
window.app = app;

// Development Helpers
if (window.location.hostname === 'localhost' || window.location.hostname.includes('192.168')) {
    console.log('🛠️ Development Mode aktiv');
    console.log('Verfügbare Debug-Befehle:');
    console.log('- app.getSystemInfo()');
    console.log('- app.runDiagnostics()');
    console.log('- app.refreshData()');
    console.log('- app.exportData()');
}
