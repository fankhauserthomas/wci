<?php
/**
 * Test: HRS Payload Debug
 * Testet die Quota-Erstellung mit Console-Logging
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== HRS PAYLOAD DEBUG TEST ===\n\n";

// Test-Quota mit Kategorien
$testQuotas = [
    [
        'title' => 'Test Quota 2025-02-12',
        'date_from' => '2025-02-12',
        'date_to' => '2025-02-13',
        'capacity' => 88,
        'categories' => [
            'lager' => 77,
            'betten' => 11,
            'dz' => 0,
            'sonder' => 0
        ]
    ]
];

echo "📤 Sende Test-Quota an hrs_create_quota_batch.php\n";
echo "Input: " . json_encode($testQuotas, JSON_PRETTY_PRINT) . "\n\n";

$url = 'http://localhost/wci/hrs/hrs_create_quota_batch.php';
$payload = json_encode(['quotas' => $testQuotas]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "📥 Response (HTTP $httpCode):\n";
echo $response . "\n\n";

$result = json_decode($response, true);
if ($result && isset($result['log'])) {
    echo "📋 LOG MESSAGES:\n";
    foreach ($result['log'] as $logEntry) {
        echo "  $logEntry\n";
    }
}

echo "\n=== TEST BEENDET ===\n";
?>
