<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Keine Daten empfangen');
    }

    $avResId = $input['av_res_id'] ?? null;
    
    if (!$avResId) {
        throw new Exception('AV_Res ID ist erforderlich');
    }

    // Check if record exists using mysqli
    $stmt = $mysqli->prepare("SELECT id, av_id FROM `AV-Res` WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('i', $avResId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existingRecord = $result->fetch_assoc();
    $stmt->close();
    
    if (!$existingRecord) {
        throw new Exception('AV_Res Datensatz nicht gefunden');
    }

    $isAVReservation = $input['isAVReservation'] ?? (($existingRecord['av_id'] ?? 0) > 0);

    // Build update query based on editability rules and proper updates array
    $updateFields = [];
    $updateValues = [];
    $updates = $input['updates'] ?? [];

    // Immer editierbare Felder
    if (isset($updates['bem'])) {
        $updateFields[] = "bem = ?";
        $updateValues[] = $updates['bem'];
    }
    
    if (isset($updates['hund'])) {
        $updateFields[] = "hund = ?";
        $updateValues[] = (int)$updates['hund'];
    }

    if (isset($updates['arrangement'])) {
        $updateFields[] = "arr = ?";
        $updateValues[] = $updates['arrangement'] ? (int)$updates['arrangement'] : null;
    }

    if (isset($updates['herkunft'])) {
        $updateFields[] = "origin = ?";
        $updateValues[] = $updates['herkunft'] ? (int)$updates['herkunft'] : null;
    }

    // Felder die sowohl bei AV- als auch bei normalen Reservierungen bearbeitbar sind
    if (isset($updates['vname'])) {
        $updateFields[] = "vorname = ?";
        $updateValues[] = $updates['vname'];
    }

    if (isset($updates['name'])) {
        $updateFields[] = "nachname = ?";
        $updateValues[] = $updates['name'];
    }

    if (isset($updates['mail'])) {
        $updateFields[] = "email = ?";
        $updateValues[] = $updates['mail'];
    }

    if (isset($updates['handy'])) {
        $updateFields[] = "handy = ?";
        $updateValues[] = $updates['handy'];
    }

    if (isset($updates['gruppenname'])) {
        $updateFields[] = "gruppe = ?";
        $updateValues[] = $updates['gruppenname'];
    }

    if (isset($updates['anreise'])) {
        $updateFields[] = "anreise = ?";
        $updateValues[] = $updates['anreise'] ?: null;
    }

    if (isset($updates['abreise'])) {
        $updateFields[] = "abreise = ?";
        $updateValues[] = $updates['abreise'] ?: null;
    }

    if (isset($updates['lager'])) {
        $updateFields[] = "lager = ?";
        $updateValues[] = (int)$updates['lager'];
    }

    if (isset($updates['betten'])) {
        $updateFields[] = "betten = ?";
        $updateValues[] = (int)$updates['betten'];
    }

    if (isset($updates['dz'])) {
        $updateFields[] = "dz = ?";
        $updateValues[] = (int)$updates['dz'];
    }

    if (isset($updates['sonder'])) {
        $updateFields[] = "sonder = ?";
        $updateValues[] = (int)$updates['sonder'];
    }

    // AV-geschützte Felder (nur bei AV-Reservierungen bearbeitbar)
    if ($isAVReservation) {
        if (isset($updates['bem_av'])) {
            $updateFields[] = "bem_av = ?";
            $updateValues[] = $updates['bem_av'];
        }

        if (isset($updates['storno'])) {
            $updateFields[] = "storno = ?";
            $updateValues[] = (int)$updates['storno'];
        }
    }

    if (empty($updateFields)) {
        throw new Exception('Keine aktualisierbaren Felder angegeben');
    }

    // Prepare values array with AV-Res ID at the end
    $updateValues[] = $avResId;

    // Execute update using mysqli (consistent with read operation)
    $sql = "UPDATE `AV-Res` SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    // Create type string for bind_param (all strings except last which is int for ID)
    $types = str_repeat('s', count($updateValues) - 1) . 'i';
    $stmt->bind_param($types, ...$updateValues);
    
    $result = $stmt->execute();
    
    if (!$result) {
        throw new Exception('Fehler beim Aktualisieren der Daten: ' . $stmt->error);
    }

    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    // Log the update for debugging
    error_log("AV_Res update - ID: $avResId, AV: " . ($isAVReservation ? 'Yes' : 'No') . ", Fields: " . implode(', ', array_map(function($f) { return explode(' = ', $f)[0]; }, $updateFields)) . ", Affected: $affectedRows");

    echo json_encode([
        'success' => true,
        'message' => 'AV_Res Daten erfolgreich aktualisiert',
        'affected_rows' => $affectedRows,
        'av_protected' => $isAVReservation,
        'updated_fields' => count($updateFields)
    ]);

} catch (Exception $e) {
    error_log("Error in updateReservationMasterData.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>