// TIMELINE UNIFIED RENDERER - Canvas-basierte Timeline für Zimmerplan

// Globale Variablen für Zimmerplan-Timeline
let reservations = [];
let roomDetails = [];
let rooms = [];
let DAY_WIDTH = 80;
const VERTICAL_GAP = 1;

class TimelineUnifiedRenderer {
    constructor(containerSelector) {
        console.log('TimelineUnifiedRenderer konstruiert mit:', containerSelector);
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
            rooms: { height: 400, y: 240 },
            histogram: { height: 120, y: 640 }
        };

        this.totalHeight = 760;
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
                height: 820px;
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
                    z-index: 1;
                "></canvas>
                <!-- Horizontale Scrollbar unten am Canvas -->
                <div class="scroll-track-h" style="
                    position: absolute;
                    top: 766px;
                    left: 0;
                    width: 100%;
                    height: 18px;
                    background: #e8e8e8;
                    border: 2px solid #999;
                    border-radius: 4px;
                    overflow-x: scroll;
                    z-index: 10;
                ">
                    <div class="scroll-content-h" style="
                        height: 16px; 
                        width: 5000px; 
                        background: linear-gradient(to right, #4a90e2 0%, #7bb3f0 50%, #4a90e2 100%);
                        border-radius: 2px;
                    "></div>
                </div>
                <!-- Vertikale Scrollbar rechts -->
                <div class="scroll-track-v" style="
                    position: absolute;
                    top: ${this.areas.rooms.y}px;
                    right: 0;
                    width: 18px;
                    height: ${this.areas.rooms.height}px;
                    background: #e0e0e0;
                    border-left: 1px solid #ccc;
                    overflow-y: auto;
                    z-index: 10;
                ">
                    <div style="width: 1px; height: 1000px;"></div>
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

        const horizontalTrack = this.container.querySelector('.scroll-track-h');
        if (horizontalTrack) {
            horizontalTrack.style.width = rect.width + 'px';
        }
    }

    setupScrolling() {
        const horizontalTrack = this.container.querySelector('.scroll-track-h');
        horizontalTrack.addEventListener('scroll', (e) => {
            this.scrollX = e.target.scrollLeft;
            this.render();
        });

        const verticalTrack = this.container.querySelector('.scroll-track-v');
        verticalTrack.addEventListener('scroll', (e) => {
            this.scrollY = e.target.scrollTop;
            this.render();
        });
    }

    setupEvents() {
        this.canvas.addEventListener('mousemove', (e) => {
            const rect = this.canvas.getBoundingClientRect();
            this.mouseX = e.clientX - rect.left;
            this.mouseY = e.clientY - rect.top;
            this.updateHover();
        });

        this.canvas.addEventListener('mouseleave', () => {
            this.hoveredReservation = null;
            this.render();
        });

        window.addEventListener('resize', () => {
            this.resizeCanvas();
            this.render();
        });
    }

    updateHover() {
        let newHoveredReservation = null;

        for (const room of rooms) {
            if (!room.reservations) continue;

            const roomY = this.areas.rooms.y + (room.row * 30) - this.scrollY;
            if (roomY < this.areas.rooms.y || roomY > this.areas.rooms.y + this.areas.rooms.height) continue;

            for (const reservation of room.reservations) {
                const startX = this.sidebarWidth + (reservation.startDay * DAY_WIDTH) - this.scrollX;
                const width = reservation.duration * DAY_WIDTH;
                const height = 28;

                if (this.mouseX >= startX && this.mouseX <= startX + width &&
                    this.mouseY >= roomY && this.mouseY <= roomY + height) {
                    newHoveredReservation = reservation;
                    break;
                }
            }
        }

        if (newHoveredReservation !== this.hoveredReservation) {
            this.hoveredReservation = newHoveredReservation;
            this.render();
        }
    }

    render() {
        if (!this.ctx) return;

        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        this.updateScrollContent();
        this.renderHeader();
        this.renderMaster();
        this.renderRooms();
        this.renderHistogram();

        if (this.hoveredReservation) {
            this.renderTooltip();
        }
    }

