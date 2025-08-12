<?php
// tisch-uebersicht.php - Tischübersicht basiert auf der HP-Datenbank View
require_once 'auth-simple.php';
require_once 'hp-db-config.php';

// Authentifizierung prüfen
if (!AuthManager::checkSession()) {
    header('Location: login.html');
    exit;
}

/**
 * Parst komplexe Arrangement-Eingaben und gibt strukturierte Daten zurück
 */
function parseArrangementContent($content) {
    if (empty($content)) {
        return [];
    }
    
    // Vereinfachte Parsing-Logik für die Darstellung
    $lines = explode("\n", trim($content));
    $entries = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Prüfe ob es eine reine Zahl ist
        if (is_numeric($line)) {
            $entries[] = [
                'anz' => intval($line),
                'bem' => ''
            ];
        } else {
            // Versuche Zahl am Anfang zu extrahieren
            if (preg_match('/^(\d+)\s*(.*)$/', $line, $matches)) {
                $entries[] = [
                    'anz' => intval($matches[1]),
                    'bem' => trim($matches[2])
                ];
            }
        }
    }
    
    return $entries;
}

/**
 * Erstellt HTML für ovale Darstellung von Arrangement-Einträgen
 */
function createArrangementCellHtml($entries, $timeClass = 'time-old') {
    if (empty($entries)) {
        return '';
    }
    
    // Prüfen ob Bemerkungen vorhanden sind oder mehrere Einträge
    $hasRemarks = false;
    $multipleEntries = count($entries) > 1;
    
    foreach ($entries as $entry) {
        if (!empty($entry['bem'])) {
            $hasRemarks = true;
            break;
        }
    }
    
    // Ovale Darstellung verwenden wenn Bemerkungen vorhanden ODER mehrere Einträge
    if ($hasRemarks || $multipleEntries) {
        // Ovale Darstellung verwenden mit Zeitklasse
        $html = '';
        foreach ($entries as $entry) {
            $html .= '<div class="cell-entry">';
            $html .= '<span class="cell-anzahl ' . $timeClass . '">' . intval($entry['anz']) . '</span>';
            
            if (!empty($entry['bem'])) {
                $html .= '<span class="cell-bemerkung ' . $timeClass . '">' . htmlspecialchars($entry['bem']) . '</span>';
            }
            
            $html .= '</div>';
        }
        return $html;
    } else {
        // Normale Darstellung nur für einzelne Zahl ohne Bemerkung
        $parts = [];
        foreach ($entries as $entry) {
            $parts[] = $entry['anz'];
        }
        return implode('<br>', $parts);
    }
}

// Daten von HP-Datenbank laden
function getTischUebersicht() {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        return ['error' => 'HP-Datenbank nicht verfügbar'];
    }
    
    try {
        // Basis-Query wie in der ursprünglichen View
        $sql = "
            SELECT 
                GROUP_CONCAT(DISTINCT t.bez ORDER BY t.bez ASC SEPARATOR '\n') AS Tisch,
                today.iid,
                today.anz,
                today.nam,
                today.bem,
                today.colname,
                today.start,
                today.status,
                today.an,
                today.ab,
                today.hhp,
                today.resid,
                today.srt
            FROM (
                SELECT 
                    hp.iid,
                    hp.resid,
                    hp.parrent,
                    hp.nam,
                    hp.bem,
                    hp.anz,
                    hp.start,
                    hp.status,
                    hp.ddif,
                    hp.srt,
                    hp.colname,
                    hp.an,
                    hp.ab,
                    hp.stube,
                    hp.hhp
                FROM a_hp_data hp
                WHERE hp.an <= NOW() AND hp.ab > NOW()
                ORDER BY hp.srt
            ) today
            JOIN a_hp_tisch t ON today.iid = t.iid
            GROUP BY today.iid
            ORDER BY today.srt
        ";
        
        $result = $hpConn->query($sql);
        if (!$result) {
            return ['error' => 'Query fehlgeschlagen: ' . $hpConn->error];
        }
        
        $tischData = [];
        
        // Erst alle Arrangement-Arten aus hparr laden und nach sort sortieren
        $arrQuery = "SELECT iid, bez, sort FROM hparr ORDER BY sort";
        $arrResult = $hpConn->query($arrQuery);
        $arrangements = [];
        if ($arrResult) {
            while ($arr = $arrResult->fetch_assoc()) {
                $arrangements[$arr['iid']] = $arr['bez'];
            }
        }
        
        while ($row = $result->fetch_assoc()) {
            // Für jede Zeile die Arrangements laden
            $arrangementData = [];
            
            // Alle Arrangements für diese hp_id laden
            $detailQuery = "
                SELECT 
                    hp_det.arr_id,
                    hp_det.anz,
                    hp_det.bem,
                    hp_det.ts,
                    hp_art.bez,
                    hp_art.sort,
                    TIMESTAMPDIFF(SECOND, hp_det.ts, NOW()) as seconds_ago
                FROM hpdet hp_det
                LEFT JOIN hparr hp_art ON hp_det.arr_id = hp_art.iid
                WHERE hp_det.hp_id = " . intval($row['iid']) . "
                ORDER BY hp_art.sort, hp_det.bem
            ";
            
            $detailResult = $hpConn->query($detailQuery);
            if ($detailResult) {
                while ($detail = $detailResult->fetch_assoc()) {
                    $arrId = $detail['arr_id'];
                    $bezeichnung = $detail['bez'];
                    $secondsAgo = intval($detail['seconds_ago'] ?? 0);
                    
                    // Bestimme Zeitklasse basierend auf seconds_ago
                    $timeClass = 'time-old'; // Standard: schwarz (>=2 Minuten)
                    if ($secondsAgo < 60) {
                        $timeClass = 'time-fresh'; // rot (<1 Minute)
                    } elseif ($secondsAgo < 120) {
                        $timeClass = 'time-recent'; // gold (<2 Minuten)
                    } else {
                        // Zusätzliche Regel: Wenn Timestamp-Datum vom Vortag oder früher ist
                        $currentDate = date('Y-m-d');
                        $tsDate = date('Y-m-d', strtotime($detail['ts']));
                        if ($tsDate < $currentDate) {
                            $timeClass = 'time-future'; // himmelblau für Timestamps von vortag oder früher
                        }
                    }
                    
                    if (!isset($arrangementData[$arrId])) {
                        $arrangementData[$arrId] = [
                            'timeClass' => $timeClass
                        ];
                    } else {
                        // Verwende die "frischeste" Zeitklasse wenn mehrere Einträge vorhanden
                        $currentTimeClass = $arrangementData[$arrId]['timeClass'] ?? 'time-old';
                        if ($timeClass === 'time-fresh' || 
                            ($timeClass === 'time-recent' && $currentTimeClass === 'time-old') ||
                            ($timeClass === 'time-future' && $currentTimeClass === 'time-old')) {
                            $arrangementData[$arrId]['timeClass'] = $timeClass;
                        }
                    }
                    
                    if (empty($detail['bem'])) {
                        // Keine Bemerkung - nur Anzahl summieren
                        if (!isset($arrangementData[$arrId]['sum'])) {
                            $arrangementData[$arrId]['sum'] = 0;
                        }
                        $arrangementData[$arrId]['sum'] += $detail['anz'];
                    } else {
                        // Mit Bemerkung - als separate Einträge
                        $arrangementData[$arrId]['entries'][] = $detail['anz'] . ' ' . $detail['bem'];
                    }
                }
            }
            
            // Arrangement-Spalten formatieren
            foreach ($arrangements as $arrId => $bezeichnung) {
                $cellContent = '';
                $timeClass = 'time-old'; // Standard
                
                if (isset($arrangementData[$arrId])) {
                    $timeClass = $arrangementData[$arrId]['timeClass'] ?? 'time-old';
                    $parts = [];
                    
                    // Erst Summe ohne Bemerkung
                    if (isset($arrangementData[$arrId]['sum']) && $arrangementData[$arrId]['sum'] > 0) {
                        $parts[] = $arrangementData[$arrId]['sum'];
                    }
                    
                    // Dann Einträge mit Bemerkungen
                    if (isset($arrangementData[$arrId]['entries'])) {
                        $parts = array_merge($parts, $arrangementData[$arrId]['entries']);
                    }
                    
                    $cellContent = implode("\n", $parts);
                }
                
                $row['arr_' . $arrId] = $cellContent;
                $row['arr_' . $arrId . '_timeClass'] = $timeClass;
            }
            
            $row['arrangements'] = $arrangements;
            $tischData[] = $row;
        }
        
        return $tischData;
        
    } catch (Exception $e) {
        return ['error' => 'Fehler beim Laden der Tischdaten: ' . $e->getMessage()];
    }
}

