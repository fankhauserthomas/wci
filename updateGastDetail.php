<?php
// Keine Fehlerausgaben - würde JSON kaputt machen
ini_set('display_errors', 0);
error_reporting(0);

require_once 'config.php';
header('Content-Type: application/json');

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = ''");
$mysqli->query("SET SESSION sql_mode = ''");

// 1. Daten aus dem Request-Body lesen
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if ($data === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültiges JSON']);
    exit;
}

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
try {
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fehler beim Speichern: ' . $stmt->error]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unerwarteter Fehler: ' . $e->getMessage()]);
}

// 7. Statement schließen
$stmt->close();
