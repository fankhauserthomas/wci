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
    // Get AV_Res ID from query parameter
    $avResId = $_GET['av_res_id'] ?? null;
    
    if (!$avResId) {
        throw new Exception('AV_Res ID ist erforderlich');
    }

    // Query AV_Res table for the specific reservation using mysqli
    $stmt = $mysqli->prepare("
        SELECT 
            id as av_res_id,
            av_id,
            anreise,
            abreise,
            lager,
            betten,
            dz,
            sonder,
            vorname,
            nachname,
            email,
            handy,
            email_date,
            gruppe,
            bem,
            bem_av,
            storno,
            arr,
            country_id,
            origin,
            hund
        FROM `AV-Res` 
        WHERE id = ?
    ");
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('i', $avResId);
    $stmt->execute();
    $result = $stmt->get_result();
    $avResData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$avResData) {
        throw new Exception('AV_Res Datensatz nicht gefunden');
    }

    // Convert date fields to proper format
    if ($avResData['anreise']) {
        $avResData['anreise'] = date('Y-m-d', strtotime($avResData['anreise']));
    }
    if ($avResData['abreise']) {
        $avResData['abreise'] = date('Y-m-d', strtotime($avResData['abreise']));
    }
    if ($avResData['email_date']) {
        $avResData['email_date'] = date('Y-m-d\TH:i', strtotime($avResData['email_date']));
    }

    // Convert numeric fields to proper types
    $numericFields = ['av_id', 'lager', 'betten', 'dz', 'sonder', 'storno', 'arr', 'country_id', 'origin', 'hund'];
    foreach ($numericFields as $field) {
        if (isset($avResData[$field])) {
            $avResData[$field] = (int)$avResData[$field];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $avResData,
        'message' => 'AV_Res Daten erfolgreich geladen'
    ]);

} catch (PDOException $e) {
    error_log("Database error in getAVReservationData.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Datenbankfehler: ' . $e->getMessage(),
        'data' => null
    ]);
} catch (Exception $e) {
    error_log("Error in getAVReservationData.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null
    ]);
}
?>