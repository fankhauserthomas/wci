<?php
/**
 * HRS Timeline Quota Update API
 * ==============================
 * 
 * Prioritäts-basiertes Quota-Management für Timeline
 * 
 * FEATURES:
 * - Prioritäts-basierte Quota-Verteilung
 * - Automatische Aufteilung mehrtägiger Quotas
 * - Intelligente Split-Logik bei Überlappung
 * - Atomische Transaktionen
 * 
 * INPUT:
 * {
 *   "selectedDays": ["2026-03-01", "2026-03-02", "2026-03-03"],
 *   "targetCapacity": 28,
 *   "priorities": [
 *     {"category": "lager", "max": 12},
 *     {"category": "betten", "max": 10},
 *     {"category": "sonder", "max": 2},
 *     {"category": "dz", "max": 4}
 *   ],
 *   "operation": "update" // oder "split_existing"
 * }
 * 
 * OUTPUT:
 * {
 *   "success": true,
 *   "calculatedQuotas": {"lager": 12, "betten": 10, "sonder": 2, "dz": 4},
 *   "affectedDays": ["2026-03-01", "2026-03-02", "2026-03-03"],
 *   "deletedQuotas": [123, 124],
 *   "createdQuotas": [125, 126, 127],
 *   "message": "Quotas erfolgreich aktualisiert"
 * }
 * 
 * @author GitHub Copilot
 * @version 1.0
 * @created 2025-10-09
 */

// Clean JSON output
ob_start();

// JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/hrs_login.php';
require_once __DIR__ . '/hrs_del_quota.php';

/**
 * Timeline Quota Manager
 */
class TimelineQuotaManager {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675;
    
