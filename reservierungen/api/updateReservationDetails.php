<?php
// Disable error output for clean JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json');

// Read data from request body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültige JSON-Daten']);
    exit;
}

// Check required fields
$id = isset($data['id']) ? (int)$data['id'] : 0;
if ($id === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Keine gültige ID übergeben']);
    exit;
}

// Validate required fields
if (empty($data['nachname']) || empty($data['anreise']) || empty($data['abreise'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Pflichtfelder (Nachname, Anreise, Abreise) sind erforderlich']);
    exit;
}

// Validate dates
$anreise = $data['anreise'];
$abreise = $data['abreise'];

$anreiseDate = new DateTime($anreise);
$abreiseDate = new DateTime($abreise);

if ($abreiseDate <= $anreiseDate) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Das Abreisedatum muss nach dem Anreisedatum liegen']);
    exit;
}

try {
    // First, check if reservation exists and get av_id to determine what fields can be updated
    $sql = "SELECT av_id FROM `AV-Res` WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt === false) {
        throw new Exception('SQL-Vorbereitung fehlgeschlagen: ' . $mysqli->error);
    }
    
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($av_id);
    
    if (!$stmt->fetch()) {
        $stmt->close();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Reservierung nicht gefunden']);
        exit;
    }
    
    $stmt->close();
    
    // Determine which fields can be updated based on av_id
    $isReadonly = $av_id && (int)$av_id > 0;
    
    // Prepare update fields
    $updateFields = [];
    $params = [];
    $types = '';
    
    // Fields that can always be updated
    $updateFields[] = "bem = ?";
    $params[] = $data['bem'] ?? '';
    $types .= 's';
    
    $updateFields[] = "arr = ?";
    $params[] = $data['arr'] ? (int)$data['arr'] : 0;
    $types .= 'i';
    
    $updateFields[] = "origin = ?";
    $params[] = $data['origin'] ? (int)$data['origin'] : 0;
    $types .= 'i';
    
    // Schlafkategorien und hund sind immer editierbar
    $updateFields[] = "lager = ?";
    $params[] = (int)($data['lager'] ?? 0);
    $types .= 'i';
    
    $updateFields[] = "betten = ?";
    $params[] = (int)($data['betten'] ?? 0);
    $types .= 'i';
    
    $updateFields[] = "dz = ?";
    $params[] = (int)($data['dz'] ?? 0);
    $types .= 'i';

    $updateFields[] = "sonder = ?";
    $params[] = (int)($data['sonder'] ?? 0);
    $types .= 'i';

    $updateFields[] = "hund = ?";
    $params[] = (int)($data['hund'] ?? 0);
    $types .= 'i';

    $updateFields[] = "invoice = ?";
    $params[] = (int)($data['invoice'] ?? 0);
    $types .= 'i';
    
    // Anreise und Abreise sind immer editierbar
    $updateFields[] = "anreise = ?";
    $params[] = $anreise;
    $types .= 's';
    
    $updateFields[] = "abreise = ?";
    $params[] = $abreise;
    $types .= 's';
    
    // Fields that can only be updated if not readonly (av_id <= 0)
    if (!$isReadonly) {
        $updateFields[] = "nachname = ?";
        $params[] = $data['nachname'];
        $types .= 's';
        
        $updateFields[] = "vorname = ?";
        $params[] = $data['vorname'];
        $types .= 's';
        
        $updateFields[] = "bem_av = ?";
        $params[] = $data['bem_av'] ?? '';
        $types .= 's';
        
        $updateFields[] = "handy = ?";
        $params[] = $data['handy'] ?? '';
        $types .= 's';
        
        $updateFields[] = "email = ?";
        $params[] = $data['email'] ?? '';
        $types .= 's';
        
        $updateFields[] = "storno = ?";
        $params[] = (int)($data['storno'] ?? 0);
        $types .= 'i';
    }
    
    // Add ID parameter for WHERE clause
    $params[] = $id;
    $types .= 'i';
    
    // Build and execute update query
    $sql = "UPDATE `AV-Res` SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt === false) {
        throw new Exception('SQL-Vorbereitung fehlgeschlagen: ' . $mysqli->error);
    }
    
    $stmt->bind_param($types, ...$params);
    
    if (!$stmt->execute()) {
        throw new Exception('Fehler beim Aktualisieren: ' . $stmt->error);
    }
    
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Reservierungsdetails erfolgreich aktualisiert',
        'affectedRows' => $affectedRows,
        'readonly' => $isReadonly
    ]);

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
