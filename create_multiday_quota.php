<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include config and auth
require_once 'config.php';
require_once 'hrs/hrs_login.php';

echo "=== Creating Multi-Day Quota via HRS API ===\n";

// Initialize HRS Login
$hrsLogin = new HRSLogin();
echo "Performing HRS login...\n";
$loginSuccess = $hrsLogin->login();

if (!$loginSuccess) {
    echo "ERROR: HRS Login failed!\n";
    exit(1);
}

echo "✅ HRS Login successful!\n";

// Create a 7-day quota from 2025-12-25 to 2025-12-31 (will span multiple days as one quota object)
$postData = array(
    'dateFrom' => '25.12.2025',
    'dateTo' => '01.01.2026',  // HRS uses exclusive end date, so this will be 25.12-31.12
    'title' => 'Multi-Day Test Quota (Split Test)',
    'mode' => 'SERVICED',
    'reservationMode' => 'SERVICED',
    'capacity' => 12,
    'hutBedCategoryDTOs' => array(
        array(
            'categoryId' => 1958,  // Lager category
            'totalBeds' => 12
        ),
        array(
            'categoryId' => 2293,  // Betten category
            'totalBeds' => 0
        ),
        array(
            'categoryId' => 2381,  // DZ category
            'totalBeds' => 0
        ),
        array(
            'categoryId' => 6106,  // Sonder category
            'totalBeds' => 0
        )
    ),
    'languagesDataDTOs' => array(
        array(
            'language' => 'DE_DE',
            'description' => ''
        ),
        array(
            'language' => 'EN',
            'description' => ''
        )
    ),
    'hutId' => defined('HUT_ID') ? HUT_ID : 675,
    'canChangeMode' => false,
    'canOverbook' => false,
    'isRecurring' => false,
    'monday' => false,
    'tuesday' => false,
    'wednesday' => false,
    'thursday' => false,
    'friday' => false,
    'saturday' => false,
    'sunday' => false
);

$headers = array(
    'Content-Type: application/json',
    'X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken()
);

echo "Creating multi-day quota: 25.12.2025 to 01.01.2026 (7 days) with 12 beds\n";
echo "POST Data: " . json_encode($postData, JSON_PRETTY_PRINT) . "\n";

$hutId = defined('HUT_ID') ? HUT_ID : 675;
$response = $hrsLogin->makeRequest("/api/v1/manage/hutQuota/{$hutId}", 'POST', json_encode($postData), $headers);

if (!$response) {
    echo "ERROR: No response from API\n";
    exit(1);
}

echo "Response Status: " . $response['status'] . "\n";
echo "Response Body: " . $response['body'] . "\n";

if ($response['status'] === 200) {
    echo "✅ SUCCESS: Multi-day quota created!\n";
    
    // Parse response to get quota ID if available
    $responseData = json_decode($response['body'], true);
    if (isset($responseData['param1'])) {
        echo "📝 Quota ID: " . $responseData['param1'] . "\n";
    }
} else {
    echo "❌ ERROR: Quota creation failed!\n";
    echo "Response: " . $response['body'] . "\n";
}

echo "\nDone.\n";
?>