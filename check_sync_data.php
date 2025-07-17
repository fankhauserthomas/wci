<?php
require_once 'config.php';

try {
    // Database connections
    $connLocal = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    $connRemote = new mysqli($remoteDbHost, $remoteDbUser, $remoteDbPass, $remoteDbName);

    if ($connLocal->connect_error) {
        throw new Exception("Local connection failed: " . $connLocal->connect_error);
    }
    if ($connRemote->connect_error) {
        throw new Exception("Remote connection failed: " . $connRemote->connect_error);
    }

    // Schauen wir nach neuen Einträgen von gestern
    $sql = "SELECT COUNT(*) as count FROM `AV-ResNamen` WHERE DATE(sync_timestamp) = '2025-07-15'";
    $result = $connLocal->query($sql);
    $local = $result->fetch_assoc()['count'];

    $result = $connRemote->query($sql);
    $remote = $result->fetch_assoc()['count'];

    echo "Einträge vom 15.07.2025:\n";
    echo "Lokal: $local\n";
    echo "Remote: $remote\n";

    // Schauen wir nach den neuesten Einträgen
    $sql = "SELECT id, vorname, nachname, sync_timestamp, sync_source FROM `AV-ResNamen` ORDER BY sync_timestamp DESC LIMIT 10";
    echo "\nNeueste Einträge lokal:\n";
    $result = $connLocal->query($sql);
    while($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, {$row['vorname']} {$row['nachname']}, Sync: {$row['sync_timestamp']}, Source: {$row['sync_source']}\n";
    }

    echo "\nNeueste Einträge remote:\n";
    $result = $connRemote->query($sql);
    while($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, {$row['vorname']} {$row['nachname']}, Sync: {$row['sync_timestamp']}, Source: {$row['sync_source']}\n";
    }

    // Prüfen welche IDs nur in einer DB vorhanden sind
    echo "\n=== Vergleich der IDs ===\n";
    
    // IDs die nur remote vorhanden sind
    $sql = "SELECT r.id, r.vorname, r.nachname, r.sync_timestamp 
            FROM `AV-ResNamen` r
            WHERE r.id NOT IN (SELECT id FROM `AV-ResNamen`)
            ORDER BY r.sync_timestamp DESC LIMIT 10";
    
    echo "IDs nur in Remote DB:\n";
    $result = $connRemote->query($sql);
    while($row = $result->fetch_assoc()) {
        echo "ID: {$row['id']}, {$row['vorname']} {$row['nachname']}, Sync: {$row['sync_timestamp']}\n";
    }

} catch (Exception $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
}
?>
