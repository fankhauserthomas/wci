// Debug-Konsole
function debugLog(message) {
    const debugConsole = document.getElementById('debug-console');
    if (debugConsole) {
        const timestamp = new Date().toLocaleTimeString();
        debugConsole.innerHTML += `[${timestamp}] ${message}<br>`;
        debugConsole.scrollTop = debugConsole.scrollHeight;
    }
    console.log(message);
}

// Timeline Script - 4 Bereiche mit zentralem Scrollbar
let reservations = [];
let roomDetails = []; // Neue Variable für Zimmer-Details
let rooms = []; // Zimmer aus zb_zimmer Tabelle
let DAY_WIDTH = 80; // Breite pro Tag in Pixeln (jetzt variabel für Zoom)
const VERTICAL_GAP = 1; // Gap zwischen Balken in Pixeln (reduziert auf 1)

// Setze globale CSS-Variable für Tagesbreite
function setDayWidthCSSVar() {
    document.documentElement.style.setProperty('--day-width', DAY_WIDTH + 'px');
}
setDayWidthCSSVar();

// Zimmer aus Datenbank laden
async function loadRooms() {
    try {
        const response = await fetch('getRooms.php');
        const result = await response.json();
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        if (!result.success) {
            throw new Error(result.error || 'Fehler beim Laden der Zimmer');
        }
        
        rooms = result.data;
        debugLog(`${rooms.length} Zimmer geladen: ${JSON.stringify(rooms)}`);
        
        return rooms;
        
    } catch (error) {
        console.error('Fehler beim Laden der Zimmer:', error);
        debugLog('Fehler beim Laden der Zimmer: ' + error.message);
        // Fallback: Demo-Zimmer
        rooms = [
            { id: 1, caption: 'Zimmer 1', capacity: 2, sort: 1, display_name: 'Zimmer 1 (2)' },
            { id: 2, caption: 'Zimmer 2', capacity: 4, sort: 2, display_name: 'Zimmer 2 (4)' },
            { id: 3, caption: 'Zimmer 3', capacity: 3, sort: 3, display_name: 'Zimmer 3 (3)' }
        ];
        return rooms;
    }
}

// Echte Daten laden mit robuster HTTP-Behandlung
async function loadRealData() {
    try {
        // Hole Datumswerte aus den Eingabefeldern
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        
        if (!startDate || !endDate) {
            alert('Bitte wählen Sie einen Start- und End-Datum aus!');
            return;
        }
        
        if (new Date(startDate) > new Date(endDate)) {
            alert('Start-Datum muss vor End-Datum liegen!');
            return;
        }
        
        console.log(`Loading data from ${startDate} to ${endDate}...`);

        // Loading-Anzeige
        const loadBtn = document.querySelector('button[onclick="loadRealData()"]');
        const originalText = loadBtn.textContent;
        loadBtn.disabled = true;
        loadBtn.textContent = 'Lade Daten...';

        // Verwende robuste HTTP-Utility wenn verfügbar
        const url = `getZimmerplanData.php?start=${startDate}&end=${endDate}`;
        const result = await (window.HttpUtils 
          ? HttpUtils.requestJson(url, {}, {
              retries: 5,
              retryDelay: 1000,
              timeout: 20000, // Längerer Timeout für große Datensätze
              backoffMultiplier: 1.8
            })
          : fetch(url).then(response => response.json())
        );
        
        if (!result.success) {
            throw new Error(result.error || 'API-Fehler');
        }
        
        console.log(`API returned ${result.data.timeline_items.length} items and ${result.data.room_details ? result.data.room_details.length : 0} room details`);
        
        // Konvertiere API-Daten
        reservations = result.data.timeline_items
            .filter(item => item.content && item.start && item.end)
            .map((item, index) => ({
                id: item.id || index,
                name: item.data ? item.data.guest_name : item.content.replace(/<[^>]*>/g, ''), // Text ohne HTML
                nachname: item.data ? item.data.guest_name.split(' ')[0] : '',
                vorname: item.data ? item.data.guest_name.split(' ').slice(1).join(' ') : '',
                capacity: item.data ? item.data.capacity : (item.capacity || 1),
                arrangement: item.data ? item.data.arrangement : '',
                start: parseGermanDate(item.start),
                end: parseGermanDate(item.end),
                data: item.data || {}, // WICHTIG: Vollständige data-Objekt übertragen für is_disposed Flag
                fullData: item.data // Für Tooltip-Details
            }));
        
        // Zimmer-Details speichern
        roomDetails = result.data.room_details 
            ? result.data.room_details
                .filter(item => item.room_id && item.start && item.end)
                .map(item => ({
                    id: item.id,
                    room_id: item.room_id,
                    guest_name: item.data ? item.data.guest_name : 'Unbekannt',
                    start: parseGermanDate(item.start),
                    end: parseGermanDate(item.end),
                    color: item.data ? item.data.color : '#3498db',
                    has_dog: item.data ? item.data.has_dog : false,
                    arrangement: item.data ? item.data.arrangement : '',
                    capacity: item.data ? item.data.capacity : 1,
                    caption: item.data ? item.data.caption : '',
                    title: item.title || '',
                    data: item.data || {}
                }))
            : [];
        
        console.log(`Found ${reservations.length} valid reservations and ${roomDetails.length} room details`);
        
        // Debug: Check disposition flags
        if (reservations.length > 0) {
            console.log('Sample reservations with disposition flags:', reservations.slice(0, 3).map(r => ({
                name: r.name,
                is_disposed: r.data ? r.data.is_disposed : 'no data',
                data_keys: r.data ? Object.keys(r.data) : 'no data'
            })));
            
            const disposedCount = reservations.filter(r => r.data && r.data.is_disposed).length;
            const undisposedCount = reservations.filter(r => r.data && !r.data.is_disposed).length;
            console.log(`Disposition breakdown: ${disposedCount} disposed, ${undisposedCount} undisposed`);
        }
        
        // Debug: Erste paar Zimmer-Details ausgeben
        if (roomDetails.length > 0) {
            console.log('Sample room details:', roomDetails.slice(0, 3));
        } else {
            console.warn('WARNUNG: Keine roomDetails gefunden!');
        }
        
        // Zimmer laden und dann Timeline rendern
        await loadRooms();
        renderTimeline();
        
    } catch (error) {
        console.error('Fehler beim Laden der echten Daten:', error);
        alert('Fehler beim Laden der echten Daten: ' + error.message);
    }
}

