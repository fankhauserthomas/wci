<?php
// addReservationNames.php - Enhanced with birthdate support
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

// Verwende mysqli statt PDO
$mysqli = $GLOBALS['mysqli'];

$payload = json_decode(file_get_contents('php://input'), true);

// Support both old format (id + entries) and new format (res_id + names)
$id      = $payload['res_id'] ?? $payload['id'] ?? '';
$entries = $payload['names'] ?? $payload['entries'] ?? [];
$format  = $payload['format'] ?? '';
$source  = $payload['source'] ?? '';

if (!$id || !ctype_digit($id) || !is_array($entries)) {
    http_response_code(400);
    echo json_encode([
        'success'=>false,
        'error'=>'Ungültige Daten: res_id und names Array erforderlich',
        'debug' => [
            'received_id' => $id,
            'received_entries' => $entries,
            'format' => $format,
            'source' => $source
        ]
    ]);
    exit;
}

// Check if gebdat column exists (for compatibility) - gebdat existiert bereits
$hasGebdatColumn = true;

$stmt = $mysqli->prepare("INSERT INTO `AV-ResNamen` (av_id, vorname, nachname, gebdat) VALUES (?, ?, ?, ?)");

$added = 0;
$birthdates_added = 0;

foreach ($entries as $entry) {
    // Handle both old format and new format
    if (isset($entry['vorname']) && isset($entry['nachname'])) {
        // New format with separate first/last name
        $firstName = trim($entry['vorname']);
        $lastName = trim($entry['nachname']);
        
        if (empty($lastName)) {
            continue; // Skip entries without at least a last name
        }
        
        $birthdate = $entry['gebdat'] ?? null;
        
        try {
            $stmt->bind_param("isss", $id, $firstName, $lastName, $birthdate);
            $stmt->execute();
            if ($birthdate) $birthdates_added++;
            $added++;
        } catch (mysqli_sql_exception $e) {
            error_log("Database error in addReservationNames.php: " . $e->getMessage());
            continue;
        }
    } else {
        // Old format with single name field - Namen aufteilen
        $fullName = trim($entry['name'] ?? '');
        $birthdate = null;
        
        if (empty($fullName)) {
            continue;
        }
        
        // Namen in Vor- und Nachname aufteilen
        $nameParts = explode(' ', $fullName, 2);
        if (count($nameParts) === 1) {
            // Only one name provided - use as lastName
            $firstName = '';
            $lastName = trim($nameParts[0]);
        } else {
            // Multiple parts - first is firstName, second is lastName
            $firstName = trim($nameParts[0]);
            $lastName = trim($nameParts[1]);
        }
        
        try {
            $stmt->bind_param("isss", $id, $firstName, $lastName, $birthdate);
            $stmt->execute();
            $added++;
        } catch (mysqli_sql_exception $e) {
            error_log("Database error in addReservationNames.php: " . $e->getMessage());
            continue;
        }
    }
}

$stmt->close();

echo json_encode([
    'success' => true,
    'added' => $added,
    'birthdates_added' => $birthdates_added,
    'format' => $format,
    'source' => $source
]);
?>
