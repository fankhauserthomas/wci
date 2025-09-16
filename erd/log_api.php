<?php
/**
 * ERD Display Logging API v2.0 - UPDATED VERSION
 * 
 * Zukunftssichere API für ESP32 E-Paper Display Logging
 * Erstellt und verwaltet Logdateien für Effizienz- und Laufzeitmessungen
 * MIT VOLTAGE- UND MEMORY-UNTERSTÜTZUNG
 * 
 * Usage:
 * POST /log_api.php
 * Body: {"action": "WAKE_UP", "details": "Deep Sleep Timer Wake-up", "device_id": "30c6f7fa8ff4", "voltage": 3.7, "free_memory": 235000}
 * 
 * GET /log_api.php?device_id=30c6f7fa8ff4&download=1 (Download Log)
 * GET /log_api.php?device_id=30c6f7fa8ff4&stats=1 (Statistiken mit Voltage)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Konfiguration
define('LOG_DIR', __DIR__ . '/logs/');
define('MAX_LOG_SIZE', 1024 * 1024); // 1MB max per Logfile
define('MAX_LOG_FILES', 10); // Rotation nach 10 Dateien
define('TIMEZONE', 'Europe/Zurich');

// Verzeichnis erstellen falls nicht vorhanden
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// Timezone setzen
date_default_timezone_set(TIMEZONE);

/**
 * Sicherheit: Device ID validieren
 */
function validateDeviceId($device_id) {
    return preg_match('/^[a-f0-9]{12}$/', $device_id);
}

/**
 * Log-Dateiname generieren - MIT TISCH-UNTERSTÜTZUNG
 */
function getLogFileName($device_id, $rotation = 0, $table_id = null) {
    $table_suffix = $table_id ? "_table_{$table_id}" : '';
    $rotation_suffix = $rotation > 0 ? "_{$rotation}" : '';
    return LOG_DIR . "erd_display_{$device_id}{$table_suffix}{$rotation_suffix}.log";
}

/**
 * Log-Rotation verwalten - MIT TISCH-UNTERSTÜTZUNG
 */
function rotateLogFile($device_id, $table_id = null) {
    $current_file = getLogFileName($device_id, 0, $table_id);
    
    if (!file_exists($current_file)) {
        return $current_file;
    }
    
    if (filesize($current_file) < MAX_LOG_SIZE) {
        return $current_file;
    }
    
    // Rotation: Verschiebe vorhandene Dateien
    for ($i = MAX_LOG_FILES - 1; $i > 0; $i--) {
        $old_file = getLogFileName($device_id, $i - 1, $table_id);
        $new_file = getLogFileName($device_id, $i, $table_id);
        
        if (file_exists($old_file)) {
            if ($i == MAX_LOG_FILES - 1) {
                unlink($old_file); // Älteste Datei löschen
            } else {
                rename($old_file, $new_file);
            }
        }
    }
    
    // Neue leere Hauptdatei erstellen
    return $current_file;
}

/**
 * Log-Eintrag schreiben - KORRIGIERTE VERSION MIT VOLTAGE, MEMORY & TISCH
 */
function writeLogEntry($device_id, $action, $details = '', $input = []) {
    // Extrahiere Tisch-ID aus details oder input
    $table_id = extractTableId($input, $details);
    
    $log_file = rotateLogFile($device_id, $table_id);
    
    $timestamp = date('c'); // ISO 8601 Format
    $entry = [
        'timestamp' => $timestamp,
        'action' => $action,
        'details' => $details,
        'table_id' => $table_id,
        'uptime' => isset($input['uptime']) ? intval($input['uptime']) : null,
        'free_memory' => isset($input['free_memory']) ? intval($input['free_memory']) : null,
        'wifi_rssi' => isset($input['wifi_rssi']) ? intval($input['wifi_rssi']) : null,
        'voltage' => isset($input['voltage']) ? floatval($input['voltage']) : null
    ];
    
    $log_line = json_encode($entry) . "\n";
    
    if (file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX) !== false) {
        return ['success' => true, 'message' => 'Log entry written', 'file' => basename($log_file), 'table_id' => $table_id];
    } else {
        return ['success' => false, 'message' => 'Failed to write log entry'];
    }
}

/**
 * Tisch-ID aus Input oder Details extrahieren - ERWEITERT für flexible Formate
 */