// Robuste Datums-Parser-Funktion
function parseGermanDate(dateString) {
    if (!dateString) return new Date();
    
    dateString = dateString.trim();
    
    if (/^\d{4}-\d{2}-\d{2}/.test(dateString)) {
        return new Date(dateString);
    }
    
    if (/^\d{1,2}\.\d{1,2}\.\d{4}/.test(dateString)) {
        const parts = dateString.split('.');
        if (parts.length >= 3) {
            const day = parseInt(parts[0], 10);
            const month = parseInt(parts[1], 10) - 1;
            const year = parseInt(parts[2], 10);
            return new Date(year, month, day, 12, 0, 0, 0);
        }
    }
    
    return new Date(dateString);
}

// Timeline rendern - alle 4 Bereiche synchron
function renderTimeline() {
    setDayWidthCSSVar(); // CSS-Variable immer vor jedem Render setzen
    if (reservations.length === 0) {
        // Zeige leere Timeline in allen Bereichen
        const masterContent = document.getElementById('timeline-content-master');
        const histogramContent = document.getElementById('timeline-content-histogram');
        masterContent.innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">Keine Reservierungen im gewählten Zeitraum</div>';
        histogramContent.innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">Keine Daten für Histogramm</div>';
        document.getElementById('row-label-text').textContent = 'Alle (0)';
        return;
    }
    // Bestimme Datum-Bereich
    const startDate = new Date(Math.min(...reservations.map(r => r.start.getTime())));
    const endDate = new Date(Math.max(...reservations.map(r => r.end.getTime())));
    // 1. Erstelle Datum-Header (für alle Bereiche synchron)
    renderDateHeader(startDate, endDate);
    // 2. Rendere Master-Reservierungen (alle Datensätze gestapelt)
    renderMasterReservations(startDate, endDate);
    // 3. Rendere Zimmerzuteilung
    renderRoomAssignments(startDate, endDate);
    // 4. Rendere Auslastungshistogramm
    renderHistogram(startDate, endDate);
    // 5. Synchronisiere Scroll zwischen allen Timeline-Bereichen
    setupScrollSynchronization();
    // 6. Aktualisiere Timeline-Content-Höhen nach initialem Rendering
    setTimeout(() => {
        updateTimelineContentHeights();
    }, 100);
    // Update Label
    const filteredCount = showDisposedReservations ? 
        reservations.length : 
        reservations.filter(r => !(r.data && r.data.is_disposed)).length;
    document.getElementById('row-label-text').textContent = `Alle (${filteredCount}${showDisposedReservations ? '' : ' ungefiltert'})`;
}

// 1. Datum-Header rendern
function renderDateHeader(startDate, endDate) {
    const datesContainer = document.getElementById('timeline-dates');
    datesContainer.innerHTML = '';
    
    const currentDate = new Date(startDate);
    const dates = [];
    
    while (currentDate <= endDate) {
        dates.push(new Date(currentDate));
        
        const dateDiv = document.createElement('div');
        dateDiv.className = 'date-column';
        dateDiv.style.width = DAY_WIDTH + 'px';
        dateDiv.style.minWidth = DAY_WIDTH + 'px';
        dateDiv.style.maxWidth = DAY_WIDTH + 'px';
        dateDiv.style.flex = 'none';
        dateDiv.style.boxSizing = 'border-box';
        dateDiv.style.padding = '0';
        dateDiv.style.margin = '0';
        dateDiv.innerHTML = `
            <div>${currentDate.toLocaleDateString('de-DE', {weekday: 'short'})}</div>
            <div>${currentDate.toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit'})}</div>
        `;
        datesContainer.appendChild(dateDiv);
        
        currentDate.setDate(currentDate.getDate() + 1);
    }
    
    // Setze Breite für alle Timeline-Bereiche (exakt gleich)
    const totalWidth = dates.length * DAY_WIDTH;
    datesContainer.style.width = totalWidth + 'px';
    datesContainer.style.minWidth = totalWidth + 'px';
    datesContainer.style.maxWidth = totalWidth + 'px';
    datesContainer.style.boxSizing = 'border-box';
    datesContainer.style.padding = '0';
    datesContainer.style.margin = '0';
    
    // Update Gitternetzlinien für alle Timeline-Content Bereiche
    updateGridLines(totalWidth);
    
    return dates;
}

