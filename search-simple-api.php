<?php
// search-simple-api.php - Hierarchische Suche mit korrekter Verknüpfung
// AV-ResNamen.av_id ↔ AV-Res.id

header('Content-Type: application/json');

// Anti-Cache Headers
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require 'config.php';

try {
    $searchTerm = $_GET['q'] ?? '';
    
    if (empty($searchTerm) || strlen($searchTerm) < 2) {
        echo json_encode([
            'success' => true,
            'results' => [],
            'total' => 0
        ]);
        exit;
    }
    
    // Suchterm für LIKE-Queries vorbereiten
    $likeTerm = '%' . $searchTerm . '%';
    $finalResults = [];
    $processedReservations = [];
    
    // 1. Finde alle Reservierungen, die den Suchkriterien entsprechen
    $reservationQuery = "
        SELECT 
            r.id AS res_id,
            r.av_id,
            r.nachname,
            r.vorname,
            r.email,
            r.gruppe,
            DATE_FORMAT(r.anreise, '%d.%m.%Y') AS anreise,
            DATE_FORMAT(r.abreise, '%d.%m.%Y') AS abreise,
            r.sonder,
            r.lager,
            r.betten,
            r.dz,
            r.storno
        FROM `AV-Res` r
        WHERE 
            r.nachname LIKE ? OR
            r.vorname LIKE ? OR
            r.email LIKE ? OR
            r.gruppe LIKE ? OR
            DATE_FORMAT(r.anreise, '%Y-%m-%d') LIKE ? OR
            DATE_FORMAT(r.abreise, '%Y-%m-%d') LIKE ? OR
            DATE_FORMAT(r.anreise, '%d.%m.%Y') LIKE ? OR
            DATE_FORMAT(r.abreise, '%d.%m.%Y') LIKE ?
        ORDER BY r.anreise DESC
        LIMIT 20
    ";
    
    $stmt = $mysqli->prepare($reservationQuery);
    if (!$stmt) {
        throw new Exception('Prepare AV-Res failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('ssssssss', $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm);
    $stmt->execute();
    $reservationResult = $stmt->get_result();
    
    $reservationIDs = [];
    while ($row = $reservationResult->fetch_assoc()) {
        $personenanzahl = intval($row['sonder']) + intval($row['lager']) + intval($row['betten']) + intval($row['dz']);
        
        $title = trim($row['nachname'] . ' ' . $row['vorname']);
        if ($row['gruppe']) {
            $title .= ' (' . $row['gruppe'] . ')';
        }
        
        $reservation = [
            'type' => 'reservation',
            'res_id' => $row['res_id'],
            'av_id' => $row['av_id'],
            'title' => $title,
            'nachname' => $row['nachname'],
            'vorname' => $row['vorname'],
            'email' => $row['email'],
            'gruppe' => $row['gruppe'],
            'anreise' => $row['anreise'],
            'abreise' => $row['abreise'],
            'personenanzahl' => $personenanzahl,
            'storno' => $row['storno'] ? 'Ja' : 'Nein',
            'personen' => []
        ];
        
        $finalResults[] = $reservation;
        $processedReservations[$row['res_id']] = count($finalResults) - 1; // Index merken
        $reservationIDs[] = $row['res_id'];
    }
    $stmt->close();
    
    // 2. Finde alle Namen, die den Suchkriterien entsprechen (auch ohne Reservierungs-Match)
    $allMatchingNamesQuery = "
        SELECT 
            arn.av_id AS res_link_id,
            arn.id as person_id,
            arn.nachname,
            arn.vorname,
            DATE_FORMAT(arn.gebdat, '%d.%m.%Y') AS geburtsdatum,
            arn.bem,
            r.id AS res_id,
            r.nachname AS res_nachname,
            r.vorname AS res_vorname,
            r.email AS res_email,
            r.gruppe AS res_gruppe,
            DATE_FORMAT(r.anreise, '%d.%m.%Y') AS res_anreise,
            DATE_FORMAT(r.abreise, '%d.%m.%Y') AS res_abreise,
            r.sonder,
            r.lager,
            r.betten,
            r.dz,
            r.storno
        FROM `AV-ResNamen` arn
        LEFT JOIN `AV-Res` r ON arn.av_id = r.id
        WHERE 
            arn.nachname LIKE ? OR
            arn.vorname LIKE ? OR
            DATE_FORMAT(arn.gebdat, '%Y-%m-%d') LIKE ? OR
            DATE_FORMAT(arn.gebdat, '%d.%m.%Y') LIKE ? OR
            arn.bem LIKE ?
        ORDER BY r.anreise DESC, arn.nachname, arn.vorname
        LIMIT 50
    ";
    
    $stmt = $mysqli->prepare($allMatchingNamesQuery);
    if (!$stmt) {
        throw new Exception('Prepare AV-ResNamen with JOIN failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('sssss', $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm);
    $stmt->execute();
    $allNamesResult = $stmt->get_result();
    
    while ($row = $allNamesResult->fetch_assoc()) {
        $person = [
            'person_id' => $row['person_id'],
            'title' => trim($row['nachname'] . ' ' . $row['vorname']),
            'nachname' => $row['nachname'],
            'vorname' => $row['vorname'],
            'geburtsdatum' => $row['geburtsdatum'],
            'bem' => $row['bem']
        ];
        
        if ($row['res_id']) {
            // Person gehört zu einer Reservierung
            $res_id = $row['res_id'];
            
            // Wenn Reservierung noch nicht in der Liste ist, hinzufügen
            if (!isset($processedReservations[$res_id])) {
                $personenanzahl = intval($row['sonder']) + intval($row['lager']) + intval($row['betten']) + intval($row['dz']);
                
                $res_title = trim($row['res_nachname'] . ' ' . $row['res_vorname']);
                if ($row['res_gruppe']) {
                    $res_title .= ' (' . $row['res_gruppe'] . ')';
                }
                
                $reservation = [
                    'type' => 'reservation',
                    'res_id' => $res_id,
                    'av_id' => $row['res_link_id'],
                    'title' => $res_title,
                    'nachname' => $row['res_nachname'],
                    'vorname' => $row['res_vorname'],
                    'email' => $row['res_email'],
                    'gruppe' => $row['res_gruppe'],
                    'anreise' => $row['res_anreise'],
                    'abreise' => $row['res_abreise'],
                    'personenanzahl' => $personenanzahl,
                    'storno' => $row['storno'] ? 'Ja' : 'Nein',
                    'personen' => []
                ];
                
                $finalResults[] = $reservation;
                $processedReservations[$res_id] = count($finalResults) - 1;
            }
            
            // Person zur entsprechenden Reservierung hinzufügen
            $reservationIndex = $processedReservations[$res_id];
            $finalResults[$reservationIndex]['personen'][] = $person;
            
        } else {
            // Person ohne Reservierung
            $finalResults[] = [
                'type' => 'person',
                'title' => $person['title'],
                'person' => $person
            ];
        }
    }
    $stmt->close();
    
    // 3. Zusätzlich: Finde Namen ohne Reservierung, die den Suchkriterien entsprechen
    $orphanNamesQuery = "
        SELECT 
            arn.id as person_id,
            arn.nachname,
            arn.vorname,
            DATE_FORMAT(arn.gebdat, '%d.%m.%Y') AS geburtsdatum,
            arn.bem
        FROM `AV-ResNamen` arn
        LEFT JOIN `AV-Res` r ON arn.av_id = r.id
        WHERE 
            r.id IS NULL
            AND (
                arn.nachname LIKE ? OR
                arn.vorname LIKE ? OR
                DATE_FORMAT(arn.gebdat, '%Y-%m-%d') LIKE ? OR
                DATE_FORMAT(arn.gebdat, '%d.%m.%Y') LIKE ? OR
                arn.bem LIKE ?
            )
        ORDER BY arn.nachname, arn.vorname
        LIMIT 10
    ";
    
    $stmt = $mysqli->prepare($orphanNamesQuery);
    if ($stmt) {
        $stmt->bind_param('sssss', $likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm);
        $stmt->execute();
        $orphanResult = $stmt->get_result();
        
        while ($row = $orphanResult->fetch_assoc()) {
            $finalResults[] = [
                'type' => 'person',
                'title' => trim($row['nachname'] . ' ' . $row['vorname']),
                'person' => [
                    'person_id' => $row['person_id'],
                    'title' => trim($row['nachname'] . ' ' . $row['vorname']),
                    'nachname' => $row['nachname'],
                    'vorname' => $row['vorname'],
                    'geburtsdatum' => $row['geburtsdatum'],
                    'bem' => $row['bem']
                ]
            ];
        }
        $stmt->close();
    }
    
    echo json_encode([
        'success' => true,
        'results' => $finalResults,
        'total' => count($finalResults),
        'search_term' => $searchTerm
    ]);
    
} catch (Exception $e) {
    error_log('Simple Search API Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage()
    ]);
}

$mysqli->close();
?>
