// Automatisches Refresh-System (Hintergrund)
let autoRefreshInterval = null;
let autoRefreshEnabled = false;

function startAutoRefresh() {
  if (autoRefreshInterval) {
    clearInterval(autoRefreshInterval);
  }

  autoRefreshEnabled = true;
  console.log('🔄 Auto-Refresh gestartet (läuft im Hintergrund alle 10 Sekunden)');

  autoRefreshInterval = setInterval(async () => {
    if (!autoRefreshEnabled) {
      // Tab ist inaktiv - markiere dass Sortierung nötig ist
      pendingSortUpdate = true;
      console.log('🔄 Auto-Refresh: Tab inaktiv - Sortierung wird bei Aktivierung nachgeholt');
      return;
    }

    try {
      console.log('🔄 Auto-Refresh: Aktualisiere Daten...');

      // Cache invalidieren und neu laden
      if (window.realHpData) {
        window.realHpData.clear();
      }
      if (window.lastHpDataKey) {
        window.lastHpDataKey = null;
      }

      // Prüfe ob loadRealHpData verfügbar ist
      if (typeof window.loadRealHpData === 'function') {
        const success = await window.loadRealHpData();

        if (success) {
          // Kurze Pause für DOM-Updates
          await new Promise(resolve => setTimeout(resolve, 100));

          // Farbschema aktualisieren (zuerst)
          if (typeof window.updateColorScheme === 'function') {
            // Bestimme aktuellen Modus
            const mode = localStorage.getItem('filterMode') || 'arrival';
            const isArrival = mode.startsWith('arrival');
            window.updateColorScheme(isArrival);
            console.log('✅ Auto-Refresh: Farbschema aktualisiert');
          }

          // Weitere Pause vor Sortierung
          await new Promise(resolve => setTimeout(resolve, 200));

          // NUR Sortierung anwenden OHNE erneuten Datenload
          const tbody = document.querySelector('#resTable tbody');
          if (tbody && window.realHpData) {
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(row => {
              if (typeof window.setSortGroupForRow === 'function') {
                window.setSortGroupForRow(row);
              }
            });
            console.log('✅ Auto-Refresh: Sortgruppen direkt angewendet (ohne erneuten Datenload)');

            // Stelle sicher, dass pending flag zurückgesetzt wird
            pendingSortUpdate = false;
          }

          console.log('✅ Auto-Refresh: Vollständig aktualisiert (Daten + Formatierung + Sortierung)');
        }
      } else if (typeof window.loadData === 'function') {
        // Fallback auf loadData wenn loadRealHpData nicht verfügbar
        await window.loadData();

        // Auch hier Formatierung anwenden
        if (typeof window.setAllSortGroups === 'function') {
          await window.setAllSortGroups();
          console.log('✅ Auto-Refresh: Sortgruppen über loadData angewendet');
        }

        // Farbschema auch im Fallback
        if (typeof window.updateColorScheme === 'function') {
          const mode = localStorage.getItem('filterMode') || 'arrival';
          const isArrival = mode.startsWith('arrival');
          window.updateColorScheme(isArrival);
          console.log('✅ Auto-Refresh: Farbschema über loadData aktualisiert');
        }

        console.log('✅ Auto-Refresh: Daten über loadData aktualisiert + formatiert');
      } else {
        console.warn('⚠️ Auto-Refresh: Keine Ladefunktionen verfügbar');
      }

    } catch (error) {
      console.error('❌ Auto-Refresh Fehler:', error);
    }
  }, 10000); // 10 Sekunden
}

function stopAutoRefresh() {
  if (autoRefreshInterval) {
    clearInterval(autoRefreshInterval);
    autoRefreshInterval = null;
  }
  autoRefreshEnabled = false;
  console.log('⏹️ Auto-Refresh gestoppt');
}

// Global verfügbar machen
window.startAutoRefresh = startAutoRefresh;
window.stopAutoRefresh = stopAutoRefresh;

// Debug- und Test-Funktionen
window.testImmediateSorting = () => applyImmediateSorting('manual-test');
window.forceSortingNow = () => {
  console.log('🔧 Erzwinge Sortierung JETZT...');
  pendingSortUpdate = true;
  return applyImmediateSorting('force-manual');
};

window.debugAutoRefresh = function () {
  console.log('=== AUTO-REFRESH DEBUG ===');
  console.log('autoRefreshEnabled:', autoRefreshEnabled);
  console.log('pendingSortUpdate:', pendingSortUpdate);
  console.log('isTabActive:', isTabActive);
  console.log('autoRefreshInterval:', autoRefreshInterval ? 'läuft' : 'gestoppt');
  console.log('realHpData verfügbar:', !!window.realHpData);
  console.log('realHpData Größe:', window.realHpData ? window.realHpData.size : 'N/A');
  console.log('loadRealHpData verfügbar:', typeof window.loadRealHpData === 'function');
  console.log('setSortGroupForRow verfügbar:', typeof window.setSortGroupForRow === 'function');
  console.log('updateColorScheme verfügbar:', typeof window.updateColorScheme === 'function');

  const tbody = document.querySelector('#resTable tbody');
  console.log('Tabellen-Body:', tbody ? `${tbody.children.length} Zeilen` : 'nicht gefunden');

  // Teste sofortige Sortierung
  console.log('--- TESTE SOFORTIGE SORTIERUNG ---');
  window.forceSortingNow();
};

