<?php
// get-arrangements.php - Verfügbare Arrangements laden
require_once 'config.php';

header('Content-Type: application/json');

try {
    if ($mysqli->connect_error) {
        throw new Exception('Datenbank nicht verfügbar: ' . $mysqli->connect_error);
    }

    // Alle verfügbaren Arrangement-Arten laden
    $sql = "SELECT ID, kbez, bez FROM arr ORDER BY sort, kbez";
    $result = $mysqli->query($sql);

    $arrangements = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $id = $row['ID'];
            $label = $row['kbez'] ?: $row['bez'];
            $arrangements[$id] = $label ?? '';
        }
    }

    echo json_encode($arrangements);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
