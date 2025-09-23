<?php
// Korrekter Pfad zur config.php - ein Verzeichnis höher
require_once dirname(__DIR__) . '/config.php';

// MySQLi-Verbindung erstellen (wie im Rest der Anwendung)
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($mysqli->connect_error) {
    die('Datenbankverbindung fehlgeschlagen: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

// Aktuelle Zimmer aus zp_zimmer Tabelle laden
$zimmer = [];
$minX = PHP_INT_MAX;
$maxX = PHP_INT_MIN;
$minY = PHP_INT_MAX;
$maxY = PHP_INT_MIN;

try {
    $stmt = $mysqli->prepare("
        SELECT id, caption, kapazitaet, kategorie, col, px, py, visible 
        FROM zp_zimmer 
        WHERE visible = 1 
        ORDER BY caption
    ");
    
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $rawZimmer = $result->fetch_all(MYSQLI_ASSOC);
        
        // Finde Min/Max X und Y Koordinaten
        foreach ($rawZimmer as $z) {
            $minX = min($minX, $z['px']);
            $maxX = max($maxX, $z['px']);
            $minY = min($minY, $z['py']);
            $maxY = max($maxY, $z['py']);
        }
        
        // Berechne Grid-Dimensionen
        $gridCols = ($maxX - $minX + 1);
        $gridRows = ($maxY - $minY + 1);
        
        // Diese Werte werden im JavaScript verwendet
        $gridInfo = [
            'minX' => $minX,
            'maxX' => $maxX,
            'minY' => $minY,
            'maxY' => $maxY,
            'cols' => $gridCols,
            'rows' => $gridRows
        ];
        
        $zimmer = $rawZimmer;
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Fehler beim Laden der Zimmer: " . $e->getMessage());
    $zimmer = [];
    $gridInfo = ['minX' => 1, 'maxX' => 1, 'minY' => 1, 'maxY' => 1, 'cols' => 1, 'rows' => 1];
}

// Separate Ablage-Zimmer von normalen Zimmern
$normalZimmer = [];
$ablageZimmer = [];

foreach ($zimmer as $z) {
    if (strtolower($z['caption']) === 'ablage' || strpos(strtolower($z['caption']), 'ablage') !== false) {
        $ablageZimmer[] = $z;
    } else {
        $normalZimmer[] = $z;
    }
}

// Aktuelles Datum für Standardansicht
$currentDate = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zimmerplan - Tagesansicht</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1f2937;
            height: 100vh;
            display: flex;
            margin: 0;
            padding: 0;
            overflow: hidden;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        .header {
            display: none;
        }

        .main-content {
            flex: 1;
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        .sidebar {
            width: 280px;
            background: #ffffff;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .sidebar-section {
            padding: 16px;
            border-bottom: 1px solid #f3f4f6;
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
        }

        .zimmerplan-container {
            flex: 1;
            position: relative;
            overflow: hidden;
            background: #f9fafb;
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
        }

        /* 1 Reservierung - maximale Größe */
        .zimmer-content.count-1 .reservation-item {
            width: 100%;
            height: calc(100% - 4px);
            max-height: calc(100vh / 8);
            font-size: 11px;
            white-space: normal;
            word-wrap: break-word;
        }

        /* 2 Reservierungen - halbe Breite, volle Höhe */
        .zimmer-content.count-2 .reservation-item {
            width: 50%;
            height: calc(100% - 4px);
            max-height: calc(100vh / 8);
            font-size: 10px;
        }

        /* 3-4 Reservierungen - halbe Breite, halbe Höhe */
        .zimmer-content.count-3-plus .reservation-item {
            width: 50%;
            height: calc(50% - 2px);
            max-height: calc(100vh / 16);
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
        
        // Reservation lookup und Cache für optimierte Kapazitätsprüfung
        let currentReservations = [];
        let reservationLookupCache = new Map(); // Cache für erweiterte Zeitraum-Abfragen
        let cacheValidUntil = null; // Zeitstempel bis wann der Cache gültig ist
        let cacheMinDate = null; // Minimales Datum im Cache
        let cacheMaxDate = null; // Maximales Datum im Cache
        
        // Initialisierung
        document.addEventListener('DOMContentLoaded', function() {
            calculateRoomPositions();
            initializeDragAndDrop();
            loadRoomData();
            
            // Neuberechnung bei Fenstergröße-Änderung
            window.addEventListener('resize', function() {
                calculateRoomPositions();
                // Alle Reservierungshöhen neu berechnen
                document.querySelectorAll('.zimmer-content').forEach(container => {
                    setTimeout(() => adjustReservationHeights(container), 10);
                });
            });
        });

        function calculateRoomPositions() {
            const container = document.querySelector('.zimmerplan');
            if (!container) return;
            
            const containerRect = container.getBoundingClientRect();
            const padding = 20;
            const availableWidth = containerRect.width - (padding * 2);
            const availableHeight = containerRect.height - (padding * 2);
            
            // Berechne Zellengröße basierend auf verfügbarem Platz
            const cellWidth = Math.floor(availableWidth / gridInfo.cols);
            const cellHeight = Math.floor(availableHeight / gridInfo.rows);
            
            console.log('Grid Info:', gridInfo);
            console.log('Container Size:', containerRect.width, 'x', containerRect.height);
            console.log('Cell Size:', cellWidth, 'x', cellHeight);
            
            // Positioniere alle Zimmer
            document.querySelectorAll('.zimmer').forEach(zimmer => {
                const gridX = parseInt(zimmer.dataset.gridX);
                const gridY = parseInt(zimmer.dataset.gridY);
                
                // Berechne Position relativ zum minimalen Grid
                const relativeX = gridX - gridInfo.minX;
                const relativeY = gridY - gridInfo.minY;
                
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
            item.draggable = true;
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
            
            // Drag & Drop für Reservierungen
            document.addEventListener('dragstart', function(e) {
                if (e.target.classList.contains('reservation-item')) {
                    draggedElement = e.target;
                    draggedReservation = getCurrentReservationData(e.target);
                    e.target.classList.add('dragging');
                    
                    // Alle Zimmer auf Kapazität prüfen und markieren
                    checkRoomCapacities(draggedReservation);
                }
            });

            document.addEventListener('dragend', function(e) {
                if (e.target.classList.contains('reservation-item')) {
                    e.target.classList.remove('dragging');
                    draggedElement = null;
                    draggedReservation = null;
                    
                    // Alle Markierungen und Hover-Effekte entfernen
                    clearRoomMarkings();
                    document.querySelectorAll('.zimmer, .ablage-zimmer').forEach(z => {
                        z.classList.remove('drag-hover');
                    });
                }
            });

            // Drop-Zonen für Zimmer
            document.addEventListener('dragover', function(e) {
                e.preventDefault();
                
                // Live-Feedback: Hervorhebung des aktuellen Drop-Ziels
                if (draggedReservation) {
                    const zimmer = e.target.closest('.zimmer, .ablage-zimmer');
                    if (zimmer) {
                        // Entferne Hover-Effekte von allen anderen Zimmern
                        document.querySelectorAll('.zimmer, .ablage-zimmer').forEach(z => {
                            z.classList.remove('drag-hover');
                        });
                        
                        // Füge Hover-Effekt zum aktuellen Zimmer hinzu
                        zimmer.classList.add('drag-hover');
                    }
                }
            });

            document.addEventListener('dragleave', function(e) {
                const zimmer = e.target.closest('.zimmer, .ablage-zimmer');
                if (zimmer && !zimmer.contains(e.relatedTarget)) {
                    zimmer.classList.remove('drag-hover');
                }
            });

            document.addEventListener('drop', async function(e) {
                e.preventDefault();
                const zimmer = e.target.closest('.zimmer, .ablage-zimmer');
                if (zimmer && draggedElement && draggedReservation) {
                    const zimmerId = zimmer.dataset.zimmerId;
                    
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
                        const sourceContainer = draggedElement.parentElement;
                        const targetContainer = zimmer.querySelector('.zimmer-content');
                        if (targetContainer) {
                            targetContainer.appendChild(draggedElement);
                            
                            // Layout-Klassen für beide Container aktualisieren
                            updateRoomLayout(sourceContainer);
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
            });
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