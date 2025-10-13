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
        sendSSE('log', ['level' => 'info', 'message' => 'HRS Reservation Importer initialized']);
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
            
            if ($this->transferWebImpToProduction()) {
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
        $result = transferWebImpToProduction($this->mysqli, function ($level, $message) {
            sendSSE('log', ['level' => $level, 'message' => $message]);
        });

        if ($result['success']) {
            $stats = $result['stats'];
            sendSSE('log', [
                'level' => 'info',
                'message' => sprintf(
                    'Transfer-Statistik: %d neu, %d aktualisiert, %d unverändert',
                    $stats['inserted'],
                    $stats['updated'],
                    $stats['unchanged']
                )
            ]);
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
        $size = 100;
        
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
            
            // Debug: Log full response for first page
            if ($page === 0) {
                sendSSE('log', ['level' => 'debug', 'message' => 'API Response Keys: ' . implode(', ', array_keys($data ?? []))]);
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
            
            $isLast = $data['last'] ?? true;
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
            $guestName = $header['guestName'] ?? '';
            $nameParts = explode(' ', $guestName, 2);
            $nachname = $nameParts[0] ?? '';
            $vorname = $nameParts[1] ?? '';
            
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
            $gruppe = $header['groupName'] ?? '';
            $vorgang = $header['status'] ?? 'UNKNOWN';
            
            // Kontakt-Daten und Kommentare aus body.leftList extrahieren
            $handy = '';
            $bem_av = '';
            $email = $reservation['guestEmail'] ?? '';
            $email_date = null;
            
            if (isset($body['leftList']) && is_array($body['leftList'])) {
                foreach ($body['leftList'] as $item) {
                    if (isset($item['label'])) {
                        if ($item['label'] === 'configureReservationListPage.phone') {
                            $handy = $item['value'] ?? '';
                        } elseif ($item['label'] === 'configureReservationListPage.comments') {
                            $bem_av = $item['value'] ?? '';
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
            
            // INSERT nur in WebImp-Tabelle (nicht direkt in Production)
            $insertSql = "INSERT INTO `AV-Res-webImp` (
                av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
                gruppe, nachname, vorname, handy, email, vorgang, email_date, bem_av, timestamp
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                anreise = VALUES(anreise),
                abreise = VALUES(abreise),
                lager = VALUES(lager),
                betten = VALUES(betten),
                dz = VALUES(dz),
                sonder = VALUES(sonder),
                hp = VALUES(hp),
                vegi = VALUES(vegi),
                gruppe = VALUES(gruppe),
                nachname = VALUES(nachname),
                vorname = VALUES(vorname),
                handy = VALUES(handy),
                email = VALUES(email),
                vorgang = VALUES(vorgang),
                email_date = VALUES(email_date),
                bem_av = VALUES(bem_av),
                timestamp = VALUES(timestamp)";
            
            $insertStmt = $this->mysqli->prepare($insertSql);
            if (!$insertStmt) {
                sendSSE('log', ['level' => 'error', 'message' => 'Prepare failed: ' . $this->mysqli->error]);
                return false;
            }
            
            $insertStmt->bind_param("issiiiiiisssssssss", 
                $av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder, $hp, $vegi,
                $gruppe, $nachname, $vorname, $handy, $email, $vorgang, $email_date, $bem_av, $timestamp
            );
            
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
