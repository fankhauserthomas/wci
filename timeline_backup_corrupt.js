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
const VERTICAL_GAP = 2; // Gap zwischen Balken in Pixeln (standard 2)

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

// Echte Daten laden
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
        
        const response = await fetch(`getZimmerplanData.php?start=${startDate}&end=${endDate}`);
        const result = await response.json();
        
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
            const roomRowHeight = Math.max(16, 16 + (maxLevel * 18));
            roomRow.style.height = roomRowHeight + 'px';
            timelineContent.style.height = roomRowHeight + 'px';
        }
    });

    console.log(`renderRoomAssignments: Processed ${processedRoomsCount} rooms, created ${totalBarsCreated} reservation bars`);

    // Füge Layer (Tages-/Wochenendhintergründe) jetzt auch in die neuen .room-timeline-content ein
    updateGridLines(totalWidth);
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
    const stackGap = 2; // Abstand zwischen gestapelten Balken
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

// 4. Auslastungshistogramm rendern
function renderHistogram(startDate, endDate) {
    const histogramContent = document.getElementById('timeline-content-histogram');
    histogramContent.innerHTML = '';
    
    const totalWidth = ((endDate - startDate) / (1000 * 60 * 60 * 24) + 1) * DAY_WIDTH;
    histogramContent.style.width = totalWidth + 'px';
    
    // Horizontale Rasterlinien und Beschriftungen hinzufügen
    const maxCapacity = 100; // Beispiel-Maximalkapazität
    const histogramHeight = 80; // Histogramm-Höhe in Pixeln
    
    // Erstelle horizontale Linien für 0%, 25%, 50%, 75%, 100%
    for (let percent = 0; percent <= 100; percent += 25) {
        const lineY = histogramHeight - (percent / 100 * histogramHeight);
        
        const gridLine = document.createElement('div');
        gridLine.style.cssText = `
            position: absolute;
            left: 0;
            right: 0;
            top: ${lineY}px;
            height: 1px;
            background: ${percent === 0 ? '#666' : '#ddd'};
            z-index: 1;
        `;
        
        // Beschriftung links
        const label = document.createElement('div');
        label.style.cssText = `
            position: absolute;
            left: -30px;
            top: ${lineY - 8}px;
            font-size: 10px;
            color: #666;
            width: 25px;
            text-align: right;
        `;
        label.textContent = percent + '%';
        gridLine.appendChild(label);
        
        histogramContent.appendChild(gridLine);
    }
    
    // Berechne Auslastung pro Tag
    const currentDate = new Date(startDate);
    let dayIndex = 0;
    
    while (currentDate <= endDate) {
        const dailyGuests = calculateDailyOccupancy(currentDate);
        const occupancyPercent = (dailyGuests / maxCapacity) * 100;

        // Erstelle Histogramm-Balken (exakt DAY_WIDTH breit, keine Abzüge)
        const histogramBar = document.createElement('div');
        histogramBar.style.cssText = `
            position: absolute;
            left: ${dayIndex * DAY_WIDTH}px;
            width: ${DAY_WIDTH}px;
            min-width: ${DAY_WIDTH}px;
            max-width: ${DAY_WIDTH}px;
            height: ${Math.max(2, occupancyPercent / 100 * histogramHeight)}px;
            bottom: 0;
            background: linear-gradient(to top, #007bff, #17a2b8);
            border-radius: 2px 2px 0 0;
            border: 1px solid #0056b3;
            z-index: 2;
            box-sizing: border-box;
        `;
        histogramBar.title = `${currentDate.toLocaleDateString('de-DE')}: ${dailyGuests} Gäste (${occupancyPercent.toFixed(1)}%)`;

        histogramContent.appendChild(histogramBar);

        currentDate.setDate(currentDate.getDate() + 1);
        dayIndex++;
    }
}

