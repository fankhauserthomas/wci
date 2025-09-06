<?php
/**
 * HRS Quota Create Batch - Neue optimierte Quotas im HRS anlegen
 * ==============================================================
 * 
 * Erstellt neue Quotas im HRS-System basierend auf der Optimierung.
 * Verwendet das bewährte Login-System und JSON-Payload wie im VB.NET Code.
 * 
 * Parameter:
 * - quotas_to_create: JSON-Array mit neuen Quota-Daten
 * 
 * @author Nach dem Muster von hrs_del_quota_batch.php und VB.NET UpsertQuotaAsync
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
    $quotas_json = isset($_POST['quotas_to_create']) ? $_POST['quotas_to_create'] : '';
    
    if (empty($quotas_json)) {
        throw new Exception('Keine Quota-Daten angegeben');
    }
    
    $quotas_to_create = json_decode($quotas_json, true);
    if (!is_array($quotas_to_create) || empty($quotas_to_create)) {
        throw new Exception('Ungültige Quota-Daten: ' . $quotas_json);
    }
    
    echo json_encode(array(
        'status' => 'info',
        'message' => "Starte Quota-Erstellung für " . count($quotas_to_create) . " neue Quotas"
    )) . "\n";
    
    // Validiere Quota-Struktur
    foreach ($quotas_to_create as $index => $quota) {
        $required_fields = ['title', 'date_from', 'date_to', 'capacity'];
        foreach ($required_fields as $field) {
            if (!isset($quota[$field])) {
                throw new Exception("Quota #$index: Feld '$field' fehlt");
            }
        }
    }
    
    echo json_encode(array(
        'status' => 'info',
        'message' => 'Quota-Struktur validiert - alle erforderlichen Felder vorhanden'
    )) . "\n";
    
    // Einmaliger HRS Login für alle Erstellungsvorgänge
    echo json_encode(array(
        'status' => 'info',
        'message' => 'Einmaliger HRS-Login für Quota-Erstellung...'
    )) . "\n";
    
    $hrsLogin = new HRSLogin();
    if (!$hrsLogin->login()) {
        throw new Exception('HRS Login fehlgeschlagen');
    }
    
    echo json_encode(array(
        'status' => 'success',
        'message' => 'HRS-Login erfolgreich - starte Quota-Erstellung'
    )) . "\n";
    
    // Quotas einzeln erstellen (wie im VB.NET Code)
    $success_count = 0;
    $error_count = 0;
    $results = array();
    
    foreach ($quotas_to_create as $index => $quota) {
        $quota_title = $quota['title'];
        $date_from = $quota['date_from'];
        $date_to_original = $quota['date_to'];
        $capacity = intval($quota['capacity']);
        
        // KORREKTUR: date_to immer +1 Tag von date_from
        $date_to_corrected = date('Y-m-d', strtotime($date_from . ' +1 day'));
        
        echo json_encode(array(
            'status' => 'info',
            'message' => "Erstelle Quota: $quota_title ($date_from bis $date_to_corrected, Kapazität: $capacity)"
        )) . "\n";
        
        try {
            // Payload bauen (exakt wie im VB.NET Code)
            $payload = array(
                'id' => 0, // 0 für neue Quotas
                'title' => $quota_title,
                'reservationMode' => 'SERVICED',
                'isRecurring' => null,
                'capacity' => 0,
                'languagesDataDTOs' => array(
                    array('language' => 'DE_DE', 'description' => ''),
                    array('language' => 'EN', 'description' => '')
                ),
                'hutBedCategoryDTOs' => array(
                    array('categoryId' => 1958, 'totalBeds' => $capacity), // Lager
                    array('categoryId' => 2293, 'totalBeds' => 0),         // Betten
                    array('categoryId' => 2381, 'totalBeds' => 0),         // DZ
                    array('categoryId' => 6106, 'totalBeds' => 0)          // Sonder
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
                'dateFrom' => date('d.m.Y', strtotime($date_from)),  // 19.09.2025
                'dateTo' => date('d.m.Y', strtotime($date_to_corrected)), // 20.09.2025
                'canOverbook' => true,
                'canChangeMode' => false,
                'allSeries' => false
            );
            
            $json_payload = json_encode($payload);
            
            // HRS API Call - Quota erstellen
            $hut_id = 675; // Franzsenhütte HUT-ID
            $url = "https://www.hut-reservation.org/api/v1/manage/hutQuota/$hut_id";
            
            // DEBUG: Payload und URL ausgeben
            echo json_encode(array(
                'status' => 'info',
                'message' => "DEBUG: HRS-URL für '$quota_title': $url"
            )) . "\n";
            
            echo json_encode(array(
                'status' => 'info',
                'message' => "DEBUG: Kompletter JSON-Payload für '$quota_title': $json_payload"
            )) . "\n";
            
            // Cookie-Header aus HRSLogin-Cookies erstellen
            $cookies = $hrsLogin->getCookies();
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
                'X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken(),
                'Cookie: ' . $cookie_header
            );
            
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
                throw new Exception("CURL-Fehler: $curl_error");
            }
            
            if ($http_code !== 200) {
                // Detaillierte HTTP-Fehler ausgeben
                echo json_encode(array(
                    'status' => 'error',
                    'message' => "HTTP-Fehler $http_code bei Quota '$quota_title': $response"
                )) . "\n";
                throw new Exception("HTTP-Fehler $http_code: $response");
            }
            
            // Response parsen und detailliert loggen
            echo json_encode(array(
                'status' => 'info',
                'message' => "DEBUG: HTTP-$http_code für '$quota_title' - Response: $response"
            )) . "\n";
            
            $response_data = json_decode($response, true);
            if (!$response_data) {
                throw new Exception("Ungültige JSON-Response: $response");
            }
            
            // Erfolg prüfen (wie bei Delete-Script)
            if (isset($response_data['messageId'])) {
                $message_id = $response_data['messageId'];
                $description = $response_data['description'] ?? 'Unbekannt';
                
                echo json_encode(array(
                    'status' => 'info',
                    'message' => "HRS MessageID für '$quota_title': $message_id ($description)"
                )) . "\n";
                
                if ($message_id == 200 || $message_id == 201 || $message_id == 120) {
                    // Erfolgreich erstellt (120 = "Quota successfully saved")
                    $success_count++;
                    $results[] = array(
                        'success' => true,
                        'title' => $quota_title,
                        'date_from' => $date_from,
                        'date_to' => $date_to_corrected, // Verwende korrigierte date_to
                        'capacity' => $capacity,
                        'message' => "Erfolgreich erstellt (MessageID: $message_id)",
                        'hrs_response' => $response_data
                    );
                    
                    echo json_encode(array(
                        'status' => 'success',
                        'message' => "✅ Quota '$quota_title' erfolgreich erstellt (MessageID: $message_id)"
                    )) . "\n";
                    
                } else {
                    // HRS-Fehler mit MessageID
                    $error_msg = "HRS-Fehler MessageID $message_id: $description";
                    echo json_encode(array(
                        'status' => 'error',
                        'message' => "❌ $error_msg für Quota '$quota_title'"
                    )) . "\n";
                    throw new Exception($error_msg);
                }
            } else {
                // Keine MessageID in Response
                $error_msg = "Unerwartete Response-Struktur (keine MessageID): $response";
                echo json_encode(array(
                    'status' => 'error',
                    'message' => "❌ $error_msg für Quota '$quota_title'"
                )) . "\n";
                throw new Exception($error_msg);
            }
            
        } catch (Exception $e) {
            $error_count++;
            $error_msg = $e->getMessage();
            
            $results[] = array(
                'success' => false,
                'title' => $quota_title,
                'date_from' => $date_from,
                'date_to' => $date_to_corrected ?? $date_to_original, // Verwende korrigierte oder Original
                'capacity' => $capacity,
                'message' => $error_msg
            );
            
            echo json_encode(array(
                'status' => 'error',
                'message' => "❌ Fehler bei Quota '$quota_title': $error_msg"
            )) . "\n";
        }
        
        // Kurze Pause zwischen Quotas (wie im VB.NET)
        usleep(500000); // 0.5 Sekunden
    }
    
    // Zusammenfassung
    echo json_encode(array(
        'status' => 'summary',
        'message' => "Quota-Erstellung abgeschlossen: $success_count erfolgreich, $error_count Fehler",
        'success_count' => $success_count,
        'error_count' => $error_count,
        'total_count' => count($quotas_to_create),
        'results' => $results
    )) . "\n";
    
} catch (Exception $e) {
    $error_msg = $e->getMessage();
    echo json_encode(array(
        'status' => 'error',
        'message' => "Kritischer Fehler: $error_msg"
    )) . "\n";
} finally {
    echo json_encode(array(
        'status' => 'complete',
        'message' => 'Quota-Erstellungsvorgang abgeschlossen'
    )) . "\n";
}
?>
