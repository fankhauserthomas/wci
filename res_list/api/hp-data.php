<?php
/* ==============================================
   HP-DATEN API - OPTIMIERT
   ============================================== */

// Error Reporting für Development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS für lokale Entwicklung
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit(0);
}

// Konfiguration laden
require_once '../../config.php';
require_once '../../hp-db-config.php';

// DB Konstanten definieren
define('DB_HOST', $GLOBALS['dbHost']);
define('DB_USER', $GLOBALS['dbUser']);
define('DB_PASS', $GLOBALS['dbPass']);
define('DB_NAME', $GLOBALS['dbName']);

// HP DB Konstanten definieren
define('HP_DB_HOST', $HP_DB_CONFIG['host']);
define('HP_DB_USER', $HP_DB_CONFIG['user']);
define('HP_DB_PASS', $HP_DB_CONFIG['pass']);
define('HP_DB_NAME', $HP_DB_CONFIG['name']);

class HpDataAPI {
    private $avPdo;
    private $hpPdo;
    
    public function __construct() {
        $this->connectDatabases();
    }
    
    private function connectDatabases() {
        try {
            // AV Database (Hauptdatenbank)
            $this->avPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            // HP Database
            $this->hpPdo = new PDO(
                "mysql:host=" . HP_DB_HOST . ";dbname=" . HP_DB_NAME . ";charset=utf8mb4",
                HP_DB_USER,
                HP_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
        } catch (PDOException $e) {
            $this->sendError('Datenbankverbindung fehlgeschlagen', 500, [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    public function handleRequest() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendError('Nur GET-Anfragen erlaubt', 405);
        }
        
        try {
            $date = $_GET['date'] ?? date('Y-m-d');
            $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';
            
            // Cache-Check (nur wenn nicht force refresh)
            if (!$forceRefresh) {
                $cached = $this->getCachedData($date);
                if ($cached) {
                    $this->sendSuccess($cached);
                }
            }
            
            // Daten laden
            $hpData = $this->loadHpData($date);
            
            // Cache speichern
            $this->setCachedData($date, $hpData);
            
            $this->sendSuccess($hpData);
            
        } catch (Exception $e) {
            $this->sendError('Server Fehler', 500, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    private function loadHpData($date) {
        $startTime = microtime(true);
        
        // HP-Arrangements laden
        $arrangements = $this->getHpArrangements($date);
        
        // Check-ins aus AV-ResNamen laden
        $checkins = $this->getCheckins($date);
        
        // Vergleichsdaten berechnen
        $comparison = $this->calculateComparison($arrangements, $checkins);
        
        $loadTime = round((microtime(true) - $startTime) * 1000, 2);
        
        return [
            'arrangements' => $arrangements,
            'checkins' => $checkins,
            'comparison' => $comparison,
            'date' => $date,
            'timestamp' => date('c'),
            'load_time_ms' => $loadTime,
            'stats' => [
                'total_arrangements' => count($arrangements),
                'total_checkins' => count($checkins),
                'matched_entries' => count($comparison['matched']),
                'unmatched_arrangements' => count($comparison['unmatched_arrangements']),
                'unmatched_checkins' => count($comparison['unmatched_checkins'])
            ]
        ];
    }
    
    private function getHpArrangements($date) {
        try {
            $sql = "
                SELECT 
                    nachname,
                    vorname,
                    anreise,
                    abreise,
                    arrangement,
                    COUNT(*) as count
                FROM reservierungen 
                WHERE DATE(anreise) <= :date 
                  AND DATE(abreise) >= :date
                  AND (storno IS NULL OR storno = 0)
                GROUP BY nachname, vorname, anreise, abreise, arrangement
                ORDER BY nachname, vorname
            ";
            
            $stmt = $this->hpPdo->prepare($sql);
            $stmt->execute(['date' => $date]);
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            // HP-DB nicht verfügbar - leeres Array zurückgeben
            error_log("HP-DB Error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getCheckins($date) {
        try {
            $sql = "
                SELECT 
                    rn.nachname,
                    rn.vorname,
                    r.anreise,
                    r.abreise,
                    r.arrangement,
                    COUNT(*) as count
                FROM `AV-ResNamen` rn
                JOIN `AV-Res` r ON rn.res_id = r.id
                WHERE DATE(r.anreise) <= :date 
                  AND DATE(r.abreise) >= :date
                  AND rn.eingecheckt = 1
                  AND (r.storno IS NULL OR r.storno = 0)
                GROUP BY rn.nachname, rn.vorname, r.anreise, r.abreise, r.arrangement
                ORDER BY rn.nachname, rn.vorname
            ";
            
            $stmt = $this->avPdo->prepare($sql);
            $stmt->execute(['date' => $date]);
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("AV-DB Error: " . $e->getMessage());
            return [];
        }
    }
    
    private function calculateComparison($arrangements, $checkins) {
        $arrangementMap = [];
        $checkinMap = [];
        $matched = [];
        $unmatched_arrangements = [];
        $unmatched_checkins = [];
        
        // Arrangements indexieren
        foreach ($arrangements as $arr) {
            $key = $this->createNameKey($arr['nachname'], $arr['vorname']);
            if (!isset($arrangementMap[$key])) {
                $arrangementMap[$key] = 0;
            }
            $arrangementMap[$key] += (int)$arr['count'];
        }
        
        // Check-ins indexieren
        foreach ($checkins as $checkin) {
            $key = $this->createNameKey($checkin['nachname'], $checkin['vorname']);
            if (!isset($checkinMap[$key])) {
                $checkinMap[$key] = 0;
            }
            $checkinMap[$key] += (int)$checkin['count'];
        }
        
        // Vergleich durchführen
        $allKeys = array_unique(array_merge(array_keys($arrangementMap), array_keys($checkinMap)));
        
        foreach ($allKeys as $key) {
            $arrCount = $arrangementMap[$key] ?? 0;
            $checkinCount = $checkinMap[$key] ?? 0;
            
            $entry = [
                'name_key' => $key,
                'arrangements' => $arrCount,
                'checkins' => $checkinCount,
                'difference' => $arrCount - $checkinCount,
                'status' => $this->getComparisonStatus($arrCount, $checkinCount)
            ];
            
            if ($arrCount > 0 && $checkinCount > 0) {
                $matched[] = $entry;
            } elseif ($arrCount > 0) {
                $unmatched_arrangements[] = $entry;
            } elseif ($checkinCount > 0) {
                $unmatched_checkins[] = $entry;
            }
        }
        
        return [
            'matched' => $matched,
            'unmatched_arrangements' => $unmatched_arrangements,
            'unmatched_checkins' => $unmatched_checkins,
            'summary' => [
                'total_arrangement_count' => array_sum($arrangementMap),
                'total_checkin_count' => array_sum($checkinMap),
                'difference' => array_sum($arrangementMap) - array_sum($checkinMap)
            ]
        ];
    }
    
    private function createNameKey($nachname, $vorname) {
        return strtolower(trim($nachname)) . '_' . strtolower(trim($vorname ?? ''));
    }
    
    private function getComparisonStatus($arrangements, $checkins) {
        if ($arrangements === $checkins) {
            return 'balanced';
        } elseif ($arrangements > $checkins) {
            return 'more_arrangements';
        } else {
            return 'more_checkins';
        }
    }
    
    private function getCachedData($date) {
        $cacheFile = sys_get_temp_dir() . "/hp_data_cache_{$date}.json";
        $cacheMaxAge = 5 * 60; // 5 Minuten
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
            $content = file_get_contents($cacheFile);
            return json_decode($content, true);
        }
        
        return null;
    }
    
    private function setCachedData($date, $data) {
        $cacheFile = sys_get_temp_dir() . "/hp_data_cache_{$date}.json";
        
        try {
            file_put_contents($cacheFile, json_encode($data), LOCK_EX);
        } catch (Exception $e) {
            // Cache-Fehler ignorieren
            error_log("Cache write error: " . $e->getMessage());
        }
    }
    
    private function sendSuccess($data = [], $status = 200) {
        http_response_code($status);
        echo json_encode([
            'success' => true,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    private function sendError($message, $status = 400, $details = []) {
        http_response_code($status);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'details' => $details
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// API ausführen
$api = new HpDataAPI();
$api->handleRequest();
?>
