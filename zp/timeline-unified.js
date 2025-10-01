// TIMELINE UNIFIED RENDERER - Canvas-basierte Timeline
let reservations = [];
let roomDetails = [];
let rooms = [];
let arrangementsCatalog = typeof window !== 'undefined' && window.arrangementsCatalog ? window.arrangementsCatalog : [];
let histogramSourceData = typeof window !== 'undefined' && window.histogramSource ? window.histogramSource : [];
let histogramStornoSourceData = typeof window !== 'undefined' && window.histogramStornoSource ? window.histogramStornoSource : [];
if (typeof window !== 'undefined') {
    window.arrangementsCatalog = arrangementsCatalog;
    window.histogramSource = histogramSourceData;
    window.histogramStornoSource = histogramStornoSourceData;

    // Initialize debug mode from sessionStorage
    if (sessionStorage.getItem('debugStickyNotes') === 'true') {
        window.debugStickyNotes = true;
        console.log('🔧 Sticky Notes Debug Mode ENABLED');
    }
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
        // Einheitlicher Radius des Zentrumskreises (wird auch für Ring-Start verwendet)
        this.centerButtonRadius = Math.max(12, Math.round((this.centerRadius - 4) * 2 / 3));
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
        // Innerer Kreis-Radius entspricht dem gespeicherten centerButtonRadius
        circle.setAttribute('r', this.centerButtonRadius);
        circle.setAttribute('fill', '#313131');
        circle.setAttribute('class', 'center-button');

        circle.addEventListener('click', (event) => {
            event.stopPropagation();
            this.hide();
            this.callbacks.onClose?.();
        });

        const line1 = document.createElementNS(svgNS, 'line');
        // X im Zentrum skaliert relativ zum Zentrumskreis
        const crossRadius = Math.max(6, Math.round(this.centerButtonRadius * 0.6));
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
        const base = this.centerButtonRadius;
        const step = this.ringThickness + this.ringGap;

        // First ring starts at centerButtonRadius and is thicker (fills one full step)
        if (safeLevel === 0) {
            const innerRadius = base;
            const outerRadius = innerRadius + step; // thicker first ring to remove gap to next
            return { innerRadius, outerRadius };
        }

        // Subsequent rings: start after first ring (no extra gap between 1 and 2)
        const innerRadius = base + safeLevel * step;
        const outerRadius = innerRadius + this.ringThickness;
        return { innerRadius, outerRadius };
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

        // Animation: Zeige das Menü sofort für Animation
        this.root.style.display = 'block';
        this.root.style.pointerEvents = 'auto';

        // Force reflow für korrekte Animation
        this.root.offsetHeight;

        // Trigger Animation
        this.root.classList.add('active');
        this.isOpen = true;
    }

    hide() {
        if (!this.root || !this.isOpen) return;

        this.root.classList.remove('active');
        this.root.classList.add('hiding');

        // Nach Animation ausblenden
        setTimeout(() => {
            if (this.root) {
                this.root.classList.remove('hiding');
                this.root.style.display = 'none';
                this.root.style.pointerEvents = 'none';
                this.clear();
            }
        }, 500); // 0.5 Sekunden warten

        this.isOpen = false;
        this.activeDetail = null;
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
        this.dragAxisLock = null; // 'horizontal' oder 'vertical'
        this.dragAxisLockThreshold = 12; // Pixel-Schwelle bis Achse fixiert wird
        this.dragRoomSwitchThreshold = 28; // Mindestabstand in px bevor Zimmerwechsel greift

        // Drag & Drop für Sticky Notes
        this.isDraggingStickyNote = false;
        this.draggedStickyNote = null;
        this.stickyNoteDragStartX = 0;
        this.stickyNoteDragStartY = 0;
        this.stickyNoteOffsetX = 0;
        this.stickyNoteOffsetY = 0;
        this.stickyNoteBounds = []; // Array of sticky note bounds for click detection

        // Animation properties for smooth sticky note movement
        this.stickyNoteAnimationFrame = null;
        this.animatingNotes = new Map(); // detail_id -> {startTime, startX, startY, targetX, targetY, duration}

        // Ghost rectangle for sticky note dragging preview
        this.stickyNoteGhost = null; // {x, y, width, height, visible}

        // Collection of sticky notes to render on top (Z-order)
        this.stickyNotesQueue = []; // Array of {barX, barY, barWidth, barHeight, detail}

        // Smooth animation helper for row height transitions
        this.roomHeightAnimations = new Map(); // roomId -> {from, to, start, duration, current}

        // Performance optimization for sticky notes
        this.stickyNotesCache = new Map(); // Cache rendered sticky notes
        this.lastStickyNotesRender = 0; // Timestamp of last sticky notes render
        this.stickyNotesRenderThreshold = 100; // Only re-render sticky notes every 100ms        // Ghost-Balken für Drag-Feedback
        this.ghostBar = null; // { x, y, width, height, room, mode, visible }
        this.pixelGhostFrame = null; // Pixelgenauer Rahmen der mit Maus mitfährt

        // Touch-optimierte Fokussteuerung für Zimmer-Balken
        this.focusedReservationKey = null;
        this.focusedReservationAt = 0;
        this.focusedReservationSource = null;

        // Separator-Positionen aus Cookies laden oder Defaults setzen
        this.separatorY = this.loadFromCookie('separatorTop', 270);
        this.bottomSeparatorY = this.loadFromCookie('separatorBottom', 820);

        // Layout-Bereiche (dynamisch) - Header wieder hinzugefügt + Menü
        const menuHeight = 30;
        const headerHeight = 40;
        const masterHeight = 200;
        const roomsHeight = 550;
        const histogramHeight = 160;

        this.areas = {
            menu: { height: menuHeight, y: 0 },
            header: { height: headerHeight, y: menuHeight },
            master: { height: masterHeight, y: menuHeight + headerHeight },
            rooms: { height: roomsHeight, y: menuHeight + headerHeight + masterHeight },
            histogram: { height: histogramHeight, y: menuHeight + headerHeight + masterHeight + roomsHeight }
        };

        this.totalHeight = menuHeight + headerHeight + masterHeight + roomsHeight + histogramHeight;
        this.topSeparatorRatio = this.separatorY / this.totalHeight;
        this.bottomSeparatorRatio = this.bottomSeparatorY / this.totalHeight;
        this.histogramPreferredHeight = histogramHeight;
        this.histogramFooterPadding = 10;

        // Navigation Overview (Minimap) State
        this.navCanvas = null;
        this.navCtx = null;
        this.navHeight = menuHeight;
        this.navDevicePixelRatio = window.devicePixelRatio || 1;
        this.navResizeObserver = null;
        this.navPointerHandlers = null;
        this.navIsDragging = false;
        this.navDragOffsetMs = 0;
        this.navActivePointerId = null;
        this.navWindowResizeHandler = null;
        this.sidebarWidth = 80;

        // Timeline-Konstanten
        this.DAY_WIDTH = 90; // Breite eines Tages in Pixeln - wird von Theme überschrieben

        // Dynamische Zimmer-Balkenhöhe
        this.ROOM_BAR_HEIGHT = 16; // Standard Balkenhöhe (10-35px)
        this.MASTER_BAR_HEIGHT = this.ROOM_BAR_HEIGHT; // Initial Master Bar Height
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

        this.rootContainer = null;
        this.lastViewportHeightPx = null;
        this.boundWindowResizeHandler = null;
        this.boundOrientationChangeHandler = null;
        this.visualViewportResizeHandler = null;
        this.pendingViewportResize = false;
        this.pendingViewportResizeReason = null;
        this.forceHistogramLayout = true;

        let disposedToggle = false;
        if (typeof window !== 'undefined') {
            if (typeof window.TIMELINE_SHOW_DISPOSED_MASTER !== 'undefined') {
                disposedToggle = Boolean(window.TIMELINE_SHOW_DISPOSED_MASTER);
            } else if (typeof window.timelineShowDisposedMaster !== 'undefined') {
                disposedToggle = Boolean(window.timelineShowDisposedMaster);
            }
        }
        this.showDisposedMasterReservations = disposedToggle;

        this.arrangementsCatalog = Array.isArray(arrangementsCatalog) ? arrangementsCatalog : [];
        this.histogramSource = Array.isArray(histogramSourceData) ? histogramSourceData : [];
        this.histogramStornoSource = Array.isArray(histogramStornoSourceData) ? histogramStornoSourceData : [];

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
            lastDragPosition: { x: 0, y: 0, room: null },
            roomBaselineHeights: new Map()
        };

        // Cached media assets
        this._cautionImage = null;
        this._cautionImageLoaded = false;
        this._cautionImageError = false;

        // Helper flag for initial scroll-to-today
        this._scrolledToTodayOnce = false;

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

        this.touchPointers = new Map();
        this.isPinchZoom = false;
        this.isPinchBarResize = false;
        this.pinchStartDistance = 0;
        this.pinchStartDayWidth = 0;
        this.pinchFocusDayOffset = 0;
        this.pinchCenterCanvasX = 0;
        this.pinchBarStartDistance = 0;
        this.pinchBarStartHeight = 0;

        // Referenzen auf Scroll-Container für Synchronisation
        this.horizontalTrack = null;
        this.masterTrack = null;
        this.roomsTrack = null;

        // Datensynchronisation & Caches
        this.dataVersion = 0;
        this.histogramCache = null;
        this.capacityMismatchCache = null;
        this.roomsById = new Map();
        this.roomCategoryCache = new Map();

        // Kontextmenüs & Datumsstatus
        this.dateMenuEl = null;
        this.dateMenuContext = null;
        this.currentRange = null;

        // Theme-Konfiguration laden
        this.themeConfig = this.loadThemeConfiguration();
        const themeDayWidth = typeof this.themeConfig.dayWidth === 'number' ? this.themeConfig.dayWidth : 90;
        this.DAY_WIDTH = Math.max(40, Math.min(250, themeDayWidth));
        const themeRoomBarHeight = this.themeConfig.room?.barHeight;
        const themeMasterBarHeight = this.themeConfig.master?.barHeight;
        const initialBarHeight = Math.max(10, Math.min(35, typeof themeRoomBarHeight === 'number'
            ? themeRoomBarHeight
            : (typeof themeMasterBarHeight === 'number' ? themeMasterBarHeight : 16)));
        this.ROOM_BAR_HEIGHT = initialBarHeight;
        this.MASTER_BAR_HEIGHT = initialBarHeight;

        this.themeConfig.sidebar = this.themeConfig.sidebar || {};
        this.sidebarFontSize = Math.max(9, Math.min(20,
            this.themeConfig.sidebar.fontSize || this.computeBarFontSize(this.ROOM_BAR_HEIGHT)));
        this.themeConfig.sidebar.fontSize = this.sidebarFontSize;
        this.sidebarMetricsDirty = true;

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

        // Create Master Bar Context Menu (DOM-based)
        this.ensureMasterContextMenu();

        this.applyPersistentSizingFromStorage({ skipRecalculate: true });

        this.init();
    }

    // Unified helper: compute timeline date range based on themeConfig weeksPast/weeksFuture
    getTimelineDateRange() {
        const now = new Date();
        now.setHours(0, 0, 0, 0);
        const weeksPast = this.themeConfig?.weeksPast ?? 2;
        const weeksFuture = this.themeConfig?.weeksFuture ?? 104;
        const startDate = new Date(now.getTime() - (weeksPast * 7 * MS_IN_DAY));
        const endDate = new Date(now.getTime() + (weeksFuture * 7 * MS_IN_DAY));
        return { now, startDate, endDate, weeksPast, weeksFuture };
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

        return reservations
            .filter(reservation => this.isMasterReservationVisible(reservation))
            .filter(reservation => {
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

        const { startDate, endDate } = this.getTimelineDateRange();

        // Aktualisiere alle Zimmer-Höhen
        for (const room of rooms) {
            const stackingResult = this.getStackingForRoom(room.id, startDate, endDate);
            const resolvedHeight = this.applyRoomHeightAnimation(room, stackingResult.roomHeight, { animate: false });
            room._dynamicHeight = resolvedHeight;
        }

        if (typeof window !== 'undefined' && window.debugTimeline) {
            console.log('Zimmer-Höhen neu berechnet für ROOM_BAR_HEIGHT:', this.ROOM_BAR_HEIGHT);
        }
    }

    easeOutCubic(t) {
        return 1 - Math.pow(1 - t, 3);
    }

    applyRoomHeightAnimation(room, targetHeight, { animate = true } = {}) {
        if (!room || room.id === undefined || room.id === null) {
            return targetHeight;
        }

        const roomKey = String(room.id);

        if (!animate) {
            this.roomHeightAnimations.delete(roomKey);
            return targetHeight;
        }

        const now = performance.now();
        let animation = this.roomHeightAnimations.get(roomKey);
        const currentHeight = animation?.current ?? room._dynamicHeight ?? targetHeight ?? 25;

        if (!animation || Math.abs(animation.to - targetHeight) > 0.5) {
            animation = {
                from: currentHeight,
                to: targetHeight,
                start: now,
                duration: 220,
                current: currentHeight
            };
            this.roomHeightAnimations.set(roomKey, animation);
        } else {
            animation.to = targetHeight;
        }

        const elapsed = now - animation.start;
        const progress = Math.max(0, Math.min(1, elapsed / animation.duration));
        const eased = this.easeOutCubic(progress);
        const animatedHeight = animation.from + (animation.to - animation.from) * eased;
        animation.current = animatedHeight;

        if (progress >= 1 || Math.abs(animatedHeight - animation.to) < 0.5) {
            this.roomHeightAnimations.delete(roomKey);
            return animation.to;
        }

        this.scheduleRender('room_height_animation');
        return animatedHeight;
    }

    updateRoomLookups() {
        this.roomsById = new Map();
        this.roomCategoryCache = new Map();

        (rooms || []).forEach(room => {
            if (!room || room.id === undefined || room.id === null) return;
            const key = String(room.id);
            this.roomsById.set(key, room);
        });

        this.sidebarMetricsDirty = true;
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

    normalizeDateForComparison(value) {
        if (value === undefined || value === null) {
            return null;
        }

        let date = null;

        if (value instanceof Date) {
            date = new Date(value.getTime());
        } else if (typeof value === 'number') {
            if (!Number.isFinite(value)) {
                return null;
            }
            date = new Date(value);
        } else if (typeof value === 'string') {
            const trimmed = value.trim();
            if (!trimmed || /^0{4}/.test(trimmed)) {
                return null;
            }

            date = new Date(trimmed);
            if (Number.isNaN(date.getTime())) {
                const match = trimmed.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
                if (match) {
                    const day = parseInt(match[1], 10);
                    const month = parseInt(match[2], 10) - 1;
                    const year = parseInt(match[3], 10);
                    date = new Date(year, month, day, 12, 0, 0, 0);
                }
            }
        }

        if (!date || Number.isNaN(date.getTime())) {
            return null;
        }

        date.setHours(12, 0, 0, 0);
        return date.getTime();
    }

    extractFirstDateFromSources(sources, candidateNames) {
        if (!Array.isArray(sources) || sources.length === 0 || !Array.isArray(candidateNames) || candidateNames.length === 0) {
            return null;
        }

        const normalizedCandidates = candidateNames
            .filter(name => typeof name === 'string' && name.length > 0)
            .map(name => name.toLowerCase());

        for (const source of sources) {
            if (!source || typeof source !== 'object') {
                continue;
            }

            for (const [key, value] of Object.entries(source)) {
                if (typeof key !== 'string') {
                    continue;
                }

                if (!normalizedCandidates.includes(key.toLowerCase())) {
                    continue;
                }

                const normalized = this.normalizeDateForComparison(value);
                if (normalized !== null) {
                    return normalized;
                }
            }
        }

        return null;
    }

    extractMasterStayBounds(reservation) {
        if (!reservation) {
            return { start: null, end: null };
        }

        const sources = [reservation];
        if (reservation.fullData && typeof reservation.fullData === 'object') {
            sources.push(reservation.fullData);
        }
        if (reservation.data && typeof reservation.data === 'object') {
            sources.push(reservation.data);
        }

        const startCandidates = [
            'start', 'start_date', 'startdate', 'anreise', 'arrival', 'arr_date', 'ankunft',
            'von', 'checkin', 'check_in', 'master_start', 'start_at', 'date_from', 'begin'
        ];
        const endCandidates = [
            'end', 'end_date', 'enddate', 'abreise', 'departure', 'dep_date', 'ende',
            'bis', 'checkout', 'check_out', 'master_end', 'end_at', 'date_to', 'finish'
        ];

        const start = this.extractFirstDateFromSources(sources, startCandidates);
        const end = this.extractFirstDateFromSources(sources, endCandidates);

        return { start, end };
    }

    evaluateDetailDateAlignment(detailStatsEntry, masterStay) {
        const result = {
            masterStart: masterStay?.start ?? null,
            masterEnd: masterStay?.end ?? null,
            detailStart: null,
            detailEnd: null,
            startMismatch: false,
            endMismatch: false,
            dateMismatch: false,
            usedLinkedGroups: false,
            comparedGroupCount: 0,
            hasDetailDates: false
        };

        if (!detailStatsEntry || !detailStatsEntry.groups || detailStatsEntry.groups.size === 0) {
            return result;
        }

        const groups = Array.from(detailStatsEntry.groups.values()).filter(group =>
            group && group.entries && group.entries.length > 0 && group.hasDateData
        );

        if (groups.length === 0) {
            return result;
        }

        const linkedGroups = groups.filter(group => group.parentId !== null);
        const groupsForComparison = linkedGroups.length > 0 ? linkedGroups : groups;
        result.usedLinkedGroups = linkedGroups.length > 0;
        result.comparedGroupCount = groupsForComparison.length;

        const masterStartKey = result.masterStart !== null ? this.getDateKey(new Date(result.masterStart)) : null;
        const masterEndKey = result.masterEnd !== null ? this.getDateKey(new Date(result.masterEnd)) : null;

        let detailStartKey = null;
        let detailEndKey = null;
        let startMismatch = false;
        let endMismatch = false;

        groupsForComparison.forEach(group => {
            if (!group) {
                return;
            }

            const groupStartMs = group.earliestStart ?? null;
            const groupEndMs = group.latestEnd ?? null;

            if (groupStartMs !== null) {
                const groupStartKey = this.getDateKey(new Date(groupStartMs));
                result.detailStart = result.detailStart === null ? groupStartMs : Math.min(result.detailStart, groupStartMs);
                detailStartKey = detailStartKey === null ? groupStartKey : Math.min(detailStartKey, groupStartKey);

                if (masterStartKey !== null && groupStartKey !== masterStartKey) {
                    startMismatch = true;
                }
            }

            if (groupEndMs !== null) {
                const groupEndKey = this.getDateKey(new Date(groupEndMs));
                result.detailEnd = result.detailEnd === null ? groupEndMs : Math.max(result.detailEnd, groupEndMs);
                detailEndKey = detailEndKey === null ? groupEndKey : Math.max(detailEndKey, groupEndKey);

                if (masterEndKey !== null && groupEndKey !== masterEndKey) {
                    endMismatch = true;
                }
            }
        });

        result.hasDetailDates = result.detailStart !== null || result.detailEnd !== null;
        result.startMismatch = Boolean(startMismatch && masterStartKey !== null && detailStartKey !== null);
        result.endMismatch = Boolean(endMismatch && masterEndKey !== null && detailEndKey !== null);
        result.dateMismatch = result.startMismatch || result.endMismatch;

        return result;
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

    getRoomCapacityValue(room) {
        if (!room) {
            return null;
        }

        const candidates = [
            room.capacity,
            room.max_capacity,
            room.total_capacity,
            room.data && room.data.capacity
        ];

        for (const candidate of candidates) {
            if (candidate === undefined || candidate === null) {
                continue;
            }
            const numeric = Number(candidate);
            if (Number.isFinite(numeric) && numeric > 0) {
                return numeric;
            }
        }

        return null;
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
        this.capacityMismatchCache = null;
        this.dataIndex = null;
    }

    finalizeRoomDetailMutation(removedDetails = [], options = {}) {
        const affectedRooms = new Set();

        removedDetails.forEach(detail => {
            if (!detail) {
                return;
            }

            const detailId = detail.detail_id
                ?? detail.detailId
                ?? detail.data?.detail_id
                ?? detail.ID
                ?? detail.id;
            if (detailId && this.stickyNotesCache && this.stickyNotesCache.size) {
                this.stickyNotesCache.delete(detailId);
            }

            const roomCandidates = [
                detail.room_id,
                detail.roomId,
                detail.data?.room_id,
                detail.data?.roomid,
                detail.data?.zimmer_id,
                detail.zimmer_id,
                detail.zimmerid
            ];

            for (const candidate of roomCandidates) {
                if (candidate !== undefined && candidate !== null) {
                    affectedRooms.add(candidate);
                    break;
                }
            }
        });

        this.markDataDirty();

        if (affectedRooms.size > 0) {
            affectedRooms.forEach(roomId => this.invalidateStackingCache(roomId));
        } else {
            this.invalidateStackingCache();
        }

        this.recalculateRoomHeights();
        this.updateViewportCache(this.scrollX, this.roomsScrollY);
        this.updateNavigationOverview();

        if (this.radialMenu?.isVisible()) {
            this.radialMenu.hide();
        }

        this.scheduleRender(options.reason || 'room_detail_mutation');
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

    getReservationFocusKey(detail) {
        if (!detail) {
            return null;
        }

        const identifiers = this.extractDetailIdentifiers(detail) || {};

        if (identifiers.uniqueId) {
            return `uid:${identifiers.uniqueId}`;
        }
        if (identifiers.detailId !== null && identifiers.detailId !== undefined) {
            return `detail:${identifiers.detailId}`;
        }
        if (identifiers.resId !== null && identifiers.resId !== undefined) {
            return `res:${identifiers.resId}`;
        }
        if (detail.id) {
            return `id:${detail.id}`;
        }
        return null;
    }

    setFocusedReservation(detail, options = {}) {
        const { source = 'rooms', silent = false } = options;
        const key = this.getReservationFocusKey(detail);
        if (!key) {
            return false;
        }

        const hasChanged = this.focusedReservationKey !== key || this.focusedReservationSource !== source;
        this.focusedReservationKey = key;
        this.focusedReservationSource = source;
        this.focusedReservationAt = Date.now();

        if (hasChanged && !silent) {
            this.scheduleRender('reservation_focus_set');
        }

        return hasChanged;
    }

    clearReservationFocus(options = {}) {
        const { reason = 'reservation_focus_clear', silent = false } = options || {};
        if (!this.focusedReservationKey) {
            return false;
        }

        this.focusedReservationKey = null;
        this.focusedReservationSource = null;
        this.focusedReservationAt = 0;

        if (!silent) {
            this.scheduleRender(reason);
        }

        return true;
    }

    isReservationFocused(detail) {
        const key = this.getReservationFocusKey(detail);
        return Boolean(key && key === this.focusedReservationKey);
    }

    isTouchLikePointer(event) {
        if (!event) {
            return false;
        }

        if (typeof event.pointerType === 'string') {
            if (event.pointerType === 'touch') {
                return true;
            }
            if (event.pointerType === 'pen') {
                if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
                    return window.matchMedia('(pointer: coarse)').matches;
                }
            }
            return false;
        }
        if (typeof event.type === 'string') {
            const lowered = event.type.toLowerCase();
            if (lowered.includes('touch')) {
                return true;
            }
        }

        if (typeof event.changedTouches !== 'undefined') {
            return true;
        }

        return false;
    }

    normalizeReservationId(resId) {
        if (resId === undefined || resId === null) {
            return null;
        }

        if (typeof resId === 'number' && Number.isFinite(resId)) {
            return String(resId);
        }

        if (typeof resId === 'string') {
            const trimmed = resId.trim();
            if (!trimmed) {
                return null;
            }
            const parsed = Number(trimmed);
            if (!Number.isNaN(parsed) && Number.isFinite(parsed)) {
                return String(parsed);
            }
            return trimmed;
        }

        const parsed = Number(resId);
        if (!Number.isNaN(parsed) && Number.isFinite(parsed)) {
            return String(parsed);
        }

        return null;
    }

    normalizeBoolean(value) {
        if (typeof value === 'boolean') {
            return value;
        }
        if (typeof value === 'number') {
            return value !== 0;
        }
        if (typeof value === 'string') {
            const normalized = value.trim().toLowerCase();
            if (!normalized) {
                return false;
            }
            return ['1', 'true', 'yes', 'y', 'ja', 'on'].includes(normalized);
        }
        return Boolean(value);
    }

    isMasterReservationVisible(reservation) {
        if (!reservation) {
            return false;
        }

        if (this.showDisposedMasterReservations) {
            return true;
        }

        const disposedFlag = reservation.is_disposed ??
            reservation.fullData?.is_disposed ??
            reservation.data?.is_disposed ??
            null;

        return !this.normalizeBoolean(disposedFlag);
    }

    extractMasterReservationIdentifiers(reservation) {
        const identifiers = {
            resId: null,
            uniqueId: null
        };

        if (!reservation) {
            return identifiers;
        }

        let resId = reservation.res_id ?? reservation.reservation_id ?? reservation.resid ?? null;

        if (resId === null || resId === undefined) {
            const data = reservation.fullData || reservation.data || {};
            if (data.res_id !== undefined && data.res_id !== null) {
                resId = data.res_id;
            } else if (data.id !== undefined && data.id !== null) {
                resId = data.id;
            }
        }

        if ((resId === null || resId === undefined) && typeof reservation.id === 'string') {
            const match = reservation.id.match(/res_(\d+)/i);
            if (match) {
                const parsed = Number(match[1]);
                if (!Number.isNaN(parsed)) {
                    resId = parsed;
                }
            }
        }

        identifiers.resId = resId ?? null;
        identifiers.uniqueId = reservation.id || (resId !== null && resId !== undefined ? `res_${resId}` : null);

        return identifiers;
    }

    getReservationCapacityValue(reservation) {
        if (!reservation) {
            return null;
        }

        const candidates = [
            reservation._capacity,
            reservation.capacity,
            reservation.total_capacity,
            reservation.persons,
            reservation.person_count,
            reservation.guest_count,
            reservation.data?.capacity,
            reservation.data?.anz,
            reservation.data?.persons,
            reservation.data?.person_count,
            reservation.fullData?.capacity,
            reservation.fullData?.anz,
            reservation.fullData?.persons,
            reservation.fullData?.person_count
        ];

        for (const candidate of candidates) {
            if (candidate === undefined || candidate === null) {
                continue;
            }
            const numeric = Number(candidate);
            if (Number.isFinite(numeric)) {
                return numeric;
            }
        }

        return null;
    }

    getMasterCategoryTotals(reservation) {
        const result = {
            categories: {
                betten: 0,
                lager: 0,
                sonder: 0,
                dz: 0
            },
            total: 0,
            found: false,
            usedFallback: false
        };

        if (!reservation) {
            result.total = null;
            return result;
        }

        const categories = ['betten', 'lager', 'sonder', 'dz'];
        const capacityDetailSources = [
            reservation.capacity_details,
            reservation.capacityDetails,
            reservation.fullData?.capacity_details,
            reservation.fullData?.capacityDetails,
            reservation.data?.capacity_details,
            reservation.data?.capacityDetails
        ];

        const directSources = [
            reservation,
            reservation.fullData,
            reservation.data
        ];

        categories.forEach(category => {
            let value = null;

            for (const source of capacityDetailSources) {
                if (!source || typeof source !== 'object') {
                    continue;
                }
                const candidate = source[category];
                if (candidate === undefined || candidate === null) {
                    continue;
                }
                const numeric = Number(candidate);
                if (Number.isFinite(numeric)) {
                    value = numeric;
                    break;
                }
            }

            if (value === null) {
                for (const source of directSources) {
                    if (!source || typeof source !== 'object') {
                        continue;
                    }
                    const candidate = source[category];
                    if (candidate === undefined || candidate === null) {
                        continue;
                    }
                    const numeric = Number(candidate);
                    if (Number.isFinite(numeric)) {
                        value = numeric;
                        break;
                    }
                }
            }

            if (value !== null) {
                const normalized = Math.max(0, value);
                result.categories[category] = normalized;
                result.total += normalized;
                result.found = true;
            }
        });

        if (!result.found) {
            const fallback = this.getReservationCapacityValue(reservation);
            if (Number.isFinite(fallback)) {
                result.total = Math.max(0, fallback);
                result.usedFallback = true;
            } else {
                result.total = null;
            }
        }

        return result;
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

    getDetailCapacityValue(detail) {
        if (!detail) {
            return 0;
        }

        const candidates = [
            detail._capacity,
            detail.capacity,
            detail.persons,
            detail.person_count,
            detail.data?.capacity,
            detail.data?.anz,
            detail.data?.persons,
            detail.data?.person_count
        ];

        for (const candidate of candidates) {
            if (candidate === undefined || candidate === null) {
                continue;
            }
            const numeric = Number(candidate);
            if (Number.isFinite(numeric) && numeric > 0) {
                return numeric;
            }
        }

        return 0;
    }

    calculateDailyRoomOccupancy(reservationsForRoom, startDate, endDate) {
        const occupancyMap = new Map();

        if (!Array.isArray(reservationsForRoom) || reservationsForRoom.length === 0) {
            return occupancyMap;
        }

        const startTs = startDate?.getTime?.();
        const endTs = endDate?.getTime?.();

        if (!Number.isFinite(startTs) || !Number.isFinite(endTs) || endTs <= startTs) {
            return occupancyMap;
        }

        const totalDays = Math.max(0, Math.ceil((endTs - startTs) / MS_IN_DAY) + 2);

        reservationsForRoom.forEach(reservation => {
            if (!reservation) {
                return;
            }

            if (reservation._isGhost || reservation._isPreview || reservation._isTemporary || reservation.id === 'ghost-current-drag') {
                return;
            }

            const capacity = this.getDetailCapacityValue(reservation);
            if (!Number.isFinite(capacity) || capacity <= 0) {
                return;
            }

            const startValue = reservation.start ?? reservation.start_date ?? reservation.startDate;
            const endValue = reservation.end ?? reservation.end_date ?? reservation.endDate;

            if (!startValue || !endValue) {
                return;
            }

            const startDateObj = new Date(startValue);
            const endDateObj = new Date(endValue);
            if (Number.isNaN(startDateObj.getTime()) || Number.isNaN(endDateObj.getTime())) {
                return;
            }

            const startNorm = this.normalizeDateToNoon(startDateObj);
            const endNorm = this.normalizeDateToNoon(endDateObj);

            if (!Number.isFinite(startNorm) || !Number.isFinite(endNorm) || endNorm <= startNorm) {
                return;
            }

            let startIndex = Math.floor((startNorm - startTs) / MS_IN_DAY);
            let endIndex = Math.floor((endNorm - startTs) / MS_IN_DAY);

            if (!Number.isFinite(startIndex) || !Number.isFinite(endIndex)) {
                return;
            }

            // Clamp to reasonable bounds within the visible range plus a small buffer
            startIndex = Math.max(-2, Math.min(startIndex, totalDays));
            endIndex = Math.max(-2, Math.min(endIndex, totalDays));

            if (endIndex <= startIndex) {
                return;
            }

            for (let day = startIndex; day < endIndex; day++) {
                occupancyMap.set(day, (occupancyMap.get(day) || 0) + capacity);
            }
        });

        return occupancyMap;
    }

    ensureCapacityMismatchCache() {
        if (this.capacityMismatchCache && this.capacityMismatchCache.version === this.dataVersion) {
            return this.capacityMismatchCache;
        }

        const detailStats = new Map();

        if (Array.isArray(roomDetails)) {
            roomDetails.forEach(detail => {
                if (!detail || detail._isGhost) {
                    return;
                }

                const identifiers = this.extractDetailIdentifiers(detail);
                const key = this.normalizeReservationId(identifiers.resId);
                if (!key) {
                    return;
                }

                const rawCapacity = this.getDetailCapacityValue(detail);
                const normalizedCapacity = Number.isFinite(rawCapacity) ? Math.max(0, rawCapacity) : 0;

                let stats = detailStats.get(key);
                if (!stats) {
                    stats = {
                        sum: 0,
                        count: 0,
                        groups: new Map(),
                        hasParentLinks: false
                    };
                    detailStats.set(key, stats);
                }

                stats.sum += normalizedCapacity;
                stats.count += 1;

                const parentIdRaw = detail.ParentID ?? detail.parentID ?? detail.parentId ?? detail.data?.ParentID ?? detail.data?.parentID ?? detail.data?.parentId;
                const parentIdNumber = Number(parentIdRaw);
                const hasValidParent = Number.isFinite(parentIdNumber) && parentIdNumber > 0;

                if (hasValidParent) {
                    stats.hasParentLinks = true;
                }

                const detailId = identifiers.detailId ?? detail.detail_id ?? detail.id ?? null;
                const canonicalGroupId = hasValidParent
                    ? String(parentIdNumber)
                    : (detailId !== null && detailId !== undefined ? String(detailId) : `detail_${stats.count}_${Math.random().toString(36).slice(2, 8)}`);

                let group = stats.groups.get(canonicalGroupId);
                if (!group) {
                    group = {
                        canonicalId: canonicalGroupId,
                        parentId: hasValidParent ? parentIdNumber : null,
                        entries: [],
                        earliestStart: null,
                        latestEnd: null,
                        hasDateData: false
                    };
                    stats.groups.set(canonicalGroupId, group);
                }

                const startValue = detail.start ?? detail.start_date ?? detail.startDate ?? detail.data?.start ?? detail.data?.start_date ?? detail.data?.startDate;
                const endValue = detail.end ?? detail.end_date ?? detail.endDate ?? detail.data?.end ?? detail.data?.end_date ?? detail.data?.endDate;

                const startNorm = this.normalizeDateForComparison(startValue);
                const endNorm = this.normalizeDateForComparison(endValue);

                group.entries.push({
                    capacity: normalizedCapacity,
                    startNorm,
                    endNorm,
                    detailId: detailId !== null && detailId !== undefined ? detailId : null,
                    parentId: hasValidParent ? parentIdNumber : null
                });

                if (startNorm !== null) {
                    group.hasDateData = true;
                    group.earliestStart = group.earliestStart === null ? startNorm : Math.min(group.earliestStart, startNorm);
                }

                if (endNorm !== null) {
                    group.hasDateData = true;
                    group.latestEnd = group.latestEnd === null ? endNorm : Math.max(group.latestEnd, endNorm);
                }
            });
        }

        const byResId = new Map();
        const byReservation = new Map();

        (Array.isArray(reservations) ? reservations : []).forEach(reservation => {
            const identifiers = this.extractMasterReservationIdentifiers(reservation);
            const key = this.normalizeReservationId(identifiers.resId);
            if (!key) {
                return;
            }

            const stats = detailStats.get(key) || { sum: 0, count: 0, groups: new Map(), hasParentLinks: false };
            const masterTotals = this.getMasterCategoryTotals(reservation);
            const masterCapacity = masterTotals.total !== null ? Math.max(0, masterTotals.total) : null;
            const masterStay = this.extractMasterStayBounds(reservation);
            const dateComparison = this.evaluateDetailDateAlignment(stats, masterStay);

            let detailDailyPeak = null;
            let detailDailyTotals = null;

            if (stats.groups && stats.groups.size > 0) {
                detailDailyTotals = new Map();

                stats.groups.forEach(group => {
                    if (!group || !Array.isArray(group.entries) || group.entries.length === 0) {
                        return;
                    }

                    const groupDaily = new Map();

                    group.entries.forEach(entry => {
                        const capacity = Number.isFinite(entry.capacity) ? entry.capacity : 0;
                        if (capacity <= 0) {
                            return;
                        }

                        let { startNorm, endNorm } = entry;

                        if (startNorm === null && endNorm === null) {
                            const fallbackKey = `__fallback__`;
                            const prev = groupDaily.get(fallbackKey) || 0;
                            groupDaily.set(fallbackKey, Math.max(prev, capacity));
                            return;
                        }

                        if (startNorm === null && endNorm !== null) {
                            startNorm = endNorm - MS_IN_DAY;
                        } else if (startNorm !== null && endNorm === null) {
                            endNorm = startNorm + MS_IN_DAY;
                        }

                        if (startNorm === null) {
                            const fallbackKey = `__fallback__`;
                            const prev = groupDaily.get(fallbackKey) || 0;
                            groupDaily.set(fallbackKey, Math.max(prev, capacity));
                            return;
                        }

                        if (!Number.isFinite(endNorm) || endNorm <= startNorm) {
                            endNorm = startNorm + MS_IN_DAY;
                        }

                        let current = startNorm;
                        let safety = 0;
                        while (current < endNorm && safety < 3700) {
                            const prev = groupDaily.get(current) || 0;
                            groupDaily.set(current, Math.max(prev, capacity));
                            current += MS_IN_DAY;
                            safety += 1;
                        }
                    });

                    if (groupDaily.size === 0) {
                        const fallbackCapacity = group.entries.reduce((max, entry) => Number.isFinite(entry.capacity) ? Math.max(max, entry.capacity) : max, 0);
                        if (fallbackCapacity > 0) {
                            const fallbackKey = `__group_${group.canonicalId}`;
                            const prev = detailDailyTotals.get(fallbackKey) || 0;
                            detailDailyTotals.set(fallbackKey, prev + fallbackCapacity);
                        }
                        return;
                    }

                    groupDaily.forEach((value, dayKey) => {
                        const prev = detailDailyTotals.get(dayKey) || 0;
                        detailDailyTotals.set(dayKey, prev + value);
                    });
                });

                if (detailDailyTotals.size > 0) {
                    detailDailyTotals.forEach(value => {
                        if (!Number.isFinite(value)) {
                            return;
                        }
                        if (detailDailyPeak === null || value > detailDailyPeak) {
                            detailDailyPeak = value;
                        }
                    });
                }
            }

            if (detailDailyPeak === null) {
                detailDailyPeak = Math.max(0, stats.sum);
            }

            const detailCapacity = Math.max(0, detailDailyPeak);
            const hasDetailData = stats.count > 0;
            const mismatch = hasDetailData && masterCapacity !== null ? Math.abs(masterCapacity - detailCapacity) > 0.001 : false;
            const dateMismatch = Boolean(dateComparison?.dateMismatch);

            const info = {
                resId: identifiers.resId,
                masterCapacity,
                detailCapacity,
                mismatch,
                hasDetailData,
                masterCategories: masterTotals.categories,
                usedFallbackCapacity: masterTotals.usedFallback,
                detailRawSum: Math.max(0, stats.sum),
                detailDailyPeak,
                detailGroupCount: stats.groups ? stats.groups.size : 0,
                detailHasParentLinks: Boolean(stats.hasParentLinks),
                hasDetailDateData: Boolean(dateComparison?.hasDetailDates),
                dateMismatch,
                startMismatch: Boolean(dateComparison?.startMismatch),
                endMismatch: Boolean(dateComparison?.endMismatch),
                masterStart: dateComparison?.masterStart ?? null,
                masterEnd: dateComparison?.masterEnd ?? null,
                detailStart: dateComparison?.detailStart ?? null,
                detailEnd: dateComparison?.detailEnd ?? null,
                comparedDateGroupCount: dateComparison?.comparedGroupCount ?? 0,
                usedLinkedGroupsForDateComparison: Boolean(dateComparison?.usedLinkedGroups)
            };

            info.hasAnyMismatch = Boolean(info.mismatch || info.dateMismatch);
            info.mismatchReasons = [];
            if (info.mismatch) {
                info.mismatchReasons.push('capacity');
            }
            if (info.startMismatch) {
                info.mismatchReasons.push('start');
            }
            if (info.endMismatch) {
                info.mismatchReasons.push('end');
            }

            byResId.set(key, info);
            byReservation.set(reservation, info);
        });

        this.capacityMismatchCache = {
            version: this.dataVersion,
            byResId,
            byReservation
        };

        return this.capacityMismatchCache;
    }

    getCapacityMismatchInfo(entry) {
        if (!entry) {
            return null;
        }

        const cache = this.ensureCapacityMismatchCache();
        if (!cache) {
            return null;
        }

        const direct = cache.byReservation.get(entry);
        if (direct) {
            return direct;
        }

        const masterIdentifiers = this.extractMasterReservationIdentifiers(entry);
        const masterKey = this.normalizeReservationId(masterIdentifiers.resId);
        if (masterKey && cache.byResId.has(masterKey)) {
            return cache.byResId.get(masterKey) || null;
        }

        if (typeof this.extractDetailIdentifiers === 'function') {
            const detailIdentifiers = this.extractDetailIdentifiers(entry);
            if (detailIdentifiers && detailIdentifiers.resId !== null && detailIdentifiers.resId !== undefined) {
                const detailKey = this.normalizeReservationId(detailIdentifiers.resId);
                if (detailKey && cache.byResId.has(detailKey)) {
                    return cache.byResId.get(detailKey) || null;
                }
            }
        }

        return null;
    }

    ensureCautionIcon() {
        if (this._cautionImageError) {
            return null;
        }

        if (!this._cautionImage) {
            this._cautionImage = new Image();
            this._cautionImageLoaded = false;
            this._cautionImage.onerror = () => {
                this._cautionImageError = true;
                console.warn('Warnsymbol konnte nicht geladen werden:', this._cautionImage?.src);
            };
            this._cautionImage.onload = () => {
                this._cautionImageLoaded = true;
                this.scheduleRender('caution_icon_loaded');
            };
            this._cautionImage.src = 'http://192.168.15.14:8080/wci/pic/caution.svg';
        }

        if (this._cautionImageLoaded || (this._cautionImage.complete && this._cautionImage.naturalWidth > 0)) {
            this._cautionImageLoaded = true;
            return this._cautionImage;
        }

        return null;
    }

    renderOverCapacityIndicators(room, reservationsForRoom, startDate, endDate, baseRoomY, roomHeight) {
        if (!room || !Array.isArray(reservationsForRoom) || reservationsForRoom.length === 0) {
            return;
        }

        if (this.isDraggingReservation) {
            const hasTemporaryReservation = reservationsForRoom.some(reservation =>
                reservation && (reservation._isPreview || reservation._isGhost || reservation._isTemporary || reservation.id === 'ghost-current-drag')
            );

            if (hasTemporaryReservation) {
                return;
            }
        }

        const roomCapacity = this.getRoomCapacityValue(room);
        if (!Number.isFinite(roomCapacity) || roomCapacity <= 0) {
            return;
        }

        const occupancyMap = this.calculateDailyRoomOccupancy(reservationsForRoom, startDate, endDate);
        if (occupancyMap.size === 0) {
            return;
        }

        const overCapacityDays = [];
        occupancyMap.forEach((value, dayIndex) => {
            if (value > roomCapacity) {
                overCapacityDays.push({ dayIndex, occupancy: value });
            }
        });

        if (overCapacityDays.length === 0) {
            return;
        }

        const cautionIcon = this.ensureCautionIcon();
        if (!cautionIcon) {
            return;
        }

        const iconSize = Math.max(20, Math.min(28, Math.min(this.DAY_WIDTH - 4, this.ROOM_BAR_HEIGHT + 12)));
        const roomsAreaTop = this.areas.rooms.y;
        const roomsAreaBottom = roomsAreaTop + this.areas.rooms.height;
        const centeredIconY = baseRoomY + (roomHeight - iconSize) / 2;
        const iconYMax = Math.min(baseRoomY + roomHeight - iconSize, roomsAreaBottom - iconSize);
        const iconY = Math.max(roomsAreaTop, Math.min(centeredIconY, iconYMax));
        const startX = this.sidebarWidth - this.scrollX;

        this.ctx.save();
        this.ctx.globalAlpha = 0.7;

        overCapacityDays.forEach(({ dayIndex }) => {
            const dayStartX = startX + ((dayIndex + 1) * this.DAY_WIDTH);
            const iconX = dayStartX + (this.DAY_WIDTH - iconSize) / 2 - (this.DAY_WIDTH / 2);
            if (iconX + iconSize < this.sidebarWidth || iconX > this.canvas.width) {
                return;
            }
            this.ctx.drawImage(cautionIcon, iconX, iconY, iconSize, iconSize);
        });

        this.ctx.restore();
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

        fetch('http://192.168.15.14:8080/wci/zp/updateRoomDetail.php', {
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

        fetch('http://192.168.15.14:8080/wci/zp/updateRoomDetailAttributes.php', {
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

    setHistogramSource(source, stornoSource = null) {
        const normalizeEntry = (entry, isStorno = false) => ({
            id: entry?.id,
            av_id: entry?.av_id ?? entry?.avId ?? null,
            start: entry?.start,
            end: entry?.end,
            capacity_details: entry?.capacity_details || {},
            storno: isStorno || Boolean(entry?.storno)
        });

        let normalizedHistogram = [];
        let normalizedStorno = [];

        if (Array.isArray(source)) {
            normalizedHistogram = source.map(entry => normalizeEntry(entry, false));
        } else if (source && typeof source === 'object') {
            if (Array.isArray(source.histogram)) {
                normalizedHistogram = source.histogram.map(entry => normalizeEntry(entry, false));
            }
            if (Array.isArray(source.storno)) {
                normalizedStorno = source.storno.map(entry => normalizeEntry(entry, true));
            }
        }

        if (Array.isArray(stornoSource)) {
            normalizedStorno = stornoSource.map(entry => normalizeEntry(entry, true));
        } else if (!normalizedStorno.length && source && Array.isArray(source?.storno)) {
            normalizedStorno = source.storno.map(entry => normalizeEntry(entry, true));
        }

        this.histogramSource = normalizedHistogram;
        this.histogramStornoSource = normalizedStorno;

        histogramSourceData = this.histogramSource;
        histogramStornoSourceData = this.histogramStornoSource;
        if (typeof window !== 'undefined') {
            window.histogramSource = this.histogramSource;
            window.histogramStornoSource = this.histogramStornoSource;
        }

        this.invalidateHistogramCache();
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

    // Heutigen Tag dezent hervorheben (hellgrau), analog Wochenende
    shadeTodayColumn(area, startDate, endDate, options = {}) {
        const fill = options.todayFill || 'rgba(200, 200, 200, 0.25)';
        if (!fill) return;

        const barWidth = options.barWidth !== undefined ? options.barWidth : this.DAY_WIDTH;
        const xOffset = options.xOffset || 0;
        const offsetY = options.offsetY !== undefined ? options.offsetY : area.y;
        const height = options.height !== undefined ? options.height : area.height;
        const startX = this.sidebarWidth - this.scrollX;

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const start = new Date(startDate);
        start.setHours(0, 0, 0, 0);
        const end = new Date(endDate);
        end.setHours(0, 0, 0, 0);
        if (today < start || today > end) return;

        const dayIndex = Math.floor((today.getTime() - start.getTime()) / MS_IN_DAY);
        const x = startX + (dayIndex * this.DAY_WIDTH) + xOffset;
        if (x + barWidth <= this.sidebarWidth || x >= this.canvas.width) {
            return;
        }
        this.ctx.save();
        this.ctx.fillStyle = fill;
        this.ctx.fillRect(x, offsetY, barWidth, height);
        this.ctx.restore();
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
            total: 0,
            storno: {
                av0: 0,
                avPositive: 0,
                total: 0
            }
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

        const stornoReservations = Array.isArray(this.histogramStornoSource) && this.histogramStornoSource.length > 0
            ? this.histogramStornoSource
            : [];

        const addStornoReservationToHistogram = (reservation) => {
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
            const total = dz + betten + lager + sonder;
            if (total <= 0) return;

            const avId = reservation.av_id !== undefined && reservation.av_id !== null
                ? Number(reservation.av_id)
                : Number(reservation.data?.av_id ?? reservation.fullData?.av_id ?? 0);

            for (let day = startIndex; day < endIndex; day++) {
                dailyDetails[day].storno.total += total;
                if (Number.isFinite(avId) && avId > 0) {
                    dailyDetails[day].storno.avPositive += total;
                } else {
                    dailyDetails[day].storno.av0 += total;
                }
            }
        };

        stornoReservations.forEach(addStornoReservationToHistogram);

        const dailyCounts = dailyDetails.map(detail => detail.total);
        const dailyStornoCounts = dailyDetails.map(detail => detail.storno.total);
        const maxActiveGuests = dailyCounts.reduce((max, value) => Math.max(max, value), 0);
        const maxStornoGuests = dailyStornoCounts.reduce((max, value) => Math.max(max, value), 0);
        const maxGuests = Math.max(maxActiveGuests, maxStornoGuests);

        this.histogramCache = {
            version: this.dataVersion,
            startTs,
            endTs,
            dailyCounts,
            dailyDetails,
            maxGuests,
            dailyStornoCounts,
            maxActiveGuests,
            maxStornoGuests
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

    computeHistogramTickStep(range, desiredTickCount) {
        if (!Number.isFinite(range) || range <= 0) {
            return 1;
        }

        const safeCount = Math.max(1, desiredTickCount);
        const rawStep = range / safeCount;
        const exponent = Math.floor(Math.log10(rawStep));
        const magnitude = Math.pow(10, exponent);
        const normalized = rawStep / magnitude;
        const candidates = [1, 1.25, 2, 2.5, 4, 5, 6, 8, 10];

        let candidate = candidates[candidates.length - 1];
        for (const option of candidates) {
            if (normalized <= option) {
                candidate = option;
                break;
            }
        }

        return candidate * magnitude;
    }

    getHistogramTicks(maxValue, options = {}) {
        const { desiredTickCount = 5, manualMax = null } = options;
        const manualTarget = Number(manualMax);
        const hasManualTarget = Number.isFinite(manualTarget) && manualTarget > 0;
        const dataMax = Number(maxValue);
        const effectiveDataMax = Number.isFinite(dataMax) && dataMax > 0 ? dataMax : 0;

        if (!effectiveDataMax && !hasManualTarget) {
            return { ticks: [0], niceMax: 0, spacing: 1, manualApplied: false };
        }

        let targetMax = effectiveDataMax;
        if (hasManualTarget) {
            targetMax = Math.max(targetMax, manualTarget);
        }

        if (!Number.isFinite(targetMax) || targetMax <= 0) {
            return { ticks: [0], niceMax: 0, spacing: 1, manualApplied: hasManualTarget };
        }

        const safeTickCount = Math.max(2, Math.floor(desiredTickCount));
        const step = this.computeHistogramTickStep(targetMax, safeTickCount - 1);
        const epsilon = step * 1e-3;

        const ticks = [];
        for (let tick = 0; tick <= targetMax + epsilon; tick += step) {
            const rounded = Math.round(tick * 1000) / 1000;
            if (!ticks.length || Math.abs(ticks[ticks.length - 1] - rounded) > epsilon) {
                ticks.push(rounded);
            }
        }

        let niceMax = ticks.length ? ticks[ticks.length - 1] : targetMax;

        if (hasManualTarget) {
            const manualRounded = Math.round(manualTarget * 1000) / 1000;

            if (!ticks.length) {
                ticks.push(0, manualRounded);
                niceMax = manualRounded;
            } else {
                const lastTick = ticks[ticks.length - 1];
                const diff = Math.abs(lastTick - manualRounded);

                if (manualRounded > lastTick + epsilon) {
                    ticks.push(manualRounded);
                    niceMax = manualRounded;
                } else if (diff > epsilon) {
                    if (ticks.length >= 2 && manualRounded > ticks[ticks.length - 2] + epsilon) {
                        ticks[ticks.length - 1] = manualRounded;
                    } else {
                        ticks[ticks.length - 1] = manualRounded;
                    }
                    niceMax = manualRounded;
                } else {
                    ticks[ticks.length - 1] = manualRounded;
                    niceMax = manualRounded;
                }
            }
        } else {
            niceMax = ticks[ticks.length - 1] ?? targetMax;
        }

        if (!ticks.length || ticks[0] !== 0) {
            ticks.unshift(0);
        }

        const deduped = [];
        for (const value of ticks) {
            const rounded = Math.round(value * 1000) / 1000;
            if (!deduped.length || Math.abs(deduped[deduped.length - 1] - rounded) > epsilon) {
                deduped.push(rounded);
            }
        }

        return {
            ticks: deduped,
            niceMax: niceMax,
            spacing: step,
            manualApplied: hasManualTarget
        };
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
            dayWidth: 90,
            weeksPast: 2,
            weeksFuture: 104
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
            dayWidth: 90,
            weeksPast: 2,
            weeksFuture: 104
        };

        // Ergänze fehlende Eigenschaften
        const result = { ...config };

        for (const [section, sectionDefaults] of Object.entries(defaults)) {
            if (section === 'dayWidth') {
                const configured = Number(result.dayWidth);
                if (Number.isFinite(configured)) {
                    result.dayWidth = Math.max(40, Math.min(250, configured));
                } else {
                    result.dayWidth = defaults.dayWidth;
                }
            } else if (section === 'weeksPast') {
                result.weeksPast = result.weeksPast ?? defaults.weeksPast;
            } else if (section === 'weeksFuture') {
                result.weeksFuture = result.weeksFuture ?? defaults.weeksFuture;
            } else {
                result[section] = { ...sectionDefaults, ...result[section] };
            }
        }

        if (result.histogram) {
            const configuredMax = Number(result.histogram.maxValue);
            if (Number.isFinite(configuredMax) && configuredMax > 0) {
                result.histogram.maxValue = Math.round(this.clamp(configuredMax, 10, 2000));
            } else {
                delete result.histogram.maxValue;
            }
        }

        return result;
    }

    refreshThemeConfiguration() {
        const previousDayWidth = this.DAY_WIDTH;
        const previousBarHeight = this.ROOM_BAR_HEIGHT;

        this.themeConfig = this.loadThemeConfiguration();

        const themeDayWidth = typeof this.themeConfig.dayWidth === 'number' ? this.themeConfig.dayWidth : 90;
        this.DAY_WIDTH = Math.max(40, Math.min(250, themeDayWidth));

        const themeRoomBarHeight = this.themeConfig.room?.barHeight;
        const themeMasterBarHeight = this.themeConfig.master?.barHeight;
        const initialBarHeight = Math.max(10, Math.min(35, typeof themeRoomBarHeight === 'number'
            ? themeRoomBarHeight
            : (typeof themeMasterBarHeight === 'number' ? themeMasterBarHeight : 16)));
        this.ROOM_BAR_HEIGHT = initialBarHeight;
        this.MASTER_BAR_HEIGHT = initialBarHeight;

        this.themeConfig.sidebar = this.themeConfig.sidebar || {};
        this.sidebarFontSize = Math.max(9, Math.min(20,
            this.themeConfig.sidebar.fontSize || this.computeBarFontSize(this.ROOM_BAR_HEIGHT)));
        this.themeConfig.sidebar.fontSize = this.sidebarFontSize;
        this.sidebarMetricsDirty = true;

        const { dayWidthChanged, barHeightChanged } = this.applyPersistentSizingFromStorage({ skipRecalculate: true });

        const effectiveDayWidthChanged = dayWidthChanged || Math.abs(previousDayWidth - this.DAY_WIDTH) > 0.01;
        const effectiveBarHeightChanged = barHeightChanged || Math.abs(previousBarHeight - this.ROOM_BAR_HEIGHT) > 0.01;

        this.invalidateStackingCache();
        if (effectiveBarHeightChanged) {
            this.recalculateRoomHeights();
        }

        if (effectiveDayWidthChanged || effectiveBarHeightChanged) {
            this.scheduleRender('theme_persisted_change');
        } else {
            this.scheduleRender('theme_change');
        }

        this.updateTopbarVisuals();

        if (typeof window !== 'undefined' && typeof window.updateTimelineToolbarValues === 'function') {
            window.updateTimelineToolbarValues();
        }
    }

    updateTopbarVisuals() {
        const topbar = document.getElementById('timeline-toolbar');
        if (!topbar) return;

        const config = this.themeConfig || {};
        const sidebarBg = config.sidebar?.bg || '#2c2c2c';
        const sidebarText = config.sidebar?.text || '#f5f5f5';
        const headerBg = config.header?.bg || '#007acc';

        const isHex = (color) => typeof color === 'string' && /^#([0-9a-f]{3}){1,2}$/i.test(color.trim());
        const safeSidebarBg = isHex(sidebarBg) ? sidebarBg : '#2c2c2c';
        const safeSidebarText = isHex(sidebarText) ? sidebarText : '#f5f5f5';

        const lighten = (color, amount) => {
            try {
                return this.lightenColor(color, amount);
            } catch (err) {
                return color;
            }
        };

        topbar.style.setProperty('--topbar-bg', lighten(safeSidebarBg, 5));
        topbar.style.setProperty('--topbar-menu-bg', lighten(safeSidebarBg, 15));
        topbar.style.setProperty('--topbar-border', lighten(safeSidebarBg, 25));
        topbar.style.setProperty('--topbar-fg', safeSidebarText);
        topbar.style.setProperty('--topbar-muted', lighten(safeSidebarText, -45));
        topbar.style.setProperty('--topbar-accent', headerBg || '#007acc');

        this.updateNavigationOverview();
        this.syncTopbarControls();
    }

    persistThemeConfig() {
        if (!this.themeConfig || typeof window === 'undefined') {
            return;
        }

        try {
            const configString = JSON.stringify(this.themeConfig);
            const expires = new Date();
            expires.setFullYear(expires.getFullYear() + 1);

            if (typeof document !== 'undefined') {
                document.cookie = `timeline_config=${encodeURIComponent(configString)}; expires=${expires.toUTCString()}; path=/`;
            }

            if (window && window.localStorage) {
                window.localStorage.setItem('timeline_config', configString);
            }
        } catch (error) {
            console.warn('Theme-Konfiguration konnte nicht gespeichert werden:', error);
        }
    }

    getConfiguredHistogramMaxValue() {
        const configured = Number(this.themeConfig?.histogram?.maxValue);
        if (Number.isFinite(configured) && configured > 0) {
            return Math.round(this.clamp(configured, 10, 2000));
        }
        return 0;
    }

    setHistogramMaxValue(value) {
        if (!this.themeConfig) {
            this.themeConfig = {};
        }
        if (!this.themeConfig.histogram) {
            this.themeConfig.histogram = {};
        }

        const numericValue = Number(value);
        const sanitized = Number.isFinite(numericValue) && numericValue > 0
            ? Math.round(this.clamp(numericValue, 10, 2000))
            : null;

        const previous = this.getConfiguredHistogramMaxValue();

        if (sanitized === null) {
            if (previous === 0) {
                this.syncTopbarControls();
                return false;
            }

            delete this.themeConfig.histogram.maxValue;
            this.persistThemeConfig();
            this.syncTopbarControls();
            this.scheduleRender('histogram_max_reset');
            return true;
        }

        if (previous === sanitized) {
            this.syncTopbarControls();
            return false;
        }

        this.themeConfig.histogram.maxValue = sanitized;
        this.persistThemeConfig();
        this.syncTopbarControls();
        this.scheduleRender('histogram_max_update');
        return true;
    }

    initializeTopbarControls() {
        if (typeof document === 'undefined') {
            return;
        }

        const toggle = document.getElementById('timeline-settings-toggle');
        const menu = document.getElementById('timeline-settings-menu');
        if (toggle && menu && !toggle.dataset.bound) {
            toggle.dataset.bound = 'true';
            toggle.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (typeof event.stopImmediatePropagation === 'function') {
                    event.stopImmediatePropagation();
                }
                const isOpen = menu.getAttribute('data-open') === 'true';
                const nextState = !isOpen;
                menu.setAttribute('data-open', nextState ? 'true' : 'false');
                menu.classList.toggle('open', nextState);
                toggle.setAttribute('aria-expanded', nextState ? 'true' : 'false');
                toggle.setAttribute('aria-haspopup', 'true');
                menu.setAttribute('aria-hidden', nextState ? 'false' : 'true');
            });
        }

        const histogramInput = document.getElementById('timeline-histogram-max');
        const histogramDisplay = document.getElementById('timeline-histogram-max-display');

        if (histogramInput && !histogramInput.dataset.bound) {
            histogramInput.dataset.bound = 'true';

            const updateDisplay = () => {
                const current = this.getConfiguredHistogramMaxValue();
                if (document.activeElement !== histogramInput) {
                    histogramInput.value = current > 0 ? String(current) : '';
                }
                if (histogramDisplay) {
                    const candidate = document.activeElement === histogramInput
                        ? Number(histogramInput.value)
                        : current;
                    const displayValue = Number.isFinite(candidate) && candidate > 0 ? Math.round(candidate) : null;
                    histogramDisplay.textContent = displayValue ? String(displayValue) : 'Auto';
                }
            };

            histogramInput.addEventListener('focus', () => updateDisplay());
            histogramInput.addEventListener('input', () => {
                if (histogramDisplay) {
                    const candidate = Number(histogramInput.value);
                    histogramDisplay.textContent = Number.isFinite(candidate) && candidate > 0
                        ? String(Math.round(candidate))
                        : 'Auto';
                }
            });
            histogramInput.addEventListener('change', () => {
                this.setHistogramMaxValue(histogramInput.value);
            });
            histogramInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    this.setHistogramMaxValue(histogramInput.value);
                    histogramInput.blur();
                }
            });
            histogramInput.addEventListener('blur', () => updateDisplay());

            updateDisplay();
        }

        this.syncTopbarControls();
    }

    syncTopbarControls() {
        if (typeof document === 'undefined') {
            return;
        }

        const histogramInput = document.getElementById('timeline-histogram-max');
        const histogramDisplay = document.getElementById('timeline-histogram-max-display');

        if (histogramInput && document.activeElement !== histogramInput) {
            const current = this.getConfiguredHistogramMaxValue();
            histogramInput.value = current > 0 ? String(current) : '';
            if (histogramDisplay) {
                histogramDisplay.textContent = current > 0 ? String(current) : 'Auto';
            }
        } else if (histogramInput && histogramDisplay) {
            const candidate = Number(histogramInput.value);
            histogramDisplay.textContent = Number.isFinite(candidate) && candidate > 0
                ? String(Math.round(candidate))
                : 'Auto';
        }
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
                height: var(--timeline-vh, 100vh);
                min-height: var(--timeline-vh, 100vh);
                max-height: var(--timeline-vh, 100vh);
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
                        <div id="timeline-toolbar" class="timeline-topbar">
                            <div class="timeline-topbar-actions">
                                <button id="timeline-settings-toggle" class="timeline-topbar-button" type="button">⚙️ Optionen</button>
                                <div id="timeline-settings-menu" class="timeline-topbar-menu" data-open="false">
                                    <div class="topbar-menu-section">
                                        <label for="timeline-preset-select">Preset</label>
                                        <select id="timeline-preset-select" class="topbar-select">
                                            <option value="professional">Professional</option>
                                            <option value="comfort">Comfort</option>
                                            <option value="focus">Focus</option>
                                            <option value="nature">Nature</option>
                                            <option value="ocean">Ocean</option>
                                            <option value="sunset">Sunset</option>
                                            <option value="earth">Earth</option>
                                            <option value="rainbow">Rainbow</option>
                                            <option value="grayscale">Grayscale</option>
                                            <option value="custom">Benutzerdefiniert</option>
                                        </select>
                                    </div>
                                    <div class="topbar-menu-section">
                                        <label for="timeline-menu-size">Radialmenü-Größe</label>
                                        <div class="topbar-control-row">
                                            <input type="range" id="timeline-menu-size" min="120" max="320" step="20" value="220">
                                            <span id="timeline-menu-size-display">220px</span>
                                        </div>
                                    </div>
                                    <div class="topbar-menu-section topbar-menu-grid">
                                        <div class="topbar-field">
                                            <label for="timeline-weeks-past">Wochen zurück</label>
                                            <input type="number" id="timeline-weeks-past" min="0" max="52" step="1" value="2">
                                        </div>
                                        <div class="topbar-field">
                                            <label for="timeline-weeks-future">Wochen voraus</label>
                                            <input type="number" id="timeline-weeks-future" min="4" max="208" step="1" value="104">
                                        </div>
                                    </div>
                                    <div class="topbar-menu-section">
                                        <label for="timeline-histogram-max">Histogramm-Maximum</label>
                                        <div class="topbar-control-row">
                                            <input type="number" id="timeline-histogram-max" min="10" max="2000" step="10" placeholder="Auto">
                                            <span id="timeline-histogram-max-display">Auto</span>
                                        </div>
                                        <p class="topbar-hint" style="margin: 4px 0 0; font-size: 11px; color: var(--topbar-muted);">Leer lassen für automatischen Modus</p>
                                    </div>
                                    <div class="topbar-menu-section topbar-menu-links">
                                        <a id="timeline-room-editor-link" class="topbar-link" href="zimmereditor/index.php" target="_blank" rel="noopener">🛏️ Zimmereditor</a>
                                    </div>
                                </div>
                            </div>
                            <div class="timeline-nav-container">
                                <canvas id="timeline-nav-canvas" class="timeline-nav-canvas"></canvas>
                            </div>
                        </div>
                        <canvas id="timeline-canvas" style="
                            position: absolute;
                            top: 0;
                            left: 0;
                            right: 0;
                            bottom: 20px;
                            cursor: default;
                            z-index: 1;
                            touch-action: none;
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
        this.rootContainer = this.container.querySelector('.timeline-unified-container');
        this.canvas = document.getElementById('timeline-canvas');
        this.canvas.style.touchAction = 'none';
        this.ctx = this.canvas.getContext('2d');

        this.updateDynamicViewportHeight(true);
        this.updateSidebarMetrics(true);

        this.resizeCanvas();
        this.addMobileScrollbarStyles();
        this.initializeNavigationOverview();
        this.updateTopbarVisuals();
        this.updateNavigationOverview();
        this.initializeTopbarControls();
    }

    addMobileScrollbarStyles() {
        // Prüfe ob bereits Styles existieren
        if (document.getElementById('mobile-scrollbar-styles')) return;

        const style = document.createElement('style');
        style.id = 'mobile-scrollbar-styles';
        style.textContent = `
            :root {
                --timeline-vh: 100vh;
            }

            /* Body Fullscreen ohne Scrollbars */
            body, html {
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                width: 100vw !important;
                height: var(--timeline-vh, 100vh) !important;
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

    disposeNavigationOverview() {
        if (this.navResizeObserver) {
            try {
                this.navResizeObserver.disconnect();
            } catch (error) {
                console.warn('ResizeObserver disconnect failed:', error);
            }
            this.navResizeObserver = null;
        }

        if (this.navWindowResizeHandler) {
            window.removeEventListener('resize', this.navWindowResizeHandler);
            this.navWindowResizeHandler = null;
        }

        if (this.navCanvas && this.navPointerHandlers) {
            this.navCanvas.removeEventListener('pointerdown', this.navPointerHandlers.down);
            this.navCanvas.removeEventListener('pointermove', this.navPointerHandlers.move);
            this.navCanvas.removeEventListener('pointerup', this.navPointerHandlers.up);
            this.navCanvas.removeEventListener('pointercancel', this.navPointerHandlers.up);
        }

        if (this.navCanvas && this.navActivePointerId !== null) {
            try {
                this.navCanvas.releasePointerCapture(this.navActivePointerId);
            } catch (error) {
                // ignore
            }
        }

        this.navPointerHandlers = null;
        this.navIsDragging = false;
        this.navDragOffsetMs = 0;
        this.navActivePointerId = null;
        this.navCanvas = null;
        this.navCtx = null;
    }

    initializeNavigationOverview() {
        this.disposeNavigationOverview();

        const navCanvas = this.container.querySelector('#timeline-nav-canvas');
        if (!navCanvas) {
            this.navCanvas = null;
            this.navCtx = null;
            return;
        }

        this.navCanvas = navCanvas;
        this.navCtx = navCanvas.getContext('2d');
        this.navCanvas.classList.remove('dragging');

        this.navPointerHandlers = {
            down: (event) => this.handleNavigationPointerDown(event),
            move: (event) => this.handleNavigationPointerMove(event),
            up: (event) => this.handleNavigationPointerUp(event)
        };

        navCanvas.addEventListener('pointerdown', this.navPointerHandlers.down);
        navCanvas.addEventListener('pointermove', this.navPointerHandlers.move);
        navCanvas.addEventListener('pointerup', this.navPointerHandlers.up);
        navCanvas.addEventListener('pointercancel', this.navPointerHandlers.up);

        if (typeof ResizeObserver !== 'undefined') {
            this.navResizeObserver = new ResizeObserver(() => this.resizeNavigationCanvas());
            const parent = navCanvas.parentElement || navCanvas;
            if (parent) {
                this.navResizeObserver.observe(parent);
            }
        } else {
            this.navWindowResizeHandler = () => this.resizeNavigationCanvas();
            window.addEventListener('resize', this.navWindowResizeHandler);
        }

        // Initial sizing once layout is available
        requestAnimationFrame(() => this.resizeNavigationCanvas());
    }

    resizeNavigationCanvas() {
        if (!this.navCanvas || !this.navCtx) {
            return;
        }

        const rect = this.navCanvas.getBoundingClientRect();
        const cssWidth = rect.width || this.navCanvas.clientWidth;
        const cssHeight = rect.height || this.navCanvas.clientHeight || this.navHeight;

        if (!cssWidth || !cssHeight) {
            return;
        }

        const dpr = window.devicePixelRatio || 1;
        this.navDevicePixelRatio = dpr;

        this.navCanvas.width = Math.max(1, Math.round(cssWidth * dpr));
        this.navCanvas.height = Math.max(1, Math.round(cssHeight * dpr));

        this.navCtx.setTransform(1, 0, 0, 1, 0, 0);
        this.navCtx.scale(dpr, dpr);

        this.updateNavigationOverview();
    }

    getNavigationMetrics() {
        if (!this.navCanvas || !this.navCtx || !this.currentRange) {
            return null;
        }

        const width = this.navCanvas.clientWidth || this.navCanvas.getBoundingClientRect().width;
        const height = this.navCanvas.clientHeight || this.navCanvas.getBoundingClientRect().height || this.navHeight;

        if (!width || !height) {
            return null;
        }

        const startMs = this.currentRange.startDate.getTime();
        const endMs = this.currentRange.endDate.getTime();
        const totalMs = Math.max(1, endMs - startMs);

        const viewportPixelWidth = Math.max(1, this.canvas.width - this.sidebarWidth);
        const viewportWidthMs = Math.min(totalMs, (viewportPixelWidth / Math.max(1, this.DAY_WIDTH)) * MS_IN_DAY);

        let viewportStartMs = startMs + (this.scrollX / Math.max(1, this.DAY_WIDTH)) * MS_IN_DAY;
        const maxViewportStart = endMs - viewportWidthMs;
        if (viewportWidthMs >= totalMs) {
            viewportStartMs = startMs;
        } else {
            viewportStartMs = this.clamp(viewportStartMs, startMs, maxViewportStart);
        }

        return {
            width,
            height,
            startMs,
            endMs,
            totalMs,
            viewportStartMs,
            viewportWidthMs,
            viewportPixelWidth,
            msPerPx: totalMs / width
        };
    }

    getNavigationPointerTimestamp(event, metrics) {
        const rect = this.navCanvas.getBoundingClientRect();
        const relativeX = this.clamp(event.clientX - rect.left, 0, metrics.width);
        return metrics.startMs + relativeX * metrics.msPerPx;
    }

    scrollToNavigationViewport(startMs, metrics) {
        if (!metrics) {
            return;
        }

        const totalWidthPx = (metrics.totalMs / MS_IN_DAY) * this.DAY_WIDTH;
        const maxScroll = Math.max(0, totalWidthPx - metrics.viewportPixelWidth);
        const desiredScroll = ((startMs - metrics.startMs) / MS_IN_DAY) * this.DAY_WIDTH;
        const clampedScroll = this.clamp(desiredScroll, 0, maxScroll);

        if (this.horizontalTrack) {
            if (Math.abs(this.horizontalTrack.scrollLeft - clampedScroll) > 0.5) {
                this.horizontalTrack.scrollLeft = clampedScroll;
            } else {
                this.scrollX = clampedScroll;
                this.updateViewportCache(this.scrollX, this.roomsScrollY);
                this.updateNavigationOverview();
                this.scheduleRender('nav_scroll_sync');
            }
        } else {
            this.scrollX = clampedScroll;
            this.updateViewportCache(this.scrollX, this.roomsScrollY);
            this.updateNavigationOverview();
            this.scheduleRender('nav_scroll_manual');
        }
    }

    buildNavigationSegments(rangeStartMs, rangeEndMs) {
        const segments = new Map();

        const ensureEntry = (key, startMs, endMs, hasRooms, color) => {
            if (!Number.isFinite(startMs) || !Number.isFinite(endMs) || endMs <= startMs) {
                return;
            }
            const existing = segments.get(key);
            if (existing) {
                existing.startMs = Math.min(existing.startMs, startMs);
                existing.endMs = Math.max(existing.endMs, endMs);
                existing.hasRooms = existing.hasRooms || hasRooms;
                if (!existing.color && color) {
                    existing.color = color;
                }
            } else {
                segments.set(key, {
                    startMs,
                    endMs,
                    hasRooms: !!hasRooms,
                    color: color || null
                });
            }
        };

        if (Array.isArray(reservations)) {
            reservations.forEach((reservation, index) => {
                if (!reservation || this.isReservationStorno(reservation)) {
                    return;
                }

                const startMs = this.normalizeNavTimestamp(reservation.start);
                const endMs = this.normalizeNavTimestamp(reservation.end);
                if (!Number.isFinite(startMs) || !Number.isFinite(endMs) || endMs <= startMs) {
                    return;
                }

                const identifiers = this.extractMasterReservationIdentifiers(reservation);
                const normalizedId = this.normalizeReservationId(identifiers.resId ?? reservation.res_id ?? reservation.id);
                const key = normalizedId || `reservation-${index}`;
                const color = this.getMasterReservationColor(reservation);
                ensureEntry(key, startMs, endMs, false, color);
            });
        }

        if (Array.isArray(roomDetails)) {
            roomDetails.forEach((detail, index) => {
                if (!detail || this.isDetailStorno(detail)) {
                    return;
                }

                const startMs = this.normalizeNavTimestamp(detail.start);
                const endMs = this.normalizeNavTimestamp(detail.end);
                if (!Number.isFinite(startMs) || !Number.isFinite(endMs) || endMs <= startMs) {
                    return;
                }

                const resId = this.normalizeReservationId(
                    detail.res_id ?? detail.reservation_id ?? detail.data?.res_id ?? detail.data?.reservation_id
                );
                const key = resId || `detail-${index}`;
                const color = this.getMasterReservationColor(detail);
                ensureEntry(key, startMs, endMs, true, color);
            });
        }

        const result = [];
        segments.forEach(entry => {
            const clampedStart = this.clamp(entry.startMs, rangeStartMs, rangeEndMs);
            const clampedEnd = this.clamp(entry.endMs, rangeStartMs, rangeEndMs);
            if (clampedEnd <= clampedStart) {
                return;
            }
            result.push({
                start: clampedStart,
                end: clampedEnd,
                hasRooms: entry.hasRooms,
                color: entry.color || this.getMasterReservationColor(null)
            });
        });

        result.sort((a, b) => a.start - b.start || a.end - b.end);
        return result;
    }

    normalizeNavTimestamp(value) {
        if (!value) {
            return NaN;
        }

        if (value instanceof Date) {
            const clone = new Date(value.getTime());
            clone.setHours(12, 0, 0, 0);
            return clone.getTime();
        }

        if (typeof value === 'number' && Number.isFinite(value)) {
            return value;
        }

        const parsed = new Date(value);
        if (!parsed || Number.isNaN(parsed.getTime())) {
            return NaN;
        }
        parsed.setHours(12, 0, 0, 0);
        return parsed.getTime();
    }

    isReservationStorno(reservation) {
        if (!reservation) {
            return false;
        }

        const candidates = [
            reservation.storno,
            reservation.data?.storno,
            reservation.fullData?.storno,
            reservation.fullData?.storno_flag,
            reservation.data?.storno_flag
        ];

        for (const candidate of candidates) {
            if (candidate === undefined || candidate === null) {
                continue;
            }

            if (typeof candidate === 'number') {
                if (candidate !== 0) return true;
            } else if (typeof candidate === 'boolean') {
                if (candidate) return true;
            } else if (typeof candidate === 'string') {
                const normalized = candidate.trim().toLowerCase();
                if (!normalized) continue;
                if (['1', 'true', 'yes', 'y', 'ja'].includes(normalized)) return true;
                if (['0', 'false', 'no', 'n', 'nein'].includes(normalized)) continue;
                const numeric = Number(normalized);
                if (!Number.isNaN(numeric) && numeric !== 0) return true;
            }
        }

        return false;
    }

    isDetailStorno(detail) {
        if (!detail) {
            return false;
        }

        const value = detail.storno ?? detail.data?.storno ?? detail.data?.storno_flag;
        if (value === undefined || value === null) {
            return false;
        }

        if (typeof value === 'number') {
            return value !== 0;
        }
        if (typeof value === 'boolean') {
            return value;
        }
        if (typeof value === 'string') {
            const normalized = value.trim().toLowerCase();
            if (!normalized) {
                return false;
            }
            if (['1', 'true', 'yes', 'y', 'ja'].includes(normalized)) {
                return true;
            }
            if (['0', 'false', 'no', 'n', 'nein'].includes(normalized)) {
                return false;
            }
            const numeric = Number(normalized);
            return !Number.isNaN(numeric) && numeric !== 0;
        }

        return false;
    }

    extractNumericCapacity(source) {
        if (!source) {
            return 1;
        }

        const candidates = [
            source.capacity,
            source.data?.capacity,
            source.data?.anz,
            source.fullData?.capacity,
            source.personen,
            source.data?.personen
        ];

        for (const candidate of candidates) {
            if (candidate === undefined || candidate === null) {
                continue;
            }

            const numeric = Number(candidate);
            if (Number.isFinite(numeric) && numeric > 0) {
                return numeric;
            }
        }

        return 1;
    }

    getMasterReservationColor(source) {
        const baseColor = this.themeConfig?.master?.bar || '#4facfe';
        if (!source) {
            return baseColor;
        }

        if (source.color) {
            return source.color;
        }

        const capacity = this.extractNumericCapacity(source);

        if (capacity <= 2) return baseColor;
        if (capacity <= 5) return '#2ecc71';
        if (capacity <= 10) return '#f39c12';
        if (capacity <= 20) return '#e74c3c';
        return '#9b59b6';
    }

    updateNavigationOverview() {
        if (!this.navCanvas || !this.navCtx) {
            return;
        }

        const metrics = this.getNavigationMetrics();
        if (!metrics) {
            const width = this.navCanvas.width / (this.navDevicePixelRatio || 1);
            const height = this.navCanvas.height / (this.navDevicePixelRatio || 1);
            if (width && height) {
                this.navCtx.clearRect(0, 0, width, height);
            }
            return;
        }

        const { width, height } = metrics;
        const ctx = this.navCtx;
        ctx.save();
        ctx.clearRect(0, 0, width, height);

        const sidebarBg = this.themeConfig.sidebar?.bg || '#242424';
        let backgroundFill = sidebarBg;
        try {
            backgroundFill = this.lightenColor(sidebarBg, -14);
        } catch (error) {
            backgroundFill = '#1c1c1c';
        }

        ctx.fillStyle = backgroundFill;
        ctx.fillRect(0, 0, width, height);
        ctx.fillStyle = 'rgba(0, 0, 0, 0.12)';
        ctx.fillRect(0, 0, width, height);

        const monthLabels = [];

        // Alternating month bands and labels
        const monthNames = ['Jan.', 'Feb.', 'Mär.', 'Apr.', 'Mai.', 'Jun.', 'Jul.', 'Aug.', 'Sep.', 'Okt.', 'Nov.', 'Dez.'];
        const monthStart = new Date(metrics.startMs);
        monthStart.setHours(12, 0, 0, 0);
        monthStart.setDate(1);

        // If the computed first-of-month is after the range start, step one month back
        if (monthStart.getTime() > metrics.startMs) {
            monthStart.setMonth(monthStart.getMonth() - 1);
        }

        const monthBandAlpha = 0.08;
        while (monthStart.getTime() < metrics.endMs) {
            const currentMonthStart = monthStart.getTime();
            const nextMonth = new Date(monthStart.getTime());
            nextMonth.setMonth(monthStart.getMonth() + 1);
            const currentMonthEnd = nextMonth.getTime();

            const clampedStart = Math.max(currentMonthStart, metrics.startMs);
            const clampedEnd = Math.min(currentMonthEnd, metrics.endMs);
            if (clampedEnd > clampedStart) {
                const startX = (clampedStart - metrics.startMs) / metrics.totalMs * width;
                const endX = (clampedEnd - metrics.startMs) / metrics.totalMs * width;
                const bandWidth = Math.max(0, endX - startX);
                const monthIndex = monthStart.getMonth();

                if (bandWidth > 0) {
                    ctx.fillStyle = monthIndex % 2 === 0
                        ? 'rgba(255, 255, 255, 0.05)'
                        : `rgba(0, 0, 0, ${monthBandAlpha})`;
                    ctx.fillRect(startX, 0, bandWidth, height);

                    const midX = (startX + endX) / 2;
                    const label = `${monthNames[monthIndex] || ''} ${String(monthStart.getFullYear()).slice(-2)}`.trim();
                    monthLabels.push({ x: midX, text: label });
                }
            }

            monthStart.setMonth(monthStart.getMonth() + 1);
        }

        const segments = this.buildNavigationSegments(metrics.startMs, metrics.endMs);

        if (segments.length > 0) {
            const stackEnds = [];
            segments.forEach(segment => {
                let level = 0;
                while (stackEnds[level] !== undefined && stackEnds[level] > segment.start) {
                    level++;
                }
                stackEnds[level] = segment.end;
                segment.level = level;
            });

            const maxLevel = Math.max(0, ...segments.map(segment => segment.level));
            const totalLevels = maxLevel + 1;
            const drawableHeight = Math.max(1, height);
            let lineThickness = Math.max(1, Math.floor(drawableHeight / Math.max(1, totalLevels)));
            if (lineThickness < 1) {
                lineThickness = 1;
            }

            let maxDrawableLevels = Math.max(1, Math.floor(drawableHeight / lineThickness));
            let levelsToRender = Math.min(totalLevels, maxDrawableLevels);

            while (levelsToRender * lineThickness > drawableHeight && lineThickness > 1) {
                lineThickness -= 1;
                maxDrawableLevels = Math.max(1, Math.floor(drawableHeight / lineThickness));
                levelsToRender = Math.min(totalLevels, maxDrawableLevels);
            }

            const baseColor = this.themeConfig.master?.bar || '#4facfe';

            segments.forEach(segment => {
                const clampedStart = this.clamp(segment.start, metrics.startMs, metrics.endMs);
                const clampedEnd = this.clamp(segment.end, metrics.startMs, metrics.endMs);
                if (clampedEnd <= clampedStart) {
                    return;
                }

                if (segment.level >= levelsToRender) {
                    return;
                }

                const x1 = (clampedStart - metrics.startMs) / metrics.totalMs * width;
                const x2 = (clampedEnd - metrics.startMs) / metrics.totalMs * width;
                const pixelStart = Math.floor(x1);
                const pixelEnd = Math.ceil(x2);
                const segWidth = Math.max(1, pixelEnd - pixelStart);
                const y = height - (segment.level + 1) * lineThickness;

                ctx.globalAlpha = segment.hasRooms ? 0.95 : 1.0;
                ctx.fillStyle = segment.color || baseColor;
                ctx.fillRect(pixelStart, y, segWidth, lineThickness);
            });
            ctx.globalAlpha = 1;
        }

        // Viewport highlight
        if (metrics.viewportWidthMs < metrics.totalMs - 1) {
            const viewportX = (metrics.viewportStartMs - metrics.startMs) / metrics.totalMs * width;
            const viewportW = Math.max(2, metrics.viewportWidthMs / metrics.totalMs * width);
            let clampedViewportX = this.clamp(viewportX, 0, width);
            const clampedViewportEnd = this.clamp(viewportX + viewportW, 0, width);
            let clampedViewportW = Math.max(2, clampedViewportEnd - clampedViewportX);
            if (clampedViewportW < 2) {
                clampedViewportX = this.clamp(viewportX, 0, width - 2);
                clampedViewportW = Math.max(2, Math.min(width - clampedViewportX, viewportW));
            }
            ctx.save();
            ctx.fillStyle = 'rgba(255, 214, 10, 0.28)';
            ctx.fillRect(clampedViewportX, 0, clampedViewportW, height);
            ctx.restore();
        }

        if (monthLabels.length) {
            ctx.save();
            ctx.fillStyle = 'rgba(255, 255, 255, 0.75)';
            ctx.font = `${Math.max(9, Math.min(12, Math.floor(height * 0.45)))}px "Segoe UI", "Helvetica Neue", Arial, sans-serif`;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            const labelY = Math.min(height - 6, Math.max(10, height * 0.45));

            monthLabels.forEach(label => {
                if (label.text) {
                    const clampedX = this.clamp(label.x, 12, width - 12);
                    ctx.fillText(label.text, clampedX, labelY);
                }
            });

            ctx.restore();
        }

        ctx.strokeStyle = 'rgba(255, 255, 255, 0.18)';
        ctx.lineWidth = 1;
        ctx.strokeRect(0.5, 0.5, width - 1, height - 1);

        ctx.restore();
    }

    handleNavigationPointerDown(event) {
        if (!this.navCanvas || !this.navCtx) {
            return;
        }

        const metrics = this.getNavigationMetrics();
        if (!metrics) {
            return;
        }

        this.navIsDragging = true;
        this.navActivePointerId = event.pointerId;
        try {
            this.navCanvas.setPointerCapture(event.pointerId);
        } catch (error) {
            console.warn('setPointerCapture failed:', error);
        }

        const pointerMs = this.getNavigationPointerTimestamp(event, metrics);
        this.navDragOffsetMs = this.clamp(pointerMs - metrics.viewportStartMs, 0, metrics.viewportWidthMs);
        this.navCanvas.classList.add('dragging');

        this.handleNavigationPointerMove(event);
    }

    handleNavigationPointerMove(event) {
        if (!this.navIsDragging) {
            return;
        }

        const metrics = this.getNavigationMetrics();
        if (!metrics) {
            return;
        }

        const pointerMs = this.getNavigationPointerTimestamp(event, metrics);
        let desiredStart = pointerMs - this.navDragOffsetMs;
        const maxStart = metrics.endMs - metrics.viewportWidthMs;
        desiredStart = this.clamp(desiredStart, metrics.startMs, maxStart);
        this.scrollToNavigationViewport(desiredStart, metrics);
    }

    handleNavigationPointerUp(event) {
        if (!this.navIsDragging) {
            return;
        }

        if (this.navCanvas && this.navActivePointerId !== null) {
            try {
                this.navCanvas.releasePointerCapture(this.navActivePointerId);
            } catch (error) {
                // ignore
            }
        }

        this.navIsDragging = false;
        this.navDragOffsetMs = 0;
        this.navActivePointerId = null;
        this.navCanvas?.classList.remove('dragging');
        this.updateNavigationOverview();
    }

    updateDynamicViewportHeight(force = false) {
        if (typeof window === 'undefined') {
            return false;
        }

        const viewport = window.visualViewport;
        const viewportHeight = Math.round(
            viewport?.height ||
            window.innerHeight ||
            document.documentElement?.clientHeight ||
            document.body?.clientHeight ||
            0
        );

        if (!viewportHeight) {
            return false;
        }

        if (!force && this.lastViewportHeightPx === viewportHeight) {
            return false;
        }

        this.lastViewportHeightPx = viewportHeight;
        const cssValue = `${viewportHeight}px`;

        if (document?.documentElement?.style) {
            document.documentElement.style.setProperty('--timeline-vh', cssValue);
        }

        if (this.rootContainer) {
            this.rootContainer.style.height = cssValue;
            this.rootContainer.style.minHeight = cssValue;
            this.rootContainer.style.maxHeight = cssValue;
        }

        return true;
    }

    handleViewportResize(reason = 'viewport_resize') {
        if (this.pendingViewportResize) {
            this.pendingViewportResizeReason = reason;
            return;
        }

        this.pendingViewportResize = true;
        this.pendingViewportResizeReason = reason;

        requestAnimationFrame(() => {
            const finalReason = this.pendingViewportResizeReason || reason;
            this.pendingViewportResize = false;
            this.pendingViewportResizeReason = null;

            this.updateDynamicViewportHeight(true);
            this.resizeCanvas();
            this.scheduleRender(finalReason);
        });
    }

    resizeCanvas() {
        const canvasContainer = this.container.querySelector('.canvas-container');
        const rect = canvasContainer.getBoundingClientRect();

        // Canvas-Höhe: vom oberen Rand bis 20px über dem unteren Rand
        this.updateDynamicViewportHeight();
        const availableHeight = rect.height - 20;
        this.canvas.width = rect.width;
        this.canvas.height = availableHeight;
        this.canvas.style.width = rect.width + 'px';
        this.canvas.style.height = availableHeight + 'px';

        this.sidebarMetricsDirty = true;

        // Separator-Position relativ zur Canvas-Höhe anpassen
        const relativePosition = this.separatorY / this.totalHeight; // Verhältnis beibehalten
        this.separatorY = Math.min(availableHeight * 0.5, availableHeight * relativePosition);

        // Layout-Bereiche aktualisieren
        this.updateLayoutAreas();

        // Scrollbars positionieren
        this.positionScrollbars();

        this.updateNavigationOverview();
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
            // Hide master context menu when scrolling horizontally
            if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') {
                this.hideMasterContextMenu();
            }
            this.updateNavigationOverview();
            this.scheduleRender('scroll_h');
        });

        // Master-Bereich Scroll
        if (masterTrack) {
            masterTrack.addEventListener('scroll', (e) => {
                this.masterScrollY = e.target.scrollTop;
                // Hide master context menu when master band scrolls
                if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') {
                    this.hideMasterContextMenu();
                }
                this.scheduleRender('scroll_master');
            });
        }

        // Rooms-Bereich Scroll
        if (roomsTrack) {
            roomsTrack.addEventListener('scroll', (e) => {
                this.roomsScrollY = e.target.scrollTop;
                // Hide master context menu when rooms scrolls
                if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') {
                    this.hideMasterContextMenu();
                }
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
            if (mouseY >= this.areas.header.y && mouseY < this.areas.header.y + this.areas.header.height && !e.shiftKey) {
                const delta = e.deltaY > 0 ? 5 : -5; // Scroll Richtung steuert Zoom
                const targetDayWidth = Math.max(40, Math.min(250, this.DAY_WIDTH + delta));

                if (targetDayWidth !== this.DAY_WIDTH) {
                    const startX = this.sidebarWidth - this.scrollX;
                    const dayOffset = (mouseX - startX) / this.DAY_WIDTH;

                    this.DAY_WIDTH = targetDayWidth;

                    const range = this.currentRange || this.getTimelineDateRange();
                    const rangeDuration = Math.max(MS_IN_DAY, range.endDate.getTime() - range.startDate.getTime());
                    const totalDays = Math.max(1, Math.ceil(rangeDuration / MS_IN_DAY));
                    const viewportWidth = Math.max(1, this.canvas.width - this.sidebarWidth);
                    let newScrollX = this.sidebarWidth + dayOffset * this.DAY_WIDTH - mouseX;
                    const maxScroll = Math.max(0, totalDays * this.DAY_WIDTH - viewportWidth);
                    newScrollX = Math.max(0, Math.min(maxScroll, newScrollX));

                    if (this.horizontalTrack) {
                        this.horizontalTrack.scrollLeft = newScrollX;
                    }
                    this.scrollX = newScrollX;
                    this.updateViewportCache(this.scrollX, this.roomsScrollY);

                    try {
                        localStorage.setItem('timeline_day_width', this.DAY_WIDTH.toString());
                        console.log('DAY_WIDTH gespeichert:', this.DAY_WIDTH);
                    } catch (error) {
                        console.warn('DAY_WIDTH konnte nicht gespeichert werden:', error);
                    }

                    this.invalidateStackingCache();
                    this.updateNavigationOverview();
                    this.scheduleRender('day_width_change');
                }
                return; // Kein weiteres Scrolling
            }

            // ROOM/Master BAR_HEIGHT ändern wenn Maus über Sidebar-Bereich
            if (mouseX <= this.sidebarWidth && !e.shiftKey) {
                const delta = e.deltaY > 0 ? 1 : -1;
                const targetHeight = this.ROOM_BAR_HEIGHT + delta;
                this.setUnifiedBarHeight(targetHeight, { persist: true, reason: 'wheel_bar_resize' });
                return; // Kein weiteres Scrolling
            }

            if (e.shiftKey) {
                // Shift + Mausrad = horizontal scrollen
                const newScrollX = Math.max(0, this.scrollX + e.deltaY);

                if (horizontalTrack) {
                    horizontalTrack.scrollLeft = newScrollX;
                }

                if (Math.abs(newScrollX - this.scrollX) > 0.5 || !horizontalTrack) {
                    this.scrollX = newScrollX;
                    this.updateViewportCache(this.scrollX, this.roomsScrollY);
                    this.invalidateStackingCache();
                    this.updateNavigationOverview();

                    if (!horizontalTrack) {
                        this.scheduleRender('wheel_shift_scroll');
                    }
                }
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
        if (!this.boundWindowResizeHandler) {
            this.boundWindowResizeHandler = () => this.handleViewportResize('window_resize');
        }
        window.addEventListener('resize', this.boundWindowResizeHandler, { passive: true });

        if ('onorientationchange' in window) {
            if (!this.boundOrientationChangeHandler) {
                this.boundOrientationChangeHandler = () => this.handleViewportResize('orientation_change');
            }
            window.addEventListener('orientationchange', this.boundOrientationChangeHandler, { passive: true });
        }

        if (typeof window.visualViewport !== 'undefined') {
            if (!this.visualViewportResizeHandler) {
                this.visualViewportResizeHandler = () => this.handleViewportResize('visual_viewport_resize');
            }
            window.visualViewport.addEventListener('resize', this.visualViewportResizeHandler, { passive: true });
        }

        // Pointer-Events für Touch-Unterstützung
        this.canvas.addEventListener('pointerdown', (e) => this.handlePointerDown(e), { passive: false });
        this.canvas.addEventListener('pointermove', (e) => this.handlePointerMove(e), { passive: false });
        this.canvas.addEventListener('pointerup', (e) => this.handlePointerUp(e));
        this.canvas.addEventListener('pointercancel', (e) => this.handlePointerUp(e));

        this.canvas.addEventListener('contextmenu', (e) => this.handleContextMenu(e));

        document.addEventListener('click', (event) => {
            // Close radial menu if open and click outside
            if (this.radialMenu?.isVisible()) {
                const root = this.radialMenu.root;
                if (root && !root.contains(event.target)) {
                    this.radialMenu.hide();
                }
            }

            // Close master context menu on outside click
            if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') {
                if (!this.masterMenuEl.contains(event.target)) {
                    this.hideMasterContextMenu();
                }
            }

            // Close date context menu on outside click
            if (this.dateMenuEl && this.dateMenuEl.style.display === 'block') {
                if (!this.dateMenuEl.contains(event.target)) {
                    this.hideDateContextMenu();
                }
            }

            // Close settings menu on outside click
            const settingsMenu = document.getElementById('timeline-settings-menu');
            const settingsToggle = document.getElementById('timeline-settings-toggle');
            if (settingsMenu && settingsMenu.getAttribute('data-open') === 'true') {
                const clickedToggle = settingsToggle && settingsToggle.contains(event.target);
                const clickedInside = settingsMenu.contains(event.target);
                if (!clickedToggle && !clickedInside) {
                    settingsMenu.setAttribute('data-open', 'false');
                    settingsMenu.classList.remove('open');
                    settingsMenu.setAttribute('aria-hidden', 'true');
                    if (settingsToggle) {
                        settingsToggle.setAttribute('aria-expanded', 'false');
                    }
                }
            }
        });

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                if (this.radialMenu?.isVisible()) {
                    this.radialMenu.hide();
                }
                if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') {
                    this.hideMasterContextMenu();
                }
                if (this.dateMenuEl && this.dateMenuEl.style.display === 'block') {
                    this.hideDateContextMenu();
                }
                const settingsMenu = document.getElementById('timeline-settings-menu');
                const settingsToggle = document.getElementById('timeline-settings-toggle');
                if (settingsMenu && settingsMenu.getAttribute('data-open') === 'true') {
                    settingsMenu.setAttribute('data-open', 'false');
                    settingsMenu.classList.remove('open');
                    settingsMenu.setAttribute('aria-hidden', 'true');
                    if (settingsToggle) {
                        settingsToggle.setAttribute('aria-expanded', 'false');
                    }
                }
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
            // Disable throttling for sticky note dragging for live feedback
            const skipThrottling = this.isDraggingStickyNote;
            if (!skipThrottling && now - this.lastDragRender < 16) return; // 60 FPS für andere Drags
            this.lastDragRender = now;

            if (this.isDraggingReservation) {
                this.handleReservationDrag(e);
                this.scheduleRender('drag');
            }
            // Sticky note dragging
            else if (this.isDraggingStickyNote) {
                this.handleStickyNoteDrag(e);
                // Force immediate render for live dragging feedback
                this.lastDragRender = 0; // Reset throttling for sticky notes
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
            // Sticky note drag end
            else if (this.isDraggingStickyNote) {
                this.endStickyNoteDrag();
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

        const rect = this.canvas.getBoundingClientRect();
        const canvasX = e.clientX - rect.left;
        const canvasY = e.clientY - rect.top;

        this.touchPointers.set(e.pointerId, {
            id: e.pointerId,
            clientX: e.clientX,
            clientY: e.clientY,
            canvasX,
            canvasY
        });

        try {
            this.canvas.setPointerCapture(e.pointerId);
        } catch (err) {
            console.warn('Pointer capture failed:', err);
        }

        if (!this.isPinchZoom && !this.isPinchBarResize && this.touchPointers.size >= 2) {
            const headerPointers = Array.from(this.touchPointers.values()).filter(ptr => this.isWithinHeader(ptr.canvasY));
            if (headerPointers.length >= 2) {
                this.beginPinchZoom(headerPointers.slice(0, 2));
                e.preventDefault();
                return;
            }

            const sidebarPointers = Array.from(this.touchPointers.values()).filter(ptr => this.isWithinSidebar(ptr.canvasX));
            if (sidebarPointers.length >= 2) {
                this.beginBarHeightPinch(sidebarPointers.slice(0, 2));
                e.preventDefault();
                return;
            }
        }

        if (this.isPinchZoom || this.isPinchBarResize) {
            e.preventDefault();
            return;
        }

        if (this.activePointerId !== null) {
            return;
        }

        this.activePointerId = e.pointerId;
        this.mouseX = canvasX;
        this.mouseY = canvasY;

        this.handleMouseDown(e);

        const isSeparatorDrag = this.isDraggingSeparator || this.isDraggingBottomSeparator;
        const isReservationDrag = this.isDraggingReservation;
        const isStickyDrag = this.isDraggingStickyNote;

        if (!isSeparatorDrag && !isReservationDrag && !isStickyDrag) {
            this.startTouchPanning(e);
        }

        e.preventDefault();
    }

    handlePointerMove(e) {
        if (e.pointerType !== 'touch') {
            return;
        }

        const rect = this.canvas.getBoundingClientRect();
        const canvasX = e.clientX - rect.left;
        const canvasY = e.clientY - rect.top;

        const pointer = this.touchPointers.get(e.pointerId);
        if (pointer) {
            pointer.clientX = e.clientX;
            pointer.clientY = e.clientY;
            pointer.canvasX = canvasX;
            pointer.canvasY = canvasY;
        }

        if (this.isPinchZoom) {
            this.updatePinchZoom();
            e.preventDefault();
            return;
        }

        if (this.isPinchBarResize) {
            this.updateBarHeightPinch();
            e.preventDefault();
            return;
        }

        if (this.activePointerId !== e.pointerId) {
            try {
                this.canvas.releasePointerCapture(e.pointerId);
            } catch (err) {
                // ignore
            }
            return;
        }

        this.mouseX = canvasX;
        this.mouseY = canvasY;

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

        if (this.isDraggingStickyNote) {
            this.handleStickyNoteDrag(e);
            e.preventDefault();
            return;
        }

        if (this.isTouchPanning && this.panContext) {
            this.updateTouchPan(e);
            e.preventDefault();
        }
    }

    handlePointerUp(e) {
        if (e.pointerType !== 'touch') {
            return;
        }

        this.touchPointers.delete(e.pointerId);

        if (this.isPinchZoom) {
            if (this.touchPointers.size >= 2) {
                this.resetPinchBaseline();
            } else {
                this.endPinchZoom();
            }

            try {
                this.canvas.releasePointerCapture(e.pointerId);
            } catch (err) {
                // ignore
            }

            e.preventDefault();
            return;
        }

        if (this.isPinchBarResize) {
            if (this.touchPointers.size >= 2) {
                this.resetBarPinchBaseline();
            } else {
                this.endBarHeightPinch();
            }

            try {
                this.canvas.releasePointerCapture(e.pointerId);
            } catch (err) {
                // ignore
            }

            e.preventDefault();
            return;
        }

        if (this.activePointerId !== e.pointerId) {
            try {
                this.canvas.releasePointerCapture(e.pointerId);
            } catch (err) {
                // ignore
            }
            return;
        }

        if (this.isDraggingReservation) {
            this.finishReservationDrag();
            this.scheduleRender('drag_touch_end');
        } else if (this.isDraggingStickyNote) {
            this.endStickyNoteDrag();
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

        try {
            this.canvas.releasePointerCapture(e.pointerId);
        } catch (err) {
            // ignore
        }
        this.activePointerId = null;
        e.preventDefault();
    }

    isWithinHeader(canvasY) {
        const header = this.areas?.header;
        if (!header) return false;
        return canvasY >= header.y && canvasY <= header.y + header.height;
    }

    isWithinSidebar(canvasX) {
        return canvasX <= this.sidebarWidth;
    }

    beginPinchZoom(pointers) {
        if (!pointers || pointers.length < 2) {
            return;
        }

        const [first, second] = pointers;
        const distance = this.distanceBetweenPoints(first, second);
        if (!distance || distance <= 0) {
            return;
        }

        if (this.activePointerId !== null) {
            try {
                this.canvas.releasePointerCapture(this.activePointerId);
            } catch (err) {
                // ignore
            }
            this.activePointerId = null;
        }

        this.isPinchZoom = true;
        this.isTouchPanning = false;
        this.panContext = null;
        this.pinchStartDistance = distance;
        this.pinchStartDayWidth = this.DAY_WIDTH;
        this.pinchCenterCanvasX = (first.canvasX + second.canvasX) / 2;

        const startX = this.sidebarWidth - this.scrollX;
        this.pinchFocusDayOffset = (this.pinchCenterCanvasX - startX) / Math.max(1, this.DAY_WIDTH);
        if (!Number.isFinite(this.pinchFocusDayOffset)) {
            this.pinchFocusDayOffset = 0;
        }

        // Ensure new pointers maintain capture for continued updates
        pointers.forEach(ptr => {
            try {
                this.canvas.setPointerCapture(ptr.id);
            } catch (err) {
                // ignore
            }
        });

        this.scheduleRender('pinch_start');
    }

    updatePinchZoom() {
        if (!this.isPinchZoom || this.touchPointers.size < 2) {
            return;
        }

        const headerPointers = Array.from(this.touchPointers.values()).filter(ptr => this.isWithinHeader(ptr.canvasY));
        if (headerPointers.length < 2) {
            // If fingers move out of header, end pinch gracefully
            this.endPinchZoom();
            return;
        }

        const [first, second] = headerPointers;
        const distance = this.distanceBetweenPoints(first, second);
        if (!distance || distance <= 0 || !this.pinchStartDistance) {
            return;
        }

        const scale = distance / this.pinchStartDistance;
        const targetDayWidth = this.clamp(this.pinchStartDayWidth * scale, 40, 250);

        if (Math.abs(targetDayWidth - this.DAY_WIDTH) < 0.05) {
            return;
        }

        this.DAY_WIDTH = targetDayWidth;

        this.pinchCenterCanvasX = (first.canvasX + second.canvasX) / 2;
        const startX = this.sidebarWidth - this.scrollX;
        const focusOffset = Number.isFinite(this.pinchFocusDayOffset)
            ? this.pinchFocusDayOffset
            : (this.pinchCenterCanvasX - startX) / Math.max(1, this.DAY_WIDTH);

        const range = this.currentRange || this.getTimelineDateRange();
        const rangeDuration = Math.max(MS_IN_DAY, range.endDate.getTime() - range.startDate.getTime());
        const totalDays = Math.max(1, Math.ceil(rangeDuration / MS_IN_DAY));
        const viewportWidth = Math.max(1, this.canvas.width - this.sidebarWidth);

        let newScrollX = this.sidebarWidth + focusOffset * this.DAY_WIDTH - this.pinchCenterCanvasX;
        const maxScroll = Math.max(0, totalDays * this.DAY_WIDTH - viewportWidth);
        newScrollX = this.clamp(newScrollX, 0, maxScroll);

        if (this.horizontalTrack) {
            this.horizontalTrack.scrollLeft = newScrollX;
        }
        this.scrollX = newScrollX;

        // Recompute focus offset for stability on subsequent moves
        const newStartX = this.sidebarWidth - this.scrollX;
        this.pinchFocusDayOffset = (this.pinchCenterCanvasX - newStartX) / Math.max(1, this.DAY_WIDTH);

        this.updateViewportCache(this.scrollX, this.roomsScrollY);
        this.invalidateStackingCache();
        this.updateNavigationOverview();
        this.scheduleRender('pinch_zoom');
    }

    endPinchZoom() {
        if (!this.isPinchZoom) {
            return;
        }

        this.isPinchZoom = false;

        try {
            localStorage.setItem('timeline_day_width', this.DAY_WIDTH.toString());
        } catch (error) {
            console.warn('DAY_WIDTH konnte nicht gespeichert werden (pinch):', error);
        }

        this.scheduleRender('pinch_end');

        const remainingPointers = Array.from(this.touchPointers.values());
        if (remainingPointers.length === 1) {
            const pointer = remainingPointers[0];
            this.activePointerId = pointer.id;
            this.mouseX = pointer.canvasX;
            this.mouseY = pointer.canvasY;
            try {
                this.canvas.setPointerCapture(pointer.id);
            } catch (err) {
                // ignore
            }
            this.isTouchPanning = false;
            this.panContext = null;
            this.startTouchPanning({ clientX: pointer.clientX, clientY: pointer.clientY });
        } else {
            this.activePointerId = null;
            this.isTouchPanning = false;
            this.panContext = null;
        }

        this.pinchStartDistance = 0;
        this.pinchStartDayWidth = 0;
        this.pinchFocusDayOffset = 0;
        this.pinchCenterCanvasX = 0;
    }

    resetPinchBaseline() {
        if (!this.isPinchZoom || this.touchPointers.size < 2) {
            this.endPinchZoom();
            return;
        }

        const headerPointers = Array.from(this.touchPointers.values()).filter(ptr => this.isWithinHeader(ptr.canvasY));
        if (headerPointers.length < 2) {
            this.endPinchZoom();
            return;
        }

        const [first, second] = headerPointers;
        this.pinchStartDistance = this.distanceBetweenPoints(first, second);
        this.pinchStartDayWidth = this.DAY_WIDTH;
        this.pinchCenterCanvasX = (first.canvasX + second.canvasX) / 2;
        const startX = this.sidebarWidth - this.scrollX;
        this.pinchFocusDayOffset = (this.pinchCenterCanvasX - startX) / Math.max(1, this.DAY_WIDTH);
    }

    beginBarHeightPinch(pointers) {
        if (!pointers || pointers.length < 2) {
            return;
        }

        const [first, second] = pointers;
        const distance = this.verticalDistanceBetweenPoints(first, second);
        if (!distance || distance <= 0) {
            return;
        }

        if (this.activePointerId !== null) {
            try {
                this.canvas.releasePointerCapture(this.activePointerId);
            } catch (err) {
                // ignore
            }
            this.activePointerId = null;
        }

        this.isPinchBarResize = true;
        this.isTouchPanning = false;
        this.panContext = null;
        this.pinchBarStartDistance = distance;
        this.pinchBarStartHeight = this.ROOM_BAR_HEIGHT;

        pointers.forEach(ptr => {
            try {
                this.canvas.setPointerCapture(ptr.id);
            } catch (err) {
                // ignore
            }
        });

        this.scheduleRender('pinch_bar_start');
    }

    updateBarHeightPinch() {
        if (!this.isPinchBarResize || this.touchPointers.size < 2) {
            return;
        }

        const sidebarPointers = Array.from(this.touchPointers.values()).filter(ptr => this.isWithinSidebar(ptr.canvasX));
        if (sidebarPointers.length < 2) {
            this.endBarHeightPinch();
            return;
        }

        const [first, second] = sidebarPointers;
        const distance = this.verticalDistanceBetweenPoints(first, second);
        if (!distance || distance <= 0 || !this.pinchBarStartDistance) {
            return;
        }

        const scale = distance / this.pinchBarStartDistance;
        const targetHeight = this.pinchBarStartHeight * scale;
        const changed = this.setUnifiedBarHeight(targetHeight, {
            persist: false,
            reason: 'pinch_bar_resize'
        });

        if (changed) {
            // Baseline refresh for smooth scaling
            this.pinchBarStartHeight = this.ROOM_BAR_HEIGHT;
            this.pinchBarStartDistance = distance;
        }
    }

    endBarHeightPinch() {
        if (!this.isPinchBarResize) {
            return;
        }

        this.isPinchBarResize = false;
        this.persistUnifiedBarHeight();

        this.scheduleRender('pinch_bar_end');

        const remainingPointers = Array.from(this.touchPointers.values());
        if (remainingPointers.length === 1) {
            const pointer = remainingPointers[0];
            this.activePointerId = pointer.id;
            this.mouseX = pointer.canvasX;
            this.mouseY = pointer.canvasY;
            try {
                this.canvas.setPointerCapture(pointer.id);
            } catch (err) {
                // ignore
            }
            this.isTouchPanning = false;
            this.panContext = null;
            this.startTouchPanning({ clientX: pointer.clientX, clientY: pointer.clientY });
        } else {
            this.activePointerId = null;
            this.isTouchPanning = false;
            this.panContext = null;
        }

        this.pinchBarStartDistance = 0;
        this.pinchBarStartHeight = this.ROOM_BAR_HEIGHT;
    }

    resetBarPinchBaseline() {
        if (!this.isPinchBarResize || this.touchPointers.size < 2) {
            this.endBarHeightPinch();
            return;
        }

        const sidebarPointers = Array.from(this.touchPointers.values()).filter(ptr => this.isWithinSidebar(ptr.canvasX));
        if (sidebarPointers.length < 2) {
            this.endBarHeightPinch();
            return;
        }

        const [first, second] = sidebarPointers;
        this.pinchBarStartDistance = this.verticalDistanceBetweenPoints(first, second);
        this.pinchBarStartHeight = this.ROOM_BAR_HEIGHT;
    }

    verticalDistanceBetweenPoints(a, b) {
        if (!a || !b) return 0;
        return Math.abs(a.clientY - b.clientY);
    }

    distanceBetweenPoints(a, b) {
        if (!a || !b) return 0;
        const dx = a.clientX - b.clientX;
        const dy = a.clientY - b.clientY;
        return Math.sqrt(dx * dx + dy * dy);
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

        if (!this.isDraggingReservation) {
            const movement = Math.max(Math.abs(deltaX), Math.abs(deltaY));
            if (movement > 8) {
                this.clearReservationFocus({ reason: 'reservation_focus_cleared_by_pan' });
            }
        }

        if (this.panContext.allowHorizontal && this.horizontalTrack) {
            const maxScrollX = Math.max(0, this.horizontalTrack.scrollWidth - this.horizontalTrack.clientWidth);
            const newScrollX = this.clamp(this.panStart.scrollX - deltaX, 0, maxScrollX);

            if (Math.abs(newScrollX - this.scrollX) > 0.5) {
                this.horizontalTrack.scrollLeft = newScrollX;
                this.scrollX = newScrollX;
                this.updateViewportCache(this.scrollX, this.roomsScrollY);
                this.invalidateStackingCache();
                this.updateNavigationOverview();
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

    computeBarFontSize(barHeight) {
        if (!Number.isFinite(barHeight)) {
            return 12;
        }
        const clampedHeight = this.clamp(barHeight, 6, 100);
        const dynamicSize = Math.round(clampedHeight * 0.6);
        return this.clamp(dynamicSize, 8, Math.max(8, Math.floor(clampedHeight - 2)));
    }

    applyPersistentSizingFromStorage(options = {}) {
        const { skipRecalculate = false } = options;
        let dayWidthChanged = false;
        let barHeightChanged = false;

        if (!this.themeConfig.sidebar) {
            this.themeConfig.sidebar = {};
        }

        try {
            const savedDayWidth = localStorage.getItem('timeline_day_width');
            if (savedDayWidth) {
                const parsedWidth = parseInt(savedDayWidth, 10);
                if (Number.isFinite(parsedWidth) && parsedWidth >= 40 && parsedWidth <= 250 && Math.abs(parsedWidth - this.DAY_WIDTH) > 0.01) {
                    this.DAY_WIDTH = parsedWidth;
                    dayWidthChanged = true;
                }
            }
        } catch (error) {
            console.warn('DAY_WIDTH konnte nicht aus localStorage geladen werden:', error);
        }

        try {
            const savedBarHeight = localStorage.getItem('timeline_room_bar_height');
            if (savedBarHeight) {
                const parsedHeight = parseInt(savedBarHeight, 10);
                if (Number.isFinite(parsedHeight) && parsedHeight >= 10 && parsedHeight <= 35 && Math.abs(parsedHeight - this.ROOM_BAR_HEIGHT) > 0.01) {
                    this.ROOM_BAR_HEIGHT = parsedHeight;
                    this.MASTER_BAR_HEIGHT = parsedHeight;
                    this.sidebarFontSize = Math.max(9, Math.min(22, Math.round(this.computeBarFontSize(this.ROOM_BAR_HEIGHT) * 0.95)));
                    this.themeConfig.sidebar.fontSize = this.sidebarFontSize;
                    this.sidebarMetricsDirty = true;
                    barHeightChanged = true;
                }
            }
        } catch (error) {
            console.warn('ROOM_BAR_HEIGHT konnte nicht aus localStorage geladen werden:', error);
        }

        this.themeConfig.dayWidth = this.DAY_WIDTH;

        if (!this.themeConfig.master) {
            this.themeConfig.master = {};
        }
        if (!this.themeConfig.room) {
            this.themeConfig.room = {};
        }

        this.themeConfig.master.barHeight = this.MASTER_BAR_HEIGHT;
        this.themeConfig.master.fontSize = this.computeBarFontSize(this.MASTER_BAR_HEIGHT);
        this.themeConfig.room.barHeight = this.ROOM_BAR_HEIGHT;
        this.themeConfig.room.fontSize = this.computeBarFontSize(this.ROOM_BAR_HEIGHT);

        if (barHeightChanged && !skipRecalculate) {
            this.invalidateStackingCache();
            this.recalculateRoomHeights();
        }

        return { dayWidthChanged, barHeightChanged };
    }

    collectSidebarLabels() {
        const labelSource = [];

        if (this.roomsById && this.roomsById.size) {
            for (const room of this.roomsById.values()) {
                const caption = room?.display_name || room?.displayName || room?.caption || room?.room || room?.name || room?.id;
                if (caption) {
                    labelSource.push(String(caption));
                }
            }
        } else if (Array.isArray(rooms) && rooms.length) {
            rooms.forEach(room => {
                const caption = room?.display_name || room?.displayName || room?.caption || room?.room || room?.name || room?.id;
                if (caption) {
                    labelSource.push(String(caption));
                }
            });
        }

        if (!labelSource.length) {
            labelSource.push('Zimmer');
        }

        return labelSource;
    }

    updateSidebarMetrics(force = false) {
        if (!this.ctx) {
            this.sidebarMetricsDirty = true;
            return false;
        }

        if (!force && !this.sidebarMetricsDirty) {
            return false;
        }

        const targetFontSize = Math.max(9, Math.min(22, Math.round(this.computeBarFontSize(this.ROOM_BAR_HEIGHT) * 0.95)));
        const fontFamily = this.themeConfig.sidebar.fontFamily || 'Arial';
        const previousFont = this.ctx.font;

        this.ctx.save();
        this.ctx.font = `${targetFontSize}px ${fontFamily}`;

        let widestLabel = 0;
        for (const label of this.collectSidebarLabels()) {
            if (!label) continue;
            const metrics = this.ctx.measureText(label);
            widestLabel = Math.max(widestLabel, metrics.width);
        }

        this.ctx.restore();
        this.ctx.font = previousFont;

        const widthPadding = 28;
        const desiredWidth = Math.ceil(widestLabel + widthPadding);
        const minWidth = 80;
        const maxWidth = Math.max(minWidth, Math.floor(this.canvas ? this.canvas.width * 0.3 : 240));
        const clampedWidth = this.clamp(desiredWidth || this.sidebarWidth, minWidth, maxWidth);

        let metricsChanged = false;

        if (Math.abs(targetFontSize - this.sidebarFontSize) >= 0.1) {
            this.sidebarFontSize = targetFontSize;
            this.themeConfig.sidebar.fontSize = this.sidebarFontSize;
            metricsChanged = true;
        }

        if (Math.abs(clampedWidth - this.sidebarWidth) >= 0.5) {
            this.sidebarWidth = clampedWidth;
            metricsChanged = true;
        }

        this.sidebarMetricsDirty = false;
        return metricsChanged;
    }

    setUnifiedBarHeight(newHeight, options = {}) {
        const { persist = true, reason = 'bar_height_change' } = options;
        const clampedHeight = this.clamp(Number(newHeight) || 0, 10, 35);

        if (Math.abs(clampedHeight - this.ROOM_BAR_HEIGHT) < 0.01 &&
            Math.abs(clampedHeight - this.MASTER_BAR_HEIGHT) < 0.01) {
            return false;
        }

        this.ROOM_BAR_HEIGHT = clampedHeight;
        this.MASTER_BAR_HEIGHT = clampedHeight;

        if (this.themeConfig.master) {
            this.themeConfig.master.barHeight = clampedHeight;
            this.themeConfig.master.fontSize = this.computeBarFontSize(clampedHeight);
        }
        if (this.themeConfig.room) {
            this.themeConfig.room.barHeight = clampedHeight;
            this.themeConfig.room.fontSize = this.computeBarFontSize(clampedHeight);
        }

        this.sidebarFontSize = Math.max(9, Math.min(22, Math.round(this.computeBarFontSize(this.ROOM_BAR_HEIGHT) * 0.95)));
        this.themeConfig.sidebar.fontSize = this.sidebarFontSize;
        this.sidebarMetricsDirty = true;

        this.invalidateStackingCache();
        this.recalculateRoomHeights();

        if (persist) {
            this.persistUnifiedBarHeight();
        }

        this.scheduleRender(reason);
        return true;
    }

    persistUnifiedBarHeight() {
        try {
            localStorage.setItem('timeline_room_bar_height', this.ROOM_BAR_HEIGHT.toString());
            console.log('ROOM/Master BAR_HEIGHT gespeichert:', this.ROOM_BAR_HEIGHT);
        } catch (error) {
            console.warn('ROOM_BAR_HEIGHT konnte nicht gespeichert werden:', error);
        }
    }

    updateLayoutAreas() {
        if (!this.canvas) {
            return;
        }

        const maxTopSeparatorY = this.canvas.height * 0.5;
        const minTopSeparatorY = this.areas.header.y + this.areas.header.height + 60;
        const minRoomGap = Math.max(120, this.ROOM_BAR_HEIGHT * 4);
        const minHistogramHeight = Math.max(80, this.ROOM_BAR_HEIGHT * 4);
        const footerPadding = Math.max(4, Math.min(12, this.sidebarFontSize ? this.sidebarFontSize * 0.5 : 10));

        this.separatorY = this.clamp(this.separatorY, minTopSeparatorY, maxTopSeparatorY);

        const minBottomSeparatorY = Math.max(this.canvas.height * 0.55, this.separatorY + minRoomGap);
        const maxBottomCandidate = this.canvas.height - footerPadding - minHistogramHeight;
        const maxBottomSeparatorY = Math.max(minBottomSeparatorY, maxBottomCandidate);

        const maxHistogramHeight = Math.max(minHistogramHeight, this.canvas.height - footerPadding - (this.separatorY + minRoomGap));
        let preferredHistogramHeight = Number.isFinite(this.histogramPreferredHeight) && this.histogramPreferredHeight > 0
            ? this.histogramPreferredHeight
            : Math.max(minHistogramHeight, this.canvas.height - this.bottomSeparatorY - footerPadding);

        preferredHistogramHeight = this.clamp(preferredHistogramHeight, minHistogramHeight, maxHistogramHeight);

        if (!this.isDraggingBottomSeparator && !this.forceHistogramLayout) {
            this.bottomSeparatorY = this.canvas.height - footerPadding - preferredHistogramHeight;
        }

        this.bottomSeparatorY = this.clamp(this.bottomSeparatorY, minBottomSeparatorY, maxBottomSeparatorY);
        this.bottomSeparatorY = Math.max(this.bottomSeparatorY, this.separatorY + minRoomGap);

        let residualHistogramSpace = this.canvas.height - this.bottomSeparatorY - footerPadding;
        if (residualHistogramSpace < minHistogramHeight) {
            this.bottomSeparatorY = this.canvas.height - footerPadding - minHistogramHeight;
            this.bottomSeparatorY = Math.max(this.bottomSeparatorY, this.separatorY + minRoomGap);
            residualHistogramSpace = this.canvas.height - this.bottomSeparatorY - footerPadding;
        }

        const headerBottom = this.areas.header.y + this.areas.header.height;

        this.areas.master.y = headerBottom;
        this.areas.master.height = Math.max(60, this.separatorY - headerBottom);

        this.areas.rooms.y = this.separatorY;
        this.areas.rooms.height = Math.max(60, this.bottomSeparatorY - this.separatorY);

        this.areas.histogram.y = this.bottomSeparatorY;
        const availableHistogramSpace = Math.max(minHistogramHeight, residualHistogramSpace);
        this.areas.histogram.height = availableHistogramSpace;

        if (this.isDraggingBottomSeparator || !Number.isFinite(this.histogramPreferredHeight) || this.histogramPreferredHeight <= 0 || this.forceHistogramLayout) {
            this.histogramPreferredHeight = availableHistogramSpace;
        }
        this.forceHistogramLayout = false;

        this.totalHeight = this.canvas.height;
        if (this.totalHeight > 0) {
            this.topSeparatorRatio = this.separatorY / this.totalHeight;
            this.bottomSeparatorRatio = this.bottomSeparatorY / this.totalHeight;
        }

        if (typeof window !== 'undefined' && window.debugTimeline) {
            this.layoutMetrics = {
                canvasHeight: this.canvas.height,
                menu: { y: this.areas.menu.y, height: this.areas.menu.height },
                header: { y: this.areas.header.y, height: this.areas.header.height },
                master: { y: this.areas.master.y, height: this.areas.master.height },
                rooms: { y: this.areas.rooms.y, height: this.areas.rooms.height },
                histogram: { y: this.areas.histogram.y, height: this.areas.histogram.height, footerPadding }
            };
        }

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

        const isTouchLike = this.isTouchLikePointer(e);

        // Reservierung Drag & Drop nur wenn im Rooms-Bereich
        if (mouseY >= this.areas.rooms.y && mouseY <= this.areas.rooms.y + this.areas.rooms.height) {
            // Check for sticky note click first (higher priority than reservation drag)
            const stickyNote = this.findStickyNoteAt(mouseX, mouseY);
            if (stickyNote) {
                this.startStickyNoteDrag(stickyNote, mouseX, mouseY, e);
                e.preventDefault();
                e.stopPropagation();
                return;
            }

            const reservation = this.findReservationAt(mouseX, mouseY);

            if (reservation) {
                const focusKey = this.getReservationFocusKey(reservation);
                const focusSource = isTouchLike ? 'touch' : 'mouse';
                const focusChanged = focusKey ? this.setFocusedReservation(reservation, { source: focusSource }) : false;

                if (!focusKey && this.focusedReservationKey) {
                    this.clearReservationFocus({ reason: 'reservation_focus_missing_key', silent: true });
                }

                if (isTouchLike && focusKey && focusChanged) {
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }

                if (focusKey && !focusChanged) {
                    this.focusedReservationAt = Date.now();
                }

                this.startReservationDrag(reservation, mouseX, mouseY, e);
                e.preventDefault();
                e.stopPropagation();
            } else {
                this.clearReservationFocus({ reason: 'reservation_focus_clear_room_background' });
            }
        } else {
            this.clearReservationFocus({ reason: 'reservation_focus_clear_outside' });
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
        if (!this.canvas) return;

        const rect = this.canvas.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        // Header-Bereich: Datums-spezifisches Kontextmenü
        if (mouseY >= this.areas.header.y && mouseY <= this.areas.header.y + this.areas.header.height) {
            if (mouseX >= this.sidebarWidth) {
                const targetDate = this.resolveDateFromHeader(mouseX);
                if (targetDate) {
                    e.preventDefault();
                    if (this.radialMenu?.isVisible()) this.radialMenu.hide();
                    if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') this.hideMasterContextMenu();
                    this.showDateContextMenu(targetDate);
                    return;
                }
            }

            // Kein gültiges Datum getroffen -> vorhandenes Menü schließen
            if (this.dateMenuEl && this.dateMenuEl.style.display === 'block') {
                this.hideDateContextMenu();
            }
        }

        // 1) Master area context menu
        if (mouseY >= this.areas.master.y && mouseY <= this.separatorY) {
            const hitMaster = this.findMasterReservationAt(mouseX, mouseY);
            if (hitMaster) {
                e.preventDefault();
                this.showMasterContextMenu(hitMaster, e.clientX, e.clientY);
                // Also hide radial menu if it was open
                if (this.radialMenu?.isVisible()) this.radialMenu.hide();
                if (this.dateMenuEl && this.dateMenuEl.style.display === 'block') this.hideDateContextMenu();
                return;
            } else {
                // Clicked in master area but not on a bar -> hide menus
                if (this.radialMenu?.isVisible()) this.radialMenu.hide();
                if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') this.hideMasterContextMenu();
                if (this.dateMenuEl && this.dateMenuEl.style.display === 'block') this.hideDateContextMenu();
                e.preventDefault();
                return;
            }
        }

        // 2) Rooms area radial menu
        if (!this.radialMenu) return;
        if (mouseY >= this.areas.rooms.y && mouseY <= this.areas.rooms.y + this.areas.rooms.height) {
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
            // Ensure master context menu is closed when opening radial
            if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') this.hideMasterContextMenu();
            if (this.dateMenuEl && this.dateMenuEl.style.display === 'block') this.hideDateContextMenu();
            this.radialMenu.show(detail, e.clientX, e.clientY, rings);
        } else {
            // Outside of actionable areas: close menus and let default menu appear
            if (this.radialMenu?.isVisible()) this.radialMenu.hide();
            if (this.masterMenuEl && this.masterMenuEl.style.display === 'block') this.hideMasterContextMenu();
            if (this.dateMenuEl && this.dateMenuEl.style.display === 'block') this.hideDateContextMenu();
        }
    }

    // Hit-test for master bars based on the same layout logic as renderMasterArea
    findMasterReservationAt(mouseX, mouseY) {
        const area = this.areas.master;
        if (!area) return null;

        // Only consider clicks inside the master drawing band
        if (mouseX < this.sidebarWidth || mouseY < area.y || mouseY > this.separatorY) return null;

        const { startDate, endDate } = this.getTimelineDateRange();
        const startX = this.sidebarWidth - this.scrollX;
        const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.25;
        const barHeight = this.MASTER_BAR_HEIGHT || 14;

        // Build stack levels incrementally like in renderMasterArea
        const stackLevels = [];
        const sortedReservations = reservations
            .filter(reservation => this.isMasterReservationVisible(reservation))
            .sort((a, b) =>
                new Date(a.start).getTime() - new Date(b.start).getTime()
            );

        for (const reservation of sortedReservations) {
            const checkinDate = new Date(reservation.start);
            checkinDate.setHours(12, 0, 0, 0);
            const checkoutDate = new Date(reservation.end);
            checkoutDate.setHours(12, 0, 0, 0);

            const startOffset = (checkinDate.getTime() - startDate.getTime()) / MS_IN_DAY;
            const duration = (checkoutDate.getTime() - checkinDate.getTime()) / MS_IN_DAY;

            const left = startX + (startOffset + 0.01) * this.DAY_WIDTH;
            const width = (duration - 0.005) * this.DAY_WIDTH;

            // Skip far outside viewport for performance (same heuristic as renderer)
            const viewportLeft = this.scrollX - 1000;
            const viewportRight = this.scrollX + this.canvas.width + 1000;
            const absoluteLeft = this.sidebarWidth + (startOffset + 0.01) * this.DAY_WIDTH;
            const absoluteRight = absoluteLeft + width;
            if (absoluteRight < viewportLeft || absoluteLeft > viewportRight) {
                continue;
            }

            // Determine stack level
            let stackLevel = 0;
            while (stackLevel < stackLevels.length && stackLevels[stackLevel] > left + OVERLAP_TOLERANCE) {
                stackLevel++;
            }
            while (stackLevels.length <= stackLevel) stackLevels.push(0);
            stackLevels[stackLevel] = left + width + 5;

            const top = area.y + 10 + (stackLevel * (barHeight + 2)) - this.masterScrollY;

            // Hit test
            if (mouseX >= left && mouseX <= left + width && mouseY >= top && mouseY <= top + barHeight) {
                // Enrich master hit object with AV-Res.id so downstream modals can resolve it reliably
                // Prefer the canonical AV-Res.id from fullData.id (provided by API)
                let avResId = undefined;
                const fd = reservation.fullData || reservation.data || {};
                if (fd && (fd.id !== undefined && fd.id !== null)) {
                    avResId = fd.id;
                }
                // Fallbacks: direct props or parse from string id like "res_123"
                if (avResId === undefined || avResId === null) {
                    avResId = reservation.res_id ?? reservation.reservation_id ?? reservation.resid ?? null;
                }
                if ((avResId === undefined || avResId === null) && typeof reservation.id === 'string') {
                    const m = reservation.id.match(/res_(\d+)/);
                    if (m) avResId = Number(m[1]);
                }

                // Normalize to number when possible
                const avResIdNum = (avResId !== undefined && avResId !== null && !Number.isNaN(Number(avResId)))
                    ? Number(avResId)
                    : avResId;

                // Build a shallow copy and attach res_id in expected places
                const enriched = { ...reservation };
                // Top-level aliases expected by existing flows
                if (avResIdNum !== undefined && avResIdNum !== null) {
                    enriched.res_id = avResIdNum;
                    enriched.reservation_id = enriched.reservation_id ?? avResIdNum;
                    enriched.resid = enriched.resid ?? avResIdNum;
                }
                // Also expose a data object (modal checks detail.data?.res_id)
                const dataObj = { ...(reservation.fullData || reservation.data || {}) };
                if (avResIdNum !== undefined && avResIdNum !== null) {
                    dataObj.res_id = avResIdNum;
                }
                enriched.data = dataObj;

                return { ...enriched, left, width, top, barHeight, stackLevel };
            }
        }

        return null;
    }

    // ===== Master Context Menu (DOM) =====
    ensureMasterContextMenu() {
        // Inject styles once
        this.injectMasterContextMenuStyles();

        if (!this.masterMenuEl) {
            const el = document.createElement('div');
            el.id = 'master-context-menu';
            el.setAttribute('aria-hidden', 'true');
            el.style.display = 'none';
            el.innerHTML = `
                <div class="mcm-card">
                    <button class="mcm-item" data-action="dataset">Datensatz</button>
                    <button class="mcm-item" data-action="to-room">in Zimmer</button>
                </div>
            `;
            document.body.appendChild(el);
            this.masterMenuEl = el;

            // Delegate click events
            el.addEventListener('click', (evt) => {
                const btn = evt.target.closest('.mcm-item');
                if (!btn) return;
                const action = btn.getAttribute('data-action');
                const detail = this.masterMenuContext || null;
                if (action === 'dataset') this.onMasterMenuDataset(detail);
                if (action === 'to-room') this.onMasterMenuToRoom(detail);
                this.hideMasterContextMenu();
                evt.preventDefault();
                evt.stopPropagation();
            });
        }
    }

    injectMasterContextMenuStyles() {
        if (document.getElementById('master-context-menu-styles')) return;
        const style = document.createElement('style');
        style.id = 'master-context-menu-styles';
        style.textContent = `
            #master-context-menu {
                position: fixed;
                z-index: 9999;
                left: 0; top: 0;
                transform: translate(-50%, -50%);
                user-select: none;
            }
            #master-context-menu .mcm-card {
                background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
                border: 1px solid rgba(255,255,255,0.06);
                box-shadow: 0 12px 28px rgba(0,0,0,0.45), 0 2px 6px rgba(0,0,0,0.2);
                border-radius: 12px;
                padding: 8px;
                min-width: 180px;
                display: flex;
                flex-direction: column;
                gap: 6px;
                backdrop-filter: blur(4px);
            }
            #master-context-menu .mcm-item {
                appearance: none;
                border: none;
                background: linear-gradient(90deg, rgba(99,102,241,0.18), rgba(236,72,153,0.18));
                color: #e5e7eb;
                padding: 10px 12px;
                border-radius: 8px;
                font-size: 13px;
                text-align: left;
                cursor: pointer;
                transition: transform .06s ease, background .2s ease, box-shadow .2s ease;
                box-shadow: inset 0 0 0 1px rgba(255,255,255,0.06);
            }
            #master-context-menu .mcm-item:hover {
                background: linear-gradient(90deg, rgba(99,102,241,0.35), rgba(236,72,153,0.35));
                transform: translateY(-1px);
                box-shadow: inset 0 0 0 1px rgba(255,255,255,0.15), 0 6px 14px rgba(99,102,241,0.18);
            }
            #master-context-menu .mcm-item:active {
                transform: translateY(0);
                background: linear-gradient(90deg, rgba(99,102,241,0.45), rgba(236,72,153,0.45));
            }
        `;
        document.head.appendChild(style);
    }

    showMasterContextMenu(detail, clientX, clientY) {
        this.ensureMasterContextMenu();
        if (!this.masterMenuEl) return;
        this.masterMenuContext = detail;

        // Position near cursor but keep fully in viewport
        const menuRect = { width: 200, height: 100 };
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        let x = clientX;
        let y = clientY;
        if (x + menuRect.width > vw) x = vw - menuRect.width - 8;
        if (y + menuRect.height > vh) y = vh - menuRect.height - 8;

        this.masterMenuEl.style.left = `${x}px`;
        this.masterMenuEl.style.top = `${y}px`;
        this.masterMenuEl.style.display = 'block';
        this.masterMenuEl.setAttribute('aria-hidden', 'false');
    }

    hideMasterContextMenu() {
        if (!this.masterMenuEl) return;
        // Move focus out to avoid aria-hidden on focused element
        if (document.activeElement && this.masterMenuEl.contains(document.activeElement)) {
            try { document.activeElement.blur(); } catch (_) { }
        }
        this.masterMenuEl.style.display = 'none';
        this.masterMenuEl.setAttribute('aria-hidden', 'true');
        this.masterMenuContext = null;
    }

    // Dummy handlers for requested actions
    onMasterMenuDataset(detail) {
        // Open the existing dataset modal flow
        try {
            // Ensure menu is closed before opening modal
            this.hideMasterContextMenu();
            this.handleDatasetCommand(detail);
        } catch (err) {
            console.error('Error opening dataset modal from master menu:', err);
        }
    }
    onMasterMenuToRoom(detail) {
        try {
            // Global modal opener defined in timeline-unified.html
            if (typeof window.openRoomAssignModal === 'function') {
                window.openRoomAssignModal(detail);
            } else {
                console.warn('Room Assignment Modal nicht verfügbar.');
            }
        } catch (err) {
            console.error('Error opening room assignment modal:', err);
        }
    }

    // ===== Date Context Menu (DOM) =====
    resolveDateFromHeader(mouseX) {
        if (!this.currentRange || !this.DAY_WIDTH) return null;
        const { startDate, endDate } = this.currentRange;
        if (!(startDate instanceof Date) || !(endDate instanceof Date)) return null;

        const relativeX = mouseX + this.scrollX - this.sidebarWidth;
        if (relativeX < 0) return null;
        const dayOffset = Math.floor(relativeX / this.DAY_WIDTH);
        if (dayOffset < 0) return null;

        const target = new Date(startDate.getTime());
        target.setDate(target.getDate() + dayOffset);
        if (target.getTime() > endDate.getTime()) {
            return null;
        }
        return target;
    }

    formatDateISO(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    formatDateLabel(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
        try {
            return date.toLocaleDateString('de-DE', {
                weekday: 'short',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
        } catch (err) {
            console.warn('Locale formatting failed, falling back to ISO:', err);
            return this.formatDateISO(date);
        }
    }

    ensureDateContextMenu() {
        this.injectDateContextMenuStyles();
        if (this.dateMenuEl) return;

        const el = document.createElement('div');
        el.id = 'timeline-date-menu';
        el.setAttribute('aria-hidden', 'true');
        el.style.display = 'none';
        el.innerHTML = `
            <div class="tdm-card">
                <button class="tdm-item" data-action="roomplan">Zimmerplan</button>
                <button class="tdm-item" data-action="dayplan">Tagesplan</button>
                <button class="tdm-item" data-action="guestreport">Berichte</button>
            </div>
        `;

        document.body.appendChild(el);
        this.dateMenuEl = el;

        el.addEventListener('click', (evt) => {
            const btn = evt.target.closest('.tdm-item');
            if (!btn) return;
            evt.preventDefault();
            evt.stopPropagation();

            const action = btn.getAttribute('data-action');
            const contextDate = this.dateMenuContext ? new Date(this.dateMenuContext.getTime()) : null;

            if (!contextDate) {
                this.hideDateContextMenu();
                return;
            }

            if (action === 'roomplan') {
                this.onDateMenuRoomplan(contextDate);
            } else if (action === 'dayplan') {
                this.onDateMenuDayplan(contextDate);
            } else if (action === 'guestreport') {
                this.onDateMenuGuestreport(contextDate);
            } else {
                this.hideDateContextMenu();
            }
        });
    }

    injectDateContextMenuStyles() {
        if (document.getElementById('timeline-date-menu-styles')) return;
        const style = document.createElement('style');
        style.id = 'timeline-date-menu-styles';
        style.textContent = `
            #timeline-date-menu {
                position: fixed;
                z-index: 9999;
                left: 0;
                top: 0;
                transform: none;
                user-select: none;
            }
            #timeline-date-menu .tdm-card {
                min-width: 200px;
                background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(17, 24, 39, 0.92));
                border: 1px solid rgba(148, 163, 184, 0.25);
                box-shadow: 0 10px 24px rgba(15, 23, 42, 0.35);
                border-radius: 12px;
                padding: 10px;
                display: flex;
                flex-direction: column;
                gap: 8px;
                backdrop-filter: blur(6px);
            }
            #timeline-date-menu .tdm-item {
                appearance: none;
                border: none;
                background: linear-gradient(90deg, rgba(37, 99, 235, 0.28), rgba(59, 130, 246, 0.18));
                color: #e2e8f0;
                padding: 10px 12px;
                border-radius: 8px;
                font-size: 13px;
                text-align: left;
                cursor: pointer;
                transition: transform .06s ease, background .18s ease, box-shadow .18s ease;
                box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
            }
            #timeline-date-menu .tdm-item:hover {
                background: linear-gradient(90deg, rgba(37, 99, 235, 0.42), rgba(59, 130, 246, 0.32));
                transform: translateY(-1px);
                box-shadow: inset 0 0 0 1px rgba(255,255,255,0.14), 0 6px 14px rgba(59, 130, 246, 0.22);
            }
            #timeline-date-menu .tdm-item:active {
                transform: translateY(0);
                background: linear-gradient(90deg, rgba(37, 99, 235, 0.52), rgba(59, 130, 246, 0.42));
            }
        `;
        document.head.appendChild(style);
    }

    showDateContextMenu(date) {
        this.ensureDateContextMenu();
        if (!this.dateMenuEl) return;

        this.dateMenuContext = new Date(date.getTime());

        // Display for measurement
        this.dateMenuEl.style.display = 'block';
        this.dateMenuEl.setAttribute('aria-hidden', 'false');
        this.dateMenuEl.style.left = '-9999px';
        this.dateMenuEl.style.top = '-9999px';

        const menuRect = this.dateMenuEl.getBoundingClientRect();
        const margin = 12;
        const vw = window.innerWidth;
        const vh = window.innerHeight;

        let headerBottom = margin;
        let columnCenter = vw / 2;

        if (this.canvas && this.areas?.header) {
            const canvasRect = this.canvas.getBoundingClientRect();
            headerBottom = canvasRect.top + this.areas.header.y + this.areas.header.height;

            if (this.currentRange?.startDate instanceof Date && !Number.isNaN(this.currentRange.startDate.getTime())) {
                const msPerDay = 86400000;
                const diffDays = Math.round((date.getTime() - this.currentRange.startDate.getTime()) / msPerDay);
                columnCenter = canvasRect.left + this.sidebarWidth - this.scrollX + (diffDays + 0.5) * this.DAY_WIDTH;
            } else {
                columnCenter = canvasRect.left + (canvasRect.width / 2);
            }
        }

        let left = columnCenter - menuRect.width / 2;
        if (left < margin) left = margin;
        if (left + menuRect.width > vw - margin) left = Math.max(margin, vw - menuRect.width - margin);

        let top = headerBottom + 6;
        if (top + menuRect.height > vh - margin) {
            top = Math.max(headerBottom + 6, vh - menuRect.height - margin);
        }

        this.dateMenuEl.style.left = `${Math.round(left)}px`;
        this.dateMenuEl.style.top = `${Math.round(top)}px`;
    }

    hideDateContextMenu() {
        if (!this.dateMenuEl) return;
        if (document.activeElement && this.dateMenuEl.contains(document.activeElement)) {
            try { document.activeElement.blur(); } catch (_) { }
        }
        this.dateMenuEl.style.display = 'none';
        this.dateMenuEl.setAttribute('aria-hidden', 'true');
        this.dateMenuContext = null;
    }

    onDateMenuRoomplan(date) {
        this.hideDateContextMenu();
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return;
        const iso = this.formatDateISO(date);
        if (!iso) return;

        try {
            const url = new URL('roomplan.php', window.location.href);
            url.searchParams.set('date', iso);
            window.open(url.toString(), '_blank', 'noopener=yes');
        } catch (err) {
            console.error('Konnte Zimmerplan-Seite nicht öffnen:', err);
        }
    }

    onDateMenuDayplan(date) {
        this.hideDateContextMenu();
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return;
        const iso = this.formatDateISO(date);
        if (!iso) return;

        try {
            const url = new URL('zp_day.php', window.location.href);
            url.searchParams.set('date', iso);
            url.searchParams.set('return', window.location.href);
            window.location.assign(url.toString());
        } catch (err) {
            console.error('Konnte Tagesplan-Seite nicht öffnen:', err);
        }
    }

    onDateMenuGuestreport(date) {
        this.hideDateContextMenu();
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return;
        const iso = this.formatDateISO(date);
        if (!iso) return;

        try {
            const url = new URL('guestreport.php', window.location.href);
            url.searchParams.set('date', iso);
            window.open(url.toString(), '_blank', 'noopener=yes');
        } catch (err) {
            console.error('Konnte Gästereport-Seite nicht öffnen:', err);
        }
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
        // Fixed palette only; do not inject current detail color to avoid extra segments
        const palette = [
            { label: '', value: '#3498db' },
            { label: '', value: '#1f78ff' },
            { label: '', value: '#27ae60' },
            { label: '', value: '#c0392b' },
            { label: '', value: '#e67e22' },
            { label: '', value: '#8e44ad' },
            { label: '', value: '#16a085' },
            { label: '', value: '#7f8c8d', textColor: '#ffffff' }
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
        const minOption = 1;
        const maxOption = 10;
        const options = [];
        for (let value = minOption; value <= maxOption; value++) {
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
                this.handleDesignationCommand(detail);
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

    async handleNoteCommand(detail) {
        console.log('🔍 handleNoteCommand called with detail:', detail);
        const currentNote = detail?.data?.note || detail?.note || '';
        console.log('🔍 Current note:', currentNote);

        const newNote = await showNotesModal(currentNote);
        console.log('🔍 New note from modal:', newNote);

        if (newNote !== null) { // User didn't cancel
            console.log('🔍 Updating note, detail_id:', detail.detail_id || detail.id);

            if (!detail.data) detail.data = {};
            detail.data.note = newNote;
            detail.note = newNote;

            // Update database with new note
            try {
                const response = await fetch('http://192.168.15.14:8080/wci/zp/updateRoomDetailAttributes.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        detail_id: detail.detail_id || detail.id,
                        updates: {
                            note: newNote
                        }
                    })
                });

                console.log('🔍 Response status:', response.status);

                if (response.ok) {
                    const result = await response.json();
                    console.log('✅ Note updated successfully:', result);

                    // Update all instances of this detail in the global data
                    const detailId = detail.detail_id || detail.id;

                    // Update in roomDetails global variable
                    if (typeof roomDetails !== 'undefined' && Array.isArray(roomDetails)) {
                        roomDetails.forEach(roomDetail => {
                            if (roomDetail.detail_id === detailId || roomDetail.id === detailId) {
                                roomDetail.note = newNote;
                                if (roomDetail.data) roomDetail.data.note = newNote;
                            }
                        });
                    }

                    // Update in reservations global variable
                    if (typeof reservations !== 'undefined' && Array.isArray(reservations)) {
                        reservations.forEach(reservation => {
                            if (reservation.detail_id === detailId || reservation.id === detailId) {
                                reservation.note = newNote;
                                if (reservation.data) reservation.data.note = newNote;
                            }
                        });
                    }

                    // Update local instance
                    detail.note = newNote;
                    if (detail.data) detail.data.note = newNote;

                    // Force complete cache invalidation and re-render
                    this.invalidateStackingCache();

                    // Clear sticky notes cache to force re-render with new data
                    this.stickyNotesCache.clear();
                    this.lastStickyNotesRender = 0;

                    this.render();

                    console.log('✅ Note updated in all data structures and re-rendered');
                } else {
                    const errorText = await response.text();
                    console.error('❌ Failed to update note:', response.statusText, errorText);
                }
            } catch (error) {
                console.error('❌ Error updating note:', error);
            }
        }
    }

    async handleShareCommand(detail) {
        // Detail-ID und aktuelle Anzahl extrahieren
        const detailId = detail.data?.detail_id || detail.detail_id || detail.ID || detail.id;
        const currentCapacity = detail.capacity || detail.data?.capacity || detail.data?.anz || 1;

        if (!detailId) {
            return;
        }

        if (currentCapacity <= 1) {
            return;
        }

        // Bestätigung anfordern
        const shouldSplit = await showConfirmationModal(
            'Detail teilen',
            'Balken aufteilen?',
            'Teilen',
            'Abbrechen'
        );

        if (!shouldSplit) {
            return;
        }

        // AJAX-Aufruf zur API für das Teilen
        const requestData = {
            detailId: detailId
        };

        console.log('Sende Split-Request:', requestData);

        fetch('http://192.168.15.14:8080/wci/reservierungen/api/splitReservationDetail.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        })
            .then(response => {
                console.log('Split API Response Status:', response.status);
                return response.text().then(responseText => {
                    try {
                        return JSON.parse(responseText);
                    } catch (parseError) {
                        console.error('Parse error:', parseError);
                        console.error('Raw response:', responseText);
                        throw new Error('Ungültige JSON-Antwort vom Server');
                    }
                });
            })
            .then(data => {
                console.log('Split API Response:', data);

                if (data.success) {
                    // Erfolgreich geteilt - aktualisiere lokale Daten
                    // Original Detail auf Anzahl 1 setzen
                    detail.capacity = 1;
                    if (detail.data) {
                        detail.data.capacity = 1;
                        detail.data.anz = 1;
                    }

                    // Neues Detail zur lokalen Liste hinzufügen
                    const newDetail = {
                        ...detail,
                        id: `room_detail_${data.newDetailId}`,
                        detail_id: data.newDetailId,
                        capacity: data.newAnzahl,
                        data: {
                            ...detail.data,
                            detail_id: data.newDetailId,
                            capacity: data.newAnzahl,
                            anz: data.newAnzahl
                        }
                    };

                    // Zur globalen roomDetails Liste hinzufügen
                    if (typeof roomDetails !== 'undefined' && Array.isArray(roomDetails)) {
                        roomDetails.push(newDetail);
                    }

                    // Cache invalidieren und neu rendern
                    this.invalidateStackingCache(detail.room_id);
                    this.markDataDirty();
                    this.render();
                }
            })
            .catch(error => {
                console.error('Netzwerkfehler beim Teilen:', error);
            });
    }

    handleSplitCommand(detail) {
        // Detail-ID extrahieren
        const detailId = detail.data?.detail_id || detail.detail_id || detail.ID || detail.id;

        if (!detailId) {
            return;
        }

        // Letzte Mausposition für Split-Datum verwenden
        const splitDate = this.calculateDateFromMousePosition();
        if (!splitDate) {
            return;
        }

        // AJAX-Aufruf zur API für das Splitten
        const requestData = {
            detailId: detailId,
            splitDate: splitDate
        };

        console.log('Sende Split-By-Date-Request:', requestData);

        fetch('http://192.168.15.14:8080/wci/reservierungen/api/splitReservationByDate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        })
            .then(response => {
                console.log('Split-By-Date API Response Status:', response.status);
                return response.text().then(responseText => {
                    try {
                        return JSON.parse(responseText);
                    } catch (parseError) {
                        console.error('Parse error:', parseError);
                        console.error('Raw response:', responseText);
                        throw new Error('Ungültige JSON-Antwort vom Server');
                    }
                });
            })
            .then(data => {
                console.log('Split-By-Date API Response:', data);

                if (data.success) {
                    // Erfolgreich gesplittet - aktualisiere lokale Daten
                    // WICHTIG: Ursprüngliches end-Datum vor Änderung speichern
                    const originalEndDate = new Date(detail.end);

                    // Original Detail: bis-Datum auf Split-Datum aktualisieren
                    const splitEndDate = new Date(data.splitDate);
                    detail.end = splitEndDate;
                    if (detail.data) {
                        detail.data.end = splitEndDate;
                    }

                    // Neues Detail zur lokalen Liste hinzufügen
                    const newDetail = {
                        ...detail,
                        id: `room_detail_${data.newDetailId}`,
                        detail_id: data.newDetailId,
                        start: new Date(data.splitDate),
                        end: originalEndDate,  // ← KORREKT: ursprüngliches end-Datum verwenden
                        ParentID: detailId,
                        data: {
                            ...detail.data,
                            detail_id: data.newDetailId,
                            start: new Date(data.splitDate),
                            end: originalEndDate,  // ← KORREKT: ursprüngliches end-Datum verwenden
                            ParentID: detailId
                        }
                    };

                    // Zur globalen roomDetails Liste hinzufügen
                    if (typeof roomDetails !== 'undefined' && Array.isArray(roomDetails)) {
                        roomDetails.push(newDetail);
                    }

                    // Cache invalidieren und neu rendern
                    this.invalidateStackingCache(detail.room_id);
                    this.markDataDirty();
                    this.render();
                }
            })
            .catch(error => {
                console.error('Netzwerkfehler beim Splitten:', error);
            });
    }

    calculateDateFromMousePosition() {
        // Verwende die letzte bekannte Mausposition
        if (!this.mouseX || this.mouseX < this.sidebarWidth) {
            return null;
        }

        // Berechne das Datum basierend auf der Mausposition
        const { startDate } = this.getTimelineDateRange();

        const startX = this.sidebarWidth - this.scrollX;
        const relativeX = this.mouseX - startX;
        const dayOffset = relativeX / this.DAY_WIDTH;

        const splitDate = new Date(startDate.getTime() + (dayOffset * 24 * 60 * 60 * 1000));

        // Format als YYYY-MM-DD
        const year = splitDate.getFullYear();
        const month = String(splitDate.getMonth() + 1).padStart(2, '0');
        const day = String(splitDate.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }

    handleDogCommand(detail) {
        // Aktuellen Hund-Status ermitteln
        const currentDog = detail?.has_dog || detail?.hund || (detail.data && detail.data.has_dog) || false;
        const newDog = !currentDog; // Toggle ohne Rückfrage

        // Lokale Darstellung sofort aktualisieren
        detail.has_dog = newDog;
        detail.hund = newDog;
        if (!detail.data) detail.data = {};
        detail.data.has_dog = newDog;
        detail.data.hund = newDog;

        // Detail-Identifiers extrahieren
        const identifiers = this.extractDetailIdentifiers(detail);
        if (!identifiers.detailId) {
            console.error('Keine Detail-ID gefunden für Hund-Toggle:', detail);
            return;
        }

        // Stacking-Cache invalidieren und neu rendern
        this.invalidateStackingCache();
        this.markDataDirty();
        this.render();

        // Datenbank aktualisieren
        this.persistRoomDetailAttributes({
            scope: 'detail',
            detail_id: identifiers.detailId,
            res_id: identifiers.resId,
            updates: { hund: newDog ? 1 : 0 }
        }, () => {
            // Rollback bei Fehler
            detail.has_dog = currentDog;
            detail.hund = currentDog;
            if (detail.data) {
                detail.data.has_dog = currentDog;
                detail.data.hund = currentDog;
            }
            this.invalidateStackingCache();
            this.markDataDirty();
            this.render();
            console.error('Fehler beim Aktualisieren des Hund-Status in der Datenbank');
        });
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
            fetch('http://192.168.15.14:8080/wci/reservierungen/api/deleteReservationDetail.php', {
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

                        const removedDetails = [];
                        const index = roomDetails.findIndex(item => item === detail);
                        if (index >= 0) {
                            const [removed] = roomDetails.splice(index, 1);
                            removedDetails.push(removed || detail);
                        } else {
                            removedDetails.push(detail);
                        }

                        this.finalizeRoomDetailMutation(removedDetails, { reason: 'detail_deleted' });

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
            fetch('http://192.168.15.14:8080/wci/reservierungen/api/deleteReservationAllDetails.php', {
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

                        const removedDetails = [];

                        for (let i = roomDetails.length - 1; i >= 0; i--) {
                            const item = roomDetails[i];
                            const itemResId = item.data?.res_id || item.res_id || item.resid;
                            if (itemResId == resId) {
                                const [removed] = roomDetails.splice(i, 1);
                                if (removed) {
                                    removedDetails.push(removed);
                                }
                            }
                        }

                        if (removedDetails.length === 0) {
                            console.warn('Keine lokalen Detail-Datensätze gefunden, die entfernt werden konnten.');
                        }

                        this.finalizeRoomDetailMutation(removedDetails, { reason: 'detail_delete_all' });

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

    async handleDesignationCommand(detail) {
        // Detail-ID und aktuelle Bezeichnung extrahieren
        const detailId = detail.data?.detail_id || detail.detail_id || detail.ID || detail.id;
        const currentDesignation = detail.data?.caption || detail.caption || detail.bez || '';
        const resId = detail.data?.res_id || detail.res_id || detail.resid;

        if (!detailId) {
            console.error('Keine Detail-ID gefunden:', detail);
            alert('Fehler: Keine gültige Detail-ID gefunden');
            return;
        }

        // Prüfe ob es mehrere Detail-Datensätze mit gleicher resid gibt
        const sameResDetails = roomDetails.filter(item => {
            const itemResId = item.data?.res_id || item.res_id || item.resid;
            return itemResId == resId;
        });
        const hasMultipleDetails = sameResDetails.length > 1;

        // Zeige Bezeichnungs-Modal
        const result = await showDesignationModal(currentDesignation, hasMultipleDetails);

        if (result === null) {
            // Benutzer hat abgebrochen
            return;
        }

        // AJAX-Aufruf zur API für das Aktualisieren der Bezeichnung
        const requestData = {
            detailId: detailId,
            designation: result.designation,
            updateAll: result.updateAll
        };

        console.log('Sende API-Request:', requestData);

        fetch('http://192.168.15.14:8080/wci/reservierungen/api/updateReservationDesignation.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        })
            .then(response => {
                console.log('API Response Status:', response.status);
                console.log('API Response Headers:', response.headers.get('content-type'));

                // Lese Response als Text für Debug, auch bei Fehlern
                return response.text().then(responseText => {
                    console.log('Raw API Response:', responseText);

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}\nResponse: ${responseText.substring(0, 500)}`);
                    }

                    return responseText;
                });
            })
            .then(responseText => {
                try {
                    const data = JSON.parse(responseText);
                    if (data.success) {
                        console.log('Bezeichnung erfolgreich aktualisiert:', data);

                        // Lokale Daten aktualisieren
                        if (result.updateAll) {
                            // Alle Detail-Datensätze mit gleicher resid aktualisieren
                            roomDetails.forEach(item => {
                                const itemResId = item.data?.res_id || item.res_id || item.resid;
                                if (itemResId == resId) {
                                    if (item.data) {
                                        item.data.caption = result.designation;
                                    }
                                    item.caption = result.designation;
                                    item.bez = result.designation;
                                }
                            });
                        } else {
                            // Nur den spezifischen Detail-Datensatz aktualisieren
                            if (detail.data) {
                                detail.data.caption = result.designation;
                            }
                            detail.caption = result.designation;
                            detail.bez = result.designation;
                        }

                        // Einfacher Page-Reload für saubere Aktualisierung
                        window.location.reload();

                    } else {
                        console.error('Fehler beim Aktualisieren der Bezeichnung:', data.error);
                        alert('Fehler beim Aktualisieren: ' + data.error);
                    }
                } catch (parseError) {
                    console.error('JSON Parse Error:', parseError);
                    alert('Ungültige API-Antwort: ' + responseText.substring(0, 200));
                }
            })
            .catch(error => {
                console.error('Netzwerkfehler beim Aktualisieren der Bezeichnung:', error);
                alert('Netzwerkfehler beim Aktualisieren: ' + error.message);
            });
    }

    async handleDatasetCommand(detail) {
        try {
            // Zeige Dataset-Modal
            const formData = await showDatasetModal(detail);

            if (formData === null) {
                // Benutzer hat abgebrochen
                return;
            }

            console.log('📋 Dataset-Formular-Daten:', formData);

            // 🚨 CACHE-BUSTER: 2025-09-20-19:43 - Debug erweitert!
            // Debug: Änderungserkennung
            if (formData.originalData) {
                console.log('🔍 Original-Daten für Vergleich:', formData.originalData);
                console.log('🔍 AV-Reservierung:', formData.isAVReservation);
                console.log('🔍 Formular-Bemerkungen:', formData.bem);
                console.log('🔍 Original-Bemerkungen:', formData.originalData.bem);
                console.log('🔍 Formular-Vorname:', formData.vorname);
                console.log('🔍 Original-Vorname:', formData.originalData.vorname);
                console.log('🔍 Formular-Nachname:', formData.nachname);
                console.log('🔍 Original-Nachname:', formData.originalData.nachname);
                console.log('🔍 Formular-Origin:', formData.origin);
                console.log('🔍 Original-Origin:', formData.originalData.origin);
                console.log('🔍 Formular-Arrangement:', formData.arr);
                console.log('🔍 Original-Arrangement:', formData.originalData.arr);

                // ALLE Formular-Felder anzeigen
                console.log('🔍 ALLE Formular-Daten:');
                Object.keys(formData).forEach(key => {
                    if (key !== 'originalData') {
                        console.log(`  ${key}: "${formData[key]}"`);
                    }
                });

                // ALLE Original-Felder anzeigen
                console.log('🔍 ALLE Original-Daten:');
                Object.keys(formData.originalData).forEach(key => {
                    console.log(`  ${key}: "${formData.originalData[key]}"`);
                });
            }

            // Wenn es eine AV-Reservierung ist, zusätzliche Validierung
            if (formData.isAVReservation) {
                const confirmUpdate = await showConfirmationModal(
                    'AV-Reservierung aktualisieren',
                    'Sie bearbeiten eine AV-Reservierung. Änderungen werden in der AV-Datenbank gespeichert. Fortfahren?',
                    'Aktualisieren',
                    'Abbrechen'
                );

                if (!confirmUpdate) {
                    return;
                }
            }

            // Extrahiere korrekte AV-Res ID (AV-Res.id), nicht av_id
            const identifiers = this.extractDetailIdentifiers(detail);
            const avResId = identifiers.resId || detail.res_id || detail.resid;

            if (!avResId) {
                console.error('Keine AV-Res ID (resid) gefunden für Dataset-Update:', detail);
                alert('Fehler: Keine gültige AV-Res ID gefunden');
                return;
            }

            console.log('📋 Verwende AV-Res ID (id):', avResId, 'für Datensatz-Update');

            // Bereite Update-Payload vor - verwende av_res_id statt res_id
            const updatePayload = {
                scope: 'av_reservation', // Spezifischer Scope für AV-Res Updates
                av_res_id: avResId, // AV_Res.id (entspricht AV_ResDet.resid)
                updates: {}
            };

            // Spezielle Updates für AV-Reservierungen mit korrekten AV_Res Feldnamen
            if (formData.isAVReservation) {
                const orig = formData.originalData; // Verwende die ursprünglichen API-Daten

                // Bemerkungen
                if (formData.bem !== (orig?.bem || '')) {
                    updatePayload.updates.bem = formData.bem;
                }

                // Datum für Anreise (YYYY-MM-DD)
                const origAnreise = orig?.anreise ? new Date(orig.anreise).toISOString().split('T')[0] : '';
                if (formData.anreise !== origAnreise) {
                    updatePayload.updates.anreise = formData.anreise;
                }

                // Datum für Abreise (YYYY-MM-DD)
                const origAbreise = orig?.abreise ? new Date(orig.abreise).toISOString().split('T')[0] : '';
                if (formData.abreise !== origAbreise) {
                    updatePayload.updates.abreise = formData.abreise;
                }

                // Anzahl Personen und Betten
                if (formData.lager !== (orig?.lager || 0)) {
                    updatePayload.updates.lager = formData.lager;
                }
                if (formData.betten !== (orig?.betten || 0)) {
                    updatePayload.updates.betten = formData.betten;
                }
                if (formData.dz !== (orig?.dz || 0)) {
                    updatePayload.updates.dz = formData.dz;
                }
                if (formData.sonder !== (orig?.sonder || 0)) {
                    updatePayload.updates.sonder = formData.sonder;
                }

                // Personendaten (Mapping von formData zu AV_Res Feldnamen)
                if (formData.vorname !== (orig?.vorname || '')) {
                    updatePayload.updates.vname = formData.vorname;
                }
                if (formData.nachname !== (orig?.nachname || '')) {
                    updatePayload.updates.name = formData.nachname;
                }
                if (formData.email !== (orig?.email || '')) {
                    updatePayload.updates.mail = formData.email;
                }
                if (formData.handy !== (orig?.handy || '')) {
                    updatePayload.updates.handy = formData.handy;
                }

                // Gruppen- und weitere Daten
                if (formData.gruppe !== (orig?.gruppe || '')) {
                    updatePayload.updates.gruppenname = formData.gruppe;
                }
                if (formData.bem_av !== (orig?.bem_av || '')) {
                    updatePayload.updates.bem_av = formData.bem_av;
                }

                // Herkunft und Arrangement
                if (formData.origin !== (orig?.origin || null)) {
                    updatePayload.updates.herkunft = formData.origin;
                }
                if (formData.arr !== (orig?.arr || null)) {
                    updatePayload.updates.arrangement = formData.arr;
                }

                // Status-Felder
                if (formData.storno !== (orig?.storno || 0)) {
                    updatePayload.updates.storno = formData.storno;
                }
                if (formData.hund !== (orig?.hund || 0)) {
                    updatePayload.updates.hund = formData.hund;
                }
            } else {
                // Für Nicht-AV-Reservierungen: Erweiterte editierbare Felder
                const orig = formData.originalData;

                // Bemerkungen
                if (formData.bem !== (orig?.bem || '')) {
                    updatePayload.updates.bem = formData.bem;
                }

                // Herkunft und Arrangement (immer editierbar)
                if (formData.origin !== (orig?.origin || null)) {
                    updatePayload.updates.herkunft = formData.origin;
                }
                if (formData.arr !== (orig?.arr || null)) {
                    updatePayload.updates.arrangement = formData.arr;
                }

                // Hund (immer editierbar)
                if (formData.hund !== (orig?.hund || 0)) {
                    updatePayload.updates.hund = formData.hund;
                }

                // Personendaten (auch bei normalen Reservierungen editierbar)
                if (formData.vorname !== (orig?.vorname || '')) {
                    updatePayload.updates.vname = formData.vorname;
                }
                if (formData.nachname !== (orig?.nachname || '')) {
                    updatePayload.updates.name = formData.nachname;
                }
                if (formData.email !== (orig?.email || '')) {
                    updatePayload.updates.mail = formData.email;
                }
                if (formData.handy !== (orig?.handy || '')) {
                    updatePayload.updates.handy = formData.handy;
                }

                // Gruppendaten
                if (formData.gruppe !== (orig?.gruppe || '')) {
                    updatePayload.updates.gruppenname = formData.gruppe;
                }

                // Aufenthaltsdaten (auch bei normalen Reservierungen editierbar)
                const origAnreise = orig?.anreise ? new Date(orig.anreise).toISOString().split('T')[0] : '';
                if (formData.anreise !== origAnreise) {
                    updatePayload.updates.anreise = formData.anreise;
                }

                const origAbreise = orig?.abreise ? new Date(orig.abreise).toISOString().split('T')[0] : '';
                if (formData.abreise !== origAbreise) {
                    updatePayload.updates.abreise = formData.abreise;
                }

                if (formData.lager !== (orig?.lager || 0)) {
                    updatePayload.updates.lager = formData.lager;
                }
                if (formData.betten !== (orig?.betten || 0)) {
                    updatePayload.updates.betten = formData.betten;
                }
                if (formData.dz !== (orig?.dz || 0)) {
                    updatePayload.updates.dz = formData.dz;
                }
                if (formData.sonder !== (orig?.sonder || 0)) {
                    updatePayload.updates.sonder = formData.sonder;
                }
            }

            // Wenn keine Änderungen, beenden
            if (Object.keys(updatePayload.updates).length === 0) {
                console.log('✅ Keine Änderungen erkannt');
                return;
            }

            console.log('� Erkannte Änderungen:', Object.keys(updatePayload.updates));
            console.log('�🔄 Sende Dataset-Update:', updatePayload);

            // Lokale Darstellung sofort aktualisieren
            this.updateLocalDetailData(detail, formData);
            this.markDataDirty();
            this.render();

            // API-Aufruf für Dataset-Update
            fetch('http://192.168.15.14:8080/wci/zp/updateReservationMasterData.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(updatePayload)
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        console.log('✅ Dataset erfolgreich aktualisiert');

                        // Optional: Daten neu laden für vollständige Synchronisation
                        if (formData.isAVReservation) {
                            console.log('🔄 Lade Daten neu wegen AV-Update');
                            // Hier könnte ein Reload der Daten erfolgen
                        }
                    } else {
                        throw new Error(data.message || 'Unbekannter Fehler beim Dataset-Update');
                    }
                })
                .catch(error => {
                    console.error('❌ Fehler beim Dataset-Update:', error);

                    // Rollback der lokalen Änderungen bei Fehler
                    this.rollbackLocalDetailData(detail);
                    this.markDataDirty();
                    this.render();

                    alert('Fehler beim Speichern der Stammdaten: ' + error.message);
                });

        } catch (error) {
            console.error('❌ Fehler in handleDatasetCommand:', error);
            alert('Ein unerwarteter Fehler ist aufgetreten: ' + error.message);
        }
    }

    // Helper-Methoden für Dataset-Update
    formatDateForDB(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toISOString().split('T')[0];
    }

    updateLocalDetailData(detail, formData) {
        // Sichere aktuelle Daten für Rollback
        detail._backupData = JSON.parse(JSON.stringify(detail.data || {}));

        if (!detail.data) detail.data = {};

        // Update lokale Daten
        if (formData.firstname) detail.data.firstname = formData.firstname;
        if (formData.lastname) {
            detail.data.lastname = formData.lastname;
            detail.guest_name = formData.lastname; // Auch Haupt-Property aktualisieren
        }
        if (formData.email) detail.data.email = formData.email;
        if (formData.phone) detail.data.handy = formData.phone;
        if (formData.group) detail.data.gruppenname = formData.group;
        if (formData.notes) detail.data.notes = formData.notes;

        // Datumsbereich aktualisieren
        if (formData.checkin) {
            const newStart = new Date(formData.checkin + 'T12:00:00');
            detail.start = newStart;
            detail.data.start = newStart.toISOString();
        }
        if (formData.checkout) {
            const newEnd = new Date(formData.checkout + 'T12:00:00');
            detail.end = newEnd;
            detail.data.end = newEnd.toISOString();
        }

        // Kapazität aktualisieren
        if (formData.guests) {
            detail.capacity = formData.guests;
            detail.data.capacity = formData.guests;
            detail.data.anz = formData.guests;
        }
    }

    rollbackLocalDetailData(detail) {
        if (detail._backupData) {
            detail.data = detail._backupData;
            delete detail._backupData;

            // Auch Haupt-Properties zurücksetzen
            if (detail.data.lastname) detail.guest_name = detail.data.lastname;
            if (detail.data.start) detail.start = new Date(detail.data.start);
            if (detail.data.end) detail.end = new Date(detail.data.end);
            if (detail.data.capacity) detail.capacity = detail.data.capacity;
        }
    }

    findReservationAt(mouseX, mouseY) {
        // Performance-optimiert: nur sichtbare Zimmer durchsuchen
        const startX = this.sidebarWidth - this.scrollX;
        const startY = this.areas.rooms.y - this.roomsScrollY;
        let currentYOffset = 0;

        // Date range für Position-Berechnung (konfigurierbar)
        const { startDate } = this.getTimelineDateRange();

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
                const edgeHandleMargin = Math.min(24, Math.max(10, this.ROOM_BAR_HEIGHT + 6));

                for (const reservation of sortedReservations) {
                    const barHeight = this.ROOM_BAR_HEIGHT;
                    const stackY = baseRoomY + 1 + (reservation.stackLevel * (barHeight + 2));

                    if (mouseX >= reservation.left - edgeHandleMargin && mouseX <= reservation.left + reservation.width + edgeHandleMargin &&
                        mouseY >= stackY - edgeHandleMargin * 0.4 && mouseY <= stackY + barHeight + edgeHandleMargin * 0.4) {
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
        const dragSource = this.isTouchLikePointer(e) ? 'touch' : 'mouse';
        this.setFocusedReservation(reservation, { source: dragSource, silent: true });
        this.dragStartX = mouseX;
        this.dragStartY = mouseY;
        this.dragAxisLock = null;

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

        if (this.dragOptimization.roomBaselineHeights) {
            this.dragOptimization.roomBaselineHeights.clear();
        } else {
            this.dragOptimization.roomBaselineHeights = new Map();
        }

        const perRowHeight = this.ROOM_BAR_HEIGHT + 2;
        const baselinePadding = 4;

        rooms.forEach(room => {
            if (!room || room.id === undefined || room.id === null) {
                return;
            }

            const key = String(room.id);
            const baseHeight = Math.max(25, room._dynamicHeight || (baselinePadding + perRowHeight));
            const effective = Math.max(0, baseHeight - baselinePadding);
            const levelCount = Math.max(1, Math.round(effective / perRowHeight));
            const baselineMaxStack = Math.max(0, levelCount - 1);

            this.dragOptimization.roomBaselineHeights.set(key, {
                roomHeight: baseHeight,
                maxStackLevel: baselineMaxStack
            });
        });

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

        let axisLock = this.dragAxisLock;
        const isMoveMode = this.dragMode === 'move';

        if (isMoveMode) {
            axisLock = null;
            this.dragAxisLock = null;
        } else {
            if (!axisLock) {
                axisLock = 'horizontal';
                this.dragAxisLock = axisLock;
            }
        }

        let effectiveMouseX = mouseX;
        let effectiveMouseY = mouseY;

        if (axisLock === 'vertical') {
            effectiveMouseX = this.dragStartX;
        }
        if (axisLock === 'horizontal') {
            effectiveMouseY = this.dragStartY;
        }

        const horizontalDelta = effectiveMouseX - this.dragStartX;
        const daysDelta = isMoveMode
            ? Math.round(horizontalDelta / this.DAY_WIDTH)
            : Math.round((effectiveMouseX - this.dragStartX) / this.DAY_WIDTH);

        // Phase 3+: Real-time optimal stacking during drag
        if (this.dragOptimization.enabled) {
            this.updateDragPreview(effectiveMouseX, effectiveMouseY, daysDelta);
            this.updateRoomStackingOptimal();
        }

        // Update pixel-precise ghost frame that follows mouse
        this.updatePixelGhostFrame(effectiveMouseX, effectiveMouseY);

        // Invalidate stacking cache for affected rooms to force re-calculation
        if (this.dragOriginalData?.room_id) {
            this.invalidateStackingCache(this.dragOriginalData.room_id);
        }
        const targetRoom = this.resolveDragTargetRoom(effectiveMouseY);
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
        this.updateGhostBar(effectiveMouseX, effectiveMouseY, daysDelta, targetRoom);

        if (this.dragMode === 'move') {
            this.handleReservationMove(daysDelta);
        } else if (this.dragMode === 'resize-start') {
            this.handleReservationResizeStart(daysDelta);
        } else if (this.dragMode === 'resize-end') {
            this.handleReservationResizeEnd(daysDelta);
        }

        // Finde Ziel-Zimmer bei Move-Operation
        if (this.dragMode === 'move') {
            this.dragTargetRoom = targetRoom;
        }

        // Fallback für deaktivierte Drag-Optimierung
        if (!this.dragOptimization.enabled) {
            this.updateRoomStackingOptimal();
        }
    }

    handleReservationMove(daysDelta) {
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
        const { startDate } = this.getTimelineDateRange();

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

    resolveDragTargetRoom(mouseY) {
        let targetRoom = this.findRoomAt(mouseY);

        if (!targetRoom || this.dragMode !== 'move' || !this.dragOriginalData?.room_id) {
            return targetRoom;
        }

        const verticalDelta = mouseY - this.dragStartY;
        const switchThreshold = Math.max(this.dragRoomSwitchThreshold || 0, this.ROOM_BAR_HEIGHT * 1.5);

        if (Math.abs(verticalDelta) < switchThreshold) {
            const originalRoomId = String(this.dragOriginalData.room_id);
            const originalRoom = rooms.find(room => String(room.id) === originalRoomId);
            if (originalRoom) {
                targetRoom = originalRoom;
            }
        }

        return targetRoom;
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
            if (this.dragOptimization.roomBaselineHeights) {
                this.dragOptimization.roomBaselineHeights.clear();
            }
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
            if (this.dragOptimization.roomBaselineHeights) {
                this.dragOptimization.roomBaselineHeights.clear();
            }
        }

        // Clear ALL drag-related state
        this.isDraggingReservation = false;
        this.draggedReservation = null;
        this.draggedReservationReference = null; // Clear drag reference
        this.dragMode = null;
        this.dragOriginalData = null;
        this.dragTargetRoom = null;
        this.dragAxisLock = null;
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

    updateGhostBar(mouseX, mouseY, daysDelta, externalTargetRoom = null) {
        if (!this.ghostBar || !this.draggedReservation) return;

        const { startDate } = this.getTimelineDateRange();
        const startX = this.sidebarWidth - this.scrollX;

        if (this.dragOptimization.enabled) {
            const resolvedRoom = externalTargetRoom ||
                (this.dragMode === 'move'
                    ? (this.dragTargetRoom || this.findRoomAt(mouseY))
                    : this.findRoomByReservation(this.draggedReservation));

            if (resolvedRoom) {
                const previewResult = this.dragOptimization.previewStacking.get(resolvedRoom.id) ||
                    this.dragOptimization.previewStacking.get(String(resolvedRoom.id));

                if (previewResult && Array.isArray(previewResult.reservations)) {
                    const previewReservation = previewResult.reservations.find(item => item && item._isPreview);
                    if (previewReservation) {
                        const barHeight = this.ROOM_BAR_HEIGHT;
                        const baseRoomY = this.calculateRoomY(resolvedRoom);

                        this.ghostBar.x = previewReservation.left;
                        this.ghostBar.width = previewReservation.width;
                        this.ghostBar.y = baseRoomY + 1 + (previewReservation.stackLevel * (barHeight + 2));
                        this.ghostBar.height = barHeight;
                        this.ghostBar.targetRoom = resolvedRoom;
                        this.ghostBar.visible = true;
                        this.ghostBar._stackLevel = previewReservation.stackLevel;
                        return;
                    }
                }
            }
        }

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
            const targetRoom = externalTargetRoom || this.findRoomAt(mouseY);
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

                const barHeight = this.ROOM_BAR_HEIGHT;
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
        const { startDate } = this.getTimelineDateRange();

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
        }).filter(detail => {
            if (detail._isPreview) {
                return true;
            }

            const visibleLeft = this.sidebarWidth - 60; // small negative padding keeps near-edge items
            const visibleRight = this.canvas.width + 60;
            const detailRight = detail.left + detail.width;

            return detailRight > visibleLeft && detail.left < visibleRight;
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
        const barHeight = this.ROOM_BAR_HEIGHT;
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
                    const previewResult = this.dragOptimization.previewStacking.get(roomId) ||
                        this.dragOptimization.previewStacking.get(String(roomId));
                    if (previewResult) {
                        const roomKey = String(room.id);
                        const baselineInfo = this.dragOptimization.roomBaselineHeights
                            ? this.dragOptimization.roomBaselineHeights.get(roomKey)
                            : null;

                        let targetHeight = previewResult.roomHeight ?? (baselineInfo?.roomHeight ?? room._dynamicHeight);
                        const previewMaxStack = typeof previewResult.maxStackLevel === 'number'
                            ? previewResult.maxStackLevel
                            : 0;
                        const reservations = Array.isArray(previewResult.reservations)
                            ? previewResult.reservations
                            : [];

                        const requiresHigherStackFromRealReservations = reservations.some(reservation =>
                            !reservation._isPreview && baselineInfo && reservation.stackLevel > (baselineInfo.maxStackLevel ?? 0)
                        );
                        const isOriginalRoom = this.dragOriginalData?.room_id !== undefined &&
                            String(room.id) === String(this.dragOriginalData.room_id);
                        const isCurrentTargetRoom = this.dragTargetRoom &&
                            String(room.id) === String(this.dragTargetRoom.id);

                        if (baselineInfo) {
                            const baselineHeight = baselineInfo.roomHeight;
                            const baselineMaxStack = baselineInfo.maxStackLevel ?? 0;

                            if (isOriginalRoom && targetHeight < baselineHeight) {
                                // Shrink immediately when the dragged reservation leaves the source room
                                targetHeight = previewResult.roomHeight ?? baselineHeight;
                            } else if (!isCurrentTargetRoom && targetHeight > baselineHeight && !requiresHigherStackFromRealReservations) {
                                // Prevent non-target rooms from expanding due to preview-only stacking
                                targetHeight = baselineHeight;
                                previewResult.maxStackLevel = Math.min(previewMaxStack, baselineMaxStack);
                                reservations.forEach(reservation => {
                                    if (reservation.stackLevel > baselineMaxStack) {
                                        reservation.stackLevel = baselineMaxStack;
                                    }
                                });
                            } else if (requiresHigherStackFromRealReservations && targetHeight < baselineHeight) {
                                // Keep enough height when real reservations still need it
                                targetHeight = baselineHeight;
                            }

                            if (!isOriginalRoom && !isCurrentTargetRoom && targetHeight < baselineHeight) {
                                targetHeight = baselineHeight;
                            }
                        }

                        // Temporarily update room height for live preview and keep preview result in sync
                        const animatedHeight = this.applyRoomHeightAnimation(room, targetHeight, { animate: true });
                        room._dynamicHeight = animatedHeight;
                        previewResult.roomHeight = animatedHeight;

                        // Apply stacking to visible reservations
                        if (Array.isArray(previewResult.reservations)) {
                            previewResult.reservations.forEach(reservation => {
                                if (!reservation._isPreview) {
                                    // Update position data for rendering
                                    this.updateReservationPosition(reservation);
                                }
                            });
                        }
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
                const barHeight = this.ROOM_BAR_HEIGHT;
                const roomHeight = Math.max(20, 4 + (maxStackLevel + 1) * (barHeight + 0));
                const animatedHeight = this.applyRoomHeightAnimation(room, roomHeight, { animate: true });
                room._dynamicHeight = animatedHeight;
            });
        }
    }

    render() {
        // Phase 3: Performance monitoring start
        const renderStart = performance.now();

        // Phase 3: Start batch operations for optimized rendering
        this.startBatch();

        // Clear sticky notes queue for this render cycle
        this.stickyNotesQueue = [];
        this.stickyNoteBounds = [];

        // Force sticky notes re-render on scroll or zoom
        this.lastStickyNotesRender = 0;

        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        const sidebarChanged = this.updateSidebarMetrics();

        if (sidebarChanged) {
            this.updateViewportCache(this.scrollX, this.roomsScrollY);
        }

        if (reservations.length === 0) {
            this.renderEmpty();
            this.updateNavigationOverview();
            return;
        }

        // Initialize data index if not done yet
        if (!this.dataIndex && typeof reservations !== 'undefined' && typeof roomDetails !== 'undefined') {
            this.initializeDataIndex(reservations, roomDetails);
        }

        // Phase 3: Update viewport cache for intelligent culling
        this.updateViewportCache(this.scrollX, this.roomsScrollY);

        // Datums-Logik: konfigurierbare Wochen Vergangenheit/Zukunft (auf 0 Uhr fixiert)
        const { now, startDate, endDate } = this.getTimelineDateRange();

        this.currentRange = {
            startDate: new Date(startDate.getTime()),
            endDate: new Date(endDate.getTime()),
            now: new Date(now.getTime())
        };

        // Pre-calculate room heights for correct scrollbar sizing
        this.preCalculateRoomHeights(startDate, endDate);

        const totalDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));
        const timelineWidth = totalDays * this.DAY_WIDTH;

        // Update scroll tracks
        const scrollContentH = this.container.querySelector('.scroll-content-h');
        if (scrollContentH) {
            scrollContentH.style.width = timelineWidth + 'px';
        }

        // Beim ersten Render: Heute in die Seitenmitte scrollen
        if (!this._scrolledToTodayOnce) {
            const todayOffsetDays = Math.floor((now.getTime() - startDate.getTime()) / MS_IN_DAY);
            const todayCenterX = (todayOffsetDays + 0.5) * this.DAY_WIDTH; // Mitte des heutigen Tages
            const viewportWidth = this.canvas.width - this.sidebarWidth;
            const desiredScrollX = Math.max(0, todayCenterX - viewportWidth / 2);

            // Scrollbar-Elemente synchronisieren
            if (this.horizontalTrack) {
                this.horizontalTrack.scrollLeft = desiredScrollX;
            }
            this.scrollX = desiredScrollX;
            this.updateViewportCache(this.scrollX, this.roomsScrollY);
            this._scrolledToTodayOnce = true;
        }

        // Viewport Culling für bessere Performance - aber großzügiger für Sichtbarkeit
        const visibleReservations = reservations; // Temporär alle verwenden für korrekte Darstellung

        // Update Master-Scrollbar Content-Höhe
        const scrollContentMaster = this.container.querySelector('.scroll-content-master');
        if (scrollContentMaster && reservations.length > 0) {
            // Berechne tatsächliche maximale Stack-Höhe für Master-Bereich
            const maxStackLevel = this.calculateMasterMaxStackLevel(startDate, endDate, reservations);
            const barHeight = this.MASTER_BAR_HEIGHT || 14;
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

        // Render ParentID relationship curves after room details are rendered
        this.renderParentIdCurves(startDate, endDate, this.areas.rooms);

        this.renderHistogramAreaOptimized(startDate, endDate);
        this.renderSeparatorsOptimized();

        // Phase 3: Execute all batched operations
        this.executeBatch();

        // Ghost-Bar als letztes rendern (über allem) - not batched for immediate feedback
        this.renderGhostBar();

        // Pixel-precise ghost frame - rendered last for immediate mouse feedback
        this.renderPixelGhostFrame();

        // Sticky note ghost rectangle for dragging preview
        this.renderStickyNoteGhost();

        // Render all sticky notes on top (highest Z-order)
        this.renderAllStickyNotes();

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

        this.updateNavigationOverview();
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

                    const barHeight = this.ROOM_BAR_HEIGHT;
                    const roomHeight = Math.max(25, 4 + (maxStackLevel + 1) * (barHeight + 2));
                    const resolvedHeight = this.applyRoomHeightAnimation(room, roomHeight, { animate: false });
                    room._dynamicHeight = resolvedHeight;
                } else {
                    const resolvedHeight = this.applyRoomHeightAnimation(room, 25, { animate: false });
                    room._dynamicHeight = resolvedHeight;
                }
            } else {
                const resolvedHeight = this.applyRoomHeightAnimation(room, stackingResult.roomHeight, { animate: false });
                room._dynamicHeight = resolvedHeight;
            }
        });
    }

    calculateMasterMaxStackLevel(startDate, endDate, visibleReservations = null) {
        // Verwende ALLE Reservierungen für Master-Bereich, nicht nur sichtbare
        const reservationsToCheck = reservations.filter(reservation => this.isMasterReservationVisible(reservation));
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

        // Menü-Border unten
        this.ctx.strokeStyle = '#ddd';
        this.ctx.lineWidth = 1;
        this.ctx.beginPath();
        this.ctx.moveTo(0, area.y + area.height);
        this.ctx.lineTo(this.canvas.width, area.y + area.height);
        this.ctx.stroke();
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
        this.ctx.font = `${this.sidebarFontSize}px Arial`;
        this.ctx.textAlign = 'center';

        this.ctx.fillText('Alle', this.sidebarWidth / 2, this.areas.master.y + Math.min(20, this.sidebarFontSize + 4));

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

        // Menü-Border unten
        this.ctx.strokeStyle = '#ddd';
        this.ctx.lineWidth = 1;
        this.ctx.beginPath();
        this.ctx.moveTo(0, area.y + area.height);
        this.ctx.lineTo(this.canvas.width, area.y + area.height);
        this.ctx.stroke();

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
        this.shadeTodayColumn(area, startDate, endDate, { barWidth: this.DAY_WIDTH });

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
        this.shadeTodayColumn(area, startDate, endDate, { barWidth: this.DAY_WIDTH });

        const baseReservations = Array.isArray(visibleReservations) && visibleReservations.length > 0
            ? visibleReservations
            : reservations;
        const reservationsToRender = baseReservations.filter(reservation => this.isMasterReservationVisible(reservation));

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

            const barHeight = this.MASTER_BAR_HEIGHT || 14;
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

        const bottomPadding = Math.max(0, Math.min(4, area.height * 0.02));
        const topPadding = Math.max(14, Math.min(28, area.height * 0.12));
        const availableHeight = Math.max(10, area.height - topPadding - bottomPadding);
        const chartBottomY = area.y + area.height - bottomPadding;
        const chartTopY = chartBottomY - availableHeight;
        const stornoColors = histogramTheme.stornoSegments || { av0: '#f97316', avPositive: '#ef4444' };
        const baseBarWidth = Math.max(4, this.DAY_WIDTH - 10);
        let stornoEnabled = dailyDetails.some(detail => detail?.storno?.total > 0);
        let stornoBarWidth = stornoEnabled ? Math.max(3, Math.min(5, Math.round(baseBarWidth * 0.18))) : 0;
        let stornoGap = stornoEnabled && baseBarWidth - stornoBarWidth > 6 ? 1 : 0;
        let barWidth = stornoEnabled ? baseBarWidth - stornoBarWidth - stornoGap : baseBarWidth;
        if (stornoEnabled && barWidth < 4) {
            stornoGap = 0;
            barWidth = baseBarWidth - stornoBarWidth;
        }
        if (stornoEnabled && barWidth < 4) {
            stornoEnabled = false;
            stornoBarWidth = 0;
            barWidth = baseBarWidth;
        }
        const totalBarWidth = barWidth + (stornoEnabled ? stornoGap + stornoBarWidth : 0);
        const xOffsetBase = (this.DAY_WIDTH - totalBarWidth) / 2;
        const categoryColors = histogramTheme.segments || {
            dz: '#1f78ff',
            betten: '#2ecc71',
            lager: '#f1c40f',
            sonder: '#8e44ad'
        };
        const weekendFill = histogramTheme.weekendFill || (this.themeConfig.weekend && this.themeConfig.weekend.fill) || 'rgba(255, 99, 132, 0.08)';
        const gridColor = histogramTheme.gridColor || 'rgba(255,255,255,0.28)';

        const manualHistogramMax = this.getConfiguredHistogramMaxValue();
        const estimatedTicks = Math.max(3, Math.min(8, Math.round(availableHeight / 36)));
        const { ticks, niceMax } = this.getHistogramTicks(maxGuests, {
            desiredTickCount: estimatedTicks,
            manualMax: manualHistogramMax > 0 ? manualHistogramMax : null
        });
        const scaledMax = niceMax > 0 ? niceMax : (manualHistogramMax > 0 ? manualHistogramMax : (maxGuests > 0 ? maxGuests : 1));

        // Shade weekends
        dailyCounts.forEach((_, dayIndex) => {
            const x = startX + (dayIndex * this.DAY_WIDTH) + xOffsetBase;
            if (x + totalBarWidth <= this.sidebarWidth - 100 || x >= this.canvas.width + 100) {
                return;
            }
            const dayDate = new Date(startDate.getTime() + dayIndex * MS_IN_DAY);
            const dayOfWeek = dayDate.getDay();
            if (dayOfWeek === 0 || dayOfWeek === 6) {
                this.ctx.fillStyle = weekendFill;
                this.ctx.globalAlpha = 1;
                this.ctx.fillRect(x, area.y, totalBarWidth, area.height);
            }
        });
        this.ctx.globalAlpha = 1;

        // Grid lines and labels
        this.ctx.setLineDash([4, 4]);
        this.ctx.strokeStyle = gridColor;

        // Erst die Gitterlinien im geclippten Bereich zeichnen
        ticks.forEach(tick => {
            const y = chartBottomY - (tick / scaledMax) * availableHeight;
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
            const y = chartBottomY - (tick / scaledMax) * availableHeight;
            this.ctx.fillText(String(tick), this.sidebarWidth - 6, y);
        });

        // Clipping wieder aktivieren für den Rest
        this.ctx.beginPath();
        this.ctx.rect(this.sidebarWidth, area.y, this.canvas.width - this.sidebarWidth, area.height);
        this.ctx.clip();

        this.ctx.textAlign = 'center';
        this.ctx.textBaseline = 'alphabetic';

        dailyCounts.forEach((count, dayIndex) => {
            const x = startX + (dayIndex * this.DAY_WIDTH) + xOffsetBase;

            if (x + totalBarWidth <= this.sidebarWidth - 100 || x >= this.canvas.width + 100) {
                return;
            }

            const defaultStorno = { av0: 0, avPositive: 0, total: 0 };
            const detail = dailyDetails[dayIndex] || { total: count };

            const totalValue = detail.total || 0;
            const ratio = scaledMax > 0 ? totalValue / scaledMax : 0;
            const barHeight = ratio * availableHeight;
            const barY = chartBottomY - barHeight;

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

            const stornoDetail = detail.storno || defaultStorno;

            if (stornoEnabled && stornoBarWidth > 0 && scaledMax > 0) {
                if (stornoDetail.total > 0) {
                    const stornoX = x + barWidth + stornoGap;
                    let stornoCurrentTop = chartBottomY;
                    const av0Height = stornoDetail.av0 > 0 ? (stornoDetail.av0 / scaledMax) * availableHeight : 0;
                    const avPositiveHeight = stornoDetail.avPositive > 0 ? (stornoDetail.avPositive / scaledMax) * availableHeight : 0;

                    if (av0Height > 0.5) {
                        const segmentY = stornoCurrentTop - av0Height;
                        this.ctx.fillStyle = stornoColors.av0 || '#f97316';
                        this.ctx.globalAlpha = 0.9;
                        this.ctx.fillRect(stornoX, segmentY, stornoBarWidth, av0Height);
                        stornoCurrentTop = segmentY;
                    }

                    if (avPositiveHeight > 0.5) {
                        const segmentY = stornoCurrentTop - avPositiveHeight;
                        this.ctx.fillStyle = stornoColors.avPositive || '#ef4444';
                        this.ctx.globalAlpha = 0.9;
                        this.ctx.fillRect(stornoX, segmentY, stornoBarWidth, avPositiveHeight);
                        stornoCurrentTop = segmentY;
                    }

                    this.ctx.globalAlpha = 1;
                }
            }

            const previousAlign = this.ctx.textAlign;
            const previousBaseline = this.ctx.textBaseline;
            const previousFont = this.ctx.font;

            try {
                this.ctx.fillStyle = textColor;
                this.ctx.font = `${fontSize}px Arial`;

                const labelPadding = Math.min(12, Math.max(4, barWidth * 0.12));
                const blockInnerPadding = 4;
                const minFontSize = Math.max(5, Math.round(fontSize * 0.6));
                let currentFontSize = fontSize;
                let lineHeight = Math.max(6, Math.round(currentFontSize * 1.05));

                const updateFontSize = (size) => {
                    currentFontSize = size;
                    lineHeight = Math.max(6, Math.round(size * 1.05));
                };

                const safeTotalValue = Number.isFinite(totalValue) ? totalValue : 0;
                const stornoTotal = Number.isFinite(stornoDetail.total) ? stornoDetail.total : 0;
                const safeSonder = Number(detail.sonder) || 0;
                const safeLager = Number(detail.lager) || 0;
                const safeBetten = Number(detail.betten) || 0;
                const safeDz = Number(detail.dz) || 0;

                const percentageRaw = safeTotalValue > 0 ? (stornoTotal / safeTotalValue) * 100 : 0;
                let percentageStr;
                if (!Number.isFinite(percentageRaw)) {
                    percentageStr = '0';
                } else if (percentageRaw >= 10) {
                    percentageStr = String(Math.round(percentageRaw));
                } else {
                    percentageStr = (Math.round(percentageRaw * 10) / 10).toString();
                    if (percentageStr.indexOf('.') !== -1) {
                        percentageStr = percentageStr.replace(/\.0$/, '');
                    }
                }

                const infoLines = [
                    { text: `ST:${percentageStr}%`, color: histogramTheme.stornoText || '#ff8c8c', bold: false },
                    { text: `SU:${safeTotalValue}`, color: histogramTheme.sumText || '#fada5e', bold: true }
                ];
                if (safeSonder) infoLines.push({ text: `PL:${safeSonder}`, color: textColor, bold: false });
                if (safeLager) infoLines.push({ text: `LA:${safeLager}`, color: textColor, bold: false });
                if (safeBetten) infoLines.push({ text: `BE:${safeBetten}`, color: textColor, bold: false });
                if (safeDz) infoLines.push({ text: `DZ:${safeDz}`, color: textColor, bold: false });

                if (infoLines.length === 0) {
                    return;
                }

                const maxBlockWidth = Math.max(40, this.DAY_WIDTH - 12);
                const maxAvailableHeight = Math.max(40, chartBottomY - area.y - 4);

                const measureBlock = () => {
                    let width = 0;
                    infoLines.forEach(line => {
                        this.ctx.font = `${line.bold ? 'bold ' : ''}${currentFontSize}px Arial`;
                        const measured = this.ctx.measureText(line.text).width;
                        if (measured > width) width = measured;
                    });
                    this.ctx.font = `${currentFontSize}px Arial`;
                    return {
                        width: width + blockInnerPadding * 2,
                        height: infoLines.length * lineHeight + blockInnerPadding * 2
                    };
                };

                let metrics = measureBlock();
                while ((metrics.width > maxBlockWidth || metrics.height > maxAvailableHeight) && currentFontSize > minFontSize) {
                    updateFontSize(currentFontSize - 1);
                    metrics = measureBlock();
                }

                const blockWidth = Number.isFinite(metrics.width)
                    ? Math.max(40, Math.min(metrics.width, maxBlockWidth))
                    : Math.min(140, maxBlockWidth);
                const blockHeight = Number.isFinite(metrics.height)
                    ? Math.max(lineHeight + blockInnerPadding * 2, metrics.height)
                    : Math.max(lineHeight + blockInnerPadding * 2, Math.min(maxAvailableHeight, 160));

                this.ctx.font = `${currentFontSize}px Arial`;

                this.ctx.textAlign = 'left';
                this.ctx.textBaseline = 'alphabetic';

                const totalBarWidth = barWidth + (stornoEnabled ? stornoGap + stornoBarWidth : 0);
                let blockLeft = x + Math.max(labelPadding, (totalBarWidth - blockWidth) * 0.05);
                const rightLimit = x + totalBarWidth - labelPadding;
                if (blockLeft + blockWidth > rightLimit) {
                    blockLeft = rightLimit - blockWidth;
                }
                if (!Number.isFinite(blockLeft)) {
                    blockLeft = x;
                }
                blockLeft = Math.max(blockLeft, x);

                const rectWidth = Math.max(40, blockWidth);
                const rectHeight = Math.max(40, blockHeight);

                let blockBottom = chartBottomY;
                let blockTop = blockBottom - rectHeight;
                if (blockTop < area.y + 2) {
                    blockTop = area.y + 2;
                    blockBottom = blockTop + rectHeight;
                }

                this.ctx.save();
                this.ctx.fillStyle = histogramTheme.labelBackground || 'rgba(30, 30, 30, 0.55)';
                this.roundedRect(blockLeft, blockTop, rectWidth, rectHeight, 5);
                this.ctx.fill();
                this.ctx.restore();

                let textY = blockBottom - blockInnerPadding;
                infoLines.forEach(line => {
                    this.ctx.font = `${line.bold ? 'bold ' : ''}${currentFontSize}px Arial`;
                    this.ctx.fillStyle = line.color || textColor;
                    this.ctx.fillText(line.text, blockLeft + blockInnerPadding, textY);
                    textY -= lineHeight;
                });

            } catch (error) {
                console.warn('Histogram label rendering failed:', error);
            } finally {
                this.ctx.textAlign = previousAlign;
                this.ctx.textBaseline = previousBaseline;
                this.ctx.font = previousFont;
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
        this.ctx.font = `${this.sidebarFontSize}px Arial`;
        this.ctx.textAlign = 'center';

        this.ctx.fillText('Alle', this.sidebarWidth / 2, this.areas.master.y + Math.min(20, this.sidebarFontSize + 4));

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

        const baseReservations = Array.isArray(visibleReservations) && visibleReservations.length > 0
            ? visibleReservations
            : reservations;
        const reservationsToRender = baseReservations.filter(reservation => this.isMasterReservationVisible(reservation));

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

            const barHeight = this.MASTER_BAR_HEIGHT || 14;
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
            const barHeight = this.ROOM_BAR_HEIGHT;
            const targetRoomHeight = Math.max(20, 4 + (maxStackLevel + 1) * (barHeight + 0));
            const animatedHeight = this.applyRoomHeightAnimation(room, targetRoomHeight, { animate: true });
            const displayHeight = animatedHeight;
            room._dynamicHeight = displayHeight;

            // Zimmer-Hintergrund mit alternierenden Streifen
            this.ctx.save();
            const roomIndex = rooms.indexOf(room);
            const isDropTarget = this.isDraggingReservation && this.dragMode === 'move' &&
                this.dragTargetRoom && this.dragTargetRoom.id === room.id &&
                this.dragTargetRoom.id !== this.dragOriginalData?.room_id;

            if (isDropTarget) {
                this.ctx.fillStyle = '#4CAF50';
                this.ctx.globalAlpha = 0.3;
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, displayHeight);
                this.ctx.globalAlpha = 1.0;
            } else {
                this.ctx.globalAlpha = 0.2;
                this.ctx.fillStyle = roomIndex % 2 === 0 ? '#000000' : '#ffffff';
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, displayHeight);
                this.ctx.globalAlpha = 1.0;
            }
            this.ctx.restore();

            // Render Reservierungen - Ghost-Reservierungen werden NIEMALS sichtbar gerendert
            sortedReservations.forEach(reservation => {
                // Skip ghost reservations KOMPLETT - sie sind nur für Stacking-Berechnung
                if (reservation._isGhost || reservation._isPreview) {
                    return; // Keine Sichtbarkeit für Ghost-Reservierungen
                }

                const stackY = baseRoomY + 1 + (reservation.stackLevel * (this.ROOM_BAR_HEIGHT + 2));
                const isHovered = this.isReservationHovered(reservation.left, stackY, reservation.width, this.ROOM_BAR_HEIGHT);

                this.renderRoomReservationBar(reservation.left, stackY, reservation.width, this.ROOM_BAR_HEIGHT, reservation, isHovered);

                if (isHovered) {
                    this.hoveredReservation = reservation;
                }
            });

            this.renderOverCapacityIndicators(room, sortedReservations, startDate, endDate, baseRoomY, displayHeight);

            // Zimmer-Trennlinie
            this.ctx.save();
            this.ctx.strokeStyle = '#444';
            this.ctx.lineWidth = 1;
            this.ctx.beginPath();
            this.ctx.moveTo(this.sidebarWidth, baseRoomY + displayHeight);
            this.ctx.lineTo(this.canvas.width, baseRoomY + displayHeight);
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
            const roomDisplayY = baseRoomY + (height / 2) + (this.sidebarFontSize / 3);

            if (roomDisplayY >= this.areas.rooms.y && roomDisplayY <= this.areas.rooms.y + this.areas.rooms.height) {
                this.ctx.fillStyle = this.themeConfig.sidebar.text;
                this.ctx.font = `${this.sidebarFontSize}px Arial`;
                this.ctx.textAlign = 'center';

                const caption = room.caption || `R${room.id}`;
                this.ctx.fillText(caption, this.sidebarWidth / 2, roomDisplayY);
            }
        });

        this.ctx.restore();
    }

    renderRoomsAreaOptimized(startDate, endDate) {
        // Clear sticky note bounds for new render cycle
        this.stickyNoteBounds = [];

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

            if (this.isDraggingReservation && this.dragOptimization.enabled) {
                const previewResult = this.dragOptimization.previewStacking.get(room.id) ||
                    this.dragOptimization.previewStacking.get(String(room.id));
                if (previewResult && previewResult.reservations) {
                    stackingResult = previewResult;
                }
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

                    const barHeight = this.ROOM_BAR_HEIGHT;
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

            const animatedHeight = this.applyRoomHeightAnimation(room, roomHeight, { animate: true });
            room._dynamicHeight = animatedHeight;

            // Zimmer-Hintergrund mit alternierenden Streifen
            this.ctx.save();
            const roomIndex = rooms.indexOf(room);
            const isDropTarget = this.isDraggingReservation && this.dragMode === 'move' &&
                this.dragTargetRoom && this.dragTargetRoom.id === room.id &&
                this.dragTargetRoom.id !== this.dragOriginalData?.room_id;

            if (isDropTarget) {
                this.ctx.fillStyle = '#4CAF50';
                this.ctx.globalAlpha = 0.3;
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, animatedHeight);
                this.ctx.globalAlpha = 1.0;
            } else {
                this.ctx.globalAlpha = 0.2;
                this.ctx.fillStyle = roomIndex % 2 === 0 ? '#000000' : '#ffffff';
                this.ctx.fillRect(this.sidebarWidth, baseRoomY, this.canvas.width - this.sidebarWidth, animatedHeight);
                this.ctx.globalAlpha = 1.0;
            }
            this.ctx.restore();

            this.shadeWeekendColumns({ y: baseRoomY, height: animatedHeight }, startDate, endDate, { barWidth: this.DAY_WIDTH, offsetY: baseRoomY, height: animatedHeight });
            this.shadeTodayColumn({ y: baseRoomY, height: animatedHeight }, startDate, endDate, { barWidth: this.DAY_WIDTH, offsetY: baseRoomY, height: animatedHeight });

            // Render Reservierungen - Ghost-Reservierungen werden NIEMALS sichtbar gerendert  
            sortedReservations.forEach(reservation => {
                // Skip ghost reservations KOMPLETT - sie sind nur für Stacking-Berechnung
                if (reservation._isGhost || reservation._isPreview) {
                    return; // Keine Sichtbarkeit für Ghost-Reservierungen
                }

                const barHeight = this.ROOM_BAR_HEIGHT; // Verwende dynamische Balkenhöhe
                const stackY = baseRoomY + 1 + (reservation.stackLevel * (this.ROOM_BAR_HEIGHT + 2));
                const isHovered = this.isReservationHovered(reservation.left, stackY, reservation.width, this.ROOM_BAR_HEIGHT);

                this.renderRoomReservationBar(reservation.left, stackY, reservation.width, this.ROOM_BAR_HEIGHT, reservation, isHovered);

                // Queue sticky note for top-level rendering (Z-order)
                if (reservation.note && reservation.note.trim() !== '') {
                    const noteData = {
                        barX: reservation.left,
                        barY: stackY,
                        barWidth: reservation.width,
                        barHeight: this.ROOM_BAR_HEIGHT,
                        detail: reservation
                    };

                    this.stickyNotesQueue.push(noteData);

                    // Cache the note data for performance optimization
                    const detailId = reservation.id || reservation.detail_id;
                    if (detailId) {
                        this.stickyNotesCache.set(detailId, {
                            barX: reservation.left,
                            barY: stackY,
                            barWidth: reservation.width,
                            barHeight: this.ROOM_BAR_HEIGHT
                        });
                    }
                }

                if (isHovered) {
                    this.hoveredReservation = reservation;
                }
            });

            this.renderOverCapacityIndicators(room, sortedReservations, startDate, endDate, baseRoomY, animatedHeight);

            // Zimmer-Trennlinie
            this.ctx.save();
            this.ctx.strokeStyle = '#444';
            this.ctx.lineWidth = 1;
            this.ctx.beginPath();
            this.ctx.moveTo(this.sidebarWidth, baseRoomY + animatedHeight);
            this.ctx.lineTo(this.canvas.width, baseRoomY + animatedHeight);
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
            const roomDisplayY = baseRoomY + (height / 2) + (this.sidebarFontSize / 3);

            if (roomDisplayY >= this.areas.rooms.y && roomDisplayY <= this.areas.rooms.y + this.areas.rooms.height) {
                this.ctx.fillStyle = this.themeConfig.sidebar.text;
                this.ctx.font = `${this.sidebarFontSize}px Arial`;
                this.ctx.textAlign = 'center';

                const caption = room.caption || `R${room.id}`;
                this.ctx.fillText(caption, this.sidebarWidth / 2, roomDisplayY);
            }
        });

        this.ctx.restore();
    }

    renderParentIdCurves(startDate, endDate, area) {
        if (!roomDetails || !Array.isArray(roomDetails)) {
            return;
        }

        const debug = window.debugMode || false;

        // Create a lookup map for all details by ID for faster parent lookup
        const detailsById = {};
        roomDetails.forEach(detail => {
            const id = detail.id || detail.detail_id;
            if (id) {
                detailsById[id] = detail;
            }
            // Also add by detail_id for ParentID lookup
            if (detail.detail_id) {
                detailsById[detail.detail_id] = detail;
            }
        });

        // Find all child details that have ParentID > 0
        const childDetails = roomDetails.filter(detail =>
            detail.ParentID &&
            Number(detail.ParentID) > 0 &&
            detailsById[detail.ParentID]
        );

        if (debug) {
            console.log(`[ParentID Curves] Found ${childDetails.length} child details with valid ParentIDs`);
        }

        if (childDetails.length === 0) {
            return;
        }

        this.ctx.save();

        // Set style for the curves
        this.ctx.strokeStyle = 'rgba(255, 165, 0, 0.8)'; // Orange color
        this.ctx.lineWidth = 2;
        this.ctx.lineCap = 'round';

        const startX = this.sidebarWidth - this.scrollX;
        const visibleRooms = this.getVisibleRooms();

        childDetails.forEach(childDetail => {
            const parentDetail = detailsById[childDetail.ParentID];
            if (!parentDetail) return;

            // Find coordinates for both parent and child bars
            const parentCoords = this.getDetailBarCoordinates(parentDetail, startDate, startX, visibleRooms);
            const childCoords = this.getDetailBarCoordinates(childDetail, startDate, startX, visibleRooms);

            if (!parentCoords || !childCoords) {
                if (debug) {
                    console.log(`[ParentID Curves] Skipping curve - coordinates not found for child ${childDetail.id} -> parent ${parentDetail.id}`);
                }
                return;
            }

            // Draw extended curve with horizontal segments from right end of parent to left start of child
            const startPointX = parentCoords.x + parentCoords.width; // Right end of parent
            const startPointY = parentCoords.y + (parentCoords.height / 2); // Middle of parent bar

            const endPointX = childCoords.x; // Left start of child
            const endPointY = childCoords.y + (childCoords.height / 2); // Middle of child bar

            // Calculate quarter day length for horizontal segments
            const quarterDay = this.DAY_WIDTH / 4;

            // Define intermediate points for extended curve
            const horizontalStartX = startPointX + quarterDay; // First horizontal segment end
            const horizontalEndX = endPointX - quarterDay; // Second horizontal segment start

            // Control points for very smooth horizontal transitions
            const horizontalDistance = Math.abs(horizontalEndX - horizontalStartX);
            const controlDistance = Math.min(horizontalDistance / 1.5, quarterDay * 3); // Much larger distance for very smooth curve

            // First control point: continue horizontally to the RIGHT from start point
            const cp1X = horizontalStartX + controlDistance;
            const cp1Y = startPointY;

            // Second control point: come horizontally from the LEFT to end point  
            const cp2X = horizontalEndX - controlDistance;
            const cp2Y = endPointY;

            // Draw the extended curve path
            this.ctx.beginPath();
            this.ctx.moveTo(startPointX, startPointY);

            // First horizontal segment
            this.ctx.lineTo(horizontalStartX, startPointY);

            // Curved middle section with very smooth horizontal tangents
            this.ctx.bezierCurveTo(cp1X, cp1Y, cp2X, cp2Y, horizontalEndX, endPointY);

            // Final horizontal segment
            this.ctx.lineTo(endPointX, endPointY);

            this.ctx.stroke();

            if (debug) {
                console.log(`[ParentID Curves] Drew curve from child ${childDetail.id} to parent ${parentDetail.id}`);
            }
        });

        this.ctx.restore();
        if (debug && childDetails.length > 0) {
            console.log(`[ParentID Curves] Render complete - drew ${childDetails.length} curves`);
        }
    }

    getDetailBarCoordinates(detail, startDate, startX, visibleRooms) {
        // Find which room this detail belongs to
        const roomData = visibleRooms.find(({ room }) =>
            room.id === detail.room_id ||
            String(room.id) === String(detail.room_id) ||
            Number(room.id) === Number(detail.room_id)
        );

        if (!roomData) {
            return null;
        }

        const { room, yOffset } = roomData;

        // Calculate temporal coordinates
        const checkinDate = new Date(detail.start);
        checkinDate.setHours(12, 0, 0, 0);
        const checkoutDate = new Date(detail.end);
        checkoutDate.setHours(12, 0, 0, 0);

        const startOffset = (checkinDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
        const duration = (checkoutDate.getTime() - checkinDate.getTime()) / (1000 * 60 * 60 * 24);

        const x = startX + (startOffset + 0.01) * this.DAY_WIDTH;
        const width = (duration - 0.02) * this.DAY_WIDTH;

        // Check if bar is visible
        if (x + width <= this.sidebarWidth || x >= this.canvas.width) {
            return null;
        }

        // Calculate stack level - we need to replicate the stacking logic
        const roomReservations = roomDetails.filter(rd =>
            rd.room_id === room.id ||
            String(rd.room_id) === String(room.id) ||
            Number(rd.room_id) === Number(room.id)
        );

        const positionedReservations = roomReservations.map(rd => {
            const checkin = new Date(rd.start);
            checkin.setHours(12, 0, 0, 0);
            const checkout = new Date(rd.end);
            checkout.setHours(12, 0, 0, 0);

            const rdStartOffset = (checkin.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24);
            const rdDuration = (checkout.getTime() - checkin.getTime()) / (1000 * 60 * 60 * 24);

            return {
                ...rd,
                left: startX + (rdStartOffset + 0.01) * this.DAY_WIDTH,
                width: (rdDuration - 0.02) * this.DAY_WIDTH,
                startOffset: rdStartOffset,
                stackLevel: 0
            };
        }).sort((a, b) => a.startOffset - b.startOffset);

        // Calculate stacking
        const OVERLAP_TOLERANCE = this.DAY_WIDTH * 0.1;
        positionedReservations.forEach((reservation, index) => {
            let stackLevel = 0;
            let placed = false;

            while (!placed) {
                let canPlaceHere = true;

                for (let i = 0; i < index; i++) {
                    const other = positionedReservations[i];
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

                if (stackLevel > 15) {
                    reservation.stackLevel = stackLevel;
                    placed = true;
                }
            }
        });

        // Find our specific detail
        const targetReservation = positionedReservations.find(pr =>
            (pr.id && pr.id === detail.id) ||
            (pr.detail_id && pr.detail_id === detail.detail_id) ||
            (pr.start === detail.start && pr.end === detail.end && pr.room_id === detail.room_id)
        );

        if (!targetReservation) {
            return null;
        }

        const baseRoomY = this.areas.rooms.y - this.roomsScrollY + yOffset;
        const stackY = baseRoomY + 1 + (targetReservation.stackLevel * (this.ROOM_BAR_HEIGHT + 2));

        return {
            x: x,
            y: stackY,
            width: width,
            height: this.ROOM_BAR_HEIGHT
        };
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

        const isFocused = this.isReservationFocused(reservation);
        const capacity = this.extractNumericCapacity(reservation);

        // Verwende Theme-Standard-Farbe wenn keine spezifische Farbe gesetzt
        let color = this.getMasterReservationColor(reservation);

        const renderX = x;
        const renderY = y;
        const renderWidth = width;
        const renderHeight = height;

        this.ctx.save();

        if (isFocused && !isHovered) {
            if (typeof color === 'string' && color.startsWith('#')) {
                color = this.lightenColor(color, 10);
            }
        }

        if (isHovered) {
            if (typeof color === 'string' && color.startsWith('#')) {
                color = this.lightenColor(color, 20);
            }
            this.ctx.shadowColor = 'rgba(0,0,0,0.25)';
            this.ctx.shadowBlur = 4;
            this.ctx.shadowOffsetX = 1;
            this.ctx.shadowOffsetY = 1;
        }

        this.ctx.fillStyle = color;
        this.roundedRect(renderX, renderY, renderWidth, renderHeight, 3);
        this.ctx.fill();
        this.ctx.restore();

        this.ctx.strokeStyle = isHovered ? 'rgba(0,0,0,0.3)' : 'rgba(0,0,0,0.1)';
        this.ctx.lineWidth = 1;
        this.ctx.stroke();

        const shouldRenderTouchHelpers = isFocused && this.focusedReservationSource === 'touch' &&
            !this.isDraggingReservation && renderHeight > 10 && renderWidth > 60;
        const shouldRenderHoverHelpers = isHovered && !this.isDraggingReservation && renderHeight > 10 && renderWidth > 60;

        let helperInsets = { leftInset: 0, rightInset: 0 };
        if (shouldRenderTouchHelpers || shouldRenderHoverHelpers) {
            const mode = shouldRenderTouchHelpers ? 'touch' : 'hover';
            helperInsets = this.renderTouchHelperIcons(renderX, renderY, renderWidth, renderHeight, { mode });
        }

        if (renderWidth > 40) {
            // Automatische Textfarbe basierend auf Balkenhelligkeit
            const textColor = this.getContrastColor(color);
            const masterFontSize = this.computeBarFontSize(renderHeight);
            if (this.themeConfig.master) {
                this.themeConfig.master.fontSize = masterFontSize;
            }
            this.ctx.fillStyle = textColor;
            this.ctx.font = `${masterFontSize}px Arial`;
            this.ctx.textAlign = 'left';

            // Name ermitteln (Nachname Vorname bevorzugt)
            let fullName = '';
            const nachname = reservation.nachname || '';
            const vorname = reservation.vorname || '';
            if (nachname && vorname) {
                fullName = `${nachname} ${vorname}`;
            } else if (nachname) {
                fullName = nachname;
            } else if (vorname) {
                fullName = vorname;
            } else {
                fullName = (reservation.name || reservation.guest_name || '').trim();
            }
            fullName = fullName.trim();

            // Arrangement-Kreis am rechten Rand vorbereiten (Buchstabe und Farbe abhängig von av_id)
            const arrangementRaw = (reservation.arrangement_name || reservation.arr_kbez || reservation.arrangement || reservation.data?.arrangement || '').trim();
            const arrangementLetterMatch = arrangementRaw.match(/[A-Za-zÄÖÜäöü]/);
            const arrangementLetter = arrangementLetterMatch ? arrangementLetterMatch[0].toUpperCase() : null;
            const hasCircle = Boolean(arrangementLetter);
            const circleDiameter = hasCircle ? Math.max(12, Math.min(renderHeight - 4, 18)) : 0;
            const circlePadding = hasCircle ? circleDiameter + 6 : 0;

            // Strikt: AV-Res.av_id > 0 (Masterdaten aus AV-Res), einmal berechnen und überall verwenden
            // av_id wird vom Loader im item.data belassen und als fullData auf das Master-Objekt gemappt
            const avIdRawForMaster = (reservation.fullData && reservation.fullData.av_id)
                ?? (reservation.data && reservation.data.av_id)
                ?? reservation.av_id
                ?? 0;
            const avIdNumForMaster = Number(avIdRawForMaster);
            const hasAvMaster = Number.isFinite(avIdNumForMaster) && avIdNumForMaster > 0;

            // Text: Gesamtanzahl Personen vor Nachname und Vorname
            let caption = `${capacity} ${fullName}`.trim();

            // Platz für Text unter Berücksichtigung des Kreis-Indikators
            const availableWidth = renderWidth - helperInsets.leftInset - helperInsets.rightInset - 8 - circlePadding;
            if (availableWidth > 0) {
                caption = this.truncateTextToWidth(caption, availableWidth);
                const textY = renderY + (renderHeight / 2) + (masterFontSize / 3);
                const textX = renderX + helperInsets.leftInset + 2;
                this.ctx.fillText(caption, textX, textY);
            }

            // Kreis rechts mit Buchstabe des Arrangements; Farbe grün wenn AV-Res.av_id vorhanden (>0 numerisch oder allgemein truthy)
            if (hasCircle && renderWidth > circleDiameter + 10 + helperInsets.rightInset) {
                const circleX = renderX + renderWidth - helperInsets.rightInset - (circleDiameter / 2) - 3;
                const circleY = renderY + renderHeight / 2;
                // Kreisfarbe strikt anhand der bereits berechneten AV-Kennung
                const circleFill = hasAvMaster ? '#2ecc71' : '#bdc3c7';
                const circleStroke = hasAvMaster ? '#1e8449' : '#95a5a6';

                this.ctx.beginPath();
                this.ctx.fillStyle = circleFill;
                this.ctx.strokeStyle = circleStroke;
                this.ctx.lineWidth = 1;
                this.ctx.arc(circleX, circleY, circleDiameter / 2, 0, Math.PI * 2);
                this.ctx.fill();
                this.ctx.stroke();

                this.ctx.fillStyle = hasAvMaster ? '#ffffff' : '#2d3436';
                this.ctx.font = `${Math.max(8, circleDiameter * 0.55)}px Arial`;
                this.ctx.textAlign = 'center';
                this.ctx.textBaseline = 'middle';
                this.ctx.fillText(arrangementLetter, circleX, circleY + 0.5);
                this.ctx.textAlign = 'left';
                this.ctx.textBaseline = 'alphabetic';
            }
        }

        // Hundesymbol vorne (links) mit Fade-Effekt, wenn AV-Res.hund=true
        if ((reservation.has_dog || reservation.hund || reservation.data?.has_dog) && width > 12) {
            const padding = Math.max(1, Math.floor(height * 0.1));
            const svgHeight = Math.max(10, height - padding * 2);
            const svgWidth = svgHeight;
            // Icon vor dem Balken positionieren (vorne links), halb herausstehend
            const svgX = x - svgWidth / 2;
            const svgY = y + (height - svgHeight) / 2;

            if (!this._dogSvgImage) {
                this._dogSvgImage = new Image();
                this._dogSvgImage.onload = () => this.render();
                this._dogSvgImage.src = 'http://192.168.15.14:8080/wci/pic/DogProfile.svg';
            }

            if (this._dogSvgImage.complete && this._dogSvgImage.naturalWidth > 0) {
                const now = Date.now();
                const cycle = 3000;
                const phase = (now % cycle) / cycle; // 0..1
                const t = (Math.sin(phase * Math.PI * 2) + 1) / 2; // 0..1 weich
                const w = { r: 255, g: 255, b: 0 };
                const b = { r: 139, g: 69, b: 19 };
                const cr = Math.round(w.r + (b.r - w.r) * t);
                const cg = Math.round(w.g + (b.g - w.g) * t);
                const cb = Math.round(w.b + (b.b - w.b) * t);
                const fadeColor = `rgb(${cr}, ${cg}, ${cb})`;

                const tempCanvas = document.createElement('canvas');
                tempCanvas.width = svgWidth;
                tempCanvas.height = svgHeight;
                const tctx = tempCanvas.getContext('2d');
                tctx.drawImage(this._dogSvgImage, 0, 0, svgWidth, svgHeight);
                tctx.globalCompositeOperation = 'source-in';
                tctx.fillStyle = fadeColor;
                tctx.fillRect(0, 0, svgWidth, svgHeight);

                this.ctx.save();
                // 50% Transparenz für Hundesymbol auf Master-Balken
                this.ctx.globalAlpha = 0.5;
                this.ctx.drawImage(tempCanvas, svgX, svgY);
                this.ctx.restore();

                if (!this._dogFadeAnimScheduled) {
                    this._dogFadeAnimScheduled = true;
                    requestAnimationFrame(() => {
                        this._dogFadeAnimScheduled = false;
                        this.render();
                    });
                }
            } else {
                // Fallback bis SVG geladen ist
                this.ctx.save();
                this.ctx.fillStyle = 'rgba(0,0,0,0.25)';
                this.ctx.beginPath();
                this.ctx.arc(svgX + svgWidth / 2, svgY + svgHeight / 2, Math.min(svgWidth, svgHeight) / 3, 0, Math.PI * 2);
                this.ctx.fill();
                this.ctx.restore();
            }
        }
    }

    renderTouchHelperIcons(renderX, renderY, renderWidth, renderHeight, options = {}) {
        const { mode = 'hover' } = options || {};

        const circleRadius = Math.max(10, Math.min(renderHeight * 0.45, 18, renderWidth / 8));
        if (circleRadius < 8 || renderWidth <= circleRadius * 2 + 12) {
            return { leftInset: 0, rightInset: 0 };
        }

        const baseGrey = mode === 'touch' ? 90 : 110;
        const fillOpacity = mode === 'touch' ? 0.28 : 0.18;
        const strokeOpacity = mode === 'touch' ? 0.85 : 0.7;
        const arrowOpacity = mode === 'touch' ? 0.85 : 0.75;
        const fillColor = `rgba(${baseGrey + 120}, ${baseGrey + 120}, ${baseGrey + 120}, ${fillOpacity})`;
        const strokeColor = `rgba(${baseGrey + 40}, ${baseGrey + 40}, ${baseGrey + 40}, ${strokeOpacity})`;
        const arrowColor = `rgba(${baseGrey}, ${baseGrey}, ${baseGrey}, ${arrowOpacity})`;

        const helperInsets = { leftInset: Math.min(renderWidth / 2, circleRadius + 6), rightInset: Math.min(renderWidth / 2, circleRadius + 6) };
        const circleCenters = [];

        const baseY = renderY + renderHeight / 2;
        const leftCenterX = renderX;
        const rightCenterX = renderX + renderWidth;
        circleCenters.push({ x: leftCenterX, y: baseY, kind: 'resize-left' });
        circleCenters.push({ x: rightCenterX, y: baseY, kind: 'resize-right' });

        if (rightCenterX - leftCenterX > circleRadius * 3) {
            circleCenters.push({ x: renderX + renderWidth / 2, y: baseY, kind: 'move' });
        }

        const drawCircle = (cx, cy) => {
            this.ctx.save();
            this.ctx.beginPath();
            this.ctx.fillStyle = fillColor;
            this.ctx.strokeStyle = strokeColor;
            this.ctx.lineWidth = 2;
            this.ctx.arc(cx, cy, circleRadius, 0, Math.PI * 2);
            this.ctx.fill();
            this.ctx.stroke();
            this.ctx.restore();
        };

        const drawArrow = (center, kind) => {
            const { x: cx, y: cy } = center;
            const arrowHead = Math.min(6, circleRadius * 0.45);
            const shaftHalf = circleRadius * 0.7;
            this.ctx.save();
            this.ctx.strokeStyle = arrowColor;
            this.ctx.fillStyle = arrowColor;
            this.ctx.lineWidth = 2;
            this.ctx.lineCap = 'round';
            this.ctx.setLineDash([]);

            if (kind === 'resize-left' || kind === 'resize-right') {
                const direction = kind === 'resize-left' ? -1 : 1;
                this.ctx.beginPath();
                this.ctx.moveTo(cx - shaftHalf, cy);
                this.ctx.lineTo(cx + shaftHalf, cy);
                this.ctx.stroke();

                const tipX = direction === -1 ? cx - shaftHalf : cx + shaftHalf;
                const baseX = tipX - direction * arrowHead;
                this.ctx.beginPath();
                this.ctx.moveTo(tipX, cy);
                this.ctx.lineTo(baseX, cy - arrowHead);
                this.ctx.lineTo(baseX, cy + arrowHead);
                this.ctx.closePath();
                this.ctx.fill();
            } else {
                const moveHalf = circleRadius * 0.6;
                const moveArrow = Math.min(5, circleRadius * 0.35);

                // Horizontale Linie
                this.ctx.beginPath();
                this.ctx.moveTo(cx - moveHalf, cy);
                this.ctx.lineTo(cx + moveHalf, cy);
                this.ctx.stroke();

                // Vertikale Linie
                this.ctx.beginPath();
                this.ctx.moveTo(cx, cy - moveHalf);
                this.ctx.lineTo(cx, cy + moveHalf);
                this.ctx.stroke();

                // Linke Pfeilspitze
                this.ctx.beginPath();
                this.ctx.moveTo(cx - moveHalf, cy);
                this.ctx.lineTo(cx - moveHalf + moveArrow, cy - moveArrow);
                this.ctx.lineTo(cx - moveHalf + moveArrow, cy + moveArrow);
                this.ctx.closePath();
                this.ctx.fill();

                // Rechte Pfeilspitze
                this.ctx.beginPath();
                this.ctx.moveTo(cx + moveHalf, cy);
                this.ctx.lineTo(cx + moveHalf - moveArrow, cy - moveArrow);
                this.ctx.lineTo(cx + moveHalf - moveArrow, cy + moveArrow);
                this.ctx.closePath();
                this.ctx.fill();

                // Obere Pfeilspitze
                this.ctx.beginPath();
                this.ctx.moveTo(cx, cy - moveHalf);
                this.ctx.lineTo(cx - moveArrow, cy - moveHalf + moveArrow);
                this.ctx.lineTo(cx + moveArrow, cy - moveHalf + moveArrow);
                this.ctx.closePath();
                this.ctx.fill();

                // Untere Pfeilspitze
                this.ctx.beginPath();
                this.ctx.moveTo(cx, cy + moveHalf);
                this.ctx.lineTo(cx - moveArrow, cy + moveHalf - moveArrow);
                this.ctx.lineTo(cx + moveArrow, cy + moveHalf - moveArrow);
                this.ctx.closePath();
                this.ctx.fill();
            }

            this.ctx.restore();
        };

        circleCenters.forEach(center => {
            drawCircle(center.x, center.y);
            drawArrow(center, center.kind);
        });

        return helperInsets;
    }

    renderRoomReservationBar(x, y, width, height, detail, isHovered = false) {
        // Ghost-Reservierungen werden NIEMALS sichtbar gerendert - nur für Stacking
        if (detail._isGhost) {
            return; // Komplette Verweigerung der Sichtbarkeit
        }

        const isFocused = this.isReservationFocused(detail);

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

        let mismatchInfo = this.getCapacityMismatchInfo(detail);
        let hasCapacityMismatch = Boolean(mismatchInfo && mismatchInfo.masterCapacity !== null && mismatchInfo.mismatch);
        let hasDateMismatch = Boolean(mismatchInfo && mismatchInfo.dateMismatch);
        let hasMismatch = hasCapacityMismatch || hasDateMismatch;

        if (this.isDraggingReservation && isSourceOfDrag) {
            // Unterdrücke Warnungen während der Balken aktiv verschoben wird
            mismatchInfo = null;
            hasCapacityMismatch = false;
            hasDateMismatch = false;
            hasMismatch = false;
        }
        let mismatchPulse = 0;
        let mismatchScale = 1;

        if (hasMismatch) {
            const pulsePhase = Date.now() / 260;
            mismatchPulse = (Math.sin(pulsePhase) + 1) / 2;
            mismatchScale = 1 + mismatchPulse * 0.06;
            if (typeof color === 'string' && color.startsWith('#')) {
                const lightenAmount = 12 + (mismatchPulse * 14);
                color = this.lightenColor(color, lightenAmount);
            }
            this.scheduleRender('capacity_mismatch_pulse');
        }

        // Drag & Drop visuelles Feedback
        const isDropTarget = this.isDraggingReservation && this.dragMode === 'move' &&
            this.dragTargetRoom && this.dragTargetRoom.id !== this.dragOriginalData?.room_id;

        if (isFocused && !isSourceOfDrag) {
            color = this.lightenColor(color, 10);
        }

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

        if (hasMismatch && !isSourceOfDrag) {
            const widthInflation = renderWidth * ((mismatchScale - 1) * 0.35);
            const heightInflation = renderHeight * (mismatchScale - 1);
            renderX -= widthInflation / 2;
            renderWidth += widthInflation;
            renderY -= heightInflation / 2;
            renderHeight += heightInflation;

            this.ctx.shadowColor = `rgba(255, 210, 40, ${0.55 + mismatchPulse * 0.35})`;
            this.ctx.shadowBlur = 14 + mismatchPulse * 18;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        this.roundedRect(renderX, renderY, renderWidth, renderHeight, 3);
        this.ctx.fill();

        // Shadow reset nach dem Balken-Rendering
        if (isSourceOfDrag) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.globalAlpha = 1.0; // Alpha zurücksetzen
        } else if (hasMismatch) {
            this.ctx.shadowColor = 'transparent';
            this.ctx.shadowBlur = 0;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        if (hasMismatch && renderWidth > 6 && renderHeight > 4) {
            const overlayAlpha = 0.25 + (mismatchPulse * 0.35);
            this.ctx.save();
            this.ctx.globalCompositeOperation = 'lighter';
            this.ctx.fillStyle = `rgba(255, 196, 0, ${overlayAlpha})`;
            this.roundedRect(renderX, renderY, renderWidth, renderHeight, 3);
            this.ctx.fill();
            this.ctx.restore();

            this.ctx.save();
            const strokeAlpha = 0.55 + (mismatchPulse * 0.45);
            this.ctx.lineWidth = 1.4 + mismatchPulse * 1.6;
            this.ctx.strokeStyle = `rgba(255, 185, 0, ${strokeAlpha})`;
            const strokePadding = 1.5 + mismatchPulse * 1.5;
            this.roundedRect(renderX - strokePadding, renderY - strokePadding, renderWidth + strokePadding * 2, renderHeight + strokePadding * 2, 4 + mismatchPulse * 2);
            this.ctx.stroke();
            this.ctx.restore();
        }

        // Border - auch für vergrößerten Balken
        this.ctx.strokeStyle = isHovered ? 'rgba(0,0,0,0.3)' : 'rgba(0,0,0,0.1)';
        this.ctx.lineWidth = 1;
        this.ctx.stroke();

        const shouldRenderTouchHelpers = isFocused && this.focusedReservationSource === 'touch' &&
            !this.isDraggingReservation && !isSourceOfDrag && renderHeight > 10 && renderWidth > 60;
        const shouldRenderHoverHelpers = isHovered && !this.isDraggingReservation && !isSourceOfDrag && renderHeight > 10 && renderWidth > 60;

        let helperInsets = { leftInset: 0, rightInset: 0 };
        if (shouldRenderTouchHelpers || shouldRenderHoverHelpers) {
            const mode = shouldRenderTouchHelpers ? 'touch' : 'hover';
            helperInsets = this.renderTouchHelperIcons(renderX, renderY, renderWidth, renderHeight, { mode });
        }

        if (isFocused && renderWidth > 6 && renderHeight > 6) {
            this.ctx.save();
            const focusPadding = Math.min(12, Math.max(4, renderHeight * 0.4));
            this.ctx.lineWidth = 2.2;
            this.ctx.strokeStyle = 'rgba(255, 214, 10, 0.9)';
            this.ctx.setLineDash([6, 4]);
            this.roundedRect(renderX - focusPadding / 2, renderY - focusPadding / 2,
                renderWidth + focusPadding, renderHeight + focusPadding, 4.5);
            this.ctx.stroke();
            this.ctx.restore();
        }

        if (renderWidth > 12) {
            const textColor = this.getContrastColor(color);
            this.ctx.fillStyle = textColor;

            const dynamicFontSize = this.computeBarFontSize(renderHeight);
            if (this.themeConfig.room) {
                this.themeConfig.room.fontSize = dynamicFontSize;
            }
            this.ctx.font = `${dynamicFontSize}px Arial`;
            this.ctx.textAlign = 'left';
            this.ctx.textBaseline = 'alphabetic';

            let text = this.getDetailCaption(detail);
            // Hundesymbol nicht mehr an Bezeichnung anhängen

            const arrangementRaw = (detail.arrangement_label || detail.data?.arrangement || detail.data?.arrangement_kbez || '').trim();
            const arrangementLetterMatch = arrangementRaw.match(/[A-Za-zÄÖÜäöü]/);
            const arrangementLetter = arrangementLetterMatch ? arrangementLetterMatch[0].toUpperCase() : null;
            const hasCircle = Boolean(arrangementLetter);
            const circleDiameter = hasCircle ? Math.max(12, Math.min(renderHeight - 4, 18)) : 0;
            const circlePadding = hasCircle ? circleDiameter + 6 : 0;

            // Schriftposition NICHT wegen Hund verschieben
            const availableWidth = renderWidth - helperInsets.leftInset - helperInsets.rightInset - 8 - circlePadding;
            if (availableWidth > 0) {
                const truncated = this.truncateTextToWidth(text, availableWidth);
                const textY = renderY + (renderHeight / 2) + (dynamicFontSize / 3);
                const textX = renderX + helperInsets.leftInset + 3;
                this.ctx.fillText(truncated, textX, textY);
            }

            if (hasCircle && renderWidth > circleDiameter + 10 + helperInsets.rightInset) {
                const circleX = renderX + renderWidth - helperInsets.rightInset - (circleDiameter / 2) - 3;
                const circleY = renderY + renderHeight / 2;
                // Strikt: AV-Res.av_id > 0 (aus Join in den Room-Details)
                const avIdRaw = (detail.data && detail.data.av_id) ?? detail.av_id ?? 0;
                const avIdNum = Number(avIdRaw);
                const hasAv = Number.isFinite(avIdNum) && avIdNum > 0;
                const circleFill = hasAv ? '#2ecc71' : '#bdc3c7';
                const circleStroke = hasAv ? '#1e8449' : '#95a5a6';

                this.ctx.beginPath();
                this.ctx.fillStyle = circleFill;
                this.ctx.strokeStyle = circleStroke;
                this.ctx.lineWidth = 1;
                this.ctx.arc(circleX, circleY, circleDiameter / 2, 0, Math.PI * 2);
                this.ctx.fill();
                this.ctx.stroke();

                this.ctx.fillStyle = hasAv ? '#ffffff' : '#2d3436';
                this.ctx.font = `${Math.max(8, circleDiameter * 0.55)}px Arial`;
                this.ctx.textAlign = 'center';
                this.ctx.textBaseline = 'middle';
                this.ctx.fillText(arrangementLetter, circleX, circleY + 0.5);
                this.ctx.textAlign = 'left';
                this.ctx.textBaseline = 'alphabetic';
            }
        }

        // Hund-SVG-Markierung: wenn Hund=true, Icon in Balkenhöhe anzeigen
        if ((detail.has_dog || detail.hund || (detail.data && detail.data.has_dog)) && renderWidth > 12) {
            const padding = Math.max(1, Math.floor(renderHeight * 0.1));
            const svgHeight = Math.max(10, renderHeight - padding * 2); // exakte Balkenhöhe (mit etwas Innenabstand)
            const svgWidth = svgHeight; // quadratisch
            // Icon um halbe Balkenbreite nach links schieben
            const svgX = renderX - svgWidth / 2;
            const svgY = renderY + (renderHeight - svgHeight) / 2; // vertikal zentriert

            // SVG laden und cachen
            if (!this._dogSvgImage) {
                this._dogSvgImage = new Image();
                this._dogSvgImage.onload = () => this.render();
                this._dogSvgImage.src = 'http://192.168.15.14:8080/wci/pic/DogProfile.svg';
            }

            if (this._dogSvgImage.complete && this._dogSvgImage.naturalWidth > 0) {
                // Weiß↔Braun Fade-Farbe (2s Zyklus)
                const now = Date.now();
                const cycle = 3000;
                const phase = (now % cycle) / cycle; // 0..1
                const t = (Math.sin(phase * Math.PI * 2) + 1) / 2; // weich 0..1
                const w = { r: 255, g: 255, b: 0 };
                const b = { r: 139, g: 69, b: 19 };
                const cr = Math.round(w.r + (b.r - w.r) * t);
                const cg = Math.round(w.g + (b.g - w.g) * t);
                const cb = Math.round(w.b + (b.b - w.b) * t);
                const fadeColor = `rgb(${cr}, ${cg}, ${cb})`;

                // Masking auf temporärem Canvas, um SVG farbig zu füllen
                const tempCanvas = document.createElement('canvas');
                tempCanvas.width = svgWidth;
                tempCanvas.height = svgHeight;
                const tctx = tempCanvas.getContext('2d');
                tctx.drawImage(this._dogSvgImage, 0, 0, svgWidth, svgHeight);
                tctx.globalCompositeOperation = 'source-in';
                tctx.fillStyle = fadeColor;
                tctx.fillRect(0, 0, svgWidth, svgHeight);

                // Ergebnis zeichnen
                this.ctx.save();
                this.ctx.globalAlpha = 0.5;
                this.ctx.drawImage(tempCanvas, svgX, svgY);
                this.ctx.restore();

                // Animation triggern (sanft)
                if (!this._dogFadeAnimScheduled) {
                    this._dogFadeAnimScheduled = true;
                    requestAnimationFrame(() => {
                        this._dogFadeAnimScheduled = false;
                        this.render();
                    });
                }
            } else {
                // Fallback: neutrale Platzhaltermarke, bis SVG lädt
                this.ctx.save();
                this.ctx.fillStyle = 'rgba(0,0,0,0.25)';
                this.ctx.beginPath();
                this.ctx.arc(svgX + svgWidth / 2, svgY + svgHeight / 2, Math.min(svgWidth, svgHeight) / 3, 0, Math.PI * 2);
                this.ctx.fill();
                this.ctx.restore();
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

    // Render sticky note for reservation detail with note text
    renderStickyNote(barX, barY, barWidth, barHeight, detail) {
        // Debug logging - check all note-related fields
        if (window.debugStickyNotes) {
            console.log('renderStickyNote called for detail:', {
                id: detail.detail_id || detail.id,
                note: detail.note,
                dx: detail.dx,
                dy: detail.dy,
                allKeys: Object.keys(detail)
            });
        }

        // Skip if no note text
        if (!detail.note || detail.note.trim() === '') {
            return;
        }

        if (window.debugStickyNotes) {
            console.log('🟡 RENDERING STICKY NOTE:', detail.note, 'at dx:', detail.dx, 'dy:', detail.dy);
        }

        // Get dx, dy offsets (default to 0 if not set)
        const dx = parseFloat(detail.dx) || 0;
        const dy = -(parseFloat(detail.dy) || 0); // Fix: multiply dy by -1

        // Calculate sticky note position relative to bar end
        const barEndX = barX + barWidth;
        const barCenterY = barY + (barHeight / 2);

        const noteX = barEndX + dx;
        const noteY = barCenterY + dy;

        // Sticky note dimensions
        const noteWidth = 120;
        const noteHeight = 80;
        const cornerRadius = 4;

        // Check if this sticky note is being dragged or animated
        const isDragging = this.isDraggingStickyNote &&
            this.draggedStickyNote &&
            this.draggedStickyNote.detail_id === (detail.detail_id || detail.id);

        const detailId = detail.detail_id || detail.id;
        const animation = this.animatingNotes.get(detailId);
        let scale = 1.0;
        let animationProgress = 0;

        // Calculate animation scale for smooth transitions
        if (animation) {
            const elapsed = Date.now() - animation.startTime;
            animationProgress = Math.min(elapsed / animation.duration, 1);

            if (animation.type === 'dragStart') {
                // Small scale up when starting drag
                scale = 1.0 + (0.1 * Math.sin(animationProgress * Math.PI));
            } else if (animation.type === 'dragEnd') {
                // Quick scale down when ending drag
                scale = 1.05 * (1 - animationProgress) + 1.0 * animationProgress;
            }

            // Remove completed animations
            if (animationProgress >= 1) {
                this.animatingNotes.delete(detailId);
            } else {
                // Schedule next frame for smooth animation
                requestAnimationFrame(() => this.render());
            }
        }

        // Additional scale for dragging state
        if (isDragging) {
            scale *= 1.05; // Slightly larger when dragging
        }

        this.ctx.save();

        // ALWAYS render sticky notes on top - force highest Z-order
        this.ctx.globalCompositeOperation = 'source-over';

        // Apply scale transformation for animations
        if (scale !== 1.0) {
            const centerX = noteX + noteWidth / 2;
            const centerY = noteY + noteHeight / 2;
            this.ctx.translate(centerX, centerY);
            this.ctx.scale(scale, scale);
            this.ctx.translate(-centerX, -centerY);
        }

        // Enhanced shadow for all sticky notes (stronger when dragging)
        if (isDragging) {
            this.ctx.shadowColor = 'rgba(0,0,0,0.4)';
            this.ctx.shadowBlur = 10;
            this.ctx.shadowOffsetX = 3;
            this.ctx.shadowOffsetY = 3;
        } else {
            this.ctx.shadowColor = 'rgba(0,0,0,0.2)';
            this.ctx.shadowBlur = 4;
            this.ctx.shadowOffsetX = 1;
            this.ctx.shadowOffsetY = 1;
        }

        // Draw arrow from bar end to sticky note
        this.ctx.strokeStyle = '#666';
        this.ctx.lineWidth = 1;
        this.ctx.setLineDash([2, 2]); // Dashed line
        this.ctx.beginPath();
        this.ctx.moveTo(barEndX, barCenterY);
        this.ctx.lineTo(noteX, noteY);
        this.ctx.stroke();
        this.ctx.setLineDash([]); // Reset dash

        // Draw sticky note background (semi-transparent yellow, more opaque when dragging)
        this.ctx.fillStyle = isDragging ? 'rgba(255, 215, 0, 0.95)' : 'rgba(255, 255, 153, 0.85)';
        this.ctx.strokeStyle = isDragging ? '#ff6b35' : '#e6e600';
        this.ctx.lineWidth = isDragging ? 3 : 1;

        // Add glow effect when dragging
        if (isDragging) {
            this.ctx.shadowColor = 'rgba(255, 215, 0, 0.5)';
            this.ctx.shadowBlur = 12;
            this.ctx.shadowOffsetX = 0;
            this.ctx.shadowOffsetY = 0;
        }

        // Rounded rectangle for sticky note
        this.ctx.beginPath();
        this.ctx.moveTo(noteX + cornerRadius, noteY);
        this.ctx.lineTo(noteX + noteWidth - cornerRadius, noteY);
        this.ctx.quadraticCurveTo(noteX + noteWidth, noteY, noteX + noteWidth, noteY + cornerRadius);
        this.ctx.lineTo(noteX + noteWidth, noteY + noteHeight - cornerRadius);
        this.ctx.quadraticCurveTo(noteX + noteWidth, noteY + noteHeight, noteX + noteWidth - cornerRadius, noteY + noteHeight);
        this.ctx.lineTo(noteX + cornerRadius, noteY + noteHeight);
        this.ctx.quadraticCurveTo(noteX, noteY + noteHeight, noteX, noteY + noteHeight - cornerRadius);
        this.ctx.lineTo(noteX, noteY + cornerRadius);
        this.ctx.quadraticCurveTo(noteX, noteY, noteX + cornerRadius, noteY);
        this.ctx.closePath();
        this.ctx.fill();
        this.ctx.stroke();

        // Draw folded corner effect (small triangle in top-right)
        const foldSize = 12;
        this.ctx.fillStyle = 'rgba(230, 230, 0, 0.3)';
        this.ctx.beginPath();
        this.ctx.moveTo(noteX + noteWidth - foldSize, noteY);
        this.ctx.lineTo(noteX + noteWidth, noteY + foldSize);
        this.ctx.lineTo(noteX + noteWidth, noteY);
        this.ctx.closePath();
        this.ctx.fill();

        // Draw note text
        this.ctx.fillStyle = '#333';
        this.ctx.font = '11px Arial';
        this.ctx.textAlign = 'left';
        this.ctx.textBaseline = 'top';

        // Word wrap text within sticky note
        const maxLineWidth = noteWidth - 16; // Padding
        const lineHeight = 14;
        const maxLines = Math.floor((noteHeight - 16) / lineHeight);

        const words = detail.note.trim().split(/\s+/);
        const lines = [];
        let currentLine = '';

        for (const word of words) {
            const testLine = currentLine ? currentLine + ' ' + word : word;
            const textWidth = this.ctx.measureText(testLine).width;

            if (textWidth <= maxLineWidth) {
                currentLine = testLine;
            } else {
                if (currentLine) {
                    lines.push(currentLine);
                    currentLine = word;
                } else {
                    // Word is too long, truncate it
                    lines.push(word.substring(0, 15) + '...');
                    currentLine = '';
                }

                if (lines.length >= maxLines) {
                    break;
                }
            }
        }

        if (currentLine && lines.length < maxLines) {
            lines.push(currentLine);
        }

        // Draw the text lines
        lines.forEach((line, index) => {
            this.ctx.fillText(line, noteX + 8, noteY + 8 + (index * lineHeight));
        });

        // Store sticky note bounds for click detection
        if (!this.stickyNoteBounds) {
            this.stickyNoteBounds = [];
        }

        this.stickyNoteBounds.push({
            detail_id: detail.detail_id || detail.id,
            detail: detail,
            x: noteX,
            y: noteY,
            width: noteWidth,
            height: noteHeight,
            barEndX: barEndX,
            barCenterY: barCenterY
        });

        this.ctx.restore();
    }

    // Render ghost rectangle during sticky note dragging
    renderStickyNoteGhost() {
        if (!this.stickyNoteGhost || !this.stickyNoteGhost.visible) {
            return;
        }

        const ghost = this.stickyNoteGhost;
        const cornerRadius = 4;

        this.ctx.save();

        // Semi-transparent ghost with dashed border
        this.ctx.globalAlpha = 0.4;
        this.ctx.fillStyle = 'rgba(255, 215, 0, 0.3)';
        this.ctx.strokeStyle = 'rgba(255, 100, 50, 0.8)';
        this.ctx.lineWidth = 2;
        this.ctx.setLineDash([5, 5]); // Dashed border for ghost effect

        // Draw rounded rectangle for ghost
        this.ctx.beginPath();
        this.ctx.moveTo(ghost.x + cornerRadius, ghost.y);
        this.ctx.lineTo(ghost.x + ghost.width - cornerRadius, ghost.y);
        this.ctx.quadraticCurveTo(ghost.x + ghost.width, ghost.y, ghost.x + ghost.width, ghost.y + cornerRadius);
        this.ctx.lineTo(ghost.x + ghost.width, ghost.y + ghost.height - cornerRadius);
        this.ctx.quadraticCurveTo(ghost.x + ghost.width, ghost.y + ghost.height, ghost.x + ghost.width - cornerRadius, ghost.y + ghost.height);
        this.ctx.lineTo(ghost.x + cornerRadius, ghost.y + ghost.height);
        this.ctx.quadraticCurveTo(ghost.x, ghost.y + ghost.height, ghost.x, ghost.y + ghost.height - cornerRadius);
        this.ctx.lineTo(ghost.x, ghost.y + cornerRadius);
        this.ctx.quadraticCurveTo(ghost.x, ghost.y, ghost.x + cornerRadius, ghost.y);
        this.ctx.closePath();
        this.ctx.fill();
        this.ctx.stroke();

        // Reset line dash
        this.ctx.setLineDash([]);

        this.ctx.restore();
    }

    // Render all queued sticky notes on top (highest Z-order)
    renderAllStickyNotes() {
        if (!this.stickyNotesQueue || this.stickyNotesQueue.length === 0) {
            return;
        }

        const now = performance.now();

        // Performance optimization: Only re-render sticky notes if enough time has passed
        // or if we're dragging (which needs immediate updates)
        const shouldThrottle = !this.isDraggingStickyNote &&
            (now - this.lastStickyNotesRender < this.stickyNotesRenderThreshold);

        if (shouldThrottle) {
            // Use cached version if available and recent
            if (this.stickyNotesCache.size > 0) {
                this.renderCachedStickyNotes();
                return;
            }
        }

        // Update render timestamp
        this.lastStickyNotesRender = now;

        // Only log when actually rendering (not using cache)
        if (window.debugStickyNotes) {
            console.log(`🎯 Rendering ${this.stickyNotesQueue.length} sticky notes on top layer`);
        }

        // Clear cache and render fresh
        this.stickyNotesCache.clear();

        // Render each sticky note with maximum Z-order priority
        this.stickyNotesQueue.forEach(noteData => {
            this.renderStickyNote(noteData.barX, noteData.barY, noteData.barWidth, noteData.barHeight, noteData.detail);
        });
    }

    // Render cached sticky notes without re-calculation
    renderCachedStickyNotes() {
        // Simply re-use the last rendered sticky notes positions
        // This is much faster for hover events that don't affect sticky notes
        for (const [detailId, cachedData] of this.stickyNotesCache) {
            const detail = this.stickyNotesQueue.find(q => q.detail.id === detailId || q.detail.detail_id === detailId);
            if (detail) {
                this.renderStickyNote(cachedData.barX, cachedData.barY, cachedData.barWidth, cachedData.barHeight, detail.detail);
            }
        }
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
        this.setHistogramSource(histogramSourceData, histogramStornoSourceData);

        this.updateRoomLookups();
        this.normalizeRoomDetails();
        this.invalidateStackingCache();
        this.markDataDirty();

        // Verwende konfigurierten Datumsbereich (auf 0 Uhr fixiert)
        const { startDate, endDate } = this.getTimelineDateRange();
        this.startDate = startDate;
        this.endDate = endDate;

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

        // Persist size for future sessions
        try {
            localStorage.setItem('timeline_menu_size', String(newSize));
        } catch (error) {
            console.warn('Konnte timeline_menu_size nicht speichern:', error);
        }

        // Sync toolbar controls if available
        if (typeof window !== 'undefined') {
            const slider = document.getElementById('timeline-menu-size');
            const display = document.getElementById('timeline-menu-size-display');
            if (slider) slider.value = String(newSize);
            if (display) display.textContent = `${newSize}px`;

            if (typeof window.updateTimelineToolbarValues === 'function') {
                window.updateTimelineToolbarValues();
            }
        }
    }

    // Find sticky note at mouse position
    findStickyNoteAt(mouseX, mouseY) {
        if (!this.stickyNoteBounds || this.stickyNoteBounds.length === 0) {
            return null;
        }

        // Check each sticky note bounds
        for (const stickyNote of this.stickyNoteBounds) {
            if (mouseX >= stickyNote.x && mouseX <= stickyNote.x + stickyNote.width &&
                mouseY >= stickyNote.y && mouseY <= stickyNote.y + stickyNote.height) {
                return stickyNote;
            }
        }
        return null;
    }

    // Start dragging a sticky note with animation
    startStickyNoteDrag(stickyNote, mouseX, mouseY, e) {
        this.isDraggingStickyNote = true;
        this.draggedStickyNote = stickyNote;
        this.stickyNoteDragStartX = mouseX;
        this.stickyNoteDragStartY = mouseY;

        // Store initial offset from mouse to sticky note corner
        this.stickyNoteOffsetX = mouseX - stickyNote.x;
        this.stickyNoteOffsetY = mouseY - stickyNote.y;

        // Initialize ghost rectangle
        this.stickyNoteGhost = {
            x: stickyNote.x,
            y: stickyNote.y,
            width: 120,
            height: 80,
            visible: true
        };

        // Add small scale animation when starting drag
        const detailId = stickyNote.detail_id;
        this.animatingNotes.set(detailId, {
            startTime: Date.now(),
            type: 'dragStart',
            duration: 200 // 200ms animation
        });

        console.log('🔄 Starting sticky note drag with ghost rectangle:', stickyNote.detail_id);
        this.render(); // Re-render to show dragging state
    }

    // Handle sticky note dragging with direct mouse following
    handleStickyNoteDrag(e) {
        if (!this.isDraggingStickyNote || !this.draggedStickyNote) return;

        const rect = this.canvas.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;

        // Calculate ghost rectangle position (follows mouse exactly)
        const ghostX = mouseX - this.stickyNoteOffsetX;
        const ghostY = mouseY - this.stickyNoteOffsetY;

        // Update ghost rectangle for pixelgenau mouse following
        this.stickyNoteGhost = {
            x: ghostX,
            y: ghostY,
            width: 120, // Same as sticky note width
            height: 80, // Same as sticky note height
            visible: true
        };

        // Update sticky note position (can be slightly delayed for performance)
        this.draggedStickyNote.x = ghostX;
        this.draggedStickyNote.y = ghostY;

        // Force immediate render for live dragging - bypass normal scheduling
        this.renderImmediate();
    }

    // Immediate render without throttling for live drag feedback
    renderImmediate() {
        this.lastRenderTime = 0; // Reset render throttling
        this.render();
    }

    // End sticky note drag and save position
    async endStickyNoteDrag() {
        if (!this.isDraggingStickyNote || !this.draggedStickyNote) return;

        const stickyNote = this.draggedStickyNote;
        const detail = stickyNote.detail;

        // Calculate new dx/dy relative to bar end
        const newDx = stickyNote.x - stickyNote.barEndX;
        const newDy = -(stickyNote.y - stickyNote.barCenterY); // Fix: negate dy for database

        console.log('💾 Saving sticky note position:', {
            detail_id: detail.detail_id || detail.id,
            oldDx: detail.dx,
            oldDy: detail.dy,
            newDx: newDx,
            newDy: newDy
        });

        try {
            // Save to database
            const response = await fetch('http://192.168.15.14:8080/wci/zp/updateRoomDetailAttributes.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    detail_id: detail.detail_id || detail.id,
                    updates: {
                        dx: Math.round(newDx),
                        dy: Math.round(newDy)
                    }
                })
            });

            if (response.ok) {
                // Update local data
                detail.dx = newDx;
                detail.dy = newDy;
                console.log('✅ Sticky note position saved successfully');
            } else {
                console.error('❌ Failed to save sticky note position:', response.statusText);
            }
        } catch (error) {
            console.error('❌ Error saving sticky note position:', error);
        }

        // Clean up drag state
        this.isDraggingStickyNote = false;
        this.draggedStickyNote = null;
        this.stickyNoteDragStartX = 0;
        this.stickyNoteDragStartY = 0;
        this.stickyNoteOffsetX = 0;
        this.stickyNoteOffsetY = 0;

        // Hide ghost rectangle
        this.stickyNoteGhost = null;

        // Add completion animation
        const detailId = detail.detail_id || detail.id;
        this.animatingNotes.set(detailId, {
            startTime: Date.now(),
            type: 'dragEnd',
            duration: 150 // Quick snap-back animation
        });

        // Final render
        this.render();
    }
}

// Export
window.TimelineUnifiedRenderer = TimelineUnifiedRenderer;
// Cache Buster Sat Sep 20 07:45:50 PM CEST 2025
