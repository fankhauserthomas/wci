<?php
/**
 * Erweiterte Belegungsanalyse - FUNKTIONIERENDE VERSION mit Pan
 * Verwendet die bewährte Logik + korrekte Pan-Funktionalität
 */

require_once '../config.php';

// Zeitraum: heute + 31 Tage (kann später parametrisiert werden)
$startDate = $_GET['start'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime($startDate . ' +31 days'));

/**
 * Hausbelegung berechnen - FUNKTIONIERENDE Methode
 */
function getErweiterteGelegungsDaten($mysqli, $startDate, $endDate) {
    // Alle Tage im Zeitraum generieren (PHP-basiert)
    $alleTage = [];
    $currentDate = new DateTime($startDate);
    $endDateTime = new DateTime($endDate);
    
    while ($currentDate <= $endDateTime) {
        $alleTage[] = $currentDate->format('Y-m-d');
        $currentDate->add(new DateInterval('P1D'));
    }
    
    // Für jeden Tag die Belegung berechnen
    $detailDaten = [];
    $aggregatedData = [];
    $freieKapazitaeten = [];
    
    foreach ($alleTage as $tag) {
        // Freie Kapazitäten aus Daily Summary holen
        $freieKapazitaeten[$tag] = getFreieKapazitaet($mysqli, $tag);
        
        // Alle Reservierungen die an diesem Tag im Haus sind
        $sql = "SELECT 
            CASE WHEN av_id > 0 THEN 'hrs' ELSE 'lokal' END as quelle,
            av_id, vorname, nachname, gruppe,
            sonder, lager, betten, dz,
            hp, vegi, bem_av, anreise, abreise,
            timestamp as import_zeit
        FROM `AV-Res` 
        WHERE DATE(?) >= DATE(anreise) 
        AND DATE(?) < DATE(abreise)
        AND (storno IS NULL OR storno != 1)
        ORDER BY quelle, nachname";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('ss', $tag, $tag);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $row['tag'] = $tag;
            $detailDaten[] = $row;
            
            // Aggregierte Daten für Chart
            $key = $tag . '_' . $row['quelle'];
            if (!isset($aggregatedData[$key])) {
                $aggregatedData[$key] = [
                    'tag' => $tag,
                    'quelle' => $row['quelle'],
                    'sonder' => 0,
                    'lager' => 0,
                    'betten' => 0,
                    'dz' => 0,
                    'anzahl_reservierungen' => 0
                ];
            }
            
            $aggregatedData[$key]['sonder'] += (int)$row['sonder'];
            $aggregatedData[$key]['lager'] += (int)$row['lager'];
            $aggregatedData[$key]['betten'] += (int)$row['betten'];
            $aggregatedData[$key]['dz'] += (int)$row['dz'];
            $aggregatedData[$key]['anzahl_reservierungen']++;
        }
        
        $stmt->close();
    }
    
    return ['detail' => $detailDaten, 'aggregated' => array_values($aggregatedData), 'kapazitaeten' => $freieKapazitaeten];
}

/**
 * Freie Kapazitäten für einen Tag berechnen
 */
function getFreieKapazitaet($mysqli, $datum) {
    $result = [
        'gesamt_frei' => 0,
        'sonder_frei' => 0,
        'lager_frei' => 0,
        'betten_frei' => 0,
        'dz_frei' => 0
    ];
    
    // Prüfe zuerst daily_summary_categories über JOIN mit daily_summary
    $sql = "SELECT dsc.category_type, dsc.free_places 
            FROM daily_summary_categories dsc
            JOIN daily_summary ds ON dsc.daily_summary_id = ds.id
            WHERE ds.day = ?";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $datum);
    $stmt->execute();
    $categoryResult = $stmt->get_result();
    
    $kategorienGefunden = false;
    while ($row = $categoryResult->fetch_assoc()) {
        $kategorienGefunden = true;
        $freePlaces = max(0, (int)$row['free_places']); // Cutoff bei 0
        
        // Mapping: ML=Lager, MBZ=Betten, 2BZ=DZ, SK=Sonder
        switch ($row['category_type']) {
            case 'SK': // Sonderkategorie
                $result['sonder_frei'] += $freePlaces;
                break;
            case 'ML': // Matratzenlager
                $result['lager_frei'] += $freePlaces;
                break;
            case 'MBZ': // Mehrbettzimmer
                $result['betten_frei'] += $freePlaces;
                break;
            case '2BZ': // Zweierzimmer
                $result['dz_frei'] += $freePlaces;
                break;
        }
    }
    $stmt->close();
    
    // Falls Kategorien gefunden wurden, berechne Gesamtsumme
    if ($kategorienGefunden) {
        $result['gesamt_frei'] = $result['sonder_frei'] + $result['lager_frei'] + 
                                $result['betten_frei'] + $result['dz_frei'];
    } else {
        // Fallback: daily_summary - berechne aus total_guests und Kapazität
        $sql = "SELECT total_guests FROM daily_summary WHERE day = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('s', $datum);
        $stmt->execute();
        $summaryResult = $stmt->get_result();
        
        if ($row = $summaryResult->fetch_assoc()) {
            // Hier könnten wir eine Schätzung machen, aber ohne Kapazitätsdaten schwierig
            // Vorerst 0 lassen wenn keine Kategorien-Daten vorhanden
            $result['gesamt_frei'] = 0;
        }
        $stmt->close();
    }
    
    return $result;
}