// Hilfsfunktionen
function updateGridLines(totalWidth) {
    // Grid-Lines komplett entfernt - nur noch Breiten setzen
    // 1. Master-Bereich
    const masterContent = document.getElementById('timeline-content-master');
    if (masterContent) {
        masterContent.style.width = totalWidth + 'px';
        removeOldGridElements(masterContent);
    }

    // 2. Histogramm-Bereich
    const histogramContent = document.getElementById('timeline-content-histogram');
    if (histogramContent) {
        histogramContent.style.width = totalWidth + 'px';
        removeOldGridElements(histogramContent);
    }

    // 3. Zimmerbereich
    const roomTimelineColumn = document.querySelector('.room-timeline-column');
    if (roomTimelineColumn) {
        roomTimelineColumn.style.width = 'auto'; // Nimmt restlichen Platz
        removeOldGridElements(roomTimelineColumn);
    }

    // Hilfsfunktion: Entfernt alte Layer
    function removeOldGridElements(container) {
        const oldGridElements = container.querySelectorAll('.grid-line');
        oldGridElements.forEach(element => element.remove());
    }
}

// Zentrale Scroll-Synchronisation (nur ein Scrollbar!)
function setupScrollSynchronization() {
    const mainScrollbar = document.getElementById('main-scrollbar');
    const scrollTrack = document.getElementById('scroll-track');
    
    if (!mainScrollbar || !scrollTrack) {
        console.warn('Main scrollbar oder scroll track nicht gefunden');
        return;
    }
    
    // Setze Breite des Scroll-Tracks
    const dates = document.querySelectorAll('.date-column');
    const totalWidth = dates.length * DAY_WIDTH;
    scrollTrack.style.width = totalWidth + 'px';
    
    console.log('Scroll-Track Breite gesetzt:', totalWidth + 'px');
    
    // Alle zu synchronisierenden Elemente sammeln
    const syncElements = [
        document.getElementById('timeline-dates'),           // Datum-Header
        document.getElementById('timeline-content-master'),  // Master-Content
        document.getElementById('timeline-content-histogram') // Histogramm-Content
    ];
    
    // Finde alle Zimmer-Timeline-Contents
    const roomContents = document.querySelectorAll('.room-timeline-content');
    roomContents.forEach(content => syncElements.push(content));
    
    // Filter null-Elemente
    const validSyncElements = syncElements.filter(el => el !== null);
    
    console.log('Sync-Elemente gefunden:', validSyncElements.length);
    
    // Hauptscrollbar Event
    mainScrollbar.addEventListener('scroll', function() {
        const scrollLeft = this.scrollLeft;
        console.log('Main scrollbar scrollLeft:', scrollLeft);
        
        validSyncElements.forEach((element, index) => {
            if (element) {
                // Alle Elemente direkt scrollen
                element.scrollLeft = scrollLeft;
                
                // Debug-Logging
                if (index < 5) { // Nur erste 5 für Performance
                    console.log(`Element ${element.id || element.className} scrollLeft gesetzt auf:`, scrollLeft);
                }
            }
        });
    });
    
    // Rückwärts-Synchronisation: Wenn ein Timeline-Content gescrollt wird, 
    // synchronisiere mit dem Hauptscrollbar
    validSyncElements.forEach(element => {
        if (!element) return;
        
        element.addEventListener('scroll', function() {
            if (this.scrollLeft !== mainScrollbar.scrollLeft) {
                mainScrollbar.scrollLeft = this.scrollLeft;
            }
        });
        
        // Mousewheel-Support mit Shift-Taste
        element.addEventListener('wheel', function(e) {
            if (e.shiftKey) { // Nur mit Shift für horizontales Scrollen
                e.preventDefault();
                const scrollAmount = e.deltaY * 2;
                mainScrollbar.scrollLeft += scrollAmount;
            }
        });
    });
}

