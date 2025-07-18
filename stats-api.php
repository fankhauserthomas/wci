<?php
// stats-api.php - Dedicated API for statistics calculations

header('Content-Type: application/json');
require 'config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = 'ALLOW_INVALID_DATES'");

$date = $_GET['date'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ungültiges Datum']));
}

$stats = [];

// 1. Anreisen heute (ohne Stornos)
$sql_arrivals = "
SELECT COUNT(*) as count
FROM `AV-Res` r
WHERE DATE(r.anreise) = ? 
AND r.storno = 0
";

$stmt = $mysqli->prepare($sql_arrivals);
$stmt->bind_param('s', $date);
$stmt->execute();
$result = $stmt->get_result();
$stats['arrivals_today'] = (int)$result->fetch_assoc()['count'];

// 2. Abreisen morgen (ohne Stornos)
$tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
$sql_departures = "
SELECT COUNT(*) as count
FROM `AV-Res` r
WHERE DATE(r.abreise) = ? 
AND r.storno = 0
";

$stmt = $mysqli->prepare($sql_departures);
$stmt->bind_param('s', $tomorrow);
$stmt->execute();
$result = $stmt->get_result();
$stats['departures_tomorrow'] = (int)$result->fetch_assoc()['count'];

// 4. Gäste aktuell im Haus (ohne Stornos)
$sql_guests = "
SELECT SUM(r.betten + r.dz + r.lager + r.sonder) as total_guests
FROM `AV-Res` r
WHERE DATE(r.anreise) <= ? 
AND DATE(r.abreise) > ? 
AND r.storno = 0
";

$stmt = $mysqli->prepare($sql_guests);
$stmt->bind_param('ss', $date, $date);
$stmt->execute();
$result = $stmt->get_result();
$stats['guests_in_house'] = (int)$result->fetch_assoc()['total_guests'];

// 5. Hunde aktuell im Haus (ohne Stornos)
$sql_dogs = "
SELECT SUM(r.hund) as total_dogs
FROM `AV-Res` r
WHERE DATE(r.anreise) <= ? 
AND DATE(r.abreise) > ? 
AND r.storno = 0
";

$stmt = $mysqli->prepare($sql_dogs);
$stmt->bind_param('ss', $date, $date);
$stmt->execute();
$result = $stmt->get_result();
$stats['dogs_in_house'] = (int)$result->fetch_assoc()['total_dogs'];

// 6. Check-in Status für heutige Anreisen
$sql_checkins = "
SELECT 
    r.id,
    r.vorname,
    r.nachname,
    (r.betten + r.dz + r.lager + r.sonder) as total_guests,
    COALESCE(names.total_names, 0) as total_names,
    COALESCE(names.checked_in_names, 0) as checked_in_names,
    CASE 
        WHEN COALESCE(names.total_names, 0) = 0 THEN 'no_names'
        WHEN COALESCE(names.checked_in_names, 0) = COALESCE(names.total_names, 0) THEN 'complete'
        WHEN COALESCE(names.checked_in_names, 0) > 0 THEN 'partial'
        ELSE 'pending'
    END as checkin_status
FROM `AV-Res` r
LEFT JOIN (
    SELECT 
        av_id,
        COUNT(*) as total_names,
        SUM(CASE 
            WHEN checked_in IS NOT NULL 
            AND CAST(checked_in AS CHAR) != '' 
            AND CAST(checked_in AS CHAR) != '0000-00-00 00:00:00'
            AND checked_in > '1970-01-01 00:00:00'
            THEN 1 ELSE 0 
        END) as checked_in_names
    FROM `AV-ResNamen`
    GROUP BY av_id
) names ON r.id = names.av_id
WHERE DATE(r.anreise) = ? 
AND r.storno = 0
ORDER BY r.nachname, r.vorname
";

$stmt = $mysqli->prepare($sql_checkins);
$stmt->bind_param('s', $date);
$stmt->execute();
$result = $stmt->get_result();

$checkin_data = [];
$pending_count = 0;
$complete_count = 0;

while ($row = $result->fetch_assoc()) {
    $checkin_data[] = [
        'id' => (int)$row['id'],
        'name' => trim($row['nachname'] . ' ' . $row['vorname']),
        'total_guests' => (int)$row['total_guests'],
        'total_names' => (int)$row['total_names'],
        'checked_in_names' => (int)$row['checked_in_names'],
        'status' => $row['checkin_status']
    ];
    
    if ($row['checkin_status'] === 'complete') {
        $complete_count++;
    } else {
        $pending_count++;
    }
}

$stats['checkins'] = [
    'total_reservations' => count($checkin_data),
    'complete_checkins' => $complete_count,
    'pending_checkins' => $pending_count,
    'details' => $checkin_data
];

// 7. Check-out Status für morgige Abreisen
$sql_checkouts = "
SELECT 
    r.id,
    r.vorname,
    r.nachname,
    (r.betten + r.dz + r.lager + r.sonder) as total_guests,
    COALESCE(names.total_names, 0) as total_names,
    COALESCE(names.checked_out_names, 0) as checked_out_names,
    CASE 
        WHEN COALESCE(names.total_names, 0) = 0 THEN 'no_names'
        WHEN COALESCE(names.checked_out_names, 0) = COALESCE(names.total_names, 0) THEN 'complete'
        WHEN COALESCE(names.checked_out_names, 0) > 0 THEN 'partial'
        ELSE 'pending'
    END as checkout_status
FROM `AV-Res` r
LEFT JOIN (
    SELECT 
        av_id,
        COUNT(*) as total_names,
        SUM(CASE 
            WHEN checked_out IS NOT NULL 
            AND CAST(checked_out AS CHAR) != '' 
            AND CAST(checked_out AS CHAR) != '0000-00-00 00:00:00'
            AND checked_out > '1970-01-01 00:00:00'
            THEN 1 ELSE 0 
        END) as checked_out_names
    FROM `AV-ResNamen`
    GROUP BY av_id
) names ON r.id = names.av_id
WHERE DATE(r.abreise) = ? 
AND r.storno = 0
ORDER BY r.nachname, r.vorname
";

$stmt = $mysqli->prepare($sql_checkouts);
$stmt->bind_param('s', $tomorrow);
$stmt->execute();
$result = $stmt->get_result();

$checkout_data = [];
$pending_checkout_count = 0;
$complete_checkout_count = 0;

while ($row = $result->fetch_assoc()) {
    $checkout_data[] = [
        'id' => (int)$row['id'],
        'name' => trim($row['nachname'] . ' ' . $row['vorname']),
        'total_guests' => (int)$row['total_guests'],
        'total_names' => (int)$row['total_names'],
        'checked_out_names' => (int)$row['checked_out_names'],
        'status' => $row['checkout_status']
    ];
    
    if ($row['checkout_status'] === 'complete') {
        $complete_checkout_count++;
    } else {
        $pending_checkout_count++;
    }
}

$stats['checkouts'] = [
    'total_reservations' => count($checkout_data),
    'complete_checkouts' => $complete_checkout_count,
    'pending_checkouts' => $pending_checkout_count,
    'details' => $checkout_data
];

echo json_encode($stats, JSON_PRETTY_PRINT);
?>
