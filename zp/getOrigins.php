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
    // Query origins table using mysqli - echte Daten laden
    $result = $mysqli->query("SHOW TABLES LIKE 'origin'");
    
    if ($result && $result->num_rows > 0) {
        // Echte Tabelle existiert
        $result = $mysqli->query("
            SELECT 
                ID,
                country,
                sort
            FROM origin 
            ORDER BY sort ASC, country ASC
        ");
        
        $origins = [];
        
        if ($result && $result->num_rows > 0) {
            // Echte Daten verwenden
            while ($row = $result->fetch_assoc()) {
                $row['ID'] = (int)$row['ID'];
                $row['sort'] = (int)($row['sort'] ?? 0);
                $origins[] = $row;
            }
            $result->free();
        } else {
            throw new Exception('Keine Origins in der Datenbank gefunden');
        }
    } else {
        throw new Exception('Origin-Tabelle nicht gefunden');
    }

    echo json_encode([
        'success' => true,
        'data' => $origins,
        'message' => 'Herkünfte erfolgreich geladen'
    ]);

} catch (Exception $e) {
    error_log("Error in getOrigins.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => []
    ]);
}
?>