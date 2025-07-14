<?php
// getDashboardStats-debug.php - Mit ausführlichem Debug-Output
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$debug = [];
$debug['start_time'] = microtime(true);
$debug['php_version'] = phpversion();
$debug['date_requested'] = $_GET['date'] ?? date('Y-m-d');

try {
    $debug['step'] = 'Loading config';
    require_once __DIR__ . '/config-simple.php';
    $debug['config_loaded'] = true;
    
    // GET-Parameter: date (YYYY-MM-DD) für das Referenzdatum
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        die(json_encode(['error' => 'Ungültiges Datum', 'debug' => $debug]));
    }
    
    $debug['step'] = 'Checking database connection';
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('Keine MySQL-Verbindung verfügbar');
    }
    
    if ($mysqli->connect_error) {
        throw new Exception('MySQL Connect Error: ' . $mysqli->connect_error);
    }
    
    $debug['mysql_connected'] = true;
    $debug['mysql_host'] = $mysqli->host_info;
    $debug['mysql_server'] = $mysqli->server_info;
    
    $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
    $debug['tomorrow'] = $tomorrow;
    
    $stats = [];
    
    $debug['step'] = 'Query 1: Arrivals today';
    // 1. Anreisen heute
    $sql_arrivals = "
        SELECT 
            COUNT(*) as count_reservations,
            COALESCE(SUM(r.sonder + r.betten + r.dz + r.lager), 0) as total_guests
        FROM `AV-Res` r
        WHERE DATE(r.anreise) = ? 
        AND (r.storno = 0 OR r.storno IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_arrivals);
    if (!$stmt) {
        throw new Exception('Prepare failed for arrivals: ' . $mysqli->error);
    }
    
    $stmt->bind_param('s', $date);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed for arrivals: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $arrivals = $result->fetch_assoc();
    $debug['arrivals_raw'] = $arrivals;
    
    $stats['arrivals_today'] = [
        'reservations' => (int)($arrivals['count_reservations'] ?? 0),
        'guests' => (int)($arrivals['total_guests'] ?? 0)
    ];
    
    $debug['step'] = 'Query 2: Departures tomorrow';
    // 2. Abreisen morgen
    $sql_departures = "
        SELECT 
            COUNT(*) as count_reservations,
            COALESCE(SUM(r.sonder + r.betten + r.dz + r.lager), 0) as total_guests
        FROM `AV-Res` r
        WHERE DATE(r.abreise) = ? 
        AND (r.storno = 0 OR r.storno IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_departures);
    if (!$stmt) {
        throw new Exception('Prepare failed for departures: ' . $mysqli->error);
    }
    
    $stmt->bind_param('s', $tomorrow);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed for departures: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $departures = $result->fetch_assoc();
    $debug['departures_raw'] = $departures;
    
    $stats['departures_tomorrow'] = [
        'reservations' => (int)($departures['count_reservations'] ?? 0),
        'guests' => (int)($departures['total_guests'] ?? 0)
    ];
    
    $debug['step'] = 'Query 3: Current guests';
    // 3. Aktuelle Gäste im Haus
    $sql_current = "
        SELECT COALESCE(SUM(r.sonder + r.betten + r.dz + r.lager), 0) as current_guests
        FROM `AV-Res` r
        WHERE DATE(r.anreise) <= ? 
        AND DATE(r.abreise) > ?
        AND (r.storno = 0 OR r.storno IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_current);
    if (!$stmt) {
        throw new Exception('Prepare failed for current: ' . $mysqli->error);
    }
    
    $stmt->bind_param('ss', $date, $date);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed for current: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $current = $result->fetch_assoc();
    $debug['current_raw'] = $current;
    
    $stats['current_guests'] = (int)($current['current_guests'] ?? 0);
    
    $debug['step'] = 'Query 4: Pending checkins';
    // 4. Ausstehende Check-ins
    $sql_pending = "
        SELECT COUNT(*) as pending_checkins
        FROM `AV-Res` r
        WHERE DATE(r.anreise) = ? 
        AND (r.storno = 0 OR r.storno IS NULL)
        AND (r.guide = 0 OR r.guide IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_pending);
    if (!$stmt) {
        throw new Exception('Prepare failed for pending: ' . $mysqli->error);
    }
    
    $stmt->bind_param('s', $date);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed for pending: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $pending = $result->fetch_assoc();
    $debug['pending_raw'] = $pending;
    
    $stats['pending_checkins'] = (int)($pending['pending_checkins'] ?? 0);
    
    $debug['step'] = 'Success';
    $debug['end_time'] = microtime(true);
    $debug['duration_ms'] = round(($debug['end_time'] - $debug['start_time']) * 1000, 2);
    
    $stats['debug'] = $debug;
    
    echo json_encode($stats, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    $debug['step'] = 'ERROR';
    $debug['error'] = $e->getMessage();
    $debug['end_time'] = microtime(true);
    $debug['duration_ms'] = round(($debug['end_time'] - $debug['start_time']) * 1000, 2);
    
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'debug' => $debug
    ], JSON_PRETTY_PRINT);
}
?>
