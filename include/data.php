<?php
// data.php

header('Content-Type: application/json');

// Anti-Cache Headers - Verhindert Browser/Proxy-Caching
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

require_once __DIR__ . '/../config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = 'ALLOW_INVALID_DATES'");

// GET-Parameter: date (YYYY-MM-DD), type ('arrival'|'departure')
$date = $_GET['date']   ?? '';
$type = $_GET['type']   ?? 'arrival';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ungültiges Datum']));
}

$col = ($type === 'departure') ? 'abreise' : 'anreise';

$sql = "
SELECT
    r.id,
    DATE_FORMAT(r.anreise, '%d.%m.%Y') AS anreise,
    DATE_FORMAT(r.abreise, '%d.%m.%Y') AS abreise,
    r.anreise AS anreise_raw,
    r.abreise AS abreise_raw,
    (r.betten + r.dz + r.lager + r.sonder) AS anzahl,
    IFNULL(r.vorname, '') AS vorname,
    IFNULL(r.nachname, '') AS nachname,
    a.kbez AS arr_kurz,
    r.hund,
    -- unverändert: Verhältnis aller eingetragenen Namen zu allen Bettenplätzen
    IFNULL(
      ROUND(
        c.count_names
        / NULLIF((r.betten+r.dz+r.lager+r.sonder), 0)
        * 100
      ,0)
    ,0) AS percent_chkin,
    -- neu: wieviel Prozent der eingetragenen Namen eingecheckt (logged in) sind
    IFNULL(
      ROUND(
        c.count_logged_in
        / NULLIF(c.count_names,0)
        * 100
      ,0)
    ,0) AS percent_logged_in,
    -- neu: wieviel Prozent der eingetragenen Namen ausgecheckt (logged out) sind
    IFNULL(
      ROUND(
        c.count_logged_out
        / NULLIF(c.count_names,0)
        * 100
      ,0)
    ,0) AS percent_logged_out,
    r.bem,
    r.bem_av,
    o.country AS origin,
    r.storno,
    r.av_id,
    IFNULL(r.invoice, 0) AS invoice
FROM `AV-Res` r
LEFT JOIN arr    a ON r.arr    = a.ID
LEFT JOIN origin o ON r.origin = o.id
LEFT JOIN (
    SELECT
      av_id,
      COUNT(CASE WHEN NoShow = 0 THEN 1 END) AS count_names,
      SUM(CASE 
        WHEN NoShow = 0
        AND checked_in IS NOT NULL 
        AND CAST(checked_in AS CHAR) != '' 
        AND CAST(checked_in AS CHAR) != '0000-00-00 00:00:00'
        AND checked_in > '1970-01-01 00:00:00'
        THEN 1 ELSE 0 END) AS count_logged_in,
      SUM(CASE 
        WHEN NoShow = 0
        AND checked_out IS NOT NULL 
        AND CAST(checked_out AS CHAR) != '' 
        AND CAST(checked_out AS CHAR) != '0000-00-00 00:00:00'
        AND checked_out > '1970-01-01 00:00:00'
        THEN 1 ELSE 0 END) AS count_logged_out
    FROM `AV-ResNamen`
    GROUP BY av_id
) c ON r.id = c.av_id
WHERE DATE(r.`$col`) = ?
ORDER BY TRIM(CONCAT_WS(' ', r.nachname, r.vorname)) ASC;
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $date);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    // cast to appropriate types
    $row['percent_chkin']       = (int)$row['percent_chkin'];
    $row['percent_logged_in']   = (int)$row['percent_logged_in'];
    $row['percent_logged_out']  = (int)$row['percent_logged_out'];
    $row['storno']              = (bool)$row['storno'];
    $row['av_id']               = (int)$row['av_id'];
    $row['invoice']             = (bool)$row['invoice'];
    $data[] = $row;
}

echo json_encode($data);
