<?php
// Quick test to verify the disposition logic syntax

// Mock data
$roomDetails = [
    ['resid' => 123],
    ['resid' => 456],
    ['resid' => 789]
];

$masterReservations = [
    ['id' => 123, 'nachname' => 'Müller', 'vorname' => 'Hans', 'total_capacity' => 2],
    ['id' => 456, 'nachname' => 'Schmidt', 'vorname' => 'Anna', 'total_capacity' => 1],
    ['id' => 999, 'nachname' => 'Weber', 'vorname' => 'Klaus', 'total_capacity' => 3]
];

// Create lookup for disposed reservations
$disposedReservations = [];
foreach ($roomDetails as $detail) {
    if ($detail['resid']) {
        $disposedReservations[$detail['resid']] = true;
    }
}

echo "Disposed reservations lookup:\n";
print_r($disposedReservations);

echo "\nTesting disposition flags:\n";
foreach ($masterReservations as $res) {
    $isDisposed = isset($disposedReservations[$res['id']]);
    echo "Reservation {$res['id']} ({$res['nachname']}): " . ($isDisposed ? 'DISPOSED' : 'UNDISPOSED') . "\n";
}
?>
