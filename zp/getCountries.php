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
    // Query countries table using mysqli - Fallback für Demo
    $result = $mysqli->query("SHOW TABLES LIKE 'countries'");
    
    if ($result && $result->num_rows > 0) {
        // Echte Tabelle existiert
        $result = $mysqli->query("
            SELECT 
                id,
                country
            FROM countries 
            ORDER BY country ASC
        ");
    } else {
        // Fallback: Erstelle Demo-Daten
        $result = false;
    }
    
    $countries = [];
    
    if ($result && $result->num_rows > 0) {
        // Echte Daten verwenden
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $countries[] = $row;
        }
        $result->free();
    } else {
        // Demo-Daten verwenden
        $countries = [
            ['id' => 1, 'country' => 'Deutschland'],
            ['id' => 2, 'country' => 'Österreich'],
            ['id' => 3, 'country' => 'Schweiz'],
            ['id' => 4, 'country' => 'Italien'],
            ['id' => 5, 'country' => 'Frankreich']
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $countries,
        'message' => 'Länder erfolgreich geladen'
    ]);

} catch (Exception $e) {
    error_log("Error in getCountries.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => []
    ]);
}
?>