<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config.php';
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

// 3. Aktuellen Storno-Status abfragen
$sql = "SELECT storno FROM `AV-Res` WHERE id = ?";
$stmt = $mysqli->prepare($sql);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SQL-Vorbereitung fehlgeschlagen: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($currentStorno);

if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Reservierung nicht gefunden']);
    $stmt->close();
    exit;
}

$stmt->close();

// Check if this is just a status check
$checkOnly = isset($data['checkOnly']) ? (bool)$data['checkOnly'] : false;
if ($checkOnly) {
    echo json_encode([
        'success' => true,
        'currentStorno' => $currentStorno ? true : false
    ]);
    exit;
}

// 4. Bei Stornierung (currentStorno = 0 -> newStorno = 1): Zimmerzuweisungen prüfen
$newStorno = $currentStorno ? 0 : 1;

if ($newStorno === 1) { // Nur bei Stornierung prüfen
    // Anzahl Zimmerzuweisungen ermitteln
    $sql = "SELECT COUNT(*) FROM `AV_ResDet` WHERE resid = ?";
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'SQL-Vorbereitung für Zimmerzuweisungen fehlgeschlagen: ' . $mysqli->error]);
        exit;
    }
    
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($roomAssignmentCount);
    $stmt->fetch();
    $stmt->close();
    
    // Wenn Zimmerzuweisungen vorhanden sind, Rückfrage erforderlich
    if ($roomAssignmentCount > 0) {
        // Check if user confirmed deletion of room assignments
        $deleteRoomAssignments = isset($data['deleteRoomAssignments']) ? (bool)$data['deleteRoomAssignments'] : false;
        
        if (!$deleteRoomAssignments) {
            // Send back request for confirmation
            $action = $newStorno ? 'stornieren' : 'Storno zurücksetzen';
            $actionTitle = $newStorno ? 'Stornierung bestätigen' : 'Storno zurücksetzen bestätigen';
            $actionMessage = $newStorno ? 'Beim Stornieren werden diese automatisch gelöscht.' : 'Beim Zurücksetzen des Stornos bleiben die Zimmerzuweisungen erhalten.';
            
            echo json_encode([
                'success' => false,
                'requiresConfirmation' => true,
                'roomAssignmentCount' => $roomAssignmentCount,
                'title' => $actionTitle,
                'message' => "Diese Reservierung hat $roomAssignmentCount aktive Zimmerzuweisung(en).\n\n$actionMessage",
                'question' => "Reservierung trotzdem $action?"
            ]);
            exit;
        }
        
        // User confirmed: Delete room assignments first
        $sql = "DELETE FROM `AV_ResDet` WHERE resid = ?";
        $stmt = $mysqli->prepare($sql);
        
        if ($stmt === false) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'SQL-Vorbereitung für Löschen der Zimmerzuweisungen fehlgeschlagen: ' . $mysqli->error]);
            exit;
        }
        
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Fehler beim Löschen der Zimmerzuweisungen: ' . $stmt->error]);
            $stmt->close();
            exit;
        }
        $stmt->close();
    }
}

// 5. Update durchführen
$sql = "UPDATE `AV-Res` SET storno = ? WHERE id = ?";
$stmt = $mysqli->prepare($sql);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SQL-Vorbereitung fehlgeschlagen: ' . $mysqli->error]);
    exit;
}

$stmt->bind_param('ii', $newStorno, $id);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true, 
        'newStorno' => $newStorno,
        'message' => $newStorno ? 'Reservierung storniert' : 'Storno aufgehoben'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Fehler beim Aktualisieren: ' . $stmt->error]);
}

$stmt->close();
?>