// 2. Master-Reservierungen rendern (alle Datensätze gestapelt)
function renderMasterReservations(startDate, endDate) {
    const contentContainer = document.getElementById('timeline-content-master');
    contentContainer.innerHTML = '';
    
    const totalWidth = ((endDate - startDate) / (1000 * 60 * 60 * 24) + 1) * DAY_WIDTH;
    contentContainer.style.width = totalWidth + 'px';
    
    // Filter reservations based on disposition status
    const filteredReservations = reservations.filter(reservation => {
        if (!showDisposedReservations && reservation.data && reservation.data.is_disposed) {
            return false; // Hide disposed reservations if setting is off
        }
        return true;
    });
    
    console.log(`Showing ${filteredReservations.length} of ${reservations.length} master reservations (disposed filter: ${!showDisposedReservations})`);
    
    // Stack-Algorithmus mit Überlappungs-Toleranz
    const stackLevels = [];
    const OVERLAP_TOLERANCE = DAY_WIDTH * 0.25; // 25% eines Tages = ~6 Stunden Überlappung erlaubt
    
    // Sortiere Reservierungen nach Start-Zeit für besseres Stacking
    const sortedReservations = [...filteredReservations].sort((a, b) => {
        return new Date(a.start).getTime() - new Date(b.start).getTime();
    });
    
    sortedReservations.forEach(reservation => {
        // SYMBOLISCHE Darstellung: Mittag zu Mittag für Nächtigungen
        const checkinDate = new Date(reservation.start);
        checkinDate.setHours(12, 0, 0, 0); // Anreisetag 12:00 (symbolisch)
        
        const checkoutDate = new Date(reservation.end);
        checkoutDate.setHours(12, 0, 0, 0); // Abreisetag 12:00 (symbolisch)

        const timelineStart = new Date(startDate);
        timelineStart.setHours(0, 0, 0, 0); // Timeline startet um Mitternacht

        // Berechne Position und Breite
        const startOffset = (checkinDate.getTime() - timelineStart.getTime()) / (1000 * 60 * 60 * 24);
        const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

        // DARSTELLUNGS-ANPASSUNG: Kleine Lücken für bessere Optik
        const left = (startOffset + 0.1) * DAY_WIDTH; // 0.1 Tag = 2.4h Abstand vom Tagesanfang
        const width = (duration - 0.2) * DAY_WIDTH;   // 0.2 Tag = 4.8h kürzer (je 2.4h links/rechts)

        // NEUE LOGIK: Finde freie Stack-Ebene mit Überlappungs-Toleranz
        let stackLevel = 0;
        while (stackLevel < stackLevels.length && 
               stackLevels[stackLevel] > left + OVERLAP_TOLERANCE) {
            stackLevel++;
        }
        
        // Stelle sicher, dass das Array groß genug ist
        while (stackLevels.length <= stackLevel) {
            stackLevels.push(0);
        }
        
        // Speichere das Ende dieses Balkens (ohne Toleranz-Abzug)
        stackLevels[stackLevel] = left + width + 5;

        const top = 10 + (stackLevel * (18 + VERTICAL_GAP));

        // Erstelle Master-Balken
        const bar = createReservationBar(reservation, left, width, top, checkinDate, checkoutDate, duration);
        contentContainer.appendChild(bar);
    });
}

