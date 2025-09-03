/**
 * script.js
 */

/**
 * Initialisiert den 4-Stufen Toggle für An-/Abreise.
 *
 * @param {HTMLElement} toggleBtn   Der Button für den Datumstyp.
 * @param {HTMLInputElement} dateEl Das <input type="date"> Element.
 * @param {Function} loadDataFn     Funktion, die bei jeder Änderung neu lädt.
 */
function initDateToggle(toggleBtn, dateEl, loadDataFn) {
  const modes = ['arrival', 'departure', 'arrival-today', 'departure-tomorrow'];
  let mode = localStorage.getItem('filterMode') || 'arrival';
  let customDate = localStorage.getItem('filterDate') || '';

  const today = () => new Date().toISOString().slice(0, 10);
  const tomorrow = () => {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
  };

  function updateUI() {
    const labels = {
      arrival: 'Anreise',
      departure: 'Abreise',
      'arrival-today': 'Anreise heute',
      'departure-tomorrow': 'Abreise morgen'
    };
    toggleBtn.textContent = labels[mode];

    if (mode === 'arrival' || mode === 'departure') {
      dateEl.style.display = '';
      dateEl.value = customDate || (mode === 'arrival' ? today() : tomorrow());
    } else {
      dateEl.style.display = 'none';
    }
  }

  function persist() {
    localStorage.setItem('filterMode', mode);
    if (mode === 'arrival' || mode === 'departure') {
      localStorage.setItem('filterDate', customDate);
    }
  }

  toggleBtn.addEventListener('click', () => {
    const idx = modes.indexOf(mode);
    mode = modes[(idx + 1) % modes.length];
    persist();
    updateUI();
    loadDataFn();
  });

  dateEl.addEventListener('change', () => {
    customDate = dateEl.value;
    persist();
  });

  updateUI();
}

