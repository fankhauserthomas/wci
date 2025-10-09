<?php
/**
 * API: Histogram Quota Data
 * 
 * Liefert Quota-Daten nach Kategorien für das Histogram
 * Format: Array mit Quota-Werten pro Tag und Kategorie
 */

require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');
header('Cache-Control: max-age=300'); // 5 Minuten cachen

// Parameter
$startDate = $_GET['start'] ?? $_GET['from'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? $_GET['to'] ?? date('Y-m-d', strtotime($startDate . ' +31 days'));

try {
    // Validierung
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    
    if ($start > $end) {
        throw new Exception('Start date must be before end date');
    }
    
    // SQL: Quota-Daten nach Tag und Kategorie
    $sql = "
    WITH 
    -- 1. Alle Tage im Zeitraum generieren
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

    -- 2. Quota-Daten mit Category-Mapping
    quota_categories AS (
        SELECT 
            hq.id as quota_id,
            hq.date_from,
            hq.date_to,
            hqc.category_id,
            hqc.total_beds,
            CASE 
                WHEN hqc.category_id = 1958 THEN 'ML'   -- Matratzenlager
                WHEN hqc.category_id = 2293 THEN 'MBZ'  -- Mehrbettzimmer
                WHEN hqc.category_id = 2381 THEN '2BZ'  -- Zweibettzimmer
                WHEN hqc.category_id = 6106 THEN 'SK'   -- Sonderkontingent
                ELSE 'UNKNOWN'
            END as category_type
        FROM hut_quota hq
        LEFT JOIN hut_quota_categories hqc ON hq.id = hqc.hut_quota_id
        WHERE hqc.category_id IS NOT NULL
    )

    -- 3. Aktive Quotas für jeden Tag
    SELECT 
        dr.datum as date,
        COALESCE(SUM(CASE WHEN qc.category_type = 'ML' THEN qc.total_beds ELSE 0 END), 0) as quota_lager,
        COALESCE(SUM(CASE WHEN qc.category_type = 'MBZ' THEN qc.total_beds ELSE 0 END), 0) as quota_betten,
        COALESCE(SUM(CASE WHEN qc.category_type = '2BZ' THEN qc.total_beds ELSE 0 END), 0) as quota_dz,
        COALESCE(SUM(CASE WHEN qc.category_type = 'SK' THEN qc.total_beds ELSE 0 END), 0) as quota_sonder
    FROM date_range dr
    LEFT JOIN quota_categories qc ON dr.datum >= qc.date_from AND dr.datum < qc.date_to
    GROUP BY dr.datum
    ORDER BY dr.datum
    ";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('sss', $startDate, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $quotaData = [];
    while ($row = $result->fetch_assoc()) {
        $quotaData[] = [
            'date' => $row['date'],
            'quota_lager' => (int)$row['quota_lager'],
            'quota_betten' => (int)$row['quota_betten'],
            'quota_dz' => (int)$row['quota_dz'],
            'quota_sonder' => (int)$row['quota_sonder'],
            'quota_total' => (int)($row['quota_lager'] + $row['quota_betten'] + $row['quota_dz'] + $row['quota_sonder'])
        ];
    }
    
    $stmt->close();
    $mysqli->close();
    
    // Response
    echo json_encode([
        'success' => true,
        'data' => $quotaData,
        'count' => count($quotaData),
        'dateRange' => [
            'start' => $startDate,
            'end' => $endDate
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
