<?php
require_once '../config.php';
header('Content-Type: application/json');

try {
    // Datum aus GET-Parameter oder aktuelles Datum
    $date = $_GET['date'] ?? date('Y-m-d');
    $von = $_GET['von'] ?? null;
    $bis = $_GET['bis'] ?? null;
    
    // Wenn von/bis Parameter gesetzt sind, verwende Datumsbereich
    if ($von && $bis) {
        // Validiere Datumsbereich
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) {
            throw new Exception('Ungültiges Datumsformat für Bereich');
        }
        $startDate = $von;
        $endDate = $bis;
    } else {
        // Validiere Einzeldatum
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Ungültiges Datumsformat');
        }
        $startDate = $date;
        $endDate = $date;
    }
    
    // Reservierungen für das angegebene Datum laden - korrekte Struktur basierend auf getZimmerplanData.php
    $reservations = [];
    
    // SQL Query für Zimmer-Details aus AV_ResDet (wie in getZimmerplanData.php)
    $sql = "SELECT 
                rd.ID as detail_id,
                rd.resid,
                rd.zimID,
                rd.von,
                rd.bis,
                rd.anz,
                rd.bez as caption,
                rd.col as color,
                rd.hund,
                rd.arr as detail_arr,
                rd.ParentID,
                rd.note,
                z.caption as zimmer_name,
                z.kapazitaet as zimmer_capacity,
                a.kbez as arrangement_kbez,
                a.bez as arrangement_bez,
                r.nachname,
                r.vorname,
                r.av_id,
                r.anreise,
                r.abreise,
                r.email,
                r.handy,
                r.gruppe,
                r.bem,
                r.storno
            FROM AV_ResDet rd
            LEFT JOIN zp_zimmer z ON rd.zimID = z.id
            LEFT JOIN `AV-Res` r ON rd.resid = r.id
            LEFT JOIN arr a ON rd.arr = a.ID
            WHERE (rd.von <= ? AND rd.bis > ?) OR (rd.von <= ? AND rd.bis > ?)
            AND z.visible = 1
            AND (r.storno IS NULL OR r.storno = 0)
            ORDER BY z.sort ASC, rd.von ASC";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL Prepare Error: ' . $mysqli->error);
    }
    
    // Erweitere Abfrage für Datumsbereich
    $stmt->bind_param('ssss', $endDate, $startDate, $endDate, $startDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Name zusammensetzen
        $fullName = trim(($row['vorname'] ?? '') . ' ' . ($row['nachname'] ?? ''));
        if (empty($fullName)) {
            $fullName = $row['caption'] ?? $row['arrangement_kbez'] ?? 'Unbekannt';
        }
        
        $reservations[] = [
            'id' => $row['detail_id'],
            'resid' => $row['resid'],
            'name' => $fullName,
            'zimmer_id' => $row['zimID'],
            'von' => $row['von'],
            'bis' => $row['bis'],
            'anz' => $row['anz'],
            'caption' => $row['caption'],
            'color' => $row['color'],
            'arrangement' => $row['arrangement_kbez'],
            'arrangement_full' => $row['arrangement_bez'],
            'zimmer_name' => $row['zimmer_name'],
            'av_id' => $row['av_id'],
            'anreise' => $row['anreise'],
            'abreise' => $row['abreise'],
            'email' => $row['email'],
            'handy' => $row['handy'],
            'gruppe' => $row['gruppe'],
            'bem' => $row['bem'],
            'note' => $row['note']
        ];
    }
    
    $stmt->close();
    
    // Zimmer laden (mit aktueller Belegung)
    $rooms = [];
    $sql_rooms = "SELECT id, caption, kapazitaet, kategorie, px, py, col, visible, sort 
                  FROM zp_zimmer 
                  WHERE visible = 1 
                  ORDER BY sort ASC, caption ASC";
    $result_rooms = $mysqli->query($sql_rooms);
    
    if ($result_rooms) {
        while ($room = $result_rooms->fetch_assoc()) {
            // Belegung für dieses Zimmer zählen
            $occupancy = 0;
            foreach ($reservations as $res) {
                if ($res['zimmer_id'] == $room['id']) {
                    $occupancy++;
                }
            }
            
            $rooms[] = [
                'id' => $room['id'],
                'caption' => $room['caption'],
                'kapazitaet' => (int)$room['kapazitaet'],
                'kategorie' => $room['kategorie'],
                'px' => (int)$room['px'],
                'py' => (int)$room['py'],
                'col' => $room['col'],
                'visible' => (int)$room['visible'],
                'sort' => (int)$room['sort'],
                'current_occupancy' => $occupancy
            ];
        }
    }
    
    // Antwort zusammenstellen
    $response = [
        'success' => true,
        'date' => $date,
        'reservations' => $reservations,
        'rooms' => $rooms,
        'summary' => [
            'total_reservations' => count($reservations),
            'total_rooms' => count($rooms),
            'occupied_rooms' => count(array_filter($rooms, function($r) { return $r['current_occupancy'] > 0; }))
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'date' => $_GET['date'] ?? date('Y-m-d')
    ], JSON_UNESCAPED_UNICODE);
}

$mysqli->close();
?>