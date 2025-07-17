<?php
// printSelected.php
// Sendet Druckjobs parallel an lokale und remote Datenbank

require 'config.php';

// 0) Parameter validieren
$printer = $_GET['printer'] ?? '';
$resId   = $_GET['resId']   ?? '';
$ids     = $_GET['id']      ?? [];

if ($printer === '' || !ctype_digit($resId) || !is_array($ids) || count($ids) === 0) {
    // Bei fehlenden Parametern einfach zurück zur Detailseite
    header('Location: reservation.html?id=' . urlencode($resId));
    exit;
}

// 1) Lokale Datenbank - INSERT vorbereiten
$stmt = $mysqli->prepare("INSERT INTO prt_queue (rn_id, prt) VALUES (?, ?)");
if (!$stmt) {
    // Bei DB-Fehlern ebenfalls zurück
    header('Location: reservation.html?id=' . urlencode($resId));
    exit;
}

// 2) Remote Datenbank - Verbindung und INSERT vorbereiten
$remoteDb = new mysqli($remoteDbHost, $remoteDbUser, $remoteDbPass, $remoteDbName);
$remoteStmt = null;
$remoteSuccess = false;

if (!$remoteDb->connect_error) {
    $remoteDb->set_charset('utf8mb4');
    $remoteStmt = $remoteDb->prepare("INSERT INTO prt_queue (rn_id, prt) VALUES (?, ?)");
    if ($remoteStmt) {
        $remoteSuccess = true;
    }
}

// 3) Für jede übergebene Namens-ID parallel in beide Datenbanken einfügen
$localCount = 0;
$remoteCount = 0;

foreach ($ids as $rn_id) {
    if (!ctype_digit((string)$rn_id)) {
        continue;
    }
    
    // Lokale Datenbank
    $stmt->bind_param('is', $rn_id, $printer);
    if ($stmt->execute()) {
        $localCount++;
    }
    
    // Remote Datenbank (parallel)
    if ($remoteSuccess && $remoteStmt) {
        $remoteStmt->bind_param('is', $rn_id, $printer);
        if ($remoteStmt->execute()) {
            $remoteCount++;
        }
    }
}

// 4) Cleanup
$stmt->close();
if ($remoteStmt) {
    $remoteStmt->close();
}
if ($remoteDb && !$remoteDb->connect_error) {
    $remoteDb->close();
}

// 5) Optional: Sync-Trigger für Konsistenz
if (function_exists('triggerAutoSync')) {
    triggerAutoSync('print_jobs');
}

// 6) Zurück zur Reservierungs-Detailseite
header('Location: reservation.html?id=' . urlencode($resId));
exit;
