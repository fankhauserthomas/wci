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

    $sql = "SELECT id, anreise, abreise,
                   COALESCE(dz, 0) AS dz,
                   COALESCE(betten, 0) AS betten,
                   COALESCE(lager, 0) AS lager,
                   COALESCE(sonder, 0) AS sonder
            FROM `AV-Res`
            WHERE anreise <= ?
              AND abreise >= ?
              AND (storno IS NULL OR storno = 0)";

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
        $anreise = DateTime::createFromFormat('Y-m-d', $row['anreise'] ?? '');
        $abreise = DateTime::createFromFormat('Y-m-d', $row['abreise'] ?? '');
        if (!$anreise || !$abreise) {
            continue;
        }

        $data[] = [
            'id' => (int)$row['id'],
            'start' => $anreise->format('Y-m-d'),
            'end' => $abreise->format('Y-m-d'),
            'capacity_details' => [
                'dz' => (int)$row['dz'],
                'betten' => (int)$row['betten'],
                'lager' => (int)$row['lager'],
                'sonder' => (int)$row['sonder']
            ]
        ];
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'data' => [
            'histogram' => $data,
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
