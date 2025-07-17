<?php
// addReservation.php - Backend für neue Reservierungen
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';

try {
    // Input lesen
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Keine gültigen JSON-Daten erhalten');
    }
    
    if (empty($data['nachname'])) {
        throw new Exception('Nachname ist ein Pflichtfeld');
    }

    // Felder auslesen und validieren
    $nachname = trim($data['nachname']);
    $vorname = trim($data['vorname'] ?? '');
    $origin = (int)($data['herkunft'] ?? 0); // herkunft wird als origin gespeichert
    $anreise = $data['anreise'] ?? date('Y-m-d');
    $abreise = $data['abreise'] ?? date('Y-m-d', strtotime('+1 day'));
    $arr = (int)($data['arrangement'] ?? 0); // arrangement wird als arr gespeichert
    $dz = (int)($data['dz'] ?? 0);
    $betten = (int)($data['betten'] ?? 0);
    $lager = (int)($data['lager'] ?? 0);
    $sonder = (int)($data['sonder'] ?? 0);
    $bemerkung = trim($data['bemerkung'] ?? '');

    // Validierung
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anreise) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $abreise)) {
        throw new Exception('Ungültiges Datumsformat');
    }
    
    if (strtotime($abreise) <= strtotime($anreise)) {
        throw new Exception('Abreise muss nach Anreise liegen');
    }

    // DB-Insert mit MySQLi (da config.php MySQLi verwendet)
    $mysqli->begin_transaction();

    try {
        // AV_Res einfügen
        $id64 = uniqid(); // Eindeutige ID generieren
        $vorgang = "WebCheckin-" . date('Y-m-d-H-i-s'); // Vorgangsbezeichnung
        $av_id = 0; // Neue Reservierung beginnt mit av_id = 0
        $hund = 0; // Kein Hund standardmäßig
        
        $stmt = $mysqli->prepare("INSERT INTO `AV-Res` (nachname, vorname, origin, anreise, abreise, arr, dz, betten, lager, sonder, bem, av_id, vorgang, id64, hund) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        
        $stmt->bind_param('ssissiiiisissis', $nachname, $vorname, $origin, $anreise, $abreise, $arr, $dz, $betten, $lager, $sonder, $bemerkung, $av_id, $vorgang, $id64, $hund);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $resId = $mysqli->insert_id;
        $stmt->close();

        // TODO: AV_ResDet insert needs proper structure based on schema
        /*
        // AV_ResDet einfügen - Zimmer auf "Ablage" setzen
        $stmt2 = $mysqli->prepare("INSERT INTO AV_ResDet (resid, zimmer, nachname, vorname, anreise, abreise) VALUES (?, 'Ablage', ?, ?, ?, ?)");
        
        if (!$stmt2) {
            throw new Exception('Prepare failed for AV_ResDet: ' . $mysqli->error);
        }
        
        $stmt2->bind_param('issss', $resId, $nachname, $vorname, $anreise, $abreise);
        
        if (!$stmt2->execute()) {
            throw new Exception('Execute failed for AV_ResDet: ' . $stmt2->error);
        }
        
        $stmt2->close();
        */

        $mysqli->commit();
        
        // Auto-sync auslösen
        triggerAutoSync('new_reservation');
        
        echo json_encode([
            'success' => true, 
            'id' => $resId,
            'message' => 'Reservierung erfolgreich angelegt'
        ]);

    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?>
