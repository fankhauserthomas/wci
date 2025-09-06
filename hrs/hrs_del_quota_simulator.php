<?php
/**
 * HRS Quota Delete Simulator 
 * ===========================
 * 
 * Simuliert HRS-Antworten für Entwicklung/Tests ohne echte API-Calls.
 * Basiert auf den echten HRS-Responses die du getestet hast.
 */

header('Content-Type: application/json; charset=utf-8');

$hrs_id = isset($_POST['hrs_id']) ? (int)$_POST['hrs_id'] : 0;
$name = isset($_POST['name']) ? $_POST['name'] : 'Test-Quota';

if ($hrs_id <= 0) {
    echo json_encode(array('status' => 'error', 'message' => 'Ungültige HRS-ID')) . "\n";
    exit;
}

echo json_encode(array(
    'status' => 'info',
    'message' => "SIMULATION: Starte Löschung von Quota: $name (HRS-ID: $hrs_id)"
)) . "\n";

sleep(1); // Simulate network delay

echo json_encode(array(
    'status' => 'info',
    'message' => 'SIMULATION: Verbinde mit HRS-System...'
)) . "\n";

sleep(1); // Simulate login

echo json_encode(array(
    'status' => 'success',
    'message' => 'SIMULATION: HRS-Login erfolgreich'
)) . "\n";

sleep(1); // Simulate API call

echo json_encode(array(
    'status' => 'info',
    'message' => "SIMULATION: Lösche Quota: $name..."
)) . "\n";

// Simulate different responses based on HRS-ID
if ($hrs_id == 42795) {
    // Simulate successful deletion (like your real test)
    echo json_encode(array(
        'status' => 'success',
        'message' => "SIMULATION: Quota '$name' erfolgreich gelöscht",
        'hrs_id' => $hrs_id,
        'response' => '{"messageId":126,"description":"Hut quota deleted","statusCode":200}'
    )) . "\n";
} elseif ($hrs_id == 99999) {
    // Simulate overbook error
    echo json_encode(array(
        'status' => 'error',
        'message' => 'SIMULATION: HRS-Fehler: HTTP 400: Change leads to overbook due to reservations [Message-ID: 122]',
        'hrs_id' => $hrs_id
    )) . "\n";
} else {
    // Simulate success for other IDs
    echo json_encode(array(
        'status' => 'success',
        'message' => "SIMULATION: Quota '$name' erfolgreich gelöscht",
        'hrs_id' => $hrs_id,
        'response' => '{"messageId":126,"description":"Hut quota deleted","statusCode":200}'
    )) . "\n";
}

echo json_encode(array(
    'status' => 'complete',
    'message' => 'SIMULATION: Löschvorgang abgeschlossen'
)) . "\n";

?>
