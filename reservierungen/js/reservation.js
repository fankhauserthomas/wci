document.addEventListener('DOMContentLoaded', () => {

  const RES_ROOT_PREFIX = window.location.pathname.split('/reservierungen/')[0] || '';
  const API_BASE = `${RES_ROOT_PREFIX}/reservierungen/api/`;
  const resAssetPath = `${RES_ROOT_PREFIX}/pic/`;
  const resApiPath = (name) => `${API_BASE}${name}`;

  // Debug-System für reservation.js
  const DEBUG_MODE = false; // Setze auf true für Debug-Modus

  // Smart Debug Logger
  const debugLog = function (message, ...args) {
    if (DEBUG_MODE) {
      console.log(message, ...args);
    }
  };

  const debugWarn = function (message, ...args) {
    if (DEBUG_MODE) {
      console.warn(message, ...args);
    }
  };

  const debugError = function (message, ...args) {
    // Errors immer anzeigen
    console.error(message, ...args);
  };

  // Intelligente Namen-Bereinigungsfunktion
  function cleanNamesText(text) {
    if (!text) return '';

    // Alle Sonderzeichen außer Bindestrich (-) entfernen
    // Erlaubt: Buchstaben, Zahlen (werden später separat entfernt), Leerzeichen, Zeilenumbrüche, Bindestrich
    const allowedCharsRegex = /[^a-zA-ZäöüÄÖÜß0-9\s\n-]/g;

    // Akademische Titel (deutsch und englisch)
    const academicTitles = [
      'dr', 'prof', 'professor', 'doktor', 'phd', 'md', 'mag', 'dipl', 'ing',
      'ba', 'ma', 'bsc', 'msc', 'llm', 'mba', 'ddr', 'dds', 'dvm'
    ];

    // Anreden (deutsch, englisch, weitere)
    const salutations = [
      'herr', 'frau', 'mr', 'mrs', 'ms', 'miss', 'mister', 'sir', 'madam', 'madame',
      'dr', 'prof', 'professor', 'fräulein', 'mademoiselle', 'monsieur', 'señor',
      'señora', 'señorita', 'signore', 'signora', 'signorina'
    ];

    // Zusätzliche Wörter die entfernt werden sollen
    const wordsToRemove = [
      'alpenverein', 'mitglied', 'bergführer', 'führer', 'leiter', 'trainer',
      'vegetarier', 'vegetarisch', 'vegan', 'veganer'
    ];

    let cleanedText = text
      // Tabs durch Leerzeichen ersetzen
      .replace(/\t/g, ' ')
      // Alle Sonderzeichen außer Bindestrich entfernen (inkl. alle Klammern)
      .replace(allowedCharsRegex, '')
      // Zahlen entfernen
      .replace(/[0-9]/g, '')
      // Doppelte Leerzeichen in einfache umwandeln (mehrfach wiederholen)
      .replace(/  +/g, ' ')
      .replace(/  +/g, ' ')
      .replace(/  +/g, ' ')
      // Doppelte Zeilenumbrüche in einfache umwandeln
      .replace(/\n\n+/g, '\n');

    // Zeilen einzeln verarbeiten
    const lines = cleanedText.split('\n');
    const cleanedLines = [];

    lines.forEach(line => {
      if (!line.trim()) return; // Leere Zeilen überspringen

      let cleanedLine = line.trim();

      // Wörter in der Zeile aufteilen
      let words = cleanedLine.split(' ').filter(word => word.length > 0);

      // Akademische Titel entfernen
      words = words.filter(word => {
        const lowerWord = word.toLowerCase().replace(/[^a-z]/g, '');
        return !academicTitles.includes(lowerWord);
      });

      // Zusätzliche unerwünschte Wörter entfernen
      words = words.filter(word => {
        const lowerWord = word.toLowerCase().replace(/[^a-z]/g, '');
        return !wordsToRemove.includes(lowerWord);
      });

      // Anreden entfernen (nur am Anfang)
      if (words.length > 0) {
        const firstWord = words[0].toLowerCase().replace(/[^a-z]/g, '');
        if (salutations.includes(firstWord)) {
          words.shift(); // Erstes Wort entfernen
        }
      }

      // Nur behalten wenn noch Wörter übrig sind
      if (words.length > 0) {
        cleanedLines.push(words.join(' '));
      }
    });

    return cleanedLines.join('\n');
  }

  const resId = new URLSearchParams(location.search).get('id');
  const highlightName = new URLSearchParams(location.search).get('highlight');
  const source = new URLSearchParams(location.search).get('source');
  let currentReservationData = null; // Global variable to store current reservation data
  let currentNameBeingCorrected = null; // Global variable to store name being corrected
  const headerDiv = document.getElementById('resHeader');
  const tbody = document.querySelector('#namesTable tbody');
  const selectAll = document.getElementById('selectAll');
  const deleteBtn = document.getElementById('deleteBtn');
  const printBtn = document.getElementById('printBtn');
  const arrBtn = document.getElementById('arrangementBtn');
  const dietBtn = document.getElementById('dietBtn');
  const backBtn = document.getElementById('backBtn');
  const importBtn = document.getElementById('importNamesBtn');
  const newArea = document.getElementById('newNamesTextarea');
  const formatSelect = document.getElementById('importFormat');

  // Action Bar Namen hinzufügen Button
  const addNamesBtn = document.getElementById('addNamesBtn');

  // Namen hinzufügen Modal elements
  const addNamesModal = document.getElementById('addNamesModal');
  const addNamesModalClose = document.getElementById('addNamesModalClose');
  const addNamesCancel = document.getElementById('addNamesCancel');

  // Header button elements (no longer dropdown)
  const editBtn = document.getElementById('editBtn');
  const stornoBtn = document.getElementById('stornoBtn');
  const deleteReservationBtn = document.getElementById('deleteReservationBtn');
  const backMenuBtn = document.getElementById('backMenuBtn');

  // Custom confirmation modal elements
  const confirmModal = document.getElementById('confirmModal');
  const confirmModalClose = document.getElementById('confirmModalClose');
  const confirmModalTitle = document.getElementById('confirmModalTitle');
  const confirmModalMessage = document.getElementById('confirmModalMessage');
  const confirmModalQuestion = document.getElementById('confirmModalQuestion');
  const confirmModalYes = document.getElementById('confirmModalYes');
  const confirmModalNo = document.getElementById('confirmModalNo');

  // Name correction modal elements
  const nameCorrectionModal = document.getElementById('nameCorrectionModal');
  const nameCorrectionModalClose = document.getElementById('nameCorrectionModalClose');
  const nameCorrectionOptions = document.getElementById('nameCorrectionOptions');
  const nameCorrectionApply = document.getElementById('nameCorrectionApply');
  const nameCorrectionSkip = document.getElementById('nameCorrectionSkip');
  const customVorname = document.getElementById('customVorname');
  const customNachname = document.getElementById('customNachname');

  // New name display elements
  const nameWithSpaces = document.getElementById('nameWithSpaces');
  const previewVorname = document.getElementById('previewVorname');
  const previewNachname = document.getElementById('previewNachname');
  const swapNamesBtn = document.getElementById('swapNamesBtn');
  const applyPreviewBtn = document.getElementById('applyPreviewBtn');
  const removeLongSpaces = document.getElementById('removeLongSpaces');
  const removeSpecialChars = document.getElementById('removeSpecialChars');
  const removeNumbers = document.getElementById('removeNumbers');

  if (!resId) {
    alert('Keine Reservierungs-ID in der URL.');
    history.back();
    return;
  }

  // Hilfsfunktionen zum Formatieren
  const fmtDate = iso => {
    if (!iso || iso === null || iso === 'null' || iso === undefined || typeof iso !== 'string') return '';
    try {
      // Handle both MySQL format (YYYY-MM-DD HH:MM:SS) and ISO format (YYYY-MM-DDTHH:MM:SS)
      let datePart;
      if (iso.includes('T')) {
        datePart = iso.split('T')[0];
      } else {
        datePart = iso.split(' ')[0];
      }

      const [y, m, d] = datePart.split('-');
      return `${d}.${m}.`;
    } catch (e) {
      console.log('fmtDate error with value:', iso, e);
      return '';
    }
  };
  const fmtDateTime = iso => {
    if (!iso || iso === null || iso === 'null' || iso === undefined || typeof iso !== 'string') return '';
    try {
      // Handle both MySQL format (YYYY-MM-DD HH:MM:SS) and ISO format (YYYY-MM-DDTHH:MM:SS)
      let dateTimeParts;
      if (iso.includes('T')) {
        // ISO format
        dateTimeParts = iso.split('T');
      } else {
        // MySQL format - replace space with T to make it ISO-like
        dateTimeParts = iso.split(' ');
      }

      const [date, time] = dateTimeParts;
      const [y, m, d] = date.split('-');
      const [hh, mm] = time.split(':');
      return `${d}.${m} ${hh}:${mm}`;
    } catch (e) {
      console.log('fmtDateTime error with value:', iso, e);
      return '';
    }
  };

  // === NAME PROCESSING HELPER FUNCTIONS ===

  function cleanName(name) {
    let cleaned = name;

    // Multiple spaces to single space
    if (removeLongSpaces && removeLongSpaces.checked) {
      cleaned = cleaned.replace(/\s+/g, ' ');
    }

    // Remove special characters
    if (removeSpecialChars && removeSpecialChars.checked) {
      cleaned = cleaned.replace(/[()_+\/,.:;"'-]/g, ' ');
    }

    // Remove numbers
    if (removeNumbers && removeNumbers.checked) {
      cleaned = cleaned.replace(/[0-9]/g, ' ');
    }

    // Clean up multiple spaces again and trim
    cleaned = cleaned.replace(/\s+/g, ' ').trim();

    return cleaned;
  }

  function displayNameWithSpaces(name) {
    if (!name) return '';

    return name
      .replace(/ /g, '[_]') // Replace spaces with visible indicators
      .replace(/[()_+\/,.:;"'-]/g, '[X]') // Replace special chars with X
      .replace(/[0-9]/g, '[#]'); // Replace numbers with #
  }

  // NEW: Interactive split position functionality
  function updateSplitVisualization(originalName, splitPosition) {
    const originalDisplay = document.getElementById('originalNameDisplay');
    const splitText = document.getElementById('splitPositionText');

    if (!originalName) return;

    // Find all possible split positions (before spaces and at boundaries)
    const splitPositions = [];
    splitPositions.push(0); // Start
    for (let i = 0; i < originalName.length; i++) {
      if (originalName[i] === ' ' || /[()_+\/,.;:"'-]/.test(originalName[i])) {
        splitPositions.push(i); // ON the space/special char, not after
      }
    }
    splitPositions.push(originalName.length); // End

    // Remove duplicates and sort
    const uniquePositions = [...new Set(splitPositions)].sort((a, b) => a - b);

    // Update slider max value
    const slider = document.getElementById('splitPositionSlider');
    slider.max = uniquePositions.length - 1;

    // Get actual split position
    const actualSplitPos = uniquePositions[splitPosition] || 0;

    // Update position text
    if (splitPosition === 0) {
      splitText.textContent = 'Am Anfang';
    } else if (splitPosition >= uniquePositions.length - 1) {
      splitText.textContent = 'Am Ende';
    } else {
      const char = originalName[actualSplitPos];
      if (char === ' ') {
        splitText.textContent = `Auf Leerzeichen (Position ${actualSplitPos})`;
      } else if (/[()_+\/,.;:"'-]/.test(char)) {
        splitText.textContent = `Auf "${char}" (Position ${actualSplitPos})`;
      } else {
        splitText.textContent = `Position ${actualSplitPos}`;
      }
    }

    // Create visual representation with color coding
    let visualHtml = '';
    for (let i = 0; i < originalName.length; i++) {
      const char = originalName[i];
      const isSpaceOrSpecial = char === ' ' || /[()_+\/,.;:"'-]/.test(char);
      const isSplitPoint = i === actualSplitPos;

      let charClass = '';
      if (isSplitPoint) {
        charClass = 'split-point';
      } else if (i < actualSplitPos) {
        charClass = 'before-split';
      } else {
        charClass = 'after-split';
      }

      if (isSpaceOrSpecial) {
        visualHtml += `<span class="${charClass}" style="background-color: ${i < actualSplitPos ? '#e3f2fd' : '#fff3e0'}; color: ${i < actualSplitPos ? '#1976d2' : '#f57900'}; padding: 2px 4px; border-radius: 3px; margin: 0 1px; border: ${isSplitPoint ? '3px solid #f44336' : '1px solid #ddd'}; font-weight: bold;">${char === ' ' ? '␣' : char}</span>`;
      } else {
        visualHtml += `<span class="${charClass}" style="background-color: ${i < actualSplitPos ? '#e8f5e8' : '#ffe8e8'}; color: ${i < actualSplitPos ? '#2e7d32' : '#d32f2f'}; padding: 2px 1px; border-radius: 2px; border: ${isSplitPoint ? '3px solid #f44336' : 'none'};">${char}</span>`;
      }
    }

    originalDisplay.innerHTML = visualHtml;

    // Update preview
    updateSplitPreview(originalName, actualSplitPos);
  }

  function updateSplitPreview(originalName, splitPosition) {
    const cleanedName = cleanName(originalName);

    let nachname = cleanedName.substring(0, splitPosition).trim();
    let vorname = cleanedName.substring(splitPosition).trim();

    // Handle empty parts
    if (!nachname && vorname) {
      nachname = vorname;
      vorname = '';
    }

    document.getElementById('previewNachname').value = nachname;
    document.getElementById('previewVorname').value = vorname;
  }

  function splitNamePreview(name) {
    const cleaned = cleanName(name);
    const parts = cleaned.split(' ').filter(part => part.trim());

    if (parts.length === 0) {
      return { vorname: '', nachname: '' };
    } else if (parts.length === 1) {
      return { vorname: '', nachname: parts[0] };
    } else {
      // First part as Nachname, rest as Vorname (following the existing pattern)
      return {
        vorname: parts.slice(1).join(' '),
        nachname: parts[0]
      };
    }
  }

  function updateNamePreview(originalName) {
    if (!nameWithSpaces || !previewVorname || !previewNachname) return;

    // Display name with space indicators
    nameWithSpaces.textContent = displayNameWithSpaces(originalName);

    // Update preview fields
    const preview = splitNamePreview(originalName);
    previewVorname.value = preview.vorname;
    previewNachname.value = preview.nachname;
  }

  // === END NAME PROCESSING HELPER FUNCTIONS ===

  // 1) Load reservation header + Zimmerliste
  const loadReservationData = async () => {
    // Signal für HTML dass reservation.js am Laden ist
    window.reservationJsLoading = true;

    try {
      // Include color parameter for accurate header coloring
      const apiUrl = `${resApiPath('getReservationDetails.php')}?id=${resId}&includeColor=true`;
      const data = await (window.HttpUtils
        ? HttpUtils.requestJsonWithLoading(apiUrl, {}, { retries: 3, timeout: 12000 }, 'Reservierungsdetails werden geladen...')
        : window.LoadingOverlay
          ? LoadingOverlay.wrapFetch(() => fetch(apiUrl).then(r => r.json()), 'Reservierungsdetails')
          : fetch(apiUrl).then(r => r.json())
      );

      // Store reservation data globally
      const oldInvoiceStatus = currentReservationData?.detail?.invoice;
      currentReservationData = data;

      // Handle AV Icon based on av_id
      if (data.detail && data.detail.av_id && parseInt(data.detail.av_id) > 0) {
        debugLog('🔍 AV ID gefunden:', data.detail.av_id, '- füge AV Icon hinzu');
        if (typeof addAvIcon === 'function') {
          addAvIcon();
        } else if (typeof window.addAvIcon === 'function') {
          window.addAvIcon();
        }
      } else {
        debugLog('⚪ Keine AV ID oder av_id <= 0 - entferne AV Icon falls vorhanden');
        if (typeof removeAvIcon === 'function') {
          removeAvIcon();
        } else if (typeof window.removeAvIcon === 'function') {
          window.removeAvIcon();
        }
      }

      // Check if invoice status changed
      const newInvoiceStatus = data.detail?.invoice;
      if (oldInvoiceStatus !== undefined && oldInvoiceStatus !== newInvoiceStatus) {
        console.log('🔄 Invoice status changed:', oldInvoiceStatus, '=>', newInvoiceStatus);
        // Dispatch custom event for header color update
        window.dispatchEvent(new CustomEvent('invoiceStatusChanged', {
          detail: { oldStatus: oldInvoiceStatus, newStatus: newInvoiceStatus }
        }));
      }

      // je nach Rückgabe-Format entweder data.names[0] oder direkt data
      // neu: wenn data.detail existiert, nimm das, sonst fall auf altes Verhalten zurück
      const detail = data.detail
        ? data.detail
        : (Array.isArray(data.names) && data.names.length
          ? data.names[0]
          : data);

      // Header-Farbe basierend auf Invoice-Status setzen
      const headerElement = document.getElementById('resHeader');
      if (headerElement) {
        // Debug: Log invoice status and color data
        console.log('Debug - Invoice Status:', detail.invoice, typeof detail.invoice);
        console.log('Debug - Server Color:', detail.headerColor, detail.headerColorName);
        console.log('Debug - Complete detail object:', detail);

        // Use server-provided color if available (more reliable)
        if (detail.headerColor) {
          console.log('✅ Using server-calculated color:', detail.headerColor);

          // Multiple approaches for robust color update
          document.documentElement.style.setProperty('--res-header-bg', detail.headerColor, 'important');
          headerElement.style.setProperty('background-color', detail.headerColor, 'important');

          // Force re-render with class toggle
          headerElement.classList.remove('invoice-header', 'normal-header');
          setTimeout(() => {
            headerElement.classList.add(detail.isInvoice ? 'invoice-header' : 'normal-header');
          }, 10);

          console.log('✅ Header color set via server calculation:', detail.headerColorName);

        } else {
          // Fallback: Client-side calculation
          console.log('⚙️ Using client-side color calculation');

          if (detail.invoice === true || detail.invoice === 1 || detail.invoice === '1') {
            // Dunkelgold für Invoice=true
            const color = '#b8860b';
            document.documentElement.style.setProperty('--res-header-bg', color, 'important');
            headerElement.style.setProperty('background-color', color, 'important');
            console.log('✅ Header set to DARK GOLD (invoice=true) via CSS variable');
          } else {
            // Normalgrün für Invoice=false/null
            const color = '#2d8f4f';
            document.documentElement.style.setProperty('--res-header-bg', color, 'important');
            headerElement.style.setProperty('background-color', color, 'important');
            console.log('✅ Header set to DARK GREEN (invoice=false/null) via CSS variable');
          }
        }
      }

      // Check for name correction needs
      setTimeout(() => {
        checkNameCorrection(detail);
      }, 500);

      // Schlafkategorien (nur >0) sammeln
      const cats = [];
      if (detail.betten > 0) cats.push(`${detail.betten} Betten`);
      if (detail.dz > 0) cats.push(`${detail.dz} DZ`);
      if (detail.lager > 0) cats.push(`${detail.lager} Lager`);
      if (detail.sonder > 0) cats.push(`${detail.sonder} Sonder`);
      const catsText = cats.join(', ');

      // linke Spalte: Basisdaten (neu mit semantic classes statt <br>)
      const leftHtml = `
          <div class="rd-name">
            ${(detail.nachname || '').trim()} ${(detail.vorname || '').trim()
        }</div>
          ${catsText ? `<div class="rd-cats">${catsText}</div>` : ''}
          <div class="rd-dates">
            ${fmtDate(detail.anreise)} – ${fmtDate(detail.abreise)}
          </div>
          <div class="rd-arr">
            <span class="label">Arrangement:</span> 
            ${detail.arrangement && detail.arrangement.trim() !== '' ? `
              <button id="reservationArrangementBtn" class="arrangement-btn" 
                      data-arrangement="${detail.arrangement}" 
                      title="Klicken um dieses Arrangement allen Namen zuzuweisen">
                ${detail.arrangement}
              </button>
            ` : `
              <span class="arrangement-text">–</span>
            `}
          </div>
          <div class="rd-origin">
            <span class="label">Herkunft:</span> ${detail.origin || '–'}
          </div>
          <div class="rd-remark">
            <span class="label">Bemerkung:</span> ${detail.bem || detail.bem_av || '–'}
          </div>
        `;

      // rechte Spalte: scrollbare Zimmerliste (max 4 Zeilen)
      const rooms = data.rooms || [];
      let roomsHtml = '<div class="room-list">';
      if (rooms.length) {
        rooms.forEach(z => {
          roomsHtml += `
            <div class="room-item">
              ${z.caption} (${z.anz}/${z.kapazitaet})
              &nbsp;&nbsp;${z.kategorie}
              &nbsp;&nbsp;${z.gast}
            </div>`;
        });
      } else {
        roomsHtml += '<div class="room-item">Keine Zimmer zugewiesen</div>';
      }
      roomsHtml += '</div>';

      // zusammen in ein Grid packen
      const headerInfo = document.querySelector('.header-info');
      if (headerInfo) {
        headerInfo.innerHTML = `
          <div class="header-grid">
            <div class="header-left">${leftHtml}</div>
            <div class="header-right">${roomsHtml}</div>
          </div>
        `;
      } else {
        // Fallback for old structure
        headerDiv.innerHTML = `
          <div class="header-content">
            <div class="header-info">
              <div class="header-grid">
                <div class="header-left">${leftHtml}</div>
                <div class="header-right">${roomsHtml}</div>
              </div>
            </div>
          </div>
        `;
      }

      // Load HP arrangements for the middle column
      console.log('🔥 CALLING loadHpArrangements with resId:', resId);
      loadHpArrangements(resId);

      // Event-Listener für Arrangement-Button hinzufügen
      setTimeout(() => {
        const arrangementBtn = document.getElementById('reservationArrangementBtn');
        if (arrangementBtn) {
          arrangementBtn.addEventListener('click', () => {
            const arrangement = arrangementBtn.dataset.arrangement;
            if (arrangement && arrangement !== '') {
              applyArrangementToAllNames(arrangement);
            } else {
              alert('Keine Arrangement-Information verfügbar.');
            }
          });
        }
      }, 100); // Kurze Verzögerung, damit der DOM aktualisiert wird

    } catch (error) {
      console.error('Error loading reservation data:', error);
      alert('Fehler beim Laden der Reservierungsdaten.');
      history.back();
    } finally {
      // Signal für HTML dass reservation.js fertig ist
      window.reservationJsLoading = false;
    }
  };

  // Name correction functions
  function checkNameCorrection(detail) {
    const vorname = (detail.vorname || '').trim();
    const nachname = (detail.nachname || '').trim();

    // Only show dialog if at least one field is empty
    // If both fields have values, don't show the dialog
    if (vorname && nachname) {
      return; // Both fields have values - no correction needed
    }

    // Check if one field is empty and the other contains multiple words
    const needsCorrection = (
      (!vorname && nachname && nachname.includes(' ')) ||
      (!nachname && vorname && vorname.includes(' '))
    );

    if (needsCorrection) {
      showNameCorrectionDialog(detail, vorname, nachname);
    }
  }

  function showNameCorrectionDialog(detail, currentVorname, currentNachname) {
    // Determine the field with multiple names
    let fullNameText = '';
    if (!currentVorname && currentNachname && currentNachname.includes(' ')) {
      fullNameText = currentNachname;
    } else if (!currentNachname && currentVorname && currentVorname.includes(' ')) {
      fullNameText = currentVorname;
    } else {
      fullNameText = (currentVorname + ' ' + currentNachname).trim();
    }

    // Store for event listeners
    currentNameBeingCorrected = fullNameText;

    // Generate suggestions
    const suggestions = generateNameSuggestions(fullNameText);

    // Populate options
    let optionsHtml = '';
    suggestions.forEach((suggestion, index) => {
      optionsHtml += `
        <label style="display: block; margin: 8px 0; cursor: pointer;">
          <input type="radio" name="nameSuggestion" value="${index}" ${index === 0 ? 'checked' : ''} style="margin-right: 8px;">
          <strong>VN:</strong> ${suggestion.vorname} <strong>NN:</strong> ${suggestion.nachname}
        </label>
      `;
    });

    nameCorrectionOptions.innerHTML = optionsHtml;

    // Set custom fields with current values
    customVorname.value = currentVorname;
    customNachname.value = currentNachname;

    // Initialize new name preview features
    if (previewVorname && previewNachname) {
      // Initialize preview with automatic split
      const preview = splitNamePreview(fullNameText);
      previewVorname.value = preview.vorname;
      previewNachname.value = preview.nachname;
    }

    // Initialize interactive split position functionality
    const slider = document.getElementById('splitPositionSlider');
    if (slider) {
      // Find all possible split positions
      const splitPositions = [0]; // Start
      for (let i = 0; i < fullNameText.length; i++) {
        if (fullNameText[i] === ' ' || /[()_+\/,.;:"'-]/.test(fullNameText[i])) {
          splitPositions.push(i); // ON the space/special char
        }
      }
      splitPositions.push(fullNameText.length); // End

      // Remove duplicates and sort
      const uniquePositions = [...new Set(splitPositions)].sort((a, b) => a - b);

      // Initialize with first space/special char position if available
      let initialPosition = 0;
      if (uniquePositions.length > 2) { // More than just start and end
        initialPosition = 1; // First space/special char
      }

      slider.value = initialPosition;
      updateSplitVisualization(fullNameText, initialPosition);

      // Add slider event listener
      slider.oninput = (e) => {
        updateSplitVisualization(fullNameText, parseInt(e.target.value));
      };
    }

    // Show modal
    nameCorrectionModal.classList.remove('hidden');

    // Handle apply preview button (new)
    const applyPreviewBtn = document.getElementById('applyPreviewBtn');
    if (applyPreviewBtn) {
      applyPreviewBtn.onclick = async () => {
        try {
          const nachname = document.getElementById('previewNachname').value.trim();
          const vorname = document.getElementById('previewVorname').value.trim();

          console.log('Applying preview values:', { vorname, nachname });

          // Update the reservation
          if (vorname !== currentVorname || nachname !== currentNachname) {
            await updateReservationNames(detail.id, vorname, nachname);

            // Reload the reservation data to reflect changes
            setTimeout(() => {
              loadReservationData();
            }, 500);
          }

          nameCorrectionModal.classList.add('hidden');
        } catch (error) {
          console.error('Error applying preview:', error);
          alert('Fehler beim Aktualisieren der Namen: ' + error.message);
        }
      };
    }

    // Show modal
    nameCorrectionModal.classList.remove('hidden');

    // Handle apply button
    const handleApply = async () => {
      try {
        let selectedVorname = '', selectedNachname = '';

        console.log('Dialog values before processing:', {
          customVorname: customVorname.value,
          customNachname: customNachname.value,
          currentVorname,
          currentNachname
        });

        // Check if custom fields have been changed from their initial values
        const customFieldsChanged = (
          customVorname.value.trim() !== currentVorname ||
          customNachname.value.trim() !== currentNachname
        );

        if (customFieldsChanged) {
          selectedVorname = customVorname.value.trim();
          selectedNachname = customNachname.value.trim();
          console.log('Using custom input values (changed):', { selectedVorname, selectedNachname });
        } else {
          // Use selected suggestion
          const selectedRadio = document.querySelector('input[name="nameSuggestion"]:checked');
          if (selectedRadio) {
            const suggestion = suggestions[parseInt(selectedRadio.value)];
            selectedVorname = suggestion.vorname;
            selectedNachname = suggestion.nachname;
            console.log('Using suggestion:', suggestion);
          }
        }

        console.log('Final comparison:', {
          selectedVorname,
          selectedNachname,
          currentVorname,
          currentNachname,
          voornameChanged: selectedVorname !== currentVorname,
          nachnameChanged: selectedNachname !== currentNachname
        });

        // Update the reservation
        if (selectedVorname !== currentVorname || selectedNachname !== currentNachname) {
          console.log('Updating names:', {
            id: detail.id,
            vorname: selectedVorname,
            nachname: selectedNachname
          });

          await updateReservationNames(detail.id, selectedVorname, selectedNachname);

          // Reload the reservation data to reflect changes
          setTimeout(() => {
            loadReservationData();
          }, 500);
        } else {
          console.log('No name changes detected');
        }

        nameCorrectionModal.classList.add('hidden');
      } catch (error) {
        console.error('Error applying name correction:', error);
        alert('Fehler beim Aktualisieren der Namen: ' + error.message);
      }
    };

    // Handle skip button
    const handleSkip = () => {
      nameCorrectionModal.classList.add('hidden');
    };

    // Handle close button
    const handleClose = () => {
      nameCorrectionModal.classList.add('hidden');
    };

    // Clear any existing event listeners and add new ones
    const applyBtn = document.getElementById('nameCorrectionApply');
    const skipBtn = document.getElementById('nameCorrectionSkip');
    const closeBtn = document.getElementById('nameCorrectionModalClose');

    // Remove old listeners by cloning the buttons
    applyBtn.onclick = null;
    skipBtn.onclick = null;
    closeBtn.onclick = null;

    // Add new event listeners directly
    applyBtn.onclick = handleApply;
    skipBtn.onclick = handleSkip;
    closeBtn.onclick = handleClose;
  }

  function generateNameSuggestions(fullNameText) {
    const words = fullNameText.split(/\s+/).filter(word => word.length > 0);
    const suggestions = [];

    if (words.length >= 2) {
      // Suggestion 1: First word as Vorname, rest as Nachname
      const option1 = {
        vorname: words[0],
        nachname: words.slice(1).join(' ')
      };
      suggestions.push(option1);

      // Suggestion 2: Last word as Nachname, rest as Vorname (only if different from option 1)
      const option2 = {
        vorname: words.slice(0, -1).join(' '),
        nachname: words[words.length - 1]
      };

      // Only add if different from first option
      if (option2.vorname !== option1.vorname || option2.nachname !== option1.nachname) {
        suggestions.push(option2);
      }

      // If exactly 2 words, add reverse option (only if different)
      if (words.length === 2) {
        const option3 = {
          vorname: words[1],
          nachname: words[0]
        };

        // Only add if different from existing options
        const isDuplicate = suggestions.some(s =>
          s.vorname === option3.vorname && s.nachname === option3.nachname
        );

        if (!isDuplicate) {
          suggestions.push(option3);
        }
      }
    } else if (words.length === 1) {
      // Single word - suggest as either Vorname or Nachname
      suggestions.push({
        vorname: words[0],
        nachname: ''
      });
      suggestions.push({
        vorname: '',
        nachname: words[0]
      });
    }

    return suggestions;
  }

  async function updateReservationNames(reservationId, vorname, nachname) {
    try {
      console.log('Sending update request:', { id: reservationId, vorname, nachname });

      const response = await fetch(resApiPath('updateReservationNames.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          id: reservationId,
          vorname: vorname,
          nachname: nachname
        })
      });

      console.log('Response status:', response.status);

      const data = await response.json();
      console.log('Response data:', data);

      if (!data.success) {
        throw new Error(data.error || 'Unbekannter Fehler');
      }

      console.log('Namen erfolgreich aktualisiert:', data);
      return data;

    } catch (error) {
      console.error('Error updating reservation names:', error);
      throw error;
    }
  }

  // Lade Reservierungsdaten
  loadReservationData();

  // Namen werden über debounceLoadNames geladen

  // 2) Load names list
  async function loadNames() {
    try {
      tbody.innerHTML = '';
      selectAll.checked = false;
      selectAll.indeterminate = false;

      const list = await (window.HttpUtils
        ? HttpUtils.requestJsonWithLoading(`${resApiPath('getReservationNames.php')}?id=${resId}`, {}, { retries: 3, timeout: 10000 }, 'Namensliste wird geladen...')
        : window.LoadingOverlay
          ? LoadingOverlay.wrapFetch(() => fetch(`${resApiPath('getReservationNames.php')}?id=${resId}`).then(r => r.json()), 'Namensliste')
          : fetch(`${resApiPath('getReservationNames.php')}?id=${resId}`).then(r => r.json())
      );

      // Automatisch Namen erstellen wenn Namensliste leer ist
      if (list.length === 0 && currentReservationData) {
        console.log('Namensliste ist leer - erstelle automatisch einen Namen');
        const autoCreated = await createAutoName();

        // Nur wenn automatische Erstellung erfolgreich war, die neue Liste laden
        if (autoCreated) {
          const newList = await fetch(`${resApiPath('getReservationNames.php')}?id=${resId}`).then(r => r.json());
          renderNamesList(newList);
          return;
        }
      }

      renderNamesList(list);
    } catch (error) {
      console.error('Error loading names:', error);
      alert('Fehler beim Laden der Namensliste.');
    }
  }

  // Namen-Liste rendern (ausgelagert um Duplikation zu vermeiden)
  function renderNamesList(list) {
    // Sort names alphabetically by nachname, then by vorname
    const sortedList = [...list].sort((a, b) => {
      const nameA = `${a.nachname || ''} ${a.vorname || ''}`.trim().toLowerCase();
      const nameB = `${b.nachname || ''} ${b.vorname || ''}`.trim().toLowerCase();
      return nameA.localeCompare(nameB, 'de');
    });

    sortedList.forEach(n => {
      // Debug: Log die ersten paar Check-in/Check-out Werte
      if (sortedList.indexOf(n) < 2) {
        console.log('Debug name entry:', {
          id: n.id,
          name: n.vorname + ' ' + n.nachname,
          checked_in: n.checked_in,
          checked_out: n.checked_out,
          checked_in_type: typeof n.checked_in
        });
      }

      const tr = document.createElement('tr');
      tr.dataset.id = n.id;

      // Prüfe ob dieser Name hervorgehoben werden soll (Barcode-Match)
      let shouldHighlight = false;
      if (highlightName && source === 'barcode') {
        const fullName = `${n.nachname || ''} ${n.vorname || ''}`.trim();
        const cardName = decodeURIComponent(highlightName);

        // Verschiedene Matching-Strategien
        if (fullName === cardName ||
          (n.CardName && n.CardName === cardName) ||
          fullName.includes(cardName) ||
          cardName.includes(fullName)) {
          shouldHighlight = true;
          console.log('🎯 Barcode-Match gefunden:', {
            fullName,
            cardName,
            matchedName: n
          });
        }
      }

      // Hervorhebungsklasse hinzufügen wenn nötig
      if (shouldHighlight) {
        tr.classList.add('barcode-highlight');
      }

      // build detail icons
      let detailIcons = '';
      if (n.transport && parseFloat(n.transport) > 0) {
        detailIcons += `<img src="${resAssetPath}luggage.svg" alt="Transport" class="detail-icon" title="Transport: ${n.transport}€">`;
      }
      if (n.dietInfo && n.dietInfo.trim() !== '') {
        detailIcons += `<img src="${resAssetPath}food.svg" alt="Diät Info" class="detail-icon" title="Info Küche: ${n.dietInfo}">`;
      }
      if (n.bem && n.bem.trim() !== '') {
        detailIcons += `<img src="${resAssetPath}info.svg" alt="Bemerkung" class="detail-icon" title="Bemerkung: ${n.bem}">`;
      }
      if (detailIcons === '') {
        detailIcons = `<img src="${resAssetPath}dots.svg" alt="Details" class="detail-icon">`;
      }

      // AV-Zelle vorbereiten
      const isAv = n.av === true || n.av === 1 || n.av === '1' || n.av === 'true';

      tr.innerHTML = `
        <td><input type="checkbox" class="rowCheckbox"></td>
        <td class="name-cell">${n.nachname || ''} ${n.vorname || ''}</td>
        <td class="detail-cell" style="cursor:pointer; text-align: center;">${detailIcons}</td>
        <td>${n.alter_bez || ''}</td>
        <td class="bem-cell">${n.bem || ''}</td>
        <td class="guide-cell">
          <span class="guide-icon">${n.guide ? '✓' : '○'}</span>
        </td>
        <td class="av-cell">
          ${isAv ? `<img src="${resAssetPath}AV.svg" alt="AV" style="width: 16px; height: 16px;">` : '<span class="av-icon">○</span>'}
        </td>
        <td class="arr-cell">${n.arr || '–'}</td>
        <td class="diet-cell">${n.diet_text || '–'}</td>
        <td class="noshow-cell" style="text-align: center; cursor: pointer;">
          <span class="noshow-indicator ${n.NoShow ? 'noshow-yes' : 'noshow-no'}" 
                title="${n.NoShow ? 'No-Show markiert' : 'Klicken für No-Show'}">
            ${n.NoShow ? '❌' : '✓'}
          </span>
        </td>
        <td class="checkin-cell ${n.checked_in ? 'checked-in' : ''}">
          ${n.checked_in
          ? fmtDateTime(n.checked_in)
          : `<img src="${resAssetPath}notyet.svg" alt="Not yet" class="notyet-icon">`}
        </td>
        <td class="checkout-cell ${n.checked_out ? 'checked-out' : ''}">
          ${n.checked_out
          ? fmtDateTime(n.checked_out)
          : `<img src="${resAssetPath}notyet.svg" alt="Not yet" class="notyet-icon">`}
        </td>
      `;

      tbody.appendChild(tr);

      // Scrolling zu hervorgehobener Zeile nach kurzer Verzögerung
      if (shouldHighlight) {
        setTimeout(() => {
          tr.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
          });

          // Zusätzliche visuelle Rückmeldung
          if (source === 'barcode') {
            // Info-Message anzeigen
            const message = document.createElement('div');
            message.className = 'barcode-success-message';
            message.innerHTML = `
              <div style="background: #d4edda; color: #155724; padding: 10px; margin: 10px 0; border: 1px solid #c3e6cb; border-radius: 5px; text-align: center;">
                🎴 <strong>Karte gefunden:</strong> ${cardName}
              </div>
            `;

            // Message vor der Tabelle einfügen
            const tableContainer = document.querySelector('.table-container');
            if (tableContainer) {
              tableContainer.parentNode.insertBefore(message, tableContainer);

              // Message nach 5 Sekunden entfernen
              setTimeout(() => {
                if (message.parentNode) {
                  message.parentNode.removeChild(message);
                }
              }, 5000);
            }
          }
        }, 500);
      }
    });

    // Leere Zeilen hinzufügen um die erwartete Teilnehmeranzahl zu erreichen
    if (currentReservationData && currentReservationData.detail) {
      const detail = currentReservationData.detail;
      const expectedParticipants = (detail.lager || 0) + (detail.sonder || 0) + (detail.betten || 0) + (detail.dz || 0);
      const currentRows = list.length; // Use original list length, not sorted

      console.log(`Teilnehmer erwartet: ${expectedParticipants} (Lager: ${detail.lager || 0}, Sonder: ${detail.sonder || 0}, Betten: ${detail.betten || 0}, DZ: ${detail.dz || 0}), Aktuell: ${currentRows}`); if (expectedParticipants > currentRows) {
        const emptyRowsNeeded = expectedParticipants - currentRows;
        console.log(`Füge ${emptyRowsNeeded} leere Zeilen hinzu`);

        for (let i = 0; i < emptyRowsNeeded; i++) {
          const tr = document.createElement('tr');
          tr.classList.add('empty-row');

          tr.innerHTML = `
            <td><input type="checkbox" class="rowCheckbox" disabled></td>
            <td class="name-cell" style="color: #ccc; font-style: italic;" title="Klicken zum Hinzufügen">+ Nachname Vorname</td>
            <td class="detail-cell" style="text-align: center;"><img src="${resAssetPath}dots.svg" alt="Details" class="detail-icon" style="opacity: 0.3;"></td>
            <td></td>
            <td class="bem-cell"></td>
            <td class="guide-cell">
              <span class="guide-icon" style="opacity: 0.3;">○</span>
            </td>
            <td class="av-cell">
              <span class="av-icon" style="opacity: 0.3;">○</span>
            </td>
            <td class="arr-cell">–</td>
            <td class="diet-cell">–</td>
            <td class="noshow-cell" style="text-align: center;">
              <span class="noshow-indicator noshow-no" style="opacity: 0.3;">✓</span>
            </td>
            <td class="checkin-cell">
              <img src="${resAssetPath}notyet.svg" alt="Not yet" class="notyet-icon" style="opacity: 0.3;">
            </td>
            <td class="checkout-cell">
              <img src="${resAssetPath}notyet.svg" alt="Not yet" class="notyet-icon" style="opacity: 0.3;">
            </td>
          `;
          tbody.appendChild(tr);
        }
      }
    }

    bindCheckboxes();
    updateBulkButtonStates(); // Initialize button states
  }

  // Inline Name Editing Functions
  function startInlineNameEdit(row, isExisting = false) {
    const nameCell = row.querySelector('.name-cell');
    if (!nameCell) return;

    // Prevent multiple edits on the same row
    if (row.hasAttribute('data-editing')) return;
    row.setAttribute('data-editing', 'true');

    // Get current name for existing entries
    let currentValue = '';
    if (isExisting) {
      const nameText = nameCell.textContent.trim();
      // Parse current name and convert to "Nachname Vorname" format
      const nameParts = nameText.split(' ').filter(part => part.trim());
      if (nameParts.length >= 2) {
        // Assume current format is "Nachname Vorname" already
        currentValue = nameText;
      } else if (nameParts.length === 1) {
        currentValue = nameParts[0]; // Just the nachname
      }
    }

    // Create input field
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'inline-name-input';
    input.placeholder = 'Nachname Vorname';
    input.value = currentValue;
    input.style.cssText = `
      width: 100%;
      border: 2px solid #007bff;
      background: white;
      padding: 4px 8px;
      font-size: inherit;
      font-family: inherit;
    `;

    // Replace cell content
    nameCell.innerHTML = '';
    nameCell.appendChild(input);
    input.focus();
    input.select(); // Select all text for easy editing

    // Flag to prevent duplicate saves
    let saving = false;

    // Handle input events
    input.addEventListener('keydown', async (e) => {
      if (saving) return;

      if (e.key === 'Enter') {
        e.preventDefault();
        saving = true;
        if (isExisting) {
          await updateExistingName(row, input.value.trim());
        } else {
          await saveInlineName(row, input.value.trim());
        }
      } else if (e.key === 'Escape') {
        e.preventDefault();
        if (isExisting) {
          restoreExistingName(row);
        } else {
          cancelInlineEdit(row);
        }
      }
    });

    input.addEventListener('blur', async () => {
      if (saving) return;

      if (input.value.trim()) {
        saving = true;
        if (isExisting) {
          await updateExistingName(row, input.value.trim());
        } else {
          await saveInlineName(row, input.value.trim());
        }
      } else {
        if (isExisting) {
          restoreExistingName(row);
        } else {
          cancelInlineEdit(row);
        }
      }
    });
  }

  async function saveInlineName(row, fullName) {
    if (!fullName) {
      cancelInlineEdit(row);
      return;
    }

    // Parse name (assume "Nachname Vorname" format)
    const nameParts = fullName.split(' ').filter(part => part.trim());
    let vorname = '';
    let nachname = '';

    if (nameParts.length === 1) {
      // Only one name provided - use as nachname
      nachname = nameParts[0];
    } else if (nameParts.length >= 2) {
      // Multiple parts - first is nachname, rest is vorname
      nachname = nameParts[0];
      vorname = nameParts.slice(1).join(' ');
    } else {
      // Empty name
      cancelInlineEdit(row);
      return;
    }

    const nameCell = row.querySelector('.name-cell');
    if (!nameCell) {
      cancelInlineEdit(row);
      return;
    }

    try {
      // Show loading state
      nameCell.innerHTML = '<span style="color: #007bff;">💾 Speichert...</span>';

      // Save to database
      const response = await fetch(resApiPath('addReservationNames.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          res_id: resId,
          names: [{
            vorname: vorname,
            nachname: nachname
          }]
        })
      });

      const result = await response.json();

      if (result.success) {
        console.log('Name successfully added:', fullName);
        // Show success briefly before reloading
        nameCell.innerHTML = '<span style="color: #28a745;">✓ Gespeichert</span>';

        // Clean up editing state
        row.removeAttribute('data-editing');

        setTimeout(async () => {
          // Reload the names list to refresh the table
          await loadNames();
        }, 800);
      } else {
        throw new Error(result.error || 'Fehler beim Speichern');
      }
    } catch (error) {
      console.error('Error saving name:', error);
      // Clean up editing state on error
      row.removeAttribute('data-editing');
      alert('Fehler beim Speichern des Namens: ' + error.message);
      cancelInlineEdit(row);
    }
  }

  async function updateExistingName(row, fullName) {
    if (!fullName) {
      restoreExistingName(row);
      return;
    }

    const id = row.dataset.id;
    if (!id) {
      restoreExistingName(row);
      return;
    }

    // Parse name (assume "Nachname Vorname" format)
    const nameParts = fullName.split(' ').filter(part => part.trim());
    let vorname = '';
    let nachname = '';

    if (nameParts.length === 1) {
      // Only one name provided - use as nachname
      nachname = nameParts[0];
    } else if (nameParts.length >= 2) {
      // Multiple parts - first is nachname, rest is vorname
      nachname = nameParts[0];
      vorname = nameParts.slice(1).join(' ');
    } else {
      // Empty name
      restoreExistingName(row);
      return;
    }

    const nameCell = row.querySelector('.name-cell');
    if (!nameCell) {
      restoreExistingName(row);
      return;
    }

    try {
      // Show loading state
      nameCell.innerHTML = '<span style="color: #007bff;">💾 Aktualisiert...</span>';

      // First get current data to preserve other fields
      const currentResponse = await fetch(`${resApiPath('getGastDetail.php')}?id=${id}`);

      if (!currentResponse.ok) {
        throw new Error('Konnte aktuelle Daten nicht laden');
      }

      const currentData = await currentResponse.json();

      if (currentData.error) {
        throw new Error('Konnte aktuelle Daten nicht laden: ' + currentData.error);
      }

      // Update with new names but keep all other data
      const updateData = {
        ...currentData,
        vorname: vorname,
        nachname: nachname
      };

      // Update in database
      const response = await fetch(resApiPath('updateGastDetail.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(updateData)
      });

      const result = await response.json();

      if (result.success) {
        console.log('Name successfully updated:', fullName);
        // Show success briefly before reloading
        nameCell.innerHTML = '<span style="color: #28a745;">✓ Aktualisiert</span>';

        // Clean up editing state
        row.removeAttribute('data-editing');

        setTimeout(async () => {
          // Reload the names list to refresh the table
          await loadNames();
        }, 800);
      } else {
        throw new Error(result.error || 'Fehler beim Aktualisieren');
      }
    } catch (error) {
      console.error('Error updating name:', error);
      // Clean up editing state on error
      row.removeAttribute('data-editing');
      alert('Fehler beim Aktualisieren des Namens: ' + error.message);
      restoreExistingName(row);
    }
  }

  function restoreExistingName(row) {
    // Remove editing flag
    row.removeAttribute('data-editing');

    // Reload the table to restore original content
    loadNames();
  }

  function cancelInlineEdit(row) {
    // Remove editing flag
    row.removeAttribute('data-editing');

    const nameCell = row.querySelector('.name-cell');
    if (nameCell) {
      nameCell.innerHTML = '<span style="color: #ccc; font-style: italic;" title="Klicken zum Hinzufügen">+ Nachname Vorname</span>';
    }
  }

  // Automatisch einen Namen für die Reservierung erstellen
  async function createAutoName() {
    try {
      if (!currentReservationData) {
        console.log('Keine Reservierungsdaten verfügbar für automatische Namenserstellung');
        return false;
      }

      const detail = currentReservationData.detail
        ? currentReservationData.detail
        : (Array.isArray(currentReservationData.names) && currentReservationData.names.length
          ? currentReservationData.names[0]
          : currentReservationData);

      let vorname = '';
      let nachname = '';

      // Vorname und Nachname aus Reservierung extrahieren
      const resVorname = (detail.vorname || '').trim();
      const resNachname = (detail.nachname || '').trim();

      if (resVorname && resNachname) {
        // Beide Namen vorhanden - übernehmen
        vorname = resVorname;
        nachname = resNachname;
      } else if (resVorname && !resNachname) {
        // Nur Vorname vorhanden - als Nachname eintragen
        nachname = resVorname;
      } else if (!resVorname && resNachname) {
        // Nur Nachname vorhanden - als Nachname eintragen
        nachname = resNachname;
      } else {
        // Keine Namen vorhanden - Fallback
        nachname = 'Gast';
      }

      // Namen mit Arrangement der Reservierung erstellen
      const arrangement = detail.arrangement || '';

      const entry = {
        vorname: vorname,
        nachname: nachname,
        arr: arrangement
      };

      console.log('Erstelle automatischen Namen:', entry);

      const response = await fetch(resApiPath('addReservationNames.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: resId,
          entries: [entry]
        })
      });

      const result = await response.json();

      if (!result.success) {
        console.error('Fehler beim automatischen Erstellen des Namens:', result.error);
        return false;
      } else {
        console.log('Automatischer Name erfolgreich erstellt');
        return true;
      }
    } catch (error) {
      console.error('Fehler bei automatischer Namenserstellung:', error);
      return false;
    }
  }

  // 3) Checkbox-Logik
  function bindCheckboxes() {
    document.querySelectorAll('.rowCheckbox').forEach(cb =>
      cb.addEventListener('change', updateSelectAll)
    );
  }
  function updateSelectAll() {
    // Nur aktive (nicht-disabled) Checkboxen berücksichtigen - keine leeren Zeilen
    const boxes = Array.from(document.querySelectorAll('.rowCheckbox:not([disabled])'));
    const checked = boxes.filter(b => b.checked).length;
    selectAll.checked = checked === boxes.length && boxes.length > 0;
    selectAll.indeterminate = checked > 0 && checked < boxes.length;

    // Update bulk button states
    updateBulkButtonStates();
  }
  selectAll.addEventListener('change', () => {
    const val = selectAll.checked;
    // Nur aktive (nicht-disabled) Checkboxen selektieren - keine leeren Zeilen
    document.querySelectorAll('.rowCheckbox:not([disabled])').forEach(cb => cb.checked = val);
    updateBulkButtonStates();
  });

  // 3.5) Bulk Check-in/Check-out Toggle Funktionalität
  const bulkCheckinBtn = document.getElementById('bulkCheckinBtn');
  const bulkCheckoutBtn = document.getElementById('bulkCheckoutBtn');
  const bulkAvToggleBtn = document.getElementById('bulkAvToggleBtn');

  function updateBulkButtonStates() {
    const selectedRows = getSelectedRows();
    const allRows = Array.from(document.querySelectorAll('#namesTable tbody tr[data-id]'));
    const hasSelection = selectedRows.length > 0;

    // Verwende ausgewählte Zeilen oder alle Zeilen falls keine Auswahl
    const targetRows = hasSelection ? selectedRows : allRows;

    if (targetRows.length === 0) {
      // Keine Zeilen vorhanden - alle Buttons deaktivieren
      bulkCheckinBtn.disabled = true;
      bulkCheckinBtn.textContent = 'Bulk Check-in';
      bulkCheckoutBtn.disabled = true;
      bulkCheckoutBtn.textContent = 'Bulk Check-out';
      bulkAvToggleBtn.disabled = true;
      bulkAvToggleBtn.textContent = 'AV Toggle';
      bulkAvToggleBtn.className = 'btn-bulk-av';
      deleteBtn.disabled = true;
      printBtn.disabled = true;
      arrBtn.disabled = true;
      dietBtn.disabled = true;
      return;
    }

    // Analyze target guests to determine button states
    const checkinStates = targetRows.map(row => {
      const checkinCell = row.querySelector('.checkin-cell');
      const checkoutCell = row.querySelector('.checkout-cell');
      return {
        isCheckedIn: checkinCell.classList.contains('checked-in'),
        isCheckedOut: checkoutCell.classList.contains('checked-out')
      };
    });

    const allCheckedIn = checkinStates.every(state => state.isCheckedIn);
    const noneCheckedIn = checkinStates.every(state => !state.isCheckedIn);
    const someCheckedIn = checkinStates.some(state => state.isCheckedIn);

    const allCheckedOut = checkinStates.every(state => state.isCheckedOut);
    const noneCheckedOut = checkinStates.every(state => !state.isCheckedOut);
    const someCheckedOut = checkinStates.some(state => state.isCheckedOut);

    // Check-in button logic - immer aktiv
    bulkCheckinBtn.disabled = false;
    const selectionText = hasSelection ? ` (${selectedRows.length})` : ` (alle ${allRows.length})`;

    if (allCheckedIn) {
      bulkCheckinBtn.textContent = `Undo Check-in${selectionText}`;
      bulkCheckinBtn.className = 'btn-bulk btn-undo';
    } else if (noneCheckedIn) {
      bulkCheckinBtn.textContent = `Check-in${selectionText}`;
      bulkCheckinBtn.className = 'btn-bulk';
    } else {
      bulkCheckinBtn.textContent = `Toggle Check-in${selectionText}`;
      bulkCheckinBtn.className = 'btn-bulk btn-mixed';
    }

    // Check-out button logic - immer aktiv wenn möglich
    const eligibleForCheckout = checkinStates.filter(state => state.isCheckedIn && !state.isCheckedOut).length;
    const eligibleForUndoCheckout = checkinStates.filter(state => state.isCheckedOut).length;

    if (eligibleForCheckout === 0 && eligibleForUndoCheckout === 0) {
      bulkCheckoutBtn.disabled = true;
      bulkCheckoutBtn.textContent = 'Bulk Check-out';
      bulkCheckoutBtn.className = 'btn-bulk';
    } else {
      bulkCheckoutBtn.disabled = false;
      if (allCheckedOut) {
        bulkCheckoutBtn.textContent = `Undo Check-out${selectionText}`;
        bulkCheckoutBtn.className = 'btn-bulk btn-undo';
      } else if (eligibleForCheckout > 0 && eligibleForUndoCheckout === 0) {
        bulkCheckoutBtn.textContent = `Check-out${selectionText}`;
        bulkCheckoutBtn.className = 'btn-bulk';
      } else if (eligibleForCheckout === 0 && eligibleForUndoCheckout > 0) {
        bulkCheckoutBtn.textContent = `Undo Check-out${selectionText}`;
        bulkCheckoutBtn.className = 'btn-bulk btn-undo';
      } else {
        bulkCheckoutBtn.textContent = `Toggle Check-out${selectionText}`;
        bulkCheckoutBtn.className = 'btn-bulk btn-mixed';
      }
    }

    // Nur Löschen und Drucken erfordern eine Auswahl
    deleteBtn.disabled = !hasSelection;
    printBtn.disabled = !hasSelection;

    // Arrangement und Diät sind immer aktiv (arbeiten mit Auswahl oder allen)
    arrBtn.disabled = false;
    dietBtn.disabled = false;

    // AV Toggle Button logic - immer aktiv
    bulkAvToggleBtn.disabled = false;

    // Analyze AV states for button text
    const avStates = targetRows.map(row => {
      const avCell = row.querySelector('.av-cell');
      // Check if it contains an img with AV.svg (true) or just text content (false)
      const hasAvImg = avCell && avCell.querySelector('img[src*="AV.svg"]');
      return hasAvImg ? 1 : 0;
    });

    const allAv = avStates.every(state => state === 1);
    const noneAv = avStates.every(state => state === 0);

    if (allAv) {
      bulkAvToggleBtn.textContent = `AV Off${selectionText}`;
      bulkAvToggleBtn.className = 'btn-bulk-av btn-undo';
    } else if (noneAv) {
      bulkAvToggleBtn.textContent = `AV On${selectionText}`;
      bulkAvToggleBtn.className = 'btn-bulk-av';
    } else {
      bulkAvToggleBtn.textContent = `Toggle AV${selectionText}`;
      bulkAvToggleBtn.className = 'btn-bulk-av btn-mixed';
    }
  }

  function getSelectedRows() {
    const boxes = Array.from(document.querySelectorAll('.rowCheckbox:checked'));
    return boxes.map(box => box.closest('tr')).filter(row => row);
  }

  // Verbesserte HTTP-Optionen für instabile Verbindungen
  const httpOptions = {
    retries: 4,           // Mehr Versuche für kritische Operationen
    retryDelay: 800,      // Kürzere initiale Wartezeit
    timeout: 15000,       // Längerer Timeout für langsamere Verbindungen
    backoffMultiplier: 1.5 // Moderater Backoff
  };

  function getSelectedIds() {
    return getSelectedRows().map(row => row.dataset.id);
  }

  // Bulk Check-in Toggle mit robuster Fehlerbehandlung
  bulkCheckinBtn.addEventListener('click', async () => {
    const selectedRows = getSelectedRows();
    const allRows = Array.from(document.querySelectorAll('#namesTable tbody tr[data-id]'));

    // Verwende ausgewählte Zeilen oder alle Zeilen falls keine Auswahl
    const targetRows = selectedRows.length > 0 ? selectedRows : allRows;

    if (targetRows.length === 0) return;

    // Determine what action to take based on current button state
    const isUndoMode = bulkCheckinBtn.textContent.includes('Undo');
    const isMixedMode = bulkCheckinBtn.textContent.includes('Toggle');

    let eligibleRows = [];
    let action = '';

    if (isUndoMode) {
      // Undo mode: clear check-ins for checked-in guests (but not if they're checked out)
      eligibleRows = targetRows.filter(row => {
        const checkinCell = row.querySelector('.checkin-cell');
        const checkoutCell = row.querySelector('.checkout-cell');
        return checkinCell.classList.contains('checked-in') &&
          !checkoutCell.classList.contains('checked-out');
      });
      action = 'clear';
    } else {
      // Set mode: check-in guests who aren't checked in yet
      eligibleRows = targetRows.filter(row => {
        const checkinCell = row.querySelector('.checkin-cell');
        return !checkinCell.classList.contains('checked-in');
      });
      action = 'set';
    }

    if (eligibleRows.length === 0) {
      if (isUndoMode) {
        alert('Keine Gäste können aus-gecheckt werden (Check-in zurückgenommen werden).');
      } else {
        alert('Alle Gäste sind bereits eingecheckt.');
      }
      return;
    }

    // Verbindungsstatus prüfen wenn verfügbar
    if (window.connectionMonitor && !window.connectionMonitor.isOnline()) {
      alert('Keine Internetverbindung. Bitte Verbindung prüfen und erneut versuchen.');
      return;
    }

    const ids = eligibleRows.map(row => row.dataset.id);
    const actionText = action === 'set' ? 'Check-in' : 'Check-in Rücknahme';

    try {
      // Verwende robuste Batch-Verarbeitung mit Loading-Overlay
      if (window.HttpUtils) {
        const requests = ids.map(id => ({
          url: resApiPath('updateReservationNamesCheckin.php'),
          options: {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action })
          }
        }));

        const { results, errors, successCount } = await HttpUtils.batchRequestWithLoading(requests, {
          concurrency: 2, // Nur 2 parallele Requests für Stabilität
          retryOptions: httpOptions
        }, `${actionText} für ${ids.length} Gäste...`);

        // Ergebnisse verarbeiten
        let errorMessages = [];

        results.forEach((result, index) => {
          const row = eligibleRows[index];
          const id = ids[index];

          if (result.success && result.data.success) {
            const cell = row.querySelector('.checkin-cell');
            if (action === 'set') {
              cell.classList.add('checked-in');
              cell.textContent = fmtDateTime(result.data.newValue);
            } else {
              cell.classList.remove('checked-in');
              cell.innerHTML = `<img src="${resAssetPath}notyet.svg" class="notyet-icon">`;
            }
          } else {
            const nameCell = row.querySelector('.name-cell');
            const guestName = nameCell ? nameCell.textContent : `ID ${id}`;
            const errorMsg = result.data?.error || result.error || 'Unbekannter Fehler';
            errorMessages.push(`${guestName}: ${errorMsg}`);
          }
        });

        // Nur Fehler anzeigen, keine Erfolgs-Alerts
        if (errorMessages.length > 0) {
          alert(`❌ Fehler bei ${errorMessages.length} ${actionText}:\n${errorMessages.join('\n')}`);
        }

      } else {
        // Fallback mit Loading-Overlay
        const fallbackOperation = async () => {
          console.warn('[BULK-CHECKIN-TOGGLE] HttpUtils not available, using fallback method');

          const results = await Promise.allSettled(ids.map(id =>
            fetch(resApiPath('updateReservationNamesCheckin.php'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id, action })
            }).then(r => r.json()).then(j => {
              if (!j.success) throw new Error(j.error);
              return { id, result: j };
            })
          ));

          let successCount = 0;
          let errors = [];

          results.forEach((result, index) => {
            const row = eligibleRows[index];
            const id = ids[index];

            if (result.status === 'fulfilled') {
              const cell = row.querySelector('.checkin-cell');
              if (action === 'set') {
                cell.classList.add('checked-in');
                cell.textContent = fmtDateTime(result.value.result.newValue);
              } else {
                cell.classList.remove('checked-in');
                cell.innerHTML = `<img src="${resAssetPath}notyet.svg" class="notyet-icon">`;
              }
              successCount++;
            } else {
              errors.push(`${row.querySelector('.name-cell').textContent}: ${result.reason.message}`);
            }
          });

          // Nur Fehler anzeigen, keine Erfolgs-Alerts
          if (errors.length > 0) {
            alert(`❌ Fehler bei ${errors.length} ${actionText}:\n${errors.join('\n')}`);
          }
        };

        if (window.LoadingOverlay) {
          await LoadingOverlay.wrap(fallbackOperation, `${actionText} für ${ids.length} Gäste...`);
        } else {
          await fallbackOperation();
        }
      }

    } catch (error) {
      console.error('Bulk Check-in Toggle error:', error);
      const actionText = action === 'set' ? 'Check-in' : 'Check-in Rücknahme';
      alert(`❌ Kritischer Fehler beim Batch ${actionText}:\n${error.message}\n\nBitte einzeln versuchen oder Verbindung prüfen.`);
    } finally {
      updateBulkButtonStates();
    }
  });

  // Bulk Check-out Toggle mit robuster Fehlerbehandlung
  bulkCheckoutBtn.addEventListener('click', async () => {
    const selectedRows = getSelectedRows();
    const allRows = Array.from(document.querySelectorAll('#namesTable tbody tr[data-id]'));

    // Verwende ausgewählte Zeilen oder alle Zeilen falls keine Auswahl
    const targetRows = selectedRows.length > 0 ? selectedRows : allRows;

    if (targetRows.length === 0) return;

    // Determine what action to take based on current button state
    const isUndoMode = bulkCheckoutBtn.textContent.includes('Undo');
    const isMixedMode = bulkCheckoutBtn.textContent.includes('Toggle');

    let eligibleRows = [];
    let action = '';

    if (isUndoMode) {
      // Undo mode: clear check-outs for checked-out guests
      eligibleRows = targetRows.filter(row => {
        const checkoutCell = row.querySelector('.checkout-cell');
        return checkoutCell.classList.contains('checked-out');
      });
      action = 'clear';
    } else {
      // Set mode: check-out guests who are checked-in but not checked-out
      eligibleRows = targetRows.filter(row => {
        const checkinCell = row.querySelector('.checkin-cell');
        const checkoutCell = row.querySelector('.checkout-cell');
        return checkinCell.classList.contains('checked-in') &&
          !checkoutCell.classList.contains('checked-out');
      });
      action = 'set';
    }

    if (eligibleRows.length === 0) {
      if (isUndoMode) {
        alert('Keine Gäste können aus-gecheckt werden (Check-out zurückgenommen werden).');
      } else {
        alert('Keine Gäste können ausgecheckt werden.\n(Gäste müssen eingecheckt und noch nicht ausgecheckt sein)');
      }
      return;
    }

    if (window.connectionMonitor && !window.connectionMonitor.isOnline()) {
      alert('Keine Internetverbindung. Bitte Verbindung prüfen und erneut versuchen.');
      return;
    }

    const ids = eligibleRows.map(row => row.dataset.id);
    const actionText = action === 'set' ? 'Check-out' : 'Check-out Rücknahme';

    try {
      if (window.HttpUtils) {
        const requests = ids.map(id => ({
          url: resApiPath('updateReservationNamesCheckout.php'),
          options: {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action })
          }
        }));

        const { results, errors, successCount } = await HttpUtils.batchRequestWithLoading(requests, {
          concurrency: 2,
          retryOptions: httpOptions
        }, `${actionText} für ${ids.length} Gäste...`);

        let errorMessages = [];

        results.forEach((result, index) => {
          const row = eligibleRows[index];
          const id = ids[index];

          if (result.success && result.data.success) {
            const cell = row.querySelector('.checkout-cell');
            if (action === 'set') {
              cell.classList.add('checked-out');
              cell.textContent = fmtDateTime(result.data.newValue);
            } else {
              cell.classList.remove('checked-out');
              cell.innerHTML = `<img src="${resAssetPath}notyet.svg" class="notyet-icon">`;
            }
          } else {
            const nameCell = row.querySelector('.name-cell');
            const guestName = nameCell ? nameCell.textContent : `ID ${id}`;
            const errorMsg = result.data?.error || result.error || 'Unbekannter Fehler';
            errorMessages.push(`${guestName}: ${errorMsg}`);
          }
        });

        // Nur Fehler anzeigen, keine Erfolgs-Alerts
        if (errorMessages.length > 0) {
          alert(`❌ Fehler bei ${errorMessages.length} ${actionText}:\n${errorMessages.join('\n')}`);
        }

      } else {
        // Fallback mit Loading-Overlay
        const fallbackOperation = async () => {
          console.warn('[BULK-CHECKOUT-TOGGLE] HttpUtils not available, using fallback method');

          const results = await Promise.allSettled(ids.map(id =>
            fetch(resApiPath('updateReservationNamesCheckout.php'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id, action })
            }).then(r => r.json()).then(j => {
              if (!j.success) throw new Error(j.error);
              return { id, result: j };
            })
          ));

          let successCount = 0;
          let errors = [];

          results.forEach((result, index) => {
            const row = eligibleRows[index];
            const id = ids[index];

            if (result.status === 'fulfilled') {
              const cell = row.querySelector('.checkout-cell');
              if (action === 'set') {
                cell.classList.add('checked-out');
                cell.textContent = fmtDateTime(result.value.result.newValue);
              } else {
                cell.classList.remove('checked-out');
                cell.innerHTML = `<img src="${resAssetPath}notyet.svg" class="notyet-icon">`;
              }
              successCount++;
            } else {
              errors.push(`${row.querySelector('.name-cell').textContent}: ${result.reason.message}`);
            }
          });

          // Nur Fehler anzeigen, keine Erfolgs-Alerts
          if (errors.length > 0) {
            alert(`❌ Fehler bei ${errors.length} ${actionText}:\n${errors.join('\n')}`);
          }
        };

        if (window.LoadingOverlay) {
          await LoadingOverlay.wrap(fallbackOperation, `${actionText} für ${ids.length} Gäste...`);
        } else {
          await fallbackOperation();
        }
      }

    } catch (error) {
      console.error('Bulk Check-out Toggle error:', error);
      const actionText = action === 'set' ? 'Check-out' : 'Check-out Rücknahme';
      alert(`❌ Kritischer Fehler beim Batch ${actionText}:\n${error.message}\n\nBitte einzeln versuchen oder Verbindung prüfen.`);
    } finally {
      updateBulkButtonStates();
    }
  });

  // Bulk AV Toggle mit robuster Fehlerbehandlung
  bulkAvToggleBtn.addEventListener('click', async () => {
    const selectedRows = getSelectedRows();
    const allRows = Array.from(document.querySelectorAll('#namesTable tbody tr[data-id]'));

    // Verwende ausgewählte Zeilen oder alle Zeilen falls keine Auswahl
    const targetRows = selectedRows.length > 0 ? selectedRows : allRows;

    if (targetRows.length === 0) return;

    // Determine action based on current button state
    const isOffMode = bulkAvToggleBtn.textContent.includes('AV Off');
    const isMixedMode = bulkAvToggleBtn.textContent.includes('Toggle AV');

    let eligibleRows = [];
    let action = '';

    if (isOffMode) {
      // Off mode: set AV to 0 for guests with AV=1
      eligibleRows = targetRows.filter(row => {
        const avCell = row.querySelector('.av-cell');
        return avCell && avCell.querySelector('img[src*="AV.svg"]');
      });
      action = 'off';
    } else {
      // On mode: set AV to 1 for guests with AV=0
      eligibleRows = targetRows.filter(row => {
        const avCell = row.querySelector('.av-cell');
        return !avCell || !avCell.querySelector('img[src*="AV.svg"]');
      });
      action = 'on';
    }

    if (eligibleRows.length === 0) {
      if (isOffMode) {
        alert('Keine Gäste haben AV-Status aktiviert.');
      } else {
        alert('Alle Gäste haben bereits AV-Status aktiviert.');
      }
      return;
    }

    // Verbindungsstatus prüfen wenn verfügbar
    if (window.connectionMonitor && !window.connectionMonitor.isOnline()) {
      alert('Keine Internetverbindung. Bitte Verbindung prüfen und erneut versuchen.');
      return;
    }

    const ids = eligibleRows.map(row => row.dataset.id);
    const actionText = action === 'on' ? 'AV Aktivierung' : 'AV Deaktivierung';

    try {
      // Verwende robuste Batch-Verarbeitung mit Loading-Overlay
      if (window.HttpUtils) {
        const requests = ids.map(id => ({
          url: resApiPath('toggleAvFlag.php'),
          options: {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
          }
        }));

        const { results, errors, successCount } = await HttpUtils.batchRequestWithLoading(requests, {
          concurrency: 2,
          retryOptions: httpOptions
        }, `${actionText} für ${ids.length} Gäste...`);

        // Ergebnisse verarbeiten
        let errorMessages = [];

        results.forEach((result, index) => {
          const row = eligibleRows[index];
          const id = ids[index];

          if (result.success && result.data.success) {
            const cell = row.querySelector('.av-cell');
            if (result.data.newValue === 1) {
              cell.innerHTML = `<img src="${resAssetPath}AV.svg" alt="AV" style="width: 16px; height: 16px;">`;
            } else {
              cell.innerHTML = '<span class="av-icon">○</span>';
            }
          } else {
            const nameCell = row.querySelector('.name-cell');
            const guestName = nameCell ? nameCell.textContent : `ID ${id}`;
            const errorMsg = result.data?.error || result.error || 'Unbekannter Fehler';
            errorMessages.push(`${guestName}: ${errorMsg}`);
          }
        });

        // Nur Fehler anzeigen, keine Erfolgs-Alerts
        if (errorMessages.length > 0) {
          alert(`❌ Fehler bei ${errorMessages.length} ${actionText}:\n${errorMessages.join('\n')}`);
        }

      } else {
        // Fallback ohne HttpUtils
        const fallbackOperation = async () => {
          const promises = ids.map(async (id) => {
            const response = await fetch(resApiPath('toggleAvFlag.php'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id })
            });
            return { id, result: await response.json() };
          });

          const results = await Promise.allSettled(promises);
          let errors = [];
          let successCount = 0;

          results.forEach((result, index) => {
            const row = eligibleRows[index];
            const id = ids[index];

            if (result.status === 'fulfilled' && result.value.result.success) {
              const cell = row.querySelector('.av-cell');
              if (result.value.result.newValue === 1) {
                cell.innerHTML = `<img src="${resAssetPath}AV.svg" alt="AV" style="width: 16px; height: 16px;">`;
              } else {
                cell.innerHTML = '<span class="av-icon">○</span>';
              }
              successCount++;
            } else {
              const nameCell = row.querySelector('.name-cell');
              const guestName = nameCell ? nameCell.textContent : `ID ${id}`;
              const errorMsg = result.value?.result?.error || result.reason?.message || 'Unbekannter Fehler';
              errors.push(`${guestName}: ${errorMsg}`);
            }
          });

          // Nur Fehler anzeigen, keine Erfolgs-Alerts
          if (errors.length > 0) {
            alert(`❌ Fehler bei ${errors.length} ${actionText}:\n${errors.join('\n')}`);
          }
        };

        if (window.LoadingOverlay) {
          await LoadingOverlay.wrap(fallbackOperation, `${actionText} für ${ids.length} Gäste...`);
        } else {
          await fallbackOperation();
        }
      }

    } catch (error) {
      console.error('Bulk AV Toggle error:', error);
      alert(`❌ Kritischer Fehler beim Batch ${actionText}:\n${error.message}\n\nBitte einzeln versuchen oder Verbindung prüfen.`);
    } finally {
      updateBulkButtonStates();
    }
  });

  // Initial state
  updateBulkButtonStates();

  // 4) Inline handlers: guide / arrangement / diet / checkin / checkout
  tbody.addEventListener('click', e => {
    const row = e.target.closest('tr');
    if (!row) return;

    // Handle empty row clicks - enable inline editing
    if (row.classList.contains('empty-row')) {
      // Check if we're clicking on a disabled checkbox to prevent action
      if (e.target.type === 'checkbox' && e.target.disabled) {
        return;
      }

      // Start inline editing
      console.log('Clicked on empty row - starting inline editing');
      startInlineNameEdit(row);
      return;
    }

    const id = row.dataset.id;

    // Detail-Click auf Herkunft/Detail-Spalte
    const detailCell = e.target.closest('td.detail-cell');
    if (detailCell) {
      const id = row.dataset.id;
      window.location.href = `GastDetail.html?id=${id}`;
      return;
    }

    // Name-Click - enable inline editing (Ctrl+Click for GastDetail)
    const nameCell = e.target.closest('td.name-cell');
    if (nameCell) {
      const id = row.dataset.id;

      // If Ctrl+Click, open GastDetail
      if (e.ctrlKey || e.metaKey) {
        window.location.href = `GastDetail.html?id=${id}`;
        return;
      }

      // Otherwise start inline editing
      console.log('Clicked on existing name - starting inline editing');
      startInlineNameEdit(row, true); // true indicates existing name
      return;
    }

    // Bemerkung-Click - öffne GastDetail
    const bemCell = e.target.closest('td.bem-cell');
    if (bemCell) {
      const id = row.dataset.id;
      window.location.href = `GastDetail.html?id=${id}`;
      return;
    }

    // Guide toggle: Klick auf die ganze Zelle
    const guideCell = e.target.closest('td.guide-cell');
    if (guideCell) {
      const icon = guideCell.querySelector('.guide-icon');

      const toggleOperation = async () => {
        const response = await fetch(resApiPath('toggleGuideFlag.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id })
        });
        const j = await response.json();

        if (j.success) {
          icon.textContent = j.newValue ? '✓' : '○';
        } else {
          throw new Error(j.error);
        }
      };

      if (window.LoadingOverlay) {
        LoadingOverlay.wrap(toggleOperation, 'Guide-Flag wird geändert...').catch(() => alert('Netzwerkfehler beim Umschalten.'));
      } else {
        toggleOperation().catch(err => alert('Fehler: ' + err.message));
      }
      return;
    }

    // AV toggle: Klick auf die ganze Zelle
    const avCell = e.target.closest('td.av-cell');
    if (avCell) {
      const toggleOperation = async () => {
        const response = await fetch(resApiPath('toggleAvFlag.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id })
        });
        const j = await response.json();

        if (j.success) {
          // AV-Zelle mit korrektem Icon aktualisieren - EXAKT wie im Haupt-Rendering
          const isAv = j.newValue === true || j.newValue === 1 || j.newValue === '1' || j.newValue === 'true';
          avCell.innerHTML = isAv ? `<img src="${resAssetPath}AV.svg" alt="AV" style="width: 16px; height: 16px;">` : '<span class="av-icon">○</span>';
        } else {
          throw new Error(j.error);
        }
      };

      if (window.LoadingOverlay) {
        LoadingOverlay.wrap(toggleOperation, 'AV-Flag wird geändert...').catch(() => alert('Netzwerkfehler beim Umschalten.'));
      } else {
        toggleOperation().catch(err => alert('Fehler: ' + err.message));
      }
      return;
    }

    // Arrangement inline: Klick auf ganze Zelle
    const arrCell = e.target.closest('td.arr-cell');
    if (arrCell) {
      openArrModal([id], newLabel => {
        arrCell.textContent = newLabel;
      });
      return;
    }
    // Diet inline: Klick auf ganze Zelle
    const dietCell = e.target.closest('td.diet-cell');
    if (dietCell) {
      openDietModal([id], newLabel => {
        dietCell.textContent = newLabel;
      });
      return;
    }

    // NoShow inline: Klick auf ganze Zelle
    const noShowCell = e.target.closest('td.noshow-cell');
    if (noShowCell) {
      const id = row.dataset.id;

      // Einzelner NoShow Toggle
      fetch(resApiPath('toggleNoShow.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id })
      })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            // UI aktualisieren
            const indicator = noShowCell.querySelector('.noshow-indicator');
            if (data.newValue === 1) {
              indicator.textContent = '❌';
              indicator.className = 'noshow-indicator noshow-yes';
              indicator.title = 'No-Show markiert';
            } else {
              indicator.textContent = '✓';
              indicator.className = 'noshow-indicator noshow-no';
              indicator.title = 'Klicken für No-Show';
            }
          } else {
            alert('Fehler beim NoShow Toggle: ' + (data.error || 'Unbekannter Fehler'));
          }
        })
        .catch(error => {
          console.error('NoShow update error:', error);
          alert('Fehler beim Aktualisieren des NoShow Status.');
        });
      return;
    }

    // Check-in
    if (e.target.closest('.checkin-cell')) {
      const cell = e.target.closest('.checkin-cell');
      const hasIn = cell.classList.contains('checked-in');
      const hasOut = row.querySelector('.checkout-cell').classList.contains('checked-out');

      if (!hasIn) {
        // set now
        const checkinOperation = async () => {
          const response = await fetch(resApiPath('updateReservationNamesCheckin.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'set' })
          });
          const j = await response.json();

          if (j.success) {
            cell.classList.add('checked-in');
            cell.textContent = fmtDateTime(j.newValue);
          } else {
            throw new Error(j.error);
          }
        };

        if (window.LoadingOverlay) {
          LoadingOverlay.wrap(checkinOperation, 'Check-in wird gesetzt...').catch(err => alert('Fehler Check-in setzen:\n' + err.message));
        } else {
          checkinOperation().catch(err => alert('Fehler Check-in setzen:\n' + err.message));
        }
      } else if (!hasOut && hasIn) {
        // clear without confirmation
        const checkinClearOperation = async () => {
          const response = await fetch(resApiPath('updateReservationNamesCheckin.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'clear' })
          });
          const j = await response.json();

          if (j.success) {
            cell.classList.remove('checked-in');
            cell.innerHTML = `<img src="${resAssetPath}notyet.svg" class="notyet-icon">`;
          } else {
            throw new Error(j.error);
          }
        };

        if (window.LoadingOverlay) {
          LoadingOverlay.wrap(checkinClearOperation, 'Check-in wird zurückgenommen...').catch(err => alert('Fehler Check-in löschen:\n' + err.message));
        } else {
          checkinClearOperation().catch(err => alert('Fehler Check-in löschen:\n' + err.message));
        }
      }
      return;
    }

    // Check-out
    if (e.target.closest('.checkout-cell')) {
      const cell = e.target.closest('.checkout-cell');
      const hasIn = row.querySelector('.checkin-cell').classList.contains('checked-in');
      const hasOut = cell.classList.contains('checked-out');

      if (hasIn && !hasOut) {
        // set now
        const checkoutOperation = async () => {
          const response = await fetch(resApiPath('updateReservationNamesCheckout.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'set' })
          });
          const j = await response.json();

          if (j.success) {
            cell.classList.add('checked-out');
            cell.textContent = fmtDateTime(j.newValue);
          } else {
            throw new Error(j.error);
          }
        };

        if (window.LoadingOverlay) {
          LoadingOverlay.wrap(checkoutOperation, 'Check-out wird gesetzt...').catch(err => alert('Fehler Check-out setzen:\n' + err.message));
        } else {
          checkoutOperation().catch(err => alert('Fehler Check-out setzen:\n' + err.message));
        }
      } else if (hasOut) {
        // clear without confirmation
        const checkoutClearOperation = async () => {
          const response = await fetch(resApiPath('updateReservationNamesCheckout.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'clear' })
          });
          const j = await response.json();

          if (j.success) {
            cell.classList.remove('checked-out');
            cell.innerHTML = `<img src="${resAssetPath}notyet.svg" class="notyet-icon">`;
          } else {
            throw new Error(j.error);
          }
        };

        if (window.LoadingOverlay) {
          LoadingOverlay.wrap(checkoutClearOperation, 'Check-out wird zurückgenommen...').catch(err => alert('Fehler Check-out löschen:\n' + err.message));
        } else {
          checkoutClearOperation().catch(err => alert('Fehler Check-out löschen:\n' + err.message));
        }
      }
      return;
    }
  });

  // 5) Delete selected names
  deleteBtn.addEventListener('click', () => {
    const ids = Array.from(document.querySelectorAll('.rowCheckbox:checked'))
      .map(cb => cb.closest('tr').dataset.id);
    if (ids.length === 0) return alert('Bitte mindestens eine Zeile markieren, um sie zu löschen.');
    if (!confirm(`Soll ${ids.length} Eintrag(e) wirklich gelöscht werden?`)) return;

    fetch(resApiPath('deleteReservationNames.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids })
    })
      .then(r => { if (!r.ok) return r.text().then(t => { throw new Error(t) }); return r.json(); })
      .then(resp => {
        if (resp.success) {
          ids.forEach(id => {
            const tr = document.querySelector(`tr[data-id="${id}"]`);
            if (tr) tr.remove();
          });
          // Update button states after deletion
          updateBulkButtonStates();
        } else {
          throw new Error(resp.error);
        }
      })
      .catch(err => {
        console.error('Löschen fehlgeschlagen:', err);
        alert('Fehler beim Löschen:\n' + err.message);
      });
  });

  // 6) Print selected
  printBtn.addEventListener('click', () => {
    const ids = Array.from(document.querySelectorAll('.rowCheckbox:checked'))
      .map(cb => cb.closest('tr').dataset.id);
    if (!ids.length) return alert('Bitte mindestens einen Eintrag markieren.');

    fetch(resApiPath('GetCardPrinters.php'))
      .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
      .then(printers => showPrintModal(printers, ids))
      .catch(err => {
        console.error(err);
        alert('Druckerliste konnte nicht geladen werden.');
      });
  });

  function showPrintModal(printers, ids) {
    const backdrop = document.createElement('div');
    backdrop.className = 'modal';
    document.body.appendChild(backdrop);

    const content = document.createElement('div');
    content.className = 'modal-content';
    content.style.maxWidth = '800px'; // Breitere Modal für zwei Spalten
    backdrop.appendChild(content);

    const close = document.createElement('span');
    close.className = 'modal-close';
    close.innerHTML = '&times;';
    content.appendChild(close);

    const title = document.createElement('h2');
    title.textContent = 'Drucker wählen';
    content.appendChild(title);

    // Container für vertikale Anordnung (alle Drucker untereinander)
    const columnsContainer = document.createElement('div');
    columnsContainer.style.cssText = `
      display: flex;
      flex-direction: column;
      gap: 15px;
      margin-top: 20px;
    `;
    content.appendChild(columnsContainer);

    // Farbpalette für verschiedene Drucker
    const printerColors = [
      { bg: '#28a745', hover: '#218838' }, // Grün
      { bg: '#007bff', hover: '#0056b3' }, // Blau
      { bg: '#ffc107', hover: '#d39e00' }, // Gelb
      { bg: '#dc3545', hover: '#c82333' }, // Rot
      { bg: '#6f42c1', hover: '#5a359a' }, // Lila
      { bg: '#fd7e14', hover: '#e65100' }, // Orange
      { bg: '#20c997', hover: '#17a085' }, // Türkis
      { bg: '#e83e8c', hover: '#d91a72' }  // Pink
    ];

    printers.forEach((p, index) => {
      const colors = printerColors[index % printerColors.length];

      // Container für jeden Drucker (horizontale Anordnung der beiden Buttons)
      const printerRow = document.createElement('div');
      printerRow.style.cssText = `
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        align-items: stretch;
      `;
      columnsContainer.appendChild(printerRow);

      // Linker Button - Normales Drucken
      const leftBtn = document.createElement('button');
      leftBtn.textContent = p.bez;
      leftBtn.style.cssText = `
        width: 100%;
        height: 90px;
        padding: 20px;
        font-size: 16px;
        font-weight: 500;
        background: ${colors.bg};
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        line-height: 1.2;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
      `;

      leftBtn.addEventListener('mouseenter', () => {
        leftBtn.style.backgroundColor = colors.hover;
        leftBtn.style.transform = 'translateY(-1px)';
        leftBtn.style.boxShadow = '0 4px 8px rgba(0,0,0,0.2)';
      });
      leftBtn.addEventListener('mouseleave', () => {
        leftBtn.style.backgroundColor = colors.bg;
        leftBtn.style.transform = 'translateY(0)';
        leftBtn.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
      });

      leftBtn.addEventListener('click', () => {
        document.body.removeChild(backdrop);
        const q = ids.map(i => `id[]=${i}`).join('&');
        const url = `${resApiPath('printSelected.php')}?printer=${encodeURIComponent(p.kbez)}&resId=${encodeURIComponent(resId)}&${q}`;
        window.location.href = url;
      });
      printerRow.appendChild(leftBtn);

      // Rechter Button - Drucken + Check-in
      const rightBtn = document.createElement('button');
      rightBtn.innerHTML = `${p.bez}<br><small style="opacity: 0.9; font-size: 12px;">+ Check-in</small>`;
      rightBtn.style.cssText = `
        width: 100%;
        height: 90px;
        padding: 20px;
        font-size: 16px;
        font-weight: 500;
        background: linear-gradient(135deg, ${colors.bg} 0%, ${colors.hover} 100%);
        color: white;
        border: 2px solid rgba(255,255,255,0.3);
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        line-height: 1.2;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        position: relative;
      `;

      // Check-in Icon hinzufügen
      const checkIcon = document.createElement('span');
      checkIcon.innerHTML = '✓';
      checkIcon.style.cssText = `
        position: absolute;
        top: 8px;
        right: 8px;
        font-size: 18px;
        font-weight: bold;
        opacity: 0.8;
      `;
      rightBtn.appendChild(checkIcon);

      rightBtn.addEventListener('mouseenter', () => {
        rightBtn.style.transform = 'translateY(-2px) scale(1.02)';
        rightBtn.style.boxShadow = '0 6px 12px rgba(0,0,0,0.3)';
        rightBtn.style.borderColor = 'rgba(255,255,255,0.6)';
      });
      rightBtn.addEventListener('mouseleave', () => {
        rightBtn.style.transform = 'translateY(0) scale(1)';
        rightBtn.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
        rightBtn.style.borderColor = 'rgba(255,255,255,0.3)';
      });

      rightBtn.addEventListener('click', async () => {
        try {
          // Modal schließen
          document.body.removeChild(backdrop);

          // Erst Check-in für alle IDs durchführen
          console.log('🔄 Führe automatisches Check-in durch für IDs:', ids);

          // Check-in für alle IDs parallel durchführen
          const checkinPromises = ids.map(async (id) => {
            const response = await fetch(resApiPath('updateReservationNamesCheckin.php'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id, action: 'set' })
            });

            if (!response.ok) {
              throw new Error(`Check-in fehlgeschlagen für ID ${id}: HTTP ${response.status}`);
            }

            const result = await response.json();
            if (!result.success) {
              throw new Error(`Check-in fehlgeschlagen für ID ${id}: ${result.error}`);
            }

            return { id, success: true, result };
          });

          // Warte auf alle Check-in Operationen
          const checkinResults = await Promise.allSettled(checkinPromises);

          // Ergebnisse auswerten
          const successful = checkinResults.filter(r => r.status === 'fulfilled').length;
          const failed = checkinResults.filter(r => r.status === 'rejected');

          console.log(`✅ Check-in Ergebnisse: ${successful} erfolgreich, ${failed.length} fehlgeschlagen`);

          // Bei Fehlern nur in Konsole loggen, aber keine Benutzer-Bestätigung
          if (failed.length > 0) {
            const failedIds = failed.map((f, index) => ids[checkinResults.findIndex(r => r === f)]);
            console.warn('❌ Check-in fehlgeschlagen für IDs:', failedIds);
            // Keine alert() - stilles Fortfahren mit Drucken
          }

          // Dann Druckauftrag senden
          const q = ids.map(i => `id[]=${i}`).join('&');
          const printUrl = `${resApiPath('printSelected.php')}?printer=${encodeURIComponent(p.kbez)}&resId=${encodeURIComponent(resId)}&${q}`;

          // Kurz warten für UI-Update und dann drucken
          setTimeout(() => {
            window.location.href = printUrl;
          }, 300);

        } catch (error) {
          console.error('❌ Fehler beim Check-in + Drucken:', error);
          // Keine alert() - stilles Fortfahren mit Drucken

          // Trotzdem drucken versuchen
          const q = ids.map(i => `id[]=${i}`).join('&');
          const printUrl = `${resApiPath('printSelected.php')}?printer=${encodeURIComponent(p.kbez)}&resId=${encodeURIComponent(resId)}&${q}`;
          window.location.href = printUrl;
        }
      });
      printerRow.appendChild(rightBtn);
    });

    backdrop.addEventListener('click', e => {
      if (e.target === backdrop) document.body.removeChild(backdrop);
    });
    close.addEventListener('click', () => document.body.removeChild(backdrop));
  }


  // 7) Global arrangement
  arrBtn.addEventListener('click', () => {
    const selectedIds = Array.from(document.querySelectorAll('.rowCheckbox:checked'))
      .map(cb => cb.closest('tr').dataset.id);

    // Wenn keine Auswahl, dann alle Namen verwenden
    const ids = selectedIds.length > 0
      ? selectedIds
      : Array.from(document.querySelectorAll('#namesTable tbody tr[data-id]'))
        .map(row => row.dataset.id);

    if (!ids.length) {
      alert('Keine Namen vorhanden.');
      return;
    }

    openArrModal(ids, loadNames);
  });

  function openArrModal(ids, onDone) {
    const modal = document.getElementById('arrangementModal');
    const container = document.getElementById('arrButtonsContainer');
    container.innerHTML = '';

    fetch(resApiPath('getArrangements.php'))
      .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
      .then(arrs => {
        arrs.forEach(a => {
          const btn = document.createElement('button');
          btn.textContent = a.kbez;
          btn.addEventListener('click', () => {
            applyArrangement(a.id, a.kbez, ids, onDone);
          });
          container.appendChild(btn);
        });
        modal.classList.remove('hidden');
      })
      .catch(err => {
        console.error(err);
        alert('Fehler beim Laden der Arrangements.');
      });
  }

  document.getElementById('closeArrModal')
    .addEventListener('click', () => document.getElementById('arrangementModal').classList.add('hidden'));
  document.querySelector('#arrangementModal .modal-backdrop')
    .addEventListener('click', () => document.getElementById('arrangementModal').classList.add('hidden'));

  function applyArrangement(arrId, label, ids, onDone) {
    fetch(resApiPath('updateReservationNamesArrangement.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids, arr: arrId })
    })
      .then(r => { if (!r.ok) return r.text().then(t => { throw new Error(t) }); return r.json(); })
      .then(j => {
        if (!j.success) throw new Error(j.error);
        if (ids.length === 1) {
          document.querySelector(`tr[data-id="${ids[0]}"] .arr-cell`).textContent = label;
        } else {
          onDone();
        }
        document.getElementById('arrangementModal').classList.add('hidden');
      })
      .catch(e => alert('Fehler beim Speichern: ' + e.message));
  }

  // Funktion um Reservierungs-Arrangement allen Namen zuzuweisen
  async function applyArrangementToAllNames(arrangementLabel) {
    // Alle Namen in der Tabelle sammeln
    const allRows = Array.from(document.querySelectorAll('#namesTable tbody tr[data-id]'));

    if (allRows.length === 0) {
      alert('Keine Namen in der Tabelle gefunden.');
      return;
    }

    // Verbindungsstatus prüfen wenn verfügbar
    if (window.connectionMonitor && !window.connectionMonitor.isOnline()) {
      alert('Keine Internetverbindung. Bitte Verbindung prüfen und erneut versuchen.');
      return;
    }

    // Erst das Arrangement aus der Datenbank laden, um die ID zu bekommen
    try {
      const arrangements = await (window.LoadingOverlay ?
        LoadingOverlay.wrapFetch(() =>
          fetch(resApiPath('getArrangements.php')).then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
          }), 'Arrangements'
        ) :
        fetch(resApiPath('getArrangements.php')).then(r => {
          if (!r.ok) throw new Error(`HTTP ${r.status}`);
          return r.json();
        })
      );

      const arrangement = arrangements.find(a => a.kbez === arrangementLabel);
      if (!arrangement) {
        alert(`Arrangement "${arrangementLabel}" nicht in der Datenbank gefunden.`);
        return;
      }

      const ids = allRows.map(row => row.dataset.id);

      // Robuste HTTP-Behandlung verwenden wenn verfügbar
      if (window.HttpUtils) {
        const requests = ids.map(id => ({
          url: resApiPath('updateReservationNamesArrangement.php'),
          options: {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: [id], arr: arrangement.id })
          }
        }));

        const { results, errors, successCount } = await HttpUtils.batchRequestWithLoading(requests, {
          concurrency: 3,
          retryOptions: { retries: 3, timeout: 10000 }
        }, `Arrangement "${arrangementLabel}" wird ${ids.length} Namen zugewiesen...`);

        // Ergebnisse verarbeiten
        let errorMessages = [];
        let updatedCount = 0;

        results.forEach((result, index) => {
          const row = allRows[index];
          const id = ids[index];

          if (result.success && result.data.success) {
            // Arrangement-Zelle in der Tabelle aktualisieren
            const arrCell = row.querySelector('.arr-cell');
            if (arrCell) {
              arrCell.textContent = arrangementLabel;
            }
            updatedCount++;
          } else {
            const nameCell = row.querySelector('.name-cell');
            const guestName = nameCell ? nameCell.textContent : `ID ${id}`;
            const errorMsg = result.data?.error || result.error || 'Unbekannter Fehler';
            errorMessages.push(`${guestName}: ${errorMsg}`);
          }
        });

        // Ergebnis-Feedback
        if (errorMessages.length === 0) {
          // Erfolg ohne Popup (silent success)
          console.log(`✓ ${updatedCount} Namen erfolgreich mit Arrangement "${arrangementLabel}" aktualisiert.`);
        } else if (updatedCount > 0) {
          alert(`✓ ${updatedCount} Namen erfolgreich aktualisiert.\n\n❌ Fehler bei ${errorMessages.length} Namen:\n${errorMessages.join('\n')}`);
        } else {
          alert(`❌ Alle Aktualisierungen fehlgeschlagen:\n${errorMessages.join('\n')}`);
        }

      } else {
        // Fallback mit Loading-Overlay
        const fallbackOperation = async () => {
          const results = await Promise.allSettled(ids.map(id =>
            fetch(resApiPath('updateReservationNamesArrangement.php'), {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ ids: [id], arr: arrangement.id })
            }).then(r => r.json()).then(j => {
              if (!j.success) throw new Error(j.error);
              return { id, result: j };
            })
          ));

          let successCount = 0;
          let errors = [];

          results.forEach((result, index) => {
            const row = allRows[index];

            if (result.status === 'fulfilled') {
              const arrCell = row.querySelector('.arr-cell');
              if (arrCell) {
                arrCell.textContent = arrangementLabel;
              }
              successCount++;
            } else {
              const nameCell = row.querySelector('.name-cell');
              const guestName = nameCell ? nameCell.textContent : `ID ${ids[index]}`;
              errors.push(`${guestName}: ${result.reason.message}`);
            }
          });

          // Nur Fehler anzeigen, keine Erfolgs-Alerts
          if (errors.length > 0) {
            alert(`❌ Fehler bei ${errors.length} Namen:\n${errors.join('\n')}`);
          }
        };

        if (window.LoadingOverlay) {
          await LoadingOverlay.wrap(fallbackOperation, `Arrangement "${arrangementLabel}" wird ${ids.length} Namen zugewiesen...`);
        } else {
          await fallbackOperation();
        }
      }

    } catch (error) {
      console.error('Error applying arrangement to all names:', error);
      alert(`❌ Kritischer Fehler beim Zuweisen des Arrangements:\n${error.message}`);
    }
  }

  // 8) Global diet
  dietBtn.addEventListener('click', () => {
    const selectedIds = Array.from(document.querySelectorAll('.rowCheckbox:checked'))
      .map(cb => cb.closest('tr').dataset.id);

    // Wenn keine Auswahl, dann alle Namen verwenden
    const ids = selectedIds.length > 0
      ? selectedIds
      : Array.from(document.querySelectorAll('#namesTable tbody tr[data-id]'))
        .map(row => row.dataset.id);

    if (!ids.length) {
      alert('Keine Namen vorhanden.');
      return;
    }

    openDietModal(ids, loadNames);
  });

  function openDietModal(ids, onDone) {
    const modal = document.getElementById('dietModal');
    const container = document.getElementById('dietButtonsContainer');
    container.innerHTML = '';

    fetch(resApiPath('getDiets.php'))
      .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
      .then(diets => {
        diets.forEach(d => {
          const btn = document.createElement('button');
          btn.textContent = d.bez;
          btn.addEventListener('click', () => {
            applyDiet(d.id, d.bez, ids, onDone);
          });
          container.appendChild(btn);
        });
        modal.classList.remove('hidden');
      })
      .catch(err => {
        console.error(err);
        alert('Fehler beim Laden der Diäten.');
      });
  }

  document.getElementById('closeDietModal')
    .addEventListener('click', () => document.getElementById('dietModal').classList.add('hidden'));
  document.querySelector('#dietModal .modal-backdrop')
    .addEventListener('click', () => document.getElementById('dietModal').classList.add('hidden'));

  function applyDiet(dietId, label, ids, onDone) {
    fetch(resApiPath('updateReservationNamesDiet.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids, diet: dietId })
    })
      .then(r => { if (!r.ok) return r.text().then(t => { throw new Error(t) }); return r.json(); })
      .then(j => {
        if (!j.success) throw new Error(j.error);
        if (ids.length === 1) {
          document.querySelector(`tr[data-id="${ids[0]}"] .diet-cell`).textContent = label;
        } else {
          onDone();
        }
        document.getElementById('dietModal').classList.add('hidden');
      })
      .catch(e => alert('Fehler beim Speichern: ' + e.message));
  }

  // 9) Import names
  importBtn.addEventListener('click', () => {
    const text = newArea.value.trim();
    if (!text) return alert('Bitte mindestens einen Namen eingeben.');

    const importFormat = document.getElementById('importFormat').value;
    const entries = text.split('\n').map(line => {
      const trimmedLine = line.trim();
      if (!trimmedLine) return null;

      let vorname = '';
      let nachname = '';

      if (importFormat === 'lastname-firstname') {
        // Format: "Nachname Vorname"
        const parts = trimmedLine.split(/\s+/);
        if (parts.length >= 2) {
          nachname = parts[0];
          vorname = parts.slice(1).join(' '); // Join remaining parts as first name
        } else if (parts.length === 1) {
          // Single name defaults to last name
          nachname = parts[0];
        }
      } else {
        // Format: "Vorname Nachname" (default)
        const parts = trimmedLine.split(/\s+/);
        if (parts.length >= 2) {
          vorname = parts[0];
          nachname = parts.slice(1).join(' '); // Join remaining parts as last name
        } else if (parts.length === 1) {
          // Single name defaults to first name
          vorname = parts[0];
        }
      }

      return { vorname: vorname.trim(), nachname: nachname.trim() };
    }).filter(entry => entry && (entry.vorname || entry.nachname)); // Filter out empty entries

    if (entries.length === 0) {
      return alert('Keine gültigen Namen gefunden.');
    }

    fetch(resApiPath('addReservationNames.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: resId, entries })
    })
      .then(r => r.json())
      .then(j => {
        if (j.success) {
          newArea.value = '';
          // Modal schließen statt details element
          if (addNamesModal) {
            addNamesModal.classList.add('hidden');
          } else {
            // Fallback für altes System
            const details = document.getElementById('addNamesDetails');
            if (details) details.removeAttribute('open');
          }
          loadNames();
        } else alert('Fehler: ' + j.error);
      })
      .catch(() => alert('Netzwerkfehler beim Hinzufügen.'));
  });

  // Update placeholder text based on format selection
  function updatePlaceholder() {
    const format = formatSelect.value;
    if (format === 'lastname-firstname') {
      newArea.placeholder = 'Nachname Vorname – pro Zeile ein Eintrag';
    } else {
      newArea.placeholder = 'Vorname Nachname – pro Zeile ein Eintrag';
    }
  }

  // Initialize placeholder and add event listener
  if (formatSelect) {
    updatePlaceholder();
    formatSelect.addEventListener('change', updatePlaceholder);
  }

  // 10) Back to list
  backBtn.addEventListener('click', () => location.href = 'reservierungen.html');

  // Namen hinzufügen Button (Action Bar)
  if (addNamesBtn) {
    addNamesBtn.addEventListener('click', () => {
      if (window.openAddNamesModal && window.openAddNamesModal()) {
        console.log('Opened add names modal via global function');
      } else {
        console.log('Failed to open modal - fallback to direct manipulation');
        const modal = document.getElementById('addNamesModal');
        if (modal) {
          modal.classList.remove('hidden');
          setTimeout(() => {
            const textarea = document.getElementById('newNamesTextarea');
            if (textarea) textarea.focus();
          }, 100);
        }
      }
    });
  }

  // Edit button - open reservation details page
  editBtn.addEventListener('click', () => {
    // You'll need to create this page or determine the correct URL
    window.location.href = `ReservationDetails.html?id=${resId}`;
  });

  // Email button - send email to guest
  const emailBtn = document.getElementById('emailBtn');
  emailBtn.addEventListener('click', () => {
    // Get the current reservation data to extract email information
    if (currentReservationData && currentReservationData.detail) {
      const detail = currentReservationData.detail;
      const reservationForEmail = {
        id: resId,
        nachname: detail.nachname || '',
        vorname: detail.vorname || '',
        email: detail.email || '',
        anreise: detail.anreise || '',
        abreise: detail.abreise || ''
      };

      if (window.EmailUtils) {
        window.EmailUtils.sendNameListEmail(reservationForEmail);
      } else {
        alert('Email-Funktionalität nicht verfügbar.');
      }
    } else {
      alert('Reservierungsdaten konnten nicht geladen werden.');
    }
  });

  // Storno button - toggle storno flag with confirmation
  stornoBtn.addEventListener('click', () => {
    // Make a quick API call to get current storno status
    fetch(`${resApiPath('toggleStorno.php')}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: resId, checkOnly: true })
    })
      .then(r => r.json())
      .then(statusData => {
        const currentStorno = statusData.currentStorno || false;
        const action = currentStorno ? 'Storno zurücksetzen' : 'stornieren';
        const title = currentStorno ? 'Storno zurücksetzen' : 'Reservierung stornieren';
        const question = `Möchten Sie diese Reservierung wirklich ${action}?`;

        showCustomConfirmModal(
          title,
          question,
          '',
          () => {
            // User confirmed, proceed with storno
            toggleStornoStatus(false);
          }
        );
      })
      .catch(err => {
        // Fallback: use simple confirmation
        showCustomConfirmModal(
          'Storno-Status ändern',
          'Möchten Sie den Storno-Status dieser Reservierung ändern?',
          '',
          () => {
            toggleStornoStatus(false);
          }
        );
      });
  });

  // Delete reservation button - completely delete reservation and all details
  deleteReservationBtn.addEventListener('click', () => {
    if (!currentReservationData || !currentReservationData.detail) {
      alert('Reservierungsdaten konnten nicht geladen werden.');
      return;
    }

    const detail = currentReservationData.detail;
    const guestName = `${detail.nachname || ''} ${detail.vorname || ''}`.trim();
    const dateRange = `${fmtDate(detail.anreise)} - ${fmtDate(detail.abreise)}`;

    showCustomConfirmModal(
      '⚠️ Reservierung komplett löschen',
      `Möchten Sie diese Reservierung wirklich komplett löschen?`,
      `<div style="margin: 15px 0;">
         <strong>Gast:</strong> ${guestName}<br>
         <strong>Zeitraum:</strong> ${dateRange}
       </div>
       <div style="color: #dc3545; font-weight: bold; margin-top: 15px;">
         ⚠️ Achtung: Diese Aktion kann nicht rückgängig gemacht werden!
       </div>`,
      () => {
        // User confirmed, proceed with deletion
        deleteCompleteReservation();
      }
    );
  });

  function getCurrentStornoStatus() {
    // Try to get storno status from stored reservation data
    if (currentReservationData && currentReservationData.detail) {
      return currentReservationData.detail.storno ? true : false;
    }
    if (currentReservationData && currentReservationData.storno !== undefined) {
      return currentReservationData.storno ? true : false;
    }
    // Fallback: assume not storniert if we can't determine
    return false;
  }

  function toggleStornoStatus(deleteRoomAssignments = false) {
    const requestData = { id: resId };
    if (deleteRoomAssignments) {
      requestData.deleteRoomAssignments = true;
    }

    fetch(resApiPath('toggleStorno.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(requestData)
    })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          location.href = 'reservierungen.html';
        } else if (data.requiresConfirmation) {
          // Room assignments found, show custom modal
          showCustomConfirmModal(data.title || 'Bestätigung', data.message, data.question, () => {
            // User confirmed, retry with deleteRoomAssignments = true
            toggleStornoStatus(true);
          });
        } else {
          alert('Fehler beim Ändern des Storno-Status: ' + (data.error || 'Unbekannter Fehler'));
        }
      })
      .catch(err => {
        alert('Netzwerkfehler beim Ändern des Storno-Status');
      });
  }

  // Back button - return to list (falls vorhanden)
  if (backMenuBtn) {
    backMenuBtn.addEventListener('click', () => {
      location.href = 'reservierungen.html';
    });
  } else {
    console.warn('[RESERVATION] backMenuBtn element not found - using fallback');
  }

  // Custom confirmation modal functions
  function showCustomConfirmModal(title, message, question, onConfirm) {
    confirmModalTitle.textContent = title;
    confirmModalMessage.innerHTML = message; // Changed from textContent to innerHTML

    // Hide question if empty
    if (question && question.trim()) {
      confirmModalQuestion.innerHTML = question; // Changed from textContent to innerHTML
      confirmModalQuestion.style.display = 'block';
    } else {
      confirmModalQuestion.style.display = 'none';
    }

    confirmModal.classList.remove('hidden');

    // Remove any existing event listeners
    confirmModalYes.replaceWith(confirmModalYes.cloneNode(true));
    confirmModalNo.replaceWith(confirmModalNo.cloneNode(true));

    // Get new references after cloning
    const newYesBtn = document.getElementById('confirmModalYes');
    const newNoBtn = document.getElementById('confirmModalNo');

    // Add new event listeners
    newYesBtn.addEventListener('click', () => {
      confirmModal.classList.add('hidden');
      onConfirm();
    });

    newNoBtn.addEventListener('click', () => {
      confirmModal.classList.add('hidden');
    });
  }

  // Close modal functionality
  confirmModalClose.addEventListener('click', () => {
    confirmModal.classList.add('hidden');
  });

  confirmModal.addEventListener('click', (e) => {
    if (e.target === confirmModal) {
      confirmModal.classList.add('hidden');
    }
  });

  // Namen hinzufügen Modal handlers
  if (addNamesModalClose) {
    addNamesModalClose.addEventListener('click', () => {
      addNamesModal.classList.add('hidden');
    });
  }

  if (addNamesCancel) {
    addNamesCancel.addEventListener('click', () => {
      addNamesModal.classList.add('hidden');
    });
  }

  // Bereinigen Button Handler
  const cleanNamesBtn = document.getElementById('cleanNamesBtn');
  if (cleanNamesBtn) {
    cleanNamesBtn.addEventListener('click', () => {
      const textarea = document.getElementById('newNamesTextarea');
      if (textarea) {
        textarea.value = cleanNamesText(textarea.value);
      }
    });
  }

  if (addNamesModal) {
    addNamesModal.addEventListener('click', (e) => {
      if (e.target === addNamesModal) {
        addNamesModal.classList.add('hidden');
      }
    });
  }

  // === Auto-Refresh nach dem Speichern ===
  // Debounce-Mechanismus um mehrfache Ladungen zu verhindern
  let loadNamesDebounce = null;

  function debounceLoadNames(reason = 'unknown') {
    if (loadNamesDebounce) {
      clearTimeout(loadNamesDebounce);
    }
    loadNamesDebounce = setTimeout(() => {
      console.log('Loading names:', reason);
      loadNames();
      loadNamesDebounce = null;
    }, 500); // 500ms Verzögerung damit Reservierungsdaten zuerst geladen werden
  }

  // Initial load nach kurzer Verzögerung
  debounceLoadNames('initial');

  // Wenn der Benutzer von GastDetail.html oder ReservationDetails zurückkommt, automatisch alle Daten aktualisieren
  window.addEventListener('pageshow', (event) => {
    // event.persisted = true bedeutet die Seite kam aus dem Browser Cache (history.back())
    if (event.persisted) {
      console.log('Page shown from cache - refreshing all data (header + names)');
      // Header-Daten zuerst laden (wichtig für Invoice-Status Updates)
      loadReservationData().then(() => {
        // Dann Namen-Liste aktualisieren
        debounceLoadNames('pageshow-cache');
      }).catch(error => {
        console.error('Error refreshing header data on pageshow:', error);
        // Auch bei Fehler die Namen-Liste aktualisieren
        debounceLoadNames('pageshow-cache');
      });
    }
  });

  // === Focus Event für zusätzliche Sicherheit ===
  // Falls pageshow nicht funktioniert, zusätzlich auf Focus-Event hören
  window.addEventListener('focus', () => {
    // Prüfe ob die Seite in den letzten 2 Sekunden im Hintergrund war
    if (document.hidden === false) {
      console.log('Window focused - refreshing all data (header + names)');
      // Header-Daten zuerst laden (wichtig für Invoice-Status Updates)
      loadReservationData().then(() => {
        // Dann Namen-Liste aktualisieren
        debounceLoadNames('window-focus');
      }).catch(error => {
        console.error('Error refreshing header data on focus:', error);
        // Auch bei Fehler die Namen-Liste aktualisieren
        debounceLoadNames('window-focus');
      });
    }
  });

  // === Delete Complete Reservation Function ===
  function deleteCompleteReservation() {
    // Show loading overlay if available
    if (window.LoadingOverlay) {
      LoadingOverlay.show('Reservierung wird gelöscht...');
    }

    fetch(resApiPath('deleteReservation.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: resId })
    })
      .then(response => response.json())
      .then(data => {
        if (window.LoadingOverlay) {
          LoadingOverlay.hide();
        }

        if (data.success) {
          alert('Reservierung wurde erfolgreich gelöscht.');
          // Redirect to reservations list
          window.location.href = 'reservierungen.html';
        } else {
          alert(`Fehler beim Löschen: ${data.error || 'Unbekannter Fehler'}`);
        }
      })
      .catch(error => {
        if (window.LoadingOverlay) {
          LoadingOverlay.hide();
        }
        console.error('Error deleting reservation:', error);
        alert('Fehler beim Löschen der Reservierung. Bitte versuchen Sie es erneut.');
      });
  }

  // === Navigation Status Update ===
  function updateNavigationStatus() {
    // Navigation-Status-Indikator wurde entfernt - nur für Kompatibilität
    return;
  }

  // Globale Funktion verfügbar machen
  window.updateNavigationStatus = updateNavigationStatus;

  // loadReservationData global verfügbar machen für externe Header-Updates
  window.loadReservationData = loadReservationData;

  // loadNames global verfügbar machen für externe Updates
  window.loadNames = loadNames;

  // Update Navigation Status alle 5 Sekunden
  setInterval(updateNavigationStatus, 5000);

  // Update HP Arrangements alle 30 Sekunden für aktuelle Zeitklassen
  setInterval(() => {
    if (resId && currentHpArrangements.length > 0) {
      loadHpArrangements(resId);
    }
  }, 30000);

  // Initial Status Update nach kurzer Verzögerung
  setTimeout(updateNavigationStatus, 2000);

  // === Universelle Verbindungsstatus-Funktionen ===
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

  // === HP Arrangements Functionality ===
  let currentHpArrangements = [];
  let availableArrangements = {};

  // Load HP arrangements data and display in header
  async function loadHpArrangements(resId) {
    console.log('🚀 loadHpArrangements called with resId:', resId);
    const headerArrContent = document.getElementById('headerArrContent');
    if (!headerArrContent) {
      console.error('❌ headerArrContent element not found');
      return;
    }

    console.log('✅ headerArrContent found, starting API call...');

    try {
      headerArrContent.innerHTML = '<div class="loading-arr">🔄 Laden...</div>';

      // Verwende die bestehende HP-Arrangements API
      const response = await fetch(`${resApiPath('get-hp-arrangements.php')}?res_id=${resId}`);

      if (!response.ok) {
        throw new Error('HTTP ' + response.status);
      }

      const data = await response.json();
      console.log('HP Arrangements API Response:', data);

      if (data.success && data.arrangements && data.arrangements.length > 0) {
        currentHpArrangements = data.arrangements || [];
        availableArrangements = data.available_arrangements || {};

        let html = '';
        data.arrangements.forEach(arr => {
          const displayName = arr.display_name || arr.name;
          const guestCount = Object.keys(arr.guests || {}).length;
          const countDisplay = guestCount > 1 ? `${arr.total_count}x (${guestCount} Gäste)` : `${arr.total_count}x`;

          html += `
            <div class="header-arr-item">
              <span class="arr-display-name">${displayName}</span>: 
              <span class="arr-count">${countDisplay}</span>
            </div>
          `;
        });

        headerArrContent.innerHTML = html;

      } else {
        headerArrContent.innerHTML = '<div class="empty-arr">Keine Arrangements</div>';
      }

      // Event Handler immer setzen, unabhängig davon ob Arrangements vorhanden sind
      setupHpArrangementsHandlers();

    } catch (error) {
      console.error('Error loading HP arrangements:', error);
      headerArrContent.innerHTML = '<div class="empty-arr">Fehler: ' + error.message + '</div>';
      // Event Handler auch bei Fehlern setzen
      setupHpArrangementsHandlers();
    }
  }

  // Display HP arrangements in header
  function displayHpArrangements(arrangements) {
    const headerArrContent = document.getElementById('headerArrContent');
    if (!headerArrContent) {
      console.error('headerArrContent element not found');
      return;
    }

    console.log('displayHpArrangements called with:', arrangements);

    if (!arrangements || arrangements.length === 0) {
      headerArrContent.innerHTML = '<div class="empty-arr">Keine Arrangements</div>';
      return;
    }

    let html = '';
    arrangements.forEach(arr => {
      // Use display_name (remark if available, otherwise arrangement name)
      const displayName = arr.display_name || arr.name;
      // Show count with guest count in parentheses if multiple guests
      const guestCount = Object.keys(arr.guests || {}).length;
      const countDisplay = guestCount > 1 ? `${arr.total_count}x (${guestCount} Gäste)` : `${arr.total_count}x`;

      // Verwende die Zeitklasse aus der Tischübersicht für die Farbgebung
      const timeClass = arr.time_class || 'time-old';

      html += `
        <div class="header-arr-item ${timeClass}">
          <span class="arr-display-name">${displayName}</span>: 
          <span class="arr-count ${timeClass}">${countDisplay}</span>
        </div>
      `;
    });

    console.log('Setting headerArrContent HTML to:', html);
    headerArrContent.innerHTML = html;
  }

  // Setup event handlers for HP arrangements
  function setupHpArrangementsHandlers() {
    // Header arrangements area click - öffnet Tisch-Übersicht Modal
    const headerArrangements = document.getElementById('headerArrangements');

    console.log('🔧 setupHpArrangementsHandlers aufgerufen');
    console.log('🔧 headerArrangements Element:', headerArrangements);

    if (headerArrangements) {
      console.log('✅ headerArrangements gefunden, Event Listener wird hinzugefügt');

      headerArrangements.addEventListener('click', (e) => {
        console.log('🖱️ Click auf headerArrangements erkannt!');
        e.preventDefault();
        e.stopPropagation();
        openTischUebersichtModal();
      });

      // Cursor pointer für bessere UX
      headerArrangements.style.cursor = 'pointer';
      headerArrangements.title = 'Klicken um Tischübersicht zu öffnen';
    } else {
      console.error('❌ headerArrangements Element nicht gefunden!');
    }
  }

  // Open Tisch-Uebersicht Modal mit iframe
  function openTischUebersichtModal() {
    console.log('🚀 openTischUebersichtModal aufgerufen');

    const urlParams = new URLSearchParams(window.location.search);
    const resId = urlParams.get('id');

    console.log('🔧 Reservierungs-ID:', resId);

    if (!resId) {
      console.error('❌ Keine Reservierungs-ID gefunden');
      alert('Fehler: Keine Reservierungs-ID gefunden');
      return;
    }

    const modal = document.getElementById('tischUebersichtModal');
    const iframe = document.getElementById('tischUebersichtIframe');
    const closeBtn = document.getElementById('tischUebersichtClose');

    console.log('🔧 Modal Elemente:', {
      modal: modal,
      iframe: iframe,
      closeBtn: closeBtn
    });

    if (!modal || !iframe || !closeBtn) {
      console.error('❌ Tisch-Übersicht Modal Elemente nicht gefunden');
      console.error('Modal:', modal);
      console.error('Iframe:', iframe);
      console.error('Close Button:', closeBtn);
      return;
    }

    // iframe URL setzen
    const iframeUrl = `${RES_ROOT_PREFIX}/tisch-uebersicht-resid.php?resid=${resId}`;
    console.log('🔗 iframe URL:', iframeUrl);
    iframe.src = iframeUrl;

    // Modal öffnen
    console.log('🎯 Modal wird geöffnet...');
    modal.classList.remove('hidden');
    console.log('✅ Modal classList nach remove:', modal.classList.toString());

    // Event Handlers
    const closeModal = () => {
      modal.classList.add('hidden');
      iframe.src = ''; // iframe leeren für Performance
    };

    // Close Button
    closeBtn.onclick = closeModal;

    // Backdrop Click
    const backdrop = modal.querySelector('.modal-backdrop');
    if (backdrop) {
      backdrop.onclick = closeModal;
    }

    // ESC Taste
    const handleKeyDown = (e) => {
      if (e.key === 'Escape') {
        closeModal();
        document.removeEventListener('keydown', handleKeyDown);
      }
    };
    document.addEventListener('keydown', handleKeyDown);

    console.log('✅ Tisch-Übersicht Modal geöffnet für Reservierung:', resId);
  }

  // Globale Verfügbarkeit sicherstellen
  window.openTischUebersichtModal = openTischUebersichtModal;

  // Open HP arrangements modal
  function openHpArrangementsModal() {
    const modal = document.getElementById('hpArrangementsModal');
    const container = document.getElementById('hpArrangementsContainer');

    if (!modal || !container) return;

    // Store original state to determine if this was initially empty
    const wasOriginallyEmpty = currentHpArrangements.length === 0;

    // Populate modal with current arrangements
    renderHpArrangementsModal();

    // Handle Add button visibility for originally empty arrangements
    const addBtn = document.getElementById('hpArrangementsAdd');
    if (addBtn && wasOriginallyEmpty) {
      addBtn.style.display = 'inline-block';
    }

    modal.classList.remove('hidden');

    // Setup modal event handlers
    setupHpArrangementsModalHandlers();
  }

  // Render arrangements in modal
  async function renderHpArrangementsModal() {
    const container = document.getElementById('hpArrangementsContainer');
    if (!container) return;

    container.innerHTML = '<div class="loading-arr">Lade HP Arrangements Tabelle...</div>';

    try {
      // Load available arrangements and current data
      const response = await fetch(`${resApiPath('get-hp-arrangements-table.php')}?id=${resId}`);
      const data = await response.json();

      if (!data.success) {
        throw new Error(data.error || 'Fehler beim Laden der Arrangements');
      }

      const arrangements = data.arrangements || [];
      const currentData = data.current_data || [];

      // Create table HTML
      let html = `
        <div class="hp-arrangements-table-container">
          <h4>HP Arrangements bearbeiten</h4>
          <p>Geben Sie für jedes Arrangement die Anzahl und Bemerkungen ein:</p>
          
          <table class="hp-arrangements-table" id="hpArrangementsTable">
            <thead>
              <tr>
                ${arrangements.map(arr => `<th>${arr.bez}</th>`).join('')}
              </tr>
            </thead>
            <tbody>
              <tr class="quantity-row">
                ${arrangements.map(arr => {
        const current = currentData.find(d => d.arr_id == arr.id);
        const anz = current ? current.anz : '';
        return `
                    <td>
                      <input type="number" 
                             class="hp-arr-quantity" 
                             data-arr-id="${arr.id}"
                             data-arr-name="${arr.bez}"
                             value="${anz}"
                             min="0" 
                             max="99"
                             placeholder="0">
                    </td>
                  `;
      }).join('')}
              </tr>
              <tr class="remark-row">
                ${arrangements.map(arr => {
        const current = currentData.find(d => d.arr_id == arr.id);
        const bem = current ? current.bem : '';
        return `
                    <td>
                      <input type="text" 
                             class="hp-arr-remark" 
                             data-arr-id="${arr.id}"
                             value="${bem}"
                             placeholder="Bemerkung">
                    </td>
                  `;
      }).join('')}
              </tr>
            </tbody>
          </table>
          
          <div class="hp-arrangements-preview" id="hpArrangementsPreview">
            <!-- Preview wird hier angezeigt -->
          </div>
        </div>
      `;

      container.innerHTML = html;

      // Setup input event listeners for live preview
      setupHpTableInputHandlers();

      // Initial preview update
      updateHpArrangementsPreview();

    } catch (error) {
      console.error('Error loading HP arrangements table:', error);
      container.innerHTML = `
        <div class="hp-arr-error">
          <p>Fehler beim Laden der HP Arrangements:</p>
          <p>${error.message}</p>
          <button onclick="renderHpArrangementsModal()" class="btn-confirm">Erneut versuchen</button>
        </div>
      `;
    }
  }

  // Create HTML for single arrangement item
  function createHpArrangementItemHtml(arr, index, isNew = false) {
    const options = Object.entries(availableArrangements)
      .map(([id, name]) => `<option value="${id}" ${arr.id == id ? 'selected' : ''}>${name}</option>`)
      .join('');

    return `
      <div class="hp-arr-item ${isNew ? 'new-item' : ''}" data-index="${index}">
        <div class="hp-arr-select">
          <select data-field="arr_id">
            <option value="">Arrangement wählen</option>
            ${options}
          </select>
        </div>
        <div class="hp-arr-quantity">
          <input type="text" readonly min="1" value="${arr.total_count || 1}" data-field="count" placeholder="Anzahl" class="quantity-input">
        </div>
        <div class="hp-arr-remark">
          <input type="text" value="${arr.details[0]?.remark || ''}" data-field="remark" placeholder="Bemerkung">
        </div>
        <button class="hp-arr-delete" data-index="${index}" title="Löschen">×</button>
      </div>
    `;
  }

  // Setup modal event handlers
  function setupHpArrangementsModalHandlers() {
    // Close modal
    document.getElementById('hpArrangementsModalClose')?.addEventListener('click', closeHpArrangementsModal);
    document.getElementById('hpArrangementsCancel')?.addEventListener('click', closeHpArrangementsModal);

    // Save arrangements (updated for table format)
    document.getElementById('hpArrangementsSave')?.addEventListener('click', saveHpArrangementsTable);

    // Remove Add button event (not needed for table format)
    const addBtn = document.getElementById('hpArrangementsAdd');
    if (addBtn) {
      addBtn.style.display = 'none'; // Hide add button for table format
    }

    // Modal backdrop click
    const modal = document.getElementById('hpArrangementsModal');
    modal?.addEventListener('click', (e) => {
      if (e.target === modal) {
        closeHpArrangementsModal();
      }
    });
  }

  // Setup input handlers for HP arrangements table
  function setupHpTableInputHandlers() {
    const table = document.getElementById('hpArrangementsTable');
    if (!table) return;

    // Add event listeners to all quantity and remark inputs
    const quantityInputs = table.querySelectorAll('.hp-arr-quantity');
    const remarkInputs = table.querySelectorAll('.hp-arr-remark');

    [...quantityInputs, ...remarkInputs].forEach(input => {
      input.addEventListener('input', updateHpArrangementsPreview);
      input.addEventListener('change', updateHpArrangementsPreview);
    });
  }

  // Update preview showing current arrangements like in tisch-übersicht
  function updateHpArrangementsPreview() {
    const preview = document.getElementById('hpArrangementsPreview');
    if (!preview) return;

    const quantityInputs = document.querySelectorAll('.hp-arr-quantity');
    const remarkInputs = document.querySelectorAll('.hp-arr-remark');

    let previewItems = [];

    quantityInputs.forEach(qInput => {
      const arrId = qInput.dataset.arrId;
      const arrName = qInput.dataset.arrName;
      const quantity = parseInt(qInput.value) || 0;

      if (quantity > 0) {
        const remarkInput = document.querySelector(`.hp-arr-remark[data-arr-id="${arrId}"]`);
        const remark = remarkInput ? remarkInput.value.trim() : '';

        // Create preview item like in tisch-übersicht
        let itemText = '';
        if (remark) {
          itemText = `${arrName}: ${quantity}x ${remark}`;
        } else {
          itemText = `${arrName}: ${quantity}x`;
        }

        previewItems.push({
          text: itemText,
          quantity: quantity,
          remark: remark
        });
      }
    });

    // Create preview HTML with oval quantity display like tisch-übersicht
    let previewHtml = '';
    if (previewItems.length > 0) {
      previewHtml = `
        <div class="hp-preview-title">Vorschau der HP Arrangements:</div>
        <div class="hp-preview-items">
          ${previewItems.map(item => `
            <div class="hp-preview-item">
              <span class="hp-quantity-oval">${item.quantity}</span>
              <span class="hp-item-text">${item.text}</span>
            </div>
          `).join('')}
        </div>
      `;
    } else {
      previewHtml = '<div class="hp-preview-empty">Keine Arrangements eingegeben</div>';
    }

    preview.innerHTML = previewHtml;
  }

  // Save HP arrangements table data
  async function saveHpArrangementsTable() {
    const table = document.getElementById('hpArrangementsTable');
    if (!table) return;

    const saveBtn = document.getElementById('hpArrangementsSave');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Speichern...';
    }

    try {
      const arrangements = [];
      const quantityInputs = table.querySelectorAll('.hp-arr-quantity');

      quantityInputs.forEach(qInput => {
        const arrId = parseInt(qInput.dataset.arrId);
        const quantity = parseInt(qInput.value) || 0;

        if (quantity > 0) {
          const remarkInput = document.querySelector(`.hp-arr-remark[data-arr-id="${arrId}"]`);
          const remark = remarkInput ? remarkInput.value.trim() : '';

          arrangements.push({
            arr_id: arrId,
            anz: quantity,
            bem: remark
          });
        }
      });

      const response = await fetch(resApiPath('save-hp-arrangements-table.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          resId: resId,
          arrangements: arrangements
        })
      });

      const result = await response.json();

      if (result.success) {
        // Close modal
        closeHpArrangementsModal();

        // Refresh HP arrangements display in header
        if (window.loadHpArrangements) {
          window.loadHpArrangements();
        }

        console.log('HP Arrangements saved successfully:', result);

        // Show success message
        if (result.total_count > 0) {
          console.log(`${result.total_count} HP Arrangements gespeichert`);
        } else {
          console.log('Alle HP Arrangements entfernt');
        }
      } else {
        throw new Error(result.error || 'Unbekannter Fehler beim Speichern');
      }

    } catch (error) {
      console.error('Error saving HP arrangements:', error);
      alert('Fehler beim Speichern der HP Arrangements: ' + error.message);
    } finally {
      if (saveBtn) {
        saveBtn.disabled = false;
        saveBtn.textContent = 'Speichern';
      }
    }
  }

  // Handle events on modal items
  function handleModalItemEvents(e) {
    if (e.target.matches('.hp-arr-delete')) {
      const index = parseInt(e.target.dataset.index);
      removeHpArrangement(index);
    } else if (e.target.matches('.quantity-input')) {
      // Open number pad for quantity input
      openNumberPad(e.target);
    }
  }

  // Add new arrangement
  function addNewHpArrangement() {
    // Only allow adding one row if no arrangements exist yet
    if (currentHpArrangements.length === 0) {
      const newArr = {
        id: '',
        name: '',
        total_count: 1,
        details: [{ remark: '' }]
      };

      currentHpArrangements.push(newArr);

      const container = document.getElementById('hpArrangementsContainer');
      const newIndex = currentHpArrangements.length - 1;

      if (container.querySelector('.hp-arr-empty')) {
        container.innerHTML = '';
      }

      container.insertAdjacentHTML('beforeend', createHpArrangementItemHtml(newArr, newIndex, true));

      // Hide add button after adding first row when no arrangements existed
      const addBtn = document.getElementById('hpArrangementsAdd');
      if (addBtn) {
        addBtn.style.display = 'none';
      }
    } else {
      // Normal behavior for existing arrangements
      const newArr = {
        id: '',
        name: '',
        total_count: 1,
        details: [{ remark: '' }]
      };

      currentHpArrangements.push(newArr);

      const container = document.getElementById('hpArrangementsContainer');
      const newIndex = currentHpArrangements.length - 1;

      container.insertAdjacentHTML('beforeend', createHpArrangementItemHtml(newArr, newIndex, true));
    }
  }

  // Remove arrangement
  function removeHpArrangement(index) {
    if (confirm('Arrangement wirklich löschen?')) {
      currentHpArrangements.splice(index, 1);
      renderHpArrangementsModal();
    }
  }

  // Save arrangements
  async function saveHpArrangements() {
    try {
      // Collect data from modal
      const items = document.querySelectorAll('.hp-arr-item');
      const arrangements = [];

      items.forEach((item, index) => {
        const arrId = item.querySelector('[data-field="arr_id"]').value;
        const count = parseInt(item.querySelector('[data-field="count"]').value) || 1;
        const remark = item.querySelector('[data-field="remark"]').value;

        if (arrId && count > 0) {
          arrangements.push({
            arr_id: arrId,
            count: count,
            remark: remark
          });
        }
      });

      const payload = {
        res_id: resId,
        arrangements: arrangements
      };

      const response = await fetch(resApiPath('save-hp-arrangements.php'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload)
      });

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.error || 'Fehler beim Speichern');
      }

      if (result.success) {
        closeHpArrangementsModal();
        // Reload arrangements
        loadHpArrangements(resId);
      } else {
        throw new Error(result.error || 'Unbekannter Fehler');
      }

    } catch (error) {
      console.error('Error saving HP arrangements:', error);
      alert('❌ Fehler beim Speichern: ' + error.message);
    }
  }

  // Number Pad functionality
  let currentQuantityInput = null;
  let isFirstDigitEntry = true;

  // Open number pad for quantity input
  function openNumberPad(inputElement) {
    currentQuantityInput = inputElement;
    const modal = document.getElementById('numberPadModal');
    const display = document.getElementById('numberDisplay');

    if (!modal || !display) return;

    // Set current value or default to 1
    const currentValue = inputElement.value || '1';
    display.value = currentValue;

    // Reset first digit flag
    isFirstDigitEntry = true;

    modal.classList.remove('hidden');
    setupNumberPadHandlers();
  }

  // Close number pad
  function closeNumberPad() {
    const modal = document.getElementById('numberPadModal');
    modal?.classList.add('hidden');
    currentQuantityInput = null;
    isFirstDigitEntry = true;
  }

  // Setup number pad event handlers
  function setupNumberPadHandlers() {
    // Remove existing event listeners to prevent duplicates
    const modal = document.getElementById('numberPadModal');
    if (modal.hasAttribute('data-handlers-setup')) {
      return;
    }
    modal.setAttribute('data-handlers-setup', 'true');

    // Close handlers
    document.getElementById('numberPadClose')?.addEventListener('click', closeNumberPad);
    document.getElementById('numberPadCancel')?.addEventListener('click', closeNumberPad);

    // OK handler
    document.getElementById('numberPadOk')?.addEventListener('click', () => {
      const display = document.getElementById('numberDisplay');
      const value = parseInt(display.value) || 1;

      // Validate range 1-99
      if (value >= 1 && value <= 99) {
        if (currentQuantityInput) {
          currentQuantityInput.value = value;
          // Trigger change event for data sync
          const event = new Event('change', { bubbles: true });
          currentQuantityInput.dispatchEvent(event);
        }
        closeNumberPad();
      } else {
        alert('Bitte geben Sie eine Zahl zwischen 1 und 99 ein.');
      }
    });

    // Number button handlers
    modal.addEventListener('click', (e) => {
      if (e.target.matches('[data-num]')) {
        const num = e.target.dataset.num;
        addNumberToDisplay(num);
      } else if (e.target.matches('[data-action="clear"]')) {
        clearDisplay();
      } else if (e.target.matches('[data-action="backspace"]')) {
        backspaceDisplay();
      } else if (e.target === modal) {
        closeNumberPad();
      }
    });
  }

  // Add number to display
  function addNumberToDisplay(num) {
    const display = document.getElementById('numberDisplay');
    let current = display.value;

    // Clear display on first digit entry (like a calculator)
    if (isFirstDigitEntry) {
      current = '';
      isFirstDigitEntry = false;
    } else {
      // Remove leading zeros unless it's just "0"
      if (current === '0') {
        current = '';
      }
    }

    const newValue = current + num;
    const intValue = parseInt(newValue);

    // Limit to 99
    if (intValue <= 99 && intValue > 0) {
      display.value = newValue;
    } else if (newValue === '0') {
      // Don't allow starting with 0
      display.value = num;
    }
  }

  // Clear display
  function clearDisplay() {
    const display = document.getElementById('numberDisplay');
    display.value = '1';
    isFirstDigitEntry = true;
  }

  // Backspace display
  function backspaceDisplay() {
    const display = document.getElementById('numberDisplay');
    let current = display.value;

    // Mark as no longer first digit entry since user is editing
    isFirstDigitEntry = false;

    if (current.length > 1) {
      current = current.slice(0, -1);
    } else {
      current = '1';
      isFirstDigitEntry = true; // Reset flag when going back to default
    }

    display.value = current;
  }

  // Close modal
  function closeHpArrangementsModal() {
    const modal = document.getElementById('hpArrangementsModal');
    modal?.classList.add('hidden');
  }

  // Global function to open add names modal (for navigation system)
  window.openAddNamesModal = function () {
    const modal = document.getElementById('addNamesModal');
    if (modal) {
      modal.classList.remove('hidden');
      // Focus auf textarea setzen
      setTimeout(() => {
        const textarea = document.getElementById('newNamesTextarea');
        if (textarea) textarea.focus();
      }, 100);
      return true;
    }
    return false;
  };

  // === NEW NAME PREVIEW EVENT LISTENERS ===

  // Event listener for swap names button
  if (swapNamesBtn) {
    swapNamesBtn.addEventListener('click', () => {
      const temp = previewVorname.value;
      previewVorname.value = previewNachname.value;
      previewNachname.value = temp;
    });
  }

  // Event listeners for cleaning options
  if (removeLongSpaces) {
    removeLongSpaces.addEventListener('change', () => {
      // Update split visualization if slider exists
      const slider = document.getElementById('splitPositionSlider');
      const originalDisplay = document.getElementById('originalNameDisplay');
      if (slider && originalDisplay && currentNameBeingCorrected) {
        updateSplitVisualization(currentNameBeingCorrected, parseInt(slider.value));
      }
    });
  }

  if (removeSpecialChars) {
    removeSpecialChars.addEventListener('change', () => {
      // Update split visualization if slider exists
      const slider = document.getElementById('splitPositionSlider');
      const originalDisplay = document.getElementById('originalNameDisplay');
      if (slider && originalDisplay && currentNameBeingCorrected) {
        updateSplitVisualization(currentNameBeingCorrected, parseInt(slider.value));
      }
    });
  }

  if (removeNumbers) {
    removeNumbers.addEventListener('change', () => {
      // Update split visualization if slider exists
      const slider = document.getElementById('splitPositionSlider');
      const originalDisplay = document.getElementById('originalNameDisplay');
      if (slider && originalDisplay && currentNameBeingCorrected) {
        updateSplitVisualization(currentNameBeingCorrected, parseInt(slider.value));
      }
    });
  }

  // Event listener for apply preview button
  if (applyPreviewBtn) {
    applyPreviewBtn.addEventListener('click', () => {
      // Set custom fields with preview values
      if (customVorname && customNachname) {
        customVorname.value = previewVorname.value;
        customNachname.value = previewNachname.value;

        // Trigger the existing apply logic
        const applyBtn = document.getElementById('nameCorrectionApply');
        if (applyBtn) {
          applyBtn.click();
        }
      }
    });
  }

});
