<?php
/**
 * Belegungsanalyse - Nur Tabelle
 * Erweiterte Belegungsanalyse ohne Chart-Funktionalität
 */

require_once '../config.php';

// Zeitraum: heute + 31 Tage (kann später parametrisiert werden)
$startDate = $_GET['start'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime($startDate . ' +31 days'));

/**
 * Hausbelegung berechnen
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
        
        // Aggregierte Daten für Chart
        $tagesDaten = ['hrs' => [], 'lokal' => []];
        $aggregierung = ['hrs' => ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0], 'lokal' => ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0]];
        
        while ($row = $result->fetch_assoc()) {
            $quelle = $row['quelle'];
            $tagesDaten[$quelle][] = $row;
            
            // Aggregierung
            $key = $tag . '_' . $quelle;
            if (!isset($aggregatedData[$key])) {
                $aggregatedData[$key] = [
                    'tag' => $tag,
                    'quelle' => $quelle,
                    'sonder' => 0,
                    'lager' => 0,
                    'betten' => 0,
                    'dz' => 0,
                    'reservierungen' => []
                ];
            }
            
            $aggregatedData[$key]['sonder'] += (int)$row['sonder'];
            $aggregatedData[$key]['lager'] += (int)$row['lager'];
            $aggregatedData[$key]['betten'] += (int)$row['betten'];
            $aggregatedData[$key]['dz'] += (int)$row['dz'];
            $aggregatedData[$key]['reservierungen'][] = $row;
        }
        
        // Detaildaten für spätere Verwendung
        $detailDaten[] = [
            'tag' => $tag,
            'datum_formatted' => date('D d.m.Y', strtotime($tag)),
            'hrs' => $tagesDaten['hrs'],
            'lokal' => $tagesDaten['lokal'],
            'freie_plaetze' => $freieKapazitaeten[$tag]['gesamt_frei'] ?? 0
        ];
    }
    
    return [
        'detail' => $detailDaten,
        'aggregated' => array_values($aggregatedData),
        'freieKapazitaeten' => $freieKapazitaeten
    ];
}

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

function getQuotaData($mysqli, $startDate, $endDate) {
    $quotas = [];
    
    // Debug-Ausgabe
    if (isset($_GET['debug'])) {
        echo "<!-- DEBUG getQuotaData: Suche Quotas von $startDate bis $endDate -->\n";
    }
    
    // Lade alle Quotas die in den Zeitraum fallen oder ihn überschneiden
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
            WHERE hq.date_from <= ? AND hq.date_to > ?
            ORDER BY hq.date_from, hq.title, hqc.category_id";
    
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ss', $endDate, $startDate);
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
    
    // Debug-Ausgabe der gefundenen Quotas
    if (isset($_GET['debug'])) {
        echo "<!-- DEBUG: Gefundene Quotas in DB-Abfrage: " . count($quotas) . "\n";
        foreach ($quotas as $i => $quota) {
            echo "  Quota $i: {$quota['title']} von {$quota['date_from']} bis {$quota['date_to']}\n";
        }
        echo "-->\n";
    }
    
    return array_values($quotas);
}

function getQuotasForDate($quotas, $date) {
    $matching = [];
    foreach ($quotas as $quota) {
        // Quota gilt für Nächte: date_from bis date_to (exklusiv)
        // Quota vom 1.8. bis 2.8. gilt nur für 1.8. (Nacht vom 1.8. auf 2.8.)
        if ($date >= $quota['date_from'] && $date < $quota['date_to']) {
            $matching[] = $quota;
        }
    }
    
    // Debug: Wenn es der erste Tag ist, zeige alle verfügbaren Quotas
    if (isset($_GET['debug']) && $date == $_GET['start']) {
        echo "<!-- DEBUG für Datum $date:\n";
        echo "Alle Quotas:\n";
        foreach ($quotas as $i => $quota) {
            echo "Quota $i: {$quota['title']} von {$quota['date_from']} bis {$quota['date_to']}\n";
            echo "  Bedingung: $date >= {$quota['date_from']} && $date < {$quota['date_to']}\n";
            echo "  Ergebnis: " . (($date >= $quota['date_from'] && $date < $quota['date_to']) ? 'MATCH' : 'NO MATCH') . "\n";
        }
        echo "Gefundene Quotas: " . count($matching) . "\n";
        if (!empty($matching)) {
            foreach ($matching as $m) {
                echo "  - {$m['title']} von {$m['date_from']} bis {$m['date_to']}\n";
            }
        }
        echo "-->\n";
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

// Daten laden
$resultSet = getErweiterteGelegungsDaten($mysqli, $startDate, $endDate);
$detailDaten = $resultSet['detail'];
$rohdaten = $resultSet['aggregated'];
$freieKapazitaeten = $resultSet['freieKapazitaeten'];

// Quota-Daten laden
$quotaData = getQuotaData($mysqli, $startDate, $endDate);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Belegungsanalyse - Tabellenansicht</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
        .export-buttons { margin: 20px 0; text-align: center; }
        .export-btn { 
            padding: 10px 20px; 
            margin: 0 10px; 
            border: none; 
            border-radius: 6px; 
            font-size: 14px; 
            cursor: pointer; 
            transition: all 0.3s ease;
        }
        .export-excel { background: #4CAF50; color: white; }
        .btn-import { 
            padding: 8px 15px; 
            background: #2196F3; 
            color: white; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-import:hover { background: #1976D2; }
        .btn-import:disabled { background: #ccc; cursor: not-allowed; }
        
        .progress-container {
            display: none;
            margin-top: 10px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e3e6ea;
        }
        
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            position: relative;
        }
        
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            position: relative;
        }
        
        .progress-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 12px;
            left: 50%;
            right: -50%;
            height: 2px;
            background: #ddd;
            z-index: 0;
        }
        
        .progress-step.active::after,
        .progress-step.completed::after {
            background: #2196F3;
        }
        
        .step-circle {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #ddd;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 5px;
            position: relative;
            z-index: 1;
        }
        
        .progress-step.active .step-circle {
            background: #2196F3;
            animation: pulse 1.5s infinite;
        }
        
        .progress-step.completed .step-circle {
            background: #4CAF50;
        }
        
        .progress-step.completed .step-circle::before {
            content: '✓';
            font-size: 14px;
        }
        
        .step-label {
            font-size: 11px;
            color: #666;
            font-weight: 500;
            text-align: center;
        }
        
        .step-result {
            font-size: 10px;
            color: #888;
            text-align: center;
            margin-top: 2px;
            min-height: 12px;
        }
        
        .progress-step.completed .step-result {
            color: #4CAF50;
            font-weight: bold;
        }
        
        .progress-step.active .step-label {
            color: #2196F3;
            font-weight: bold;
        }
        
        .progress-step.completed .step-label {
            color: #4CAF50;
        }
        
        .progress-status {
            text-align: center;
            font-size: 13px;
            color: #666;
            margin-top: 10px;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .table-container {
            height: 70vh;
            overflow-y: auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #ddd;
        }
        
        .sticky-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .sticky-table thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #f5f5f5;
            padding: 12px;
            border: 1px solid #ddd;
            font-weight: bold;
        }
        
        .sticky-table tbody td {
            /* Basis-Styling für alle Zellen - wird von spezifischen Klassen überschrieben */
        }
        
        /* Spezielle Zell-Klassen für verschiedene Datentypen */
        .cell-base {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: center;
        }
        
        .cell-datum {
            font-weight: bold;
            cursor: pointer;
        }
        
        .cell-weekend {
            color: #cc6600;
            font-weight: 900;
        }
        
        .cell-frei-gesamt {
            font-weight: bold;
            background: #e8f5e8;
        }
        
        .cell-frei-sonder {
            background: #f3e5f5;
        }
        
        .cell-frei-lager {
            background: #e5f5e5;
        }
        
        .cell-frei-betten {
            background: #fffbe5;
        }
        
        .cell-frei-dz {
            background: #ffe5e5;
        }
        
        .cell-quota-name {
            background: #fff3cd;
            font-size: 12px;
            vertical-align: middle;
        }
        
        .cell-quota-data {
            background: #f0f8ff;
            vertical-align: middle;
        }
        
        .cell-hrs-sonder {
            color: #9966CC;
        }
        
        .cell-lokal-sonder {
            color: #7722CC;
        }
        
        .cell-hrs-lager {
            color: #66CC66;
        }
        
        .cell-lokal-lager {
            color: #228822;
        }
        
        .cell-hrs-betten {
            color: #CCCC66;
        }
        
        .cell-lokal-betten {
            color: #CCCC00;
        }
        
        .cell-hrs-dz {
            color: #FF6666;
        }
        
        .cell-lokal-dz {
            color: #CC2222;
        }
        
        .cell-gesamt {
            font-weight: bold;
        }
        .export-excel:hover { background: #45a049; }
        .details-panel {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            max-width: 1200px;
            max-height: 80vh;
            background: white;
            border: 2px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            display: none;
            z-index: 1000;
            overflow: hidden;
        }
        .details-header {
            background: #f5f5f5;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .close-btn {
            background: #ff4444;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 15px;
            cursor: pointer;
            font-size: 14px;
        }
        .close-btn:hover { background: #cc0000; }
        .details-content {
            padding: 20px;
            max-height: calc(80vh - 80px);
            overflow-y: auto;
        }
        .legend {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Belegungsanalyyse - Tabellenansicht</h1>
            <p>Zeitraum: <?= date('d.m.Y', strtotime($startDate)) ?> bis <?= date('d.m.Y', strtotime($endDate)) ?></p>
        </div>
        
        <div class="controls">
            <div class="control-group">
                <label for="startDate">Startdatum:</label>
                <input type="date" id="startDate" value="<?= $startDate ?>">
            </div>
            <div class="control-group">
                <label for="endDate">Enddatum:</label>
                <input type="date" id="endDate" value="<?= $endDate ?>">
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="updateData()">🔄 Aktualisieren</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="importHRSData()" class="btn-import" id="importBtn">📥 HRS Import</button>
            </div>
        </div>
        
        <!-- Progress Indicator -->
        <div id="progressContainer" class="progress-container">
            <div class="progress-steps">
                <div class="progress-step" id="step1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Reservierungen</div>
                    <div class="step-result" id="step1Result"></div>
                </div>
                <div class="progress-step" id="step2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Daily Summaries</div>
                    <div class="step-result" id="step2Result"></div>
                </div>
                <div class="progress-step" id="step3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Quotas</div>
                    <div class="step-result" id="step3Result"></div>
                </div>
            </div>
            <div class="progress-status" id="progressStatus">Vorbereitung...</div>
        </div>

        <div class="export-buttons">
            <button class="export-btn export-excel" onclick="exportToExcel()">� Excel Export</button>
        </div>
        
        <!-- Kontroll-Tabelle -->
        <div style="margin-top: 30px;">
            <h3 style="color: #333; margin-bottom: 15px;">📊 Detailierte Tageswerte</h3>
            <div class="table-container">
                <table class="sticky-table">
                    <thead>
                        <tr>
                            <th style="background: #f5f5f5;">Datum</th>
                            <th style="background: #e8f5e8;">Frei Gesamt</th>
                            <th style="background: #d4edda;">Frei Quotas</th>
                            <th style="background: #f3e5f5;">Frei Sonder</th>
                            <th style="background: #e5f5e5;">Frei Lager</th>
                            <th style="background: #fffbe5;">Frei Betten</th>
                            <th style="background: #ffe5e5;">Frei DZ</th>
                            <th style="background: #fff3cd;">Quota Name</th>
                            <th style="background: #f0f8ff;">Q-Sonder</th>
                            <th style="background: #f0f8ff;">Q-Lager</th>
                            <th style="background: #f0f8ff;">Q-Betten</th>
                            <th style="background: #f0f8ff;">Q-DZ</th>
                            <th style="color: #9966CC;">HRS Sonder</th>
                            <th style="color: #7722CC;">Lokal Sonder</th>
                            <th style="color: #66CC66;">HRS Lager</th>
                            <th style="color: #228822;">Lokal Lager</th>
                            <th style="color: #CCCC66;">HRS Betten</th>
                            <th style="color: #CCCC00;">Lokal Betten</th>
                            <th style="color: #FF6666;">HRS DZ</th>
                            <th style="color: #CC2222;">Lokal DZ</th>
                            <th style="background: #f5f5f5;">Gesamt Belegt</th>
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
                        
                        // Quota-Spans vorberechnen (für rowspan)
                        $quotaSpans = [];
                        $processedQuotas = [];
                        
                        foreach ($alleTage as $i => $tag) {
                            $tagesQuotas = getQuotasForDate($quotaData, $tag);
                            if (!empty($tagesQuotas)) {
                                $quota = $tagesQuotas[0];
                                $quotaKey = $quota['id'] . '_' . $quota['date_from'] . '_' . $quota['date_to'];
                                
                                if (!isset($processedQuotas[$quotaKey])) {
                                    // Berechne wie viele Nächte diese Quota abdeckt
                                    // Quota vom 1.8. bis 2.8. = nur 1 Nacht (1.8.)
                                    // Quota vom 1.8. bis 5.8. = 4 Nächte (1.8., 2.8., 3.8., 4.8.)
                                    $quotaStart = max($quota['date_from'], $startDate);
                                    $quotaEnd = min($quota['date_to'], $endDate);
                                    
                                    $start = new DateTime($quotaStart);
                                    $end = new DateTime($quotaEnd);
                                    $interval = $start->diff($end);
                                    $quotaSpanDays = $interval->days; // NICHT +1, da Endtag nicht eingeschlossen
                                    
                                    // Finde den ersten Tag dieser Quota in unserer Liste
                                    $firstDayIndex = array_search($quotaStart, $alleTage);
                                    if ($firstDayIndex !== false && $quotaSpanDays > 0) {
                                        $quotaSpans[$firstDayIndex] = $quotaSpanDays;
                                        $processedQuotas[$quotaKey] = $firstDayIndex;
                                    }
                                }
                            }
                        }
                        
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
                            
                            // Quota-Hintergrundfarbe bestimmen
                            $quotaName = '';
                            $quotaSonder = $quotaLager = $quotaBetten = $quotaDz = '';
                            $freieQuotas = 0;
                            
                            // Wochenende erkennen (für Datum-Formatierung)
                            $dayOfWeek = $datum->format('w');
                            $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
                            
                            // Normale Zebrastreifen ohne Quota-Färbung
                            $rowBgColor = ($i % 2 == 0) ? '#f9f9f9' : 'white';
                            
                            if (!empty($tagesQuotas)) {
                                $quota = $tagesQuotas[0]; // Erste Quota nehmen
                                $quotaName = $quota['title'];
                                
                                // Quota-Zahlen aus Kategorien extrahieren
                                if (!empty($quota['categories'])) {
                                    $quotaSonder = isset($quota['categories']['SK']) ? $quota['categories']['SK']['total_beds'] : 0;
                                    $quotaLager = isset($quota['categories']['ML']) ? $quota['categories']['ML']['total_beds'] : 0;
                                    $quotaBetten = isset($quota['categories']['MBZ']) ? $quota['categories']['MBZ']['total_beds'] : 0;
                                    $quotaDz = isset($quota['categories']['2BZ']) ? $quota['categories']['2BZ']['total_beds'] : 0;
                                    
                                    // Freie Quotas berechnen: max(0, Quota - HRS_belegt) pro Kategorie
                                    $freieQuotas = max(0, $quotaSonder - $hrsData['sonder']) +
                                                  max(0, $quotaLager - $hrsData['lager']) +
                                                  max(0, $quotaBetten - $hrsData['betten']) +
                                                  max(0, $quotaDz - $hrsData['dz']);
                                }
                                
                                // Für Anzeige: leere Werte als '' darstellen
                                $quotaSonder = $quotaSonder > 0 ? $quotaSonder : '';
                                $quotaLager = $quotaLager > 0 ? $quotaLager : '';
                                $quotaBetten = $quotaBetten > 0 ? $quotaBetten : '';
                                $quotaDz = $quotaDz > 0 ? $quotaDz : '';
                            }
                            
                            echo '<tr style="background: ' . $rowBgColor . ';" onclick="showDayDetails(' . $i . ')">';
                            
                            // Datum mit Wochenend-Kennzeichnung
                            $datumClasses = 'cell-base cell-datum';
                            $datumText = $datum->format('D d.m.Y');
                            
                            if ($isWeekend) {
                                $datumClasses .= ' cell-weekend';
                                $datumText = '🏖️ ' . $datumText; // Emoji für Wochenende
                            }
                            
                            echo '<td class="' . $datumClasses . '">' . $datumText . '</td>';
                            
                            // Freie Kapazitäten
                            echo '<td class="cell-base cell-frei-gesamt">' . 
                                 $freieKap['gesamt_frei'] . '</td>';
                            
                            // Frei Quotas mit rotem Hintergrund wenn ungleich Frei Gesamt
                            $quotasBgColor = '#d4edda'; // Standard grün
                            if ($freieQuotas != $freieKap['gesamt_frei']) {
                                $quotasBgColor = '#f8d7da'; // Rot wenn Quotas ≠ Frei Gesamt
                            }
                            echo '<td class="cell-base" style="background: ' . $quotasBgColor . '; font-weight: bold;">' . 
                                 $freieQuotas . '</td>';
                            echo '<td class="cell-base cell-frei-sonder">' . 
                                 $freieKap['sonder_frei'] . '</td>';
                            echo '<td class="cell-base cell-frei-lager">' . 
                                 $freieKap['lager_frei'] . '</td>';
                            echo '<td class="cell-base cell-frei-betten">' . 
                                 $freieKap['betten_frei'] . '</td>';
                            echo '<td class="cell-base cell-frei-dz">' . 
                                 $freieKap['dz_frei'] . '</td>';
                                 
                            // Quota-Informationen mit rowspan
                            // Prüfe ob dies der erste Tag einer mehrtägigen Quota ist
                            $isFirstQuotaDay = isset($quotaSpans[$i]);
                            $isQuotaContinuation = false;
                            
                            // Prüfe ob dies ein Fortsetzungstag einer Quota ist
                            if (!$isFirstQuotaDay && !empty($tagesQuotas)) {
                                $currentQuota = $tagesQuotas[0];
                                foreach ($quotaSpans as $firstDayIndex => $spanDays) {
                                    if ($i > $firstDayIndex && $i <= $firstDayIndex + $spanDays - 1) {
                                        // Dies ist ein Fortsetzungstag, überspringe Quota-Zellen
                                        $isQuotaContinuation = true;
                                        break;
                                    }
                                }
                            }
                            
                            if (!$isQuotaContinuation) {
                                $rowspanAttr = $isFirstQuotaDay ? ' rowspan="' . $quotaSpans[$i] . '"' : '';
                                
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-name">' . 
                                     htmlspecialchars($quotaName) . '</td>';
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-data">' . $quotaSonder . '</td>';
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-data">' . $quotaLager . '</td>';
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-data">' . $quotaBetten . '</td>';
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-data">' . $quotaDz . '</td>';
                            }
                            
                            // Belegungszahlen
                            echo '<td class="cell-base cell-hrs-sonder">' . $hrsData['sonder'] . '</td>';
                            echo '<td class="cell-base cell-lokal-sonder">' . $lokalData['sonder'] . '</td>';
                            echo '<td class="cell-base cell-hrs-lager">' . $hrsData['lager'] . '</td>';
                            echo '<td class="cell-base cell-lokal-lager">' . $lokalData['lager'] . '</td>';
                            echo '<td class="cell-base cell-hrs-betten">' . $hrsData['betten'] . '</td>';
                            echo '<td class="cell-base cell-lokal-betten">' . $lokalData['betten'] . '</td>';
                            echo '<td class="cell-base cell-hrs-dz">' . $hrsData['dz'] . '</td>';
                            echo '<td class="cell-base cell-lokal-dz">' . $lokalData['dz'] . '</td>';
                            
                            echo '<td class="cell-base cell-gesamt">' . $gesamtBelegt . '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Wochenend-Legende -->
            <div style="margin-top: 15px; padding: 10px; background: #f9f9f9; border-radius: 5px; font-size: 12px;">
                <strong>🗓️ Legende:</strong>
                <span style="margin-left: 20px;">🏖️ <span style="color: #cc6600; font-weight: bold;">Orange Datum</span> = Wochenende (Sa/So)</span>
                <span style="margin-left: 20px;">📋 Verbundene Zellen = Mehrtägige Quotas</span>
                <span style="margin-left: 20px;">🎨 Verschiedene Hintergrundfarben = Unterschiedliche Quota-Perioden</span>
            </div>
        </div>
        
        <div id="detailsPanel" class="details-panel">
            <div class="details-header">
                Reservierungsdetails
                <button class="close-btn" onclick="hideDetailsPanel()">✕ Schließen</button>
            </div>
            <div class="details-content" id="detailsContent">
                <!-- Details werden hier eingefügt -->
            </div>
        </div>
    </div>

    <script>
        // Daten für JavaScript verfügbar machen
        const detailData = <?= json_encode($detailDaten) ?>;
        
        function updateData() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (startDate && endDate) {
                window.location.href = `?start=${startDate}&end=${endDate}`;
            }
        }
        
        async function importHRSData() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const importBtn = document.getElementById('importBtn');
            const progressContainer = document.getElementById('progressContainer');
            const progressStatus = document.getElementById('progressStatus');
            
            if (!startDate || !endDate) {
                alert('Bitte Start- und Enddatum auswählen!');
                return;
            }
            
            // UI Setup
            importBtn.disabled = true;
            importBtn.textContent = '⏳ Importiere...';
            progressContainer.style.display = 'block';
            
            // Reset progress steps
            ['step1', 'step2', 'step3'].forEach(id => {
                const step = document.getElementById(id);
                step.classList.remove('active', 'completed');
                document.getElementById(id + 'Result').textContent = '';
            });
            
            try {
                // Step 1: Reservierungen importieren
                document.getElementById('step1').classList.add('active');
                progressStatus.textContent = 'Importiere Reservierungen...';
                console.log('Starte Reservierungen Import...');
                
                const resResponse = await fetch(`/wci/hrs/hrs_imp_res.php?from=${startDate}&to=${endDate}&json=1`);
                if (!resResponse.ok) {
                    throw new Error(`Reservierungen API Fehler: ${resResponse.status}`);
                }
                const resText = await resResponse.text();
                console.log('Reservierungen Antwort:', resText);
                const resData = JSON.parse(resText);
                console.log('Reservierungen:', resData);
                
                document.getElementById('step1').classList.remove('active');
                document.getElementById('step1').classList.add('completed');
                document.getElementById('step1Result').textContent = resData.success ? 'Erfolg!' : 'Fehler';
                
                // Step 2: Daily Summaries importieren
                document.getElementById('step2').classList.add('active');
                progressStatus.textContent = 'Importiere Daily Summaries...';
                console.log('Starte Daily Summaries Import...');
                
                const dailyResponse = await fetch(`/wci/hrs/hrs_imp_daily.php?from=${startDate}&to=${endDate}&json=1`);
                if (!dailyResponse.ok) {
                    throw new Error(`Daily Summaries API Fehler: ${dailyResponse.status}`);
                }
                const dailyText = await dailyResponse.text();
                console.log('Daily Summaries Antwort:', dailyText);
                const dailyData = JSON.parse(dailyText);
                console.log('Daily Summaries:', dailyData);
                
                document.getElementById('step2').classList.remove('active');
                document.getElementById('step2').classList.add('completed');
                document.getElementById('step2Result').textContent = dailyData.success ? 'Erfolg!' : 'Fehler';
                
                // Step 3: Quotas importieren
                document.getElementById('step3').classList.add('active');
                progressStatus.textContent = 'Importiere Quotas...';
                console.log('Starte Quotas Import...');
                
                const quotaResponse = await fetch(`/wci/hrs/hrs_imp_quota.php?from=${startDate}&to=${endDate}&json=1`);
                if (!quotaResponse.ok) {
                    throw new Error(`Quotas API Fehler: ${quotaResponse.status}`);
                }
                const quotaText = await quotaResponse.text();
                console.log('Quotas Antwort:', quotaText);
                const quotaData = JSON.parse(quotaText);
                console.log('Quotas:', quotaData);
                
                document.getElementById('step3').classList.remove('active');
                document.getElementById('step3').classList.add('completed');
                document.getElementById('step3Result').textContent = quotaData.success ? 'Erfolg!' : 'Fehler';
                progressStatus.textContent = 'Import erfolgreich abgeschlossen! ✅';
                
                setTimeout(() => {
                    alert('✅ HRS Import erfolgreich abgeschlossen!\\n\\n' +
                          `Reservierungen: ${resData.message || 'OK'}\\n` +
                          `Daily Summaries: ${dailyData.message || 'OK'}\\n` +
                          `Quotas: ${quotaData.message || 'OK'}`);
                    
                    // Seite neu laden um neue Daten anzuzeigen
                    updateData();
                }, 500);
                
            } catch (error) {
                console.error('Import Fehler:', error);
                progressStatus.textContent = 'Fehler beim Import ❌';
                alert('❌ Fehler beim HRS Import: ' + error.message);
            } finally {
                importBtn.disabled = false;
                importBtn.textContent = '📥 HRS Import';
                setTimeout(() => {
                    progressContainer.style.display = 'none';
                }, 3000);
            }
        }
        
        function exportToExcel() {
            // Hilfsfunktion zum Parsen deutscher Datumsangaben
            function parseGermanDate(dateStr) {
                // Entferne Emojis und Wochentag-Abkürzungen
                const cleanStr = dateStr.replace(/🏖️\s*/, '').replace(/^\w+\s+/, '');
                
                // Parse DD.MM.YYYY Format
                const parts = cleanStr.split('.');
                if (parts.length === 3) {
                    const day = parseInt(parts[0]);
                    const month = parseInt(parts[1]) - 1; // JavaScript Monate sind 0-basiert
                    const year = parseInt(parts[2]);
                    return new Date(year, month, day);
                }
                return null;
            }
            
            // Hilfsfunktion für Zahlenwerte
            function parseNumericValue(value) {
                if (!value || value.trim() === '' || value.toLowerCase() === 'nan') {
                    return ''; // Leere Zelle für NaN oder leere Werte
                }
                const numValue = parseFloat(value);
                return isNaN(numValue) ? value : numValue;
            }
            
            // Tabellendaten sammeln
            const tableData = [];
            
            // Header-Zeile
            const headers = [
                'Datum', 'Frei Gesamt', 'Frei Quotas', 'Frei Sonder', 'Frei Lager', 'Frei Betten', 'Frei DZ',
                'Quota Name', 'Quota Sonder', 'Quota Lager', 'Quota Betten', 'Quota DZ',
                'HRS Sonder', 'Lokal Sonder', 'HRS Lager', 'Lokal Lager', 
                'HRS Betten', 'Lokal Betten', 'HRS DZ', 'Lokal DZ', 'Gesamt Belegt'
            ];
            tableData.push(headers);
            
            // Tabellendaten durchgehen
            const table = document.querySelector('table tbody');
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    const rowData = [];
                    
                    // Datum - konvertiere zu Excel-kompatiblem Datum
                    const dateText = cells[0]?.textContent.trim() || '';
                    const excelDate = parseGermanDate(dateText);
                    rowData.push(excelDate || dateText);
                    
                    // Freie Kapazitäten (6 Spalten - jetzt mit Frei Quotas)
                    for (let i = 1; i <= 6; i++) {
                        const value = cells[i]?.textContent.trim() || '';
                        rowData.push(parseNumericValue(value));
                    }
                    
                    // Quota-Informationen (5 Spalten)
                    let quotaStartIndex = 7;
                    for (let i = quotaStartIndex; i < quotaStartIndex + 5; i++) {
                        const cellContent = cells[i]?.textContent.trim() || '';
                        if (i === quotaStartIndex) {
                            // Quota Name - Text beibehalten
                            rowData.push(cellContent || '');
                        } else {
                            // Quota Zahlen
                            rowData.push(parseNumericValue(cellContent));
                        }
                    }
                    
                    // Belegungszahlen (8 Spalten)
                    let belegungStartIndex = quotaStartIndex + 5;
                    for (let i = belegungStartIndex; i < belegungStartIndex + 8; i++) {
                        const value = cells[i]?.textContent.trim() || '';
                        rowData.push(parseNumericValue(value));
                    }
                    
                    // Gesamt belegt
                    const gesamtIndex = belegungStartIndex + 8;
                    const gesamtValue = cells[gesamtIndex]?.textContent.trim() || '';
                    rowData.push(parseNumericValue(gesamtValue));
                    
                    tableData.push(rowData);
                }
            });
            
            // Excel-Arbeitsblatt erstellen
            const ws = XLSX.utils.aoa_to_sheet(tableData);
            
            // Spaltenbreiten setzen
            const colWidths = [
                {wch: 12}, // Datum
                {wch: 10}, // Frei Gesamt
                {wch: 10}, // Frei Quotas
                {wch: 10}, // Frei Sonder
                {wch: 10}, // Frei Lager
                {wch: 10}, // Frei Betten
                {wch: 10}, // Frei DZ
                {wch: 15}, // Quota Name
                {wch: 10}, // Quota Sonder
                {wch: 10}, // Quota Lager
                {wch: 10}, // Quota Betten
                {wch: 10}, // Quota DZ
                {wch: 10}, // HRS Sonder
                {wch: 10}, // Lokal Sonder
                {wch: 10}, // HRS Lager
                {wch: 10}, // Lokal Lager
                {wch: 10}, // HRS Betten
                {wch: 10}, // Lokal Betten
                {wch: 10}, // HRS DZ
                {wch: 10}, // Lokal DZ
                {wch: 12}  // Gesamt Belegt
            ];
            ws['!cols'] = colWidths;
            
            // Header-Styling
            const headerStyle = {
                font: { bold: true },
                fill: { fgColor: { rgb: "CCCCCC" } },
                alignment: { horizontal: "center" }
            };
            
            // Header-Zellen stylen
            for (let i = 0; i < headers.length; i++) {
                const cellRef = XLSX.utils.encode_cell({ r: 0, c: i });
                if (!ws[cellRef]) ws[cellRef] = {};
                ws[cellRef].s = headerStyle;
            }
            
            // Datumsspalte formatieren
            const range = XLSX.utils.decode_range(ws['!ref']);
            for (let row = 1; row <= range.e.r; row++) {
                const cellRef = XLSX.utils.encode_cell({ r: row, c: 0 });
                if (ws[cellRef] && ws[cellRef].v instanceof Date) {
                    ws[cellRef].z = 'DD.MM.YYYY'; // Deutsches Datumsformat
                    ws[cellRef].t = 'd'; // Typ: Datum
                }
            }
            
            // Arbeitsmappe erstellen
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Belegungsanalyse");
            
            // Dateiname mit aktuellem Datum
            const today = new Date();
            const dateStr = today.getFullYear() + '-' + 
                          String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                          String(today.getDate()).padStart(2, '0');
            const filename = `Belegungsanalyse_${dateStr}.xlsx`;
            
            // Excel-Datei herunterladen
            XLSX.writeFile(wb, filename);
        }
        
        function showDayDetails(dayIndex) {
            const dayData = detailData[dayIndex];
            if (!dayData) return;
            
            const content = document.getElementById('detailsContent');
            
            let html = `<h3>Reservierungen für ${dayData.datum_formatted}</h3>`;
            html += `<p><strong>Freie Plätze gesamt:</strong> ${dayData.freie_plaetze}</p>`;
            
            // HRS Reservierungen
            if (dayData.hrs && dayData.hrs.length > 0) {
                html += '<h4 style="color: #0066cc;">HRS Reservierungen (' + dayData.hrs.length + ')</h4>';
                html += '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
                html += '<tr style="background: #f0f8ff;"><th style="border: 1px solid #ddd; padding: 8px;">Name</th><th style="border: 1px solid #ddd; padding: 8px;">Gruppe</th><th style="border: 1px solid #ddd; padding: 8px;">Anreise</th><th style="border: 1px solid #ddd; padding: 8px;">Abreise</th><th style="border: 1px solid #ddd; padding: 8px;">Sonder</th><th style="border: 1px solid #ddd; padding: 8px;">Lager</th><th style="border: 1px solid #ddd; padding: 8px;">Betten</th><th style="border: 1px solid #ddd; padding: 8px;">DZ</th><th style="border: 1px solid #ddd; padding: 8px;">HP</th><th style="border: 1px solid #ddd; padding: 8px;">Vegi</th></tr>';
                
                dayData.hrs.forEach(res => {
                    html += '<tr>';
                    html += `<td style="border: 1px solid #ddd; padding: 8px;">${res.nachname}, ${res.vorname}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px;">${res.gruppe || ''}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px;">${res.anreise}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px;">${res.abreise}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.sonder}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.lager}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.betten}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.dz}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.hp}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.vegi}</td>`;
                    html += '</tr>';
                });
                html += '</table></div>';
            }
            
            // Lokale Reservierungen
            if (dayData.lokal && dayData.lokal.length > 0) {
                html += '<h4 style="color: #006600;">Lokale Reservierungen (' + dayData.lokal.length + ')</h4>';
                html += '<div style="overflow-x: auto;"><table style="width: 100%; border-collapse: collapse;">';
                html += '<tr style="background: #f0fff0;"><th style="border: 1px solid #ddd; padding: 8px;">Name</th><th style="border: 1px solid #ddd; padding: 8px;">Gruppe</th><th style="border: 1px solid #ddd; padding: 8px;">Anreise</th><th style="border: 1px solid #ddd; padding: 8px;">Abreise</th><th style="border: 1px solid #ddd; padding: 8px;">Sonder</th><th style="border: 1px solid #ddd; padding: 8px;">Lager</th><th style="border: 1px solid #ddd; padding: 8px;">Betten</th><th style="border: 1px solid #ddd; padding: 8px;">DZ</th><th style="border: 1px solid #ddd; padding: 8px;">HP</th><th style="border: 1px solid #ddd; padding: 8px;">Vegi</th></tr>';
                
                dayData.lokal.forEach(res => {
                    html += '<tr>';
                    html += `<td style="border: 1px solid #ddd; padding: 8px;">${res.nachname}, ${res.vorname}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px;">${res.gruppe || ''}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px;">${res.anreise}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px;">${res.abreise}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.sonder}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.lager}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.betten}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.dz}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.hp}</td>`;
                    html += `<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">${res.vegi}</td>`;
                    html += '</tr>';
                });
                html += '</table></div>';
            }
            
            if ((!dayData.hrs || dayData.hrs.length === 0) && (!dayData.lokal || dayData.lokal.length === 0)) {
                html += '<p style="color: #666; font-style: italic;">Keine Reservierungen für diesen Tag gefunden.</p>';
            }
            
            content.innerHTML = html;
            document.getElementById('detailsPanel').style.display = 'block';
        }
        
        function hideDetailsPanel() {
            document.getElementById('detailsPanel').style.display = 'none';
        }
        
        // Panel schließen beim Klick außerhalb
        document.getElementById('detailsPanel').addEventListener('click', function(e) {
            if (e.target === this) {
                hideDetailsPanel();
            }
        });
    </script>
</body>
</html>
