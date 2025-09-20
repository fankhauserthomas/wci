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
    // Query arr table using mysqli - echte Daten laden
    $result = $mysqli->query("SHOW TABLES LIKE 'arr'");
    
    if ($result && $result->num_rows > 0) {
        // Echte Tabelle existiert
        $result = $mysqli->query("
            SELECT 
                ID,
                kbez,
                sort
            FROM arr 
            ORDER BY sort ASC, kbez ASC
        ");
        
        $arrangements = [];
        
        if ($result && $result->num_rows > 0) {
            // Echte Daten verwenden
            while ($row = $result->fetch_assoc()) {
                $row['ID'] = (int)$row['ID'];
                $row['sort'] = (int)($row['sort'] ?? 0);
                $arrangements[] = $row;
            }
            $result->free();
        } else {
            throw new Exception('Keine Arrangements in der Datenbank gefunden');
        }
    } else {
        throw new Exception('Arr-Tabelle nicht gefunden');
    }

    echo json_encode([
        'success' => true,
        'data' => $arrangements,
        'message' => 'Arrangements erfolgreich geladen'
    ]);

} catch (Exception $e) {
    error_log("Error in getArrangements.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => []
    ]);
}
?>