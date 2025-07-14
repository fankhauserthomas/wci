<?php
// getDashboardStats.php - Erweiterte Dashboard-Statistiken mit Authentifizierung

require_once __DIR__ . '/auth.php';

// Prüfe Authentifizierung
AuthManager::requireAuth();

header('Content-Type: application/json');
require 'config.php';

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

    // 3. Gäste aktuell im Haus (basierend auf Anreise/Abreise-Daten, unabhängig vom Check-in-Status)
    $sql_current_guests = "
        SELECT SUM(r.dz + r.betten + r.sonder + r.lager) as current_guests
        FROM `AV-Res` r
        WHERE DATE(r.anreise) <= ?
        AND DATE(r.abreise) > ?
        AND (r.storno = 0 OR r.storno IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_current_guests);
    $stmt->bind_param('ss', $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $current = $result->fetch_assoc();
    
    $stats['current_guests'] = (int)$current['current_guests'];

    // 4. Offene Check-ins (Anreisen heute, bei denen noch nicht alle Namen eingecheckt sind)
    $sql_pending_checkins = "
        SELECT r.id, 
               (r.sonder + r.betten + r.dz + r.lager) as expected_guests,
               IFNULL(checkin_stats.total_names, 0) as total_names,
               IFNULL(checkin_stats.checked_in_names, 0) as checked_in_names
        FROM `AV-Res` r
        LEFT JOIN (
            SELECT 
                av_id,
                COUNT(*) as total_names,
                SUM(CASE WHEN checked_in IS NOT NULL AND checked_in != '' THEN 1 ELSE 0 END) as checked_in_names
            FROM `AV-ResNamen`
            GROUP BY av_id
        ) checkin_stats ON r.id = checkin_stats.av_id
        WHERE DATE(r.anreise) = ? 
        AND (r.storno = 0 OR r.storno IS NULL)
    ";
    $stmt = $mysqli->prepare($sql_pending_checkins);
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $pending_count = 0;
    while ($row = $result->fetch_assoc()) {
        $expected = (int)$row['expected_guests'];
        $total_names = (int)$row['total_names'];
        $checked_in = (int)$row['checked_in_names'];
        
        // Offener Check-in wenn:
        // - Keine Namen eingetragen (total_names = 0)
        // - Oder nicht alle erwarteten Gäste eingecheckt (checked_in < expected)
        // - Oder Namen vorhanden aber nicht alle eingecheckt (checked_in < total_names)
        if ($total_names == 0 || $checked_in < $expected || ($total_names > 0 && $checked_in < $total_names)) {
            $pending_count++;
        }
    }
    
    $stats['pending_checkins'] = $pending_count;

} catch (Exception $e) {
    error_log("Dashboard Stats Error: " . $e->getMessage());
    $stats = [
        'arrivals_today' => ['reservations' => 0, 'guests' => 0],
        'departures_tomorrow' => ['reservations' => 0, 'guests' => 0],
        'current_guests' => 0,
        'pending_checkins' => 0,
        'error' => 'Fehler beim Laden der Statistiken'
    ];
}

echo json_encode($stats);
?>
