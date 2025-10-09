<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }

    $startDate = $_GET['start'] ?? null;
    $endDate = $_GET['end'] ?? null;

    if (!$startDate || !$endDate) {
        throw new InvalidArgumentException('Parameter start und end erforderlich (YYYY-MM-DD).');
    }

    $start = DateTime::createFromFormat('Y-m-d', $startDate);
    $end = DateTime::createFromFormat('Y-m-d', $endDate);

    if (!$start || !$end) {
        throw new InvalidArgumentException('Ungültiges Datumsformat. Erwartet YYYY-MM-DD.');
    }

    $sql = "SELECT id, av_id, anreise, abreise,
                   COALESCE(dz, 0) AS dz,
                   COALESCE(betten, 0) AS betten,
                   COALESCE(lager, 0) AS lager,
                   COALESCE(sonder, 0) AS sonder,
                   nachname, vorname
            FROM `AV-Res`
            WHERE anreise <= ?
              AND abreise > ?
              AND (storno IS NULL OR storno = 0)
            ORDER BY anreise, id";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('ss', $endDate, $startDate);
    if (!$stmt->execute()) {
        throw new Exception('SQL execution failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $data = [];

    while ($row = $result->fetch_assoc()) {
        $anreise = DateTime::createFromFormat('Y-m-d H:i:s', $row['anreise'] ?? '');
        $abreise = DateTime::createFromFormat('Y-m-d H:i:s', $row['abreise'] ?? '');
        
        // Fallback für reines Datumsformat
        if (!$anreise) {
            $anreise = DateTime::createFromFormat('Y-m-d', $row['anreise'] ?? '');
        }
        if (!$abreise) {
            $abreise = DateTime::createFromFormat('Y-m-d', $row['abreise'] ?? '');
        }
        
        if (!$anreise || !$abreise) {
            continue;
        }

        $data[] = [
            'id' => (int)$row['id'],
            'av_id' => isset($row['av_id']) ? (int)$row['av_id'] : null,
            'start' => $anreise->format('Y-m-d'),
            'end' => $abreise->format('Y-m-d'),
            'guest_name' => trim(($row['nachname'] ?? '') . ' ' . ($row['vorname'] ?? '')),
            'capacity_details' => [
                'dz' => (int)$row['dz'],
                'betten' => (int)$row['betten'],
                'lager' => (int)$row['lager'],
                'sonder' => (int)$row['sonder']
            ]
        ];
    }

    $stmt->close();

    $stornoSql = "SELECT id, av_id, anreise, abreise,
                   COALESCE(dz, 0) AS dz,
                   COALESCE(betten, 0) AS betten,
                   COALESCE(lager, 0) AS lager,
                   COALESCE(sonder, 0) AS sonder,
                   nachname, vorname
            FROM `AV-Res`
            WHERE anreise <= ?
              AND abreise > ?
              AND (storno IS NOT NULL AND storno <> 0)
            ORDER BY anreise, id";

    $stmt = $mysqli->prepare($stornoSql);
    if (!$stmt) {
        throw new Exception('SQL prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('ss', $endDate, $startDate);
    if (!$stmt->execute()) {
        throw new Exception('SQL execution failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $stornoData = [];

    while ($row = $result->fetch_assoc()) {
        $anreise = DateTime::createFromFormat('Y-m-d H:i:s', $row['anreise'] ?? '');
        $abreise = DateTime::createFromFormat('Y-m-d H:i:s', $row['abreise'] ?? '');

        if (!$anreise) {
            $anreise = DateTime::createFromFormat('Y-m-d', $row['anreise'] ?? '');
        }
        if (!$abreise) {
            $abreise = DateTime::createFromFormat('Y-m-d', $row['abreise'] ?? '');
        }

        if (!$anreise || !$abreise) {
            continue;
        }

        $stornoData[] = [
            'id' => (int)$row['id'],
            'av_id' => isset($row['av_id']) ? (int)$row['av_id'] : null,
            'start' => $anreise->format('Y-m-d'),
            'end' => $abreise->format('Y-m-d'),
            'guest_name' => trim(($row['nachname'] ?? '') . ' ' . ($row['vorname'] ?? '')),
            'capacity_details' => [
                'dz' => (int)$row['dz'],
                'betten' => (int)$row['betten'],
                'lager' => (int)$row['lager'],
                'sonder' => (int)$row['sonder']
            ]
        ];
    }

    $stmt->close();

    $availabilitySql = "SELECT datum, COALESCE(free_place, 0) AS free_places, hut_status
            FROM av_belegung
            WHERE datum >= ?
              AND datum <= ?
            ORDER BY datum";

    $stmt = $mysqli->prepare($availabilitySql);
    if (!$stmt) {
        throw new Exception('SQL prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('ss', $startDate, $endDate);
    if (!$stmt->execute()) {
        throw new Exception('SQL execution failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $availabilityData = [];

    while ($row = $result->fetch_assoc()) {
        $date = DateTime::createFromFormat('Y-m-d', $row['datum'] ?? '');
        if (!$date) {
            $date = DateTime::createFromFormat('Y-m-d H:i:s', $row['datum'] ?? '');
        }
        if (!$date) {
            continue;
        }

        $availabilityData[] = [
            'datum' => $date->format('Y-m-d'),
            'free_places' => (int)($row['free_places'] ?? 0),
            'hut_status' => isset($row['hut_status']) ? (string)$row['hut_status'] : null
        ];
    }

    $stmt->close();

    // === QUOTA-DATEN LADEN (nach Kategorien) ===
    $quotaSql = "WITH 
        -- 1. Date Range generieren
        date_range AS (
            SELECT 
                DATE(?) + INTERVAL (a.a + (10 * b.a) + (100 * c.a)) DAY as datum
            FROM 
                (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as a
                CROSS JOIN (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as b
                CROSS JOIN (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as c
            WHERE 
                DATE(?) + INTERVAL (a.a + (10 * b.a) + (100 * c.a)) DAY <= DATE(?)
        ),
        
        -- 2. Quota-Kategorien mit Mapping
        quota_categories AS (
            SELECT 
                hq.id as quota_id,
                hq.date_from,
                hq.date_to,
                SUM(CASE WHEN hqc.category_id = 1958 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_lager,
                SUM(CASE WHEN hqc.category_id = 2293 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_betten,
                SUM(CASE WHEN hqc.category_id = 2381 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_dz,
                SUM(CASE WHEN hqc.category_id = 6106 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_sonder
            FROM hut_quota hq
            LEFT JOIN hut_quota_categories hqc ON hq.id = hqc.hut_quota_id
            GROUP BY hq.id, hq.date_from, hq.date_to
        )
        
        -- 3. Aktive Quotas pro Tag
        SELECT 
            dr.datum,
            COALESCE(SUM(qc.quota_lager), 0) as quota_lager,
            COALESCE(SUM(qc.quota_betten), 0) as quota_betten,
            COALESCE(SUM(qc.quota_dz), 0) as quota_dz,
            COALESCE(SUM(qc.quota_sonder), 0) as quota_sonder,
            -- Mehrtägige Quota-Marker: MIN date_from und MAX date_to aller aktiven Quotas
            MIN(qc.date_from) as quota_date_from,
            MAX(qc.date_to) as quota_date_to
        FROM date_range dr
        LEFT JOIN quota_categories qc ON dr.datum >= qc.date_from AND dr.datum < qc.date_to
        GROUP BY dr.datum
        ORDER BY dr.datum";

    $stmt = $mysqli->prepare($quotaSql);
    if (!$stmt) {
        throw new Exception('Quota SQL prepare failed: ' . $mysqli->error);
    }

    $stmt->bind_param('sss', $startDate, $startDate, $endDate);
    if (!$stmt->execute()) {
        throw new Exception('Quota SQL execution failed: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $quotaDataByDate = [];

    while ($row = $result->fetch_assoc()) {
        $dateFrom = $row['quota_date_from'];
        $dateTo = $row['quota_date_to'];
        
        // Berechne ob Quota mehrtägig ist
        $isMultiDay = false;
        if ($dateFrom && $dateTo) {
            $fromTs = strtotime($dateFrom);
            $toTs = strtotime($dateTo);
            $daysDiff = ($toTs - $fromTs) / 86400; // Sekunden pro Tag
            $isMultiDay = $daysDiff > 1;
        }
        
        $quotaDataByDate[$row['datum']] = [
            'quota_lager' => (int)$row['quota_lager'],
            'quota_betten' => (int)$row['quota_betten'],
            'quota_dz' => (int)$row['quota_dz'],
            'quota_sonder' => (int)$row['quota_sonder'],
            'quota_is_multi_day' => $isMultiDay
        ];
    }

    $stmt->close();

    // Merge Quota-Daten in Availability
    foreach ($availabilityData as &$entry) {
        $date = $entry['datum'];
        if (isset($quotaDataByDate[$date])) {
            $entry = array_merge($entry, $quotaDataByDate[$date]);
        } else {
            // Fallback: 0 wenn keine Quota
            $entry['quota_lager'] = 0;
            $entry['quota_betten'] = 0;
            $entry['quota_dz'] = 0;
            $entry['quota_sonder'] = 0;
            $entry['quota_is_multi_day'] = false;
        }
    }
    unset($entry); // Break reference
    // === END QUOTA-DATEN ===

    echo json_encode([
        'success' => true,
        'data' => [
            'histogram' => $data,
            'storno' => $stornoData,
            'availability' => $availabilityData,
            'generated_at' => date('Y-m-d H:i:s')
        ]
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
