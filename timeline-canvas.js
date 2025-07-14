// Timeline Canvas - Einheitliche Zeichenfläche für alle Bereiche
let reservations = [];
let rooms = [];
let DAY_WIDTH = 80;
const VERTICAL_GAP = 2;

// Neue Architektur: Feste Separator-Positionen
const HEADER_HEIGHT = 50;
const SEPARATOR_HEIGHT = 8;
let separatorPositions = {
    separator1: 40,   // FEST: Header (50px) + fixer Abstand (40px) = 40px
    separator2: 750,  // Position zwischen Master und Rooms (draggable)
    separator3: 850   // Position zwischen Rooms und Histogram (draggable)
};

// Berechne Bereiche basierend auf Separator-Positionen
function calculateAreaHeights() {
    return {
        HEADER_HEIGHT: HEADER_HEIGHT,
        MASTER_HEIGHT: separatorPositions.separator1 - HEADER_HEIGHT,
        ROOM_HEIGHT: separatorPositions.separator2 - separatorPositions.separator1,
        HISTOGRAM_HEIGHT: separatorPositions.separator3 - separatorPositions.separator2
    };
}

let canvas, ctx;
let totalWidth = 0;
let totalHeight = 0;
let scrollX = 0;
let scrollY = 0;
let masterContentHeight = 0;
let scrollY = 0;
let masterMaxLevels = 0;
let startDate, endDate;

// Initialisierung
function initializeCanvas() {
    canvas = document.getElementById('timeline-canvas');
    ctx = canvas.getContext('2d');
    
    // High-DPI Support
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    
    // Event Listeners
    setupCanvasEvents();
    
    // Window resize listener
    window.addEventListener('resize', resizeCanvas);
    
    console.log('Canvas initialisiert:', canvas.width, 'x', canvas.height);
}

// Timeline rendern
function renderTimeline() {
    if (!canvas || !ctx) {
        console.error('Canvas nicht initialisiert');
        return;
    }
    
    if (reservations.length === 0) {
        drawEmptyState();
        return;
    }
    
    // Berechne Dimensionen
    calculateDimensions();
    
    // Lösche Canvas
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    const areas = calculateAreaHeights();
    
    // Zeichne alle Bereiche basierend auf festen Positionen
    
    // 1. Datumsheader (0 bis HEADER_HEIGHT)
    drawDateHeader(0, areas.HEADER_HEIGHT);
    
    // 2. Separator 1 (bei separatorPositions.separator1)
    drawSeparator(separatorPositions.separator1, 'separator1');
    
    // 3. Master-Reservierungen (zwischen separator1 und separator2)
    drawMasterReservations(separatorPositions.separator1 + SEPARATOR_HEIGHT, areas.MASTER_HEIGHT);
    
    // 4. Separator 2 (bei separatorPositions.separator2)
    drawSeparator(separatorPositions.separator2, 'separator2');
    
    // 5. Zimmerbereich (zwischen separator2 und separator3)
    drawRoomAssignments(separatorPositions.separator2 + SEPARATOR_HEIGHT, areas.ROOM_HEIGHT);
    
    // 6. Separator 3 (bei separatorPositions.separator3)
    drawSeparator(separatorPositions.separator3, 'separator3');
    
    // 7. Histogramm (nach separator3)
    drawHistogram(separatorPositions.separator3 + SEPARATOR_HEIGHT, areas.HISTOGRAM_HEIGHT);
    
    // Scrollbar aktualisieren
    updateScrollbar();
    
    console.log('Timeline gerendert mit Separator-Positionen:', separatorPositions);
}

// Dimensionen berechnen
function calculateDimensions() {
    const numDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1;
    totalWidth = numDays * DAY_WIDTH;
    
    // Berechne Gesamthöhe basierend auf Separator-Positionen
    const areas = calculateAreaHeights();
    totalHeight = separatorPositions.separator3 + areas.HISTOGRAM_HEIGHT + 20;
    
    // Canvas-Höhe an berechnete Höhe anpassen
    if (canvas) {
        const rect = canvas.getBoundingClientRect();
        canvas.style.height = Math.min(totalHeight, window.innerHeight - 160) + 'px';
        
        // Re-initialize canvas with new dimensions
        const dpr = window.devicePixelRatio || 1;
        canvas.width = rect.width * dpr;
        canvas.height = parseInt(canvas.style.height) * dpr;
        ctx.scale(dpr, dpr);
    }
}