// Rendere Zimmer-Labels im Header-Bereich
function renderRoomLabels() {
    const roomLabelsColumn = document.getElementById('room-labels-column');
    if (!roomLabelsColumn) {
        debugLog('ERROR: room-labels-column Element nicht gefunden!');
        return;
    }
    
    debugLog(`renderRoomLabels aufgerufen, Anzahl Zimmer: ${rooms.length}`);
    roomLabelsColumn.innerHTML = '';
    
    if (rooms.length === 0) {
        const noRoomsMsg = document.createElement('div');
        noRoomsMsg.className = 'room-label-item';
        noRoomsMsg.textContent = 'Keine Zimmer';
        noRoomsMsg.style.color = '#999';
        roomLabelsColumn.appendChild(noRoomsMsg);
        debugLog('Keine Zimmer gefunden, Fallback-Nachricht angezeigt');
        return;
    }
    
    // Erstelle Label für jedes Zimmer
    rooms.forEach((room, index) => {
        debugLog(`Erstelle Label für Zimmer ${index}: ${room.display_name}`);
        const labelItem = document.createElement('div');
        labelItem.className = 'room-label-item';
        labelItem.textContent = room.display_name; // z.B. "Zimmer 1 (4)"
        labelItem.title = `${room.caption} - Kapazität: ${room.capacity} Personen`;
        roomLabelsColumn.appendChild(labelItem);
    });
    
    debugLog(`${rooms.length} Zimmer-Labels erfolgreich erstellt`);
}

// Hilfsfunktion: Finde Reservierungen für bestimmtes Zimmer
// HINWEIS: Aktuell nicht verwendet, da Zimmer-Zeilen leer sind
// Wird später durch neue Datenlogik ersetzt
function findReservationsForRoom(roomId) {
    return reservations.filter(reservation => {
        // Suche nach Zimmer-Zuordnung in den Reservierungsdaten
        if (reservation.fullData && reservation.fullData.room_id) {
            return reservation.fullData.room_id === roomId;
        }
        if (reservation.zimmer_id) {
            return reservation.zimmer_id === roomId;
        }
        
        // Fallback: Zufällige Zuordnung für Demo (später entfernen)
        return Math.random() > 0.7; // 30% Chance
    });
}

// Kapazitäts-CSS-Klasse bestimmen
function getCapacityClass(capacity) {
    if (capacity >= 20) return 'capacity-20-plus';
    if (capacity >= 11) return 'capacity-11-20';
    if (capacity >= 6) return 'capacity-6-10';
    if (capacity >= 3) return 'capacity-3-5';
    return 'capacity-1-2';
}

// Standard-Datumsbereich setzen
function resetDates() {
    const today = new Date();
    const oneWeekAgo = new Date(today);
    oneWeekAgo.setDate(today.getDate() - 7);
    
    const twoWeeksLater = new Date(today);
    twoWeeksLater.setDate(today.getDate() + 14);
    
    document.getElementById('startDate').value = oneWeekAgo.toISOString().split('T')[0];
    document.getElementById('endDate').value = twoWeeksLater.toISOString().split('T')[0];
}

// Zoom-Funktionalität für Mousewheel
function handleZoom(event) {
    event.preventDefault();
    
    const zoomFactor = event.deltaY > 0 ? 0.9 : 1.1; // Verkleinern/Vergrößern
    const newWidth = Math.max(40, Math.min(200, DAY_WIDTH * zoomFactor)); // Limits: 40px - 200px
    
    if (newWidth !== DAY_WIDTH) {
        DAY_WIDTH = newWidth;
        renderTimeline(); // Timeline neu rendern mit neuer Breite
    }
}

// Initialisierung
function initializePage() {
    resetDates(); // Setze Standard-Datumsbereich
    
    // Event Listener für automatisches Laden bei Datums-Änderung
    document.getElementById('startDate').addEventListener('change', function() {
        if (document.getElementById('endDate').value) {
            loadRealData();
        }
    });
    
    document.getElementById('endDate').addEventListener('change', function() {
        if (document.getElementById('startDate').value) {
            loadRealData();
        }
    });
    
    // Mousewheel-Zoom für Datum-Header
    document.getElementById('timeline-dates').addEventListener('wheel', handleZoom);
    
    // Initialisiere Separator-Drag-Funktionalität
    initializeSeparators();
    
    // Initialisiere vertikalen Separator
    initializeVerticalSeparator();
    
    // Lade Zimmer aus Datenbank und zeige sie sofort an
    loadRooms().then(() => {
        debugLog('Zimmer geladen, rufe renderRoomLabels auf...');
        renderRoomLabels();
    });
    
    // Automatisch Daten laden wenn beide Daten gesetzt sind
    if (document.getElementById('startDate').value && document.getElementById('endDate').value) {
        loadRealData();
    }
}

