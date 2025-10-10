<?php
/**
 * Get Daily Summary Data for a specific date
 * 
 * Returns HRS Daily Summary data including category breakdowns
 * This data is ESSENTIAL for correct quota calculations!
 * 
 * Usage: getDailySummary.php?date=2026-02-13
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    // Parameter validieren
    $date = $_GET['date'] ?? null;
    
    if (!$date) {
        throw new InvalidArgumentException('Parameter "date" ist erforderlich (YYYY-MM-DD)');
    }
    
    // Datum validieren
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj) {
        throw new InvalidArgumentException('Ungültiges Datumsformat. Erwartet: YYYY-MM-DD');
    }
    
    // Hole Daily Summary Hauptdaten
    $sql = "SELECT 
                id,
                hut_id,
                day,
                day_of_week,
                hut_mode,
                number_of_arriving_guests,
                total_guests,
                half_boards_value,
                vegetarians_value,
                children_value,
                mountain_guides_value,
                waiting_list_value
            FROM daily_summary
            WHERE day = ?
            LIMIT 1";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('s', $date);
    if (!$stmt->execute()) {
        throw new Exception('SQL execution failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $summary = $result->fetch_assoc();
    $stmt->close();
    
    if (!$summary) {
        // Kein Daily Summary für dieses Datum
        echo json_encode([
            'success' => false,
            'error' => 'Kein Daily Summary für Datum ' . $date . ' gefunden',
            'hint' => 'Bitte zuerst HRS Daily Summary importieren'
        ]);
        exit;
    }
    
    // Hole Kategorie-Details
    $sqlCategories = "SELECT 
                        category_type,
                        is_winteraum,
                        free_places,
                        assigned_guests,
                        occupancy_level
                    FROM daily_summary_categories
                    WHERE daily_summary_id = ?
                    ORDER BY 
                        CASE category_type
                            WHEN 'ML' THEN 1
                            WHEN 'MBZ' THEN 2
                            WHEN '2BZ' THEN 3
                            WHEN 'SK' THEN 4
                        END";
    
    $stmtCat = $mysqli->prepare($sqlCategories);
    if (!$stmtCat) {
        throw new Exception('SQL prepare failed: ' . $mysqli->error);
    }
    
    $stmtCat->bind_param('i', $summary['id']);
    if (!$stmtCat->execute()) {
        throw new Exception('SQL execution failed: ' . $stmtCat->error);
    }
    
    $resultCat = $stmtCat->get_result();
    $categories = [];
    
    while ($row = $resultCat->fetch_assoc()) {
        $categories[] = [
            'category_type' => $row['category_type'],
            'is_winteraum' => (bool)$row['is_winteraum'],
            'free_places' => (int)$row['free_places'],
            'assigned_guests' => (int)$row['assigned_guests'],
            'occupancy_level' => (float)$row['occupancy_level']
        ];
    }
    
    $stmtCat->close();
    
    // Response zusammenstellen
    echo json_encode([
        'success' => true,
        'data' => [
            'date' => $summary['day'],
            'day_of_week' => $summary['day_of_week'],
            'hut_mode' => $summary['hut_mode'],
            'arriving_guests' => (int)$summary['number_of_arriving_guests'],
            'total_guests' => (int)$summary['total_guests'],
            'half_boards' => (int)$summary['half_boards_value'],
            'vegetarians' => (int)$summary['vegetarians_value'],
            'children' => (int)$summary['children_value'],
            'mountain_guides' => (int)$summary['mountain_guides_value'],
            'waiting_list' => (int)$summary['waiting_list_value'],
            'categories' => $categories
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
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