// 1. Datumsheader zeichnen
function drawDateHeader(startY, height) {
    // Erst die Tageshintergründe zeichnen
    drawDayBackgrounds(startY, height);
    
    const currentDate = new Date(startDate);
    let x = -scrollX;
    
    // Datumsspalten
    ctx.font = '11px Arial';
    ctx.textAlign = 'center';
    ctx.fillStyle = '#333';
    
    while (currentDate <= endDate) {
        // Tagesraster
        ctx.strokeStyle = '#ddd';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, startY);
        ctx.lineTo(x, startY + height);
        ctx.stroke();
        
        // Wochenendhintergrund
        const dayOfWeek = currentDate.getDay();
        if (dayOfWeek === 0 || dayOfWeek === 6) {
            ctx.fillStyle = '#ffe6e6';
            ctx.fillRect(x, startY, DAY_WIDTH, height);
            ctx.fillStyle = '#333';
        }
        
        // Datum-Text
        const weekday = currentDate.toLocaleDateString('de-DE', {weekday: 'short'});
        const dateStr = currentDate.toLocaleDateString('de-DE', {day: '2-digit', month: '2-digit'});
        
        ctx.fillText(weekday, x + DAY_WIDTH/2, startY + height/2 - 5);
        ctx.fillText(dateStr, x + DAY_WIDTH/2, startY + height/2 + 8);
        
        x += DAY_WIDTH;
        currentDate.setDate(currentDate.getDate() + 1);
    }
}

// 2. Master-Reservierungen zeichnen
function drawMasterReservations(startY, height) {
    // Tageshintergründe (horizontales Scrollen)
    drawDayBackgrounds(startY, height);
    // Clip Master-Bereich für vertikales Scrollen
    ctx.save();
    ctx.beginPath(); ctx.rect(0, startY, canvas.clientWidth, height); ctx.clip();
    
    // Stack-Algorithmus für Reservierungen mit 2h-Toleranz
    const stackLevels = [];
    const COLLISION_TOLERANCE = (2 / 24) * DAY_WIDTH; // 2 Stunden in Pixeln
    
    // WICHTIG: Sortiere Reservierungen nach Start-Zeit für besseres Stacking
    const sortedReservations = [...reservations].sort((a, b) => {
        const startA = new Date(a.start);
        const startB = new Date(b.start);
        return startA.getTime() - startB.getTime();
    });
    
    sortedReservations.forEach(reservation => {
        // Zeitberechnung für DARSTELLUNG: Mitte des Aufenthalts = Mitternacht
        const originalStart = new Date(reservation.start);
        const originalEnd = new Date(reservation.end);
        
        // Berechne die Mitte des ursprünglichen Aufenthalts
        const middleTime = new Date((originalStart.getTime() + originalEnd.getTime()) / 2);
        middleTime.setHours(0, 0, 0, 0); // Mitte = Mitternacht
        
        // Berechne halbe Aufenthaltsdauer
        const originalDuration = (originalEnd.getTime() - originalStart.getTime()) / (1000 * 60 * 60 * 24);
        const halfDuration = originalDuration / 2;
        
        // Darstellungszeiten: Mitte um Mitternacht
        const checkinTime = new Date(middleTime.getTime() - (halfDuration * 24 * 60 * 60 * 1000) + (1 * 60 * 60 * 1000));
        const checkoutTime = new Date(middleTime.getTime() + (halfDuration * 24 * 60 * 60 * 1000)- (1 * 60 * 60 * 1000));
        
        const timelineStart = new Date(startDate);
        timelineStart.setHours(0, 0, 0, 0);
        
        const startOffset = (checkinTime.getTime() - timelineStart.getTime()) / (1000 * 60 * 60 * 24);
        const duration = (checkoutTime.getTime() - checkinTime.getTime()) / (1000 * 60 * 60 * 24);
        
        const left = startOffset * DAY_WIDTH - scrollX;
        const width = duration * DAY_WIDTH;
        
        // Finde erstes Level ohne Überlappung (Berücksichtige Toleranz)
        let stackLevel = 0;
        while (stackLevel < stackLevels.length && stackLevels[stackLevel] + COLLISION_TOLERANCE > left) {
            stackLevel++;
        }
        // Setze Ende dieses Levels auf das Ende der aktuellen Reservierung
        stackLevels[stackLevel] = left + width;
        
        const top = startY + 10 + (stackLevel * (18 + VERTICAL_GAP));
        
        // Reservierungsbalken zeichnen (mit semi-transparenz für Hintergründe)
        drawReservationBar(reservation, left, width, top);
    });
}