    // Kategorie-Mapping (HRS category_id)
    private $categoryMap = [
        'lager'  => 1958,  // ML - Matratzenlager
        'betten' => 2293,  // MBZ - Mehrbettzimmer
        'dz'     => 2381,  // 2BZ - Zweibettzimmer
        'sonder' => 6106   // SK - Sonderkategorie
    ];
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
    }
    
    /**
     * Hauptfunktion: Update Quotas für selektierte Tage
     */
    public function updateQuotas($selectedDays, $targetCapacity, $priorities, $operation = 'update', $dailyQuotas = null) {
        $this->mysqli->begin_transaction();
        
        try {
            // 1. Validierung
            $this->validateInput($selectedDays, $targetCapacity, $priorities);
            
            // 2. Wenn Frontend bereits dailyQuotas berechnet hat, verwende diese
            // (enthält bereits AV-Reservierungen pro Tag!)
            if ($dailyQuotas && is_array($dailyQuotas) && !empty($dailyQuotas)) {
                error_log("✅ Verwende Frontend-berechnete dailyQuotas: " . count($dailyQuotas) . " Tage");
            } else {
                // Fallback: Berechne selbst (alte Logik)
                error_log("⚠️ Keine dailyQuotas vom Frontend, berechne selbst (FALLBACK)");
                
                // 2. Aktuelle Belegung prüfen
                $currentOccupancy = $this->getCurrentOccupancy($selectedDays[0]);
                
                // 3. Quota-Verteilung berechnen
                $calculatedQuotas = $this->calculateQuotaDistribution(
                    $targetCapacity, 
                    $priorities, 
                    $currentOccupancy
                );
            }
            
            // 4. Betroffene Quotas identifizieren
            $affectedQuotas = $this->findAffectedQuotas($selectedDays);
            
            // 5. Alte Quotas löschen/aufteilen
            $deletedQuotaIds = [];
            foreach ($affectedQuotas as $quota) {
                if ($this->isFullyInsideSelection($quota, $selectedDays)) {
                    // Vollständig in Selektion → Löschen
                    $deletedQuotaIds[] = $quota['id'];
                    $this->deleteQuota($quota['id']);
                } else {
                    // Teilweise überlappend → Split mit Erhalt
                    $newQuotas = $this->splitQuotaWithPreservation($quota, $selectedDays);
                    $deletedQuotaIds[] = $quota['id'];
                }
            }
            
            // 6. Neue eintägige Quotas erstellen (für jeden selektierten Tag)
            $createdQuotaIds = [];
            $createdQuotasData = []; // Für HRS-Upload
            
            if ($dailyQuotas && is_array($dailyQuotas) && !empty($dailyQuotas)) {
                // ✅ Verwende Frontend-Quotas (pro Tag unterschiedlich!)
                foreach ($dailyQuotas as $dayQuota) {
                    $day = $dayQuota['date'];
                    $quotas = $dayQuota['quotas']; // { lager, betten, dz, sonder }
                    
                    error_log("📅 Erstelle Quota für $day: " . json_encode($quotas));
                    
                    $quotaId = $this->createSingleDayQuota($day, $quotas);
                    if ($quotaId) {
                        $createdQuotaIds[] = $quotaId;
                        
                        // Sammle Daten für HRS-Upload
                        $totalCapacity = array_sum($quotas);
                        $createdQuotasData[] = [
                            'db_id' => $quotaId,
                            'title' => 'Timeline-' . date('dmy', strtotime($day)),
                            'date_from' => $day,
                            'date_to' => date('Y-m-d', strtotime($day . ' +1 day')),
                            'capacity' => $totalCapacity,
                            'categories' => $quotas
                        ];
                    }
                }
                
                // Für Response
                $calculatedQuotas = $dailyQuotas[0]['quotas']; // Erster Tag als Beispiel
                
            } else {
                // ⚠️ FALLBACK: Alte Logik (gleiche Quotas für alle Tage)
                foreach ($selectedDays as $day) {
                    $quotaId = $this->createSingleDayQuota($day, $calculatedQuotas);
                    if ($quotaId) {
                        $createdQuotaIds[] = $quotaId;
                        
                        // Sammle Daten für HRS-Upload
                        $totalCapacity = array_sum($calculatedQuotas);
                        $createdQuotasData[] = [
                            'db_id' => $quotaId,
                            'title' => 'Timeline-' . date('dmy', strtotime($day)),
                            'date_from' => $day,
                            'date_to' => date('Y-m-d', strtotime($day . ' +1 day')),
                            'capacity' => $totalCapacity,
                            'categories' => $calculatedQuotas
                        ];
                    }
                }
            }
            
            // 7. Commit lokale Änderungen
            $this->mysqli->commit();
            
            // 8. Upload ins HRS-System
            $hrsUploadResult = $this->uploadQuotasToHRS($createdQuotasData, $affectedQuotas);
            
            return [
                'success' => true,
                'calculatedQuotas' => $calculatedQuotas,
                'affectedDays' => $selectedDays,
                'deletedQuotas' => $deletedQuotaIds,
                'createdQuotas' => $createdQuotaIds,
                'hrsUpload' => $hrsUploadResult,
                'message' => count($selectedDays) . ' Tage erfolgreich aktualisiert (lokal + HRS)',
                'debug' => [
                    'createdQuotasData_count' => count($createdQuotasData),
                    'createdQuotasData' => $createdQuotasData,
                    'hrsUploadLogs' => $hrsUploadResult['logs'] ?? []
                ]
            ];
            
        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }
    
    /**
     * Berechnet Quota-Verteilung basierend auf Prioritäten
     * 
     * LOGIK:
     * - Durchlaufe Prioritäten von 1 bis 4
     * - Weise jeder Kategorie min(remaining, max) zu
     * - Rest geht zu Priorität 1
     * 
     * SPEZIALFALL:
     * - Wenn Belegung > Zielkapazität → Alle Quotas = 0
     */
    private function calculateQuotaDistribution($targetCapacity, $priorities, $currentOccupancy) {
        // Spezialfall: Überbelegung
        if ($currentOccupancy > $targetCapacity) {
            return [
                'lager'  => 0,
                'betten' => 0,
                'dz'     => 0,
                'sonder' => 0,
                'warning' => 'Aktuelle Belegung überschreitet Zielkapazität'
            ];
        }
        
        $result = [
            'lager'  => 0,
            'betten' => 0,
            'dz'     => 0,
            'sonder' => 0
        ];
        
        $remaining = $targetCapacity;
        
        // Durchlaufe Prioritäten
        foreach ($priorities as $prio) {
            if ($remaining <= 0) break;
            
            $category = $prio['category'];
            $maxValue = isset($prio['max']) && $prio['max'] !== null && $prio['max'] !== '' 
                ? (int)$prio['max'] 
                : PHP_INT_MAX; // NULL/leer = unbegrenzt
            
            $allocated = min($remaining, $maxValue);
            $result[$category] = $allocated;
            $remaining -= $allocated;
        }
        
        // Rest zu Priorität 1 addieren
        if ($remaining > 0 && count($priorities) > 0) {
            $firstPrio = $priorities[0];
            $result[$firstPrio['category']] += $remaining;
        }
        
        return $result;
    }
    
    /**
     * Validiert Input-Parameter
     */
    private function validateInput($selectedDays, $targetCapacity, $priorities) {
        if (empty($selectedDays) || !is_array($selectedDays)) {
            throw new Exception("selectedDays muss ein nicht-leeres Array sein");
        }
        
        if (!is_numeric($targetCapacity) || $targetCapacity < 0) {
            throw new Exception("targetCapacity muss >= 0 sein");
        }
        
        if (empty($priorities) || !is_array($priorities)) {
            throw new Exception("priorities muss ein nicht-leeres Array sein");
        }
        
        // Prüfe auf Duplikate
        $categories = array_column($priorities, 'category');
        if (count($categories) !== count(array_unique($categories))) {
            throw new Exception("Kategorie-Duplikate nicht erlaubt");
        }
        
        // Validiere Kategorien
        $validCategories = ['lager', 'betten', 'dz', 'sonder'];
        foreach ($categories as $cat) {
            if (!in_array($cat, $validCategories)) {
                throw new Exception("Ungültige Kategorie: $cat");
            }
        }
    }
    
    /**
     * Ermittelt aktuelle Belegung für einen Tag
     * HINWEIS: Verwendet AV-Res Tabelle statt nicht-existierende availability
     */
    private function getCurrentOccupancy($date) {
        // Verwende AV-Res Tabelle um Belegung zu berechnen
        $sql = "SELECT 
                    COALESCE(SUM(COALESCE(dz,0) + COALESCE(betten,0) + COALESCE(lager,0) + COALESCE(sonder,0)), 0) as total_occupied
                FROM `AV-Res`
                WHERE anreise <= ? 
                  AND abreise > ?";
        
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            // Fallback: Wenn Abfrage fehlschlägt, gebe 0 zurück
            // Die Frontend-Berechnung ist eh präziser
            return 0;
        }
        
        $stmt->bind_param('ss', $date, $date);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return (int)($result['total_occupied'] ?? 0);
    }
    
    /**
     * Findet betroffene Quotas im Zeitraum
     */
    private function findAffectedQuotas($selectedDays) {
        $minDate = min($selectedDays);
        $maxDate = max($selectedDays);
        
        $sql = "SELECT 
                    hq.id,
                    hq.hrs_id,
                    hq.date_from,
                    hq.date_to
                FROM hut_quota hq
                WHERE hq.date_from < DATE_ADD(?, INTERVAL 1 DAY)
                  AND hq.date_to > ?
                ORDER BY hq.date_from";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('ss', $maxDate, $minDate);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $quotas = [];
        while ($row = $result->fetch_assoc()) {
            $quotas[] = $row;
        }
        
        return $quotas;
    }
    
    /**
     * Prüft ob Quota vollständig innerhalb Selektion liegt
     */
    private function isFullyInsideSelection($quota, $selectedDays) {
        $minSelected = min($selectedDays);
        $maxSelected = max($selectedDays);
        
        $quotaStart = $quota['date_from'];
        $quotaEnd = date('Y-m-d', strtotime($quota['date_to'] . ' -1 day')); // date_to ist exklusiv
        
        return $quotaStart >= $minSelected && $quotaEnd <= $maxSelected;
    }
    
    /**
     * Teilt Quota bei Überlappung mit Erhalt der Randbereiche
     * 
     * BEISPIEL:
     * Original:  01.03. -------- 10.03.
     * Selektion:        03.03. - 05.03.
     * 
     * Ergebnis:
     * - Quota 1: 01.03. - 02.03. (mehrtägig erhalten)
     * - Quota 2: 03.03. - 03.03. (eintägig neu)
     * - Quota 3: 04.03. - 04.03. (eintägig neu)
     * - Quota 4: 05.03. - 05.03. (eintägig neu)
     * - Quota 5: 06.03. - 10.03. (mehrtägig erhalten)
     */
    private function splitQuotaWithPreservation($quota, $selectedDays) {
        $minSelected = min($selectedDays);
        $maxSelected = max($selectedDays);
        
        // Hole Kategorie-Werte der Original-Quota
        $categoryValues = $this->getQuotaCategoryValues($quota['id']);
        
        $newQuotas = [];
        
        // Bereich VOR Selektion
        $quotaStart = new DateTime($quota['date_from']);
        $selectionStart = new DateTime($minSelected);
        
        if ($quotaStart < $selectionStart) {
            $beforeEnd = clone $selectionStart;
            
            $newQuotaId = $this->createQuotaRange(
                $quotaStart->format('Y-m-d'),
                $beforeEnd->format('Y-m-d'),
                $categoryValues
            );
            
            if ($newQuotaId) {
                $newQuotas[] = $newQuotaId;
            }
        }
        
        // Bereich NACH Selektion
        $quotaEnd = new DateTime($quota['date_to']);
        $selectionEnd = new DateTime($maxSelected);
        $selectionEnd->modify('+1 day'); // date_to ist exklusiv
        
        if ($quotaEnd > $selectionEnd) {
            $newQuotaId = $this->createQuotaRange(
                $selectionEnd->format('Y-m-d'),
                $quotaEnd->format('Y-m-d'),
                $categoryValues
            );
            
            if ($newQuotaId) {
                $newQuotas[] = $newQuotaId;
            }
        }
        
        // Original-Quota löschen
        $this->deleteQuota($quota['id']);
        
        return $newQuotas;
    }
    
    /**
     * Holt Kategorie-Werte einer Quota
     */
    private function getQuotaCategoryValues($quotaId) {
        $sql = "SELECT category_id, total_beds
                FROM hut_quota_categories
                WHERE hut_quota_id = ?";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('i', $quotaId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $values = [
            'lager'  => 0,
            'betten' => 0,
            'dz'     => 0,
            'sonder' => 0
        ];
        
        while ($row = $result->fetch_assoc()) {
            $categoryId = (int)$row['category_id'];
            $totalBeds = (int)$row['total_beds'];
            
            // Reverse-Mapping
            foreach ($this->categoryMap as $name => $id) {
                if ($id === $categoryId) {
                    $values[$name] = $totalBeds;
                    break;
                }
            }
        }
        
        return $values;
    }
    
    /**
     * Erstellt Quota für Datumsbereich
     */
    private function createQuotaRange($dateFrom, $dateTo, $categoryValues) {
        // Generiere eindeutige hrs_id
        // Verwende 9-stellige Nummer: 9MMDDHHSS (9 für lokal, dann Monat/Tag/Stunde/Sekunde)
        $hrsId = 900000000 + (int)date('mdHis');
        
        // Falls ID existiert, inkrementiere
        $checkSql = "SELECT id FROM hut_quota WHERE hrs_id = ?";
        $stmt = $this->mysqli->prepare($checkSql);
        $stmt->bind_param('i', $hrsId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($result->num_rows > 0) {
            $hrsId++; // Inkrementiere bis eine freie ID gefunden wird
            $stmt->bind_param('i', $hrsId);
            $stmt->execute();
            $result = $stmt->get_result();
        }
        
        // Formatiere Titel
        $dateFromFormatted = date('dmy', strtotime($dateFrom));
        $title = "Timeline-{$dateFromFormatted}";
        
        // Füge hut_quota ein mit allen Required Fields
        $sql = "INSERT INTO hut_quota (
                    hrs_id, hut_id, date_from, date_to, title, mode, 
                    capacity, is_recurring, created_at, updated_at
                )
                VALUES (?, ?, ?, ?, ?, 'SERVICED', 0, 0, NOW(), NOW())";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('iisss', $hrsId, $this->hutId, $dateFrom, $dateTo, $title);
        $stmt->execute();
        
        $quotaId = $this->mysqli->insert_id;
        
        // Füge hut_quota_categories ein
        foreach ($categoryValues as $category => $value) {
            if ($value > 0) {
                $categoryId = $this->categoryMap[$category];
                
                $sql = "INSERT INTO hut_quota_categories (hut_quota_id, category_id, total_beds)
                        VALUES (?, ?, ?)";
                
                $stmt = $this->mysqli->prepare($sql);
                $stmt->bind_param('iii', $quotaId, $categoryId, $value);
                $stmt->execute();
            }
        }
        
        return $quotaId;
    }
    
    /**
     * Upload der Quotas ins HRS-System
     * 
     * @param array $quotasToCreate Array mit Quota-Daten für HRS-Upload
     * @param array $affectedQuotas Array mit betroffenen alten Quotas (aus findAffectedQuotas - haben hrs_id!)
     * @return array Upload-Ergebnis
     */
    private function uploadQuotasToHRS($quotasToCreate, $affectedQuotas) {
        $logs = []; // Sammle alle Logs
        $result = [
            'deleted' => 0,
            'created' => 0,
            'errors' => [],
            'skipped' => false
        ];
        
        try {
            $logs[] = "🚀 uploadQuotasToHRS gestartet";
            $logs[] = "📦 quotasToCreate: " . count($quotasToCreate) . " Stück";
            $logs[] = "🗑️ affectedQuotas: " . count($affectedQuotas) . " Stück";
            
            // HRS Login durchführen (für API-Calls)
            if (!$this->hrsLogin->isLoggedIn()) {
                $logs[] = "🔐 HRS-Login wird durchgeführt...";
                if (!$this->hrsLogin->login()) {
                    $logs[] = "❌ HRS-Login fehlgeschlagen!";
                    $result['errors'][] = 'HRS-Login fehlgeschlagen';
                    $result['logs'] = $logs;
                    return $result;
                }
                $logs[] = "✅ HRS-Login erfolgreich";
            } else {
                $logs[] = "✅ HRS-Login bereits aktiv";
            }
            
            // 1. Lösche existierende HRS-Quotas
            // WICHTIG: Hole ALLE HRS-Quotas für die Tage (auch Auto-Quotas vom Daily Import!)
            // Nicht nur die aus unserer DB, da Auto-Quotas nicht lokal gespeichert sind!
            
            if (!empty($affectedQuotas)) {
                // Sammle alle betroffenen Tage
                $dates = [];
                foreach ($affectedQuotas as $quota) {
                    $dates[] = $quota['date_from'];
                }
                $dates = array_unique($dates);
                
                $logs[] = "🗑️ Hole ALLE HRS-Quotas für Tage: " . implode(', ', $dates);
                
                // Hole alle HRS-Quotas direkt vom HRS (nicht nur aus unserer DB!)
                $quotasFromHRS = $this->getAllHRSQuotasForDates($dates, $logs);
                
                $logs[] = "🗑️ Gefundene HRS-Quotas vom Server: " . count($quotasFromHRS);
                
                if (!empty($quotasFromHRS)) {
                    // Extrahiere HRS-IDs
                    $hrsIds = array_column($quotasFromHRS, 'id');
                    
                    foreach ($quotasFromHRS as $q) {
                        $logs[] = "🗑️ Zum Löschen vom HRS: ID={$q['id']}, '{$q['title']}' ({$q['dateFrom']} bis {$q['dateTo']})";
                    }
                    
                    // Rufe hrs_del_quota_batch.php auf
                    $deletePayload = json_encode(['quota_hrs_ids' => $hrsIds]);
                    $deleteResult = $this->callHRSScript('hrs_del_quota_batch.php', $deletePayload);
                    
                    $logs[] = "🗑️ HRS DELETE RESULT: " . json_encode($deleteResult);
                    
                    if ($deleteResult && isset($deleteResult['success_count'])) {
                        $result['deleted'] = $deleteResult['success_count'];
                        $logs[] = "✅ Im HRS gelöscht: " . $deleteResult['success_count'];
                        if ($deleteResult['error_count'] > 0) {
                            $logs[] = "⚠️ Fehler beim Löschen: " . $deleteResult['error_count'];
                        }
                    } else {
                        $logs[] = "⚠️ Keine HRS-Quotas gelöscht (Result: " . json_encode($deleteResult) . ")";
                    }
                } else {
                    $logs[] = "ℹ️ Keine HRS-Quotas zum Löschen gefunden (Server ist leer für diese Tage)";
                }
            }
            
            // 2. Erstelle neue Quotas im HRS
            if (!empty($quotasToCreate)) {
                $logs[] = "🔍 HRS CREATE: Übergebe " . count($quotasToCreate) . " Quotas";
                $logs[] = "📦 Payload: " . json_encode($quotasToCreate);
                
                // Rufe hrs_create_quota_batch.php auf
                $createPayload = json_encode(['quotas' => $quotasToCreate]);
                $createResult = $this->callHRSScript('hrs_create_quota_batch.php', $createPayload);
                
                $logs[] = "� HRS CREATE RESULT: " . json_encode($createResult);
                
                if ($createResult && isset($createResult['success_count'])) {
                    $result['created'] = $createResult['success_count'];
                    $logs[] = "✅ Erstellt: " . $createResult['success_count'];
                    
                    // Update der DB mit den neuen HRS-IDs (falls vom Script zurückgegeben)
                    if (isset($createResult['results'])) {
                        $this->updateHrsIds($createResult['results']);
                        $logs[] = "✅ HRS-IDs aktualisiert";
                    }
                } else {
                    $logs[] = "❌ HRS CREATE fehlgeschlagen: " . json_encode($createResult);
                }
                
                if ($createResult && isset($createResult['errors'])) {
                    $result['errors'] = $createResult['errors'];
                    $logs[] = "❌ Fehler: " . json_encode($createResult['errors']);
                }
            }
            
            $result['logs'] = $logs;
            return $result;
            
        } catch (Exception $e) {
            $logs[] = "💥 Exception: " . $e->getMessage();
            $result['errors'][] = 'HRS Upload fehlgeschlagen: ' . $e->getMessage();
            $result['logs'] = $logs;
            return $result;
        }
    }
    
    /**
     * Hole alle HRS-Quota-IDs die mit den angegebenen Daten überlappen
     * WICHTIG: Holt direkt vom HRS-System, nicht aus unserer DB!
     * 
     * Findet auch mehrtägige Quotas die teilweise überlappen!
     * Beispiel: Quota vom 10.02-15.02 wird gefunden wenn wir 12.02 updaten wollen
     * 
     * @param array $dates Array mit Datumsstrings (Y-m-d)
     * @param array &$logs Reference zu Logs-Array (optional, für Response-Logging)
     */
    private function getAllHRSQuotasForDates($dates, &$logs = null) {
        $quotasToDelete = []; // Array mit [id, dateFrom, dateTo, title]
        
        try {
            // Erweitere Datumsbereich (hole mehr Quotas um mehrtägige zu finden)
            $minDate = min($dates);
            $maxDate = max($dates);
            $dateFrom = date('d.m.Y', strtotime($minDate . ' -30 days')); // 30 Tage zurück!
            $dateTo = date('d.m.Y', strtotime($maxDate . ' +30 days'));
            
            $log = "🔍 HRS API: Hole Quotas von $dateFrom bis $dateTo";
            error_log($log);
            if ($logs !== null) $logs[] = $log;
            
            // API-URL (GLEICH WIE IM IMPORT!)
            $url = "/api/v1/manage/hutQuota?hutId={$this->hutId}&page=0&size=200&sortList=BeginDate&sortOrder=ASC&open=true&dateFrom={$dateFrom}&dateTo={$dateTo}";
            
            $log = "🔗 HRS API URL: " . $url;
            error_log($log);
            if ($logs !== null) $logs[] = $log;
            
            // Verwende HRSLogin->makeRequest() statt direktes CURL!
            $headers = ['X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()];
            $responseArray = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
            
            if (!$responseArray || !isset($responseArray['body'])) {
                $log = "❌ HRS API: Keine Response";
                error_log($log);
                if ($logs !== null) $logs[] = $log;
                return $quotasToDelete;
            }
            
            $response = $responseArray['body'];
            $log = "📥 HRS API Response: " . strlen($response) . " bytes, HTTP " . ($responseArray['status'] ?? 'unknown');
            error_log($log);
            if ($logs !== null) $logs[] = $log;
            
            $log = "📄 First 500 chars: " . substr($response, 0, 500);
            error_log($log);
            if ($logs !== null) $logs[] = $log;
            
            if ($response) {
                $data = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $log = "❌ JSON Decode Error: " . json_last_error_msg();
                    error_log($log);
                    if ($logs !== null) $logs[] = $log;
                    return $quotasToDelete;
                }
                
                $log = "🔍 JSON decode OK: " . (is_array($data) ? "Array with keys: " . implode(', ', array_keys($data)) : gettype($data));
                error_log($log);
                if ($logs !== null) $logs[] = $log;
                
                // Die Management-API gibt "_embedded" -> "bedCapacityChangeResponseDTOList" zurück!
                $quotasList = null;
                if (isset($data['_embedded']['bedCapacityChangeResponseDTOList']) && is_array($data['_embedded']['bedCapacityChangeResponseDTOList'])) {
                    $quotasList = $data['_embedded']['bedCapacityChangeResponseDTOList'];
                    $log = "📊 HRS API: " . count($quotasList) . " Quotas in _embedded.bedCapacityChangeResponseDTOList gefunden";
                    error_log($log);
                    if ($logs !== null) $logs[] = $log;
                } elseif (isset($data['content']) && is_array($data['content'])) {
                    $quotasList = $data['content'];
                    $log = "📊 HRS API: " . count($quotasList) . " Quotas in content gefunden";
                    error_log($log);
                    if ($logs !== null) $logs[] = $log;
                } else {
                    $log = "⚠️ HRS API: Keine Quotas gefunden (keys: " . implode(', ', array_keys($data ?: [])) . ")";
                    error_log($log);
                    if ($logs !== null) $logs[] = $log;
                }
                
                if ($quotasList) {
                    foreach ($quotasList as $quota) {
                        $quotaId = $quota['id'] ?? null;
                        $quotaTitle = $quota['title'] ?? '';
                        $quotaDateFromStr = $quota['dateFrom'] ?? '';
                        $quotaDateToStr = $quota['dateTo'] ?? '';
                        
                        if (!$quotaId || !$quotaDateFromStr || !$quotaDateToStr) continue;
                        
                        // Konvertiere HRS-Datumsformat (dd.mm.yyyy) zu Y-m-d
                        $quotaDateFrom = date('Y-m-d', strtotime(str_replace('.', '-', $quotaDateFromStr)));
                        $quotaDateTo = date('Y-m-d', strtotime(str_replace('.', '-', $quotaDateToStr)));
                        
                        $log = "🔎 Prüfe Quota ID=$quotaId '$quotaTitle' ($quotaDateFrom bis $quotaDateTo)";
                        error_log($log);
                        if ($logs !== null) $logs[] = $log;
                        
                        // Prüfe ob Quota einen unserer Tage betrifft (Overlap-Check!)
                        $overlaps = false;
                        foreach ($dates as $checkDate) {
                            // Quota überschneidet sich wenn:
                            // - checkDate >= quotaDateFrom UND checkDate < quotaDateTo
                            if ($checkDate >= $quotaDateFrom && $checkDate < $quotaDateTo) {
                                $overlaps = true;
                                $log = "✅ Overlap gefunden: Quota ID=$quotaId '$quotaTitle' betrifft $checkDate";
                                error_log($log);
                                if ($logs !== null) $logs[] = $log;
                                break;
                            }
                        }
                        
                        if ($overlaps) {
                            $quotasToDelete[] = [
                                'id' => $quotaId,
                                'title' => $quotaTitle,
                                'dateFrom' => $quotaDateFrom,
                                'dateTo' => $quotaDateTo
                            ];
                        } else {
                            $log = "⏭️ Quota ID=$quotaId '$quotaTitle' betrifft unsere Tage nicht";
                            error_log($log);
                            if ($logs !== null) $logs[] = $log;
                        }
                    }
                }
            } else {
                $log = "❌ HRS API: Leere Response";
                error_log($log);
                if ($logs !== null) $logs[] = $log;
            }
            
        } catch (Exception $e) {
            $log = "💥 Exception beim Abrufen der HRS-Quotas: " . $e->getMessage();
            error_log($log);
            if ($logs !== null) $logs[] = $log;
        }
        
        return $quotasToDelete;
    }
    
    /**
     * Ruft ein HRS-Script via internem POST auf
     */
    
    /**
     * Ruft ein HRS-Script via internem POST auf
     */
    private function callHRSScript($scriptName, $jsonPayload) {
        // Verwende korrekten Server (nicht localhost!)
        $serverName = $_SERVER['SERVER_NAME'] ?? '192.168.15.14';
        $serverPort = $_SERVER['SERVER_PORT'] ?? '8080';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        
        $url = "http://{$serverName}:{$serverPort}{$scriptDir}/{$scriptName}";
        
        error_log("🔗 Calling HRS Script: $url");
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 120 // 2 Minuten für Batch-Operationen
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        error_log("📥 HRS Script Response: HTTP $httpCode" . ($curlError ? " (Error: $curlError)" : ""));
        
        if ($httpCode === 200 && $response) {
            // Das Script kann mehrere JSON-Zeilen zurückgeben (Stream-Format)
            // Parse alle Zeilen und suche die "summary" Zeile (enthält success_count)
            $lines = explode("\n", trim($response));
            $result = null;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $decoded = json_decode($line, true);
                if ($decoded) {
                    // Priorität 1: "summary" Status (enthält success_count, error_count)
                    if (isset($decoded['status']) && $decoded['status'] === 'summary') {
                        $result = $decoded;
                        break; // Das ist die wichtigste Zeile!
                    } 
                    // Priorität 2: Zeile mit success_count (auch wenn kein "summary" status)
                    else if (isset($decoded['success_count'])) {
                        $result = $decoded;
                    }
                    // Fallback: Erste gültige JSON-Zeile
                    else if (!$result) {
                        $result = $decoded;
                    }
                }
            }
            
            // 🔍 DEBUG: Logs in Console ausgeben
            if ($result && isset($result['log']) && is_array($result['log'])) {
                foreach ($result['log'] as $logEntry) {
                    error_log("HRS $scriptName: $logEntry");
                }
            }
            
            return $result;
        }
        
        error_log("❌ HRS Script fehlgeschlagen: HTTP $httpCode, Response: " . substr($response, 0, 500));
        
        return null;
    }
    
    /**
     * Update der lokalen DB mit HRS-IDs nach erfolgreichem Upload
     */
    private function updateHrsIds($hrsResults) {
        foreach ($hrsResults as $result) {
            if (isset($result['success']) && $result['success'] && isset($result['db_id']) && isset($result['hrs_id'])) {
                $sql = "UPDATE hut_quota SET hrs_id = ? WHERE id = ?";
                $stmt = $this->mysqli->prepare($sql);
                $stmt->bind_param('ii', $result['hrs_id'], $result['db_id']);
                $stmt->execute();
            }
        }
    }
    
    /**
     * Erstellt eintägige Quota
     */
    private function createSingleDayQuota($date, $categoryValues) {
        $dateTo = date('Y-m-d', strtotime($date . ' +1 day')); // date_to ist exklusiv
        
        return $this->createQuotaRange($date, $dateTo, $categoryValues);
    }
    
    /**
     * Löscht Quota aus Datenbank
     */
    private function deleteQuota($quotaId) {
        // 1. Lösche hut_quota_categories
        $sql = "DELETE FROM hut_quota_categories WHERE hut_quota_id = ?";
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('i', $quotaId);
        $stmt->execute();
        
        // 2. Lösche hut_quota
        $sql = "DELETE FROM hut_quota WHERE id = ?";
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param('i', $quotaId);
        $stmt->execute();
        
        return true;
    }
}

// ============ MAIN EXECUTION ============

try {
    // Parse JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Ungültiger JSON Input');
    }
    
    // Extrahiere Parameter
    $selectedDays = $input['selectedDays'] ?? [];
    $targetCapacity = $input['targetCapacity'] ?? 0;
    $priorities = $input['priorities'] ?? [];
    $operation = $input['operation'] ?? 'update';
    $dailyQuotas = $input['dailyQuotas'] ?? null; // ✅ NEU: Frontend-berechnete Quotas
    
    // 🔍 DEBUG: Log input
    error_log("📥 API Request: " . count($selectedDays) . " Tage, dailyQuotas=" . (is_array($dailyQuotas) ? 'YES' : 'NO'));
    if ($dailyQuotas) {
        error_log("📊 Daily Quotas: " . json_encode($dailyQuotas, JSON_PRETTY_PRINT));
    }
    
    // Initialize Manager
    $hrsLogin = new HRSLogin();
    $manager = new TimelineQuotaManager($mysqli, $hrsLogin);
    
    // Execute Update
    $result = $manager->updateQuotas($selectedDays, $targetCapacity, $priorities, $operation, $dailyQuotas);
    
    // Success response
    ob_end_clean();
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
