<?php
/**
 * HRS Kapazitäts-Quota Import - CLI & JSON API
 * Importiert Hütten-Kapazitätsänderungen von HRS in lokale Datenbank
 *
 * Importiert in Tabellen:
 * - hut_quota (Hauptdaten)
 * - hut_quota_categories (Betten-Kategorien)
 * - hut_quota_languages (Sprach-Beschreibungen)
 *
 * Usage CLI: php hrs_imp_quota.php 20.08.2025 31.08.2025
 * Usage Web: hrs_imp_quota.php?from=20.08.2025&to=31.08.2025
 */

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/hrs_login.php');

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
    private $silent = false;
    private $logs = [];
    private $categoryMap = [
        'lager'  => 1958,
        'betten' => 2293,
        'dz'     => 2381,
        'sonder' => 6106
    ];
    
    public function __construct($mysqli, HRSLogin $hrsLogin, array $options = []) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
        if (isset($options['silent'])) {
            $this->silent = (bool)$options['silent'];
        }
        $this->debug("HRS Quota Importer initialized");
    }

    public function getLogs() {
        return $this->logs;
    }

    private function appendLog($line) {
        $this->logs[] = $line;
        if (!$this->silent) {
            echo $line . "\n";
        }
    }

    private function timestamp() {
        return date('H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
    }
    
    /**
     * Debug-Ausgabe mit Timestamp
     */
    public function debug($message) {
        $this->appendLog(sprintf('[%s] %s', $this->timestamp(), $message));
    }
    
    /**
     * Erfolgs-Debug mit grünem Marker
     */
    public function debugSuccess($message) {
        $this->appendLog(sprintf('[%s] ✅ SUCCESS: %s', $this->timestamp(), $message));
    }
    
    /**
     * Error-Debug mit rotem Marker
     */
    public function debugError($message) {
        $this->appendLog(sprintf('[%s] ❌ ERROR: %s', $this->timestamp(), $message));
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
        
        if ($quotaData === false) {
            $this->debugError("No quota data received from HRS");
            return false;
        }

        if (empty($quotaData)) {
            $this->debug("No quotas returned by HRS for this range (local cache cleared)");
            return true;
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

        $rangeStart = $this->parseInputDate($dateFrom);
        $rangeEnd = $this->parseInputDate($dateTo);
        if (!$rangeStart || !$rangeEnd) {
            $this->debugError("Invalid date range supplied (from=$dateFrom, to=$dateTo)");
            return false;
        }

        $rangeEndExclusive = (clone $rangeEnd)->modify('+1 day');
        $apiStart = (clone $rangeStart)->modify('-1 day');
        $apiEnd = (clone $rangeEnd)->modify('+1 day');

        $headers = [
            'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()
        ];

        $pageSize = 200;
        $maxPages = 25;
        $totalFetched = 0;
        $uniqueQuotas = [];
        $openStates = ['true', 'false'];

        foreach ($openStates as $openState) {
            $page = 0;
            $stateLabel = $openState === 'true' ? 'open' : 'closed';
            $this->debug("➡️  Fetching {$stateLabel} quotas (open={$openState})");

            do {
                $url = sprintf(
                    '/api/v1/manage/hutQuota?hutId=%d&page=%d&size=%d&sortList=BeginDate&sortOrder=ASC&dateFrom=%s&dateTo=%s&open=%s',
                    $this->hutId,
                    $page,
                    $pageSize,
                    $this->formatDateForApi($apiStart),
                    $this->formatDateForApi($apiEnd),
                    $openState
                );

                $this->debug("→ Fetch page $page ($url)");

                $response = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
                if (!$response || $response['status'] != 200 || !isset($response['body'])) {
                    $this->debugError("Failed to fetch quota data: HTTP " . ($response['status'] ?? 'unknown') . " on page $page (open={$openState})");
                    break;
                }

                $decoded = json_decode($response['body'], true);
                if ($decoded === null) {
                    $this->debugError("JSON decode error on page $page (open={$openState})");
                    break;
                }

                $list = null;
                if (isset($decoded['_embedded']['bedCapacityChangeResponseDTOList']) && is_array($decoded['_embedded']['bedCapacityChangeResponseDTOList'])) {
                    $list = $decoded['_embedded']['bedCapacityChangeResponseDTOList'];
                } elseif (isset($decoded['content']) && is_array($decoded['content'])) {
                    $list = $decoded['content'];
                } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
                    $list = $decoded['items'];
                } elseif (is_array($decoded)) {
                    $list = $decoded;
                }

                if (!$list) {
                    $this->debug("No quota data on page $page (open={$openState})");
                    break;
                }

                $totalFetched += count($list);

                foreach ($list as $quota) {
                    $quotaId = $quota['id'] ?? ($quota['quotaId'] ?? ($quota['quota']['id'] ?? null));
                    if (!$quotaId) {
                        $this->debug("⚠️  Skip quota without ID: " . json_encode($quota));
                        continue;
                    }

                    $beginRaw = $quota['dateFrom'] ?? ($quota['beginDate'] ?? ($quota['date_from'] ?? null));
                    $endRaw = $quota['dateTo'] ?? ($quota['endDate'] ?? ($quota['date_to'] ?? null));

                    $quotaStart = $this->parseQuotaDateFlexible($beginRaw);
                    $quotaEndRaw = $this->parseQuotaDateFlexible($endRaw);

                    if (!$quotaStart || !$quotaEndRaw) {
                        $this->debug("⚠️  Skip quota $quotaId due to invalid dates (from=$beginRaw, to=$endRaw)");
                        continue;
                    }

                    [$normalizedStart, $normalizedEnd] = $this->normalizeQuotaRange($quotaStart, $quotaEndRaw);

                    if ($normalizedEnd <= $rangeStart || $normalizedStart >= $rangeEndExclusive) {
                        continue; // outside requested window
                    }

                    $uniqueQuotas[$quotaId] = $quota;
                }

                $page++;
                $hasMore = false;

                if (isset($decoded['page']['totalPages'])) {
                    $totalPages = (int)$decoded['page']['totalPages'];
                    $hasMore = $page < $totalPages;
                } elseif (isset($decoded['totalPages'])) {
                    $totalPages = (int)$decoded['totalPages'];
                    $hasMore = $page < $totalPages;
                } else {
                    $hasMore = count($list) === $pageSize;
                }
            } while ($page < $maxPages && $hasMore);
        }

        $this->debug("Fetched $totalFetched raw quota entries across open/closed states");
        $this->debug("Filtered to " . count($uniqueQuotas) . " quota(s) within selection");

        return array_values($uniqueQuotas);
    }
    
    /**
     * Einzelne Quota verarbeiten und in Datenbank speichern
     */
    private function processQuota($quota) {
        $hrsId = $quota['id'] ?? ($quota['quotaId'] ?? ($quota['quota']['id'] ?? null));
        if (!$hrsId) {
            $this->debugError("Skipping quota ohne gültige ID");
            return false;
        }

        $this->debug("Processing quota hrs_id: $hrsId");

        $dateFromRaw = $quota['dateFrom'] ?? ($quota['beginDate'] ?? ($quota['date_from'] ?? null));
        $dateToRaw = $quota['dateTo'] ?? ($quota['endDate'] ?? ($quota['date_to'] ?? null));

        $startDt = $this->parseQuotaDateFlexible($dateFromRaw);
        $endDtRaw = $this->parseQuotaDateFlexible($dateToRaw);

        if (!$startDt || !$endDtRaw) {
            $this->debugError("Skipping quota $hrsId wegen ungültiger Datumswerte (from=$dateFromRaw, to=$dateToRaw)");
            return false;
        }

        [$normalizedStart, $normalizedEnd] = $this->normalizeQuotaRange($startDt, $endDtRaw);
        $dateFrom = $normalizedStart->format('Y-m-d');
        $dateTo = $normalizedEnd->format('Y-m-d');

        $title = $quota['title'] ?? ($quota['name'] ?? ($quota['quota']['title'] ?? "Quota {$hrsId}"));
        $mode = $quota['mode'] ?? ($quota['reservationMode'] ?? ($quota['status'] ?? 'SERVICED'));
        $capacity = isset($quota['capacity']) ? (int)$quota['capacity'] :
            (isset($quota['quota']['capacity']) ? (int)$quota['quota']['capacity'] : 0);
        $weeksRecurrence = isset($quota['weeksRecurrence']) ? (int)$quota['weeksRecurrence'] : 0;
        $occurrencesNumber = isset($quota['occurrencesNumber']) ? (int)$quota['occurrencesNumber'] : 0;
        $isRecurring = (!empty($quota['isRecurring']) || (!empty($quota['quota']['isRecurring']))) ? 1 : 0;

        $weekdayField = function ($field) use ($quota) {
            if (isset($quota[$field])) {
                return $quota[$field] ? 1 : 0;
            }
            if (isset($quota['quota'][$field])) {
                return $quota['quota'][$field] ? 1 : 0;
            }
            return 0;
        };

        $monday = $weekdayField('monday');
        $tuesday = $weekdayField('tuesday');
        $wednesday = $weekdayField('wednesday');
        $thursday = $weekdayField('thursday');
        $friday = $weekdayField('friday');
        $saturday = $weekdayField('saturday');
        $sunday = $weekdayField('sunday');

        $seriesBeginDateRaw = $quota['seriesBeginDate'] ?? ($quota['quota']['seriesBeginDate'] ?? null);
        $seriesEndDateRaw = $quota['seriesEndDate'] ?? ($quota['quota']['seriesEndDate'] ?? null);
        $seriesBeginDate = $seriesBeginDateRaw ? $this->convertDateToMySQL($seriesBeginDateRaw) : null;
        $seriesEndDate = $seriesEndDateRaw ? $this->convertDateToMySQL($seriesEndDateRaw) : null;

        $this->debug("→ Data: $title, $dateFrom-$dateTo, Mode:$mode, Capacity:$capacity");

        $this->removeExistingQuotaByHrsId($hrsId);

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

        $stmt->bind_param(
            'iissssiiiiiiiiisssi',
            $hrsId,
            $this->hutId,
            $dateFrom,
            $dateTo,
            $title,
            $mode,
            $capacity,
            $weeksRecurrence,
            $occurrencesNumber,
            $monday,
            $tuesday,
            $wednesday,
            $thursday,
            $friday,
            $saturday,
            $sunday,
            $seriesBeginDate,
            $seriesEndDate,
            $isRecurring
        );

        if (!$stmt->execute()) {
            $this->debugError("Failed to insert quota: " . $stmt->error);
            $stmt->close();
            return false;
        }

        $quotaId = $this->mysqli->insert_id;
        $stmt->close();

        $categoryEntries = $this->extractQuotaCategoryEntries($quota);
        foreach ($categoryEntries as $category) {
            $this->insertQuotaCategory($quotaId, $category);
        }

        $languagesSources = [];
        if (isset($quota['languagesDataDTOs']) && is_array($quota['languagesDataDTOs'])) {
            $languagesSources = $quota['languagesDataDTOs'];
        } elseif (isset($quota['languages']) && is_array($quota['languages'])) {
            $languagesSources = $quota['languages'];
        } elseif (isset($quota['quota']['languagesDataDTOs']) && is_array($quota['quota']['languagesDataDTOs'])) {
            $languagesSources = $quota['quota']['languagesDataDTOs'];
        }

        foreach ($languagesSources as $language) {
            $this->insertQuotaLanguage($quotaId, $language);
        }

        $this->debugSuccess("Inserted quota $hrsId successfully (local_id: $quotaId)");
        return true;
    }
    
    /**
     * Quota-Kategorie in hut_quota_categories einfügen
     */
    private function insertQuotaCategory($quotaId, $category) {
        if (!is_array($category) || !isset($category['categoryId'])) {
            return;
        }

        $categoryId = (int)$category['categoryId'];
        $totalBeds = isset($category['totalBeds']) ? (int)$category['totalBeds'] : 0;

        if ($totalBeds <= 0) {
            return;
        }
        
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
        if (!is_array($language) || !isset($language['language'])) {
            return;
        }

        $lang = $language['language'];
        $description = $language['description'] ?? ($language['text'] ?? '');
        
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
        
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }

        $parts = explode('.', $date);
        if (count($parts) === 3) {
            return $parts[2] . '-' . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $date)) {
            return substr($date, 0, 10);
        }

        try {
            $dt = new DateTime($date);
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            // Fallback: return original string
        }
        
        return $date; // Fallback
    }

    private function parseInputDate($date) {
        if (!$date) {
            return null;
        }

        if ($date instanceof DateTime) {
            return clone $date;
        }

        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) {
            $dt = DateTime::createFromFormat('d.m.Y', $date);
            return $dt ?: null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $dt = DateTime::createFromFormat('Y-m-d', $date);
            return $dt ?: null;
        }

        return null;
    }

    private function formatDateForApi(DateTime $date) {
        return $date->format('d.m.Y');
    }

    private function parseQuotaDateFlexible($value) {
        if (!$value) {
            return null;
        }

        if ($value instanceof DateTime) {
            return clone $value;
        }

        try {
            return new DateTime($value);
        } catch (Exception $e) {
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
                $dt = DateTime::createFromFormat('d.m.Y', $value);
                if ($dt instanceof DateTime) {
                    return $dt;
                }
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $dt = DateTime::createFromFormat('Y-m-d', $value);
                if ($dt instanceof DateTime) {
                    return $dt;
                }
            }
        }

        return null;
    }

    private function normalizeQuotaRange(DateTime $start, DateTime $rawEnd) {
        $normalizedStart = clone $start;
        $normalizedEnd = clone $rawEnd;

        if ($normalizedEnd < $normalizedStart) {
            $normalizedEnd = clone $normalizedStart;
        }

        $diffDays = (int)$normalizedStart->diff($normalizedEnd)->format('%a');

        if ($normalizedEnd <= $normalizedStart) {
            $normalizedEnd = (clone $normalizedStart)->modify('+1 day');
        } elseif ($diffDays >= 2) {
            $normalizedEnd = (clone $normalizedEnd)->modify('+1 day');
        }

        return [$normalizedStart, $normalizedEnd];
    }

    private function isAssocArray(array $array) {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function extractQuotaCategoryEntries($quota) {
        $values = [];
        foreach ($this->categoryMap as $name => $id) {
            $values[$id] = 0;
        }

        $sources = [];
        if (isset($quota['hutBedCategoryDTOs']) && is_array($quota['hutBedCategoryDTOs'])) {
            $sources[] = $quota['hutBedCategoryDTOs'];
        }
        if (isset($quota['categories']) && is_array($quota['categories'])) {
            $sources[] = $quota['categories'];
        }
        if (isset($quota['quota']['hutBedCategoryDTOs']) && is_array($quota['quota']['hutBedCategoryDTOs'])) {
            $sources[] = $quota['quota']['hutBedCategoryDTOs'];
        }
        if (isset($quota['quota']['categories']) && is_array($quota['quota']['categories'])) {
            $sources[] = $quota['quota']['categories'];
        }

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            if ($this->isAssocArray($source)) {
                foreach ($source as $key => $value) {
                    if (isset($this->categoryMap[$key])) {
                        $values[$this->categoryMap[$key]] = (int)$value;
                    }
                }
            } else {
                foreach ($source as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $categoryId = null;
                    if (isset($entry['categoryId'])) {
                        $categoryId = (int)$entry['categoryId'];
                    } elseif (isset($entry['category']['id'])) {
                        $categoryId = (int)$entry['category']['id'];
                    } elseif (isset($entry['bedCategory']['id'])) {
                        $categoryId = (int)$entry['bedCategory']['id'];
                    } elseif (isset($entry['id'])) {
                        $categoryId = (int)$entry['id'];
                    }

                    if ($categoryId === null) {
                        continue;
                    }

                    $beds = null;
                    if (isset($entry['totalBeds'])) {
                        $beds = $entry['totalBeds'];
                    } elseif (isset($entry['beds'])) {
                        $beds = $entry['beds'];
                    } elseif (isset($entry['total'])) {
                        $beds = $entry['total'];
                    } elseif (isset($entry['capacity'])) {
                        $beds = $entry['capacity'];
                    }

                    if ($beds === null) {
                        continue;
                    }

                    $values[$categoryId] = (int)$beds;
                }
            }
        }

        $entries = [];
        foreach ($values as $categoryId => $beds) {
            $entries[] = [
                'categoryId' => $categoryId,
                'totalBeds' => (int)$beds
            ];
        }

        return $entries;
    }

    private function removeExistingQuotaByHrsId($hrsId) {
        $selectQuery = "SELECT id FROM hut_quota WHERE hut_id = ? AND hrs_id = ?";
        $stmt = $this->mysqli->prepare($selectQuery);
        if (!$stmt) {
            $this->debugError("Failed to prepare select for existing quota: " . $this->mysqli->error);
            return;
        }

        $stmt->bind_param('ii', $this->hutId, $hrsId);
        if (!$stmt->execute()) {
            $this->debugError("Failed to execute select for existing quota (hrs_id=$hrsId): " . $stmt->error);
            $stmt->close();
            return;
        }

        $result = $stmt->get_result();
        $quotaIds = [];
        while ($row = $result->fetch_assoc()) {
            $quotaIds[] = (int)$row['id'];
        }
        $stmt->close();

        if (empty($quotaIds)) {
            return;
        }

        foreach ($quotaIds as $quotaId) {
            $deleteCategories = $this->mysqli->prepare("DELETE FROM hut_quota_categories WHERE hut_quota_id = ?");
            if ($deleteCategories) {
                $deleteCategories->bind_param('i', $quotaId);
                if (!$deleteCategories->execute()) {
                    $this->debugError("Failed to delete categories for quota $quotaId: " . $deleteCategories->error);
                }
                $deleteCategories->close();
            }

            $deleteLanguages = $this->mysqli->prepare("DELETE FROM hut_quota_languages WHERE hut_quota_id = ?");
            if ($deleteLanguages) {
                $deleteLanguages->bind_param('i', $quotaId);
                if (!$deleteLanguages->execute()) {
                    $this->debugError("Failed to delete languages for quota $quotaId: " . $deleteLanguages->error);
                }
                $deleteLanguages->close();
            }

            $deleteQuota = $this->mysqli->prepare("DELETE FROM hut_quota WHERE id = ?");
            if ($deleteQuota) {
                $deleteQuota->bind_param('i', $quotaId);
                if ($deleteQuota->execute()) {
                    $this->debug("🧹 Removed existing quota with hrs_id=$hrsId (local_id=$quotaId)");
                } else {
                    $this->debugError("Failed to delete quota $quotaId: " . $deleteQuota->error);
                }
                $deleteQuota->close();
            }
        }
    }
}

