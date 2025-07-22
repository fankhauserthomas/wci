<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Fehlerbehandlung
error_reporting(E_ALL);
ini_set('display_errors', 0); // Nicht in JSON ausgeben

try {
    // Datenbank-Konfiguration laden
    require 'config.php';
    
    // SQL-Query für Zimmer (nur visible=true, sortiert nach sort)
    $sql = "SELECT 
                id,
                caption,
                kapazitaet,
                sort,
                visible
            FROM zp_zimmer 
            WHERE visible = 1 
            ORDER BY sort ASC, caption ASC";
    
    $result = $mysqli->query($sql);
    
    if (!$result) {
        throw new Exception("Query-Fehler: " . $mysqli->error);
    }
    
    $rooms = [];
    while ($row = $result->fetch_assoc()) {
        $rooms[] = [
            'id' => $row['id'],
            'caption' => $row['caption'],
            'capacity' => (int)$row['kapazitaet'],
            'sort' => (int)$row['sort'],
            'display_name' => $row['caption'] . ' (' . $row['kapazitaet'] . ')'
        ];
    }
    
    // Erfolgreiche Antwort
    echo json_encode([
        'success' => true,
        'data' => $rooms,
        'count' => count($rooms),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Datenbankfehler
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
}
?>