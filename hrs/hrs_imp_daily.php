<?php
/**
 * HRS Daily Summary Import - CLI & JSON API
 * Importiert tägliche Zusammenfassungen von HRS in lokale Datenbank
 * 
 * Importiert in Tabellen:
 * - daily_summary (Hauptdaten)
 * - daily_summary_categories (Kategorie-Details)
 * 
 * Usage CLI: php hrs_imp_daily.php 20.08.2025 31.08.2025
 * Usage Web: hrs_imp_daily.php?from=20.08.2025&to=31.08.2025
 */

// Parameter verarbeiten (einheitlich: from/to)
if (isset($_GET['from']) && isset($_GET['to'])) {
    // Web-Interface: JSON Header setzen
    header('Content-Type: application/json');
    $dateFrom = $_GET['from'];
    $dateTo = $_GET['to'];
    $isWebInterface = true;
    
    // Konvertiere YYYY-MM-DD Format zu DD.MM.YYYY für interne Verarbeitung
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = DateTime::createFromFormat('Y-m-d', $dateFrom)->format('d.m.Y');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = DateTime::createFromFormat('Y-m-d', $dateTo)->format('d.m.Y');
    }
} else {
    // CLI Parameter verarbeiten
    $dateFrom = isset($argv[1]) ? $argv[1] : null;
    $dateTo = isset($argv[2]) ? $argv[2] : null;
    $isWebInterface = false;
    
    if (!$dateFrom || !$dateTo) {
        echo "Usage: php hrs_imp_daily.php <dateFrom> <dateTo>\n";
        echo "Example: php hrs_imp_daily.php 20.08.2025 31.08.2025\n";
        exit(1);
    }
}

// Datenbankverbindung und HRS Login
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/hrs_login.php');

// JSON Output Capture für Web-Interface
if ($isWebInterface) {
    ob_start();
}

/**
 * HRS Daily Summary Importer Class
 * ================================
 * 
 * Importiert tägliche Zusammenfassungen aus HRS in die lokale Datenbank.
 * Da die API immer maximal 10 Tage zurückgibt, werden mehrere Requests gemacht.
 */
class HRSDailySummaryImporter {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675; // Franzsennhütte ID
    
    // Kategorie-Mapping basierend auf categoryData shortLabel
    private $categoryMapping = [
        'ML' => 'ML',     // Matratzenlager
        'MBZ' => 'MBZ',   // Mehrbettzimmer
        '2BZ' => '2BZ',   // Zweierzimmer
        'SK' => 'SK'      // Sonderkategorie
    ];
    
