<?php
// tisch-uebersicht.php - Tischübersicht basiert auf der HP-Datenbank View
require_once 'auth-simple.php';
require_once 'hp-db-config.php';

// Authentifizierung prüfen
if (!AuthManager::checkSession()) {
    header('Location: login.html');
    exit;
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
                    hp_art.bez,
                    hp_art.sort
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
                    
                    if (!isset($arrangementData[$arrId])) {
                        $arrangementData[$arrId] = [];
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
                
                if (isset($arrangementData[$arrId])) {
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
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            border: 1px solid #e9ecef;
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
            border-right: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 0.8rem;
            white-space: nowrap;
            line-height: 1.1;
        }
        
        .table td {
            padding: 3px 4px;
            border-bottom: 1px solid #e9ecef;
            border-right: 1px solid #f1f3f4;
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
            font-size: 1.6rem !important;
            font-weight: bold;
            line-height: 1.0 !important;
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
            border-bottom: 1px solid #e9ecef;
        }
        
        .table td:last-child,
        .table th:last-child {
            border-right: none;
        }
        
        .tisch-cell {
            font-weight: 600;
            color: #2ecc71;
            max-width: 150px;
            text-align: left;
            word-wrap: break-word;
            white-space: pre-line;
            line-height: 1.1;
            font-size: 0.85rem;
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
        
        /* Kleine Schrift für mehrzeilige Inhalte */
        .arrangement-cell.multi-value {
            font-size: 0.75rem;
            line-height: 1.0;
            margin: 1px;
        }
        
        .arrangement-cell.has-value {
            color: #27ae60;
            font-weight: 700;
        }
        
        .arrangement-cell.empty {
            color: #6c757d;
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
            border-right: 1px solid rgba(255, 255, 255, 0.2) !important;
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
                height: 100vh;
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
                            <tr class="<?php echo $rowClass; ?>">
                                <td class="tisch-cell"><?php 
                                    $tischText = $row['Tisch'] ?? '-';
                                    // Doppelte/mehrfache LF durch einfache ersetzen und direkt <br> verwenden
                                    $cleanTischText = preg_replace('/\n+/', '<br>', trim($tischText));
                                    echo htmlspecialchars_decode($cleanTischText, ENT_NOQUOTES); 
                                ?></td>
                                <td class="anz-cell"><?php echo htmlspecialchars($row['anz'] ?? '0'); ?></td>
                                <td class="nam-cell">
                                    <?php echo htmlspecialchars($row['nam'] ?? '-'); ?>
                                </td>
                                <td class="bem-cell clickable" onclick="openArrangementModal(<?php echo $row['iid']; ?>, '<?php echo htmlspecialchars($row['nam'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['bem'] ?? '', ENT_QUOTES); ?>', <?php echo $row['anz']; ?>, '<?php echo htmlspecialchars($row['Tisch'], ENT_QUOTES); ?>')"><?php echo htmlspecialchars($row['bem'] ?? '-'); ?></td>
                                
                                <?php 
                                // Dynamische Arrangement-Spalten
                                if (isset($row['arrangements'])) {
                                    foreach ($row['arrangements'] as $arrId => $bezeichnung) {
                                        $content = isset($row['arr_' . $arrId]) ? $row['arr_' . $arrId] : '';
                                        $hasValue = !empty($content);
                                        
                                        // Bestimme ob es mehrzeilig ist oder nur eine Zahl
                                        $isMultiLine = $hasValue && (strpos($content, "\n") !== false || !is_numeric(trim($content)));
                                        $isSingleNumber = $hasValue && is_numeric(trim($content)) && strpos($content, "\n") === false;
                                        
                                        $cssClass = 'arrangement-cell ';
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
                                        
                                        echo '<td class="' . $cssClass . '">';
                                        if ($hasValue) {
                                            // Doppelte/mehrfache LF durch einfache <br> ersetzen
                                            $cleanContent = preg_replace('/\n+/', '<br>', trim($content));
                                            // Erst HTML escapen, dann <br> wieder als HTML-Tag zulassen
                                            $escapedContent = htmlspecialchars($cleanContent);
                                            $finalContent = str_replace('&lt;br&gt;', '<br>', $escapedContent);
                                            echo $finalContent;
                                        } else {
                                            echo '-';
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
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
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
    </script>
</body>
</html>
