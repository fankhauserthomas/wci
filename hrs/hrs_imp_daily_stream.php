<?php
/**
 * HRS Daily Summary Import - Server-Sent Events (SSE) Version
 * Sendet Echtzeit-Updates während des Imports
 * 
 * Usage: hrs_imp_daily_stream.php?from=2024-01-01&to=2024-01-07
 */

// SSE Headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx: Disable buffering

// Disable output buffering for immediate flush
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

/**
 * Send SSE message
 */
function sendSSE($type, $data = []) {
    $message = array_merge(['type' => $type], $data);
    echo "data: " . json_encode($message) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// Parameter validieren
if (!isset($_GET['from']) || !isset($_GET['to'])) {
    sendSSE('error', ['message' => 'Missing parameters: from and to are required']);
    exit;
}

$dateFrom = $_GET['from'];
$dateTo = $_GET['to'];

// Konvertiere YYYY-MM-DD Format zu DD.MM.YYYY für interne Verarbeitung
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = DateTime::createFromFormat('Y-m-d', $dateFrom)->format('d.m.Y');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = DateTime::createFromFormat('Y-m-d', $dateTo)->format('d.m.Y');
}

// Datenbankverbindung und HRS Login
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/hrs_login.php');

sendSSE('start', ['message' => 'Initialisiere HRS Import...', 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]);

/**
 * HRS Daily Summary Importer Class (SSE Version)
 */
class HRSDailySummaryImporterSSE {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675;
    
    private $categoryMapping = [
        'ML' => 'ML',
        'MBZ' => 'MBZ',
        '2BZ' => '2BZ',
        'SK' => 'SK'
    ];
    