// 3. Zimmerbereich zeichnen
function drawRoomAssignments(startY, height) {
    // Hintergrund mit Tagesraster
    drawDayBackgrounds(startY, height);
    
    // Zimmerzeilen
    const roomRowHeight = 16;
    let y = startY + 5;
    
    rooms.forEach((room, index) => {
        // Zimmer-Label
        ctx.fillStyle = '#f8f9fa';
        ctx.fillRect(-scrollX, y, 100, roomRowHeight);
        
        ctx.fillStyle = '#333';
        ctx.font = '11px Arial';
        ctx.textAlign = 'left';
        ctx.fillText(room.display_name || room.caption, -scrollX + 5, y + 12);
        
        // Trennlinie
        ctx.strokeStyle = '#eee';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(-scrollX, y + roomRowHeight);
        ctx.lineTo(-scrollX + totalWidth, y + roomRowHeight);
        ctx.stroke();
        
        y += roomRowHeight;
    });
}

// 4. Histogramm zeichnen
function drawHistogram(startY, height) {
    // Hintergrund mit Tagesraster
    drawDayBackgrounds(startY, height);
    
    // Horizontale Rasterlinien (0%, 25%, 50%, 75%, 100%)
    ctx.strokeStyle = '#ddd';
    ctx.lineWidth = 1;
    ctx.font = '10px Arial';
    ctx.textAlign = 'right';
    ctx.fillStyle = '#666';
    
    for (let percent = 0; percent <= 100; percent += 25) {
        const y = startY + height - (percent / 100 * height);
        
        ctx.beginPath();
        ctx.moveTo(-scrollX, y);
        ctx.lineTo(-scrollX + totalWidth, y);
        ctx.stroke();
        
        // Beschriftung
        ctx.fillText(percent + '%', -scrollX - 5, y + 3);
    }
    
    // Auslastungsbalken pro Tag
    const currentDate = new Date(startDate);
    let x = -scrollX;
    
    while (currentDate <= endDate) {
        const dailyGuests = calculateDailyOccupancy(currentDate);
        const occupancyPercent = (dailyGuests / 100) * 100; // Beispiel-Max: 100 Gäste
        const barHeight = Math.max(2, occupancyPercent / 100 * height);
        
        // Histogramm-Balken
        const gradient = ctx.createLinearGradient(0, startY + height, 0, startY + height - barHeight);
        gradient.addColorStop(0, '#007bff');
        gradient.addColorStop(1, '#17a2b8');
        
        ctx.fillStyle = gradient;
        ctx.fillRect(x + 1, startY + height - barHeight, DAY_WIDTH - 2, barHeight);
        
        x += DAY_WIDTH;
        currentDate.setDate(currentDate.getDate() + 1);
    }
}

