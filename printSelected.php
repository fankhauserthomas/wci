<?php
// printSelected.php
// Leitet zurück zu reservation.html?id=…

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

// 1) Insert vorbereiten
$stmt = $mysqli->prepare("INSERT INTO prt_queue (rn_id, prt) VALUES (?, ?)");
if (!$stmt) {
    // Bei DB-Fehlern ebenfalls zurück
    header('Location: reservation.html?id=' . urlencode($resId));
    exit;
}

// 2) Für jede übergebene Namens-ID einen Queue-Eintrag anlegen
foreach ($ids as $rn_id) {
    if (!ctype_digit((string)$rn_id)) {
        continue;
    }
    $stmt->bind_param('is', $rn_id, $printer);
    $stmt->execute();
}
$stmt->close();

// 3) Zurück zur Reservierungs-Detailseite
header('Location: reservation.html?id=' . urlencode($resId));
exit;