// 3. Zimmerzuteilung rendern (aus zb_zimmer Tabelle)
async function renderRoomAssignments(startDate, endDate) {
    console.log('renderRoomAssignments: Rendering', roomDetails.length, 'reservations for', rooms.length, 'rooms');
    
    const roomContainer = document.getElementById('room-rows-container');
    if (!roomContainer) {
        console.error('FEHLER: room-rows-container Element nicht gefunden!');
        return;
    }
    
    roomContainer.innerHTML = '';
    
    // Lade Zimmer falls noch nicht geladen
    if (rooms.length === 0) {
        await loadRooms();
    }
    
    const totalWidth = ((endDate - startDate) / (1000 * 60 * 60 * 24) + 1) * DAY_WIDTH;
    
    // Erstelle Zimmer-Labels im Header-Bereich
    renderRoomLabels();
    
    // Array zum Sammeln der Zimmer-Höhen für spätere Label-Anpassung
    const roomHeights = [];
    
    // Erstelle Zeile für jedes Zimmer
    let processedRoomsCount = 0;
    let totalBarsCreated = 0;
    
    rooms.forEach((room, roomIndex) => {
        const roomRow = createRoomRow(room, startDate, endDate, totalWidth);
        roomContainer.appendChild(roomRow);
        processedRoomsCount++;
        
        // Zimmer-Details für dieses Zimmer finden - robuste ID-Matching-Strategie
        const roomReservationsExact = roomDetails.filter(detail => detail.room_id === room.id);
        const roomReservationsString = roomDetails.filter(detail => String(detail.room_id) === String(room.id));
        const roomReservationsNumber = roomDetails.filter(detail => Number(detail.room_id) === Number(room.id));
        
        // Verwende die beste Matching-Strategie
        let roomReservations = roomReservationsExact;
        if (roomReservations.length === 0 && roomReservationsString.length > 0) {
            roomReservations = roomReservationsString;
        } else if (roomReservations.length === 0 && roomReservationsNumber.length > 0) {
            roomReservations = roomReservationsNumber;
        }
        
        const timelineContent = roomRow.querySelector('.room-timeline-content');
        if (!timelineContent) {
            console.error(`FEHLER: .room-timeline-content nicht gefunden für Zimmer ${room.id}`);
            return;
        }
        
        // STACKING ALGORITHM: Erkenne überlappende Reservierungen
        if (roomReservations.length > 0) {
            // Sortiere Reservierungen nach Startzeit für besseres Stacking
            const sortedRoomReservations = [...roomReservations].sort((a, b) => {
                return new Date(a.start).getTime() - new Date(b.start).getTime();
            });
            
            // Stack-Level Tracking für diese Zimmerzeile
            const roomStackLevels = [];
            const maxStackLevel = [];
            const OVERLAP_TOLERANCE = DAY_WIDTH * 0.25; // 25% eines Tages = ~6 Stunden
            
            sortedRoomReservations.forEach((detail, detailIndex) => {
                // Berechne Position dieser Reservierung
                const checkinDate = new Date(detail.start);
                checkinDate.setHours(12, 0, 0, 0);
                const checkoutDate = new Date(detail.end);
                checkoutDate.setHours(12, 0, 0, 0);
                
                const timelineStart = new Date(startDate);
                timelineStart.setHours(0, 0, 0, 0);
                
                const startOffset = (checkinDate.getTime() - timelineStart.getTime()) / (1000 * 60 * 60 * 24);
                const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);
                const left = (startOffset + 0.1) * DAY_WIDTH;
                const width = (duration - 0.2) * DAY_WIDTH;
                
                // Finde freie Stack-Ebene
                let stackLevel = 0;
                while (stackLevel < roomStackLevels.length && 
                       roomStackLevels[stackLevel] > left + OVERLAP_TOLERANCE) {
                    stackLevel++;
                }
                
                // Stelle sicher, dass das Array groß genug ist
                while (roomStackLevels.length <= stackLevel) {
                    roomStackLevels.push(0);
                }
                
                // Speichere das Ende dieser Reservierung
                roomStackLevels[stackLevel] = left + width + 5;
                maxStackLevel.push(stackLevel);
                
                try {
                    const roomBar = createRoomReservationBar(detail, startDate, stackLevel);
                    if (roomBar) {
                        timelineContent.appendChild(roomBar);
                        totalBarsCreated++;
                    } else {
                        console.error(`Fehler beim Erstellen der Bar für ${detail.guest_name}`);
                    }
                } catch (error) {
                    console.error(`Fehler beim Erstellen der Bar für ${detail.guest_name}:`, error);
                }
            });
            
            // Setze Zimmerzeilenhöhe basierend auf dem maximalen Stack Level
            const maxLevel = Math.max(...maxStackLevel, 0);
            const roomRowHeight = Math.max(16, 16 + (maxLevel * 17)); // 17px pro Level (14px Bar + 1px Gap + 2px Padding)
            roomRow.style.height = roomRowHeight + 'px';
            timelineContent.style.height = roomRowHeight + 'px';
            
            // Speichere Höhe für spätere Label-Anpassung
            roomHeights[roomIndex] = roomRowHeight;
        } else {
            // Zimmer ohne Reservierungen - Standard-Höhe
            roomHeights[roomIndex] = 16;
        }
    });

    // Passe alle Zimmer-Labels an die berechneten Höhen an
    updateRoomLabelHeights(roomHeights);
    
    // Verwende zusätzlich die robuste direkte Synchronisation
    setTimeout(() => {
        synchronizeRoomLabelHeights();
    }, 100);
    
    // Zusätzlicher Fallback nach einem längeren Timeout für robustere Synchronisation
    setTimeout(() => {
        console.log('Final fallback room label height update after 300ms');
        synchronizeRoomLabelHeights();
    }, 300);

    console.log(`renderRoomAssignments: Processed ${processedRoomsCount} rooms, created ${totalBarsCreated} reservation bars`);

    // Füge Layer (Tages-/Wochenendhintergründe) jetzt auch in die neuen .room-timeline-content ein
    updateGridLines(totalWidth);
}

// Render room labels in the sidebar
function renderRoomLabels() {
    const roomLabelsContainer = document.querySelector('.room-labels-column');
    if (!roomLabelsContainer) {
        console.warn('Room labels container not found');
        return;
    }
    
    roomLabelsContainer.innerHTML = '';
    
    rooms.forEach(room => {
        const labelDiv = document.createElement('div');
        labelDiv.className = 'room-label-item';
        labelDiv.textContent = room.display_name || room.caption || `Zimmer ${room.id}`;
        labelDiv.title = `${room.caption} (Kapazität: ${room.capacity || 'Unbekannt'})`;
        roomLabelsContainer.appendChild(labelDiv);
    });
    
    console.log(`Rendered ${rooms.length} room labels`);
}

function createRoomRow(room, startDate, endDate, totalWidth) {
    const rowDiv = document.createElement('div');
    rowDiv.className = 'timeline-row room-row';
    rowDiv.style.height = '16px'; // Initial-Höhe, wird später angepasst
    rowDiv.style.margin = '0';
    rowDiv.style.padding = '0';
    rowDiv.style.position = 'relative'; // Für absolute positioning der Reservierungen
    rowDiv.style.minHeight = '16px'; // Mindesthöhe
    
    // Timeline-Content für dieses Zimmer (OHNE eigenen Scrollbar, nimmt die volle Breite)
    const contentDiv = document.createElement('div');
    contentDiv.className = 'room-timeline-content';
    contentDiv.style.width = '100%'; // Volle Breite, da kein Label mehr links
    contentDiv.style.height = '16px'; // Initial-Höhe, wird später angepasst
    contentDiv.style.margin = '0';
    contentDiv.style.padding = '0';
    contentDiv.style.position = 'relative'; // Für absolute positioning der Reservierungen
    contentDiv.style.minHeight = '16px'; // Mindesthöhe
    contentDiv.style.overflow = 'visible'; // Wichtig für gestapelte Balken
    
    rowDiv.appendChild(contentDiv);
    return rowDiv;
}

