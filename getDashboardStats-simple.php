<?php
// getDashboardStats-simple.php - Dashboard-Statistiken ohne blockierte Auth-Dateien

require_once __DIR__ . '/auth-simple.php';

// Prüfe Authentifizierung (optional - entfernen falls das Dashboard auch ohne Login funktionieren soll)
if (!AuthManager::isAuthenticated()) {
    http_response_code(401);
    die(json_encode(['error' => 'Nicht authentifiziert']));
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
require_once __DIR__ . '/config-simple.php';

// GET-Parameter: date (YYYY-MM-DD) für das Referenzdatum
$date = $_GET['date'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    die(json_encode(['error' => 'Ungültiges Datum']));
}

$tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));

$stats = [];

try {
    // 1. Anreisen heute (nicht storniert)
    $sql_arrivals = "
        SELECT 
            COUNT(*) as count_reservations,
            SUM(r.sonder + r.betten + r.dz + r.lager) as total_guests
        FROM `AV-Res` r
        WHERE DATE(r.anreise) = ? 
        AND (r.storno = 0 OR r.storno IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_arrivals);
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $arrivals = $result->fetch_assoc();
    
    $stats['arrivals_today'] = [
        'reservations' => (int)$arrivals['count_reservations'],
        'guests' => (int)$arrivals['total_guests']
    ];

    // 2. Abreisen morgen (nicht storniert)
    $sql_departures = "
        SELECT 
            COUNT(*) as count_reservations,
            SUM(r.sonder + r.betten + r.dz + r.lager) as total_guests
        FROM `AV-Res` r
        WHERE DATE(r.abreise) = ? 
        AND (r.storno = 0 OR r.storno IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_departures);
    $stmt->bind_param('s', $tomorrow);
    $stmt->execute();
    $result = $stmt->get_result();
    $departures = $result->fetch_assoc();
    
    $stats['departures_tomorrow'] = [
        'reservations' => (int)$departures['count_reservations'],
        'guests' => (int)$departures['total_guests']
    ];

    // 3. Aktuelle Gäste im Haus (heute zwischen Anreise und Abreise)
    $sql_current = "
        SELECT SUM(r.sonder + r.betten + r.dz + r.lager) as current_guests
        FROM `AV-Res` r
        WHERE DATE(r.anreise) <= ? 
        AND DATE(r.abreise) > ?
        AND (r.storno = 0 OR r.storno IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_current);
    $stmt->bind_param('ss', $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $current = $result->fetch_assoc();
    
    $stats['current_guests'] = (int)$current['current_guests'];

    // 4. Ausstehende Check-ins (heute angereist, aber noch nicht eingecheckt)
    $sql_pending = "
        SELECT COUNT(*) as pending_checkins
        FROM `AV-Res` r
        WHERE DATE(r.anreise) = ? 
        AND (r.storno = 0 OR r.storno IS NULL)
        AND (r.guide = 0 OR r.guide IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_pending);
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $pending = $result->fetch_assoc();
    
    $stats['pending_checkins'] = (int)$pending['pending_checkins'];

    // 5. Zusätzliche Statistiken
    $stats['date'] = $date;
    $stats['tomorrow'] = $tomorrow;
    
    echo json_encode($stats);

} catch (Exception $e) {
    error_log('Dashboard stats error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Fehler beim Laden der Statistiken',
        'details' => $e->getMessage()
    ]);
}
?>
