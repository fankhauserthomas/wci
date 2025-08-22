<?php
/**
 * HRS Kapazitäts-Quota Import - Saubere CLI Version
 * Importiert Hütten-Kapazitätsänderungen von HRS in lokale Datenbank
 * 
 * Importiert in Tabellen:
 * - hut_quota (Hauptdaten)
 * - hut_quota_categories (Betten-Kategorien)
 * - hut_quota_languages (Sprach-Beschreibungen)
 * 
 * Usage: php hrs_imp_quota.php 20.08.2025 31.08.2025
 */

// CLI Parameter verarbeiten
$dateFrom = isset($argv[1]) ? $argv[1] : (isset($_GET['dateFrom']) ? $_GET['dateFrom'] : null);
$dateTo = isset($argv[2]) ? $argv[2] : (isset($_GET['dateTo']) ? $_GET['dateTo'] : null);

if (!$dateFrom || !$dateTo) {
    echo "Usage: php hrs_imp_quota.php <dateFrom> <dateTo>\n";
    echo "Example: php hrs_imp_quota.php 20.08.2025 31.08.2025\n";
    exit(1);
}

// Datenbankverbindung und HRS Login
require_once '../config.php';
require_once 'hrs_login.php';

/**
 * HRS Quota Importer Class
 * ========================
 * 
 * Importiert Kapazitätsänderungen aus HRS in die lokale Datenbank.
 * Verwaltet die 3-Tabellen-Struktur für Quota-Daten.
 */
class HRSQuotaImporter {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675; // Franzsennhütte ID
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
        $this->debug("HRS Quota Importer initialized");
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
    public function importQuotas($dateFrom, $dateTo) {
        $this->debug("=== Starting Quota Import ===");
        $this->debug("Date range: $dateFrom to $dateTo");
        
        // Schritt 1: Bestehende Quotas für Zeitraum löschen
        $this->deleteExistingQuotas($dateFrom, $dateTo);
        
        // Schritt 2: HRS Quota-Daten abrufen
        $quotaData = $this->fetchQuotaData($dateFrom, $dateTo);
        
        if (!$quotaData) {
            $this->debugError("No quota data received from HRS");
            return false;
        }
        
        // Schritt 3: Quotas verarbeiten und importieren
        $processed = 0;
        $inserted = 0;
        
        foreach ($quotaData as $quota) {
            $processed++;
            if ($this->processQuota($quota)) {
                $inserted++;
            }
        }
        
        $this->debugSuccess("Import completed: $processed processed, $inserted inserted into database");
        return true;
    }
    
    /**
     * Bestehende Quotas für Zeitraum löschen
     */
    private function deleteExistingQuotas($dateFrom, $dateTo) {
        $this->debug("Deleting existing quotas for date range $dateFrom to $dateTo");
        
        // Datum-Format für MySQL konvertieren (DD.MM.YYYY -> YYYY-MM-DD)
        $mysqlDateFrom = $this->convertDateToMySQL($dateFrom);
        $mysqlDateTo = $this->convertDateToMySQL($dateTo);
        
        // Quotas löschen die in den Zeitraum fallen
        $deleteQuery = "DELETE FROM hut_quota WHERE hut_id = ? AND (
            (date_from >= ? AND date_from <= ?) OR 
            (date_to >= ? AND date_to <= ?) OR
            (date_from <= ? AND date_to >= ?)
        )";
        
        $stmt = $this->mysqli->prepare($deleteQuery);
        if (!$stmt) {
            $this->debugError("Failed to prepare delete query: " . $this->mysqli->error);
            return false;
        }
        
        $stmt->bind_param('issssss', 
            $this->hutId, 
            $mysqlDateFrom, $mysqlDateTo,  // date_from range
            $mysqlDateFrom, $mysqlDateTo,  // date_to range  
            $mysqlDateFrom, $mysqlDateTo   // overlapping range
        );
        
