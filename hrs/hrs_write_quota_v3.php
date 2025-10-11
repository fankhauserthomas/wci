<?php
/**
 * HRS Quota Writer V3 - With Management API
 * ==========================================
 * 
 * Verwendet die BEWÄHRTE Management API (wie hrs_create_quota_batch.php)
 * statt der REST API die 405 Fehler produziert!
 * 
 * FORMEL: Q_write = assigned_guests + Q_berechnet
 */

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

// LOGGING: Schreibe in Datei die garantiert funktioniert
$GLOBALS['hrs_v3_log'] = '/home/vadmin/lemp/html/wci/hrs/debug_v3.log';
function logV3($msg) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($GLOBALS['hrs_v3_log'], "[$timestamp] $msg\n", FILE_APPEND);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/hrs_login.php';

class QuotaWriterV3 {
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
            
            $dates = array_map(function($q) { return $q['date']; }, $quotas);
            logV3("📅 V3: Processing " . count($dates) . " dates: " . implode(', ', $dates));
            
            // Delete overlapping quotas
            $deletedQuotas = $this->deleteOverlappingQuotas($dates);
            logV3("🗑️ V3: Deleted " . count($deletedQuotas) . " overlapping quotas");
            
            // Create new quotas
            foreach ($quotas as $quota) {
                $date = $quota['date'];
                
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
                            logV3("✅ V3: Created quota: $date $categoryName=$quantity (ID: $quotaId)");
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
            logV3("❌ V3 Error in updateQuotas: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function deleteOverlappingQuotas($dates) {
        $deleted = [];
        
        try {
            $minDate = min($dates);
            $maxDate = max($dates);
            
            $dateFrom = date('d.m.Y', strtotime($minDate . ' -30 days'));
            $dateTo = date('d.m.Y', strtotime($maxDate . ' +30 days'));
            
            logV3("🔍 V3: Searching quotas from $dateFrom to $dateTo");
            
            $url = "/api/v1/manage/hutQuota?hutId={$this->hutId}&page=0&size=200&sortList=BeginDate&sortOrder=ASC&open=true&dateFrom={$dateFrom}&dateTo={$dateTo}";
            
            $headers = ['X-XSRF-TOKEN' => $this->hrsLogin->getCsrfToken()];
            $responseArray = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
            
            if (!$responseArray || !isset($responseArray['body'])) {
                logV3("❌ V3: No response from HRS API");
                return $deleted;
            }
            
            $data = json_decode($responseArray['body'], true);
            
            if (!$data) {
                logV3("❌ V3: Failed to parse HRS API response");
                return $deleted;
            }
            
            $quotasList = null;
            if (isset($data['_embedded']['bedCapacityChangeResponseDTOList'])) {
                $quotasList = $data['_embedded']['bedCapacityChangeResponseDTOList'];
            } else if (isset($data['content'])) {
                $quotasList = $data['content'];
            } else if (is_array($data)) {
                $quotasList = $data;
            }
            
            if (!$quotasList || count($quotasList) === 0) {
                logV3("📭 V3: No quotas found");
                return $deleted;
            }
            
            logV3("📊 V3: Found " . count($quotasList) . " total quotas");
            
            $toDelete = [];
            foreach ($quotasList as $quota) {
                $beginDate = isset($quota['beginDate']) ? $quota['beginDate'] : $quota['date_from'];
                $endDate = isset($quota['endDate']) ? $quota['endDate'] : $quota['date_to'];
                
                $quotaStart = new DateTime($beginDate);
                $quotaEnd = new DateTime($endDate);
                
                foreach ($dates as $date) {
                    $checkDate = new DateTime($date);
                    
                    if ($checkDate >= $quotaStart && $checkDate < $quotaEnd) {
                        $toDelete[$quota['id']] = $quota;
                        break;
                    }
                }
            }
            
            logV3("🎯 V3: Found " . count($toDelete) . " overlapping quotas to delete");
            
            foreach ($toDelete as $quota) {
                $beginDate = isset($quota['beginDate']) ? $quota['beginDate'] : $quota['date_from'];
                $endDate = isset($quota['endDate']) ? $quota['endDate'] : $quota['date_to'];
                $quotaId = $quota['id'];
                
                logV3("🗑️ V3: Attempting to delete quota ID $quotaId ($beginDate - $endDate)");
                
                if ($this->deleteQuotaViaAPI($quotaId)) {
                    $deleted[] = [
                        'id' => $quotaId,
                        'from' => $beginDate,
                        'to' => $endDate
                    ];
                    logV3("✅ V3: Deleted quota ID $quotaId");
                } else {
                    logV3("⚠️ V3: Failed to delete quota ID $quotaId");
                }
            }
            
        } catch (Exception $e) {
            logV3("❌ V3 Error in deleteOverlappingQuotas: " . $e->getMessage());
        }
        
        return $deleted;
    }
    
    private function deleteQuotaViaAPI($quotaId) {
        try {
            logV3("🔧 V3: deleteQuotaViaAPI called for ID $quotaId");
            
            $queryParams = [
                'hutId' => $this->hutId,
                'quotaId' => (int)$quotaId,
                'canChangeMode' => 'false',
                'canOverbook' => 'true',
                'allSeries' => 'false'
            ];
            
            $queryString = http_build_query($queryParams);
            $url = "/api/v1/manage/deleteQuota?$queryString";
            
            logV3("🌐 V3: DELETE $url");
            
            $headers = [
                'X-XSRF-TOKEN' => $this->hrsLogin->getCsrfToken()
            ];
            
            $response = $this->hrsLogin->makeRequest($url, 'DELETE', null, $headers);
            
            logV3("📥 V3: DELETE response: HTTP " . ($response['status'] ?? 'N/A'));
            
            if (isset($response['status']) && $response['status'] == 200) {
                logV3("✅ V3: DELETE successful for ID $quotaId");
                return true;
            }
            
            logV3("⚠️ V3: DELETE failed for ID $quotaId - HTTP " . ($response['status'] ?? 'N/A'));
            return false;
            
        } catch (Exception $e) {
            logV3("❌ V3: DELETE exception for ID $quotaId: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✅ BEWÄHRTE MANAGEMENT API (wie in hrs_create_quota_batch.php)
     */
    private function createQuota($date, $category, $quantity) {
        try {
            $categoryId = $this->categoryMap[$category];
            $date_from = $date;
            $date_to = date('Y-m-d', strtotime($date . ' +1 day'));
            
            logV3("📤 V3: Creating quota via Management API: $date $category=$quantity");
            
            $payload = array(
                'id' => 0,
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
            
            // ✅ DIREKTES CURL wie hrs_create_quota_batch.php (BEWÄHRT!)
            $url = "https://www.hut-reservation.org/api/v1/manage/hutQuota/{$this->hutId}";
            
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
            
            logV3("🌐 V3: POST $url (Direct CURL like hrs_create_quota_batch.php)");
            logV3("📦 V3: Payload: $category=$quantity on $date");
            
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ));
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_error) {
                logV3("❌ V3: CURL Error: $curl_error");
                throw new Exception("CURL-Fehler: $curl_error");
            }
            
            $response_body = $response;
            
            logV3("📥 V3: HTTP $http_code");
            logV3("📥 V3: Response: " . substr($response_body, 0, 200));
            
            if ($http_code !== 200) {
                throw new Exception("HTTP-Fehler $http_code: $response_body");
            }
            
            $response_data = json_decode($response_body, true);
            if ($response_data && isset($response_data['messageId']) && $response_data['messageId'] == 120) {
                $quotaId = $response_data['param1'] ?? null;
                logV3("✅ V3: Quota created successfully (MessageID: 120, ID: $quotaId)");
                return $quotaId;
            } else {
                logV3("⚠️ V3: Unexpected response: $response_body");
                throw new Exception("Unerwartete Antwort: " . $response_body);
            }
            
        } catch (Exception $e) {
            logV3("❌ V3 Exception creating quota: " . $e->getMessage());
            throw $e;
        }
    }
}

// ===== AJAX ENDPOINT =====

logV3("🚀 hrs_write_quota_v3.php START");

try {
    global $mysqli;
    
    if (!$mysqli || !($mysqli instanceof mysqli)) {
        throw new Exception('Database connection not available');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['quotas'])) {
        throw new Exception('Invalid input - missing quotas array');
    }
    
    if (!isset($input['operation'])) {
        throw new Exception('Invalid input - missing operation');
    }
    
    logV3("📥 V3: Received " . count($input['quotas']) . " quotas");
    
    // HRS Login
    logV3("🔐 V3: Starting HRS Login...");
    $hrsLogin = new HRSLogin();
    
    if (!$hrsLogin->login()) {
        throw new Exception('HRS Login failed');
    }
    
    logV3("✅ V3: HRS Login successful");
    
    // Process quotas
    $writer = new QuotaWriterV3($mysqli, $hrsLogin);
    $result = $writer->updateQuotas($input['quotas']);
    
    // Send response
    ob_end_clean();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    logV3("🎉 V3: Response sent successfully");
    
} catch (Exception $e) {
    logV3("❌ V3 Fatal error: " . $e->getMessage());
    
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
