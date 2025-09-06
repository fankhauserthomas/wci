<?php
/**
 * HRS Quota Delete Multiple - Mehrere Quotas mit einem Login löschen
 * ================================================================
 * 
 * Löscht mehrere Quotas im HRS-System mit einem einzigen Login.
 * Verwendet die bewährte HRSLogin-Klasse (NICHT ÄNDERN!).
 * 
 * Parameter:
 * - quotas: JSON-Array mit Quota-Objekten [{hrs_id, name}, ...]
 * ODER einzelne Parameter:
 * - hrs_id: HRS Quota-ID (aus der Datenbank)
 * - name: Quota-Name für Logging
 * 
 * @author Nach dem Muster von hrs_imp_res.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Headers für JSON-Response
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/hrs_login.php';

try {
    // Parameter verarbeiten - unterstützt sowohl einzelne als auch multiple Quotas
    $quotasToDelete = array();
    
    if (isset($_POST['quotas'])) {
        // Multiple Quotas als JSON
        $quotasJson = $_POST['quotas'];
        if (is_string($quotasJson)) {
            $quotasArray = json_decode($quotasJson, true);
            if ($quotasArray && is_array($quotasArray)) {
                $quotasToDelete = $quotasArray;
            } else {
                throw new Exception('Ungültiges Quotas-JSON-Format');
            }
        } elseif (is_array($quotasJson)) {
            $quotasToDelete = $quotasJson;
        }
    } else {
        // Einzelne Quota (Backward-Kompatibilität)
        $hrs_id = isset($_POST['hrs_id']) ? (int)$_POST['hrs_id'] : 0;
        $name = isset($_POST['name']) ? $_POST['name'] : 'Unbekannt';
        
        if ($hrs_id > 0) {
            $quotasToDelete = array(array('hrs_id' => $hrs_id, 'name' => $name));
        }
    }
    
    if (empty($quotasToDelete)) {
        throw new Exception('Keine Quotas zum Löschen angegeben');
    }
    
    // Quotas validieren
    foreach ($quotasToDelete as $index => $quota) {
        if (!isset($quota['hrs_id']) || (int)$quota['hrs_id'] <= 0) {
            throw new Exception("Ungültige HRS-ID bei Quota #" . ($index + 1));
        }
        $quotasToDelete[$index]['hrs_id'] = (int)$quota['hrs_id'];
        $quotasToDelete[$index]['name'] = $quota['name'] ?? "Quota-{$quota['hrs_id']}";
    }
    
    echo json_encode(array(
        'status' => 'info',
        'message' => "Starte Löschung von " . count($quotasToDelete) . " Quota(s)",
        'total_count' => count($quotasToDelete)
    )) . "\n";
    
    // EINMALIGER HRS Login für alle Quotas
    echo json_encode(array(
        'status' => 'info',
        'message' => 'Verbinde mit HRS-System (einmalig für alle Quotas)...'
    )) . "\n";
    
    $hrsLogin = new HRSLogin();
    if (!$hrsLogin->login()) {
        throw new Exception('HRS Login fehlgeschlagen');
    }
    
    echo json_encode(array(
        'status' => 'success',
        'message' => 'HRS-Login erfolgreich - bereit für Löschungen'
    )) . "\n";
    
    // Standard DELETE-Parameter (wie in VB.NET + deine Tests)
    $hutId = 675; // Standard Hütten-ID
    $canChangeMode = false;
    $canOverbook = true;  // WICHTIG: true wegen bestehender Reservierungen!
    $allSeries = false;
    
    // Lösche alle Quotas eine nach der anderen (wie in VB.NET)
    $results = array();
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($quotasToDelete as $index => $quota) {
        $quotaNumber = $index + 1;
        $hrs_id = $quota['hrs_id'];
        $name = $quota['name'];
        
        echo json_encode(array(
            'status' => 'info',
            'message' => "[$quotaNumber/" . count($quotasToDelete) . "] Lösche: $name (HRS-ID: $hrs_id)"
        )) . "\n";
        
        try {
            // DELETE Request vorbereiten
            $queryParams = array(
                'hutId' => $hutId,
                'quotaId' => $hrs_id,
                'canChangeMode' => $canChangeMode ? 'true' : 'false',
                'canOverbook' => $canOverbook ? 'true' : 'false',
                'allSeries' => $allSeries ? 'true' : 'false'
            );
            
            $queryString = http_build_query($queryParams);
            $deleteUrl = "/api/v1/manage/deleteQuota?$queryString";
            
            // DELETE Request mit bestehender HRSLogin-Session
            $deleteHeaders = array(
                'Accept: application/json, text/plain, */*',
                'Origin: https://www.hut-reservation.org',
                'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
                'X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken()
            );
            
            $deleteResponse = $hrsLogin->makeRequest($deleteUrl, 'DELETE', null, $deleteHeaders);
            
            if (!$deleteResponse) {
                throw new Exception("Verbindungsfehler beim Löschen von $name");
            }
            
            $httpCode = $deleteResponse['status'];
            $responseBody = $deleteResponse['body'];
            
            // Response analysieren
            if ($httpCode == 200) {
                $successCount++;
                $results[] = array(
                    'success' => true,
                    'hrs_id' => $hrs_id,
                    'name' => $name,
                    'message' => 'Erfolgreich gelöscht'
                );
                
                echo json_encode(array(
                    'status' => 'success',
                    'message' => "  ✅ $name erfolgreich gelöscht",
                    'quota_result' => array('hrs_id' => $hrs_id, 'success' => true)
                )) . "\n";
                
            } else {
                // HRS-spezifische Fehleranalyse
                $errorMsg = "HTTP $httpCode";
                $isActualError = true;
                
                if (!empty($responseBody)) {
                    $bodyData = json_decode($responseBody, true);
                    if ($bodyData) {
                        if (isset($bodyData['description'])) {
                            $errorMsg .= ": " . $bodyData['description'];
                        }
                        
                        // Message-ID 126 ist eigentlich ein Erfolg
                        if (isset($bodyData['messageId']) && $bodyData['messageId'] == 126) {
                            $successCount++;
                            $isActualError = false;
                            $results[] = array(
                                'success' => true,
                                'hrs_id' => $hrs_id,
                                'name' => $name,
                                'message' => 'Erfolgreich gelöscht (Message-ID 126)'
                            );
                            
                            echo json_encode(array(
                                'status' => 'success',
                                'message' => "  ✅ $name erfolgreich gelöscht (Message-ID 126)",
                                'quota_result' => array('hrs_id' => $hrs_id, 'success' => true)
                            )) . "\n";
                        } else {
                            // Echte Fehler
                            if (isset($bodyData['messageId'])) {
                                $errorMsg .= " [Message-ID: " . $bodyData['messageId'] . "]";
                                if ($bodyData['messageId'] == 122) {
                                    $errorMsg .= " (Reservierungen vorhanden)";
                                }
                            }
                        }
                    }
                }
                
                if ($isActualError) {
                    $errorCount++;
                    $results[] = array(
                        'success' => false,
                        'hrs_id' => $hrs_id,
                        'name' => $name,
                        'error' => $errorMsg
                    );
                    
                    echo json_encode(array(
                        'status' => 'error',
                        'message' => "  ❌ $name: $errorMsg",
                        'quota_result' => array('hrs_id' => $hrs_id, 'success' => false, 'error' => $errorMsg)
                    )) . "\n";
                }
            }
            
        } catch (Exception $e) {
            $errorCount++;
            $results[] = array(
                'success' => false,
                'hrs_id' => $hrs_id,
                'name' => $name,
                'error' => $e->getMessage()
            );
            
            echo json_encode(array(
                'status' => 'error',
                'message' => "  ❌ $name: " . $e->getMessage(),
                'quota_result' => array('hrs_id' => $hrs_id, 'success' => false, 'error' => $e->getMessage())
            )) . "\n";
        }
        
        // Kurze Pause zwischen Löschungen (Höflichkeit gegenüber HRS-API)
        if ($index < count($quotasToDelete) - 1) {
            usleep(500000); // 0.5 Sekunden
        }
    }
    
    // Zusammenfassung
    echo json_encode(array(
        'status' => 'summary',
        'message' => "Löschvorgang abgeschlossen: $successCount erfolgreich, $errorCount Fehler",
        'total_count' => count($quotasToDelete),
        'success_count' => $successCount,
        'error_count' => $errorCount,
        'results' => $results
    )) . "\n";
    
} catch (Exception $e) {
    echo json_encode(array(
        'status' => 'error',
        'message' => $e->getMessage(),
        'total_count' => count($quotasToDelete ?? array()),
        'success_count' => $successCount ?? 0,
        'error_count' => ($errorCount ?? 0) + 1
    )) . "\n";
    
    // Fehler-Log für Debugging
    error_log("HRS_DELETE_MULTIPLE_ERROR: " . $e->getMessage());
}

// Abschluss-Signal
echo json_encode(array(
    'status' => 'complete',
    'message' => 'Löschvorgang komplett abgeschlossen'
)) . "\n";

?>
