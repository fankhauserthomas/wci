// TIMELINE UNIFIED RENDERER - Canvas-basierte Timeline
let reservations = [];
let roomDetails = [];
let rooms = [];
let arrangementsCatalog = typeof window !== 'undefined' && window.arrangementsCatalog ? window.arrangementsCatalog : [];
let histogramSourceData = typeof window !== 'undefined' && window.histogramSource ? window.histogramSource : [];
if (typeof window !== 'undefined') {
    window.arrangementsCatalog = arrangementsCatalog;
    window.histogramSource = histogramSourceData;
}
let DAY_WIDTH = 120;
const VERTICAL_GAP = 1;
const MS_IN_DAY = 24 * 60 * 60 * 1000;
const TIMELINE_RADIAL_MENU_SIZE = 220;

class TimelineRadialMenu {
    constructor(rootElement, callbacks = {}) {
        this.root = rootElement;
        this.callbacks = callbacks;
        this.size = callbacks.size || TIMELINE_RADIAL_MENU_SIZE;
        this.center = { x: this.size / 2, y: this.size / 2 };
        this.activeDetail = null;
        this.isOpen = false;
        this.segmentIdCounter = 0;
        this.maxRings = 4;

        const computedCenter = Math.max(16, this.size * 0.12);
        const availableRadius = Math.max(20, (this.size / 2) - computedCenter);
        const gapCount = Math.max(0, this.maxRings - 1);
        let ringGap = 1;
        let ringThickness = (availableRadius - gapCount * ringGap) / this.maxRings;
        if (ringThickness < 12) {
            ringGap = Math.max(0, ringGap * 0.5);
            ringThickness = (availableRadius - gapCount * ringGap) / this.maxRings;
        }

        this.centerRadius = computedCenter;
        this.ringGap = ringGap;
        this.ringThickness = Math.max(10, ringThickness);

        if (this.root) {
            this.root.style.pointerEvents = 'none';
            this.root.addEventListener('click', (event) => {
                event.stopPropagation();
            });
        }
    }

    polarToCartesian(radius, angleDegrees) {
        const angle = (angleDegrees - 90) * Math.PI / 180.0;
        return {
            x: this.center.x + (radius * Math.cos(angle)),
            y: this.center.y + (radius * Math.sin(angle))
        };
    }

    describeSegment(innerRadius, outerRadius, startAngle, endAngle) {
        const startOuter = this.polarToCartesian(outerRadius, startAngle);
        const endOuter = this.polarToCartesian(outerRadius, endAngle);
        const startInner = this.polarToCartesian(innerRadius, endAngle);
        const endInner = this.polarToCartesian(innerRadius, startAngle);
        const largeArc = (endAngle - startAngle) > 180 ? 1 : 0;

        return [
            'M', startOuter.x, startOuter.y,
            'A', outerRadius, outerRadius, 0, largeArc, 1, endOuter.x, endOuter.y,
            'L', startInner.x, startInner.y,
            'A', innerRadius, innerRadius, 0, largeArc, 0, endInner.x, endInner.y,
            'Z'
        ].join(' ');
    }

    clear() {
        if (!this.root) return;
        while (this.root.firstChild) {
            this.root.removeChild(this.root.firstChild);
        }
    }

    createCenterButton() {
        const svgNS = 'http://www.w3.org/2000/svg';
        const group = document.createElementNS(svgNS, 'g');

        const circle = document.createElementNS(svgNS, 'circle');
        circle.setAttribute('cx', this.center.x);
        circle.setAttribute('cy', this.center.y);
        circle.setAttribute('r', Math.max(16, this.centerRadius - 4));
        circle.setAttribute('fill', '#313131');
        circle.setAttribute('class', 'center-button');

        circle.addEventListener('click', (event) => {
            event.stopPropagation();
            this.hide();
            this.callbacks.onClose?.();
        });

        const line1 = document.createElementNS(svgNS, 'line');
        const crossRadius = Math.max(8, (this.centerRadius - 8));
        line1.setAttribute('x1', this.center.x - crossRadius);
        line1.setAttribute('y1', this.center.y - crossRadius);
        line1.setAttribute('x2', this.center.x + crossRadius);
        line1.setAttribute('y2', this.center.y + crossRadius);
        line1.setAttribute('stroke', '#fafafa');
        line1.setAttribute('stroke-width', 3);
        line1.setAttribute('stroke-linecap', 'round');

        const line2 = document.createElementNS(svgNS, 'line');
        line2.setAttribute('x1', this.center.x - crossRadius);
        line2.setAttribute('y1', this.center.y + crossRadius);
        line2.setAttribute('x2', this.center.x + crossRadius);
        line2.setAttribute('y2', this.center.y - crossRadius);
        line2.setAttribute('stroke', '#fafafa');
        line2.setAttribute('stroke-width', 3);
        line2.setAttribute('stroke-linecap', 'round');

        [line1, line2].forEach(line => {
            line.addEventListener('click', (event) => {
                event.stopPropagation();
                this.hide();
                this.callbacks.onClose?.();
            });
        });

        group.appendChild(circle);
        group.appendChild(line1);
        group.appendChild(line2);
        return group;
    }

    renderRing(svg, ringConfig) {
        const svgNS = 'http://www.w3.org/2000/svg';
        const { innerRadius, outerRadius, options, action } = ringConfig;
        if (!options || options.length === 0) return;

        const step = 360 / options.length;
        let startAngle = -90;

        const defs = this.ensureDefs(svg);

        options.forEach(option => {
            const endAngle = startAngle + step;
            const path = document.createElementNS(svgNS, 'path');
            path.setAttribute('d', this.describeSegment(innerRadius, outerRadius, startAngle, endAngle));
            path.setAttribute('fill', option.fill || '#555');
            path.setAttribute('class', 'segment');
            path.setAttribute('stroke', 'rgba(0, 0, 0, 0.25)');
            path.setAttribute('stroke-width', 0.6);
            path.setAttribute('vector-effect', 'non-scaling-stroke');

            path.addEventListener('click', (event) => {
                event.stopPropagation();
                if (action === 'color') {
                    this.callbacks.onColorSelect?.(option, this.activeDetail);
                } else if (action === 'arrangement') {
                    this.callbacks.onArrangementSelect?.(option, this.activeDetail);
                } else if (action === 'capacity') {
                    this.callbacks.onCapacitySelect?.(option, this.activeDetail);
                } else if (action === 'command') {
                    this.callbacks.onCommandSelect?.(option, this.activeDetail);
                }
            });

            svg.appendChild(path);

            if (option.label) {
                const textRadius = innerRadius + ((outerRadius - innerRadius) / 2);
                const pathId = `segment-label-${this.segmentIdCounter++}`;
                const textPath = document.createElementNS(svgNS, 'path');
                textPath.setAttribute('id', pathId);
                textPath.setAttribute('d', this.describeArcPath(textRadius, startAngle, endAngle));
                textPath.setAttribute('fill', 'none');
                defs.appendChild(textPath);

                const text = document.createElementNS(svgNS, 'text');
                if (option.textColor) {
                    text.setAttribute('fill', option.textColor);
                }
                text.setAttribute('font-size', '10');
                text.setAttribute('dominant-baseline', 'middle');
                const textPathElement = document.createElementNS(svgNS, 'textPath');
                textPathElement.setAttribute('href', `#${pathId}`);
                textPathElement.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', `#${pathId}`);
                textPathElement.setAttribute('startOffset', '50%');
                textPathElement.setAttribute('text-anchor', 'middle');
                textPathElement.textContent = option.label;
                text.appendChild(textPathElement);
                svg.appendChild(text);
            }

            startAngle = endAngle;
        });
    }

    ensureDefs(svg) {
        const svgNS = 'http://www.w3.org/2000/svg';
        let defs = svg.querySelector('defs');
        if (!defs) {
            defs = document.createElementNS(svgNS, 'defs');
            svg.appendChild(defs);
        }
        return defs;
    }

    describeArcPath(radius, startAngle, endAngle) {
        const start = this.polarToCartesian(radius, startAngle);
        const end = this.polarToCartesian(radius, endAngle);
        const largeArc = (endAngle - startAngle) > 180 ? 1 : 0;
        return `M ${start.x} ${start.y} A ${radius} ${radius} 0 ${largeArc} 1 ${end.x} ${end.y}`;
    }

    getRingBounds(level) {
        const safeLevel = Math.max(0, level);
        const innerRadius = this.centerRadius + safeLevel * (this.ringThickness + this.ringGap);
        return {
            innerRadius,
            outerRadius: innerRadius + this.ringThickness
        };
    }

    show(detail, clientX, clientY, ringConfigurations = []) {
        if (!this.root) return;

        this.activeDetail = detail;
        this.segmentIdCounter = 0;
        this.clear();

        const svgNS = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('viewBox', `0 0 ${this.size} ${this.size}`);
        svg.style.width = `${this.size}px`;
        svg.style.height = `${this.size}px`;

        ringConfigurations.forEach(ring => this.renderRing(svg, ring));
        svg.appendChild(this.createCenterButton());
        this.root.appendChild(svg);

        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const half = this.size / 2;
        let targetX = clientX;
        let targetY = clientY;

        if (targetX < half) targetX = half;
        if (targetY < half) targetY = half;
        if (targetX > viewportWidth - half) targetX = viewportWidth - half;
        if (targetY > viewportHeight - half) targetY = viewportHeight - half;

        this.root.style.left = `${targetX}px`;
        this.root.style.top = `${targetY}px`;
        this.root.classList.add('active');
        this.root.style.pointerEvents = 'auto';
        this.isOpen = true;
    }

    hide() {
        if (!this.root) return;
        this.root.classList.remove('active');
        this.root.style.pointerEvents = 'none';
        this.isOpen = false;
        this.activeDetail = null;
        this.clear();
    }

    isVisible() {
        return this.isOpen;
    }

    updateSize(newSize) {
        if (!newSize || newSize < 120 || newSize > 320) return;

        // Store current state if menu is open
        const wasOpen = this.isOpen;
        const currentDetail = this.activeDetail;
        const currentX = wasOpen ? parseInt(this.root.style.left) : 0;
        const currentY = wasOpen ? parseInt(this.root.style.top) : 0;
        let currentRings = [];

        // If menu is open, extract current ring configurations from DOM
        if (wasOpen && this.root) {
            const svg = this.root.querySelector('svg');
            if (svg) {
                // We'll store the ring data differently - let's just close and remember to reopen
                this.storedRingData = {
                    detail: currentDetail,
                    x: currentX,
                    y: currentY
                };
            }
        }

        // Update internal properties
        this.size = newSize;
        this.center = { x: this.size / 2, y: this.size / 2 };

        // Recalculate geometry
        const computedCenter = Math.max(16, this.size * 0.12);
        const availableRadius = Math.max(20, (this.size / 2) - computedCenter);
        const gapCount = Math.max(0, this.maxRings - 1);
        let ringGap = 1;
        let ringThickness = (availableRadius - gapCount * ringGap) / this.maxRings;
        if (ringThickness < 12) {
            ringGap = Math.max(0, ringGap * 0.5);
            ringThickness = (availableRadius - gapCount * ringGap) / this.maxRings;
        }

        this.centerRadius = computedCenter;
        this.ringGap = ringGap;
        this.ringThickness = Math.max(10, ringThickness);

        // If menu was open, hide it for now
        if (wasOpen) {
            this.hide();
        }
    }

    // Method to re-render with stored data if available
    restoreIfNeeded(ringConfigurations = []) {
        if (this.storedRingData) {
            const data = this.storedRingData;
            this.storedRingData = null;
            this.show(data.detail, data.x, data.y, ringConfigurations);
        }
    }
}


class TimelineUnifiedRenderer {
    static instance = null; // Statische Referenz zur aktuellen Instanz

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

        // Setze statische Referenz auf diese Instanz
        TimelineUnifiedRenderer.instance = this;

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
        this.pixelGhostFrame = null; // Pixelgenauer Rahmen der mit Maus mitfährt

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

        // Dynamische Zimmer-Balkenhöhe
        this.ROOM_BAR_HEIGHT = 16; // Standard Balkenhöhe (10-30px)

        // Performance tracking
        this.lastDragRender = 0;
        this.lastHoverRender = 0;
        this.lastScrollRender = 0;
        this.renderScheduled = false;

        // Phase 2: Stacking Cache System
        this.stackingCache = new Map(); // roomId -> stackingResult
        this.stackingDirty = new Set(); // Set of dirty roomIds
        this.dataIndex = null; // Will be initialized with reservation data

        // Phase 3: Advanced Performance Systems
        this.renderPipeline = {
            batchOperations: [],
            contextSwitches: 0,
            lastBatchTime: 0
        };

        this.viewportCache = {
            lastScrollX: -1,
            lastScrollY: -1,
            visibleItems: new Map(),
            cullingBounds: { left: 0, right: 0, top: 0, bottom: 0 }
        };

        this.objectPool = {
            rectangles: [],
            textMetrics: [],
            positions: []
        };

        this.arrangementsCatalog = Array.isArray(arrangementsCatalog) ? arrangementsCatalog : [];
        this.histogramSource = Array.isArray(histogramSourceData) ? histogramSourceData : [];

        this.predictiveCache = {
            scrollDirection: 0,
            scrollVelocity: 0,
            lastScrollTime: 0,
            preloadBuffer: 500 // pixels
        };

        // Phase 3: Performance monitoring
        this.performanceStats = {
            renderTime: 0,
            batchCount: 0,
            contextSwitches: 0,
            culledItems: 0,
            totalItems: 0
        };

        // Phase 3+: Real-time Drag Optimization
        this.dragOptimization = {
            enabled: true,
            isActive: false,
            previewStackingCache: new Map(), // roomId -> preview stacking result
            previewStacking: new Map(), // roomId -> preview stacking result
            draggedReservationBackup: null,
            affectedRooms: new Set(),
            lastDragPosition: { x: 0, y: 0, room: null }
        };

        // Touch-/Pointer-Unterstützung
        this.activePointerId = null;
        this.isTouchPanning = false;
        this.panContext = null;
        this.panStart = {
            clientX: 0,
            clientY: 0,
            scrollX: 0,
            masterScrollY: 0,
            roomsScrollY: 0
        };

        // Referenzen auf Scroll-Container für Synchronisation
        this.horizontalTrack = null;
        this.masterTrack = null;
        this.roomsTrack = null;

        // Datensynchronisation & Caches
        this.dataVersion = 0;
        this.histogramCache = null;
        this.roomsById = new Map();
        this.roomCategoryCache = new Map();

        // Theme-Konfiguration laden
        this.themeConfig = this.loadThemeConfiguration();
        this.DAY_WIDTH = this.themeConfig.dayWidth || 90; // Verwende Theme-DAY_WIDTH
        this.ROOM_BAR_HEIGHT = this.themeConfig.room?.barHeight || 16; // Verwende Theme-ROOM_BAR_HEIGHT

        const radialRoot = document.getElementById('timeline-radial-menu');

        // Load saved menu size from localStorage
        let menuSize = TIMELINE_RADIAL_MENU_SIZE; // Default fallback
        try {
            const savedMenuSize = localStorage.getItem('timeline_menu_size');
            if (savedMenuSize) {
                const parsedSize = parseInt(savedMenuSize, 10);
                if (parsedSize >= 120 && parsedSize <= 320) {
                    menuSize = parsedSize;
                }
            }
        } catch (error) {
            console.warn('Could not load saved menu size:', error);
        }

        if (radialRoot) {
            radialRoot.style.width = `${menuSize}px`;
            radialRoot.style.height = `${menuSize}px`;
            radialRoot.style.marginLeft = `-${menuSize / 2}px`;
            radialRoot.style.marginTop = `-${menuSize / 2}px`;
        }