// Separator zeichnen
function drawSeparator(startY, id) {
    // Separator-Hintergrund (über die gesamte Canvas-Breite)
    ctx.fillStyle = '#e0e0e0';
    ctx.fillRect(0, startY, canvas.clientWidth, SEPARATOR_HEIGHT);
    
    // Separator-Handle (für Drag) - zentriert und gut sichtbar
    const handleWidth = 80;
    const handleX = (canvas.clientWidth - handleWidth) / 2;
    
    // Handle-Farbe abhängig davon, ob Separator fest ist
    if (id === 'separator1') {
        // Separator 1 ist fest - andere Farbe
        ctx.fillStyle = '#ddd';
        ctx.fillRect(handleX, startY + 1, handleWidth, SEPARATOR_HEIGHT - 2);
        ctx.strokeStyle = '#ccc';
    } else {
        // Separator 2 und 3 sind verschiebbar
        ctx.fillStyle = '#bbb';
        ctx.fillRect(handleX, startY + 1, handleWidth, SEPARATOR_HEIGHT - 2);
        ctx.strokeStyle = '#999';
    }
    
    // Handle-Rand für bessere Sichtbarkeit
    ctx.lineWidth = 1;
    ctx.strokeRect(handleX, startY + 1, handleWidth, SEPARATOR_HEIGHT - 2);
    
    // Drag-Punkte nur für verschiebbare Separatoren
    if (id !== 'separator1') {
        ctx.fillStyle = '#666';
        for (let i = 0; i < 5; i++) {
            const dotX = handleX + 15 + (i * 12);
            const dotY = startY + SEPARATOR_HEIGHT / 2;
            ctx.beginPath();
            ctx.arc(dotX, dotY, 1.5, 0, 2 * Math.PI);
            ctx.fill();
        }
    } else {
        // Für festen Separator: Zeige "FEST" Text
        ctx.fillStyle = '#999';
        ctx.font = '10px Arial';
        ctx.textAlign = 'center';
        ctx.fillText('FEST', handleX + handleWidth/2, startY + SEPARATOR_HEIGHT/2 + 3);
    }
    
    // Debug-Text (entfernbar nach Tests)
    ctx.fillStyle = '#666';
    ctx.font = '10px Arial';
    ctx.textAlign = 'left';
    ctx.fillText(`${id} (${startY}px)`, 10, startY + SEPARATOR_HEIGHT - 1);
    
    return startY + SEPARATOR_HEIGHT;
}

// Tagesraster-Hintergrund zeichnen
function drawDayBackgrounds(startY, height) {
    const currentDate = new Date(startDate);
    let x = -scrollX;
    let dayIndex = 0;
    
    while (currentDate <= endDate) {
        const dayOfWeek = currentDate.getDay();
        
        // Wechselnde Tageshintergründe
        if (dayIndex % 2 === 0) {
            ctx.fillStyle = '#f5f7ff';
        } else {
            ctx.fillStyle = '#e3eaff';
        }
        
        // Wochenende überschreibt normale Tage
        if (dayOfWeek === 0 || dayOfWeek === 6) {
            ctx.fillStyle = '#ffd6d6';
        }
        
        ctx.fillRect(x, startY, DAY_WIDTH, height);
        
        // Vertikale Gitternetzlinie
        ctx.strokeStyle = '#ddd';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, startY);
        ctx.lineTo(x, startY + height);
        ctx.stroke();
        
        x += DAY_WIDTH;
        dayIndex++;
        currentDate.setDate(currentDate.getDate() + 1);
    }
}

// Reservierungsbalken zeichnen
function drawReservationBar(reservation, x, width, y) {
    const height = 14;
    
    // Farbcodierung nach Kapazität (mit leichter Transparenz für Hintergründe)
    let color = '#28a745'; // 1-2 Personen
    if (reservation.capacity >= 20) color = '#dc3545';
    else if (reservation.capacity >= 11) color = '#fd7e14';
    else if (reservation.capacity >= 6) color = '#ffc107';
    else if (reservation.capacity >= 3) color = '#17a2b8';
    
    // Balken zeichnen mit leichter Transparenz (0.95 statt 1.0)
    ctx.globalAlpha = 0.95;
    ctx.fillStyle = color;
    ctx.fillRect(x, y, width, height);
    
    // Rahmen (etwas stärker für bessere Abgrenzung)
    ctx.globalAlpha = 1.0;
    ctx.strokeStyle = 'rgba(0,0,0,0.3)';
    ctx.lineWidth = 1.5;
    ctx.strokeRect(x, y, width, height);
    
    // Text
    ctx.fillStyle = color === '#ffc107' ? '#000' : '#fff';
    ctx.font = '10px Arial';
    ctx.textAlign = 'left';
    
    const text = `${reservation.nachname || reservation.name}, ${reservation.vorname || ''} (${reservation.capacity})`;
    ctx.fillText(text, x + 4, y + 10);
    
    // Hund-Icon (falls vorhanden)
    if ((reservation.fullData && reservation.fullData.has_dog) || reservation.hund) {
        ctx.fillStyle = '#fff';
        ctx.font = '10px Arial';
        ctx.textAlign = 'right';
        ctx.fillText('🐕', x + width - 4, y + 10);
    }
    
    // Alpha zurücksetzen
    ctx.globalAlpha = 1.0;
}

