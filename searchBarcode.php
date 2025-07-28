<?php
require_once 'config.php';

header('Content-Type: application/json');

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$response = ['success' => false, 'message' => '', 'data' => null, 'debug' => []];

try {
    if (!isset($_GET['barcode']) || empty(trim($_GET['barcode']))) {
        throw new Exception('Kein Barcode angegeben');
    }

    $barcode = trim($_GET['barcode']);
    $response['debug']['received_barcode'] = $barcode;
    $response['debug']['barcode_length'] = strlen($barcode);
    
    // Suche in der AV-ResNamen Tabelle nach CardName
    $stmt = $mysqli->prepare("SELECT av_id, vorname, nachname, CardName FROM `AV-ResNamen` WHERE CardName = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Datenbankfehler: ' . $mysqli->error);
    }
    
    $stmt->bind_param('s', $barcode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $response['debug']['query_executed'] = true;
    $response['debug']['num_rows'] = $result->num_rows;
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $response['success'] = true;
        $response['message'] = 'Karte gefunden';
        $response['data'] = [
            'av_id' => $row['av_id'],
            'vorname' => $row['vorname'],
            'nachname' => $row['nachname'],
            'cardName' => $row['CardName']
        ];
        $response['debug']['found_record'] = $row;
    } else {
        // Zusätzliche Suche für Debugging - schaue was ähnlich ist
        $likeStmt = $mysqli->prepare("SELECT av_id, vorname, nachname, CardName FROM `AV-ResNamen` WHERE CardName LIKE ? LIMIT 5");
        $likePattern = '%' . $barcode . '%';
        $likeStmt->bind_param('s', $likePattern);
        $likeStmt->execute();
        $likeResult = $likeStmt->get_result();
        
        $similarRecords = [];
        while ($row = $likeResult->fetch_assoc()) {
            $similarRecords[] = $row;
        }
        
        // Auch nach Namen-Kombinationen suchen
        $nameStmt = $mysqli->prepare("SELECT av_id, vorname, nachname, CardName FROM `AV-ResNamen` WHERE CONCAT(nachname, ' ', vorname) LIKE ? OR CONCAT(vorname, ' ', nachname) LIKE ? LIMIT 5");
        $namePattern = '%' . $barcode . '%';
        $nameStmt->bind_param('ss', $namePattern, $namePattern);
        $nameStmt->execute();
        $nameResult = $nameStmt->get_result();
        
        $nameMatches = [];
        while ($row = $nameResult->fetch_assoc()) {
            $nameMatches[] = $row;
        }
        
        $response['success'] = false;
        $response['message'] = 'Karte nicht gefunden';
        $response['debug']['similar_cardname_records'] = $similarRecords;
        $response['debug']['similar_name_records'] = $nameMatches;
        $likeStmt->close();
        $nameStmt->close();
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