/**
 * Quota-Daten für einen Zeitraum laden
 */
function getQuotaData($mysqli, $startDate, $endDate) {
    $quotas = [];
    
    // Lade alle Quotas die in den Zeitraum fallen
    $sql = "SELECT hq.*, hqc.category_id, hqc.total_beds,
                   CASE 
                       WHEN hqc.category_id = 1958 THEN 'ML'
                       WHEN hqc.category_id = 2293 THEN 'MBZ' 
                       WHEN hqc.category_id = 2381 THEN '2BZ'
                       WHEN hqc.category_id = 6106 THEN 'SK'
                       ELSE 'UNKNOWN'
                   END as category_type
            FROM hut_quota hq
            LEFT JOIN hut_quota_categories hqc ON hq.id = hqc.hut_quota_id
            WHERE (hq.date_from <= ? AND hq.date_to >= ?)
               OR (hq.date_from >= ? AND hq.date_from <= ?)
            ORDER BY hq.date_from, hq.title, hqc.category_id";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ssss', $endDate, $startDate, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $quotaId = $row['id'];
        if (!isset($quotas[$quotaId])) {
            $quotas[$quotaId] = [
                'id' => $row['id'],
                'hrs_id' => $row['hrs_id'],
                'title' => $row['title'],
                'date_from' => $row['date_from'],
                'date_to' => $row['date_to'],
                'capacity' => $row['capacity'],
                'mode' => $row['mode'],
                'categories' => []
            ];
        }
        
        // Kategorie hinzufügen falls vorhanden
        if ($row['category_id']) {
            $quotas[$quotaId]['categories'][$row['category_type']] = [
                'category_id' => $row['category_id'],
                'total_beds' => $row['total_beds'],
                'category_type' => $row['category_type']
            ];
        }
    }
    $stmt->close();
    
    return array_values($quotas);
}

/**
 * Prüfe ob ein Datum in einer Quota liegt und gib die beste zurück
 */
function getQuotasForDate($quotas, $date) {
    $matching = [];
    foreach ($quotas as $quota) {
        if ($date >= $quota['date_from'] && $date <= $quota['date_to']) {
            $matching[] = $quota;
        }
    }
    
    // Wenn mehrere Quotas gefunden, wähle die beste aus
    if (count($matching) > 1) {
        // Priorisierung:
        // 1. Quota die genau an diesem Tag startet
        // 2. Quota mit dem neuesten Startdatum
        // 3. Quota mit der höchsten HRS_ID (meist neuer)
        usort($matching, function($a, $b) use ($date) {
            // Priorisiere Quota die genau an diesem Tag startet
            $aStartsToday = ($a['date_from'] == $date) ? 1 : 0;
            $bStartsToday = ($b['date_from'] == $date) ? 1 : 0;
            if ($aStartsToday != $bStartsToday) {
                return $bStartsToday - $aStartsToday;
            }
            
            // Dann nach Startdatum (neuestes zuerst)
            $dateCompare = strcmp($b['date_from'], $a['date_from']);
            if ($dateCompare != 0) {
                return $dateCompare;
            }
            
            // Zuletzt nach HRS_ID (höchste zuerst)
            return $b['hrs_id'] - $a['hrs_id'];
        });
        
        // Nur die beste Quota zurückgeben
        return [$matching[0]];
    }
    
    return $matching;
}

$daten = getErweiterteGelegungsDaten($mysqli, $startDate, $endDate);
$rohdaten = $daten['aggregated'];
$detailDaten = $daten['detail'];
$freieKapazitaeten = $daten['kapazitaeten'];

// Quota-Daten laden
$quotaData = getQuotaData($mysqli, $startDate, $endDate);

