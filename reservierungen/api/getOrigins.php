<?php
// getOrigins.php - API für Origin-Daten
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    $sql = "SELECT id, country FROM origin ORDER BY sort ASC";
    $result = $mysqli->query($sql);
    
    if (!$result) {
        throw new Exception('Datenbankfehler: ' . $mysqli->error);
    }
    
    $origins = [];
    while ($row = $result->fetch_assoc()) {
        $origins[] = [
            'id' => (int)$row['id'],
            'bez' => $row['country']
        ];
    }
    
    echo json_encode($origins, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