// Canvas-Events
function setupCanvasEvents() {
    let isDragging = false;
    let dragStartY = 0;
    let dragSeparator = null;
    
    console.log('Canvas Events Setup');
    
    // Mouse-Events
    canvas.addEventListener('mousedown', (e) => {
        const rect = canvas.getBoundingClientRect();
        const x = e.clientX - rect.left + scrollX;
        const y = e.clientY - rect.top;
        
        console.log('Mouse down at:', x, y);
        
        // Prüfe Separator-Drag
        dragSeparator = checkSeparatorHit(x, y);
        console.log('Separator hit check result:', dragSeparator);
        
        if (dragSeparator && dragSeparator !== 'separator1') {
            // Separator 1 ist fest - erlaube kein Drag
            isDragging = true;
            dragStartY = e.clientY;
            canvas.style.cursor = 'row-resize';
            console.log('Starting drag for separator:', dragSeparator);
            return;
        } else if (dragSeparator === 'separator1') {
            console.log('Separator 1 ist fest und kann nicht verschoben werden');
            return;
        }
        
        // Prüfe Reservierungs-Click
        const reservation = checkReservationHit(x, y);
        if (reservation) {
            showReservationDetails(reservation);
        }
    });
    
    canvas.addEventListener('mousemove', (e) => {
        const rect = canvas.getBoundingClientRect();
        const x = e.clientX - rect.left + scrollX;
        const y = e.clientY - rect.top;
        
        if (isDragging && dragSeparator) {
            const deltaY = e.clientY - dragStartY;
            resizeSeparator(dragSeparator, deltaY);
            dragStartY = e.clientY;
            calculateDimensions();
            renderTimeline();
            updateScrollbar();
        } else {
            // Cursor ändern bei Separator-Hover
            const separatorHit = checkSeparatorHit(x, y);
            if (separatorHit === 'separator1') {
                canvas.style.cursor = 'not-allowed'; // Separator 1 ist fest
            } else if (separatorHit) {
                canvas.style.cursor = 'row-resize'; // Separator 2 und 3 sind verschiebbar
            } else {
                canvas.style.cursor = 'default';
            }
        }
    });
    
    canvas.addEventListener('mouseup', () => {
        isDragging = false;
        dragSeparator = null;
        canvas.style.cursor = 'default';
    });
    
    // Scroll-Events (horizontal)
    canvas.addEventListener('wheel', (e) => {
        e.preventDefault();
        if (e.shiftKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) {
            // Horizontal scrollen
            scrollX += e.deltaX || e.deltaY;
            scrollX = Math.max(0, Math.min(scrollX, Math.max(0, totalWidth - canvas.clientWidth)));
            renderTimeline();
            updateScrollbar();
        }
    });
    
    // Setup horizontaler Scrollbar
    setupHorizontalScrollbar();
    // Setup vertikaler Master-Scrollbar
    setupMasterScrollbar();
}

// Horizontaler Scrollbar Setup
function setupHorizontalScrollbar() {
    const scrollbar = document.getElementById('canvas-scrollbar');
    const scrollTrack = document.getElementById('canvas-scroll-track');
    
    if (!scrollbar || !scrollTrack) {
        console.warn('Scrollbar-Elemente nicht gefunden');
        return;
    }
    
    // Event Listener für Scrollbar
    scrollbar.addEventListener('scroll', (e) => {
        scrollX = e.target.scrollLeft;
        renderTimeline();
    });
    
    updateScrollbar();
}

