<?php
// Korrekter Pfad zur config.php - ein Verzeichnis höher
require_once dirname(__DIR__) . '/config.php';

// MySQLi-Verbindung erstellen (wie im Rest der Anwendung)
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_errno) {
    die('Datenbankverbindung fehlgeschlagen: ' . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

$currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $currentDate)) {
    $currentDate = date('Y-m-d');
}

$roomSql = "SELECT id, caption, kapazitaet, sort, visible, px, py, col
            FROM zp_zimmer
            WHERE visible = 1 OR LOWER(TRIM(caption)) = 'ablage'
            ORDER BY sort ASC, caption ASC";
$roomResult = $mysqli->query($roomSql);
if (!$roomResult) {
    die('Zimmerabfrage fehlgeschlagen: ' . $mysqli->error);
}

$normalZimmer = [];
$ablageZimmer = [];

$gridBounds = [
    'minX' => PHP_INT_MAX,
    'maxX' => PHP_INT_MIN,
    'minY' => PHP_INT_MAX,
    'maxY' => PHP_INT_MIN,
];

while ($row = $roomResult->fetch_assoc()) {
    $room = [
        'id' => (int)$row['id'],
        'caption' => $row['caption'],
        'kapazitaet' => isset($row['kapazitaet']) ? (int)$row['kapazitaet'] : 0,
        'sort' => isset($row['sort']) ? (int)$row['sort'] : 0,
        'visible' => isset($row['visible']) ? (int)$row['visible'] : 0,
        'px' => isset($row['px']) ? (int)$row['px'] : 0,
        'py' => isset($row['py']) ? (int)$row['py'] : 0,
        'col' => $row['col'],
    ];

    $isAblage = mb_strtolower(trim((string)$row['caption'])) === 'ablage';

    if ($isAblage) {
        $ablageZimmer[] = $room;
        continue;
    }

    if ($room['visible']) {
        $normalZimmer[] = $room;

        $gridBounds['minX'] = min($gridBounds['minX'], $room['px']);
        $gridBounds['maxX'] = max($gridBounds['maxX'], $room['px']);
        $gridBounds['minY'] = min($gridBounds['minY'], $room['py']);
        $gridBounds['maxY'] = max($gridBounds['maxY'], $room['py']);
    }
}

$gridInfo = [
    'minX' => 0,
    'maxX' => 0,
    'minY' => 0,
    'maxY' => 0,
    'cols' => 1,
    'rows' => 1,
];

