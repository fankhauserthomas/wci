<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Check database connection
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }
    
    // Get parameters
    $startDate = $_GET['start'] ?? null;
    $endDate = $_GET['end'] ?? null;
    
    // Default date range: today - 1 day to today + 3 days
    if (!$startDate) {
        $startDate = date('Y-m-d', strtotime('-1 day'));
    }
    if (!$endDate) {
        $endDate = date('Y-m-d', strtotime('+3 days'));
    }
    
    // Validate dates
    if (!DateTime::createFromFormat('Y-m-d', $startDate) || !DateTime::createFromFormat('Y-m-d', $endDate)) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD');
    }
    
    // Query für Master-Reservierungen (gestapelte Ansicht)
    $masterSql = "
        SELECT 
            r.id,
            r.av_id,
            r.anreise,
            r.abreise,
            r.lager,
            r.betten,
            r.dz,
            r.sonder,
            r.nachname,
            r.vorname,
            r.bem,
            r.bem_av,
            r.storno,
            r.hund,
            a.kbez as arrangement_name,
            a.bez as arrangement_full,
            (COALESCE(r.sonder, 0) + COALESCE(r.lager, 0) + COALESCE(r.betten, 0) + COALESCE(r.dz, 0)) as total_capacity
        FROM `AV-Res` r
        LEFT JOIN `arr` a ON r.arr = a.ID
        WHERE r.anreise IS NOT NULL 
            AND r.abreise IS NOT NULL
            AND r.anreise <= ?
            AND r.abreise >= ?
            AND (r.storno IS NULL OR r.storno = 0)
        ORDER BY r.anreise ASC, r.nachname ASC
    ";
    
    // Query für Zimmer-Details aus AV_ResDet
    $detailSql = "
        SELECT 
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
            z.caption as zimmer_name,
            z.kapazitaet as zimmer_capacity,
            z.sort as zimmer_sort,
            a.kbez as arrangement_kbez,
            a.bez as arrangement_bez,
            r.nachname,
            r.vorname,
            r.av_id
        FROM AV_ResDet rd
        LEFT JOIN zp_zimmer z ON rd.zimID = z.id
        LEFT JOIN `AV-Res` r ON rd.resid = r.id
        LEFT JOIN arr a ON rd.arr = a.ID
        WHERE rd.von <= ? 
            AND rd.bis >= ?
            AND z.visible = 1
            AND (r.storno IS NULL OR r.storno = 0)
        ORDER BY z.sort ASC, rd.von ASC
    ";
    
    // Master-Reservierungen ausführen
    $stmt = $mysqli->prepare($masterSql);
    if (!$stmt) {
        throw new Exception('SQL prepare failed: ' . $mysqli->error);
    }
    $stmt->bind_param('ss', $endDate, $startDate);
    if (!$stmt->execute()) {
        throw new Exception('SQL execution failed: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    $masterReservations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Zimmer-Details ausführen
    $stmt = $mysqli->prepare($detailSql);
    if (!$stmt) {
        throw new Exception('SQL prepare failed: ' . $mysqli->error);
    }
    $stmt->bind_param('ss', $endDate, $startDate);
    if (!$stmt->execute()) {
        throw new Exception('SQL execution failed: ' . $stmt->error);
    }
    $result = $stmt->get_result();
    $roomDetails = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Create lookup for disposed reservations
    $disposedReservations = [];
    foreach ($roomDetails as $detail) {
        if ($detail['resid']) {
            $disposedReservations[$detail['resid']] = true;
        }
    }
    
    // Debug: Log disposition lookup creation
    error_log("Creating disposition lookup...");
    error_log("Room details count: " . count($roomDetails));
    foreach ($roomDetails as $detail) {
        if ($detail['resid']) {
            error_log("Room detail resid: " . $detail['resid']);
        }
    }
    error_log("Disposed reservations: " . json_encode($disposedReservations));

    // Process Master data for timeline
    $masterTimelineData = [];
    $statistics = [
        'total_reservations' => 0,
        'total_guests' => 0,
        'active_today' => 0,
        'upcoming' => 0,
        'disposed' => 0,
        'undisposed' => 0
    ];
    
    $today = date('Y-m-d');
    
    foreach ($masterReservations as $res) {
        // Skip if no capacity
        if ($res['total_capacity'] <= 0) {
            continue;
        }
        
        $anreise = new DateTime($res['anreise']);
        $abreise = new DateTime($res['abreise']);
        $todayDate = new DateTime($today);
        
        // Create guest name
        $guestName = trim(($res['nachname'] ?? '') . ' ' . ($res['vorname'] ?? ''));
        if (empty($guestName)) {
            $guestName = 'Unbekannt';
        }
        
        // Create capacity info
        $capacityParts = [];
        if ($res['lager'] > 0) $capacityParts[] = $res['lager'] . ' Lager';
        if ($res['betten'] > 0) $capacityParts[] = $res['betten'] . ' Betten';
        if ($res['dz'] > 0) $capacityParts[] = $res['dz'] . ' DZ';
        if ($res['sonder'] > 0) $capacityParts[] = $res['sonder'] . ' Sonder';
        $capacityText = implode(', ', $capacityParts);
        
        // Check if this reservation has room assignments (is disposed)
        $isDisposed = isset($disposedReservations[$res['id']]);
        
        // Create timeline item
        $timelineItem = [
            'id' => 'res_' . $res['id'],
            'content' => $guestName . '<br><small>' . $capacityText . '</small>',
            'start' => $anreise->format('Y-m-d\TH:i:s'),
            'end' => $abreise->format('Y-m-d\TH:i:s'),
            'group' => 'overview',
            'title' => $guestName . '\n' . 
                      'Aufenthalt: ' . $anreise->format('d.m.Y') . ' - ' . $abreise->format('d.m.Y') . '\n' .
                      'Kapazität: ' . $res['total_capacity'] . ' (' . $capacityText . ')' . '\n' .
                      'Arrangement: ' . ($res['arrangement_name'] ?? 'Nicht zugewiesen') . 
                      ($res['bem'] ? '\nBemerkung: ' . $res['bem'] : '') .
                      ($res['hund'] ? '\nMit Hund' : '') .
                      ($isDisposed ? '\n✓ Disponiert' : '\n⚠ Nicht disponiert'),
            'className' => 'reservation-item' . ($isDisposed ? ' disposed' : ' undisposed'),
            // Add fields needed for timeline rendering
            'nachname' => $res['nachname'],
            'vorname' => $res['vorname'],
            'name' => $guestName,
            'capacity' => $res['total_capacity'],
            'arrangement_name' => $res['arrangement_name'],
            'arr_kbez' => $res['arrangement_name'], // fallback
            'has_dog' => (bool)$res['hund'],
            'hund' => (bool)$res['hund'],
            'data' => [
                'id' => $res['id'],
                'av_id' => $res['av_id'],
                'guest_name' => $guestName,
                'capacity' => $res['total_capacity'],
                'capacity_details' => [
                    'lager' => (int)$res['lager'],
                    'betten' => (int)$res['betten'],
                    'dz' => (int)$res['dz'],
                    'sonder' => (int)$res['sonder']
                ],
                'arrangement' => $res['arrangement_name'],
                'has_dog' => (bool)$res['hund'],
                'notes' => $res['bem'],
                'av_notes' => $res['bem_av'],
                'is_disposed' => $isDisposed  // NEW: Flag indicating if reservation has room assignments
            ]
        ];
        
        $masterTimelineData[] = $timelineItem;
        
        // Update statistics
        $statistics['total_reservations']++;
        $statistics['total_guests'] += $res['total_capacity'];
        
        // Update disposition statistics
        if ($isDisposed) {
            $statistics['disposed']++;
        } else {
            $statistics['undisposed']++;
        }
        
        // Check if active today
        if ($anreise <= $todayDate && $abreise >= $todayDate) {
            $statistics['active_today']++;
        }
        
        // Check if upcoming (starts after today)
        if ($anreise > $todayDate) {
            $statistics['upcoming']++;
        }
    }
    
    // Process Room Details for timeline
    $roomTimelineData = [];
    
    foreach ($roomDetails as $detail) {
        $von = new DateTime($detail['von']);
        $bis = new DateTime($detail['bis']);
        
        // Create guest name
        $guestName = trim(($detail['nachname'] ?? '') . ' ' . ($detail['vorname'] ?? ''));
        if (empty($guestName)) {
            $guestName = 'Unbekannt';
        }
        
        // Create content with arrangement
        $content = $guestName;
        if ($detail['caption']) {
            $content .= '<br><small>' . $detail['caption'] . '</small>';
        }
        if ($detail['arrangement_kbez']) {
            $content .= '<br><span class="arrangement">' . $detail['arrangement_kbez'] . '</span>';
        }
        if ($detail['hund']) {
            $content .= ' 🐕';
        }
        
        // Create timeline item for room
        $roomTimelineItem = [
            'id' => 'room_detail_' . $detail['detail_id'],
            'content' => $content,
            'start' => $von->format('Y-m-d\TH:i:s'),
            'end' => $bis->format('Y-m-d\TH:i:s'),
            'group' => 'room_' . $detail['zimID'],
            'room_id' => $detail['zimID'],
            'title' => $guestName . '\n' . 
                      'Zimmer: ' . $detail['zimmer_name'] . '\n' .
                      'Aufenthalt: ' . $von->format('d.m.Y') . ' - ' . $bis->format('d.m.Y') . '\n' .
                      'Anzahl: ' . $detail['anz'] . '\n' .
                      'Arrangement: ' . ($detail['arrangement_kbez'] ?? 'Nicht zugewiesen') . 
                      ($detail['hund'] ? '\nMit Hund' : ''),
            'className' => 'room-reservation-item',
            'style' => 'background-color: ' . ($detail['color'] ?? '#3498db') . ';',
            'data' => [
                'detail_id' => $detail['detail_id'],
                'res_id' => $detail['resid'],
                'av_id' => $detail['av_id'],
                'room_id' => $detail['zimID'],
                'room_name' => $detail['zimmer_name'],
                'guest_name' => $guestName,
                'capacity' => $detail['anz'],
                'arrangement' => $detail['arrangement_kbez'],
                'has_dog' => (bool)$detail['hund'],
                'color' => $detail['color'] ?? '#3498db',
                'caption' => $detail['caption']
            ]
        ];
        
        $roomTimelineData[] = $roomTimelineItem;
    }
    
    // Create groups for timeline
    $groups = [
        [
            'id' => 'overview',
            'content' => 'Reservierungen<br><small>Gestapelte Ansicht</small>',
            'title' => 'Alle Reservierungen in gestapelter Ansicht'
        ]
    ];
    
    // Response
    echo json_encode([
        'success' => true,
        'data' => [
            'timeline_items' => $masterTimelineData,
            'room_details' => $roomTimelineData,
            'groups' => $groups,
            'statistics' => $statistics,
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'meta' => [
                'master_found' => count($masterReservations),
                'master_with_capacity' => count($masterTimelineData),
                'room_details_found' => count($roomDetails),
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} catch (Error $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
