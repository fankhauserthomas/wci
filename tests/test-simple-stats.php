<?php
// test-simple-stats.php - Allereinfachste Statistiken für Test
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');

// Einfache Test-Daten ohne Datenbank
$testStats = [
    'arrivals_today' => [
        'reservations' => 3,
        'guests' => 8
    ],
    'departures_tomorrow' => [
        'reservations' => 2,
        'guests' => 5
    ],
    'current_guests' => 12,
    'pending_checkins' => 1,
    'debug' => [
        'test_mode' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'date' => $_GET['date'] ?? date('Y-m-d')
    ]
];

echo json_encode($testStats, JSON_PRETTY_PRINT);
?>