    private $importDateFrom;
    private $importDateTo;
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
        sendSSE('log', ['level' => 'info', 'message' => 'HRS Daily Summary Importer initialized']);
    }
    
    public function importDailySummaries($dateFrom, $dateTo) {
        sendSSE('phase', ['step' => 'daily', 'name' => 'Daily Summary', 'message' => 'Starte Import...']);
        
        $this->importDateFrom = $dateFrom;
        $this->importDateTo = $dateTo;
        
        // Schritt 1: Bestehende löschen (optional - wir verwenden jetzt ON DUPLICATE KEY UPDATE)
        // sendSSE('log', ['level' => 'info', 'message' => 'Lösche bestehende Daily Summaries für Zeitraum...']);
        // $this->deleteExistingDailySummaries($dateFrom, $dateTo);
        
        // Schritt 2: Datum-Range in 10-Tage-Blöcke aufteilen
        $dateRanges = $this->splitDateRange($dateFrom, $dateTo);
        sendSSE('log', ['level' => 'info', 'message' => 'Zeitraum in ' . count($dateRanges) . ' Blöcke aufgeteilt']);
        
        $totalProcessed = 0;
        $totalInserted = 0;
        $allDays = [];
        
        // Alle Tage sammeln für Fortschrittsanzeige
        $start = DateTime::createFromFormat('d.m.Y', $dateFrom);
        $end = DateTime::createFromFormat('d.m.Y', $dateTo);
        while ($start <= $end) {
            $allDays[] = $start->format('d.m.Y');
            $start->add(new DateInterval('P1D'));
        }
        
        $totalDays = count($allDays);
        sendSSE('total', ['count' => $totalDays]);
        
        // Schritt 3: Jeden Datumsbereich abfragen
        foreach ($dateRanges as $rangeIndex => $range) {
            sendSSE('log', ['level' => 'info', 'message' => "Block " . ($rangeIndex + 1) . "/" . count($dateRanges) . ": {$range['from']} bis {$range['to']}"]);
            
            $dailyData = $this->fetchDailySummaryData($range['from'], $range['to']);
            
            if ($dailyData) {
                foreach ($dailyData as $daily) {
                    $dayStr = $daily['day'];
                    
                    // Prüfen ob im Bereich
                    if (!$this->isDateInRange($dayStr)) {
                        sendSSE('log', ['level' => 'warn', 'message' => "Tag $dayStr übersprungen (außerhalb Bereich)"]);
                        continue;
                    }
                    
                    $totalProcessed++;
                    
                    // Progress für diesen Tag
                    $currentIndex = array_search($dayStr, $allDays);
                    if ($currentIndex !== false) {
                        $percent = round((($currentIndex + 1) / $totalDays) * 100);
                        sendSSE('progress', [
                            'current' => $currentIndex + 1,
                            'total' => $totalDays,
                            'percent' => $percent,
                            'day' => $dayStr
                        ]);
                    }
                    
                    if ($this->processDailySummary($daily)) {
                        $totalInserted++;
                        sendSSE('log', ['level' => 'success', 'message' => "✓ Tag $dayStr erfolgreich importiert"]);
                    } else {
                        sendSSE('log', ['level' => 'error', 'message' => "✗ Fehler beim Import von Tag $dayStr"]);
                    }
                    
                    // Kurze Pause für bessere UI-Darstellung (optional)
                    usleep(50000); // 50ms
                }
            } else {
                sendSSE('log', ['level' => 'error', 'message' => "Keine Daten für Block {$range['from']} bis {$range['to']}"]);
            }
        }
        
        sendSSE('complete', [
            'step' => 'daily',
            'message' => "Import abgeschlossen: $totalInserted von $totalProcessed Tagen importiert",
            'totalProcessed' => $totalProcessed,
            'totalInserted' => $totalInserted
        ]);
        
        return true;
    }
    
    private function splitDateRange($dateFrom, $dateTo) {
        $startDate = DateTime::createFromFormat('d.m.Y', $dateFrom);
        $endDate = DateTime::createFromFormat('d.m.Y', $dateTo);
        
        $ranges = [];
        $currentStart = clone $startDate;
        
        while ($currentStart <= $endDate) {
            $currentEnd = clone $currentStart;
            $currentEnd->add(new DateInterval('P9D'));
            
            if ($currentEnd > $endDate) {
                $currentEnd = clone $endDate;
            }
            
            $ranges[] = [
                'from' => $currentStart->format('d.m.Y'),
                'to' => $currentEnd->format('d.m.Y')
            ];
            
            $currentStart->add(new DateInterval('P10D'));
        }
        
        return $ranges;
    }
    
    private function deleteExistingDailySummaries($dateFrom, $dateTo) {
        $mysqlDateFrom = $this->convertDateToMySQL($dateFrom);
        $mysqlDateTo = $this->convertDateToMySQL($dateTo);
        
        $deleteQuery = "DELETE FROM daily_summary WHERE hut_id = ? AND day >= ? AND day <= ?";
        $stmt = $this->mysqli->prepare($deleteQuery);
        
        if (!$stmt) {
            sendSSE('log', ['level' => 'error', 'message' => 'Fehler beim Vorbereiten der Lösch-Abfrage']);
            return false;
        }
        
        $stmt->bind_param('iss', $this->hutId, $mysqlDateFrom, $mysqlDateTo);
        
        if ($stmt->execute()) {
            $deletedRows = $stmt->affected_rows;
            sendSSE('log', ['level' => 'success', 'message' => "$deletedRows bestehende Datensätze gelöscht"]);
        } else {
            sendSSE('log', ['level' => 'error', 'message' => 'Fehler beim Löschen: ' . $stmt->error]);
        }
        
        $stmt->close();
    }
    
    private function fetchDailySummaryData($dateFrom, $dateTo) {
        $url = "/api/v1/manage/reservation/dailySummary?hutId={$this->hutId}&dateFrom={$dateFrom}";
        
        $headers = array(
            'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()
        );
        
        $response = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            sendSSE('log', ['level' => 'error', 'message' => 'API-Fehler: HTTP ' . ($response['status'] ?? 'unknown')]);
            return false;
        }
        
        $data = json_decode($response['body'], true);
        
        if (!is_array($data)) {
            sendSSE('log', ['level' => 'error', 'message' => 'Keine Daten in API-Response']);
            return false;
        }
        
        sendSSE('log', ['level' => 'info', 'message' => count($data) . ' Tage von API erhalten']);
        return $data;
    }
    
    private function processDailySummary($daily) {
        try {
            $day = $this->convertDateToMySQL($daily['day']);
            
            if (!$this->isDateInRange($daily['day'])) {
                return true;
            }
            
            $dayOfWeek = $daily['dayOfWeek'] ?? null;
            $hutMode = $daily['hutMode'] ?? null;
            $numberOfArrivingGuests = $daily['numberOfArrivingGuests'] ?? 0;
            $totalGuests = $daily['totalGuests'] ?? 0;
            
            $halfBoardsValue = $daily['halfBoards']['value'] ?? 0;
            $halfBoardsIsActive = ($daily['halfBoards']['isActive'] ?? false) ? 1 : 0;
            $vegetariansValue = $daily['vegetarians']['value'] ?? 0;
            $vegetariansIsActive = ($daily['vegetarians']['isActive'] ?? false) ? 1 : 0;
            $childrenValue = $daily['children']['value'] ?? 0;
            $childrenIsActive = ($daily['children']['isActive'] ?? false) ? 1 : 0;
            $mountainGuidesValue = $daily['mountainGuides']['value'] ?? 0;
            $mountainGuidesIsActive = ($daily['mountainGuides']['isActive'] ?? false) ? 1 : 0;
            $waitingListValue = $daily['waitingList']['value'] ?? 0;
            $waitingListIsActive = ($daily['waitingList']['isActive'] ?? false) ? 1 : 0;
        
        $insertQuery = "INSERT INTO daily_summary (
            hut_id, day, day_of_week, hut_mode, number_of_arriving_guests, total_guests,
            half_boards_value, half_boards_is_active, vegetarians_value, vegetarians_is_active,
            children_value, children_is_active, mountain_guides_value, mountain_guides_is_active,
            waiting_list_value, waiting_list_is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            day_of_week = VALUES(day_of_week),
            hut_mode = VALUES(hut_mode),
            number_of_arriving_guests = VALUES(number_of_arriving_guests),
            total_guests = VALUES(total_guests),
            half_boards_value = VALUES(half_boards_value),
            half_boards_is_active = VALUES(half_boards_is_active),
            vegetarians_value = VALUES(vegetarians_value),
            vegetarians_is_active = VALUES(vegetarians_is_active),
            children_value = VALUES(children_value),
            children_is_active = VALUES(children_is_active),
            mountain_guides_value = VALUES(mountain_guides_value),
            mountain_guides_is_active = VALUES(mountain_guides_is_active),
            waiting_list_value = VALUES(waiting_list_value),
            waiting_list_is_active = VALUES(waiting_list_is_active)";
        
        $stmt = $this->mysqli->prepare($insertQuery);
        if (!$stmt) {
            sendSSE('log', ['level' => 'error', 'message' => 'SQL Prepare Error: ' . $this->mysqli->error]);
            return false;
        }
        
        $stmt->bind_param('isssiiiiiiiiiiii', 
            $this->hutId, $day, $dayOfWeek, $hutMode, $numberOfArrivingGuests, $totalGuests,
            $halfBoardsValue, $halfBoardsIsActive, $vegetariansValue, $vegetariansIsActive,
            $childrenValue, $childrenIsActive, $mountainGuidesValue, $mountainGuidesIsActive,
            $waitingListValue, $waitingListIsActive
        );
        
        if (!$stmt->execute()) {
            sendSSE('log', ['level' => 'error', 'message' => 'SQL Execute Error: ' . $stmt->error]);
            $stmt->close();
            return false;
        }
        
        // Get daily_summary_id (works for both INSERT and UPDATE)
        $dailySummaryId = $this->mysqli->insert_id;
        
        // If insert_id is 0, it was an UPDATE - need to SELECT the id
        if ($dailySummaryId == 0) {
            $selectQuery = "SELECT id FROM daily_summary WHERE hut_id = ? AND day = ?";
            $selectStmt = $this->mysqli->prepare($selectQuery);
            $selectStmt->bind_param('is', $this->hutId, $day);
            $selectStmt->execute();
            $selectResult = $selectStmt->get_result();
            if ($row = $selectResult->fetch_assoc()) {
                $dailySummaryId = $row['id'];
            }
            $selectStmt->close();
        }
        
        $stmt->close();
        
        // Kategorien löschen (falls UPDATE) und neu importieren
        if ($dailySummaryId > 0) {
            // Alte Kategorien löschen
            $deleteCategories = "DELETE FROM daily_summary_categories WHERE daily_summary_id = ?";
            $deleteStmt = $this->mysqli->prepare($deleteCategories);
            $deleteStmt->bind_param('i', $dailySummaryId);
            $deleteStmt->execute();
            $deleteStmt->close();
            
            // Neue Kategorien importieren
            if (isset($daily['freePlacesPerCategories'])) {
                $categoryIndex = 0;
                foreach ($daily['freePlacesPerCategories'] as $category) {
                    $this->insertDailySummaryCategory($dailySummaryId, $category, $categoryIndex);
                    $categoryIndex++;
                }
            }
        }
        
        return true;
        
        } catch (Exception $e) {
            sendSSE('log', ['level' => 'error', 'message' => 'Fehler bei Tag ' . ($daily['day'] ?? 'unknown') . ': ' . $e->getMessage()]);
            return false;
        }
    }
    
    private function insertDailySummaryCategory($dailySummaryId, $category, $categoryIndex) {
        $isWinteraum = ($category['isWinteraum'] ?? false) ? 1 : 0;
        $freePlaces = $category['freePlaces'] ?? 0;
        $assignedGuests = $category['assignedGuests'] ?? 0;
        $occupancyLevel = $category['occupancyLevel'] ?? 0.0;
        
        $categoryType = $this->determineCategoryType($category['categoryData'] ?? [], $categoryIndex);
        
        $insertCategoryQuery = "INSERT INTO daily_summary_categories (
            daily_summary_id, category_type, is_winteraum, free_places, assigned_guests, occupancy_level
        ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->mysqli->prepare($insertCategoryQuery);
        if (!$stmt) {
            return false;
        }
        
        $stmt->bind_param('isiiii', $dailySummaryId, $categoryType, $isWinteraum, $freePlaces, $assignedGuests, $occupancyLevel);
        $stmt->execute();
        $stmt->close();
        
        return true;
    }
    
    private function determineCategoryType($categoryData, $fallbackIndex) {
        if (is_array($categoryData) && isset($categoryData['DE_DE']['shortLabel'])) {
            $shortLabel = $categoryData['DE_DE']['shortLabel'];
            return $this->categoryMapping[$shortLabel] ?? 'ML';
        }
        
        $fallbackMapping = ['ML', 'MBZ', '2BZ', 'SK'];
        return $fallbackMapping[$fallbackIndex] ?? 'ML';
    }
    
    private function isDateInRange($dateString) {
        $date = DateTime::createFromFormat('d.m.Y', $dateString);
        $fromDate = DateTime::createFromFormat('d.m.Y', $this->importDateFrom);
        $toDate = DateTime::createFromFormat('d.m.Y', $this->importDateTo);
        
        return ($date >= $fromDate && $date <= $toDate);
    }
    
    private function convertDateToMySQL($date) {
        if (!$date) return null;
        
        $parts = explode('.', $date);
        if (count($parts) === 3) {
            return $parts[2] . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        }
        
        return $date;
    }
}

// === MAIN EXECUTION ===

try {
    // HRS Login
    $hrsLogin = new HRSLogin();
    sendSSE('log', ['level' => 'info', 'message' => 'Verbinde mit HRS...']);
    
    if (!$hrsLogin->login()) {
        sendSSE('error', ['message' => 'HRS Login fehlgeschlagen']);
        exit;
    }
    
    sendSSE('log', ['level' => 'success', 'message' => 'HRS Login erfolgreich']);
    
    // Import durchführen
    $importer = new HRSDailySummaryImporterSSE($mysqli, $hrsLogin);
    
    if ($importer->importDailySummaries($dateFrom, $dateTo)) {
        sendSSE('finish', ['message' => 'Import vollständig abgeschlossen!']);
    } else {
        sendSSE('error', ['message' => 'Import mit Fehlern beendet']);
    }
    
} catch (Exception $e) {
    sendSSE('error', ['message' => 'Ausnahme: ' . $e->getMessage()]);
}
?>
