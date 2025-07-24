// TIMELINE UNIFIED RENDERER - Canvas-basierte Timeline
let reservations = [];
let roomDetails = [];
let rooms = [];
let DAY_WIDTH = 120;
const VERTICAL_GAP = 1;

class TimelineUnifiedRenderer {
    constructor(containerSelector) {
        this.container = document.querySelector(containerSelector);
        this.canvas = null;
        this.ctx = null;
        this.scrollX = 0;
        this.scrollY = 0;
        this.masterScrollY = 0; // Separater Scroll für Master-Bereich
        this.roomsScrollY = 0;  // Separater Scroll für Rooms-Bereich

        // Mouse-Tracking für Hover-Effekte
        this.mouseX = 0;
        this.mouseY = 0;
        this.hoveredReservation = null;

        // Config-Button Tracking
        this.isConfigButtonHovered = false;
        this.configButtonBounds = null;

        // Drag & Drop für Separatoren
        this.isDraggingSeparator = false;
        this.isDraggingBottomSeparator = false;
        this.draggingType = null; // 'top' oder 'bottom'

        // Drag & Drop für Reservierungen
        this.isDraggingReservation = false;
        this.draggedReservation = null;
        this.dragMode = null; // 'move', 'resize-start', 'resize-end'
        this.dragStartX = 0;
        this.dragStartY = 0;
        this.dragOriginalData = null;
        this.dragTargetRoom = null;
        this.lastDragRender = 0; // Für Performance-Throttling

        // Ghost-Balken für Drag-Feedback
        this.ghostBar = null; // { x, y, width, height, room, mode, visible }

        // Separator-Positionen aus Cookies laden oder Defaults setzen
        this.separatorY = this.loadFromCookie('separatorTop', 255);
        this.bottomSeparatorY = this.loadFromCookie('separatorBottom', 805);

        // Layout-Bereiche (dynamisch) - Header wieder hinzugefügt + Menü
        this.areas = {
            menu: { height: 20, y: 0 },
            header: { height: 40, y: 20 },
            master: { height: 200, y: 60 },
            rooms: { height: 550, y: 260 },
            histogram: { height: 160, y: 810 }
        };

        this.totalHeight = 970;
        this.sidebarWidth = 80;

        // Timeline-Konstanten
        this.DAY_WIDTH = 90; // Breite eines Tages in Pixeln - wird von Theme überschrieben

        // Performance tracking
        this.lastDragRender = 0;
        this.lastHoverRender = 0;
        this.lastScrollRender = 0;
        this.renderScheduled = false;

        // Phase 2: Stacking Cache System
        this.stackingCache = new Map(); // roomId -> stackingResult
        this.stackingDirty = new Set(); // Set of dirty roomIds
        this.dataIndex = null; // Will be initialized with reservation data

        // Theme-Konfiguration laden
        this.themeConfig = this.loadThemeConfiguration();
        this.DAY_WIDTH = this.themeConfig.dayWidth || 90; // Verwende Theme-DAY_WIDTH

        this.init();
    }

    loadFromCookie(name, defaultValue) {
        const value = document.cookie
            .split('; ')
            .find(row => row.startsWith(name + '='))
            ?.split('=')[1];
        return value ? parseInt(value) : defaultValue;
    }

    saveToCookie(name, value) {
        const expires = new Date();
        expires.setFullYear(expires.getFullYear() + 1); // 1 Jahr gültig
        document.cookie = `${name}=${value}; expires=${expires.toUTCString()}; path=/`;
    }

    // ===== PERFORMANCE OPTIMIZATIONS =====

    scheduleRender(reason = 'unknown') {
        if (this.renderScheduled) return;

        this.renderScheduled = true;
        requestAnimationFrame(() => {
            this.render();
            this.renderScheduled = false;
        });
    }

    getVisibleReservations(startDate, endDate) {
        // Erweiterte Viewport Culling - großzügigerer Puffer für bessere Sichtbarkeit
        const viewportLeft = this.scrollX - 500; // Größerer Puffer für bessere Sichtbarkeit
        const viewportRight = this.scrollX + this.canvas.width + 500;

        const startX = this.sidebarWidth - this.scrollX;

        return reservations.filter(reservation => {
            const checkinDate = new Date(reservation.start);
            checkinDate.setHours(12, 0, 0, 0);
            const checkoutDate = new Date(reservation.end);
            checkoutDate.setHours(12, 0, 0, 0);

            const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
            const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

            const resLeft = startX + (startOffset + 0.1) * this.DAY_WIDTH;
            const resRight = resLeft + (duration - 0.2) * this.DAY_WIDTH;

            // Großzügigere Sichtbarkeitsprüfung
            return resRight >= viewportLeft && resLeft <= viewportRight;
        });
    }

    getVisibleRooms() {
        // Viewport Culling für Zimmer
        const viewportTop = this.roomsScrollY - 50; // 50px Puffer
        const viewportBottom = this.roomsScrollY + this.areas.rooms.height + 50;

        const visibleRooms = [];
        let currentYOffset = 0;

        for (const room of rooms) {
            const roomHeight = room._dynamicHeight || 25;
            const roomTop = currentYOffset;
            const roomBottom = currentYOffset + roomHeight;

            if (roomBottom >= viewportTop && roomTop <= viewportBottom) {
                visibleRooms.push({
                    room: room,
                    yOffset: currentYOffset,
                    height: roomHeight
                });
            }

            currentYOffset += roomHeight;
        }

        return visibleRooms;
    }

    // ===== PHASE 2: DATA INDEXING SYSTEM =====

    initializeDataIndex(reservations, roomDetails) {
        this.dataIndex = {
            reservationsByRoom: new Map(),
            reservationsByDate: new Map(),
            roomDetailsById: new Map(),
            dateRange: { start: null, end: null }
        };

        // Index reservations by room
        reservations.forEach(reservation => {
            const roomId = reservation.room_id;
            if (!this.dataIndex.reservationsByRoom.has(roomId)) {
                this.dataIndex.reservationsByRoom.set(roomId, []);
            }
            this.dataIndex.reservationsByRoom.get(roomId).push(reservation);
        });

        // Index room details by room
        roomDetails.forEach(detail => {
            const roomId = detail.room_id;
            if (!this.dataIndex.reservationsByRoom.has(roomId)) {
                this.dataIndex.reservationsByRoom.set(roomId, []);
            }
            this.dataIndex.reservationsByRoom.get(roomId).push(detail);

            // Also index by detail ID for quick lookup
            this.dataIndex.roomDetailsById.set(detail.id || detail.detail_id, detail);
        });

        // Index by date for temporal queries
        [...reservations, ...roomDetails].forEach(item => {
            const startDate = new Date(item.start);
            startDate.setHours(0, 0, 0, 0);
            const dateKey = this.getDateKey(startDate);

            if (!this.dataIndex.reservationsByDate.has(dateKey)) {
                this.dataIndex.reservationsByDate.set(dateKey, []);
            }
            this.dataIndex.reservationsByDate.get(dateKey).push(item);

            // Update date range
            if (!this.dataIndex.dateRange.start || startDate < this.dataIndex.dateRange.start) {
                this.dataIndex.dateRange.start = new Date(startDate);
            }
            if (!this.dataIndex.dateRange.end || startDate > this.dataIndex.dateRange.end) {
                this.dataIndex.dateRange.end = new Date(startDate);
            }
        });
    }

    getDateKey(date) {
        return Math.floor(date.getTime() / (1000 * 60 * 60 * 24));
    }

    getReservationsForRoom(roomId) {
        // Fallback auf direkte roomDetails-Suche wenn kein dataIndex
        if (!this.dataIndex) {
            return roomDetails.filter(detail =>
                detail.room_id === roomId ||
                String(detail.room_id) === String(roomId) ||
                Number(detail.room_id) === Number(roomId)
            );
        }

        // Verwende Index, aber mit Fallback auf direkte Suche
        const indexedResults = this.dataIndex.reservationsByRoom.get(roomId) ||
            this.dataIndex.reservationsByRoom.get(String(roomId)) ||
            this.dataIndex.reservationsByRoom.get(Number(roomId)) ||
            [];

        // Zusätzliche Sicherheits-Suche in roomDetails für aktualisierte Daten
        const directResults = roomDetails.filter(detail =>
            detail.room_id === roomId ||
            String(detail.room_id) === String(roomId) ||
            Number(detail.room_id) === Number(roomId)
        );

        // Verwende die direkten Ergebnisse wenn sie aktueller sind (nach Drag & Drop)
        if (directResults.length !== indexedResults.length) {
            return directResults;
        }

        return indexedResults.length > 0 ? indexedResults : directResults;
    }

    getReservationsInDateRange(startDate, endDate, roomId = null) {
        if (!this.dataIndex) return [];

        const result = [];
        const startKey = this.getDateKey(startDate);
        const endKey = this.getDateKey(endDate);

        for (let dateKey = startKey; dateKey <= endKey; dateKey++) {
            const dayReservations = this.dataIndex.reservationsByDate.get(dateKey) || [];
            if (roomId) {
                result.push(...dayReservations.filter(r =>
                    r.room_id === roomId ||
                    String(r.room_id) === String(roomId) ||
                    Number(r.room_id) === Number(roomId)
                ));
            } else {
                result.push(...dayReservations);
            }
        }
        return result;
    }

    // ===== PHASE 2: STACKING CACHE SYSTEM =====

    getStackingForRoom(roomId, startDate, endDate) {
        const cacheKey = `${roomId}_${this.getDateKey(startDate)}_${this.getDateKey(endDate)}`;

        // Check if cache is dirty for this room (with multiple ID formats)
        const isDirty = this.stackingDirty.has(roomId) ||
            this.stackingDirty.has(String(roomId)) ||
            this.stackingDirty.has(Number(roomId));

        if (this.stackingCache.has(cacheKey) && !isDirty) {
            return this.stackingCache.get(cacheKey);
        }

        const result = this.calculateStackingForRoom(roomId, startDate, endDate);
        this.stackingCache.set(cacheKey, result);

        // Clear dirty flag for all formats
        this.stackingDirty.delete(roomId);
        this.stackingDirty.delete(String(roomId));
        this.stackingDirty.delete(Number(roomId));

        return result;
    }

