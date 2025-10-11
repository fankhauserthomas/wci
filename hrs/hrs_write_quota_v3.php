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

// LOGGING: Schreibe in Datei relative zum Skript (robust bei Symlinks)
$logFilePath = __DIR__ . '/debug_v3.log';
$GLOBALS['hrs_v3_log'] = $logFilePath;
$GLOBALS['hrs_v3_buffer'] = [];
function logV3($msg) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    $GLOBALS['hrs_v3_buffer'][] = trim($line);
    if (file_put_contents($GLOBALS['hrs_v3_log'], $line, FILE_APPEND) === false) {
        error_log("hrs_write_quota_v3.log_failed: " . $line);
    }
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
    
    private function resolveQuotaField(array $item, array $candidateKeys) {
        foreach ($candidateKeys as $key) {
            if (isset($item[$key]) && $item[$key] !== null && $item[$key] !== '') {
                return $item[$key];
            }
        }
        
        if (isset($item['quota']) && is_array($item['quota'])) {
            foreach ($candidateKeys as $key) {
                if (isset($item['quota'][$key]) && $item['quota'][$key] !== null && $item['quota'][$key] !== '') {
                    return $item['quota'][$key];
                }
            }
        }
        
        return null;
    }
    
    private function parseQuotaDate($value) {
        if (!$value) {
            return null;
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
        }
        
        return null;
    }
    
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

                $quantities = [];
                $totalQuantity = 0;
                foreach ($this->categoryMap as $categoryName => $categoryId) {
                    $fieldName = 'quota_' . $categoryName;
                    $quantity = isset($quota[$fieldName]) ? (int)$quota[$fieldName] : 0;
                    $quantities[$categoryName] = max(0, $quantity);
                    $totalQuantity += $quantities[$categoryName];
                }

                if ($totalQuantity <= 0) {
                    logV3("ℹ️ V3: Skipping quota for $date (all categories = 0)");
                    continue;
                }

                $quotaId = $this->createQuota($date, $quantities);
                if ($quotaId) {
                    $createdQuotas[] = [
                        'date' => $date,
                        'quantities' => $quantities,
                        'id' => $quotaId
                    ];
                    logV3(sprintf(
                        "✅ V3: Created quota ID %s for %s (L=%d, B=%d, DZ=%d, S=%d)",
                        $quotaId,
                        $date,
                        $quantities['lager'],
                        $quantities['betten'],
                        $quantities['dz'],
                        $quantities['sonder']
                    ));
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
            
            $toDelete = [];
            $page = 0;
            $maxPages = 25;
            $headers = ['X-XSRF-TOKEN' => $this->hrsLogin->getCsrfToken()];
            $totalFound = 0;

            do {
                $url = "/api/v1/manage/hutQuota?hutId={$this->hutId}&page={$page}&size=200&sortList=BeginDate&sortOrder=ASC&open=true&dateFrom={$dateFrom}&dateTo={$dateTo}";
                logV3("🌐 V3: FETCH quotas page {$page}");

                $responseArray = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
                logV3("📬 V3: GET quotas page {$page} status " . ($responseArray['status'] ?? 'n/a'));
                if (isset($responseArray['body'])) {
                    logV3("📄 V3: Page {$page} body preview: " . substr($responseArray['body'], 0, 200));
                }
                
                if (!$responseArray || !isset($responseArray['body'])) {
                    logV3("❌ V3: No response from HRS API (page {$page})");
                    break;
                }

                $data = json_decode($responseArray['body'], true);
                
                if (!$data) {
                    logV3("❌ V3: Failed to parse HRS API response (page {$page})");
                    break;
                }

                $dumpFile = __DIR__ . '/debug_v3_fetch_' . date('Ymd_His') . "_p{$page}.json";
                file_put_contents($dumpFile, $responseArray['body']);

                $quotasList = null;
                if (isset($data['_embedded']['bedCapacityChangeResponseDTOList'])) {
                    $quotasList = $data['_embedded']['bedCapacityChangeResponseDTOList'];
                } else if (isset($data['content'])) {
                    $quotasList = $data['content'];
                } else if (isset($data['quotas']) && is_array($data['quotas'])) {
                    $quotasList = $data['quotas'];
                } else if (isset($data['items']) && is_array($data['items'])) {
                    $quotasList = $data['items'];
                } else if (is_array($data)) {
                    $quotasList = $data;
                }
                
                $currentCount = is_array($quotasList) ? count($quotasList) : 0;
                
                logV3("📄 V3: Page {$page} returned {$currentCount} quotas");

                if (!$quotasList || $currentCount === 0) {
                    break;
                }

                $totalFound += $currentCount;

                foreach ($quotasList as $quota) {
                    $beginDateRaw = $this->resolveQuotaField($quota, ['beginDate', 'dateFrom', 'date_from', 'startDate', 'date_from_formatted']);
                    $endDateRaw = $this->resolveQuotaField($quota, ['endDate', 'dateTo', 'date_to', 'endDate', 'date_to_formatted']);
                    $quotaId = null;

                    if (isset($quota['id']) && $quota['id']) {
                        $quotaId = $quota['id'];
                    } elseif (isset($quota['quotaId']) && $quota['quotaId']) {
                        $quotaId = $quota['quotaId'];
                    } elseif (isset($quota['quota']['id']) && $quota['quota']['id']) {
                        $quotaId = $quota['quota']['id'];
                    }

                    if (!$quotaId) {
                        logV3("⚠️ V3: Unable to determine quota ID for entry: " . json_encode($quota));
                        continue;
                    }

                    $quotaStart = $this->parseQuotaDate($beginDateRaw);
                    $quotaEndRaw = $this->parseQuotaDate($endDateRaw);
                    
                    if (!$quotaStart || !$quotaEndRaw) {
                        logV3(sprintf(
                            "⚠️ V3: Could not parse dates for quota %s (begin=%s, end=%s)",
                            $quotaId,
                            $beginDateRaw,
                            $endDateRaw
                        ));
                        continue;
                    }

                    // Ensure chronological order
                    if ($quotaEndRaw < $quotaStart) {
                        $quotaEndRaw = clone $quotaStart;
                    }

                    $diffDays = (int)$quotaStart->diff($quotaEndRaw)->format('%a');

                    // Default exclusive end
                    $quotaEnd = clone $quotaEndRaw;

                    if ($quotaEnd <= $quotaStart) {
                        // Single-day quota stored without end increment
                        $quotaEnd = (clone $quotaStart)->modify('+1 day');
                    } elseif ($diffDays >= 2) {
                        // Multi-day quotas often use inclusive end → add one day to make exclusive
                        $quotaEnd = (clone $quotaEndRaw)->modify('+1 day');
                    }
                    
                     logV3(sprintf(
                        "🧮 V3: Quota %s range %s -> %s (rawEnd=%s, diff=%d)",
                        $quotaId,
                        $quotaStart->format('Y-m-d'),
                        $quotaEnd->format('Y-m-d'),
                        $quotaEndRaw->format('Y-m-d'),
                        $diffDays
                    ));

                    foreach ($dates as $date) {
                        $checkDate = new DateTime($date);
                        logV3(sprintf(
                            "   🔎 Check %s against %s-%s",
                            $checkDate->format('Y-m-d'),
                            $quotaStart->format('Y-m-d'),
                            $quotaEnd->format('Y-m-d')
                        ));
                        
                        if ($checkDate >= $quotaStart && $checkDate < $quotaEnd) {
                            $toDelete[$quotaId] = $quota;
                            logV3("   ✅ Overlap detected for quota {$quotaId} on {$checkDate->format('Y-m-d')}");
                            break;
                        }
                    }
                }

                $page++;
                $hasMore = false;

                if (isset($data['page']['totalPages'])) {
                    $totalPages = (int)$data['page']['totalPages'];
                    $hasMore = $page < $totalPages;
                } elseif (isset($data['totalPages'])) {
                    $totalPages = (int)$data['totalPages'];
                    $hasMore = $page < $totalPages;
                } else {
                    $hasMore = $currentCount === 200;
                }
            } while ($page < $maxPages && $hasMore);
            
            logV3("📊 V3: Fetched {$totalFound} quotas across {$page} pages");
            logV3("🎯 V3: Found " . count($toDelete) . " overlapping quotas to delete");
            
            foreach ($toDelete as $quotaId => $quota) {
                $beginDate = isset($quota['beginDate']) ? $quota['beginDate'] : $quota['date_from'];
                $endDate = isset($quota['endDate']) ? $quota['endDate'] : $quota['date_to'];
                
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
                'canOverbook' => 'false',
                'allSeries' => 'false'
            ];
            
            $queryString = http_build_query($queryParams);
            $url = "/api/v1/manage/deleteQuota?$queryString";
            
            logV3("🌐 V3: DELETE $url");
            
            $cookies = $this->hrsLogin->getCookies();
            $cookieParts = [];
            foreach ($cookies as $name => $value) {
                $cookieParts[] = "{$name}={$value}";
            }
            $cookieHeader = implode('; ', $cookieParts);
            
            $headers = [
                'Accept: application/json, text/plain, */*',
                'Origin: https://www.hut-reservation.org',
                'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
                'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken(),
                'Cookie: ' . $cookieHeader
            ];
            
            $fullUrl = "https://www.hut-reservation.org{$url}";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                logV3("❌ V3: DELETE curl error for ID $quotaId: $curlError");
                return false;
            }
            
            logV3("📥 V3: DELETE response: HTTP " . $httpCode);
            logV3("📄 V3: DELETE body: " . substr($responseBody, 0, 200));
            
            if ($httpCode == 200) {
                logV3("✅ V3: DELETE successful for ID $quotaId");
                return true;
            }
            
            $decoded = json_decode($responseBody, true);
            if ($decoded && isset($decoded['messageId']) && (int)$decoded['messageId'] === 126) {
                logV3("✅ V3: DELETE treated as success (MessageID 126) for ID $quotaId");
                return true;
            }
            
            logV3("⚠️ V3: DELETE failed for ID $quotaId - HTTP $httpCode");
            return false;
            
        } catch (Exception $e) {
            logV3("❌ V3: DELETE exception for ID $quotaId: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✅ BEWÄHRTE MANAGEMENT API (wie in hrs_create_quota_batch.php)
     */
    private function createQuota($date, array $quantities) {
        try {
            $date_from = $date;
            $date_to = date('Y-m-d', strtotime($date . ' +1 day'));
            
            logV3(sprintf(
                "📤 V3: Creating quota via Management API: %s (L=%d, B=%d, DZ=%d, S=%d)",
                $date,
                $quantities['lager'],
                $quantities['betten'],
                $quantities['dz'],
                $quantities['sonder']
            ));
            
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
                    array('categoryId' => 1958, 'totalBeds' => $quantities['lager'] ?? 0),
                    array('categoryId' => 2293, 'totalBeds' => $quantities['betten'] ?? 0),
                    array('categoryId' => 2381, 'totalBeds' => $quantities['dz'] ?? 0),
                    array('categoryId' => 6106, 'totalBeds' => $quantities['sonder'] ?? 0)
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
            logV3(sprintf(
                "📦 V3: Payload (per category): L=%d, B=%d, DZ=%d, S=%d on %s",
                $quantities['lager'],
                $quantities['betten'],
                $quantities['dz'],
                $quantities['sonder'],
                $date
            ));
            
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
    if (!isset($result['log'])) {
        $result['log'] = $GLOBALS['hrs_v3_buffer'];
    }
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
        'line' => $e->getLine(),
        'log' => $GLOBALS['hrs_v3_buffer']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
