<?php
/**
 * HRS Quota Delete Batch - Mehrere Quotas mit einem Login löschen
 * ===============================================================
 * 
 * Löscht mehrere Quotas im HRS-System mit einem einzigen Login.
 * Arbeitet mit Datenbank-IDs und ermittelt automatisch die hrs_id Werte.
 * 
 * Parameter:
 * - quota_db_ids: JSON-Array mit Datenbank-IDs (nicht hrs_id!)
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
    // Parameter validieren
    $quota_db_ids_json = isset($_POST['quota_db_ids']) ? $_POST['quota_db_ids'] : '';
    
    if (empty($quota_db_ids_json)) {
        throw new Exception('Keine Quota-IDs angegeben');
    }
    
    $quota_db_ids = json_decode($quota_db_ids_json, true);
    if (!is_array($quota_db_ids) || empty($quota_db_ids)) {
        throw new Exception('Ungültige Quota-IDs: ' . $quota_db_ids_json);
    }
    
    echo json_encode(array(
        'status' => 'info',
        'message' => "Starte Batch-Löschung für " . count($quota_db_ids) . " Quotas"
    )) . "\n";
    
    // Datenbank-Verbindung für hrs_id Lookup
    echo json_encode(array(
        'status' => 'info',
        'message' => 'Verbinde mit Datenbank...'
    )) . "\n";
    
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($mysqli->connect_error) {
        throw new Exception('Datenbankverbindung fehlgeschlagen: ' . $mysqli->connect_error);
    }
    
    // hrs_id Werte für alle Datenbank-IDs ermitteln
    $placeholders = str_repeat('?,', count($quota_db_ids) - 1) . '?';
    $sql = "SELECT id, hrs_id, title, date_from, date_to FROM hut_quota WHERE id IN ($placeholders) AND hrs_id IS NOT NULL";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception('SQL-Prepare fehlgeschlagen: ' . $mysqli->error);
    }
    
    $types = str_repeat('i', count($quota_db_ids));
    $stmt->bind_param($types, ...$quota_db_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $quotas_to_delete = array();
    while ($row = $result->fetch_assoc()) {
        $quotas_to_delete[] = array(
            'db_id' => $row['id'],
            'hrs_id' => $row['hrs_id'],
            'name' => $row['title'] ?: 'Unbenannt',
            'datum_von' => $row['date_from'],
            'datum_bis' => $row['date_to']
        );
    }
    
    $stmt->close();
    $mysqli->close();
    
    if (empty($quotas_to_delete)) {
        throw new Exception('Keine gültigen HRS-Quotas gefunden für IDs: ' . implode(', ', $quota_db_ids));
    }
    
    echo json_encode(array(
        'status' => 'info',
        'message' => count($quotas_to_delete) . ' HRS-Quotas gefunden zum Löschen'
    )) . "\n";
    
    // Einmaliger HRS Login für alle Löschvorgänge
    echo json_encode(array(
        'status' => 'info',
        'message' => 'Einmaliger HRS-Login...'
    )) . "\n";
    
    $hrsLogin = new HRSLogin();
    if (!$hrsLogin->login()) {
        throw new Exception('HRS Login fehlgeschlagen');
    }
    
    echo json_encode(array(
        'status' => 'success',
        'message' => 'HRS-Login erfolgreich - starte Löschvorgänge'
    )) . "\n";
    
    // Jede Quota einzeln löschen (mit derselben Session)
    $success_count = 0;
    $error_count = 0;
    $results = array();
    
    foreach ($quotas_to_delete as $quota) {
        echo json_encode(array(
            'status' => 'info',
            'message' => "Lösche Quota: {$quota['name']} (DB-ID: {$quota['db_id']}, HRS-ID: {$quota['hrs_id']})"
        )) . "\n";
        
        try {
            // DELETE Request Parameter
            $hutId = 675;
            $queryParams = array(
                'hutId' => $hutId,
                'quotaId' => $quota['hrs_id'],
                'canChangeMode' => 'false',
                'canOverbook' => 'true',  // WICHTIG: true wegen bestehender Reservierungen!
                'allSeries' => 'false'
            );
            
            $queryString = http_build_query($queryParams);
            $deleteUrl = "/api/v1/manage/deleteQuota?$queryString";
            
            // DELETE Request mit bereits authentifizierter Session
            $deleteHeaders = array(
                'Accept: application/json, text/plain, */*',
                'Origin: https://www.hut-reservation.org',
                'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
                'X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken()
            );
            
            $deleteResponse = $hrsLogin->makeRequest($deleteUrl, 'DELETE', null, $deleteHeaders);
            
            if (!$deleteResponse) {
                throw new Exception("Verbindungsfehler beim Löschen");
            }
            
            $httpCode = $deleteResponse['status'];
            $responseBody = $deleteResponse['body'];
            
            // Response analysieren
            if ($httpCode == 200) {
                $success_count++;
                echo json_encode(array(
                    'status' => 'success',
                    'message' => "✅ Quota '{$quota['name']}' erfolgreich gelöscht"
                )) . "\n";
                
                $results[] = array(
                    'success' => true,
                    'db_id' => $quota['db_id'],
                    'hrs_id' => $quota['hrs_id'],
                    'name' => $quota['name'],
                    'message' => 'Erfolgreich gelöscht'
                );
            } else {
                // Fehleranalyse
                $errorMsg = "HTTP $httpCode";
                $isActuallySuccess = false;
                
                if (!empty($responseBody)) {
                    $bodyData = json_decode($responseBody, true);
                    if ($bodyData) {
                        if (isset($bodyData['messageId']) && $bodyData['messageId'] == 126) {
                            // Das ist eigentlich ein Erfolg
                            $success_count++;
                            $isActuallySuccess = true;
                            echo json_encode(array(
                                'status' => 'success',
                                'message' => "✅ Quota '{$quota['name']}' erfolgreich gelöscht (Message-ID 126)"
                            )) . "\n";
                            
                            $results[] = array(
                                'success' => true,
                                'db_id' => $quota['db_id'],
                                'hrs_id' => $quota['hrs_id'],
                                'name' => $quota['name'],
                                'message' => 'Erfolgreich gelöscht'
                            );
                        } else {
                            if (isset($bodyData['description'])) {
                                $errorMsg .= ": " . $bodyData['description'];
                            }
                            if (isset($bodyData['messageId'])) {
                                $errorMsg .= " [Message-ID: " . $bodyData['messageId'] . "]";
                                if ($bodyData['messageId'] == 122) {
                                    $errorMsg .= " (Reservierungen vorhanden)";
                                }
                            }
                        }
                    }
                }
                
                if (!$isActuallySuccess) {
                    $error_count++;
                    echo json_encode(array(
                        'status' => 'error',
                        'message' => "❌ Fehler bei Quota '{$quota['name']}': $errorMsg"
                    )) . "\n";
                    
                    $results[] = array(
                        'success' => false,
                        'db_id' => $quota['db_id'],
                        'hrs_id' => $quota['hrs_id'],
                        'name' => $quota['name'],
                        'error' => $errorMsg
                    );
                }
            }
            
        } catch (Exception $e) {
            $error_count++;
            echo json_encode(array(
                'status' => 'error',
                'message' => "❌ Exception bei Quota '{$quota['name']}': " . $e->getMessage()
            )) . "\n";
            
            $results[] = array(
                'success' => false,
                'db_id' => $quota['db_id'],
                'hrs_id' => $quota['hrs_id'],
                'name' => $quota['name'],
                'error' => $e->getMessage()
            );
        }
        
        // Kurze Pause zwischen Löschvorgängen
        usleep(500000); // 0.5 Sekunden
    }
    
    // Zusammenfassung
    echo json_encode(array(
        'status' => 'summary',
        'message' => "Batch-Löschung abgeschlossen: $success_count erfolgreich, $error_count Fehler",
        'success_count' => $success_count,
        'error_count' => $error_count,
        'total_count' => count($quotas_to_delete),
        'results' => $results
    )) . "\n";
    
} catch (Exception $e) {
    echo json_encode(array(
        'status' => 'error',
        'message' => $e->getMessage()
    )) . "\n";
    
    // Fehler-Log für Debugging
    error_log("HRS_BATCH_DELETE_ERROR: " . $e->getMessage());
}

// Abschluss-Signal
echo json_encode(array(
    'status' => 'complete',
    'message' => 'Batch-Löschvorgang abgeschlossen'
)) . "\n";

?>