// Neue Funktion: Zimmer-Reservierungs-Balken erstellen - DEBUG VERSION mit Stapelung
function createRoomReservationBar(detail, startDate, stackLevel = 0) {
    if (!detail || !detail.start || !detail.end) {
        console.error('FEHLER: Ungültige detail-Daten:', detail);
        return null;
    }
    
    const von = new Date(detail.start);
    const bis = new Date(detail.end);
    
    // SYMBOLISCHE Darstellung: Mittag zu Mittag
    von.setHours(12, 0, 0, 0);
    bis.setHours(12, 0, 0, 0);
    
    const timelineStart = new Date(startDate);
    timelineStart.setHours(0, 0, 0, 0);
    
    // Berechne Position und Breite
    const startOffset = (von.getTime() - timelineStart.getTime()) / (1000 * 60 * 60 * 24);
    const duration = (bis.getTime() - von.getTime()) / (1000 * 60 * 60 * 24);
    
    // Prüfe ob die Reservierung im sichtbaren Bereich liegt
    if (startOffset + duration < 0) {
        return null;
    }
    
    // DARSTELLUNGS-ANPASSUNG: Kleine Lücken wie bei Master-Reservierungen
    const left = (startOffset + 0.1) * DAY_WIDTH;
    const width = (duration - 0.2) * DAY_WIDTH;
    
    // Berechne vertikale Position basierend auf Stack-Level
    const barHeight = 14; // Höhe eines einzelnen Balkens
    const stackGap = 1; // Abstand zwischen gestapelten Balken (reduziert auf 1)
    const topPosition = 1 + (stackLevel * (barHeight + stackGap));
    
    if (width <= 0) {
        return null;
    }
    
    // Erstelle Zimmer-Reservierungs-Balken
    const bar = document.createElement('div');
    bar.className = 'room-reservation-bar';
    bar.style.position = 'absolute';
    bar.style.left = left + 'px';
    bar.style.width = width + 'px';
    bar.style.top = topPosition + 'px';
    bar.style.height = barHeight + 'px';
    bar.style.backgroundColor = detail.color || '#3498db';
    bar.style.border = '1px solid rgba(0,0,0,0.1)';
    bar.style.borderRadius = '2px';
    bar.style.fontSize = '9px';
    bar.style.color = '#fff';
    bar.style.padding = '1px 2px';
    bar.style.overflow = 'hidden';
    bar.style.whiteSpace = 'nowrap';
    bar.style.textOverflow = 'ellipsis';
    bar.style.boxSizing = 'border-box';
    bar.style.zIndex = (10 + stackLevel).toString(); // Höhere Stack-Level haben höhere z-index
    bar.style.cursor = 'pointer';
    
    // Inhalt: Gast + Arrangement + Hund
    let content = detail.guest_name;
    if (detail.arrangement) {
        content += ' (' + detail.arrangement + ')';
    }
    if (detail.has_dog) {
        content += ' 🐕';
    }
    
    bar.textContent = content;
    bar.title = `${detail.guest_name}\nZimmer: ${detail.data && detail.data.room_name ? detail.data.room_name : 'Unbekannt'}\nAufenthalt: ${von.toLocaleDateString('de-DE')} - ${bis.toLocaleDateString('de-DE')}\nAnzahl: ${detail.capacity}\nArrangement: ${detail.arrangement || 'Nicht zugewiesen'}${detail.has_dog ? '\nMit Hund' : ''}${stackLevel > 0 ? '\nStapel-Level: ' + (stackLevel + 1) : ''}`;
    
    // Click-Event für Details
    bar.addEventListener('click', function(e) {
        e.stopPropagation();
        alert(`Zimmer-Details:\n${bar.title}`);
    });
    
    // Hover-Effekt - verstärkt für gestapelte Balken
    bar.addEventListener('mouseover', function() {
        bar.style.transform = 'scale(1.02)';
        bar.style.boxShadow = '0 2px 4px rgba(0,0,0,0.3)';
        bar.style.zIndex = (20 + stackLevel).toString();
    });
    
    bar.addEventListener('mouseout', function() {
        bar.style.transform = 'scale(1)';
        bar.style.boxShadow = 'none';
        bar.style.zIndex = (10 + stackLevel).toString();
    });
    
    return bar;
}

// Hilfsfunktion: Kapazitäts-CSS-Klasse bestimmen
function getCapacityClass(capacity) {
    if (capacity <= 2) return 'capacity-1-2';
    if (capacity <= 5) return 'capacity-3-5';
    if (capacity <= 10) return 'capacity-6-10';
    if (capacity <= 20) return 'capacity-11-20';
    return 'capacity-20-plus';
}

