<?php
/**
 * Debug version of HRS quota importer for testing
 */

// Check if this is web interface
if (isset($_GET['from']) && isset($_GET['to'])) {
    header('Content-Type: application/json');
    $dateFrom = $_GET['from'];
    $dateTo = $_GET['to'];
    $isWebInterface = true;
    
    // Start output buffering for web interface
    ob_start();
    
    try {
        echo "Starting debug quota import...\n";
        echo "Date from: $dateFrom\n";
        echo "Date to: $dateTo\n";
        
        // Include the config and login
        require_once(__DIR__ . '/../config.php');
        require_once(__DIR__ . '/hrs_login.php');
        
        echo "Files included successfully\n";
        
        // Try HRS login
        $hrsLogin = new HRSLogin();
        echo "HRS Login object created\n";
        
        if ($hrsLogin->login()) {
            echo "HRS Login successful\n";
            
            // Test the API call
            $hutId = 675;
            $url = "/api/v1/manage/hutQuota?hutId={$hutId}&page=0&size=100&sortList=BeginDate&sortOrder=DESC&open=true&dateFrom={$dateFrom}&dateTo={$dateTo}";
            
            $headers = array(
                'X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken()
            );
            
            echo "Making API request to: $url\n";
            
            $response = $hrsLogin->makeRequest($url, 'GET', null, $headers);
            
            echo "API Response status: " . ($response['status'] ?? 'unknown') . "\n";
            echo "API Response body length: " . strlen($response['body'] ?? '') . " chars\n";
            
            if ($response && $response['status'] == 200) {
                $data = json_decode($response['body'], true);
                if ($data && isset($data['_embedded']['bedCapacityChangeResponseDTOList'])) {
                    $quotas = $data['_embedded']['bedCapacityChangeResponseDTOList'];
                    echo "Found " . count($quotas) . " quotas\n";
                } else {
                    echo "No quota data in response or JSON decode failed\n";
                    echo "Raw response (first 500 chars): " . substr($response['body'], 0, 500) . "\n";
                }
            } else {
                echo "API request failed\n";
                if ($response) {
                    echo "Response body: " . substr($response['body'], 0, 500) . "\n";
                }
            }
        } else {
            echo "HRS Login failed\n";
        }
        
        $output = ob_get_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Debug completed',
            'log' => $output
        ]);
        
    } catch (Exception $e) {
        $output = ob_get_clean();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'log' => $output
        ]);
    }
} else {
    echo "Usage: debug_quota.php?from=24.08.2025&to=24.08.2025\n";
}
?>
