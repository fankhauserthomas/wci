<?php
/**
 * HRS Reservations Import - Server-Sent Events (SSE) Version
 * Sendet Echtzeit-Updates während des Reservierungs-Imports
 * 
 * Usage: hrs_imp_res_stream.php?from=2024-01-01&to=2024-01-07
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

if (!isset($_GET['from']) || !isset($_GET['to'])) {
    sendSSE('error', ['message' => 'Missing parameters: from and to are required']);
    exit;
}

$dateFrom = $_GET['from'];
$dateTo = $_GET['to'];

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = DateTime::createFromFormat('Y-m-d', $dateFrom)->format('d.m.Y');
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = DateTime::createFromFormat('Y-m-d', $dateTo)->format('d.m.Y');
}

require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/hrs_login.php');
require_once(__DIR__ . '/webimp_transfer.php');

sendSSE('start', ['message' => 'Initialisiere Reservierungs-Import...', 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]);

/**
 * HRS Reservation Importer SSE Class
 */
class HRSReservationImporterSSE {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675;
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
        
                // Set charset für utf8mb3_general_ci Kompatibilität
        if (!$this->mysqli->set_charset('utf8')) {
            throw new Exception('Failed to set charset');
        }
        
        // Set SQL mode to handle invalid dates gracefully
        $this->mysqli->query("SET sql_mode = 'ALLOW_INVALID_DATES'");
        
        sendSSE('log', ['level' => 'info', 'message' => 'MySQL charset set to utf8 (utf8mb3_general_ci compatible)']);
        