    updateScrollContent() {
        const timelineWidth = Math.max(3000, rooms.length > 0 ?
            Math.max(...rooms.flatMap(r => r.reservations || []).map(res =>
                this.sidebarWidth + (res.startDay + res.duration) * DAY_WIDTH
            )) + 200 : 3000
        );

        const scrollContentH = this.container.querySelector('.scroll-content-h');
        if (scrollContentH) {
            scrollContentH.style.width = timelineWidth + 'px';
        }
    }

    renderHeader() {
        const area = this.areas.header;

        this.ctx.fillStyle = '#f0f0f0';
        this.ctx.fillRect(0, area.y, this.canvas.width, area.height);

        this.ctx.fillStyle = '#333';
        this.ctx.font = 'bold 16px Arial';
        this.ctx.fillText('Zimmerplan Timeline', 10, area.y + 25);

        this.ctx.font = '12px Arial';
        const startX = this.sidebarWidth - this.scrollX;
        for (let day = 0; day < 50; day++) {
            const x = startX + (day * DAY_WIDTH);
            if (x > this.canvas.width) break;
            if (x > this.sidebarWidth - DAY_WIDTH) {
                this.ctx.fillText(`Tag ${day + 1}`, x + 5, area.y + 35);
            }
        }
    }

    renderMaster() {
        const area = this.areas.master;

        this.ctx.fillStyle = '#fafafa';
        this.ctx.fillRect(0, area.y, this.canvas.width, area.height);

        this.ctx.fillStyle = '#666';
        this.ctx.font = '14px Arial';
        this.ctx.fillText('Master-Bereich (Übersicht)', 10, area.y + 25);

        this.ctx.font = '12px Arial';
        this.ctx.fillStyle = '#4CAF50';
        this.ctx.fillRect(10, area.y + 40, 15, 15);
        this.ctx.fillStyle = '#666';
        this.ctx.fillText('Verfügbar', 30, area.y + 52);

        this.ctx.fillStyle = '#2196F3';
        this.ctx.fillRect(10, area.y + 65, 15, 15);
        this.ctx.fillStyle = '#666';
        this.ctx.fillText('Reserviert', 30, area.y + 77);

        this.ctx.fillStyle = '#FF9800';
        this.ctx.fillRect(10, area.y + 90, 15, 15);
        this.ctx.fillStyle = '#666';
        this.ctx.fillText('Check-in', 30, area.y + 102);
    }

    renderRooms() {
        const area = this.areas.rooms;

        this.ctx.fillStyle = '#ffffff';
        this.ctx.fillRect(0, area.y, this.canvas.width, area.height);

        for (let i = 0; i < rooms.length; i++) {
            const room = rooms[i];
            const roomY = area.y + (i * 30) - this.scrollY;

            if (roomY < area.y - 30 || roomY > area.y + area.height) continue;

            this.ctx.fillStyle = '#e8e8e8';
            this.ctx.fillRect(0, roomY, this.sidebarWidth, 28);
            this.ctx.strokeStyle = '#ddd';
            this.ctx.strokeRect(0, roomY, this.sidebarWidth, 28);

            this.ctx.fillStyle = '#333';
            this.ctx.font = '12px Arial';
            this.ctx.fillText(room.caption || `Zimmer ${i + 1}`, 5, roomY + 18);

            if (room.reservations) {
                for (const reservation of room.reservations) {
                    this.renderReservation(reservation, roomY);
                }
            }
        }
    }

