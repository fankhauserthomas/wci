// TIMELINE UNIFIED RENDERER - Alles auf einer Fläche!
// Radikaler Umbau: Ein Canvas für alle Timeline-Bereiche

let reservations = [];
let roomDetails = [];
let rooms = [];
let DAY_WIDTH = 80;
const VERTICAL_GAP = 1;

// UNIFIED RENDERER - Zentrale Rendering-Engine
class TimelineUnifiedRenderer {
    constructor(containerSelector) {
        this.container = document.querySelector(containerSelector);
        this.canvas = null;
        this.ctx = null;
        this.scrollX = 0;
        this.scrollY = 0;
        
        // Mouse-Tracking für Hover-Effekte
        this.mouseX = 0;
        this.mouseY = 0;
        this.hoveredReservation = null;
    
    renderWeekendBackgrounds(startDate, endDate) {
        // Wochenend-Hintergründe für alle Timeline-Bereiche
        const startX = this.sidebarWidth - this.scrollX;

        // CLIPPING: Timeline-Bereich (rechts von Sidebar)
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, 0, this.canvas.width - this.sidebarWidth, this.canvas.height);
        this.ctx.clip();

        const currentDate = new Date(startDate);
        let dayIndex = 0;

        while (currentDate <= endDate) {
            const dayOfWeek = currentDate.getDay(); // 0 = Sonntag, 6 = Samstag
            
            // Wochenend-Hintergrund (Samstag und Sonntag)
            if (dayOfWeek === 0 || dayOfWeek === 6) {
                const x = startX + (dayIndex * DAY_WIDTH);
                this.ctx.fillStyle = 'rgba(255, 220, 220, 0.15)'; // Sehr transparentes Rosa
                this.ctx.fillRect(x, 0, DAY_WIDTH, this.canvas.height);
            }

            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        this.ctx.restore(); // CLIPPING beenden
    }

    renderHeader(startDate, endDate) {Selector(containerSelector);
        this.canvas = null;
        this.ctx = null;
        this.scrollX = 0;
        this.scrollY = 0;

        // Mouse tracking für Hover-Effekte
        this.mouseX = 0;
        this.mouseY = 0;
        this.hoveredReservation = null;

        // Layout-Bereiche
        this.areas = {
            header: { height: 40, y: 0 },
            master: { height: 200, y: 40 },
            rooms: { height: 400, y: 240 },
            histogram: { height: 140, y: 640 } // Mehr Platz für Beschriftungen
        };

        this.totalHeight = 780; // Angepasste Gesamthöhe
        this.sidebarWidth = 80;

        this.init();
    }

    init() {
        this.createCanvas();
        this.setupScrolling();
        this.setupEvents();
    }

    createCanvas() {
        // Container vorbereiten
        this.container.innerHTML = `
            <div class="timeline-unified-container" style="
                width: 100%;
                height: ${this.totalHeight}px;
                position: relative;
                overflow: hidden;
                border: 1px solid #ddd;
                background: #f8f9fa;
            ">
                <canvas id="timeline-canvas" style="
                    position: absolute;
                    top: 0;
                    left: 0;
                    cursor: default;
                "></canvas>
                
                <!-- Scrollbars -->
                <div class="scroll-horizontal" style="
                    position: absolute;
                    bottom: 0;
                    left: ${this.sidebarWidth}px;
                    right: 0;
                    height: 20px;
                    overflow-x: auto;
                    overflow-y: hidden;
                    background: #eee;
                ">
                    <div class="scroll-track-h" style="height: 1px; width: 2000px;"></div>
                </div>
                
                <div class="scroll-vertical" style="
                    position: absolute;
                    right: 0;
                    top: 0;
                    bottom: 20px;
                    width: 20px;
                    overflow-y: auto;
                    overflow-x: hidden;
                    background: #eee;
                ">
                    <div class="scroll-track-v" style="width: 1px; height: ${this.areas.rooms.height + 200}px;"></div>
                </div>
            </div>
        `;

        this.canvas = document.getElementById('timeline-canvas');
        this.ctx = this.canvas.getContext('2d');

        this.resizeCanvas();
    }

    resizeCanvas() {
        const rect = this.container.getBoundingClientRect();
        this.canvas.width = rect.width;
        this.canvas.height = this.totalHeight;
        this.canvas.style.width = rect.width + 'px';
        this.canvas.style.height = this.totalHeight + 'px';

        // Redraw after resize
        this.render();
    }

    setupScrolling() {
        const hScroll = this.container.querySelector('.scroll-horizontal');
        const vScroll = this.container.querySelector('.scroll-vertical');

        hScroll.addEventListener('scroll', () => {
            this.scrollX = hScroll.scrollLeft;
            this.render();
        });

        vScroll.addEventListener('scroll', () => {
            this.scrollY = vScroll.scrollTop;
            this.render();
        });
    }

    setupEvents() {
        window.addEventListener('resize', () => this.resizeCanvas());

        // Mouse-Events für Hover-Effekte mit Throttling für bessere Performance
        let hoverTimeout = null;
        this.canvas.addEventListener('mousemove', (e) => {
            const rect = this.canvas.getBoundingClientRect();
            this.mouseX = e.clientX - rect.left;
            this.mouseY = e.clientY - rect.top;

            // Sofortiges Re-render für schnellere Hover-Response
            if (hoverTimeout) clearTimeout(hoverTimeout);

            this.checkHover();
            this.render(); // Sofortiges Rendering für schnelle Response

            // Zusätzliche Optimierung: Reduziere unnötige Renders
            hoverTimeout = setTimeout(() => {
                // Cleanup wenn nötig
            }, 50);
        });

        this.canvas.addEventListener('mouseleave', () => {
            this.hoveredReservation = null;
            this.render();
        });
    }

    // HAUPT-RENDER-FUNKTION
    render() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        // Berechne Zeitraum
        if (reservations.length === 0) {
            this.renderEmpty();
            return;
        }

        const startDate = new Date(Math.min(...reservations.map(r => r.start.getTime())));
        const endDate = new Date(Math.max(...reservations.map(r => r.end.getTime())));
        const totalDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));
        const timelineWidth = totalDays * DAY_WIDTH;

        // Update horizontaler scroll track
        const scrollTrack = this.container.querySelector('.scroll-track-h');
        scrollTrack.style.width = timelineWidth + 'px';

        // Render alle Bereiche
        this.renderSidebar();
        this.renderWeekendBackgrounds(startDate, endDate); // Wochenend-Hintergründe zuerst
        this.renderHeader(startDate, endDate);
        this.renderMasterArea(startDate, endDate);
        this.renderRoomsArea(startDate, endDate);
        this.renderHistogramArea(startDate, endDate);
        this.renderVerticalGridLines(startDate, endDate); // Gitterlinien wieder über den Inhalten
        this.renderSeparators();

        // Update vertikaler scroll track basierend auf dynamischen Zimmer-Höhen
        const totalRoomHeight = rooms.reduce((sum, room) => sum + (room._dynamicHeight || 25), 0);
        const scrollTrackV = this.container.querySelector('.scroll-track-v');
        scrollTrackV.style.height = (totalRoomHeight + 200) + 'px';
    }

    renderEmpty() {
        this.ctx.fillStyle = '#666';
        this.ctx.font = '16px Arial';
        this.ctx.textAlign = 'center';
        this.ctx.fillText('Keine Daten geladen', this.canvas.width / 2, this.canvas.height / 2);
    }

    renderSidebar() {
        // Sidebar-Hintergrund
        this.ctx.fillStyle = '#f5f5f5';
        this.ctx.fillRect(0, 0, this.sidebarWidth, this.canvas.height);

        // Sidebar-Border
        this.ctx.strokeStyle = '#ddd';
        this.ctx.lineWidth = 1;
        this.ctx.beginPath();
        this.ctx.moveTo(this.sidebarWidth, 0);
        this.ctx.lineTo(this.sidebarWidth, this.canvas.height);
        this.ctx.stroke();

        // Labels
        this.ctx.fillStyle = '#333';
        this.ctx.font = '12px Arial';
        this.ctx.textAlign = 'center';

        // Header Label
        this.ctx.fillText('Datum', this.sidebarWidth / 2, this.areas.header.y + 25);

        // Master Label  
        this.ctx.fillText('Alle', this.sidebarWidth / 2, this.areas.master.y + 20);

        // Rooms Labels mit dynamischen Höhen
        const roomY = this.areas.rooms.y - this.scrollY;
        let currentYOffset = 0;

        rooms.forEach((room, index) => {
            // Verwende gespeicherte dynamische Höhe oder Standard-Höhe
            const roomHeight = room._dynamicHeight || 25;
            const labelY = roomY + currentYOffset + (roomHeight / 2) + 5; // Zentriert in der Zimmer-Zeile

            if (labelY > this.areas.rooms.y && labelY < this.areas.rooms.y + this.areas.rooms.height) {
                this.ctx.save();
                this.ctx.fillStyle = '#333';
                this.ctx.font = '10px Arial';
                this.ctx.textAlign = 'left';

                // Verbesserte Zimmer-Darstellung mit echten Daten
                let displayName = room.display_name;
                if (!displayName) {
                    const caption = room.caption || `Zimmer ${room.id}`;
                    const capacity = room.capacity ? ` (${room.capacity})` : '';
                    displayName = caption + capacity;
                }

                // Textbreite-Anpassung für Sidebar
                const maxWidth = this.sidebarWidth - 10;
                let text = displayName;
                const metrics = this.ctx.measureText(text);
                if (metrics.width > maxWidth) {
                    while (this.ctx.measureText(text + '...').width > maxWidth && text.length > 3) {
                        text = text.slice(0, -1);
                    }
                    text += '...';
                }

                this.ctx.fillText(text, 5, labelY);
                this.ctx.restore();
            }

            // Update Y-Offset für nächstes Zimmer
            currentYOffset += roomHeight;
        });

        // Histogram Label
        this.ctx.fillText('Auslastung', this.sidebarWidth / 2, this.areas.histogram.y + 20);
    }

    renderHeader(startDate, endDate) {
        const area = this.areas.header;
        const startX = this.sidebarWidth - this.scrollX;

        // Header-Hintergrund
        this.ctx.fillStyle = '#f8f9fa';
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING: Timeline-Bereich (rechts von Sidebar)
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Datum-Header
        this.ctx.fillStyle = '#333';
        this.ctx.font = '10px Arial';
        this.ctx.textAlign = 'center';

        const currentDate = new Date(startDate);
        let dayIndex = 0;

        while (currentDate <= endDate) {
            const x = startX + (dayIndex * DAY_WIDTH) + (DAY_WIDTH / 2);

            // Render ohne zusätzliche Sichtbarkeits-Prüfung (Clipping übernimmt das)
            // Wochentag
            const weekday = currentDate.toLocaleDateString('de-DE', { weekday: 'short' });
            this.ctx.fillText(weekday, x, area.y + 15);

            // Datum
            const dateStr = currentDate.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
            this.ctx.fillText(dateStr, x, area.y + 30);

            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        this.ctx.restore(); // CLIPPING beenden

        // Header-Border
        this.ctx.strokeStyle = '#ddd';
        this.ctx.lineWidth = 2;
        this.ctx.beginPath();
        this.ctx.moveTo(this.sidebarWidth, area.y + area.height);
        this.ctx.lineTo(this.canvas.width, area.y + area.height);
        this.ctx.stroke();
    }

    renderMasterArea(startDate, endDate) {
        const area = this.areas.master;
        const startX = this.sidebarWidth - this.scrollX;

        // Area-Hintergrund
        this.ctx.fillStyle = '#fff';
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING: Timeline-Bereich (rechts von Sidebar)
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Stack-Algorithmus für Master-Reservierungen
        const stackLevels = [];
        const OVERLAP_TOLERANCE = DAY_WIDTH * 0.25;

        const sortedReservations = [...reservations].sort((a, b) =>
            new Date(a.start).getTime() - new Date(b.start).getTime()
        );

        sortedReservations.forEach(reservation => {
            const checkinDate = new Date(reservation.start);
            checkinDate.setHours(12, 0, 0, 0);
            const checkoutDate = new Date(reservation.end);
            checkoutDate.setHours(12, 0, 0, 0);

            const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
            const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

            const left = startX + (startOffset + 0.1) * DAY_WIDTH;
            const width = (duration - 0.2) * DAY_WIDTH;

            // Stack-Level finden
            let stackLevel = 0;
            while (stackLevel < stackLevels.length &&
                stackLevels[stackLevel] > left + OVERLAP_TOLERANCE) {
                stackLevel++;
            }

            while (stackLevels.length <= stackLevel) {
                stackLevels.push(0);
            }

            stackLevels[stackLevel] = left + width + 5;

            const top = area.y + 10 + (stackLevel * 16);

            // Prüfe Hover-Status
            const isHovered = this.isReservationHovered(left, top, width, 14);

            // Speichere gehovertes Element für zusätzliche Effekte
            if (isHovered) {
                this.hoveredReservation = reservation;
            }

            // Render Reservation Bar mit Hover-Effekt (Clipping verhindert Überlauf in Sidebar)
            this.renderReservationBar(left, top, width, 14, reservation, isHovered);
        });

        this.ctx.restore(); // CLIPPING beenden
    }

    renderRoomsArea(startDate, endDate) {
        const area = this.areas.rooms;
        const startX = this.sidebarWidth - this.scrollX;
        const startY = area.y - this.scrollY;

        // Area-Hintergrund
        this.ctx.fillStyle = '#fff';
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING: Timeline-Bereich (rechts von Sidebar)
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Berechne sichtbaren Zeitbereich für dynamisches Stacking
        const visibleStartTime = startDate.getTime() + (this.scrollX / DAY_WIDTH) * (1000 * 60 * 60 * 24);
        const visibleEndTime = visibleStartTime + ((this.canvas.width - this.sidebarWidth) / DAY_WIDTH) * (1000 * 60 * 60 * 24);

        // Zimmer-Zeilen rendern mit 1px Abstand zwischen Balken
        let currentYOffset = 0;

        rooms.forEach((room, roomIndex) => {
            const baseRoomY = startY + currentYOffset;

            // Nur rendern wenn sichtbar
            if (baseRoomY > area.y - 50 && baseRoomY < area.y + area.height + 50) {
                // Zimmer-Reservierungen mit robustem ID-Matching
                const roomReservations = roomDetails.filter(detail => {
                    return detail.room_id === room.id ||
                        String(detail.room_id) === String(room.id) ||
                        Number(detail.room_id) === Number(room.id);
                });

                // Filter nur sichtbare Reservierungen für Stacking-Berechnung
                const visibleReservations = roomReservations.filter(detail => {
                    const startTime = new Date(detail.start).getTime();
                    const endTime = new Date(detail.end).getTime();

                    // Überschneidung mit sichtbarem Bereich prüfen
                    return startTime < visibleEndTime && endTime > visibleStartTime;
                });

                // DYNAMISCHES STACKING - nur für sichtbare Reservierungen mit 1px Abstand
                const stackLevels = [];
                const OVERLAP_TOLERANCE = DAY_WIDTH * 0.1;
                let maxStackLevel = 0;

                // Sortiere sichtbare Reservierungen nach Startzeit
                const sortedVisibleReservations = visibleReservations
                    .map(detail => {
                        const checkinDate = new Date(detail.start);
                        checkinDate.setHours(12, 0, 0, 0);
                        const checkoutDate = new Date(detail.end);
                        checkoutDate.setHours(12, 0, 0, 0);

                        const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                        const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                        const left = startX + (startOffset + 0.1) * DAY_WIDTH;
                        const width = (duration - 0.2) * DAY_WIDTH;

                        return { ...detail, left, width, startOffset };
                    })
                    .filter(item => item.left + item.width > this.sidebarWidth && item.left < this.canvas.width)
                    .sort((a, b) => a.startOffset - b.startOffset);

                // Berechne Stack-Level für jede sichtbare Reservierung
                sortedVisibleReservations.forEach((reservation, index) => {
                    // Finde freies Stack-Level
                    let stackLevel = 0;
                    while (stackLevel < stackLevels.length &&
                        stackLevels[stackLevel] > reservation.left - OVERLAP_TOLERANCE) {
                        stackLevel++;
                    }

                    // Erweitere Stack-Array wenn nötig
                    while (stackLevels.length <= stackLevel) {
                        stackLevels.push(0);
                    }

                    // Reserviere Platz für diese Reservierung
                    stackLevels[stackLevel] = reservation.left + reservation.width + 2;
                    reservation.stackLevel = stackLevel;
                    maxStackLevel = Math.max(maxStackLevel, stackLevel);
                });

                // Berechne dynamische Zimmer-Höhe mit 1px Abstand zwischen Balken
                const barHeight = 14; // Einheitliche Balken-Höhe
                const roomHeight = Math.max(20, 4 + (maxStackLevel + 1) * (barHeight + 1)); // 1px Abstand zwischen Balken

                // Speichere dynamische Höhe für Sidebar-Synchronisation
                room._dynamicHeight = roomHeight;

                // Zimmer-Hintergrund mit dynamischer Höhe (ohne Clipping für Hintergrund)
                this.ctx.save();
                this.ctx.resetTransform();
                this.ctx.fillStyle = roomIndex % 2 === 0 ? '#fafafa' : '#fff';
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, roomHeight);
                this.ctx.restore();

                // Render alle sichtbaren Reservierungen mit 1px Stacking
                sortedVisibleReservations.forEach(reservation => {
                    const stackY = baseRoomY + 3 + (reservation.stackLevel * (barHeight + 1)); // 1px Abstand

                    // Prüfe Hover-Status
                    const isHovered = this.isReservationHovered(reservation.left, stackY, reservation.width, barHeight);

                    this.renderRoomReservationBar(reservation.left, stackY, reservation.width, barHeight, reservation, isHovered);
                });

                // Zimmer-Trennlinie mit angepasster Position (ohne Clipping)
                this.ctx.save();
                this.ctx.resetTransform();
                this.ctx.strokeStyle = '#ddd';
                this.ctx.lineWidth = 1;
                this.ctx.beginPath();
                this.ctx.moveTo(this.sidebarWidth, baseRoomY + roomHeight);
                this.ctx.lineTo(this.canvas.width, baseRoomY + roomHeight);
                this.ctx.stroke();
                this.ctx.restore();
            } else {
                // Auch für nicht sichtbare Zimmer Standard-Höhe setzen
                room._dynamicHeight = 20;
            }

            // Update Y-Offset für nächstes Zimmer
            currentYOffset += room._dynamicHeight || 20;
        });

        this.ctx.restore(); // CLIPPING beenden
    }

    renderHistogramArea(startDate, endDate) {
        const area = this.areas.histogram;
        const startX = this.sidebarWidth - this.scrollX;

        // Area-Hintergrund
        this.ctx.fillStyle = '#f8f9fa';
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING: Timeline-Bereich (rechts von Sidebar)
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Berechne tägliche Auslastung mit Details
        const dailyCounts = [];
        const dailyDetails = [];
        const tempDate = new Date(startDate);
        let maxGuests = 1;

        while (tempDate <= endDate) {
            const guestCount = this.calculateDailyOccupancy(tempDate);
            const details = this.calculateDetailedOccupancy(tempDate);
            dailyCounts.push(guestCount);
            dailyDetails.push(details);
            maxGuests = Math.max(maxGuests, guestCount);
            tempDate.setDate(tempDate.getDate() + 1);
        }

        // Render Histogram-Balken mit detaillierten Beschriftungen
        dailyCounts.forEach((count, dayIndex) => {
            const x = startX + (dayIndex * DAY_WIDTH) + 5;
            const barWidth = DAY_WIDTH - 10;
            const barHeight = (count / maxGuests) * (area.height - 50); // Mehr Platz für Text
            const y = area.y + area.height - barHeight - 40; // Platz für Beschriftung unten

            // Render ohne zusätzliche Sichtbarkeits-Prüfung (Clipping übernimmt das)
            // Farbe basierend auf Auslastung
            const color = count > 50 ? '#dc3545' :
                count > 30 ? '#ffc107' :
                    count > 10 ? '#28a745' : '#6c757d';

            this.ctx.fillStyle = color;
            this.ctx.globalAlpha = 0.7;
            this.ctx.fillRect(x, y, barWidth, barHeight);
            this.ctx.globalAlpha = 1.0;
            
            // Detaillierte Beschriftung unter dem Balken
            const details = dailyDetails[dayIndex];
            if (details && barWidth > 30) { // Nur bei ausreichend Platz
                this.ctx.fillStyle = '#333';
                this.ctx.font = '8px Arial';
                this.ctx.textAlign = 'center';
                
                const centerX = x + barWidth / 2;
                let textY = area.y + area.height - 30;
                
                // Gesamtsumme
                this.ctx.fillText(`${details.total}`, centerX, textY);
                textY += 10;
                
                // Details nur wenn genug Platz und Werte vorhanden
                if (barWidth > 50) {
                    if (details.dz > 0) {
                        this.ctx.fillText(`DZ:${details.dz}`, centerX, textY);
                        textY += 8;
                    }
                    if (details.betten > 0) {
                        this.ctx.fillText(`B:${details.betten}`, centerX, textY);
                        textY += 8;
                    }
                    if (details.lager > 0) {
                        this.ctx.fillText(`L:${details.lager}`, centerX, textY);
                        textY += 8;
                    }
                    if (details.sonder > 0) {
                        this.ctx.fillText(`S:${details.sonder}`, centerX, textY);
                    }
                }
            }
        });

        this.ctx.restore(); // CLIPPING beenden
    }

    renderVerticalGridLines(startDate, endDate) {
        // Leichte vertikale Gitterlinien über den Balken (aber hinter Text)
        const startX = this.sidebarWidth - this.scrollX;

        // CLIPPING: Timeline-Bereich (rechts von Sidebar)
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, 0, this.canvas.width - this.sidebarWidth, this.canvas.height);
        this.ctx.clip();

        const currentDate = new Date(startDate);
        let dayIndex = 0;

        while (currentDate <= endDate) {
            const x = startX + (dayIndex * DAY_WIDTH);
            
            // Nur sehr dünne, dezente Gitterlinien
            this.ctx.strokeStyle = 'rgba(200, 200, 200, 0.3)'; // Sehr transparent
            this.ctx.lineWidth = 1;
            this.ctx.beginPath();
            this.ctx.moveTo(x, 0);
            this.ctx.lineTo(x, this.canvas.height);
            this.ctx.stroke();

            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        this.ctx.restore(); // CLIPPING beenden
    }

    renderSeparators() {
        // Horizontale Separatoren zwischen Bereichen
        this.ctx.strokeStyle = '#bbb';
        this.ctx.lineWidth = 2;

        // Zwischen Master und Rooms
        this.ctx.beginPath();
        this.ctx.moveTo(0, this.areas.rooms.y);
        this.ctx.lineTo(this.canvas.width, this.areas.rooms.y);
        this.ctx.stroke();

        // Zwischen Rooms und Histogram
        this.ctx.beginPath();
        this.ctx.moveTo(0, this.areas.histogram.y);
        this.ctx.lineTo(this.canvas.width, this.areas.histogram.y);
        this.ctx.stroke();
    }

    // Hover-Erkennung für Reservierungen
    checkHover() {
        // Die Hover-Erkennung wird direkt während des Renderings durchgeführt
        // Reset für bessere Performance
        this.hoveredReservation = null;
    }

    isReservationHovered(x, y, width, height) {
        // Schnelle Hover-Erkennung mit optimierten Berechnungen
        return this.mouseX >= x && this.mouseX <= x + width &&
            this.mouseY >= y && this.mouseY <= y + height;
    }

    renderReservationBar(x, y, width, height, reservation, isHovered = false) {
        // Kapazitäts-Farbe
        const capacity = reservation.capacity || 1;
        let color = capacity <= 2 ? '#3498db' :
            capacity <= 5 ? '#2ecc71' :
                capacity <= 10 ? '#f39c12' :
                    capacity <= 20 ? '#e74c3c' : '#9b59b6';

        // Hover-Effekt - optimiert für schnellere Darstellung
        if (isHovered) {
            // Leicht aufhellen für Hover-Effekt
            color = this.lightenColor(color, 20);

            // Optimierter Schatten-Effekt
            this.ctx.shadowColor = 'rgba(0,0,0,0.25)';
            this.ctx.shadowBlur = 4;
            this.ctx.shadowOffsetX = 1;
            this.ctx.shadowOffsetY = 1;
        }

        this.ctx.fillStyle = color;

        // Abgerundete Balken
        this.roundedRect(x, y, width, height, 3);
        this.ctx.fill();

        // Schatten sofort zurücksetzen für Performance
        if (isHovered) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        // Border
        this.ctx.strokeStyle = isHovered ? 'rgba(0,0,0,0.4)' : 'rgba(0,0,0,0.2)';
        this.ctx.lineWidth = 1;
        this.ctx.stroke();

        // Text (wie im Master-Bereich - größere Schrift)
        if (width > 40) {
            this.ctx.fillStyle = '#fff';
            this.ctx.font = '9px Arial'; // Gleiche Schriftgröße wie Master-Bereich
            this.ctx.textAlign = 'left';

            const text = `${reservation.nachname || reservation.name} (${capacity})`;
            this.ctx.fillText(text, x + 2, y + height - 3);
        }
    }

    renderRoomReservationBar(x, y, width, height, detail, isHovered = false) {
        let color = detail.color || '#3498db';

        // Hover-Effekt - optimiert
        if (isHovered) {
            color = this.lightenColor(color, 15);

            // Reduzierter Schatten für bessere Performance
            this.ctx.shadowColor = 'rgba(0,0,0,0.2)';
            this.ctx.shadowBlur = 3;
            this.ctx.shadowOffsetX = 1;
            this.ctx.shadowOffsetY = 1;
        }

        this.ctx.fillStyle = color;

        // Abgerundete Balken
        this.roundedRect(x, y, width, height, 3);
        this.ctx.fill();

        // Schatten sofort zurücksetzen
        if (isHovered) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        // Border
        this.ctx.strokeStyle = isHovered ? 'rgba(0,0,0,0.3)' : 'rgba(0,0,0,0.1)';
        this.ctx.lineWidth = 1;
        this.ctx.stroke();

        // Text (größere Schrift wie im Master-Bereich)
        if (width > 30) {
            this.ctx.fillStyle = '#fff';
            this.ctx.font = '9px Arial'; // Vergrößerte Schrift
            this.ctx.textAlign = 'left';

            let text = detail.guest_name;
            if (detail.has_dog) text += ' 🐕';

            this.ctx.fillText(text, x + 2, y + height - 2);
        }
    }

    // Hilfsfunktion für abgerundete Rechtecke
    roundedRect(x, y, width, height, radius) {
        this.ctx.beginPath();
        this.ctx.moveTo(x + radius, y);
        this.ctx.lineTo(x + width - radius, y);
        this.ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
        this.ctx.lineTo(x + width, y + height - radius);
        this.ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
        this.ctx.lineTo(x + radius, y + height);
        this.ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
        this.ctx.lineTo(x, y + radius);
        this.ctx.quadraticCurveTo(x, y, x + radius, y);
        this.ctx.closePath();
    }

    // Hilfsfunktion zum Aufhellen von Farben
    lightenColor(color, percent) {
        const num = parseInt(color.replace("#", ""), 16);
        const amt = Math.round(2.55 * percent);
        const R = (num >> 16) + amt;
        const G = (num >> 8 & 0x00FF) + amt;
        const B = (num & 0x0000FF) + amt;
        return "#" + (0x1000000 + (R < 255 ? R < 1 ? 0 : R : 255) * 0x10000 +
            (G < 255 ? G < 1 ? 0 : G : 255) * 0x100 +
            (B < 255 ? B < 1 ? 0 : B : 255)).toString(16).slice(1);
    }

    calculateDailyOccupancy(date) {
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
    
    calculateDetailedOccupancy(date) {
        const checkDate = new Date(date);
        checkDate.setHours(12, 0, 0, 0);
        
        let dz = 0; // Doppelzimmer
        let betten = 0; // Einzelbetten
        let lager = 0; // Lager
        let sonder = 0; // Sonderzimmer
        
        roomDetails.forEach(reservation => {
            const checkin = new Date(reservation.start);
            const checkout = new Date(reservation.end);
            checkin.setHours(12, 0, 0, 0);
            checkout.setHours(12, 0, 0, 0);

            if (checkin <= checkDate && checkout > checkDate) {
                const capacity = reservation.capacity || 1;
                
                // Finde das entsprechende Zimmer
                const room = rooms.find(r => r.id === reservation.room_id);
                if (room) {
                    // Intelligente Klassifizierung basierend auf Zimmer-Eigenschaften
                    const roomName = (room.caption || '').toLowerCase();
                    const roomCapacity = room.capacity || capacity;
                    
                    if (roomName.includes('dz') || roomName.includes('doppel')) {
                        dz += capacity;
                    } else if (roomName.includes('lager') || roomName.includes('matratzen') || roomCapacity >= 6) {
                        lager += capacity;
                    } else if (roomName.includes('sonder') || roomName.includes('suite') || roomName.includes('fam')) {
                        sonder += capacity;
                    } else {
                        // Standard: Einzelbetten/normale Zimmer
                        betten += capacity;
                    }
                } else {
                    // Fallback wenn Zimmer nicht gefunden
                    betten += capacity;
                }
            }
        });
        
        return {
            dz,
            betten,
            lager,
            sonder,
            total: dz + betten + lager + sonder
        };
    }

    // Public API
    updateData(newReservations, newRoomDetails, newRooms) {
        console.log('TimelineUnifiedRenderer: Updating data with real API data');

        reservations = newReservations || [];
        roomDetails = newRoomDetails || [];
        rooms = newRooms || [];

        console.log(`Updated data: ${reservations.length} reservations, ${roomDetails.length} room details, ${rooms.length} rooms`);

        // Bestimme Datum-Bereich aus echten Daten
        if (reservations.length > 0) {
            const allDates = [...reservations.map(r => r.start), ...reservations.map(r => r.end)];
            this.startDate = new Date(Math.min(...allDates.map(d => d.getTime())));
            this.endDate = new Date(Math.max(...allDates.map(d => d.getTime())));

            console.log(`Date range from real data: ${this.startDate.toLocaleDateString()} - ${this.endDate.toLocaleDateString()}`);
        } else {
            // Fallback bei leeren Daten
            this.startDate = new Date();
            this.endDate = new Date();
            this.endDate.setDate(this.endDate.getDate() + 30);
        }

        this.render();
    }

    setDayWidth(width) {
        DAY_WIDTH = width;
        this.render();
    }
}

// GLOBALE INSTANZ
let timelineRenderer = null;

// INTEGRATION MIT BESTEHENDER API
function renderTimeline() {
    if (!timelineRenderer) {
        timelineRenderer = new TimelineUnifiedRenderer('.main-areas-container');
    }

    timelineRenderer.updateData(reservations, roomDetails, rooms);
}

// Export für bestehende Funktionen
window.timelineUnifiedRenderer = timelineRenderer;
