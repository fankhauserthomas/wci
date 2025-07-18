<?php
// transport-api.php - API für Transportkosten-Verwaltung

header('Content-Type: application/json');
require 'config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = 'ALLOW_INVALID_DATES'");

$action = $_GET['action'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ungültiges Datum']));
}

switch ($action) {
    case 'get_arrivals':
        // Alle Gäste die heute anreisen mit ihren Namen
        $sql = "
        SELECT 
            r.id as reservation_id,
            r.vorname as reservation_vorname,
            r.nachname as reservation_nachname,
            DATE_FORMAT(r.anreise, '%d.%m.%Y') as anreise,
            DATE_FORMAT(r.abreise, '%d.%m.%Y') as abreise,
            n.id as name_id,
            n.vorname as guest_vorname,
            n.nachname as guest_nachname,
            n.transport,
            CONCAT_WS(' ', r.nachname, r.vorname) as reservation_name
        FROM `AV-Res` r
        LEFT JOIN `AV-ResNamen` n ON r.id = n.av_id
        WHERE DATE(r.anreise) = ? 
        AND r.storno = 0
        AND n.vorname IS NOT NULL
        AND n.nachname IS NOT NULL
        ORDER BY r.nachname, r.vorname, n.nachname, n.vorname
        ";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $guests = [];
        while ($row = $result->fetch_assoc()) {
            $guests[] = [
                'reservation_id' => (int)$row['reservation_id'],
                'reservation_name' => trim($row['reservation_name']),
                'anreise' => $row['anreise'],
                'abreise' => $row['abreise'],
                'name_id' => (int)$row['name_id'],
                'guest_name' => trim($row['guest_nachname'] . ' ' . $row['guest_vorname']),
                'guest_vorname' => $row['guest_vorname'],
                'guest_nachname' => $row['guest_nachname'],
                'transport' => (float)$row['transport']
            ];
        }
        
        echo json_encode($guests);
        break;
        
    case 'update_transport':
        // Transportkosten für einen Gast aktualisieren
        $name_id = $_POST['name_id'] ?? 0;
        $transport_cost = $_POST['transport_cost'] ?? 0;
        
        if (!$name_id) {
            http_response_code(400);
            die(json_encode(['error' => 'Name ID erforderlich']));
        }
        
        $sql = "UPDATE `AV-ResNamen` SET transport = ? WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('di', $transport_cost, $name_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Transportkosten aktualisiert']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Fehler beim Aktualisieren']);
        }
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Aktion']);
}
?>