        sendSSE('log', ['level' => 'info', 'message' => 'HRS Reservation Importer initialized']);
    }
    
    /**
     * Sanitizes UTF-8 strings to be compatible with utf8mb3 collation
     * Removes 4-byte UTF-8 characters (emojis, etc.) that cause collation conflicts
     */
    private function sanitizeUtf8String($string) {
        if (empty($string)) return $string;
        
        // Remove 4-byte UTF-8 characters (emojis, etc.)
        $sanitized = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $string);
        
        // Ensure proper encoding
        if (!mb_check_encoding($sanitized, 'UTF-8')) {
            $sanitized = mb_convert_encoding($sanitized, 'UTF-8', 'UTF-8');
        }
        
        return trim($sanitized);
    }
    
    /**
     * Count records in AV-Res-webImp table
     */
    private function countWebimpRecords() {
        $result = $this->mysqli->query("SELECT COUNT(*) as count FROM `AV-Res-webImp`");
        return $result ? (int)$result->fetch_assoc()['count'] : 0;
    }
    
    /**
     * Count unique records in AV-Res-webImp table (by av_id)
     */
    private function countUniqueWebimpRecords() {
        $result = $this->mysqli->query("SELECT COUNT(DISTINCT av_id) as count FROM `AV-Res-webImp`");
        return $result ? (int)$result->fetch_assoc()['count'] : 0;
    }
    
    /**
     * Count records in AV-Res production table
     */
    private function countProductionRecords() {
        $result = $this->mysqli->query("SELECT COUNT(*) as count FROM `AV-Res`");
        return $result ? (int)$result->fetch_assoc()['count'] : 0;
    }
    
    public function importReservations($dateFrom, $dateTo) {
        sendSSE('phase', ['step' => 'res', 'name' => 'Reservierungen', 'message' => 'Starte Import...']);
        
        try {
            // Schritt 1: Reservierungen von HRS abrufen
            sendSSE('log', ['level' => 'info', 'message' => 'Rufe Reservierungen von HRS ab...']);
            $reservations = $this->fetchAllReservations($dateFrom, $dateTo);

            if ($reservations === false) {
                sendSSE('error', ['message' => 'Keine Reservierungen von HRS erhalten']);
                return false;
            }

            if (!is_array($reservations)) {
                sendSSE('error', ['message' => 'Ungültiges Reservierungsformat von HRS erhalten']);
                return false;
            }
            
            $totalRes = count($reservations);
            sendSSE('total', ['count' => $totalRes]);

            if ($totalRes === 0) {
                sendSSE('log', ['level' => 'info', 'message' => 'Keine Reservierungen im gewählten Zeitraum gefunden (OK)']);
            } else {
                sendSSE('log', ['level' => 'info', 'message' => "$totalRes Reservierungen erhalten"]);
            }

            // Schritt 2: Reservierungen importieren
            $importedCount = 0;
            if ($totalRes > 0) {
                foreach ($reservations as $index => $res) {
                    $percent = round((($index + 1) / $totalRes) * 100);
                    
                    sendSSE('progress', [
                        'current' => $index + 1,
                        'total' => $totalRes,
                        'percent' => $percent,
                        'res_id' => $res['id'] ?? 'unknown'
                    ]);
                    
                    if ($this->processReservation($res)) {
                        $importedCount++;
                        sendSSE('log', ['level' => 'success', 'message' => "✓ Reservierung " . ($res['id'] ?? 'unknown') . " importiert"]);
                    } else {
                        sendSSE('log', ['level' => 'error', 'message' => "✗ Fehler bei Reservierung " . ($res['id'] ?? 'unknown')]);
                    }
                    
                    usleep(20000); // 20ms
                }
            }
            
            $completionMessage = $totalRes > 0
                ? "Import abgeschlossen: $importedCount von $totalRes Reservierungen importiert"
                : 'Keine Reservierungen im gewählten Zeitraum zu importieren';

            sendSSE('complete', [
                'step' => 'res',
                'message' => $completionMessage,
                'totalProcessed' => $totalRes,
                'totalInserted' => $importedCount
            ]);
            
            // Schritt 3: WebImp-Daten in Production-Tabelle übertragen
            sendSSE('phase', ['step' => 'transfer', 'name' => 'Transfer', 'message' => 'Übertrage in Production-Tabelle...']);
            sendSSE('log', ['level' => 'info', 'message' => 'Starte Transfer von AV-Res-webImp nach AV-Res...']);
            
            // Debug: Count records before transfer
            $webimpCount = $this->countWebimpRecords();
            $webimpUniqueCount = $this->countUniqueWebimpRecords();
            $productionCountBefore = $this->countProductionRecords();
            sendSSE('log', ['level' => 'debug', 'message' => 
                "Vor Transfer: WebImp=$webimpCount (unique: $webimpUniqueCount), Production=$productionCountBefore"]);
            
            if ($this->transferWebImpToProduction()) {
                // Debug: Count records after transfer
                $productionCountAfter = $this->countProductionRecords();
                $transferred = $productionCountAfter - $productionCountBefore;
                sendSSE('log', ['level' => 'debug', 'message' => 
                    "Nach Transfer: Production=$productionCountAfter (Transfer-Delta: $transferred)"]);
                
                sendSSE('log', ['level' => 'success', 'message' => '✓ Transfer erfolgreich abgeschlossen']);
            } else {
                sendSSE('log', ['level' => 'warning', 'message' => '⚠ Transfer mit Warnungen abgeschlossen']);
            }
            
            return true;
            
        } catch (Exception $e) {
            sendSSE('error', ['message' => 'Exception: ' . $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Überträgt Daten von AV-Res-webImp nach AV-Res
     * Nutzt die bewährte Import-Logik aus import_webimp.php
     */
    private function transferWebImpToProduction() {
        // Clean up duplicates before transfer
        $this->cleanupDuplicates();
        
        // Analyze skipped records before transfer
        $this->analyzeSkippedRecords();
        
        $result = transferWebImpToProduction($this->mysqli, function ($level, $message) {
            sendSSE('log', ['level' => $level, 'message' => $message]);
        });

        if ($result['success']) {
            $stats = $result['stats'];
            $totalTransferred = $stats['inserted'] + $stats['updated'] + $stats['unchanged'];
            $webimpCount = $this->countWebimpRecords();
            $skipped = max(0, $webimpCount - $totalTransferred); // Prevent negative values
            
            // Enhanced debugging for the 378 vs 357 discrepancy
            sendSSE('log', ['level' => 'debug', 'message' => "🔍 Transfer Math: WebImp=$webimpCount, Transferred=$totalTransferred (neu:{$stats['inserted']}, upd:{$stats['updated']}, unv:{$stats['unchanged']})"]);
            
            sendSSE('log', [
                'level' => 'info',
                'message' => sprintf(
                    'Transfer-Statistik: %d neu, %d aktualisiert, %d unverändert (Übersprungen: %d)',
                    $stats['inserted'],
                    $stats['updated'],
                    $stats['unchanged'],
                    $skipped
                )
            ]);
            
            // Check for mathematical discrepancy
            $mathDiscrepancy = $webimpCount - $totalTransferred;
            if ($mathDiscrepancy != $skipped) {
                sendSSE('log', ['level' => 'warning', 'message' => "⚠ Mathematik-Problem: WebImp($webimpCount) - Transferred($totalTransferred) = $mathDiscrepancy, aber Skipped=$skipped"]);
            }
            
            if ($skipped > 0) {
                sendSSE('log', ['level' => 'warning', 'message' => "⚠ $skipped Reservierungen wurden übersprungen"]);
                $this->logSkippedRecords();
            } elseif ($mathDiscrepancy > 0) {
                // Even if skipped = 0, check if there's a real discrepancy
                sendSSE('log', ['level' => 'warning', 'message' => "⚠ $mathDiscrepancy Reservierungen nicht transferiert (versteckte Filter?)"]);
                $this->logSkippedRecords();
            }
            
            // Additional check: Are some of the "unchanged" records actually new?
            if ($stats['unchanged'] > 0 && $stats['inserted'] == 0) {
                sendSSE('log', ['level' => 'info', 'message' => "🤔 Alle {$stats['unchanged']} Reservierungen als 'unverändert' klassifiziert - prüfe auf bereits existierende Records"]);
                $this->analyzeUnchangedRecords();
            }
            
            return true;
        }

        $details = $result['raw'] ?? 'unbekannter Fehler';
        if (is_array($details)) {
            $details = json_encode($details);
        }
        sendSSE('log', ['level' => 'error', 'message' => 'Transfer fehlgeschlagen: ' . $details]);

        return false;
    }
    
    /**
     * Clean up duplicate records in WebImp table
     */
    private function cleanupDuplicates() {
        // Remove duplicates, keeping the latest timestamp
        $deleteDuplicates = "
            DELETE w1 FROM `AV-Res-webImp` w1
            INNER JOIN `AV-Res-webImp` w2 
            WHERE w1.av_id = w2.av_id 
            AND w1.timestamp < w2.timestamp
        ";
        
        $result = $this->mysqli->query($deleteDuplicates);
        if ($result) {
            $deletedCount = $this->mysqli->affected_rows;
            if ($deletedCount > 0) {
                sendSSE('log', ['level' => 'info', 'message' => "🧹 $deletedCount Duplikate aus WebImp entfernt"]);
            }
        }
        
        // Update counts after cleanup
        $webimpCount = $this->countWebimpRecords();
        $uniqueCount = $this->countUniqueWebimpRecords();
        sendSSE('log', ['level' => 'debug', 'message' => "Nach Cleanup: WebImp=$webimpCount (unique: $uniqueCount)"]);
    }

    /**
     * Analyze "unchanged" records to see if they were actually new
     */
    private function analyzeUnchangedRecords() {
        // Check recent WebImp records that match Production records
        $unchangedQuery = "
            SELECT w.av_id, w.timestamp, p.timestamp as prod_timestamp
            FROM `AV-Res-webImp` w
            INNER JOIN `AV-Res` p ON w.av_id = p.av_id
            WHERE w.timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ORDER BY w.av_id
            LIMIT 10
        ";
        
        $result = $this->mysqli->query($unchangedQuery);
        if ($result && $result->num_rows > 0) {
            sendSSE('log', ['level' => 'debug', 'message' => 'Beispiele "unveränderte" Records (WebImp vs Production):']);
            while ($row = $result->fetch_assoc()) {
                $webimpTime = $row['timestamp'] ?? 'NULL';
                $prodTime = $row['prod_timestamp'] ?? 'NULL';
                sendSSE('log', ['level' => 'debug', 'message' => 
                    "AV-ID {$row['av_id']}: WebImp=$webimpTime, Prod=$prodTime"]);
            }
        }
        
        // Count how many WebImp records from this session already exist in Production
        $existingQuery = "
            SELECT COUNT(*) as count 
            FROM `AV-Res-webImp` w
            INNER JOIN `AV-Res` p ON w.av_id = p.av_id
            WHERE w.timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ";
        
        $existingResult = $this->mysqli->query($existingQuery);
        if ($existingResult) {
            $existingCount = $existingResult->fetch_assoc()['count'];
            sendSSE('log', ['level' => 'info', 'message' => "📊 $existingCount von {$this->countWebimpRecords()} WebImp-Records existieren bereits in Production"]);
        }
    }

    /**
     * Analyze which records might be skipped during transfer
     */
    private function analyzeSkippedRecords() {
        // Check for records with invalid dates
        $invalidDates = $this->mysqli->query("
            SELECT COUNT(*) as count FROM `AV-Res-webImp` 
            WHERE anreise = '0000-00-00' OR abreise = '0000-00-00' 
               OR anreise IS NULL OR abreise IS NULL
               OR anreise > abreise
        ");
        
        if ($invalidDates && $invalidDates->fetch_assoc()['count'] > 0) {
            $count = $invalidDates->fetch_assoc()['count'];
            sendSSE('log', ['level' => 'warning', 'message' => "⚠ $count Reservierungen mit ungültigen Daten gefunden"]);
        }
        
        // Check for records with zero capacity
        $zeroCapacity = $this->mysqli->query("
            SELECT COUNT(*) as count FROM `AV-Res-webImp` 
            WHERE (lager + betten + dz + sonder) = 0
        ");
        
        if ($zeroCapacity && $zeroCapacity->fetch_assoc()['count'] > 0) {
            $count = $zeroCapacity->fetch_assoc()['count'];
            sendSSE('log', ['level' => 'info', 'message' => "ℹ $count Reservierungen ohne Kapazität (möglicherweise storniert)"]);
        }
    }
    
    /**
     * Log details of skipped records
     */
    private function logSkippedRecords() {
        // Find records that exist in webImp but not in production
        $skippedQuery = "
            SELECT w.av_id, w.anreise, w.abreise, w.lager, w.betten, w.dz, w.sonder, w.vorgang, w.timestamp
            FROM `AV-Res-webImp` w
            LEFT JOIN `AV-Res` p ON w.av_id = p.av_id
            WHERE p.av_id IS NULL
            ORDER BY w.av_id
            LIMIT 25
        ";
        
        $result = $this->mysqli->query($skippedQuery);
        if ($result && $result->num_rows > 0) {
            sendSSE('log', ['level' => 'debug', 'message' => 'Nicht transferierte Reservierungen:']);
            $count = 0;
            while ($row = $result->fetch_assoc()) {
                $count++;
                $capacity = $row['lager'] + $row['betten'] + $row['dz'] + $row['sonder'];
                $anreise = $row['anreise'] ?: 'NULL';
                $abreise = $row['abreise'] ?: 'NULL';
                sendSSE('log', ['level' => 'debug', 'message' => 
                    "$count. AV-ID {$row['av_id']}: $anreise → $abreise, Kap: $capacity, Status: {$row['vorgang']}"]);
            }
        }
        
        // Also check for records with invalid data
        $invalidQuery = "
            SELECT COUNT(*) as count FROM `AV-Res-webImp` 
            WHERE anreise IS NULL OR abreise IS NULL OR anreise = '0000-00-00' OR abreise = '0000-00-00'
        ";
        $invalidResult = $this->mysqli->query($invalidQuery);
        if ($invalidResult) {
            $invalidCount = $invalidResult->fetch_assoc()['count'];
            if ($invalidCount > 0) {
                sendSSE('log', ['level' => 'warning', 'message' => "⚠ $invalidCount Reservierungen mit ungültigen Daten gefunden"]);
            }
        }
        
        // Check date range restrictions
        $dateRangeQuery = "
            SELECT COUNT(*) as count FROM `AV-Res-webImp` 
            WHERE anreise < '2025-01-01' OR anreise > '2025-12-31'
        ";
        $dateRangeResult = $this->mysqli->query($dateRangeQuery);
        if ($dateRangeResult) {
            $dateRangeCount = $dateRangeResult->fetch_assoc()['count'];
            if ($dateRangeCount > 0) {
                sendSSE('log', ['level' => 'info', 'message' => "📅 $dateRangeCount Reservierungen außerhalb 2025 (möglicherweise gefiltert)"]);
            }
        }
    }
    
    /**
     * Normalisiert String-Werte
     */
    private function normalizeString($value) {
        if ($value === null || $value === '') return null;
        $trimmed = trim($value);
        return ($trimmed === '' || $trimmed === '-') ? null : $trimmed;
    }
    
    private function fetchAllReservations($dateFrom, $dateTo) {
        $allReservations = [];
        $page = 0;
        $size = 1000;
        
        do {
            $params = [
                'hutId' => $this->hutId,
                'sortList' => 'ArrivalDate',
                'sortOrder' => 'ASC',
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'page' => $page,
                'size' => $size
            ];
            
            $url = '/api/v1/manage/reservation/list?' . http_build_query($params);
            $headers = [
                'Origin: https://www.hut-reservation.org',
                'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
                'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()
            ];
            $response = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
            
            if (!$response || $response['status'] != 200) {
                sendSSE('log', ['level' => 'error', 'message' => "API-Fehler bei Seite $page: HTTP " . ($response['status'] ?? 'unknown')]);
                if (empty($allReservations)) {
                    return false;
                }
                break;
            }
            
            $data = json_decode($response['body'], true);
            
            // Debug: Log pagination info for first page
            if ($page === 0) {
                sendSSE('log', ['level' => 'debug', 'message' => 'API Response Keys: ' . implode(', ', array_keys($data ?? []))]);
                if (isset($data['page'])) {
                    $pageInfo = $data['page'];
                    sendSSE('log', ['level' => 'info', 'message' => 
                        "Pagination: Seite {$pageInfo['number']}/{$pageInfo['totalPages']}, " .
                        "Einträge: {$pageInfo['size']}, Gesamt: {$pageInfo['totalElements']}"]);
                }
            }
            
            // Wichtig: HRS API gibt reservationsDataModelDTOList zurück, nicht reservationResponseDTOList!
            if (!isset($data['_embedded']['reservationsDataModelDTOList'])) {
                // Fallback zu anderen möglichen Strukturen
                if (isset($data['content'])) {
                    $pageReservations = $data['content'];
                } elseif (isset($data['_embedded']['reservationResponseDTOList'])) {
                    $pageReservations = $data['_embedded']['reservationResponseDTOList'];
                } elseif (is_array($data) && isset($data[0])) {
                    $pageReservations = $data;
                } else {
                    sendSSE('log', ['level' => 'warning', 'message' => 'Keine Reservierungen gefunden. Response Keys: ' . implode(', ', array_keys($data['_embedded'] ?? []))]);
                    break;
                }
            } else {
                $pageReservations = $data['_embedded']['reservationsDataModelDTOList'];
            }
            
            if (!is_array($pageReservations) || count($pageReservations) === 0) {
                break;
            }
            
            $allReservations = array_merge($allReservations, $pageReservations);
            
            sendSSE('log', ['level' => 'info', 'message' => "Seite " . ($page + 1) . ": " . count($pageReservations) . " Reservierungen"]);
            
            // Korrekte Pagination-Prüfung basierend auf page-Struktur
            $pageInfo = $data['page'] ?? null;
            $currentPage = $pageInfo['number'] ?? $page;
            $totalPages = $pageInfo['totalPages'] ?? 1;
            
            // Fallback zu 'last' wenn page-Info nicht verfügbar
            $isLastByFlag = $data['last'] ?? true;
            $isLastByPage = ($currentPage + 1) >= $totalPages;
            
            $isLast = $pageInfo ? $isLastByPage : $isLastByFlag;
            
            $page++;
            
        } while (!$isLast && count($pageReservations) > 0);
        
        return $allReservations;
    }
    
    private function processReservation($reservation) {
        try {
            // Basis-Daten aus header extrahieren
            $header = $reservation['header'] ?? [];
            $body = $reservation['body'] ?? [];
            
            // av_id aus header.reservationNumber extrahieren
            $av_id = $header['reservationNumber'] ?? null;
            
            if (!$av_id) {
                sendSSE('log', ['level' => 'error', 'message' => 'Keine reservationNumber gefunden']);
                return false;
            }
            
            $av_id = (int)$av_id;
            
            // Gast-Name aufteilen
            $guestName = $this->sanitizeUtf8String($header['guestName'] ?? '');
            $nameParts = explode(' ', $guestName, 2);
            $nachname = $this->sanitizeUtf8String($nameParts[0] ?? '');
            $vorname = $this->sanitizeUtf8String($nameParts[1] ?? '');
            
            // Kategorie-Zuordnung verarbeiten
            $lager = 0; $betten = 0; $dz = 0; $sonder = 0;
            
            if (isset($header['assignment']) && is_array($header['assignment'])) {
                foreach ($header['assignment'] as $assignment) {
                    if (isset($assignment['categoryDTOs'])) {
                        foreach ($assignment['categoryDTOs'] as $category) {
                            $categoryId = $assignment['categoryId'] ?? 0;
                            $amount = $assignment['bedOccupied'] ?? 0;
                            
                            switch ($categoryId) {
                                case 1958: $lager = $amount; break;   // ML
                                case 2293: $betten = $amount; break;  // MBZ
                                case 2381: $dz = $amount; break;      // 2BZ
                                case 6106: $sonder = $amount; break;  // SK
                            }
                        }
                    }
                }
            }
            
            // Weitere Daten extrahieren
            $anreise = date('Y-m-d', strtotime($header['arrivalDate']));
            $abreise = date('Y-m-d', strtotime($header['departureDate']));
            $hp = ($header['halfPension'] ?? false) ? 1 : 0;
            $vegi = $header['numberOfVegetarians'] ?? 0;
            $gruppe = $this->sanitizeUtf8String($header['groupName'] ?? '');
            $vorgang = $this->sanitizeUtf8String($header['status'] ?? 'UNKNOWN');
            
            // Kontakt-Daten und Kommentare aus body.leftList extrahieren
            $handy = '';
            $bem_av = '';
            $email = $this->sanitizeUtf8String($reservation['guestEmail'] ?? '');
            $email_date = null;
            
            if (isset($body['leftList']) && is_array($body['leftList'])) {
                foreach ($body['leftList'] as $item) {
                    if (isset($item['label'])) {
                        if ($item['label'] === 'configureReservationListPage.phone') {
                            $handy = $this->sanitizeUtf8String($item['value'] ?? '');
                        } elseif ($item['label'] === 'configureReservationListPage.comments') {
                            $bem_av = $this->sanitizeUtf8String($item['value'] ?? '');
                        } elseif ($item['label'] === 'configureReservationListPage.reservationDate') {
                            $reservationDateStr = $item['value'] ?? '';
                            if ($reservationDateStr) {
                                try {
                                    $email_date = DateTime::createFromFormat('d.m.Y H:i:s', $reservationDateStr)->format('Y-m-d H:i:s');
                                } catch (Exception $e) {
                                    $email_date = date('Y-m-d H:i:s');
                                }
                            }
                        }
                    }
                }
            }
            
            if (!$email_date) {
                $email_date = date('Y-m-d H:i:s');
            }
            
            $timestamp = date('Y-m-d H:i:s');
            
            // Fix invalid dates that cause MySQL errors
            if (empty($anreise) || $anreise === '0000-00-00' || $anreise === '0000-00-00 00:00:00') {
                $anreise = NULL;
            }
            if (empty($abreise) || $abreise === '0000-00-00' || $abreise === '0000-00-00 00:00:00') {
                $abreise = NULL;
            }

            // Check if record already exists (prevent duplicates)
            $checkSql = "SELECT COUNT(*) as count FROM `AV-Res-webImp` WHERE av_id = ?";
            $checkStmt = $this->mysqli->prepare($checkSql);
            $checkStmt->bind_param("i", $av_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $exists = $result->fetch_assoc()['count'] > 0;
            $checkStmt->close();
            
            // Debug: Log duplicate detection
            if ($exists) {
                sendSSE('log', ['level' => 'debug', 'message' => "📋 Reservierung $av_id bereits vorhanden - Update"]);
            } else {
                sendSSE('log', ['level' => 'debug', 'message' => "➕ Reservierung $av_id neu - Insert"]);
            }

            if ($exists) {
                // UPDATE existing record to prevent duplicates
                $updateSql = "UPDATE `AV-Res-webImp` SET 
                    anreise = ?, abreise = ?, lager = ?, betten = ?, dz = ?, sonder = ?, hp = ?, vegi = ?,
                    gruppe = ?, nachname = ?, vorname = ?, handy = ?, email = ?, vorgang = ?, 
                    email_date = ?, bem_av = ?, timestamp = ?
                    WHERE av_id = ?";
                
                $insertStmt = $this->mysqli->prepare($updateSql);
                if (!$insertStmt) {
                    sendSSE('log', ['level' => 'error', 'message' => 'Update prepare failed: ' . $this->mysqli->error]);
                    return false;
                }
                
                $insertStmt->bind_param("ssiiiiiisssssssssi", 
                    $anreise, $abreise, $lager, $betten, $dz, $sonder, $hp, $vegi,
                    $gruppe, $nachname, $vorname, $handy, $email, $vorgang, $email_date, $bem_av, $timestamp, $av_id
                );
                
            } else {
                // INSERT new record
                $insertSql = "INSERT INTO `AV-Res-webImp` (
                    av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
                    gruppe, nachname, vorname, handy, email, vorgang, email_date, bem_av, timestamp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $insertStmt = $this->mysqli->prepare($insertSql);
                if (!$insertStmt) {
                    sendSSE('log', ['level' => 'error', 'message' => 'Insert prepare failed: ' . $this->mysqli->error]);
                    return false;
                }
                
                $insertStmt->bind_param("issiiiiiisssssssss", 
                    $av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder, $hp, $vegi,
                    $gruppe, $nachname, $vorname, $handy, $email, $vorgang, $email_date, $bem_av, $timestamp
                );
            }
            
            if ($insertStmt->execute()) {
                $insertStmt->close();
                return true;
            } else {
                sendSSE('log', ['level' => 'error', 'message' => 'Execute failed: ' . $this->mysqli->error]);
                $insertStmt->close();
                return false;
            }
            
        } catch (Exception $e) {
            sendSSE('log', ['level' => 'error', 'message' => 'Exception: ' . $e->getMessage()]);
            return false;
        }
    }
}

// === MAIN EXECUTION ===

try {
    $hrsLogin = new HRSLogin();
    sendSSE('log', ['level' => 'info', 'message' => 'Verbinde mit HRS...']);
    
    if (!$hrsLogin->login()) {
        sendSSE('error', ['message' => 'HRS Login fehlgeschlagen']);
        exit;
    }
    
    sendSSE('log', ['level' => 'success', 'message' => 'HRS Login erfolgreich']);
    
    $importer = new HRSReservationImporterSSE($mysqli, $hrsLogin);
    
    if ($importer->importReservations($dateFrom, $dateTo)) {
        sendSSE('finish', ['message' => 'Reservierungs-Import vollständig abgeschlossen!']);
    } else {
        sendSSE('error', ['message' => 'Reservierungs-Import mit Fehlern beendet']);
    }
    
} catch (Exception $e) {
    sendSSE('error', ['message' => 'Ausnahme: ' . $e->getMessage()]);
}
?>