function extractTableId($input, $details) {
    // 1. Priorität: Explizit übermittelte table_id
    if (isset($input['table_id']) && !empty($input['table_id'])) {
        return strtoupper($input['table_id']);
    }
    
    // 2. Priorität: Aus URL in details extrahieren - ERWEITERT für verschiedene Formate
    // Neue Pattern für verschiedene Tisch-Formate (KORRIGIERT - keine CRC-Hashes)
    $patterns = [
        '/([T]\d+)\.bmp/i',                          // T13.bmp
        '/([A-Z]*tisch[_\s]*\d+)/i',                 // Ecktisch_3, Ecktisch 3
        '/([A-Z]*tisch[_\s]*\d+[_\s]*[A-Z]*)/i',    // Ecktisch_3_imGarten
        '/(table[_\s]*\d+)/i',                       // table_5, table 5
        '/\b([A-Z]{2,}[_\s]*\d+)\b/i'                // CORNER_3, SIDE_12 (aber nicht fc5 aus CRC)
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $details, $matches)) {
            $table_match = strtoupper(str_replace([' ', '_'], '_', $matches[1]));
            // Normalisierung für bessere Lesbarkeit
            $table_match = preg_replace('/[_]+/', '_', $table_match); // Mehrfache _ reduzieren
            
            // FILTER: Keine CRC-Hashes (hex mit mehr als 4 Zeichen)
            if (!preg_match('/^[A-F0-9]{5,}$/', $table_match)) {
                return $table_match;
            }
        }
    }
    
    // 3. Priorität: Aus anderen details extrahieren
    if (preg_match('/tisch[:\s]*([T]?\d+)/i', $details, $matches)) {
        $table = $matches[1];
        return strpos($table, 'T') === 0 ? strtoupper($table) : 'T' . $table;
    }
    
    // Default: null (kein Tisch identifiziert)
    return null;
}

/**
 * Log-Statistiken generieren - MIT VOLTAGE-UNTERSTÜTZUNG & TISCH-GRUPPIERUNG
 */
function generateStats($device_id, $table_id = null) {
    $stats = [
        'device_id' => $device_id,
        'table_id' => $table_id,
        'total_entries' => 0,
        'first_entry' => null,
        'last_entry' => null,
        'actions' => [],
        'avg_sleep_duration' => 0,
        'total_wake_ups' => 0,
        'image_updates' => 0,
        'efficiency_ratio' => 0,
        'uptime_stats' => [
            'min' => null,
            'max' => null,
            'avg' => null
        ],
        'voltage_stats' => [
            'min' => null,
            'max' => null,
            'avg' => null
        ],
        'memory_stats' => [
            'min' => null,
            'max' => null,
            'avg' => null
        ],
        'table_stats' => []
    ];
    
    $all_entries = [];
    $table_groups = [];
    
    // Alle Log-Dateien durchsuchen
    for ($i = 0; $i < MAX_LOG_FILES; $i++) {
        $log_file = getLogFileName($device_id, $i, $table_id);
        if (!file_exists($log_file)) {
            // Fallback: Auch alte Dateien ohne table_id suchen
            $log_file_fallback = getLogFileName($device_id, $i, null);
            if (!file_exists($log_file_fallback)) continue;
            $log_file = $log_file_fallback;
        }
        
        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                // Filter nach table_id wenn angegeben
                if ($table_id === null || (isset($entry['table_id']) && $entry['table_id'] === $table_id)) {
                    $all_entries[] = $entry;
                }
                
                // Sammle Tisch-Statistiken
                $entry_table = $entry['table_id'] ?? 'UNKNOWN';
                if (!isset($table_groups[$entry_table])) {
                    $table_groups[$entry_table] = ['count' => 0, 'last_seen' => null];
                }
                $table_groups[$entry_table]['count']++;
                $table_groups[$entry_table]['last_seen'] = $entry['timestamp'];
            }
        }
    }
    
    $stats['total_entries'] = count($all_entries);
    $stats['table_stats'] = $table_groups;
    
    if (empty($all_entries)) {
        return $stats;
    }
    
    // Sortieren nach Zeitstempel
    usort($all_entries, function($a, $b) {
        return strtotime($a['timestamp']) - strtotime($b['timestamp']);
    });
    
    $stats['first_entry'] = $all_entries[0]['timestamp'];
    $stats['last_entry'] = end($all_entries)['timestamp'];
    
    // Aktionen zählen
    $sleep_durations = [];
    $uptimes = [];
    $voltages = [];
    $memories = [];
    
    foreach ($all_entries as $entry) {
        $action = $entry['action'];
        $stats['actions'][$action] = ($stats['actions'][$action] ?? 0) + 1;
        
        if ($action === 'WAKE_UP') {
            $stats['total_wake_ups']++;
        } elseif ($action === 'IMAGE_UPDATE') {
            $stats['image_updates']++;
        } elseif ($action === 'DEEP_SLEEP_START' && preg_match('/(\d+)s/', $entry['details'], $matches)) {
            $sleep_durations[] = intval($matches[1]);
        }
        
        if (isset($entry['uptime']) && $entry['uptime'] > 0) {
            $uptimes[] = $entry['uptime'];
        }
        
        // NEUE VOLTAGE-SAMMLUNG
        if (isset($entry['voltage']) && $entry['voltage'] > 0) {
            $voltages[] = floatval($entry['voltage']);
        }
        
        // NEUE MEMORY-SAMMLUNG
        if (isset($entry['free_memory']) && $entry['free_memory'] > 0) {
            $memories[] = intval($entry['free_memory']);
        }
    }
    
    // Durchschnittliche Sleep-Dauer
    if (!empty($sleep_durations)) {
        $stats['avg_sleep_duration'] = array_sum($sleep_durations) / count($sleep_durations);
    }
    
    // Effizienz-Ratio (Update Rate)
    if ($stats['total_wake_ups'] > 0) {
        $stats['efficiency_ratio'] = round(($stats['image_updates'] / $stats['total_wake_ups']) * 100, 2);
    }
    
    // Uptime-Statistiken
    if (!empty($uptimes)) {
        $stats['uptime_stats']['min'] = min($uptimes);
        $stats['uptime_stats']['max'] = max($uptimes);
        $stats['uptime_stats']['avg'] = round(array_sum($uptimes) / count($uptimes), 2);
    }
    
    // NEUE VOLTAGE-STATISTIKEN
    if (!empty($voltages)) {
        $stats['voltage_stats']['min'] = round(min($voltages), 2);
        $stats['voltage_stats']['max'] = round(max($voltages), 2);
        $stats['voltage_stats']['avg'] = round(array_sum($voltages) / count($voltages), 2);
        $stats['voltage_stats']['count'] = count($voltages);
    }
    
    // NEUE MEMORY-STATISTIKEN
    if (!empty($memories)) {
        $stats['memory_stats']['min'] = min($memories);
        $stats['memory_stats']['max'] = max($memories);
        $stats['memory_stats']['avg'] = round(array_sum($memories) / count($memories));
        $stats['memory_stats']['count'] = count($memories);
    }
    
    return $stats;
}

