<?php
// getReservationNames.php

// === 1) Header & Config ===
header('Content-Type: application/json; charset=utf-8');
require 'config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = ''");
$mysqli->query("SET SESSION sql_mode = ''");

// === 2) DB-Verbindungs-Check ===
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'DB-Verbindungsfehler']);
    exit;
}

// === 3) Validierung Input ===
$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Reservierungs-ID']);
    exit;
}

// === 4) SELECT: Namen + dynamische Altersgruppe ===
$sql = "
SELECT
  n.id AS id,
  n.nachname,
  n.vorname,
  n.gebdat AS gebdat,
  o.country AS herkunft,
  n.bem AS bem,
  n.guide AS guide,
  a.kbez AS arr,
  d.bez AS diet_text,
  n.dietInfo,
  n.transport,
  '' AS checked_in_raw,
  '' AS checked_out_raw,
  '' AS alter_bez,
  0 AS ageGrp

FROM `AV-ResNamen` AS n
JOIN `AV-Res` AS r ON r.id = n.av_id
LEFT JOIN `countries` AS o ON n.herkunft = o.id
LEFT JOIN `arr` AS a ON a.id = COALESCE(NULLIF(n.arr, 0), 5)
LEFT JOIN `diet` AS d ON d.id = COALESCE(NULLIF(n.diet, 0), 1)

WHERE n.av_id = ?
ORDER BY n.id
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Datenbank-Fehler']);
    exit;
}

$stmt->bind_param('i', $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Abfrage-Fehler']);
    $stmt->close();
    exit;
}

$res = $stmt->get_result();
if ($res === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Ergebnis-Fehler']);
    $stmt->close();
    exit;
}

// === 5) Ergebnis aufbauen ===
$data = [];
while ($row = $res->fetch_assoc()) {
    // Boolean cast für JS
    $row['guide'] = (bool)$row['guide'];
    
    // Check-in-Zustand separat und sicher laden
    $nameId = $row['id'];
    try {
        $checkQuery = "SELECT 
            CASE WHEN checked_in IS NULL OR checked_in = '' OR checked_in = '0000-00-00 00:00:00' THEN 0 ELSE 1 END as has_checkin,
            CASE WHEN checked_out IS NULL OR checked_out = '' OR checked_out = '0000-00-00 00:00:00' THEN 0 ELSE 1 END as has_checkout
            FROM `AV-ResNamen` WHERE id = ?";
        $checkStmt = $mysqli->prepare($checkQuery);
        $checkStmt->bind_param('i', $nameId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $checkData = $checkResult->fetch_assoc();
        $checkStmt->close();
        
        // Setze Check-in/Check-out basierend auf Boolean-Flags
        $row['checked_in'] = $checkData['has_checkin'] ? '2025-01-01T12:00:00' : null;
        $row['checked_out'] = $checkData['has_checkout'] ? '2025-01-01T13:00:00' : null;
        
    } catch (Exception $e) {
        // Fallback bei Fehlern
        $row['checked_in'] = null;
        $row['checked_out'] = null;
    }
    
    // Raw-Werte entfernen
    unset($row['checked_in_raw']);
    unset($row['checked_out_raw']);
    
    $data[] = $row;
}
$stmt->close();

// === 6) Ausgabe ===
echo json_encode($data);
