<?php
require_once 'config.php';

echo "=== Vergleichstest für erste 5 Datensätze ===\n\n";

// Setze SQL Mode
$mysqli->query("SET SESSION sql_mode = ''");

// Lade erste 5 av_ids aus WebImp
$webimpQuery = $mysqli->query("SELECT av_id FROM `AV-Res-webImp` ORDER BY av_id LIMIT 5");
$testAvIds = [];
while ($row = $webimpQuery->fetch_assoc()) {
    $testAvIds[] = (int)$row['av_id'];
}

foreach ($testAvIds as $av_id) {
    echo "=== Vergleich für av_id $av_id ===\n";
    
    // Daten aus AV-Res
    $existingQuery = $mysqli->query("SELECT * FROM `AV-Res` WHERE av_id = $av_id");
    $existing = $existingQuery->fetch_assoc();
    
    // Daten aus WebImp
    $webimpSourceQuery = $mysqli->query("
        SELECT
          w.av_id,
          w.anreise,
          w.abreise,
          COALESCE(w.lager,  0) AS lager,
          COALESCE(w.betten, 0) AS betten,
          COALESCE(w.dz,     0) AS dz,
          COALESCE(w.sonder, 0) AS sonder,
          COALESCE(w.hp,     0) AS hp,
          COALESCE(w.vegi,   0) AS vegi,
          w.gruppe,
          w.bem_av,
          w.nachname,
          w.vorname,
          w.handy,
          w.email,
          w.email_date,
          CASE
            WHEN UPPER(TRIM(w.vorgang)) = 'DISCARDED' THEN 1
            WHEN UPPER(TRIM(w.vorgang)) = 'CONFIRMED' THEN 0
            ELSE 0
          END AS storno,
          CASE WHEN COALESCE(w.hp,0)=1 THEN 1 ELSE 4 END AS arr,
          COALESCE(w.`timestamp`, NOW()) AS `timestamp`,
          w.vorgang
        FROM `AV-Res-webImp` w
        WHERE w.av_id = $av_id
    ");
    $webimp = $webimpSourceQuery->fetch_assoc();
    
    if (!$existing || !$webimp) {
        echo "Daten nicht gefunden!\n";
        continue;
    }
    
    // Normalisiere existierende Daten
    $existingNorm = [
        'anreise' => $existing['anreise'],
        'abreise' => $existing['abreise'], 
        'lager' => (int)$existing['lager'],
        'betten' => (int)$existing['betten'],
        'dz' => (int)$existing['dz'],
        'sonder' => (int)$existing['sonder'],
        'hp' => (int)$existing['hp'],
        'vegi' => (int)$existing['vegi'],
        'gruppe' => ($existing['gruppe'] === '' || $existing['gruppe'] === null) ? null : $existing['gruppe'],
        'bem_av' => ($existing['bem_av'] === '' || $existing['bem_av'] === null) ? null : $existing['bem_av'],
        'nachname' => ($existing['nachname'] === '' || $existing['nachname'] === null) ? null : $existing['nachname'],
        'vorname' => ($existing['vorname'] === '' || $existing['vorname'] === null) ? null : $existing['vorname'],
        'handy' => ($existing['handy'] === '' || $existing['handy'] === null) ? null : $existing['handy'],
        'email' => ($existing['email'] === '' || $existing['email'] === null) ? null : $existing['email'],
        'email_date' => ($existing['email_date'] === '' || $existing['email_date'] === null || $existing['email_date'] === '0000-00-00 00:00:00') ? null : $existing['email_date'],
        'storno' => (int)$existing['storno'],
        'arr' => (int)$existing['arr'],
        'vorgang' => ($existing['vorgang'] === '' || $existing['vorgang'] === null) ? null : $existing['vorgang']
    ];
    
    // Normalisiere WebImp Daten
    $webimpNorm = [
        'anreise' => $webimp['anreise'] ?: null,
        'abreise' => $webimp['abreise'] ?: null,
        'lager' => (int)$webimp['lager'],
        'betten' => (int)$webimp['betten'],
        'dz' => (int)$webimp['dz'],
        'sonder' => (int)$webimp['sonder'],
        'hp' => (int)$webimp['hp'],
        'vegi' => (int)$webimp['vegi'],
        'gruppe' => ($webimp['gruppe'] === '' || $webimp['gruppe'] === null) ? null : $webimp['gruppe'],
        'bem_av' => ($webimp['bem_av'] === '' || $webimp['bem_av'] === null) ? null : $webimp['bem_av'],
        'nachname' => ($webimp['nachname'] === '' || $webimp['nachname'] === null) ? null : $webimp['nachname'],
        'vorname' => ($webimp['vorname'] === '' || $webimp['vorname'] === null) ? null : $webimp['vorname'],
        'handy' => ($webimp['handy'] === '' || $webimp['handy'] === null) ? null : $webimp['handy'],
        'email' => ($webimp['email'] === '' || $webimp['email'] === null) ? null : $webimp['email'],
        'email_date' => ($webimp['email_date'] === '' || $webimp['email_date'] === null || $webimp['email_date'] === '0000-00-00 00:00:00') ? null : $webimp['email_date'],
        'storno' => (int)$webimp['storno'],
        'arr' => (int)$webimp['arr'],
        'vorgang' => ($webimp['vorgang'] === '' || $webimp['vorgang'] === null) ? null : $webimp['vorgang']
    ];
    
    // Vergleiche
    $hasChanges = false;
    $differences = [];
    
    foreach ($existingNorm as $field => $existingValue) {
        $webimpValue = $webimpNorm[$field];
        
        if ($existingValue !== $webimpValue) {
            $hasChanges = true;
            $differences[] = "$field: existing='" . var_export($existingValue, true) . "' vs webimp='" . var_export($webimpValue, true) . "'";
        }
    }
    
    if ($hasChanges) {
        echo "ÄNDERUNGEN GEFUNDEN:\n";
        foreach ($differences as $diff) {
            echo "  - $diff\n";
        }
    } else {
        echo "KEINE ÄNDERUNGEN - identisch\n";
    }
    
    echo "\n";
}

$mysqli->close();
?>
