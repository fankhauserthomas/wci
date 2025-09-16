<?php
require_once 'config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = ''");
$mysqli->query("SET SESSION sql_mode = ''");

echo "=== Test reservierungen/api/getReservationNames.php functionality ===\n";

// Find a reservation with names
$resResult = $mysqli->query("
    SELECT r.id, r.nachname, r.vorname 
    FROM `AV-Res` r 
    WHERE EXISTS (SELECT 1 FROM `AV-ResNamen` n WHERE n.av_id = r.id) 
    LIMIT 1
");
if ($resResult && $resResult->num_rows > 0) {
    $reservation = $resResult->fetch_assoc();
    $resId = $reservation['id'];
    
    echo "Testing with Reservation ID: $resId ({$reservation['nachname']}, {$reservation['vorname']})\n\n";
    
    // Simulate the API call
    $_GET['id'] = $resId;
    
    // Include the API and capture output
    ob_start();
    include 'reservierungen/api/getReservationNames.php';
    $output = ob_get_clean();
    
    echo "API Response:\n";
    $data = json_decode($output, true);
    if ($data && is_array($data)) {
        foreach ($data as $person) {
            echo "- {$person['nachname']}, {$person['vorname']} ";
            echo "(Alter: '{$person['alter_bez']}', Gruppe: {$person['ageGrp']}) ";
            echo "Gebdat: {$person['gebdat']}\n";
        }
    } else {
        echo "Raw output: $output\n";
    }
} else {
    echo "No reservations with names found.\n";
}

$mysqli->close();
?>
