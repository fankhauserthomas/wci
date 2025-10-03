<?php
// getReservationNames.php

// === 1) Header & Config ===
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

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

// === 4) Prüfen, ob Logis-Spalte vorhanden ist
$logisColumnExists = false;
$columnCheck = $mysqli->query("SHOW COLUMNS FROM `AV-ResNamen` LIKE 'logis'");
if ($columnCheck) {
    $logisColumnExists = $columnCheck->num_rows > 0;
    $columnCheck->free();
}

// === 5) SELECT: Namen + dynamische Altersgruppe ===
$logisSelect = $logisColumnExists
    ? "  n.logis AS logis_id,\n  l.kbez AS logis_label,\n"
    : "  NULL AS logis_id,\n  NULL AS logis_label,\n";

$logisJoin = $logisColumnExists
    ? "LEFT JOIN `logis` AS l ON l.id = NULLIF(n.logis, 0)\n"
    : '';

$sql = "
SELECT
  n.id AS id,
  n.nachname,
  n.vorname,
  n.gebdat AS gebdat,
  o.country AS herkunft,
  n.bem AS bem,
  n.guide AS guide,
  n.av AS av,
" . $logisSelect . "  n.NoShow AS NoShow,
  a.kbez AS arr,
  d.bez AS diet_text,
  n.dietInfo,
  n.transport,
  n.checked_in,
  n.checked_out,
  n.CardName,
  CASE 
    WHEN n.gebdat IS NULL OR n.gebdat = '0000-00-00' OR n.gebdat < '1900-01-01' THEN ''
    ELSE COALESCE(ag.bez, CONCAT(TIMESTAMPDIFF(YEAR, n.gebdat, CURDATE()), ' J.'))
  END AS alter_bez,
  CASE 
    WHEN n.gebdat IS NULL OR n.gebdat = '0000-00-00' OR n.gebdat < '1900-01-01' THEN 0
    ELSE 
      COALESCE(
        (SELECT id FROM Ages WHERE TIMESTAMPDIFF(YEAR, n.gebdat, CURDATE()) BETWEEN von AND bis LIMIT 1),
        0
      )
  END AS ageGrp

FROM `AV-ResNamen` AS n
JOIN `AV-Res` AS r ON r.id = n.av_id
LEFT JOIN `countries` AS o ON n.herkunft = o.id
LEFT JOIN `arr` AS a ON a.id = COALESCE(NULLIF(n.arr, 0), 5)
" . $logisJoin . "LEFT JOIN `diet` AS d ON d.id = COALESCE(NULLIF(n.diet, 0), 1)
LEFT JOIN `Ages` AS ag ON TIMESTAMPDIFF(YEAR, n.gebdat, CURDATE()) BETWEEN ag.von AND ag.bis

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

// === 6) Ergebnis aufbauen ===
$data = [];
while ($row = $res->fetch_assoc()) {
    // Boolean cast für JS
    $row['guide'] = (bool)$row['guide'];
    $row['NoShow'] = (bool)$row['NoShow'];

    if (array_key_exists('logis_id', $row)) {
        $row['logis_id'] = $row['logis_id'] !== null ? (int)$row['logis_id'] : null;
    }
    
    // Check-in/Check-out-Werte normalisieren
    // NULL, leerer String oder 0000-00-00 00:00:00 = nicht eingecheckt
    if (empty($row['checked_in']) || $row['checked_in'] === '0000-00-00 00:00:00') {
        $row['checked_in'] = null;
    }
    
    if (empty($row['checked_out']) || $row['checked_out'] === '0000-00-00 00:00:00') {
        $row['checked_out'] = null;
    }
    
    // Debug: Log die ersten paar Datensätze
    if (count($data) < 2) {
        error_log("Debug row " . count($data) . ": checked_in=" . var_export($row['checked_in'], true) . ", checked_out=" . var_export($row['checked_out'], true));
    }
    
    $data[] = $row;
}
$stmt->close();

// === 7) Ausgabe ===
// Debug: Zeige die ersten 2 Datensätze zur Kontrolle
if (count($data) > 0) {
    $debugInfo = [
        'first_row_checked_in' => $data[0]['checked_in'] ?? 'NOT_SET',
        'first_row_checked_out' => $data[0]['checked_out'] ?? 'NOT_SET',
        'first_row_checked_in_type' => gettype($data[0]['checked_in'] ?? null),
        'total_rows' => count($data)
    ];
    // Temporär Debug-Info in Response Header
    header('X-Debug-Info: ' . json_encode($debugInfo));
}

echo json_encode($data);
