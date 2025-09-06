<?php
/**
 * HRS Quota Delete - Einzelne Quota löschen
 * ==========================================
 * 
 * Löscht eine einzelne Quota im HRS-System.
 * Verwendet die bewährte HRSLogin-Klasse (NICHT ÄNDERN!).
 * 
 * Parameter:
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
    // Parameter validieren
    $hrs_id = isset($_POST['hrs_id']) ? (int)$_POST['hrs_id'] : 0;
    $name = isset($_POST['name']) ? $_POST['name'] : 'Unbekannt';
    
    if ($hrs_id <= 0) {
        throw new Exception('Ungültige HRS-ID');
    }
    
    echo json_encode(array(
        'status' => 'info',
        'message' => "Starte Löschung von Quota: $name (HRS-ID: $hrs_id)"
    )) . "\n";
    
    // HRS Login durchführen (BESTEHENDE KLASSE - NICHT ÄNDERN!)
    echo json_encode(array(
        'status' => 'info',
        'message' => 'Verbinde mit HRS-System...'
    )) . "\n";
    
    $hrsLogin = new HRSLogin();
    if (!$hrsLogin->login()) {
        throw new Exception('HRS Login fehlgeschlagen');
    }
    
    echo json_encode(array(
        'status' => 'success',
        'message' => 'HRS-Login erfolgreich'
    )) . "\n";
    
    // DELETE Request vorbereiten (wie in VB.NET + deine Tests)
    $hutId = 675; // Standard Hütten-ID
    $canChangeMode = false;
    $canOverbook = true;  // WICHTIG: true wegen bestehender Reservierungen!
    $allSeries = false;
    
    $queryParams = array(
        'hutId' => $hutId,
        'quotaId' => $hrs_id,
        'canChangeMode' => $canChangeMode ? 'true' : 'false',
        'canOverbook' => $canOverbook ? 'true' : 'false',  // MUSS true sein!
        'allSeries' => $allSeries ? 'true' : 'false'
    );
    
    $queryString = http_build_query($queryParams);
    $deleteUrl = "/api/v1/manage/deleteQuota?$queryString";
    
    echo json_encode(array(
        'status' => 'info',
        'message' => "Lösche Quota: $name..."
    )) . "\n";
    
    // DELETE Request mit bestehender HRSLogin-Klasse
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
    
    // Response analysieren (mit HRS-spezifischen Meldungen)
    if ($httpCode == 200) {
        echo json_encode(array(
            'status' => 'success',
            'message' => "Quota '$name' erfolgreich gelöscht",
            'hrs_id' => $hrs_id,
            'response' => $responseBody
        )) . "\n";
    } else {
        // Detaillierte Fehleranalyse für HRS-spezifische Fehler
        $errorMsg = "HTTP $httpCode";
        
        if (!empty($responseBody)) {
            $bodyData = json_decode($responseBody, true);
            if ($bodyData) {
                if (isset($bodyData['description'])) {
                    $errorMsg .= ": " . $bodyData['description'];
                    
                    // Spezifische HRS-Fehlermeldungen behandeln
                    if (isset($bodyData['messageId'])) {
                        switch ($bodyData['messageId']) {
                            case 122:
                                $errorMsg .= " (Reservierungen vorhanden - canOverbook sollte true sein)";
                                break;
                            case 126:
                                // Das ist eigentlich ein Erfolg
                                echo json_encode(array(
                                    'status' => 'success',
                                    'message' => "Quota '$name' erfolgreich gelöscht",
                                    'hrs_id' => $hrs_id,
                                    'response' => $responseBody
                                )) . "\n";
                                break;
                        }
                    }
                }
                if (isset($bodyData['messageId'])) {
                    $errorMsg .= " [Message-ID: " . $bodyData['messageId'] . "]";
                }
            } else {
                $errorMsg .= ": " . $responseBody;
            }
        }
        
        // Nur als Fehler behandeln, wenn es nicht Message-ID 126 ist (Erfolg)
        if (!($bodyData && isset($bodyData['messageId']) && $bodyData['messageId'] == 126)) {
            throw new Exception("HRS-Fehler: $errorMsg");
        }
    }
    
} catch (Exception $e) {
    echo json_encode(array(
        'status' => 'error',
        'message' => $e->getMessage(),
        'hrs_id' => $hrs_id ?? 0
    )) . "\n";
    
    // Fehler-Log für Debugging
    error_log("HRS_DELETE_ERROR: " . $e->getMessage());
}

// Abschluss-Signal
echo json_encode(array(
    'status' => 'complete',
    'message' => 'Löschvorgang abgeschlossen'
)) . "\n";

?>