    renderReservation(reservation, roomY) {
        const startX = this.sidebarWidth + (reservation.startDay * DAY_WIDTH) - this.scrollX;
        const width = reservation.duration * DAY_WIDTH;
        const height = 28;

        if (startX + width < this.sidebarWidth || startX > this.canvas.width) return;

        let bgColor = '#2196F3';
        if (reservation.status === 'checkin') bgColor = '#FF9800';
        else if (reservation.status === 'checkout') bgColor = '#4CAF50';

        this.ctx.fillStyle = bgColor;
        this.ctx.fillRect(startX, roomY, width - 1, height - 1);

        if (this.hoveredReservation === reservation) {
            this.ctx.fillStyle = 'rgba(255, 255, 255, 0.3)';
            this.ctx.fillRect(startX, roomY, width - 1, height - 1);
        }

        this.ctx.fillStyle = '#fff';
        this.ctx.font = '11px Arial';
        const text = reservation.guest || `Res ${reservation.id}`;
        this.ctx.fillText(text, startX + 3, roomY + 16);

        this.ctx.strokeStyle = '#fff';
        this.ctx.strokeRect(startX, roomY, width - 1, height - 1);
    }

    renderHistogram() {
        const area = this.areas.histogram;

        this.ctx.fillStyle = '#f8f8f8';
        this.ctx.fillRect(0, area.y, this.canvas.width, area.height);

        this.ctx.fillStyle = '#333';
        this.ctx.font = 'bold 14px Arial';
        this.ctx.fillText('Belegungshistogramm', 10, area.y + 20);

        const startX = this.sidebarWidth - this.scrollX;
        for (let day = 0; day < 50; day++) {
            const x = startX + (day * DAY_WIDTH);
            if (x > this.canvas.width) break;
            if (x > this.sidebarWidth - DAY_WIDTH) {
                const occupancy = Math.random() * 0.8 + 0.1;
                const barHeight = occupancy * 80;

                this.ctx.fillStyle = `hsl(${120 - (occupancy * 120)}, 70%, 50%)`;
                this.ctx.fillRect(x + 5, area.y + 100 - barHeight, DAY_WIDTH - 10, barHeight);

                this.ctx.fillStyle = '#666';
                this.ctx.font = '10px Arial';
                this.ctx.fillText(`${Math.round(occupancy * 100)}%`, x + 8, area.y + 115);
            }
        }
    }

    renderTooltip() {
        if (!this.hoveredReservation) return;

        const tooltip = `Gast: ${this.hoveredReservation.guest || 'Unbekannt'}\nZeitraum: ${this.hoveredReservation.duration} Tage\nStatus: ${this.hoveredReservation.status || 'aktiv'}`;

        const lines = tooltip.split('\n');
        const maxWidth = Math.max(...lines.map(line => this.ctx.measureText(line).width)) + 20;
        const height = lines.length * 16 + 10;

        let x = this.mouseX + 10;
        let y = this.mouseY - height - 10;

        if (x + maxWidth > this.canvas.width) x = this.mouseX - maxWidth - 10;
        if (y < 0) y = this.mouseY + 20;

        this.ctx.fillStyle = 'rgba(0, 0, 0, 0.8)';
        this.ctx.fillRect(x, y, maxWidth, height);

        this.ctx.fillStyle = '#fff';
        this.ctx.font = '12px Arial';
        lines.forEach((line, i) => {
            this.ctx.fillText(line, x + 10, y + 20 + (i * 16));
        });
    }

    // Öffentliche Methoden
    updateData(newReservations, newRoomDetails, newRooms) {
        console.log('updateData aufgerufen mit:', newReservations?.length, 'reservations');

        if (newRooms) {
            rooms = newRooms;
            reservations = newReservations || [];
            roomDetails = newRoomDetails || [];
        } else {
            reservations = newReservations || [];
            roomDetails = newRoomDetails || [];

            rooms = roomDetails.map((room, index) => ({
                ...room,
                row: index,
                reservations: reservations.filter(res => res.roomId === room.id)
            }));
        }
        this.render();
    }

    setDayWidth(width) {
        DAY_WIDTH = width;
        this.render();
    }
}

window.TimelineUnifiedRenderer = TimelineUnifiedRenderer;
console.log('timeline-unified.js geladen, TimelineUnifiedRenderer verfügbar:', typeof TimelineUnifiedRenderer);