// Auto-Refresh starten nach Seitenladen
window.addEventListener('load', () => {
  // Sofort Event-Listener installieren
  setupRobustEventListeners();

  setTimeout(() => {
    // Warte bis alle Funktionen geladen sind
    if (typeof window.loadRealHpData === 'function' || typeof window.loadData === 'function') {
      startAutoRefresh();

      // Initiale Sortierung nach 1 Sekunde
      setTimeout(() => {
        console.log('🔄 Initiale Sortierung beim Laden');
        applyImmediateSorting('initial-load');
      }, 1000);

    } else {
      console.warn('⚠️ Auto-Refresh: Warte auf Verfügbarkeit der Ladefunktionen...');
      // Versuche nochmal nach weiteren 2 Sekunden
      setTimeout(() => {
        if (typeof window.loadRealHpData === 'function' || typeof window.loadData === 'function') {
          startAutoRefresh();
          setTimeout(() => applyImmediateSorting('delayed-load'), 500);
        }
      }, 2000);
    }
  }, 3000); // Reduziert von 5000ms auf 3000ms
});

// Robuste Event-Erkennung für alle Plattformen
let pendingSortUpdate = false;
let lastVisibilityChange = 0;
let isTabActive = !document.hidden;

// Funktion für sofortige Sortierung (vereinfacht und robuster)
async function applyImmediateSorting(source = 'unbekannt') {
  console.log(`🔄 Sofortige Sortierung ausgelöst von: ${source}`);

  // Stelle sicher, dass wir Daten haben
  if (!window.realHpData || window.realHpData.size === 0) {
    console.warn('⚠️ Keine Daten verfügbar - lade Daten nach');
    if (typeof window.loadRealHpData === 'function') {
      const success = await window.loadRealHpData();
      if (!success) {
        console.error('❌ Datenload fehlgeschlagen');
        return false;
      }
    } else {
      console.error('❌ loadRealHpData Funktion nicht verfügbar');
      return false;
    }
  }

  console.log(`📊 Verwende ${window.realHpData.size} Daten-Einträge`);

  // 1. Farbschema SOFORT anwenden
  if (typeof window.updateColorScheme === 'function') {
    const mode = localStorage.getItem('filterMode') || 'arrival';
    const isArrival = mode.startsWith('arrival');
    window.updateColorScheme(isArrival);
    console.log('✅ Farbschema sofort angewendet');
  }

  // 2. Sortierung SOFORT anwenden
  const tbody = document.querySelector('#resTable tbody');
  if (!tbody) {
    console.warn('⚠️ Tabellen-Body nicht gefunden');
    return false;
  }

  const rows = tbody.querySelectorAll('tr');
  let appliedCount = 0;

  rows.forEach((row, index) => {
    if (typeof window.setSortGroupForRow === 'function') {
      const resId = parseInt(row.dataset.resId);
      if (window.realHpData.has(resId)) {
        const realData = window.realHpData.get(resId);
        window.setSortGroupForRow(row);
        appliedCount++;

        // Debug für erste 3 Zeilen
        if (index < 3) {
          const nameCell = row.querySelector('.name-cell');
          const classes = nameCell ? Array.from(nameCell.classList).join(', ') : 'keine name-cell';
          console.log(`🔍 Zeile ${index}: ResID ${resId}, Gruppe "${realData.sortGroup}", Klassen: [${classes}]`);
        }
      }
    }
  });

  // 3. FORCED REPAINT (aggressiv für alle Browser)
  tbody.style.visibility = 'hidden';
  tbody.offsetHeight; // Force reflow
  tbody.style.visibility = 'visible';

  // Zusätzlicher Repaint-Trick
  document.body.classList.add('force-repaint');
  requestAnimationFrame(() => {
    document.body.classList.remove('force-repaint');
  });

  console.log(`✅ Sortierung abgeschlossen: ${appliedCount}/${rows.length} Zeilen verarbeitet + Repaint erzwungen`);
  pendingSortUpdate = false;
  return true;
}

// Einfaches aber robustes Event-System
function setupRobustEventListeners() {
  // Track Tab-Status
  document.addEventListener('visibilitychange', () => {
    const wasActive = isTabActive;
    isTabActive = !document.hidden;
    lastVisibilityChange = Date.now();

    if (!wasActive && isTabActive) {
      // Tab wurde gerade aktiviert
      console.log('🔄 Tab aktiviert - führe Sortierung aus');
      setTimeout(() => applyImmediateSorting('visibilitychange'), 100);
    }

    if (isTabActive) {
      autoRefreshEnabled = true;
      if (!autoRefreshInterval) {
        setTimeout(startAutoRefresh, 2000);
      }
    } else {
      autoRefreshEnabled = false;
      pendingSortUpdate = true;
    }
  });

  // Window Focus (für Desktop)
  window.addEventListener('focus', () => {
    console.log('🔄 Window Focus - führe Sortierung aus');
    setTimeout(() => applyImmediateSorting('focus'), 100);
  });

  // Page Show (für Mobile und Back-Navigation)
  window.addEventListener('pageshow', (event) => {
    console.log('🔄 Page Show - führe Sortierung aus');
    setTimeout(() => applyImmediateSorting('pageshow'), 150);
  });

  // Backup: Polling System (alle 2 Sekunden prüfen ob Sortierung nötig)
  setInterval(() => {
    if (isTabActive && pendingSortUpdate && Date.now() - lastVisibilityChange > 1000) {
      console.log('🔄 Backup-Polling aktiviert - führe Sortierung aus');
      applyImmediateSorting('polling-backup');
    }
  }, 2000);

  console.log('✅ Robuste Event-Listener installiert');
}
