<?php
/**
 * AV Capacity Range Import - Server-Sent Events (SSE) Version
 * Sendet Echtzeit-Updates während des AV Capacity Updates
 * 
 * Usage: get_av_cap_range_stream.php?hutID=675&von=2024-01-01&bis=2024-01-07
 */

// SSE Headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);

function sendSSE($type, $data = []) {
    $message = array_merge(['type' => $type], $data);
    echo "data: " . json_encode($message) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

if (!isset($_GET['hutID']) || !isset($_GET['von']) || !isset($_GET['bis'])) {
    sendSSE('error', ['message' => 'Missing parameters: hutID, von, bis required']);
    exit;
}

$hutID = intval($_GET['hutID']);
$vonDate = $_GET['von'];
$bisDate = $_GET['bis'];

require_once(__DIR__ . '/../../config.php');

sendSSE('start', ['message' => 'Initialisiere AV Capacity Update...', 'vonDate' => $vonDate, 'bisDate' => $bisDate]);

/**
 * AV Capacity Updater SSE Class
 */
class AVCapacityUpdaterSSE {
    private $mysqli;
    private $hutID;
    
    public function __construct($mysqli, $hutID) {
        $this->mysqli = $mysqli;
        $this->hutID = $hutID;
        sendSSE('log', ['level' => 'info', 'message' => 'AV Capacity Updater initialized']);
    }
    
    public function updateAvailability($vonDate, $bisDate) {
        sendSSE('phase', ['step' => 'avcap', 'name' => 'AV Capacity', 'message' => 'Starte Update...']);
        
        try {
            // Calculate optimal API request dates
            $apiDates = $this->calculateApiRequestDates($vonDate, $bisDate);
            $totalApiCalls = count($apiDates);
            
            sendSSE('log', ['level' => 'info', 'message' => "$totalApiCalls API-Aufrufe nötig"]);
            sendSSE('total', ['count' => $totalApiCalls]);
            
            $allData = [];
            
            // Fetch data from API
            foreach ($apiDates as $index => $dateRange) {
                $percent = round((($index + 1) / $totalApiCalls) * 100);
                
                sendSSE('progress', [
                    'current' => $index + 1,
                    'total' => $totalApiCalls,
                    'percent' => $percent,
                    'dateRange' => $dateRange['von'] . ' - ' . $dateRange['bis']
                ]);
                
                sendSSE('log', ['level' => 'info', 'message' => "API-Aufruf " . ($index + 1) . "/$totalApiCalls: {$dateRange['von']} - {$dateRange['bis']}"]);
                
                $data = $this->fetchAvailabilityFromApi($dateRange['von'], $dateRange['bis']);
                
                if ($data) {
                    $allData = array_merge($allData, $data);
                    sendSSE('log', ['level' => 'success', 'message' => "✓ " . count($data) . " Tage erhalten"]);
                } else {
                    sendSSE('log', ['level' => 'error', 'message' => "✗ API-Fehler bei {$dateRange['von']} - {$dateRange['bis']}"]);
                }
                
                usleep(100000); // 100ms zwischen API-Calls
            }
            
            // Filter to requested range
            $filteredData = $this->filterDataByDateRange($allData, $vonDate, $bisDate);
            $totalDays = count($filteredData);
            
            sendSSE('log', ['level' => 'info', 'message' => "$totalDays Tage nach Filterung"]);
            
            // Save to database
            $savedCount = $this->saveAvailabilityToDatabase($filteredData, $vonDate, $bisDate);
            
            sendSSE('complete', [
                'step' => 'avcap',
                'message' => "AV Capacity Update abgeschlossen: $savedCount Tage gespeichert",
                'totalDays' => $totalDays,
                'savedCount' => $savedCount,
                'apiCalls' => $totalApiCalls
            ]);
            
            return true;
            
        } catch (Exception $e) {
            sendSSE('error', ['message' => 'Exception: ' . $e->getMessage()]);
            return false;
        }
    }
    
    private function calculateApiRequestDates($vonDate, $bisDate) {
        $start = new DateTime($vonDate);
        $end = new DateTime($bisDate);
        $diff = $start->diff($end)->days;
        
        $ranges = [];
        
        if ($diff <= 11) {
            // Single API call
            $ranges[] = ['von' => $vonDate, 'bis' => $bisDate];
        } else {
            // Multiple API calls (11-day chunks)
            $current = clone $start;
            while ($current <= $end) {
                $chunkEnd = clone $current;
                $chunkEnd->add(new DateInterval('P10D'));
                
                if ($chunkEnd > $end) {
                    $chunkEnd = clone $end;
                }
                
                $ranges[] = [
                    'von' => $current->format('Y-m-d'),
                    'bis' => $chunkEnd->format('Y-m-d')
                ];
                
                $current->add(new DateInterval('P11D'));
            }
        }
        
        return $ranges;
    }
    
    private function fetchAvailabilityFromApi($vonDate, $bisDate) {
        // Wichtig: Diese API braucht KEINE Authentifizierung und verwendet nur 'from' Parameter
        // Die API gibt immer mindestens 10-11 Tage zurück
        $apiUrl = "https://www.hut-reservation.org/api/v1/reservation/getHutAvailability?hutId={$this->hutID}&step=WIZARD&from={$vonDate}";
        
        sendSSE('log', ['level' => 'debug', 'message' => "API URL: $apiUrl"]);
        
        // Verwende cURL direkt (keine HRS Login nötig)
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: WCI-Availability-Checker/1.0'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || !empty($curlError)) {
            sendSSE('log', ['level' => 'error', 'message' => 'cURL Fehler: ' . $curlError]);
            return [];
        }
        
        if ($httpCode !== 200) {
            sendSSE('log', ['level' => 'error', 'message' => 'API-Fehler: HTTP ' . $httpCode]);
            return [];
        }
        
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }
    
    private function filterDataByDateRange($data, $vonDate, $bisDate) {
        $start = new DateTime($vonDate);
        $end = new DateTime($bisDate);
        
        return array_filter($data, function($dayData) use ($start, $end) {
            // API gibt Daten im Format: {"date": "2026-02-11T00:00:00Z", "freeBeds": ..., "freeBedsPerCategory": {...}}
            $dateStr = $dayData['date'] ?? null;
            if (!$dateStr) return false;
            
            // Parse ISO date
            $dayDate = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dateStr);
            if (!$dayDate) {
                $dayDate = DateTime::createFromFormat('Y-m-d\TH:i:s', $dateStr);
            }
            if (!$dayDate) return false;
            
            return $dayDate >= $start && $dayDate <= $end;
        });
    }
    
    private function saveAvailabilityToDatabase($data, $vonDate, $bisDate) {
        if (empty($data)) {
            return 0;
        }
        
        sendSSE('log', ['level' => 'info', 'message' => 'Speichere ' . count($data) . ' Tage in Datenbank...']);
        
        $savedCount = 0;
        
        foreach ($data as $index => $dayData) {
            try {
                // Parse date from ISO format: "2026-02-11T00:00:00Z"
                $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dayData['date']);
                if (!$dateObj) {
                    $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $dayData['date']);
                }
                if (!$dateObj) {
                    sendSSE('log', ['level' => 'error', 'message' => "Ungültiges Datumsformat: " . ($dayData['date'] ?? 'null')]);
                    continue;
                }
                
                $datum = $dateObj->format('Y-m-d');
                $free_place = (int)($dayData['freeBeds'] ?? 0);
                $hut_status = $dayData['hutStatus'] ?? 'UNKNOWN';
                
                // Kategorie-spezifische Daten aus freeBedsPerCategory
                $freeBedsPerCategory = $dayData['freeBedsPerCategory'] ?? [];
                $kat_1958 = (int)($freeBedsPerCategory['1958'] ?? 0);   // ML
                $kat_2293 = (int)($freeBedsPerCategory['2293'] ?? 0);  // MBZ
                $kat_2381 = (int)($freeBedsPerCategory['2381'] ?? 0);  // 2BZ
                $kat_6106 = (int)($freeBedsPerCategory['6106'] ?? 0);   // SK
                
                $insertQuery = "INSERT INTO av_belegung (
                    datum, free_place, hut_status, kat_1958, kat_2293, kat_2381, kat_6106
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    free_place = VALUES(free_place),
                    hut_status = VALUES(hut_status),
                    kat_1958 = VALUES(kat_1958),
                    kat_2293 = VALUES(kat_2293),
                    kat_2381 = VALUES(kat_2381),
                    kat_6106 = VALUES(kat_6106)";
                
                $stmt = $this->mysqli->prepare($insertQuery);
                $stmt->bind_param('sisiiii',
                    $datum, $free_place, $hut_status,
                    $kat_1958, $kat_2293, $kat_2381, $kat_6106
                );
                
                if ($stmt->execute()) {
                    $savedCount++;
                    
                    // Log every 10th day or last day
                    if (($index + 1) % 10 == 0 || $index == count($data) - 1) {
                        sendSSE('log', ['level' => 'success', 'message' => "✓ Gespeichert: " . ($index + 1) . "/" . count($data) . " Tage"]);
                    }
                }
                
                $stmt->close();
                
            } catch (Exception $e) {
                sendSSE('log', ['level' => 'error', 'message' => "✗ Fehler bei Tag: " . $e->getMessage()]);
            }
        }
        
        return $savedCount;
    }
}

// === MAIN EXECUTION ===

try {
    // AV Capacity API braucht KEINE Authentifizierung!
    sendSSE('log', ['level' => 'info', 'message' => 'Starte AV Capacity Update (ohne Login)...']);
    
    $updater = new AVCapacityUpdaterSSE($mysqli, $hutID);
    
    if ($updater->updateAvailability($vonDate, $bisDate)) {
        sendSSE('finish', ['message' => 'AV Capacity Update vollständig abgeschlossen!']);
    } else {
        sendSSE('error', ['message' => 'AV Capacity Update mit Fehlern beendet']);
    }
    
} catch (Exception $e) {
    sendSSE('error', ['message' => 'Ausnahme: ' . $e->getMessage()]);
}
?>
