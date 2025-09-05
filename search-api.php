<?php
// search-api.php - API für die umfassende Reservierungen & Namen Suche

header('Content-Type: application/json');

// Anti-Cache Headers
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

require 'config.php';

// MySQL Error Mode lockern
$mysqli->query("SET sql_mode = 'ALLOW_INVALID_DATES'");

try {
    $startTime = microtime(true);
    
    // Parameter auslesen
    $searchTerm = $_GET['searchTerm'] ?? '';
    $dateFrom = $_GET['dateFrom'] ?? '';
    $dateTo = $_GET['dateTo'] ?? '';
    $reservationType = $_GET['reservationType'] ?? '';
    $searchIn = $_GET['searchIn'] ?? 'all';
    $sortBy = $_GET['sortBy'] ?? 'anreise';
    
    // Mindestens ein Suchkriterium erforderlich
    if (empty($searchTerm) && empty($dateFrom) && empty($dateTo)) {
        echo json_encode([
            'success' => false,
            'error' => 'Mindestens ein Suchkriterium erforderlich'
        ]);
        exit;
    }
    
    // Base Query für Reservierungen
    $reservationQuery = "
        SELECT DISTINCT
            r.id,
            DATE_FORMAT(r.anreise, '%d.%m.%Y') AS anreise,
            DATE_FORMAT(r.abreise, '%d.%m.%Y') AS abreise,
            r.anreise AS anreise_raw,
            r.abreise AS abreise_raw,
            r.nachname,
            r.vorname,
            r.gruppenname,
            r.av_id,
            r.email,
            (SELECT COUNT(*) FROM \`AV-ResNamen\` arn WHERE arn.res_id = r.id) AS namen_count
        FROM Reservierungen r
        WHERE 1=1
    ";
    
    $params = [];
    $types = '';
    
    // Suchterm-Filter (Namen, Vornamen, E-Mail)
    if (!empty($searchTerm)) {
        if ($searchIn === 'reservations' || $searchIn === 'all') {
            $reservationQuery .= " AND (
                r.nachname LIKE ? OR 
                r.vorname LIKE ? OR 
                r.email LIKE ? OR
                r.gruppenname LIKE ?
            )";
            $searchPattern = '%' . $searchTerm . '%';
            $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
            $types .= 'ssss';
        }
        
        // Wenn auch in Namen gesucht werden soll
        if ($searchIn === 'names' || $searchIn === 'all') {
            $reservationQuery .= " OR r.id IN (
                SELECT DISTINCT arn.res_id 
                FROM \`AV-ResNamen\` arn 
                WHERE arn.nachname LIKE ? OR arn.vorname LIKE ?
            )";
            $params = array_merge($params, [$searchPattern, $searchPattern]);
            $types .= 'ss';
        }
    }
    
    // Datums-Filter (Anwesenheit)
    if (!empty($dateFrom)) {
        $reservationQuery .= " AND r.abreise >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    
    if (!empty($dateTo)) {
        $reservationQuery .= " AND r.anreise <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }
    
    // Reservierungstyp-Filter
    if ($reservationType === 'av') {
        $reservationQuery .= " AND r.av_id > 0";
    } elseif ($reservationType === 'regular') {
        $reservationQuery .= " AND r.av_id = 0";
    }
    
    // Sortierung
    switch ($sortBy) {
        case 'abreise':
            $reservationQuery .= " ORDER BY r.abreise DESC, r.nachname";
            break;
        case 'nachname':
            $reservationQuery .= " ORDER BY r.nachname, r.vorname, r.anreise";
            break;
        case 'relevance':
            // Bei Textsuche nach Relevanz, sonst nach Anreise
            if (!empty($searchTerm)) {
                $reservationQuery .= " ORDER BY 
                    CASE 
                        WHEN r.nachname LIKE ? THEN 1
                        WHEN r.vorname LIKE ? THEN 2
                        WHEN r.email LIKE ? THEN 3
                        ELSE 4
                    END, r.anreise";
                $searchExact = $searchTerm . '%';
                $params = array_merge($params, [$searchExact, $searchExact, $searchExact]);
                $types .= 'sss';
            } else {
                $reservationQuery .= " ORDER BY r.anreise";
            }
            break;
        default: // anreise
            $reservationQuery .= " ORDER BY r.anreise, r.nachname";
    }
    
    // Query ausführen
    $stmt = $mysqli->prepare($reservationQuery);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $reservations = [];
    while ($row = $result->fetch_assoc()) {
        $reservations[] = $row;
    }
    
    // Namen für jede Reservierung laden
    foreach ($reservations as &$reservation) {
        $nameQuery = "
            SELECT 
                av_id,
                nachname,
                vorname,
                DATE_FORMAT(gebdat, '%d.%m.%Y') AS geburtsdatum,
                checkin_zeit,
                checkout_zeit,
                bemerkung
            FROM \`AV-ResNamen\`
            WHERE res_id = ?
        ";
        
        // Bei Namen-Suche zusätzlich filtern
        if (!empty($searchTerm) && ($searchIn === 'names' || $searchIn === 'all')) {
            $nameQuery .= " AND (nachname LIKE ? OR vorname LIKE ?)";
        }
        
        $nameQuery .= " ORDER BY nachname, vorname";
        
        $nameStmt = $mysqli->prepare($nameQuery);
        if ($nameStmt) {
            if (!empty($searchTerm) && ($searchIn === 'names' || $searchIn === 'all')) {
                $nameStmt->bind_param('iss', $reservation['id'], $searchPattern, $searchPattern);
            } else {
                $nameStmt->bind_param('i', $reservation['id']);
            }
            
            $nameStmt->execute();
            $nameResult = $nameStmt->get_result();
            
            $namen = [];
            while ($nameRow = $nameResult->fetch_assoc()) {
                $namen[] = $nameRow;
            }
            
            $reservation['namen'] = $namen;
            $nameStmt->close();
        }
    }
    
    // Statistiken berechnen
    $totalNames = array_sum(array_column($reservations, 'namen_count'));
    $searchTime = round((microtime(true) - $startTime) * 1000);
    
    // Ergebnis zurückgeben
    echo json_encode([
        'success' => true,
        'results' => $reservations,
        'stats' => [
            'total_reservations' => count($reservations),
            'total_names' => $totalNames,
            'search_time' => $searchTime,
            'search_term' => $searchTerm,
            'date_range' => ($dateFrom || $dateTo) ? 
                (($dateFrom ?: 'Anfang') . ' - ' . ($dateTo ?: 'Ende')) : null
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Search API Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Datenbankfehler: ' . $e->getMessage()
    ]);
}

$mysqli->close();
?>
