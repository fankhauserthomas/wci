<?php
// getReservationDetails.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

// 0) ID validieren
$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige Reservierungs-ID']);
    exit;
}

// Optional: Include color calculation parameter
$includeColor = isset($_GET['includeColor']) && $_GET['includeColor'] === 'true';

// Ensure optional columns exist
$columnCheck = $mysqli->query("SHOW COLUMNS FROM `AV-Res` LIKE 'country_id'");
if ($columnCheck && $columnCheck->num_rows === 0) {
    $mysqli->query("ALTER TABLE `AV-Res` ADD COLUMN country_id INT DEFAULT NULL");
}
if ($columnCheck) {
    $columnCheck->free();
}

// 1) Reservierungs-Basisdaten abfragen (inkl. Betten/DZ/Lager/Sonder)
$sql1 = "
SELECT
    r.id,
    r.av_id,
    DATE_FORMAT(r.anreise, '%Y-%m-%dT%H:%i:%s') AS anreise,
    DATE_FORMAT(r.abreise, '%Y-%m-%dT%H:%i:%s') AS abreise,
    r.betten,
    r.dz,
    r.lager,
    r.sonder,
    (r.betten + r.dz + r.lager + r.sonder) AS anzahl,
    IFNULL(r.nachname, '') AS nachname,
    IFNULL(r.vorname, '')  AS vorname,
    IFNULL(r.email, '') AS email,
    COALESCE(r.invoice, 0) AS invoice,
    a.kbez                AS arrangement,
    r.bem,
    r.bem_av,
    o.country             AS origin,
    IFNULL(r.country_id, 0) AS country_id,
    ct.country            AS country_name
FROM `AV-Res` r
LEFT JOIN arr    a ON r.arr    = a.ID
LEFT JOIN origin o ON r.origin = o.id
LEFT JOIN countries ct ON r.country_id = ct.id
WHERE r.id = ?
LIMIT 1
";
$stmt1 = $mysqli->prepare($sql1);
if (!$stmt1) {
    http_response_code(500);
    echo json_encode(['error' => 'DB-Fehler (prepare1): '.$mysqli->error], JSON_UNESCAPED_UNICODE);
    exit;
}
$stmt1->bind_param('i', $id);
$stmt1->execute();
$res1 = $stmt1->get_result();
if ($res1->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Reservierung nicht gefunden']);
    exit;
}
$detail = $res1->fetch_assoc();
$stmt1->close();

if ($detail) {
    $detail['country_id'] = (int)($detail['country_id'] ?? 0);
}

// 2) Zimmer-Details abfragen
$sql2 = "
SELECT
    z.caption,
    z.kapazitaet,
    z.kategorie,
    d.bez   AS gast,
    d.anz
FROM `AV_ResDet` d
JOIN `zp_zimmer` z ON z.id = d.zimID
WHERE d.resid = ?
ORDER BY z.caption
";
$stmt2 = $mysqli->prepare($sql2);
if (!$stmt2) {
    http_response_code(500);
    echo json_encode(['error' => 'DB-Fehler (prepare2): '.$mysqli->error], JSON_UNESCAPED_UNICODE);
    exit;
}
$stmt2->bind_param('i', $id);
$stmt2->execute();
$stmt2->bind_result($caption, $kapazitaet, $kategorie, $gast, $anz);

$rooms = [];
while ($stmt2->fetch()) {
    $rooms[] = [
        'caption'    => $caption,
        'kapazitaet' => (int)$kapazitaet,
        'kategorie'  => $kategorie,
        'gast'       => $gast,
        'anz'        => (int)$anz,
    ];
}
$stmt2->close();

// 3) Calculate header color if requested
if ($includeColor && $detail) {
    // Determine header color based on invoice status
    $isInvoice = ($detail['invoice'] === 1 || $detail['invoice'] === '1' || $detail['invoice'] === true);
    $detail['headerColor'] = $isInvoice ? '#B8860B' : '#2d8f4f'; // Dark Gold : Dark Green
    $detail['headerColorName'] = $isInvoice ? 'DARK_GOLD' : 'DARK_GREEN';
    $detail['isInvoice'] = $isInvoice;
}

// 4) Ausgabe
echo json_encode([
    'detail' => $detail,
    'rooms'  => $rooms,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
