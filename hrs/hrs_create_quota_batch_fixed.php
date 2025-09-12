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
    // JSON-Body lesen
    $input_raw = file_get_contents('php://input');
    $input_data = json_decode($input_raw, true);
    
    if (!$input_data || !isset($input_data['quotas'])) {
        throw new Exception('Keine Quota-Daten angegeben');
    }
    
    $quotas_to_create = $input_data['quotas'];
    if (!is_array($quotas_to_create) || empty($quotas_to_create)) {
        throw new Exception('Ungültige Quota-Daten: ' . json_encode($input_data));
    }
    
    // Sammle alle Log-Nachrichten in einem Array
    $log_messages = [];
    $log_messages[] = "Starte Quota-Erstellung für " . count($quotas_to_create) . " neue Quotas";
    
    // Validiere Quota-Struktur
    foreach ($quotas_to_create as $index => $quota) {
        $required_fields = ['title', 'date_from', 'date_to', 'capacity'];
        foreach ($required_fields as $field) {
            if (!isset($quota[$field])) {
                throw new Exception("Quota #$index: Feld '$field' fehlt");
            }
        }
        
        // Validiere numerische Werte
        if (!is_numeric($quota['capacity']) || $quota['capacity'] < 0) {
            throw new Exception("Quota #$index: Ungültige Kapazität '" . $quota['capacity'] . "'");
        }
        
        // Prüfe auf NaN/Infinity
        if (!is_finite($quota['capacity'])) {
            throw new Exception("Quota #$index: Kapazität ist nicht endlich (" . $quota['capacity'] . ")");
        }
    }
    
    $log_messages[] = 'Quota-Struktur validiert - alle erforderlichen Felder vorhanden';
    
    // Einmaliger HRS Login für alle Erstellungsvorgänge
    $log_messages[] = 'Einmaliger HRS-Login für Quota-Erstellung...';
    
    $hrsLogin = new HRSLogin();
    if (!$hrsLogin->login()) {
        throw new Exception('HRS Login fehlgeschlagen');
    }
    
    $log_messages[] = 'HRS-Login erfolgreich - starte Quota-Erstellung';
    
    // Quotas einzeln erstellen (wie im VB.NET Code)
    $success_count = 0;
    $error_count = 0;
    $results = array();
    
    foreach ($quotas_to_create as $index => $quota) {
        $quota_title = $quota['title'];
        $date_from = $quota['date_from'];
        $date_to_original = $quota['date_to'];
        $capacity_raw = $quota['capacity'];
        
        // Robuste Kapazitäts-Validierung
        if (!is_numeric($capacity_raw) || !is_finite($capacity_raw) || $capacity_raw < 0) {
            $log_messages[] = "FEHLER: Ungültige Kapazität '$capacity_raw' für Quota '$quota_title'";
            $error_count++;
            continue; // Überspringe diese Quota
        }
        
        $capacity = max(0, intval(round($capacity_raw)));
        
        // KORREKTUR: date_to immer +1 Tag von date_from
        $date_to_corrected = date('Y-m-d', strtotime($date_from . ' +1 day'));
        
        $log_messages[] = "Erstelle Quota: $quota_title ($date_from bis $date_to_corrected, Kapazität: $capacity)";
        
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
            
            $url = 'https://www.hut-reservation.org/hut/rest/hutQuota/675';
            
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
                throw new Exception("HTTP-Fehler $http_code: $response");
            }
            
            // Parse Response und prüfe MessageID
            $response_data = json_decode($response, true);
            if ($response_data && isset($response_data['messageId']) && $response_data['messageId'] == 120) {
                $success_count++;
                $log_messages[] = "✅ Quota '$quota_title' erfolgreich erstellt (MessageID: 120)";
                $results[] = array(
                    'title' => $quota_title,
                    'date' => $date_from,
                    'capacity' => $capacity,
                    'status' => 'success',
                    'message' => 'Erfolgreich erstellt'
                );
            } else {
                throw new Exception("Unerwartete Antwort: " . $response);
            }
            
        } catch (Exception $quota_error) {
            $error_count++;
            $error_msg = $quota_error->getMessage();
            $log_messages[] = "❌ Quota '$quota_title' Fehler: $error_msg";
            $results[] = array(
                'title' => $quota_title,
                'date' => $date_from,
                'capacity' => $capacity,
                'status' => 'error',
                'message' => $error_msg
            );
        }
        
        // Kurze Pause zwischen Quotas (wie im VB.NET)
        usleep(500000); // 0.5 Sekunden
    }
    
    // Finale einzige JSON-Antwort
    echo json_encode(array(
        'success' => ($error_count == 0),
        'message' => "Quota-Erstellung abgeschlossen: $success_count erfolgreich, $error_count Fehler",
        'success_count' => $success_count,
        'error_count' => $error_count,
        'total_count' => count($quotas_to_create),
        'results' => $results,
        'log' => $log_messages
    ));
    
} catch (Exception $e) {
    $error_msg = $e->getMessage();
    echo json_encode(array(
        'success' => false,
        'message' => "Kritischer Fehler: $error_msg",
        'error' => $error_msg
    ));
}
?>
