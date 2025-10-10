<?php
/**
 * HUT AVAILABILITY API - DATE RANGE VERSION
 * 
 * Fetches availability data for a specific date range from HRS API
 * and saves it to the av_belegung table in both local and remote databases.
 * 
 * Key Features:
 * - Accepts von (from) and bis (to) date parameters
 * - Automatically adjusts API calls to ensure full date coverage
 * - API returns minimum 10-11 days, so requests are optimized accordingly
 * - Supports both CLI and web execution
 * - Filters results to only save data within the requested range
 * 
 * Usage (Web):
 *   get_av_cap_range.php?hutID=675&von=2025-01-01&bis=2025-01-15
 * 
 * Usage (CLI):
 *   php get_av_cap_range.php --hutID=675 --von=2025-01-01 --bis=2025-01-15
 */

// Include database configuration
require_once __DIR__ . '/../../config.php';

// Detect execution environment (CLI or Web)
$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    // Disable error display for clean JSON output (Web only)
    error_reporting(0);
    ini_set('display_errors', 0);
    
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
} else {
    // Enable error display for CLI
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

/**
 * Get parameter from either CLI arguments or HTTP GET parameters
 * @param string $name - Parameter name
 * @param mixed $default - Default value if parameter not found
 * @return mixed - Parameter value
 */
function getParameter($name, $default = null) {
    global $isCLI, $argv;
    
    if ($isCLI) {
        // CLI mode: parse command line arguments
        // Support both --name=value and --name value formats
        if (isset($argv)) {
            for ($i = 1; $i < count($argv); $i++) {
                if (strpos($argv[$i], "--$name=") === 0) {
                    return substr($argv[$i], strlen("--$name="));
                } elseif ($argv[$i] === "--$name" && isset($argv[$i + 1])) {
                    return $argv[$i + 1];
                }
            }
        }
        return $default;
    } else {
        // Web mode: use GET parameters
        return $_GET[$name] ?? $default;
    }
}

/**
 * Output response in appropriate format (JSON for web, formatted text for CLI)
 * @param array $data - Data to output
 * @param int $httpCode - HTTP status code (ignored in CLI mode)
 */
function outputResponse($data, $httpCode = 200) {
    global $isCLI;
    
    if ($isCLI) {
        // CLI output: formatted text
        if ($data['success']) {
            echo "✅ SUCCESS - AVAILABILITY API (DATE RANGE)\n";
            echo "Hut ID: " . $data['summary']['hutId'] . "\n";
            echo "Requested Range: " . $data['summary']['requestedRange'] . "\n";
            echo "Retrieved Range: " . $data['summary']['retrievedRange'] . "\n";
            echo "Saved Range: " . $data['summary']['savedRange'] . "\n";
            echo "Total Days Retrieved: " . $data['summary']['totalDaysRetrieved'] . "\n";
            echo "Total Days Saved: " . $data['summary']['totalDaysSaved'] . "\n";
            echo "API Calls: " . $data['summary']['apiCalls'] . "\n";
            echo "\n";
            
            echo "📊 DATABASE RESULTS:\n";
            $db = $data['database'];
            echo "Local DB: " . ($db['local'] ? "✅ Success ({$db['local_count']} records)" : "❌ Failed") . "\n";
            echo "Remote DB: " . ($db['remote'] ? "✅ Success ({$db['remote_count']} records)" : "❌ Failed") . "\n";
            
            // Show category statistics
            if (isset($data['summary']['categoryStats'])) {
                echo "\n🏠 CATEGORY STATISTICS:\n";
                $stats = $data['summary']['categoryStats'];
                echo "Category 1958: avg " . round($stats['kat_1958']['avg'], 1) . " (max: {$stats['kat_1958']['max']})\n";
                echo "Category 2293: avg " . round($stats['kat_2293']['avg'], 1) . " (max: {$stats['kat_2293']['max']})\n";
                echo "Category 2381: avg " . round($stats['kat_2381']['avg'], 1) . " (max: {$stats['kat_2381']['max']})\n";
                echo "Category 6106: avg " . round($stats['kat_6106']['avg'], 1) . " (max: {$stats['kat_6106']['max']})\n";
            }
            
            if (!empty($db['errors'])) {
                echo "\n⚠️  ERRORS:\n";
                foreach ($db['errors'] as $error) {
                    echo "- $error\n";
                }
            }
        } else {
            echo "❌ ERROR: " . $data['error'] . "\n";
            if (isset($data['suggestions'])) {
                echo "\n💡 SUGGESTIONS:\n";
                foreach ($data['suggestions'] as $suggestion) {
                    echo "- $suggestion\n";
                }
            }
        }
    } else {
        // Web output: JSON
        if (!headers_sent()) {
            http_response_code($httpCode);
        }
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

/**
 * Calculate API request dates to ensure full coverage of requested range
 * 
 * Since the API returns minimum 10-11 days, we need to optimize requests:
 * - For ranges <= 11 days: 1 API call starting from 'von'
 * - For ranges > 11 days: Multiple API calls with 11-day steps
 * 
 * @param string $vonDate - Start date (YYYY-MM-DD)
 * @param string $bisDate - End date (YYYY-MM-DD)
 * @return array - Array of dates to request from API
 */
function calculateApiRequestDates($vonDate, $bisDate) {
    // WICHTIG: Die HRS-API ignoriert den 'from' Parameter komplett!
    // Sie gibt IMMER ~466 Tage ab HEUTE zurück, unabhängig vom angeforderten Datum.
    // Daher benötigen wir nur EINEN API-Call und filtern die Daten clientseitig.
    
    global $isCLI;
    if ($isCLI) {
        echo "ℹ️  API gibt ~466 Tage ab heute zurück (from-Parameter wird ignoriert)\n";
    }
    
    $requestDates = [];
    $requestDates[] = $vonDate; // Senden trotzdem das Datum für zukünftige API-Versionen
    
    return $requestDates;
}

/**
 * Fetch availability data from HRS API for a specific date
 * 
 * WICHTIG: Diese API ignoriert den 'from' Parameter und gibt immer ~466 Tage ab HEUTE zurück!
 * Die Filterung auf den gewünschten Zeitraum erfolgt clientseitig.
 * 
 * @param string $hutId - Hut ID
 * @param string $fromDate - Start date (wird von API ignoriert, aber trotzdem mitgesendet)
 * @return array - API response data (~466 Tage ab heute)
 */
function fetchAvailabilityFromApi($hutId, $fromDate) {
    global $isCLI;
    
    // Prepare API URL with parameters
    // API uses 'from' parameter to specify start date
    $apiUrl = "https://www.hut-reservation.org/api/v1/reservation/getHutAvailability?hutId={$hutId}&step=WIZARD&from={$fromDate}";
    
    // Debug output for CLI
    if ($isCLI) {
        echo "🔍 API Request: {$apiUrl}\n";
    }
    
    // Initialize cURL
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: WCI-Availability-Range-Checker/1.0'
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $responseSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    
    // Debug output for CLI
    if ($isCLI) {
        echo "  ↪ HTTP {$httpCode}, Size: " . number_format($responseSize) . " bytes\n";
    }
    
    // Check for cURL errors
    if ($response === false || !empty($curlError)) {
        throw new Exception('API request failed: ' . $curlError);
    }
    
    // Check HTTP status code
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = "API returned HTTP {$httpCode}";
        
        if ($errorData && isset($errorData['description'])) {
            $errorMessage .= " - " . $errorData['description'];
        }
        
        throw new Exception($errorMessage);
    }
    
    // Decode JSON response
    $apiData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from API: ' . json_last_error_msg());
    }
    
    if (!is_array($apiData) || empty($apiData)) {
        throw new Exception('API returned empty or invalid data');
    }
    
    return $apiData;
}

/**
 * Filter API data to only include dates within the requested range
 * 
 * @param array $apiData - Full API response data
 * @param string $vonDate - Start date (YYYY-MM-DD)
 * @param string $bisDate - End date (YYYY-MM-DD)
 * @return array - Filtered data
 */
function filterDataByDateRange($apiData, $vonDate, $bisDate) {
    $von = new DateTime($vonDate);
    $bis = new DateTime($bisDate);
    
    $filteredData = [];
    
    foreach ($apiData as $dayData) {
        // Parse date from API response
        $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dayData['date']);
        if (!$dateObj) {
            $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $dayData['date']);
        }
        
        if ($dateObj) {
            // Check if date is within range
            if ($dateObj >= $von && $dateObj <= $bis) {
                $filteredData[] = $dayData;
            }
        }
    }
    
    return $filteredData;
}