// Create individual reservation bar element
function createReservationBar(reservation, left, width, top, checkinTime, checkoutTime, duration) {
    const bar = document.createElement('div');
    bar.className = 'reservation-bar ' + getCapacityClass(reservation.capacity);
    
    // Exakte Positionierung und Größe
    bar.style.position = 'absolute';
    bar.style.left = left + 'px';
    bar.style.width = width + 'px';
    bar.style.top = top + 'px';
    bar.style.boxSizing = 'border-box';
    bar.style.height = '14px';
    
    // Format: "Nachname, Vorname (Anzahl Arr.Kbez)"
    const displayText = `${reservation.nachname || reservation.name}, ${reservation.vorname || ''} (${reservation.capacity} ${reservation.arrangement || ''})`.trim();
    bar.textContent = displayText;
    
    // Hund-Icon hinzufügen falls vorhanden
    if ((reservation.fullData && reservation.fullData.has_dog) || reservation.hund) {
        const indicatorContainer = document.createElement('div');
        indicatorContainer.className = 'reservation-indicators';
        
        const dogIcon = document.createElement('div');
        dogIcon.className = 'reservation-indicator indicator-dog';
        dogIcon.title = 'Mit Hund';
        
        indicatorContainer.appendChild(dogIcon);
        bar.appendChild(indicatorContainer);
        bar.style.paddingRight = '18px';
    }
    
    // Tooltip erstellen
    let tooltipText = `${reservation.nachname || reservation.name}, ${reservation.vorname || ''}\n`;
    tooltipText += `Kapazität: ${reservation.capacity} Personen\n`;
    tooltipText += `Arrangement: ${reservation.arrangement || 'Nicht zugewiesen'}\n`;
    tooltipText += `Check-in: ${checkinTime.toLocaleDateString('de-DE')}\n`;
    tooltipText += `Check-out: ${checkoutTime.toLocaleDateString('de-DE')}\n`;
    tooltipText += `Aufenthalt: ${duration.toFixed(1)} Tage`;
    
    if (reservation.fullData) {
        const data = reservation.fullData;
        if (data.capacity_details) {
            const details = [];
            if (data.capacity_details.lager > 0) details.push(`${data.capacity_details.lager} Lager`);
            if (data.capacity_details.betten > 0) details.push(`${data.capacity_details.betten} Betten`);
            if (data.capacity_details.dz > 0) details.push(`${data.capacity_details.dz} DZ`);
            if (data.capacity_details.sonder > 0) details.push(`${data.capacity_details.sonder} Sonder`);
            if (details.length > 0) {
                tooltipText += `\nDetails: ${details.join(', ')}`;
            }
        }
        if (data.has_dog) tooltipText += '\n🐕 Mit Hund';
        if (data.notes) tooltipText += `\nBemerkung: ${data.notes}`;
        if (data.av_notes) tooltipText += `\nAV-Bemerkung: ${data.av_notes}`;
    }
    
    bar.title = tooltipText;
    
    // Click-Event
    bar.addEventListener('click', function() {
        if (typeof showGastDetail === 'function') {
            showGastDetail(reservation);
        } else {
            alert(tooltipText);
        }
    });
    
    return bar;
}

// Global variable for UI settings
let showDisposedReservations = true;

// Function to toggle visibility of disposed reservations
function toggleDisposedReservations() {
    showDisposedReservations = !showDisposedReservations;
    
    console.log('=== TOGGLE DISPOSED RESERVATIONS ===');
    console.log('New state:', showDisposedReservations ? 'SHOW ALL' : 'HIDE DISPOSED');
    console.log('Total reservations:', reservations.length);
    
    // Debug: Check current reservation data
    const disposedCount = reservations.filter(r => r.data && r.data.is_disposed).length;
    const undisposedCount = reservations.filter(r => r.data && !r.data.is_disposed).length;
    const noDataCount = reservations.filter(r => !r.data).length;
    
    console.log(`Breakdown: ${disposedCount} disposed, ${undisposedCount} undisposed, ${noDataCount} no data`);
    
    // Update button text
    const button = document.getElementById('toggle-disposed-btn');
    if (button) {
        button.textContent = showDisposedReservations ? 'Disponierte ausblenden' : 'Disponierte anzeigen';
    }
    
    // Update filter status info (only if element exists)
    const statusInfo = document.getElementById('filter-status');
    if (statusInfo) {
        if (showDisposedReservations) {
            statusInfo.textContent = 'Alle Reservierungen werden angezeigt';
            statusInfo.style.color = '#666';
        } else {
            statusInfo.textContent = 'Disponierte Reservierungen sind ausgeblendet';
            statusInfo.style.color = '#e74c3c';
            statusInfo.style.fontWeight = 'bold';
        }
    }
    
    // Re-render timeline with new filter
    renderTimeline();
    
    console.log('Toggle completed, timeline re-rendered');
}

// Test function to check data
function testDispositionData() {
    console.log('=== DISPOSITION DATA TEST ===');
    console.log('Total reservations:', reservations.length);
    
    reservations.forEach((res, index) => {
        console.log(`Reservation ${index}:`, {
            name: res.name,
            has_data: !!res.data,
            data_keys: res.data ? Object.keys(res.data) : null,
            is_disposed: res.data ? res.data.is_disposed : 'undefined'
        });
    });
    
    const disposedCount = reservations.filter(r => r.data && r.data.is_disposed === true).length;
    const undisposedCount = reservations.filter(r => r.data && r.data.is_disposed === false).length;
    const noDispositionData = reservations.filter(r => !r.data || r.data.is_disposed === undefined).length;
    
    console.log('Summary:', {
        total: reservations.length,
        disposed: disposedCount,
        undisposed: undisposedCount,
        no_disposition_data: noDispositionData
    });
}

// Calculate daily occupancy based on reservations for a specific date
function calculateDailyOccupancy(date) {
    let guestCount = 0;
    
    const checkDate = new Date(date);
    checkDate.setHours(12, 0, 0, 0);
    
    roomDetails.forEach(reservation => {
        const checkin = new Date(reservation.start);
        const checkout = new Date(reservation.end);
        checkin.setHours(12, 0, 0, 0);
        checkout.setHours(12, 0, 0, 0);
        
        if (checkin <= checkDate && checkout > checkDate) {
            guestCount += reservation.capacity || 1;
        }
    });
    
    return guestCount;
}

// Update grid lines for all timeline content areas
function updateGridLines(totalWidth) {
    const timelineContents = [
        document.getElementById('timeline-content-master'),
        document.getElementById('timeline-content-histogram'),
        ...document.querySelectorAll('.room-timeline-content')
    ].filter(el => el);
    
    timelineContents.forEach(content => {
        // Remove existing grid lines
        const existingLines = content.querySelectorAll('.day-line, .weekend-bg');
        existingLines.forEach(line => line.remove());
        
        // Set width
        content.style.width = totalWidth + 'px';
        
        // Add day lines
        for (let day = 0; day * DAY_WIDTH < totalWidth; day++) {
            const dayLine = document.createElement('div');
            dayLine.className = 'day-line';
            dayLine.style.position = 'absolute';
            dayLine.style.left = (day * DAY_WIDTH) + 'px';
            dayLine.style.top = '0';
            dayLine.style.bottom = '0';
            dayLine.style.width = '1px';
            dayLine.style.backgroundColor = '#ddd';
            dayLine.style.zIndex = '1';
            content.appendChild(dayLine);
        }
    });
    
    // Update main scrollbar track width
    const scrollTrack = document.getElementById('scroll-track');
    if (scrollTrack) {
        scrollTrack.style.width = totalWidth + 'px';
        console.log(`Updated scroll track width to ${totalWidth}px`);
    } else {
        console.warn('Scroll track element not found');
    }
}

