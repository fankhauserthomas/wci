<?php
/**
 * HRS Quota Import - Server-Sent Events (SSE) Version
 * Sendet Echtzeit-Updates während des Quota-Imports
 * 
 * Usage: hrs_imp_quota_stream.php?from=2024-01-01&to=2024-01-07
 */

// SSE Headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Nginx: Disable buffering

// Disable output buffering
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

// Konvertiere YYYY-MM-DD Format zu DD.MM.YYYY
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = DateTime::createFromFormat('Y-m-d', $dateFrom)->format('d.m.Y');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = DateTime::createFromFormat('Y-m-d', $dateTo)->format('d.m.Y');
}

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/hrs_login.php');

sendSSE('start', ['message' => 'Initialisiere Quota Import...', 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]);

/**
 * HRS Quota Importer SSE Class
 */
class HRSQuotaImporterSSE {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675;
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
        sendSSE('log', ['level' => 'info', 'message' => 'HRS Quota Importer initialized']);
    }
    
    public function importQuotas($dateFrom, $dateTo) {
        sendSSE('phase', ['step' => 'quota', 'name' => 'Quota', 'message' => 'Starte Import...']);
        
        try {
            // Schritt 1: Quota-Daten von HRS abrufen
            sendSSE('log', ['level' => 'info', 'message' => 'Rufe Quota-Daten von HRS ab...']);
            $quotas = $this->fetchQuotaData($dateFrom, $dateTo);
            
            if (!$quotas || !is_array($quotas)) {
                sendSSE('error', ['message' => 'Keine Quota-Daten von HRS erhalten']);
                return false;
            }
            
            $totalQuotas = count($quotas);
            sendSSE('total', ['count' => $totalQuotas]);
            sendSSE('log', ['level' => 'info', 'message' => "$totalQuotas Quota-Einträge erhalten"]);
            
            // Schritt 2: Bestehende Quotas im Zeitraum löschen
            $deletedCount = $this->deleteExistingQuotas($dateFrom, $dateTo);
            sendSSE('log', ['level' => 'success', 'message' => "$deletedCount bestehende Quota-Einträge gelöscht"]);
            
            // Schritt 3: Neue Quotas importieren
            $importedCount = 0;
            foreach ($quotas as $index => $quota) {
                $percent = round((($index + 1) / $totalQuotas) * 100);
                
                sendSSE('progress', [
                    'current' => $index + 1,
                    'total' => $totalQuotas,
                    'percent' => $percent,
                    'quota_id' => $quota['id'] ?? 'unknown'
                ]);
                
                if ($this->processQuota($quota)) {
                    $importedCount++;
                    sendSSE('log', ['level' => 'success', 'message' => "✓ Quota " . ($quota['id'] ?? 'unknown') . " importiert"]);
                } else {
                    sendSSE('log', ['level' => 'error', 'message' => "✗ Fehler bei Quota " . ($quota['id'] ?? 'unknown')]);
                }
                
                // Pause NUR wenn nicht letztes Item
                if ($index < $totalQuotas - 1) {
                    usleep(30000); // 30ms Pause für UI
                }
            }
            
            sendSSE('complete', [
                'step' => 'quota',
                'message' => "Import abgeschlossen: $importedCount von $totalQuotas Quotas importiert",
                'totalProcessed' => $totalQuotas,
                'totalInserted' => $importedCount
            ]);
            
            return true;
            
        } catch (Exception $e) {
            sendSSE('error', ['message' => 'Exception: ' . $e->getMessage()]);
            return false;
        }
    }
    
    private function fetchQuotaData($dateFrom, $dateTo) {
        // HRS API behandelt dateTo als exklusiv - daher +1 Tag hinzufügen
        $dateToInclusive = $this->addOneDayToDate($dateTo);
        
        // Verwende hutQuota API (nicht hut/quota/search)
        $url = "/api/v1/manage/hutQuota?hutId={$this->hutId}&page=0&size=1000&sortList=BeginDate&sortOrder=DESC&open=true&dateFrom={$dateFrom}&dateTo={$dateToInclusive}";
        
        $headers = ['X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()];
        $response = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            sendSSE('log', ['level' => 'error', 'message' => 'API-Fehler: HTTP ' . ($response['status'] ?? 'unknown')]);
            return false;
        }
        
        $data = json_decode($response['body'], true);
        
        // Response Format: { "_embedded": { "bedCapacityChangeResponseDTOList": [...] } }
        if (!isset($data['_embedded']['bedCapacityChangeResponseDTOList'])) {
            sendSSE('log', ['level' => 'error', 'message' => 'Keine Quota-Daten in Response gefunden']);
            return false;
        }
        
        return $data['_embedded']['bedCapacityChangeResponseDTOList'];
    }
    
    /**
     * Add one day to a DD.MM.YYYY date string
     */
    private function addOneDayToDate($dateStr) {
        $dt = DateTime::createFromFormat('d.m.Y', $dateStr);
        if (!$dt) {
            return $dateStr; // Fallback
        }
        $dt->modify('+1 day');
        return $dt->format('d.m.Y');
    }
    
    private function deleteExistingQuotas($dateFrom, $dateTo) {
        $mysqlDateFrom = $this->convertDateToMySQL($dateFrom);
        $mysqlDateTo = $this->convertDateToMySQL($dateTo);
        
        // Kategorien und Sprachen werden automatisch gelöscht (ON DELETE CASCADE)
        $deleteQuery = "DELETE FROM hut_quota WHERE hut_id = ? AND date_from >= ? AND date_from <= ?";
        $stmt = $this->mysqli->prepare($deleteQuery);
        $stmt->bind_param('iss', $this->hutId, $mysqlDateFrom, $mysqlDateTo);
        $stmt->execute();
        $deletedRows = $stmt->affected_rows;
        $stmt->close();
        
        return $deletedRows;
    }
    
    private function processQuota($quota) {
        try {
            $hrsId = $quota['id'];
            
            // Hauptdaten extrahieren (Format von hutQuota API)
            $dateFrom = $this->convertDateToMySQL($quota['dateFrom']);
            
            // ⚠️ WICHTIG: HRS API gibt dateTo als EXCLUSIVE zurück!
            // Beispiel: dateFrom="22.03.2026", dateTo="23.03.2026" → Nur 22.03 ist gemeint
            // Für die DB brauchen wir INCLUSIVE → dateTo - 1 Tag
            $dateToExclusive = $this->convertDateToMySQL($quota['dateTo']);
            $dateToObj = DateTime::createFromFormat('Y-m-d', $dateToExclusive);
            if ($dateToObj) {
                $dateToObj->modify('-1 day');
                $dateTo = $dateToObj->format('Y-m-d');
            } else {
                $dateTo = $dateToExclusive; // Fallback
            }
            
            $title = $quota['title'] ?? '';
            $mode = $quota['mode'] ?? 'SERVICED'; // SERVICED, UNSERVICED, CLOSED
            $capacity = $quota['capacity'] ?? 0;
            $weeksRecurrence = $quota['weeksRecurrence'] ?? 0;
            $occurrencesNumber = $quota['occurrencesNumber'] ?? 0;
            $isRecurring = isset($quota['isRecurring']) && $quota['isRecurring'] ? 1 : 0;
            
            // Wochentage
            $monday = isset($quota['monday']) && $quota['monday'] ? 1 : 0;
            $tuesday = isset($quota['tuesday']) && $quota['tuesday'] ? 1 : 0;
            $wednesday = isset($quota['wednesday']) && $quota['wednesday'] ? 1 : 0;
            $thursday = isset($quota['thursday']) && $quota['thursday'] ? 1 : 0;
            $friday = isset($quota['friday']) && $quota['friday'] ? 1 : 0;
            $saturday = isset($quota['saturday']) && $quota['saturday'] ? 1 : 0;
            $sunday = isset($quota['sunday']) && $quota['sunday'] ? 1 : 0;
            
            // Serie-Daten
            $seriesBeginDate = isset($quota['seriesBeginDate']) ? $this->convertDateToMySQL($quota['seriesBeginDate']) : null;
            $seriesEndDate = isset($quota['seriesEndDate']) ? $this->convertDateToMySQL($quota['seriesEndDate']) : null;
            
            // Insert into hut_quota (mit ON DUPLICATE KEY UPDATE)
            $insertQuotaQuery = "INSERT INTO hut_quota (
                hrs_id, hut_id, date_from, date_to, title, mode, capacity, 
                weeks_recurrence, occurrences_number, monday, tuesday, wednesday, 
                thursday, friday, saturday, sunday, series_begin_date, series_end_date, 
                is_recurring, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                date_from = VALUES(date_from),
                date_to = VALUES(date_to),
                title = VALUES(title),
                mode = VALUES(mode),
                capacity = VALUES(capacity),
                weeks_recurrence = VALUES(weeks_recurrence),
                occurrences_number = VALUES(occurrences_number),
                monday = VALUES(monday),
                tuesday = VALUES(tuesday),
                wednesday = VALUES(wednesday),
                thursday = VALUES(thursday),
                friday = VALUES(friday),
                saturday = VALUES(saturday),
                sunday = VALUES(sunday),
                series_begin_date = VALUES(series_begin_date),
                series_end_date = VALUES(series_end_date),
                is_recurring = VALUES(is_recurring),
                updated_at = NOW()";
            
            $stmt = $this->mysqli->prepare($insertQuotaQuery);
            if (!$stmt) {
                sendSSE('log', ['level' => 'error', 'message' => 'Prepare failed: ' . $this->mysqli->error]);
                return false;
            }
            
            $stmt->bind_param('iissssiiiiiiiiisssi', 
                $hrsId, $this->hutId, $dateFrom, $dateTo, $title, $mode, $capacity,
                $weeksRecurrence, $occurrencesNumber, $monday, $tuesday, $wednesday,
                $thursday, $friday, $saturday, $sunday, $seriesBeginDate, $seriesEndDate,
                $isRecurring
            );
            
            if (!$stmt->execute()) {
                sendSSE('log', ['level' => 'error', 'message' => 'Execute failed: ' . $stmt->error]);
                $stmt->close();
                return false;
            }
            
            // Get local_id (bei UPDATE ist insert_id = 0)
            $localId = $this->mysqli->insert_id;
            if ($localId == 0) {
                $selectQuery = "SELECT local_id FROM hut_quota WHERE hrs_id = ?";
                $selectStmt = $this->mysqli->prepare($selectQuery);
                $selectStmt->bind_param('i', $hrsId);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $localId = $row['local_id'];
                }
                $selectStmt->close();
            }
            $stmt->close();
            
            if ($localId > 0) {
                // Delete old categories and languages (für sauberen Update)
                $this->mysqli->query("DELETE FROM hut_quota_categories WHERE hut_quota_id = $localId");
                $this->mysqli->query("DELETE FROM hut_quota_languages WHERE hut_quota_id = $localId");
                
                // Insert categories (Format: hutBedCategoryDTOs)
                if (isset($quota['hutBedCategoryDTOs']) && is_array($quota['hutBedCategoryDTOs'])) {
                    foreach ($quota['hutBedCategoryDTOs'] as $category) {
                        $this->insertQuotaCategory($localId, $category);
                    }
                }
                
                // Insert languages (Format: languagesDataDTOs)
                if (isset($quota['languagesDataDTOs']) && is_array($quota['languagesDataDTOs'])) {
                    foreach ($quota['languagesDataDTOs'] as $language) {
                        $this->insertQuotaLanguage($localId, $language);
                    }
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            sendSSE('log', ['level' => 'error', 'message' => 'Exception: ' . $e->getMessage()]);
            return false;
        }
    }
    
    private function insertQuotaCategory($localId, $category) {
        $categoryId = $category['categoryId'] ?? null;
        $totalBeds = $category['totalBeds'] ?? 0;
        
        if (!$categoryId) return;
        
        $insertQuery = "INSERT INTO hut_quota_categories (hut_quota_id, category_id, total_beds) VALUES (?, ?, ?)";
        $stmt = $this->mysqli->prepare($insertQuery);
        if (!$stmt) return;
        
        $stmt->bind_param('iii', $localId, $categoryId, $totalBeds);
        $stmt->execute();
        $stmt->close();
    }
    
    private function insertQuotaLanguage($localId, $language) {
        $languageCode = $language['languageCode'] ?? null;
        $description = $language['description'] ?? '';
        
        if (!$languageCode) return;
        
        $insertQuery = "INSERT INTO hut_quota_languages (hut_quota_id, language_code, description) VALUES (?, ?, ?)";
        $stmt = $this->mysqli->prepare($insertQuery);
        if (!$stmt) return;
        
        $stmt->bind_param('iss', $localId, $languageCode, $description);
        $stmt->execute();
        $stmt->close();
    }
    
    private function convertDateToMySQL($date) {
        if (!$date) return null;
        
        // DD.MM.YYYY → YYYY-MM-DD
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $date, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }
        
        // YYYY-MM-DD (already correct)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        // Try to parse with DateTime
        try {
            $dt = new DateTime($date);
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            return $date;
        }
    }
}

// === MAIN EXECUTION ===

try {
    $hrsLogin = new HRSLogin();
    sendSSE('log', ['level' => 'info', 'message' => 'Verbinde mit HRS...']);
    
    if (!$hrsLogin->login()) {
        sendSSE('error', ['message' => 'HRS Login fehlgeschlagen']);
        exit;
    }
    
    sendSSE('log', ['level' => 'success', 'message' => 'HRS Login erfolgreich']);
    
    $importer = new HRSQuotaImporterSSE($mysqli, $hrsLogin);
    
    if ($importer->importQuotas($dateFrom, $dateTo)) {
        sendSSE('finish', ['message' => 'Quota Import vollständig abgeschlossen!']);
    } else {
        sendSSE('error', ['message' => 'Quota Import mit Fehlern beendet']);
    }
    
} catch (Exception $e) {
    sendSSE('error', ['message' => 'Ausnahme: ' . $e->getMessage()]);
}
?>