/**
 * Save availability data to av_belegung table in both local and remote databases
 * @param array $availabilityData - Array of availability data from API
 * @param string $hutId - Hut ID
 * @return array - Result with success status and details
 */
function saveAvailabilityToDatabase($availabilityData, $hutId) {
    global $mysqli, $dbHost, $dbUser, $dbPass, $dbName, $remoteDbHost, $remoteDbUser, $remoteDbPass, $remoteDbName, $isCLI;
    
    $results = ['local' => false, 'remote' => false, 'errors' => []];
    
    // Prepare the data for insertion
    $insertData = [];
    $dateList = [];
    $categoryStats = [
        'kat_1958' => ['total' => 0, 'count' => 0, 'max' => 0],
        'kat_2293' => ['total' => 0, 'count' => 0, 'max' => 0],
        'kat_2381' => ['total' => 0, 'count' => 0, 'max' => 0],
        'kat_6106' => ['total' => 0, 'count' => 0, 'max' => 0]
    ];
    
    foreach ($availabilityData as $index => $dayData) {
        // Convert ISO date format to MySQL date format
        $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dayData['date']);
        if (!$dateObj) {
            $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $dayData['date']);
        }
        
        if ($dateObj) {
            $mysqlDate = $dateObj->format('Y-m-d');
            
            // Extract category data
            $freeBedsPerCategory = $dayData['freeBedsPerCategory'] ?? [];
            $kat_1958 = (int)($freeBedsPerCategory['1958'] ?? 0);
            $kat_2293 = (int)($freeBedsPerCategory['2293'] ?? 0);
            $kat_2381 = (int)($freeBedsPerCategory['2381'] ?? 0);
            $kat_6106 = (int)($freeBedsPerCategory['6106'] ?? 0);
            
            $insertData[] = [
                'datum' => $mysqlDate,
                'free_place' => (int)($dayData['freeBeds'] ?? 0),
                'hut_status' => $dayData['hutStatus'] ?? 'UNKNOWN',
                'kat_1958' => $kat_1958,
                'kat_2293' => $kat_2293,
                'kat_2381' => $kat_2381,
                'kat_6106' => $kat_6106
            ];
            $dateList[] = $mysqlDate;
            
            // Update statistics
            $categoryStats['kat_1958']['total'] += $kat_1958;
            $categoryStats['kat_1958']['count']++;
            $categoryStats['kat_1958']['max'] = max($categoryStats['kat_1958']['max'], $kat_1958);
            
            $categoryStats['kat_2293']['total'] += $kat_2293;
            $categoryStats['kat_2293']['count']++;
            $categoryStats['kat_2293']['max'] = max($categoryStats['kat_2293']['max'], $kat_2293);
            
            $categoryStats['kat_2381']['total'] += $kat_2381;
            $categoryStats['kat_2381']['count']++;
            $categoryStats['kat_2381']['max'] = max($categoryStats['kat_2381']['max'], $kat_2381);
            
            $categoryStats['kat_6106']['total'] += $kat_6106;
            $categoryStats['kat_6106']['count']++;
            $categoryStats['kat_6106']['max'] = max($categoryStats['kat_6106']['max'], $kat_6106);
        }
    }
    
    if (empty($insertData)) {
        $results['errors'][] = 'No valid data to insert';
        return $results;
    }
    
    // Calculate averages
    foreach ($categoryStats as $key => &$stat) {
        $stat['avg'] = $stat['count'] > 0 ? $stat['total'] / $stat['count'] : 0;
    }
    $results['categoryStats'] = $categoryStats;
    
    $minDate = min($dateList);
    $maxDate = max($dateList);
    
    // Database operation closure for both local and remote DB
    $performDbOperation = function($connection, $dbType) use (&$results, $insertData, $minDate, $maxDate, $hutId) {
        try {
            // Check if table exists
            $tableCheck = $connection->query("SHOW TABLES LIKE 'av_belegung'");
            if ($tableCheck->num_rows === 0) {
                throw new Exception("Table 'av_belegung' does not exist");
            }
            
            // Check which columns exist
            $columnsResult = $connection->query("SHOW COLUMNS FROM av_belegung");
            $existingColumns = [];
            while ($row = $columnsResult->fetch_assoc()) {
                $existingColumns[] = $row['Field'];
            }
            
            $hasHutIdColumn = in_array('hut_id', $existingColumns);
            $hasCategoryColumns = in_array('kat_1958', $existingColumns) && 
                                 in_array('kat_2293', $existingColumns) && 
                                 in_array('kat_2381', $existingColumns) && 
                                 in_array('kat_6106', $existingColumns);
            
            // Begin transaction
            $connection->begin_transaction();
            
            // Delete existing data in date range (optional: only if hut_id column exists)
            if ($hasHutIdColumn) {
                $deleteStmt = $connection->prepare("DELETE FROM av_belegung WHERE datum >= ? AND datum <= ? AND hut_id = ?");
                $deleteStmt->bind_param('ssi', $minDate, $maxDate, $hutId);
            } else {
                $deleteStmt = $connection->prepare("DELETE FROM av_belegung WHERE datum >= ? AND datum <= ?");
                $deleteStmt->bind_param('ss', $minDate, $maxDate);
            }
            
            if (!$deleteStmt->execute()) {
                throw new Exception("Delete failed: " . $deleteStmt->error);
            }
            $deletedCount = $deleteStmt->affected_rows;
            $deleteStmt->close();
            
            // Prepare INSERT statement based on available columns
            if ($hasHutIdColumn && $hasCategoryColumns) {
                $insertSql = "INSERT INTO av_belegung (datum, free_place, hut_status, hut_id, kat_1958, kat_2293, kat_2381, kat_6106) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            } elseif ($hasHutIdColumn) {
                $insertSql = "INSERT INTO av_belegung (datum, free_place, hut_status, hut_id) VALUES (?, ?, ?, ?)";
            } elseif ($hasCategoryColumns) {
                $insertSql = "INSERT INTO av_belegung (datum, free_place, hut_status, kat_1958, kat_2293, kat_2381, kat_6106) 
                             VALUES (?, ?, ?, ?, ?, ?, ?)";
            } else {
                $insertSql = "INSERT INTO av_belegung (datum, free_place, hut_status) VALUES (?, ?, ?)";
            }
            
            $insertStmt = $connection->prepare($insertSql);
            if (!$insertStmt) {
                throw new Exception("Prepare failed: " . $connection->error);
            }
            
            // Insert all data
            $insertedCount = 0;
            foreach ($insertData as $data) {
                if ($hasHutIdColumn && $hasCategoryColumns) {
                    $insertStmt->bind_param('siisiiii', 
                        $data['datum'], $data['free_place'], $data['hut_status'], $hutId,
                        $data['kat_1958'], $data['kat_2293'], $data['kat_2381'], $data['kat_6106']
                    );
                } elseif ($hasHutIdColumn) {
                    $insertStmt->bind_param('sisi', $data['datum'], $data['free_place'], $data['hut_status'], $hutId);
                } elseif ($hasCategoryColumns) {
                    $insertStmt->bind_param('sisiiii', 
                        $data['datum'], $data['free_place'], $data['hut_status'],
                        $data['kat_1958'], $data['kat_2293'], $data['kat_2381'], $data['kat_6106']
                    );
                } else {
                    $insertStmt->bind_param('sis', $data['datum'], $data['free_place'], $data['hut_status']);
                }
                
                if ($insertStmt->execute()) {
                    $insertedCount++;
                } else {
                    throw new Exception("Insert failed: " . $insertStmt->error);
                }
            }
            $insertStmt->close();
            
            // Commit transaction
            $connection->commit();
            
            $results[$dbType] = true;
            $results[$dbType . '_count'] = $insertedCount;
            $results[$dbType . '_deleted'] = $deletedCount;
            $results[$dbType . '_has_hut_id'] = $hasHutIdColumn;
            $results[$dbType . '_has_categories'] = $hasCategoryColumns;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $connection->rollback();
            $results['errors'][] = "$dbType DB error: " . $e->getMessage();
        }
    };
    
    // Save to local database
    if ($mysqli && $mysqli->ping()) {
        $performDbOperation($mysqli, 'local');
    } else {
        $results['errors'][] = 'Local database connection not available';
    }
    
    // Save to remote database
    try {
        $remoteMysqli = new mysqli($remoteDbHost, $remoteDbUser, $remoteDbPass, $remoteDbName);
        if ($remoteMysqli->connect_error) {
            $results['errors'][] = 'Remote database connection failed: ' . $remoteMysqli->connect_error;
        } else {
            $remoteMysqli->set_charset('utf8mb4');
            $performDbOperation($remoteMysqli, 'remote');
            $remoteMysqli->close();
        }
    } catch (Exception $e) {
        $results['errors'][] = 'Remote database error: ' . $e->getMessage();
    }
    
    return $results;
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