document.addEventListener('DOMContentLoaded', () => {
  // DOM-Elemente
  const toggleType = document.getElementById('toggleType');
  const toggleStorno = document.getElementById('toggleStorno');
  const filterDate = document.getElementById('filterDate');
  const searchInput = document.getElementById('searchInput');
  const clearSearchBtn = document.getElementById('clearSearchBtn');
  const tbody = document.querySelector('#resTable tbody');

  // Clear Search Button Event
  if (clearSearchBtn) {
    clearSearchBtn.addEventListener('click', () => {
      searchInput.value = '';
      searchInput.focus();
      // Trigger input event to update the table
      const inputEvent = new Event('input', { bubbles: true });
      searchInput.dispatchEvent(inputEvent);
      // Suchfeld geleert
    });
  }

  // Debug: Prüfe ob Elemente gefunden wurden
  // Debug - Toggle Elements

  // Flag um doppelte Listener zu vermeiden
  let toggleListenersSetup = false;

  // Setup Toggle Listeners nur einmal
  if (toggleStorno && !toggleListenersSetup) {
    // Setting up toggle listeners immediately
    setupToggleListeners(toggleStorno);
    toggleListenersSetup = true;
  }

  // Fallback: Suche Elemente später nochmal falls nicht gefunden
  if (!toggleStorno) {
    // Toggle elements not found initially, retrying...
    setTimeout(() => {
      if (!toggleListenersSetup) {
        const retryStorno = document.getElementById('toggleStorno');
        if (retryStorno) {
          // Found toggle elements on retry
          setupToggleListeners(retryStorno);
          toggleListenersSetup = true;
        }
      }
    }, 1000);
  }

  const modal = document.getElementById('modal');
  const modalText = document.getElementById('modalText');
  const modalClose = document.getElementById('modalClose');

  const qrModal = document.getElementById('qrModal');
  const qrContainer = document.getElementById('qrContainer');
  const qrClose = document.getElementById('qrClose');

  let rawData = [];

  // Neue Reservierung: Button & Modal
  const newReservationBtn = document.getElementById('newReservationBtn');
  const newReservationModal = document.getElementById('newReservationModal');
  const newResModalClose = document.getElementById('newResModalClose');
  const newResCancelBtn = document.getElementById('newResCancelBtn');
  const newReservationForm = document.getElementById('newReservationForm');

  // Öffnen des Modals
  if (newReservationBtn) {
    newReservationBtn.addEventListener('click', () => {
      // Herkunft und Arrangement Dropdowns laden
      fetch('getOrigins.php')
        .then(r => r.json())
        .then(origins => {
          const herkunftSel = document.getElementById('newResHerkunft');
          herkunftSel.innerHTML = '<option value="">Bitte wählen...</option>' +
            origins.map(o => `<option value="${o.id}">${o.bez}</option>`).join('');
        })
        .catch(err => console.error('Fehler beim Laden der Herkunft:', err));

      fetch('getArrangements.php')
        .then(r => r.json())
        .then(arrs => {
          const arrSel = document.getElementById('newResArrangement');
          arrSel.innerHTML = '<option value="">Bitte wählen...</option>' +
            arrs.map(a => `<option value="${a.id}">${a.kbez}</option>`).join('');
        })
        .catch(err => console.error('Fehler beim Laden der Arrangements:', err));

      // Default-Daten setzen
      const today = new Date().toISOString().slice(0, 10);
      const tomorrow = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
      document.getElementById('newResAnreise').value = today;
      document.getElementById('newResAbreise').value = tomorrow;
      newReservationModal.style.display = 'block';
    });
  }

  // Schließen des Modals
  if (newResModalClose) {
    newResModalClose.addEventListener('click', () => {
      newReservationModal.style.display = 'none';
    });
  }

  if (newResCancelBtn) {
    newResCancelBtn.addEventListener('click', () => {
      newReservationModal.style.display = 'none';
    });
  }

  // ENTFERNT: Modal schließen durch Hintergrund-Click
  // Das Modal soll nur durch X oder Abbrechen-Button geschlossen werden
  /*
  window.addEventListener('click', e => {
    if (e.target === newReservationModal) newReservationModal.style.display = 'none';
  });
  */

  // Formular absenden
  if (newReservationForm) {
    let isSubmitting = false; // Prevent double submissions

    newReservationForm.addEventListener('submit', function (e) {
      e.preventDefault();

      // Prevent double submission
      if (isSubmitting) {
        // Form submission already in progress
        return;
      }

      // Felder auslesen
      const nachname = document.getElementById('newResNachname').value.trim();
      if (!nachname) {
        alert('Nachname ist ein Pflichtfeld!');
        return;
      }

      const vorname = document.getElementById('newResVorname').value.trim();
      const herkunft = document.getElementById('newResHerkunft').value;
      const anreise = document.getElementById('newResAnreise').value;
      const abreise = document.getElementById('newResAbreise').value;
      const arrangement = document.getElementById('newResArrangement').value;
      const dz = parseInt(document.getElementById('newResDZ').value) || 0;
      const betten = parseInt(document.getElementById('newResBetten').value) || 0;
      const lager = parseInt(document.getElementById('newResLager').value) || 0;
      const sonder = parseInt(document.getElementById('newResSonder').value) || 0;
      const bemerkung = document.getElementById('newResBemerkung').value.trim();

      isSubmitting = true;
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn ? submitBtn.textContent : '';
      if (submitBtn) {
        submitBtn.textContent = 'Speichere...';
        submitBtn.disabled = true;
      }

      // AJAX-Request an addReservation.php
      fetch('addReservation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          nachname, vorname, herkunft, anreise, abreise, arrangement,
          dz, betten, lager, sonder, bemerkung
        })
      })
        .then(r => r.json())
        .then(result => {
          if (result.success) {
            alert('Reservierung erfolgreich angelegt!');
            newReservationModal.style.display = 'none';
            // Formular zurücksetzen
            newReservationForm.reset();
            // Tabelle neu laden
            loadData();
          } else {
            alert('Fehler: ' + (result.error || 'Unbekannter Fehler'));
          }
        })
        .catch(err => {
          console.error('Netzwerkfehler:', err);
          alert('Netzwerkfehler: ' + err.message);
        })
        .finally(() => {
          isSubmitting = false;
          if (submitBtn) {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
          }
        });
    });
  }

  // 4-Stufen Toggle initialisieren
  initDateToggle(toggleType, filterDate, loadData);

  // Soundex für phonetische Suche
  function soundex(s) {
    const a = s.toUpperCase().split(''), first = a.shift();
    const map = {
      B: 1, F: 1, P: 1, V: 1, C: 2, G: 2, J: 2, K: 2, Q: 2, S: 2, X: 2, Z: 2,
      D: 3, T: 3, L: 4, M: 5, N: 5, R: 6
    };
    const digits = a.map(c => map[c] || '0');
    const filtered = digits.filter((d, i) => d !== digits[i - 1] && d !== '0');
    return (first + filtered.join('') + '000').slice(0, 4);
  }

  // Levenshtein-Distanz für Tippfehler-Toleranz
  function levenshteinDistance(a, b) {
    const matrix = [];
    for (let i = 0; i <= b.length; i++) {
      matrix[i] = [i];
    }
    for (let j = 0; j <= a.length; j++) {
      matrix[0][j] = j;
    }
    for (let i = 1; i <= b.length; i++) {
      for (let j = 1; j <= a.length; j++) {
        if (b.charAt(i - 1) === a.charAt(j - 1)) {
          matrix[i][j] = matrix[i - 1][j - 1];
        } else {
          matrix[i][j] = Math.min(
            matrix[i - 1][j - 1] + 1, // substitution
            matrix[i][j - 1] + 1,     // insertion
            matrix[i - 1][j] + 1      // deletion
          );
        }
      }
    }
    return matrix[b.length][a.length];
  }

  // Erweiterte Fuzzy-Suche
  function fuzzyMatch(searchTerm, targetString) {
    const search = searchTerm.toLowerCase();
    const target = targetString.toLowerCase();

    // 1. Exakte Substring-Übereinstimmung (höchste Priorität)
    if (target.includes(search)) return true;

    // 2. Soundex-Vergleich für phonetische Ähnlichkeit
    if (soundex(target).startsWith(soundex(search))) return true;

    // 3. Levenshtein-Distanz für Tippfehler (toleriere 1-2 Fehler je nach Länge)
    const maxDistance = search.length <= 3 ? 1 : Math.floor(search.length * 0.3);
    if (levenshteinDistance(search, target) <= maxDistance) return true;

    // 4. Wort-für-Wort Vergleich bei mehreren Wörtern
    const searchWords = search.split(/\s+/);
    const targetWords = target.split(/\s+/);

    for (const searchWord of searchWords) {
      for (const targetWord of targetWords) {
        if (targetWord.includes(searchWord) ||
          soundex(targetWord).startsWith(soundex(searchWord)) ||
          levenshteinDistance(searchWord, targetWord) <= Math.floor(searchWord.length * 0.3)) {
          return true;
        }
      }
    }

    return false;
  }

  // Pie-Chart SVG Builder
  const colors = ['#e74c3c', '#e67e22', '#f1c40f', '#2ecc71', '#27ae60'];
  function createPie(percent, id) {
    const pct = Math.min(percent, 100), r = 10, cx = 12, cy = 12;
    const idx = Math.min(Math.floor(pct / 20), colors.length - 1),
      color = colors[idx];
    if (pct === 100) {
      return `<svg class="pie-chart" data-id="${id}" viewBox="0 0 24 24">
        <circle cx="${cx}" cy="${cy}" r="${r}" fill="#ddd"/>
        <circle cx="${cx}" cy="${cy}" r="${r * 0.9}" fill="${color}"/>
        <text x="${cx}" y="${cy}" text-anchor="middle" dy=".35em" font-size="8" fill="#333">${Math.round(pct)}%</text>
      </svg>`;
    }
    const angle = pct / 100 * 360,
      largeArc = angle > 180 ? 1 : 0,
      rad = a => (a - 90) * Math.PI / 180,
      x2 = cx + r * Math.cos(rad(angle)),
      y2 = cy + r * Math.sin(rad(angle));
    return `<svg class="pie-chart" data-id="${id}" viewBox="0 0 24 24">
      <circle cx="${cx}" cy="${cy}" r="${r}" fill="#ddd"/>
      <path d="M${cx},${cy} L${cx},${cy - r} A${r},${r} 0 ${largeArc},1 ${x2},${y2} Z" fill="${color}"/>
      <text x="${cx}" y="${cy}" text-anchor="middle" dy=".35em" font-size="8" fill="#333">${Math.round(pct)}%</text>
    </svg>`;
  }

  // Filter-Persistenz: Storno, Offen, Suche
  function loadFiltersFromStorage() {
    const savedSt = localStorage.getItem('filterStorno');
    const savedTerm = localStorage.getItem('searchTerm');

    if (savedSt === 'storno') {
      toggleStorno.classList.replace('no-storno', 'storno');
      toggleStorno.textContent = 'Storno';
    }
    if (savedTerm) {
      searchInput.value = savedTerm;
    }
  }
  function saveFiltersToStorage() {
    localStorage.setItem('filterStorno', toggleStorno.classList.contains('storno') ? 'storno' : 'no-storno');
    localStorage.setItem('searchTerm', searchInput.value.trim());
  }

  // Ermittelt arrival/departure aus Datumstoggle
  function getType() {
    const mode = localStorage.getItem('filterMode') || 'arrival';
    return mode.startsWith('arrival') ? 'arrival' : 'departure';
  }

  // Daten laden
  function loadData() {
    saveFiltersToStorage();

    let date;
    const mode = localStorage.getItem('filterMode') || 'arrival';
    if (mode === 'arrival-today') {
      date = new Date().toISOString().slice(0, 10);
    } else if (mode === 'departure-tomorrow') {
      const d = new Date(); d.setDate(d.getDate() + 1);
      date = d.toISOString().slice(0, 10);
    } else {
      date = filterDate.value;
      localStorage.setItem('filterDate', date);
    }

    if (!date) {
      alert('Bitte ein Datum wählen.');
      return;
    }

    const params = new URLSearchParams({ date, type: getType() });

    // Paralleles Laden von Hauptdaten und HP-Daten für bessere Performance
    const dataPromise = window.HttpUtils
      ? HttpUtils.requestJsonWithLoading(`data.php?${params}`, {}, { retries: 3, timeout: 12000 }, 'Reservierungsliste wird geladen...')
      : window.LoadingOverlay
        ? LoadingOverlay.wrapFetch(() => fetch(`data.php?${params}`).then(res => res.json()), 'Reservierungsliste')
        : fetch(`data.php?${params}`).then(res => res.json());

    // HP-Daten parallel laden
    window.hpDataLoading = true; // Flag für andere Scripts
    const hpDataPromise = fetch(`get-all-hp-data.php?${params}`)
      .then(res => res.json())
      .catch(error => {
        console.warn('HP-Daten konnten nicht geladen werden:', error);
        return { success: false, data: [] };
      })
      .finally(() => {
        window.hpDataLoading = false; // Flag zurücksetzen
      });

    // Beide Promises parallel ausführen
    Promise.all([dataPromise, hpDataPromise])
      .then(([mainData, hpData]) => {
        if (mainData.error) {
          alert(mainData.error);
          return;
        }

        // Hauptdaten verarbeiten
        rawData = mainData.map(r => ({ ...r, storno: Boolean(r.storno) }));

        // HP-Daten global speichern für sofortige Verwendung
        if (hpData.success && window.realHpData) {
          window.realHpData.clear();
          hpData.data.forEach(item => {
            window.realHpData.set(item.res_id, {
              hpArrangements: item.hp_arrangements,
              checkedInCount: item.checked_in_count,
              totalNames: item.total_names,
              name: item.name,
              sortGroup: item.sort_group,
              sortDescription: item.sort_description
            });
          });
          // Setze Cache-Key für Validierung in anderen Funktionen
          window.lastHpDataKey = `${date}_${getType()}`;
          // HP-Daten parallel geladen
        }

        renderTable();

        // Sortiergruppen DIREKT hier anwenden, da alle Daten verfügbar sind
        setTimeout(() => {
          applySortGroupsDirectly(hpData.success ? hpData.data : []);
        }, 100);

        // Koordination: Signalisiere Auto-Refresh dass neue Tabelle fertig ist
        window.dispatchEvent(new CustomEvent('tableRebuilt'));
      })
      .catch((error) => {
        console.error('Fehler beim Laden der Daten:', error);
        alert('Fehler beim Laden der Daten. Bitte Verbindung prüfen und erneut versuchen.');
      });
  }

  // Tabelle rendern
  function renderTable() {
    // Koordination: Signalisiere Auto-Refresh dass Tabelle neu erstellt wird
    window.dispatchEvent(new CustomEvent('tableRebuilding'));

    let view = rawData.slice();

    // Storno-Filter
    const stornoElement = toggleStorno || document.getElementById('toggleStorno');
    if (stornoElement && stornoElement.classList.contains('storno')) {
      view = view.filter(r => r.storno);
    } else if (stornoElement) {
      view = view.filter(r => !r.storno);
    }

    // Erweiterte Fuzzy-Suche
    const term = searchInput.value.trim();
    if (term) {
      view = view.filter(r => {
        const fullName = `${r.nachname} ${r.vorname}`;
        const reverseName = `${r.vorname} ${r.nachname}`;

        // Suche in vollständigem Namen (beide Reihenfolgen) und einzelnen Namen
        return fuzzyMatch(term, fullName) ||
          fuzzyMatch(term, reverseName) ||
          fuzzyMatch(term, r.nachname) ||
          fuzzyMatch(term, r.vorname);
      });
    }

    tbody.innerHTML = '';
    if (!view.length) {
      tbody.innerHTML = '<tr><td colspan="9">Keine Einträge gefunden.</td></tr>';
      return;
    }

    // Sortierung: Primär nach Sortiergruppen (A → B → C → D), sekundär nach Namen
    view.sort((a, b) => {
      // Hole Sortiergruppen-Daten aus window.realHpData
      const dataA = window.realHpData ? window.realHpData.get(a.id) : null;
      const dataB = window.realHpData ? window.realHpData.get(b.id) : null;

      const sortGroupA = dataA?.sortGroup || 'Z'; // Z als Fallback für Einträge ohne Sortiergruppe
      const sortGroupB = dataB?.sortGroup || 'Z';

      // Primäre Sortierung: Sortiergruppen A → B → C → D → Z
      if (sortGroupA !== sortGroupB) {
        return sortGroupA.localeCompare(sortGroupB);
      }

      // Sekundäre Sortierung: Nach Namen (Nachname, dann Vorname)
      const nameA = `${a.nachname} ${a.vorname}`.toLowerCase();
      const nameB = `${b.nachname} ${b.vorname}`.toLowerCase();

      return nameA.localeCompare(nameB);
    });

    // Debug: Zeige Sortierung der ersten Einträge (nur bei Bedarf)
    if (window.debugSortingEnabled && view.length > 0) {
      const sortInfo = view.slice(0, 5).map(r => {
        const data = window.realHpData ? window.realHpData.get(r.id) : null;
        return `${data?.sortGroup || 'Z'}: ${r.nachname} ${r.vorname}`;
      }).join(', ');
      // Erste 5 Einträge nach Sortierung
    }

    view.forEach(r => {
      const statusPct = getType() === 'arrival' ? r.percent_logged_in : r.percent_logged_out;
      const nameText = `${r.nachname} ${r.vorname}` + (r.hund ? ' <img src="pic/dog.svg" alt1="Hund" style="width: 1.15em; height: 1.15em; vertical-align: middle;">' : '') + (r.av_id > 0 ? ' <img src="pic/AV.svg" alt="AV" style="width: 1.15em; height: 1.15em; vertical-align: middle;">' : '') + (r.invoice ? ' <img src="pic/invoice.svg" alt="Debitor" style="width: 1.15em; height: 1.15em; vertical-align: middle;">' : '') + (r.storno ? ' <img src="pic/cancelled.svg" alt="Storniert" style="width: 4.6em; height: 1.15em; vertical-align: middle;">' : '');

      // Prüfen ob Nachname fehlt oder leer ist
      const missingLastname = !r.nachname || r.nachname.trim() === '';
      const nameClass = missingLastname ? 'name-cell name-missing-lastname' : 'name-cell';
      const nameCell = `<td class="${nameClass}" data-id="${r.id}">${nameText}</td>`;

      const bemHtml = r.bem && r.bem_av
        ? `${r.bem}<hr>${r.bem_av}`
        : r.bem || r.bem_av || '';

      // Funktion zum Prüfen ob Text mehr als 2 Zeilen benötigt
      const needsModal = (text, maxChars = 40) => {
        if (!text) return false;
        return text.length > maxChars || text.split('\n').length > 2;
      };

      const bemCell = bemHtml
        ? `<td class="bem-cell ${needsModal(bemHtml) ? 'has-overflow' : ''}" data-id="${r.id}" title="${bemHtml}"><span class="bem-text">${bemHtml}</span></td>`
        : '<td class="bem-cell" data-id="${r.id}"></td>';

      const origCell = r.origin
        ? `<td class="orig-cell ${needsModal(r.origin, 40) ? 'has-overflow' : ''}" data-id="${r.id}" title="${r.origin}"><span class="orig-text">${r.origin}</span></td>`
        : '<td class="orig-cell" data-id="${r.id}"></td>';

      // Calculate length of stay and determine background color
      // Convert German date format (dd.mm.yyyy) to ISO format (yyyy-mm-dd)
      const convertGermanDate = (dateStr) => {
        const parts = dateStr.split('.');
        if (parts.length === 3) {
          return `${parts[2]}-${parts[1]}-${parts[0]}`; // yyyy-mm-dd
        }
        return dateStr;
      };

      const arrivalDate = new Date(convertGermanDate(r.anreise));
      const departureDate = new Date(convertGermanDate(r.abreise));
      const lengthOfStay = Math.round((departureDate - arrivalDate) / (1000 * 60 * 60 * 24));

      // Debug: log the calculation for testing
      // Guest debug info

      let backgroundColor = '';
      if (lengthOfStay === 1) {
        backgroundColor = '#e0e0e0'; // darker gray - 1 night
      } else if (lengthOfStay === 2 || lengthOfStay === 3) {
        backgroundColor = '#cce7ff'; // darker light blue - 2-3 nights
      } else if (lengthOfStay >= 4 && lengthOfStay <= 6) {
        backgroundColor = '#ccffcc'; // darker light green - 4-6 nights
      } else if (lengthOfStay > 6) {
        backgroundColor = '#ffcc99'; // darker orange - over 6 nights
      }

      const tr = document.createElement('tr');
      tr.dataset.storno = r.storno;
      tr.dataset.avId = r.av_id;
      tr.dataset.resId = r.id;  // Füge Reservierungs-ID hinzu für HP-Checks
      if (backgroundColor) {
        tr.style.backgroundColor = backgroundColor;
      }
      tr.innerHTML = `
        <td>${createPie(statusPct, r.id)}</td>
        <td>${r.anreise.substring(0, 5)}</td>
        <td>${r.abreise.substring(0, 5)}</td>
        <td>${r.anzahl}</td>
        ${nameCell}
        <td>${r.arr_kurz || ''}</td>
        <td class="qr-cell" data-id="${r.id}">${createPie(r.percent_chkin, r.id)}</td>
        ${bemCell}
        ${origCell}
      `;
      tbody.appendChild(tr);
    });

    // Name-Zellen klickbar
    document.querySelectorAll('.name-cell').forEach(cell => {
      cell.addEventListener('click', () => {
        window.location.href = `reservation.html?id=${cell.dataset.id}`;
      });
    });

    // Bemerkung-Zellen -> Modal mit vollem Text anzeigen
    document.querySelectorAll('.bem-cell').forEach(cell => {
      cell.addEventListener('click', () => {
        const title = cell.getAttribute('title');
        if (title && title.trim()) {
          const modalText = document.getElementById('modalText');
          const modal = document.getElementById('modal');
          modalText.innerHTML = `<h3>Bemerkung</h3><div style="margin-top: 1rem; white-space: pre-wrap; word-wrap: break-word;">${title.replace('<hr>', '\n\n')}</div>`;
          modal.classList.add('visible');
        }
      });
    });

    // Origin-Zellen -> Modal mit vollem Text anzeigen
    document.querySelectorAll('.orig-cell').forEach(cell => {
      cell.addEventListener('click', () => {
        const title = cell.getAttribute('title');
        if (title && title.trim()) {
          const modalText = document.getElementById('modalText');
          const modal = document.getElementById('modal');
          modalText.innerHTML = `<h3>Herkunft</h3><div style="margin-top: 1rem; white-space: pre-wrap; word-wrap: break-word;">${title}</div>`;
          modal.classList.add('visible');
        }
      });
    });

    // QR-Code für 7. Spalte (ANam) - Check-in Status
    document.querySelectorAll('.qr-cell').forEach(cell => {
      cell.style.cursor = 'pointer';
      cell.addEventListener('click', async () => {
        const reservationId = cell.dataset.id;

        qrContainer.innerHTML = `
          <div class="qr-hint">
            Diesen QR Code scannen…<br>Scan this QR code…
          </div>
          <div id="qrCode"></div>
        `;

        try {
          // Stelle sicher, dass QRCode.js geladen ist
          if (typeof QRCode === 'undefined') {
            if (typeof loadQRCodeScript === 'function') {
              await loadQRCodeScript();
            } else {
              throw new Error('QRCode library loader not found');
            }
          }

          const qrPromise = window.HttpUtils
            ? HttpUtils.requestJsonWithLoading(`getBookingUrl.php?id=${reservationId}`, {}, {}, 'QR-Code wird generiert...')
            : window.LoadingOverlay
              ? LoadingOverlay.wrapFetch(() => fetch(`getBookingUrl.php?id=${reservationId}`).then(res => res.json()), 'QR-Code')
              : fetch(`getBookingUrl.php?id=${reservationId}`).then(res => res.json());

          const json = await qrPromise;

          if (json.url) {
            new QRCode(document.getElementById('qrCode'), {
              text: json.url, width: 128, height: 128
            });

            // Store reservation data for email button
            const row = cell.closest('tr');

            // Fetch complete reservation data for email
            fetch(`getReservationDetails.php?id=${reservationId}`)
              .then(response => response.json())
              .then(reservationData => {
                const detail = reservationData.detail || reservationData;
                const currentReservationForEmail = {
                  id: reservationId,
                  nachname: detail.nachname || '',
                  vorname: detail.vorname || '',
                  email: detail.email || '',
                  anreise: detail.anreise || '',
                  abreise: detail.abreise || ''
                };

                // Set up email button click handler
                const emailGuestBtn = document.getElementById('emailGuestBtn');
                if (emailGuestBtn) {
                  // Remove existing listeners
                  emailGuestBtn.replaceWith(emailGuestBtn.cloneNode(true));
                  const newEmailBtn = document.getElementById('emailGuestBtn');

                  newEmailBtn.addEventListener('click', () => {
                    if (window.EmailUtils) {
                      window.EmailUtils.sendNameListEmail(currentReservationForEmail);
                      qrModal.classList.remove('visible');
                    } else {
                      alert('Email-Funktionalität nicht verfügbar.');
                    }
                  });
                }
              })
              .catch(error => {
                console.error('Error fetching reservation details for email:', error);
                // Fallback with basic data from table
                const currentReservationForEmail = {
                  id: reservationId,
                  nachname: row.querySelector('.name-cell')?.textContent.split(' ')[0] || '',
                  vorname: row.querySelector('.name-cell')?.textContent.split(' ').slice(1).join(' ') || '',
                  email: '',
                  anreise: row.querySelector('.dates-cell')?.dataset.anreise || '',
                  abreise: row.querySelector('.dates-cell')?.dataset.abreise || ''
                };

                const emailGuestBtn = document.getElementById('emailGuestBtn');
                if (emailGuestBtn) {
                  emailGuestBtn.replaceWith(emailGuestBtn.cloneNode(true));
                  const newEmailBtn = document.getElementById('emailGuestBtn');

                  newEmailBtn.addEventListener('click', () => {
                    if (window.EmailUtils) {
                      window.EmailUtils.sendNameListEmail(currentReservationForEmail);
                      qrModal.classList.remove('visible');
                    } else {
                      alert('Email-Funktionalität nicht verfügbar.');
                    }
                  });
                }
              });

            qrModal.classList.add('visible');
          } else {
            alert('Fehler beim Abrufen der Buchungs-URL');
          }
        } catch (error) {
          console.error('QR-Code Fehler:', error);
          alert('Netzwerkfehler beim QR-Code Laden. Bitte erneut versuchen.');
        }
      });
    });

    // QR-Codes für Pie-Charts - ENTFERNT: Kein Klick mehr auf Status-Diagramme
    // document.querySelectorAll('.pie-chart').forEach(svg => {
    //   svg.style.cursor = 'pointer';
    //   svg.addEventListener('click', () => {
    //     ...
    //   });
    // });
  }

  // Separate Funktion für Toggle-Listener Setup
  function setupToggleListeners(stornoEl) {
    if (!stornoEl) {
      // setupToggleListeners: Elements not found
      return;
    }

    // Prüfe ob bereits Event-Listener vorhanden sind
    if (stornoEl.hasAttribute('data-listeners-setup')) {
      // Toggle listeners already setup, skipping
      return;
    }

    // Setting up toggle listeners

    // Event-Listener für Storno
    stornoEl.addEventListener('click', (e) => {
      console.log('Storno button clicked, current classes:', stornoEl.className);

      if (stornoEl.classList.contains('no-storno')) {
        stornoEl.classList.replace('no-storno', 'storno');
        stornoEl.textContent = 'Storno';
        console.log('Changed to storno mode');
      } else {
        stornoEl.classList.replace('storno', 'no-storno');
        stornoEl.textContent = 'Ohne Storno';
        console.log('Changed to no-storno mode');
      }

      console.log('New classes after toggle:', stornoEl.className);
      saveFiltersToStorage();
      renderTable(); // NUR renderTable(), nicht loadData()!
    });

    // Markiere Element als setup
    stornoEl.setAttribute('data-listeners-setup', 'true');
  }

  // ENTFERNT: Event-Listener Setup erfolgt bereits oben mit Flag-Protection

  filterDate.addEventListener('change', loadData);
  searchInput.addEventListener('input', () => {
    saveFiltersToStorage();
    renderTable();
  });

  // Modals schließen
  modalClose.addEventListener('click', () => modal.classList.remove('visible'));
  qrClose.addEventListener('click', () => qrModal.classList.remove('visible'));
  window.addEventListener('click', e => {
    if (e.target === modal) modal.classList.remove('visible');
    if (e.target === qrModal) qrModal.classList.remove('visible');
  });

  // Back/Forward-Cache
  window.addEventListener('pageshow', e => {
    if (e.persisted) {
      // 1. Filter (Storno, Open, Datum) wiederherstellen
      loadFiltersFromStorage();

      // 2. Suchfeld leer machen
      searchInput.value = '';
      localStorage.removeItem('searchTerm');

      // 3. Daten neu laden
      loadData();
    }
  });

  // === Navigation Status Update ===
  function updateNavigationStatus() {
    const monitor = window.connectionMonitor;
    if (!monitor) return;

    const navStatus = document.getElementById('nav-connection-status');
    if (!navStatus) return;

    const dot = navStatus.querySelector('.status-dot');
    const text = navStatus.querySelector('.status-text');

    const quality = monitor.getQuality();
    const isOnline = monitor.isOnline();

    if (!isOnline) {
      dot.style.backgroundColor = '#dc3545';
      text.textContent = 'Offline';
      navStatus.title = 'Verbindung: Offline - Klicken für Details';
    } else {
      switch (quality) {
        case 'excellent':
        case 'good':
          dot.style.backgroundColor = '#28a745';
          text.textContent = 'Online';
          navStatus.title = `Verbindung: ${quality === 'excellent' ? 'Ausgezeichnet' : 'Gut'} - Klicken für Details`;
          break;
        case 'fair':
          dot.style.backgroundColor = '#ffc107';
          text.textContent = 'Langsam';
          navStatus.title = 'Verbindung: Mäßig - Klicken für Details';
          break;
        case 'poor':
          dot.style.backgroundColor = '#fd7e14';
          text.textContent = 'Sehr langsam';
          navStatus.title = 'Verbindung: Schlecht - Klicken für Details';
          break;
        default:
          dot.style.backgroundColor = '#6c757d';
          text.textContent = 'Unbekannt';
          navStatus.title = 'Verbindung: Unbekannt - Klicken für Details';
      }
    }
  }

  // Globale Funktion verfügbar machen
  window.updateNavigationStatus = updateNavigationStatus;

  // Navigation Status Click Handler
  const navStatus = document.getElementById('nav-connection-status');
  if (navStatus) {
    navStatus.addEventListener('click', () => {
      if (window.connectionMonitor && window.HttpUtils) {
        HttpUtils.showDetailedConnectionStatus(window.connectionMonitor);
      }
    });
  }

  // Update Navigation Status alle 5 Sekunden
  setInterval(updateNavigationStatus, 5000);

  // Initial Status Update nach kurzer Verzögerung
  setTimeout(updateNavigationStatus, 2000);

  // Initial Load
  loadFiltersFromStorage();

  // URL-Parameter auswerten (z.B. für Barcode-Navigation)
  const urlParams = new URLSearchParams(window.location.search);
  const urlSearchTerm = urlParams.get('search');

  if (urlSearchTerm) {
    console.log('🔍 Suchterm aus URL erkannt:', urlSearchTerm);
    searchInput.value = urlSearchTerm;
    // Entferne URL-Parameter nach dem Lesen
    window.history.replaceState({}, document.title, window.location.pathname);
  } else {
    // Suchfeld sofort leeren (auch bei F5) nur wenn kein URL-Parameter
    searchInput.value = '';
    localStorage.removeItem('searchTerm');
  }

  loadData();

  // Seite neu laden wenn von anderen Seiten zurückgekehrt wird
  window.addEventListener('focus', function () {
    console.log('🔄 Reservierungen-Seite erhält Fokus - lade Daten neu');
    loadData();
  });

  // Pagehide/Pageshow für bessere Mobile-Unterstützung
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      console.log('🔄 Seite aus Cache geladen - lade Daten neu');
      loadData();
    }
  });

  // Visibility API für Tab-Wechsel
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      console.log('🔄 Tab wieder sichtbar - lade Daten neu');
      loadData();
    }
  });

  // === Sortiergruppen-Funktionalität ===
  // Direkte Anwendung der Sortiergruppen basierend auf API-Daten
  function applySortGroupsDirectly(hpDataArray) {
    if (!hpDataArray || hpDataArray.length === 0) {
      return;
    }

    // Erstelle Map für schnellen Zugriff
    const dataMap = new Map();
    hpDataArray.forEach(item => {
      dataMap.set(item.res_id, item);
    });

    // Finde alle Tabellenzeilen
    const rows = document.querySelectorAll('#resTable tbody tr');

    let appliedCount = 0;
    rows.forEach(row => {
      const resId = parseInt(row.dataset.resId);
      const nameCell = row.querySelector('.name-cell');

      if (!nameCell || !resId) {
        return;
      }

      const data = dataMap.get(resId);
      if (data && data.sort_group) {
        // Entferne alte Klassen
        nameCell.classList.remove('sort-group-a', 'sort-group-b', 'sort-group-c', 'sort-group-d');

        // Füge neue Klasse hinzu
        const sortGroup = data.sort_group.toLowerCase();
        const className = `sort-group-${sortGroup}`;
        nameCell.classList.add(className);

        // Setze Tooltip
        nameCell.title = data.sort_description || `Sortiergruppe ${data.sort_group}`;

        appliedCount++;
      }
    });

    if (window.debugSortingEnabled) {
      console.log(`🎨 Sortiergruppen-Einfärbung: ${appliedCount} von ${rows.length} Zeilen eingefärbt`);
    }
  }  // === Universelle Verbindungsstatus-Funktionen ===
  // Stelle sicher, dass updateNavigationStatus global verfügbar ist, auch wenn keine Navigation vorhanden
  if (!window.updateNavigationStatus) {
    window.updateNavigationStatus = function () {
      // Fallback für Seiten ohne Navigation-Status
      console.log('[CONNECTION] Navigation status not available on this page');
    };
  }

  // Stelle globale Connection-Update-Funktion zur Verfügung
  window.updateConnectionStatus = function () {
    if (window.connectionMonitor && window.HttpUtils) {
      window.connectionMonitor.testConnection().then(() => {
        HttpUtils.updatePermanentIndicator(window.connectionMonitor);
        if (window.updateNavigationStatus) {
          window.updateNavigationStatus();
        }
      }).catch(() => {
        // Silent fail
      });
    }
  };

  // === Koordination mit Auto-Refresh-System ===
  window.addEventListener('autoRefreshStarted', () => {
    console.log('📡 Auto-Refresh gestartet - script.js pausiert');
  });

  window.addEventListener('autoRefreshCompleted', () => {
    console.log('📡 Auto-Refresh abgeschlossen - script.js reaktiviert');
  });

  // Globale Funktionen für Auto-Refresh verfügbar machen
  window.loadData = loadData;

  console.log('✅ Script.js geladen und koordiniert mit Auto-Refresh');
});