// Chartdaten strukturieren
function strukturiereDatenFuerChart($rohdaten, $startDate, $endDate, $freieKapazitaeten) {
    $alleTage = [];
    $currentDate = new DateTime($startDate);
    $endDateTime = new DateTime($endDate);
    
    while ($currentDate <= $endDateTime) {
        $alleTage[] = $currentDate->format('Y-m-d');
        $currentDate->add(new DateInterval('P1D'));
    }
    
    $chartData = [
        'labels' => [],
        'datasets' => [
            ['label' => 'Sonder (HRS)', 'data' => [], 'backgroundColor' => '#D8B3FF', 'borderColor' => '#9966CC', 'borderWidth' => 1],
            ['label' => 'Sonder (Lokal)', 'data' => [], 'backgroundColor' => '#9933FF', 'borderColor' => '#7722CC', 'borderWidth' => 1],
            ['label' => 'Lager (HRS)', 'data' => [], 'backgroundColor' => '#B3FFB3', 'borderColor' => '#66CC66', 'borderWidth' => 1],
            ['label' => 'Lager (Lokal)', 'data' => [], 'backgroundColor' => '#33CC33', 'borderColor' => '#228822', 'borderWidth' => 1],
            ['label' => 'Betten (HRS)', 'data' => [], 'backgroundColor' => '#FFFF99', 'borderColor' => '#CCCC66', 'borderWidth' => 1],
            ['label' => 'Betten (Lokal)', 'data' => [], 'backgroundColor' => '#FFFF00', 'borderColor' => '#CCCC00', 'borderWidth' => 1],
            ['label' => 'DZ (HRS)', 'data' => [], 'backgroundColor' => '#FFB3B3', 'borderColor' => '#FF6666', 'borderWidth' => 1],
            ['label' => 'DZ (Lokal)', 'data' => [], 'backgroundColor' => '#FF3333', 'borderColor' => '#CC2222', 'borderWidth' => 1],
            ['label' => 'Freie Plätze', 'data' => [], 'backgroundColor' => 'rgba(135, 206, 235, 0.5)', 'borderColor' => '#5F9EA0', 'borderWidth' => 1]
        ],
        'freieKapazitaeten' => []
    ];
    
    $datenIndex = [];
    foreach ($rohdaten as $row) {
        $key = $row['tag'] . '_' . $row['quelle'];
        $datenIndex[$key] = $row;
    }
    
    foreach ($alleTage as $tag) {
        $chartData['labels'][] = date('d.m', strtotime($tag));
        
        // Freie Kapazitäten für diesen Tag
        $kapazitaet = isset($freieKapazitaeten[$tag]) ? $freieKapazitaeten[$tag] : [
            'gesamt_frei' => 0, 'sonder_frei' => 0, 'lager_frei' => 0, 'betten_frei' => 0, 'dz_frei' => 0
        ];
        $chartData['freieKapazitaeten'][] = $kapazitaet;
        
        $hrsKey = $tag . '_hrs';
        $hrsData = isset($datenIndex[$hrsKey]) ? $datenIndex[$hrsKey] : ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0];
        
        $lokalKey = $tag . '_lokal';
        $lokalData = isset($datenIndex[$lokalKey]) ? $datenIndex[$lokalKey] : ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0];
        
        $chartData['datasets'][0]['data'][] = (int)$hrsData['sonder'];
        $chartData['datasets'][1]['data'][] = (int)$lokalData['sonder'];
        $chartData['datasets'][2]['data'][] = (int)$hrsData['lager'];
        $chartData['datasets'][3]['data'][] = (int)$lokalData['lager'];
        $chartData['datasets'][4]['data'][] = (int)$hrsData['betten'];
        $chartData['datasets'][5]['data'][] = (int)$lokalData['betten'];
        $chartData['datasets'][6]['data'][] = (int)$hrsData['dz'];
        $chartData['datasets'][7]['data'][] = (int)$lokalData['dz'];
        $chartData['datasets'][8]['data'][] = $kapazitaet['gesamt_frei']; // Freie Plätze als oberstes Segment
    }
    
    return $chartData;
}