    // Import-Bereich speichern für Bereichsprüfung
    private $importDateFrom;
    private $importDateTo;
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
        $this->debug("HRS Daily Summary Importer initialized");
    }
    
    /**
     * Debug-Ausgabe mit Timestamp
     */
    public function debug($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        echo "[$timestamp] $message\n";
    }
    
    /**
     * Erfolgs-Debug mit grünem Marker
     */
    public function debugSuccess($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        echo "[$timestamp] ✅ SUCCESS: $message\n";
    }
    
    /**
     * Error-Debug mit rotem Marker
     */
    public function debugError($message) {
        $timestamp = date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        echo "[$timestamp] ❌ ERROR: $message\n";
    }
    
    /**
     * Haupt-Import-Methode
     */
    public function importDailySummaries($dateFrom, $dateTo) {
        $this->debug("=== Starting Daily Summary Import ===");
        $this->debug("Date range: $dateFrom to $dateTo");
        
        // Import-Bereich speichern
        $this->importDateFrom = $dateFrom;
        $this->importDateTo = $dateTo;
        
        // Schritt 1: Bestehende Daily Summaries für Zeitraum löschen
        $this->deleteExistingDailySummaries($dateFrom, $dateTo);
        
        // Schritt 2: Datum-Range in 10-Tage-Blöcke aufteilen (API-Limitation)
        $dateRanges = $this->splitDateRange($dateFrom, $dateTo);
        
        $totalProcessed = 0;
        $totalInserted = 0;
        
        // Schritt 3: Jeden Datumsbereich abfragen
        foreach ($dateRanges as $range) {
            $this->debug("Processing date range: {$range['from']} to {$range['to']}");
            
            $dailyData = $this->fetchDailySummaryData($range['from'], $range['to']);
            
            if ($dailyData) {
                foreach ($dailyData as $daily) {
                    $totalProcessed++;
                    if ($this->processDailySummary($daily)) {
                        $totalInserted++;
                    }
                }
            }
        }
        
        $this->debugSuccess("Import completed: $totalProcessed processed, $totalInserted inserted into database");
        return true;
    }
    
    /**
     * Datum-Range in 10-Tage-Blöcke aufteilen
     */
    private function splitDateRange($dateFrom, $dateTo) {
        $startDate = DateTime::createFromFormat('d.m.Y', $dateFrom);
        $endDate = DateTime::createFromFormat('d.m.Y', $dateTo);
        
        $ranges = [];
        $currentStart = clone $startDate;
        
        while ($currentStart <= $endDate) {
            $currentEnd = clone $currentStart;
            $currentEnd->add(new DateInterval('P9D')); // 9 Tage dazu = 10 Tage total
            
            if ($currentEnd > $endDate) {
                $currentEnd = clone $endDate;
            }
            
            $ranges[] = [
                'from' => $currentStart->format('d.m.Y'),
                'to' => $currentEnd->format('d.m.Y')
            ];
            
            $currentStart->add(new DateInterval('P10D')); // Nächster Block
        }
        
        $this->debug("Split date range into " . count($ranges) . " blocks of max 10 days each");
        return $ranges;
    }
    
    /**
     * Bestehende Daily Summaries für Zeitraum löschen
     */
    private function deleteExistingDailySummaries($dateFrom, $dateTo) {
        $this->debug("Deleting existing daily summaries for date range $dateFrom to $dateTo");
        
        // Datum-Format für MySQL konvertieren (DD.MM.YYYY -> YYYY-MM-DD)
        $mysqlDateFrom = $this->convertDateToMySQL($dateFrom);
        $mysqlDateTo = $this->convertDateToMySQL($dateTo);
        
        // Daily Summaries löschen die in den Zeitraum fallen
        $deleteQuery = "DELETE FROM daily_summary WHERE hut_id = ? AND day >= ? AND day <= ?";
        
        $stmt = $this->mysqli->prepare($deleteQuery);
        if (!$stmt) {
            $this->debugError("Failed to prepare delete query: " . $this->mysqli->error);
            return false;
        }
        
        $stmt->bind_param('iss', $this->hutId, $mysqlDateFrom, $mysqlDateTo);
        
        if ($stmt->execute()) {
            $deletedRows = $stmt->affected_rows;
            $this->debugSuccess("Deleted $deletedRows existing daily summary records");
        } else {
            $this->debugError("Failed to delete existing daily summaries: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    /**
     * HRS Daily Summary-Daten von API abrufen
     */
    private function fetchDailySummaryData($dateFrom, $dateTo) {
        $this->debug("Fetching daily summary data for date range $dateFrom to $dateTo");
        
        // API-URL für Daily Summary-Daten (nur dateFrom, da API max 10 Tage zurückgibt)
        $url = "/api/v1/manage/reservation/dailySummary?hutId={$this->hutId}&dateFrom={$dateFrom}";
        
        $headers = array(
            'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()
        );
        
        $response = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            $this->debugError("Failed to fetch daily summary data: HTTP " . ($response['status'] ?? 'unknown'));
            return false;
        }
        
        $data = json_decode($response['body'], true);
        
        if (!is_array($data)) {
            $this->debugError("No daily summary data found in response");
            return false;
        }
        
        $this->debug("Processing " . count($data) . " daily summaries from HRS");
        return $data;
    }
    
    /**
     * Einzelne Daily Summary verarbeiten und in Datenbank speichern
     */
    private function processDailySummary($daily) {
        $day = $this->convertDateToMySQL($daily['day']);
        $this->debug("Processing daily summary for day: $day");
        
        // Prüfen ob das Datum im gewünschten Import-Bereich liegt
        // (API gibt manchmal einen Tag mehr zurück)
        if (!$this->isDateInRange($daily['day'])) {
            $this->debug("Skipping day {$daily['day']} - outside import range");
            return true; // Als erfolgreich behandeln, aber überspringen
        }
        
        // Prüfen ob bereits importiert (Duplikat-Schutz bei überlappenden API-Blöcken)
        $checkQuery = "SELECT id FROM daily_summary WHERE hut_id = ? AND day = ?";
        $checkStmt = $this->mysqli->prepare($checkQuery);
        $checkStmt->bind_param('is', $this->hutId, $day);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $this->debug("Skipping day {$daily['day']} - already imported (duplicate protection)");
            $checkStmt->close();
            return true;
        }
        $checkStmt->close();
        
        // Hauptdaten extrahieren
        $dayOfWeek = $daily['dayOfWeek'];
        $hutMode = $daily['hutMode'];
        $numberOfArrivingGuests = $daily['numberOfArrivingGuests'];
        $totalGuests = $daily['totalGuests'];
        
        // DTO-Werte extrahieren
        $halfBoardsValue = $daily['halfBoardsDTO']['value'] ?? 0;
        $halfBoardsIsActive = ($daily['halfBoardsDTO']['isActive'] ?? false) ? 1 : 0;
        
        $vegetariansValue = $daily['vegetariansDTO']['value'] ?? 0;
        $vegetariansIsActive = ($daily['vegetariansDTO']['isActive'] ?? false) ? 1 : 0;
        
        $childrenValue = $daily['childrenDTO']['value'] ?? 0;
        $childrenIsActive = ($daily['childrenDTO']['isActive'] ?? false) ? 1 : 0;
        
        $mountainGuidesValue = $daily['mountainGuidesDTO']['value'] ?? 0;
        $mountainGuidesIsActive = ($daily['mountainGuidesDTO']['isActive'] ?? false) ? 1 : 0;
        
        $waitingListValue = $daily['waitingListDTO']['value'] ?? 0;
        $waitingListIsActive = ($daily['waitingListDTO']['isActive'] ?? false) ? 1 : 0;
        
        $this->debug("→ Data: $day ($dayOfWeek), Mode:$hutMode, Arriving:$numberOfArrivingGuests, Total:$totalGuests, HB:$halfBoardsValue");
        
        // Haupttabelle: daily_summary
        $insertDailyQuery = "INSERT INTO daily_summary (
            hut_id, day, day_of_week, hut_mode, number_of_arriving_guests, total_guests,
            half_boards_value, half_boards_is_active, vegetarians_value, vegetarians_is_active,
            children_value, children_is_active, mountain_guides_value, mountain_guides_is_active,
            waiting_list_value, waiting_list_is_active, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $this->mysqli->prepare($insertDailyQuery);
        if (!$stmt) {
            $this->debugError("Failed to prepare daily summary insert: " . $this->mysqli->error);
            return false;
        }
        
        $stmt->bind_param('isssiiiiiiiiiiii', 
            $this->hutId, $day, $dayOfWeek, $hutMode, $numberOfArrivingGuests, $totalGuests,
            $halfBoardsValue, $halfBoardsIsActive, $vegetariansValue, $vegetariansIsActive,
            $childrenValue, $childrenIsActive, $mountainGuidesValue, $mountainGuidesIsActive,
            $waitingListValue, $waitingListIsActive
        );
        
        if (!$stmt->execute()) {
            $this->debugError("Failed to insert daily summary: " . $stmt->error);
            $stmt->close();
            return false;
        }
        
        $dailySummaryId = $this->mysqli->insert_id;
        $stmt->close();
        
        // Kategorien importieren
        if (isset($daily['freePlacesPerCategories'])) {
            $categoryIndex = 0;
            foreach ($daily['freePlacesPerCategories'] as $category) {
                $this->insertDailySummaryCategory($dailySummaryId, $category, $categoryIndex);
                $categoryIndex++;
            }
        }
        
        $this->debugSuccess("Inserted daily summary for $day successfully (local_id: $dailySummaryId)");
        return true;
    }
    
    /**
     * Daily Summary Kategorie in daily_summary_categories einfügen
     */
    private function insertDailySummaryCategory($dailySummaryId, $category, $categoryIndex) {
        $isWinteraum = ($category['isWinteraum'] ?? false) ? 1 : 0;
        $freePlaces = $category['freePlaces'] ?? 0;
        $assignedGuests = $category['assignedGuests'] ?? 0;
        $occupancyLevel = $category['occupancyLevel'] ?? 0.0;
        
        // Kategorie-Typ aus categoryData ermitteln (DE_DE shortLabel)
        $categoryType = $this->determineCategoryType($category['categoryData'] ?? [], $categoryIndex);
        
        $insertCategoryQuery = "INSERT INTO daily_summary_categories (
            daily_summary_id, category_type, is_winteraum, free_places, assigned_guests, occupancy_level
        ) VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->mysqli->prepare($insertCategoryQuery);
        if (!$stmt) {
            $this->debugError("Failed to prepare category insert: " . $this->mysqli->error);
            return false;
        }
        
        // Debug: Parameter-Informationen
        $this->debug("  → Binding params: dailySummaryId=$dailySummaryId, categoryType=$categoryType, isWinteraum=$isWinteraum, freePlaces=$freePlaces, assignedGuests=$assignedGuests, occupancyLevel=$occupancyLevel");
        
        $stmt->bind_param('isiidd', $dailySummaryId, $categoryType, $isWinteraum, $freePlaces, $assignedGuests, $occupancyLevel);
        
        if ($stmt->execute()) {
            $this->debug("  → Category $categoryType: Free:$freePlaces, Assigned:$assignedGuests, Occupancy:$occupancyLevel%");
        } else {
            $this->debugError("Failed to insert category: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    /**
     * Kategorie-Typ aus categoryData ermitteln
     */
    private function determineCategoryType($categoryData, $fallbackIndex) {
        // Suche nach DE_DE Eintrag
        foreach ($categoryData as $data) {
            if ($data['language'] === 'DE_DE' && isset($data['shortLabel'])) {
                $shortLabel = $data['shortLabel'];
                if (isset($this->categoryMapping[$shortLabel])) {
                    return $this->categoryMapping[$shortLabel];
                }
            }
        }
        
        // Fallback basierend auf Index (Reihenfolge ist konsistent)
        $fallbackMapping = ['ML', 'MBZ', '2BZ', 'SK'];
        return $fallbackMapping[$fallbackIndex] ?? 'ML';
    }
    
    /**
     * Prüfen ob Datum im Import-Bereich liegt
     */
    private function isDateInRange($dateString) {
        $date = DateTime::createFromFormat('d.m.Y', $dateString);
        $fromDate = DateTime::createFromFormat('d.m.Y', $this->importDateFrom);
        $toDate = DateTime::createFromFormat('d.m.Y', $this->importDateTo);
        
        return ($date >= $fromDate && $date <= $toDate);
    }
    
    /**
     * Datum von DD.MM.YYYY zu YYYY-MM-DD konvertieren
     */
    private function convertDateToMySQL($date) {
        if (!$date) return null;
        
        $parts = explode('.', $date);
        if (count($parts) === 3) {
            return $parts[2] . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        }
        
        return $date; // Fallback
    }
}

// === MAIN EXECUTION ===

try {
    // HRS Login durchführen
    $hrsLogin = new HRSLogin();
    if (!$hrsLogin->login()) {
        if ($isWebInterface) {
            $output = ob_get_clean();
            echo json_encode([
                'success' => false,
                'error' => 'HRS Login failed',
                'log' => $output
            ]);
        } else {
            echo "❌ HRS Login failed!\n";
        }
        exit(1);
    }
    
    // Daily Summary Importer erstellen und ausführen
    $importer = new HRSDailySummaryImporter($mysqli, $hrsLogin);
    
    if ($importer->importDailySummaries($dateFrom, $dateTo)) {
        if ($isWebInterface) {
            $output = ob_get_clean();
            
            // Extract imported count from output
            $importedCount = 0;
            if (preg_match('/Import completed: (\d+) processed, (\d+) inserted/', $output, $matches)) {
                $importedCount = (int)$matches[2];
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Daily Summary import completed successfully',
                'imported' => $importedCount,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'log' => $output
            ]);
        } else {
            echo "\n✅ Daily Summary import completed successfully!\n";
        }
    } else {
        if ($isWebInterface) {
            $output = ob_get_clean();
            echo json_encode([
                'success' => false,
                'error' => 'Daily Summary import failed',
                'log' => $output
            ]);
        } else {
            echo "\n❌ Daily Summary import failed!\n";
        }
        exit(1);
    }
    
} catch (Exception $e) {
    if ($isWebInterface) {
        $output = ob_get_clean();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'log' => $output
        ]);
    } else {
        echo "❌ Exception: " . $e->getMessage() . "\n";
    }
    exit(1);
}
?>
