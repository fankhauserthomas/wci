<?php
// tisch-uebersicht-resid.php - Tischübersicht für spezielle Reservierungs-ID
require_once 'hp-db-config.php';

// Reservierungs-ID Parameter prüfen
$resid = isset($_GET['resid']) ? intval($_GET['resid']) : 0;
if ($resid <= 0) {
    die('Fehler: Keine gültige Reservierungs-ID angegeben. Verwendung: tisch-uebersicht-resid.php?resid=123');
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
                $html .= '<span class="cell-bemerkung ' . $timeClass . '">' . htmlspecialchars($entry['bem'] ?? '') . '</span>';
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

// Daten von HP-Datenbank laden - nur für spezielle Reservierungs-ID
function getTischUebersichtForResid($resid) {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        return ['error' => 'HP-Datenbank nicht verfügbar'];
    }
    
    try {
        // Query angepasst für spezielle Reservierungs-ID
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
                WHERE hp.resid = " . intval($resid) . "
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
                        $cleanBem = trim($detail['bem'] ?? '');
                        $arrangementData[$arrId]['entries'][] = $detail['anz'] . ' ' . $cleanBem;
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
                    $cellContent = trim($cellContent); // Führende/nachfolgende Leerzeichen und LF entfernen
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

$tischData = getTischUebersichtForResid($resid);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tischübersicht für Reservierung <?php echo htmlspecialchars($resid); ?> - Franz-Senn-Hütte</title>
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
            height: 100vh;
            gap: 10px;
            padding: 10px;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 350px; /* Mindestbreite auf 350px reduziert */
            border: 2px solid #adb5bd;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            table-layout: fixed; /* Für gleichmäßige Spaltenbreite */
        }
        
        .arrangements-table {
            margin-top: 0;
        }
        
        /* Arrangements Caption Styling */
        .arrangement-caption {
            background: #3498db !important;
            color: white !important;
            font-size: 1.1rem !important;
            font-weight: 600 !important;
            text-align: center !important;
            padding: 12px 8px !important;
            border-bottom: 2px solid #2980b9 !important;
        }
        
        /* Details Caption Styling */
        .details-caption {
            background: #e74c3c !important;
            color: white !important;
            font-size: 1.1rem !important;
            font-weight: 600 !important;
            text-align: center !important;
            padding: 12px 8px !important;
            border-bottom: 2px solid #c0392b !important;
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
        }
        
        .table thead tr:first-child {
            background: #e74c3c; /* Details Caption - Rot */
        }
        
        .table thead tr:last-child {
            background: #2ecc71; /* Standard Header - Grün */
        }
        
        .arrangements-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .arrangements-table thead tr:first-child {
            background: #3498db; /* Arrangements Caption - Blau */
        }
        
        .arrangements-table thead tr:last-child {
            background: #2ecc71; /* Standard Header - Grün */
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
            line-height: 1.1 !important;
            margin: 2px; /* leichter Außenabstand */
            vertical-align: top !important;
        }
        
        .table td.tisch-cell br {
            line-height: 0.8 !important;
            margin: 1px 0 !important;
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

        /* Dynamische Zeilenhöhe für Arrangement-Zeilen */
        .table tbody tr {
            height: auto;
        }

        .table tbody td {
            height: auto;
            vertical-align: top;
        }

        /* Spezielle Regeln für Arrangement-Tabelle */
        .arrangements-table tbody tr {
            height: auto !important;
            min-height: 40px; /* Mindesthöhe entsprechend der Zellen */
        }

        .arrangements-table tbody td {
            height: auto !important;
            min-height: 40px; /* Mindesthöhe entsprechend der leeren Zellen */
            vertical-align: middle; /* Zentriert den Inhalt vertikal */
        }

        /* Deutlich höhere Zeilen wenn alle Arrangement-Zellen leer sind */
        .arrangements-table tbody tr.all-empty {
            min-height: 60px; /* Deutlich höher für komplett leere Zeilen */
        }

        .arrangements-table tbody tr.all-empty td {
            min-height: 60px; /* Entsprechend höhere Zellen */
            padding: 15px 2px; /* Mehr vertikales Padding für bessere Klickbarkeit */
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
            text-align: center !important;
            word-wrap: break-word;
            white-space: normal;
            line-height: 1.1;
            font-size: 1.0rem;
            margin-left: 2px; /* kleiner Versatz */
            padding: 4px 6px !important;
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
            min-width: auto; /* Anpassung durch table-layout: fixed */
            white-space: normal;
            line-height: 1.0;
            padding: 3px 2px; /* Kompakteres Padding für schmale Spalten */
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
            height: auto; /* Dynamische Höhe für Arrangement-Zellen */
        }
        
        /* Große Schrift für einzelne Zahlen */
        .arrangement-cell.single-value {
            font-size: 1.1rem;
        }
        
        /* Kleine Schrift für mehrzeilige Inhalte - linksbündig */
        .arrangement-cell.multi-value {
            font-size: 0.75rem;
            line-height: 1.0;
            margin: 0;
            text-align: left;
            padding: 2px 4px;
        }
        
        /* Oval-Darstellung für Anzahl in Zellen */
        .cell-entry {
            display: inline-flex;
            align-items: center;
            margin: 0 1px 0 0; /* Kompaktere Abstände für schmale Spalten */
            white-space: nowrap;
            vertical-align: top;
        }
        
        .cell-anzahl {
            background: #27ae60;
            color: white;
            padding: 1px 6px; /* Kompakteres Padding für schmale Spalten */
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.65rem; /* Kleinere Schrift für bessere Platznutzung */
            min-width: 20px; /* Schmalere Mindestbreite */
            text-align: center;
            margin-right: 2px; /* Reduzierte Abstände */
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
            font-size: 0.7rem; /* Leicht kleinere Schrift */
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
            min-height: 40px; /* Mindesthöhe für leere Zellen */
            padding: 8px 2px; /* Etwas mehr Padding für bessere Klickbarkeit */
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
        
        /* Spezielle Behandlung für leere, editierbare Zellen */
        .arrangement-cell.editable.empty {
            min-height: 40px; /* Mindesthöhe für bessere Klickbarkeit */
            /* Flexbox entfernt - verwendet normale Tabellenzell-Darstellung */
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
            width: auto; /* Gleichmäßige Breite durch table-layout: fixed */
            font-size: 0.75rem;
            padding: 3px 4px;
            line-height: 1.0;
            border-right: 2px solid rgba(255, 255, 255, 0.3) !important;
        }
        
        /* Feste Breiten für die Grundspalten */
        .table th:nth-child(1), /* Tisch */
        .table td:nth-child(1) {
            width: 80px;
        }
        
        .table th:nth-child(2), /* Anz */
        .table td:nth-child(2) {
            width: 50px;
        }
        
        .table th:nth-child(3), /* Name */
        .table td:nth-child(3) {
            width: 120px;
        }
        
        .table th:nth-child(4), /* Bemerkung */
        .table td:nth-child(4) {
            width: 120px;
        }
        
        /* Arrangements-Tabelle: Alle Arrangement-Spalten gleich breit */
        .arrangements-table {
            table-layout: fixed;
        }
        
        .arrangements-table .arrangement-header {
            width: auto; /* Wird durch table-layout: fixed gleichmäßig verteilt */
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($tischData['error'])): ?>
            <div class="error">
                <h2>❌ Fehler</h2>
                <p><?php echo htmlspecialchars($tischData['error']); ?></p>
                <br>
                <a href="tisch-uebersicht.php" class="back-button">Zurück zur Tischübersicht</a>
            </div>
        <?php elseif (empty($tischData)): ?>
            <div class="info">
                <h2>ℹ️ Keine Daten</h2>
                <p>Für die Reservierungs-ID <strong><?php echo htmlspecialchars($resid); ?></strong> wurden keine aktiven Tischplätze gefunden.</p>
                <br>
                <a href="tisch-uebersicht.php" class="back-button">Zurück zur Tischübersicht</a>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th colspan="4" class="details-caption">
                                Details - Reservierung #<?php echo htmlspecialchars($resid); ?>
                            </th>
                        </tr>
                        <tr>
                            <th style="min-width: 60px;">Tisch</th>
                            <th style="min-width: 40px;">Anz</th>
                            <th style="min-width: 120px;">Name</th>
                            <th style="min-width: 120px;">Bemerkung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowIndex = 0;
                        foreach ($tischData as $row): 
                            // Row-Klasse basierend auf colname bestimmen
                            $rowClass = '';
                            $colname = strtolower($row['colname'] ?? '');
                            
                            // Mapping der Farbnamen
                            $colorMap = [
                                'lavender' => 'row-lavender',
                                'powderblue' => 'row-powderblue', 
                                'floralwhite' => 'row-floralwhite',
                                'aliceblue' => 'row-aliceblue',
                                'lightgray' => 'row-lightgray',
                                'lightblue' => 'row-lightblue',
                                'lightgreen' => 'row-lightgreen',
                                'lightyellow' => 'row-lightyellow',
                                'lightpink' => 'row-lightpink',
                                'lightcyan' => 'row-lightcyan'
                            ];
                            
                            if (isset($colorMap[$colname])) {
                                $rowClass = $colorMap[$colname];
                                // Alternierung hinzufügen (jede zweite Zeile dunkler)
                                if ($rowIndex % 2 == 1) {
                                    $rowClass .= ' dark';
                                }
                            } else {
                                // Fallback: Gruppierung nach Tischnummer
                                $rowClass = 'row-group-' . ($rowIndex % 2);
                            }
                            
                            $rowIndex++;
                        ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="tisch-cell">
                                    <?php 
                                    // Tische kompakter darstellen - mehrfache Zeilenwechsel durch einzelne ersetzen
                                    $tischText = $row['Tisch'] ?? '';
                                    $cleanTischText = preg_replace("/\n+/", "\n", trim($tischText));
                                    echo nl2br(htmlspecialchars($cleanTischText));
                                    ?>
                                </td>
                                <td class="anz-cell"><?php echo htmlspecialchars($row['anz'] ?? '0'); ?></td>
                                <td class="nam-cell"><?php echo htmlspecialchars($row['nam'] ?? ''); ?></td>
                                <td class="bem-cell"><?php echo htmlspecialchars($row['bem'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Arrangements Tabelle -->
                <table class="table arrangements-table">
                    <thead>
                        <tr>
                            <th colspan="<?php 
                            // Nur die Anzahl der Arrangements zählen
                            $arrangementCount = 0;
                            if (!empty($tischData) && isset($tischData[0]['arrangements'])) {
                                $arrangementCount = count($tischData[0]['arrangements']);
                            }
                            echo $arrangementCount;
                            ?>" class="arrangement-caption">Arrangements</th>
                        </tr>
                        <tr>
                            <?php
                            // Nur Arrangement-Spalten erstellen
                            $arrangements = isset($tischData[0]['arrangements']) ? $tischData[0]['arrangements'] : [];
                            foreach ($arrangements as $arrId => $bezeichnung): ?>
                                <th class="arrangement-header"><?php echo htmlspecialchars($bezeichnung); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowIndex = 0;
                        foreach ($tischData as $row): 
                            // Row-Klasse wie oben bestimmen
                            $rowClass = '';
                            $colname = strtolower($row['colname'] ?? '');
                            
                            if (isset($colorMap[$colname])) {
                                $rowClass = $colorMap[$colname];
                                if ($rowIndex % 2 == 1) {
                                    $rowClass .= ' dark';
                                }
                            } else {
                                $rowClass = 'row-group-' . ($rowIndex % 2);
                            }
                            
                            $rowIndex++;
                            
                            // Prüfen ob alle Arrangement-Zellen in dieser Zeile leer sind
                            $allEmpty = true;
                            foreach ($arrangements as $arrId => $bezeichnung) {
                                $cellContent = $row['arr_' . $arrId] ?? '';
                                if (!empty(trim($cellContent))) {
                                    $allEmpty = false;
                                    break;
                                }
                            }
                            
                            // all-empty Klasse hinzufügen wenn alle Zellen leer sind
                            if ($allEmpty) {
                                $rowClass .= ' all-empty';
                            }
                        ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <?php foreach ($arrangements as $arrId => $bezeichnung): 
                                    $cellContent = $row['arr_' . $arrId] ?? '';
                                    $timeClass = $row['arr_' . $arrId . '_timeClass'] ?? 'time-old';
                                    
                                    // Prüfen ob mehrzeilig
                                    $isMultiline = strpos($cellContent, "\n") !== false;
                                    $hasContent = !empty(trim($cellContent));
                                    
                                    $cellClasses = ['arrangement-cell', 'editable'];
                                    
                                    if ($hasContent) {
                                        $cellClasses[] = 'has-value';
                                        $cellClasses[] = $timeClass;
                                        
                                        if ($isMultiline) {
                                            $cellClasses[] = 'multi-value';
                                        } else {
                                            $cellClasses[] = 'single-value';
                                        }
                                    } else {
                                        $cellClasses[] = 'empty';
                                    }
                                    
                                    $cellClassStr = implode(' ', $cellClasses);
                                ?>
                                    <td class="<?php echo $cellClassStr; ?>" 
                                        data-guest-id="<?php echo intval($row['iid']); ?>" 
                                        data-arr-id="<?php echo $arrId; ?>" 
                                        data-arr-name="<?php echo htmlspecialchars($bezeichnung); ?>">
                                        <?php 
                                        if ($hasContent) {
                                            if ($isMultiline || strpos($cellContent, ' ') !== false) {
                                                // Parsing für ovale Darstellung
                                                $entries = parseArrangementContent($cellContent);
                                                echo createArrangementCellHtml($entries, $timeClass);
                                            } else {
                                                // Einfache Zahl
                                                echo htmlspecialchars($cellContent);
                                            }
                                        } else {
                                            echo '';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        let currentEditingCell = null; // Verhindere mehrfache Bearbeitung
        
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
                }); // Ende click event listener
            } // Ende if tableContainer
        }); // Ende DOMContentLoaded
        
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
                window.location.href = 'tisch-uebersicht.php';
            } else if (e.key === 'Backspace' && !currentEditingCell) {
                window.location.href = 'tisch-uebersicht.php';
            }
        });
    </script>
</body>
</html>
