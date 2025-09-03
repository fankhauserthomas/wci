// Koordiniertes Auto-Refresh-System (Version 2.0)
// Arbeitet zusammen mit script.js statt dagegen

let autoRefreshInterval = null;
let autoRefreshEnabled = false;
let scriptJsIsLoading = false;
let lastTableRebuild = 0;
let pendingSortUpdate = false;
let isTabActive = !document.hidden;

// Change Detection System
let lastDataHash = null;
let lastDataTimestamp = null;
let lastReservationCount = 0;

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

      // Intelligent Change Detection + HP-Daten laden
      if (typeof window.loadRealHpData === 'function') {
        const changeResult = await detectRelevantChanges();

        if (changeResult.requiresFullRefresh) {
          console.log('🔄 Relevante Änderungen erkannt - Vollständiger Seitenrefresh wird ausgelöst');
          console.log('🔄 Änderungsdetails:', changeResult.changes);

          // Kurze Verzögerung für bessere UX
          setTimeout(() => {
            window.location.reload();
          }, 1000);
          return;
        }

        if (changeResult.success) {
          // Prüfe ob wir wirklich neue Daten haben
          if (window.realHpData && window.realHpData.size > 0) {
            // Nur Formatierung anwenden
            await applyFormattingOnly();
            console.log('✅ Auto-Refresh erfolgreich abgeschlossen');
          } else {
            console.warn('⚠️ Auto-Refresh: Keine HP-Daten verfügbar für Formatierung');
          }
        } else {
          console.warn('⚠️ Auto-Refresh: Change Detection fehlgeschlagen');
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

// Intelligent Change Detection für relevante Datenänderungen
async function detectRelevantChanges() {
  try {
    // Verwende die gleiche Logik wie loadRealHpData für URL-Parameter
    let date;
    const mode = localStorage.getItem('filterMode') || 'arrival';
    if (mode === 'arrival-today') {
      date = new Date().toISOString().slice(0, 10);
    } else if (mode === 'departure-tomorrow') {
      const d = new Date(); d.setDate(d.getDate() + 1);
      date = d.toISOString().slice(0, 10);
    } else {
      const filterDateEl = document.getElementById('filterDate');
      date = filterDateEl ? filterDateEl.value : new Date().toISOString().slice(0, 10);
    }

    const type = (localStorage.getItem('filterMode') || 'arrival').startsWith('arrival') ? 'arrival' : 'departure';
    const url = `get-all-hp-data.php?date=${date}&type=${type}&change_detect=1`;

    const response = await fetch(url);
    const data = await response.json();

    if (!data.success) {
      console.warn('⚠️ Change Detection: Fehler beim Laden der Daten');
      return { success: false, requiresFullRefresh: false };
    }

    // Erstelle Hash der relevanten Daten
    const currentDataHash = generateDataHash(data.data);
    const currentTimestamp = data.timestamp || Date.now();
    const currentReservationCount = data.data.length;

    let requiresFullRefresh = false;
    let changes = [];

    // Erste Initialisierung
    if (lastDataHash === null) {
      lastDataHash = currentDataHash;
      lastDataTimestamp = currentTimestamp;
      lastReservationCount = currentReservationCount;
      console.log('🔍 Change Detection initialisiert');

      // Cache invalidieren und neu laden für erste Ausführung
      if (window.realHpData) {
        window.realHpData.clear();
      }
      if (window.lastHpDataKey) {
        window.lastHpDataKey = null;
      }

      const success = await window.loadRealHpData();
      return { success, requiresFullRefresh: false, changes: ['Initial Load'] };
    }

    // Prüfe auf relevante Änderungen
    if (currentReservationCount !== lastReservationCount) {
      changes.push(`Anzahl Reservierungen: ${lastReservationCount} → ${currentReservationCount}`);
      requiresFullRefresh = true;
    }

    if (currentDataHash !== lastDataHash) {
      changes.push('HP-Arrangements oder Sortiergruppen geändert');

      // Bei Hash-Änderung prüfen ob es nur Formatierungs-relevante Änderungen sind
      const oldData = window.realHpData ? Array.from(window.realHpData.values()) : [];
      const significantChanges = detectSignificantChanges(oldData, data.data);

      if (significantChanges.length > 0) {
        changes.push(...significantChanges);
        requiresFullRefresh = true;
      }
    }

    // Update tracking variables
    lastDataHash = currentDataHash;
    lastDataTimestamp = currentTimestamp;
    lastReservationCount = currentReservationCount;

    if (!requiresFullRefresh) {
      // Auch bei normalen Updates die HP-Daten laden
      if (window.realHpData) {
        window.realHpData.clear();
      }
      if (window.lastHpDataKey) {
        window.lastHpDataKey = null;
      }

      const success = await window.loadRealHpData();
      console.log(`🔄 HP-Daten geladen: ${success ? 'erfolgreich' : 'fehlgeschlagen'}, ${window.realHpData ? window.realHpData.size : 0} Einträge`);
      return { success, requiresFullRefresh: false, changes: changes.length > 0 ? changes : ['Normale Aktualisierung'] };
    }

    return { success: true, requiresFullRefresh, changes };

  } catch (error) {
    console.error('❌ Change Detection Fehler:', error);
    // Bei Fehler normales Update durchführen
    if (window.realHpData) {
      window.realHpData.clear();
    }
    if (window.lastHpDataKey) {
      window.lastHpDataKey = null;
    }

    const success = await window.loadRealHpData();
    return { success, requiresFullRefresh: false, changes: ['Fehler bei Change Detection'] };
  }
}

// Generiere Hash für Datenvergleich
function generateDataHash(data) {
  // Erstelle String mit relevanten Daten für Hash
  const relevantData = data.map(item => ({
    res_id: item.res_id,
    hp_arrangements: item.hp_arrangements,
    checked_in_count: item.checked_in_count,
    total_names: item.total_names,
    sort_group: item.sort_group
  }));

  const dataString = JSON.stringify(relevantData);

  // Simple Hash-Funktion
  let hash = 0;
  for (let i = 0; i < dataString.length; i++) {
    const char = dataString.charCodeAt(i);
    hash = ((hash << 5) - hash) + char;
    hash = hash & hash; // Convert to 32bit integer
  }

  return hash.toString(36);
}

// Erkenne signifikante Änderungen die einen Refresh erfordern
function detectSignificantChanges(oldData, newData) {
  const changes = [];

  // Erstelle Maps für einfacheren Vergleich
  const oldMap = new Map(oldData.map(item => [item.res_id, item]));
  const newMap = new Map(newData.map(item => [item.res_id, item]));

  // Prüfe auf neue Reservierungen
  for (const [resId, newItem] of newMap) {
    if (!oldMap.has(resId)) {
      changes.push(`Neue Reservierung: ${newItem.name || resId}`);
    }
  }

  // Prüfe auf gelöschte Reservierungen
  for (const [resId, oldItem] of oldMap) {
    if (!newMap.has(resId)) {
      changes.push(`Gelöschte Reservierung: ${oldItem.name || resId}`);
    }
  }

  // Prüfe auf signifikante Änderungen in bestehenden Reservierungen
  for (const [resId, newItem] of newMap) {
    const oldItem = oldMap.get(resId);
    if (oldItem) {
      // Prüfe HP-Arrangements Änderungen
      if (oldItem.hpArrangements !== newItem.hp_arrangements) {
        changes.push(`HP-Arrangements geändert für ${newItem.name || resId}: ${oldItem.hpArrangements} → ${newItem.hp_arrangements}`);
      }

      // Prüfe Check-in Count Änderungen (signifikant ab Differenz > 1)
      const checkinDiff = Math.abs((oldItem.checkedInCount || 0) - (newItem.checked_in_count || 0));
      if (checkinDiff > 1) {
        changes.push(`Check-in Count signifikant geändert für ${newItem.name || resId}: ${oldItem.checkedInCount} → ${newItem.checked_in_count}`);
      }

      // Prüfe Sortiergruppen-Änderungen
      if (oldItem.sortGroup !== newItem.sort_group) {
        changes.push(`Sortiergruppe geändert für ${newItem.name || resId}: ${oldItem.sortGroup} → ${newItem.sort_group}`);
      }
    }
  }

  return changes;
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
      console.log('🔄 Tab aktiviert - prüfe auf Änderungen');
      autoRefreshEnabled = true;

      // WICHTIG: Change Detection statt nur Formatierung
      setTimeout(async () => {
        console.log('🔄 Führe Change Detection nach Tab-Aktivierung aus');
        const changeResult = await detectRelevantChanges();

        if (changeResult.requiresFullRefresh) {
          console.log('🔄 Änderungen bei Tab-Aktivierung erkannt - Vollrefresh');
          console.log('🔄 Änderungsdetails:', changeResult.changes);
          setTimeout(() => window.location.reload(), 500);
          return;
        }

        if (changeResult.success && window.realHpData && window.realHpData.size > 0) {
          console.log('🎨 Wende Formatierung nach Tab-Aktivierung an');
          await applyFormattingOnly();
          pendingSortUpdate = false;
        } else {
          console.warn('⚠️ Tab-Aktivierung: Keine Daten für Formatierung verfügbar');
        }
      }, 300);

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
    console.log('🔄 Page Show Event - prüfe auf Änderungen');
    setTimeout(async () => {
      const changeResult = await detectRelevantChanges();

      if (changeResult.requiresFullRefresh) {
        console.log('🔄 Änderungen bei Page Show erkannt - Vollrefresh');
        console.log('🔄 Änderungsdetails:', changeResult.changes);
        setTimeout(() => window.location.reload(), 500);
        return;
      }

      if (changeResult.success && window.realHpData && window.realHpData.size > 0) {
        await applyFormattingOnly();
      }
    }, 200);
  });

  // Window Focus für Desktop
  window.addEventListener('focus', () => {
    console.log('🔄 Window Focus - prüfe auf Änderungen');
    setTimeout(async () => {
      const changeResult = await detectRelevantChanges();

      if (changeResult.requiresFullRefresh) {
        console.log('🔄 Änderungen bei Window Focus erkannt - Vollrefresh');
        console.log('🔄 Änderungsdetails:', changeResult.changes);
        setTimeout(() => window.location.reload(), 500);
        return;
      }

      if (changeResult.success && window.realHpData && window.realHpData.size > 0) {
        await applyFormattingOnly();
      }
    }, 100);
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
window.testChangeDetection = () => detectRelevantChanges();

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

  console.log('=== CHANGE DETECTION STATUS ===');
  console.log('lastDataHash:', lastDataHash);
  console.log('lastReservationCount:', lastReservationCount);
  console.log('lastDataTimestamp:', lastDataTimestamp ? new Date(lastDataTimestamp).toLocaleTimeString() : 'nie');

  const tbody = document.querySelector('#resTable tbody');
  console.log('Tabelle:', tbody ? `${tbody.children.length} Zeilen` : 'nicht gefunden');

  console.log('--- TESTE FORMATIERUNG ---');
  window.testCoordinatedSorting();

  console.log('--- TESTE CHANGE DETECTION ---');
  window.testChangeDetection().then(result => {
    console.log('Change Detection Ergebnis:', result);
  });
};

window.forcePageRefresh = function () {
  console.log('🔄 Erzwinge Seitenrefresh...');
  window.location.reload();
};

window.resetChangeDetection = function () {
  lastDataHash = null;
  lastDataTimestamp = null;
  lastReservationCount = 0;
  console.log('🔄 Change Detection zurückgesetzt');
};

console.log('✅ Koordiniertes Auto-Refresh-System mit Change Detection geladen');