/**
 * Log-Datei zum Download bereitstellen - MIT TISCH-UNTERSTÜTZUNG
 */
function downloadLog($device_id, $table_id = null) {
    $log_file = getLogFileName($device_id, 0, $table_id);
    
    if (!file_exists($log_file)) {
        // Fallback: Versuche alte Datei ohne table_id
        $log_file = getLogFileName($device_id, 0, null);
        if (!file_exists($log_file)) {
            http_response_code(404);
            echo json_encode(['error' => 'Log file not found']);
            return;
        }
    }
    
    $table_suffix = $table_id ? "_table_{$table_id}" : '';
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="erd_display_' . $device_id . $table_suffix . '_' . date('Y-m-d_H-i-s') . '.log"');
    readfile($log_file);
}

// API-Endpunkt verarbeiten
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Log-Eintrag erstellen - KORRIGIERTE VERSION
    $input = json_decode(file_get_contents('php://input'), true);
    
    // DEBUG: Empfangene Daten loggen
    error_log("API DEBUG v2.0 - Received input: " . print_r($input, true));
    
    if (!$input || !isset($input['device_id']) || !isset($input['action'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: device_id, action']);
        exit();
    }
    
    $device_id = $input['device_id'];
    $action = $input['action'];
    $details = $input['details'] ?? '';
    
    if (!validateDeviceId($device_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid device_id format']);
        exit();
    }
    
    // KORRIGIERT: $input direkt übergeben statt $_POST manipulation
    $result = writeLogEntry($device_id, $action, $details, $input);
    
    if ($result['success']) {
        http_response_code(201);
    } else {
        http_response_code(500);
    }
    
    echo json_encode($result);
    
} elseif ($method === 'GET') {
    $device_id = $_GET['device_id'] ?? '';
    $table_id = $_GET['table_id'] ?? null;
    
    if (!$device_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing device_id parameter']);
        exit();
    }
    
    if (!validateDeviceId($device_id)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid device_id format']);
        exit();
    }
    
    if (isset($_GET['download'])) {
        // Log-Datei downloaden
        downloadLog($device_id, $table_id);
    } elseif (isset($_GET['stats'])) {
        // Statistiken anzeigen
        $stats = generateStats($device_id, $table_id);
        echo json_encode($stats, JSON_PRETTY_PRINT);
    } else {
        // API-Info anzeigen
        echo json_encode([
            'api' => 'ERD Display Logging API v2.2',
            'version' => '2.2 - WITH VOLTAGE, MEMORY & FLEXIBLE TABLE SUPPORT',
            'device_id' => $device_id,
            'table_id' => $table_id,
            'endpoints' => [
                'POST /' => 'Create log entry with voltage/memory/table',
                'GET /?device_id=X&stats=1' => 'Get statistics with voltage/memory/table',
                'GET /?device_id=X&table_id=T13&stats=1' => 'Get statistics for specific table',
                'GET /?device_id=X&download=1' => 'Download log file',
                'GET /?device_id=X&table_id=ECKTISCH_3_IMGARTEN&download=1' => 'Download table-specific log file'
            ],
            'supported_table_formats' => [
                'T13.bmp' => 'T13',
                'Ecktisch_3_imGarten.bmp' => 'ECKTISCH_3_IMGARTEN',
                'table_5.bmp' => 'TABLE_5',
                'CORNER_12.bmp' => 'CORNER_12'
            ],
            'log_file' => file_exists(getLogFileName($device_id, 0, $table_id)) ? basename(getLogFileName($device_id, 0, $table_id)) : 'not_created',
            'features' => [
                'voltage_logging' => true,
                'memory_logging' => true,
                'table_identification' => true,
                'flexible_table_names' => true,
                'table_specific_logs' => true,
                'statistics' => true,
                'debug_logging' => true
            ]
        ], JSON_PRETTY_PRINT);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
