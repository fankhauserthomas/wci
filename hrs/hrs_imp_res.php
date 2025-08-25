<?php
/**
 * HRS Reservierungen Import - CLI & JSON API
 * Basiert auf hrs_login_debug.php - Importiert in AV-Res-webImp
 * 
 * Usage CLI: php hrs_imp_res.php 20.08.2025 31.08.2025
 * Usage Web: hrs_imp_res.php?from=20.08.2025&to=31.08.2025
 */

// Parameter verarbeiten (einheitlich: from/to)
if (isset($_GET['from']) && isset($_GET['to'])) {
    // Web-Interface: JSON Header setzen
    header('Content-Type: application/json');
    $dateFrom = $_GET['from'];
    $dateTo = $_GET['to'];
    $isWebInterface = true;
    
    // Konvertiere YYYY-MM-DD Format zu DD.MM.YYYY für interne Verarbeitung
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = DateTime::createFromFormat('Y-m-d', $dateFrom)->format('d.m.Y');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = DateTime::createFromFormat('Y-m-d', $dateTo)->format('d.m.Y');
    }
} else {
    // CLI Parameter verarbeiten
    $dateFrom = isset($argv[1]) ? $argv[1] : null;
    $dateTo = isset($argv[2]) ? $argv[2] : null;
    $isWebInterface = false;
    
    if (!$dateFrom || !$dateTo) {
        echo "Usage: php hrs_imp_res.php <dateFrom> <dateTo>\n";
        echo "Example: php hrs_imp_res.php 20.08.2025 31.08.2025\n";
        exit(1);
    }
}

// Datenbankverbindung und HRS Login
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/hrs_login.php');

// JSON Output Capture für Web-Interface
if ($isWebInterface) {
    ob_start();
}

class HRSReservationImporter {
    private $mysqli;
    private $hrsLogin;
    private $importDateFrom;
    private $importDateTo;
    
    public function __construct() {
        $this->mysqli = $GLOBALS['mysqli'];
        $this->hrsLogin = new HRSLogin();
        
        $this->debug("HRS Reservation Importer initialized");
    }
    
    private function debug($message) {
        $this->hrsLogin->debug($message);
    }
    
    private function debugError($message) {
        $this->hrsLogin->debugError($message);
    }
    
    private function debugSuccess($message) {
        $this->hrsLogin->debugSuccess($message);
    }
    
    public function getReservationList($hutId, $dateFrom, $dateTo, $page = 0, $size = 100) {
        $this->debug("Fetching reservations for date range $dateFrom to $dateTo (page $page)");
        
        $params = array(
            'hutId' => $hutId,
            'sortList' => 'ArrivalDate',
            'sortOrder' => 'ASC',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'page' => $page,
            'size' => $size
        );
        
        $url = '/api/v1/manage/reservation/list?' . http_build_query($params);
        
        $headers = array(
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
            'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()
        );
        
        $response = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            $this->debugError("Failed to fetch reservations");
            return false;
        }
        