// Separator Drag-Funktionalität für 4-Bereich Layout
function initializeSeparators() {
    const separator1 = document.getElementById('separator1');
    const separator2 = document.getElementById('separator2');
    
    if (separator1) {
        makeSeparatorDraggable(separator1, 'master-reservations-area', 'room-assignment-area');
    }
    
    if (separator2) {
        makeSeparatorDraggable(separator2, 'room-assignment-area', 'histogram-area');
    }
}

function makeSeparatorDraggable(separator, topAreaClass, bottomAreaClass) {
    let isDragging = false;
    let startY = 0;
    let startTopHeight = 0;
    
    separator.addEventListener('mousedown', function(e) {
        isDragging = true;
        startY = e.clientY;
        
        const topArea = document.querySelector('.' + topAreaClass);
        
        startTopHeight = topArea.offsetHeight;
        
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        
        e.preventDefault();
    });
    
    function onMouseMove(e) {
        if (!isDragging) return;
        
        const deltaY = e.clientY - startY;
        const topArea = document.querySelector('.' + topAreaClass);
        
        const newTopHeight = Math.max(100, startTopHeight + deltaY);
        
        // WICHTIG: Nur die Höhe des oberen Bereichs ändern
        // Der untere Bereich behält seine ursprüngliche Höhe und Position
        topArea.style.flex = 'none';
        topArea.style.height = newTopHeight + 'px';
        
        // Den unteren Bereich NICHT verändern, damit die Separatoren darunter stabil bleiben
        
        // Aktualisiere Timeline-Content-Höhen nach Separator-Bewegung
        updateTimelineContentHeights();
    }
    
    function onMouseUp() {
        isDragging = false;
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
    }
}

// Vertikaler Separator für Caption-Breite
function initializeVerticalSeparator() {
    const verticalSeparator = document.getElementById('vertical-separator');
    if (!verticalSeparator) return;
    
    // Setze initiale Position korrekt
    const initialWidth = 80;
    verticalSeparator.style.left = initialWidth + 'px';
    
    // Setze initiale Caption-Breiten
    updateCaptionWidths(initialWidth);
    
    let isDragging = false;
    let startX = 0;
    
    verticalSeparator.addEventListener('mousedown', function(e) {
        isDragging = true;
        startX = e.clientX;
        
        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
        
        e.preventDefault();
    });
    
    function onMouseMove(e) {
        if (!isDragging) return;
        
        const deltaX = e.clientX - startX;
        const currentLeft = parseInt(verticalSeparator.style.left || '80', 10);
        const newLeft = Math.max(50, Math.min(200, currentLeft + deltaX));
        
        // Aktualisiere Separator-Position
        verticalSeparator.style.left = newLeft + 'px';
        
        // Aktualisiere alle row-label Breiten
        updateCaptionWidths(newLeft);
        
        startX = e.clientX;
    }
    
    function onMouseUp() {
        isDragging = false;
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
    }
}

// Caption-Breiten nach Separator-Bewegung aktualisieren
function updateCaptionWidths(newWidth) {
    // Timeline-Sidebar im Header (Datum-Spalte)
    const headerSidebar = document.querySelector('.timeline-header-area .timeline-sidebar');
    if (headerSidebar) {
        headerSidebar.style.width = newWidth + 'px';
    }
    
    // Room-Labels-Column im Zimmerbereich
    const roomLabelsColumn = document.getElementById('room-labels-column');
    if (roomLabelsColumn) {
        roomLabelsColumn.style.width = newWidth + 'px';
    }
    
    // Timeline-Sidebar im Hauptbereich (falls vorhanden)
    const mainSidebar = document.querySelector('.main-areas-container > .timeline-sidebar');
    if (mainSidebar) {
        mainSidebar.style.width = newWidth + 'px';
    }
    
    // Alle row-label Elemente
    const rowLabels = document.querySelectorAll('.row-label');
    rowLabels.forEach(label => {
        label.style.width = newWidth + 'px';
    });
    
    // Grid-Lines in Master/Histogramm mit neuem Offset aktualisieren
    updateGridLinesOffset(newWidth);
}

// Grid-Lines mit dynamischem Offset aktualisieren
function updateGridLinesOffset(offsetLeft) {
    // Grid elements no longer needed - function kept for compatibility
}