    calculateStackingForRoom(roomId, startDate, endDate) {
        const roomReservations = this.getReservationsForRoom(roomId);
        if (roomReservations.length === 0) {
            return { reservations: [], maxStackLevel: 0, roomHeight: 25 };
        }

        const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;
        let maxStackLevel = 0;
        const startX = this.sidebarWidth - this.scrollX;

        // Map und position alle Reservierungen - mit eindeutigen Kopien
        const positionedReservations = roomReservations
            .map(detail => {
                const checkinDate = new Date(detail.start);
                checkinDate.setHours(12, 0, 0, 0);
                const checkoutDate = new Date(detail.end);
                checkoutDate.setHours(12, 0, 0, 0);

                const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                const left = startX + (startOffset + 0.01) * this.DAY_WIDTH;
                const width = (duration - 0.02) * this.DAY_WIDTH;

                // Wichtig: Komplett neue Kopie mit allen relevanten Eigenschaften
                return {
                    ...detail,
                    left,
                    width,
                    startOffset,
                    duration,
                    stackLevel: 0,
                    // Eindeutige ID für Tracking
                    _calcId: detail.id || detail.detail_id || `${detail.room_id}_${detail.start}_${detail.end}`
                };
            })
            .filter(item => item.left + item.width > this.sidebarWidth - 100 &&
                item.left < this.canvas.width + 100)
            .sort((a, b) => a.startOffset - b.startOffset);

        // Stacking-Berechnung - sauberer Algorithmus ohne Seiteneffekte
        positionedReservations.forEach((reservation, index) => {
            let stackLevel = 0;
            let placed = false;

            while (!placed) {
                let canPlaceHere = true;

                // Prüfe gegen ALLE bereits platzierten Reservierungen
                for (let i = 0; i < index; i++) {
                    const other = positionedReservations[i];
                    if (other.stackLevel === stackLevel) {
                        const reservationEnd = reservation.left + reservation.width;
                        const otherEnd = other.left + other.width;

                        // Überlappungs-Check
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

                // Sicherheits-Ausgang
                if (stackLevel > 15) {
                    reservation.stackLevel = stackLevel;
                    maxStackLevel = Math.max(maxStackLevel, stackLevel);
                    placed = true;
                }
            }
        });

        const barHeight = this.themeConfig.room.barHeight || 16;
        const roomHeight = Math.max(25, 4 + (maxStackLevel + 1) * (barHeight + 2));

        return {
            reservations: positionedReservations,
            maxStackLevel,
            roomHeight
        };
    }

    invalidateStackingCache(roomId = null) {
        if (roomId) {
            // Convert roomId to string for consistent comparison
            const roomIdStr = String(roomId);
            this.stackingDirty.add(roomId);
            this.stackingDirty.add(roomIdStr);
            this.stackingDirty.add(Number(roomId));

            // Remove cached entries for this room (check all possible key formats)
            const keysToDelete = [];
            for (const key of this.stackingCache.keys()) {
                if (key.startsWith(`${roomId}_`) ||
                    key.startsWith(`${roomIdStr}_`) ||
                    key.startsWith(`${Number(roomId)}_`)) {
                    keysToDelete.push(key);
                }
            }
            keysToDelete.forEach(key => this.stackingCache.delete(key));
        } else {
            // Clear entire cache
            this.stackingCache.clear();
            this.stackingDirty.clear();
        }
    }

    // ===== THEME CONFIGURATION =====
    loadThemeConfiguration() {
        // Versuche aus Cookie zu laden
        const cookieValue = document.cookie
            .split('; ')
            .find(row => row.startsWith('timeline_config='))
            ?.split('=')[1];

        if (cookieValue) {
            try {
                const config = JSON.parse(decodeURIComponent(cookieValue));
                // Ergänze fehlende Eigenschaften mit Fallback-Werten
                return this.addMissingDefaults(config);
            } catch (e) {
                console.warn('Fehler beim Laden der Theme-Konfiguration:', e);
            }
        }

        // Fallback: localStorage
        const localStorageValue = localStorage.getItem('timeline_config');
        if (localStorageValue) {
            try {
                const config = JSON.parse(localStorageValue);
                // Ergänze fehlende Eigenschaften mit Fallback-Werten
                return this.addMissingDefaults(config);
            } catch (e) {
                console.warn('Fehler beim Laden der localStorage-Konfiguration:', e);
            }
        }

        // Default: Professional Theme
        return {
            sidebar: { bg: '#2c3e50', text: '#ecf0f1', fontSize: 12 },
            header: { bg: '#34495e', text: '#ecf0f1', fontSize: 10 },
            master: { bg: '#2c3e50', bar: '#3498db', fontSize: 10, barHeight: 14 },
            room: { bg: '#2c3e50', bar: '#27ae60', fontSize: 10, barHeight: 16 },
            histogram: { bg: '#34495e', bar: '#e74c3c', text: '#ecf0f1', fontSize: 9 },
            dayWidth: 90
        };
    }

    addMissingDefaults(config) {
        // Default-Werte für neue Eigenschaften
        const defaults = {
            sidebar: { bg: '#2c3e50', text: '#ecf0f1', fontSize: 12 },
            header: { bg: '#34495e', text: '#ecf0f1', fontSize: 10 },
            master: { bg: '#2c3e50', bar: '#3498db', fontSize: 10, barHeight: 14 },
            room: { bg: '#2c3e50', bar: '#27ae60', fontSize: 10, barHeight: 16 },
            histogram: { bg: '#34495e', bar: '#e74c3c', text: '#ecf0f1', fontSize: 9 },
            dayWidth: 90
        };

        // Ergänze fehlende Eigenschaften
        const result = { ...config };

        for (const [section, sectionDefaults] of Object.entries(defaults)) {
            if (section === 'dayWidth') {
                result.dayWidth = result.dayWidth || defaults.dayWidth;
            } else {
                result[section] = { ...sectionDefaults, ...result[section] };
            }
        }

        return result;
    }

    refreshThemeConfiguration() {
        this.themeConfig = this.loadThemeConfiguration();
        this.DAY_WIDTH = this.themeConfig.dayWidth || 90; // Verwende Theme-DAY_WIDTH

        // Phase 2: Invalidate all caches when theme changes
        this.invalidateStackingCache(); // Clear all caches

        this.scheduleRender('theme_change'); // Optimiert: Scheduled render statt direkt
    }

    init() {
        this.createCanvas();
        this.setupScrolling();
        this.setupEvents();
    }

    createCanvas() {
        this.container.innerHTML = `
            <div class="timeline-unified-container" style="
                width: 100vw;
                height: 100vh;
                position: fixed;
                top: 0;
                left: 0;
                overflow: hidden;
                border: none;
                background: #2c2c2c;
                display: flex;
                flex-direction: column;
                margin: 0;
                padding: 0;
            ">
                <!-- Hauptbereich mit Canvas und vertikalen Scrollbars -->
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
                            right: 0;
                            bottom: 20px;
                            cursor: default;
                            z-index: 1;
                        "></canvas>
                        
                        <!-- Master-Scrollbar (zwischen Header und Separator 1) -->
                        <div class="scroll-track-master mobile-scrollbar" style="
                            position: absolute;
                            right: 0;
                            width: 18px;
                            background: #e8e8e8;
                            border-left: 1px solid #ccc;
                            overflow-y: auto;
                            z-index: 10;
                            -webkit-overflow-scrolling: touch;
                        ">
                            <div class="scroll-content-master" style="
                                width: 1px; 
                                height: 400px;
                            "></div>
                        </div>
                        
                        <!-- Rooms-Scrollbar (zwischen Separator 1 und 2) -->
                        <div class="scroll-track-rooms mobile-scrollbar" style="
                            position: absolute;
                            right: 0;
                            width: 18px;
                            background: #e0e0e0;
                            border-left: 1px solid #ccc;
                            overflow-y: auto;
                            z-index: 10;
                            -webkit-overflow-scrolling: touch;
                        ">
                            <div class="scroll-content-rooms" style="
                                width: 1px; 
                                height: 1000px;
                            "></div>
                        </div>
                    </div>
                </div>
                
                <!-- Horizontale Scrollbar unten -->
                <div class="scroll-track-h mobile-scrollbar" style="
                    height: 18px;
                    background: #e8e8e8;
                    border-top: 1px solid #ccc;
                    overflow-x: auto;
                    flex-shrink: 0;
                    -webkit-overflow-scrolling: touch;
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
        this.addMobileScrollbarStyles();
    }

    addMobileScrollbarStyles() {
        // Prüfe ob bereits Styles existieren
        if (document.getElementById('mobile-scrollbar-styles')) return;

        const style = document.createElement('style');
        style.id = 'mobile-scrollbar-styles';
        style.textContent = `
            /* Body Fullscreen ohne Scrollbars */
            body, html {
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                width: 100vw !important;
                height: 100vh !important;
            }
            
            /* Verbesserte Scrollbars für Mobilgeräte */
            .mobile-scrollbar {
                scrollbar-width: auto !important;
                scrollbar-color: #888 #f1f1f1 !important;
            }
            
            .mobile-scrollbar::-webkit-scrollbar {
                width: 18px !important;
                height: 18px !important;
                background: transparent;
            }
            
            .mobile-scrollbar::-webkit-scrollbar-track {
                background: #f1f1f1 !important;
                border-radius: 0px;
            }
            
            .mobile-scrollbar::-webkit-scrollbar-thumb {
                background: #888 !important;
                border-radius: 9px !important;
                border: 3px solid #f1f1f1 !important;
                min-height: 30px !important;
                min-width: 30px !important;
            }
            
            .mobile-scrollbar::-webkit-scrollbar-thumb:hover {
                background: #555 !important;
            }
            
            .mobile-scrollbar::-webkit-scrollbar-thumb:active {
                background: #333 !important;
            }
            
            /* Touch-optimiert für kleine Bildschirme */
            @media (max-width: 768px), (pointer: coarse) {
                .mobile-scrollbar::-webkit-scrollbar {
                    width: 24px !important;
                    height: 24px !important;
                }
                
                .mobile-scrollbar::-webkit-scrollbar-thumb {
                    min-height: 40px !important;
                    min-width: 40px !important;
                    border: 4px solid #f1f1f1 !important;
                }
                
                .scroll-track-master,
                .scroll-track-rooms {
                    width: 24px !important;
                }
                
                .scroll-track-h {
                    height: 24px !important;
                }
            }
            
            /* iOS Safari spezifische Anpassungen */
            @supports (-webkit-touch-callout: none) {
                .mobile-scrollbar {
                    -webkit-overflow-scrolling: touch !important;
                    overflow-scrolling: touch !important;
                }
            }
        `;
        document.head.appendChild(style);
    }

    resizeCanvas() {
        const canvasContainer = this.container.querySelector('.canvas-container');
        const rect = canvasContainer.getBoundingClientRect();

        // Canvas-Höhe: vom oberen Rand bis 20px über dem unteren Rand
        const availableHeight = rect.height - 20;
        this.canvas.width = rect.width;
        this.canvas.height = availableHeight;
        this.canvas.style.width = rect.width + 'px';
        this.canvas.style.height = availableHeight + 'px';

        // Separator-Position relativ zur Canvas-Höhe anpassen
        const relativePosition = this.separatorY / this.totalHeight; // Verhältnis beibehalten
        this.separatorY = Math.min(availableHeight * 0.5, availableHeight * relativePosition);

        // Layout-Bereiche aktualisieren
        this.updateLayoutAreas();

        // Scrollbars positionieren
        this.positionScrollbars();
    }

    positionScrollbars() {
        const masterScrollbar = this.container.querySelector('.scroll-track-master');
        const roomsScrollbar = this.container.querySelector('.scroll-track-rooms');

        if (masterScrollbar) {
            // Master-Scrollbar zwischen Header-Unterkante und Separator 1
            masterScrollbar.style.top = this.areas.header.y + this.areas.header.height + 'px';
            masterScrollbar.style.height = (this.separatorY - this.areas.header.y - this.areas.header.height) + 'px';
        }

        if (roomsScrollbar) {
            // Rooms-Scrollbar zwischen Separator 1 und Separator 2
            roomsScrollbar.style.top = this.separatorY + 'px';
            roomsScrollbar.style.height = (this.bottomSeparatorY - this.separatorY) + 'px';
        }
    }

    setupScrolling() {
        const horizontalTrack = this.container.querySelector('.scroll-track-h');
        const masterTrack = this.container.querySelector('.scroll-track-master');
        const roomsTrack = this.container.querySelector('.scroll-track-rooms');
        const scrollContentH = this.container.querySelector('.scroll-content-h');
        const scrollContentMaster = this.container.querySelector('.scroll-content-master');
        const scrollContentRooms = this.container.querySelector('.scroll-content-rooms');

        // Horizontaler Scroll
        horizontalTrack.addEventListener('scroll', (e) => {
            this.scrollX = e.target.scrollLeft;
            // Phase 2: Invalidate viewport-dependent caches on horizontal scroll
            this.invalidateStackingCache();
            this.scheduleRender('scroll_h');
        });

        // Master-Bereich Scroll
        if (masterTrack) {
            masterTrack.addEventListener('scroll', (e) => {
                this.masterScrollY = e.target.scrollTop;
                this.scheduleRender('scroll_master');
            });
        }

        // Rooms-Bereich Scroll
        if (roomsTrack) {
            roomsTrack.addEventListener('scroll', (e) => {
                this.roomsScrollY = e.target.scrollTop;
                this.scheduleRender('scroll_rooms');
            });
        }

        // Mausrad-Events für bereichsspezifisches Scrollen
        this.canvas.addEventListener('wheel', (e) => {
            e.preventDefault();
            const mouseY = e.offsetY;

            // Throttle wheel events
            const now = Date.now();
            if (now - this.lastScrollRender < 16) return; // 60 FPS für Scrolling
            this.lastScrollRender = now;

            if (e.shiftKey) {
                // Shift + Mausrad = horizontal scrollen
                const newScrollX = Math.max(0, this.scrollX + e.deltaY);
                horizontalTrack.scrollLeft = newScrollX;
            } else {
                // Bereichsspezifisches vertikales Scrollen basierend auf Mausposition
                if (mouseY >= this.areas.master.y && mouseY < this.separatorY && masterTrack) {
                    // Master-Bereich (nach Header)
                    const newScrollY = Math.max(0, this.masterScrollY + e.deltaY);
                    masterTrack.scrollTop = newScrollY;
                } else if (mouseY >= this.separatorY && mouseY < this.bottomSeparatorY && roomsTrack) {
                    // Rooms-Bereich  
                    const newScrollY = Math.max(0, this.roomsScrollY + e.deltaY);
                    roomsTrack.scrollTop = newScrollY;
                }
            }
        }, { passive: false });
    }

    setupEvents() {
        window.addEventListener('resize', () => this.resizeCanvas());

        // Mouse-Events für Hover-Effekte mit optimierter Performance
        let hoverTimeout = null;
        let lastRenderTime = 0;

        this.canvas.addEventListener('mousemove', (e) => {
            const rect = this.canvas.getBoundingClientRect();
            this.mouseX = e.clientX - rect.left;
            this.mouseY = e.clientY - rect.top;

            if (hoverTimeout) clearTimeout(hoverTimeout);

            // Separator und Reservierung Dragging wird über document behandelt
            if (this.isDraggingSeparator || this.isDraggingBottomSeparator || this.isDraggingReservation) {
                return; // Keine lokale Behandlung während Drag-Operationen
            }

            // Throttle normale Hover-Events um Performance zu verbessern
            const now = Date.now();
            if (now - this.lastHoverRender < 16) { // 60 FPS für Hover - optimiert
                return;
            }
            this.lastHoverRender = now;

            // Normale Hover-Logik nur wenn nicht gedraggt wird
            const oldHovered = this.hoveredReservation;
            this.checkHover();
            this.updateCursor();

            // Nur rendern wenn sich Hover-Status geändert hat
            if (oldHovered !== this.hoveredReservation) {
                this.scheduleRender('hover');
            }

            hoverTimeout = setTimeout(() => {
                hoverTimeout = null;
            }, 100);
        });

        this.canvas.addEventListener('mousedown', (e) => {
            this.handleMouseDown(e);
        });

        this.canvas.addEventListener('mouseup', (e) => {
            this.handleMouseUp(e);
        });

        // Global mouse events für besseres Drag & Drop - nur ein Handler
        document.addEventListener('mousemove', (e) => {
            // Optimiertes Throttling für alle Drag-Operationen
            const now = Date.now();
            if (now - this.lastDragRender < 16) return; // 60 FPS für alle Drags - optimiert
            this.lastDragRender = now;

            if (this.isDraggingReservation) {
                this.handleReservationDrag(e);
                this.scheduleRender('drag');
            }
            // Separator-Dragging über document für bessere UX
            else if (this.isDraggingSeparator || this.isDraggingBottomSeparator) {
                const rect = this.canvas.getBoundingClientRect();
                const mouseY = e.clientY - rect.top;

                if (this.isDraggingSeparator) {
                    this.handleTopSeparatorDrag(mouseY);
                } else {
                    this.handleBottomSeparatorDrag(mouseY);
                }
                this.scheduleRender('separator');
            }
        }, { passive: true });

        document.addEventListener('mouseup', (e) => {
            if (this.isDraggingReservation) {
                this.finishReservationDrag();
                this.scheduleRender('drag_end');
            }
            // Separator-MouseUp über document
            else if (this.isDraggingSeparator || this.isDraggingBottomSeparator) {
                if (this.isDraggingSeparator) {
                    this.saveToCookie('separatorTop', this.separatorY);
                }
                if (this.isDraggingBottomSeparator) {
                    this.saveToCookie('separatorBottom', this.bottomSeparatorY);
                }
                this.isDraggingSeparator = false;
                this.isDraggingBottomSeparator = false;
                this.draggingType = null;
            }
        }, { passive: true }); this.canvas.addEventListener('mouseleave', () => {
            this.hoveredReservation = null;
            this.scheduleRender('mouseleave');
        });

        // Setup drag & drop events for separator
        this.setupSeparatorEvents();
    }

    updateLayoutAreas() {
        const maxTopSeparatorY = this.canvas.height * 0.5;
        const minBottomSeparatorY = this.canvas.height * 0.6;
        const maxBottomSeparatorY = this.canvas.height - 40; // 40px für Scrollbar

        // Oberer Separator begrenzen
        this.separatorY = Math.min(this.separatorY, maxTopSeparatorY);

        // Unterer Separator begrenzen und sicherstellen dass er unter dem oberen ist
        this.bottomSeparatorY = Math.max(minBottomSeparatorY,
            Math.min(this.bottomSeparatorY, maxBottomSeparatorY));
        this.bottomSeparatorY = Math.max(this.bottomSeparatorY, this.separatorY + 100);

        // Layout-Bereiche aktualisieren (Menü + Header wieder hinzugefügt)
        this.areas.master.height = this.separatorY - 60;
        this.areas.master.y = 60;
        this.areas.rooms.y = this.separatorY;
        this.areas.rooms.height = this.bottomSeparatorY - this.separatorY;
        this.areas.histogram.y = this.bottomSeparatorY;
        this.areas.histogram.height = (this.canvas.height - this.bottomSeparatorY - 20) * 0.95; // 95% Ausnutzung

        // Scrollbars nach Layout-Änderung neu positionieren
        this.positionScrollbars();
    }

    setupSeparatorEvents() {
        // Separator Events sind bereits in setupEvents() und handleMouseDown() integriert
        // Diese Methode wird nur für Kompatibilität beibehalten
    }

    isOverTopSeparator(mouseY) {
        const tolerance = 5;
        return Math.abs(mouseY - this.separatorY) <= tolerance;
    }

    isOverBottomSeparator(mouseY) {
        const tolerance = 5;
        return Math.abs(mouseY - this.bottomSeparatorY) <= tolerance;
    }

    handleTopSeparatorDrag(mouseY) {
        const minY = 80; // Mindestens etwas Platz für Menü + Header + Master
        const maxY = this.canvas.height * 0.5;

        this.separatorY = Math.max(minY, Math.min(maxY, mouseY));
        this.updateLayoutAreas();
        this.scheduleRender('separator_drag');
    }

    handleBottomSeparatorDrag(mouseY) {
        const minY = Math.max(this.separatorY + 100, this.canvas.height * 0.6);
        const maxY = this.canvas.height - 40; // Platz für Scrollbar

        this.bottomSeparatorY = Math.max(minY, Math.min(maxY, mouseY));
        this.updateLayoutAreas();
        this.scheduleRender('separator_drag');
    }

    // ===== DRAG & DROP FÜR RESERVIERUNGEN =====

    handleMouseDown(e) {
        // Config-Button Click-Check hat höchste Priorität
        const rect = this.canvas.getBoundingClientRect();
        const mouseY = e.clientY - rect.top;
        const mouseX = e.clientX - rect.left;

        if (this.isConfigButtonHovered && this.configButtonBounds) {
            // Öffne Konfigurationsseite
            window.location.href = 'timeline-config.html';
            e.preventDefault();
            return;
        }

        // Separator-Handling hat Priorität
        if (this.isOverTopSeparator(mouseY)) {
            this.isDraggingSeparator = true;
            this.draggingType = 'top';
            e.preventDefault();
            return;
        } else if (this.isOverBottomSeparator(mouseY)) {
            this.isDraggingBottomSeparator = true;
            this.draggingType = 'bottom';
            e.preventDefault();
            return;
        }

        // Reservierung Drag & Drop nur wenn im Rooms-Bereich
        if (mouseY >= this.areas.rooms.y && mouseY <= this.areas.rooms.y + this.areas.rooms.height) {
            const reservation = this.findReservationAt(mouseX, mouseY);

            if (reservation) {
                this.startReservationDrag(reservation, mouseX, mouseY, e);
                e.preventDefault();
                e.stopPropagation();
            }
        }
    }

    handleMouseUp(e) {
        // Canvas MouseUp nur für lokale Events, globale Events über document
        // Separator handling wird über document.mouseup behandelt

        // Reservierung Drag & Drop wird über document.mouseup behandelt
        // Hier nur für Fallback
        if (this.isDraggingReservation) {
            this.finishReservationDrag();
        }
    }

    findReservationAt(mouseX, mouseY) {
        // Performance-optimiert: nur sichtbare Zimmer durchsuchen
        const startX = this.sidebarWidth - this.scrollX;
        const startY = this.areas.rooms.y - this.roomsScrollY;
        let currentYOffset = 0;

        // Date range für Position-Berechnung
        const now = new Date();
        now.setHours(0, 0, 0, 0); // Auf Mitternacht (0 Uhr) fixieren
        const startDate = new Date(now.getTime() - (14 * 24 * 60 * 60 * 1000));

        for (const room of rooms) {
            const baseRoomY = startY + currentYOffset;
            const roomHeight = room._dynamicHeight || 25;

            // Nur wenn Maus im Zimmer-Bereich ist
            if (mouseY >= baseRoomY && mouseY <= baseRoomY + roomHeight) {
                // Zimmer-Reservierungen für dieses Zimmer finden
                const roomReservations = roomDetails.filter(detail =>
                    detail.room_id === room.id ||
                    String(detail.room_id) === String(room.id) ||
                    Number(detail.room_id) === Number(room.id)
                );

                // WICHTIG: Positionsdaten berechnen BEVOR wir suchen
                const positionedReservations = roomReservations.map(detail => {
                    const checkinDate = new Date(detail.start);
                    checkinDate.setHours(12, 0, 0, 0);
                    const checkoutDate = new Date(detail.end);
                    checkoutDate.setHours(12, 0, 0, 0);

                    const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                    const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                    const left = startX + (startOffset + 0.1) * this.DAY_WIDTH;
                    const width = (duration - 0.2) * this.DAY_WIDTH;

                    return { ...detail, left, width, startOffset, duration };
                });

                // Stacking-Berechnung (vereinfacht)
                const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;
                const sortedReservations = positionedReservations
                    .filter(item => item.left + item.width > this.sidebarWidth - 100 &&
                        item.left < this.canvas.width + 100)
                    .sort((a, b) => a.startOffset - b.startOffset);

                sortedReservations.forEach((reservation, index) => {
                    let stackLevel = 0;
                    let placed = false;

                    while (!placed) {
                        let canPlaceHere = true;

                        for (let i = 0; i < index; i++) {
                            const other = sortedReservations[i];
                            if (other.stackLevel === stackLevel) {
                                const reservationEnd = reservation.left + reservation.width;
                                const otherEnd = other.left + other.width;

                                if (!(reservationEnd <= other.left + OVERLAP_TOLERANCE ||
                                    reservation.left >= otherEnd - OVERLAP_TOLERANCE)) {
                                    canPlaceHere = false;
                                    break;
                                }
                            }
                        }

                        if (canPlaceHere) {
                            reservation.stackLevel = stackLevel;
                            placed = true;
                        } else {
                            stackLevel++;
                        }

                        if (stackLevel > 10) {
                            reservation.stackLevel = stackLevel;
                            placed = true;
                        }
                    }
                });

                // Jetzt durchsuchen mit korrekten Positionsdaten
                for (const reservation of sortedReservations) {
                    const barHeight = this.themeConfig.room.barHeight || 16;
                    const stackY = baseRoomY + 1 + (reservation.stackLevel * (barHeight + 2));

                    if (mouseX >= reservation.left && mouseX <= reservation.left + reservation.width &&
                        mouseY >= stackY && mouseY <= stackY + barHeight) {
                        return { ...reservation, room_id: room.id, stackY, barHeight };
                    }
                }
                break; // Nur ein Zimmer kann getroffen werden
            }
            currentYOffset += roomHeight;
        }
        return null;
    }

    startReservationDrag(reservation, mouseX, mouseY, e) {
        this.isDraggingReservation = true;
        this.draggedReservation = reservation;
        this.dragStartX = mouseX;
        this.dragStartY = mouseY;

        // Bestimme Drag-Modus basierend auf Position
        const edgeThreshold = 8; // Pixel-Bereich für Resize-Handles
        const relativeX = mouseX - reservation.left;

        if (relativeX <= edgeThreshold) {
            this.dragMode = 'resize-start';
        } else if (relativeX >= reservation.width - edgeThreshold) {
            this.dragMode = 'resize-end';
        } else {
            this.dragMode = 'move';
        }

        // Original-Daten für Rollback speichern
        this.dragOriginalData = {
            start: new Date(reservation.start),
            end: new Date(reservation.end),
            room_id: reservation.room_id
        };

        this.canvas.style.cursor = this.dragMode === 'move' ? 'grabbing' : 'col-resize';

        // Ghost-Bar initialisieren
        this.ghostBar = {
            visible: true,
            mode: this.dragMode,
            originalReservation: { ...reservation }
        };
    }

    handleReservationDrag(e) {
        if (!this.isDraggingReservation || !this.draggedReservation) return;

        const rect = this.canvas.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        const deltaX = mouseX - this.dragStartX;
        const deltaY = mouseY - this.dragStartY;

        // Berechne neue Datums-Werte basierend auf Drag-Modus
        const daysDelta = Math.round(deltaX / this.DAY_WIDTH);

        // Berechne Ghost-Bar Position (diskret)
        this.updateGhostBar(mouseX, mouseY, daysDelta);

        if (this.dragMode === 'move') {
            this.handleReservationMove(daysDelta, mouseY);
        } else if (this.dragMode === 'resize-start') {
            this.handleReservationResizeStart(daysDelta);
        } else if (this.dragMode === 'resize-end') {
            this.handleReservationResizeEnd(daysDelta);
        }

        // Finde Ziel-Zimmer bei Move-Operation
        if (this.dragMode === 'move') {
            this.dragTargetRoom = this.findRoomAt(mouseY);
        }

        // Live-Update des Stackings während dem Drag
        this.updateRoomStacking();
    }

    handleReservationMove(daysDelta, mouseY) {
        const duration = this.dragOriginalData.end.getTime() - this.dragOriginalData.start.getTime();

        // Neue Start- und End-Daten berechnen
        const newStart = new Date(this.dragOriginalData.start.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
        const newEnd = new Date(newStart.getTime() + duration);

        // Update temporär für Vorschau
        this.draggedReservation.start = newStart;
        this.draggedReservation.end = newEnd;
        this.updateReservationPosition(this.draggedReservation);
    }

    handleReservationResizeStart(daysDelta) {
        const newStart = new Date(this.dragOriginalData.start.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
        const minDuration = 24 * 60 * 60 * 1000; // 1 Tag Minimum

        // Prüfe Mindestdauer
        if (this.dragOriginalData.end.getTime() - newStart.getTime() >= minDuration) {
            this.draggedReservation.start = newStart;
            this.updateReservationPosition(this.draggedReservation);
        }
    }

    handleReservationResizeEnd(daysDelta) {
        const newEnd = new Date(this.dragOriginalData.end.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
        const minDuration = 24 * 60 * 60 * 1000; // 1 Tag Minimum

        // Prüfe Mindestdauer
        if (newEnd.getTime() - this.dragOriginalData.start.getTime() >= minDuration) {
            this.draggedReservation.end = newEnd;
            this.updateReservationPosition(this.draggedReservation);
        }
    }

    updateReservationPosition(reservation) {
        const now = new Date();
        now.setHours(0, 0, 0, 0); // Auf Mitternacht (0 Uhr) fixieren
        const startDate = new Date(now.getTime() - (14 * 24 * 60 * 60 * 1000));

        const checkinDate = new Date(reservation.start);
        checkinDate.setHours(12, 0, 0, 0);
        const checkoutDate = new Date(reservation.end);
        checkoutDate.setHours(12, 0, 0, 0);

        const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
        const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

        const startX = this.sidebarWidth - this.scrollX;
        reservation.left = startX + (startOffset + 0.1) * this.DAY_WIDTH;
        reservation.width = (duration - 0.2) * this.DAY_WIDTH;
    }

    findRoomAt(mouseY) {
        const startY = this.areas.rooms.y - this.roomsScrollY;
        let currentYOffset = 0;

        for (const room of rooms) {
            const baseRoomY = startY + currentYOffset;
            const roomHeight = room._dynamicHeight || 25;

            if (mouseY >= baseRoomY && mouseY <= baseRoomY + roomHeight) {
                return room;
            }
            currentYOffset += roomHeight;
        }
        return null;
    }

    finishReservationDrag() {
        if (!this.isDraggingReservation || !this.draggedReservation) return;

        const originalRoomId = this.dragOriginalData.room_id;
        let targetRoomId = originalRoomId;

        // Bei Move-Operation: Zimmer wechseln wenn nötig
        if (this.dragMode === 'move' && this.dragTargetRoom &&
            this.dragTargetRoom.id !== this.dragOriginalData.room_id) {
            this.draggedReservation.room_id = this.dragTargetRoom.id;
            targetRoomId = this.dragTargetRoom.id;
        }

        // Aktualisiere roomDetails Array ZUERST - das ist kritisch für korrekte Referenzen
        const originalIndex = roomDetails.findIndex(detail =>
            detail === this.draggedReservation ||
            (detail.id && detail.id === this.draggedReservation.id) ||
            (detail.detail_id && detail.detail_id === this.draggedReservation.detail_id) ||
            (detail.data && detail.data.detail_id === this.draggedReservation.data?.detail_id)
        );

        if (originalIndex !== -1) {
            // Wichtig: Komplett neue Kopie erstellen, um Referenz-Probleme zu vermeiden
            roomDetails[originalIndex] = {
                ...this.draggedReservation,
                room_id: targetRoomId, // Sicherstellen dass room_id korrekt gesetzt ist
                start: new Date(this.draggedReservation.start).toISOString(),
                end: new Date(this.draggedReservation.end).toISOString()
            };
        }

        // Re-initialize data index NACH Array-Update
        if (this.dataIndex) {
            this.initializeDataIndex(reservations, roomDetails);
        }

        // Invalidate stacking cache NACH Daten-Update
        this.invalidateStackingCache(originalRoomId);
        if (targetRoomId !== originalRoomId) {
            this.invalidateStackingCache(targetRoomId);
        }

        // Force room height recalculation für betroffene Zimmer
        const affectedRooms = [originalRoomId];
        if (targetRoomId !== originalRoomId) {
            affectedRooms.push(targetRoomId);
        }

        affectedRooms.forEach(roomId => {
            const room = rooms.find(r =>
                r.id === roomId ||
                String(r.id) === String(roomId) ||
                Number(r.id) === Number(roomId)
            );
            if (room) {
                // Reset room height to trigger recalculation
                delete room._dynamicHeight;
            }
        });

        this.cancelDrag();
    }

    cancelDrag() {
        if (this.isDraggingReservation && this.draggedReservation && this.dragOriginalData) {
            // Rollback bei Abbruch
            this.draggedReservation.start = this.dragOriginalData.start;
            this.draggedReservation.end = this.dragOriginalData.end;
            this.draggedReservation.room_id = this.dragOriginalData.room_id;
        }

        this.isDraggingReservation = false;
        this.draggedReservation = null;
        this.dragMode = null;
        this.dragOriginalData = null;
        this.dragTargetRoom = null;
        this.ghostBar = null; // Ghost-Bar ausblenden
        this.canvas.style.cursor = 'default';
    }

    updateGhostBar(mouseX, mouseY, daysDelta) {
        if (!this.ghostBar || !this.draggedReservation) return;

        const now = new Date();
        now.setHours(0, 0, 0, 0); // Auf Mitternacht (0 Uhr) fixieren
        const startDate = new Date(now.getTime() - (14 * 24 * 60 * 60 * 1000));
        const startX = this.sidebarWidth - this.scrollX;

        // Berechne diskrete Werte basierend auf Drag-Modus
        if (this.dragMode === 'move') {
            // Diskrete Tages-Position
            const originalStart = new Date(this.dragOriginalData.start);
            const newStart = new Date(originalStart.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
            const duration = this.dragOriginalData.end.getTime() - this.dragOriginalData.start.getTime();

            // Position berechnen
            const checkinDate = new Date(newStart);
            checkinDate.setHours(12, 0, 0, 0);
            const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
            const durationDays = duration / (1000 * 60 * 60 * 24);

            this.ghostBar.x = startX + (startOffset + 0.1) * this.DAY_WIDTH;
            this.ghostBar.width = (durationDays - 0.2) * this.DAY_WIDTH;

            // Diskrete Zimmer-Position
            const targetRoom = this.findRoomAt(mouseY);
            if (targetRoom) {
                this.ghostBar.targetRoom = targetRoom;
                this.ghostBar.y = this.calculateRoomY(targetRoom) + 1; // +1 für Padding
                this.ghostBar.height = 16;
            }

        } else if (this.dragMode === 'resize-start') {
            // Resize am Anfang - diskrete Tages-Schritte
            const originalStart = new Date(this.dragOriginalData.start);
            const newStart = new Date(originalStart.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
            const minDuration = 24 * 60 * 60 * 1000; // 1 Tag minimum

            if (this.dragOriginalData.end.getTime() - newStart.getTime() >= minDuration) {
                const checkinDate = new Date(newStart);
                checkinDate.setHours(12, 0, 0, 0);
                const checkoutDate = new Date(this.dragOriginalData.end);
                checkoutDate.setHours(12, 0, 0, 0);

                const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                this.ghostBar.x = startX + (startOffset + 0.1) * this.DAY_WIDTH;
                this.ghostBar.width = (duration - 0.2) * this.DAY_WIDTH;
                this.ghostBar.y = this.calculateRoomY(this.findRoomByReservation(this.draggedReservation)) + 1;
                this.ghostBar.height = 16;
            }

        } else if (this.dragMode === 'resize-end') {
            // Resize am Ende - diskrete Tages-Schritte
            const originalEnd = new Date(this.dragOriginalData.end);
            const newEnd = new Date(originalEnd.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
            const minDuration = 24 * 60 * 60 * 1000; // 1 Tag minimum

            if (newEnd.getTime() - this.dragOriginalData.start.getTime() >= minDuration) {
                const checkinDate = new Date(this.dragOriginalData.start);
                checkinDate.setHours(12, 0, 0, 0);
                const checkoutDate = new Date(newEnd);
                checkoutDate.setHours(12, 0, 0, 0);

                const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                this.ghostBar.x = startX + (startOffset + 0.1) * this.DAY_WIDTH;
                this.ghostBar.width = (duration - 0.2) * this.DAY_WIDTH;
                this.ghostBar.y = this.calculateRoomY(this.findRoomByReservation(this.draggedReservation)) + 1;
                this.ghostBar.height = 16;
            }
        }
    }

    calculateRoomY(room) {
        if (!room) return 0;

        const startY = this.areas.rooms.y - this.roomsScrollY;
        let currentYOffset = 0;

        for (const r of rooms) {
            if (r.id === room.id) {
                return startY + currentYOffset;
            }
            currentYOffset += r._dynamicHeight || 25;
        }
        return startY;
    }

    findRoomByReservation(reservation) {
        return rooms.find(room =>
            room.id === reservation.room_id ||
            String(room.id) === String(reservation.room_id) ||
            Number(room.id) === Number(reservation.room_id)
        );
    }

    renderGhostBar() {
        if (!this.ghostBar || !this.ghostBar.visible || !this.isDraggingReservation) return;

        const ctx = this.ctx;
        ctx.save();

        // Ghost-Effekt: halbtransparent und verschwommen
        ctx.globalAlpha = 0.5;
        ctx.shadowColor = 'rgba(255, 255, 255, 0.8)';
        ctx.shadowBlur = 8;
        ctx.shadowOffsetX = 0;
        ctx.shadowOffsetY = 0;

        // Farbe basierend auf Drag-Modus
        let ghostColor;
        if (this.dragMode === 'move') {
            ghostColor = this.ghostBar.targetRoom &&
                this.ghostBar.targetRoom.id !== this.dragOriginalData.room_id
                ? '#4CAF50' : '#2196F3'; // Grün für Zimmer-Wechsel, Blau für normale Bewegung
        } else {
            ghostColor = '#FF9800'; // Orange für Resize
        }

        ctx.fillStyle = ghostColor;

        // Rounded Rectangle für Ghost-Bar
        this.roundedRect(this.ghostBar.x, this.ghostBar.y, this.ghostBar.width, this.ghostBar.height, 3);
        ctx.fill();

        // Dünner Rahmen
        ctx.shadowBlur = 0;
        ctx.globalAlpha = 0.8;
        ctx.strokeStyle = ghostColor;
        ctx.lineWidth = 1;
        ctx.stroke();

        ctx.restore();
    }

    updateCursor() {
        if (this.isDraggingReservation || this.isDraggingSeparator || this.isDraggingBottomSeparator) {
            return; // Cursor nicht ändern während Drag-Operationen
        }

        // Config-Button Cursor
        if (this.isConfigButtonHovered) {
            this.canvas.style.cursor = 'pointer';
            return;
        }

        // Separator-Cursor hat Priorität
        const overTopSeparator = this.isOverTopSeparator(this.mouseY);
        const overBottomSeparator = this.isOverBottomSeparator(this.mouseY);

        if (overTopSeparator || overBottomSeparator) {
            this.canvas.style.cursor = 'row-resize';
            return;
        }

        // Reservierung-Cursor nur im Rooms-Bereich
        if (this.mouseY >= this.areas.rooms.y && this.mouseY <= this.areas.rooms.y + this.areas.rooms.height) {
            const reservation = this.findReservationAt(this.mouseX, this.mouseY);
            if (reservation) {
                const edgeThreshold = 8;
                const relativeX = this.mouseX - reservation.left;

                if (relativeX <= edgeThreshold || relativeX >= reservation.width - edgeThreshold) {
                    this.canvas.style.cursor = 'col-resize';
                } else {
                    this.canvas.style.cursor = 'grab';
                }
                return;
            }
        }

        this.canvas.style.cursor = 'default';
    }

    updateRoomStacking() {
        // Phase 2: Use cache-aware approach
        if (this.dataIndex) {
            // Only update rooms that have reservations
            for (const roomId of this.dataIndex.reservationsByRoom.keys()) {
                this.invalidateStackingCache(roomId);
            }
        } else {
            // Fallback: Traditional approach
            rooms.forEach(room => {
                const roomReservations = roomDetails.filter(detail =>
                    detail.room_id === room.id ||
                    String(detail.room_id) === String(room.id) ||
                    Number(detail.room_id) === Number(room.id)
                );

                // Stacking-Algorithmus anwenden
                const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;
                let maxStackLevel = 0;

                const sortedReservations = roomReservations
                    .map(detail => {
                        this.updateReservationPosition(detail);
                        return detail;
                    })
                    .sort((a, b) => a.startOffset - b.startOffset);

                sortedReservations.forEach((reservation, index) => {
                    let stackLevel = 0;
                    let placed = false;

                    while (!placed) {
                        let canPlaceHere = true;

                        for (let i = 0; i < index; i++) {
                            const other = sortedReservations[i];
                            if (other.stackLevel === stackLevel) {
                                const reservationEnd = reservation.left + reservation.width;
                                const otherEnd = other.left + other.width;

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

                        if (stackLevel > 10) {
                            reservation.stackLevel = stackLevel;
                            maxStackLevel = Math.max(maxStackLevel, stackLevel);
                            placed = true;
                        }
                    }
                });

                // Update Zimmer-Höhe
                const barHeight = this.themeConfig.room.barHeight || 16;
                const roomHeight = Math.max(20, 4 + (maxStackLevel + 1) * (barHeight + 0));
                room._dynamicHeight = roomHeight;
            });
        }
    }

    render() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        if (reservations.length === 0) {
            this.renderEmpty();
            return;
        }

        // Initialize data index if not done yet
        if (!this.dataIndex && typeof reservations !== 'undefined' && typeof roomDetails !== 'undefined') {
            this.initializeDataIndex(reservations, roomDetails);
        }

        // Neue Datums-Logik: now - 2 weeks bis now + 2 years (auf 0 Uhr fixiert)
        const now = new Date();
        now.setHours(0, 0, 0, 0); // Auf Mitternacht (0 Uhr) fixieren
        const startDate = new Date(now.getTime() - (14 * 24 * 60 * 60 * 1000)); // now - 2 weeks
        const endDate = new Date(now.getTime() + (0.5 * 365 * 24 * 60 * 60 * 1000)); // now + 2 years

        // Pre-calculate room heights for correct scrollbar sizing
        this.preCalculateRoomHeights(startDate, endDate);

        const totalDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));
        const timelineWidth = totalDays * this.DAY_WIDTH;

        // Update scroll tracks
        const scrollContentH = this.container.querySelector('.scroll-content-h');
        if (scrollContentH) {
            scrollContentH.style.width = timelineWidth + 'px';
        }

        // Viewport Culling für bessere Performance - aber großzügiger für Sichtbarkeit
        const visibleReservations = reservations; // Temporär alle verwenden für korrekte Darstellung

        // Update Master-Scrollbar Content-Höhe
        const scrollContentMaster = this.container.querySelector('.scroll-content-master');
        if (scrollContentMaster && reservations.length > 0) {
            // Berechne tatsächliche maximale Stack-Höhe für Master-Bereich
            const maxStackLevel = this.calculateMasterMaxStackLevel(startDate, endDate, reservations);
            const barHeight = this.themeConfig.master.barHeight || 14;
            const masterContentHeight = Math.max(this.areas.master.height, 10 + (maxStackLevel + 1) * barHeight + 50);
            scrollContentMaster.style.height = masterContentHeight + 'px';
        }

        // Update Rooms-Scrollbar Content-Höhe  
        const scrollContentRooms = this.container.querySelector('.scroll-content-rooms');
        if (scrollContentRooms) {
            const totalRoomHeight = rooms.reduce((sum, room) => sum + (room._dynamicHeight || 25), 0);
            scrollContentRooms.style.height = Math.max(this.areas.rooms.height, totalRoomHeight + 200) + 'px';
        }

        // Render alle Bereiche (Menü + Header wieder hinzugefügt)
        this.renderSidebar();
        this.renderMenu();
        this.renderHeader(startDate, endDate);
        this.renderMasterArea(startDate, endDate, reservations); // Alle Reservierungen verwenden
        this.renderRoomsAreaOptimized(startDate, endDate); // Use optimized version
        this.renderHistogramArea(startDate, endDate);
        this.renderVerticalGridLines(startDate, endDate);
        this.renderSeparators();

        // Ghost-Bar als letztes rendern (über allem)
        this.renderGhostBar();
    }

    // Pre-calculate room heights to ensure correct scrollbar sizing
    preCalculateRoomHeights(startDate, endDate) {
        if (!rooms || rooms.length === 0) return;

        rooms.forEach(room => {
            // Skip if already calculated
            if (room._dynamicHeight) return;

            // Try cached stacking first
            let stackingResult;
            try {
                stackingResult = this.getStackingForRoom(room.id, startDate, endDate);
            } catch (e) {
                stackingResult = null;
            }

            // Fallback: Manual calculation if cache fails
            if (!stackingResult || !stackingResult.reservations) {
                // Manual room details processing
                const roomReservations = roomDetails.filter(detail =>
                    detail.room_id === room.id ||
                    String(detail.room_id) === String(room.id) ||
                    Number(detail.room_id) === Number(room.id)
                );

                if (roomReservations.length > 0) {
                    // Quick stacking calculation without full position data
                    const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;
                    let maxStackLevel = 0;

                    const reservationsByTime = roomReservations.map(detail => {
                        const checkinDate = new Date(detail.start);
                        checkinDate.setHours(12, 0, 0, 0);
                        const checkoutDate = new Date(detail.end);
                        checkoutDate.setHours(12, 0, 0, 0);

                        const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                        const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                        return {
                            ...detail,
                            startOffset,
                            duration,
                            stackLevel: 0
                        };
                    }).sort((a, b) => a.startOffset - b.startOffset);

                    // Simple stacking algorithm
                    reservationsByTime.forEach((reservation, index) => {
                        let stackLevel = 0;
                        let placed = false;

                        while (!placed) {
                            let canPlaceHere = true;

                            for (let i = 0; i < index; i++) {
                                const other = reservationsByTime[i];
                                if (other.stackLevel === stackLevel) {
                                    const reservationEnd = reservation.startOffset + reservation.duration;
                                    const otherEnd = other.startOffset + other.duration;

                                    if (!(reservationEnd <= other.startOffset + (OVERLAP_TOLERANCE / this.DAY_WIDTH) ||
                                        reservation.startOffset >= otherEnd - (OVERLAP_TOLERANCE / this.DAY_WIDTH))) {
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

                            if (stackLevel > 15) {
                                reservation.stackLevel = stackLevel;
                                maxStackLevel = Math.max(maxStackLevel, stackLevel);
                                placed = true;
                            }
                        }
                    });

                    const barHeight = this.themeConfig.room.barHeight || 16;
                    const roomHeight = Math.max(25, 4 + (maxStackLevel + 1) * (barHeight + 2));
                    room._dynamicHeight = roomHeight;
                } else {
                    room._dynamicHeight = 25;
                }
            } else {
                room._dynamicHeight = stackingResult.roomHeight;
            }
        });
    }

    calculateMasterMaxStackLevel(startDate, endDate, visibleReservations = null) {
        // Verwende ALLE Reservierungen für Master-Bereich, nicht nur sichtbare
        const reservationsToCheck = reservations; // Alle Reservierungen verwenden
        if (reservationsToCheck.length === 0) return 0;

        const stackLevels = [];
        const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.25;
        let maxStackLevel = 0;

        const sortedReservations = [...reservationsToCheck].sort((a, b) =>
            new Date(a.start).getTime() - new Date(b.start).getTime()
        );

        sortedReservations.forEach(reservation => {
            const checkinDate = new Date(reservation.start);
            checkinDate.setHours(12, 0, 0, 0);
            const checkoutDate = new Date(reservation.end);
            checkoutDate.setHours(12, 0, 0, 0);

            const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
            const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

            // Verwende ABSOLUTE Position ohne Scroll-Offset für Stack-Berechnung
            const left = this.sidebarWidth + (startOffset + 0.1) * this.DAY_WIDTH;
            const width = (duration - 0.2) * this.DAY_WIDTH;

            // Stack-Level finden (gleicher Algorithmus wie in renderMasterArea)
            let stackLevel = 0;
            while (stackLevel < stackLevels.length &&
                stackLevels[stackLevel] > left + OVERLAP_TOLERANCE) {
                stackLevel++;
            }

            while (stackLevels.length <= stackLevel) {
                stackLevels.push(0);
            }

            stackLevels[stackLevel] = left + width + 5;
            maxStackLevel = Math.max(maxStackLevel, stackLevel);
        });

        return maxStackLevel;
    }

    renderEmpty() {
        this.ctx.fillStyle = '#666';
        this.ctx.font = '16px Arial';
        this.ctx.textAlign = 'center';
        this.ctx.fillText('Keine Daten geladen', this.canvas.width / 2, this.canvas.height / 2);
    }

    renderSeparators() {
        const ctx = this.ctx;
        ctx.save();

        // Oberer Separator (Master/Rooms)
        if (this.isDraggingSeparator) {
            ctx.strokeStyle = '#007acc';
            ctx.lineWidth = 3;
        } else {
            ctx.strokeStyle = '#ddd';
            ctx.lineWidth = 1;
        }

        ctx.beginPath();
        ctx.moveTo(0, this.separatorY);
        ctx.lineTo(this.canvas.width, this.separatorY);
        ctx.stroke();

        // Griff für oberen Separator
        if (this.isDraggingSeparator) {
            ctx.fillStyle = '#007acc';
            const handleWidth = 20;
            const handleHeight = 4;
            const centerX = this.canvas.width / 2;
            ctx.fillRect(centerX - handleWidth / 2, this.separatorY - handleHeight / 2, handleWidth, handleHeight);
        }

        // Unterer Separator (Rooms/Histogram)
        if (this.isDraggingBottomSeparator) {
            ctx.strokeStyle = '#007acc';
            ctx.lineWidth = 3;
        } else {
            ctx.strokeStyle = '#ddd';
            ctx.lineWidth = 1;
        }

        ctx.beginPath();
        ctx.moveTo(0, this.bottomSeparatorY);
        ctx.lineTo(this.canvas.width, this.bottomSeparatorY);
        ctx.stroke();

        // Griff für unteren Separator
        if (this.isDraggingBottomSeparator) {
            ctx.fillStyle = '#007acc';
            const handleWidth = 20;
            const handleHeight = 4;
            const centerX = this.canvas.width / 2;
            ctx.fillRect(centerX - handleWidth / 2, this.bottomSeparatorY - handleHeight / 2, handleWidth, handleHeight);
        }

        ctx.restore();
    }

    renderMenu() {
        const area = this.areas.menu;

        // Menü-Hintergrund
        this.ctx.fillStyle = this.lightenColor(this.themeConfig.sidebar.bg, 5);
        this.ctx.fillRect(0, area.y, this.canvas.width, area.height);

        // Config-Button im Menü (rechts)
        this.renderConfigButtonInMenu();

        // Menü-Border unten
        this.ctx.strokeStyle = '#ddd';
        this.ctx.lineWidth = 1;
        this.ctx.beginPath();
        this.ctx.moveTo(0, area.y + area.height);
        this.ctx.lineTo(this.canvas.width, area.y + area.height);
        this.ctx.stroke();
    }

    renderConfigButtonInMenu() {
        const area = this.areas.menu;
        const buttonWidth = 60;
        const buttonHeight = 16;
        const buttonX = this.canvas.width - buttonWidth - 5;
        const buttonY = area.y + 2;

        // Button-Hintergrund
        this.ctx.fillStyle = this.isConfigButtonHovered ?
            this.lightenColor(this.themeConfig.sidebar.bg, 30) :
            this.lightenColor(this.themeConfig.sidebar.bg, 15);

        this.roundedRect(buttonX, buttonY, buttonWidth, buttonHeight, 3);
        this.ctx.fill();

        // Button-Border
        this.ctx.strokeStyle = this.themeConfig.sidebar.text;
        this.ctx.lineWidth = 0.5;
        this.ctx.stroke();

        // Button-Text
        this.ctx.fillStyle = this.themeConfig.sidebar.text;
        this.ctx.font = '9px Arial';
        this.ctx.textAlign = 'center';
        this.ctx.fillText('⚙️ Config', buttonX + buttonWidth / 2, buttonY + 10);

        // Button-Position für Click-Detection speichern
        this.configButtonBounds = {
            x: buttonX,
            y: buttonY,
            width: buttonWidth,
            height: buttonHeight
        };
    }

    renderSidebar() {
        // Sidebar-Hintergrund mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.sidebar.bg;
        this.ctx.fillRect(0, 0, this.sidebarWidth, this.canvas.height);

        // Sidebar-Border
        this.ctx.strokeStyle = '#ddd';
        this.ctx.lineWidth = 1;
        this.ctx.beginPath();
        this.ctx.moveTo(this.sidebarWidth, 0);
        this.ctx.lineTo(this.sidebarWidth, this.canvas.height);
        this.ctx.stroke();

        // Labels mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.sidebar.text;
        this.ctx.font = `${this.themeConfig.sidebar.fontSize}px Arial`;
        this.ctx.textAlign = 'center';

        this.ctx.fillText('Alle', this.sidebarWidth / 2, this.areas.master.y + 20);

        // Zimmer-Label
        this.ctx.save();
        this.ctx.translate(this.sidebarWidth / 2, this.areas.rooms.y + this.areas.rooms.height / 2);
        this.ctx.rotate(-Math.PI / 2);
        //this.ctx.fillText('Zimmer', 0, 5);
        this.ctx.restore();

        this.ctx.fillText('Auslastung', this.sidebarWidth / 2, this.areas.histogram.y + 20);
    }

    renderHeader(startDate, endDate) {
        const area = this.areas.header;
        const startX = this.sidebarWidth - this.scrollX;

        // Header-Hintergrund mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.header.bg;
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Datum-Header mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.header.text;
        this.ctx.font = `${this.themeConfig.header.fontSize}px Arial`;
        this.ctx.textAlign = 'center';

        const currentDate = new Date(startDate);
        let dayIndex = 0;

        while (currentDate <= endDate) {
            const x = startX + (dayIndex * this.DAY_WIDTH) + (this.DAY_WIDTH / 2);

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

    renderMasterArea(startDate, endDate, visibleReservations = null) {
        const area = this.areas.master;
        const startX = this.sidebarWidth - this.scrollX;

        // Area-Hintergrund mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.master.bg;
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Verwende ALLE Reservierungen für Master-Bereich - KEIN Viewport-Filter!
        const reservationsToRender = reservations; // Alle Reservierungen ohne Filter verwenden

        // Stack-Algorithmus für Master-Reservierungen
        const stackLevels = [];
        const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.25;

        const sortedReservations = [...reservationsToRender].sort((a, b) =>
            new Date(a.start).getTime() - new Date(b.start).getTime()
        );

        sortedReservations.forEach(reservation => {
            const checkinDate = new Date(reservation.start);
            checkinDate.setHours(12, 0, 0, 0);
            const checkoutDate = new Date(reservation.end);
            checkoutDate.setHours(12, 0, 0, 0);

            const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
            const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

            const left = startX + (startOffset + 0.01) * this.DAY_WIDTH;
            const width = (duration - 0.005) * this.DAY_WIDTH;

            // Viewport-Check für Rendering - basierend auf absoluter Position OHNE Scroll
            const viewportLeft = this.scrollX - 1000;
            const viewportRight = this.scrollX + this.canvas.width + 1000;

            // Berechne absolute Position für Viewport-Check (ohne startX Scroll-Offset)
            const absoluteLeft = this.sidebarWidth + (startOffset + 0.01) * this.DAY_WIDTH;
            const absoluteRight = absoluteLeft + width;

            // Skip nur wenn WEIT außerhalb Viewport für Performance
            if (absoluteRight < viewportLeft || absoluteLeft > viewportRight) {
                return; // Skip weit entfernte Reservierungen
            }

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

            const barHeight = this.themeConfig.master.barHeight || 14;
            const top = area.y + 10 + (stackLevel * (barHeight + 2)) - this.masterScrollY;

            // Prüfe Hover-Status
            const isHovered = this.isReservationHovered(left, top, width, barHeight);

            if (isHovered) {
                this.hoveredReservation = reservation;
            }

            this.renderReservationBar(left, top, width, barHeight, reservation, isHovered);
        });

        this.ctx.restore();
    }

    renderRoomsArea(startDate, endDate) {
        const area = this.areas.rooms;
        const startX = this.sidebarWidth - this.scrollX;
        const startY = area.y - this.roomsScrollY;

        // Area-Hintergrund mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.room.bg;
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Zimmer-Zeilen rendern - nur sichtbare Zimmer für bessere Performance
        const visibleRooms = this.getVisibleRooms();

        visibleRooms.forEach(({ room, yOffset, height }) => {
            const baseRoomY = startY + yOffset;

            // Zimmer-Reservierungen
            const roomReservations = roomDetails.filter(detail => {
                return detail.room_id === room.id ||
                    String(detail.room_id) === String(room.id) ||
                    Number(detail.room_id) === Number(room.id);
            });

            // Stacking nur für sichtbare Reservierungen
            const stackLevels = [];
            const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;
            let maxStackLevel = 0;

            const sortedReservations = roomReservations
                .map(detail => {
                    const checkinDate = new Date(detail.start);
                    checkinDate.setHours(12, 0, 0, 0);
                    const checkoutDate = new Date(detail.end);
                    checkoutDate.setHours(12, 0, 0, 0);

                    const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                    const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                    const left = startX + (startOffset + 0.01) * this.DAY_WIDTH;
                    const width = (duration - 0.02) * this.DAY_WIDTH;

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

                while (!placed) {
                    let canPlaceHere = true;

                    for (let i = 0; i < index; i++) {
                        const other = sortedReservations[i];
                        if (other.stackLevel === stackLevel) {
                            const reservationEnd = reservation.left + reservation.width;
                            const otherEnd = other.left + other.width;

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

                    if (stackLevel > 10) {
                        reservation.stackLevel = stackLevel;
                        maxStackLevel = Math.max(maxStackLevel, stackLevel);
                        placed = true;
                    }
                }
            });

            // Update Zimmer-Höhe basierend auf Stacking
            const barHeight = this.themeConfig.room.barHeight || 16;
            const roomHeight = Math.max(20, 4 + (maxStackLevel + 1) * (barHeight + 0));
            room._dynamicHeight = roomHeight;

            // Zimmer-Hintergrund mit alternierenden Streifen
            this.ctx.save();
            const roomIndex = rooms.indexOf(room);
            const isDropTarget = this.isDraggingReservation && this.dragMode === 'move' &&
                this.dragTargetRoom && this.dragTargetRoom.id === room.id &&
                this.dragTargetRoom.id !== this.dragOriginalData?.room_id;

            if (isDropTarget) {
                this.ctx.fillStyle = '#4CAF50';
                this.ctx.globalAlpha = 0.3;
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, roomHeight);
                this.ctx.globalAlpha = 1.0;
            } else {
                this.ctx.globalAlpha = 0.2;
                this.ctx.fillStyle = roomIndex % 2 === 0 ? '#000000' : '#ffffff';
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, roomHeight);
                this.ctx.globalAlpha = 1.0;
            }
            this.ctx.restore();

            // Render Reservierungen
            sortedReservations.forEach(reservation => {
                const stackY = baseRoomY + 1 + (reservation.stackLevel * (barHeight + 2));
                const isHovered = this.isReservationHovered(reservation.left, stackY, reservation.width, barHeight);

                this.renderRoomReservationBar(reservation.left, stackY, reservation.width, barHeight, reservation, isHovered);

                if (isHovered) {
                    this.hoveredReservation = reservation;
                }
            });

            // Zimmer-Trennlinie
            this.ctx.save();
            this.ctx.strokeStyle = '#444';
            this.ctx.lineWidth = 1;
            this.ctx.beginPath();
            this.ctx.moveTo(this.sidebarWidth, baseRoomY + roomHeight);
            this.ctx.lineTo(this.canvas.width, baseRoomY + roomHeight);
            this.ctx.stroke();
            this.ctx.restore();
        });

        this.ctx.restore();

        // Zimmer-Captions im Sidebar-Bereich rendern
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(0, this.areas.rooms.y, this.sidebarWidth, this.areas.rooms.height);
        this.ctx.clip();

        visibleRooms.forEach(({ room, yOffset, height }) => {
            const baseRoomY = startY + yOffset;
            const roomDisplayY = baseRoomY + (height / 2) + (this.themeConfig.sidebar.fontSize / 3);

            if (roomDisplayY >= this.areas.rooms.y && roomDisplayY <= this.areas.rooms.y + this.areas.rooms.height) {
                this.ctx.fillStyle = this.themeConfig.sidebar.text;
                this.ctx.font = `${this.themeConfig.sidebar.fontSize}px Arial`;
                this.ctx.textAlign = 'center';

                const caption = room.caption || `R${room.id}`;
                this.ctx.fillText(caption, this.sidebarWidth / 2, roomDisplayY);
            }
        });

        this.ctx.restore();
    }

    renderRoomsAreaOptimized(startDate, endDate) {
        const area = this.areas.rooms;
        const startX = this.sidebarWidth - this.scrollX;
        const startY = area.y - this.roomsScrollY;

        // Area-Hintergrund mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.room.bg;
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Zimmer-Zeilen rendern - nur sichtbare Zimmer für bessere Performance
        const visibleRooms = this.getVisibleRooms();

        visibleRooms.forEach(({ room, yOffset, height }) => {
            const baseRoomY = startY + yOffset;

            // Try cached stacking first, fallback to manual calculation
            let stackingResult;
            try {
                stackingResult = this.getStackingForRoom(room.id, startDate, endDate);
            } catch (e) {
                console.warn('Cache failed, using fallback:', e);
                stackingResult = null;
            }

            // Fallback: Manual calculation if cache fails
            if (!stackingResult || !stackingResult.reservations) {
                // Manual room details processing - DIREKT aus roomDetails Array
                const roomReservations = roomDetails.filter(detail =>
                    detail.room_id === room.id ||
                    String(detail.room_id) === String(room.id) ||
                    Number(detail.room_id) === Number(room.id)
                );

                if (roomReservations.length > 0) {
                    // Berechne Positionen manuell - IDENTISCH zur Cache-Version
                    const positionedReservations = roomReservations.map(detail => {
                        const checkinDate = new Date(detail.start);
                        checkinDate.setHours(12, 0, 0, 0);
                        const checkoutDate = new Date(detail.end);
                        checkoutDate.setHours(12, 0, 0, 0);

                        const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                        const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                        const left = startX + (startOffset + 0.01) * this.DAY_WIDTH;
                        const width = (duration - 0.02) * this.DAY_WIDTH;

                        return {
                            ...detail,
                            left,
                            width,
                            startOffset,
                            duration,
                            stackLevel: 0,
                            _calcId: detail.id || detail.detail_id || `${detail.room_id}_${detail.start}_${detail.end}`
                        };
                    });

                    // Viewport-Filter
                    const visibleReservations = positionedReservations
                        .filter(item => item.left + item.width > this.sidebarWidth - 100 &&
                            item.left < this.canvas.width + 100)
                        .sort((a, b) => a.startOffset - b.startOffset);

                    // Stacking-Berechnung - IDENTISCH zur Cache-Version
                    const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;
                    let maxStackLevel = 0;

                    visibleReservations.forEach((reservation, index) => {
                        let stackLevel = 0;
                        let placed = false;

                        while (!placed) {
                            let canPlaceHere = true;

                            for (let i = 0; i < index; i++) {
                                const other = visibleReservations[i];
                                if (other.stackLevel === stackLevel) {
                                    const reservationEnd = reservation.left + reservation.width;
                                    const otherEnd = other.left + other.width;

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

                            if (stackLevel > 15) {
                                reservation.stackLevel = stackLevel;
                                maxStackLevel = Math.max(maxStackLevel, stackLevel);
                                placed = true;
                            }
                        }
                    });

                    const barHeight = this.themeConfig.room.barHeight || 16;
                    const roomHeight = Math.max(25, 4 + (maxStackLevel + 1) * (barHeight + 2));

                    stackingResult = {
                        reservations: visibleReservations,
                        maxStackLevel,
                        roomHeight
                    };
                } else {
                    stackingResult = { reservations: [], maxStackLevel: 0, roomHeight: 25 };
                }
            }

            const { reservations: sortedReservations, maxStackLevel, roomHeight } = stackingResult;

            // Update room height
            room._dynamicHeight = roomHeight;

            // Zimmer-Hintergrund mit alternierenden Streifen
            this.ctx.save();
            const roomIndex = rooms.indexOf(room);
            const isDropTarget = this.isDraggingReservation && this.dragMode === 'move' &&
                this.dragTargetRoom && this.dragTargetRoom.id === room.id &&
                this.dragTargetRoom.id !== this.dragOriginalData?.room_id;

            if (isDropTarget) {
                this.ctx.fillStyle = '#4CAF50';
                this.ctx.globalAlpha = 0.3;
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, roomHeight);
                this.ctx.globalAlpha = 1.0;
            } else {
                this.ctx.globalAlpha = 0.2;
                this.ctx.fillStyle = roomIndex % 2 === 0 ? '#000000' : '#ffffff';
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, roomHeight);
                this.ctx.globalAlpha = 1.0;
            }
            this.ctx.restore();

            // Render Reservierungen
            sortedReservations.forEach(reservation => {
                const barHeight = this.themeConfig.room.barHeight || 16;
                const stackY = baseRoomY + 1 + (reservation.stackLevel * (barHeight + 2));
                const isHovered = this.isReservationHovered(reservation.left, stackY, reservation.width, barHeight);

                this.renderRoomReservationBar(reservation.left, stackY, reservation.width, barHeight, reservation, isHovered);

                if (isHovered) {
                    this.hoveredReservation = reservation;
                }
            });

            // Zimmer-Trennlinie
            this.ctx.save();
            this.ctx.strokeStyle = '#444';
            this.ctx.lineWidth = 1;
            this.ctx.beginPath();
            this.ctx.moveTo(this.sidebarWidth, baseRoomY + roomHeight);
            this.ctx.lineTo(this.canvas.width, baseRoomY + roomHeight);
            this.ctx.stroke();
            this.ctx.restore();
        });

        this.ctx.restore();

        // Zimmer-Captions im Sidebar-Bereich rendern
        this.ctx.save();
        this.ctx.beginPath();
        this.ctx.rect(0, this.areas.rooms.y, this.sidebarWidth, this.areas.rooms.height);
        this.ctx.clip();

        visibleRooms.forEach(({ room, yOffset, height }) => {
            const baseRoomY = startY + yOffset;
            const roomDisplayY = baseRoomY + (height / 2) + (this.themeConfig.sidebar.fontSize / 3);

            if (roomDisplayY >= this.areas.rooms.y && roomDisplayY <= this.areas.rooms.y + this.areas.rooms.height) {
                this.ctx.fillStyle = this.themeConfig.sidebar.text;
                this.ctx.font = `${this.themeConfig.sidebar.fontSize}px Arial`;
                this.ctx.textAlign = 'center';

                const caption = room.caption || `R${room.id}`;
                this.ctx.fillText(caption, this.sidebarWidth / 2, roomDisplayY);
            }
        });

        this.ctx.restore();
    }

    renderHistogramArea(startDate, endDate) {
        const area = this.areas.histogram;
        const startX = this.sidebarWidth - this.scrollX;

        // Area-Hintergrund mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.histogram.bg;
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
        const availableHeight = area.height * 0.95; // 95% der verfügbaren Höhe nutzen
        const bottomMargin = 0; // Margin zum Scrollbar

        dailyCounts.forEach((count, dayIndex) => {
            const x = startX + (dayIndex * this.DAY_WIDTH) + 5;
            const barWidth = this.DAY_WIDTH - 10;
            const barHeight = (count / maxGuests) * (availableHeight - bottomMargin);
            const y = area.y + area.height - barHeight - bottomMargin;

            // Verwende Theme-Histogram-Farbe als Basis mit Intensitäts-Variationen
            const baseColor = this.themeConfig.histogram.bar;
            const color = count > 50 ? '#dc3545' :
                count > 30 ? '#ffc107' :
                    count > 10 ? '#28a745' : baseColor;

            this.ctx.fillStyle = color;
            this.ctx.globalAlpha = 0.7;
            this.ctx.fillRect(x, y, barWidth, barHeight);
            this.ctx.globalAlpha = 1.0;

            // Detaillierte Beschriftung mit Theme-Textfarbe
            const details = dailyDetails[dayIndex];
            if (details && barWidth > 30) {
                this.ctx.fillStyle = this.themeConfig.histogram.text;
                this.ctx.font = `${this.themeConfig.histogram.fontSize}px Arial`;
                this.ctx.textAlign = 'center';

                const centerX = x + barWidth / 2;
                let textY = area.y + area.height - 15; // Knapp über dem unteren Rand

                this.ctx.fillText(`${details.total}`, centerX, textY);

                // Zusätzliche Details nur wenn genug Platz vorhanden
                if (barHeight > 30 && barWidth > 50) {
                    textY -= 10; // Eine Zeile höher für Details

                    if (details.dz > 0) {
                        this.ctx.fillText(`DZ:${details.dz}`, centerX, textY);
                        textY -= 8;
                    }
                    if (details.betten > 0) {
                        this.ctx.fillText(`B:${details.betten}`, centerX, textY);
                        textY -= 8;
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
            const x = startX + (dayIndex * this.DAY_WIDTH);

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
        // Config-Button Hover-Check
        this.isConfigButtonHovered = false;
        if (this.configButtonBounds) {
            const bounds = this.configButtonBounds;
            if (this.mouseX >= bounds.x && this.mouseX <= bounds.x + bounds.width &&
                this.mouseY >= bounds.y && this.mouseY <= bounds.y + bounds.height) {
                this.isConfigButtonHovered = true;
            }
        }

        // Nur im Rooms-Bereich nach Reservierungen suchen
        if (this.mouseY >= this.areas.rooms.y && this.mouseY <= this.areas.rooms.y + this.areas.rooms.height) {
            const reservation = this.findReservationAt(this.mouseX, this.mouseY);
            this.hoveredReservation = reservation;
        } else {
            this.hoveredReservation = null;
        }
    }

    isReservationHovered(x, y, width, height) {
        return this.mouseX >= x && this.mouseX <= x + width &&
            this.mouseY >= y && this.mouseY <= y + height;
    }

    // ...existing code...

    renderReservationBar(x, y, width, height, reservation, isHovered = false) {
        const capacity = reservation.capacity || 1;

        // Verwende Theme-Standard-Farbe wenn keine spezifische Farbe gesetzt
        let color = reservation.color ||
            (capacity <= 2 ? this.themeConfig.master.bar :
                capacity <= 5 ? '#2ecc71' :
                    capacity <= 10 ? '#f39c12' :
                        capacity <= 20 ? '#e74c3c' : '#9b59b6');

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
            // Automatische Textfarbe basierend auf Balkenhelligkeit
            const textColor = this.getContrastColor(color);
            this.ctx.fillStyle = textColor;
            this.ctx.font = `${this.themeConfig.master.fontSize}px Arial`;
            this.ctx.textAlign = 'left';

            // Enhanced caption format: trim(nachname & " " & vorname) & (total_capacity arrangement_name) + dog_symbol
            let fullName = '';
            const nachname = reservation.nachname || '';
            const vorname = reservation.vorname || '';

            // Build full name
            if (nachname && vorname) {
                fullName = `${nachname} ${vorname}`;
            } else if (nachname) {
                fullName = nachname;
            } else if (vorname) {
                fullName = vorname;
            } else {
                fullName = reservation.name || '';
            }
            fullName = fullName.trim();

            // Build capacity and arrangement part - FIX: Check multiple sources for arrangement
            const totalCapacity = capacity || 1;
            const arrangementName = reservation.arrangement_name ||
                reservation.arr_kbez ||
                reservation.arrangement ||
                (reservation.data && reservation.data.arrangement) ||
                '';

            let capacityPart = `(${totalCapacity}`;
            if (arrangementName) {
                capacityPart += ` ${arrangementName}`;
            }
            capacityPart += ')';

            // Add dog symbol if present
            const dogSymbol = (reservation.has_dog || reservation.hund ||
                (reservation.data && reservation.data.has_dog)) ? ' 🐕' : '';

            // Calculate available width for text (minus padding)
            const availableWidth = width - 8; // 4px padding on each side

            // Measure full caption
            let caption = `${fullName} ${capacityPart}${dogSymbol}`;
            let textWidth = this.ctx.measureText(caption).width;

            // If too wide, progressively trim the name
            if (textWidth > availableWidth && fullName.length > 0) {
                const minNameLength = 3; // Minimum characters to keep
                let trimmedName = fullName;

                while (textWidth > availableWidth && trimmedName.length > minNameLength) {
                    // Remove last character and add ellipsis
                    trimmedName = fullName.substring(0, trimmedName.length - 1);
                    caption = `${trimmedName}… ${capacityPart}${dogSymbol}`;
                    textWidth = this.ctx.measureText(caption).width;
                }

                // If still too wide, try just initials
                if (textWidth > availableWidth && nachname && vorname) {
                    const initials = `${nachname.charAt(0)}.${vorname.charAt(0)}.`;
                    caption = `${initials} ${capacityPart}${dogSymbol}`;
                    textWidth = this.ctx.measureText(caption).width;
                }

                // Last resort: just capacity and arrangement
                if (textWidth > availableWidth) {
                    caption = `${capacityPart}${dogSymbol}`;
                }
            }

            // Vertikal zentrierter Text
            const textY = y + (height / 2) + (this.themeConfig.master.fontSize / 3);
            this.ctx.fillText(caption, x + 2, textY);
        }
    }

    renderRoomReservationBar(x, y, width, height, detail, isHovered = false) {
        // Verwende Theme-Standard-Farbe wenn keine spezifische Farbe gesetzt
        let color = detail.color || this.themeConfig.room.bar;

        // Drag & Drop visuelles Feedback
        const isDragged = this.isDraggingReservation && this.draggedReservation === detail;
        const isDropTarget = this.isDraggingReservation && this.dragMode === 'move' &&
            this.dragTargetRoom && this.dragTargetRoom.id !== this.dragOriginalData?.room_id;

        if (isDragged) {
            color = this.lightenColor(color, 30);
            this.ctx.globalAlpha = 0.8;
        } else if (isHovered) {
            color = this.lightenColor(color, 15);
        }

        if (isHovered || isDragged) {
            this.ctx.shadowColor = 'rgba(0,0,0,0.2)';
            this.ctx.shadowBlur = 3;
            this.ctx.shadowOffsetX = 1;
            this.ctx.shadowOffsetY = 1;
        }

        this.ctx.fillStyle = color;
        this.roundedRect(x, y, width, height, 3);
        this.ctx.fill();

        // Resize-Handles bei Hover oder Drag
        if ((isHovered || isDragged) && width > 20) {
            const handleWidth = 4;
            const handleColor = 'rgba(255,255,255,0.8)';

            // Start-Handle (links)
            this.ctx.fillStyle = handleColor;
            this.ctx.fillRect(x, y, handleWidth, height);

            // End-Handle (rechts)
            this.ctx.fillRect(x + width - handleWidth, y, handleWidth, height);
        }

        if (isHovered || isDragged) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        this.ctx.strokeStyle = isDragged ? 'rgba(0,0,0,0.5)' :
            isHovered ? 'rgba(0,0,0,0.3)' : 'rgba(0,0,0,0.1)';
        this.ctx.lineWidth = isDragged ? 2 : 1;
        this.ctx.stroke();

        if (isDragged) {
            this.ctx.globalAlpha = 1.0;
        }

        if (width > 30) {
            // Automatische Textfarbe basierend auf Balkenhelligkeit
            const textColor = this.getContrastColor(color);
            this.ctx.fillStyle = textColor;
            this.ctx.font = `${this.themeConfig.room.fontSize}px Arial`;
            this.ctx.textAlign = 'left';

            let text = detail.guest_name;
            if (detail.has_dog) text += ' 🐕';

            // Vertikal zentrierter Text
            const textY = y + (height / 2) + (this.themeConfig.room.fontSize / 3);
            this.ctx.fillText(text, x + 2, textY);
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

    getContrastColor(hexColor) {
        // Konvertiere Hex zu RGB
        const hex = hexColor.replace('#', '');
        const r = parseInt(hex.substr(0, 2), 16);
        const g = parseInt(hex.substr(2, 2), 16);
        const b = parseInt(hex.substr(4, 2), 16);

        // Berechne relative Luminanz (WCAG Standard)
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

        // Rückgabe basierend auf Helligkeit
        return luminance > 0.5 ? '#000000' : '#ffffff';
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
        reservations = newReservations || [];
        roomDetails = newRoomDetails || [];
        rooms = newRooms || [];

        // Verwende festen Datumsbereich: now - 2 weeks bis now + 2 years (auf 0 Uhr fixiert)
        const now = new Date();
        now.setHours(0, 0, 0, 0); // Auf Mitternacht (0 Uhr) fixieren
        this.startDate = new Date(now.getTime() - (14 * 24 * 60 * 60 * 1000));
        this.endDate = new Date(now.getTime() + (0.5 * 365 * 24 * 60 * 60 * 1000));

        this.render();
    }
}

// Export
window.TimelineUnifiedRenderer = TimelineUnifiedRenderer;
