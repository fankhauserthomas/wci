<?php
/**
 * API Endpoint: getReservationMasterData.php
 * Liefert vollständige Stammdaten für Tooltip-Anzeige
 */

error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

try {
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }
    
    $startDate = $_GET['start'] ?? null;
    $endDate = $_GET['end'] ?? null;
    
    if (!$startDate) {
        $startDate = date('Y-m-d', strtotime('-1 day'));
    }
    if (!$endDate) {
        $endDate = date('Y-m-d', strtotime('+3 days'));
    }
    
    if (!DateTime::createFromFormat('Y-m-d', $startDate) || !DateTime::createFromFormat('Y-m-d', $endDate)) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD');
    }
    
    // Query für vollständige Stammdaten
    $sql = "
        SELECT 
            r.id,
            r.av_id,
            r.anreise,
            r.abreise,
            r.nachname,
            r.vorname,
            r.email,
            r.handy as telefon,
            r.gruppe as gruppenname,
            r.lager,
            r.betten,
            r.dz,
            r.sonder,
            r.hund,
            r.bem as bemerkung_intern,
            r.bem_av as bemerkung_av,
            r.storno,
            r.invoice,
            a.kbez as arrangement_kbez,
            a.bez as arrangement_bez,
            o.country as herkunft_land,
            c.country as land_name,
            (COALESCE(r.sonder, 0) + COALESCE(r.lager, 0) + COALESCE(r.betten, 0) + COALESCE(r.dz, 0)) as total_capacity
        FROM `AV-Res` r
        LEFT JOIN `arr` a ON r.arr = a.ID
        LEFT JOIN `origin` o ON r.origin = o.id
        LEFT JOIN `countries` c ON r.country_id = c.id
        WHERE r.anreise IS NOT NULL 
            AND r.abreise IS NOT NULL
            AND r.anreise <= ?
            AND r.abreise >= ?
            AND (r.storno IS NULL OR r.storno = 0)
        ORDER BY r.anreise ASC, r.nachname ASC
    ";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('ss', $endDate, $startDate);
    if (!$stmt->execute()) {
        throw new Exception('SQL execution failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $masterData = [];
    
    while ($row = $result->fetch_assoc()) {
        // Format dates
        $anreise = new DateTime($row['anreise']);
        $abreise = new DateTime($row['abreise']);
        
        $masterData[$row['id']] = [
            'id' => (int)$row['id'],
            'av_id' => (int)$row['av_id'],
            'nachname' => $row['nachname'] ?? '',
            'vorname' => $row['vorname'] ?? '',
            'email' => $row['email'] ?? '',
            'telefon' => $row['telefon'] ?? '',
            'gruppenname' => $row['gruppe'] ?? '',
            'anreise' => $anreise->format('d.m.Y'),
            'anreise_iso' => $anreise->format('Y-m-d'),
            'abreise' => $abreise->format('d.m.Y'),
            'abreise_iso' => $abreise->format('Y-m-d'),
            'arrangement_kbez' => $row['arrangement_kbez'] ?? '',
            'arrangement_bez' => $row['arrangement_bez'] ?? '',
            'dz' => (int)$row['dz'],
            'betten' => (int)$row['betten'],
            'lager' => (int)$row['lager'],
            'sonder' => (int)$row['sonder'],
            'total_capacity' => (int)$row['total_capacity'],
            'hund' => (bool)$row['hund'],
            'herkunft' => $row['herkunft_land'] ?? $row['land_name'] ?? '',
            'bemerkung_intern' => $row['bemerkung_intern'] ?? '',
            'bemerkung_av' => $row['bemerkung_av'] ?? '',
            'storno' => (bool)$row['storno'],
            'invoice' => (bool)$row['invoice']
        ];
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'data' => $masterData,
        'meta' => [
            'count' => count($masterData),
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'generated_at' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>
