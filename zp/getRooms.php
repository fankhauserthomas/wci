<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Fehlerbehandlung
error_reporting(E_ALL);
ini_set('display_errors', 0); // Nicht in JSON ausgeben

try {
    // Datenbank-Konfiguration laden
    require 'config.php';
    
    // Parameter für Verfügbarkeitsberechnung (falls gewünscht)
    $startDate = isset($_GET['start']) ? $_GET['start'] : null;
    $endDate = isset($_GET['end']) ? $_GET['end'] : null;
    
    // Zusätzliche Modus-Parameter
    $calcMode = isset($_GET['calc']) ? $_GET['calc'] : null; // 'day' | 'min'
    $dayParam = isset($_GET['day']) ? $_GET['day'] : null;   // YYYY-MM-DD

    // SQL-Query für Zimmer - mit optionaler Verfügbarkeitsberechnung
    if ($calcMode === 'day' && $dayParam) {
        // Tagesbasierte Berechnung: belegte Plätze am gegebenen Tag, frei = kapazitaet - belegt
        $dayTs = strtotime($dayParam);
        if ($dayTs === false) {
            throw new Exception('Ungültiger day-Parameter. Erwartet YYYY-MM-DD.');
        }
        $dayStr = date('Y-m-d', $dayTs);

        $sql = "SELECT 
                    z.id,
                    z.caption,
                    z.kapazitaet,
                    z.sort,
                    z.visible,
                    z.px,
                    z.py,
                    z.col,
                    COALESCE(occ.belegt, 0) AS belegt,
                    GREATEST(0, z.kapazitaet - COALESCE(occ.belegt, 0)) AS frei,
                    GREATEST(0, COALESCE(occ.belegt, 0) - z.kapazitaet) AS ueberbelegt
                FROM zp_zimmer z
                LEFT JOIN (
                    SELECT rd.zimID, SUM(rd.anz) AS belegt
                    FROM AV_ResDet rd
                    JOIN `AV-Res` r ON rd.resid = r.id
                    WHERE rd.von <= ? AND rd.bis > ?
                      AND IFNULL(r.storno, 0) = 0
                    GROUP BY rd.zimID
                ) occ ON occ.zimID = z.id
                WHERE z.visible = 1
                ORDER BY z.sort ASC, z.caption ASC";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare-Fehler: " . $mysqli->error);
        }
        $stmt->bind_param('ss', $dayStr, $dayStr);
        if (!$stmt->execute()) {
            throw new Exception("Execute-Fehler: " . $stmt->error);
        }
        $result = $stmt->get_result();

        if (!$result) {
            throw new Exception("Query-Fehler: " . $mysqli->error);
        }

        $rooms = [];
        while ($row = $result->fetch_assoc()) {
            $rooms[] = [
                'id' => (int)$row['id'],
                'caption' => $row['caption'],
                'capacity' => (int)$row['kapazitaet'],
                'kapazitaet' => (int)$row['kapazitaet'],
                'sort' => (int)$row['sort'],
                'display_name' => $row['caption'] . ' (' . (int)$row['kapazitaet'] . ')',
                'px' => isset($row['px']) ? (int)$row['px'] : 1,
                'py' => isset($row['py']) ? (int)$row['py'] : 1,
                'col' => isset($row['col']) ? $row['col'] : null,
                'belegt' => isset($row['belegt']) ? (int)$row['belegt'] : 0,
                'frei' => isset($row['frei']) ? (int)$row['frei'] : max(0, ((int)$row['kapazitaet']) - ((int)($row['belegt'] ?? 0))),
                'ueberbelegt' => isset($row['ueberbelegt']) ? (int)$row['ueberbelegt'] : max(0, ((int)($row['belegt'] ?? 0)) - ((int)$row['kapazitaet'])),
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $rooms,
            'count' => count($rooms),
            'timestamp' => date('Y-m-d H:i:s'),
            'mode' => 'day',
            'day' => $dayStr
        ], JSON_UNESCAPED_UNICODE);
        return;
    } elseif ($startDate && $endDate) {
        // Validierung und Normalisierung der Datumswerte (Zeitraum ist [start, end) - end exklusiv)
        $startTs = strtotime($startDate);
        $endTs   = strtotime($endDate);
        if ($startTs === false || $endTs === false) {
            throw new Exception('Ungültige Datumswerte. Erwartet YYYY-MM-DD.');
        }
        if ($endTs <= $startTs) {
            throw new Exception('Der Endzeitpunkt muss nach dem Startzeitpunkt liegen.');
        }

        $startStr = date('Y-m-d', $startTs);
        $endStr   = date('Y-m-d', $endTs);

        // 1) Alle sichtbaren Zimmer laden
        $roomsSql = "SELECT id, caption, kapazitaet, sort, visible, px, py, col
                     FROM zp_zimmer
                     WHERE visible = 1
                     ORDER BY sort ASC, caption ASC";
        $roomsRes = $mysqli->query($roomsSql);
        if (!$roomsRes) {
            throw new Exception('Query-Fehler (Zimmer): ' . $mysqli->error);
        }

        $rooms = [];
        $roomCap = [];
        while ($row = $roomsRes->fetch_assoc()) {
            $rid = (int)$row['id'];
            $rooms[$rid] = $row; // Rohdaten für spätere Ausgabe
            $roomCap[$rid] = (int)$row['kapazitaet'];
        }

        // Frühzeitiger Exit, falls keine Zimmer vorhanden
        if (empty($rooms)) {
            echo json_encode([
                'success' => true,
                'data' => [],
                'count' => 0,
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2) Alle relevanten Detailbuchungen für den Zeitraum laden
        // Overlap-Bedingung: rd.von < end AND rd.bis > start (da bis exklusiv ist)
        $detSql = "SELECT rd.zimID AS room_id, rd.anz, rd.von, rd.bis
                   FROM AV_ResDet rd
                   JOIN `AV-Res` r ON rd.resid = r.id
                   WHERE rd.von < ? AND rd.bis > ?
                     AND IFNULL(r.storno, 0) = 0";
        $detStmt = $mysqli->prepare($detSql);
        if (!$detStmt) {
            throw new Exception('Prepare-Fehler (Details): ' . $mysqli->error);
        }
        $detStmt->bind_param('ss', $endStr, $startStr);
        if (!$detStmt->execute()) {
            throw new Exception('Execute-Fehler (Details): ' . $detStmt->error);
        }
        $detRes = $detStmt->get_result();

        // 3) Tagesweise Belegung je Zimmer aggregieren und minimale freie Kapazität bestimmen
        // Wir bilden Tage im Intervall [start, end) ab; Schlüssel als Y-m-d
        $days = [];
        for ($t = $startTs; $t < $endTs; $t += 86400) {
            $days[] = date('Y-m-d', $t);
        }

        // Initial: pro Zimmer pro Tag 0 belegt
        $usageByDay = [];
        foreach ($rooms as $rid => $_) {
            $usageByDay[$rid] = array_fill_keys($days, 0);
        }

        while ($d = $detRes->fetch_assoc()) {
            $rid = (int)$d['room_id'];
            if (!isset($usageByDay[$rid])) continue; // Details für nicht sichtbare Zimmer ignorieren
            $anz = (int)$d['anz'];
            $vonTs = strtotime($d['von']);
            $bisTs = strtotime($d['bis']);
            if ($vonTs === false || $bisTs === false) continue;

            // Überschneidung mit unserem Intervall bestimmen (beachte: bis exklusiv)
            $useStart = max($startTs, $vonTs);
            $useEnd   = min($endTs, $bisTs);
            if ($useEnd <= $useStart) continue;

            for ($t = $useStart; $t < $useEnd; $t += 86400) {
                $key = date('Y-m-d', $t);
                // additiv: mehrere Datensätze können sich am selben Tag summieren
                $usageByDay[$rid][$key] += $anz;
            }
        }

        // 4) Ergebnisliste mit minimaler freier Kapazität je Zimmer aufbauen
        $out = [];
        foreach ($rooms as $rid => $row) {
            $cap = $roomCap[$rid] ?? 0;
            $minFree = $cap;
            $maxUsed = 0;
            foreach ($usageByDay[$rid] as $k => $used) {
                $free = max(0, $cap - (int)$used);
                if ($free < $minFree) $minFree = $free;
                if ($used > $maxUsed) $maxUsed = (int)$used;
                if ($minFree === 0) break; // frühzeitiger Abbruch
            }

            $out[] = [
                'id' => $rid,
                'caption' => $row['caption'],
                'capacity' => (int)$row['kapazitaet'],
                'kapazitaet' => (int)$row['kapazitaet'],
                'sort' => isset($row['sort']) ? (int)$row['sort'] : 0,
                'display_name' => $row['caption'] . ' (' . (int)$row['kapazitaet'] . ')',
                'px' => isset($row['px']) ? (int)$row['px'] : 1,
                'py' => isset($row['py']) ? (int)$row['py'] : 1,
                'col' => isset($row['col']) ? $row['col'] : null,
                'frei' => (int)$minFree,
                'belegt_max' => (int)$maxUsed,
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $out,
            'count' => count($out),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        return;
    } else {
        $sql = "SELECT 
                    id,
                    caption,
                    kapazitaet,
                    sort,
                    visible,
                    px,
                    py,
                    col
                FROM zp_zimmer 
                WHERE visible = 1 
                ORDER BY sort ASC, caption ASC";
        
        $result = $mysqli->query($sql);
    }
    
    if (!$result) {
        throw new Exception("Query-Fehler: " . $mysqli->error);
    }
    
    $rooms = [];
    while ($row = $result->fetch_assoc()) {
        $rooms[] = [
            'id' => (int)$row['id'],
            'caption' => $row['caption'],
            'capacity' => (int)$row['kapazitaet'],
            'kapazitaet' => (int)$row['kapazitaet'],
            'sort' => (int)$row['sort'],
            'display_name' => $row['caption'] . ' (' . (int)$row['kapazitaet'] . ')',
            'px' => isset($row['px']) ? (int)$row['px'] : 1,
            'py' => isset($row['py']) ? (int)$row['py'] : 1,
            'col' => isset($row['col']) ? $row['col'] : null,
            'frei' => null
        ];
    }
    
    // Erfolgreiche Antwort (ohne Zeitbereich)
    echo json_encode([
        'success' => true,
        'data' => $rooms,
        'count' => count($rooms),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Datenbankfehler
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>