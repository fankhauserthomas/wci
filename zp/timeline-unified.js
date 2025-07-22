// TIMELINE UNIFIED RENDERER - Canvas-basierte Timeline
let reservations = [];
let roomDetails = [];
let rooms = [];
let DAY_WIDTH = 80;
const VERTICAL_GAP = 1;

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

        // Layout-Bereiche
        this.areas = {
            header: { height: 40, y: 0 },
            master: { height: 200, y: 40 },
            rooms: { height: 550, y: 240 },
            histogram: { height: 160, y: 790 }
        };

        this.totalHeight = 950;
        this.sidebarWidth = 80;

        this.init();
    }

    init() {
        this.createCanvas();
        this.setupScrolling();
        this.setupEvents();
    }

    createCanvas() {
        this.container.innerHTML = `
            <div class="timeline-unified-container" style="
                width: 100%;
                height: 100%;
                position: relative;
                overflow: hidden;
                border: 1px solid #ddd;
                background: #f8f9fa;
                display: flex;
                flex-direction: column;
            ">
                <!-- Hauptbereich mit Canvas und vertikaler Scrollbar -->
                <div style="
                    flex: 1;
                    display: flex;
                    position: relative;
                    overflow: hidden;
                ">
                    <!-- Canvas Container -->
                    <div class="canvas-container" style="
                        flex: 1;
                        position: relative;
                        overflow: hidden;
                    ">
                        <canvas id="timeline-canvas" style="
                            position: absolute;
                            top: 0;
                            left: 0;
                            cursor: default;
                            z-index: 1;
                        "></canvas>
                    </div>
                    
                    <!-- Vertikale Scrollbar rechts -->
                    <div class="scroll-track-v" style="
                        width: 18px;
                        background: #e0e0e0;
                        border-left: 1px solid #ccc;
                        overflow-y: auto;
                        flex-shrink: 0;
                    ">
                        <div class="scroll-content-v" style="
                            width: 1px; 
                            height: 1000px;
                        "></div>
                    </div>
                </div>
                
                <!-- Horizontale Scrollbar unten -->
                <div class="scroll-track-h" style="
                    height: 18px;
                    background: #e8e8e8;
                    border-top: 1px solid #ccc;
                    overflow-x: auto;
                    flex-shrink: 0;
                ">
                    <div class="scroll-content-h" style="
                        height: 1px; 
                        width: 5000px;
                    "></div>
                </div>
            </div>
        `;
        this.canvas = document.getElementById('timeline-canvas');
        this.ctx = this.canvas.getContext('2d');

        this.resizeCanvas();
    }

    resizeCanvas() {
        const canvasContainer = this.container.querySelector('.canvas-container');
        const rect = canvasContainer.getBoundingClientRect();

        // Dynamische Canvas-Höhe basierend auf verfügbarem Platz
        const availableHeight = rect.height;
        this.canvas.width = rect.width;
        this.canvas.height = availableHeight;
        this.canvas.style.width = rect.width + 'px';
        this.canvas.style.height = availableHeight + 'px';

        // Update areas basierend auf tatsächlicher Canvas-Höhe
        this.areas.rooms.height = Math.max(300, availableHeight - 400);
        this.areas.histogram.y = this.areas.rooms.y + this.areas.rooms.height;
    }

    setupScrolling() {
        const horizontalTrack = this.container.querySelector('.scroll-track-h');
        const verticalTrack = this.container.querySelector('.scroll-track-v');
        const scrollContentH = this.container.querySelector('.scroll-content-h');
        const scrollContentV = this.container.querySelector('.scroll-content-v');

        // Horizontaler Scroll
        horizontalTrack.addEventListener('scroll', (e) => {
            this.scrollX = e.target.scrollLeft;
            this.render();
        });

        // Vertikaler Scroll
        verticalTrack.addEventListener('scroll', (e) => {
            this.scrollY = e.target.scrollTop;
            this.render();
        });

        // Mausrad-Events für natürlichere Bedienung
        this.canvas.addEventListener('wheel', (e) => {
            e.preventDefault();

            if (e.shiftKey) {
                // Shift + Mausrad = horizontal scrollen
                const newScrollX = Math.max(0, this.scrollX + e.deltaY);
                horizontalTrack.scrollLeft = newScrollX;
            } else {
                // Normal Mausrad = vertikal scrollen
                const newScrollY = Math.max(0, this.scrollY + e.deltaY);
                verticalTrack.scrollTop = newScrollY;
            }
        });
    }

    setupEvents() {
        window.addEventListener('resize', () => this.resizeCanvas());

        // Mouse-Events für Hover-Effekte mit optimierter Performance
        let hoverTimeout = null;
        this.canvas.addEventListener('mousemove', (e) => {
            const rect = this.canvas.getBoundingClientRect();
            this.mouseX = e.clientX - rect.left;
            this.mouseY = e.clientY - rect.top;

            if (hoverTimeout) clearTimeout(hoverTimeout);

            this.checkHover();
            this.render();

            hoverTimeout = setTimeout(() => {
                // Cleanup wenn nötig
            }, 50);
        });

        this.canvas.addEventListener('mouseleave', () => {
            this.hoveredReservation = null;
            this.render();
        });
    }

    render() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        if (reservations.length === 0) {
            this.renderEmpty();
            return;
        }

        const startDate = new Date(Math.min(...reservations.map(r => r.start.getTime())));
        const endDate = new Date(Math.max(...reservations.map(r => r.end.getTime())));
        const totalDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));
        const timelineWidth = totalDays * DAY_WIDTH;

        // Update scroll tracks
        const scrollContentH = this.container.querySelector('.scroll-content-h');
        if (scrollContentH) {
            scrollContentH.style.width = timelineWidth + 'px';
        }

        // Render alle Bereiche
        this.renderSidebar();
        this.renderHeader(startDate, endDate);
        this.renderMasterArea(startDate, endDate);
        this.renderRoomsArea(startDate, endDate);
        this.renderHistogramArea(startDate, endDate);
        this.renderVerticalGridLines(startDate, endDate);
        this.renderSeparators();

        // Update vertikaler scroll track
        const totalRoomHeight = rooms.reduce((sum, room) => sum + (room._dynamicHeight || 25), 0);
        const scrollContentV = this.container.querySelector('.scroll-content-v');
        if (scrollContentV) {
            scrollContentV.style.height = Math.max(this.areas.rooms.height, totalRoomHeight + 200) + 'px';
        }
    }

    renderEmpty() {
        this.ctx.fillStyle = '#666';
        this.ctx.font = '16px Arial';
        this.ctx.textAlign = 'center';
        this.ctx.fillText('Keine Daten geladen', this.canvas.width / 2, this.canvas.height / 2);
    }

    renderSidebar() {
        // Sidebar-Hintergrund
        this.ctx.fillStyle = '#47d42b3f';
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

        this.ctx.fillText('Datum', this.sidebarWidth / 2, this.areas.header.y + 25);
        this.ctx.fillText('Alle', this.sidebarWidth / 2, this.areas.master.y + 20);

        // Zimmer-Label
        this.ctx.save();
        this.ctx.translate(this.sidebarWidth / 2, this.areas.rooms.y + this.areas.rooms.height / 2);
        this.ctx.rotate(-Math.PI / 2);
        this.ctx.fillText('Zimmer', 0, 5);
        this.ctx.restore();

        this.ctx.fillText('Auslastung', this.sidebarWidth / 2, this.areas.histogram.y + 20);
    }

    renderHeader(startDate, endDate) {
        const area = this.areas.header;
        const startX = this.sidebarWidth - this.scrollX;

        // Header-Hintergrund
        this.ctx.fillStyle = '#f8f9fa';
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING
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

            const weekday = currentDate.toLocaleDateString('de-DE', { weekday: 'short' });
            this.ctx.fillText(weekday, x, area.y + 15);

            const dateStr = currentDate.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
            this.ctx.fillText(dateStr, x, area.y + 30);

            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        this.ctx.restore();

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

        // CLIPPING
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

            if (isHovered) {
                this.hoveredReservation = reservation;
            }

            this.renderReservationBar(left, top, width, 14, reservation, isHovered);
        });

        this.ctx.restore();
    }

    renderRoomsArea(startDate, endDate) {
        const area = this.areas.rooms;
        const startX = this.sidebarWidth - this.scrollX;
        const startY = area.y - this.scrollY;

        // Area-Hintergrund
        this.ctx.fillStyle = '#fff';
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Zimmer-Zeilen rendern
        let currentYOffset = 0;

        rooms.forEach((room, roomIndex) => {
            const baseRoomY = startY + currentYOffset;

            // Nur berechnen wenn Zimmer im sichtbaren Bereich (+Puffer)
            if (baseRoomY > area.y - 100 && baseRoomY < area.y + area.height + 100) {
                // Zimmer-Reservierungen
                const roomReservations = roomDetails.filter(detail => {
                    return detail.room_id === room.id ||
                        String(detail.room_id) === String(room.id) ||
                        Number(detail.room_id) === Number(room.id);
                });

                // Stacking nur für sichtbare Reservierungen
                const stackLevels = [];
                const OVERLAP_TOLERANCE = DAY_WIDTH * 0.1;
                let maxStackLevel = 0;

                const sortedReservations = roomReservations
                    .map(detail => {
                        const checkinDate = new Date(detail.start);
                        checkinDate.setHours(12, 0, 0, 0);
                        const checkoutDate = new Date(detail.end);
                        checkoutDate.setHours(12, 0, 0, 0);

                        const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                        const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                        const left = startX + (startOffset + 0.1) * DAY_WIDTH;
                        const width = (duration - 0.2) * DAY_WIDTH;

                        return { ...detail, left, width, startOffset, duration };
                    })
                    // Nur sichtbare Reservierungen für Stacking verwenden
                    .filter(item => item.left + item.width > this.sidebarWidth - 100 &&
                        item.left < this.canvas.width + 100)
                    .sort((a, b) => a.startOffset - b.startOffset);

                // Intelligentes Stacking-System
                sortedReservations.forEach((reservation, index) => {
                    let stackLevel = 0;
                    let placed = false;

                    // Finde den niedrigsten verfügbaren Stack-Level
                    while (!placed) {
                        let canPlaceHere = true;

                        // Prüfe Überlappung mit allen bereits platzierten Reservierungen auf diesem Level
                        for (let i = 0; i < index; i++) {
                            const other = sortedReservations[i];
                            if (other.stackLevel === stackLevel) {
                                const reservationEnd = reservation.left + reservation.width;
                                const otherEnd = other.left + other.width;

                                // Überlappung prüfen mit Toleranz
                                if (!(reservationEnd <= other.left + OVERLAP_TOLERANCE ||
                                    reservation.left >= otherEnd - OVERLAP_TOLERANCE)) {
                                    canPlaceHere = false;
                                    break;
                                }
                            }
                        }

                        if (canPlaceHere) {
                            reservation.stackLevel = stackLevel;
                            maxStackLevel = Math.max(maxStackLevel, stackLevel);
                            placed = true;
                        } else {
                            stackLevel++;
                        }

                        // Sicherheitsabbruch
                        if (stackLevel > 10) {
                            reservation.stackLevel = stackLevel;
                            maxStackLevel = Math.max(maxStackLevel, stackLevel);
                            placed = true;
                        }
                    }
                });

                // Berechne dynamische Zimmer-Höhe
                const barHeight = 16;
                const roomHeight = Math.max(20, 4 + (maxStackLevel + 1) * (barHeight + 0));
                room._dynamicHeight = roomHeight;                // Zimmer-Hintergrund
                this.ctx.save();
                this.ctx.resetTransform();
                this.ctx.fillStyle = roomIndex % 2 === 0 ? '#fafafa' : '#fff';
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, roomHeight);
                this.ctx.restore();

                // Render Reservierungen mit korrektem Stacking
                sortedReservations.forEach(reservation => {
                    const stackY = baseRoomY + 1 + (reservation.stackLevel * (barHeight + 2));

                    const isHovered = this.isReservationHovered(reservation.left, stackY, reservation.width, barHeight);

                    this.renderRoomReservationBar(reservation.left, stackY, reservation.width, barHeight, reservation, isHovered);
                });

                // Zimmer-Trennlinie
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
                // Auch für nicht sichtbare Zimmer Caption rendern, falls im Sichtbereich
                room._dynamicHeight = 23; // Minimal-Höhe für nicht sichtbare Zimmer
            }

            currentYOffset += room._dynamicHeight || 20;
        });

        this.ctx.restore();

        // CAPTIONS NACH dem Clipping rendern - aber nur im Zimmer-Bereich!
        this.ctx.save();
        // Clipping nur für den Zimmer-Bereich (Sidebar)
        this.ctx.beginPath();
        this.ctx.rect(0, this.areas.rooms.y, this.sidebarWidth, this.areas.rooms.height);
        this.ctx.clip();

        let currentYOffset2 = 0;
        rooms.forEach((room, roomIndex) => {
            const baseRoomY = startY + currentYOffset2;
            const roomDisplayY = baseRoomY + 12;

            // Caption rendern wenn sie im geclippten Zimmer-Bereich sichtbar ist
            if (roomDisplayY >= this.areas.rooms.y && roomDisplayY <= this.areas.rooms.y + this.areas.rooms.height) {
                this.ctx.fillStyle = '#555';
                this.ctx.font = '10px Arial';
                this.ctx.textAlign = 'center';

                const caption = room.caption || `R${room.id}`;
                console.log(`🏠 Rendering room caption: "${caption}" at Y=${roomDisplayY} (in rooms area: ${this.areas.rooms.y}-${this.areas.rooms.y + this.areas.rooms.height})`);
                this.ctx.fillText(caption, this.sidebarWidth / 2, roomDisplayY);
            }

            currentYOffset2 += room._dynamicHeight || 25;
        });

        this.ctx.restore();
    }

    renderHistogramArea(startDate, endDate) {
        const area = this.areas.histogram;
        const startX = this.sidebarWidth - this.scrollX;

        // Area-Hintergrund
        this.ctx.fillStyle = '#f8f9fa';
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING
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
            const barHeight = (count / maxGuests) * (area.height - 80);
            const y = area.y + area.height - barHeight - 70;

            const color = count > 50 ? '#dc3545' :
                count > 30 ? '#ffc107' :
                    count > 10 ? '#28a745' : '#6c757d';

            this.ctx.fillStyle = color;
            this.ctx.globalAlpha = 0.7;
            this.ctx.fillRect(x, y, barWidth, barHeight);
            this.ctx.globalAlpha = 1.0;

            // Detaillierte Beschriftung mit mehr Platz
            const details = dailyDetails[dayIndex];
            if (details && barWidth > 30) {
                this.ctx.fillStyle = '#333';
                this.ctx.font = '8px Arial';
                this.ctx.textAlign = 'center';

                const centerX = x + barWidth / 2;
                let textY = area.y + area.height - 60;

                this.ctx.fillText(`${details.total}`, centerX, textY);
                textY += 10;

                if (barWidth > 50) {
                    if (details.dz > 0) {
                        this.ctx.fillText(`DZ:${details.dz}`, centerX, textY);
                        textY += 9;
                    }
                    if (details.betten > 0) {
                        this.ctx.fillText(`B:${details.betten}`, centerX, textY);
                        textY += 9;
                    }
                    if (details.lager > 0) {
                        this.ctx.fillText(`L:${details.lager}`, centerX, textY);
                        textY += 9;
                    }
                    if (details.sonder > 0) {
                        this.ctx.fillText(`S:${details.sonder}`, centerX, textY);
                    }
                }
            }
        });

        this.ctx.restore();
    }

    renderVerticalGridLines(startDate, endDate) {
        // Leichte vertikale Gitterlinien über den Balken
        const startX = this.sidebarWidth - this.scrollX;

        // CLIPPING
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, 0, this.canvas.width - this.sidebarWidth, this.canvas.height);
        this.ctx.clip();

        const currentDate = new Date(startDate);
        let dayIndex = 0;

        while (currentDate <= endDate) {
            const x = startX + (dayIndex * DAY_WIDTH);

            // Sehr dezente Gitterlinien
            this.ctx.strokeStyle = 'rgba(200, 200, 200, 0.25)';
            this.ctx.lineWidth = 1;
            this.ctx.beginPath();
            this.ctx.moveTo(x, 0);
            this.ctx.lineTo(x, this.canvas.height);
            this.ctx.stroke();

            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        this.ctx.restore();
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

    // Hilfsmethoden
    checkHover() {
        this.hoveredReservation = null;
    }

    isReservationHovered(x, y, width, height) {
        return this.mouseX >= x && this.mouseX <= x + width &&
            this.mouseY >= y && this.mouseY <= y + height;
    }

    renderReservationBar(x, y, width, height, reservation, isHovered = false) {
        const capacity = reservation.capacity || 1;
        let color = capacity <= 2 ? '#3498db' :
            capacity <= 5 ? '#2ecc71' :
                capacity <= 10 ? '#f39c12' :
                    capacity <= 20 ? '#e74c3c' : '#9b59b6';

        if (isHovered) {
            color = this.lightenColor(color, 20);
            this.ctx.shadowColor = 'rgba(0,0,0,0.25)';
            this.ctx.shadowBlur = 4;
            this.ctx.shadowOffsetX = 1;
            this.ctx.shadowOffsetY = 1;
        }

        this.ctx.fillStyle = color;
        this.roundedRect(x, y, width, height, 3);
        this.ctx.fill();

        if (isHovered) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        this.ctx.strokeStyle = isHovered ? 'rgba(0,0,0,0.4)' : 'rgba(0,0,0,0.2)';
        this.ctx.lineWidth = 1;
        this.ctx.stroke();

        if (width > 40) {
            this.ctx.fillStyle = '#fff';
            this.ctx.font = '9px Arial';
            this.ctx.textAlign = 'left';

            const text = `${reservation.nachname || reservation.name} (${capacity})`;
            this.ctx.fillText(text, x + 2, y + height - 3);
        }
    }

    renderRoomReservationBar(x, y, width, height, detail, isHovered = false) {
        let color = detail.color || '#3498db';

        if (isHovered) {
            color = this.lightenColor(color, 15);
            this.ctx.shadowColor = 'rgba(0,0,0,0.2)';
            this.ctx.shadowBlur = 3;
            this.ctx.shadowOffsetX = 1;
            this.ctx.shadowOffsetY = 1;
        }

        this.ctx.fillStyle = color;
        this.roundedRect(x, y, width, height, 3);
        this.ctx.fill();

        if (isHovered) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        this.ctx.strokeStyle = isHovered ? 'rgba(0,0,0,0.3)' : 'rgba(0,0,0,0.1)';
        this.ctx.lineWidth = 1;
        this.ctx.stroke();

        if (width > 30) {
            this.ctx.fillStyle = '#fff';
            this.ctx.font = '9px Arial';
            this.ctx.textAlign = 'left';

            let text = detail.guest_name;
            if (detail.has_dog) text += ' 🐕';

            this.ctx.fillText(text, x + 2, y + height - 2);
        }
    }

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

        let dz = 0;
        let betten = 0;
        let lager = 0;
        let sonder = 0;

        roomDetails.forEach(reservation => {
            const checkin = new Date(reservation.start);
            const checkout = new Date(reservation.end);
            checkin.setHours(12, 0, 0, 0);
            checkout.setHours(12, 0, 0, 0);

            if (checkin <= checkDate && checkout > checkDate) {
                const capacity = reservation.capacity || 1;

                const room = rooms.find(r => r.id === reservation.room_id);
                if (room) {
                    const roomName = (room.caption || '').toLowerCase();
                    const roomCapacity = room.capacity || capacity;

                    if (roomName.includes('dz') || roomName.includes('doppel')) {
                        dz += capacity;
                    } else if (roomName.includes('lager') || roomName.includes('matratzen') || roomCapacity >= 6) {
                        lager += capacity;
                    } else if (roomName.includes('sonder') || roomName.includes('suite') || roomName.includes('fam')) {
                        sonder += capacity;
                    } else {
                        betten += capacity;
                    }
                } else {
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

        if (reservations.length > 0) {
            const allDates = [...reservations.map(r => r.start), ...reservations.map(r => r.end)];
            this.startDate = new Date(Math.min(...allDates.map(d => d.getTime())));
            this.endDate = new Date(Math.max(...allDates.map(d => d.getTime())));
        }

        this.render();
    }
}

// Export
window.TimelineUnifiedRenderer = TimelineUnifiedRenderer;