try {
    // Get parameters (works for both CLI and web)
    $hutId = getParameter('hutID') ?: getParameter('hutId');
    $vonDate = getParameter('von');
    $bisDate = getParameter('bis');
    
    // Show usage for CLI if no parameters provided
    if ($isCLI && (!$hutId || !$vonDate || !$bisDate)) {
        echo "🏔️  HUT AVAILABILITY API - DATE RANGE VERSION\n";
        echo "=============================================\n\n";
        echo "Usage:\n";
        echo "  php get_av_cap_range.php --hutID=675 --von=2025-01-01 --bis=2025-01-15\n\n";
        echo "Parameters:\n";
        echo "  --hutID or --hutId   Hut ID (required)\n";
        echo "  --von                Start date YYYY-MM-DD (required)\n";
        echo "  --bis                End date YYYY-MM-DD (required)\n\n";
        echo "Features:\n";
        echo "  • Fetches data only for the specified date range\n";
        echo "  • Optimizes API calls (API returns min. 10-11 days)\n";
        echo "  • Filters results to requested range\n";
        echo "  • Includes detailed category breakdown\n";
        echo "  • Supports both CLI and web execution\n";
        echo "  • Automatic database saving (local + remote)\n\n";
        echo "Examples:\n";
        echo "  # Single week\n";
        echo "  php get_av_cap_range.php --hutID=675 --von=2025-01-01 --bis=2025-01-07\n\n";
        echo "  # Full month\n";
        echo "  php get_av_cap_range.php --hutID=675 --von=2025-01-01 --bis=2025-01-31\n\n";
        exit(1);
    }
    
    // Validate required parameters
    if (!$hutId) {
        throw new Exception('Parameter hutID (or hutId) is required');
    }
    if (!$vonDate) {
        throw new Exception('Parameter von (start date) is required');
    }
    if (!$bisDate) {
        throw new Exception('Parameter bis (end date) is required');
    }
    
    // Validate date formats
    $vonDateTime = DateTime::createFromFormat('Y-m-d', $vonDate);
    $bisDateTime = DateTime::createFromFormat('Y-m-d', $bisDate);
    
    if (!$vonDateTime || $vonDateTime->format('Y-m-d') !== $vonDate) {
        throw new Exception('Invalid von date format. Use YYYY-MM-DD');
    }
    if (!$bisDateTime || $bisDateTime->format('Y-m-d') !== $bisDate) {
        throw new Exception('Invalid bis date format. Use YYYY-MM-DD');
    }
    
    // Validate date range
    if ($vonDateTime > $bisDateTime) {
        throw new Exception('von date must be before or equal to bis date');
    }
    
    // Validate hutId format
    if (!is_numeric($hutId)) {
        throw new Exception('hutID must be numeric');
    }
    
    // Debug output for CLI
    if ($isCLI) {
        echo "🔍 DEBUG INFO:\n";
        echo "Hut ID: {$hutId}\n";
        echo "Requested Range: {$vonDate} - {$bisDate}\n";
        $interval = $vonDateTime->diff($bisDateTime);
        echo "Total Days: " . ($interval->days + 1) . "\n\n";
    }
    
    // Calculate optimal API request dates
    $apiRequestDates = calculateApiRequestDates($vonDate, $bisDate);
    
    if ($isCLI) {
        echo "📅 API STRATEGY:\n";
        echo "API Calls Required: " . count($apiRequestDates) . "\n";
        echo "Request Dates: " . implode(', ', $apiRequestDates) . "\n\n";
    }
    
    // Fetch data from API for each request date
    $allApiData = [];
    $apiCallCount = 0;
    
    foreach ($apiRequestDates as $requestDate) {
        $apiData = fetchAvailabilityFromApi($hutId, $requestDate);
        $allApiData = array_merge($allApiData, $apiData);
        $apiCallCount++;
        
        if ($isCLI) {
            echo "  ✓ Retrieved " . count($apiData) . " days\n";
        }
    }
    
    if ($isCLI) {
        echo "\n📦 TOTAL DATA RETRIEVED: " . count($allApiData) . " days\n\n";
    }
    
    // Remove duplicates (in case of overlapping data)
    $uniqueData = [];
    $seenDates = [];
    foreach ($allApiData as $dayData) {
        $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $dayData['date']);
        if (!$dateObj) {
            $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $dayData['date']);
        }
        
        if ($dateObj) {
            $dateKey = $dateObj->format('Y-m-d');
            if (!isset($seenDates[$dateKey])) {
                $seenDates[$dateKey] = true;
                $uniqueData[] = $dayData;
            }
        }
    }
    
    if ($isCLI && count($uniqueData) < count($allApiData)) {
        echo "🔄 Removed " . (count($allApiData) - count($uniqueData)) . " duplicate days\n\n";
    }
    
    // Filter data to requested date range
    $filteredData = filterDataByDateRange($uniqueData, $vonDate, $bisDate);
    
    if ($isCLI) {
        echo "✂️  FILTERED TO RANGE: " . count($filteredData) . " days\n\n";
    }
    
    if (empty($filteredData)) {
        throw new Exception('No data available for the requested date range');
    }
    
    // Calculate actual saved date range
    $savedDates = array_map(function($item) {
        $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $item['date']);
        if (!$dateObj) {
            $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $item['date']);
        }
        return $dateObj ? $dateObj->format('Y-m-d') : null;
    }, $filteredData);
    $savedDates = array_filter($savedDates);
    sort($savedDates);
    
    $savedRange = count($savedDates) > 0 ? reset($savedDates) . ' - ' . end($savedDates) : 'Unknown';
    
    // Calculate retrieved date range
    $retrievedDates = array_map(function($item) {
        $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $item['date']);
        if (!$dateObj) {
            $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $item['date']);
        }
        return $dateObj ? $dateObj->format('Y-m-d') : null;
    }, $uniqueData);
    $retrievedDates = array_filter($retrievedDates);
    sort($retrievedDates);
    
    $retrievedRange = count($retrievedDates) > 0 ? reset($retrievedDates) . ' - ' . end($retrievedDates) : 'Unknown';
    
    // Save filtered data to database
    if ($isCLI) {
        echo "💾 SAVING TO DATABASE...\n";
    }
    
    $dbSaveResult = saveAvailabilityToDatabase($filteredData, $hutId);
    
    // Prepare successful response
    $result = [
        'success' => true,
        'database' => $dbSaveResult,
        'summary' => [
            'hutId' => $hutId,
            'requestedRange' => "{$vonDate} - {$bisDate}",
            'retrievedRange' => $retrievedRange,
            'savedRange' => $savedRange,
            'totalDaysRetrieved' => count($uniqueData),
            'totalDaysSaved' => count($filteredData),
            'apiCalls' => $apiCallCount,
            'dataRetrieved' => date('Y-m-d H:i:s'),
            'categoryStats' => $dbSaveResult['categoryStats'] ?? null
        ]
    ];
    
    // Output response in appropriate format
    outputResponse($result);
    
} catch (Exception $e) {
    // Error response
    $errorData = [
        'success' => false,
        'error' => $e->getMessage(),
        'request' => [
            'hutId' => $hutId ?? null,
            'von' => $vonDate ?? null,
            'bis' => $bisDate ?? null
        ],
        'suggestions' => [
            'Check if hutId exists and is numeric',
            'Verify date format is YYYY-MM-DD',
            'Ensure von date is before or equal to bis date',
            'Verify the API endpoint is accessible',
            'Check if the database has the required columns'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    outputResponse($errorData, 400);
    
} catch (Error $e) {
    // Fatal error response
    $fatalErrorData = [
        'success' => false,
        'error' => 'Fatal error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    outputResponse($fatalErrorData, 500);
}
?>