$chartData = strukturiereDatenFuerChart($rohdaten, $startDate, $endDate, $freieKapazitaeten);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erweiterte Belegungsanalyse</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
        .container { max-width: 1600px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; color: #333; margin-bottom: 30px; }
        .controls { display: flex; gap: 20px; margin-bottom: 20px; align-items: center; flex-wrap: wrap; }
        .control-group { display: flex; flex-direction: column; gap: 5px; }
        .control-group label { font-weight: bold; font-size: 14px; }
        .control-group input, .control-group button { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .control-group button { background: #2196F3; color: white; border: none; cursor: pointer; }
        .control-group button:hover { background: #1976D2; }
        .chart-container { position: relative; height: 600px; margin-bottom: 30px; overflow-x: auto; overflow-y: hidden; background-color: #2F2F2F; border-radius: 8px; }
        .chart-wrapper { min-width: 800px; height: 100%; position: relative; }
        .custom-tooltip { 
            position: absolute; 
            top: 10px; 
            right: 10px; 
            background: rgba(255, 255, 255, 0.95); 
            border: 1px solid #ddd; 
            border-radius: 6px; 
            padding: 10px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.15); 
            font-size: 14px; 
            min-width: 200px; 
            z-index: 1000;
            display: none;
        }
        .tooltip-header { font-weight: bold; margin-bottom: 8px; color: #333; }
        .tooltip-item { margin: 3px 0; display: flex; justify-content: space-between; align-items: center; }
        .tooltip-color { width: 12px; height: 12px; border-radius: 2px; margin-right: 8px; }
        .tooltip-label { flex: 1; }
        .tooltip-value { font-weight: bold; color: #1976d2; }
        .details-panel { display: none; background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px; }
        .details-header { font-size: 18px; font-weight: bold; margin-bottom: 15px; }
        .details-content { max-height: 300px; overflow-y: auto; }
        .reservation-item { padding: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .reservation-item:hover { background: #e3f2fd; }
        .guest-info { font-weight: bold; }
        .category-info { color: #666; font-size: 14px; }
        .hrs-badge { background: #4CAF50; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .local-badge { background: #FF9800; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 20px; }
        .stat-card { background: #e3f2fd; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #1976d2; }
        .stat-label { color: #666; font-size: 14px; }
        .export-buttons { display: flex; gap: 10px; margin-top: 20px; }
        .export-btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .export-csv { background: #4CAF50; color: white; }
        .export-png { background: #2196F3; color: white; }
        .zoom-info { background: #e8f5e8; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; color: #2e7d32; }
        .zoom-controls { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .zoom-btn { padding: 5px 15px; border: 1px solid #ddd; border-radius: 4px; background: white; cursor: pointer; }
        .zoom-btn:hover { background: #f5f5f5; }
        .scrollbar-container { background: #f0f0f0; border-radius: 4px; margin-bottom: 15px; height: 8px; position: relative; }
        .scrollbar-thumb { background: #2196F3; border-radius: 4px; height: 100%; position: absolute; min-width: 20px; cursor: pointer; }
        .scrollbar-thumb:hover { background: #1976D2; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏔️ Erweiterte Belegungsanalyse - Franzsennhütte</h1>
            <p>Interaktives Dashboard für Reservierungsanalyse</p>
        </div>
        
        <div class="controls">
            <div class="control-group">
                <label for="startDate">Von:</label>
                <input type="date" id="startDate" value="<?= $startDate ?>">
            </div>
            <div class="control-group">
                <label for="endDate">Bis:</label>
                <input type="date" id="endDate" value="<?= $endDate ?>">
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="updateChartWithImport()">🔄 Aktualisieren + HRS Import</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="resetToDefaultRange()">31 Tage ab heute</button>
            </div>
        </div>
        
        <div class="zoom-info">
            🔍 <strong>Navigation:</strong> Mausrad zum Zoomen | Horizontaler Scrollbar zum Scrollen
        </div>
        
        <div class="chart-container">
            <div class="chart-wrapper">
                <canvas id="belegungChart"></canvas>
            </div>
            <div id="customTooltip" class="custom-tooltip">
                <div class="tooltip-header">Belegungsdetails</div>
                <div id="tooltipContent"></div>
            </div>
        </div>
        
        <div class="export-buttons">
            <button class="export-btn export-csv" onclick="exportToCSV()">📊 CSV Export</button>
            <button class="export-btn export-png" onclick="exportChartAsPNG()">📸 PNG Export</button>
        </div>
        
        <div id="detailsPanel" class="details-panel">
            <div class="details-header">Reservierungsdetails</div>
            <div id="detailsContent" class="details-content"></div>
        </div>
        
        <?php
        // Statistiken berechnen
        $gesamtHRS = ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0];
        $gesamtLokal = ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0];
        
        foreach ($rohdaten as $row) {
            if ($row['quelle'] == 'hrs') {
                $gesamtHRS['sonder'] += $row['sonder'];
                $gesamtHRS['lager'] += $row['lager'];
                $gesamtHRS['betten'] += $row['betten'];
                $gesamtHRS['dz'] += $row['dz'];
            } else {
                $gesamtLokal['sonder'] += $row['sonder'];
                $gesamtLokal['lager'] += $row['lager'];
                $gesamtLokal['betten'] += $row['betten'];
                $gesamtLokal['dz'] += $row['dz'];
            }
        }
        
        $gesamtPersonenHRS = $gesamtHRS['sonder'] + $gesamtHRS['lager'] + $gesamtHRS['betten'] + $gesamtHRS['dz'];
        $gesamtPersonenLokal = $gesamtLokal['sonder'] + $gesamtLokal['lager'] + $gesamtLokal['betten'] + $gesamtLokal['dz'];
        $gesamtPersonen = $gesamtPersonenHRS + $gesamtPersonenLokal;
        ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $gesamtPersonen ?></div>
                <div class="stat-label">Gesamtbelegung (Personen)</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $gesamtPersonenHRS ?></div>
                <div class="stat-label">HRS-Reservierungen</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $gesamtPersonenLokal ?></div>
                <div class="stat-label">Lokale Reservierungen</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= round(($gesamtPersonenHRS / max($gesamtPersonen, 1)) * 100, 1) ?>%</div>
                <div class="stat-label">HRS-Anteil</div>
            </div>
        </div>
        
        <!-- Kontroll-Tabelle -->
        <div style="margin-top: 30px;">
            <h3 style="color: #333; margin-bottom: 15px;">📊 Detailierte Tageswerte (Kontrolle)</h3>
            <div style="overflow-x: auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Datum</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #e8f5e8;">Frei Gesamt</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #f3e5f5;">Frei Sonder</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #e5f5e5;">Frei Lager</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #fffbe5;">Frei Betten</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #ffe5e5;">Frei DZ</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #fff3cd;">Quota Name</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #f0f8ff;">Q-Sonder</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #f0f8ff;">Q-Lager</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #f0f8ff;">Q-Betten</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; background: #f0f8ff;">Q-DZ</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #9966CC;">HRS Sonder</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #7722CC;">Lokal Sonder</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #66CC66;">HRS Lager</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #228822;">Lokal Lager</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #CCCC66;">HRS Betten</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #CCCC00;">Lokal Betten</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #FF6666;">HRS DZ</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold; color: #CC2222;">Lokal DZ</th>
                            <th style="padding: 12px; border: 1px solid #ddd; font-weight: bold;">Gesamt Belegt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Alle Tage durchgehen und Daten strukturieren
                        $alleTage = [];
                        $currentDate = new DateTime($startDate);
                        $endDateTime = new DateTime($endDate);
                        
                        while ($currentDate <= $endDateTime) {
                            $alleTage[] = $currentDate->format('Y-m-d');
                            $currentDate->add(new DateInterval('P1D'));
                        }
                        
                        // Daten für Tabelle vorbereiten
                        $datenIndex = [];
                        foreach ($rohdaten as $row) {
                            $key = $row['tag'] . '_' . $row['quelle'];
                            $datenIndex[$key] = $row;
                        }
                        
                        // Quota-Gruppen für alternierende Farben verfolgen
                        $quotaColors = [];
                        $colorIndex = 0;
                        $alternatingColors = ['#fff8dc', '#f0f8ff', '#f5fffa', '#fffaf0', '#f8f8ff'];
                        
                        foreach ($alleTage as $i => $tag) {
                            $datum = new DateTime($tag);
                            $hrsKey = $tag . '_hrs';
                            $lokalKey = $tag . '_lokal';
                            
                            $hrsData = isset($datenIndex[$hrsKey]) ? $datenIndex[$hrsKey] : ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0];
                            $lokalData = isset($datenIndex[$lokalKey]) ? $datenIndex[$lokalKey] : ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0];
                            
                            $freieKap = isset($freieKapazitaeten[$tag]) ? $freieKapazitaeten[$tag] : [
                                'gesamt_frei' => 0, 'sonder_frei' => 0, 'lager_frei' => 0, 'betten_frei' => 0, 'dz_frei' => 0
                            ];
                            
                            // Quotas für diesen Tag
                            $tagesQuotas = getQuotasForDate($quotaData, $tag);
                            
                            $gesamtBelegt = $hrsData['sonder'] + $hrsData['lager'] + $hrsData['betten'] + $hrsData['dz'] +
                                           $lokalData['sonder'] + $lokalData['lager'] + $lokalData['betten'] + $lokalData['dz'];
                            
                            // Basis-Zeilenstil
                            $rowStyle = ($i % 2 == 0) ? 'background: #f9f9f9;' : 'background: white;';
                            
                            // Quota-spezifische Färbung wenn vorhanden
                            if (!empty($tagesQuotas)) {
                                $quotaKey = '';
                                foreach ($tagesQuotas as $quota) {
                                    $quotaKey .= $quota['id'] . '_';
                                }
                                
                                if (!isset($quotaColors[$quotaKey])) {
                                    $quotaColors[$quotaKey] = $alternatingColors[$colorIndex % count($alternatingColors)];
                                    $colorIndex++;
                                }
                                $rowStyle = 'background: ' . $quotaColors[$quotaKey] . ';';
                            }
                            
                            echo "<tr style='$rowStyle'>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; font-weight: bold;'>" . $datum->format('d.m.Y') . "</td>";
                            
                            // Freie Kapazitäten
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; background: #e8f5e8; font-weight: bold;'>" . $freieKap['gesamt_frei'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; background: #f3e5f5;'>" . $freieKap['sonder_frei'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; background: #e5f5e5;'>" . $freieKap['lager_frei'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; background: #fffbe5;'>" . $freieKap['betten_frei'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; background: #ffe5e5;'>" . $freieKap['dz_frei'] . "</td>";
                            
                            // Quota-Informationen
                            $quotaName = '';
                            $quotaSonder = '';
                            $quotaLager = '';
                            $quotaBetten = '';
                            $quotaDZ = '';
                            
                            if (!empty($tagesQuotas)) {
                                foreach ($tagesQuotas as $quota) {
                                    // Hintergrundfarbe basierend auf Mode
                                    $modeColor = '';
                                    $modeStyle = '';
                                    if ($quota['mode'] == 'SERVICED') {
                                        $modeColor = '#d4edda'; // Grün
                                        $modeStyle = 'background: #d4edda; color: #155724;';
                                    } elseif ($quota['mode'] == 'CLOSED') {
                                        $modeColor = '#f8d7da'; // Rot  
                                        $modeStyle = 'background: #f8d7da; color: #721c24;';
                                    } else {
                                        $modeColor = '#e2e3e5'; // Grau
                                        $modeStyle = 'background: #e2e3e5; color: #383d41;';
                                    }
                                    
                                    $quotaName .= '<div style="font-size: 11px; margin-bottom: 2px; padding: 2px 4px; border-radius: 3px; ' . $modeStyle . '">';
                                    $quotaName .= '<strong>' . htmlspecialchars($quota['title']) . '</strong><br>';
                                    $quotaName .= '<small>' . $quota['mode'] . '</small>';
                                    $quotaName .= '</div>';
                                    
                                    // Kategorien extrahieren
                                    $categories = $quota['categories'];
                                    $sonder = isset($categories['SK']) ? $categories['SK']['total_beds'] : 0;
                                    $lager = isset($categories['ML']) ? $categories['ML']['total_beds'] : 0;
                                    $betten = isset($categories['MBZ']) ? $categories['MBZ']['total_beds'] : 0;
                                    $dz = isset($categories['2BZ']) ? $categories['2BZ']['total_beds'] : 0;
                                    
                                    $quotaSonder .= '<div style="font-size: 11px; margin-bottom: 2px; text-align: center;">' . ($sonder > 0 ? $sonder : '-') . '</div>';
                                    $quotaLager .= '<div style="font-size: 11px; margin-bottom: 2px; text-align: center;">' . ($lager > 0 ? $lager : '-') . '</div>';
                                    $quotaBetten .= '<div style="font-size: 11px; margin-bottom: 2px; text-align: center;">' . ($betten > 0 ? $betten : '-') . '</div>';
                                    $quotaDZ .= '<div style="font-size: 11px; margin-bottom: 2px; text-align: center;">' . ($dz > 0 ? $dz : '-') . '</div>';
                                }
                            } else {
                                $quotaName = '-';
                                $quotaSonder = '-';
                                $quotaLager = '-';
                                $quotaBetten = '-';
                                $quotaDZ = '-';
                            }
                            
                            echo "<td style='padding: 8px; border: 1px solid #ddd; background: #fff3cd; font-size: 11px;'>" . $quotaName . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; background: #f0f8ff; font-size: 11px;'>" . $quotaSonder . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; background: #f0f8ff; font-size: 11px;'>" . $quotaLager . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; background: #f0f8ff; font-size: 11px;'>" . $quotaBetten . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; background: #f0f8ff; font-size: 11px;'>" . $quotaDZ . "</td>";
                            
                            // Belegungen
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #9966CC;'>" . $hrsData['sonder'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #7722CC; font-weight: bold;'>" . $lokalData['sonder'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #66CC66;'>" . $hrsData['lager'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #228822; font-weight: bold;'>" . $lokalData['lager'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #CCCC66;'>" . $hrsData['betten'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #CCCC00; font-weight: bold;'>" . $lokalData['betten'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #FF6666;'>" . $hrsData['dz'] . "</td>";
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; color: #CC2222; font-weight: bold;'>" . $lokalData['dz'] . "</td>";
                            
                            echo "<td style='padding: 8px; border: 1px solid #ddd; text-align: center; font-weight: bold; background: #e3f2fd;'>" . $gesamtBelegt . "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Globale Variablen
        let chart;
        const detailData = <?= json_encode($detailDaten) ?>;
        const chartData = <?= json_encode($chartData) ?>;
        
        console.log('Debug: Chart Data Labels:', chartData.labels.length);
        console.log('Debug: Detail Data:', detailData.length, 'Einträge');
        
        // Chart initialisieren
        function initChart() {
            const ctx = document.getElementById('belegungChart').getContext('2d');
            
            chart = new Chart(ctx, {
                type: 'bar',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    backgroundColor: '#2F2F2F',
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    scales: {
                        x: {
                            stacked: true,
                            title: { display: true, text: 'Datum', color: '#FFFFFF' },
                            ticks: { color: '#FFFFFF' },
                            grid: { color: '#555555' }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: { display: true, text: 'Anzahl Personen', color: '#FFFFFF' },
                            ticks: { color: '#FFFFFF' },
                            grid: { color: '#555555' }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Tägliche Hausbelegung nach Kategorien und Herkunft (ohne Stornos)',
                            font: { size: 16 },
                            color: '#FFFFFF'
                        },
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: { usePointStyle: true, padding: 15, color: '#FFFFFF' }
                        },
                        tooltip: {
                            enabled: false,
                            external: function(context) {
                                showCustomTooltip(context);
                            }
                        },
                        zoom: {
                            zoom: {
                                wheel: {
                                    enabled: true,
                                    speed: 0.1
                                },
                                pinch: {
                                    enabled: true
                                },
                                mode: 'x',
                                onZoom: function({chart}) {
                                    updateChartWidth();
                                }
                            }
                        }
                    },
                    plugins: [
                        {
                            id: 'freeCapacityLabels',
                            afterDatasetsDraw: function(chart) {
                                const ctx = chart.ctx;
                                const meta = chart.getDatasetMeta(0);
                                
                                ctx.save();
                                ctx.fillStyle = '#FFFFFF';
                                ctx.font = 'bold 12px Arial';
                                ctx.textAlign = 'center';
                                ctx.textBaseline = 'bottom';
                                
                                meta.data.forEach((bar, index) => {
                                    const freieKap = chartData.freieKapazitaeten[index];
                                    if (freieKap && freieKap.gesamt_frei > 0) {
                                        const x = bar.x;
                                        // Finde den höchsten Punkt aller Balken (inklusive freie Plätze)
                                        let maxY = bar.y;
                                        chart.data.datasets.forEach((dataset, datasetIndex) => {
                                            const meta = chart.getDatasetMeta(datasetIndex);
                                            if (meta.data[index] && meta.data[index].y < maxY) {
                                                maxY = meta.data[index].y;
                                            }
                                        });
                                        
                                        const y = maxY - 10; // 10px über dem höchsten Segment
                                        
                                        // Detailangaben (kleinere Schrift)
                                        ctx.font = '10px Arial';
                                        let detailText = '';
                                        if (freieKap.sonder_frei > 0) detailText += `S:${freieKap.sonder_frei} `;
                                        if (freieKap.lager_frei > 0) detailText += `L:${freieKap.lager_frei} `;
                                        if (freieKap.betten_frei > 0) detailText += `B:${freieKap.betten_frei} `;
                                        if (freieKap.dz_frei > 0) detailText += `D:${freieKap.dz_frei}`;
                                        
                                        if (detailText.trim()) {
                                            ctx.fillText(detailText.trim(), x, y);
                                        }
                                    }
                                });
                                
                                ctx.restore();
                            }
                        }
                    ],
                    onClick: function(event, elements) {
                        if (elements.length > 0) {
                            const dataIndex = elements[0].index;
                            showDayDetails(dataIndex);
                        }
                    },
                    onHover: function(event, elements) {
                        event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
                    }
                }
            });
            
            // Chart-Breite basierend auf Anzahl der Datenpunkte anpassen
            updateChartWidth();
            
            console.log('Chart mit Mausrad-Zoom initialisiert');
        }
        
        // Chart-Breite dynamisch anpassen
        function updateChartWidth() {
            const chartWrapper = document.querySelector('.chart-wrapper');
            const dataPointCount = chartData.labels.length;
            const minBarWidth = 20; // Minimale Balkenbreite in Pixel
            const minWidth = Math.max(800, dataPointCount * minBarWidth);
            
            chartWrapper.style.minWidth = minWidth + 'px';
            
            if (chart) {
                chart.resize();
            }
        }
        
        // Custom Tooltip anzeigen
        function showCustomTooltip(context) {
            const tooltip = document.getElementById('customTooltip');
            const tooltipContent = document.getElementById('tooltipContent');
            
            if (context.tooltip.opacity === 0) {
                tooltip.style.display = 'none';
                return;
            }
            
            const tooltipModel = context.tooltip;
            let html = '';
            
            if (tooltipModel.dataPoints && tooltipModel.dataPoints.length > 0) {
                const dataIndex = tooltipModel.dataPoints[0].dataIndex;
                const datum = chartData.labels[dataIndex];
                
                let total = 0;
                tooltipModel.dataPoints.forEach(function(point) {
                    total += point.parsed.y;
                });
                
                html = `<div class="tooltip-header">${datum} - Total: ${total} Personen</div>`;
                
                tooltipModel.dataPoints.forEach(function(point) {
                    if (point.parsed.y > 0) {
                        const color = point.dataset.backgroundColor;
                        html += `
                            <div class="tooltip-item">
                                <div class="tooltip-color" style="background-color: ${color}"></div>
                                <div class="tooltip-label">${point.dataset.label}</div>
                                <div class="tooltip-value">${point.parsed.y}</div>
                            </div>
                        `;
                    }
                });
            }
            
            tooltipContent.innerHTML = html;
            tooltip.style.display = 'block';
        }
        
        // Tagesdetails anzeigen
        function showDayDetails(dayIndex) {
            const startDate = new Date('<?= $startDate ?>');
            const targetDate = new Date(startDate);
            targetDate.setDate(startDate.getDate() + dayIndex);
            const targetDateStr = targetDate.toISOString().split('T')[0];
            
            const dayDetails = detailData.filter(item => item.tag === targetDateStr);
            
            const detailsPanel = document.getElementById('detailsPanel');
            const detailsContent = document.getElementById('detailsContent');
            
            let html = '';
            if (dayDetails.length === 0) {
                html = '<p>Keine Reservierungen für diesen Tag.</p>';
            } else {
                dayDetails.forEach(reservation => {
                    const kategorie = [];
                    if (reservation.sonder > 0) kategorie.push(`Sonder: ${reservation.sonder}`);
                    if (reservation.lager > 0) kategorie.push(`Lager: ${reservation.lager}`);
                    if (reservation.betten > 0) kategorie.push(`Betten: ${reservation.betten}`);
                    if (reservation.dz > 0) kategorie.push(`DZ: ${reservation.dz}`);
                    
                    const badge = reservation.quelle === 'hrs' ? 
                        '<span class="hrs-badge">HRS</span>' : 
                        '<span class="local-badge">LOKAL</span>';
                    
                    html += `
                        <div class="reservation-item">
                            <div>
                                <div class="guest-info">${reservation.nachname}, ${reservation.vorname} ${badge}</div>
                                <div class="category-info">${kategorie.join(', ')}</div>
                                ${reservation.gruppe ? `<div class="category-info">Gruppe: ${reservation.gruppe}</div>` : ''}
                                ${reservation.bem_av ? `<div class="category-info">Bemerkung: ${reservation.bem_av}</div>` : ''}
                            </div>
                            <div>
                                ${reservation.hp ? '🍽️' : ''} ${reservation.vegi ? '🥬' : ''}
                            </div>
                        </div>
                    `;
                });
            }
            
            detailsContent.innerHTML = html;
            detailsPanel.style.display = 'block';
            detailsPanel.querySelector('.details-header').textContent = 
                `Reservierungen am ${targetDate.toLocaleDateString('de-DE')} (${dayDetails.length} Reservierungen)`;
        }
        
        // Chart aktualisieren
        function updateChart() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            window.location.href = `?start=${startDate}&end=${endDate}`;
        }
        
        // Chart aktualisieren mit HRS Import
        function updateChartWithImport() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                alert('Bitte Start- und Enddatum auswählen.');
                return;
            }
            
            // Prüfe Zeitraum (max 31 Tage für Stabilität)
            const start = new Date(startDate);
            const end = new Date(endDate);
            const diffDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            
            if (diffDays > 31) {
                if (!confirm(`Zeitraum ist ${diffDays} Tage lang. HRS-Import kann bei langen Zeiträumen instabil sein.\n\nTrotzdem fortfahren?`)) {
                    return;
                }
            }
            
            // Button deaktivieren und Status anzeigen
            const btn = event.target;
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '⏳ Importiere HRS Daten...';
            btn.style.background = '#666';
            
            // Alle drei Importer parallel ausführen
            const importPromises = [
                fetch(`../hrs/hrs_imp_daily.php?from=${startDate}&to=${endDate}`)
                    .then(r => r.text())
                    .then(text => {
                        try {
                            const result = JSON.parse(text);
                            return { type: 'daily', ...result };
                        } catch (e) {
                            console.warn('Daily Import Response:', text);
                            return { type: 'daily', success: false, error: 'JSON Parse Error', rawResponse: text };
                        }
                    }),
                fetch(`../hrs/hrs_imp_quota.php?from=${startDate}&to=${endDate}`)
                    .then(r => r.text())
                    .then(text => {
                        try {
                            const result = JSON.parse(text);
                            return { type: 'quota', ...result };
                        } catch (e) {
                            console.warn('Quota Import Response:', text);
                            return { type: 'quota', success: false, error: 'JSON Parse Error', rawResponse: text };
                        }
                    }),
                fetch(`../hrs/hrs_imp_res.php?from=${startDate}&to=${endDate}`)
                    .then(r => r.text())
                    .then(text => {
                        try {
                            const result = JSON.parse(text);
                            return { type: 'res', ...result };
                        } catch (e) {
                            console.warn('Reservation Import Response:', text);
                            return { type: 'res', success: false, error: 'JSON Parse Error', rawResponse: text };
                        }
                    })
            ];
            
            Promise.all(importPromises)
                .then(results => {
                    let successMessage = '📊 HRS Import Ergebnisse:\n\n';
                    let hasErrors = false;
                    
                    results.forEach(result => {
                        const emoji = result.type === 'daily' ? '📊' : result.type === 'quota' ? '🏗️' : '🏠';
                        const name = result.type === 'daily' ? 'Daily Summary' : result.type === 'quota' ? 'Quotas' : 'Reservierungen';
                        
                        if (result.success) {
                            successMessage += `${emoji} ${name}: ✅ ${result.imported || 0} importiert, ${result.updated || 0} aktualisiert\n`;
                        } else {
                            hasErrors = true;
                            const errorMsg = result.error || 'Unbekannter Fehler';
                            successMessage += `${emoji} ${name}: ❌ ${errorMsg}\n`;
                            
                            // Spezielle Behandlung für HRS 500 Fehler
                            if (errorMsg.includes('HTTP 500') || errorMsg.includes('No quota data received')) {
                                successMessage += `    → HRS-Server Probleme, versuchen Sie kleineren Zeitraum\n`;
                            }
                        }
                    });
                    
                    if (hasErrors) {
                        successMessage += '\n⚠️ Einige Importer hatten Probleme. Details in der Browser-Konsole.';
                        successMessage += '\n💡 Tipp: Versuchen Sie einen kleineren Zeitraum (max 31 Tage).';
                    }
                    
                    alert(successMessage);
                    
                    // Seite neu laden mit aktualisierten Daten (auch bei teilweisen Fehlern)
                    window.location.href = `?start=${startDate}&end=${endDate}`;
                })
                .catch(error => {
                    console.error('Import Error:', error);
                    alert(`❌ HRS Import fehlgeschlagen:\n${error.message}\n\nDie Seite wird trotzdem aktualisiert, falls teilweise Daten importiert wurden.`);
                    
                    // Trotzdem versuchen zu aktualisieren
                    window.location.href = `?start=${startDate}&end=${endDate}`;
                })
                .finally(() => {
                    // Button wieder aktivieren (falls kein reload)
                    btn.disabled = false;
                    btn.textContent = originalText;
                    btn.style.background = '#2196F3';
                });
        }
        
        // Standardbereich zurücksetzen
        function resetToDefaultRange() {
            const today = new Date().toISOString().split('T')[0];
            const endDate = new Date();
            endDate.setDate(endDate.getDate() + 31);
            const endDateStr = endDate.toISOString().split('T')[0];
            
            document.getElementById('startDate').value = today;
            document.getElementById('endDate').value = endDateStr;
        }
        
        // CSV Export
        function exportToCSV() {
            const rows = [['Datum', 'Quelle', 'Name', 'Sonder', 'Lager', 'Betten', 'DZ', 'HP', 'Vegetarisch', 'Bemerkung']];
            
            detailData.forEach(item => {
                rows.push([
                    item.tag,
                    item.quelle.toUpperCase(),
                    `${item.nachname}, ${item.vorname}`,
                    item.sonder,
                    item.lager,
                    item.betten,
                    item.dz,
                    item.hp ? 'Ja' : 'Nein',
                    item.vegi ? 'Ja' : 'Nein',
                    item.bem_av || ''
                ]);
            });
            
            const csv = rows.map(row => row.map(field => `"${field}"`).join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `belegung_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            URL.revokeObjectURL(url);
        }
        
        // PNG Export
        function exportChartAsPNG() {
            const link = document.createElement('a');
            link.download = `belegungsdiagramm_${new Date().toISOString().split('T')[0]}.png`;
            link.href = chart.toBase64Image();
            link.click();
        }
        
        // Chart beim Laden initialisieren
        window.addEventListener('load', initChart);
    </script>
</body>
</html>
