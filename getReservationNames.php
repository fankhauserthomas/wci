<?php
// getReservationNames.php

// === 1) Header & Config ===
header('Content-Type: application/json; charset=utf-8');
require 'config.php';

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
  n.id                                        AS id,
  n.nachname,
  n.vorname,
  n.gebdat                                    AS gebdat,
  o.country                                   AS herkunft,
  n.bem                                       AS bem,
  n.guide                                     AS guide,
  a.kbez                                      AS arr,
  d.bez                                       AS diet_text,
  n.dietInfo,
  n.transport,
  DATE_FORMAT(n.checked_in,  '%Y-%m-%dT%H:%i:%s') AS checked_in,
  DATE_FORMAT(n.checked_out, '%Y-%m-%dT%H:%i:%s')  AS checked_out,

  /* final: entweder berechnet oder gespeichert */
  COALESCE(calc.kkbez, stored.kkbez)          AS alter_bez,
  COALESCE(calc.nr,   stored.nr)              AS ageGrp

FROM `AV-ResNamen` AS n
JOIN `AV-Res`        AS r ON r.id = n.av_id

LEFT JOIN `countries` AS o
  ON n.herkunft = o.id

LEFT JOIN `arr`       AS a
  ON a.id = COALESCE(NULLIF(n.arr, 0), 5)

LEFT JOIN `diet`      AS d
  ON d.id = COALESCE(NULLIF(n.diet, 0), 1)

/* 1) Berechnete Gruppe – nur wenn n.gebdat nicht NULL */
LEFT JOIN `Ages`      AS calc
  ON n.gebdat IS NOT NULL
  AND TIMESTAMPDIFF(
        YEAR,
        n.gebdat,
        r.anreise
      ) BETWEEN calc.von AND calc.bis

/* 2) Gespeicherte Gruppe – nur wenn n.gebdat NULL */
LEFT JOIN `Ages`      AS stored
  ON n.gebdat IS NULL
  AND stored.nr = n.ageGrp

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
    $data[] = $row;
}
$stmt->close();

// === 6) Ausgabe ===
echo json_encode($data);
