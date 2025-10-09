<?php
// getIncompleteReservations.php - API für unvollständige Reservierungen

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
require_once __DIR__ . '/config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = 'ALLOW_INVALID_DATES'");

// GET-Parameter
$days = isset($_GET['days']) ? (int)$_GET['days'] : 14;
$min_guests = isset($_GET['min_guests']) ? (int)$_GET['min_guests'] : 4;
$max_percentage = isset($_GET['max_percentage']) ? (float)$_GET['max_percentage'] : 75.0;

// Validierung
if ($days < 1 || $days > 365) $days = 14;
if ($min_guests < 1) $min_guests = 4;
if ($max_percentage < 0 || $max_percentage > 100) $max_percentage = 75.0;

$today = date('Y-m-d');
$end_date = date('Y-m-d', strtotime($today . ' +' . $days . ' days'));

try {
    // SQL Query für unvollständige Reservierungen
    $sql = "
        SELECT 
            r.id,
            r.anreise,
            r.abreise,
            r.nachname,
            r.vorname,
            (r.sonder + r.betten + r.dz + r.lager) as total_guests,
            COUNT(rn.id) as entered_names,
            ROUND((COUNT(rn.id) / (r.sonder + r.betten + r.dz + r.lager)) * 100, 1) as percentage_complete
        FROM `AV-Res` r
        LEFT JOIN `AV-ResNamen` rn ON r.id = rn.av_id
        WHERE r.anreise BETWEEN ? AND ?
        AND (r.storno = 0 OR r.storno IS NULL)
        AND (r.sonder + r.betten + r.dz + r.lager) >= ?
        GROUP BY r.id
        HAVING percentage_complete <= ?
        ORDER BY r.anreise ASC, r.nachname ASC
    ";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('ssid', $today, $end_date, $min_guests, $max_percentage);
    $stmt->execute();
    $result = $stmt->get_result();

    $reservations = [];
    while ($row = $result->fetch_assoc()) {
        $reservations[] = [
            'id' => (int)$row['id'],
            'anreise' => $row['anreise'],
            'abreise' => $row['abreise'],
            'nachname' => $row['nachname'],
            'vorname' => $row['vorname'],
            'total_guests' => (int)$row['total_guests'],
            'entered_names' => (int)$row['entered_names'],
            'percentage_complete' => (float)$row['percentage_complete']
        ];
    }

    $response = [
        'success' => true,
        'data' => $reservations,
        'parameters' => [
            'days' => $days,
            'min_guests' => $min_guests,
            'max_percentage' => $max_percentage,
            'date_range' => $today . ' bis ' . $end_date
        ],
        'count' => count($reservations)
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Error in getIncompleteReservations.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