        this.radialMenu = new TimelineRadialMenu(radialRoot, {
            size: menuSize,
            onClose: () => {
                this.radialMenu?.hide();
            },
            onColorSelect: (option, detail) => this.handleRadialColorSelection(option, detail),
            onArrangementSelect: (option, detail) => this.handleRadialArrangementSelection(option, detail),
            onCapacitySelect: (option, detail) => this.handleRadialCapacitySelection(option, detail),
            onCommandSelect: (option, detail) => this.handleRadialCommandSelection(option, detail)
        });

        // DAY_WIDTH aus localStorage laden (überschreibt Theme-Wert)
        try {
            const savedDayWidth = localStorage.getItem('timeline_day_width');
            if (savedDayWidth) {
                const dayWidth = parseInt(savedDayWidth, 10);
                if (dayWidth >= 60 && dayWidth <= 150) {
                    this.DAY_WIDTH = dayWidth;
                    console.log('DAY_WIDTH aus localStorage geladen:', this.DAY_WIDTH);
                }
            }
        } catch (e) {
            console.warn('DAY_WIDTH konnte nicht aus localStorage geladen werden:', e);
        }

        // ROOM_BAR_HEIGHT aus localStorage laden (überschreibt Theme-Wert) 
        try {
            const savedBarHeight = localStorage.getItem('timeline_room_bar_height');
            if (savedBarHeight) {
                const barHeight = parseInt(savedBarHeight, 10);
                if (barHeight >= 10 && barHeight <= 30) {
                    this.ROOM_BAR_HEIGHT = barHeight;
                    console.log('ROOM_BAR_HEIGHT aus localStorage geladen:', this.ROOM_BAR_HEIGHT);
                }
            }
        } catch (e) {
            console.warn('ROOM_BAR_HEIGHT konnte nicht aus localStorage geladen werden:', e);
        }

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

    // ===== PHASE 3: ADVANCED PERFORMANCE SYSTEMS =====

    // Advanced Viewport Culling with Predictive Loading
    updateViewportCache(scrollX, scrollY) {
        const now = Date.now();

        // Calculate scroll velocity and direction for predictive loading
        if (this.viewportCache.lastScrollX !== -1) {
            const deltaX = scrollX - this.viewportCache.lastScrollX;
            const deltaTime = now - this.predictiveCache.lastScrollTime;

            if (deltaTime > 0) {
                this.predictiveCache.scrollVelocity = Math.abs(deltaX) / deltaTime;
                this.predictiveCache.scrollDirection = deltaX > 0 ? 1 : (deltaX < 0 ? -1 : 0);
            }
        }

        this.viewportCache.lastScrollX = scrollX;
        this.viewportCache.lastScrollY = scrollY;
        this.predictiveCache.lastScrollTime = now;

        // Calculate predictive viewport bounds
        const predictiveBuffer = this.predictiveCache.preloadBuffer *
            (1 + this.predictiveCache.scrollVelocity * 0.5);

        this.viewportCache.cullingBounds = {
            left: scrollX - predictiveBuffer,
            right: scrollX + this.canvas.width + predictiveBuffer,
            top: scrollY - predictiveBuffer,
            bottom: scrollY + this.canvas.height + predictiveBuffer
        };
    }

    // Object Pool Management for Memory Optimization
    borrowFromPool(type) {
        const pool = this.objectPool[type];
        return pool.length > 0 ? pool.pop() : this.createPoolObject(type);
    }

    returnToPool(type, obj) {
        const pool = this.objectPool[type];
        if (pool.length < 100) { // Limit pool size
            pool.push(obj);
        }
    }

    createPoolObject(type) {
        switch (type) {
            case 'rectangles':
                return { x: 0, y: 0, width: 0, height: 0, color: '', visible: false };
            case 'textMetrics':
                return { width: 0, height: 0, text: '', font: '' };
            case 'positions':
                return { left: 0, top: 0, right: 0, bottom: 0 };
            default:
                return {};
        }
    }

    // Advanced Batch Rendering System
    startBatch() {
        this.renderPipeline.batchOperations = [];
        this.renderPipeline.contextSwitches = 0;
    }

    addToBatch(operation) {
        this.renderPipeline.batchOperations.push(operation);
    }

    executeBatch() {
        if (this.renderPipeline.batchOperations.length === 0) return;

        // Group operations by type to minimize context switches
        const groupedOps = {
            fills: [],
            strokes: [],
            texts: [],
            images: []
        };

        this.renderPipeline.batchOperations.forEach(op => {
            if (!groupedOps[op.type]) groupedOps[op.type] = [];
            groupedOps[op.type].push(op);
        });

        // Execute grouped operations
        Object.entries(groupedOps).forEach(([type, operations]) => {
            if (operations.length === 0) return;

            this.ctx.save();
            this.executeBatchType(type, operations);
            this.ctx.restore();
            this.renderPipeline.contextSwitches++;
        });

        this.renderPipeline.batchOperations = [];
    }

    executeBatchType(type, operations) {
        switch (type) {
            case 'fills':
                operations.forEach(op => {
                    if (this.ctx.fillStyle !== op.color) {
                        this.ctx.fillStyle = op.color;
                    }
                    this.ctx.fillRect(op.x, op.y, op.width, op.height);
                });
                break;

            case 'strokes':
                operations.forEach(op => {
                    if (this.ctx.strokeStyle !== op.color) {
                        this.ctx.strokeStyle = op.color;
                    }
                    if (this.ctx.lineWidth !== op.lineWidth) {
                        this.ctx.lineWidth = op.lineWidth;
                    }
                    this.ctx.strokeRect(op.x, op.y, op.width, op.height);
                });
                break;

            case 'texts':
                operations.forEach(op => {
                    if (this.ctx.fillStyle !== op.color) {
                        this.ctx.fillStyle = op.color;
                    }
                    if (this.ctx.font !== op.font) {
                        this.ctx.font = op.font;
                    }
                    if (this.ctx.textAlign !== op.align) {
                        this.ctx.textAlign = op.align;
                    }
                    this.ctx.fillText(op.text, op.x, op.y);
                });
                break;
        }
    }

    // Intelligent Viewport Culling
    isItemInViewport(x, y, width, height) {
        const bounds = this.viewportCache.cullingBounds;
        return !(x + width < bounds.left ||
            x > bounds.right ||
            y + height < bounds.top ||
            y > bounds.bottom);
    }

    // Memory-Optimized Rectangle Drawing
    drawOptimizedRect(x, y, width, height, fillColor, strokeColor = null, lineWidth = 1) {
        if (!this.isItemInViewport(x, y, width, height)) return;

        if (fillColor) {
            this.addToBatch({
                type: 'fills',
                x, y, width, height,
                color: fillColor
            });
        }

        if (strokeColor) {
            this.addToBatch({
                type: 'strokes',
                x, y, width, height,
                color: strokeColor,
                lineWidth
            });
        }
    }

    // Optimized Text Rendering with Caching
    drawOptimizedText(text, x, y, font, color, align = 'left') {
        if (!this.isItemInViewport(x - 50, y - 10, 100, 20)) return;

        this.addToBatch({
            type: 'texts',
            text, x, y, font, color, align
        });
    }

    // PHASE 2: DATA INDEXING SYSTEM =====

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
            const possibleKeys = [
                detail.id,
                detail.detail_id,
                detail.detailId,
                detail.data?.detail_id,
                detail.data?.detailId
            ];
            possibleKeys
                .filter(key => key !== undefined && key !== null)
                .forEach(key => this.dataIndex.roomDetailsById.set(String(key), detail));
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

        // Filter out dragged reservation during drag operations to make space
        const filteredReservations = roomReservations.filter(detail => {
            if (this.isDraggingReservation && this.draggedReservation) {
                const isDraggedReservation = detail === this.draggedReservation ||
                    (detail.id && detail.id === this.draggedReservation.id) ||
                    (detail.detail_id && detail.detail_id === this.draggedReservation.detail_id);

                // Only exclude dragged reservation from its original room
                // This allows space to be made in the original room
                if (isDraggedReservation && roomId === this.dragOriginalData?.room_id) {
                    return false;
                }
            }
            return true;
        });

        // Add ghost reservation to target room for accurate stacking - nur wenn wirklich aktiv
        let ghostReservation = null;
        if (this.isDraggingReservation && this.ghostBar && this.ghostBar.targetRoom &&
            this.ghostBar.targetRoom.id === roomId && this.draggedReservation && this.ghostBar.visible !== false) {

            // WICHTIG: Cache für dieses Zimmer löschen um Ghost-Reste zu entfernen
            if (this.stackingCache) {
                const cacheKey = `${roomId}_stacking`;
                this.stackingCache.delete(cacheKey);
            }

            // Create a proper ghost reservation with all necessary properties
            const originalStart = new Date(this.dragOriginalData.start);
            const originalEnd = new Date(this.dragOriginalData.end);
            const duration = originalEnd.getTime() - originalStart.getTime();

            // Calculate ghost position from ghost bar
            const ghostStartOffset = (this.ghostBar.x - startX) / this.DAY_WIDTH;

            ghostReservation = {
                id: 'ghost-current-drag', // Stabile ID - immer dieselbe
                guest_name: '[GHOST]',
                start: new Date(startDate.getTime() + (ghostStartOffset * 24 * 60 * 60 * 1000)),
                end: new Date(startDate.getTime() + ((ghostStartOffset + (duration / (24 * 60 * 60 * 1000))) * 24 * 60 * 60 * 1000)),
                room_id: roomId,
                left: this.ghostBar.x,
                width: this.ghostBar.width,
                startOffset: ghostStartOffset,
                duration: this.ghostBar.width / this.DAY_WIDTH,
                stackLevel: 0,
                _isGhost: true,
                _isTemporary: true,
                _calcId: 'ghost-current-drag' // Stabile Calc-ID
            };
        }

        // Map und position alle Reservierungen - mit eindeutigen Kopien
        const positionedReservations = filteredReservations
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

        // Add ghost reservation to the array for stacking calculation - aber NUR temporär
        if (ghostReservation) {
            // WICHTIG: Entferne alle vorherigen Ghost-Reservierungen aus positionedReservations
            const cleanedReservations = positionedReservations.filter(res => !res._isGhost);

            // Mark als temporär für Stacking aber nicht für persistente Speicherung
            ghostReservation._isTemporary = true;
            cleanedReservations.push(ghostReservation);

            // Update das Array mit den bereinigten Reservierungen
            positionedReservations.length = 0; // Array leeren
            positionedReservations.push(...cleanedReservations.sort((a, b) => a.startOffset - b.startOffset));
        }

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

        const barHeight = this.ROOM_BAR_HEIGHT; // Verwende dynamische Balkenhöhe
        const roomHeight = maxStackLevel === 0
            ? Math.max(this.ROOM_BAR_HEIGHT + 2, 12) // Keine Stacks: Balkenhöhe + 2px
            : Math.max(25, 4 + (maxStackLevel + 1) * (this.ROOM_BAR_HEIGHT + 2)); // Mit Stacks: x*barHeight+(x+1)px

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

    // Zimmer-Höhen neu berechnen basierend auf aktueller ROOM_BAR_HEIGHT
    recalculateRoomHeights() {
        if (!window.rooms) return;

        const now = new Date();
        now.setHours(0, 0, 0, 0);
        const startDate = new Date(now.getTime() - (14 * 24 * 60 * 60 * 1000));
        const endDate = new Date(now.getTime() + (60 * 24 * 60 * 60 * 1000));

        // Aktualisiere alle Zimmer-Höhen
        for (const room of rooms) {
            const stackingResult = this.getStackingForRoom(room.id, startDate, endDate);
            room._dynamicHeight = stackingResult.roomHeight;
        }

        console.log('Zimmer-Höhen neu berechnet für ROOM_BAR_HEIGHT:', this.ROOM_BAR_HEIGHT);
    }

    updateRoomLookups() {
        this.roomsById = new Map();
        this.roomCategoryCache = new Map();

        (rooms || []).forEach(room => {
            if (!room || room.id === undefined || room.id === null) return;
            const key = String(room.id);
            this.roomsById.set(key, room);
        });
    }

    normalizeRoomDetails() {
        if (!roomDetails) return;
        roomDetails.forEach(detail => this.normalizeRoomDetail(detail));
    }

    normalizeRoomDetail(detail) {
        if (!detail) return;

        detail._normalizedStart = this.normalizeDateToNoon(detail.start);
        detail._normalizedEnd = this.normalizeDateToNoon(detail.end);
        const baseCapacity = detail.capacity ?? (detail.data && detail.data.capacity) ?? 1;
        detail._capacity = Number.isFinite(baseCapacity) ? baseCapacity : Number.parseInt(baseCapacity, 10) || 1;
        detail._occupancyCategory = this.getRoomCategoryByRoomId(detail.room_id);
    }

    normalizeDateToNoon(value) {
        if (!value) return 0;
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return 0;
        date.setHours(12, 0, 0, 0);
        return date.getTime();
    }

    getRoomCategoryByRoomId(roomId) {
        const key = String(roomId ?? '');
        if (!this.roomCategoryCache.has(key)) {
            const room = this.roomsById.get(key);
            this.roomCategoryCache.set(key, this.determineRoomCategory(room));
        }
        return this.roomCategoryCache.get(key) || 'betten';
    }

    determineRoomCategory(room) {
        if (!room) return 'betten';
        const caption = (room.display_name || room.caption || '').toLowerCase();
        const capacity = room.capacity || 0;

        if (caption.includes('dz') || caption.includes('doppel')) {
            return 'dz';
        }

        if (caption.includes('lager') || caption.includes('matratzen') || capacity >= 6) {
            return 'lager';
        }

        if (caption.includes('sonder') || caption.includes('suite') || caption.includes('fam')) {
            return 'sonder';
        }

        return 'betten';
    }

    getHistogramBarColor(count) {
        if (count > 50) return '#dc3545';
        if (count > 30) return '#ffc107';
        if (count > 10) return '#28a745';
        const histogramTheme = this.themeConfig && this.themeConfig.histogram ? this.themeConfig.histogram : null;
        return histogramTheme && histogramTheme.bar ? histogramTheme.bar : '#e74c3c';
    }

    invalidateHistogramCache() {
        this.histogramCache = null;
    }

    markDataDirty() {
        this.dataVersion += 1;
        this.invalidateHistogramCache();
        this.dataIndex = null;
    }

