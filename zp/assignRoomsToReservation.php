<?php
// assignRoomsToReservation.php - Persistiert Zimmerzuweisungen (pro Tag) für eine Reservierung
// Erwartet JSON: { res_id:number, start: 'YYYY-MM-DD', end: 'YYYY-MM-DD', assignments: { [roomId]: count } }

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid payload');
    }

    $resId = isset($input['res_id']) ? (int)$input['res_id'] : 0;
    $start = isset($input['start']) ? trim($input['start']) : '';
    $end = isset($input['end']) ? trim($input['end']) : '';
    $assignments = isset($input['assignments']) && is_array($input['assignments']) ? $input['assignments'] : [];

    if ($resId <= 0) throw new InvalidArgumentException('res_id fehlt/ungültig');
    if (!$start || !$end) throw new InvalidArgumentException('start/end fehlt');

    $startDt = DateTime::createFromFormat('Y-m-d', $start);
    $endDt = DateTime::createFromFormat('Y-m-d', $end);
    if (!$startDt || !$endDt) throw new InvalidArgumentException('start/end Format YYYY-MM-DD');
    if ($endDt <= $startDt) throw new InvalidArgumentException('end muss nach start liegen');

    // Lade Zimmerkapazitäten in Map
    $roomCap = [];
    $capRes = $mysqli->query("SELECT id, COALESCE(kapazitaet, capacity) AS cap FROM zp_zimmer");
    if ($capRes) {
        while ($row = $capRes->fetch_assoc()) {
            $roomCap[(int)$row['id']] = max(0, (int)$row['cap']);
        }
        $capRes->free();
    }

    // Bestehende Detailzeilen für diesen Zeitraum und resId laden
    $stmt = $mysqli->prepare("SELECT ID, zimID, von, bis, anz FROM AV_ResDet WHERE resid = ? AND bis > ? AND von < ?");
    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $mysqli->error);
    $startParam = $startDt->format('Y-m-d');
    $endParam = $endDt->format('Y-m-d');
    $stmt->bind_param('iss', $resId, $startParam, $endParam);
    if (!$stmt->execute()) throw new RuntimeException('Query failed: ' . $stmt->error);
    $existing = [];
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) { $existing[] = $row; }
    $stmt->close();

    // Entferne bestehende Details für den Zeitraum (wir schreiben neu)
    $stmtDel = $mysqli->prepare("DELETE FROM AV_ResDet WHERE resid = ? AND bis > ? AND von < ?");
    if (!$stmtDel) throw new RuntimeException('Prepare delete failed: ' . $mysqli->error);
    $stmtDel->bind_param('iss', $resId, $startParam, $endParam);
    if (!$stmtDel->execute()) throw new RuntimeException('Delete failed: ' . $stmtDel->error);
    $stmtDel->close();

    // Validierung gegen Kapazitäten: Für jeden Raum die minimale freie Kapazität ermitteln
    // Tagesweise Belegung im Zielzeitraum anderer Reservierungen laden
    $stmtOther = $mysqli->prepare("SELECT zimID, von, bis, anz FROM AV_ResDet WHERE resid <> ? AND bis > ? AND von < ?");
    if (!$stmtOther) throw new RuntimeException('Prepare other failed: ' . $mysqli->error);
    $stmtOther->bind_param('iss', $resId, $startParam, $endParam);
    if (!$stmtOther->execute()) throw new RuntimeException('Query other failed: ' . $stmtOther->error);
    $others = [];
    $rs2 = $stmtOther->get_result();
    while ($row = $rs2->fetch_assoc()) { $others[] = $row; }
    $stmtOther->close();

    // Erzeuge Tages-Index der Fremdbelegung
    $dayKey = function($dt){ return (int)floor(strtotime($dt)/86400); };
    $rangeStartKey = $dayKey($startParam);
    $rangeEndKey = $dayKey($endParam); // exclusive
    $usedPerDayRoom = [];
    foreach ($others as $o){
        $rid = (int)$o['zimID'];
        $sk = $dayKey($o['von']);
        $ek = $dayKey($o['bis']);
        $anz = max(0, (int)$o['anz']);
        for ($k=$sk; $k<$ek; $k++){
            $usedPerDayRoom[$rid][$k] = ($usedPerDayRoom[$rid][$k] ?? 0) + $anz;
        }
    }

    // Validieren assignments
    foreach ($assignments as $ridStr => $count){
        $rid = (int)$ridStr; $count = max(0, (int)$count);
        if ($count === 0) continue;
        $cap = $roomCap[$rid] ?? 0;
        if ($cap <= 0) throw new InvalidArgumentException("Zimmer $rid hat keine Kapazität");
        for ($k=$rangeStartKey; $k<$rangeEndKey; $k++){
            $used = $usedPerDayRoom[$rid][$k] ?? 0;
            if ($used + $count > $cap){
                throw new InvalidArgumentException("Überbelegung in Zimmer $rid an Tag $k: $used+$count > $cap");
            }
        }
    }

    // Speichern: Eine Zeile pro Raum (gesamter Zeitraum) mit anz = count
    $ins = $mysqli->prepare("INSERT INTO AV_ResDet (resid, zimID, von, bis, anz, bez, col, hund, arr, tab, note, dx, dy, ParentID) VALUES (?, ?, ?, ?, ?, '', '#3498db', 0, NULL, 'local', '', 0, 0, 0)");
    if (!$ins) throw new RuntimeException('Prepare insert failed: ' . $mysqli->error);

    $inserted = 0;
    foreach ($assignments as $ridStr => $count){
        $rid = (int)$ridStr; $count = max(0, (int)$count);
        if ($count <= 0) continue;
        if (!$ins->bind_param('iissi', $resId, $rid, $startParam, $endParam, $count)) {
            throw new RuntimeException('Bind insert failed: ' . $ins->error);
        }
        if (!$ins->execute()) {
            throw new RuntimeException('Insert failed: ' . $ins->error);
        }
        $inserted += $ins->affected_rows;
    }
    $ins->close();

    // Optionale Auto-Sync
    if (function_exists('triggerAutoSync')) {
        triggerAutoSync('assign_rooms');
    }

    echo json_encode([ 'success' => true, 'inserted' => $inserted ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([ 'success' => false, 'error' => $e->getMessage() ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([ 'success' => false, 'error' => $e->getMessage() ]);
}