if (!empty($normalZimmer)) {
    $gridInfo['minX'] = $gridBounds['minX'] === PHP_INT_MAX ? 0 : $gridBounds['minX'];
    $gridInfo['maxX'] = $gridBounds['maxX'] === PHP_INT_MIN ? $gridInfo['minX'] : $gridBounds['maxX'];
    $gridInfo['minY'] = $gridBounds['minY'] === PHP_INT_MAX ? 0 : $gridBounds['minY'];
    $gridInfo['maxY'] = $gridBounds['maxY'] === PHP_INT_MIN ? $gridInfo['minY'] : $gridBounds['maxY'];
    $gridInfo['cols'] = max(1, $gridInfo['maxX'] - $gridInfo['minX'] + 1);
    $gridInfo['rows'] = max(1, $gridInfo['maxY'] - $gridInfo['minY'] + 1);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zimmerplan Tagesansicht</title>
    <style>
        *, *::before, *::after {
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f1f5f9;
            color: #1f2937;
            -webkit-font-smoothing: antialiased;
            user-select: none;
            -webkit-user-select: none;
        }

        .main-content {
            display: flex;
            gap: 24px;
            min-height: 100vh;
            padding: 24px;
        }

        .sidebar {
            width: 300px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .sidebar-section {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        .sidebar-section + .sidebar-section {
            margin-top: 8px;
        }

        .sidebar-section h3 {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 12px;
        }

        .date-controls {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .date-input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 12px;
            width: 100%;
            background: #ffffff;
            color: inherit;
        }

        .zimmerplan-container {
            flex: 1;
            position: relative;
            overflow: auto;
            background: #f9fafb;
            touch-action: pan-x pan-y;
            -ms-touch-action: pan-x pan-y;
            scrollbar-gutter: stable both-edges;
        }

        .zimmerplan-container.scroll-enabled {
            overflow: auto;
        }

        .zimmerplan-container.scroll-enabled::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }

        .zimmerplan-container.scroll-enabled::-webkit-scrollbar-thumb {
            background: rgba(100, 116, 139, 0.45);
            border-radius: 6px;
        }

        .zimmerplan-container.scroll-enabled::-webkit-scrollbar-track {
            background: rgba(203, 213, 225, 0.35);
        }

        input, textarea, select, button {
            user-select: text;
            -webkit-user-select: text;
        }

        .zimmerplan {
            position: relative;
            width: 100%;
            height: 100%;
            margin: 0;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .zimmer, .ablage-zimmer, .zimmer-content, .reservation-item {
            -webkit-touch-callout: none;
            -webkit-user-drag: none;
        }

        .zimmer {
            position: absolute;
            background: #ffffff;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            padding: 0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            cursor: pointer;
            transition: all 0.2s ease;
            overflow: hidden;
            display: flex;
            flex-direction: row;
        }

        .zimmer:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transform: scale(1.1);
            z-index: 100;
        }

        .zimmer:hover .reservation-item {
            font-size: 12px;
            padding: 4px 6px;
            white-space: normal;
            height: auto;
            min-height: 20px;
        }

        .zimmer-sidebar {
            width: 80px;
            background: rgba(0, 0, 0, 0.05);
            border-right: 1px solid #e5e7eb;
            padding: 8px 6px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            flex-shrink: 0;
        }

        .zimmer,
        .ablage-zimmer,
        .zimmer-content,
        .reservation-item {
            -webkit-touch-callout: none;
        }

        .zimmer-header {
            font-weight: 600;
            font-size: 10px;
            color: #374151;
            margin-bottom: 6px;
            line-height: 1.2;
            word-wrap: break-word;
        }

        .zimmer-info {
            font-size: 9px;
            color: #6b7280;
            line-height: 1.3;
        }

        .zimmer-content {
            flex: 1;
            padding: 4px;
            position: relative;
            overflow-y: auto;
            display: flex;
            flex-wrap: wrap;
            gap: 0;
            align-content: flex-start;
        }

        /* Standard Reservierung - wird dynamisch überschrieben */
        .reservation-item {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            border-radius: 4px;
            padding: 2px 4px;
            font-size: 8px;
            cursor: grab;
            transition: all 0.2s ease;
            box-sizing: border-box;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            touch-action: manipulation;
            user-select: none;
            -webkit-user-select: none;
        }

        .reservation-item.drag-hidden {
            opacity: 0;
        }

        .drag-ghost {
            position: fixed;
            pointer-events: none;
            opacity: 0.9;
            transform: scale(1.05);
            box-shadow: 0 12px 25px rgba(59, 130, 246, 0.35);
            border-radius: 8px;
            z-index: 100000;
            transition: none !important;
        }

        .drag-placeholder {
            border: 2px dashed #60a5fa;
            border-radius: 6px;
            background: rgba(191, 219, 254, 0.35);
            box-sizing: border-box;
        }

        body.dragging-active {
            user-select: none !important;
            -webkit-user-select: none !important;
            cursor: grabbing;
        }

        body.dragging-active .reservation-item,
        body.dragging-active .zimmer,
        body.dragging-active .ablage-zimmer {
            cursor: grabbing !important;
        }

        /* 1 Reservierung - maximale Größe */
        .zimmer-content.count-1 .reservation-item {
            width: 100%;
            height: calc(100% - 4px);
            max-height: calc(100vh / 16);
            font-size: 11px;
            white-space: normal;
            word-wrap: break-word;
        }

        /* 2 Reservierungen - halbe Breite, volle Höhe */
        .zimmer-content.count-2 .reservation-item {
            width: 50%;
            height: calc(100% - 4px);
            max-height: calc(100vh / 16);
            font-size: 10px;
        }

        /* 3-4 Reservierungen - halbe Breite, halbe Höhe */
        .zimmer-content.count-3-plus .reservation-item {
            width: 50%;
            height: calc(50% - 2px);
            max-height: calc(100vh / 32);
            font-size: 9px;
        }

        .reservation-item:hover {
            background: #bfdbfe;
            border-color: #60a5fa;
        }

        .reservation-item.dragging {
            cursor: grabbing;
            opacity: 0.7;
            transform: rotate(2deg);
            box-shadow: 0 8px 25px -5px rgba(0, 0, 0, 0.3);
        }

        /* 📱 Mobile Touch Improvements */
        @media (hover: none) and (pointer: coarse) {
            .reservation-item {
                min-height: 55px; /* Größere Touch-Targets */
                font-size: 14px;
                padding: 10px 12px;
                touch-action: manipulation; /* Erlaubt Scrollen, blockiert Doppeltap-Zoom */
                user-select: none;
            }
            
            .reservation-item.dragging {
                opacity: 0.9;
                transform: scale(1.1) rotate(3deg);
                box-shadow: 0 12px 35px -5px rgba(0, 0, 0, 0.4);
                border: 2px solid #3b82f6;
                background: #ffffff !important;
            }
            
            .zimmer, .ablage-zimmer {
                min-height: 85px; /* Größere Drop-Zonen */
                touch-action: manipulation;
            }
            
            .drag-hover {
                background: #dbeafe !important;
                border-color: #3b82f6 !important;
                transform: scale(1.02);
                transition: all 0.2s ease;
                box-shadow: 0 6px 20px -3px rgba(59, 130, 246, 0.3);
            }

            /* Verbesserte Drop-Target Markierungen für Mobile */
            .drop-target {
                animation: mobilePulseGreen 1.5s infinite ease-in-out;
            }
            
            .drop-target-warning {
                animation: mobilePulseYellow 1.5s infinite ease-in-out;
            }
            
            .drop-target-forbidden {
                animation: mobilePulseRed 1.5s infinite ease-in-out;
            }

            /* Haptic-style Animationen für Mobile */
            @keyframes mobilePulseGreen {
                0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
                50% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0.1); }
            }
            
            @keyframes mobilePulseYellow {
                0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
                50% { box-shadow: 0 0 0 8px rgba(245, 158, 11, 0.1); }
            }
            
            @keyframes mobilePulseRed {
                0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
                50% { box-shadow: 0 0 0 8px rgba(239, 68, 68, 0.1); }
            }
        }

        /* Touch-specific drag states */
        .reservation-item[style*="position: fixed"] {
            border-radius: 8px;
            box-shadow: 0 15px 40px -10px rgba(0, 0, 0, 0.5) !important;
            background: #ffffff !important;
            border: 2px solid #3b82f6 !important;
            transform: scale(1.1) rotate(3deg) !important;
        }

        .ablage-zimmer {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 6px;
            padding: 0;
            margin-bottom: 8px;
            display: flex;
            flex-direction: row;
            min-height: 60px;
        }

        .ablage-zimmer .zimmer-sidebar {
            width: 70px;
            background: rgba(245, 158, 11, 0.1);
            border-right: 1px solid #f59e0b;
        }

        .ablage-zimmer .zimmer-header {
            color: #92400e;
        }

        .drop-target {
            background: #ecfdf5 !important;
            border-color: #10b981 !important;
        }

        .drop-target-warning {
            background: #fef3c7 !important;
            border-color: #f59e0b !important;
            animation: warningPulse 1s infinite;
        }

        .drop-target-forbidden {
            background: #fef2f2 !important;
            border-color: #ef4444 !important;
            cursor: not-allowed !important;
            opacity: 0.2 !important;
        }

        /* Hover-Effekt während des Draggings */
        .drag-hover {
            transform: scale(1.02) !important;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15) !important;
            z-index: 10 !important;
            transition: all 0.1s ease-out !important;
        }

        @keyframes warningPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(245, 158, 11, 0); }
        }

        @keyframes forbiddenPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            50% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
        }

        .controls {
            padding: 16px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
        }

        .btn {
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #ffffff;
            color: #374151;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
            margin-bottom: 4px;
        }

        .btn:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }

        .btn.primary {
            background: #3b82f6;
            border-color: #2563eb;
            color: #ffffff;
        }

        .btn.primary:hover {
            background: #2563eb;
        }

        @media (max-width: 1024px) {
            .sidebar {
                width: 240px;
            }
            
            .zimmerplan {
                min-width: 800px;
                min-height: 600px;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="sidebar">
            <div class="sidebar-section">
                <h3>Datum</h3>
                <div class="date-controls">
                    <input type="date" id="view-date" class="date-input" value="<?= $currentDate ?>" onchange="loadRoomData()">
                    <button class="btn" onclick="loadToday()">Heute</button>
                    <button class="btn primary" onclick="loadRoomData()">Aktualisieren</button>
                </div>
                
                <!-- Cache-Status anzeigen -->
                <div id="cache-status" style="margin-top: 10px; padding: 8px; background: #f3f4f6; border-radius: 4px; font-size: 11px; color: #6b7280;">
                    <div id="cache-info">Cache: nicht geladen</div>
                </div>
            </div>
            
            <div class="sidebar-section">
                <h3>Menü</h3>
                <button class="btn" onclick="showReservations()">Reservierungen</button>
                <button class="btn" onclick="showSettings()">Einstellungen</button>
                <button class="btn" onclick="showReports()">Berichte</button>
            </div>

            <?php if (!empty($ablageZimmer)): ?>
            <div class="sidebar-section">
                <h3>Ablage</h3>
                <?php foreach ($ablageZimmer as $zimmer): ?>
                <div class="ablage-zimmer" data-zimmer-id="<?= $zimmer['id'] ?>">
                    <div class="zimmer-sidebar">
                        <div class="zimmer-header"><?= htmlspecialchars($zimmer['caption']) ?></div>
                        <div class="zimmer-info">
                            <?= $zimmer['kapazitaet'] ?><br>
                            <span id="occupancy-<?= $zimmer['id'] ?>">0/<?= $zimmer['kapazitaet'] ?></span>
                        </div>
                    </div>
                    <div class="zimmer-content" id="zimmer-content-<?= $zimmer['id'] ?>">
                        <!-- Reservierungen werden hier eingefügt -->
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="zimmerplan-container">
            <div class="zimmerplan" id="zimmerplan">
                <?php foreach ($normalZimmer as $zimmer): ?>
                <div class="zimmer" 
                     data-zimmer-id="<?= $zimmer['id'] ?>"
                     data-grid-x="<?= $zimmer['px'] ?>"
                     data-grid-y="<?= $zimmer['py'] ?>">
                    <div class="zimmer-sidebar">
                        <div class="zimmer-header"><?= htmlspecialchars($zimmer['caption']) ?></div>
                        <div class="zimmer-info">
                            <?= $zimmer['kapazitaet'] ?><br>
                            <span id="occupancy-<?= $zimmer['id'] ?>">0/<?= $zimmer['kapazitaet'] ?></span>
                        </div>
                    </div>
                    <div class="zimmer-content" id="zimmer-content-<?= $zimmer['id'] ?>">
                        <!-- Reservierungen werden hier eingefügt -->
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    // Globale Variablen
    let currentViewDate = '<?= $currentDate ?>';
    let draggedElement = null;
    let draggedReservation = null;
    let rooms = <?= json_encode($normalZimmer) ?>;
    let ablageRooms = <?= json_encode($ablageZimmer) ?>;
    let gridInfo = <?= json_encode($gridInfo) ?>;
    let dragGhost = null;
    let dragPlaceholder = null;
    let lastTouchPoint = null;
    let dragSourceContainer = null;
    let dragGhostOffset = { x: 0, y: 0 };
    let activePointerId = null;
    let ghostAnimationFrame = null;
    let ghostPendingPosition = null;

    const GRID_PADDING = 20;
    const MIN_CELL_WIDTH = 240;
    const MIN_CELL_HEIGHT = 70;
    const SCROLL_EDGE_THRESHOLD = 80;
    const SCROLL_EDGE_SPEED = 18;
        
        // Reservation lookup und Cache für optimierte Kapazitätsprüfung
        let currentReservations = [];
        let reservationLookupCache = new Map(); // Cache für erweiterte Zeitraum-Abfragen
        let cacheValidUntil = null; // Zeitstempel bis wann der Cache gültig ist
        let cacheMinDate = null; // Minimales Datum im Cache
        let cacheMaxDate = null; // Maximales Datum im Cache
        
        function autoScrollDuringDrag(clientX, clientY) {
            const wrapper = document.querySelector('.zimmerplan-container');
            if (!wrapper) return;

            const rect = wrapper.getBoundingClientRect();
            const withinHorizontalBand = clientX >= rect.left - SCROLL_EDGE_THRESHOLD && clientX <= rect.right + SCROLL_EDGE_THRESHOLD;
            const withinVerticalBand = clientY >= rect.top - SCROLL_EDGE_THRESHOLD && clientY <= rect.bottom + SCROLL_EDGE_THRESHOLD;

            if (withinHorizontalBand) {
                if (clientX - rect.left < SCROLL_EDGE_THRESHOLD) {
                    wrapper.scrollLeft -= SCROLL_EDGE_SPEED;
                } else if (rect.right - clientX < SCROLL_EDGE_THRESHOLD) {
                    wrapper.scrollLeft += SCROLL_EDGE_SPEED;
                }
            }

            if (withinVerticalBand) {
                if (clientY - rect.top < SCROLL_EDGE_THRESHOLD) {
                    wrapper.scrollTop -= SCROLL_EDGE_SPEED;
                } else if (rect.bottom - clientY < SCROLL_EDGE_THRESHOLD) {
                    wrapper.scrollTop += SCROLL_EDGE_SPEED;
                }
            }

            const element = document.elementFromPoint(clientX, clientY);
            if (element) {
                const scrollContainer = element.closest('.zimmer-content');
                if (scrollContainer) {
                    const scrollRect = scrollContainer.getBoundingClientRect();
                    if (clientY - scrollRect.top < SCROLL_EDGE_THRESHOLD) {
                        scrollContainer.scrollTop -= SCROLL_EDGE_SPEED;
                    } else if (scrollRect.bottom - clientY < SCROLL_EDGE_THRESHOLD) {
                        scrollContainer.scrollTop += SCROLL_EDGE_SPEED;
                    }
                }
            }
        }

        function isTabletViewport() {
            const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
            const minSide = Math.min(window.innerWidth, window.innerHeight);
            const maxSide = Math.max(window.innerWidth, window.innerHeight);
            return coarsePointer && minSide >= 600 && maxSide <= 1600;
        }

        function removeDragGhost() {
            if (dragGhost && dragGhost.parentNode) {
                dragGhost.parentNode.removeChild(dragGhost);
            }
            dragGhost = null;
            ghostPendingPosition = null;
            if (ghostAnimationFrame !== null) {
                cancelAnimationFrame(ghostAnimationFrame);
                ghostAnimationFrame = null;
            }
        }

        function removeDragPlaceholder() {
            if (dragPlaceholder && dragPlaceholder.parentNode) {
                dragPlaceholder.parentNode.removeChild(dragPlaceholder);
            }
            dragPlaceholder = null;
        }

        function updateGhostPosition(point, offsetX, offsetY) {
            if (!dragGhost || !point) return;
            if (typeof offsetX === 'number' && typeof offsetY === 'number') {
                dragGhostOffset = { x: offsetX, y: offsetY };
            }
            ghostPendingPosition = {
                x: point.x - dragGhostOffset.x,
                y: point.y - dragGhostOffset.y
            };

            if (ghostAnimationFrame !== null) {
                return;
            }

            ghostAnimationFrame = requestAnimationFrame(() => {
                ghostAnimationFrame = null;
                if (!dragGhost || !ghostPendingPosition) {
                    return;
                }
                dragGhost.style.transform = `translate3d(${ghostPendingPosition.x}px, ${ghostPendingPosition.y}px, 0) scale(1.05)`;
            });
        }

        function updateDeviceMode() {
            const body = document.body;
            if (!body) return;
            const tablet = isTabletViewport();
            body.classList.toggle('tablet-mode', tablet);

            if (document.readyState === 'interactive' || document.readyState === 'complete') {
                requestAnimationFrame(() => calculateRoomPositions());
            }
        }

        function shouldSuppressContextMenu(event) {
            if (!event) return false;
            const target = event.target ? event.target.closest('.reservation-item, .zimmer, .ablage-zimmer') : null;
            if (!target) return false;

            if (document.body && document.body.classList.contains('tablet-mode')) {
                return true;
            }

            if ('ontouchstart' in window || navigator.maxTouchPoints > 1) {
                const coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
                if (coarse) {
                    return true;
                }
            }

            return false;
        }

        const coarsePointerMedia = window.matchMedia('(pointer: coarse)');
        if (coarsePointerMedia && typeof coarsePointerMedia.addEventListener === 'function') {
            coarsePointerMedia.addEventListener('change', updateDeviceMode);
        } else if (coarsePointerMedia && typeof coarsePointerMedia.addListener === 'function') {
            // Safari iOS
            coarsePointerMedia.addListener(updateDeviceMode);
        }

        document.addEventListener('contextmenu', function(event) {
            if (shouldSuppressContextMenu(event)) {
                event.preventDefault();
            }
        }, { passive: false, capture: true });

        document.addEventListener('selectstart', function(event) {
            if (shouldSuppressContextMenu(event)) {
                event.preventDefault();
            }
        }, { passive: false, capture: true });

        // Initialisierung
        document.addEventListener('DOMContentLoaded', function() {
            updateDeviceMode();
            calculateRoomPositions();
            initializeDragAndDrop();
            loadRoomData();
            
            // Neuberechnung bei Fenstergröße-Änderung
            window.addEventListener('resize', function() {
                updateDeviceMode();
                calculateRoomPositions();
                // Alle Reservierungshöhen neu berechnen
                document.querySelectorAll('.zimmer-content').forEach(container => {
                    setTimeout(() => adjustReservationHeights(container), 10);
                });
            });
        });

        function calculateRoomPositions() {
            const container = document.querySelector('.zimmerplan');
            const wrapper = document.querySelector('.zimmerplan-container');
            if (!container || !wrapper || !gridInfo) {
                return;
            }

            const cols = Number(gridInfo.cols || 0);
            const rows = Number(gridInfo.rows || 0);
            const minGridX = Number(gridInfo.minX || 0);
            const minGridY = Number(gridInfo.minY || 0);

            if (!cols || !rows) {
                return;
            }

            const wrapperWidth = wrapper.clientWidth || wrapper.offsetWidth || container.offsetWidth;
            const wrapperHeight = wrapper.clientHeight || wrapper.offsetHeight || container.offsetHeight;
            const padding = GRID_PADDING;

            let cellWidth = Math.floor((wrapperWidth - padding * 2) / cols);
            let cellHeight = Math.floor((wrapperHeight - padding * 2) / rows);

            cellWidth = Math.max(cellWidth, MIN_CELL_WIDTH);
            cellHeight = Math.max(cellHeight, MIN_CELL_HEIGHT);

            const layoutWidth = (cellWidth * cols) + padding * 2;
            const layoutHeight = (cellHeight * rows) + padding * 2;

            if (layoutWidth > wrapperWidth || layoutHeight > wrapperHeight) {
                container.style.width = layoutWidth + 'px';
                container.style.height = layoutHeight + 'px';
                container.style.minWidth = layoutWidth + 'px';
                container.style.minHeight = layoutHeight + 'px';
                wrapper.classList.add('scroll-enabled');
            } else {
                container.style.width = '100%';
                container.style.height = '100%';
                container.style.minWidth = 'auto';
                container.style.minHeight = 'auto';
                wrapper.classList.remove('scroll-enabled');
            }

            console.log('Grid Info:', gridInfo);
            console.log('Wrapper Size:', wrapperWidth, 'x', wrapperHeight);
            console.log('Cell Size:', cellWidth, 'x', cellHeight);

            // Positioniere alle Zimmer
            document.querySelectorAll('.zimmer').forEach(zimmer => {
                const gridX = parseInt(zimmer.dataset.gridX);
                const gridY = parseInt(zimmer.dataset.gridY);
                
                // Berechne Position relativ zum minimalen Grid
                const relativeX = gridX - minGridX;
                const relativeY = gridY - minGridY;

                const left = padding + (relativeX * cellWidth);
                const top = padding + (relativeY * cellHeight);
                
                zimmer.style.position = 'absolute';
                zimmer.style.left = left + 'px';
                zimmer.style.top = top + 'px';
                zimmer.style.width = (cellWidth - 2) + 'px'; // 2px Abstand zwischen Zellen
                zimmer.style.height = (cellHeight - 2) + 'px';
                
                // Setze Hintergrundfarbe aus Zimmerdefinition
                applyRoomColor(zimmer);
                
                // Höhen der Reservierungen anpassen
                const container = zimmer.querySelector('.zimmer-content');
                if (container) {
                    setTimeout(() => adjustReservationHeights(container), 10);
                }
            });
        }

        function applyRoomColor(zimmerElement) {
            const zimmerId = zimmerElement.dataset.zimmerId;
            const room = rooms.find(r => r.id == zimmerId);
            
            if (room && room.col) {
                let color = room.col.trim();
                
                // Konvertiere #AARRGGBB zu #RRGGBB falls nötig
                if (color.startsWith('#') && color.length === 9) {
                    color = '#' + color.slice(3);
                }
                
                // Prüfe ob gültige Hex-Farbe
                if (/^#[0-9a-fA-F]{6}$/.test(color)) {
                    // Farbe ausbleichen durch Mischung mit Weiß (30% der ursprünglichen Intensität)
                    const r = parseInt(color.slice(1, 3), 16);
                    const g = parseInt(color.slice(3, 5), 16);
                    const b = parseInt(color.slice(5, 7), 16);
                    
                    const fadedR = Math.round(r + (255 - r) * 0.7);
                    const fadedG = Math.round(g + (255 - g) * 0.7);
                    const fadedB = Math.round(b + (255 - b) * 0.7);
                    
                    zimmerElement.style.backgroundColor = `rgb(${fadedR}, ${fadedG}, ${fadedB})`;
                    
                    // Text immer dunkel, da Hintergrund jetzt heller ist
                    zimmerElement.style.color = '#1f2937';
                }
            }
        }

        function loadToday() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('view-date').value = today;
            currentViewDate = today;
            loadRoomData();
        }

        function loadRoomData() {
            currentViewDate = document.getElementById('view-date').value;
            console.log('Lade Daten für Datum:', currentViewDate);
            
            // Loading-Indikator anzeigen
            showLoadingIndicator();
            
            // Schritt 1: Lade erst die Daten für den aktuellen Tag
            fetch(`api_room_data.php?date=${currentViewDate}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('✅ Tag-Daten geladen:', {
                            reservations: data.reservations.length,
                            date: currentViewDate
                        });
                        
                        // Schritt 2: Ermittle Min/Max-Datum aller sichtbaren Reservierungen
                        let minDate = currentViewDate;
                        let maxDate = currentViewDate;
                        
                        data.reservations.forEach(res => {
                            if (res.von < minDate) minDate = res.von;
                            if (res.bis > maxDate) maxDate = res.bis;
                        });
                        
                        console.log('📅 Relevanter Zeitraum ermittelt:', {
                            minDate: minDate,
                            maxDate: maxDate,
                            spanDays: Math.ceil((new Date(maxDate) - new Date(minDate)) / (1000*60*60*24))
                        });
                        
                        // Schritt 3: Nur wenn der Zeitraum größer als 1 Tag ist, lade erweiterten Cache
                        if (minDate !== maxDate) {
                            // Erweiterte Cache-Anfrage für den relevanten Zeitraum
                            cacheMinDate = minDate;
                            cacheMaxDate = maxDate;
                            
                            console.log('🗂️ Lade intelligenten Cache:', `${cacheMinDate} bis ${cacheMaxDate}`);
                            
                            fetch(`api_room_data.php?von=${cacheMinDate}&bis=${cacheMaxDate}`)
                                .then(response => response.json())
                                .then(cacheData => {
                                    if (cacheData.success) {
                                        console.log('📊 Intelligenter Cache geladen:', {
                                            totalReservations: cacheData.reservations.length,
                                            dateRange: `${cacheMinDate} - ${cacheMaxDate}`,
                                            cacheSize: `${Math.round(JSON.stringify(cacheData.reservations).length / 1024)}KB`
                                        });
                                        
                                        // Cache aufbauen
                                        reservationLookupCache.clear();
                                        cacheData.reservations.forEach(res => {
                                            const key = `${res.zimmer_id}_${res.von}_${res.bis}`;
                                            reservationLookupCache.set(key, res);
                                        });
                                        
                                        // Cache-Gültigkeit setzen (5 Minuten)
                                        cacheValidUntil = Date.now() + (5 * 60 * 1000);
                                        
                                        // Cache-Status in der UI anzeigen
                                        updateCacheStatusDisplay();
                                        
                                        console.log('⚡ Cache aktiv - Drag & Drop optimiert');
                                    }
                                })
                                .catch(error => {
                                    console.warn('Cache-Laden fehlgeschlagen:', error);
                                });
                        } else {
                            console.log('📋 Kein erweiterte Cache nötig - nur Ein-Tages-Reservierungen');
                            // Cache-Status für Ein-Tages-Reservierungen anzeigen
                            cacheMinDate = null;
                            cacheMaxDate = null;
                            updateCacheStatusDisplay();
                        }
                        
                        // Filtere Reservierungen für aktuellen Tag (für Anzeige)
                        currentReservations = data.reservations.filter(res => {
                            return res.von <= currentViewDate && res.bis > currentViewDate;
                        });
                        
                        console.log('Daten für Anzeige gefiltert:', {
                            viewDate: currentViewDate,
                            displayReservations: currentReservations.length
                        });
                        
                        // Alle Zimmer leeren
                        document.querySelectorAll('.zimmer-content').forEach(container => {
                            container.innerHTML = '';
                            container.classList.remove('count-1', 'count-2', 'count-3-plus');
                        });
                        
                        // Reservierungen einfügen (nur für aktuellen Tag)
                        currentReservations.forEach(reservation => {
                            if (reservation.zimmer_id) {
                                addReservationToRoom(reservation.zimmer_id, reservation);
                            }
                        });
                        
                        // Belegungszähler aktualisieren
                        updateOccupancyCounters();
                        
                        // Status in der Konsole ausgeben
                        console.log(`${currentReservations.length} Reservierungen für ${currentViewDate} angezeigt`);
                        
                        hideLoadingIndicator();
                        
                    } else {
                        hideLoadingIndicator();
                        console.error('Fehler beim Laden der Daten:', data.error);
                        alert('Fehler beim Laden der Zimmerdaten: ' + data.error);
                        
                        // Fallback auf Demo-Daten
                        loadPlaceholderData();
                    }
                })
                .catch(error => {
                    hideLoadingIndicator();
                    console.error('Netzwerkfehler:', error);
                    alert('Netzwerkfehler beim Laden der Daten. Verwende Demo-Daten.');
                    
                    // Fallback auf Demo-Daten
                    loadPlaceholderData();
                });
        }

        // Schnelle Cache-Lookup-Funktion für Kapazitätsprüfung
        function checkCachedCapacity(zimmerId, fromDate, toDate) {
            if (!isCacheValid()) {
                console.log('🚫 Cache ungültig oder leer - fallback zu API');
                return null; // Cache ungültig, fallback zu API
            }
            
            console.log(`🔍 Cache-Lookup: Zimmer ${zimmerId}, ${fromDate} - ${toDate}`);
            console.log(`📦 Cache enthält ${reservationLookupCache.size} Reservierungen`);
            
            // KORREKTE HOTEL-LOGIK: Tag-für-Tag Prüfung wie bei API-Fallback
            const [startYear, startMonth, startDay] = fromDate.split('-').map(Number);
            const [endYear, endMonth, endDay] = toDate.split('-').map(Number);
            const reservationStart = new Date(startYear, startMonth - 1, startDay);
            const reservationEnd = new Date(endYear, endMonth - 1, endDay);
            
            let maxDayOccupancy = 0;
            const totalDays = Math.ceil((reservationEnd - reservationStart) / (1000 * 60 * 60 * 24));
            
            console.log(`📊 Prüfe ${totalDays} Tage für Cache-Lookup...`);
            
            // Gehe durch jeden Tag des Aufenthaltszeitraums
            for (let dayOffset = 0; dayOffset < totalDays; dayOffset++) {
                const checkDate = new Date(reservationStart);
                checkDate.setDate(checkDate.getDate() + dayOffset);
                const checkDateString = checkDate.getFullYear() + '-' + 
                    String(checkDate.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(checkDate.getDate()).padStart(2, '0');
                
                let dayOccupancy = 0;
                let dayGuests = [];
                
                // Prüfe alle Reservierungen in diesem Zimmer für diesen spezifischen Tag
                for (let [key, reservation] of reservationLookupCache) {
                    if (reservation.zimmer_id == zimmerId) {
                        // Hotel-Logik: Reservierung belegt von Ankunft bis (aber nicht einschließlich) Abreise
                        if (reservation.von <= checkDateString && reservation.bis > checkDateString) {
                            const guestCount = parseInt(reservation.anz || 1);
                            dayOccupancy += guestCount;
                            dayGuests.push(`${reservation.name}(${guestCount})`);
                        }
                    }
                }
                
                maxDayOccupancy = Math.max(maxDayOccupancy, dayOccupancy);
                
                if (dayGuests.length > 0) {
                    console.log(`  📅 ${checkDateString}: ${dayOccupancy} Gäste - ${dayGuests.join(', ')}`);
                }
            }
            
            console.log(`🎯 Cache-Ergebnis: Max ${maxDayOccupancy} Gäste an einem Tag`);
            return maxDayOccupancy;
        }

        // Cache-Gültigkeit prüfen
        function isCacheValid() {
            return Date.now() < cacheValidUntil && 
                   cacheMinDate && cacheMaxDate &&
                   currentViewDate >= cacheMinDate && 
                   currentViewDate <= cacheMaxDate;
        }

        // Cache-Status in der UI anzeigen
        function updateCacheStatusDisplay() {
            const cacheInfo = document.getElementById('cache-info');
            if (!cacheInfo) return;
            
            if (cacheMinDate && cacheMaxDate) {
                const totalReservations = reservationLookupCache.size;
                const spanDays = Math.ceil((new Date(cacheMaxDate) - new Date(cacheMinDate)) / (1000*60*60*24));
                const cacheSize = Math.round(JSON.stringify([...reservationLookupCache.values()]).length / 1024);
                
                cacheInfo.innerHTML = `
                    <strong>Cache aktiv:</strong><br>
                    📅 ${cacheMinDate} - ${cacheMaxDate}<br>
                    📊 ${totalReservations} Reservierungen (${spanDays} Tage)<br>
                    💾 ${cacheSize}KB Speicher
                `;
                cacheInfo.style.background = '#ecfdf5';
                cacheInfo.style.color = '#065f46';
            } else {
                cacheInfo.innerHTML = 'Cache: nur Ein-Tages-Daten';
                cacheInfo.style.background = '#fef3c7';
                cacheInfo.style.color = '#92400e';
            }
        }

        function showLoadingIndicator() {
            // Einfacher Loading-Indikator
            document.querySelectorAll('.zimmer-content').forEach(container => {
                container.innerHTML = '<div style="text-align: center; color: #6b7280; font-size: 10px; padding: 20px;">Lade...</div>';
            });
        }

        function hideLoadingIndicator() {
            // Loading-Indikator wird durch echte Daten ersetzt
        }

        function loadPlaceholderData() {
            console.log('Lade Fallback-Demo-Daten...');
            
            // Einfache Demo-Reservierungen nur als Fallback
            const sampleReservations = [
                { id: 'demo1', name: 'Demo-Reservierung', zimmer_id: rooms[0]?.id, arr_date: currentViewDate, ankunft: currentViewDate, abreise: currentViewDate }
            ];

            // Alle Zimmer leeren
            document.querySelectorAll('.zimmer-content').forEach(container => {
                container.innerHTML = '';
                container.classList.remove('count-1', 'count-2', 'count-3-plus');
            });

            // Demo-Reservierungen einfügen
            sampleReservations.forEach(res => {
                if (res.zimmer_id) {
                    addReservationToRoom(res.zimmer_id, res);
                }
            });

            // Belegungszähler aktualisieren
            updateOccupancyCounters();
        }

        function addReservationToRoom(zimmerId, reservation) {
            const container = document.getElementById(`zimmer-content-${zimmerId}`);
            if (!container) return;

            const item = document.createElement('div');
            item.className = 'reservation-item';
            item.dataset.reservationId = reservation.id;
            item.dataset.resid = reservation.resid;
            
            // Vollständige Reservierungsinfo als Tooltip basierend auf AV_ResDet Struktur
            let tooltip = `${reservation.name}`;
            if (reservation.von) tooltip += `\nVon: ${reservation.von}`;
            if (reservation.bis) tooltip += `\nBis: ${reservation.bis}`;
            if (reservation.anz) tooltip += `\nAnzahl: ${reservation.anz}`;
            if (reservation.arrangement) tooltip += `\nArrangement: ${reservation.arrangement}`;
            if (reservation.av_id) tooltip += `\nAV-ID: ${reservation.av_id}`;
            if (reservation.note) tooltip += `\nNotiz: ${reservation.note}`;
            
            item.title = tooltip;
            
            // Anzeigename (gekürzt wenn nötig)
            item.textContent = reservation.name;
            
            // Status-basierte Farbgebung
            const statusColor = getReservationStatusColor(reservation);
            item.style.backgroundColor = statusColor.bg;
            item.style.color = statusColor.text;
            item.style.borderColor = statusColor.border;
            
            container.appendChild(item);
            
            // Layout-Klasse basierend auf Anzahl Reservierungen setzen
            updateRoomLayout(container);
        }

        function getReservationStatusColor(reservation) {
            const today = new Date(currentViewDate);
            const vonDate = new Date(reservation.von);
            const bisDate = new Date(reservation.bis);
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            
            // Berechne Nächte zwischen von und bis
            const nights = Math.ceil((bisDate - vonDate) / (1000 * 60 * 60 * 24));
            
            // Gelb: Reist morgen ab UND war länger als eine Nacht da (OVERRIDE für alle anderen)
            if (bisDate.toDateString() === tomorrow.toDateString() && nights > 1) {
                return {
                    bg: '#f59e0b',
                    text: '#000000',
                    border: '#d97706'
                };
            }
            
            // Orange: Kommt heute, reist morgen ab (1 Nacht)
            if (vonDate.toDateString() === today.toDateString() && 
                bisDate.toDateString() === tomorrow.toDateString()) {
                return {
                    bg: '#f97316',
                    text: '#ffffff',
                    border: '#ea580c'
                };
            }
            
            // Blau: Anreise heute und bleibt mindestens 2 Nächte
            if (vonDate.toDateString() === today.toDateString() && nights >= 2) {
                return {
                    bg: '#3b82f6',
                    text: '#ffffff',
                    border: '#2563eb'
                };
            }
            
            // Grün: Bereits angereist und bleibt noch
            if (vonDate < today && bisDate > tomorrow) {
                return {
                    bg: '#10b981',
                    text: '#ffffff',
                    border: '#059669'
                };
            }
            
            // Standard: Hellblau für andere Fälle
            return {
                bg: '#dbeafe',
                text: '#1e40af',
                border: '#93c5fd'
            };
        }

        function updateRoomLayout(container) {
            const count = container.querySelectorAll('.reservation-item').length;
            
            // Alle Layout-Klassen entfernen
            container.classList.remove('count-1', 'count-2', 'count-3-plus');
            
            // Entsprechende Klasse setzen
            if (count === 1) {
                container.classList.add('count-1');
            } else if (count === 2) {
                container.classList.add('count-2');
            } else if (count >= 3) {
                container.classList.add('count-3-plus');
            }
            
            // Dynamische Höhenanpassung basierend auf Zimmergröße
            adjustReservationHeights(container);
        }

        function adjustReservationHeights(container) {
            const zimmer = container.closest('.zimmer');
            if (!zimmer) return;
            
            const zimmerRect = zimmer.getBoundingClientRect();
            const sidebarWidth = 80; // Breite der zimmer-sidebar
            const contentHeight = zimmerRect.height - 16; // Abzug für Padding
            const count = container.querySelectorAll('.reservation-item').length;
            
            let itemHeight;
            if (count === 1) {
                itemHeight = contentHeight - 4;
            } else if (count === 2) {
                itemHeight = contentHeight - 4;
            } else if (count >= 3) {
                itemHeight = (contentHeight / 2) - 2;
            }
            
            // Maximale Höhe basierend auf Bildschirmhöhe begrenzen
            const maxHeight = Math.min(itemHeight, window.innerHeight / 12);
            
            container.querySelectorAll('.reservation-item').forEach(item => {
                item.style.maxHeight = maxHeight + 'px';
                item.style.overflow = 'hidden';
            });
        }

        function initializeDragAndDrop() {
            let draggedReservation = null;
            let isDragging = false;
            let touchStartPos = null;
            let touchCurrentElement = null;
            let touchHoldTimer = null;
            let touchStartTime = 0;
            let touchMoved = false;
            const TOUCH_HOLD_DURATION = 300;
            const TOUCH_MOVE_THRESHOLD = 12;
            const MOUSE_MOVE_THRESHOLD = 4;
            let currentPointerType = null;
            let autoScrollLoopId = null;

            function isPointerDragCandidate(event) {
                const type = event.pointerType || '';
                return type === 'touch' || type === 'pen' || type === 'mouse' || type === '';
            }

            function startAutoScrollLoop() {
                if (autoScrollLoopId !== null) {
                    return;
                }
                autoScrollLoopId = requestAnimationFrame(runAutoScrollStep);
            }

            function runAutoScrollStep() {
                autoScrollLoopId = null;
                if (!isDragging || !lastTouchPoint) {
                    return;
                }
                autoScrollDuringDrag(lastTouchPoint.x, lastTouchPoint.y);
                startAutoScrollLoop();
            }

            function stopAutoScrollLoop() {
                if (autoScrollLoopId !== null) {
                    cancelAnimationFrame(autoScrollLoopId);
                    autoScrollLoopId = null;
                }
            }

            function clearPendingTouchHold() {
                if (touchHoldTimer) {
                    clearTimeout(touchHoldTimer);
                    touchHoldTimer = null;
                }
            }

            function resetTouchPreview() {
                if (touchCurrentElement) {
                    touchCurrentElement.style.transform = '';
                    touchCurrentElement.style.transition = '';
                }
            }

            function abortTouchPreparation() {
                const element = touchCurrentElement;
                clearPendingTouchHold();
                if (element) {
                    element.style.transform = '';
                    element.style.transition = '';
                }
                touchCurrentElement = null;
                touchStartPos = null;
                touchStartTime = 0;
                touchMoved = false;
                lastTouchPoint = null;
                dragSourceContainer = null;
                activePointerId = null;
                currentPointerType = null;
            }

            document.addEventListener('pointerdown', function(event) {
                if (!isPointerDragCandidate(event)) {
                    return;
                }

                if (isDragging) {
                    return;
                }

                const target = event.target.closest('.reservation-item');
                if (!target) {
                    return;
                }

                const pointerType = event.pointerType || (event instanceof MouseEvent ? 'mouse' : '');
                if ((pointerType === 'mouse' || pointerType === '') && event.button !== undefined && event.button !== 0) {
                    return;
                }

                currentPointerType = pointerType || 'mouse';
                activePointerId = event.pointerId;
                touchCurrentElement = target;
                touchStartTime = Date.now();
                touchMoved = false;
                touchStartPos = {
                    x: event.clientX,
                    y: event.clientY
                };
                lastTouchPoint = { ...touchStartPos };

                clearPendingTouchHold();

                if (currentPointerType === 'mouse') {
                    // Starte erst bei Bewegung
                    target.style.transition = 'transform 0.1s ease';
                } else {
                    target.style.transform = 'scale(0.98)';
                    target.style.transition = 'transform 0.1s ease';

                    touchHoldTimer = setTimeout(() => {
                        touchHoldTimer = null;
                        if (touchCurrentElement && !touchMoved && !isDragging) {
                            startTouchDrag(touchCurrentElement);
                            if (navigator.vibrate) {
                                navigator.vibrate(40);
                            }
                        }
                    }, TOUCH_HOLD_DURATION);
                }

                if (currentPointerType === 'mouse') {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('pointermove', function(event) {
                if (!isPointerDragCandidate(event)) {
                    return;
                }
                if (activePointerId === null || event.pointerId !== activePointerId || !touchCurrentElement) {
                    return;
                }

                const deltaX = Math.abs(event.clientX - touchStartPos.x);
                const deltaY = Math.abs(event.clientY - touchStartPos.y);
                lastTouchPoint = { x: event.clientX, y: event.clientY };

                if (!isDragging) {
                    const threshold = currentPointerType === 'mouse' ? MOUSE_MOVE_THRESHOLD : TOUCH_MOVE_THRESHOLD;
                    if (deltaX > threshold || deltaY > threshold) {
                        touchMoved = true;
                        if (currentPointerType === 'mouse' && touchCurrentElement) {
                            startTouchDrag(touchCurrentElement);
                            event.preventDefault();
                        } else {
                            abortTouchPreparation();
                        }
                    }
                    return;
                }

                event.preventDefault();
                startAutoScrollLoop();

                const elementBelow = document.elementFromPoint(event.clientX, event.clientY);
                const zimmer = elementBelow ? elementBelow.closest('.zimmer, .ablage-zimmer') : null;

                document.querySelectorAll('.zimmer, .ablage-zimmer').forEach(z => z.classList.remove('drag-hover'));
                if (zimmer) {
                    zimmer.classList.add('drag-hover');
                }

                updateGhostPosition(lastTouchPoint);
            }, { passive: false });

            document.addEventListener('pointerup', async function(event) {
                if (!isPointerDragCandidate(event) || activePointerId === null || event.pointerId !== activePointerId) {
                    return;
                }

                clearPendingTouchHold();
                const pointerElement = touchCurrentElement;
                const elementBelow = document.elementFromPoint(event.clientX, event.clientY);

                if (isDragging && pointerElement) {
                    event.preventDefault();
                    await handleDrop(event, elementBelow);
                    if (pointerElement && typeof pointerElement.releasePointerCapture === 'function') {
                        try {
                            pointerElement.releasePointerCapture(event.pointerId);
                        } catch (err) {
                            // Ignorieren
                        }
                    }
                    endTouchDrag();
                } else {
                    resetTouchPreview();
                    if (pointerElement && typeof pointerElement.releasePointerCapture === 'function') {
                        try {
                            pointerElement.releasePointerCapture(event.pointerId);
                        } catch (err) {
                            // Ignorieren
                        }
                    }
                }

                touchCurrentElement = null;
                touchStartPos = null;
                touchMoved = false;
                touchStartTime = 0;
                lastTouchPoint = null;
                activePointerId = null;
                currentPointerType = null;
                stopAutoScrollLoop();
            }, { passive: false });

            document.addEventListener('pointercancel', function(event) {
                if (!isPointerDragCandidate(event) || activePointerId === null || event.pointerId !== activePointerId) {
                    return;
                }

                clearPendingTouchHold();
                const pointerElement = touchCurrentElement;

                if (isDragging && pointerElement) {
                    if (typeof pointerElement.releasePointerCapture === 'function') {
                        try {
                            pointerElement.releasePointerCapture(event.pointerId);
                        } catch (err) {}
                    }
                    endTouchDrag();
                } else {
                    resetTouchPreview();
                    if (pointerElement && typeof pointerElement.releasePointerCapture === 'function') {
                        try {
                            pointerElement.releasePointerCapture(event.pointerId);
                        } catch (err) {}
                    }
                }

                touchCurrentElement = null;
                touchStartPos = null;
                touchMoved = false;
                touchStartTime = 0;
                lastTouchPoint = null;
                activePointerId = null;
                currentPointerType = null;
                stopAutoScrollLoop();
            }, { passive: false });


            // 🚀 GEMEINSAME FUNKTIONEN
            function startTouchDrag(element) {
                clearPendingTouchHold();
                resetTouchPreview();
                if (element && typeof element.setPointerCapture === 'function' && activePointerId !== null) {
                    try {
                        element.setPointerCapture(activePointerId);
                    } catch (err) {
                        // Ignorieren
                    }
                }

                draggedElement = element;
                draggedReservation = getCurrentReservationData(element);
                element.classList.add('dragging');
                isDragging = true;
                if (document.body) {
                    document.body.classList.add('dragging-active');
                }
                const selection = window.getSelection ? window.getSelection() : null;
                if (selection && typeof selection.removeAllRanges === 'function') {
                    selection.removeAllRanges();
                }
                startAutoScrollLoop();

                const referencePoint = lastTouchPoint || touchStartPos;
                const elementRect = element.getBoundingClientRect();
                if (referencePoint) {
                    dragGhostOffset = {
                        x: referencePoint.x - elementRect.left,
                        y: referencePoint.y - elementRect.top
                    };
                } else {
                    dragGhostOffset = {
                        x: elementRect.width / 2,
                        y: elementRect.height / 2
                    };
                }
                const dragLabel = currentPointerType === 'mouse' ? '🖱️ Desktop-Drag' : '📱 Pointer-Drag';
                if (draggedReservation && draggedReservation.name) {
                    console.log(`${dragLabel} gestartet für: ${draggedReservation.name}`);
                } else {
                    console.log(`${dragLabel} gestartet`);
                }
                
                // Platzhalter erstellen, damit Layout stabil bleibt
                removeDragPlaceholder();
                const parent = element.parentElement;
                if (parent) {
                    dragSourceContainer = parent;
                    dragPlaceholder = document.createElement('div');
                    dragPlaceholder.className = 'drag-placeholder';
                    dragPlaceholder.style.width = element.offsetWidth + 'px';
                    dragPlaceholder.style.height = element.offsetHeight + 'px';
                    const computed = window.getComputedStyle(element);
                    dragPlaceholder.style.margin = computed.margin;
                    dragPlaceholder.style.flex = computed.flex;
                    dragPlaceholder.style.display = computed.display;
                    parent.insertBefore(dragPlaceholder, element);
                }

                element.classList.add('drag-hidden');
                element.style.pointerEvents = 'none';
                element.style.position = 'absolute';
                element.style.left = '-9999px';
                element.style.top = '-9999px';

                // Ghost erstellen
                removeDragGhost();
                dragGhost = element.cloneNode(true);
                dragGhost.classList.add('drag-ghost');
                dragGhost.style.width = element.offsetWidth + 'px';
                dragGhost.style.height = element.offsetHeight + 'px';
                dragGhost.style.left = '0px';
                dragGhost.style.top = '0px';
                dragGhost.style.willChange = 'transform';
                dragGhost.style.transform = 'translate3d(0, 0, 0) scale(1.05)';
                dragGhost.style.transformOrigin = 'top left';
                document.body.appendChild(dragGhost);
                const ghostAnchor = referencePoint || {
                    x: elementRect.left + elementRect.width / 2,
                    y: elementRect.top + elementRect.height / 2
                };
                updateGhostPosition(ghostAnchor, dragGhostOffset.x, dragGhostOffset.y);
                
                // Kapazitätsprüfung und Zimmer-Markierung
                checkRoomCapacities(draggedReservation);
                
                // Visuelles Feedback für verfügbare Drop-Zonen
                document.querySelectorAll('.zimmer, .ablage-zimmer').forEach(zimmer => {
                    zimmer.style.transition = 'all 0.2s ease';
                    if (zimmer.classList.contains('drop-target') || 
                        zimmer.classList.contains('drop-target-warning') || 
                        zimmer.classList.contains('ablage-zimmer')) {
                        zimmer.style.transform = 'scale(1.02)';
                        zimmer.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                    }
                });
            }

            function endTouchDrag() {
                if (touchCurrentElement) {
                    // Style-Reset für das bewegte Element
                    touchCurrentElement.style.position = '';
                    touchCurrentElement.style.left = '';
                    touchCurrentElement.style.top = '';
                    touchCurrentElement.style.zIndex = '';
                    touchCurrentElement.style.pointerEvents = '';
                    touchCurrentElement.style.opacity = '';
                    touchCurrentElement.style.transform = '';
                    touchCurrentElement.style.transition = '';
                    touchCurrentElement.style.boxShadow = '';
                    touchCurrentElement.classList.remove('dragging');
                    touchCurrentElement.classList.remove('drag-hidden');
                }

                removeDragGhost();
                removeDragPlaceholder();
                stopAutoScrollLoop();
                if (document.body) {
                    document.body.classList.remove('dragging-active');
                }
                
                // Reset für alle Zimmer
                document.querySelectorAll('.zimmer, .ablage-zimmer').forEach(zimmer => {
                    zimmer.style.transform = '';
                    zimmer.style.boxShadow = '';
                    zimmer.style.transition = '';
                });
                
                draggedElement = null;
                draggedReservation = null;
                isDragging = false;
                dragSourceContainer = null;
                
                // Alle Markierungen entfernen
                clearRoomMarkings();
                document.querySelectorAll('.zimmer, .ablage-zimmer').forEach(z => {
                    z.classList.remove('drag-hover');
                });
            }

            async function handleDrop(e, targetElement) {
                const zimmer = targetElement ? targetElement.closest('.zimmer, .ablage-zimmer') : null;
                if (zimmer && draggedElement && draggedReservation) {
                    const zimmerId = zimmer.dataset.zimmerId;
                    const sourceZimmerElement = dragSourceContainer
                        ? dragSourceContainer.closest('.zimmer, .ablage-zimmer')
                        : draggedElement.closest('.zimmer, .ablage-zimmer');
                    const sourceZimmerId = sourceZimmerElement ? sourceZimmerElement.dataset.zimmerId : null;

                    if (sourceZimmerId && sourceZimmerId === zimmerId) {
                        console.log(`🔁 Reservierung ${draggedReservation.id} im gleichen Zimmer ${zimmerId} abgelegt – keine Änderungen erforderlich.`);
                        clearRoomMarkings();
                        return;
                    }
                    
                    // SONDERBEHANDLUNG FÜR ABLAGE-ZIMMER: Diese haben unbegrenzte Kapazität
                    const isAblageZimmer = zimmer.classList.contains('ablage-zimmer');
                    
                    let capacityStatus;
                    if (isAblageZimmer) {
                        // Ablage-Zimmer: Immer erlaubt
                        capacityStatus = {
                            allowed: true,
                            hasOtherGuests: false,
                            maxOccupancy: 1,
                            capacity: 999,
                            isAblage: true
                        };
                        console.log(`📂 Ablage-Drop: Immer erlaubt für Zimmer ${zimmerId}`);
                    } else {
                        // Normale Zimmer: Kapazitätsprüfung
                        capacityStatus = await checkRoomCapacityForReservation(zimmerId, draggedReservation);
                    }
                    
                    // Drop erlauben wenn Kapazität vorhanden (grün oder gelb) oder Ablage
                    // Nur "rot" (forbidden) blockiert das Drop bei normalen Zimmern
                    if (capacityStatus.allowed) {
                        const sourceContainer = dragSourceContainer || draggedElement.parentElement;
                        const targetContainer = zimmer.querySelector('.zimmer-content');
                        if (targetContainer) {
                            targetContainer.appendChild(draggedElement);
                            
                            // Layout-Klassen für beide Container aktualisieren
                            if (sourceContainer) {
                                updateRoomLayout(sourceContainer);
                            }
                            updateRoomLayout(targetContainer);
                            
                            // Belegungszähler aktualisieren
                            updateOccupancyCounters();
                            
                            // Verschiedenes Feedback je nach Zieltyp
                            if (isAblageZimmer) {
                                console.log(`📂 Reservierung ${draggedReservation.id} (${draggedReservation.name}) in Ablage ${zimmerId} zurückgelegt`);
                            } else if (capacityStatus.hasOtherGuests) {
                                console.log(`⚠️ Reservierung ${draggedReservation.id} (${draggedReservation.name}) nach Zimmer ${zimmerId} verschoben - GETEILTES ZIMMER`);
                                console.log(`📊 Kapazität: ${capacityStatus.maxOccupancy}/${capacityStatus.capacity} (mit anderen Gästen)`);
                                
                                // Kurze gelbe Hervorhebung für geteiltes Zimmer
                                zimmer.style.animation = 'none';
                                zimmer.offsetHeight; // Force reflow
                                zimmer.style.animation = 'warningPulse 0.3s ease-in-out 2';
                            } else {
                                console.log(`✅ Reservierung ${draggedReservation.id} (${draggedReservation.name}) erfolgreich nach Zimmer ${zimmerId} verschoben`);
                                console.log(`📊 Kapazität: ${capacityStatus.maxOccupancy}/${capacityStatus.capacity} - alleiniges Zimmer`);
                            }
                            
                            // 💾 Server-Update für Zimmerwechsel
                            updateReservationRoom(draggedReservation.id, zimmerId, isAblageZimmer);
                            dragSourceContainer = targetContainer;
                        }
                    } else {
                        // Drop verweigert - nur bei echter Kapazitätsüberschreitung (nicht bei Ablage)
                        console.warn(`❌ Drop verweigert für Reservierung ${draggedReservation.id} (${draggedReservation.name})`);
                        console.warn(`📊 Kapazität überschritten: ${capacityStatus.maxOccupancy}/${capacityStatus.capacity}`);
                        if (capacityStatus.conflictDate) {
                            console.warn(`📅 Konflikt am: ${capacityStatus.conflictDate}`);
                        }
                        
                        // Visuelles Feedback für Benutzer
                        zimmer.style.animation = 'none';
                        zimmer.offsetHeight; // Force reflow
                        zimmer.style.animation = 'forbiddenPulse 0.5s ease-in-out 3';
                    }
                }
                
                // Alle Drop-Targets zurücksetzen
                clearRoomMarkings();
            }
        }

        function getCurrentReservationData(element) {
            const reservationId = element.dataset.reservationId;
            // Finde die Reservierung in den geladenen Daten
            return currentReservations.find(r => r.id == reservationId) || {
                id: reservationId,
                von: currentViewDate,
                bis: currentViewDate,
                anz: 1
            };
        }

        async function checkRoomCapacities(reservation) {
            const roomElements = document.querySelectorAll('.zimmer, .ablage-zimmer');
            console.log(`🔄 Prüfe Kapazitäten für ${roomElements.length} Zimmer...`);
            
            for (const zimmer of roomElements) {
                const zimmerId = zimmer.dataset.zimmerId;
                const isAblageZimmer = zimmer.classList.contains('ablage-zimmer');
                
                // Entferne alle Markierungen
                zimmer.classList.remove('drop-target', 'drop-target-warning', 'drop-target-forbidden');
                
                // SONDERBEHANDLUNG FÜR ABLAGE-ZIMMER
                if (isAblageZimmer) {
                    // Ablage-Zimmer sind immer erlaubt (grün)
                    zimmer.classList.add('drop-target');
                    console.log(`📂 Ablage-Zimmer ${zimmerId}: Immer erlaubt`);
                    continue;
                }
                
                // Normale Zimmer: Kapazitätsprüfung
                const capacityStatus = await checkRoomCapacityForReservation(zimmerId, reservation);
                
                // Setze entsprechende Markierung
                if (capacityStatus.allowed) {
                    if (capacityStatus.hasOtherGuests) {
                        zimmer.classList.add('drop-target-warning');
                    } else {
                        zimmer.classList.add('drop-target');
                    }
                } else {
                    zimmer.classList.add('drop-target-forbidden');
                }
            }
            console.log(`✅ Kapazitätsprüfung für alle Zimmer abgeschlossen`);
        }

        async function checkRoomCapacityForReservation(zimmerId, reservation) {
            const room = rooms.find(r => r.id == zimmerId);
            if (!room) return { allowed: false, hasOtherGuests: false };
            
            // Sichere Datums-Parsing - split und manual construction um Timezone-Probleme zu vermeiden
            const [startYear, startMonth, startDay] = reservation.von.split('-').map(Number);
            const [endYear, endMonth, endDay] = reservation.bis.split('-').map(Number);
            const reservationStart = new Date(startYear, startMonth - 1, startDay); // month is 0-indexed
            const reservationEnd = new Date(endYear, endMonth - 1, endDay);
            const newGuestCount = parseInt(reservation.anz || 1);
            
            console.log(`🔍 Prüfe Kapazität für Zimmer ${room.caption} (${room.kapazitaet} Plätze)`);
            console.log(`📅 Zeitraum: ${reservation.von} bis ${reservation.bis} (${newGuestCount} Gäste)`);
            
            // 🚀 CACHE FIRST: Versuche schnelle Cache-Abfrage
            if (isCacheValid()) {
                console.log('⚡ Verwende Cache für Kapazitätsprüfung');
                const cachedConflicts = checkCachedCapacity(zimmerId, reservation.von, reservation.bis);
                
                if (cachedConflicts !== null) {
                    // Berechne mit gecachten Daten
                    const totalOccupancy = cachedConflicts + newGuestCount;
                    const allowed = totalOccupancy <= room.kapazitaet;
                    
                    console.log(`⚡ Cache-Ergebnis: ${cachedConflicts} + ${newGuestCount} = ${totalOccupancy}/${room.kapazitaet} → ${allowed ? '✅' : '❌'}`);
                    
                    return {
                        allowed: allowed,
                        hasOtherGuests: cachedConflicts > 0,
                        maxOccupancy: totalOccupancy,
                        capacity: room.kapazitaet,
                        method: 'cache'
                    };
                }
            }
            
            // FALLBACK: API-basierte Prüfung (wie bisher)
            console.log('🐌 Fallback zu API-basierter Kapazitätsprüfung');
            
            let hasOtherGuests = false;
            let maxTotalOccupancy = 0;
            let conflictDetails = [];
            
            // Berechne Anzahl der Nächte
            const totalDays = Math.ceil((reservationEnd - reservationStart) / (1000 * 60 * 60 * 24));
            console.log(`📊 Prüfe ${totalDays} Tage...`);
            
            // Lade alle Reservierungsdaten für den gesamten Zeitraum
            const allReservationsForPeriod = await loadReservationsForPeriod(reservation.von, reservation.bis);
            console.log(`📋 Geladene Reservierungen für Zeitraum: ${allReservationsForPeriod.length}`);
            
            // Gehe durch jeden Tag des GESAMTEN Aufenthaltszeitraums
            for (let dayOffset = 0; dayOffset < totalDays; dayOffset++) {
                const checkDate = new Date(reservationStart);
                checkDate.setDate(checkDate.getDate() + dayOffset);
                // Format: YYYY-MM-DD ohne Timezone-Konversion
                const checkDateString = checkDate.getFullYear() + '-' + 
                    String(checkDate.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(checkDate.getDate()).padStart(2, '0');
                
                let dayOccupancy = 0;
                let dayGuests = [];
                
                // Prüfe alle anderen Reservierungen in diesem Zimmer für diesen Tag
                allReservationsForPeriod.forEach(otherRes => {
                    // Überspringe die Reservierung, die gerade verschoben wird
                    if (otherRes.zimmer_id == zimmerId && otherRes.id != reservation.id) {
                        const [otherStartYear, otherStartMonth, otherStartDay] = otherRes.von.split('-').map(Number);
                        const [otherEndYear, otherEndMonth, otherEndDay] = otherRes.bis.split('-').map(Number);
                        const otherStart = new Date(otherStartYear, otherStartMonth - 1, otherStartDay);
                        const otherEnd = new Date(otherEndYear, otherEndMonth - 1, otherEndDay);
                        
                        // Debug: Zeige alle Reservierungen in diesem Zimmer
                        if (otherRes.zimmer_id == zimmerId) {
                            console.log(`  🏠 Zimmer ${zimmerId} hat Reservierung: ${otherRes.name} von ${otherRes.von} bis ${otherRes.bis}`);
                        }
                        
                        // Prüfe ob die andere Reservierung an diesem Tag im Zimmer ist
                        // Hotel-Logik: Eine Reservierung belegt das Zimmer von Ankunft bis (aber nicht einschließlich) Abreise
                        if (otherStart <= checkDate && otherEnd > checkDate) {
                            const guestCount = parseInt(otherRes.anz || 1);
                            dayOccupancy += guestCount;
                            dayGuests.push(`${otherRes.name} (${guestCount})`);
                            hasOtherGuests = true;
                            console.log(`    ✅ Überschneidung gefunden: ${otherRes.name} belegt Zimmer am ${checkDateString}`);
                        } else {
                            console.log(`    ❌ Keine Überschneidung: ${otherRes.name} (${otherRes.von} - ${otherRes.bis}) nicht am ${checkDateString}`);
                        }
                    }
                });
                
                // Berechne die Gesamtbelegung mit der neuen Reservierung
                const totalDayOccupancy = dayOccupancy + newGuestCount;
                maxTotalOccupancy = Math.max(maxTotalOccupancy, totalDayOccupancy);
                
                console.log(`📅 ${checkDateString}: ${dayOccupancy} + ${newGuestCount} = ${totalDayOccupancy}/${room.kapazitaet}${dayGuests.length > 0 ? ' | Andere: ' + dayGuests.join(', ') : ''}`);
                
                // Wenn an irgendeinem Tag die Kapazität überschritten wird
                if (totalDayOccupancy > room.kapazitaet) {
                    conflictDetails.push({
                        date: checkDateString,
                        occupancy: totalDayOccupancy,
                        capacity: room.kapazitaet,
                        otherGuests: dayGuests
                    });
                    
                    console.warn(`❌ Kapazitätskonflikt am ${checkDateString}: ${totalDayOccupancy}/${room.kapazitaet}`);
                    
                    return {
                        allowed: false,
                        hasOtherGuests: hasOtherGuests,
                        maxOccupancy: totalDayOccupancy,
                        capacity: room.kapazitaet,
                        conflictDate: checkDateString,
                        conflictDetails: conflictDetails
                    };
                }
            }
            
            console.log(`✅ Kapazität OK: Max ${maxTotalOccupancy}/${room.kapazitaet}`);
            
            return {
                allowed: true,
                hasOtherGuests: hasOtherGuests,
                maxOccupancy: maxTotalOccupancy,
                capacity: room.kapazitaet,
                timeSpan: `${reservation.von} bis ${reservation.bis}`,
                checkedDays: totalDays
            };
        }

        // Neue Funktion um Reservierungen für einen bestimmten Zeitraum zu laden
        async function loadReservationsForPeriod(startDate, endDate) {
            try {
                const response = await fetch(`api_room_data.php?von=${startDate}&bis=${endDate}`);
                const data = await response.json();
                return data.reservations || [];
            } catch (error) {
                console.error('Fehler beim Laden der Reservierungen für Zeitraum:', error);
                // Fallback: verwende die aktuell geladenen Daten
                return currentReservations;
            }
        }

        function clearRoomMarkings() {
            document.querySelectorAll('.zimmer, .ablage-zimmer').forEach(el => {
                el.classList.remove('drop-target', 'drop-target-warning', 'drop-target-forbidden', 'drag-hover');
            });
        }

        function updateOccupancyCounters() {
            // Zähle Reservierungen in jedem Zimmer
            rooms.forEach(room => {
                const container = document.getElementById(`zimmer-content-${room.id}`);
                if (container) {
                    const reservationCount = container.querySelectorAll('.reservation-item').length;
                    const occupancyElement = document.getElementById(`occupancy-${room.id}`);
                    if (occupancyElement) {
                        occupancyElement.textContent = `${reservationCount}/${room.kapazitaet}`;
                        
                        // Farbe je nach Belegung
                        if (reservationCount >= room.kapazitaet) {
                            occupancyElement.style.color = '#dc2626'; // Rot wenn voll
                        } else if (reservationCount >= room.kapazitaet * 0.8) {
                            occupancyElement.style.color = '#f59e0b'; // Gelb wenn fast voll
                        } else {
                            occupancyElement.style.color = '#059669'; // Grün wenn Platz
                        }
                    }
                }
            });
        }

        // Server-Update für Zimmerwechsel
        async function updateReservationRoom(reservationId, newZimmerId, isAblage) {
            try {
                console.log(`💾 Server-Update: Reservierung ${reservationId} → Zimmer ${newZimmerId}${isAblage ? ' (Ablage)' : ''}`);
                
                const response = await fetch('api_update_room.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        reservation_id: reservationId,
                        new_zimmer_id: newZimmerId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    console.log('✅ Server-Update erfolgreich:', result.data);
                    console.log(`📝 ${result.data.guest_name}: ${result.data.old_zimmer_id} → ${result.data.new_zimmer_id} (${result.data.zimmer_name})`);
                    
                    // Optionale Erfolgs-Benachrichtigung
                    if (!isAblage) {
                        showSuccessMessage(`Reservierung erfolgreich nach ${result.data.zimmer_name} verschoben`);
                    }
                } else {
                    console.error('❌ Server-Update fehlgeschlagen:', result.error);
                    alert(`Fehler beim Speichern des Zimmerwechsels: ${result.error}`);
                    
                    // Bei Fehler: UI-Änderung rückgängig machen
                    location.reload(); // Vereinfachte Lösung
                }
                
            } catch (error) {
                console.error('❌ Netzwerkfehler beim Server-Update:', error);
                alert(`Netzwerkfehler beim Speichern: ${error.message}`);
                
                // Bei Fehler: UI-Änderung rückgängig machen
                location.reload(); // Vereinfachte Lösung
            }
        }
        
        // Erfolgs-Benachrichtigung anzeigen
        function showSuccessMessage(message) {
            // Einfache Lösung: Temporäre Nachricht in der Konsole
            console.log(`🎉 ${message}`);
            
            // TODO: Optionale schönere UI-Benachrichtigung
            // const notification = document.createElement('div');
            // notification.style.cssText = 'position:fixed;top:20px;right:20px;background:#10b981;color:white;padding:15px;border-radius:6px;z-index:1000;';
            // notification.textContent = message;
            // document.body.appendChild(notification);
            // setTimeout(() => notification.remove(), 3000);
        }

        // Menü-Funktionen
        function showReservations() {
            window.location.href = '../reservierungen.html';
        }

        function showSettings() {
            alert('Einstellungen - noch nicht implementiert');
        }

        function showReports() {
            alert('Berichte - noch nicht implementiert');
        }
    </script>
</body>
</html>