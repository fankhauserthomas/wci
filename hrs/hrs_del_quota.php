<?php
/**
 * HRS Quota Deletion Module
 * =========================
 * 
 * Diese Datei implementiert das Löschen von Quotas im HRS-System.
 * Basiert auf der VB.NET DeleteQuotaAsync Funktion und verwendet
 * die bestehende HRS-Login-Authentifizierung.
 * 
 * API ENDPOINT: DELETE /api/v1/manage/deleteQuota
 * 
 * PARAMETER:
 * - hutId: Hütten-ID (immer 675)
 * - quotaId: HRS Quota-ID (entspricht hrs_id in DB, NICHT id!)
 * - canChangeMode: boolean (default: false)
 * - canOverbook: boolean (default: true) 
 * - allSeries: boolean (default: false)
 * 
 * SICHERHEIT:
 * - Erfordert aktive HRS-Session (hrs_login.php)
 * - CSRF-Token wird automatisch übertragen
 * - Alle Parameter werden validiert
 * 
 * @author Based on VB.NET DeleteQuotaAsync implementation
 * @version 1.0
 * @created 2025-09-06
 */

// Ensure clean JSON output
ob_start();

// Set JSON headers early
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config.php';
require_once 'hrs_login.php';

/**
 * HRS Quota Deletion Class
 */
class HRSQuotaDeleter {
    private $hrsLogin;
    private $hutId = 675; // Standard Hütten-ID
    
    public function __construct() {
        $this->hrsLogin = new HRSLogin();
    }
    
    /**
     * Löscht eine einzelne Quota im HRS-System
     * 
     * @param int $quotaId HRS Quota-ID (hrs_id aus der Datenbank)
     * @param bool $canChangeMode Optional: Erlaube Modus-Änderung (default: false)
     * @param bool $canOverbook Optional: Erlaube Überbuchung (default: true)
     * @param bool $allSeries Optional: Lösche alle in Serie (default: false)
     * @return array Result array mit success/error und details
     */
    public function deleteQuota($quotaId, $canChangeMode = false, $canOverbook = true, $allSeries = false) {
        try {
            // Parameter validieren
            if (!is_numeric($quotaId) || $quotaId <= 0) {
                throw new Exception("Ungültige Quota-ID: $quotaId");
            }
            
            $this->debugLog("🗑️ Starte Quota-Löschung für ID: $quotaId");
            
            // 1. HRS Login durchführen
            if (!$this->hrsLogin->login()) {
                throw new Exception("HRS Login fehlgeschlagen");
            }
            
            $this->debugLog("✅ HRS Login erfolgreich");
            
            // 2. Query-String erstellen (exakt wie in VB.NET)
            $queryParams = array(
                'hutId' => $this->hutId,
                'quotaId' => (int)$quotaId,
                'canChangeMode' => $canChangeMode ? 'true' : 'false',
                'canOverbook' => $canOverbook ? 'true' : 'false',
                'allSeries' => $allSeries ? 'true' : 'false'
            );
            
            $queryString = http_build_query($queryParams);
            $url = "/api/v1/manage/deleteQuota?$queryString";
            
            // 3. Request ausführen mit korrekten Headers (wie in VB.NET)
            $headers = array(
                'accept' => 'application/json, text/plain, */*',
                'origin' => 'https://www.hut-reservation.org',
                'referer' => 'https://www.hut-reservation.org/hut/manage-hut/675',
                'x-xsrf-token' => $this->hrsLogin->getCurrentCSRFToken(),
                'cookie' => $this->hrsLogin->getCookieHeader()
            );
            
            $this->debugLog("🌐 DELETE URL: $url");
            $this->debugLog("📤 Headers: " . print_r($headers, true));
            
            $response = $this->hrsLogin->makeRequest($url, 'DELETE', null, $headers);
            
            // 4. Response analysieren
            $this->debugLog("📨 HRS Response: " . print_r($response, true));
            
            if (isset($response['success']) && $response['success']) {
                $this->debugLog("✅ Quota erfolgreich gelöscht");
                return array(
                    'success' => true,
                    'message' => 'Quota erfolgreich gelöscht',
                    'quota_id' => $quotaId,
                    'response' => $response
                );
            } else {
                // Detaillierte Fehleranalyse
                $errorDetails = array();
                
                if (isset($response['error'])) {
                    $errorDetails[] = "API Error: " . $response['error'];
                }
                if (isset($response['message'])) {
                    $errorDetails[] = "Message: " . $response['message'];
                }
                if (isset($response['details'])) {
                    $errorDetails[] = "Details: " . print_r($response['details'], true);
                }
                if (isset($response['code'])) {
                    $errorDetails[] = "Code: " . $response['code'];
                }
                
                if (empty($errorDetails)) {
                    $errorDetails[] = "Unbekannte Antwort: " . print_r($response, true);
                }
                
                $errorMsg = implode(" | ", $errorDetails);
                $this->debugLog("❌ HRS Fehler: $errorMsg");
                
                throw new Exception("HRS API Fehler: $errorMsg");
            }
            
        } catch (Exception $e) {
            $this->debugLog("❌ FEHLER: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'quota_id' => $quotaId
            );
        }
    }
    