$tischData = getTischUebersicht();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tischübersicht - Franz-Senn-Hütte</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8f9fa;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        .back-button {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.2s;
        }
        
        .back-button:hover {
            background: #5a6268;
            text-decoration: none;
            color: white;
        }
        
        .container {
            max-width: 100vw;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Ribbon-Style Button Bar */
        .ribbon-bar {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid #dee2e6;
            padding: 8px 12px;
            display: flex;
            gap: 8px;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-height: 56px;
        }
        
        .ribbon-button {
            width: 32px;
            height: 32px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #495057;
            transition: all 0.2s ease;
            position: relative;
            text-decoration: none;
        }
        
        .ribbon-button:hover {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-color: #2196f3;
            color: #1976d2;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        
        .ribbon-button:active {
            transform: translateY(0);
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        .ribbon-button-tooltip {
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #2c3e50;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 1000;
        }
        
        .ribbon-button:hover .ribbon-button-tooltip {
            opacity: 1;
        }
        
        /* Separator zwischen Button-Gruppen */
        .ribbon-separator {
            width: 1px;
            height: 24px;
            background: #ced4da;
            margin: 0 4px;
        }
        
        .error, .info {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .error h2, .info h2 {
            color: #dc3545;
            margin-bottom: 1rem;
        }
        
        .info h2 {
            color: #6c757d;
        }
        
        .table-container {
            flex: 1;
            background: white;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            height: calc(100vh - 56px); /* Abzug der Ribbon-Höhe */
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            border: 2px solid #adb5bd;
        }
        
        .table-container {
            flex: 1;
            background: white;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        
        .table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #2ecc71;
        }
        
        .table th {
            background: #2ecc71;
            color: white;
            padding: 3px 4px;
            text-align: center;
            font-weight: 600;
            border-bottom: 2px solid #27ae60;
            border-right: 2px solid rgba(255, 255, 255, 0.3);
            font-size: 0.8rem;
            white-space: nowrap;
            line-height: 1.1;
        }
        
        .table td {
            padding: 3px 4px;
            border-bottom: 2px solid #ced4da;
            border-right: 2px solid #ced4da;
            vertical-align: middle;
            text-align: center;
            line-height: 1.2;
        }
        
        /* Überschreibung für linksbündige Zellen */
        .table td.nam-cell,
        .table td.bem-cell {
            text-align: left !important;
        }
        
        /* Tischnummer-Styling */
        .table td.tisch-cell {
            text-align: center !important;
            font-size: 1.28rem !important; /* -20% von 1.6rem */
            font-weight: 400 !important; /* nicht bold */
            color: #0d47a1 !important; /* dunkelblau */
            line-height: 1.0 !important;
            margin: 2px; /* leichter Außenabstand */
        }
        
        .table td.tisch-cell.clickable {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .table td.tisch-cell.clickable:hover {
            background-color: rgba(46, 204, 113, 0.1) !important;
        }
        
        /* Mehrzeilige Arrangement-Zellen */
        .table td.arrangement-cell.multi-value {
            line-height: 1.0 !important;
        }
        
        /* BR-Tags in Tisch- und Arrangement-Zellen kompakter machen */
        .table td.tisch-cell br,
        .table td.arrangement-cell.multi-value br {
            line-height: 0.1 !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Farbzuordnung basierend auf colname (Original-Helligkeit) */
        .row-lavender { background-color: #f8f0ff !important; }
        .row-powderblue { background-color: #e6f3ff !important; }
        .row-floralwhite { background-color: #fffef8 !important; }
        .row-aliceblue { background-color: #f0f8ff !important; }
        .row-lightgray { background-color: #f5f5f5 !important; }
        .row-lightblue { background-color: #e6f7ff !important; }
        .row-lightgreen { background-color: #f0fff0 !important; }
        .row-lightyellow { background-color: #fffffe !important; }
        .row-lightpink { background-color: #fff0f5 !important; }
        .row-lightcyan { background-color: #e0ffff !important; }
        
        /* Farbzuordnung basierend auf colname (30% dunkler für Alternierung) */
        .row-lavender.dark { background-color: #e6d9f2 !important; }
        .row-powderblue.dark { background-color: #cce0f2 !important; }
        .row-floralwhite.dark { background-color: #f2f1e6 !important; }
        .row-aliceblue.dark { background-color: #d9e6f2 !important; }
        .row-lightgray.dark { background-color: #e6e6e6 !important; }
        .row-lightblue.dark { background-color: #cce6f2 !important; }
        .row-lightgreen.dark { background-color: #d9f2d9 !important; }
        .row-lightyellow.dark { background-color: #f2f2e6 !important; }
        .row-lightpink.dark { background-color: #f2d9e6 !important; }
        .row-lightcyan.dark { background-color: #ccf2f2 !important; }
        
        /* Fallback Farben für Tischgruppierung (deutlich alternierende Helligkeit) */
        .row-group-0 { background-color: #ffffff !important; }
        .row-group-1 { background-color: #f0f0f0 !important; }
        
        // Debug Info für colname
        .debug-colname {
            font-size: 0.6rem;
            color: #666;
            font-style: italic;
        }
        
        .table tr:hover {
            background-color: #e3f2fd !important;
        }
        
        .table tr:last-child td {
            border-bottom: 2px solid #ced4da;
        }
        
        .table td:last-child,
        .table th:last-child {
            border-right: none;
        }
        
        .tisch-cell {
            font-weight: 400; /* nicht bold */
            color: #0d47a1; /* dunkelblau */
            max-width: 150px;
            text-align: left;
            word-wrap: break-word;
            white-space: pre-line;
            line-height: 1.1;
            font-size: 0.68rem; /* -20% von 0.85rem */
            margin-left: 2px; /* kleiner Versatz */
        }
        
        .anz-cell {
            text-align: center;
            font-weight: 600;
            color: #495057;
            font-size: 1rem;
        }
        
        .nam-cell {
            font-weight: 500;
            color: #2c3e50;
            text-align: left;
            max-width: 200px;
            line-height: 1.2;
        }
        
        .nam-cell.clickable {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .nam-cell.clickable:hover {
            background-color: rgba(46, 204, 113, 0.1) !important;
            color: #2ecc71;
        }
        
        .bem-cell {
            color: #6c757d;
            font-style: italic;
            max-width: 200px;
            word-wrap: break-word;
            text-align: left;
            font-size: 0.8rem;
            line-height: 1.1;
        }
        
        .bem-cell.clickable {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .bem-cell.clickable:hover {
            background-color: rgba(46, 204, 113, 0.1) !important;
            color: #2ecc71;
        }
        
        .arrangement-cell {
            text-align: center;
            font-weight: 600;
            min-width: 80px;
            white-space: pre-line;
            line-height: 1.0;
            padding: 3px 4px;
        }
        
        /* Große Schrift für einzelne Zahlen */
        .arrangement-cell.single-value {
            font-size: 1.1rem;
        }
        
        /* Kleine Schrift für mehrzeilige Inhalte - linksbündig */
        .arrangement-cell.multi-value {
            font-size: 0.75rem;
            line-height: 1.0;
            margin: 1px;
            text-align: left;
        }
        
        /* Oval-Darstellung für Anzahl in Zellen */
        .cell-entry {
            display: inline-flex;
            align-items: center;
            margin: 1px 2px 1px 0;
            white-space: nowrap;
        }
        
        .cell-anzahl {
            background: #27ae60;
            color: white;
            padding: 1px 8px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.7rem;
            min-width: 24px;
            text-align: center;
            margin-right: 3px;
        }
        
        /* Zeitbasierte Farbgebung für ovale Anzahlen */
        .cell-anzahl.time-fresh {
            background: #dc3545 !important; /* Rot für <1 Minute */
        }
        
        .cell-anzahl.time-recent {
            background: #ffc107 !important; /* Gold für <2 Minuten */
            color: #000 !important; /* Schwarzer Text für bessere Lesbarkeit auf Gold */
        }
        
        .cell-anzahl.time-old {
            background: #495057 !important; /* Schwarz für >=2 Minuten */
        }
        
        .cell-anzahl.time-future {
            background: #87ceeb !important; /* Himmelblau für Timestamps von vortag oder früher */
            color: #000 !important; /* Schwarzer Text für bessere Lesbarkeit auf Himmelblau */
        }
        
        .cell-bemerkung {
            color: #27ae60;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        /* Zeitbasierte Farbgebung für Bemerkungen */
        .cell-bemerkung.time-fresh {
            color: #dc3545 !important;
        }
        
        .cell-bemerkung.time-recent {
            color: #ffc107 !important;
        }
        
        .cell-bemerkung.time-old {
            color: #495057 !important;
        }
        
        .cell-bemerkung.time-future {
            color: #87ceeb !important;
        }
        
        .arrangement-cell.has-value {
            color: #27ae60;
            font-weight: 700;
        }
        
        .arrangement-cell.empty {
            color: #6c757d;
        }
        
        /* Zeitbasierte Farbgebung für Arrangement-Zellen */
        .arrangement-cell.time-fresh {
            color: #dc3545 !important; /* Rot für <1 Minute */
        }
        
        .arrangement-cell.time-recent {
            color: #ffc107 !important; /* Gold für <2 Minuten */
        }
        
        .arrangement-cell.time-old {
            color: #495057 !important; /* Schwarz für >=2 Minuten */
        }
        
        .arrangement-cell.time-future {
            color: #87ceeb !important; /* Himmelblau für Timestamps von vortag oder früher */
        }
        
        /* Spezielle Behandlung für has-value Klasse bei Zeitfärbung */
        .arrangement-cell.has-value.time-fresh {
            color: #dc3545 !important;
            font-weight: 700;
        }
        
        .arrangement-cell.has-value.time-recent {
            color: #ffc107 !important;
            font-weight: 700;
        }
        
        .arrangement-cell.has-value.time-old {
            color: #495057 !important;
            font-weight: 700;
        }
        
        .arrangement-cell.has-value.time-future {
            color: #87ceeb !important;
            font-weight: 700;
        }
        
        /* Inline editing für Arrangement-Zellen */
        .arrangement-cell.editable {
            cursor: pointer;
            position: relative;
        }
        
        .arrangement-cell.editable:hover {
            background-color: rgba(46, 204, 113, 0.1) !important;
        }
        
        .arrangement-cell-input {
            width: 100%;
            height: 100%;
            border: 2px solid #2ecc71;
            background: white;
            text-align: center;
            font-weight: 600;
            font-size: inherit;
            padding: 4px;
            margin: 0;
            box-sizing: border-box;
            border-radius: 3px;
            outline: none;
            resize: none;
            font-family: inherit;
            line-height: 1.2;
        }
        
        /* Spezielle Styles für Textarea */
        .arrangement-cell-input:focus {
            border-color: #27ae60;
            box-shadow: 0 0 0 2px rgba(46, 204, 113, 0.2);
        }
        
        /* Visuelle Parser-Vorschau */
        .parser-preview {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #27ae60;
            border-top: none;
            border-radius: 0 0 6px 6px;
            padding: 8px;
            font-size: 0.8rem;
            line-height: 1.4;
            z-index: 1000;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            max-height: 120px;
            overflow-y: auto;
        }
        
        .parser-preview-empty {
            color: #6c757d;
            font-style: italic;
            text-align: center;
        }
        
        .parser-entry {
            display: inline-flex;
            align-items: center;
            margin: 2px 4px 2px 0;
            white-space: nowrap;
        }
        
        .parser-anzahl {
            background: #27ae60;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.75rem;
            min-width: 24px;
            text-align: center;
            margin-right: 4px;
        }
        
        .parser-bemerkung {
            color: #27ae60;
            font-weight: 500;
            font-size: 0.8rem;
        }
        
        .parser-bemerkung.empty {
            color: #6c757d;
            font-style: italic;
        }
        
        .arrangement-header {
            background: #27ae60 !important;
            writing-mode: horizontal-tb;
            text-orientation: mixed;
            min-height: 45px;
            vertical-align: middle;
            min-width: 70px;
            font-size: 0.75rem;
            padding: 3px 4px;
            line-height: 1.0;
            border-right: 2px solid rgba(255, 255, 255, 0.3) !important;
        }
        
        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            display: none;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        
        .modal-overlay.active {
            display: flex;
            animation: fadeIn 0.3s ease-out;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            animation: slideUp 0.3s ease-out;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            padding: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .modal-body {
            padding: 1rem;
            max-height: calc(90vh - 140px);
            overflow-y: auto;
        }
        
        .guest-info {
            background: #f8f9fa;
            padding: 0.75rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            border-left: 3px solid #2ecc71;
        }
        
        .guest-info h3 {
            margin: 0 0 0.25rem 0;
            color: #2c3e50;
            font-size: 1.1rem;
        }
        
        .guest-info p {
            margin: 0.15rem 0;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .guest-remark-edit {
            margin-bottom: 1rem;
        }
        
        .guest-remark-edit label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.25rem;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .guest-remark-edit textarea {
            width: 100%;
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 0.5rem;
            font-size: 0.9rem;
            min-height: 50px;
            resize: vertical;
        }
        
        .guest-remark-edit textarea:focus {
            outline: none;
            border-color: #2ecc71;
            box-shadow: 0 0 0 2px rgba(46, 204, 113, 0.1);
        }
        
        .arrangements-container {
            margin-top: 1rem;
        }
        
        .arrangements-container h3 {
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }
        
        .arrangement-item {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .arrangement-item:hover {
            border-color: #2ecc71;
            box-shadow: 0 2px 8px rgba(46, 204, 113, 0.1);
        }
        
        .arrangement-item.new-item {
            border-color: #2ecc71;
            background: #f8fff9;
        }
        
        .arrangement-header-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .arrangement-type {
            font-weight: 600;
            color: #2c3e50;
            font-size: 1rem;
        }
        
        .arrangement-delete {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
            font-size: 0.8rem;
        }
        
        .arrangement-delete:hover {
            background: #c82333;
        }
        
        .arrangement-inputs {
            display: grid;
            grid-template-columns: 80px 1fr;
            gap: 0.75rem;
            align-items: start;
        }
        
        .input-group {
            display: flex;
            flex-direction: column;
        }
        
        .input-group label {
            font-weight: 500;
            margin-bottom: 0.25rem;
            color: #495057;
            font-size: 0.85rem;
        }
        
        .input-group input,
        .input-group textarea {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 0.5rem;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }
        
        .input-group input:focus,
        .input-group textarea:focus {
            outline: none;
            border-color: #2ecc71;
            box-shadow: 0 0 0 2px rgba(46, 204, 113, 0.1);
        }
        
        .input-group textarea {
            resize: vertical;
            min-height: 40px;
        }
        
        .add-arrangement {
            background: #2ecc71;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.75rem;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-top: 0.75rem;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .add-arrangement:hover {
            background: #27ae60;
        }
        
        .add-arrangement-select {
            margin-top: 0.75rem;
            position: relative;
        }
        
        .add-arrangement-select select {
            width: 100%;
            border: 2px dashed #2ecc71;
            border-radius: 6px;
            padding: 0.75rem;
            font-size: 0.9rem;
            background: #f8fff9;
            color: #2c3e50;
            cursor: pointer;
        }
        
        .modal-footer {
            background: #f8f9fa;
            padding: 1rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            border-top: 1px solid #e9ecef;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-primary {
            background: #2ecc71;
            color: white;
        }
        
        .btn-primary:hover {
            background: #27ae60;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { 
                transform: translateY(30px);
                opacity: 0;
            }
            to { 
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 1rem;
            }
            
            .modal-body {
                padding: 1rem;
            }
            
            .arrangement-inputs {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                height: calc(100vh - 56px); /* Abzug der Ribbon-Höhe */
            }
            
            .table {
                font-size: 0.8rem;
                min-width: 1000px;
            }
            
            .table th,
            .table td {
                padding: 0.4rem 0.3rem;
            }
            
            .arrangement-header {
                writing-mode: horizontal-tb;
                text-orientation: mixed;
                height: auto;
                min-width: 60px;
                font-size: 0.7rem;
            }
        }
        
        /* Print Controls */
        .print-controls {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1000;
        }
        
        .print-btn {
            background: linear-gradient(45deg, #2ecc71, #27ae60);
            color: white;
            border: none;
            padding: 12px 16px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(46, 204, 113, 0.3);
            transition: all 0.3s ease;
            min-width: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .print-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 204, 113, 0.4);
        }
        
        .print-btn.receipt { 
            background: linear-gradient(45deg, #3498db, #2980b9); 
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
        }
        .print-btn.receipt:hover {
            box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4);
        }
        
        .print-btn.label { 
            background: linear-gradient(45deg, #f39c12, #d68910); 
            box-shadow: 0 4px 15px rgba(243, 156, 18, 0.3);
        }
        .print-btn.label:hover {
            box-shadow: 0 6px 20px rgba(243, 156, 18, 0.4);
        }
        
        .print-btn.overview { 
            background: linear-gradient(45deg, #9b59b6, #8e44ad); 
            box-shadow: 0 4px 15px rgba(155, 89, 182, 0.3);
        }
        .print-btn.overview:hover {
            box-shadow: 0 6px 20px rgba(155, 89, 182, 0.4);
        }
        
        /* Android-optimized print styles */
        @media print {
            body { font-size: 18px; line-height: 1.6; }
            .print-controls { display: none !important; }
            .container { height: auto !important; overflow: visible !important; }
            .table-container { height: auto !important; overflow: visible !important; }
            .table { page-break-inside: avoid; }
            .table thead { display: table-header-group; }
            .table tbody tr { page-break-inside: avoid; }
            .back-button { display: none !important; }
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .print-controls {
                position: fixed;
                bottom: 10px;
                right: 10px;
                left: 10px;
                flex-direction: row;
                justify-content: space-around;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(10px);
                padding: 10px;
                border-radius: 15px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            }
            
            .print-btn {
                min-width: auto;
                flex: 1;
                padding: 10px 8px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($tischData['error'])): ?>
            <div class="error">
                <h2>Fehler</h2>
                <p><?php echo htmlspecialchars($tischData['error']); ?></p>
                <a href="index.php" class="back-button">← Zurück zum Dashboard</a>
            </div>
        <?php elseif (empty($tischData)): ?>
            <div class="info">
                <h2>Keine Daten</h2>
                <p>Keine Gäste mit Tischzuteilung gefunden.</p>
                <a href="index.php" class="back-button">← Zurück zum Dashboard</a>
            </div>
        <?php else: ?>
            <!-- Ribbon Button Bar -->
            <div class="ribbon-bar">
                <a href="index.php" class="ribbon-button" title="Zurück zum Dashboard">
                    <span>←</span>
                    <div class="ribbon-button-tooltip">Zurück</div>
                </a>
                
                <div class="ribbon-separator"></div>
                
                <button class="ribbon-button" onclick="syncDatabase()" title="Datenbank synchronisieren">
                    <span>⟲</span>
                    <div class="ribbon-button-tooltip">Sync DB</div>
                </button>
            </div>
            
            <!-- Tabelle -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th rowspan="2">Tisch</th>
                            <th rowspan="2">Anz</th>
                            <th rowspan="2">Name</th>
                            <th rowspan="2">Bemerkung</th>
                            <?php 
                            // Zähle die Arrangements für colspan
                            $arrangementCount = 0;
                            if (!empty($tischData) && isset($tischData[0]['arrangements'])) {
                                $arrangementCount = count($tischData[0]['arrangements']);
                            }
                            if ($arrangementCount > 0): ?>
                                <th colspan="<?php echo $arrangementCount; ?>">Arrangements</th>
                            <?php endif; ?>
                        </tr>
                        <tr>
                            <?php 
                            // Dynamische Arrangement-Spalten aus erstem Datensatz - nur die Arrangement-Header
                            if (!empty($tischData) && isset($tischData[0]['arrangements'])) {
                                foreach ($tischData[0]['arrangements'] as $arrId => $bezeichnung) {
                                    echo '<th class="arrangement-header">' . htmlspecialchars($bezeichnung) . '</th>';
                                }
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $currentTisch = '';
                        $tischGroup = 0;
                        
                        foreach ($tischData as $row): 
                            // Prüfe ob sich die Tischnummer geändert hat
                            $tischNummer = $row['Tisch'] ?? '';
                            if ($tischNummer !== $currentTisch) {
                                $currentTisch = $tischNummer;
                                $tischGroup++;
                            }
                            
                            // Bestimme Farbe basierend auf colname
                            $rowClass = '';
                            $colname = strtolower($row['colname'] ?? '');
                            $isDarkGroup = ($tischGroup % 2 === 0); // Jede zweite Gruppe wird dunkler
                            
                            // Farbzuordnung basierend auf colname
                            if (strpos($colname, 'lavender') !== false) {
                                $rowClass = 'row-lavender';
                            } elseif (strpos($colname, 'powderblue') !== false) {
                                $rowClass = 'row-powderblue';
                            } elseif (strpos($colname, 'floralwhite') !== false) {
                                $rowClass = 'row-floralwhite';
                            } elseif (strpos($colname, 'aliceblue') !== false) {
                                $rowClass = 'row-aliceblue';
                            } elseif (strpos($colname, 'lightgray') !== false || strpos($colname, 'lightgrey') !== false) {
                                $rowClass = 'row-lightgray';
                            } elseif (strpos($colname, 'lightblue') !== false) {
                                $rowClass = 'row-lightblue';
                            } elseif (strpos($colname, 'lightgreen') !== false) {
                                $rowClass = 'row-lightgreen';
                            } elseif (strpos($colname, 'lightyellow') !== false) {
                                $rowClass = 'row-lightyellow';
                            } elseif (strpos($colname, 'lightpink') !== false) {
                                $rowClass = 'row-lightpink';
                            } elseif (strpos($colname, 'lightcyan') !== false) {
                                $rowClass = 'row-lightcyan';
                            } else {
                                // Fallback: Einfache Alternierung zwischen hell und dunkel
                                $rowClass = 'row-group-' . ($tischGroup % 2);
                                $isDarkGroup = false; // Bei Fallback-Farben keine zusätzliche Verdunkelung
                            }
                            
                            // Füge dark-Klasse hinzu für jede zweite Tischgruppe
                            if ($isDarkGroup && $rowClass !== 'row-group-0' && $rowClass !== 'row-group-1') {
                                $rowClass .= ' dark';
                            }
                        ?>
                            <tr class="<?php echo $rowClass; ?>" 
                                data-guest-id="<?php echo intval($row['iid']); ?>"
                                data-guest-name="<?php echo htmlspecialchars($row['nam'] ?? '', ENT_QUOTES); ?>"
                                data-guest-remark="<?php echo htmlspecialchars($row['bem'] ?? '', ENT_QUOTES); ?>"
                                data-guest-count="<?php echo intval($row['anz'] ?? 0); ?>"
                                data-guest-table="<?php echo htmlspecialchars($row['Tisch'] ?? '', ENT_QUOTES); ?>">
                                <td class="tisch-cell clickable"><?php 
                                    $tischText = $row['Tisch'] ?? '-';
                                    // Doppelte/mehrfache LF durch einfache ersetzen und direkt <br> verwenden
                                    $cleanTischText = preg_replace('/\n+/', '<br>', trim($tischText));
                                    echo htmlspecialchars_decode($cleanTischText, ENT_NOQUOTES); 
                                ?></td>
                                <td class="anz-cell"><?php echo htmlspecialchars($row['anz'] ?? '0'); ?></td>
                                <td class="nam-cell clickable">
                                    <?php echo htmlspecialchars($row['nam'] ?? '-'); ?>
                                </td>
                                <td class="bem-cell clickable"><?php echo htmlspecialchars($row['bem'] ?? '-'); ?></td>
                                
                                <?php 
                                // Dynamische Arrangement-Spalten
                                if (isset($row['arrangements'])) {
                                    foreach ($row['arrangements'] as $arrId => $bezeichnung) {
                                        $content = isset($row['arr_' . $arrId]) ? $row['arr_' . $arrId] : '';
                                        $timeClass = isset($row['arr_' . $arrId . '_timeClass']) ? $row['arr_' . $arrId . '_timeClass'] : 'time-old';
                                        $hasValue = !empty($content);
                                        
                                        // Bestimme ob es mehrzeilig ist oder nur eine Zahl
                                        $isMultiLine = $hasValue && (strpos($content, "\n") !== false || !is_numeric(trim($content)));
                                        $isSingleNumber = $hasValue && is_numeric(trim($content)) && strpos($content, "\n") === false;
                                        
                                        $cssClass = 'arrangement-cell ' . $timeClass . ' ';
                                        if ($hasValue) {
                                            $cssClass .= 'has-value ';
                                            if ($isSingleNumber) {
                                                $cssClass .= 'single-value';
                                            } elseif ($isMultiLine) {
                                                $cssClass .= 'multi-value';
                                            }
                                        } else {
                                            $cssClass .= 'empty';
                                        }
                                        
                                        echo '<td class="' . $cssClass . ' editable" 
                                              data-guest-id="' . intval($row['iid']) . '" 
                                              data-arr-id="' . $arrId . '" 
                                              data-arr-name="' . htmlspecialchars($bezeichnung) . '">';
                                        if ($hasValue) {
                                            // Parse den Inhalt und erstelle ovale Darstellung wenn nötig
                                            $parsedEntries = parseArrangementContent($content);
                                            if (!empty($parsedEntries)) {
                                                echo createArrangementCellHtml($parsedEntries, $timeClass);
                                            } else {
                                                // Fallback: normale Darstellung
                                                $cleanContent = preg_replace('/\n+/', '<br>', trim($content));
                                                $escapedContent = htmlspecialchars($cleanContent);
                                                $finalContent = str_replace('&lt;br&gt;', '<br>', $escapedContent);
                                                echo $finalContent;
                                            }
                                        }
                                        echo '</td>';
                                    }
                                }
                                ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <!-- Print Controls -->
        <div class="print-controls">
            <button onclick="printReceipt()" class="print-btn receipt">
                🧾 Rechnung
            </button>
            <button onclick="printLabels()" class="print-btn label">
                🏷️ Etiketten
            </button>
            <button onclick="printOverview()" class="print-btn overview">
                📋 Übersicht
            </button>
        </div>
    </div>

    <!-- Arrangement Modal -->
    <div id="arrangementModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Arrangements bearbeiten</h2>
                <button class="modal-close" onclick="closeArrangementModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="guest-info">
                    <h3 id="guestName">Gast Name</h3>
                    <p><strong>Tisch:</strong> <span id="guestTable">-</span> | <strong>Personen:</strong> <span id="guestCount">0</span></p>
                </div>
                
                <div class="guest-remark-edit">
                    <label for="guestRemarkInput">Bemerkung:</label>
                    <textarea id="guestRemarkInput" placeholder="Bemerkung eingeben..."></textarea>
                </div>
                
                <div class="arrangements-container">
                    <h3>Arrangements</h3>
                    <div id="arrangementsList"></div>
                    
                    <div class="add-arrangement-select">
                        <select id="newArrangementType" onchange="addNewArrangement()">
                            <option value="">+ Neues Arrangement hinzufügen</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeArrangementModal()">Abbrechen</button>
                <button class="btn btn-primary" onclick="saveArrangements()">Speichern</button>
            </div>
        </div>
    </div>

    <script>
        let currentGuestId = null;
        let availableArrangements = {};
        let currentArrangements = {};

        // Debug: Console-Log hinzufügen
        console.log('JavaScript geladen');
        
        // Event Delegation für klickbare Zellen
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, setting up event listeners');
            
            const tableContainer = document.querySelector('.table-container');
            console.log('Table container found:', tableContainer);
            
            if (tableContainer) {
                tableContainer.addEventListener('click', function(e) {
                    console.log('Click detected on:', e.target);
                    console.log('Target classes:', e.target.className);
                    
                    // Prüfe zuerst auf Arrangement-Zellen
                    const arrangementCell = e.target.closest('.arrangement-cell.editable');
                    console.log('Arrangement cell found:', arrangementCell);
                    
                    if (arrangementCell) {
                        console.log('Starting inline edit for arrangement cell');
                        e.stopPropagation();
                        startInlineEdit(arrangementCell);
                        return;
                    }
                    
                    // Dann auf andere klickbare Zellen
                    const clickableCell = e.target.closest('.clickable');
                    if (clickableCell) {
                        console.log('Clickable cell found, opening modal');
                        const row = clickableCell.closest('tr');
                        if (row) {
                            const guestId = row.getAttribute('data-guest-id');
                            const guestName = row.getAttribute('data-guest-name');
                            const guestRemark = row.getAttribute('data-guest-remark');
                            const guestCount = row.getAttribute('data-guest-count');
                            const guestTable = row.getAttribute('data-guest-table');
                            
                            if (guestId) {
                                openArrangementModal(
                                    parseInt(guestId), 
                                    guestName || '', 
                                    guestRemark || '', 
                                    parseInt(guestCount) || 0, 
                                    guestTable || ''
                                );
                            }
                        }
                    }
                }); // Ende click event listener
            } // Ende if tableContainer
        }); // Ende DOMContentLoaded
        
        // Inline Editing für Arrangement-Zellen
        let currentEditingCell = null; // Verhindere mehrfache Bearbeitung
        
        /**
         * Konvertiert Anzeige-Format zu Eingabe-Format
         * z.B. "6\n6 veg" → "6, 6 veg" (mit Komma-Delimiter für bessere Lesbarkeit)
         */
        function convertDisplayToInput(displayText) {
            if (!displayText) return '';
            
            // Zeilenumbrüche durch Komma + Leerzeichen ersetzen für bessere Lesbarkeit
            return displayText.replace(/\n+/g, ', ').replace(/\s+/g, ' ').trim();
        }
        
        /**
         * Konvertiert Eingabe zurück zu Anzeige-Format mit Gruppierung
         * z.B. "5 3veg 3 veg 1" → "6\n6 veg" (gruppiert)
         */
        function convertInputToDisplay(inputText) {
            if (!inputText) return '';
            
            // Schritt 1: Normalisierung - Kommas und Doppelpunkte mit optionalen Leerzeichen durch Leerzeichen ersetzen
            let normalized = inputText.replace(/\s*[,:]\s*/g, ' ');
            normalized = normalized.replace(/\s+/g, ' ').trim();
            
            // Schritt 2: Tokenisierung
            const tokens = normalized.split(' ');
            
            // Schritt 3: Verknüpfte Muster erweitern (z.B. "2veg" → ["2", "veg"])
            const expandedTokens = [];
            for (const token of tokens) {
                if (/^(\d+)([a-zA-ZäöüÄÖÜß].*)$/.test(token)) {
                    const matches = token.match(/^(\d+)([a-zA-ZäöüÄÖÜß].*)$/);
                    expandedTokens.push(matches[1]); // Zahl
                    expandedTokens.push(matches[2]); // Text
                } else {
                    expandedTokens.push(token);
                }
            }
            
            // Schritt 4: Parsing in Anzahl-Bemerkung Paare
            const entries = [];
            let i = 0;
            
            while (i < expandedTokens.length) {
                const token = expandedTokens[i];
                
                if (/^\d+$/.test(token)) {
                    const anzahl = parseInt(token);
                    let bemerkung = '';
                    
                    // Sammle alle nachfolgenden nicht-numerischen Tokens als Bemerkung
                    const bemerkungParts = [];
                    let j = i + 1;
                    while (j < expandedTokens.length && !/^\d+$/.test(expandedTokens[j])) {
                        bemerkungParts.push(expandedTokens[j]);
                        j++;
                    }
                    
                    if (bemerkungParts.length > 0) {
                        bemerkung = bemerkungParts.join(' ');
                    }
                    
                    if (anzahl > 0) {
                        entries.push({
                            anz: anzahl,
                            bem: bemerkung.trim()
                        });
                    }
                    
                    i = j; // Springe zur nächsten Zahl
                } else {
                    i++; // Überspringe nicht-numerische Tokens
                }
            }
            
            // Schritt 5: Gruppierung nach gleichen Bemerkungen
            const grouped = {};
            for (const entry of entries) {
                const bemerkung = entry.bem;
                const anzahl = entry.anz;
                
                if (grouped.hasOwnProperty(bemerkung)) {
                    grouped[bemerkung] += anzahl;
                } else {
                    grouped[bemerkung] = anzahl;
                }
            }
            
            // Schritt 6: Zurück in Anzeige-Format konvertieren
            const displayParts = [];
            for (const [bemerkung, totalAnzahl] of Object.entries(grouped)) {
                if (bemerkung === '') {
                    displayParts.push(totalAnzahl.toString());
                } else {
                    displayParts.push(totalAnzahl + ' ' + bemerkung);
                }
            }
            
            return displayParts.join('\n');
        }
        
        /**
         * Parst Input und gibt strukturierte Daten zurück (für Vorschau)
         */
        function parseInputForPreview(inputText) {
            if (!inputText) return [];
            
            // Schritt 1: Normalisierung - Kommas und Doppelpunkte mit optionalen Leerzeichen durch Leerzeichen ersetzen
            let normalized = inputText.replace(/\s*[,:]\s*/g, ' ');
            normalized = normalized.replace(/\s+/g, ' ').trim();
            
            // Schritt 2: Tokenisierung
            const tokens = normalized.split(' ');
            
            // Schritt 3: Verknüpfte Muster erweitern (z.B. "2veg" → ["2", "veg"])
            const expandedTokens = [];
            for (const token of tokens) {
                if (/^(\d+)([a-zA-ZäöüÄÖÜß].*)$/.test(token)) {
                    const matches = token.match(/^(\d+)([a-zA-ZäöüÄÖÜß].*)$/);
                    expandedTokens.push(matches[1]); // Zahl
                    expandedTokens.push(matches[2]); // Text
                } else {
                    expandedTokens.push(token);
                }
            }
            
            // Schritt 4: Parsing in Anzahl-Bemerkung Paare
            const entries = [];
            let i = 0;
            
            while (i < expandedTokens.length) {
                const token = expandedTokens[i];
                
                if (/^\d+$/.test(token)) {
                    const anzahl = parseInt(token);
                    let bemerkung = '';
                    
                    // Sammle alle nachfolgenden nicht-numerischen Tokens als Bemerkung
                    const bemerkungParts = [];
                    let j = i + 1;
                    while (j < expandedTokens.length && !/^\d+$/.test(expandedTokens[j])) {
                        bemerkungParts.push(expandedTokens[j]);
                        j++;
                    }
                    
                    if (bemerkungParts.length > 0) {
                        bemerkung = bemerkungParts.join(' ');
                    }
                    
                    if (anzahl > 0) {
                        entries.push({
                            anz: anzahl,
                            bem: bemerkung.trim()
                        });
                    }
                    
                    i = j; // Springe zur nächsten Zahl
                } else {
                    i++; // Überspringe nicht-numerische Tokens
                }
            }
            
            // Schritt 5: Gruppierung nach gleichen Bemerkungen
            const grouped = {};
            for (const entry of entries) {
                const bemerkung = entry.bem;
                const anzahl = entry.anz;
                
                if (grouped.hasOwnProperty(bemerkung)) {
                    grouped[bemerkung] += anzahl;
                } else {
                    grouped[bemerkung] = anzahl;
                }
            }
            
            // Schritt 6: Als Array zurückgeben
            const results = [];
            for (const [bemerkung, totalAnzahl] of Object.entries(grouped)) {
                results.push({
                    anz: totalAnzahl,
                    bem: bemerkung
                });
            }
            
            return results;
        }
        
        /**
         * Erstellt visuelle Vorschau der geparsten Eingabe
         */
        function createParserPreview(inputValue) {
            const parsed = parseInputForPreview(inputValue);
            
            if (parsed.length === 0) {
                return '<div class="parser-preview-empty">Keine gültige Eingabe erkannt</div>';
            }
            
            let html = '';
            for (const entry of parsed) {
                html += '<div class="parser-entry">';
                html += `<span class="parser-anzahl">${entry.anz}</span>`;
                
                if (entry.bem === '') {
                    html += '<span class="parser-bemerkung empty">(leer)</span>';
                } else {
                    html += `<span class="parser-bemerkung">${entry.bem}</span>`;
                }
                
                html += '</div>';
            }
            
            return html;
        }
        
        function startInlineEdit(cell) {
            // Prüfe ob bereits eine Zelle bearbeitet wird
            if (currentEditingCell) {
                return; // Verhindere mehrfache Inputs
            }
            
            // Prüfe ob bereits ein Input in dieser Zelle aktiv ist
            if (cell.querySelector('.arrangement-cell-input')) {
                return;
            }
            
            currentEditingCell = cell; // Markiere als aktiv bearbeitet
            
            const guestId = cell.getAttribute('data-guest-id');
            const arrId = cell.getAttribute('data-arr-id');
            
            // Intelligente Extraktion des aktuellen Werts
            let currentValue;
            
            // Prüfe ob es ovale Darstellung gibt (cell-entry Elemente)
            const cellEntries = cell.querySelectorAll('.cell-entry');
            
            if (cellEntries.length > 0) {
                // Ovale Darstellung vorhanden - extrahiere strukturiert
                const parts = [];
                cellEntries.forEach(entry => {
                    const anzahlSpan = entry.querySelector('.cell-anzahl');
                    const bemerkungSpan = entry.querySelector('.cell-bemerkung');
                    
                    if (anzahlSpan) {
                        const anzahl = anzahlSpan.textContent.trim();
                        const bemerkung = bemerkungSpan ? bemerkungSpan.textContent.trim() : '';
                        
                        if (bemerkung === '') {
                            parts.push(anzahl);
                        } else {
                            parts.push(anzahl + ' ' + bemerkung);
                        }
                    }
                });
                currentValue = parts.join(', ');
            } else {
                // Normale Darstellung - bereinige HTML
                const rawContent = cell.innerHTML.replace(/<br>/g, '\n').replace(/<[^>]*>/g, '').trim();
                currentValue = convertDisplayToInput(rawContent);
            }
            
            console.log('Starte Inline-Edit für:', guestId, arrId, 'Wert:', currentValue);
            
            // Container für Input und Vorschau erstellen
            const inputContainer = document.createElement('div');
            inputContainer.style.position = 'relative';
            inputContainer.style.width = '100%';
            inputContainer.style.height = '100%';
            
            // Textarea für komplexere Eingaben verwenden
            const input = document.createElement('textarea');
            input.className = 'arrangement-cell-input';
            input.value = currentValue;
            input.style.width = '100%';
            input.style.textAlign = 'center';
            input.style.resize = 'none';
            input.style.minHeight = '30px';
            input.style.fontSize = 'inherit';
            input.rows = 1;
            
            // Vorschau-Container erstellen
            const previewContainer = document.createElement('div');
            previewContainer.className = 'parser-preview';
            previewContainer.style.display = 'none';
            
            // Container zusammenbauen
            inputContainer.appendChild(input);
            inputContainer.appendChild(previewContainer);
            
            // Cell-Inhalt ersetzen
            cell.innerHTML = '';
            cell.appendChild(inputContainer);
            
            // Live-Vorschau aktualisieren
            function updatePreview() {
                const inputValue = input.value.trim();
                if (inputValue === '') {
                    previewContainer.style.display = 'none';
                } else {
                    const previewHtml = createParserPreview(inputValue);
                    previewContainer.innerHTML = previewHtml;
                    previewContainer.style.display = 'block';
                }
            }
            
            // Sofort fokussieren und selektieren
            setTimeout(() => {
                input.focus();
                input.select();
                updatePreview(); // Initiale Vorschau
            }, 10);
            
            // Event-Handler für Live-Vorschau
            input.addEventListener('input', updatePreview);
            input.addEventListener('keyup', updatePreview);
            
            // Event-Handler für Speichern
            const saveEdit = async function() {
                const newValue = input.value.trim();
                console.log('Speichere neuen Wert:', newValue);
                
                try {
                    const response = await fetch('save-arrangement-inline.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            guest_id: parseInt(guestId),
                            arr_id: parseInt(arrId),
                            value: newValue
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        console.log('Erfolgreich gespeichert');
                        previewContainer.style.display = 'none'; // Vorschau ausblenden
                        restoreCellContent(cell, newValue);
                        
                        // Alle Arrangement-Zellen aktualisieren (wegen Farbdarstellung)
                        refreshAllArrangementCells();
                    } else {
                        console.error('Speicherfehler:', result.error);
                        alert('Fehler beim Speichern: ' + result.error);
                        previewContainer.style.display = 'none'; // Vorschau ausblenden
                        restoreCellContent(cell, currentValue);
                    }
                    
                } catch (error) {
                    console.error('Fehler:', error);
                    alert('Fehler beim Speichern: ' + error.message);
                    previewContainer.style.display = 'none'; // Vorschau ausblenden
                    restoreCellContent(cell, currentValue);
                }
                
                currentEditingCell = null; // Freigeben
            };
            
            const cancelEdit = function() {
                previewContainer.style.display = 'none'; // Vorschau ausblenden
                restoreCellContent(cell, currentValue);
                currentEditingCell = null; // Freigeben
            };
            
            // Event-Listener
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveEdit();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelEdit();
                }
            });
            
            input.addEventListener('blur', function() {
                saveEdit();
            });
        }
        
        /**
         * Aktualisiert alle Arrangement-Zellen mit neuen Daten und Zeitfarben
         */
        async function refreshAllArrangementCells() {
            console.log('Aktualisiere alle Arrangement-Zellen...');
            
            try {
                // Lade neue Daten vom Server
                const response = await fetch('get-arrangement-cells-data.php');
                const result = await response.json();
                
                if (result.success && result.data) {
                    // Durchlaufe alle Arrangement-Zellen und aktualisiere sie
                    const arrangementCells = document.querySelectorAll('.arrangement-cell.editable');
                    
                    arrangementCells.forEach(cell => {
                        const guestId = cell.getAttribute('data-guest-id');
                        const arrId = cell.getAttribute('data-arr-id');
                        
                        // Finde entsprechende Daten
                        const cellData = result.data.find(data => 
                            data.guest_id == guestId && data.arr_id == arrId
                        );
                        
                        if (cellData) {
                            // Aktualisiere Zeitklasse
                            const oldTimeClass = cell.className.match(/time-\w+/);
                            if (oldTimeClass) {
                                cell.classList.remove(oldTimeClass[0]);
                            }
                            cell.classList.add(cellData.timeClass);
                            
                            // Aktualisiere Inhalt falls vorhanden
                            if (cellData.content) {
                                // Parse und erstelle HTML wie in PHP
                                const parsed = parseInputForPreview(cellData.content);
                                const hasRemarks = parsed.some(entry => entry.bem !== '');
                                const multipleEntries = parsed.length > 1;
                                
                                if (hasRemarks || multipleEntries) {
                                    // Ovale Darstellung mit neuer Zeitklasse
                                    let htmlContent = '';
                                    for (const entry of parsed) {
                                        htmlContent += '<div class="cell-entry">';
                                        htmlContent += `<span class="cell-anzahl ${cellData.timeClass}">${entry.anz}</span>`;
                                        
                                        if (entry.bem !== '') {
                                            htmlContent += `<span class="cell-bemerkung ${cellData.timeClass}">${entry.bem}</span>`;
                                        }
                                        
                                        htmlContent += '</div>';
                                    }
                                    cell.innerHTML = htmlContent;
                                } else {
                                    // Normale Darstellung
                                    const displayValue = convertInputToDisplay(cellData.content);
                                    const htmlContent = displayValue.replace(/\n/g, '<br>');
                                    cell.innerHTML = htmlContent;
                                }
                            }
                        }
                    });
                    
                    console.log('Alle Arrangement-Zellen aktualisiert');
                } else {
                    console.warn('Keine Daten für Zellenaktualisierung erhalten');
                }
                
            } catch (error) {
                console.error('Fehler beim Aktualisieren der Zellen:', error);
                // Fallback: Seite neu laden wenn API nicht verfügbar
                console.log('Fallback: Lade Seite neu...');
                window.location.reload();
            }
        }
        
        function restoreCellContent(cell, value) {
            // Eingabe zu Anzeige-Format konvertieren
            const displayValue = convertInputToDisplay(value);
            
            // CSS-Klassen bestimmen
            const hasValue = displayValue && displayValue.length > 0;
            const isMultiLine = hasValue && displayValue.includes('\n');
            const isSingleNumber = hasValue && /^\d+$/.test(displayValue.trim());
            
            // CSS-Klassen aktualisieren
            cell.className = cell.className.replace(/\b(has-value|empty|single-value|multi-value)\b/g, '').trim();
            
            if (hasValue) {
                cell.classList.add('has-value');
                if (isSingleNumber) {
                    cell.classList.add('single-value');
                } else if (isMultiLine) {
                    cell.classList.add('multi-value');
                }
            } else {
                cell.classList.add('empty');
            }
            
            // Inhalt setzen - prüfen ob ovale Darstellung verwendet werden soll
            if (hasValue) {
                const parsed = parseInputForPreview(value);
                const hasRemarks = parsed.some(entry => entry.bem !== '');
                const multipleEntries = parsed.length > 1;
                
                // Ovale Darstellung verwenden wenn Bemerkungen vorhanden ODER mehrere Einträge
                if (hasRemarks || multipleEntries) {
                    // Ovale Darstellung verwenden
                    let htmlContent = '';
                    for (const entry of parsed) {
                        htmlContent += '<div class="cell-entry">';
                        htmlContent += `<span class="cell-anzahl">${entry.anz}</span>`;
                        
                        if (entry.bem !== '') {
                            htmlContent += `<span class="cell-bemerkung">${entry.bem}</span>`;
                        }
                        
                        htmlContent += '</div>';
                    }
                    cell.innerHTML = htmlContent;
                } else {
                    // Normale Darstellung nur für einzelne Zahl ohne Bemerkung
                    const htmlContent = displayValue.replace(/\n/g, '<br>');
                    cell.innerHTML = htmlContent;
                }
            } else {
                cell.innerHTML = '';
            }
        }

        // Auto-refresh alle 5 Minuten
        setTimeout(() => {
            window.location.reload();
        }, 5 * 60 * 1000);
        
        // Tastatur-Navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('arrangementModal').classList.contains('active')) {
                    closeArrangementModal();
                } else {
                    window.location.href = 'index.php';
                }
            } else if (e.key === 'Backspace' && !document.getElementById('arrangementModal').classList.contains('active')) {
                window.location.href = 'index.php';
            }
        });

        // Modal öffnen - vereinfacht für Debug
        function openArrangementModal(guestId, guestName, guestRemark, guestCount, guestTable) {
            console.log('Modal öffnen für Gast:', guestId, guestName);
            
            currentGuestId = guestId;
            
            // Gast-Info setzen
            document.getElementById('guestName').textContent = guestName;
            document.getElementById('guestTable').textContent = guestTable;
            document.getElementById('guestCount').textContent = guestCount;
            document.getElementById('guestRemarkInput').value = guestRemark || '';
            
            // Modal sofort anzeigen für Test
            document.getElementById('arrangementModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Dann Daten laden
            loadAvailableArrangements().then(() => {
                return loadCurrentArrangements(guestId);
            }).catch(error => {
                console.error('Fehler beim Laden:', error);
            });
        }

        // Verfügbare Arrangements laden
        async function loadAvailableArrangements() {
            try {
                console.log('Lade verfügbare Arrangements...');
                const response = await fetch('get-arrangements.php');
                const data = await response.json();
                console.log('Arrangements geladen:', data);
                availableArrangements = data;
                
                // Select-Element füllen
                const select = document.getElementById('newArrangementType');
                select.innerHTML = '<option value="">+ Neues Arrangement hinzufügen</option>';
                
                Object.entries(availableArrangements).forEach(([id, name]) => {
                    const option = document.createElement('option');
                    option.value = id;
                    option.textContent = name;
                    select.appendChild(option);
                });
            } catch (error) {
                console.error('Fehler beim Laden der Arrangements:', error);
            }
        }

        // Aktuelle Arrangements laden
        async function loadCurrentArrangements(guestId) {
            try {
                console.log('Lade aktuelle Arrangements für Gast:', guestId);
                const response = await fetch(`get-guest-arrangements.php?guest_id=${guestId}`);
                const data = await response.json();
                console.log('Aktuelle Arrangements:', data);
                currentArrangements = data;
                
                renderArrangements();
            } catch (error) {
                console.error('Fehler beim Laden der aktuellen Arrangements:', error);
                currentArrangements = {};
                renderArrangements();
            }
        }

        // Arrangements rendern
        function renderArrangements() {
            console.log('Rendere Arrangements:', currentArrangements);
            const container = document.getElementById('arrangementsList');
            container.innerHTML = '';
            
            Object.entries(currentArrangements).forEach(([arrId, arrData]) => {
                const arrangementName = availableArrangements[arrId] || `Arrangement ${arrId}`;
                
                arrData.forEach((item, index) => {
                    const div = document.createElement('div');
                    div.className = 'arrangement-item';
                    div.innerHTML = `
                        <div class="arrangement-header-item">
                            <div class="arrangement-type">${arrangementName}</div>
                            <button class="arrangement-delete" onclick="removeArrangement('${arrId}', ${index})" title="Löschen">×</button>
                        </div>
                        <div class="arrangement-inputs">
                            <div class="input-group">
                                <label>Anzahl</label>
                                <input type="number" value="${item.anz || 1}" min="1" 
                                       onchange="updateArrangement('${arrId}', ${index}, 'anz', this.value)">
                            </div>
                            <div class="input-group">
                                <label>Bemerkung</label>
                                <textarea placeholder="Optionale Bemerkung..." 
                                         onchange="updateArrangement('${arrId}', ${index}, 'bem', this.value)">${item.bem || ''}</textarea>
                            </div>
                        </div>
                    `;
                    container.appendChild(div);
                });
            });
        }

        // Neues Arrangement hinzufügen
        function addNewArrangement() {
            const select = document.getElementById('newArrangementType');
            const arrId = select.value;
            
            if (!arrId) return;
            
            if (!currentArrangements[arrId]) {
                currentArrangements[arrId] = [];
            }
            
            currentArrangements[arrId].push({
                anz: 1,
                bem: ''
            });
            
            renderArrangements();
            select.value = '';
        }

        // Arrangement aktualisieren
        function updateArrangement(arrId, index, field, value) {
            if (currentArrangements[arrId] && currentArrangements[arrId][index]) {
                currentArrangements[arrId][index][field] = value;
            }
        }

        // Arrangement entfernen
        function removeArrangement(arrId, index) {
            if (currentArrangements[arrId]) {
                currentArrangements[arrId].splice(index, 1);
                
                // Wenn keine Einträge mehr vorhanden, Array löschen
                if (currentArrangements[arrId].length === 0) {
                    delete currentArrangements[arrId];
                }
                
                renderArrangements();
            }
        }

        // Modal schließen
        function closeArrangementModal() {
            console.log('Modal schließen');
            document.getElementById('arrangementModal').classList.remove('active');
            document.body.style.overflow = '';
            currentGuestId = null;
            currentArrangements = {};
        }

        // Arrangements speichern
        async function saveArrangements() {
            if (!currentGuestId) return;
            
            try {
                console.log('Speichere Arrangements für Gast:', currentGuestId);
                
                // Bemerkung aus dem Textfeld holen
                const guestRemark = document.getElementById('guestRemarkInput').value;
                
                const payload = {
                    guest_id: currentGuestId,
                    arrangements: currentArrangements,
                    guest_remark: guestRemark
                };
                
                console.log('Payload:', payload);
                
                const response = await fetch('save-arrangements.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload)
                });
                
                console.log('Response status:', response.status);
                
                // Response text lesen für bessere Fehlerdiagnose
                const responseText = await response.text();
                console.log('Response text:', responseText);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}\nResponse: ${responseText}`);
                }
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (jsonError) {
                    console.error('JSON Parse Error:', jsonError);
                    throw new Error(`JSON Parse Error: ${jsonError.message}\nResponse: ${responseText.substring(0, 500)}`);
                }
                
                console.log('Speichern Ergebnis:', result);
                
                if (result.success) {
                    closeArrangementModal();
                    // Seite neu laden um Änderungen zu sehen
                    window.location.reload();
                } else {
                    console.error('Server error details:', result);
                    alert('Fehler beim Speichern: ' + (result.error || 'Unbekannter Fehler') + 
                          (result.details ? '\n\nDetails: ' + result.details : ''));
                }
            } catch (error) {
                console.error('Fetch error:', error);
                alert('Fehler beim Speichern der Arrangements: ' + error.message);
            }
        }

        // Click outside modal to close
        document.getElementById('arrangementModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeArrangementModal();
            }
        });

        // Test-Funktion für Debug
        function testModal() {
            console.log('Test Modal');
            openArrangementModal(1, 'Test Gast', 'Test Bemerkung', 2, 'Tisch 1');
        }
        
        // Mache testModal global verfügbar
        window.testModal = testModal;
        
        // Sync Database Funktion (implementiert)
        async function syncDatabase() {
            console.log('Starting database synchronization...');
            
            // Button visuell deaktivieren mit pulsierender Animation und orange Hintergrund
            const syncButton = document.querySelector('button[onclick="syncDatabase()"]');
            if (syncButton) {
                syncButton.style.opacity = '0.8';
                syncButton.style.pointerEvents = 'none';
                syncButton.style.backgroundColor = '#ff8c00';
                syncButton.innerHTML = '<span>⟲</span>';
                syncButton.style.animation = 'pulse 1.5s ease-in-out infinite';
                
                // Add pulse animation if not exists
                if (!document.querySelector('#pulseAnimation')) {
                    const style = document.createElement('style');
                    style.id = 'pulseAnimation';
                    style.textContent = `
                        @keyframes pulse {
                            0% { transform: scale(1); }
                            50% { transform: scale(1.1); }
                            100% { transform: scale(1); }
                        }
                    `;
                    document.head.appendChild(style);
                }
            }
            
            try {
                const response = await fetch('sync-database.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });
                
                console.log('Response status:', response.status);
                const responseText = await response.text();
                console.log('Response text:', responseText);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}\nResponse: ${responseText}`);
                }
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (jsonError) {
                    throw new Error(`JSON Parse Error: ${jsonError.message}\nResponse: ${responseText.substring(0, 500)}`);
                }
                
                console.log('Sync result:', result);
                
                if (result.success) {
                    alert('✅ Sync erfolgreich');
                    
                    // Seite nach erfolgreichem Sync neu laden
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    alert(`❌ Synchronisation fehlgeschlagen:\n\n${result.error}`);
                }
                
            } catch (error) {
                console.error('Sync error:', error);
                alert(`❌ Fehler bei der Synchronisation:\n\n${error.message}`);
            } finally {
                // Button wieder aktivieren und Styling zurücksetzen
                if (syncButton) {
                    syncButton.style.opacity = '1';
                    syncButton.style.pointerEvents = 'auto';
                    syncButton.style.backgroundColor = '';
                    syncButton.innerHTML = '<span>⟲</span>';
                    syncButton.style.animation = 'none';
                }
            }
        }
        
        // ===== PRINT FUNCTIONALITY =====
        
        // Android-optimierte Print-Funktionen
        function detectMobileDevice() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        }
        
        function printReceipt() {
            console.log('Drucke Tischübersicht als Rechnung');
            
            if (detectMobileDevice()) {
                // Android-optimierter Druck
                const printData = generatePrintableReceipt();
                openMobilePrintDialog(printData, 'receipt');
            } else {
                // Desktop-Druck
                const printWindow = window.open(`print-tisch-receipt.php`, '_blank', 'width=400,height=600');
                printWindow.onload = function() {
                    setTimeout(() => {
                        printWindow.print();
                    }, 500);
                };
            }
        }
        
        function printLabels() {
            console.log('Drucke Tisch-Etiketten');
            
            if (detectMobileDevice()) {
                const printData = generatePrintableLabels();
                openMobilePrintDialog(printData, 'labels');
            } else {
                const printWindow = window.open(`print-tisch-labels.php`, '_blank', 'width=400,height=300');
                printWindow.onload = function() {
                    setTimeout(() => {
                        printWindow.print();
                    }, 500);
                };
            }
        }
        
        function printOverview() {
            console.log('Drucke aktuelle Tischübersicht');
            
            if (detectMobileDevice()) {
                // Für mobile Geräte: Aktuelle Seite drucken
                window.print();
            } else {
                // Für Desktop: Auch aktuelle Seite drucken
                window.print();
            }
        }
        
        function generatePrintableReceipt() {
            // Sammle alle Tischdaten aus der aktuellen Seite
            const tischData = [];
            const rows = document.querySelectorAll('.table tbody tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 4) {
                    const tischCell = cells[0];
                    const anzahlCell = cells[1];
                    const nameCell = cells[2];
                    const bemerkungCell = cells[3];
                    
                    tischData.push({
                        tisch: tischCell.textContent.trim(),
                        anzahl: anzahlCell.textContent.trim(),
                        name: nameCell.textContent.trim(),
                        bemerkung: bemerkungCell.textContent.trim()
                    });
                }
            });
            
            return {
                date: new Date().toLocaleDateString('de-DE'),
                time: new Date().toLocaleTimeString('de-DE'),
                tischData: tischData
            };
        }
        
        function generatePrintableLabels() {
            const tischData = generatePrintableReceipt().tischData;
            return {
                labels: tischData.map(data => ({
                    tisch: data.tisch,
                    name: data.name,
                    anzahl: data.anzahl
                }))
            };
        }
        
        function openMobilePrintDialog(data, type) {
            // Erstelle ein temporäres Print-Fenster für mobile Geräte
            const printContent = generatePrintHTML(data, type);
            const printWindow = window.open('', '_blank', 'width=400,height=600');
            
            printWindow.document.write(printContent);
            printWindow.document.close();
            
            // Android-spezifische Verzögerung für bessere Kompatibilität
            setTimeout(() => {
                printWindow.focus();
                printWindow.print();
            }, 1000);
            
            // Fenster nach Druck schließen
            setTimeout(() => {
                printWindow.close();
            }, 3000);
        }
        
        function generatePrintHTML(data, type) {
            const baseStyles = `
                <style>
                    @page { 
                        margin: 10mm; 
                        size: auto;
                    }
                    body { 
                        font-family: Arial, sans-serif; 
                        font-size: 16px; 
                        line-height: 1.4;
                        margin: 0;
                        padding: 20px;
                        color: #000;
                        background: #fff;
                    }
                    .header { 
                        text-align: center; 
                        border-bottom: 2px solid #000; 
                        padding-bottom: 10px; 
                        margin-bottom: 20px;
                    }
                    .header h1 { 
                        margin: 0; 
                        font-size: 24px; 
                        font-weight: bold;
                    }
                    .header h2 { 
                        margin: 5px 0 0 0; 
                        font-size: 18px; 
                        color: #666;
                    }
                    .content { 
                        margin-bottom: 20px; 
                    }
                    .table { 
                        width: 100%; 
                        border-collapse: collapse; 
                        margin: 15px 0;
                    }
                    .table th, .table td { 
                        border: 1px solid #000; 
                        padding: 8px; 
                        text-align: left;
                    }
                    .table th { 
                        background: #f0f0f0; 
                        font-weight: bold;
                    }
                    .label-item {
                        border: 2px solid #000;
                        margin: 10px 0;
                        padding: 15px;
                        text-align: center;
                        page-break-inside: avoid;
                    }
                    .label-tisch {
                        font-size: 20px;
                        font-weight: bold;
                        margin-bottom: 10px;
                    }
                    .label-details {
                        font-size: 16px;
                    }
                    .footer {
                        margin-top: 30px;
                        text-align: center;
                        font-size: 12px;
                        color: #666;
                        border-top: 1px solid #ccc;
                        padding-top: 10px;
                    }
                </style>
            `;
            
            if (type === 'receipt') {
                return `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Tischübersicht - Rechnung</title>
                        ${baseStyles}
                    </head>
                    <body>
                        <div class="header">
                            <h1>Franz-Senn-Hütte</h1>
                            <h2>Tischübersicht - Rechnung</h2>
                            <p>${data.date} - ${data.time}</p>
                        </div>
                        
                        <div class="content">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Tisch</th>
                                        <th>Anz</th>
                                        <th>Name</th>
                                        <th>Bemerkung</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.tischData.map(row => `
                                        <tr>
                                            <td>${row.tisch}</td>
                                            <td>${row.anzahl}</td>
                                            <td>${row.name}</td>
                                            <td>${row.bemerkung}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="footer">
                            <p>Gedruckt am ${new Date().toLocaleString('de-DE')}</p>
                            <p>Franz-Senn-Hütte - Reservierungssystem</p>
                        </div>
                    </body>
                    </html>
                `;
            } else if (type === 'labels') {
                return `
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Tisch-Etiketten</title>
                        ${baseStyles}
                    </head>
                    <body>
                        <div class="header">
                            <h1>Franz-Senn-Hütte</h1>
                            <h2>Tisch-Etiketten</h2>
                        </div>
                        
                        <div class="content">
                            ${data.labels.map(label => `
                                <div class="label-item">
                                    <div class="label-tisch">${label.tisch}</div>
                                    <div class="label-details">
                                        <strong>${label.name}</strong><br>
                                        ${label.anzahl} Personen
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                        
                        <div class="footer">
                            <p>Gedruckt am ${new Date().toLocaleString('de-DE')}</p>
                        </div>
                    </body>
                    </html>
                `;
            }
            
            return '<html><body><h1>Print Error</h1></body></html>';
        }
        
        // Touch-optimized event handling für mobile Geräte
        if (detectMobileDevice()) {
            // Verbesserte Touch-Interaktion für Print-Buttons
            document.addEventListener('DOMContentLoaded', function() {
                const printButtons = document.querySelectorAll('.print-btn');
                printButtons.forEach(btn => {
                    btn.addEventListener('touchstart', function() {
                        this.style.transform = 'translateY(-1px) scale(0.98)';
                    });
                    
                    btn.addEventListener('touchend', function() {
                        this.style.transform = 'translateY(-2px) scale(1)';
                    });
                });
            });
        }
    </script>
</body>
</html>
