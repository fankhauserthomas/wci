// Koordiniertes Auto-Refresh-System (Version 2.0)
// Arbeitet zusammen mit script.js statt dagegen

let autoRefreshInterval = null;
let autoRefreshEnabled = false;
let scriptJsIsLoading = false;
let lastTableRebuild = 0;
let pendingSortUpdate = false;
let isTabActive = !document.hidden;

function startAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }

    autoRefreshEnabled = true;
    console.log('🔄 Koordiniertes Auto-Refresh gestartet (alle 10 Sekunden)');

    autoRefreshInterval = setInterval(async () => {
        if (!autoRefreshEnabled || scriptJsIsLoading) {
            pendingSortUpdate = true;
            console.log('🔄 Auto-Refresh pausiert - Sortierung wird nachgeholt');
            return;
        }

        // Prüfe ob script.js gerade Tabelle neu erstellt hat
        if (Date.now() - lastTableRebuild < 3000) {
            console.log('🔄 Auto-Refresh übersprungen (Tabelle kürzlich neu erstellt)');
            return;
        }

        try {
            console.log('🔄 Koordiniertes Auto-Refresh startet...');

            // Signalisiere script.js dass wir arbeiten
            window.dispatchEvent(new CustomEvent('autoRefreshStarted'));

            // Nur HP-Daten laden (keine Tabellen-Neuberäung)
            if (typeof window.loadRealHpData === 'function') {
                // Cache invalidieren
                if (window.realHpData) {
                    window.realHpData.clear();
                }
                if (window.lastHpDataKey) {
                    window.lastHpDataKey = null;
                }

                const success = await window.loadRealHpData();

                if (success) {
                    // Nur Formatierung anwenden
                    await applyFormattingOnly();
                    console.log('✅ Auto-Refresh erfolgreich abgeschlossen');
                }
            }

            // Signalisiere script.js dass wir fertig sind
            window.dispatchEvent(new CustomEvent('autoRefreshCompleted'));

        } catch (error) {
            console.error('❌ Auto-Refresh Fehler:', error);
        }
    }, 10000);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
    autoRefreshEnabled = false;
    console.log('⏹️ Auto-Refresh gestoppt');
}

// Neue Funktion: Nur Formatierung anwenden (keine Daten laden)
async function applyFormattingOnly() {
    console.log('🎨 Wende nur Formatierung an...');

    // 1. Farbschema aktualisieren
    if (typeof window.updateColorScheme === 'function') {
        const mode = localStorage.getItem('filterMode') || 'arrival';
        const isArrival = mode.startsWith('arrival');
        window.updateColorScheme(isArrival);
        console.log('✅ Farbschema aktualisiert');
    }

    // 2. Sortierung anwenden
    const tbody = document.querySelector('#resTable tbody');
    if (!tbody) {
        console.warn('⚠️ Tabelle nicht gefunden');
        return false;
    }

    if (!window.realHpData || window.realHpData.size === 0) {
        console.warn('⚠️ Keine HP-Daten für Sortierung verfügbar');
        return false;
    }

    const rows = tbody.querySelectorAll('tr');
    let appliedCount = 0;

    rows.forEach((row, index) => {
        if (typeof window.setSortGroupForRow === 'function') {
            const resId = parseInt(row.dataset.resId);
            if (window.realHpData.has(resId)) {
                window.setSortGroupForRow(row);
                appliedCount++;

                // Debug für erste 2 Zeilen
                if (index < 2) {
                    const nameCell = row.querySelector('.name-cell');
                    const classes = nameCell ? Array.from(nameCell.classList).filter(c => c.startsWith('sort-group')).join(', ') : 'keine';
                    const realData = window.realHpData.get(resId);
                    console.log(`🔍 Zeile ${index}: ResID ${resId}, Gruppe "${realData.sortGroup}", CSS: [${classes}]`);
                }
            }
        }
    });

    // Forced Repaint für sofortige Sichtbarkeit
    tbody.style.visibility = 'hidden';
    tbody.offsetHeight; // Force reflow
    tbody.style.visibility = 'visible';

    console.log(`✅ Formatierung angewendet: ${appliedCount}/${rows.length} Zeilen verarbeitet`);
    pendingSortUpdate = false;
    return true;
}

