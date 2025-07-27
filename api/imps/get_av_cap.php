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
            echo "✅ SUCCESS\n";
            echo "Hut ID: " . $data['summary']['hutId'] . "\n";
            echo "Date Range: " . $data['summary']['dateRange'] . "\n";
            echo "Total Days: " . $data['summary']['totalDays'] . "\n";
            echo "Retrieved: " . $data['summary']['dataRetrieved'] . "\n";
            
            if (isset($data['summary']['parametersUsed'])) {
                $params = $data['summary']['parametersUsed'];
                if ($params['days']) {
                    echo "Days Parameter: " . $params['days'] . "\n";
                }
                if ($params['del_old']) {
                    echo "Deleted Old Records: " . $data['summary']['deletedOldRecords'] . "\n";
                }
            }
            echo "\n";
            
            echo "📊 DATABASE RESULTS:\n";
            $db = $data['database'];
            echo "Local DB: " . ($db['local'] ? "✅ Success ({$db['local_count']} records)" : "❌ Failed") . "\n";
            echo "Remote DB: " . ($db['remote'] ? "✅ Success ({$db['remote_count']} records)" : "❌ Failed") . "\n";
            
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
 * @param bool $deleteOldData - Whether to delete past dates
 * @return array - Result with success status and details
 */
function saveAvailabilityToDatabase($availabilityData, $hutId, $deleteOldData = false) {
    global $mysqli, $dbHost, $dbUser, $dbPass, $dbName, $remoteDbHost, $remoteDbUser, $remoteDbPass, $remoteDbName;
    
    $results = ['local' => false, 'remote' => false, 'errors' => [], 'deleted_old' => 0];
    
    // Prepare the data for insertion
    $insertData = [];
    $dateList = [];
    foreach ($availabilityData as $dayData) {
        // Convert German date format (DD.MM.YYYY) to MySQL date format (YYYY-MM-DD)
        $dateObj = DateTime::createFromFormat('d.m.Y', $dayData['date']);
        if ($dateObj) {
            $mysqlDate = $dateObj->format('Y-m-d');
            $insertData[] = [
                'datum' => $mysqlDate,
                'free_place' => (int)$dayData['freePlace'],
                'hut_status' => $dayData['hutStatus']
            ];
            $dateList[] = $mysqlDate;
        }
    }
    
    if (empty($insertData)) {
        $results['errors'][] = 'No valid data to insert';
        return $results;
    }
    
    $minDate = min($dateList);
    $maxDate = max($dateList);
    
    // Function to perform database operations
    $performDbOperation = function($connection, $dbType) use ($insertData, $hutId, $deleteOldData, $minDate, $maxDate, $dateList, &$results) {
        try {
            // Start transaction
            $connection->begin_transaction();
            
            // Check if table has hut_id column
            $checkColumnQuery = "SHOW COLUMNS FROM av_belegung LIKE 'hut_id'";
            $checkResult = $connection->query($checkColumnQuery);
            $hasHutIdColumn = $checkResult && $checkResult->num_rows > 0;
            
            $deletedOldCount = 0;
            
            if ($hasHutIdColumn) {
                // Delete old data if requested
                if ($deleteOldData) {
                    $deleteOldStmt = $connection->prepare("DELETE FROM av_belegung WHERE hut_id = ? AND datum < ?");
                    if ($deleteOldStmt) {
                        $deleteOldStmt->bind_param('ss', $hutId, $minDate);
                        $deleteOldStmt->execute();
                        $deletedOldCount = $deleteOldStmt->affected_rows;
                        $deleteOldStmt->close();
                    }
                }
                
                // Delete existing data only for the date range we're updating
                $dateListStr = "'" . implode("','", $dateList) . "'";
                $deleteQuery = "DELETE FROM av_belegung WHERE hut_id = ? AND datum IN ($dateListStr)";
                $deleteStmt = $connection->prepare($deleteQuery);
                if (!$deleteStmt) {
                    throw new Exception("Prepare delete failed: " . $connection->error);
                }
                $deleteStmt->bind_param('s', $hutId);
                $deleteStmt->execute();
                $deleteStmt->close();
                
                // Insert new data with hut_id
                $insertStmt = $connection->prepare("INSERT INTO av_belegung (hut_id, datum, free_place, hut_status) VALUES (?, ?, ?, ?)");
                if (!$insertStmt) {
                    throw new Exception("Prepare insert failed: " . $connection->error);
                }
                
                $insertedCount = 0;
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
                // Table doesn't have hut_id column
                if ($deleteOldData) {
                    // Delete old data
                    $deleteOldQuery = "DELETE FROM av_belegung WHERE datum < ?";
                    $deleteOldStmt = $connection->prepare($deleteOldQuery);
                    if ($deleteOldStmt) {
                        $deleteOldStmt->bind_param('s', $minDate);
                        $deleteOldStmt->execute();
                        $deletedOldCount = $deleteOldStmt->affected_rows;
                        $deleteOldStmt->close();
                    }
                }
                
                // Delete existing data only for the date range we're updating
                $dateListStr = "'" . implode("','", $dateList) . "'";
                $deleteQuery = "DELETE FROM av_belegung WHERE datum IN ($dateListStr)";
                $connection->query($deleteQuery);
                
                // Insert new data without hut_id
                $insertStmt = $connection->prepare("INSERT INTO av_belegung (datum, free_place, hut_status) VALUES (?, ?, ?)");
                if (!$insertStmt) {
                    throw new Exception("Prepare insert failed: " . $connection->error);
                }
                
                $insertedCount = 0;
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
            $results['deleted_old'] = max($results['deleted_old'], $deletedOldCount);
            
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
    $hutId = getParameter('hutID');
    $von = getParameter('von');
    $bis = getParameter('bis');
    $days = getParameter('days');
    $delOld = getParameter('del_old', 'false');
    
    // Handle days parameter - if provided, calculate von/bis from today
    if ($days && is_numeric($days)) {
        $today = new DateTime();
        $von = $today->format('Y-m-d');
        $futureDate = clone $today;
        $futureDate->add(new DateInterval('P' . (int)$days . 'D'));
        $bis = $futureDate->format('Y-m-d');
    }
    
    // Show usage for CLI if no parameters provided
    if ($isCLI && !$hutId) {
        echo "Usage:\n";
        echo "  php get_av_cap.php --hutID=675 --von=2025-07-25 --bis=2025-11-02\n";
        echo "  php get_av_cap.php --hutID=675 --days=30 [--del_old=true]\n\n";
        echo "Parameters:\n";
        echo "  --hutID        Hut ID (required)\n";
        echo "  --von          Arrival date in YYYY-MM-DD format (required if not using --days)\n";
        echo "  --bis          Departure date in YYYY-MM-DD format (required if not using --days)\n";
        echo "  --days         Number of days from today (alternative to von/bis)\n";
        echo "  --del_old      Delete past dates (true/false, default: false)\n";
        echo "  --nextPossibleReservations  Number of next possible reservations (optional, default: 10)\n";
        exit(1);
    }
    
    // Validate required parameters
    if (!$hutId) {
        throw new Exception('Parameter hutID is required');
    }
    if (!$von || !$bis) {
        if (!$days) {
            throw new Exception('Either von/bis dates or days parameter is required');
        }
    }
    
    // Validate date format (YYYY-MM-DD)
    if (!DateTime::createFromFormat('Y-m-d', $von)) {
        throw new Exception('Invalid von date format. Use YYYY-MM-DD');
    }
    if (!DateTime::createFromFormat('Y-m-d', $bis)) {
        throw new Exception('Invalid bis date format. Use YYYY-MM-DD');
    }
    
    // Validate date logic
    $arrivalDate = new DateTime($von);
    $departureDate = new DateTime($bis);
    if ($arrivalDate >= $departureDate) {
        throw new Exception('Departure date must be after arrival date');
    }
    
    // Check for reasonable date range (API might fail with very large ranges)
    $daysDiff = $arrivalDate->diff($departureDate)->days;
    if ($daysDiff > 365) {
        throw new Exception('Date range too large. Maximum 365 days allowed.');
    }
    
    // Check if dates are not too far in the past
    $today = new DateTime();
    if ($arrivalDate < $today->modify('-30 days')) {
        throw new Exception('Arrival date cannot be more than 30 days in the past');
    }
    
    // Get nextPossibleReservations parameter (optional, default 10)
    $nextPossibleReservations = (int)(getParameter('nextPossibleReservations', 10));
    if ($nextPossibleReservations < 1 || $nextPossibleReservations > 100) {
        $nextPossibleReservations = 10; // Safe default
    }
    
    // Prepare API URL
    $apiUrl = "https://hut-reservation.org/api/v1/reservation/availabilityCalendar/{$hutId}";
    
    // Convert dates to German format (DD.MM.YYYY) as expected by the API
    $arrivalDateFormatted = $arrivalDate->format('d.m.Y');
    $departureDateFormatted = $departureDate->format('d.m.Y');
    
    // Prepare POST data
    $postData = [
        'arrivalDate' => $arrivalDateFormatted,
        'departureDate' => $departureDateFormatted,
        'nextPossibleReservations' => $nextPossibleReservations > 0 ? $nextPossibleReservations : false
    ];
    
    // Initialize cURL
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: WCI-Capacity-Checker/1.0'
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
    curl_close($ch);
    
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
            $errorMessage .= ". This might be due to: invalid hutId, date range too large, or server issues.";
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
    
    // Save availability data to database
    $deleteOldData = strtolower($delOld) === 'true';
    $dbSaveResult = saveAvailabilityToDatabase($apiData, $hutId, $deleteOldData);
    
    // Prepare successful response with summary only
    $result = [
        'success' => true,
        'database' => $dbSaveResult,
        'summary' => [
            'hutId' => $hutId,
            'dateRange' => $von . ' - ' . $bis,
            'totalDays' => count($apiData),
            'dataRetrieved' => date('Y-m-d H:i:s'),
            'deletedOldRecords' => $dbSaveResult['deleted_old'] ?? 0,
            'parametersUsed' => [
                'days' => $days ?? null,
                'del_old' => $deleteOldData
            ]
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
            'arrivalDate' => $von ?? null,
            'departureDate' => $bis ?? null,
            'nextPossibleReservations' => $nextPossibleReservations ?? null
        ],
        'suggestions' => [
            'Check if hutId exists and is valid',
            'Ensure date range is reasonable (max 365 days)',
            'Verify dates are in YYYY-MM-DD format',
            'Try a smaller date range if the current one is very large'
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
