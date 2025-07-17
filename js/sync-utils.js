// Sync-Utilities für automatische Synchronisation
class SyncUtils {
    constructor() {
        this.syncInProgress = false;
        this.syncEnabled = true;
        this.lastSyncTime = null;
        this.syncInterval = 5 * 60 * 1000; // 5 Minuten
        this.debugMode = false;

        // Auto-sync bei Page Load
        this.triggerSync('page_load');

        // Setup periodic sync
        this.setupPeriodicSync();

        // Event listeners für kritische Actions
        this.setupEventListeners();
    }

    async triggerSync(action = 'manual') {
        if (this.syncInProgress || !this.syncEnabled) {
            this.log('Sync skipped: ' + (this.syncInProgress ? 'in progress' : 'disabled'));
            return;
        }

        this.syncInProgress = true;
        this.log(`Triggering sync: ${action}`);

        try {
            const response = await fetch('syncTrigger.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=sync&trigger=${encodeURIComponent(action)}`
            });

            const text = await response.text();
            let result;

            try {
                result = JSON.parse(text);
            } catch (jsonError) {
                this.log(`Invalid JSON response: ${text.substring(0, 200)}...`);
                throw new Error('Server returned invalid JSON');
            }

            if (result.success) {
                this.lastSyncTime = new Date();
                this.log(`Sync completed: ${JSON.stringify(result.results)}`);

                // Dispatch custom event für andere Components
                window.dispatchEvent(new CustomEvent('syncCompleted', {
                    detail: result
                }));
            } else {
                this.log(`Sync failed: ${result.error}`);
            }

        } catch (error) {
            this.log(`Sync error: ${error.message}`);
        } finally {
            this.syncInProgress = false;
        }
    }

    setupPeriodicSync() {
        setInterval(() => {
            if (!this.syncInProgress) {
                this.triggerSync('periodic');
            }
        }, this.syncInterval);
    }

    setupEventListeners() {
        // Sync bei wichtigen Aktionen
        const criticalActions = [
            'form[action*="update"]',
            'form[action*="add"]',
            'form[action*="delete"]',
            '.btn-save',
            '.btn-update'
        ];

        criticalActions.forEach(selector => {
            document.addEventListener('submit', (e) => {
                if (e.target.matches(selector)) {
                    setTimeout(() => {
                        this.triggerSync('user_action');
                    }, 1000);
                }
            });

            document.addEventListener('click', (e) => {
                if (e.target.matches(selector)) {
                    setTimeout(() => {
                        this.triggerSync('user_action');
                    }, 1000);
                }
            });
        });

        // Sync wenn Tab wieder aktiv wird
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.lastSyncTime) {
                const timeSinceLastSync = Date.now() - this.lastSyncTime.getTime();
                if (timeSinceLastSync > this.syncInterval) {
                    this.triggerSync('tab_focus');
                }
            }
        });

        // Sync bei Window Focus
        window.addEventListener('focus', () => {
            if (this.lastSyncTime) {
                const timeSinceLastSync = Date.now() - this.lastSyncTime.getTime();
                if (timeSinceLastSync > 30000) { // 30 Sekunden
                    this.triggerSync('window_focus');
                }
            }
        });
    }

    // Manueller Sync-Trigger für Buttons etc.
    async manualSync() {
        return await this.triggerSync('manual');
    }

    // Sync-Status prüfen
    async getSyncStatus() {
        try {
            const response = await fetch('syncTrigger.php?action=status');
            return await response.json();
        } catch (error) {
            this.log(`Status check failed: ${error.message}`);
            return { success: false, error: error.message };
        }
    }

    // Sync aktivieren/deaktivieren
    enableSync(enabled = true) {
        this.syncEnabled = enabled;
        this.log(`Sync ${enabled ? 'enabled' : 'disabled'}`);
    }

    // Debug-Mode
    setDebugMode(debug = true) {
        this.debugMode = debug;
    }

    log(message) {
        if (this.debugMode) {
            console.log(`[SyncUtils] ${new Date().toISOString()}: ${message}`);
        }
    }

    // Sync-Indicator für UI
    showSyncIndicator(show = true) {
        let indicator = document.getElementById('sync-indicator');

        if (show && !indicator) {
            indicator = document.createElement('div');
            indicator.id = 'sync-indicator';
            indicator.innerHTML = '🔄 Synchronisiere...';
            indicator.style.cssText = `
                position: fixed;
                top: 10px;
                right: 10px;
                background: #007cba;
                color: white;
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 12px;
                z-index: 9999;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            `;
            document.body.appendChild(indicator);

            // Auto-hide nach 3 Sekunden
            setTimeout(() => {
                if (indicator) indicator.remove();
            }, 3000);
        }
    }
}

// Auto-initialize wenn DOM ready
document.addEventListener('DOMContentLoaded', function () {
    // Globale Sync-Instance
    window.syncUtils = new SyncUtils();

    // Optional: Debug Mode für Development
    if (window.location.hostname === '192.168.15.14' || window.location.search.includes('sync_debug=1')) {
        window.syncUtils.setDebugMode(true);
    }

    // Event handler für sync events
    window.addEventListener('syncCompleted', function (e) {
        console.log('Sync completed:', e.detail);

        // Optional: UI feedback
        if (e.detail.results) {
            const { local_to_remote, remote_to_local, conflicts } = e.detail.results;
            if (local_to_remote > 0 || remote_to_local > 0) {
                console.log(`Synced: ${local_to_remote} to remote, ${remote_to_local} from remote, ${conflicts} conflicts`);
            }
        }
    });
});

// Helper function für manuelle Sync-Buttons
function triggerManualSync() {
    if (window.syncUtils) {
        window.syncUtils.showSyncIndicator(true);
        return window.syncUtils.manualSync();
    }
}

// Export für Module
if (typeof module !== 'undefined' && module.exports) {
    module.exports = SyncUtils;
}