// Scrollbar aktualisieren
function updateScrollbar() {
    const scrollbar = document.getElementById('canvas-scrollbar');
    const scrollTrack = document.getElementById('canvas-scroll-track');
    
    if (!scrollbar || !scrollTrack) return;
    
    // Track-Breite setzen
    scrollTrack.style.width = totalWidth + 'px';
    
    // Scrollbar-Position synchronisieren
    if (scrollbar.scrollLeft !== scrollX) {
        scrollbar.scrollLeft = scrollX;
    }
    // Sync Master-Scrollbar
    updateMasterScrollbar();
}

// Setup und Synchronisation für vertikalen Master-Scrollbar
function setupMasterScrollbar() {
    const msb = document.getElementById('master-scrollbar');
    const mtrack = document.getElementById('master-scroll-track');
    if (!msb || !mtrack) return;
    msb.addEventListener('scroll', e => {
        scrollY = e.target.scrollTop;
        renderTimeline();
    });
    updateMasterScrollbar();
}

function updateMasterScrollbar() {
    const msb = document.getElementById('master-scrollbar');
    const mtrack = document.getElementById('master-scroll-track');
    if (!msb || !mtrack) return;
    // Set Track-Höhe entsprechend Content
    mtrack.style.height = masterContentHeight + 'px';
    if (msb.scrollTop !== scrollY) {
        msb.scrollTop = scrollY;
    }
}

// Canvas-Größe bei Resize anpassen
function resizeCanvas() {
    if (!canvas) return;
    
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    canvas.style.width = rect.width + 'px';
    canvas.style.height = rect.height + 'px';
    
    ctx.scale(dpr, dpr);
    
    calculateDimensions();
    renderTimeline();
    updateScrollbar();
}

// Hit-Testing
function checkSeparatorHit(x, y) {
    // Separator 1 (zwischen Header und Master)
    if (y >= separatorPositions.separator1 && y <= separatorPositions.separator1 + SEPARATOR_HEIGHT) {
        return 'separator1';
    }
    
    // Separator 2 (zwischen Master und Rooms)
    if (y >= separatorPositions.separator2 && y <= separatorPositions.separator2 + SEPARATOR_HEIGHT) {
        return 'separator2';
    }
    
    // Separator 3 (zwischen Rooms und Histogram)
    if (y >= separatorPositions.separator3 && y <= separatorPositions.separator3 + SEPARATOR_HEIGHT) {
        return 'separator3';
    }
    
    return null;
}

function checkReservationHit(x, y) {
    // Implementierung für Reservierungs-Hit-Testing
    // TODO: Vereinfacht - sollte Stack-Level berücksichtigen
    return null;
}

// Separator-Resize
function resizeSeparator(separator, deltaY) {
    switch (separator) {
        case 'separator1':
            // Separator 1: FEST bei 90px - keine Änderung erlaubt
            console.log('Separator 1 ist fest und kann nicht verschoben werden');
            break;
        case 'separator2':
            // Separator 2: Verändere nur seine Position (zwischen Master und Rooms)
            separatorPositions.separator2 = Math.max(separatorPositions.separator1 + 100, separatorPositions.separator2 + deltaY);
            break;
        case 'separator3':
            // Separator 3: Verändere nur seine Position (zwischen Rooms und Histogram)
            separatorPositions.separator3 = Math.max(separatorPositions.separator2 + 50, separatorPositions.separator3 + deltaY);
            break;
    }
    
    console.log('Separator-Positionen:', separatorPositions);
}

// Hilfsfunktionen
function calculateDailyOccupancy(date) {
    let guestCount = 0;
    reservations.forEach(reservation => {
        const checkin = new Date(reservation.start);
        checkin.setHours(12, 0, 0, 0);
        const checkout = new Date(reservation.end);
        checkout.setHours(12, 0, 0, 0);
        
        const checkDate = new Date(date);
        checkDate.setHours(12, 0, 0, 0);
        
        if (checkDate >= checkin && checkDate < checkout) {
            guestCount += reservation.capacity;
        }
    });
    return guestCount;
}

