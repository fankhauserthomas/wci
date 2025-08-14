<?php
// addReservationNames.php - Enhanced with birthdate support
header('Content-Type: application/json');
require 'config.php';

$payload = json_decode(file_get_contents('php://input'), true);

// Check for JSON decode errors
if ($payload === null) {
    echo json_encode([
        'success'=>false,
        'error'=>'Ungültiges JSON oder leere Daten empfangen',
        'debug' => [
            'json_error' => json_last_error_msg(),
            'raw_input' => file_get_contents('php://input')
        ]
    ]);
    exit;
}

// ONLY support new format with preview data (names + res_id)
// Old format (entries + id) is deprecated to prevent duplicates
$id      = $payload['res_id'] ?? '';
$entries = $payload['names'] ?? [];
$format  = $payload['format'] ?? '';
$source  = $payload['source'] ?? '';

// Reject old format to prevent duplicate processing
if (isset($payload['entries']) && !isset($payload['names'])) {
    http_response_code(400);
    echo json_encode([
        'success'=>false, 
        'error'=>'Veraltetes Format. Bitte verwenden Sie die neue Namen+ Funktion mit Vorschau.',
        'debug'=>'Old entries format rejected to prevent duplicates'
    ]);
    exit;
}

if (!$id || !ctype_digit($id) || !is_array($entries)) {
    http_response_code(400);
    echo json_encode([
        'success'=>false,
        'error'=>'Ungültige Daten: res_id und names Array erforderlich',
        'debug' => [
            'received_id' => $id,
            'received_entries' => $entries,
            'payload_keys' => $payload ? array_keys($payload) : 'NULL_PAYLOAD'
        ]
    ]);
    exit;
}

// Check if gebdat column exists (for compatibility)
$checkGebdatQuery = "SHOW COLUMNS FROM `AV-ResNamen` LIKE 'gebdat'";
$checkResult = $mysqli->query($checkGebdatQuery);
$hasGebdatColumn = ($checkResult && $checkResult->num_rows > 0);

// Prepare INSERT statement based on available columns
if ($hasGebdatColumn) {
    $sql = "INSERT INTO `AV-ResNamen`
            (av_id, vorname, nachname, gebdat)
            VALUES (?, ?, ?, ?)";
} else {
    $sql = "INSERT INTO `AV-ResNamen`
            (av_id, vorname, nachname)
            VALUES (?, ?, ?)";
}

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'SQL-Fehler: '.$mysqli->error]);
    exit;
}

$added = 0;
$birthdates_added = 0;

foreach ($entries as $e) {
    $vn = trim($e['vorname']);
    $nn = trim($e['nachname']);
    if ($vn === '' && $nn === '') continue;
    
    $gebdat = null;
    if ($hasGebdatColumn && isset($e['gebdat']) && $e['gebdat'] !== null) {
        // Validate birthdate format (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $e['gebdat'])) {
            $gebdat = $e['gebdat'];
            $birthdates_added++;
        }
    }
    
    if ($hasGebdatColumn) {
        $stmt->bind_param('isss', $id, $vn, $nn, $gebdat);
    } else {
        $stmt->bind_param('iss', $id, $vn, $nn);
    }
    
    if (!$stmt->execute()) {
        // Bei erstem Fehler abbrechen
        http_response_code(500);
        echo json_encode([
            'success'=>false, 
            'error'=>'Insert-Fehler: '.$stmt->error,
            'debug' => [
                'entry' => $e,
                'has_gebdat' => $hasGebdatColumn,
                'gebdat_value' => $gebdat
            ]
        ]);
        exit;
    }
    
    $added++;
}

echo json_encode([
    'success'=>true, 
    'added'=>$added, 
    'birthdates_added'=>$birthdates_added,
    'has_gebdat_column'=>$hasGebdatColumn
]);
