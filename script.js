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
  const modes = ['arrival','departure','arrival-today','departure-tomorrow'];
  let mode       = localStorage.getItem('filterMode') || 'arrival';
  let customDate = localStorage.getItem('filterDate') || '';

  const today = () => new Date().toISOString().slice(0,10);
  const tomorrow = () => {
    const d = new Date();
    d.setDate(d.getDate()+1);
    return d.toISOString().slice(0,10);
  };

  function updateUI() {
    const labels = {
      arrival:              'Anreise',
      departure:            'Abreise',
      'arrival-today':      'Anreise heute',
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
  const toggleType    = document.getElementById('toggleType');
  const toggleStorno  = document.getElementById('toggleStorno');
  const toggleOpen    = document.getElementById('toggleOpen');
  const filterDate    = document.getElementById('filterDate');
  const searchInput   = document.getElementById('searchInput');
  const tbody         = document.querySelector('#resTable tbody');

  const modal         = document.getElementById('modal');
  const modalText     = document.getElementById('modalText');
  const modalClose    = document.getElementById('modalClose');

  const qrModal       = document.getElementById('qrModal');
  const qrContainer   = document.getElementById('qrContainer');
  const qrClose       = document.getElementById('qrClose');

  let rawData = [];

  // 4-Stufen Toggle initialisieren
  initDateToggle(toggleType, filterDate, loadData);

  // Soundex für phonetische Suche
  function soundex(s) {
    const a = s.toUpperCase().split(''), first = a.shift();
    const map = {
      B:1,F:1,P:1,V:1, C:2,G:2,J:2,K:2,Q:2,S:2,X:2,Z:2,
      D:3,T:3, L:4, M:5,N:5, R:6
    };
    const digits = a.map(c => map[c] || '0');
    const filtered = digits.filter((d,i) => d !== digits[i-1] && d !== '0');
    return (first + filtered.join('') + '000').slice(0,4);
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
  const colors = ['#e74c3c','#e67e22','#f1c40f','#2ecc71','#27ae60'];
  function createPie(percent, id) {
    const pct = Math.min(percent,100), r=10, cx=12, cy=12;
    const idx   = Math.min(Math.floor(pct/20), colors.length-1),
          color = colors[idx];
    if (pct === 100) {
      return `<svg class="pie-chart" data-id="${id}" viewBox="0 0 24 24">
        <circle cx="${cx}" cy="${cy}" r="${r}" fill="#ddd"/>
        <circle cx="${cx}" cy="${cy}" r="${r*0.9}" fill="${color}"/>
        <text x="${cx}" y="${cy}" text-anchor="middle" dy=".35em" font-size="8" fill="#333">${Math.round(pct)}%</text>
      </svg>`;
    }
    const angle    = pct/100*360,
          largeArc = angle>180?1:0,
          rad      = a=> (a-90)*Math.PI/180,
          x2       = cx + r*Math.cos(rad(angle)),
          y2       = cy + r*Math.sin(rad(angle));
    return `<svg class="pie-chart" data-id="${id}" viewBox="0 0 24 24">
      <circle cx="${cx}" cy="${cy}" r="${r}" fill="#ddd"/>
      <path d="M${cx},${cy} L${cx},${cy-r} A${r},${r} 0 ${largeArc},1 ${x2},${y2} Z" fill="${color}"/>
      <text x="${cx}" y="${cy}" text-anchor="middle" dy=".35em" font-size="8" fill="#333">${Math.round(pct)}%</text>
    </svg>`;
  }

  // Filter-Persistenz: Storno, Offen, Suche
  function loadFiltersFromStorage() {
    const savedSt   = localStorage.getItem('filterStorno');
    const savedOp   = localStorage.getItem('filterOpen');
    const savedTerm = localStorage.getItem('searchTerm');

    if (savedSt === 'storno') {
      toggleStorno.classList.replace('no-storno','storno');
      toggleStorno.textContent = 'Storno';
    }
    if (savedOp === 'open') {
      toggleOpen.classList.replace('all','open');
      toggleOpen.textContent = 'Offen';
    }
    if (savedTerm) {
      searchInput.value = savedTerm;
    }
  }
  function saveFiltersToStorage() {
    localStorage.setItem('filterStorno', toggleStorno.classList.contains('storno') ? 'storno' : 'no-storno');
    localStorage.setItem('filterOpen',   toggleOpen.classList.contains('open')    ? 'open'    : 'all');
    localStorage.setItem('searchTerm',   searchInput.value.trim());
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
      date = new Date().toISOString().slice(0,10);
    } else if (mode === 'departure-tomorrow') {
      const d = new Date(); d.setDate(d.getDate()+1);
      date = d.toISOString().slice(0,10);
    } else {
      date = filterDate.value;
      localStorage.setItem('filterDate', date);
    }

    if (!date) {
      alert('Bitte ein Datum wählen.');
      return;
    }

    const params = new URLSearchParams({ date, type: getType() });
    
    const loadPromise = window.HttpUtils 
      ? HttpUtils.requestJsonWithLoading(`data.php?${params}`, {}, { retries: 3, timeout: 12000 }, 'Reservierungsliste wird geladen...')
      : window.LoadingOverlay
        ? LoadingOverlay.wrapFetch(() => fetch(`data.php?${params}`).then(res => res.json()), 'Reservierungsliste')
        : fetch(`data.php?${params}`).then(res => res.json());
    
    loadPromise
      .then(data => {
        if (data.error) {
          alert(data.error);
          return;
        }
        rawData = data.map(r => ({ ...r, storno: Boolean(r.storno) }));
        renderTable();
      })
      .catch((error) => {
        console.error('Fehler beim Laden der Daten:', error);
        alert('Fehler beim Laden der Daten. Bitte Verbindung prüfen und erneut versuchen.');
      });
  }

  // Tabelle rendern
  function renderTable() {
    let view = rawData.slice();

    // Storno-Filter
    if (toggleStorno.classList.contains('storno')) {
      view = view.filter(r => r.storno);
    } else {
      view = view.filter(r => !r.storno);
    }

    // Offen-Filter
    if (toggleOpen.classList.contains('open')) {
      view = view.filter(r => {
        const pct = getType()==='arrival' ? r.percent_logged_in : r.percent_logged_out;
        return pct < 100;
      });
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

    view.forEach(r => {
      const statusPct = getType()==='arrival' ? r.percent_logged_in : r.percent_logged_out;
      const nameText  = `${r.nachname} ${r.vorname}` + (r.hund ? ' <img src="pic/dog.svg" alt1="Hund" style="width: 1em; height: 1em; vertical-align: middle;">' : '') + (r.av_id > 0 ? ' <img src="pic/AV.svg" alt="AV" style="width: 1em; height: 1em; vertical-align: middle;">' : '') + (r.storno ? ' <img src="pic/cancelled.svg" alt="Storniert" style="width: 4em; height: 1em; vertical-align: middle;">' : '');
      const nameCell  = `<td class="name-cell" data-id="${r.id}">${nameText}</td>`;
      const bemHtml   = r.bem && r.bem_av
                      ? `${r.bem}<hr>${r.bem_av}`
                      : r.bem || r.bem_av || '';
      const bemCell   = bemHtml
                      ? `<td class="bem-cell" data-id="${r.id}" title="${bemHtml}"><img src="pic/info.svg" alt="Info" style="width: 1em; height: 1em;"></td>`
                      : '<td class="bem-cell" data-id="${r.id}"></td>';
      const origCell  = r.origin
                      ? `<td class="orig-cell" data-id="${r.id}" title="${r.origin}"><img src="pic/info.svg" alt="Origin" style="width: 1em; height: 1em;"></td>`
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
      console.log(`Guest: ${r.nachname}, Arrival: ${r.anreise}, Departure: ${r.abreise}, Length: ${lengthOfStay} nights`);
      
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
      tr.dataset.avId   = r.av_id;
      if (backgroundColor) {
        tr.style.backgroundColor = backgroundColor;
      }
      tr.innerHTML = `
        <td>${createPie(statusPct, r.id)}</td>
        <td>${r.anreise.substring(0,5)}</td>
        <td>${r.abreise.substring(0,5)}</td>
        <td>${r.anzahl}</td>
        ${nameCell}
        <td>${r.arr_kurz||''}</td>
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

    // Bemerkung-Zellen -> Modal mit Inhalt anzeigen
    document.querySelectorAll('.bem-cell').forEach(cell => {
      cell.addEventListener('click', () => {
        const title = cell.getAttribute('title');
        if (title && title.trim()) {
          const modalText = document.getElementById('modalText');
          const modal = document.getElementById('modal');
          modalText.innerHTML = `<h3>Bemerkung</h3><div style="margin-top: 1rem;">${title.replace('<hr>', '<br><br>')}</div>`;
          modal.classList.add('visible');
        }
      });
    });

    // Origin-Zellen -> Modal mit Inhalt anzeigen
    document.querySelectorAll('.orig-cell').forEach(cell => {
      cell.addEventListener('click', () => {
        const title = cell.getAttribute('title');
        if (title && title.trim()) {
          const modalText = document.getElementById('modalText');
          const modal = document.getElementById('modal');
          modalText.innerHTML = `<h3>Origin</h3><div style="margin-top: 1rem;">${title}</div>`;
          modal.classList.add('visible');
        }
      });
    });

    // QR-Code für 7. Spalte (ANam) - Check-in Status
    document.querySelectorAll('.qr-cell').forEach(cell => {
      cell.style.cursor = 'pointer';
      cell.addEventListener('click', () => {
        qrContainer.innerHTML = `
          <div class="qr-hint">
            Diesen QR Code scannen…<br>Scan this QR code…
          </div>
          <div id="qrCode"></div>
        `;
        const qrPromise = window.HttpUtils
          ? HttpUtils.requestJsonWithLoading(`getBookingUrl.php?id=${cell.dataset.id}`, {}, {}, 'QR-Code wird generiert...')
          : window.LoadingOverlay
            ? LoadingOverlay.wrapFetch(() => fetch(`getBookingUrl.php?id=${cell.dataset.id}`).then(res => res.json()), 'QR-Code')
            : fetch(`getBookingUrl.php?id=${cell.dataset.id}`).then(res => res.json());
        
        qrPromise
          .then(json => {
            if (json.url) {
              new QRCode(document.getElementById('qrCode'), {
                text: json.url, width:128, height:128
              });
              qrModal.classList.add('visible');
            } else alert('Fehler beim Abrufen der Buchungs-URL');
          })
          .catch((error) => {
            console.error('QR-Code Fehler:', error);
            alert('Netzwerkfehler beim QR-Code Laden. Bitte erneut versuchen.');
          });
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

  // Event-Listener für Storno & Offen
  toggleStorno.addEventListener('click', () => {
    if (toggleStorno.classList.contains('no-storno')) {
      toggleStorno.classList.replace('no-storno','storno');
      toggleStorno.textContent = 'Storno';
    } else {
      toggleStorno.classList.replace('storno','no-storno');
      toggleStorno.textContent = 'Ohne Storno';
    }
    loadData();
  });

  toggleOpen.addEventListener('click', () => {
    if (toggleOpen.classList.contains('all')) {
      toggleOpen.classList.replace('all','open');
      toggleOpen.textContent = 'Offen';
    } else {
      toggleOpen.classList.replace('open','all');
      toggleOpen.textContent = 'Alle';
    }
    loadData();
  });

  filterDate.addEventListener('change', loadData);
  searchInput.addEventListener('input', () => {
    saveFiltersToStorage();
    renderTable();
  });

  // Modals schließen
  modalClose.addEventListener('click', () => modal.classList.remove('visible'));
  qrClose.addEventListener('click',    () => qrModal.classList.remove('visible'));
  window.addEventListener('click', e => {
    if (e.target === modal)   modal.classList.remove('visible');
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

  // Suchfeld sofort leeren (auch bei F5)
  searchInput.value = '';
  localStorage.removeItem('searchTerm');

  loadData();

  // === Universelle Verbindungsstatus-Funktionen ===
  // Stelle sicher, dass updateNavigationStatus global verfügbar ist, auch wenn keine Navigation vorhanden
  if (!window.updateNavigationStatus) {
    window.updateNavigationStatus = function() {
      // Fallback für Seiten ohne Navigation-Status
      console.log('[CONNECTION] Navigation status not available on this page');
    };
  }
  
  // Stelle globale Connection-Update-Funktion zur Verfügung
  window.updateConnectionStatus = function() {
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
});