    /**
     * Löscht mehrere Quotas in einer Transaktion
     * 
     * @param array $quotaIds Array von HRS Quota-IDs
     * @param bool $stopOnError Bei true: Stoppe bei erstem Fehler
     * @return array Detaillierte Ergebnisse aller Löschvorgänge
     */
    public function deleteMultipleQuotas($quotaIds, $stopOnError = true) {
        $results = array();
        $successCount = 0;
        $errorCount = 0;
        
        $this->debugLog("🗑️ Starte Mehrfach-Löschung für " . count($quotaIds) . " Quotas");
        
        foreach ($quotaIds as $quotaId) {
            $result = $this->deleteQuota($quotaId);
            $results[] = $result;
            
            if ($result['success']) {
                $successCount++;
                $this->debugLog("✅ Quota $quotaId erfolgreich gelöscht ($successCount/" . count($quotaIds) . ")");
            } else {
                $errorCount++;
                $this->debugLog("❌ Quota $quotaId Fehler: " . $result['error']);
                
                if ($stopOnError) {
                    $this->debugLog("🛑 Stoppe bei erstem Fehler (stopOnError=true)");
                    break;
                }
            }
            
            // Kurze Pause zwischen Requests um HRS-Server zu schonen
            usleep(500000); // 0.5 Sekunden
        }
        
        return array(
            'total' => count($quotaIds),
            'success' => $successCount,
            'errors' => $errorCount,
            'results' => $results,
            'completed' => $errorCount == 0 || !$stopOnError
        );
    }
    
    /**
     * Debugging-Ausgabe mit Timestamp
     */
    private function debugLog($message) {
        $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
        $logMessage = "[$timestamp] HRS_DEL_QUOTA: $message";
        
        // In Error-Log schreiben (nicht in stdout!)
        error_log($logMessage);
        
        // Auch in Debug-File für bessere Verfolgung
        $debugFile = __DIR__ . '/debug_hrs_delete.log';
        file_put_contents($debugFile, $logMessage . "\n", FILE_APPEND | LOCK_EX);
    }
}