// Event-Listener für Koordination mit script.js
function setupCoordinatedEvents() {
    // Höre auf script.js Signale
    window.addEventListener('tableRebuilding', () => {
        scriptJsIsLoading = true;
        console.log('📡 script.js erstellt Tabelle neu - Auto-Refresh pausiert');
    });

    window.addEventListener('tableRebuilt', () => {
        scriptJsIsLoading = false;
        lastTableRebuild = Date.now();
        console.log('📡 Tabelle von script.js neu erstellt - Auto-Refresh reaktiviert');

        // Nach Tabellen-Neuerstellung sofort Formatierung anwenden
        setTimeout(async () => {
            if (window.realHpData && window.realHpData.size > 0) {
                console.log('🎨 Wende Formatierung nach Tabellen-Rebuild an');
                await applyFormattingOnly();
            } else {
                pendingSortUpdate = true;
                console.log('⏳ Formatierung wird nachgeholt sobald Daten verfügbar');
            }
        }, 200);
    });

    // Tab-Sichtbarkeit verwalten
    document.addEventListener('visibilitychange', () => {
        const wasActive = isTabActive;
        isTabActive = !document.hidden;

        if (!wasActive && isTabActive) {
            console.log('🔄 Tab aktiviert');
            autoRefreshEnabled = true;

            // Formatierung nachholen falls nötig
            if (pendingSortUpdate) {
                setTimeout(async () => {
                    console.log('🎨 Hole ausstehende Formatierung nach');
                    await applyFormattingOnly();
                }, 300);
            }

            // Auto-Refresh wieder starten
            if (!autoRefreshInterval) {
                setTimeout(startAutoRefresh, 1000);
            }
        } else if (wasActive && !isTabActive) {
            console.log('🔄 Tab deaktiviert');
            autoRefreshEnabled = false;
            pendingSortUpdate = true;
        }
    });

    // Page Show für Mobile/Zurück-Navigation
    window.addEventListener('pageshow', (event) => {
        console.log('🔄 Page Show Event');
        if (pendingSortUpdate) {
            setTimeout(() => applyFormattingOnly(), 200);
        }
    });

    // Window Focus für Desktop
    window.addEventListener('focus', () => {
        console.log('🔄 Window Focus');
        if (pendingSortUpdate) {
            setTimeout(() => applyFormattingOnly(), 100);
        }
    });

    console.log('✅ Koordinierte Event-Listener installiert');
}

// Initialisierung
window.addEventListener('load', () => {
    // Event-System sofort installieren
    setupCoordinatedEvents();

    // Auto-Refresh nach Verzögerung starten
    setTimeout(() => {
        if (typeof window.loadRealHpData === 'function' || typeof window.loadData === 'function') {
            startAutoRefresh();

            // Initiale Formatierung
            setTimeout(() => {
                console.log('🎨 Initiale Formatierung beim Laden');
                applyFormattingOnly();
            }, 2000);
        } else {
            console.warn('⚠️ Warte auf Verfügbarkeit der Funktionen...');
            setTimeout(() => {
                if (typeof window.loadRealHpData === 'function') {
                    startAutoRefresh();
                }
            }, 3000);
        }
    }, 2000);
});

// Global verfügbare Funktionen
window.startAutoRefresh = startAutoRefresh;
window.stopAutoRefresh = stopAutoRefresh;
window.applyFormattingOnly = applyFormattingOnly;

// Debug-Funktionen
window.testCoordinatedSorting = () => applyFormattingOnly();
window.debugCoordinatedRefresh = function () {
    console.log('=== KOORDINIERTES AUTO-REFRESH DEBUG ===');
    console.log('autoRefreshEnabled:', autoRefreshEnabled);
    console.log('scriptJsIsLoading:', scriptJsIsLoading);
    console.log('pendingSortUpdate:', pendingSortUpdate);
    console.log('isTabActive:', isTabActive);
    console.log('lastTableRebuild:', new Date(lastTableRebuild).toLocaleTimeString());
    console.log('autoRefreshInterval:', autoRefreshInterval ? 'läuft' : 'gestoppt');
    console.log('realHpData verfügbar:', !!window.realHpData);
    console.log('realHpData Größe:', window.realHpData ? window.realHpData.size : 'N/A');

    const tbody = document.querySelector('#resTable tbody');
    console.log('Tabelle:', tbody ? `${tbody.children.length} Zeilen` : 'nicht gefunden');

    console.log('--- TESTE FORMATIERUNG ---');
    window.testCoordinatedSorting();
};

console.log('✅ Koordiniertes Auto-Refresh-System geladen');
