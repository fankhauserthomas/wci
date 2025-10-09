<?php
/**
 * Test Complete Quota Flow: Local DB + HRS Upload
 */

require_once __DIR__ . '/config.php';

echo "=== TESTING COMPLETE QUOTA FLOW ===\n\n";

// Test-Daten
$testPayload = [
    'selectedDays' => ['2026-02-20', '2026-02-21'],
    'targetCapacity' => 80,
    'priorities' => [
        ['category' => 'lager', 'max' => null],
        ['category' => 'betten', 'max' => 15],
        ['category' => 'dz', 'max' => 5],
        ['category' => 'sonder', 'max' => 2]
    ],
    'operation' => 'update'
];

echo "📤 Sending request:\n";
echo json_encode($testPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Call API
$ch = curl_init('http://192.168.15.14:8080/wci/hrs/hrs_update_quota_timeline.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 180); // 3 minutes for HRS operations

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "📥 Response (HTTP $httpCode):\n";
$result = json_decode($response, true);

if ($result) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    if ($result['success']) {
        echo "✅ SUCCESS!\n";
        echo "   - Created local quotas: " . count($result['createdQuotas']) . "\n";
        echo "   - Deleted local quotas: " . count($result['deletedQuotas']) . "\n";
        
        if (isset($result['hrsUpload'])) {
            echo "   - HRS Deleted: " . ($result['hrsUpload']['deleted'] ?? 0) . "\n";
            echo "   - HRS Created: " . ($result['hrsUpload']['created'] ?? 0) . "\n";
            
            if (!empty($result['hrsUpload']['errors'])) {
                echo "   ⚠️ HRS Errors:\n";
                foreach ($result['hrsUpload']['errors'] as $error) {
                    echo "      - $error\n";
                }
            }
        }
    } else {
        echo "❌ ERROR: " . ($result['error'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "Raw response:\n$response\n";
}

?>
