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
const VERTICAL_GAP = 1; // Gap zwischen Balken in Pixeln (zurück auf 1px)

// Setze globale CSS-Variable für Tagesbreite
function setDayWidthCSSVar() {
    document.documentElement.style.setProperty('--day-width', DAY_WIDTH + 'px');
}
setDayWidthCSSVar();

// Zimmer aus Datenbank laden - ALLE VERFÜGBAREN ZIMMER
async function loadRooms() {
    try {
        console.log('Loading ALL rooms from database...');
        const response = await fetch('getRooms.php');
        const result = await response.json();

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        if (!result.success) {
            throw new Error(result.error || 'Fehler beim Laden der Zimmer');
        }

        rooms = result.data || [];

        // Sortiere Zimmer für konsistente Anzeige
        rooms.sort((a, b) => {
            const sortA = a.sort !== undefined ? a.sort : a.id;
            const sortB = b.sort !== undefined ? b.sort : b.id;
            return sortA - sortB;
        });

        console.log(`Successfully loaded ${rooms.length} rooms from database:`);

        // Debug: Zeige erste paar Zimmer
        if (rooms.length > 0) {
            console.log('Sample rooms:', rooms.slice(0, Math.min(5, rooms.length)).map(room => ({
                id: room.id,
                caption: room.caption,
                capacity: room.capacity,
                sort: room.sort,
                display_name: room.display_name
            })));
        }

        // Zeige Zimmer-Statistiken
        const stats = {
            total: rooms.length,
            hasCapacity: rooms.filter(r => r.capacity && r.capacity > 0).length,
            hasSort: rooms.filter(r => r.sort !== undefined && r.sort !== null).length,
            hasDisplayName: rooms.filter(r => r.display_name).length
        };

        console.log('Room loading statistics:', stats);

        return rooms;

    } catch (error) {
        console.error('Fehler beim Laden der Zimmer:', error);
        debugLog('Fehler beim Laden der Zimmer: ' + error.message);

        // Fallback: Erstelle minimal Demo-Zimmer nur wenn wirklich keine Daten verfügbar
        console.warn('Using fallback demo rooms due to database error');
        rooms = [
            { id: 1, caption: 'Zimmer 1', capacity: 2, sort: 1, display_name: 'Zimmer 1 (2P)' },
            { id: 2, caption: 'Zimmer 2', capacity: 4, sort: 2, display_name: 'Zimmer 2 (4P)' },
            { id: 3, caption: 'Zimmer 3', capacity: 3, sort: 3, display_name: 'Zimmer 3 (3P)' },
            { id: 4, caption: 'Zimmer 4', capacity: 6, sort: 4, display_name: 'Zimmer 4 (6P)' },
            { id: 5, caption: 'Zimmer 5', capacity: 2, sort: 5, display_name: 'Zimmer 5 (2P)' }
        ];

        debugLog(`Fallback: Using ${rooms.length} demo rooms`);
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

        // Button und Status zurücksetzen
        loadBtn.disabled = false;
        loadBtn.textContent = originalText;

        // Status-Info aktualisieren
        const statusInfo = document.getElementById('status-info');
        if (statusInfo) {
            statusInfo.textContent = `Daten geladen: ${reservations.length} Reservierungen`;
            statusInfo.style.color = '#28a745';
        }

    } catch (error) {
        console.error('Fehler beim Laden der echten Daten:', error);

        // Button zurücksetzen
        loadBtn.disabled = false;
        loadBtn.textContent = originalText;

        // Status-Info aktualisieren
        const statusInfo = document.getElementById('status-info');
        if (statusInfo) {
            statusInfo.textContent = 'Fehler beim Laden der Daten';
            statusInfo.style.color = '#dc3545';
        }

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
            <div>${currentDate.toLocaleDateString('de-DE', { weekday: 'short' })}</div>
            <div>${currentDate.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' })}</div>
        `;
        datesContainer.appendChild(dateDiv);

        currentDate.setDate(currentDate.getDate() + 1);
    }

    // TIMELINE-CONTENT: Container-Breite wird NICHT mehr durch JS gesetzt!
    // Das Flex-Layout im CSS bestimmt die sichtbare Breite
    // Nur totalWidth für Grid-Lines und Scroll-Track benötigt
    const totalWidth = dates.length * DAY_WIDTH;

    // ENTFERNT: Breite-Einstellungen für datesContainer
    // datesContainer wird durch CSS Flex Layout dimensioniert

    // Update Gitternetzlinien für alle Timeline-Content Bereiche
    updateGridLines(totalWidth);

    return dates;
}

// 2. Master-Reservierungen rendern (alle Datensätze gestapelt)
function renderMasterReservations(startDate, endDate) {
    const contentContainer = document.getElementById('timeline-content-master');
    contentContainer.innerHTML = '';

    // ENTFERNT: contentContainer.style.width = totalWidth + 'px';
    // Container-Breite wird durch CSS Flex Layout bestimmt - nicht durch JS!
    const totalWidth = ((endDate - startDate) / (1000 * 60 * 60 * 24) + 1) * DAY_WIDTH;

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

        const top = 10 + (stackLevel * (15 + VERTICAL_GAP)); // Reduziert von 18 auf 15

        // Erstelle Master-Balken
        const bar = createReservationBar(reservation, left, width, top, checkinDate, checkoutDate, duration);
        contentContainer.appendChild(bar);
    });

    // Berechne benötigte Höhe basierend auf höchstem Stack-Level
    const allStackLevels = Object.keys(stackLevels).map(Number);
    const maxStackLevel = allStackLevels.length > 0 ? Math.max(...allStackLevels) : 0;
    const requiredHeight = Math.max(120, 10 + (maxStackLevel * (18 + VERTICAL_GAP)) + 50); // Mehr Puffer für Scrollbar-Test

    console.log(`Master timeline: ${filteredReservations.length} reservations, max stack level: ${maxStackLevel}, required height: ${requiredHeight}px`);

    // Setze die Höhe der timeline-row dynamisch
    const masterTimelineRow = contentContainer.closest('.timeline-row');
    if (masterTimelineRow) {
        masterTimelineRow.style.height = requiredHeight + 'px';
        masterTimelineRow.style.minHeight = requiredHeight + 'px';
        console.log(`Master timeline row height set to ${requiredHeight}px`);

        // Force scrollbar check
        setTimeout(() => {
            const masterArea = document.querySelector('.master-reservations-area');
            if (masterArea) {
                const areaHeight = masterArea.getBoundingClientRect().height;
                console.log(`Master area: ${areaHeight}px, content: ${requiredHeight}px, scrollable: ${requiredHeight > areaHeight ? 'YES' : 'NO'}`);
            }
        }, 100);
    }
}

// 3. Zimmerzuteilung rendern (ALLE ZIMMER, dynamisches Stacking nur im sichtbaren Bereich)
async function renderRoomAssignments(startDate, endDate) {
    console.log('renderRoomAssignments: Rendering ALL ROOMS with dynamic visible-area stacking');

    const roomContainer = document.getElementById('room-rows-container');
    if (!roomContainer) {
        console.error('FEHLER: room-rows-container Element nicht gefunden!');
        return;
    }

    roomContainer.innerHTML = '';

    // Lade ALLE Zimmer
    if (rooms.length === 0) {
        await loadRooms();
    }

    const totalWidth = ((endDate - startDate) / (1000 * 60 * 60 * 24) + 1) * DAY_WIDTH;

    // Erstelle Zimmer-Labels für ALLE Zimmer im Header-Bereich
    renderRoomLabels();

    console.log(`Processing ALL ${rooms.length} rooms from database`);

    // Array zum Sammeln der Zimmer-Höhen für spätere Label-Anpassung
    const roomHeights = [];
    let processedRoomsCount = 0;
    let totalBarsCreated = 0;

    // Erstelle Zeile für JEDES Zimmer aus der Datenbank
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

        console.log(`Zimmer ${room.caption || room.id}: ${roomReservations.length} Reservierungen gefunden`);

        const timelineContent = roomRow.querySelector('.room-timeline-content');
        if (!timelineContent) {
            console.error(`FEHLER: .room-timeline-content nicht gefunden für Zimmer ${room.id}`);
            return;
        }

        // DYNAMISCHES STACKING nur für sichtbare Reservierungen
        if (roomReservations.length > 0) {
            // Filter: Nur Reservierungen im sichtbaren Zeitraum (Timeline-Bereich)
            const visibleReservations = roomReservations.filter(detail => {
                const checkinDate = new Date(detail.start);
                const checkoutDate = new Date(detail.end);

                // Prüfe Überlappung mit sichtbarem Zeitraum
                return checkoutDate >= startDate && checkinDate <= endDate;
            });

            console.log(`Zimmer ${room.caption}: ${visibleReservations.length} von ${roomReservations.length} Reservierungen im sichtbaren Bereich`);

            // Sortiere sichtbare Reservierungen nach Startzeit für besseres Stacking
            const sortedVisibleReservations = [...visibleReservations].sort((a, b) => {
                return new Date(a.start).getTime() - new Date(b.start).getTime();
            });

            // Stack-Level Tracking NUR für sichtbare Reservierungen
            const roomStackLevels = [];
            const maxStackLevel = [];
            const OVERLAP_TOLERANCE = DAY_WIDTH * 0.25; // 25% eines Tages = ~6 Stunden

            sortedVisibleReservations.forEach((detail, detailIndex) => {
                // Berechne Position dieser Reservierung
                const checkinDate = new Date(detail.start);
                checkinDate.setHours(12, 0, 0, 0);
                const checkoutDate = new Date(detail.end);
                checkoutDate.setHours(12, 0, 0, 0);

                const timelineStart = new Date(startDate);
                timelineStart.setHours(0, 0, 0, 0);

                const startOffset = (checkinDate.getTime() - timelineStart.getTime()) / (1000 * 60 * 60 * 24);
                const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                // Nur rendern wenn im sichtbaren Bereich
                if (startOffset + duration < 0 || startOffset > ((endDate - startDate) / (1000 * 60 * 60 * 24))) {
                    return; // Überspringe nicht-sichtbare Reservierungen
                }

                const left = (startOffset + 0.1) * DAY_WIDTH;
                const width = (duration - 0.2) * DAY_WIDTH;

                // Finde freie Stack-Ebene mit dynamischem Algorithmus
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

            // Setze Zimmerzeilenhöhe basierend auf dem maximalen Stack Level der SICHTBAREN Reservierungen
            const maxLevel = maxStackLevel.length > 0 ? Math.max(...maxStackLevel, 0) : 0;
            const roomRowHeight = Math.max(16, 16 + (maxLevel * 17)); // 17px pro Level (14px Bar + 1px Gap + 2px Padding)
            roomRow.style.height = roomRowHeight + 'px';
            timelineContent.style.height = roomRowHeight + 'px';

            // Speichere Höhe für spätere Label-Anpassung
            roomHeights[roomIndex] = roomRowHeight;

            console.log(`Zimmer ${room.caption}: Max Stack Level = ${maxLevel}, Höhe = ${roomRowHeight}px`);
        } else {
            // Zimmer ohne Reservierungen - Standard-Höhe
            roomHeights[roomIndex] = 16;
            console.log(`Zimmer ${room.caption}: Keine Reservierungen, Standard-Höhe 16px`);
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

    console.log(`renderRoomAssignments: Processed ALL ${processedRoomsCount} rooms, created ${totalBarsCreated} reservation bars (only visible area)`);

    // Füge Layer (Tages-/Wochenendhintergründe) jetzt auch in die neuen .room-timeline-content ein
    updateGridLines(totalWidth);
}

// Render room labels in the sidebar - FÜR ALLE ZIMMER AUS DATENBANK
function renderRoomLabels() {
    const roomLabelsContainer = document.querySelector('.room-labels-column');
    if (!roomLabelsContainer) {
        console.warn('Room labels container not found');
        return;
    }

    roomLabelsContainer.innerHTML = '';

    // Sortiere Zimmer nach sort-Feld oder ID für konsistente Reihenfolge
    const sortedRooms = [...rooms].sort((a, b) => {
        // Verwende sort-Feld falls vorhanden, sonst ID
        const sortA = a.sort !== undefined ? a.sort : a.id;
        const sortB = b.sort !== undefined ? b.sort : b.id;
        return sortA - sortB;
    });

    sortedRooms.forEach((room, index) => {
        const labelDiv = document.createElement('div');
        labelDiv.className = 'room-label-item';

        // Erweiterte Display-Namen Logik
        let displayName = room.display_name;
        if (!displayName) {
            // Fallback: Konstruiere Display-Namen
            const caption = room.caption || `Zimmer ${room.id}`;
            const capacity = room.capacity ? ` (${room.capacity})` : '';
            displayName = caption + capacity;
        }

        labelDiv.textContent = displayName;
        labelDiv.title = `Zimmer ID: ${room.id}\nBezeichnung: ${room.caption || 'Unbekannt'}\nKapazität: ${room.capacity || 'Unbekannt'}\nSort: ${room.sort || 'Unbekannt'}`;

        // Zusätzliche Datenattribute für Debug
        labelDiv.setAttribute('data-room-id', room.id);
        labelDiv.setAttribute('data-room-index', index);

        roomLabelsContainer.appendChild(labelDiv);
    });

    console.log(`Rendered ${sortedRooms.length} room labels for ALL rooms from database`);

    // Debug: Zeige Zimmer-Info
    const roomStats = {
        total: rooms.length,
        withReservations: 0,
        withoutReservations: 0,
        reservationCounts: {}
    };

    rooms.forEach(room => {
        const reservationsForRoom = roomDetails.filter(detail =>
            detail.room_id === room.id ||
            String(detail.room_id) === String(room.id) ||
            Number(detail.room_id) === Number(room.id)
        );

        if (reservationsForRoom.length > 0) {
            roomStats.withReservations++;
            roomStats.reservationCounts[room.id] = reservationsForRoom.length;
        } else {
            roomStats.withoutReservations++;
        }
    });

    console.log('Room statistics:', roomStats);
}

function createRoomRow(room, startDate, endDate, totalWidth) {
    const rowDiv = document.createElement('div');
    rowDiv.className = 'timeline-row room-row';
    rowDiv.style.height = '16px'; // Initial-Höhe, wird später angepasst
    rowDiv.style.margin = '0';
    rowDiv.style.padding = '0';
    rowDiv.style.position = 'relative'; // Für absolute positioning der Reservierungen
    rowDiv.style.minHeight = '16px'; // Mindesthöhe

    // Timeline-Content für dieses Zimmer (CLIPPING aktiviert)
    const contentDiv = document.createElement('div');
    contentDiv.className = 'room-timeline-content';
    contentDiv.style.width = '100%'; // Volle Breite, da kein Label mehr links
    contentDiv.style.height = '16px'; // Initial-Höhe, wird später angepasst
    contentDiv.style.margin = '0';
    contentDiv.style.padding = '0';
    contentDiv.style.border = 'none';
    contentDiv.style.position = 'relative'; // Für absolute positioning der Reservierungen
    contentDiv.style.minHeight = '16px'; // Mindesthöhe
    contentDiv.style.overflow = 'hidden'; // WICHTIG: Clipping für Timeline-Balken

    rowDiv.appendChild(contentDiv);
    return rowDiv;
}

// Neue Funktion: Zimmer-Reservierungs-Balken erstellen - OPTIMIERT für alle Zimmer mit dynamischem Stacking
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

    // Prüfe ob die Reservierung im sichtbaren Bereich liegt (erweiterte Prüfung)
    if (startOffset + duration < -1 || startOffset > 100) { // Großzügiger Sichtbarkeits-Puffer
        return null;
    }

    // DARSTELLUNGS-ANPASSUNG: Kleine Lücken wie bei Master-Reservierungen
    const left = (startOffset + 0.1) * DAY_WIDTH;
    const width = (duration - 0.2) * DAY_WIDTH;

    // Mindestbreite für sehr kurze Aufenthalte
    const finalWidth = Math.max(width, 10); // Mindestens 10px breit

    // Berechne vertikale Position basierend auf Stack-Level (optimiert)
    const barHeight = 14; // Höhe eines einzelnen Balkens
    const stackGap = 1; // Abstand zwischen gestapelten Balken
    const topPosition = 1 + (stackLevel * (barHeight + stackGap));

    if (finalWidth <= 5) { // Nur extrem kleine Balken ausschließen
        return null;
    }

    // Erstelle Zimmer-Reservierungs-Balken (Performance-optimiert)
    const bar = document.createElement('div');
    bar.className = 'room-reservation-bar';

    // Style-Optimierung: Verwende CSS-Klassen wo möglich
    Object.assign(bar.style, {
        position: 'absolute',
        left: left + 'px',
        width: finalWidth + 'px',
        top: topPosition + 'px',
        height: barHeight + 'px',
        backgroundColor: detail.color || '#3498db',
        border: '1px solid rgba(0,0,0,0.1)',
        borderRadius: '2px',
        fontSize: '9px',
        color: '#fff',
        padding: '1px 2px',
        overflow: 'hidden',
        whiteSpace: 'nowrap',
        textOverflow: 'ellipsis',
        boxSizing: 'border-box',
        zIndex: (10 + stackLevel).toString(),
        cursor: 'pointer'
    });

    // Inhalt: Optimierte Darstellung für viele Zimmer
    let content = detail.guest_name || 'Unbekannt';

    // Verkürze Namen bei zu wenig Platz
    if (finalWidth < 80) {
        const nameParts = content.split(',');
        if (nameParts.length > 1) {
            // Nur Nachname bei wenig Platz
            content = nameParts[0].trim();
        }
    }

    if (detail.arrangement && finalWidth > 60) {
        content += ' (' + detail.arrangement + ')';
    }

    if (detail.has_dog && finalWidth > 100) {
        content += ' 🐕';
    }

    bar.textContent = content;

    // Optimierter Tooltip mit allen wichtigen Infos
    const tooltipLines = [
        `Gast: ${detail.guest_name || 'Unbekannt'}`,
        `Zimmer: ${detail.data?.room_name || 'Unbekannt'}`,
        `Aufenthalt: ${von.toLocaleDateString('de-DE')} - ${bis.toLocaleDateString('de-DE')}`,
        `Anzahl: ${detail.capacity || 1} Person${(detail.capacity || 1) > 1 ? 'en' : ''}`,
        `Arrangement: ${detail.arrangement || 'Nicht zugewiesen'}`
    ];

    if (detail.has_dog) tooltipLines.push('🐕 Mit Hund');
    if (stackLevel > 0) tooltipLines.push(`Stack-Level: ${stackLevel + 1}`);
    if (detail.color) tooltipLines.push(`Farbe: ${detail.color}`);

    bar.title = tooltipLines.join('\n');

    // Event-Handler (optimiert)
    bar.addEventListener('click', function (e) {
        e.stopPropagation();
        // Verbessertes Info-Panel statt alert
        console.log('Room reservation clicked:', detail);
        alert(bar.title); // TODO: Ersetze durch modales Info-Panel
    });

    // Performance-optimierte Hover-Effekte
    let hoverTimeout;
    bar.addEventListener('mouseenter', function () {
        clearTimeout(hoverTimeout);
        this.style.transform = 'scale(1.02)';
        this.style.boxShadow = '0 2px 4px rgba(0,0,0,0.3)';
        this.style.zIndex = (20 + stackLevel).toString();
    });

    bar.addEventListener('mouseleave', function () {
        hoverTimeout = setTimeout(() => {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = 'none';
            this.style.zIndex = (10 + stackLevel).toString();
        }, 50); // Kleine Verzögerung verhindert Flackern
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
    bar.addEventListener('click', function () {
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

        // RESET: Entferne alle Padding/Margin die Versatz verursachen könnten
        content.style.padding = '0';
        content.style.margin = '0';
        content.style.border = 'none';

        // Add day lines - PRÄZISE AKKUMULATION statt Multiplikation
        let currentPosition = 0; // Startposition
        for (let day = 0; currentPosition < totalWidth; day++) {
            const dayLine = document.createElement('div');
            dayLine.className = 'day-line';
            dayLine.style.position = 'absolute';
            dayLine.style.left = Math.round(currentPosition) + 'px'; // RUNDE für exakte Pixel
            dayLine.style.top = '0';
            dayLine.style.bottom = '0';
            dayLine.style.width = '1px';
            dayLine.style.backgroundColor = '#ddd';
            dayLine.style.zIndex = '1';
            dayLine.style.pointerEvents = 'none'; // Keine Maus-Interferenz
            content.appendChild(dayLine);

            currentPosition += DAY_WIDTH; // AKKUMULIERE statt multipliziere
        }
    });

    // WICHTIG: Nur scroll-track Breite setzen - NICHT die Content-Container!
    // Content-Container werden durch CSS Flex Layout geclippt
    const scrollTrack = document.getElementById('scroll-track');
    if (scrollTrack) {
        scrollTrack.style.width = totalWidth + 'px';
        console.log(`Updated scroll track width to ${totalWidth}px (containers bleiben CSS-gesteuert)`);
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

    // ENTFERNT: histogramContent.style.width = totalWidth + 'px';
    // Container-Breite wird durch CSS Flex Layout bestimmt - nicht durch JS!
    const totalWidth = ((endDate - startDate) / (1000 * 60 * 60 * 24) + 1) * DAY_WIDTH;

    // Ermittle verfügbare Höhe der histogram-area
    const histogramArea = document.querySelector('.histogram-area');
    const availableHeight = histogramArea ? histogramArea.getBoundingClientRect().height - 60 : 80; // 60px für Labels und Padding
    const maxBarHeight = Math.max(40, availableHeight); // Mindestens 40px, aber nutze verfügbare Höhe

    // Finde maximale Gästeanzahl für Skalierung
    const allDailyCounts = [];
    const tempDate = new Date(startDate);
    while (tempDate <= endDate) {
        allDailyCounts.push(calculateDailyOccupancy(tempDate));
        tempDate.setDate(tempDate.getDate() + 1);
    }
    const maxGuests = Math.max(...allDailyCounts, 1); // Verhindere Division durch 0

    // Create daily occupancy bars
    const currentDate = new Date(startDate);
    while (currentDate <= endDate) {
        const dailyGuests = calculateDailyOccupancy(currentDate);

        const barLeft = ((currentDate - startDate) / (1000 * 60 * 60 * 24)) * DAY_WIDTH;
        // Skaliere Balken-Höhe basierend auf verfügbarer Höhe und maximal Gästen
        const barHeight = Math.max(2, (dailyGuests / maxGuests) * maxBarHeight);

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
        occupancyBar.title = `${currentDate.toLocaleDateString('de-DE')}: ${dailyGuests} Gäste (${((dailyGuests / maxGuests) * 100).toFixed(1)}% von max ${maxGuests})`;

        histogramContent.appendChild(occupancyBar);

        currentDate.setDate(currentDate.getDate() + 1);
    }

    console.log(`Histogram rendered: max guests=${maxGuests}, max bar height=${maxBarHeight}px, available height=${availableHeight}px`);
}

// Setup scroll synchronization between timeline areas
function setupScrollSynchronization() {
    console.log('Setting up NEW transform-based scroll synchronization');

    // Sammle alle Timeline-Bereiche, die synchronisiert werden sollen
    const syncElements = [
        document.getElementById('timeline-dates'),
        document.getElementById('timeline-content-master'),
        document.getElementById('timeline-content-histogram'),
        ...document.querySelectorAll('.room-timeline-content')
    ].filter(el => el);

    console.log('Synchronisiere', syncElements.length, 'Timeline-Bereiche');

    const mainScrollbar = document.querySelector('.main-scrollbar');
    if (mainScrollbar) {
        mainScrollbar.addEventListener('scroll', function () {
            const scrollLeft = this.scrollLeft;

            // Verwende transform: translateX() für perfekte Synchronisation
            syncElements.forEach(element => {
                if (element) {
                    element.style.transform = `translateX(-${scrollLeft}px)`;
                }
            });

            console.log(`Scroll sync: translateX(-${scrollLeft}px) auf ${syncElements.length} Elemente`);
        });

        console.log('Transform-basierte Scroll-Synchronisation eingerichtet');
    } else {
        console.warn('Main scrollbar nicht gefunden');
    }

    // Vertikale Synchronisation (Zimmerbereich) bleibt unverändert
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
    startDate.setDate(today.getDate() - 7); // 1 Woche vor heute
    const endDate = new Date(today);
    endDate.setDate(today.getDate() + (10 * 7)); // 10 Wochen nach heute

    document.getElementById('startDate').value = startDate.toISOString().split('T')[0];
    document.getElementById('endDate').value = endDate.toISOString().split('T')[0];

    console.log('Dates reset to extended range: 1 week before to 10 weeks after today');
}

// Initialize timeline on page load
document.addEventListener('DOMContentLoaded', async function () {
    console.log('Timeline initialized');
    resetDates();

    // Set up vertical separator dragging
    setupVerticalSeparator();

    // Set up horizontal separators dragging
    setupHorizontalSeparators();

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

    // Automatisch echte Daten laden beim Seitenaufruf
    console.log('Auto-loading real data on page load...');
    const statusInfo = document.getElementById('status-info');

    if (statusInfo) {
        statusInfo.textContent = 'Lade Daten automatisch...';
        statusInfo.style.color = '#007bff';
    }

    try {
        await loadRealData();
        console.log('Auto-load completed successfully');

        if (statusInfo) {
            statusInfo.textContent = `Daten geladen: ${reservations.length} Reservierungen`;
            statusInfo.style.color = '#28a745';
        }
    } catch (error) {
        console.error('Auto-load failed:', error);
        // Bei Fehler: Fallback zu Demo-Daten oder leere Timeline anzeigen
        debugLog('Auto-load failed, showing empty timeline: ' + error.message);

        if (statusInfo) {
            statusInfo.textContent = 'Fehler beim Laden der Daten';
            statusInfo.style.color = '#dc3545';
        }

        // Stelle sicher, dass die Timeline trotzdem gerendert wird
        try {
            await loadRooms(); // Lade zumindest die Zimmer
            renderTimeline(); // Rendere leere Timeline
        } catch (fallbackError) {
            console.error('Fallback rendering also failed:', fallbackError);
            debugLog('Complete initialization failed: ' + fallbackError.message);
        }
    }
});

// Setup vertical separator for resizing
function setupVerticalSeparator() {
    const separator = document.getElementById('vertical-separator');
    if (!separator) return;

    // Initialisiere Position basierend auf CSS-Variable
    const initialWidth = 80; // Standard-Breite
    document.documentElement.style.setProperty('--sidebar-width', initialWidth + 'px');
    separator.style.left = initialWidth + 'px';

    let isDragging = false;

    separator.addEventListener('mousedown', function (e) {
        isDragging = true;
        document.body.style.cursor = 'col-resize';
        e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
        if (!isDragging) return;

        const containerRect = document.querySelector('.main-areas-container').getBoundingClientRect();
        const newWidth = e.clientX - containerRect.left;

        if (newWidth >= 60 && newWidth <= 200) {
            separator.style.left = newWidth + 'px';
            document.documentElement.style.setProperty('--sidebar-width', newWidth + 'px');

            // CSS-Variable wird automatisch von allen Elementen übernommen
            console.log('Sidebar width updated to:', newWidth + 'px');
        }
    });

    document.addEventListener('mouseup', function () {
        isDragging = false;
        document.body.style.cursor = '';
    });
}

// Setup horizontal separators for resizing timeline areas
function setupHorizontalSeparators() {
    const separator1 = document.getElementById('separator1');
    const separator2 = document.getElementById('separator2');

    if (separator1) {
        setupHorizontalSeparator(separator1, '.master-reservations-area', '.room-assignment-area');
    }

    if (separator2) {
        setupHorizontalSeparator(separator2, '.room-assignment-area', '.histogram-area');
    }
}

function setupHorizontalSeparator(separator, topAreaSelector, bottomAreaSelector) {
    let isDragging = false;
    let startY = 0;
    let startTopHeight = 0;
    let startBottomHeight = 0;

    const topArea = document.querySelector(topAreaSelector);
    const bottomArea = document.querySelector(bottomAreaSelector);

    console.log(`🔧 Setup horizontal separator: ${topAreaSelector} ↔ ${bottomAreaSelector}`);

    if (!topArea || !bottomArea) {
        console.warn(`❌ Horizontal separator areas not found: ${topAreaSelector}, ${bottomAreaSelector}`);
        return;
    }

    console.log(`✅ Found areas:`, {
        topArea: topArea.className,
        bottomArea: bottomArea.className,
        topCurrentHeight: topArea.getBoundingClientRect().height + 'px',
        bottomCurrentHeight: bottomArea.getBoundingClientRect().height + 'px',
        topComputedStyle: {
            minHeight: getComputedStyle(topArea).minHeight,
            maxHeight: getComputedStyle(topArea).maxHeight,
            flexBasis: getComputedStyle(topArea).flexBasis,
            flex: getComputedStyle(topArea).flex
        },
        bottomComputedStyle: {
            minHeight: getComputedStyle(bottomArea).minHeight,
            maxHeight: getComputedStyle(bottomArea).maxHeight,
            flexBasis: getComputedStyle(bottomArea).flexBasis,
            flex: getComputedStyle(bottomArea).flex
        }
    });

    separator.addEventListener('mousedown', function (e) {
        isDragging = true;
        startY = e.clientY;

        // Aktuelle Höhen ermitteln
        startTopHeight = topArea.getBoundingClientRect().height;
        startBottomHeight = bottomArea.getBoundingClientRect().height;

        document.body.style.cursor = 'row-resize';
        document.body.style.userSelect = 'none';
        e.preventDefault();

        console.log(`🚀 Starting horizontal separator drag:`, {
            startY: startY,
            topAreaHeight: startTopHeight + 'px',
            bottomAreaHeight: startBottomHeight + 'px',
            topAreaSelector: topAreaSelector,
            bottomAreaSelector: bottomAreaSelector,
            separatorId: separator.id
        });
    });

    document.addEventListener('mousemove', function (e) {
        if (!isDragging) return;

        const deltaY = e.clientY - startY;
        const newTopHeight = startTopHeight + deltaY;
        const newBottomHeight = startBottomHeight - deltaY;

        // GANZ EINFACHE PIXEL-GRENZEN
        const topMin = 50;    // Minimum 50px für oberen Bereich
        const topMax = 600;   // Maximum 600px für oberen Bereich
        const bottomMin = 80; // Minimum 80px für unteren Bereich

        console.log(`📏 Drag calculation:`, {
            currentY: e.clientY,
            deltaY: deltaY,
            newTopHeight: newTopHeight,
            newBottomHeight: newBottomHeight,
            limits: {
                topMin: topMin,
                topMax: topMax,
                bottomMin: bottomMin
            },
            withinLimits: newTopHeight >= topMin && newTopHeight <= topMax && newBottomHeight >= bottomMin
        });

        // EINFACHE PIXEL-PRÜFUNG
        if (newTopHeight >= topMin && newTopHeight <= topMax && newBottomHeight >= bottomMin) {

            // Setze neue flex-basis Werte
            const oldTopStyle = {
                flexBasis: topArea.style.flexBasis,
                flex: topArea.style.flex
            };
            const oldBottomStyle = {
                flexBasis: bottomArea.style.flexBasis,
                flex: bottomArea.style.flex
            };

            topArea.style.flexBasis = newTopHeight + 'px';
            topArea.style.maxHeight = 'none'; // WICHTIG: CSS max-height überschreiben!

            // Für Histogramm: Setze flex-basis nur wenn es nicht flex: 1 ist
            if (bottomAreaSelector === '.histogram-area') {
                bottomArea.style.flex = `1 1 ${newBottomHeight}px`; // flex-grow: 1, aber mit basis
            } else {
                bottomArea.style.flexBasis = newBottomHeight + 'px';
                bottomArea.style.maxHeight = 'none'; // WICHTIG: CSS max-height überschreiben!
            }

            console.log(`✅ Applied new heights:`, {
                topArea: {
                    selector: topAreaSelector,
                    oldStyle: oldTopStyle,
                    newFlexBasis: topArea.style.flexBasis,
                    newFlex: topArea.style.flex,
                    actualHeight: topArea.getBoundingClientRect().height + 'px'
                },
                bottomArea: {
                    selector: bottomAreaSelector,
                    oldStyle: oldBottomStyle,
                    newFlexBasis: bottomArea.style.flexBasis,
                    newFlex: bottomArea.style.flex,
                    actualHeight: bottomArea.getBoundingClientRect().height + 'px'
                }
            });
        } else {
            console.log(`🚫 Height change rejected - outside limits:`, {
                newTopHeight: newTopHeight,
                newBottomHeight: newBottomHeight,
                topLimits: `${topMin}-${topMax}`,
                bottomLimits: `min ${bottomMin}px`
            });
        }
    });

    document.addEventListener('mouseup', function () {
        if (isDragging) {
            isDragging = false;
            document.body.style.cursor = '';
            document.body.style.userSelect = '';

            console.log(`🏁 Horizontal separator drag ended:`, {
                finalTopHeight: topArea.getBoundingClientRect().height + 'px',
                finalBottomHeight: bottomArea.getBoundingClientRect().height + 'px',
                topAreaFlex: topArea.style.flex,
                topAreaFlexBasis: topArea.style.flexBasis,
                bottomAreaFlex: bottomArea.style.flex,
                bottomAreaFlexBasis: bottomArea.style.flexBasis
            });
        }
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
window.debugUpdateRoomLabels = function () {
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
