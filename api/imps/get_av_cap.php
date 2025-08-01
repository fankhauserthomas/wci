<?php
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
            echo "✅ SUCCESS - NEW AVAILABILITY API\n";
            echo "Hut ID: " . $data['summary']['hutId'] . "\n";
            echo "Date Range: " . $data['summary']['dateRange'] . "\n";
            echo "Total Days: " . $data['summary']['totalDays'] . "\n";
            echo "Retrieved: " . $data['summary']['dataRetrieved'] . "\n";
            echo "API Endpoint: " . $data['summary']['apiEndpoint'] . "\n";
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
 * Save availability data to av_belegung table in both local and remote databases
 * @param array $availabilityData - Array of availability data from API
 * @param string $hutId - Hut ID
 * @return array - Result with success status and details
 */
function saveAvailabilityToDatabase($availabilityData, $hutId) {
    global $mysqli, $dbHost, $dbUser, $dbPass, $dbName, $remoteDbHost, $remoteDbUser, $remoteDbPass, $remoteDbName;
    
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
            // Try alternative format without timezone
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
            
            // Debug: Show first few entries
            global $isCLI;
            if ($isCLI && $index < 3) {
                echo "📊 PROCESSING DAY " . ($index + 1) . ":\n";
                echo "Date: {$mysqlDate}\n";
                echo "Free Beds: " . ($dayData['freeBeds'] ?? 'N/A') . "\n";
                echo "Categories RAW: " . json_encode($freeBedsPerCategory) . "\n";
                echo "kat_1958: {$kat_1958}, kat_2293: {$kat_2293}, kat_2381: {$kat_2381}, kat_6106: {$kat_6106}\n\n";
            }
            
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
    
    // Function to perform database operations
    $performDbOperation = function($connection, $dbType) use ($insertData, $hutId, $dateList, &$results) {
        try {
            // Start transaction
            $connection->begin_transaction();
            
            // Check if table has all required columns
            $checkColumnsQuery = "SHOW COLUMNS FROM av_belegung";
            $checkResult = $connection->query($checkColumnsQuery);
            $existingColumns = [];
            
            if ($checkResult) {
                while ($row = $checkResult->fetch_assoc()) {
                    $existingColumns[] = $row['Field'];
                }
            }
            
            $hasHutIdColumn = in_array('hut_id', $existingColumns);
            $hasCategoryColumns = in_array('kat_1958', $existingColumns) && 
                                 in_array('kat_2293', $existingColumns) && 
                                 in_array('kat_2381', $existingColumns) && 
                                 in_array('kat_6106', $existingColumns);
            
            // Delete existing data for the date range we're updating
            $dateListStr = "'" . implode("','", $dateList) . "'";
            
            if ($hasHutIdColumn) {
                $deleteQuery = "DELETE FROM av_belegung WHERE hut_id = ? AND datum IN ($dateListStr)";
                $deleteStmt = $connection->prepare($deleteQuery);
                if (!$deleteStmt) {
                    throw new Exception("Prepare delete failed: " . $connection->error);
                }
                $deleteStmt->bind_param('s', $hutId);
                $deleteStmt->execute();
                $deleteStmt->close();
            } else {
                $deleteQuery = "DELETE FROM av_belegung WHERE datum IN ($dateListStr)";
                $connection->query($deleteQuery);
            }
            
            // Prepare insert statement based on available columns
            $insertedCount = 0;
            
            if ($hasHutIdColumn && $hasCategoryColumns) {
                // Full insert with hut_id and category columns
                $insertStmt = $connection->prepare("INSERT INTO av_belegung (hut_id, datum, free_place, hut_status, kat_1958, kat_2293, kat_2381, kat_6106) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$insertStmt) {
                    throw new Exception("Prepare full insert failed: " . $connection->error);
                }
                
                foreach ($insertData as $data) {
                    $insertStmt->bind_param('ssisiiii', 
                        $hutId, 
                        $data['datum'], 
                        $data['free_place'], 
                        $data['hut_status'],
                        $data['kat_1958'],
                        $data['kat_2293'],
                        $data['kat_2381'],
                        $data['kat_6106']
                    );
                    if ($insertStmt->execute()) {
                        $insertedCount++;
                    } else {
                        throw new Exception("Insert failed: " . $insertStmt->error);
                    }
                }
                $insertStmt->close();
                
            } elseif ($hasHutIdColumn) {
                // Insert with hut_id but without category columns
                $insertStmt = $connection->prepare("INSERT INTO av_belegung (hut_id, datum, free_place, hut_status) VALUES (?, ?, ?, ?)");
                if (!$insertStmt) {
                    throw new Exception("Prepare insert with hut_id failed: " . $connection->error);
                }
                
                foreach ($insertData as $data) {
                    $insertStmt->bind_param('ssis', $hutId, $data['datum'], $data['free_place'], $data['hut_status']);
                    if ($insertStmt->execute()) {
                        $insertedCount++;
                    } else {
                        throw new Exception("Insert failed: " . $insertStmt->error);
                    }
                }
                $insertStmt->close();
                
            } else {
                // Basic insert without hut_id or category columns
                $insertStmt = $connection->prepare("INSERT INTO av_belegung (datum, free_place, hut_status) VALUES (?, ?, ?)");
                if (!$insertStmt) {
                    throw new Exception("Prepare basic insert failed: " . $connection->error);
                }
                
                foreach ($insertData as $data) {
                    $insertStmt->bind_param('sis', $data['datum'], $data['free_place'], $data['hut_status']);
                    if ($insertStmt->execute()) {
                        $insertedCount++;
                    } else {
                        throw new Exception("Insert failed: " . $insertStmt->error);
                    }
                }
                $insertStmt->close();
            }
            
            // Commit transaction
            $connection->commit();
            
            $results[$dbType] = true;
            $results[$dbType . '_count'] = $insertedCount;
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

try {
    // Get parameters (works for both CLI and web)
    $hutId = getParameter('hutID') ?: getParameter('hutId'); // Support both formats
    
    // Show usage for CLI if no parameters provided
    if ($isCLI && !$hutId) {
        echo "🏔️  HUT AVAILABILITY API - NEW ENDPOINT\n";
        echo "=====================================\n\n";
        echo "Usage:\n";
        echo "  php get_av_cap_new.php --hutID=675\n";
        echo "  php get_av_cap_new.php --hutId=675\n\n";
        echo "Parameters:\n";
        echo "  --hutID or --hutId   Hut ID (required)\n\n";
        echo "Features:\n";
        echo "  • Fetches 1 year of availability data\n";
        echo "  • Includes detailed category breakdown\n";
        echo "  • Supports both CLI and web execution\n";
        echo "  • Automatic database saving (local + remote)\n";
        echo "  • Enhanced debug output\n\n";
        echo "API Endpoint: https://www.hut-reservation.org/api/v1/reservation/getHutAvailability\n";
        exit(1);
    }
    
    // Validate required parameters
    if (!$hutId) {
        throw new Exception('Parameter hutID (or hutId) is required');
    }
    
    // Validate hutId format (should be numeric)
    if (!is_numeric($hutId)) {
        throw new Exception('hutID must be numeric');
    }
    
    // Prepare API URL with parameters
    $apiUrl = "https://www.hut-reservation.org/api/v1/reservation/getHutAvailability?hutId={$hutId}&step=WIZARD";
    
    // Debug output for CLI
    if ($isCLI) {
        echo "🔍 DEBUG INFO:\n";
        echo "Hut ID: {$hutId}\n";
        echo "API URL: {$apiUrl}\n";
        echo "Fetching data...\n\n";
    }
    
    // Initialize cURL
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45, // Longer timeout for yearly data
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: WCI-New-Availability-Checker/1.0'
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
        echo "HTTP Code: {$httpCode}\n";
        echo "Response Size: " . number_format($responseSize) . " bytes\n";
        if ($curlError) {
            echo "cURL Error: {$curlError}\n";
        }
        echo "\n";
    }
    
    // Check for cURL errors
    if ($response === false || !empty($curlError)) {
        throw new Exception('API request failed: ' . $curlError);
    }
    
    // Check HTTP status code
    if ($httpCode !== 200) {
        // Try to decode error response for better error handling
        $errorData = json_decode($response, true);
        $errorMessage = "API returned HTTP {$httpCode}";
        
        if ($errorData && isset($errorData['description'])) {
            $errorMessage .= " - " . $errorData['description'];
            if (isset($errorData['messageId'])) {
                $errorMessage .= " (Error ID: " . $errorData['messageId'] . ")";
            }
        } else {
            $errorMessage .= ". Raw response: " . substr($response, 0, 500);
        }
        
        // For specific error codes, provide more context
        if ($httpCode === 500) {
            $errorMessage .= ". This might be due to: invalid hutId, server issues, or API changes.";
        } elseif ($httpCode === 404) {
            $errorMessage .= ". Check if hutId '{$hutId}' exists.";
        } elseif ($httpCode === 400) {
            $errorMessage .= ". Check request parameters.";
        }
        
        throw new Exception($errorMessage);
    }
    
    // Decode JSON response
    $apiData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from API: ' . json_last_error_msg());
    }
    
    // Validate response structure
    if (!is_array($apiData)) {
        throw new Exception('API response is not an array');
    }
    
    if (empty($apiData)) {
        throw new Exception('API returned empty data');
    }
    
    // Debug: Show sample data structure
    if ($isCLI && count($apiData) > 0) {
        echo "📋 SAMPLE DATA STRUCTURE:\n";
        $sample = $apiData[0];
        echo "Date: " . ($sample['date'] ?? 'N/A') . "\n";
        echo "Date Formatted: " . ($sample['dateFormatted'] ?? 'N/A') . "\n";
        echo "Free Beds: " . ($sample['freeBeds'] ?? 'N/A') . "\n";
        echo "Hut Status: " . ($sample['hutStatus'] ?? 'N/A') . "\n";
        if (isset($sample['freeBedsPerCategory'])) {
            echo "Categories RAW: " . json_encode($sample['freeBedsPerCategory']) . "\n";
            echo "Category 1958: " . ($sample['freeBedsPerCategory']['1958'] ?? 'N/A') . "\n";
            echo "Category 2293: " . ($sample['freeBedsPerCategory']['2293'] ?? 'N/A') . "\n";
            echo "Category 2381: " . ($sample['freeBedsPerCategory']['2381'] ?? 'N/A') . "\n";
            echo "Category 6106: " . ($sample['freeBedsPerCategory']['6106'] ?? 'N/A') . "\n";
        }
        echo "\n";
    }
    
    // Save availability data to database
    $dbSaveResult = saveAvailabilityToDatabase($apiData, $hutId);
    
    // Calculate date range
    $dates = array_map(function($item) {
        $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $item['date']);
        if (!$dateObj) {
            $dateObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $item['date']);
        }
        return $dateObj ? $dateObj->format('Y-m-d') : null;
    }, $apiData);
    $dates = array_filter($dates);
    sort($dates);
    
    $dateRange = count($dates) > 0 ? reset($dates) . ' - ' . end($dates) : 'Unknown';
    
    // Prepare successful response with summary
    $result = [
        'success' => true,
        'database' => $dbSaveResult,
        'summary' => [
            'hutId' => $hutId,
            'dateRange' => $dateRange,
            'totalDays' => count($apiData),
            'dataRetrieved' => date('Y-m-d H:i:s'),
            'apiEndpoint' => 'NEW - getHutAvailability with categories',
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
            'apiEndpoint' => 'https://www.hut-reservation.org/api/v1/reservation/getHutAvailability'
        ],
        'suggestions' => [
            'Check if hutId exists and is numeric',
            'Verify the API endpoint is accessible',
            'Try again - the API might be temporarily unavailable',
            'Check if the database has the required columns (kat_1958, kat_2293, kat_2381, kat_6106)'
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