if (!defined('HRS_QUOTA_IMPORTER_NO_MAIN')) {
    $isCli = (php_sapi_name() === 'cli');
    $isWebInterface = false;
    $dateFrom = null;
    $dateTo = null;

    if (!$isCli && isset($_GET['from']) && isset($_GET['to'])) {
        header('Content-Type: application/json; charset=utf-8');
        $dateFrom = $_GET['from'];
        $dateTo = $_GET['to'];
        $isWebInterface = true;
    } else {
        $dateFrom = isset($argv[1]) ? $argv[1] : null;
        $dateTo = isset($argv[2]) ? $argv[2] : null;
        if (!$dateFrom || !$dateTo) {
            echo "Usage: php hrs_imp_quota.php <dateFrom> <dateTo>\n";
            echo "Example: php hrs_imp_quota.php 20.08.2025 31.08.2025\n";
            exit(1);
        }
    }

    try {
        $hrsLogin = new HRSLogin();
        if (!$hrsLogin->login()) {
            if ($isWebInterface) {
                echo json_encode([
                    'success' => false,
                    'error' => 'HRS Login failed',
                    'log' => ''
                ]);
            } else {
                echo "❌ HRS Login failed!\n";
            }
            exit(1);
        }

        $importerOptions = ['silent' => $isWebInterface];
        $importer = new HRSQuotaImporter($mysqli, $hrsLogin, $importerOptions);
        $success = $importer->importQuotas($dateFrom, $dateTo);
        $logOutput = $importer->getLogs();
        $logText = implode("\n", $logOutput);

        if ($isWebInterface) {
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Quota import completed successfully' : 'Quota import failed',
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'log' => $logText
            ]);
        } else {
            if ($success) {
                echo "\n✅ Quota import completed successfully!\n";
            } else {
                echo "\n❌ Quota import failed!\n";
                exit(1);
            }
        }

    } catch (Exception $e) {
        if ($isWebInterface) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'log' => ''
            ]);
        } else {
            echo "❌ Exception: " . $e->getMessage() . "\n";
        }
        exit(1);
    }
}
