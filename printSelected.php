<?php
// printSelected.php
// Sendet Druckjobs parallel an lokale und remote Datenbank

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

require 'config.php';

// CleanText-Funktion für QR-Codes (unterstützt Unicode)
function cleanText($input, $maxlen) {
    // Nur trimmen und auf maximale Länge kürzen
    $input = trim($input);
    
    // Auf maximal $maxlen Zeichen kürzen
    if (strlen($input) > $maxlen) {
        return substr($input, 0, $maxlen);
    } else {
        return $input;
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

// 2) Gastnamen und Zimmerinformationen für alle IDs laden
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
            $maxlen = 40;
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

// 3) Für jede übergebene Namens-ID in lokale Datenbank einfügen
$localCount = 0;

foreach ($ids as $rn_id) {
    if (!ctype_digit((string)$rn_id)) {
        continue;
    }
    
    // Gastnamen, Zimmerinfo und cardName für diese ID holen
    $rawName = $guestData[$rn_id]['rawName'] ?? '';
    $roomInfo = $guestData[$rn_id]['roomInfo'] ?? '';
    $cardName = $guestData[$rn_id]['cardName'] ?? '';
    
    // Lokale Datenbank - prt_queue INSERT
    $stmt->bind_param('issss', $rn_id, $printer, $rawName, $roomInfo, $cardName);
    if ($stmt->execute()) {
        $localCount++;
    }
    
    // Lokale Datenbank - AV-ResNamen.CardName UPDATE
    $updateStmt = $mysqli->prepare("UPDATE `AV-ResNamen` SET CardName = ? WHERE id = ?");
    if ($updateStmt) {
        $updateStmt->bind_param('si', $cardName, $rn_id);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

// 4) Cleanup
$stmt->close();

// 5) Optional: Sync-Trigger für Konsistenz
if (function_exists('triggerAutoSync')) {
    triggerAutoSync('print_jobs');
}

// 6) Output Buffer leeren und nach 60 Sekunden zurück zur Reservierungs-Detailseite
ob_end_clean();

// HTML-Seite mit automatischer Weiterleitung nach 60 Sekunden
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Druckauftrag gesendet</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 50px;
            background: #f8f9fa;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .success-icon {
            font-size: 64px;
            color: #28a745;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .info {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
        }
        .countdown {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            margin: 20px 0;
        }
        .back-button {
            display: inline-block;
            padding: 12px 24px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
            margin-top: 20px;
            transition: background 0.2s;
        }
        .back-button:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✓</div>
        <h1>Druckauftrag erfolgreich gesendet</h1>
        <div class="info">
            Die Karten werden jetzt gedruckt.<br>
            Sie werden automatisch in <span class="countdown" id="countdown">60</span> Sekunden zurückgeleitet.
        </div>
        <a href="reservation.html?id=<?php echo urlencode($resId); ?>" class="back-button">
            Sofort zurück
        </a>
    </div>

    <script>
        let seconds = 60;
        const countdownElement = document.getElementById('countdown');
        
        const timer = setInterval(() => {
            seconds--;
            countdownElement.textContent = seconds;
            
            if (seconds <= 0) {
                clearInterval(timer);
                window.location.href = 'reservation.html?id=<?php echo urlencode($resId); ?>';
            }
        }, 1000);
    </script>
</body>
</html>
<?php
exit;
