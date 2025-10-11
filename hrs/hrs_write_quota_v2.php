<?php
/**
 * HRS Quota Writer V2 - With Multi-Day Support
 * =============================================
 * 
 * Handles multi-day quota splitting automatically
 * FORMEL: Q_write = assigned_guests + Q_berechnet
 */

// Start output buffering EARLY
ob_start();

// Disable all error output to stdout
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/hrs_login.php';

class QuotaWriterV2 {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675;
    
    private $categoryMap = [
        'lager'  => 1958,
        'betten' => 2293,
        'dz'     => 2381,
        'sonder' => 6106
    ];
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
    }
    
    public function updateQuotas($quotas) {
        try {
            $createdQuotas = [];
            $deletedQuotas = [];
            
            // Collect all dates
            $dates = array_map(function($q) { return $q['date']; }, $quotas);
            
            error_log("📅 Processing " . count($dates) . " dates: " . implode(', ', $dates));
            
            // Delete ALL overlapping quotas (including multi-day ones)
            $deletedQuotas = $this->deleteOverlappingQuotas($dates);
            
            error_log("🗑️ Deleted " . count($deletedQuotas) . " overlapping quotas");
            
            // Create new single-day quotas
            foreach ($quotas as $quota) {
                $date = $quota['date'];
                
                // Create quota for each category with quantity > 0
                foreach ($this->categoryMap as $categoryName => $categoryId) {
                    $fieldName = 'quota_' . $categoryName;
                    $quantity = isset($quota[$fieldName]) ? (int)$quota[$fieldName] : 0;
                    
                    if ($quantity > 0) {
                        $quotaId = $this->createQuota($date, $categoryName, $quantity);
                        if ($quotaId) {
                            $createdQuotas[] = [
                                'date' => $date,
                                'category' => $categoryName,
                                'quantity' => $quantity,
                                'id' => $quotaId
                            ];
                            error_log("✅ Created quota: $date $categoryName=$quantity (ID: $quotaId)");
                        }
                    }
                }
            }
            
            return [
                'success' => true,
                'createdQuotas' => $createdQuotas,
                'deletedQuotas' => $deletedQuotas,
                'message' => count($createdQuotas) . ' Quotas erstellt, ' . count($deletedQuotas) . ' gelöscht'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error in updateQuotas: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete ALL quotas overlapping with selected dates (including multi-day)
     * Uses HRS API to find and delete quotas
     */
    private function deleteOverlappingQuotas($dates) {
        $deleted = [];
        
        try {
            // Get min/max dates
            $minDate = min($dates);
            $maxDate = max($dates);
            
            // Extend range to catch multi-day quotas (30 days before/after)
            $dateFrom = date('d.m.Y', strtotime($minDate . ' -30 days'));
            $dateTo = date('d.m.Y', strtotime($maxDate . ' +30 days'));
            
            error_log("🔍 Searching for overlapping quotas from $dateFrom to $dateTo");
            
            // Call HRS API to get quotas
            $url = "/api/v1/manage/hutQuota?hutId={$this->hutId}&page=0&size=200&sortList=BeginDate&sortOrder=ASC&open=true&dateFrom={$dateFrom}&dateTo={$dateTo}";
            
            $headers = ['X-XSRF-TOKEN' => $this->hrsLogin->getCsrfToken()];
            $responseArray = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
            
            if (!$responseArray || !isset($responseArray['body'])) {
                error_log("❌ No response from HRS API");
                return $deleted;
            }
            
            $data = json_decode($responseArray['body'], true);
            
            if (!$data) {
                error_log("❌ Failed to parse HRS API response");
                return $deleted;
            }
            
            // Check different response formats
            $quotasList = null;
            if (isset($data['_embedded']['bedCapacityChangeResponseDTOList'])) {
                $quotasList = $data['_embedded']['bedCapacityChangeResponseDTOList'];
            } else if (isset($data['content'])) {
                $quotasList = $data['content'];
            } else if (is_array($data)) {
                $quotasList = $data;
            }
            
            if (!$quotasList || count($quotasList) === 0) {
                error_log("📭 No quotas found in response");
                return $deleted;
            }
            
            error_log("📊 Found " . count($quotasList) . " total quotas");
            
            // Filter quotas that overlap with our selected dates
            $toDelete = [];
            foreach ($quotasList as $quota) {
                // Handle different date formats
                $beginDate = isset($quota['beginDate']) ? $quota['beginDate'] : $quota['date_from'];
                $endDate = isset($quota['endDate']) ? $quota['endDate'] : $quota['date_to'];
                
                $quotaStart = new DateTime($beginDate);
                $quotaEnd = new DateTime($endDate);
                
                // Check if quota overlaps with ANY of our selected dates
                foreach ($dates as $date) {
                    $checkDate = new DateTime($date);
                    
                    if ($checkDate >= $quotaStart && $checkDate < $quotaEnd) {
                        $toDelete[$quota['id']] = $quota; // Use ID as key to avoid duplicates
                        break;
                    }
                }
            }
            
            error_log("🎯 Found " . count($toDelete) . " overlapping quotas to delete");
            
            // Delete each overlapping quota via HRS API
            foreach ($toDelete as $quota) {
                $beginDate = isset($quota['beginDate']) ? $quota['beginDate'] : $quota['date_from'];
                $endDate = isset($quota['endDate']) ? $quota['endDate'] : $quota['date_to'];
                
                if ($this->deleteQuotaViaAPI($quota['id'])) {
                    $deleted[] = [
                        'id' => $quota['id'],
                        'from' => $beginDate,
                        'to' => $endDate
                    ];
                    error_log("✅ Deleted quota ID {$quota['id']} ($beginDate - $endDate)");
                } else {
                    error_log("⚠️ Failed to delete quota ID {$quota['id']}");
                }
            }
            
        } catch (Exception $e) {
            error_log("❌ Error in deleteOverlappingQuotas: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
        
        return $deleted;
    }
    
    /**
     * Delete a quota via HRS API
     */
    private function deleteQuotaViaAPI($quotaId) {
        try {
            $queryParams = [
                'hutId' => $this->hutId,
                'quotaId' => (int)$quotaId,
                'canChangeMode' => 'false',
                'canOverbook' => 'true',
                'allSeries' => 'false'
            ];
            
            $queryString = http_build_query($queryParams);
            $url = "/api/v1/manage/deleteQuota?$queryString";
            
            $headers = [
                'accept' => 'application/json, text/plain, */*',
                'origin' => 'https://www.hut-reservation.org',
                'referer' => 'https://www.hut-reservation.org/hut/manage-hut/675',
                'x-xsrf-token' => $this->hrsLogin->getCurrentCSRFToken(),
                'cookie' => $this->hrsLogin->getCookieHeader()
            ];
            
            $response = $this->hrsLogin->makeRequest($url, 'DELETE', null, $headers);
            
            if (isset($response['success']) && $response['success']) {
                return true;
            }
            
            // Also check HTTP status
            if (isset($response['status']) && $response['status'] == 200) {
                return true;
            }
            
            error_log("⚠️ Delete response for $quotaId: " . json_encode($response));
            return false;
            
        } catch (Exception $e) {
            error_log("❌ Failed to delete quota $quotaId: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a single-day quota via HRS API (bewährte Methode aus hrs_create_quota_batch_fixed.php)
     */
    private function createQuota($date, $category, $quantity) {
        try {
            $categoryId = $this->categoryMap[$category];
            $date_from = $date;
            $date_to = date('Y-m-d', strtotime($date . ' +1 day'));
            
            error_log("📤 Creating quota: $date $category=$quantity (category_id=$categoryId)");
            
            // Payload bauen (exakt wie in hrs_create_quota_batch_fixed.php - bewährt!)
            $payload = array(
                'id' => 0, // 0 für neue Quotas
                'title' => 'Timeline Quota ' . $date,
                'reservationMode' => 'SERVICED',
                'isRecurring' => null,
                'capacity' => 0,
                'languagesDataDTOs' => array(
                    array('language' => 'DE_DE', 'description' => ''),
                    array('language' => 'EN', 'description' => '')
                ),
                'hutBedCategoryDTOs' => array(
                    array('categoryId' => 1958, 'totalBeds' => $category === 'lager' ? $quantity : 0),
                    array('categoryId' => 2293, 'totalBeds' => $category === 'betten' ? $quantity : 0),
                    array('categoryId' => 2381, 'totalBeds' => $category === 'dz' ? $quantity : 0),
                    array('categoryId' => 6106, 'totalBeds' => $category === 'sonder' ? $quantity : 0)
                ),
                'monday' => null,
                'tuesday' => null,
                'wednesday' => null,
                'thursday' => null,
                'friday' => null,
                'saturday' => null,
                'sunday' => null,
                'weeksRecurrence' => null,
                'occurrencesNumber' => null,
                'seriesBeginDate' => '',
                'dateFrom' => date('d.m.Y', strtotime($date_from)),
                'dateTo' => date('d.m.Y', strtotime($date_to)),
                'canOverbook' => true,
                'canChangeMode' => false,
                'allSeries' => false
            );
            
            $json_payload = json_encode($payload);
            
            // WICHTIG: Relativer Pfad, weil HRSLogin->makeRequest() baseUrl hinzufügt!
            // Aber makeRequest() ist für API-Calls, nicht REST!
            // Deshalb direkter CURL wie in hrs_create_quota_batch_fixed.php:
            
            $url = 'https://www.hut-reservation.org/hut/rest/hutQuota/675';
            
            // Cookie-Header aus HRSLogin-Cookies erstellen
            $cookies = $this->hrsLogin->getCookies();
            $cookie_parts = array();
            foreach ($cookies as $name => $value) {
                $cookie_parts[] = "$name=$value";
            }
            $cookie_header = implode('; ', $cookie_parts);
            
            $headers = array(
                'Accept: application/json, text/plain, */*',
                'Content-Type: application/json',
                'Origin: https://www.hut-reservation.org',
                'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
                'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken(),
                'Cookie: ' . $cookie_header
            );
            
            error_log("🌐 POST $url");
            error_log("� CSRF Token: " . substr($this->hrsLogin->getCsrfToken(), 0, 20) . "...");
            error_log("🍪 Cookies: " . substr($cookie_header, 0, 100) . "...");
            error_log("📦 Payload length: " . strlen($json_payload) . " bytes");
            error_log("�📦 Payload sample: " . substr($json_payload, 0, 300) . "...");
            
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false, // Wie im Original!
                CURLOPT_FOLLOWLOCATION => true
            ));
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_error) {
                error_log("❌ CURL Error: $curl_error");
                throw new Exception("CURL-Fehler: $curl_error");
            }
            
            error_log("📥 HTTP $http_code: " . substr($response, 0, 200));
            
            if ($http_code !== 200) {
                error_log("❌ HTTP Error $http_code: $response");
                throw new Exception("HTTP-Fehler $http_code: $response");
            }
            
            // Parse Response und prüfe MessageID
            $response_data = json_decode($response, true);
            if ($response_data && isset($response_data['messageId']) && $response_data['messageId'] == 120) {
                $quotaId = $response_data['param1'] ?? null; // HRS gibt die ID zurück
                error_log("✅ Quota erstellt: $date $category=$quantity (MessageID: 120, ID: $quotaId)");
                return $quotaId;
            } else {
                error_log("⚠️ Unerwartete Response: $response");
                throw new Exception("Unerwartete Antwort: " . $response);
            }
            
        } catch (Exception $e) {
            error_log("❌ Exception creating quota: " . $e->getMessage());
            throw $e;
        }
    }
}

// ===== AJAX ENDPOINT =====

error_log("🚀 hrs_write_quota_v2.php START");

try {
    // Get database connection from config
    global $mysqli;
    
    error_log("📦 Checking database connection...");
    
    if (!$mysqli || !($mysqli instanceof mysqli)) {
        error_log("❌ Database connection failed: mysqli not available");
        throw new Exception('Database connection not available');
    }
    
    error_log("✅ Database connection OK");
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    error_log("📥 Input received: " . strlen(file_get_contents('php://input')) . " bytes");
    
    if (!$input || !isset($input['quotas'])) {
        error_log("❌ Invalid input - missing quotas array");
        throw new Exception('Invalid input - missing quotas array');
    }
    
    if (!isset($input['operation'])) {
        error_log("❌ Invalid input - missing operation");
        throw new Exception('Invalid input - missing operation');
    }
    
    error_log("📥 Received request with " . count($input['quotas']) . " quotas");
    
    // Login to HRS (KEIN Parameter - wie im Original!)
    error_log("🔐 Starting HRS Login...");
    $hrsLogin = new HRSLogin();
    
    if (!$hrsLogin->login()) {
        error_log("❌ HRS Login failed");
        throw new Exception('HRS Login failed');
    }
    
    error_log("✅ HRS Login successful");
    
    // Process quotas
    error_log("⚙️ Creating QuotaWriterV2...");
    $writer = new QuotaWriterV2($mysqli, $hrsLogin);
    
    error_log("📝 Processing quotas...");
    $result = $writer->updateQuotas($input['quotas']);
    
    error_log("✅ Quotas processed successfully");
    
    // Clean output buffer and send response
    ob_end_clean();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    error_log("🎉 Response sent successfully");
    
} catch (Exception $e) {
    error_log("❌ Fatal error: " . $e->getMessage());
    error_log("📍 File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("📚 Trace: " . $e->getTraceAsString());
    
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