// Timeline-Content-Höhen nach Separator-Bewegung aktualisieren
function updateTimelineContentHeights() {
    // Master-Bereich: Content-Höhe = Area-Höhe (kein Header mehr)
    const masterArea = document.querySelector('.master-reservations-area');
    const masterContent = document.getElementById('timeline-content-master');
    if (masterArea && masterContent) {
        const availableHeight = masterArea.offsetHeight;
        masterContent.style.height = Math.max(100, availableHeight) + 'px';
    }
    
    // Histogramm-Bereich: Content-Höhe = Area-Höhe (kein Header mehr)
    const histogramArea = document.querySelector('.histogram-area');
    const histogramContent = document.getElementById('timeline-content-histogram');
    if (histogramArea && histogramContent) {
        const availableHeight = histogramArea.offsetHeight;
        histogramContent.style.height = Math.max(80, availableHeight) + 'px';
    }
    
    // Zimmer-Bereich: Rows-Container-Höhe = Area-Höhe (kein Header mehr)
    const roomArea = document.querySelector('.room-assignment-area');
    const roomContainer = document.getElementById('room-rows-container');
    if (roomArea && roomContainer) {
        const availableHeight = roomArea.offsetHeight;
        roomContainer.style.height = Math.max(150, availableHeight) + 'px';
    }
}

// Hilfsfunktionen für die 4 Timeline-Bereiche

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
        if (data.has_dog) tooltipText += '\n🐕 Mit Hund';{
        if (data.notes) tooltipText += `\nBemerkung: ${data.notes}`;
        if (data.av_notes) tooltipText += `\nAV-Bemerkung: ${data.av_notes}`;
    } 
        // Setze das End-Datum für dieses Stack-Level
    bar.title = tooltipText;esEnd;
    
    // Click-Event für Detailbereich (falls vorhanden)    console.log(`  → Stack Level: ${stackLevel}, End-Zeit für Level: ${resEnd.toLocaleDateString()}`);
    bar.addEventListener('click', function() {
        if (typeof showReservationInDetail === 'function') {
            showReservationInDetail(reservation);
        }    stackLevel: stackLevel
    });
    
    return bar;
}ole.log('Stapelung abgeschlossen. Verwendete Stack-Level:', stackLevels.length);