    formatDateForDb(value) {
        if (!value) return null;
        const date = value instanceof Date ? value : new Date(value);
        if (Number.isNaN(date.getTime())) {
            return null;
        }
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    extractDetailIdentifiers(detail) {
        const identifiers = {
            detailId: null,
            resId: null,
            uniqueId: null
        };

        if (!detail) {
            return identifiers;
        }

        let detailId = detail.detail_id ?? detail.detailId ?? null;
        if (!detailId && detail.data && detail.data.detail_id) {
            detailId = detail.data.detail_id;
        }
        if (!detailId && typeof detail.id === 'string' && detail.id.startsWith('room_detail_')) {
            const parsed = parseInt(detail.id.replace('room_detail_', ''), 10);
            if (!Number.isNaN(parsed)) {
                detailId = parsed;
            }
        }

        let resId = detail.res_id ?? detail.reservation_id ?? null;
        if (!resId && detail.data && detail.data.res_id) {
            resId = detail.data.res_id;
        }

        identifiers.detailId = detailId || null;
        identifiers.resId = resId || null;
        identifiers.uniqueId = detail.id || (detailId ? `room_detail_${detailId}` : null);

        return identifiers;
    }

    getDetailCaption(detail) {
        if (!detail) {
            return 'Reservierung';
        }

        const captionSources = [
            detail.caption,
            detail.data && detail.data.caption,
            detail.guest_name,
            detail.name,
            detail.data && detail.data.guest_name
        ];

        const base = captionSources.find(value => typeof value === 'string' && value.trim().length > 0);
        let label = base ? base.trim() : 'Reservierung';

        // Verhindere doppeltes Anhängen der Anzahl
        // Prüfe ob bereits eine Anzahl am Anfang steht (z.B. "4 Mueller Hans")
        const hasNumberPrefix = /^\d+\s/.test(label);

        const rawCapacity = detail.capacity ?? detail.data?.capacity ?? detail.data?.anz;
        const capacity = rawCapacity !== undefined && rawCapacity !== null ? Number(rawCapacity) : null;

        if (Number.isFinite(capacity) && !hasNumberPrefix) {
            return `${capacity} ${label}`;
        }

        return label;
    }

    truncateTextToWidth(text, maxWidth) {
        if (!text) return '';
        if (maxWidth <= 0) return '';

        if (this.ctx.measureText(text).width <= maxWidth) {
            return text;
        }

        const ellipsis = '…';
        let left = 0;
        let right = text.length;
        let bestFit = '';

        while (left <= right) {
            const mid = Math.floor((left + right) / 2);
            const candidate = text.slice(0, mid).trimEnd() + ellipsis;
            const width = this.ctx.measureText(candidate).width;

            if (width <= maxWidth) {
                bestFit = candidate;
                left = mid + 1;
            } else {
                right = mid - 1;
            }
        }

        return bestFit || ellipsis;
    }

    buildRoomDetailUpdatePayload(detail, originalRoomId, originalStart, originalEnd) {
        if (!detail) {
            return null;
        }

        const { detailId, resId } = this.extractDetailIdentifiers(detail);
        if (!detailId || !resId) {
            console.warn('Konnte detail_id oder res_id nicht ermitteln, speichere nicht.', detail);
            return null;
        }

        const startDate = this.formatDateForDb(detail.start);
        const endDate = this.formatDateForDb(detail.end);
        const originalStartStr = this.formatDateForDb(originalStart);
        const originalEndStr = this.formatDateForDb(originalEnd);

        if (!startDate || !endDate) {
            console.warn('Ungültige Start-/Enddaten für Detail, speichere nicht.', detail);
            return null;
        }

        const hasChange =
            startDate !== originalStartStr ||
            endDate !== originalEndStr ||
            String(detail.room_id) !== String(originalRoomId);

        if (!hasChange) {
            return null;
        }

        return {
            detail_id: Number(detailId),
            res_id: Number(resId),
            room_id: Number(detail.room_id),
            start_date: startDate,
            end_date: endDate,
            original_room_id: originalRoomId !== undefined && originalRoomId !== null ? Number(originalRoomId) : null,
            original_start_date: originalStartStr,
            original_end_date: originalEndStr
        };
    }

    persistRoomDetailChange(payload, roomIndex, originalSnapshot) {
        if (!payload) {
            return;
        }

        fetch('updateRoomDetail.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json().catch(() => {
                    throw new Error('Ungültige Serverantwort.');
                });
            })
            .then(result => {
                if (!result || !result.success) {
                    const message = result && result.error ? result.error : 'Unbekannter Fehler beim Speichern.';
                    throw new Error(message);
                }
                console.debug('Zimmerdetail erfolgreich gespeichert.', payload);
            })
            .catch(error => {
                console.error('Fehler beim Speichern der Zimmerdetail-Änderung:', error);
                this.restoreRoomDetailSnapshot(roomIndex, originalSnapshot, payload);

                if (typeof window !== 'undefined' && window.alert) {
                    window.alert('Änderung konnte nicht gespeichert werden. Die Reservierung wurde zurückgesetzt.');
                }
            });
    }

    restoreRoomDetailSnapshot(roomIndex, snapshot, payload) {
        if (roomIndex === null || roomIndex === undefined || roomIndex < 0 || !snapshot) {
            return;
        }

        const clonedDetail = {
            ...snapshot,
            start: new Date(snapshot.start),
            end: new Date(snapshot.end),
            data: snapshot.data ? { ...snapshot.data } : undefined
        };

        roomDetails[roomIndex] = clonedDetail;
        this.normalizeRoomDetail(roomDetails[roomIndex]);

        const affectedRoomIds = new Set();
        if (payload && payload.room_id) {
            affectedRoomIds.add(payload.room_id);
        }
        if (payload && payload.original_room_id) {
            affectedRoomIds.add(payload.original_room_id);
        }
        if (snapshot.room_id) {
            affectedRoomIds.add(snapshot.room_id);
        }

        affectedRoomIds.forEach(roomId => {
            if (roomId === null || roomId === undefined) return;
            this.invalidateStackingCache(roomId);
            const room = rooms.find(r =>
                String(r.id) === String(roomId) ||
                Number(r.id) === Number(roomId)
            );
            if (room) {
                delete room._dynamicHeight;
            }
        });

        this.markDataDirty();
        this.scheduleRender('rollback_after_failed_save');
    }

    persistRoomDetailAttributes(payload, onError) {
        if (!payload || !payload.updates) {
            return;
        }

        fetch('updateRoomDetailAttributes.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json().catch(() => {
                    throw new Error('Ungültige Serverantwort.');
                });
            })
            .then(result => {
                if (!result || !result.success) {
                    const message = result && result.error ? result.error : 'Unbekannter Fehler beim Speichern.';
                    throw new Error(message);
                }
            })
            .catch(error => {
                console.error('Fehler beim Speichern der Detail-Attribute:', error);
                if (typeof onError === 'function') {
                    onError();
                }
                if (typeof window !== 'undefined' && window.alert) {
                    window.alert('Änderung konnte nicht gespeichert werden. Die lokale Anpassung wurde zurückgesetzt.');
                }
            });
    }

    setArrangementsCatalog(catalog) {
        if (Array.isArray(catalog)) {
            this.arrangementsCatalog = catalog
                .map(item => ({
                    id: item.id,
                    label: item.label,
                    shortLabel: item.shortLabel || item.label,
                    rawId: item.rawId,
                    fill: item.fill || null
                }));
        } else {
            this.arrangementsCatalog = [];
        }

        arrangementsCatalog = this.arrangementsCatalog;
        if (typeof window !== 'undefined') {
            window.arrangementsCatalog = this.arrangementsCatalog;
        }
    }

    setHistogramSource(source) {
        if (Array.isArray(source)) {
            this.histogramSource = source.map(entry => ({
                id: entry.id,
                start: entry.start,
                end: entry.end,
                capacity_details: entry.capacity_details || {}
            }));
        } else {
            this.histogramSource = [];
        }

        histogramSourceData = this.histogramSource;
        if (typeof window !== 'undefined') {
            window.histogramSource = this.histogramSource;
        }
    }

    shadeWeekendColumns(area, startDate, endDate, options = {}) {
        const weekendConfig = this.themeConfig.weekend || {};
        const fill = options.fill || weekendConfig.fill || 'rgba(255, 99, 132, 0.08)';
        if (!fill) return;

        const barWidth = options.barWidth !== undefined ? options.barWidth : this.DAY_WIDTH;
        const xOffset = options.xOffset || 0;
        const offsetY = options.offsetY !== undefined ? options.offsetY : area.y;
        const height = options.height !== undefined ? options.height : area.height;
        const startX = this.sidebarWidth - this.scrollX;
        const startTime = startDate.getTime();
        const endTime = endDate.getTime();
        if (!(endTime >= startTime)) return;

        const dayCount = Math.max(1, Math.floor((endTime - startTime) / MS_IN_DAY) + 1);
        for (let dayIndex = 0; dayIndex < dayCount; dayIndex++) {
            const x = startX + (dayIndex * this.DAY_WIDTH) + xOffset;
            if (x + barWidth <= this.sidebarWidth || x >= this.canvas.width) {
                continue;
            }
            const dayDate = new Date(startTime + dayIndex * MS_IN_DAY);
            const day = dayDate.getDay();
            if (day === 0 || day === 6) {
                this.ctx.fillStyle = fill;
                this.ctx.fillRect(x, offsetY, barWidth, height);
            }
        }
    }

    renderRoomDayGridLines(startDate, endDate, area) {
        const startX = this.sidebarWidth - this.scrollX;
        const lineTop = area.y;
        const lineBottom = area.y + area.height;

        this.ctx.save();
        this.ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        this.ctx.lineWidth = 1;

        const currentDate = new Date(startDate);
        let dayIndex = 0;

        while (currentDate <= endDate) {
            const x = startX + (dayIndex * this.DAY_WIDTH);

            if (x >= this.sidebarWidth - 1 && x <= this.canvas.width + 1) {
                this.ctx.beginPath();
                this.ctx.moveTo(x, lineTop);
                this.ctx.lineTo(x, lineBottom);
                this.ctx.stroke();
            }

            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        this.ctx.restore();
    }

    getHistogramData(startDate, endDate) {
        if (!startDate || !endDate) {
            return { dailyCounts: [], dailyDetails: [], maxGuests: 0 };
        }

        const startTs = startDate.getTime();
        const endTs = endDate.getTime();

        if (this.histogramCache &&
            this.histogramCache.version === this.dataVersion &&
            this.histogramCache.startTs === startTs &&
            this.histogramCache.endTs === endTs) {
            return this.histogramCache;
        }

        const totalDays = Math.max(0, Math.ceil((endTs - startTs) / MS_IN_DAY));

        if (totalDays === 0) {
            this.histogramCache = {
                version: this.dataVersion,
                startTs,
                endTs,
                dailyCounts: [],
                dailyDetails: [],
                maxGuests: 0
            };
            return this.histogramCache;
        }

        const dailyDetails = new Array(totalDays).fill(null).map(() => ({
            dz: 0,
            betten: 0,
            lager: 0,
            sonder: 0,
            total: 0
        }));

        const addReservationToHistogram = (reservation) => {
            if (!reservation) return;

            const startValue = reservation.start ?? reservation.start_date ?? reservation.startDate;
            const endValue = reservation.end ?? reservation.end_date ?? reservation.endDate;

            const start = this.normalizeDateToNoon(startValue);
            const end = this.normalizeDateToNoon(endValue);

            let startIndex = Math.floor((start - startTs) / MS_IN_DAY);
            let endIndex = Math.floor((end - startTs) / MS_IN_DAY);

            if (Number.isNaN(startIndex) || Number.isNaN(endIndex)) return;
            if (startIndex >= totalDays || endIndex <= 0) return;

            startIndex = Math.max(0, startIndex);
            endIndex = Math.min(totalDays, endIndex);
            if (startIndex >= endIndex) return;

            const data = reservation.capacity_details ? { capacity_details: reservation.capacity_details } : (reservation.fullData || reservation.data || {});
            const details = data.capacity_details || {};
            const dz = Number(details.dz || 0);
            const betten = Number(details.betten || 0);
            const lager = Number(details.lager || 0);
            const sonder = Number(details.sonder || 0);

            for (let day = startIndex; day < endIndex; day++) {
                dailyDetails[day].dz += dz;
                dailyDetails[day].betten += betten;
                dailyDetails[day].lager += lager;
                dailyDetails[day].sonder += sonder;
                dailyDetails[day].total += dz + betten + lager + sonder;
            }
        };

        const sourceReservations = Array.isArray(this.histogramSource) && this.histogramSource.length > 0
            ? this.histogramSource
            : [];

        if (sourceReservations.length === 0) {
            console.warn('Keine Histogramm-Daten verfügbar. Histogramm wird leer angezeigt.');
        }

        sourceReservations.forEach(addReservationToHistogram);

        const dailyCounts = dailyDetails.map(detail => detail.total);
        const maxGuests = dailyCounts.reduce((max, value) => Math.max(max, value), 0);

        this.histogramCache = {
            version: this.dataVersion,
            startTs,
            endTs,
            dailyCounts,
            dailyDetails,
            maxGuests
        };

        return this.histogramCache;
    }

    niceNumber(range, round) {
        if (range === 0) return 0;
        const exponent = Math.floor(Math.log10(range));
        const fraction = range / Math.pow(10, exponent);
        let niceFraction;
        if (round) {
            if (fraction < 1.5) niceFraction = 1;
            else if (fraction < 3) niceFraction = 2;
            else if (fraction < 7) niceFraction = 5;
            else niceFraction = 10;
        } else {
            if (fraction <= 1) niceFraction = 1;
            else if (fraction <= 2) niceFraction = 2;
            else if (fraction <= 5) niceFraction = 5;
            else niceFraction = 10;
        }
        return niceFraction * Math.pow(10, exponent);
    }

    getHistogramTicks(maxValue, desiredTickCount = 5) {
        if (!maxValue || maxValue <= 0) {
            return { ticks: [0], niceMax: 0 };
        }
        const niceRange = this.niceNumber(maxValue, false);
        const tickSpacing = this.niceNumber(niceRange / Math.max(desiredTickCount - 1, 1), true);
        const niceMax = Math.ceil(maxValue / tickSpacing) * tickSpacing;
        const ticks = [];
        for (let tick = 0; tick <= niceMax + tickSpacing * 0.5; tick += tickSpacing) {
            ticks.push(Math.round(tick * 100) / 100);
        }
        return { ticks, niceMax, spacing: tickSpacing };
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
        this.canvas.style.touchAction = 'none';
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

        this.horizontalTrack = horizontalTrack;
        this.masterTrack = masterTrack;
        this.roomsTrack = roomsTrack;

        // Horizontaler Scroll
        horizontalTrack.addEventListener('scroll', (e) => {
            this.scrollX = e.target.scrollLeft;

            // Phase 3: Update viewport cache with predictive loading
            this.updateViewportCache(this.scrollX, this.roomsScrollY);

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

        // Mausrad-Events für bereichsspezifisches Scrollen und DAY_WIDTH-Änderung
        this.canvas.addEventListener('wheel', (e) => {
            e.preventDefault();
            const mouseX = e.offsetX; // Aktuelle Mausposition X
            const mouseY = e.offsetY; // Aktuelle Mausposition Y

            // Throttle wheel events
            const now = Date.now();
            if (now - this.lastScrollRender < 16) return; // 60 FPS für Scrolling
            this.lastScrollRender = now;

            // DAY_WIDTH ändern wenn Maus über Header/Datum-Bereich
            if (mouseY >= this.areas.header.y && mouseY < this.areas.header.y + this.areas.header.height && e.ctrlKey) {
                // Ctrl + Mausrad über Datum = DAY_WIDTH ändern (60-150px)
                const delta = e.deltaY > 0 ? 5 : -5; // Hoch = vergrößern, runter = verkleinern
                const newDayWidth = Math.max(60, Math.min(150, this.DAY_WIDTH + delta));

                if (newDayWidth !== this.DAY_WIDTH) {
                    this.DAY_WIDTH = newDayWidth;

                    // In localStorage speichern
                    try {
                        localStorage.setItem('timeline_day_width', this.DAY_WIDTH.toString());
                        console.log('DAY_WIDTH gespeichert:', this.DAY_WIDTH);
                    } catch (e) {
                        console.warn('DAY_WIDTH konnte nicht gespeichert werden:', e);
                    }

                    // Zimmerbereich Cache invalidieren für korrekte Neuberechnung
                    this.invalidateStackingCache();

                    // Neu rendern
                    this.scheduleRender('day_width_change');
                }
                return; // Kein weiteres Scrolling
            }

            // ROOM_BAR_HEIGHT ändern wenn Maus über Sidebar/Zimmer-Bereich
            if (mouseX <= this.sidebarWidth && mouseY >= this.areas.rooms.y && mouseY < this.areas.rooms.y + this.areas.rooms.height && e.ctrlKey) {
                // Ctrl + Mausrad über Sidebar = ROOM_BAR_HEIGHT ändern (10-30px)
                const delta = e.deltaY > 0 ? 1 : -1; // Hoch = vergrößern, runter = verkleinern
                const newBarHeight = Math.max(10, Math.min(30, this.ROOM_BAR_HEIGHT + delta));

                if (newBarHeight !== this.ROOM_BAR_HEIGHT) {
                    this.ROOM_BAR_HEIGHT = newBarHeight;

                    // In localStorage speichern
                    try {
                        localStorage.setItem('timeline_room_bar_height', this.ROOM_BAR_HEIGHT.toString());
                        console.log('ROOM_BAR_HEIGHT gespeichert:', this.ROOM_BAR_HEIGHT);
                    } catch (e) {
                        console.warn('ROOM_BAR_HEIGHT konnte nicht gespeichert werden:', e);
                    }

                    // Zimmerbereich Cache invalidieren für korrekte Neuberechnung
                    this.invalidateStackingCache();

                    // Zimmer-Höhen neu berechnen
                    this.recalculateRoomHeights();

                    // Neu rendern
                    this.scheduleRender('bar_height_change');
                }
                return; // Kein weiteres Scrolling
            }

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

        // Pointer-Events für Touch-Unterstützung
        this.canvas.addEventListener('pointerdown', (e) => this.handlePointerDown(e), { passive: false });
        this.canvas.addEventListener('pointermove', (e) => this.handlePointerMove(e), { passive: false });
        this.canvas.addEventListener('pointerup', (e) => this.handlePointerUp(e));
        this.canvas.addEventListener('pointercancel', (e) => this.handlePointerUp(e));

        this.canvas.addEventListener('contextmenu', (e) => this.handleContextMenu(e));

        document.addEventListener('click', (event) => {
            if (!this.radialMenu?.isVisible()) return;
            const root = this.radialMenu.root;
            if (!root) return;
            if (!root.contains(event.target)) {
                this.radialMenu.hide();
            }
        });

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.radialMenu?.isVisible()) {
                this.radialMenu.hide();
            }
        });

        // Mouse-Events für Hover-Effekte mit optimierter Performance
        let hoverTimeout = null;
        let lastRenderTime = 0;

        // Mouse-Leave Event: Sofortiges Ghost-Cleanup
        this.canvas.addEventListener('mouseleave', (e) => {
            // Sofortiges Ghost-Cleanup wenn Maus den Canvas verlässt
            if (this.isDraggingReservation && this.ghostBar) {
                // Nur Ghost-Bar unsichtbar machen, Drag aber weiter aktiv lassen
                this.ghostBar.visible = false;

                // Cache für alle Zimmer leeren um Ghost-Reste zu entfernen  
                if (this.stackingCache) {
                    this.stackingCache.clear();
                }

                this.scheduleRender('ghost_cleanup');
            }
        });

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
        }, { passive: true });

        this.canvas.addEventListener('mouseleave', () => {
            this.hoveredReservation = null;
            this.scheduleRender('mouseleave');
        });

        // Setup drag & drop events for separator
        this.setupSeparatorEvents();
    }

    handlePointerDown(e) {
        if (e.pointerType !== 'touch') {
            return;
        }

        if (this.activePointerId !== null) {
            return;
        }

        this.activePointerId = e.pointerId;
        this.canvas.setPointerCapture(e.pointerId);

        const rect = this.canvas.getBoundingClientRect();
        this.mouseX = e.clientX - rect.left;
        this.mouseY = e.clientY - rect.top;

        this.handleMouseDown(e);

        const isSeparatorDrag = this.isDraggingSeparator || this.isDraggingBottomSeparator;
        const isReservationDrag = this.isDraggingReservation;

        if (!isSeparatorDrag && !isReservationDrag) {
            this.startTouchPanning(e);
        }

        e.preventDefault();
    }

    handlePointerMove(e) {
        if (e.pointerType !== 'touch' || this.activePointerId !== e.pointerId) {
            return;
        }

        const rect = this.canvas.getBoundingClientRect();
        this.mouseX = e.clientX - rect.left;
        this.mouseY = e.clientY - rect.top;

        if (this.isDraggingSeparator || this.isDraggingBottomSeparator) {
            const mouseY = this.mouseY;
            if (this.isDraggingSeparator) {
                this.handleTopSeparatorDrag(mouseY);
            } else {
                this.handleBottomSeparatorDrag(mouseY);
            }
            this.scheduleRender('separator_touch_move');
            e.preventDefault();
            return;
        }

        if (this.isDraggingReservation) {
            this.handleReservationDrag(e);
            this.scheduleRender('drag_touch_move');
            e.preventDefault();
            return;
        }

        if (this.isTouchPanning && this.panContext) {
            this.updateTouchPan(e);
            e.preventDefault();
        }
    }

    handlePointerUp(e) {
        if (e.pointerType !== 'touch' || this.activePointerId !== e.pointerId) {
            return;
        }

        if (this.isDraggingReservation) {
            this.finishReservationDrag();
            this.scheduleRender('drag_touch_end');
        } else if (this.isDraggingSeparator || this.isDraggingBottomSeparator) {
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

        if (this.isTouchPanning) {
            this.isTouchPanning = false;
            this.panContext = null;
        }

        this.canvas.releasePointerCapture(e.pointerId);
        this.activePointerId = null;
        e.preventDefault();
    }

    startTouchPanning(e) {
        const rect = this.canvas.getBoundingClientRect();
        const pointerY = e.clientY - rect.top;

        this.isTouchPanning = true;
        this.panContext = this.getPanContext(pointerY);
        this.panStart = {
            clientX: e.clientX,
            clientY: e.clientY,
            scrollX: this.scrollX,
            masterScrollY: this.masterScrollY,
            roomsScrollY: this.roomsScrollY
        };
    }

    updateTouchPan(e) {
        const deltaX = e.clientX - this.panStart.clientX;
        const deltaY = e.clientY - this.panStart.clientY;
        let needsRender = false;

        if (this.panContext.allowHorizontal && this.horizontalTrack) {
            const maxScrollX = Math.max(0, this.horizontalTrack.scrollWidth - this.horizontalTrack.clientWidth);
            const newScrollX = this.clamp(this.panStart.scrollX - deltaX, 0, maxScrollX);

            if (Math.abs(newScrollX - this.scrollX) > 0.5) {
                this.horizontalTrack.scrollLeft = newScrollX;
                this.scrollX = newScrollX;
                this.updateViewportCache(this.scrollX, this.roomsScrollY);
                this.invalidateStackingCache();
                needsRender = true;
            }
        }

        if (this.panContext.allowVertical && this.panContext.mode === 'master' && this.masterTrack) {
            const maxMaster = Math.max(0, this.masterTrack.scrollHeight - this.masterTrack.clientHeight);
            const newMaster = this.clamp(this.panStart.masterScrollY - deltaY, 0, maxMaster);

            if (Math.abs(newMaster - this.masterScrollY) > 0.5) {
                this.masterTrack.scrollTop = newMaster;
                this.masterScrollY = newMaster;
                needsRender = true;
            }
        }

        if (this.panContext.allowVertical && this.panContext.mode !== 'master' && this.roomsTrack) {
            const maxRooms = Math.max(0, this.roomsTrack.scrollHeight - this.roomsTrack.clientHeight);
            const newRooms = this.clamp(this.panStart.roomsScrollY - deltaY, 0, maxRooms);

            if (Math.abs(newRooms - this.roomsScrollY) > 0.5) {
                this.roomsTrack.scrollTop = newRooms;
                this.roomsScrollY = newRooms;
                needsRender = true;
            }
        }

        if (needsRender) {
            this.scheduleRender('touch_pan');
        }
    }

    getPanContext(pointerY) {
        if (pointerY >= this.areas.master.y && pointerY <= this.areas.master.y + this.areas.master.height) {
            return { mode: 'master', allowHorizontal: true, allowVertical: true };
        }

        if (pointerY >= this.areas.rooms.y && pointerY <= this.areas.rooms.y + this.areas.rooms.height) {
            return { mode: 'rooms', allowHorizontal: true, allowVertical: true };
        }

        if (pointerY >= this.areas.histogram.y && pointerY <= this.areas.histogram.y + this.areas.histogram.height) {
            return { mode: 'histogram', allowHorizontal: true, allowVertical: false };
        }

        return { mode: 'header', allowHorizontal: true, allowVertical: false };
    }

    clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
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

        if (this.radialMenu?.isVisible()) {
            this.radialMenu.hide();
        }

        if (e.button !== undefined && e.button !== 0) {
            return;
        }

        if (this.configButtonBounds) {
            const bounds = this.configButtonBounds;
            if (mouseX >= bounds.x && mouseX <= bounds.x + bounds.width &&
                mouseY >= bounds.y && mouseY <= bounds.y + bounds.height) {
                window.location.href = 'timeline-config.html';
                e.preventDefault();
                return;
            }
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
        if (e.button !== 0) {
            return;
        }

        // Canvas MouseUp nur für lokale Events, globale Events über document
        // Separator handling wird über document.mouseup behandelt

        // Reservierung Drag & Drop wird über document.mouseup behandelt
        // Hier nur für Fallback
        if (this.isDraggingReservation) {
            this.finishReservationDrag();
        }
    }

    handleContextMenu(e) {
        if (!this.canvas || !this.radialMenu) return;

        const rect = this.canvas.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        if (mouseY < this.areas.rooms.y || mouseY > this.areas.rooms.y + this.areas.rooms.height) {
            if (this.radialMenu.isVisible()) {
                this.radialMenu.hide();
            }
            return;
        }

        const hit = this.findReservationAt(mouseX, mouseY);
        if (!hit) {
            if (this.radialMenu.isVisible()) {
                this.radialMenu.hide();
            }
            e.preventDefault();
            return;
        }

        const detail = this.resolveRoomDetail(hit);
        if (!detail) {
            console.warn('Radial-Menü: Detail konnte nicht aufgelöst werden', hit);
            e.preventDefault();
            return;
        }

        const rings = this.buildRadialRingConfigurations(detail);
        if (!rings.length) {
            e.preventDefault();
            return;
        }

        e.preventDefault();
        this.radialMenu.show(detail, e.clientX, e.clientY, rings);
    }

    resolveRoomDetail(detail) {
        if (!detail) return null;
        if (!this.dataIndex) {
            this.initializeDataIndex(reservations, roomDetails);
        }

        const identifiers = this.extractDetailIdentifiers(detail);

        const lookupByKey = (key) => {
            if (key === undefined || key === null) return null;
            const stringKey = String(key);
            if (this.dataIndex?.roomDetailsById?.has(stringKey)) {
                return this.dataIndex.roomDetailsById.get(stringKey);
            }
            return null;
        };

        const directMatch = lookupByKey(identifiers.detailId) || lookupByKey(identifiers.uniqueId);
        if (directMatch) {
            return directMatch;
        }

        if (identifiers.detailId) {
            const matchById = roomDetails.find(entry => {
                const entryIdentifiers = this.extractDetailIdentifiers(entry);
                return entryIdentifiers.detailId && entryIdentifiers.detailId === identifiers.detailId;
            });
            if (matchById) {
                return matchById;
            }
        }

        if (detail.id) {
            const matchByString = roomDetails.find(entry => entry.id === detail.id);
            if (matchByString) {
                return matchByString;
            }
        }

        return roomDetails.find(entry => entry === detail) || null;
    }

    buildRadialRingConfigurations(detail) {
        const baseConfigs = [];

        const colorOptions = this.getColorPaletteOptions(detail);
        if (colorOptions.length) {
            baseConfigs.push({ action: 'color', options: colorOptions });
        }

        const arrangementOptions = this.getArrangementOptions(detail);
        if (arrangementOptions.length) {
            baseConfigs.push({ action: 'arrangement', options: arrangementOptions });
        }

        const capacityOptions = this.getCapacityOptions(detail);
        if (capacityOptions.length) {
            baseConfigs.push({ action: 'capacity', options: capacityOptions });
        }

        const commandOptions = this.getCommandOptions(detail);
        if (commandOptions.length) {
            baseConfigs.push({ action: 'command', options: commandOptions });
        }

        return baseConfigs.map((config, index) => {
            const bounds = this.radialMenu.getRingBounds(index);
            return {
                ...config,
                innerRadius: bounds.innerRadius,
                outerRadius: bounds.outerRadius
            };
        });
    }

    getColorPaletteOptions(detail) {
        const palette = [
            { label: 'Std', value: detail?.color || detail?.data?.color || '#3498db' },
            { label: 'Blau', value: '#1f78ff' },
            { label: 'Grün', value: '#27ae60' },
            { label: 'Rot', value: '#c0392b' },
            { label: 'Orange', value: '#e67e22' },
            { label: 'Vio', value: '#8e44ad' },
            { label: 'Cyan', value: '#16a085' },
            { label: 'Grau', value: '#7f8c8d', textColor: '#ffffff' }
        ];

        const seen = new Set();
        return palette.filter(option => {
            const key = option.value.toLowerCase();
            if (seen.has(key)) return false;
            seen.add(key);
            option.fill = option.value;
            return true;
        });
    }

    getArrangementOptions(detail) {
        if (Array.isArray(this.arrangementsCatalog) && this.arrangementsCatalog.length > 0) {
            return this.arrangementsCatalog.slice(0, 12).map((arr, index) => ({
                label: arr.shortLabel || arr.label,
                fullLabel: arr.label,
                id: arr.id,
                fill: arr.fill || this.generateArrangementColor(index),
                textColor: '#ffffff'
            }));
        }

        const fallbackLabel = detail?.arrangement_label || detail?.data?.arrangement || detail?.data?.arrangement_kbez;
        if (fallbackLabel) {
            return [{
                label: fallbackLabel,
                fullLabel: fallbackLabel,
                id: detail?.arr_id ?? detail?.data?.arr_id ?? null,
                fill: '#636e72',
                textColor: '#ffffff'
            }];
        }

        return [];
    }

    generateArrangementColor(index) {
        const palette = ['#2980b9', '#16a085', '#8e44ad', '#d35400', '#c0392b', '#2c3e50', '#27ae60', '#7f8c8d', '#f39c12', '#34495e'];
        return palette[index % palette.length];
    }

    getCapacityOptions(detail) {
        const maxOption = 10;
        const options = [];
        for (let value = 0; value <= maxOption; value++) {
            options.push({
                label: String(value),
                value,
                fill: value === (detail?.capacity ?? detail?.data?.capacity) ? '#1abc9c' : '#2d3436',
                textColor: '#ffffff'
            });
        }
        return options;
    }

    getCommandOptions() {
        return [
            { label: 'Teilen', command: 'share', fill: '#0984e3' },
            { label: 'Splitten', command: 'split', fill: '#e17055' },
            { label: 'Bezeichnung', command: 'label', fill: '#00cec9' },
            { label: 'Notiz', command: 'note', fill: '#fdcb6e' },
            { label: 'Hund', command: 'dog', fill: '#b2bec3', textColor: '#2d3436' },
            { label: 'Löschen', command: 'delete', fill: '#ad1457' },
            { label: 'Alle löschen', command: 'delete_all', fill: '#d63031' },
            { label: 'Datensatz', command: 'dataset', fill: '#6c5ce7' }
        ];
    }

    handleRadialColorSelection(option, detail) {
        if (!option || !detail) return;
        const resolved = this.resolveRoomDetail(detail);
        if (!resolved) return;
        const identifiers = this.extractDetailIdentifiers(resolved);
        if (!identifiers.resId) {
            if (window.alert) {
                window.alert('Für diese Reservierung konnte keine ID ermittelt werden.');
            }
            return;
        }

        const affected = roomDetails.filter(entry => this.extractDetailIdentifiers(entry).resId === identifiers.resId);
        const backups = affected.map(entry => ({
            reference: entry,
            color: entry.color,
            dataColor: entry.data?.color
        }));

        affected.forEach(entry => {
            entry.color = option.value;
            if (!entry.data) entry.data = {};
            entry.data.color = option.value;
            entry.style = option.value ? `background-color: ${option.value};` : entry.style;
            if (entry.room_id) {
                this.invalidateStackingCache(entry.room_id);
            }
        });

        if (this.dataIndex) {
            this.initializeDataIndex(reservations, roomDetails);
        }

        this.markDataDirty();
        this.render();

        this.persistRoomDetailAttributes({
            scope: 'reservation',
            res_id: identifiers.resId,
            updates: { color: option.value }
        }, () => {
            backups.forEach(snapshot => {
                snapshot.reference.color = snapshot.color;
                if (!snapshot.reference.data) snapshot.reference.data = {};
                snapshot.reference.data.color = snapshot.dataColor;
            });
            if (this.dataIndex) {
                this.initializeDataIndex(reservations, roomDetails);
            }
            this.markDataDirty();
            this.render();
        });

        this.radialMenu.hide();
    }

    handleRadialArrangementSelection(option, detail) {
        if (!option || !detail) return;
        const resolved = this.resolveRoomDetail(detail);
        if (!resolved) return;
        const identifiers = this.extractDetailIdentifiers(resolved);
        if (!identifiers.resId) {
            if (window.alert) {
                window.alert('Reservierungs-ID konnte nicht ermittelt werden.');
            }
            return;
        }

        let targetId = null;
        if (option.id !== undefined && option.id !== null) {
            const parsed = Number(option.id);
            if (Number.isFinite(parsed)) {
                targetId = parsed;
            }
        }
        const label = option.fullLabel || option.label || 'n/a';

        const affected = roomDetails.filter(entry => this.extractDetailIdentifiers(entry).resId === identifiers.resId);
        const backups = affected.map(entry => ({
            reference: entry,
            arrId: entry.arr_id ?? entry.data?.arr_id ?? null,
            arrangementLabel: entry.arrangement_label ?? entry.data?.arrangement ?? null
        }));

        affected.forEach(entry => {
            entry.arr_id = targetId;
            entry.arrangement_label = label;
            if (!entry.data) entry.data = {};
            entry.data.arr_id = targetId;
            entry.data.arrangement = label;
            entry.data.arrangement_kbez = label;
            if (entry.room_id) {
                this.invalidateStackingCache(entry.room_id);
            }
        });

        if (this.dataIndex) {
            this.initializeDataIndex(reservations, roomDetails);
        }

        this.markDataDirty();
        this.render();

        this.persistRoomDetailAttributes({
            scope: 'reservation',
            res_id: identifiers.resId,
            updates: { arr_id: targetId }
        }, () => {
            backups.forEach(snapshot => {
                snapshot.reference.arr_id = snapshot.arrId;
                if (!snapshot.reference.data) snapshot.reference.data = {};
                snapshot.reference.arrangement_label = snapshot.arrangementLabel;
                snapshot.reference.data.arr_id = snapshot.arrId;
                snapshot.reference.data.arrangement = snapshot.arrangementLabel;
                snapshot.reference.data.arrangement_kbez = snapshot.arrangementLabel;
            });
            if (this.dataIndex) {
                this.initializeDataIndex(reservations, roomDetails);
            }
            this.markDataDirty();
            this.render();
        });

        this.radialMenu.hide();
    }

    handleRadialCapacitySelection(option, detail) {
        if (!option || !detail) return;

        const resolved = this.resolveRoomDetail(detail);
        if (!resolved) return;

        const identifiers = this.extractDetailIdentifiers(resolved);
        if (!identifiers.detailId) {
            if (window.alert) {
                window.alert('Detail-ID konnte nicht ermittelt werden.');
            }
            return;
        }

        const previous = {
            capacity: resolved.capacity,
            dataCapacity: resolved.data?.capacity,
            dataAnz: resolved.data?.anz,
            caption: resolved.caption
        };

        const newCapacity = Number(option.value);
        resolved.capacity = Number.isFinite(newCapacity) ? newCapacity : option.value;
        if (!resolved.data) resolved.data = {};
        resolved.data.capacity = resolved.capacity;
        resolved.data.anz = resolved.capacity;

        this.normalizeRoomDetail(resolved);
        this.invalidateStackingCache(resolved.room_id);
        if (this.dataIndex) {
            this.initializeDataIndex(reservations, roomDetails);
        }
        this.markDataDirty();
        this.render();

        this.persistRoomDetailAttributes({
            scope: 'detail',
            detail_id: identifiers.detailId,
            res_id: identifiers.resId,
            updates: { anzahl: resolved.capacity }
        }, () => {
            resolved.capacity = previous.capacity;
            if (!resolved.data) resolved.data = {};
            resolved.data.capacity = previous.dataCapacity;
            resolved.data.anz = previous.dataAnz;
            resolved.caption = previous.caption;

            this.normalizeRoomDetail(resolved);
            this.invalidateStackingCache(resolved.room_id);
            if (this.dataIndex) {
                this.initializeDataIndex(reservations, roomDetails);
            }
            this.markDataDirty();
            this.render();
        });

        this.radialMenu.hide();
    }

    handleRadialCommandSelection(option, detail) {
        this.radialMenu.hide();
        if (!option) return;

        const command = option.command;
        const label = option.label || command || 'Aktion';

        switch (command) {
            case 'note':
                this.handleNoteCommand(detail);
                break;
            case 'share':
                this.handleShareCommand(detail);
                break;
            case 'split':
                this.handleSplitCommand(detail);
                break;
            case 'label':
                this.handleLabelCommand(detail);
                break;
            case 'dog':
                this.handleDogCommand(detail);
                break;
            case 'delete':
                this.handleDeleteCommand(detail);
                break;
            case 'delete_all':
                this.handleDeleteAllCommand(detail);
                break;
            case 'dataset':
                this.handleDatasetCommand(detail);
                break;
            default:
                if (window.alert) {
                    window.alert(`Die Funktion "${label}" ist noch in Vorbereitung.`);
                }
                console.info('Radial-Kommando (noch nicht implementiert):', option, detail);
        }
    }

    handleNoteCommand(detail) {
        const currentNote = detail?.data?.note || detail?.note || '';
        const newNote = prompt('Notiz eingeben:', currentNote);
        if (newNote !== null) {
            if (!detail.data) detail.data = {};
            detail.data.note = newNote;
            detail.note = newNote;
            this.invalidateCache();
            this.renderFrame();
        }
    }

    handleShareCommand(detail) {
        if (window.alert) {
            window.alert('Teilen-Funktion ist noch in Vorbereitung.');
        }
    }

    handleSplitCommand(detail) {
        if (window.alert) {
            window.alert('Splitten-Funktion ist noch in Vorbereitung.');
        }
    }

    handleLabelCommand(detail) {
        const currentLabel = detail?.caption || detail?.data?.caption || detail?.guest_name || '';
        const newLabel = prompt('Bezeichnung eingeben:', currentLabel);
        if (newLabel !== null) {
            detail.caption = newLabel;
            if (!detail.data) detail.data = {};
            detail.data.caption = newLabel;
            this.invalidateCache();
            this.renderFrame();
        }
    }

    handleDogCommand(detail) {
        const currentDog = detail?.data?.dog || detail?.dog || false;
        const newDog = confirm(`Hund ${currentDog ? 'entfernen' : 'hinzufügen'}?`);
        if (!detail.data) detail.data = {};
        detail.data.dog = currentDog ? false : newDog;
        detail.dog = detail.data.dog;
        this.invalidateCache();
        this.renderFrame();
    }

    async handleDeleteCommand(detail) {
        // Verwende modale Bestätigung anstatt confirm()
        const shouldDelete = await showConfirmationModal(
            'Reservierung löschen',
            'Möchten Sie diese Reservierung wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.',
            'Löschen',
            'Abbrechen'
        );

        if (shouldDelete) {
            // Detail-ID aus dem Detail-Objekt extrahieren
            const detailId = detail.data?.detail_id || detail.detail_id || detail.ID || detail.id;

            if (!detailId) {
                console.error('Keine Detail-ID gefunden:', detail);
                alert('Fehler: Keine gültige Detail-ID gefunden');
                return;
            }

            // AJAX-Aufruf zur API für das Löschen aus der Datenbank
            fetch('/wci/reservierungen/api/deleteReservationDetail.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    detailId: detailId
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Detail-Datensatz erfolgreich gelöscht:', data.deletedDetail);

                        // Lokale Daten aus roomDetails entfernen
                        const index = roomDetails.findIndex(item => item === detail);
                        if (index >= 0) {
                            roomDetails.splice(index, 1);
                        }

                        // Einfacher Page-Reload für saubere Aktualisierung
                        window.location.reload();

                    } else {
                        console.error('Fehler beim Löschen:', data.error);
                        alert('Fehler beim Löschen: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Netzwerkfehler beim Löschen:', error);
                    alert('Netzwerkfehler beim Löschen: ' + error.message);
                });
        }
    }

    async handleDeleteAllCommand(detail) {
        // Verwende modale Bestätigung anstatt confirm()
        const shouldDelete = await showConfirmationModal(
            'Alle Zimmer-Zuweisungen löschen',
            'Möchten Sie ALLE Zimmer-Zuweisungen dieser Reservierung wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.',
            'ALLE Löschen',
            'Abbrechen'
        );

        if (shouldDelete) {
            // Reservierungs-ID aus dem Detail-Objekt extrahieren
            const resId = detail.data?.res_id || detail.res_id || detail.resid;
            
            if (!resId) {
                console.error('Keine Reservierungs-ID gefunden:', detail);
                alert('Fehler: Keine gültige Reservierungs-ID gefunden');
                return;
            }

            // AJAX-Aufruf zur API für das Löschen aller Details aus der Datenbank
            fetch('/wci/reservierungen/api/deleteReservationAllDetails.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    resId: resId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Alle Detail-Datensätze erfolgreich gelöscht:', data.deletedDetails);
                    console.log(`${data.deletedCount} Datensätze für Reservierung ${data.resId} gelöscht`);
                    
                    // Lokale Daten aus roomDetails entfernen (alle mit gleicher resid)
                    for (let i = roomDetails.length - 1; i >= 0; i--) {
                        const item = roomDetails[i];
                        const itemResId = item.data?.res_id || item.res_id || item.resid;
                        if (itemResId == resId) {
                            roomDetails.splice(i, 1);
                        }
                    }
                    
                    // Einfacher Page-Reload für saubere Aktualisierung
                    window.location.reload();
                    
                } else {
                    console.error('Fehler beim Löschen aller Details:', data.error);
                    alert('Fehler beim Löschen: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Netzwerkfehler beim Löschen aller Details:', error);
                alert('Netzwerkfehler beim Löschen: ' + error.message);
            });
        }
    }

    handleDatasetCommand(detail) {
        // Zeige Detail-Informationen zur Reservierung
        const info = [
            `ID: ${detail.id || 'N/A'}`,
            `Gast: ${detail.guest_name || detail.name || 'N/A'}`,
            `Zimmer: ${detail.room_id || 'N/A'}`,
            `Von: ${detail.start || 'N/A'}`,
            `Bis: ${detail.end || 'N/A'}`,
            `Kapazität: ${detail.capacity || detail.data?.capacity || 'N/A'}`,
            `Notiz: ${detail.note || detail.data?.note || 'Keine'}`
        ].join('\n');

        if (window.alert) {
            window.alert(info);
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

        // WICHTIG: Speichere eine eindeutige Referenz auf den EXAKTEN Balken
        this.draggedReservationReference = reservation; // Direkte Objektreferenz

        // Relativen Offset von Mausposition zu Balken-Ecke speichern
        this.dragOffsetX = mouseX - reservation.left;
        this.dragOffsetY = mouseY - reservation.stackY;

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

        // Phase 3+: Initialize drag optimization
        this.dragOptimization.draggedReservationBackup = { ...reservation };
        this.dragOptimization.previewStacking.clear();
        this.dragOptimization.affectedRooms.clear();
        this.dragOptimization.lastDragPosition = { x: mouseX, y: mouseY, room: null };

        // Add original room to affected rooms
        if (reservation.room_id) {
            this.dragOptimization.affectedRooms.add(reservation.room_id);
        }

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

        // Prüfe ob dies ein primär vertikaler Drag ist (Zimmer-Wechsel)
        const isVerticalDrag = Math.abs(deltaY) > Math.abs(deltaX) && Math.abs(deltaY) > 20;

        // Berechne neue Datums-Werte basierend auf Drag-Modus
        // Bei vertikalem Drag keine horizontale Verschiebung zulassen
        const daysDelta = isVerticalDrag ? 0 : Math.round(deltaX / this.DAY_WIDTH);

        // Phase 3+: Real-time optimal stacking during drag
        if (this.dragOptimization.enabled) {
            this.updateDragPreview(mouseX, mouseY, daysDelta);
        }

        // Update pixel-precise ghost frame that follows mouse
        this.updatePixelGhostFrame(mouseX, mouseY);

        // Invalidate stacking cache for affected rooms to force re-calculation
        if (this.dragOriginalData?.room_id) {
            this.invalidateStackingCache(this.dragOriginalData.room_id);
        }
        const targetRoom = this.findRoomAt(mouseY);
        if (targetRoom && targetRoom.id !== this.dragOriginalData?.room_id) {
            this.invalidateStackingCache(targetRoom.id);
        }

        // WICHTIG: Zimmer-Höhe neu berechnen wenn Drag andere Zeile erreicht
        const previousTargetRoom = this.dragTargetRoom;
        this.dragTargetRoom = targetRoom;

        // Wenn sich das Ziel-Zimmer geändert hat, Höhen neu berechnen
        if (previousTargetRoom && targetRoom && previousTargetRoom.id !== targetRoom.id) {
            // Alte Zimmer-Höhe zurücksetzen (entferne Ghost-Effekt)
            if (previousTargetRoom) {
                delete previousTargetRoom._dynamicHeight;
                this.invalidateStackingCache(previousTargetRoom.id);
            }

            // Neue Zimmer-Höhe wird beim nächsten Render automatisch berechnet
            delete targetRoom._dynamicHeight;
            this.invalidateStackingCache(targetRoom.id);
        }

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

        // Phase 3+: Smart stacking update - only for affected rooms
        this.updateRoomStackingOptimal();
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
        const originalStart = new Date(this.dragOriginalData.start);
        const originalEnd = new Date(this.dragOriginalData.end);
        let targetRoomId = originalRoomId;

        if (this.dragMode === 'move' && this.dragTargetRoom &&
            this.dragTargetRoom.id !== this.dragOriginalData.room_id) {
            this.draggedReservation.room_id = this.dragTargetRoom.id;
            targetRoomId = this.dragTargetRoom.id;
        }

        const identifiers = this.extractDetailIdentifiers(this.draggedReservation);
        const caption = this.getDetailCaption(this.draggedReservation);

        let originalIndex = -1;
        let updatePayload = null;
        let originalSnapshot = null;

        if (Array.isArray(roomDetails) && roomDetails.length > 0) {
            originalIndex = roomDetails.findIndex(detail =>
                detail === this.draggedReservation ||
                (detail.id && detail.id === this.draggedReservation.id) ||
                (detail.detail_id && detail.detail_id === this.draggedReservation.detail_id) ||
                (detail.data && detail.data.detail_id === this.draggedReservation.data?.detail_id)
            );

            if (originalIndex !== -1) {
                const updatedData = {
                    ...(this.draggedReservation.data || {}),
                    room_id: targetRoomId,
                    res_id: identifiers.resId,
                    detail_id: identifiers.detailId,
                    caption
                };

                const updatedDetail = {
                    ...this.draggedReservation,
                    id: identifiers.uniqueId || this.draggedReservation.id,
                    detail_id: identifiers.detailId ?? this.draggedReservation.detail_id ?? updatedData.detail_id,
                    res_id: identifiers.resId ?? this.draggedReservation.res_id ?? updatedData.res_id,
                    reservation_id: identifiers.resId ?? this.draggedReservation.reservation_id,
                    caption,
                    room_id: targetRoomId,
                    start: new Date(this.draggedReservation.start),
                    end: new Date(this.draggedReservation.end),
                    data: updatedData
                };

                roomDetails[originalIndex] = updatedDetail;
                this.normalizeRoomDetail(updatedDetail);

                if (this.dataIndex) {
                    this.initializeDataIndex(reservations, roomDetails);
                }

                if (this.dragOptimization?.draggedReservationBackup) {
                    const backup = this.dragOptimization.draggedReservationBackup;
                    originalSnapshot = {
                        ...backup,
                        id: identifiers.uniqueId || backup.id,
                        detail_id: identifiers.detailId || backup.detail_id || (backup.data && backup.data.detail_id) || null,
                        res_id: identifiers.resId || backup.res_id || (backup.data && backup.data.res_id) || null,
                        reservation_id: identifiers.resId || backup.reservation_id || null,
                        caption: this.getDetailCaption(backup),
                        room_id: originalRoomId,
                        start: new Date(originalStart),
                        end: new Date(originalEnd),
                        data: {
                            ...(backup.data || {}),
                            room_id: originalRoomId,
                            res_id: identifiers.resId || backup.res_id || (backup.data && backup.data.res_id) || null,
                            detail_id: identifiers.detailId || backup.detail_id || (backup.data && backup.data.detail_id) || null
                        }
                    };
                }

                updatePayload = this.buildRoomDetailUpdatePayload(
                    updatedDetail,
                    originalRoomId,
                    originalStart,
                    originalEnd
                );
            }
        }

        this.invalidateStackingCache(originalRoomId);
        if (targetRoomId !== originalRoomId) {
            this.invalidateStackingCache(targetRoomId);
        }

        if (this.dragOptimization && this.dragOptimization.previewStackingCache) {
            const cache = this.dragOptimization.previewStackingCache;
            for (const [roomId, roomData] of cache) {
                if (roomData.stacking && roomData.stacking.length > 0) {
                    roomData.stacking.forEach(stackingInfo => {
                        const reservation = reservations.find(res => res.id === stackingInfo.id);
                        if (reservation && stackingInfo.optimalPosition !== undefined) {
                            reservation._stackPosition = stackingInfo.optimalPosition;
                            reservation._stackLevel = stackingInfo.stackLevel;
                        }
                    });
                }
            }
            this.dragOptimization.previewStackingCache.clear();
        }

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
                delete room._dynamicHeight;
                if (this.stackingCache) {
                    const cacheKey = `${roomId}_stacking`;
                    this.stackingCache.delete(cacheKey);
                }
            }
        });

        this.markDataDirty();

        if (this.dragOptimization) {
            this.dragOptimization.previewStackingCache.clear();
            this.dragOptimization.previewStacking.clear();
            this.dragOptimization.isActive = false;
        }

        this.draggedReservationReference = null;

        const shouldPersist = Boolean(updatePayload && originalIndex !== -1);
        this.cancelDrag();

        if (shouldPersist) {
            this.persistRoomDetailChange(updatePayload, originalIndex, originalSnapshot);
        }
    }

    cancelDrag() {
        if (this.isDraggingReservation && this.draggedReservation && this.dragOriginalData) {
            // Rollback bei Abbruch
            this.draggedReservation.start = this.dragOriginalData.start;
            this.draggedReservation.end = this.dragOriginalData.end;
            this.draggedReservation.room_id = this.dragOriginalData.room_id;
        }

        // Clean up drag optimization state
        if (this.dragOptimization) {
            this.dragOptimization.previewStackingCache.clear();
            this.dragOptimization.previewStacking.clear();
            this.dragOptimization.affectedRooms.clear();
            this.dragOptimization.isActive = false;
        }

        // Clear ALL drag-related state
        this.isDraggingReservation = false;
        this.draggedReservation = null;
        this.draggedReservationReference = null; // Clear drag reference
        this.dragMode = null;
        this.dragOriginalData = null;
        this.dragTargetRoom = null;
        this.ghostBar = null; // Ghost-Bar ausblenden
        this.pixelGhostFrame = null; // Pixel ghost frame ausblenden

        // Clear any temporary ghost reservations from stacking cache  
        if (this.stackingCache) {
            this.stackingCache.clear(); // Force clear all cache to remove ghosts
        }

        // WICHTIG: Alle Zimmer-Höhen zurücksetzen damit sie neu berechnet werden
        if (typeof rooms !== 'undefined' && rooms) {
            rooms.forEach(room => {
                delete room._dynamicHeight; // Erzwinge Neuberechnung beim nächsten Render
            });
        }

        // ZUSÄTZLICH: Entferne alle Ghost-Reservierungen aus roomDetails (falls welche hineingeraten sind)
        if (typeof roomDetails !== 'undefined' && roomDetails) {
            for (let i = roomDetails.length - 1; i >= 0; i--) {
                if (roomDetails[i]._isGhost || roomDetails[i].id === 'ghost-current-drag') {
                    roomDetails.splice(i, 1);
                }
            }
        }

        this.canvas.style.cursor = 'default';

        // Force immediate re-render to clear ghost bar and pixel frame
        this.scheduleRender('drag_cleanup');
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

            // Diskrete Zimmer-Position mit Stacking-Berechnung
            const targetRoom = this.findRoomAt(mouseY);
            if (targetRoom) {
                this.ghostBar.targetRoom = targetRoom;
                const baseRoomY = this.calculateRoomY(targetRoom);

                // Berechne optimale Y-Position basierend auf vorhandenem Stacking
                let optimalStackLevel = 0;
                if (targetRoom.id !== this.dragOriginalData.room_id) {
                    // Nur wenn es ein anderes Zimmer ist, berechne Stacking
                    const roomReservations = this.getReservationsForRoom(targetRoom.id);
                    const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;

                    // Check for conflicts with existing reservations
                    const ghostLeft = this.ghostBar.x;
                    const ghostRight = ghostLeft + this.ghostBar.width;

                    for (const reservation of roomReservations) {
                        if (reservation === this.draggedReservation) continue;

                        // Calculate reservation position
                        const checkinDate = new Date(reservation.start);
                        checkinDate.setHours(12, 0, 0, 0);
                        const checkoutDate = new Date(reservation.end);
                        checkoutDate.setHours(12, 0, 0, 0);

                        const resStartOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
                        const resDuration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

                        const resLeft = startX + (resStartOffset + 0.01) * this.DAY_WIDTH;
                        const resRight = resLeft + (resDuration - 0.02) * this.DAY_WIDTH;

                        // Check for overlap
                        if (!(ghostRight <= resLeft + OVERLAP_TOLERANCE || ghostLeft >= resRight - OVERLAP_TOLERANCE)) {
                            // There's an overlap, need to stack higher
                            const currentStackLevel = reservation._stackLevel || reservation.stackLevel || 0;
                            optimalStackLevel = Math.max(optimalStackLevel, currentStackLevel + 1);
                        }
                    }
                }

                const barHeight = this.themeConfig.room.barHeight || 16;
                this.ghostBar.y = baseRoomY + 1 + (optimalStackLevel * (barHeight + 2));
                this.ghostBar.height = barHeight;
                this.ghostBar._stackLevel = optimalStackLevel;
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

    updatePixelGhostFrame(mouseX, mouseY) {
        if (!this.isDraggingReservation || !this.draggedReservation) {
            this.pixelGhostFrame = null;
            return;
        }

        // Calculate pixel-precise frame that follows mouse exactly
        const originalWidth = this.draggedReservation.width || 100;
        const originalHeight = 16;

        if (this.dragMode === 'move') {
            // Frame follows mouse position but uses relative offset (where mouse grabbed the bar)
            this.pixelGhostFrame = {
                x: mouseX - (this.dragOffsetX || originalWidth / 2),
                y: mouseY - (this.dragOffsetY || originalHeight / 2),
                width: originalWidth,
                height: originalHeight,
                visible: true,
                mode: this.dragMode
            };
        } else if (this.dragMode === 'resize-start' || this.dragMode === 'resize-end') {
            // For resize, show frame at current reservation position but follow mouse for width
            const originalLeft = this.draggedReservation.left || mouseX;

            if (this.dragMode === 'resize-start') {
                const newWidth = Math.max(20, originalLeft + originalWidth - mouseX);
                this.pixelGhostFrame = {
                    x: mouseX,
                    y: mouseY - (originalHeight / 2),
                    width: newWidth,
                    height: originalHeight,
                    visible: true,
                    mode: this.dragMode
                };
            } else { // resize-end
                const newWidth = Math.max(20, mouseX - originalLeft);
                this.pixelGhostFrame = {
                    x: originalLeft,
                    y: mouseY - (originalHeight / 2),
                    width: newWidth,
                    height: originalHeight,
                    visible: true,
                    mode: this.dragMode
                };
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
        // Strict check to ensure ghost bar is only rendered during active drag
        if (!this.ghostBar ||
            !this.ghostBar.visible ||
            !this.isDraggingReservation ||
            !this.draggedReservation ||
            !this.dragMode) {
            return;
        }

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

    renderPixelGhostFrame() {
        // Render pixel-precise frame that follows mouse exactly
        if (!this.pixelGhostFrame ||
            !this.pixelGhostFrame.visible ||
            !this.isDraggingReservation) {
            return;
        }

        const ctx = this.ctx;
        ctx.save();

        // Very light, translucent frame that follows mouse exactly
        ctx.globalAlpha = 0.3;
        ctx.strokeStyle = '#FFFFFF';
        ctx.lineWidth = 2;
        ctx.setLineDash([3, 3]); // Dashed line for pixel frame

        // Draw frame rectangle
        ctx.strokeRect(
            this.pixelGhostFrame.x,
            this.pixelGhostFrame.y,
            this.pixelGhostFrame.width,
            this.pixelGhostFrame.height
        );

        // Add small corner indicators
        ctx.globalAlpha = 0.6;
        ctx.fillStyle = '#FFFFFF';
        const cornerSize = 4;

        // Top-left corner
        ctx.fillRect(this.pixelGhostFrame.x - cornerSize / 2,
            this.pixelGhostFrame.y - cornerSize / 2,
            cornerSize, cornerSize);

        // Top-right corner
        ctx.fillRect(this.pixelGhostFrame.x + this.pixelGhostFrame.width - cornerSize / 2,
            this.pixelGhostFrame.y - cornerSize / 2,
            cornerSize, cornerSize);

        // Bottom-left corner
        ctx.fillRect(this.pixelGhostFrame.x - cornerSize / 2,
            this.pixelGhostFrame.y + this.pixelGhostFrame.height - cornerSize / 2,
            cornerSize, cornerSize);

        // Bottom-right corner
        ctx.fillRect(this.pixelGhostFrame.x + this.pixelGhostFrame.width - cornerSize / 2,
            this.pixelGhostFrame.y + this.pixelGhostFrame.height - cornerSize / 2,
            cornerSize, cornerSize);

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

    // ===== PHASE 3+: REAL-TIME DRAG OPTIMIZATION =====

    updateDragPreview(mouseX, mouseY, daysDelta) {
        const targetRoom = this.findRoomAt(mouseY);
        const currentPosition = { x: mouseX, y: mouseY, room: targetRoom };

        // Skip if position hasn't changed significantly
        const lastPos = this.dragOptimization.lastDragPosition;
        if (Math.abs(currentPosition.x - lastPos.x) < 5 &&
            Math.abs(currentPosition.y - lastPos.y) < 5 &&
            currentPosition.room === lastPos.room) {
            return;
        }

        this.dragOptimization.lastDragPosition = currentPosition;

        // Clear previous affected rooms
        this.dragOptimization.affectedRooms.clear();

        // Add original room
        if (this.dragOriginalData?.room_id) {
            this.dragOptimization.affectedRooms.add(this.dragOriginalData.room_id);
        }

        // Add target room if different
        if (targetRoom && targetRoom.id !== this.dragOriginalData?.room_id) {
            this.dragOptimization.affectedRooms.add(targetRoom.id);
        }

        // Calculate optimal stacking preview for affected rooms
        this.calculateDragStackingPreview(daysDelta, targetRoom);
    }

    calculateDragStackingPreview(daysDelta, targetRoom) {
        const now = new Date();
        now.setHours(0, 0, 0, 0);
        const startDate = new Date(now.getTime() - (14 * 24 * 60 * 60 * 1000));

        // Create temporary reservation position for preview
        let previewReservation = null;

        if (this.dragMode === 'move') {
            const duration = this.dragOriginalData.end.getTime() - this.dragOriginalData.start.getTime();
            const newStart = new Date(this.dragOriginalData.start.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
            const newEnd = new Date(newStart.getTime() + duration);

            previewReservation = {
                ...this.draggedReservation,
                start: newStart,
                end: newEnd,
                room_id: targetRoom ? targetRoom.id : this.dragOriginalData.room_id,
                _isPreview: true
            };
        } else if (this.dragMode === 'resize-start') {
            const newStart = new Date(this.dragOriginalData.start.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
            previewReservation = {
                ...this.draggedReservation,
                start: newStart,
                end: this.dragOriginalData.end,
                _isPreview: true
            };
        } else if (this.dragMode === 'resize-end') {
            const newEnd = new Date(this.dragOriginalData.end.getTime() + (daysDelta * 24 * 60 * 60 * 1000));
            previewReservation = {
                ...this.draggedReservation,
                start: this.dragOriginalData.start,
                end: newEnd,
                _isPreview: true
            };
        }

        if (!previewReservation) return;

        // Calculate optimal stacking for each affected room
        for (const roomId of this.dragOptimization.affectedRooms) {
            this.calculateOptimalStackingForRoom(roomId, previewReservation, startDate);
        }
    }

    calculateOptimalStackingForRoom(roomId, previewReservation, startDate) {
        // Get all reservations for this room (excluding the dragged one)
        const roomReservations = roomDetails.filter(detail => {
            const matchesRoom = detail.room_id === roomId ||
                String(detail.room_id) === String(roomId) ||
                Number(detail.room_id) === Number(roomId);

            // Exclude the currently dragged reservation
            const isDraggedReservation = detail === this.draggedReservation ||
                (detail.id && detail.id === this.draggedReservation.id) ||
                (detail.detail_id && detail.detail_id === this.draggedReservation.detail_id);

            return matchesRoom && !isDraggedReservation;
        });

        // Add preview reservation if it belongs to this room
        if (previewReservation.room_id === roomId) {
            roomReservations.push(previewReservation);
        }

        if (roomReservations.length === 0) {
            this.dragOptimization.previewStacking.set(roomId, {
                reservations: [],
                maxStackLevel: 0,
                roomHeight: 25
            });
            return;
        }

        // Calculate positions and optimal stacking
        const positionedReservations = roomReservations.map(detail => {
            const checkinDate = new Date(detail.start);
            checkinDate.setHours(12, 0, 0, 0);
            const checkoutDate = new Date(detail.end);
            checkoutDate.setHours(12, 0, 0, 0);

            const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
            const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

            const startX = this.sidebarWidth - this.scrollX;
            const left = startX + (startOffset + 0.01) * this.DAY_WIDTH;
            const width = (duration - 0.02) * this.DAY_WIDTH;

            return {
                ...detail,
                left,
                width,
                startOffset,
                duration,
                stackLevel: 0
            };
        }).sort((a, b) => a.startOffset - b.startOffset);

        // OPTIMAL STACKING ALGORITHM - Enhanced for real-time performance
        this.applyOptimalStackingAlgorithm(positionedReservations, roomId);
    }

    applyOptimalStackingAlgorithm(reservations, roomId) {
        const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;
        let maxStackLevel = 0;

        // Enhanced stacking algorithm - finds optimal placement
        reservations.forEach((reservation, index) => {
            let optimalStack = 0;
            let placed = false;

            // Try to find the lowest possible stack level
            while (!placed && optimalStack < 20) { // Max 20 levels for performance
                let canPlaceHere = true;

                // Check for conflicts with all previous reservations
                for (let i = 0; i < index; i++) {
                    const other = reservations[i];
                    if (other.stackLevel === optimalStack) {
                        const reservationEnd = reservation.left + reservation.width;
                        const otherEnd = other.left + other.width;

                        // Check for overlap
                        if (!(reservationEnd <= other.left + OVERLAP_TOLERANCE ||
                            reservation.left >= otherEnd - OVERLAP_TOLERANCE)) {
                            canPlaceHere = false;
                            break;
                        }
                    }
                }

                // Also check forward for better optimization
                if (canPlaceHere && index < reservations.length - 1) {
                    for (let j = index + 1; j < reservations.length; j++) {
                        const future = reservations[j];
                        if (future.stackLevel === optimalStack) {
                            const reservationEnd = reservation.left + reservation.width;
                            const futureEnd = future.left + future.width;

                            if (!(reservationEnd <= future.left + OVERLAP_TOLERANCE ||
                                reservation.left >= futureEnd - OVERLAP_TOLERANCE)) {
                                // Future conflict - try higher level
                                canPlaceHere = false;
                                break;
                            }
                        }
                    }
                }

                if (canPlaceHere) {
                    reservation.stackLevel = optimalStack;
                    maxStackLevel = Math.max(maxStackLevel, optimalStack);
                    placed = true;
                } else {
                    optimalStack++;
                }
            }

            // Fallback if no optimal placement found
            if (!placed) {
                reservation.stackLevel = optimalStack;
                maxStackLevel = Math.max(maxStackLevel, optimalStack);
            }
        });

        // Calculate room height
        const barHeight = this.themeConfig.room.barHeight || 16;
        const roomHeight = Math.max(25, 4 + (maxStackLevel + 1) * (barHeight + 2));

        // Store preview result
        this.dragOptimization.previewStacking.set(roomId, {
            reservations,
            maxStackLevel,
            roomHeight
        });
    }

    updateRoomStackingOptimal() {
        // Phase 3+: Only update affected rooms during drag for optimal performance
        if (this.isDraggingReservation && this.dragOptimization.enabled) {
            // Use preview stacking for affected rooms
            for (const roomId of this.dragOptimization.affectedRooms) {
                const room = rooms.find(r =>
                    r.id === roomId ||
                    String(r.id) === String(roomId) ||
                    Number(r.id) === Number(roomId)
                );

                if (room) {
                    const previewResult = this.dragOptimization.previewStacking.get(roomId);
                    if (previewResult) {
                        // Temporarily update room height for live preview
                        room._dynamicHeight = previewResult.roomHeight;

                        // Apply stacking to visible reservations
                        previewResult.reservations.forEach(reservation => {
                            if (!reservation._isPreview) {
                                // Update position data for rendering
                                this.updateReservationPosition(reservation);
                            }
                        });
                    }
                }

                // Invalidate cache for this room
                this.invalidateStackingCache(roomId);
            }
        } else {
            // Fallback to original method when not dragging
            this.updateRoomStacking();
        }
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
        // Phase 3: Performance monitoring start
        const renderStart = performance.now();

        // Phase 3: Start batch operations for optimized rendering
        this.startBatch();

        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        if (reservations.length === 0) {
            this.renderEmpty();
            return;
        }

        // Initialize data index if not done yet
        if (!this.dataIndex && typeof reservations !== 'undefined' && typeof roomDetails !== 'undefined') {
            this.initializeDataIndex(reservations, roomDetails);
        }

        // Phase 3: Update viewport cache for intelligent culling
        this.updateViewportCache(this.scrollX, this.roomsScrollY);

        // Neue Datums-Logik: now - 2 weeks bis now + 2 years (auf 0 Uhr fixiert)
        const now = new Date();
        now.setHours(0, 0, 0, 0); // Auf Mitternacht (0 Uhr) fixieren
        const startDate = new Date(now.getTime() - (14 * MS_IN_DAY)); // now - 2 weeks
        const endDate = new Date(now.getTime() + (2 * 365 * MS_IN_DAY)); // now + 2 years

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

        // Render critical components immediately (prevent clipping issues)
        this.renderSidebarOptimized(); // Immediate - prevent flickering
        this.renderMenuOptimized(); // Immediate - prevent flickering  
        this.renderHeaderOptimized(startDate, endDate); // Immediate - fix clipping
        this.renderMasterAreaOptimized(startDate, endDate, reservations); // Immediate - fix bars
        this.renderVerticalGridLinesOptimized(startDate, endDate); // Immediate - fix clipping

        // Start batching for remaining components
        this.renderRoomsAreaOptimized(startDate, endDate); // Use optimized version
        this.renderHistogramAreaOptimized(startDate, endDate);
        this.renderSeparatorsOptimized();

        // Phase 3: Execute all batched operations
        this.executeBatch();

        // Ghost-Bar als letztes rendern (über allem) - not batched for immediate feedback
        this.renderGhostBar();

        // Pixel-precise ghost frame - rendered last for immediate mouse feedback
        this.renderPixelGhostFrame();

        // Phase 3: Performance monitoring end
        const renderEnd = performance.now();
        this.performanceStats.renderTime = renderEnd - renderStart;
        this.performanceStats.batchCount = this.renderPipeline.batchOperations.length;
        this.performanceStats.contextSwitches = this.renderPipeline.contextSwitches;

        // Optional: Log performance stats for debugging (can be removed in production)
        if (Math.random() < 0.01) { // Log 1% of renders to avoid spam
            console.log('Phase 3 Performance:', {
                renderTime: this.performanceStats.renderTime.toFixed(2) + 'ms',
                contextSwitches: this.performanceStats.contextSwitches,
                cullingBounds: this.viewportCache.cullingBounds,
                scrollVelocity: this.predictiveCache.scrollVelocity.toFixed(2)
            });
        }
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

    // ===== PHASE 3: OPTIMIZED RENDERING METHODS =====

    renderSidebarOptimized() {
        // Render sidebar immediately (not batched) to prevent flickering
        this.ctx.save();

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


        this.ctx.restore();
    }

    renderMenuOptimized() {
        const area = this.areas.menu;

        // Render menu immediately (not batched) to prevent flickering
        this.ctx.save();

        // Menü-Hintergrund
        this.ctx.fillStyle = this.lightenColor(this.themeConfig.sidebar.bg, 5);
        this.ctx.fillRect(0, area.y, this.canvas.width, area.height);

        // Config-Button im Menü (rechts) - not batched for immediate interaction
        this.renderConfigButtonInMenuOptimized();

        // Menü-Border unten
        this.ctx.strokeStyle = '#ddd';
        this.ctx.lineWidth = 1;
        this.ctx.beginPath();
        this.ctx.moveTo(0, area.y + area.height);
        this.ctx.lineTo(this.canvas.width, area.y + area.height);
        this.ctx.stroke();

        this.ctx.restore();
    }

    renderConfigButtonInMenuOptimized() {
        const area = this.areas.menu;
        const buttonWidth = 60;
        const buttonHeight = 16;
        const buttonX = this.canvas.width - buttonWidth - 5;
        const buttonY = area.y + 2;

        // Render immediately (not batched)
        this.ctx.save();

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
            x: buttonX, y: buttonY, width: buttonWidth, height: buttonHeight
        };

        this.ctx.restore();
    }

    renderHeaderOptimized(startDate, endDate) {
        const area = this.areas.header;
        const startX = this.sidebarWidth - this.scrollX;

        // Render header immediately (not batched) to fix clipping issues
        this.ctx.save();

        // Header-Hintergrund
        this.ctx.fillStyle = this.themeConfig.header.bg;
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING für Header-Bereich
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        this.shadeWeekendColumns(area, startDate, endDate, { barWidth: this.DAY_WIDTH });

        // Datum-Header mit Theme-Konfiguration
        this.ctx.fillStyle = this.themeConfig.header.text;
        this.ctx.font = `${this.themeConfig.header.fontSize}px Arial`;
        this.ctx.textAlign = 'center';

        const currentDate = new Date(startDate);
        let dayIndex = 0;

        while (currentDate <= endDate) {
            const x = startX + (dayIndex * this.DAY_WIDTH) + (this.DAY_WIDTH / 2);

            // Nur rendern wenn im sichtbaren Bereich (ohne aggressive Culling)
            if (x >= this.sidebarWidth - 50 && x <= this.canvas.width + 50) {
                const weekday = currentDate.toLocaleDateString('de-DE', { weekday: 'short' });
                this.ctx.fillText(weekday, x, area.y + 15);

                const dateStr = currentDate.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
                this.ctx.fillText(dateStr, x, area.y + 30);
            }

            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        this.ctx.restore();

        // Header-Border (nach restore, damit es nicht geclippt wird)
        this.ctx.save();
        this.ctx.strokeStyle = '#ddd';
        this.ctx.lineWidth = 2;
        this.ctx.beginPath();
        this.ctx.moveTo(this.sidebarWidth, area.y + area.height);
        this.ctx.lineTo(this.canvas.width, area.y + area.height);
        this.ctx.stroke();
        this.ctx.restore();
    }

    renderMasterAreaOptimized(startDate, endDate, visibleReservations = null) {
        const area = this.areas.master;
        const startX = this.sidebarWidth - this.scrollX;

        // Area-Hintergrund
        this.ctx.save();
        this.ctx.fillStyle = this.themeConfig.master.bg;
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        // CLIPPING für Master-Bereich
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        this.shadeWeekendColumns(area, startDate, endDate, { barWidth: this.DAY_WIDTH });

        // Verwende ALLE Reservierungen für Master-Bereich - KEIN Viewport-Filter!
        const reservationsToRender = reservations;

        // Stack-Algorithmus für Master-Reservierungen (Original-Logik)
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
                return;
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

    renderHistogramAreaOptimized(startDate, endDate) {
        const area = this.areas.histogram;
        const startX = this.sidebarWidth - this.scrollX;

        const histogramTheme = this.themeConfig.histogram || {};
        const backgroundColor = histogramTheme.bg || '#34495e';
        const textColor = histogramTheme.text || '#ecf0f1';
        const fontSize = histogramTheme.fontSize || 9;

        this.ctx.save();
        this.ctx.fillStyle = backgroundColor;
        this.ctx.fillRect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);

        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        const { dailyCounts, dailyDetails, maxGuests } = this.getHistogramData(startDate, endDate);

        if (!dailyCounts || dailyCounts.length === 0) {
            this.ctx.restore();
            return;
        }

        const availableHeight = area.height * 0.9;
        const bottomMargin = area.height * 0.05;
        const barWidth = Math.max(4, this.DAY_WIDTH - 10);
        const categoryColors = histogramTheme.segments || {
            dz: '#1f78ff',
            betten: '#2ecc71',
            lager: '#f1c40f',
            sonder: '#8e44ad'
        };
        const weekendFill = histogramTheme.weekendFill || (this.themeConfig.weekend && this.themeConfig.weekend.fill) || 'rgba(255, 99, 132, 0.08)';
        const gridColor = histogramTheme.gridColor || 'rgba(255,255,255,0.28)';

        const { ticks, niceMax } = this.getHistogramTicks(maxGuests);
        const scaledMax = niceMax > 0 ? niceMax : (maxGuests > 0 ? maxGuests : 1);

        // Shade weekends
        dailyCounts.forEach((_, dayIndex) => {
            const xOffset = (this.DAY_WIDTH - barWidth) / 2;
            const x = startX + (dayIndex * this.DAY_WIDTH) + xOffset;
            if (x + barWidth <= this.sidebarWidth - 100 || x >= this.canvas.width + 100) {
                return;
            }
            const dayDate = new Date(startDate.getTime() + dayIndex * MS_IN_DAY);
            const dayOfWeek = dayDate.getDay();
            if (dayOfWeek === 0 || dayOfWeek === 6) {
                this.ctx.fillStyle = weekendFill;
                this.ctx.globalAlpha = 1;
                this.ctx.fillRect(x, area.y, barWidth, area.height);
            }
        });
        this.ctx.globalAlpha = 1;

        // Grid lines and labels
        this.ctx.setLineDash([4, 4]);
        this.ctx.strokeStyle = gridColor;

        // Erst die Gitterlinien im geclippten Bereich zeichnen
        ticks.forEach(tick => {
            const y = area.y + area.height - bottomMargin - (tick / scaledMax) * availableHeight;
            this.ctx.beginPath();
            this.ctx.moveTo(this.sidebarWidth, y);
            this.ctx.lineTo(this.canvas.width, y);
            this.ctx.stroke();
        });

        // Dann Clipping aufheben und Labels außerhalb zeichnen
        this.ctx.restore();
        this.ctx.save();

        // Labels ohne Clipping zeichnen
        this.ctx.fillStyle = textColor;
        this.ctx.textAlign = 'right';
        this.ctx.textBaseline = 'middle';
        this.ctx.font = `${fontSize}px Arial`;

        ticks.forEach(tick => {
            const y = area.y + area.height - bottomMargin - (tick / scaledMax) * availableHeight;
            this.ctx.fillText(String(tick), this.sidebarWidth - 6, y);
        });

        // Clipping wieder aktivieren für den Rest
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        // Weitere Labels ohne Clipping
        this.ctx.restore();
        this.ctx.save();

        const headerArea = this.areas.header;
        this.ctx.fillStyle = textColor;
        this.ctx.font = `${fontSize}px Arial`;
        this.ctx.textAlign = 'right';
        this.ctx.textBaseline = 'bottom';
        this.ctx.fillText(String(ticks[ticks.length - 1] || scaledMax), this.sidebarWidth - 6, headerArea.y + headerArea.height - 2);
        this.ctx.textBaseline = 'top';
        this.ctx.fillText('0', this.sidebarWidth - 6, area.y + area.height - bottomMargin + 4);

        // Clipping wieder für Balken aktivieren
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        this.ctx.textAlign = 'center';
        this.ctx.textBaseline = 'alphabetic';

        dailyCounts.forEach((count, dayIndex) => {
            const xOffset = (this.DAY_WIDTH - barWidth) / 2;
            const x = startX + (dayIndex * this.DAY_WIDTH) + xOffset;

            if (x + barWidth <= this.sidebarWidth - 100 || x >= this.canvas.width + 100) {
                return;
            }

            const detail = dailyDetails[dayIndex] || { total: count };
            const totalValue = detail.total || 0;
            const ratio = scaledMax > 0 ? totalValue / scaledMax : 0;
            const barHeight = ratio * availableHeight;
            const barY = area.y + area.height - bottomMargin - barHeight;

            let currentTop = barY + barHeight;
            const categoriesInOrder = ['dz', 'betten', 'lager', 'sonder'];

            categoriesInOrder.forEach(key => {
                const value = detail[key] || 0;
                if (!value || value <= 0 || !totalValue) return;

                const segmentRatio = value / totalValue;
                const segmentHeight = segmentRatio * barHeight;
                if (segmentHeight <= 0.5) return;

                const segmentY = currentTop - segmentHeight;
                const segmentColor = categoryColors[key] || this.getHistogramBarColor(totalValue);
                this.ctx.fillStyle = segmentColor;
                this.ctx.globalAlpha = 0.85;
                this.ctx.fillRect(x, segmentY, barWidth, segmentHeight);
                currentTop = segmentY;
            });

            this.ctx.globalAlpha = 1;

            if (barHeight <= 16 || totalValue <= 0) {
                return;
            }

            this.ctx.fillStyle = textColor;
            this.ctx.font = `${fontSize}px Arial`;
            const centerX = x + barWidth / 2;
            let textY = barY + barHeight - 6;

            this.ctx.fillText(String(totalValue), centerX, textY);

            const breakdownLabels = [];
            if (detail.dz) breakdownLabels.push(`DZ:${detail.dz}`);
            if (detail.betten) breakdownLabels.push(`B:${detail.betten}`);
            if (detail.lager) breakdownLabels.push(`L:${detail.lager}`);
            if (detail.sonder) breakdownLabels.push(`S:${detail.sonder}`);

            if (barHeight > 42 && barWidth > 40 && breakdownLabels.length) {
                textY -= 10;
                breakdownLabels.forEach(label => {
                    this.ctx.fillText(label, centerX, textY);
                    textY -= 9;
                });
            }
        });

        this.ctx.restore();
    }
    renderVerticalGridLinesOptimized(startDate, endDate) {
        // Render grid lines immediately (not batched) to fix clipping issues
        this.ctx.save();

        // CLIPPING für Timeline-Bereich (nicht Sidebar)
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, 0, this.canvas.width - this.sidebarWidth, this.canvas.height);
        this.ctx.clip();

        const startX = this.sidebarWidth - this.scrollX;
        const currentDate = new Date(startDate);
        let dayIndex = 0;

        // Sehr dezente Gitterlinien
        this.ctx.strokeStyle = 'rgba(200, 200, 200, 0.25)';
        this.ctx.lineWidth = 1;

        while (currentDate <= endDate) {
            const x = startX + (dayIndex * this.DAY_WIDTH);

            // Nur rendern wenn im sichtbaren Bereich
            if (x >= this.sidebarWidth - 10 && x <= this.canvas.width + 10) {
                this.ctx.beginPath();
                this.ctx.moveTo(x, 0);
                this.ctx.lineTo(x, this.canvas.height);
                this.ctx.stroke();
            }

            currentDate.setDate(currentDate.getDate() + 1);
            dayIndex++;
        }

        this.ctx.restore();
    }

    renderSeparatorsOptimized() {
        const strokeColor = this.isDraggingSeparator ? '#007acc' : '#ddd';
        const lineWidth = this.isDraggingSeparator ? 3 : 1;

        // Oberer Separator (Master/Rooms) - batched
        this.drawOptimizedRect(0, this.separatorY, this.canvas.width, lineWidth,
            null, strokeColor, lineWidth);

        // Unterer Separator (Rooms/Histogram) - batched  
        const bottomStrokeColor = this.isDraggingBottomSeparator ? '#007acc' : '#ddd';
        const bottomLineWidth = this.isDraggingBottomSeparator ? 3 : 1;

        this.drawOptimizedRect(0, this.bottomSeparatorY, this.canvas.width, bottomLineWidth,
            null, bottomStrokeColor, bottomLineWidth);

        // Separator handles are rendered immediately (not batched) for visual feedback
        if (this.isDraggingSeparator) {
            this.ctx.save();
            this.ctx.fillStyle = '#007acc';
            const handleWidth = 20;
            const handleHeight = 4;
            const centerX = this.canvas.width / 2;
            this.ctx.fillRect(centerX - handleWidth / 2, this.separatorY - handleHeight / 2, handleWidth, handleHeight);
            this.ctx.restore();
        }

        if (this.isDraggingBottomSeparator) {
            this.ctx.save();
            this.ctx.fillStyle = '#007acc';
            const handleWidth = 20;
            const handleHeight = 4;
            const centerX = this.canvas.width / 2;
            this.ctx.fillRect(centerX - handleWidth / 2, this.bottomSeparatorY - handleHeight / 2, handleWidth, handleHeight);
            this.ctx.restore();
        }
    }

    renderReservationBarDirect(x, y, width, height, reservation, isHovered) {
        // Direct rendering (not batched) for master bars to ensure proper clipping
        this.ctx.save();

        // Hintergrundfarbe
        this.ctx.fillStyle = this.themeConfig.master.bar;

        // Rounded Rectangle für Reservation
        this.roundedRect(x, y, width, height, 3);
        this.ctx.fill();

        if (isHovered) {
            this.ctx.strokeStyle = '#fff';
            this.ctx.lineWidth = 2;
            this.ctx.stroke();
        }

        // Text rendern wenn Balken breit genug
        if (width > 30) {
            this.ctx.fillStyle = '#fff';
            this.ctx.font = '10px Arial';
            this.ctx.textAlign = 'left';

            let name = reservation.name || reservation.guest_name || 'Unbekannt';
            if (name === 'undefined' || name === undefined || name === null) {
                name = 'Unbekannt';
            }
            const truncatedName = width > 80 ? name : name.substring(0, Math.floor(width / 8));
            this.ctx.fillText(truncatedName, x + 4, y + height - 3);
        }

        this.ctx.restore();
    }

    renderReservationBarOptimized(x, y, width, height, reservation, isHovered) {
        // Use batched rendering for reservation bars
        const fillColor = this.themeConfig.master.bar;
        const strokeColor = isHovered ? '#fff' : null;

        this.drawOptimizedRect(x, y, width, height, fillColor, strokeColor, 1);

        // Render text if bar is wide enough and visible
        if (width > 50 && this.isItemInViewport(x, y, width, height)) {
            let name = reservation.name || reservation.guest_name || 'Unbekannt';
            if (name === 'undefined' || name === undefined || name === null) {
                name = 'Unbekannt';
            }
            this.drawOptimizedText(name, x + 4, y + height - 3,
                '10px Arial', '#fff', 'left');
        }
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

            // Render Reservierungen - Ghost-Reservierungen werden NIEMALS sichtbar gerendert
            sortedReservations.forEach(reservation => {
                // Skip ghost reservations KOMPLETT - sie sind nur für Stacking-Berechnung
                if (reservation._isGhost) {
                    return; // Keine Sichtbarkeit für Ghost-Reservierungen
                }

                const stackY = baseRoomY + 1 + (reservation.stackLevel * (this.ROOM_BAR_HEIGHT + 2));
                const isHovered = this.isReservationHovered(reservation.left, stackY, reservation.width, this.ROOM_BAR_HEIGHT);

                this.renderRoomReservationBar(reservation.left, stackY, reservation.width, this.ROOM_BAR_HEIGHT, reservation, isHovered);

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

        this.renderRoomDayGridLines(startDate, endDate, area);

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

            this.shadeWeekendColumns({ y: baseRoomY, height: roomHeight }, startDate, endDate, { barWidth: this.DAY_WIDTH, offsetY: baseRoomY, height: roomHeight });

            // Render Reservierungen - Ghost-Reservierungen werden NIEMALS sichtbar gerendert  
            sortedReservations.forEach(reservation => {
                // Skip ghost reservations KOMPLETT - sie sind nur für Stacking-Berechnung
                if (reservation._isGhost) {
                    return; // Keine Sichtbarkeit für Ghost-Reservierungen
                }

                const barHeight = this.ROOM_BAR_HEIGHT; // Verwende dynamische Balkenhöhe
                const stackY = baseRoomY + 1 + (reservation.stackLevel * (this.ROOM_BAR_HEIGHT + 2));
                const isHovered = this.isReservationHovered(reservation.left, stackY, reservation.width, this.ROOM_BAR_HEIGHT);

                this.renderRoomReservationBar(reservation.left, stackY, reservation.width, this.ROOM_BAR_HEIGHT, reservation, isHovered);

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

        this.renderRoomDayGridLines(startDate, endDate, area);

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
        // Master-Bereich Balken werden normal gerendert (kein Glow hier)

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
        // Ghost-Reservierungen werden NIEMALS sichtbar gerendert - nur für Stacking
        if (detail._isGhost) {
            return; // Komplette Verweigerung der Sichtbarkeit
        }

        // Check if this is the source of a drag operation (show strong glow around original bar)
        // ECHTE ID-BASIERTE Lösung: Verwende die echten Datenbank-IDs vom Backend
        const getRealId = (detail) => {
            // Für Room Details: 'room_detail_' + detail_id (aus PHP Backend)
            if (detail.id && detail.id.startsWith('room_detail_')) {
                return detail.id;
            }
            // Für Master Reservations: 'res_' + id (aus PHP Backend)
            if (detail.id && detail.id.startsWith('res_')) {
                return detail.id;
            }
            // Fallback: detail_id aus data object
            if (detail.data && detail.data.detail_id) {
                return 'room_detail_' + detail.data.detail_id;
            }
            // Fallback: res_id aus data object
            if (detail.data && detail.data.id) {
                return 'res_' + detail.data.id;
            }
            // Notfall-Fallback (sollte nie auftreten bei korrekten Backend-Daten)
            console.warn('Keine echte ID gefunden für Detail:', detail);
            return 'unknown_' + Math.random();
        };

        const currentId = getRealId(detail);
        let draggedId = null;

        if (this.isDraggingReservation && this.draggedReservationReference) {
            draggedId = getRealId(this.draggedReservationReference);
        }

        const isSourceOfDrag = this.isDraggingReservation &&
            draggedId &&
            draggedId === currentId;

        // Verwende Theme-Standard-Farbe wenn keine spezifische Farbe gesetzt
        let color = detail.color || this.themeConfig.room.bar;

        // ENHANCED APPEARANCE für den EXAKTEN gedragten Balken
        if (isSourceOfDrag) {
            // Balken weiß machen für maximale Sichtbarkeit
            color = '#ffffff';
        }

        // Drag & Drop visuelles Feedback
        const isDropTarget = this.isDraggingReservation && this.dragMode === 'move' &&
            this.dragTargetRoom && this.dragTargetRoom.id !== this.dragOriginalData?.room_id;

        if (isHovered) {
            color = this.lightenColor(color, 15);
        }

        this.ctx.save();

        // MASSIVE visuelle Verstärkung wenn dieser Balken gedraggt wird
        if (isSourceOfDrag) {
            const glowRadius = 25; // Noch viel größeres Leuchten
            const glowColor = '#ffffff'; // Weißes Leuchten

            this.ctx.shadowColor = glowColor;
            this.ctx.shadowBlur = glowRadius;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
            this.ctx.globalAlpha = 1.0; // Volle Sichtbarkeit
        }

        // Hover-Shadow falls nicht gedraggt
        if (isHovered && !isSourceOfDrag) {
            this.ctx.shadowColor = 'rgba(0,0,0,0.2)';
            this.ctx.shadowBlur = 3;
            this.ctx.shadowOffsetX = 1;
            this.ctx.shadowOffsetY = 1;
        }

        this.ctx.fillStyle = color;

        // Balken vergrößern: NUR 4px für Hover (Drag-Source behält Originalgröße)
        let renderX = x;
        let renderY = y;
        let renderWidth = width;
        let renderHeight = height;

        if (isHovered && !isSourceOfDrag) {
            // 4 Pixel für Hover (nur wenn NICHT gedraggt wird)
            renderX = x - 4;
            renderY = y - 4;
            renderWidth = width + 8;
            renderHeight = height + 8;
        }
        // isSourceOfDrag behält Originalgröße, wird nur heller und glüht

        this.roundedRect(renderX, renderY, renderWidth, renderHeight, 3);
        this.ctx.fill();

        // Shadow reset nach dem Balken-Rendering
        if (isSourceOfDrag) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.globalAlpha = 1.0; // Alpha zurücksetzen
        }

        // Resize-Handles bei Hover oder Drag - angepasst für vergrößerten Balken
        if (isHovered && renderWidth > 20) {
            const handleWidth = 4;
            const handleColor = 'rgba(255,255,255,0.8)';

            // Start-Handle (links)
            this.ctx.fillStyle = handleColor;
            this.ctx.fillRect(renderX, renderY, handleWidth, renderHeight);

            // End-Handle (rechts)
            this.ctx.fillRect(renderX + renderWidth - handleWidth, renderY, handleWidth, renderHeight);
        }

        // Shadow reset nach Handles
        if (isHovered && !isSourceOfDrag) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        // Border - auch für vergrößerten Balken
        this.ctx.strokeStyle = isHovered ? 'rgba(0,0,0,0.3)' : 'rgba(0,0,0,0.1)';
        this.ctx.lineWidth = 1;
        this.ctx.stroke();

        if (renderWidth > 12) {
            const textColor = this.getContrastColor(color);
            this.ctx.fillStyle = textColor;

            const baseFontSize = (this.themeConfig?.room?.fontSize) || 12;
            const dynamicFontSize = Math.max(8, Math.min(baseFontSize, renderHeight - 2));
            this.ctx.font = `${dynamicFontSize}px Arial`;
            this.ctx.textAlign = 'left';
            this.ctx.textBaseline = 'alphabetic';

            let text = this.getDetailCaption(detail);
            if (detail.has_dog) {
                text = `${text} 🐕`;
            }

            const arrangementRaw = (detail.arrangement_label || detail.data?.arrangement || detail.data?.arrangement_kbez || '').trim();
            const arrangementLetterMatch = arrangementRaw.match(/[A-Za-zÄÖÜäöü]/);
            const arrangementLetter = arrangementLetterMatch ? arrangementLetterMatch[0].toUpperCase() : null;
            const hasCircle = Boolean(arrangementLetter);
            const circleDiameter = hasCircle ? Math.max(12, Math.min(renderHeight - 4, 18)) : 0;
            const circlePadding = hasCircle ? circleDiameter + 6 : 0;

            const availableWidth = renderWidth - 8 - circlePadding;
            if (availableWidth > 0) {
                const truncated = this.truncateTextToWidth(text, availableWidth);
                const textY = renderY + (renderHeight / 2) + (dynamicFontSize / 3);
                this.ctx.fillText(truncated, renderX + 3, textY);
            }

            if (hasCircle && renderWidth > circleDiameter + 10) {
                const circleX = renderX + renderWidth - (circleDiameter / 2) - 3;
                const circleY = renderY + renderHeight / 2;
                const avId = detail.av_id ?? detail.data?.av_id ?? 0;
                const circleFill = avId > 0 ? '#2ecc71' : '#bdc3c7';
                const circleStroke = avId > 0 ? '#1e8449' : '#95a5a6';

                this.ctx.beginPath();
                this.ctx.fillStyle = circleFill;
                this.ctx.strokeStyle = circleStroke;
                this.ctx.lineWidth = 1;
                this.ctx.arc(circleX, circleY, circleDiameter / 2, 0, Math.PI * 2);
                this.ctx.fill();
                this.ctx.stroke();

                this.ctx.fillStyle = avId > 0 ? '#ffffff' : '#2d3436';
                this.ctx.font = `${Math.max(8, circleDiameter * 0.55)}px Arial`;
                this.ctx.textAlign = 'center';
                this.ctx.textBaseline = 'middle';
                this.ctx.fillText(arrangementLetter, circleX, circleY + 0.5);
                this.ctx.textAlign = 'left';
                this.ctx.textBaseline = 'alphabetic';
            }
        }

        this.ctx.restore();
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

    // Public API
    updateData(newReservations, newRoomDetails, newRooms) {
        reservations = newReservations || [];
        roomDetails = newRoomDetails || [];
        rooms = newRooms || [];

        this.setArrangementsCatalog(arrangementsCatalog);
        this.setHistogramSource(histogramSourceData);

        this.updateRoomLookups();
        this.normalizeRoomDetails();
        this.invalidateStackingCache();
        this.markDataDirty();

        // Verwende festen Datumsbereich: now - 2 weeks bis now + 2 years (auf 0 Uhr fixiert)
        const now = new Date();
        now.setHours(0, 0, 0, 0); // Auf Mitternacht (0 Uhr) fixieren
        this.startDate = new Date(now.getTime() - (14 * MS_IN_DAY));
        this.endDate = new Date(now.getTime() + (2 * 365 * MS_IN_DAY));

        this.render();
    }

    updateMenuSize(newSize) {
        if (!newSize || newSize < 120 || newSize > 320) {
            console.warn('Invalid menu size:', newSize);
            return;
        }

        // Update the global size variable if it exists
        if (typeof window.TIMELINE_RADIAL_MENU_SIZE !== 'undefined') {
            window.TIMELINE_RADIAL_MENU_SIZE = newSize;
        }

        // Update the DOM element size
        const radialRoot = document.getElementById('timeline-radial-menu');
        if (radialRoot) {
            radialRoot.style.width = `${newSize}px`;
            radialRoot.style.height = `${newSize}px`;
            radialRoot.style.marginLeft = `-${newSize / 2}px`;
            radialRoot.style.marginTop = `-${newSize / 2}px`;

            // Update the radial menu instance if it exists
            if (this.radialMenu && this.radialMenu.updateSize) {
                this.radialMenu.updateSize(newSize);
            }
        }
    }
}

// Export
window.TimelineUnifiedRenderer = TimelineUnifiedRenderer;
