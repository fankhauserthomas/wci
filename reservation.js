document.addEventListener('DOMContentLoaded', () => {
  const resId = new URLSearchParams(location.search).get('id');
  const highlightName = new URLSearchParams(location.search).get('highlight');
  const source = new URLSearchParams(location.search).get('source');
  let currentReservationData = null; // Global variable to store current reservation data
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

  // 1) Load reservation header + Zimmerliste
  const loadReservationData = async () => {
    try {
      const data = await (window.HttpUtils
        ? HttpUtils.requestJsonWithLoading(`getReservationDetails.php?id=${resId}`, {}, { retries: 3, timeout: 12000 }, 'Reservierungsdetails werden geladen...')
        : window.LoadingOverlay
          ? LoadingOverlay.wrapFetch(() => fetch(`getReservationDetails.php?id=${resId}`).then(r => r.json()), 'Reservierungsdetails')
          : fetch(`getReservationDetails.php?id=${resId}`).then(r => r.json())
      );

      // Store reservation data globally
      currentReservationData = data;

      // Header-Farbe basierend auf Invoice-Status setzen
      const headerElement = document.getElementById('resHeader');
      if (headerElement) {
        // je nach Rückgabe-Format entweder data.names[0] oder direkt data
        // neu: wenn data.detail existiert, nimm das, sonst fall auf altes Verhalten zurück
        const detail = data.detail
          ? data.detail
          : (Array.isArray(data.names) && data.names.length
            ? data.names[0]
            : data);

        // Debug: Log invoice status
        console.log('Debug - Invoice Status:', detail.invoice, typeof detail.invoice);
        console.log('Debug - Complete detail object:', detail);

        // Header-Farbe basierend auf Invoice-Status - über CSS-Variable
        if (detail.invoice === true || detail.invoice === 1 || detail.invoice === '1') {
          // Dunkelgold für Invoice=true - über CSS-Variable
          document.documentElement.style.setProperty('--res-header-bg', '#b8860b');
          console.log('✅ Header set to DARK GOLD (invoice=true) via CSS variable');
        } else {
          // Normalgrün (etwas dunkler) für Invoice=false/null - über CSS-Variable
          document.documentElement.style.setProperty('--res-header-bg', '#2d8f4f');
          console.log('✅ Header set to DARK GREEN (invoice=false/null) via CSS variable');
        }
      }

      // Check for name correction needs
      setTimeout(() => {
        checkNameCorrection(detail);
      }, 500);

      // je nach Rückgabe-Format entweder data.names[0] oder direkt data
      // neu: wenn data.detail existiert, nimm das, sonst fall auf altes Verhalten zurück
      const detail = data.detail
        ? data.detail
        : (Array.isArray(data.names) && data.names.length
          ? data.names[0]
          : data);

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

      const response = await fetch('updateReservationNames.php', {
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
        ? HttpUtils.requestJsonWithLoading(`getReservationNames.php?id=${resId}`, {}, { retries: 3, timeout: 10000 }, 'Namensliste wird geladen...')
        : window.LoadingOverlay
          ? LoadingOverlay.wrapFetch(() => fetch(`getReservationNames.php?id=${resId}`).then(r => r.json()), 'Namensliste')
          : fetch(`getReservationNames.php?id=${resId}`).then(r => r.json())
      );

      // Automatisch Namen erstellen wenn Namensliste leer ist
      if (list.length === 0 && currentReservationData) {
        console.log('Namensliste ist leer - erstelle automatisch einen Namen');
        const autoCreated = await createAutoName();

        // Nur wenn automatische Erstellung erfolgreich war, die neue Liste laden
        if (autoCreated) {
          const newList = await fetch(`getReservationNames.php?id=${resId}`).then(r => r.json());
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
    list.forEach(n => {
      // Debug: Log die ersten paar Check-in/Check-out Werte
      if (list.indexOf(n) < 2) {
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
        detailIcons += `<img src="./pic/luggage.svg" alt="Transport" class="detail-icon" title="Transport: ${n.transport}€">`;
      }
      if (n.dietInfo && n.dietInfo.trim() !== '') {
        detailIcons += `<img src="./pic/food.svg" alt="Diät Info" class="detail-icon" title="Info Küche: ${n.dietInfo}">`;
      }
      if (n.bem && n.bem.trim() !== '') {
        detailIcons += `<img src="./pic/info.svg" alt="Bemerkung" class="detail-icon" title="Bemerkung: ${n.bem}">`;
      }
      if (detailIcons === '') {
        detailIcons = `<img src="./pic/dots.svg" alt="Details" class="detail-icon">`;
      }

      tr.innerHTML = `
        <td><input type="checkbox" class="rowCheckbox"></td>
        <td class="name-cell">${n.nachname || ''} ${n.vorname || ''}</td>
        <td class="detail-cell" style="cursor:pointer; text-align: center;">${detailIcons}</td>
        <td>${n.alter_bez || ''}</td>
        <td class="bem-cell">${n.bem || ''}</td>
        <td class="guide-cell">
          <span class="guide-icon">${n.guide ? '✓' : '○'}</span>
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
          : `<img src="./pic/notyet.svg" alt="Not yet" class="notyet-icon">`}
        </td>
        <td class="checkout-cell ${n.checked_out ? 'checked-out' : ''}">
          ${n.checked_out
          ? fmtDateTime(n.checked_out)
          : `<img src="./pic/notyet.svg" alt="Not yet" class="notyet-icon">`}
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
    bindCheckboxes();
    updateBulkButtonStates(); // Initialize button states
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

      const response = await fetch('addReservationNames.php', {
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
    const boxes = Array.from(document.querySelectorAll('.rowCheckbox'));
    const checked = boxes.filter(b => b.checked).length;
    selectAll.checked = checked === boxes.length;
    selectAll.indeterminate = checked > 0 && checked < boxes.length;

    // Update bulk button states
    updateBulkButtonStates();
  }
  selectAll.addEventListener('change', () => {
    const val = selectAll.checked;
    document.querySelectorAll('.rowCheckbox').forEach(cb => cb.checked = val);
    updateBulkButtonStates();
  });

  // 3.5) Bulk Check-in/Check-out Toggle Funktionalität
  const bulkCheckinBtn = document.getElementById('bulkCheckinBtn');
  const bulkCheckoutBtn = document.getElementById('bulkCheckoutBtn');

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
          url: 'updateReservationNamesCheckin.php',
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
              cell.innerHTML = `<img src="./pic/notyet.svg" class="notyet-icon">`;
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
            fetch('updateReservationNamesCheckin.php', {
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
                cell.innerHTML = `<img src="./pic/notyet.svg" class="notyet-icon">`;
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
          url: 'updateReservationNamesCheckout.php',
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
              cell.innerHTML = `<img src="./pic/notyet.svg" class="notyet-icon">`;
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
            fetch('updateReservationNamesCheckout.php', {
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
                cell.innerHTML = `<img src="./pic/notyet.svg" class="notyet-icon">`;
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

  // Initial state
  updateBulkButtonStates();

  // 4) Inline handlers: guide / arrangement / diet / checkin / checkout
  tbody.addEventListener('click', e => {
    const row = e.target.closest('tr');
    if (!row) return;
    const id = row.dataset.id;

    // Detail-Click auf Herkunft/Detail-Spalte
    const detailCell = e.target.closest('td.detail-cell');
    if (detailCell) {
      const id = row.dataset.id;
      window.location.href = `GastDetail.html?id=${id}`;
      return;
    }

    // Name-Click - öffne GastDetail
    const nameCell = e.target.closest('td.name-cell');
    if (nameCell) {
      const id = row.dataset.id;
      window.location.href = `GastDetail.html?id=${id}`;
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
        const response = await fetch('toggleGuideFlag.php', {
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
      fetch('toggleNoShow.php', {
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
          const response = await fetch('updateReservationNamesCheckin.php', {
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
          const response = await fetch('updateReservationNamesCheckin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'clear' })
          });
          const j = await response.json();

          if (j.success) {
            cell.classList.remove('checked-in');
            cell.innerHTML = `<img src="./pic/notyet.svg" class="notyet-icon">`;
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
          const response = await fetch('updateReservationNamesCheckout.php', {
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
          const response = await fetch('updateReservationNamesCheckout.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action: 'clear' })
          });
          const j = await response.json();

          if (j.success) {
            cell.classList.remove('checked-out');
            cell.innerHTML = `<img src="./pic/notyet.svg" class="notyet-icon">`;
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

    fetch('deleteReservationNames.php', {
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

    fetch('GetCardPrinters.php')
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
    backdrop.appendChild(content);

    const close = document.createElement('span');
    close.className = 'modal-close';
    close.innerHTML = '&times;';
    content.appendChild(close);

    const title = document.createElement('h2');
    title.textContent = 'Drucker wählen';
    content.appendChild(title);

    printers.forEach(p => {
      const btn = document.createElement('button');
      btn.textContent = p.bez;

      // Erweiterte Styles für bessere Touch-Bedienung
      btn.style.cssText = `
        width: 100%;
        height: 60px;
        margin: 8px 0;
        padding: 15px 20px;
        font-size: 16px;
        font-weight: 500;
        background: #28a745;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        line-height: 1.2;
      `;

      // Hover-Effekt hinzufügen
      btn.addEventListener('mouseenter', () => {
        btn.style.backgroundColor = '#218838';
      });
      btn.addEventListener('mouseleave', () => {
        btn.style.backgroundColor = '#28a745';
      });

      btn.addEventListener('click', () => {
        document.body.removeChild(backdrop);
        const q = ids.map(i => `id[]=${i}`).join('&');
        const url = `printSelected.php?printer=${encodeURIComponent(p.kbez)}&resId=${encodeURIComponent(resId)}&${q}`;
        // Im selben Tab darauf navigieren:
        window.location.href = url;
      });
      content.appendChild(btn);
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

    fetch('getArrangements.php')
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
    fetch('updateReservationNamesArrangement.php', {
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
          fetch('getArrangements.php').then(r => {
            if (!r.ok) throw new Error(`HTTP ${r.status}`);
            return r.json();
          }), 'Arrangements'
        ) :
        fetch('getArrangements.php').then(r => {
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
          url: 'updateReservationNamesArrangement.php',
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
            fetch('updateReservationNamesArrangement.php', {
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

    fetch('getDiets.php')
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
    fetch('updateReservationNamesDiet.php', {
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

    fetch('addReservationNames.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: resId, entries })
    })
      .then(r => r.json())
      .then(j => {
        if (j.success) {
          newArea.value = '';
          document.getElementById('addNamesDetails').removeAttribute('open');
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
    fetch(`toggleStorno.php`, {
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

    fetch('toggleStorno.php', {
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

  // Wenn der Benutzer von GastDetail.html zurückkommt, automatisch die Namensliste aktualisieren
  window.addEventListener('pageshow', (event) => {
    // event.persisted = true bedeutet die Seite kam aus dem Browser Cache (history.back())
    if (event.persisted) {
      console.log('Page shown from cache - refreshing names list');
      debounceLoadNames('pageshow-cache');
    }
  });

  // === Focus Event für zusätzliche Sicherheit ===
  // Falls pageshow nicht funktioniert, zusätzlich auf Focus-Event hören
  window.addEventListener('focus', () => {
    // Prüfe ob die Seite in den letzten 2 Sekunden im Hintergrund war
    if (document.hidden === false) {
      console.log('Window focused - refreshing names list');
      debounceLoadNames('window-focus');
    }
  });

  // === Delete Complete Reservation Function ===
  function deleteCompleteReservation() {
    // Show loading overlay if available
    if (window.LoadingOverlay) {
      LoadingOverlay.show('Reservierung wird gelöscht...');
    }

    fetch('deleteReservation.php', {
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

  // === Inaktivitäts-Timer ===
  let inactivityTimeout;
  function resetInactivityTimer() {
    clearTimeout(inactivityTimeout);
    inactivityTimeout = setTimeout(() => {
      history.back();
    }, 30_000); // 30 Sekunden
  }
  ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(evt =>
    document.addEventListener(evt, resetInactivityTimer, { passive: true })
  );
  // starte direkt den Timer
  resetInactivityTimer();

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

});
