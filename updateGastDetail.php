<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
header('Content-Type: application/json');

// 1. Daten aus dem Request-Body lesen
$data = json_decode(file_get_contents('php://input'), true);

// 2. ID prüfen
$id = isset($data['id']) ? (int)$data['id'] : 0;
if ($id === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Keine gültige ID übergeben']);
    exit;
}

// 3. Daten vorbereiten
$nachname  = $data['nachname'] ?? '';
$vorname   = $data['vorname'] ?? '';
$gebdat    = !empty($data['gebdat']) ? $data['gebdat'] : null;
$herkunft  = !empty($data['herkunft']) ? (int)$data['herkunft'] : null;
$arr       = !empty($data['arr']) ? (int)$data['arr'] : null;
$diet_id   = !empty($data['diet_id']) ? (int)$data['diet_id'] : null;
$dietInfo  = $data['dietInfo'] ?? '';  // Text field - keep as string, even if empty
$transport = !empty($data['transport']) ? (float)$data['transport'] : null;
$bem       = $data['bem'] ?? '';        // Text field - keep as string, even if empty
$guide     = isset($data['guide']) && $data['guide'] ? 1 : 0;

// 4. Prepared Statement für das Update
$sql = "UPDATE `AV-ResNamen` SET 
            nachname = ?,
            vorname = ?,
            gebdat = ?,
            herkunft = ?,
            arr = ?,
            diet = ?,
            dietInfo = ?,
            transport = ?,
            bem = ?,
            guide = ?
        WHERE id = ?";

$stmt = $mysqli->prepare($sql);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SQL-Vorbereitung fehlgeschlagen: ' . $mysqli->error]);
    exit;
}

// 5. Parameter binden (s=string, i=integer, d=double)
$stmt->bind_param(
    'sssiiisdssi', // Corrected types: s,nachname; s,vorname; s,gebdat; i,herkunft; i,arr; i,diet_id; s,dietInfo; d,transport; s,bem; i,guide; i,id
    $nachname,
    $vorname,
    $gebdat,
    $herkunft,
    $arr,
    $diet_id,
    $dietInfo,
    $transport,
    $bem,
    $guide,
    $id
);

// 6. Statement ausführen und Ergebnis prüfen
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Fehler beim Speichern: ' . $stmt->error]);
}

// 7. Statement schließen
$stmt->close();
