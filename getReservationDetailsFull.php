<?php
// getReservationDetailsFull.php - Vollständige API für ReservationDetails.html
header('Content-Type: application/json; charset=utf-8');
require 'config.php';

// ID validieren
$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Reservierungs-ID']);
    exit;
}

try {
    // Vollständige Reservierungsdaten abfragen mit allen Feldern
    $sql = "
    SELECT
        r.id,
        r.av_id,
        r.anreise,
        r.abreise,
        IFNULL(r.nachname, '') AS nachname,
        IFNULL(r.vorname, '') AS vorname,
        IFNULL(r.handy, '') AS handy,
        IFNULL(r.email, '') AS email,
        IFNULL(r.bem, '') AS bem,
        IFNULL(r.bem_av, '') AS bem_av,
        IFNULL(r.lager, 0) AS lager,
        IFNULL(r.betten, 0) AS betten,
        IFNULL(r.dz, 0) AS dz,
        IFNULL(r.sonder, 0) AS sonder,
        IFNULL(r.arr, 0) AS arr,
        IFNULL(r.origin, 0) AS origin,
        IFNULL(r.hund, 0) AS hund,
        IFNULL(r.storno, 0) AS storno
    FROM `AV-Res` r
    WHERE r.id = ?
    LIMIT 1
    ";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL-Vorbereitung fehlgeschlagen: ' . $mysqli->error);
    }
    
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Reservierung nicht gefunden']);
        exit;
    }
    
    $data = $result->fetch_assoc();
    $stmt->close();
    
    // Datentypen konvertieren
    $data['id'] = (int)$data['id'];
    $data['av_id'] = (int)$data['av_id'];
    $data['lager'] = (int)$data['lager'];
    $data['betten'] = (int)$data['betten'];
    $data['dz'] = (int)$data['dz'];
    $data['sonder'] = (int)$data['sonder'];
    $data['arr'] = (int)$data['arr'];
    $data['origin'] = (int)$data['origin'];
    $data['hund'] = (bool)$data['hund'];
    $data['storno'] = (bool)$data['storno'];
    
    // Erfolgreiche Antwort
    echo json_encode([
        'success' => true,
        'data' => $data
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
}
?>
