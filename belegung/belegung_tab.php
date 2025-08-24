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

function getQuotasForDate($quotas, $date) {
    $matching = [];
    foreach ($quotas as $quota) {
        // Quota gilt für Nächte: date_from bis date_to (exklusiv)
        // Quota vom 1.8. bis 2.8. gilt nur für 1.8. (Nacht vom 1.8. auf 2.8.)
        if ($date >= $quota['date_from'] && $date < $quota['date_to']) {
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
        .export-csv { background: #4CAF50; color: white; }
        .export-csv:hover { background: #45a049; }
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
            <h1>📊 Belegungsanalyse - Tabellenansicht</h1>
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
        </div>

        <div class="export-buttons">
            <button class="export-btn export-csv" onclick="exportToCSV()">📊 CSV Export</button>
        </div>
        
        <!-- Kontroll-Tabelle -->
        <div style="margin-top: 30px;">
            <h3 style="color: #333; margin-bottom: 15px;">📊 Detailierte Tageswerte</h3>
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
                            $rowBgColor = '';
                            
                            // Wochenende erkennen (Samstag = 6, Sonntag = 0)
                            $dayOfWeek = $datum->format('w');
                            $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
                            
                            if (!empty($tagesQuotas)) {
                                $quota = $tagesQuotas[0]; // Erste Quota nehmen
                                $quotaName = $quota['title'];
                                
                                if (!isset($quotaColors[$quotaName])) {
                                    // Für Wochenenden dunklere Quota-Farben verwenden
                                    if ($isWeekend) {
                                        $weekendColors = ['#f0e68c', '#dda0dd', '#98fb98', '#f0e68c', '#d3d3d3'];
                                        $quotaColors[$quotaName] = $weekendColors[$colorIndex % count($weekendColors)];
                                    } else {
                                        $quotaColors[$quotaName] = $alternatingColors[$colorIndex % count($alternatingColors)];
                                    }
                                    $colorIndex++;
                                }
                                $rowBgColor = $quotaColors[$quotaName];
                                
                                // Quota-Zahlen aus Kategorien extrahieren
                                if (!empty($quota['categories'])) {
                                    $quotaSonder = isset($quota['categories']['SK']) ? $quota['categories']['SK']['total_beds'] : '';
                                    $quotaLager = isset($quota['categories']['ML']) ? $quota['categories']['ML']['total_beds'] : '';
                                    $quotaBetten = isset($quota['categories']['MBZ']) ? $quota['categories']['MBZ']['total_beds'] : '';
                                    $quotaDz = isset($quota['categories']['2BZ']) ? $quota['categories']['2BZ']['total_beds'] : '';
                                }
                            } else if ($isWeekend) {
                                // Keine Quota aber Wochenende - helle Wochenend-Farbe
                                $rowBgColor = '#f5f5f5';
                            }
                            
                            echo '<tr style="background: ' . $rowBgColor . ';" onclick="showDayDetails(' . $i . ')">';
                            
                            // Datum mit Wochenend-Kennzeichnung
                            $datumStyle = 'padding: 8px; border: 1px solid #ddd; font-weight: bold; cursor: pointer;';
                            $datumText = $datum->format('D d.m.Y');
                            
                            if ($isWeekend) {
                                $datumStyle .= ' color: #cc6600; font-weight: 900;'; // Orange und fetter für Wochenende
                                $datumText = '🏖️ ' . $datumText; // Emoji für Wochenende
                            }
                            
                            echo '<td style="' . $datumStyle . '">' . $datumText . '</td>';
                            
                            // Freie Kapazitäten
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; font-weight: bold; background: #e8f5e8;">' . 
                                 $freieKap['gesamt_frei'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #f3e5f5;">' . 
                                 $freieKap['sonder_frei'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #e5f5e5;">' . 
                                 $freieKap['lager_frei'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #fffbe5;">' . 
                                 $freieKap['betten_frei'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #ffe5e5;">' . 
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
                                
                                echo '<td' . $rowspanAttr . ' style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #fff3cd; font-size: 12px; vertical-align: middle;">' . 
                                     htmlspecialchars($quotaName) . '</td>';
                                echo '<td' . $rowspanAttr . ' style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #f0f8ff; vertical-align: middle;">' . $quotaSonder . '</td>';
                                echo '<td' . $rowspanAttr . ' style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #f0f8ff; vertical-align: middle;">' . $quotaLager . '</td>';
                                echo '<td' . $rowspanAttr . ' style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #f0f8ff; vertical-align: middle;">' . $quotaBetten . '</td>';
                                echo '<td' . $rowspanAttr . ' style="padding: 8px; border: 1px solid #ddd; text-align: center; background: #f0f8ff; vertical-align: middle;">' . $quotaDz . '</td>';
                            }
                            
                            // Belegungszahlen
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: #9966CC;">' . $hrsData['sonder'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: #7722CC;">' . $lokalData['sonder'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: #66CC66;">' . $hrsData['lager'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: #228822;">' . $lokalData['lager'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: #CCCC66;">' . $hrsData['betten'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: #CCCC00;">' . $lokalData['betten'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: #FF6666;">' . $hrsData['dz'] . '</td>';
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: #CC2222;">' . $lokalData['dz'] . '</td>';
                            
                            echo '<td style="padding: 8px; border: 1px solid #ddd; text-align: center; font-weight: bold;">' . $gesamtBelegt . '</td>';
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
        
        function exportToCSV() {
            let csv = 'Datum,Frei Gesamt,Frei Sonder,Frei Lager,Frei Betten,Frei DZ,HRS Sonder,Lokal Sonder,HRS Lager,Lokal Lager,HRS Betten,Lokal Betten,HRS DZ,Lokal DZ,Gesamt Belegt\n';
            
            // Tabellendaten durchgehen
            const table = document.querySelector('table tbody');
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    const rowData = Array.from(cells).map(cell => {
                        // Text aus Zelle holen und Anführungszeichen escapen
                        return '"' + cell.textContent.trim().replace(/"/g, '""') + '"';
                    });
                    csv += rowData.join(',') + '\n';
                }
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'belegungsanalyse.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
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