// Render occupancy histogram
function renderHistogram(startDate, endDate) {
    const histogramContent = document.getElementById('timeline-content-histogram');
    if (!histogramContent) {
        console.warn('Histogram content container not found');
        return;
    }
    
    histogramContent.innerHTML = '';
    
    const totalWidth = ((endDate - startDate) / (1000 * 60 * 60 * 24) + 1) * DAY_WIDTH;
    histogramContent.style.width = totalWidth + 'px';
    
    // Create daily occupancy bars
    const currentDate = new Date(startDate);
    while (currentDate <= endDate) {
        const dailyGuests = calculateDailyOccupancy(currentDate);
        
        const barLeft = ((currentDate - startDate) / (1000 * 60 * 60 * 24)) * DAY_WIDTH;
        const barHeight = Math.min(dailyGuests * 2, 40); // Max height 40px
        
        const occupancyBar = document.createElement('div');
        occupancyBar.style.position = 'absolute';
        occupancyBar.style.left = (barLeft + 5) + 'px';
        occupancyBar.style.width = (DAY_WIDTH - 10) + 'px';
        occupancyBar.style.height = barHeight + 'px';
        occupancyBar.style.bottom = '0';
        occupancyBar.style.backgroundColor = dailyGuests > 50 ? '#dc3545' : 
                                           dailyGuests > 30 ? '#ffc107' : 
                                           dailyGuests > 10 ? '#28a745' : '#6c757d';
        occupancyBar.style.borderRadius = '2px';
        occupancyBar.style.opacity = '0.7';
        occupancyBar.title = `${currentDate.toLocaleDateString('de-DE')}: ${dailyGuests} Gäste`;
        
        histogramContent.appendChild(occupancyBar);
        
        currentDate.setDate(currentDate.getDate() + 1);
    }
    
    console.log('Histogram rendered for date range:', startDate.toLocaleDateString(), '-', endDate.toLocaleDateString());
}

// Setup scroll synchronization between timeline areas
function setupScrollSynchronization() {
    // Horizontale Synchronisation (Timeline-Inhalte)
    const syncElements = [
        document.getElementById('timeline-dates'),
        document.getElementById('timeline-content-master'),
        document.getElementById('timeline-content-histogram'),
        ...document.querySelectorAll('.room-timeline-content')
    ].filter(el => el);
    
    console.log('Horizontale Sync-Elemente gefunden:', syncElements.length);
    
    const mainScrollbar = document.querySelector('.main-scrollbar');
    if (mainScrollbar) {
        mainScrollbar.addEventListener('scroll', function() {
            const scrollLeft = this.scrollLeft;
            console.log('Main scrollbar scrollLeft:', scrollLeft);
            
            syncElements.forEach(element => {
                if (element && element.scrollLeft !== scrollLeft) {
                    element.scrollLeft = scrollLeft;
                    if (element.id) {
                        console.log(`Element ${element.id} scrollLeft gesetzt auf:`, scrollLeft);
                    } else {
                        console.log(`Element ${element.className} scrollLeft gesetzt auf:`, scrollLeft);
                    }
                }
            });
        });
    } else {
        console.warn('Main scrollbar nicht gefunden');
    }
    
    // Vertikale Synchronisation (Zimmerbereich)
    setupVerticalScrollSynchronization();
}

// Setup vertical scroll synchronization for room area
function setupVerticalScrollSynchronization() {
    const roomAssignmentArea = document.querySelector('.room-assignment-area');
    const roomLabelsColumn = document.querySelector('.room-labels-column');
    const roomTimelineColumn = document.querySelector('.room-timeline-column');
    
    if (!roomAssignmentArea) {
        console.warn('Room assignment area nicht gefunden für vertikale Synchronisation');
        return;
    }
    
    console.log('Vertikale Scroll-Synchronisation eingerichtet für Zimmerbereich');
    
    // Der zentrale Scroll-Container ist die .room-assignment-area
    // Die beiden Spalten scrollen automatisch mit, da sie Kinder sind
    // Keine weitere Synchronisation nötig, da nur ein Scroll-Container existiert
}

// Update timeline content heights
function updateTimelineContentHeights() {
    // This function can be expanded to dynamically adjust heights
    console.log('Timeline content heights updated');
}

// Reset dates to default range
function resetDates() {
    const today = new Date();
    const startDate = new Date(today);
    startDate.setDate(today.getDate() - 1);
    const endDate = new Date(today);
    endDate.setDate(today.getDate() + 3);
    
    document.getElementById('startDate').value = startDate.toISOString().split('T')[0];
    document.getElementById('endDate').value = endDate.toISOString().split('T')[0];
    
    console.log('Dates reset to default range');
}

// Initialize timeline on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Timeline initialized');
    resetDates();
    
    // Set up vertical separator dragging
    setupVerticalSeparator();
    
    // Add debug info to window for console access
    window.timelineDebug = {
        roomHeights: [],
        updateLabels: () => window.debugUpdateRoomLabels(),
        syncLabels: () => synchronizeRoomLabelHeights(),
        checkLabels: () => {
            const labels = document.querySelectorAll('.room-label-item');
            console.log('Current room labels:');
            labels.forEach((label, index) => {
                console.log(`Label ${index}: height=${label.style.height}, computed=${getComputedStyle(label).height}`);
            });
        }
    };
});