function drawEmptyState() {
    ctx.fillStyle = '#666';
    ctx.font = '16px Arial';
    ctx.textAlign = 'center';
    ctx.fillText('Keine Reservierungen im gewählten Zeitraum', canvas.width/2, canvas.height/2);
}

function showReservationDetails(reservation) {
    // TODO: Details-Panel anzeigen
    console.log('Reservierung angeklickt:', reservation);
}

// Integration mit bestehenden Funktionen
async function loadRealData() {
    try {
        const startDateInput = document.getElementById('startDate').value;
        const endDateInput = document.getElementById('endDate').value;
        
        if (!startDateInput || !endDateInput) {
            alert('Bitte wählen Sie einen Start- und End-Datum aus!');
            return;
        }
        
        startDate = new Date(startDateInput);
        endDate = new Date(endDateInput);
        
        const response = await fetch(`getZimmerplanData.php?start=${startDateInput}&end=${endDateInput}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'API-Fehler');
        }
        
        // Konvertiere API-Daten
        reservations = result.data.timeline_items
            .filter(item => item.content && item.start && item.end)
            .map((item, index) => ({
                id: item.id || index,
                name: item.data ? item.data.guest_name : item.content.replace(/<[^>]*>/g, ''),
                nachname: item.data ? item.data.guest_name.split(' ')[0] : '',
                vorname: item.data ? item.data.guest_name.split(' ').slice(1).join(' ') : '',
                capacity: item.data ? item.data.capacity : (item.capacity || 1),
                arrangement: item.data ? item.data.arrangement : '',
                start: parseGermanDate(item.start),
                end: parseGermanDate(item.end),
                fullData: item.data
            }));
        
        console.log(`${reservations.length} gültige Reservierungen gefunden`);
        
        // Lade Zimmer
        await loadRooms();
        
        renderTimeline();
        
    } catch (error) {
        console.error('Fehler beim Laden der echten Daten:', error);
        alert('Fehler beim Laden der echten Daten: ' + error.message);
    }
}

async function loadRooms() {
    try {
        const response = await fetch('getRooms.php');
        const result = await response.json();
        
        if (!response.ok || !result.success) {
            throw new Error(result.error || 'Fehler beim Laden der Zimmer');
        }
        
        rooms = result.data;
        console.log(`${rooms.length} Zimmer geladen`);
        
    } catch (error) {
        console.error('Fehler beim Laden der Zimmer:', error);
        // Fallback
        rooms = [
            { id: 1, caption: 'Zimmer 1', capacity: 2, display_name: 'Zimmer 1 (2)' },
            { id: 2, caption: 'Zimmer 2', capacity: 4, display_name: 'Zimmer 2 (4)' },
            { id: 3, caption: 'Zimmer 3', capacity: 3, display_name: 'Zimmer 3 (3)' }
        ];
    }
}

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

function resetDates() {
    const today = new Date();
    const oneWeekAgo = new Date(today);
    oneWeekAgo.setDate(today.getDate() - 7);
    
    const twoWeeksLater = new Date(today);
    twoWeeksLater.setDate(today.getDate() + 14);
    
    document.getElementById('startDate').value = oneWeekAgo.toISOString().split('T')[0];
    document.getElementById('endDate').value = twoWeeksLater.toISOString().split('T')[0];
    
    startDate = oneWeekAgo;
    endDate = twoWeeksLater;
}

// Initialisierung
document.addEventListener('DOMContentLoaded', () => {
    initializeCanvas();
    resetDates();
    
    // Event Listeners für Buttons
    document.getElementById('startDate').addEventListener('change', () => {
        if (document.getElementById('startDate').value && document.getElementById('endDate').value) {
            loadRealData();
        }
    });
    
    document.getElementById('endDate').addEventListener('change', () => {
        if (document.getElementById('startDate').value && document.getElementById('endDate').value) {
            loadRealData();
        }
    });
    
    // Zimmer laden
    loadRooms();
    
    // Automatisch Daten laden wenn beide Daten gesetzt sind
    if (document.getElementById('startDate').value && document.getElementById('endDate').value) {
        loadRealData();
    }
});