// ===== AJAX ENDPOINT =====
// Wenn diese Datei direkt aufgerufen wird, als AJAX-Endpoint fungieren

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        // POST-Daten validieren
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['action'])) {
            throw new Exception('Keine Aktion angegeben');
        }
        
        $deleter = new HRSQuotaDeleter();
        $result = null;
        
        switch ($input['action']) {
            case 'delete_single':
                if (!isset($input['quota_id'])) {
                    throw new Exception('Quota-ID fehlt');
                }
                $result = $deleter->deleteQuota(
                    $input['quota_id'],
                    $input['can_change_mode'] ?? false,
                    $input['can_overbook'] ?? true,
                    $input['all_series'] ?? false
                );
                break;
                
            case 'delete_multiple':
                // Neue Struktur: Array von Quota-Objekten mit Details
                if (!isset($input['quotas']) || !is_array($input['quotas'])) {
                    throw new Exception('Quotas Array fehlt');
                }
                
                $results = array();
                $successCount = 0;
                $totalCount = count($input['quotas']);
                
                foreach ($input['quotas'] as $quota) {
                    if (!isset($quota['hrs_id'])) {
                        $results[] = array(
                            'success' => false,
                            'name' => $quota['name'] ?? 'Unbekannt',
                            'error' => 'HRS-ID fehlt'
                        );
                        continue;
                    }
                    
                    try {
                        // Einzellöschung wie in VB.NET - eine nach der anderen
                        error_log("HRS_DEL_QUOTA: 🗑️ Lösche einzelne Quota: {$quota['hrs_id']} ({$quota['name']})");
                        
                        $deleteResult = $deleter->deleteQuota(
                            $quota['hrs_id'],
                            false, // canChangeMode = false (wie in VB.NET)
                            true,  // canOverbook = true (wie in VB.NET)
                            false  // allSeries = false (wie in VB.NET)
                        );
                        
                        if ($deleteResult['success']) {
                            $successCount++;
                            $results[] = array(
                                'success' => true,
                                'hrs_id' => $quota['hrs_id'],
                                'name' => $quota['name'] ?? 'Unbekannt',
                                'message' => 'Erfolgreich gelöscht'
                            );
                            error_log("HRS_DEL_QUOTA: ✅ Quota {$quota['hrs_id']} erfolgreich gelöscht");
                        } else {
                            $results[] = array(
                                'success' => false,
                                'hrs_id' => $quota['hrs_id'],
                                'name' => $quota['name'] ?? 'Unbekannt',
                                'error' => $deleteResult['error'] ?? 'Unbekannter Fehler'
                            );
                            error_log("HRS_DEL_QUOTA: ❌ Quota {$quota['hrs_id']} Fehler: " . ($deleteResult['error'] ?? 'Unbekannt'));
                        }
                    } catch (Exception $e) {
                        $results[] = array(
                            'success' => false,
                            'hrs_id' => $quota['hrs_id'],
                            'name' => $quota['name'] ?? 'Unbekannt',
                            'error' => $e->getMessage()
                        );
                        error_log("HRS_DEL_QUOTA: ❌ Exception bei Quota {$quota['hrs_id']}: " . $e->getMessage());
                    }
                    
                    // Stop on first error if requested (wie in VB.NET - Exit Function bei Fehler)
                    if (($input['stop_on_error'] ?? true) && end($results)['success'] === false) {
                        error_log("HRS_DEL_QUOTA: 🛑 Stoppe bei erstem Fehler (stop_on_error=true)");
                        break;
                    }
                }
                
                $result = array(
                    'success' => $successCount > 0,
                    'deleted_count' => $successCount,
                    'total_count' => $totalCount,
                    'details' => $results,
                    'message' => "Erfolgreich {$successCount} von {$totalCount} Quotas gelöscht"
                );
                break;
                
            case 'test_connection':
                // Test ob HRS-Login funktioniert
                $hrsLogin = new HRSLogin();
                if ($hrsLogin->login()) {
                    $result = array('success' => true, 'message' => 'HRS-Verbindung erfolgreich');
                } else {
                    $result = array('success' => false, 'error' => 'HRS-Login fehlgeschlagen');
                }
                break;
                
            default:
                throw new Exception('Unbekannte Aktion: ' . $input['action']);
        }
        
        // Clean output buffer and send JSON
        ob_clean();
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        // Clean output buffer and send error
        ob_clean();
        http_response_code(400);
        echo json_encode(array(
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => array(
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            )
        ), JSON_UNESCAPED_UNICODE);
    }
    
    ob_end_flush();
    exit;
}

?>