// Setup vertical separator for resizing
function setupVerticalSeparator() {
    const separator = document.getElementById('vertical-separator');
    if (!separator) return;
    
    let isDragging = false;
    
    separator.addEventListener('mousedown', function(e) {
        isDragging = true;
        document.body.style.cursor = 'col-resize';
        e.preventDefault();
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        
        const containerRect = document.querySelector('.main-areas-container').getBoundingClientRect();
        const newWidth = e.clientX - containerRect.left;
        
        if (newWidth >= 60 && newWidth <= 200) {
            separator.style.left = newWidth + 'px';
            document.documentElement.style.setProperty('--sidebar-width', newWidth + 'px');
            
            // Update all elements that depend on sidebar width
            const sidebar = document.querySelector('.timeline-sidebar');
            if (sidebar) sidebar.style.width = newWidth + 'px';
            
            const roomLabels = document.querySelector('.room-labels-column');
            if (roomLabels) roomLabels.style.width = newWidth + 'px';
        }
    });
    
    document.addEventListener('mouseup', function() {
        isDragging = false;
        document.body.style.cursor = '';
    });
}

// Update room label heights to match timeline row heights
function updateRoomLabelHeights(roomHeights) {
    console.log('updateRoomLabelHeights: Updating label heights:', roomHeights);
    
    // Use setTimeout to ensure DOM is fully rendered
    setTimeout(() => {
        const roomLabelItems = document.querySelectorAll('.room-label-item');
        console.log(`Found ${roomLabelItems.length} room label items, expected ${roomHeights.length}`);
        
        if (roomLabelItems.length === 0) {
            console.error('No room label items found! Retrying in 100ms...');
            setTimeout(() => updateRoomLabelHeights(roomHeights), 100);
            return;
        }
        
        roomHeights.forEach((height, index) => {
            if (roomLabelItems[index]) {
                const labelItem = roomLabelItems[index];
                
                // Force height update with important styles
                labelItem.style.setProperty('height', height + 'px', 'important');
                labelItem.style.setProperty('line-height', height + 'px', 'important');
                labelItem.style.setProperty('min-height', height + 'px', 'important');
                labelItem.style.setProperty('--dynamic-height', height + 'px');
                
                console.log(`Updated label ${index}: height=${height}px, current computed height=${getComputedStyle(labelItem).height}`);
            } else {
                console.warn(`Room label item ${index} not found`);
            }
        });
        
        // Force reflow to ensure styles are applied
        document.body.offsetHeight;
        
        console.log('updateRoomLabelHeights: Label height update completed');
    }, 50); // Small delay to ensure DOM is ready
}

// Debug function to manually update label heights - can be called from browser console
window.debugUpdateRoomLabels = function() {
    console.log('Manual room label height update triggered');
    const timelineRows = document.querySelectorAll('.timeline-row.room-row');
    const roomHeights = [];
    
    timelineRows.forEach((row, index) => {
        const height = parseInt(row.style.height) || 16;
        roomHeights.push(height);
        console.log(`Row ${index}: height = ${height}px`);
    });
    
    updateRoomLabelHeights(roomHeights);
};

// Direct synchronization of room label heights with timeline row heights
function synchronizeRoomLabelHeights() {
    console.log('synchronizeRoomLabelHeights: Starting synchronization');
    
    const roomLabels = document.querySelectorAll('.room-label-item');
    const timelineRows = document.querySelectorAll('.timeline-row.room-row');
    
    console.log(`Found ${roomLabels.length} room labels and ${timelineRows.length} timeline rows`);
    
    if (roomLabels.length === 0 || timelineRows.length === 0) {
        console.warn('No room labels or timeline rows found for synchronization');
        return;
    }
    
    if (roomLabels.length !== timelineRows.length) {
        console.warn(`Mismatch: ${roomLabels.length} labels vs ${timelineRows.length} rows`);
    }
    
    const maxCount = Math.min(roomLabels.length, timelineRows.length);
    
    for (let i = 0; i < maxCount; i++) {
        const label = roomLabels[i];
        const row = timelineRows[i];
        
        // Get the actual computed height of the timeline row
        const rowHeight = row.getBoundingClientRect().height;
        const styleHeight = parseInt(row.style.height) || rowHeight;
        const finalHeight = Math.max(rowHeight, styleHeight, 16); // Minimum 16px
        
        console.log(`Row ${i}: computed=${rowHeight}px, style=${styleHeight}px, final=${finalHeight}px`);
        
        // Apply height to label with maximum force
        label.style.setProperty('height', finalHeight + 'px', 'important');
        label.style.setProperty('min-height', finalHeight + 'px', 'important');
        label.style.setProperty('line-height', finalHeight + 'px', 'important');
        label.style.setProperty('max-height', 'none', 'important');
        
        // Also set CSS custom property for potential CSS usage
        label.style.setProperty('--dynamic-height', finalHeight + 'px');
        
        // Verify the change was applied
        const newHeight = label.getBoundingClientRect().height;
        console.log(`Label ${i}: applied ${finalHeight}px, actual=${newHeight}px`);
        
        if (Math.abs(newHeight - finalHeight) > 2) {
            console.warn(`Label ${i} height mismatch: expected ${finalHeight}px, got ${newHeight}px`);
        }
    }
    
    // Force a reflow
    document.body.offsetHeight;
    
    console.log('synchronizeRoomLabelHeights: Synchronization completed');
}