// Funktion zur Berechnung der Stapelung für überschneidende Zimmerreservierungenrn stackedReservations;
function calculateRoomReservationStacking(reservations, startDate) {
    console.log('=== STACKING CALCULATION ===');
    console.log('Reservierungen für Stapelung:', reservations.length);late daily occupancy based on reservations for a specific date
    
    if (reservations.length === 0) {
        return [];
    }t checkin = new Date(reservation.start);
    
    // Sortiere Reservierungen nach Startdatum
    const sortedReservations = [...reservations].sort((a, b) => { 0);
        return new Date(a.start).getTime() - new Date(b.start).getTime();
    }); checkDate = new Date(date);
    heckDate.setHours(12, 0, 0, 0);
    const stackedReservations = [];
    const stackLevels = []; // Array für aktive Stack-Level mit End-Zeiten
    || 1; // Default to 1 if capacity not specified
    sortedReservations.forEach((reservation, index) => {
        const resStart = new Date(reservation.start);
        const resEnd = new Date(reservation.end);rn guestCount;
        
        // Symbolische Darstellung: Mittag zu Mittag für Überschneidungsprüfung
        resStart.setHours(12, 0, 0, 0);l variable for UI settings
        resEnd.setHours(12, 0, 0, 0);
        
        console.log(`Verarbeite Reservierung ${index}: ${reservation.guest_name} (${resStart.toLocaleDateString()} - ${resEnd.toLocaleDateString()})`);f disposed reservations
        ons() {
        // Finde das niedrigste verfügbare Stack-LevelowDisposedReservations;
        let stackLevel = 0;
        sole.log('=== TOGGLE DISPOSED RESERVATIONS ===');
        // Prüfe alle aktiven Stack-Levelconsole.log('New state:', showDisposedReservations ? 'SHOW ALL' : 'HIDE DISPOSED');
        for (let level = 0; level < stackLevels.length; level++) {
            const levelEndTime = stackLevels[level];
            ervation data
            // Wenn das aktuelle Level vor dem Start der neuen Reservierung endet, ist es frei   const disposedCount = reservations.filter(r => r.data && r.data.is_disposed).length;
            if (!levelEndTime || levelEndTime.getTime() <= resStart.getTime()) {    const undisposedCount = reservations.filter(r => r.data && !r.data.is_disposed).length;
                stackLevel = level;
                break;
            }own: ${disposedCount} disposed, ${undisposedCount} undisposed, ${noDataCount} no data`);
        }
        
        // Wenn kein freies Level gefunden wurde, erstelle ein neuesById('toggle-disposed-btn');
        if (stackLevel >= stackLevels.length) {
            stackLevels.push(null);edReservations ? 'Disponierte ausblenden' : 'Disponierte anzeigen';
        }
        
        // Setze das End-Datum für dieses Stack-Level
        stackLevels[stackLevel] = resEnd;t statusInfo = document.getElementById('filter-status');
        
        console.log(`  → Stack Level: ${stackLevel}, End-Zeit für Level: ${resEnd.toLocaleDateString()}`);
           statusInfo.textContent = 'Alle Reservierungen werden angezeigt';
        stackedReservations.push({     statusInfo.style.color = '#666';
            detail: reservation,
            stackLevel: stackLevel           statusInfo.textContent = 'Disponierte Reservierungen sind ausgeblendet';
        });            statusInfo.style.color = '#e74c3c';
    });eight = 'bold';
    
    console.log('Stapelung abgeschlossen. Verwendete Stack-Level:', stackLevels.length);    }
    
    return stackedReservations;er
}

// Calculate daily occupancy based on reservations for a specific date);
function calculateDailyOccupancy(date) {
    let guestCount = 0;
    reservations.forEach(reservation => {est function to check data
        const checkin = new Date(reservation.start);
        checkin.setHours(12, 0, 0, 0);
        const checkout = new Date(reservation.end);
        checkout.setHours(12, 0, 0, 0);
        reservations.forEach((res, index) => {
        const checkDate = new Date(date);
        checkDate.setHours(12, 0, 0, 0);        name: res.name,
        es.data,
        if (checkDate >= checkin && checkDate < checkout) {
            guestCount += reservation.capacity || 1; // Default to 1 if capacity not specifiedsposed: res.data ? res.data.is_disposed : 'undefined'
        }
    }););
    return guestCount;
}ations.filter(r => r.data && r.data.is_disposed === true).length;
.data.is_disposed === false).length;
// Global variable for UI settingsonData = reservations.filter(r => !r.data || r.data.is_disposed === undefined).length;
let showDisposedReservations = true;

// Function to toggle visibility of disposed reservations
function toggleDisposedReservations() {: disposedCount,
    showDisposedReservations = !showDisposedReservations;
    
    console.log('=== TOGGLE DISPOSED RESERVATIONS ===');
    console.log('New state:', showDisposedReservations ? 'SHOW ALL' : 'HIDE DISPOSED');
    console.log('Total reservations:', reservations.length);        // Debug: Check current reservation data    const disposedCount = reservations.filter(r => r.data && r.data.is_disposed).length;    const undisposedCount = reservations.filter(r => r.data && !r.data.is_disposed).length;    const noDataCount = reservations.filter(r => !r.data).length;        console.log(`Breakdown: ${disposedCount} disposed, ${undisposedCount} undisposed, ${noDataCount} no data`);        // Update button text    const button = document.getElementById('toggle-disposed-btn');    if (button) {        button.textContent = showDisposedReservations ? 'Disponierte ausblenden' : 'Disponierte anzeigen';    }        // Update filter status info    const statusInfo = document.getElementById('filter-status');    if (statusInfo) {        if (showDisposedReservations) {            statusInfo.textContent = 'Alle Reservierungen werden angezeigt';            statusInfo.style.color = '#666';        } else {            statusInfo.textContent = 'Disponierte Reservierungen sind ausgeblendet';            statusInfo.style.color = '#e74c3c';            statusInfo.style.fontWeight = 'bold';        }    }        // Re-render timeline with new filter    renderTimeline();        console.log('Toggle completed, timeline re-rendered');}