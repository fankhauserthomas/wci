<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
header('Content-Type: application/json');

// 1. ID prüfen
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id === 0) {
    // HTTP-Statuscode setzen für "Bad Request"
    http_response_code(400); 
    echo json_encode(['error' => 'Keine gültige ID übergeben']);
    exit;
}

// 2. Prepared Statement verwenden
$sql = "SELECT id, nachname, vorname, gebdat, herkunft, bem, guide, av, dietInfo, transport, arr, diet FROM `AV-ResNamen` WHERE id = ?";
$stmt = $mysqli->prepare($sql);

// 3. Fehlerbehandlung für prepare
if ($stmt === false) {
    // HTTP-Statuscode für serverseitigen Fehler
    http_response_code(500);
    // Detailliertere Fehlermeldung (nur für Entwicklung, nicht für Produktion)
    echo json_encode(['error' => 'SQL-Vorbereitung fehlgeschlagen: ' . $mysqli->error]);
    exit;
}

// 4. Parameter binden und ausführen
$stmt->bind_param("i", $id);
$stmt->execute();

// Binde Ergebnisvariablen
$stmt->bind_result($id_res, $nachname, $vorname, $gebdat, $herkunft, $bem, $guide, $av, $dietInfo, $transport, $arr, $diet);

// 5. Ergebnis prüfen und ausgeben
if ($stmt->fetch()) {
    // Baue das Ergebnis-Array manuell zusammen
    $row = [
        'id' => $id_res,
        'nachname' => $nachname,
        'vorname' => $vorname,
        'gebdat' => $gebdat ? substr($gebdat, 0, 10) : null,
        'herkunft' => $herkunft,
        'bem' => $bem,
        'guide' => $guide,
        'av' => $av,
        'dietInfo' => $dietInfo,
        'transport' => $transport,
        'arr' => $arr,
        'diet_id' => $diet
    ];
    echo json_encode($row);
} else {
    // HTTP-Statuscode für "Not Found"
    http_response_code(404); 
    echo json_encode(['error' => 'Gast nicht gefunden']);
}

// 6. Statement schließen
$stmt->close();