        if ($stmt->execute()) {
            $deletedRows = $stmt->affected_rows;
            $this->debugSuccess("Deleted $deletedRows existing quota records");
        } else {
            $this->debugError("Failed to delete existing quotas: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    /**
     * HRS Quota-Daten von API abrufen
     */
    private function fetchQuotaData($dateFrom, $dateTo) {
        $this->debug("Fetching quota data for date range $dateFrom to $dateTo");
        
        // API-URL für Quota-Daten
        $url = "/api/v1/manage/hutQuota?hutId={$this->hutId}&page=0&size=100&sortList=BeginDate&sortOrder=DESC&open=true&dateFrom={$dateFrom}&dateTo={$dateTo}";
        
        $headers = array(
            'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()
        );
        
        $response = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            $this->debugError("Failed to fetch quota data: HTTP " . ($response['status'] ?? 'unknown'));
            return false;
        }
        
        $data = json_decode($response['body'], true);
        
        if (!isset($data['_embedded']['bedCapacityChangeResponseDTOList'])) {
            $this->debugError("No quota data found in response");
            return false;
        }
        
        $quotas = $data['_embedded']['bedCapacityChangeResponseDTOList'];
        $this->debug("Processing " . count($quotas) . " quotas from HRS");
        
        return $quotas;
    }
    
    /**
     * Einzelne Quota verarbeiten und in Datenbank speichern
     */
    private function processQuota($quota) {
        $hrsId = $quota['id'];
        $this->debug("Processing quota hrs_id: $hrsId");
        
        // Hauptdaten extrahieren
        $dateFrom = $this->convertDateToMySQL($quota['dateFrom']);
        $dateTo = $this->convertDateToMySQL($quota['dateTo']);
        $title = $quota['title'];
        $mode = $quota['mode']; // SERVICED, UNSERVICED, CLOSED
        $capacity = $quota['capacity'];
        $weeksRecurrence = $quota['weeksRecurrence'];
        $occurrencesNumber = $quota['occurrencesNumber'];
        $isRecurring = $quota['isRecurring'] ? 1 : 0;
        
        // Wochentage
        $monday = $quota['monday'] ? 1 : 0;
        $tuesday = $quota['tuesday'] ? 1 : 0;
        $wednesday = $quota['wednesday'] ? 1 : 0;
        $thursday = $quota['thursday'] ? 1 : 0;
        $friday = $quota['friday'] ? 1 : 0;
        $saturday = $quota['saturday'] ? 1 : 0;
        $sunday = $quota['sunday'] ? 1 : 0;
        
        // Serie-Daten
        $seriesBeginDate = $quota['seriesBeginDate'] ? $this->convertDateToMySQL($quota['seriesBeginDate']) : null;
        $seriesEndDate = $quota['seriesEndDate'] ? $this->convertDateToMySQL($quota['seriesEndDate']) : null;
        
        $this->debug("→ Data: $title, $dateFrom-$dateTo, Mode:$mode, Capacity:$capacity");
        
        // Haupttabelle: hut_quota
        $insertQuotaQuery = "INSERT INTO hut_quota (
            hrs_id, hut_id, date_from, date_to, title, mode, capacity, 
            weeks_recurrence, occurrences_number, monday, tuesday, wednesday, 
            thursday, friday, saturday, sunday, series_begin_date, series_end_date, 
            is_recurring, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $this->mysqli->prepare($insertQuotaQuery);
        if (!$stmt) {
            $this->debugError("Failed to prepare quota insert: " . $this->mysqli->error);
            return false;
        }
        
        $stmt->bind_param('iissssiiiiiiiiisssi', 
            $hrsId, $this->hutId, $dateFrom, $dateTo, $title, $mode, $capacity,
            $weeksRecurrence, $occurrencesNumber, $monday, $tuesday, $wednesday,
            $thursday, $friday, $saturday, $sunday, $seriesBeginDate, $seriesEndDate,
            $isRecurring
        );
        
        if (!$stmt->execute()) {
            $this->debugError("Failed to insert quota: " . $stmt->error);
            $stmt->close();
            return false;
        }
        
        $quotaId = $this->mysqli->insert_id;
        $stmt->close();
        
        // Kategorien importieren
        if (isset($quota['hutBedCategoryDTOs'])) {
            foreach ($quota['hutBedCategoryDTOs'] as $category) {
                $this->insertQuotaCategory($quotaId, $category);
            }
        }
        
        // Sprachen importieren
        if (isset($quota['languagesDataDTOs'])) {
            foreach ($quota['languagesDataDTOs'] as $language) {
                $this->insertQuotaLanguage($quotaId, $language);
            }
        }
        
        $this->debugSuccess("Inserted quota $hrsId successfully (local_id: $quotaId)");
        return true;
    }
    
    /**
     * Quota-Kategorie in hut_quota_categories einfügen
     */
    private function insertQuotaCategory($quotaId, $category) {
        $categoryId = $category['categoryId'];
        $totalBeds = $category['totalBeds'];
        
        $insertCategoryQuery = "INSERT INTO hut_quota_categories (hut_quota_id, category_id, total_beds) VALUES (?, ?, ?)";
        
        $stmt = $this->mysqli->prepare($insertCategoryQuery);
        if (!$stmt) {
            $this->debugError("Failed to prepare category insert: " . $this->mysqli->error);
            return false;
        }
        
        $stmt->bind_param('iii', $quotaId, $categoryId, $totalBeds);
        
        if ($stmt->execute()) {
            $this->debug("  → Category $categoryId: $totalBeds beds");
        } else {
            $this->debugError("Failed to insert category: " . $stmt->error);
        }
        
        $stmt->close();
    }
    
    /**
     * Quota-Sprache in hut_quota_languages einfügen
     */
    private function insertQuotaLanguage($quotaId, $language) {
        $lang = $language['language'];
        $description = $language['description'] ?? '';
        
        $insertLanguageQuery = "INSERT INTO hut_quota_languages (hut_quota_id, language, description) VALUES (?, ?, ?)";
        
        $stmt = $this->mysqli->prepare($insertLanguageQuery);
        if (!$stmt) {
            $this->debugError("Failed to prepare language insert: " . $this->mysqli->error);
            return false;
        }
        
        $stmt->bind_param('iss', $quotaId, $lang, $description);
        
        if ($stmt->execute()) {
            $this->debug("  → Language $lang: '$description'");
        } else {
            $this->debugError("Failed to insert language: " . $stmt->error);
        }
        
        $stmt->close();
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
        echo "❌ HRS Login failed!\n";
        exit(1);
    }
    
    // Quota Importer erstellen und ausführen
    $importer = new HRSQuotaImporter($mysqli, $hrsLogin);
    
    if ($importer->importQuotas($dateFrom, $dateTo)) {
        echo "\n✅ Quota import completed successfully!\n";
    } else {
        echo "\n❌ Quota import failed!\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    exit(1);
}
?>
