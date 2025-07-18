<?php
// weekly-stats-api.php - Extended weekly statistics

header('Content-Type: application/json');
require 'config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = 'ALLOW_INVALID_DATES'");

$start_date = $_GET['start_date'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ungültiges Datum']));
}

$weekly_data = [];

// Für die nächsten 7 Tage
for ($i = 0; $i < 7; $i++) {
    $current_date = date('Y-m-d', strtotime($start_date . " +$i days"));
    $day_stats = [];
    
    // 1. Gäste im Haus (anreise <= heute < abreise)
    $sql_guests = "
    SELECT SUM(r.betten + r.dz + r.lager + r.sonder) as total_guests
    FROM `AV-Res` r
    WHERE DATE(r.anreise) <= ? 
    AND DATE(r.abreise) > ? 
    AND r.storno = 0
    ";
    
    $stmt = $mysqli->prepare($sql_guests);
    $stmt->bind_param('ss', $current_date, $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $day_stats['guests_in_house'] = (int)$result->fetch_assoc()['total_guests'];
    
    // 2. Hunde im Haus
    $sql_dogs = "
    SELECT SUM(r.hund) as total_dogs
    FROM `AV-Res` r
    WHERE DATE(r.anreise) <= ? 
    AND DATE(r.abreise) > ? 
    AND r.storno = 0
    ";
    
    $stmt = $mysqli->prepare($sql_dogs);
    $stmt->bind_param('ss', $current_date, $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $day_stats['dogs_in_house'] = (int)$result->fetch_assoc()['total_dogs'];
    
    // 3. Anreisen an diesem Tag
    $sql_arrivals = "
    SELECT 
        COUNT(*) as reservations_arriving,
        SUM(r.betten + r.dz + r.lager + r.sonder) as guests_arriving
    FROM `AV-Res` r
    WHERE DATE(r.anreise) = ? 
    AND r.storno = 0
    ";
    
    $stmt = $mysqli->prepare($sql_arrivals);
    $stmt->bind_param('s', $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $arrival_data = $result->fetch_assoc();
    $day_stats['reservations_arriving'] = (int)$arrival_data['reservations_arriving'];
    $day_stats['guests_arriving'] = (int)$arrival_data['guests_arriving'];
    
    // 4. Abreisen an diesem Tag
    $sql_departures = "
    SELECT 
        COUNT(*) as reservations_departing,
        SUM(r.betten + r.dz + r.lager + r.sonder) as guests_departing
    FROM `AV-Res` r
    WHERE DATE(r.abreise) = ? 
    AND r.storno = 0
    ";
    
    $stmt = $mysqli->prepare($sql_departures);
    $stmt->bind_param('s', $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $departure_data = $result->fetch_assoc();
    $day_stats['reservations_departing'] = (int)$departure_data['reservations_departing'];
    $day_stats['guests_departing'] = (int)$departure_data['guests_departing'];
    
    // Datum und Wochentag hinzufügen
    $day_stats['date'] = $current_date;
    
    // Deutsche Wochentage
    $german_days = ['Sun' => 'So', 'Mon' => 'Mo', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do', 'Fri' => 'Fr', 'Sat' => 'Sa'];
    $english_day = date('D', strtotime($current_date));
    $day_stats['day_name'] = $german_days[$english_day];
    
    $day_stats['formatted_date'] = date('d.m.', strtotime($current_date));
    
    $weekly_data[] = $day_stats;
}

echo json_encode($weekly_data, JSON_PRETTY_PRINT);
?>
