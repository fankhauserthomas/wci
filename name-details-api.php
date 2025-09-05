<?php
// name-details-api.php - API für Namen-Details

header('Content-Type: application/json');

// Anti-Cache Headers
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require 'config.php';

try {
    $avId = $_GET['av_id'] ?? '';
    
    if (empty($avId) || !is_numeric($avId)) {
        echo json_encode([
            'success' => false,
            'error' => 'Ungültige AV-ID'
        ]);
        exit;
    }
    
    // Person-Details laden
    $query = "
        SELECT 
            arn.av_id,
            arn.nachname,
            arn.vorname,
            DATE_FORMAT(arn.gebdat, '%d.%m.%Y') AS geburtsdatum,
            arn.checkin_zeit,
            arn.checkout_zeit,
            arn.bemerkung,
            arn.res_id,
            r.nachname AS res_nachname,
            r.vorname AS res_vorname,
            DATE_FORMAT(r.anreise, '%d.%m.%Y') AS res_anreise,
            DATE_FORMAT(r.abreise, '%d.%m.%Y') AS res_abreise
        FROM \`AV-ResNamen\` arn
        LEFT JOIN Reservierungen r ON arn.res_id = r.id
        WHERE arn.av_id = ?
    ";
    
    $stmt = $mysqli->prepare($query);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('i', $avId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $person = [
            'av_id' => $row['av_id'],
            'nachname' => $row['nachname'],
            'vorname' => $row['vorname'],
            'geburtsdatum' => $row['geburtsdatum'],
            'checkin_zeit' => $row['checkin_zeit'],
            'checkout_zeit' => $row['checkout_zeit'],
            'bemerkung' => $row['bemerkung']
        ];
        
        // Reservierungs-Info hinzufügen falls vorhanden
        if ($row['res_id']) {
            $person['reservation'] = [
                'id' => $row['res_id'],
                'nachname' => $row['res_nachname'],
                'vorname' => $row['res_vorname'],
                'anreise' => $row['res_anreise'],
                'abreise' => $row['res_abreise']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'person' => $person
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Person nicht gefunden'
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log('Name Details API Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage()
    ]);
}

$mysqli->close();
?>