        return $response['body'];
    }
    
    public function importReservations($dateFrom, $dateTo) {
        $this->debug("=== Starting Reservation Import ===");
        $this->debug("Date range: $dateFrom to $dateTo");
        
        // Import-Zeitraum speichern für processReservation
        $this->importDateFrom = $dateFrom;
        $this->importDateTo = $dateTo;
        
        if (!$this->hrsLogin->login()) {
            $this->debugError("Login failed - cannot import reservations");
            return false;
        }
        
        $hutId = 675; // Franzsennhütte
        $page = 0;
        $size = 100;
        $totalProcessed = 0;
        $totalInserted = 0;
        
        do {
            $jsonData = $this->getReservationList($hutId, $dateFrom, $dateTo, $page, $size);
            if (!$jsonData) {
                break;
            }
            
            $data = json_decode($jsonData, true);
            if (!$data || !isset($data['_embedded']['reservationsDataModelDTOList'])) {
                $this->debug("No more reservations found");
                break;
            }
            
            $reservations = $data['_embedded']['reservationsDataModelDTOList'];
            $this->debug("Processing " . count($reservations) . " reservations from page " . ($page + 1));
            
            foreach ($reservations as $reservation) {
                if ($this->processReservation($reservation)) {
                    $totalInserted++;
                }
                $totalProcessed++;
            }
            
            $page++;
            
        } while (count($reservations) == $size);
        
        $this->debugSuccess("Import completed: $totalProcessed processed, $totalInserted inserted into AV-Res-webImp");
        return true;
    }
    
    private function processReservation($reservation) {
        try {
            // Basis-Daten aus header extrahieren
            $header = $reservation['header'];
            $body = $reservation['body'];
            
            // av_id aus header.reservationNumber extrahieren (KORREKT!)
            $av_id = $header['reservationNumber'] ?? null;
            
            if (!$av_id) {
                $this->debugError("No reservationNumber found in header for reservation");
                return false;
            }
            
            // Convert av_id to integer (database field is bigint)
            $av_id = (int)$av_id;
            
            $this->debug("Processing reservation av_id: $av_id");
            
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
                            $categoryId = $assignment['categoryId'] ?? 0;  // categoryId ist auf assignment-Level!
                            $amount = $assignment['bedOccupied'] ?? 0;     // bedOccupied statt amount!
                            
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
            $hp = $header['halfPension'] ? 1 : 0;
            $vegi = $header['numberOfVegetarians'] ?? 0;
            $gruppe = $header['groupName'] ?? '';
            $vorgang = $header['status'] ?? 'UNKNOWN';
            
            // Kontakt-Daten und Kommentare aus body.leftList extrahieren
            $handy = '';
            $bem_av = '';
            $email = $reservation['guestEmail'] ?? '';
            $email_date = date('Y-m-d H:i:s');
            
            if (isset($body['leftList']) && is_array($body['leftList'])) {
                foreach ($body['leftList'] as $item) {
                    if (isset($item['label'])) {
                        if ($item['label'] === 'configureReservationListPage.phone') {
                            $handy = $item['value'] ?? '';
                        } elseif ($item['label'] === 'configureReservationListPage.comments') {
                            $bem_av = $item['value'] ?? '';
                        }
                    }
                }
            }
            
            // Importzeitraum für timestamp (als aktuelles Datetime)
            $timestamp = date('Y-m-d H:i:s');
            
            $this->debug("→ Data: $guestName, $anreise-$abreise, L:$lager B:$betten D:$dz S:$sonder, HP:$hp, Email:$email, Bem:$bem_av");
            
            // DELETE + INSERT Strategie
            // 1. Bestehenden Datensatz löschen
            $deleteSql = "DELETE FROM `AV-Res-webImp` WHERE av_id = ?";
            $deleteStmt = $this->mysqli->prepare($deleteSql);
            $deleteStmt->bind_param("i", $av_id);
            $deleteStmt->execute();
            $deleteStmt->close();
            
            // 2. Neuen Datensatz einfügen
            $insertSql = "INSERT INTO `AV-Res-webImp` (
                av_id, anreise, abreise, lager, betten, dz, sonder, hp, vegi,
                gruppe, nachname, vorname, handy, email, vorgang, email_date, bem_av, timestamp
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            // Debug: Count parameters
            $params = array($av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder, $hp, $vegi,
                           $gruppe, $nachname, $vorname, $handy, $email, $vorgang, $email_date, $bem_av, $timestamp);
            $this->debug("→ SQL has 18 placeholders, passing " . count($params) . " parameters");
            $this->debug("→ Types: issiiiiiisssssssss (18 chars)");
            
            $insertStmt = $this->mysqli->prepare($insertSql);
            $insertStmt->bind_param("issiiiiiisssssssss", 
                $av_id, $anreise, $abreise, $lager, $betten, $dz, $sonder, $hp, $vegi,
                $gruppe, $nachname, $vorname, $handy, $email, $vorgang, $email_date, $bem_av, $timestamp
            );
            
            if ($insertStmt->execute()) {
                $insertStmt->close();
                $this->debug("✅ Inserted reservation $av_id successfully");
                return true;
            } else {
                $this->debugError("Failed to insert reservation: " . $this->mysqli->error);
                $insertStmt->close();
                return false;
            }
            
        } catch (Exception $e) {
            $this->debugError("Error processing reservation: " . $e->getMessage());
            return false;
        }
    }
}

// Main execution
try {
    $importer = new HRSReservationImporter();
    $success = $importer->importReservations($dateFrom, $dateTo);
    
    if ($success) {
        if ($isWebInterface) {
            $output = ob_get_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Reservation import completed successfully',
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'log' => $output
            ]);
        } else {
            echo "\n✅ Import completed successfully!\n";
        }
        exit(0);
    } else {
        if ($isWebInterface) {
            $output = ob_get_clean();
            echo json_encode([
                'success' => false,
                'error' => 'Import failed',
                'log' => $output
            ]);
        } else {
            echo "\n❌ Import failed!\n";
        }
        exit(1);
    }
    
} catch (Exception $e) {
    if ($isWebInterface) {
        $output = ob_get_clean();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'log' => $output
        ]);
    } else {
        echo "\n❌ Fatal error: " . $e->getMessage() . "\n";
    }
    exit(1);
}
?>
