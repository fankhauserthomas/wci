<?php
// printSelected.php
// Sendet Druckjobs parallel an lokale und remote Datenbank

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

require 'config.php';

// CleanText-Funktion für Code39-kompatible Namen
function cleanText($input, $maxlen) {
    // Gültige Code39-Zeichen (ohne Start/Stoppzeichen *)
    $allowedChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 -.$/+%';
    
    // Alles in Großbuchstaben umwandeln
    $input = strtoupper(trim($input));
    
    // Erlaubte Zeichen filtern
    $cleaned = '';
    for ($i = 0; $i < strlen($input); $i++) {
        $char = $input[$i];
        if (strpos($allowedChars, $char) !== false) {
            $cleaned .= $char;
        }
    }
    
    // Auf maximal $maxlen Zeichen kürzen
    if (strlen($cleaned) > $maxlen) {
        return substr($cleaned, 0, $maxlen);
    } else {
        return $cleaned;
    }
}

// 0) Parameter validieren
$printer = $_GET['printer'] ?? '';
$resId   = $_GET['resId']   ?? '';
$ids     = $_GET['id']      ?? [];

if ($printer === '' || !ctype_digit($resId) || !is_array($ids) || count($ids) === 0) {
    // Bei fehlenden Parametern einfach zurück zur Detailseite
    header('Location: reservation.html?id=' . urlencode($resId));
    exit;
}

// 1) Lokale Datenbank - INSERT vorbereiten (mit rawName, Info und cardName)
$stmt = $mysqli->prepare("INSERT INTO prt_queue (rn_id, prt, rawName, Info, cardName) VALUES (?, ?, ?, ?, ?)");
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
    $remoteStmt = $remoteDb->prepare("INSERT INTO prt_queue (rn_id, prt, rawName, Info, cardName) VALUES (?, ?, ?, ?, ?)");
    if ($remoteStmt) {
        $remoteSuccess = true;
    }
}

// 3) Gastnamen und Zimmerinformationen für alle IDs laden
$guestData = [];
if (!empty($ids)) {
    $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $guestQuery = "
        SELECT 
            n.id AS rn_id,
            TRIM(CONCAT_WS(' ', NULLIF(n.nachname, ''), NULLIF(n.vorname, ''))) AS rawName,
            r.anreise AS res_anreise,
            r.abreise AS res_abreise,
            TRIM(CONCAT_WS(' ', NULLIF(r.nachname, ''), NULLIF(r.vorname, ''))) AS res_hauptname,
            COALESCE(
                NULLIF(GROUP_CONCAT(
                    DISTINCT CONCAT(z.caption, ' (', d.anz, '/', z.kapazitaet, ') - ', e.stockwerk, '. Etage') 
                    SEPARATOR '\r\n'
                ), ''),
                'Keine Zimmer zugewiesen'
            ) AS roomInfo
        FROM `AV-ResNamen` AS n 
        INNER JOIN `AV-Res` AS r ON n.av_id = r.id 
        LEFT JOIN `AV_ResDet` AS d ON d.resid = r.id 
        LEFT JOIN `zp_zimmer` AS z ON d.ZimID = z.id 
        LEFT JOIN `zp_etage` AS e ON z.etage = e.nr 
        WHERE n.id IN ($idPlaceholders)
        GROUP BY n.id, n.nachname, n.vorname, r.anreise, r.abreise, r.nachname, r.vorname
    ";
    
    $guestStmt = $mysqli->prepare($guestQuery);
    if ($guestStmt) {
        $types = str_repeat('i', count($ids));
        $guestStmt->bind_param($types, ...$ids);
        $guestStmt->execute();
        $result = $guestStmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Aufenthaltsdauer berechnen
            $anreise = new DateTime($row['res_anreise']);
            $abreise = new DateTime($row['res_abreise']);
            $tageCount = $anreise->diff($abreise)->days;
            
            // CardName berechnen
            $input = trim($row['rawName']);  // rawName ist bereits "nachname vorname"
            $maxlen = 15;
            $cardName = cleanText($input, $maxlen);
            
            // Info-String aufbauen: Erste Zeile mit verkürzter Aufenthaltsdauer und Hauptname
            $infoLines = [];
            $infoLines[] = $tageCount . "T: " . trim($row['res_hauptname']);
            $infoLines[] = $row['roomInfo'] ?: '';
            
            $guestData[$row['rn_id']] = [
                'rawName' => $row['rawName'] ?: '',
                'roomInfo' => implode("\r\n", array_filter($infoLines)),
                'cardName' => $cardName
            ];
        }
        $guestStmt->close();
    }
}

// 4) Für jede übergebene Namens-ID parallel in beide Datenbanken einfügen
// 4) Für jede übergebene Namens-ID parallel in beide Datenbanken einfügen
$localCount = 0;
$remoteCount = 0;

foreach ($ids as $rn_id) {
    if (!ctype_digit((string)$rn_id)) {
        continue;
    }
    
    // Gastnamen, Zimmerinfo und cardName für diese ID holen
    $rawName = $guestData[$rn_id]['rawName'] ?? '';
    $roomInfo = $guestData[$rn_id]['roomInfo'] ?? '';
    $cardName = $guestData[$rn_id]['cardName'] ?? '';
    
    // Lokale Datenbank
    $stmt->bind_param('issss', $rn_id, $printer, $rawName, $roomInfo, $cardName);
    if ($stmt->execute()) {
        $localCount++;
    }
    
    // Remote Datenbank (parallel)
    if ($remoteSuccess && $remoteStmt) {
        $remoteStmt->bind_param('issss', $rn_id, $printer, $rawName, $roomInfo, $cardName);
        if ($remoteStmt->execute()) {
            $remoteCount++;
        }
    }
}

// 5) Cleanup
$stmt->close();
if ($remoteStmt) {
    $remoteStmt->close();
}
if ($remoteDb && !$remoteDb->connect_error) {
    $remoteDb->close();
}

// 6) Optional: Sync-Trigger für Konsistenz
if (function_exists('triggerAutoSync')) {
    triggerAutoSync('print_jobs');
}

// 7) Output Buffer leeren und zurück zur Reservierungs-Detailseite
ob_end_clean();
header('Location: reservation.html?id=' . urlencode($resId));
exit;
